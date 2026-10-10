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

$page_title = "แบบบันทึกห้องที่ปรึกษา";
include '../../includes/header.php';

$filter_date = $_GET['date'] ?? date('Y-m-d');
$teacher_id = $_SESSION['user_id'];

// Get Teacher's assigned homeroom
$stmt = $pdo->prepare("SELECT advisor_classroom FROM users WHERE id = ?");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();
$advisor_classroom = $teacher['advisor_classroom'] ?? null;

$classroom_id = null;
if ($advisor_classroom) {
    $stmt_c = $pdo->prepare("SELECT id FROM classrooms WHERE room_name = ? LIMIT 1");
    $stmt_c->execute([$advisor_classroom]);
    $classroom_id = $stmt_c->fetchColumn();
}

$students = [];
$matrix = [];
$period_logs = [];
$room_name_display = "";

// Initialize period logs template (0 to 9)
for ($i = 0; $i <= 9; $i++) {
    $period_logs[$i] = [
        'subject' => '',
        'topic' => '',
        'teacher' => '',
        'signature_path' => '',
        'log_id' => null
    ];
}

if ($classroom_id) {
    // Get room name
    $stmt_rn = $pdo->prepare("SELECT room_name FROM classrooms WHERE id = ?");
    $stmt_rn->execute([$classroom_id]);
    $room_name_display = $stmt_rn->fetchColumn();

    // 1. Get Students
    $stmt_s = $pdo->prepare("SELECT id, student_number, full_name FROM students WHERE classroom_id = ? AND status = 'active' ORDER BY student_number ASC");
    $stmt_s->execute([$classroom_id]);
    $students = $stmt_s->fetchAll();

    foreach ($students as $s) {
        // Init matrix row
        $matrix[$s['id']] = array_fill(0, 10, '');
    }

    // 2. Get Homeroom Data (Period 0)
    $stmt_hr = $pdo->prepare("
        SELECT ha.student_id, ha.status, u.full_name as teacher_name, ha.signature_path
        FROM homeroom_attendance ha
        JOIN students s ON ha.student_id = s.id
        LEFT JOIN users u ON ha.recorded_by_teacher_id = u.id
        WHERE s.classroom_id = ? AND ha.attendance_date = ?
    ");
    $stmt_hr->execute([$classroom_id, $filter_date]);
    
    $hr_teacher = "";
    $hr_sig = "";
    while ($row = $stmt_hr->fetch()) {
        $sid = $row['student_id'];
        if (isset($matrix[$sid])) {
            $matrix[$sid][0] = $row['status'];
        }
        if ($row['teacher_name']) {
            $hr_teacher = $row['teacher_name'];
            if (!empty($row['signature_path'])) $hr_sig = $row['signature_path'];
        }
    }
    // Populate Period 0 Log
    $period_logs[0] = [
        'subject' => 'เข้าแถว/โฮมรูม',
        'topic' => '-',
        'teacher' => $hr_teacher,
        'signature_path' => $hr_sig,
        'log_id' => null
    ];

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
    $stmt_log->execute([$classroom_id, $filter_date]);
    $logs = $stmt_log->fetchAll();

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
                    'topic' => $log['teaching_report_text'] ?? '-',
                    'teacher' => $teacher_display,
                    'signature_path' => $log['signature_path']
                ];

                // Get attendance for this log
                $stmt_att = $pdo->prepare("SELECT student_id, status FROM class_attendance WHERE teaching_log_id = ?");
                $stmt_att->execute([$log['log_id']]);
                while ($att = $stmt_att->fetch()) {
                    $sid = $att['student_id'];
                    if (isset($matrix[$sid])) {
                        $matrix[$sid][$p] = $att['status'];
                    }
                }
            }
        }
    }
}

// Helper formatting translation
function transSym($status) {
    if (!$status) return '';
    switch ($status) {
        case 'present': return '/';
        case 'late': return 'ส';
        case 'leave': return 'ล';
        case 'absent': return 'ข';
        default: return '';
    }
}
?>

