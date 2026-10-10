<?php
session_start();
require_once '../../config/database.php';

// Check roles
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('teacher', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "แบบบันทึกประจำห้องที่ปรึกษา";
include '../../includes/header.php';

// Find teacher's homeroom
$stmt_usr = $pdo->prepare("SELECT advisor_classroom FROM users WHERE id = ?");
$stmt_usr->execute([$_SESSION['user_id']]);
$usr = $stmt_usr->fetch();

$advisor_classroom = $usr['advisor_classroom'] ?? null;

if (!$advisor_classroom) {
    echo "<div class='card' style='text-align:center; padding: 40px;'><h3>คุณไม่มีห้องที่ปรึกษาในระบบ (โปรดติดต่อ Admin ให้ตั้งค่าในโปรไฟล์)</h3></div>";
    include '../../includes/footer.php';
    exit();
}

$stmt_c = $pdo->prepare("SELECT id, room_name FROM classrooms WHERE room_name = ? LIMIT 1");
$stmt_c->execute([$advisor_classroom]);
$hr = $stmt_c->fetch();

if (!$hr) {
    echo "<div class='card' style='text-align:center; padding: 40px;'><h3>ไม่พบข้อมูลห้องเรียน '$advisor_classroom' ในระบบ</h3></div>";
    include '../../includes/footer.php';
    exit();
}

$cid = $hr['id'];
$room_name_display = $hr['room_name'];

// Date filter
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$start_date = $filter_date;
$end_date = $filter_date;

// 1. Get Students
$stmt_s = $pdo->prepare("SELECT id, student_number, full_name FROM students WHERE classroom_id = ? AND status = 'active' ORDER BY student_number ASC");
$stmt_s->execute([$cid]);
$students = $stmt_s->fetchAll();

// Initialize data structure
$matrix = [];
$period_logs = array_fill(0, 10, ['subject' => '', 'topic' => '', 'teacher' => '', 'signature_path' => '']);
foreach ($students as $s) {
    $matrix[$s['id']] = array_fill(0, 10, '');
}

// 2. Get Homeroom Data
$stmt_hr_data = $pdo->prepare("
    SELECT ha.student_id, ha.status, u.full_name as teacher_name, ha.signature_path
    FROM homeroom_attendance ha
    JOIN students s ON ha.student_id = s.id
    LEFT JOIN users u ON ha.recorded_by_teacher_id = u.id
    WHERE s.classroom_id = ? AND ha.attendance_date = ?
");
$stmt_hr_data->execute([$cid, $filter_date]);

while ($row = $stmt_hr_data->fetch()) {
    $sid = $row['student_id'];
    if (isset($matrix[$sid])) {
        $matrix[$sid][0] = $row['status'];
    }
    if ($row['teacher_name']) {
        $period_logs[0]['teacher'] = $row['teacher_name'];
        if (!empty($row['signature_path'])) {
            $period_logs[0]['signature_path'] = $row['signature_path'];
        }
    }
}
$period_logs[0]['subject'] = 'เข้าแถว/โฮมรูม';
$period_logs[0]['topic'] = '';

// 3. Get Class Attendance (Periods 1-9)
$stmt_log = $pdo->prepare("
    SELECT tl.id as log_id, tl.period, tl.teaching_report_text, s.subject_code, u.full_name as teacher_name, tl.signature_path,
           u_orig.full_name as original_teacher_name
    FROM teaching_logs tl
    LEFT JOIN subjects s ON tl.subject_id = s.id
    LEFT JOIN users u ON tl.teacher_id = u.id
    LEFT JOIN teaching_substitutions ts 
           ON tl.teacher_id = ts.substitute_teacher_id 
           AND tl.log_date = ts.substitution_date 
           AND tl.subject_id = ts.subject_id 
           AND tl.classroom_id = ts.classroom_id 
           AND FIND_IN_SET(ts.period, REPLACE(tl.period, ' ', '')) > 0
    LEFT JOIN users u_orig ON ts.original_teacher_id = u_orig.id
    WHERE tl.classroom_id = ? AND tl.log_date = ?
");
$stmt_log->execute([$cid, $filter_date]);
$logs = $stmt_log->fetchAll();

$log_ids = [];
foreach ($logs as $log) {
    $periods = explode(',', $log['period']);
    foreach ($periods as $period_str) {
        $p = (int)trim($period_str);
        if ($p >= 1 && $p <= 9) {
            $teacher_display = $log['teacher_name'];
            if (!empty($log['original_teacher_name']) && $log['original_teacher_name'] != $log['teacher_name']) {
                $teacher_display = "แทน " . $log['original_teacher_name'] . "\n(" . $log['teacher_name'] . ")";
            }

            $period_logs[$p] = [
                'log_id' => $log['log_id'],
                'subject' => $log['subject_code'],
                'topic' => $log['teaching_report_text'] ?? '',
                'teacher' => $teacher_display,
                'signature_path' => $log['signature_path']
            ];
            $log_ids[] = $log['log_id'];
        }
    }
}

// 4. Fetch actual student attendance
if (!empty($log_ids)) {
    $in_placeholders = str_repeat('?,', count($log_ids) - 1) . '?';
    $stmt_att = $pdo->prepare("
        SELECT ca.student_id, ca.status, tl.period
        FROM class_attendance ca
        JOIN teaching_logs tl ON ca.teaching_log_id = tl.id
        WHERE ca.teaching_log_id IN ($in_placeholders)
    ");
    $stmt_att->execute($log_ids);
    while ($att = $stmt_att->fetch()) {
        $sid = $att['student_id'];
        $periods = explode(',', $att['period']);
        foreach ($periods as $period_str) {
            $p = (int)trim($period_str);
            if (isset($matrix[$sid])) {
                $matrix[$sid][$p] = $att['status'];
            }
        }
    }
}

// Helper formatting translation
function transSym($status) {
    if ($status === 'present') return '<span style="color:#16a34a;">/</span>';
    if ($status === 'late') return '<span style="color:#d97706;">ส</span>';
    if ($status === 'leave') return '<span style="color:#2563eb;">ล</span>';
    if ($status === 'absent') return '<span style="color:#dc2626;">ข</span>';
    if ($status === 'activity') return '<span style="color:#9333ea;">ก</span>';
    return '';
}

$thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
$d_parts = explode('-', $filter_date);
$thai_date = (int)$d_parts[2] . ' ' . $thai_months[(int)$d_parts[1]] . ' ' . ($d_parts[0] + 543);
?>

<div class="card" style="padding: 15px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h2 class="card-title" style="margin-bottom: 0; font-size: 1.1rem;">📝 แบบบันทึกประจำห้องที่ปรึกษา</h2>
        <a href="teacher_home.php" class="btn btn-outline" style="border-radius: 20px; padding: 6px 12px; font-size: 0.85rem;">⬅️ หน้าหลัก</a>
    </div>

    <!-- Filter Form -->
    <form method="GET" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0; display: flex; align-items: center; gap: 10px;">
            <label style="margin-bottom: 0; font-size: 0.85rem; white-space: nowrap;">เลือกวันที่</label>
            <input type="date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>" class="form-control" style="width: auto; padding: 6px 12px; height: auto; font-size: 0.9rem;" onchange="this.form.submit()">
        </div>
        
    </form>
</div>

<?php
$dates = [$filter_date];
$reports_data = [
    $cid => [
        'room_name' => $room_name_display,
        'students' => $students,
        'dates' => [
            $filter_date => [
                'matrix' => $matrix,
                'period_logs' => $period_logs
            ]
        ]
    ]
];
?>

<?php if (!empty($reports_data)): ?>
<style>
/* CSS specific for the Print Layout trying to stay exactly on 1 page Landscape */
.print-container {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 30px;
    overflow-x: auto;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
}
.report-header {
    text-align: center;
    margin-bottom: 20px;
    line-height: 1.6;
}
.report-title {
    font-size: 1.4rem;
    font-weight: bold;
}
.report-subtitle {
    font-size: 1.1rem;
}
.report-note {
    font-size: 0.85rem;
    color: #555;
    text-align: left;
    margin-bottom: 10px;
}
.split-layout {
    display: flex;
    gap: 15px;
    align-items: flex-start;
}
/* Left Section */
.left-section {
    flex: 0 0 50%; 
    max-width: 50%;
}
/* Right Section */
.right-section {
    flex: 0 0 50%; 
    max-width: 50%;
}

.t-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem; /* Small font to fit */
}
.t-table th, .t-table td {
    border: 1px solid #333;
    padding: 4px;
    text-align: center;
    vertical-align: middle;
}
.t-table th {
    background-color: #f8fafc;
    font-weight: bold;
}
.col-name {
    text-align: left !important;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 130px;
}
.col-period {
    width: 20px; /* Compress the 0-9 columns */
    padding: 2px 0px !important;
}

.t-table-right {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8rem;
}
.t-table-right th, .t-table-right td {
    border: 1px solid #333;
    padding: 4px;
    vertical-align: top;
    text-align: left;
}
.t-table-right tbody td {
    height: 55px; /* Force minimum height so empty rows look uniform */
}
.t-table-right th {
    background-color: #f8fafc;
    text-align: center;
    font-weight: bold;
    padding: 2px !important;
}

/* Format specific columns in the right table */
.th-p { width: 30px; text-align: center !important; }
.th-s { width: 60px; }
.th-t { width: auto; }
.th-te { width: 100px; }

/* The beautiful symbols */
.sym {
    font-weight: bold;
    color: #111;
}

@media print {
    @page {
        size: A4 portrait; 
        margin: 10mm 8mm;
    }
    body * {
        visibility: hidden;
    }
    #print-wrapper, #print-wrapper * {
        visibility: visible;
    }
    #print-wrapper {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        background: white;
    }
    
    .print-container {
        page-break-after: always; /* Force page break after each day */
        width: 100%;
        box-shadow: none !important;
        padding: 0;
        margin: 0;
        /* Force height to A4 size (approx 297mm height, minus margins) */
        height: 277mm; 
        overflow: hidden; /* Prevent spillover */
        display: flex;
        flex-direction: column;
    }
    
    .print-container:last-of-type {
        page-break-after: auto;
    }

    .report-title { font-size: 1.3rem !important; margin: 0 !important; padding:0 !important; text-align: center; }
    .report-subtitle { font-size: 1.1rem !important; margin: 0 !important; text-align: center; }
    .report-note { font-size: 9pt !important; margin-bottom: 2px !important; padding-bottom: 2px !important; }
    .report-header { margin-bottom: 3px !important; }

    /* Container for the table so it flex-grows to fit the page */
    .table-wrapper {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }

    .t-table, .t-table-right {
        font-size: 9pt !important; 
        width: 100%;
        border: none;
        line-height: 1.1 !important;
    }
    
    .t-table th, .t-table td { padding: 2px 2px !important; }
    .t-table-right th { padding: 4px 2px !important; }
    .t-table-right td { padding: 8px 3px !important; }
    
    .t-table th, .t-table-right th {
        background-color: transparent !important;
    }
    .t-table th, .t-table td, .t-table-right th, .t-table-right td {
        border: 1px solid #000 !important;
    }
    
    .topic-text {
        font-size: 7.5pt !important;
        line-height: 1.1 !important;
    }
    
    html, body {
        height: 100%;
        width: 100%;
        margin: 0;
        padding: 0;
        background: #fff;
    }
    tr { page-break-inside: avoid; }
}
</style>

<div id="print-wrapper">
    <?php foreach ($reports_data as $cid => $cdata): ?>
        <?php foreach ($dates as $date): ?>
            <?php 
                $matrix = $cdata['dates'][$date]['matrix'];
                $period_logs = $cdata['dates'][$date]['period_logs'];
                $students = $cdata['students'];
                $room_name_display = $cdata['room_name'];
                
                // Fetch homeroom teachers for this room
                $stmt_ht = $pdo->prepare("SELECT full_name FROM users WHERE TRIM(advisor_classroom) = ?");
                $stmt_ht->execute([trim($room_name_display)]);
                $teachers = $stmt_ht->fetchAll(PDO::FETCH_COLUMN);
                $teachers = array_filter($teachers);
                $teachers_str = !empty($teachers) ? implode(", ", $teachers) : "-";
            ?>
            <div class="print-container">
                <div class="report-header" style="position: relative; text-align: center; margin-bottom: 25px;">
                    <?php 
                    $th_logo = !empty($site_settings['school_logo']) ? $site_settings['school_logo'] : 'assets/images/school_logo.png';
                    $th_name = !empty($site_settings['school_name']) ? $site_settings['school_name'] : 'โรงเรียนของคุณ';
                    ?>
                    <img src="<?= $base_url ?>/<?= htmlspecialchars($th_logo) ?>" style="position: absolute; left: 0; top: 0; width: 45px; height: auto;" alt="Logo" onerror="this.src='<?= $base_url ?>/assets/images/school_logo.png'">
                    
                    <div style="flex-grow: 1;">
                        <h3 style="font-size: 16px; font-weight: bold; margin: 0 0 5px;"><?= htmlspecialchars($th_name) ?></h3>
                        <h4 style="font-size: 14px; margin: 0 0 5px;">แบบบันทึกการเข้าชั้นเรียนและบันทึกการสอน<?php echo (!empty($term_text) && trim($term_text) !== "") ? " (".htmlspecialchars(trim($term_text)).")" : ""; ?></h4>
                        <p style="font-size: 12px; margin: 0;">
                            <strong>ชั้น :</strong> <?= htmlspecialchars($room_name_display) ?> &nbsp;&nbsp;&nbsp;&nbsp; 
                            <strong>ครูที่ปรึกษา :</strong> <?= htmlspecialchars($teachers_str) ?>
                            <br>
                            <?php 
                                $thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
                                $d = date('j', strtotime($date));
                                $m = $thai_months[date('n', strtotime($date))];
                                $y = date('Y', strtotime($date)) + 543;
                                echo "วันที่ {$d} เดือน {$m} พ.ศ. {$y}";
                            ?>
                        </p>
                    </div>
                </div>
                
                <div class="report-note" style="border-bottom: 1px solid #000; padding-bottom: 5px;">
                    <strong>คำชี้แจง:</strong> 
                    ให้ทำเครื่องหมาย <strong style="font-size: 1.2rem;">/</strong> สำหรับนักเรียนที่มาเรียน, 
                    เขียน <strong>ข</strong> สำหรับนักเรียนที่ขาดเรียน, 
                    เขียน <strong>ล</strong> สำหรับนักเรียนที่ลา (ลากิจ/ลาป่วย), 
                    เขียน <strong>ส</strong> สำหรับนักเรียนที่เข้าเรียนสาย
                </div>

                <!-- Master Layout Table -->
                <div class="table-wrapper">
                <table style="width: 100%; height: 100%; border-collapse: collapse; border: 1px solid #000;">
                    <tr>
                        <!-- LEFT HALF: Matrix -->
                        <td style="width: 50%; vertical-align: top; padding: 0; border-right: 1px solid #000;">
                            <table class="t-table" style="border: none !important;">
                                <thead>
                                    <tr>
                                        <th rowspan="2" style="width: 25px; border-top: none; border-left: none;">ที่</th>
                                        <th rowspan="2" class="col-name" style="border-top: none;">ชื่อ-สกุล</th>
                                        <th colspan="10" style="border-top: none; padding: 1px !important;">คาบที่</th>
                                    </tr>
                                    <tr>
                                        <?php for($i=0; $i<=9; $i++): ?>
                                            <th class="col-period" style="padding: 1px !important;"><?= $i ?></th>
                                        <?php endfor; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($students)): ?>
                                        <tr><td colspan="12" style="padding: 20px;">ไม่พบรายชื่อนักเรียน</td></tr>
                                    <?php else: ?>
                                        <?php foreach($students as $index => $s): ?>
                                            <tr>
                                                <td style="border-left: none; text-align: center;"><?= $s['student_number'] ?: ($index+1) ?></td>
                                                <td class="col-name"><?= htmlspecialchars($s['full_name']) ?></td>
                                                <?php for($i=0; $i<=9; $i++): ?>
                                                    <td class="col-period sym" style="text-align: center;">
                                                        <?= transSym($matrix[$s['id']][$i] ?? '') ?>
                                                    </td>
                                                <?php endfor; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        
                                        <!-- Summary Rows at the bottom of left table -->
                                        <tr>
                                            <td colspan="2" style="text-align: right; font-weight: bold; padding-right: 10px; border-left: none;">ข (ขาด) รวม:</td>
                                            <?php for($i=0; $i<=9; $i++): 
                                                $c = 0;
                                                foreach($students as $s) if(($matrix[$s['id']][$i]??'') == 'absent') $c++;
                                            ?>
                                                <td class="col-period" style="font-size: 0.7rem; color: #555; text-align: center;"><?= $c > 0 ? $c : '' ?></td>
                                            <?php endfor; ?>
                                        </tr>
                                        <tr>
                                            <td colspan="2" style="text-align: right; font-weight: bold; padding-right: 10px; border-left: none;">ล (ลา) รวม:</td>
                                            <?php for($i=0; $i<=9; $i++): 
                                                $c = 0;
                                                foreach($students as $s) if(($matrix[$s['id']][$i]??'') == 'leave') $c++;
                                            ?>
                                                <td class="col-period" style="font-size: 0.7rem; color: #555; text-align: center;"><?= $c > 0 ? $c : '' ?></td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </td>

                        <!-- RIGHT HALF: Period Details & Signatures -->
                        <td style="width: 50%; vertical-align: top; padding: 0;">
                            
                            <table class="t-table-right" style="border: none !important; width: 100%;">
                                <thead>
                                    <tr>
                                        <th class="th-p" style="border-top: none; border-left: none; width: 35px;">คาบ</th>
                                        <th class="th-s" style="border-top: none; width: 70px;">วิชา</th>
                                        <th class="th-t" style="border-top: none;">รายการสอน</th>
                                        <th class="th-te" style="border-top: none; border-right: none; width: 110px;">ครูผู้สอน</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $skip = [];
                                    for($i=1; $i<=9; $i++): 
                                        $plog = $period_logs[$i];
                                        $is_merged_subsequent = isset($skip[$i]);
                                        
                                        $rowspan = 1;
                                        if (!$is_merged_subsequent && !empty($plog['log_id'])) {
                                            for ($j = $i + 1; $j <= 9; $j++) {
                                                if (($period_logs[$j]['log_id'] ?? null) === $plog['log_id']) {
                                                    $rowspan++;
                                                    $skip[$j] = true;
                                                } else {
                                                    break;
                                                }
                                            }
                                        }
                                    ?>
                                        <tr>
                                            <td class="th-p" style="vertical-align: middle; font-weight: bold; text-align: center; border-left: none;">
                                                <?= $i ?>
                                            </td>
                                            <?php if (!$is_merged_subsequent): ?>
                                                <td rowspan="<?= $rowspan ?>" style="padding: 12px 4px; vertical-align: middle;"><?= htmlspecialchars($plog['subject'] ?: '') ?></td>
                                                <td class="topic-text" rowspan="<?= $rowspan ?>" style="text-align: left; padding: 12px 4px; white-space: pre-wrap; vertical-align: middle;"><?= htmlspecialchars($plog['topic'] ?: '') ?></td>
                                                <td rowspan="<?= $rowspan ?>" style="padding: 12px 4px; border-right: none; text-align: center; vertical-align: middle;">
                                                    <?php if (!empty($plog['signature_path'])): ?>
                                                        <img src="../../<?= htmlspecialchars($plog['signature_path']) ?>" style="max-height: 25px; display: block; margin: 0 auto; mix-blend-mode: multiply;">
                                                        <div style="font-size: 0.45rem; line-height: 1.2; margin-top: 2px; color: #555; white-space: pre-wrap;"><?= htmlspecialchars($plog['teacher'] ?: '') ?></div>
                                                    <?php else: ?>
                                                        <div style="font-size: 0.55rem; color: #555; white-space: pre-wrap; line-height: 1.2;"><?= htmlspecialchars($plog['teacher'] ?: '') ?></div>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>

                            <!-- Summary & Signature Block -->
                            <div style="padding: 10px 15px; border-top: 1px solid #000; font-size: 0.95rem;">
                                <strong>สรุป</strong>
                                <?php 
                                    $hr_present = 0; $hr_absent = 0; $hr_leave = 0;
                                    foreach($students as $s) {
                                        $st = $matrix[$s['id']][0] ?? '';
                                        if ($st == 'present' || $st == 'late') $hr_present++;
                                        elseif ($st == 'absent') $hr_absent++;
                                        elseif ($st == 'leave') $hr_leave++;
                                    }
                                ?>
                                <div style="display: grid; grid-template-columns: auto 1fr auto; column-gap: 10px; row-gap: 8px; margin-top: 15px; font-size: 0.85rem; max-width: 250px;">
                                    <span>จำนวนนักเรียนรวม</span>
                                    <span style="border-bottom: 1px dotted #000; text-align: center; padding-bottom: 2px;"><?= count($students) ?></span>
                                    <span>คน</span>

                                    <span>มาเรียน</span>
                                    <span style="border-bottom: 1px dotted #000; text-align: center; padding-bottom: 2px;"><?= $hr_present ?: '' ?></span>
                                    <span>คน</span>

                                    <span>ขาดเรียน</span>
                                    <span style="border-bottom: 1px dotted #000; text-align: center; padding-bottom: 2px;"><?= $hr_absent ?: '' ?></span>
                                    <span>คน</span>

                                    <span>ลาป่วย/ลากิจ</span>
                                    <span style="border-bottom: 1px dotted #000; text-align: center; padding-bottom: 2px;"><?= $hr_leave ?: '' ?></span>
                                    <span>คน</span>
                                </div>
                                <?php 
                                    $teacher_name = $period_logs[0]['teacher'] ? htmlspecialchars($period_logs[0]['teacher']) : ''; 
                                    $hr_sig = $period_logs[0]['signature_path'] ?? '';
                                ?>
                                <div style="margin-top: 25px; line-height: 1.8; text-align: center; margin-left: -50px;">
                                    ลงชื่อ
                                    <span style="display: inline-block; min-width: 160px; border-bottom: 1px dotted #000; text-align: center; margin: 0 5px; vertical-align: bottom; height: 30px;">
                                        <?php if ($hr_sig): ?>
                                            <img src="../../<?= htmlspecialchars($hr_sig) ?>" style="max-height: 35px; margin-bottom: -5px; mix-blend-mode: multiply;">
                                        <?php endif; ?>
                                    </span>
                                    ผู้บันทึก<br>
                                    ( <span style="font-size: 0.8rem;"><?= $teacher_name ?: '.....................................................' ?></span> )
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>
                </div> <!-- End table-wrapper -->
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>


<?php include '../../includes/footer.php'; ?>