<div class="card no-print">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">📓 แบบบันทึกห้องที่ปรึกษา</h2>
    </div>

    <!-- Filter Form -->
    <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin-bottom: 0;">
            <label>วันที่</label>
            <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" required>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label>ห้องเรียนที่ปรึกษา</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($advisor_classroom ?: 'ไม่มี') ?>" disabled style="background:#f1f5f9; color: var(--text-muted);">
        </div>
        <button type="submit" class="btn" style="background-color: #1d4ed8; color: white;">แสดงรายงาน</button>
        <?php if ($classroom_id): ?>
            <button type="button" class="btn btn-outline" style="border-color: #1d4ed8; color: #1d4ed8;" onclick="window.print()">🖨️ พิมพ์ (Print)</button>
        <?php endif; ?>
    </form>
</div>

<?php if (!$classroom_id): ?>
    <div style="text-align: center; padding: 40px 20px; border: 1px dashed var(--border-color); border-radius: 8px; color: var(--text-muted); margin-top: 20px;">
        คุณยังไม่ได้ถูกระบุให้เป็นครูที่ปรึกษาของห้องเรียนใดๆ (ไม่สามารถพิมพ์แบบบันทึกห้องที่ปรึกษาได้)
    </div>
<?php elseif ($classroom_id): ?>
<style>
/* CSS specific for the Print Layout trying to stay exactly on 1 page Landscape */
.print-container {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
        margin: 8mm;
    }
    body * {
        visibility: hidden;
    }
    .print-container, .print-container * {
        visibility: visible;
    }
    .print-container {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        box-shadow: none !important;
        padding: 0;
        background: white;
        overflow: visible; /* disable scrolling for print */
        max-width: none;
    }

    .report-title { font-size: 1.3rem !important; margin: 0 !important; padding:0 !important; }
    .report-subtitle { font-size: 1.1rem !important; margin: 0 !important; }
    .report-note { font-size: 9pt !important; margin-bottom: 2px !important; padding-bottom: 2px !important; }
    .report-header { margin-bottom: 3px !important; }

    .t-table, .t-table-right {
        font-size: 9pt !important; 
        width: 100%;
        border: none;
        line-height: 1.2 !important;
    }
    
    /* VERY AGGRESSIVE SQUISH FOR LEFT SIDE TO AVOID OVERFLOW */
    .t-table th, .t-table td { padding: 2px 2px !important; }
    
    /* EXPAND RIGHT SIDE AS REQUESTED, BUT DONT EXCEED PAGE */
    .t-table-right th { padding: 6px 2px !important; }
    .t-table-right td { padding: 12px 3px !important; height: 45px !important; }
    
    /* Remove system background colors but keep borders */
    .t-table th, .t-table-right th {
        background-color: transparent !important;
    }
    .t-table th, .t-table td, .t-table-right th, .t-table-right td {
        border: 1px solid #000 !important; /* Use hard black for print */
    }
    
    /* Reduce topic text size as requested */
    .topic-text {
        font-size: 6.5pt !important;
        line-height: 1.1 !important;
    }
    
    /* Try to force 1 page perfectly */
    html, body {
        height: 100%;
        width: 100%;
        margin: 0;
        padding: 0;
    }
    tr { page-break-inside: avoid; }
}
</style>

<div class="print-container">
    <div class="report-header">
        <div class="report-title">แบบบันทึกการเข้าชั้นเรียนและบันทึกการสอน <?= htmlspecialchars($room_name_display) ?></div>
        <div class="report-subtitle">
            <?php 
                $thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
                $d = date('j', strtotime($filter_date));
                $m = $thai_months[date('n', strtotime($filter_date))];
                $y = date('Y', strtotime($filter_date)) + 543;
                echo "วันที่ {$d} เดือน {$m} พ.ศ. {$y}";
            ?>
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
    <table style="width: 100%; border-collapse: collapse; border: 1px solid #000;">
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
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
