<?php
session_start();
require_once '../../config/database.php';

// Check roles
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('academic', $user_roles) && !in_array('executive', $user_roles) && !in_array('admin', $user_roles) && !in_array('teacher', $user_roles)) {
    echo "Access Denied";
    exit();
}

$is_strict_teacher = false;
$teacher_homeroom_id = null;
$view_as_teacher = (isset($_GET['view_as']) && $_GET['view_as'] === 'teacher');

if (in_array('teacher', $user_roles)) {
    $has_high_roles = (in_array('academic', $user_roles) || in_array('executive', $user_roles) || in_array('admin', $user_roles));
    
    if (!$has_high_roles || $view_as_teacher) {
        $is_strict_teacher = true;
        $stmt_hr = $pdo->prepare("SELECT classroom_id as id FROM homeroom_teachers WHERE teacher_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_hr->execute([$_SESSION['user_id']]);
        $hr = $stmt_hr->fetch();
        if ($hr) {
            $teacher_homeroom_id = $hr['id'];
            $_GET['classroom_id'] = $teacher_homeroom_id; // Force filter
        }
    }
}

$page_title = "แบบบันทึกประจำห้อง";
include '../../includes/header.php';

// Get active academic term
$stmt_term = $pdo->query("SELECT term_number, year_name FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt_term->fetch();
$term_text = $active_term ? "ภาคเรียนที่ {$active_term['term_number']} ปีการศึกษา {$active_term['year_name']}" : "";

$filter_mode = $_GET['filter_mode'] ?? 'month'; // 'month' or 'range'
$selected_month = $_GET['month'] ?? date('n'); // 1-12
$selected_year = $_GET['year'] ?? date('Y');
$start_date_input = $_GET['start_date'] ?? date('Y-m-d');
$end_date_input = $_GET['end_date'] ?? date('Y-m-d');
$classroom_id = $_GET['classroom_id'] ?? '';
$grade_level = $_GET['grade_level'] ?? '';

$dates = [];

if ($filter_mode == 'month') {
    // Generate array of dates for the selected month, excluding weekends
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
    for ($d = 1; $d <= $days_in_month; $d++) {
        $date_string = sprintf("%04d-%02d-%02d", $selected_year, $selected_month, $d);
        $day_of_week = date('N', strtotime($date_string)); // 1 (Mon) - 7 (Sun)
        if ($day_of_week <= 5) {
            $dates[] = $date_string;
        }
    }
} else {
    // Generate array of dates for the selected range, excluding weekends
    if (strtotime($start_date_input) > strtotime($end_date_input)) {
        $temp = $start_date_input;
        $start_date_input = $end_date_input;
        $end_date_input = $temp;
    }
    $current = strtotime($start_date_input);
    $end = strtotime($end_date_input);
    while ($current <= $end) {
        $date_string = date('Y-m-d', $current);
        $day_of_week = date('N', $current);
        if ($day_of_week <= 5) {
            $dates[] = $date_string;
        }
        $current = strtotime('+1 day', $current);
    }
}

$start_date = !empty($dates) ? $dates[0] : '';
$end_date = !empty($dates) ? end($dates) : '';

// Get all classrooms for dropdown
if ($is_strict_teacher && $teacher_homeroom_id) {
    $stmt_c = $pdo->prepare("SELECT id, room_name, grade_level FROM classrooms WHERE id = ? ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC");
    $stmt_c->execute([$teacher_homeroom_id]);
} else {
    $stmt_c = $pdo->query("SELECT id, room_name, grade_level FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC");
}
$classrooms = $stmt_c->fetchAll();

// Get unique grade levels
$grade_levels = [];
foreach ($classrooms as $c) {
    $grade_levels[$c['grade_level']] = $c['grade_level'];
}
sort($grade_levels);

$target_classrooms = [];
if ($classroom_id) {
    $target_classrooms = array_filter($classrooms, fn($c) => $c['id'] == $classroom_id);
} else if ($grade_level) {
    $target_classrooms = array_filter($classrooms, fn($c) => $c['grade_level'] == $grade_level);
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

$reports_data = []; // Will store data indexed by classroom_id then date

if (!empty($target_classrooms) && !empty($dates)) {
    foreach ($target_classrooms as $target_c) {
        $cid = $target_c['id'];
        $room_name_display = $target_c['room_name'];
        $reports_data[$cid] = ['room_name' => $room_name_display, 'students' => [], 'dates' => []];
        
        // 1. Get Students
        $stmt_s = $pdo->prepare("SELECT id, student_number, full_name FROM students WHERE classroom_id = ? AND status = 'active' ORDER BY student_number ASC");
        $stmt_s->execute([$cid]);
        $students = $stmt_s->fetchAll();
        $reports_data[$cid]['students'] = $students;

        // Initialize all_data structure for each date
        $all_data = [];
        foreach ($dates as $d) {
            $all_data[$d] = [
                'matrix' => [],
                'period_logs' => array_fill(0, 10, ['subject' => '', 'topic' => '', 'teacher' => '', 'signature_path' => ''])
            ];
            foreach ($students as $s) {
                $all_data[$d]['matrix'][$s['id']] = array_fill(0, 10, '');
            }
        }

        // 2. Get Homeroom Data
        $stmt_hr = $pdo->prepare("
            SELECT ha.student_id, ha.status, u.full_name as teacher_name, ha.attendance_date, ha.signature_path
            FROM homeroom_attendance ha
            JOIN students s ON ha.student_id = s.id
            LEFT JOIN users u ON ha.recorded_by_teacher_id = u.id
            WHERE s.classroom_id = ? AND ha.attendance_date BETWEEN ? AND ?
        ");
        $stmt_hr->execute([$cid, $start_date, $end_date]);
        
        while ($row = $stmt_hr->fetch()) {
            $sid = $row['student_id'];
            $d = $row['attendance_date'];
            if (isset($all_data[$d]['matrix'][$sid])) {
                $all_data[$d]['matrix'][$sid][0] = $row['status'];
            }
            if ($row['teacher_name']) {
                $all_data[$d]['period_logs'][0]['teacher'] = $row['teacher_name'];
                if (!empty($row['signature_path'])) {
                    $all_data[$d]['period_logs'][0]['signature_path'] = $row['signature_path'];
                }
            }
        }
        
        foreach ($dates as $d) {
            $all_data[$d]['period_logs'][0]['subject'] = 'เข้าแถว/โฮมรูม';
            $all_data[$d]['period_logs'][0]['topic'] = '';
        }

        // 3. Get Class Attendance (Periods 1-9)
        $stmt_log = $pdo->prepare("
            SELECT tl.id as log_id, tl.period, tl.teaching_report_text, tl.log_date, s.subject_code, u.full_name as teacher_name, tl.signature_path,
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
            WHERE tl.classroom_id = ? AND tl.log_date BETWEEN ? AND ?
        ");
        $stmt_log->execute([$cid, $start_date, $end_date]);
        $logs = $stmt_log->fetchAll();

        $log_ids = [];
        foreach ($logs as $log) {
            $d = $log['log_date'];
            $periods = explode(',', $log['period']);
            foreach ($periods as $period_str) {
                $p = (int)trim($period_str);
                if ($p >= 1 && $p <= 9 && isset($all_data[$d])) {
                    $teacher_display = $log['teacher_name'];
                    if (!empty($log['original_teacher_name']) && $log['original_teacher_name'] != $log['teacher_name']) {
                        $teacher_display = "แทน " . $log['original_teacher_name'] . "\n(" . $log['teacher_name'] . ")";
                    }

                    $all_data[$d]['period_logs'][$p] = [
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
                SELECT ca.student_id, ca.status, tl.log_date, tl.period
                FROM class_attendance ca
                JOIN teaching_logs tl ON ca.teaching_log_id = tl.id
                WHERE ca.teaching_log_id IN ($in_placeholders)
            ");
            $stmt_att->execute($log_ids);
            while ($att = $stmt_att->fetch()) {
                $sid = $att['student_id'];
                $d = $att['log_date'];
                $periods = explode(',', $att['period']);
                foreach ($periods as $period_str) {
                    $p = (int)trim($period_str);
                    if (isset($all_data[$d]['matrix'][$sid])) {
                        $all_data[$d]['matrix'][$sid][$p] = $att['status'];
                    }
                }
            }
        }
        
        $reports_data[$cid]['dates'] = $all_data;
    }
}
?>

<div class="card no-print">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">📓 แบบบันทึกประจำห้อง (Daily Classroom Report)</h2>
    </div>

    <!-- Filter Form -->
    <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
        <?php if ($view_as_teacher): ?>
            <input type="hidden" name="view_as" value="teacher">
        <?php endif; ?>
        <div class="form-group" style="margin-bottom: 0;">
            <label>รูปแบบเวลา</label>
            <select name="filter_mode" id="filter_mode" class="form-control" onchange="toggleFilterMode()">
                <option value="month" <?= $filter_mode == 'month' ? 'selected' : '' ?>>รายเดือน</option>
                <option value="range" <?= $filter_mode == 'range' ? 'selected' : '' ?>>ระบุช่วงวันที่</option>
            </select>
        </div>

        <!-- Month Filter Fields -->
        <div id="month_filter" style="display: <?= $filter_mode == 'month' ? 'flex' : 'none' ?>; gap: 15px; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>เดือน</label>
                <select name="month" class="form-control">
                    <?php 
                    $thai_months_full = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
                    for($i=1; $i<=12; $i++): ?>
                        <option value="<?= $i ?>" <?= $selected_month == $i ? 'selected' : '' ?>><?= $thai_months_full[$i] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>ปี (พ.ศ.)</label>
                <select name="year" class="form-control">
                    <?php 
                    $current_y = date('Y');
                    for($i = $current_y - 2; $i <= $current_y + 1; $i++): ?>
                        <option value="<?= $i ?>" <?= $selected_year == $i ? 'selected' : '' ?>><?= $i + 543 ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>

        <!-- Range Filter Fields -->
        <div id="range_filter" style="display: <?= $filter_mode == 'range' ? 'flex' : 'none' ?>; gap: 15px; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>ตั้งแต่วันที่</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date_input) ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>ถึงวันที่</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date_input) ?>">
            </div>
        </div>
        
        <?php if (!$is_strict_teacher): ?>
        <div class="form-group" style="margin-bottom: 0; padding-left: 15px; border-left: 2px solid #eee;">
            <label>ระดับชั้น (ทั้งระดับ)</label>
            <select name="grade_level" class="form-control" onchange="document.getElementById('classroom_select').value = '';">
                <option value="">-- เลือกทั้งหมด --</option>
                <?php foreach($grade_levels as $gl): ?>
                    <option value="<?= $gl ?>" <?= $grade_level == $gl ? 'selected' : '' ?>><?= $gl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-group" style="margin-bottom: 0;">
            <label>หรือ เลือกห้องเรียน</label>
            <select name="classroom_id" id="classroom_select" class="form-control" onchange="document.querySelector('select[name=grade_level]').value = '';">
                <option value="">-- เลือกห้องเรียน --</option>
                <?php foreach($classrooms as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $classroom_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['room_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <button type="submit" class="btn" style="background-color: #1d4ed8; color: white;">แสดงรายงาน</button>
        <?php if (!empty($reports_data)): ?>
            <button type="button" class="btn btn-outline" style="border-color: #1d4ed8; color: #1d4ed8;" onclick="window.print()">🖨️ พิมพ์ (Print PDF)</button>
        <?php endif; ?>
    </form>
</div>

<script>
function toggleFilterMode() {
    var mode = document.getElementById('filter_mode').value;
    if (mode === 'month') {
        document.getElementById('month_filter').style.display = 'flex';
        document.getElementById('range_filter').style.display = 'none';
    } else {
        document.getElementById('month_filter').style.display = 'none';
        document.getElementById('range_filter').style.display = 'flex';
    }
}
</script>

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
                    $ar_logo = !empty($site_settings['school_logo']) ? $site_settings['school_logo'] : 'assets/images/school_logo.png';
                    $ar_name = !empty($site_settings['school_name']) ? $site_settings['school_name'] : 'โรงเรียนของคุณ';
                    ?>
                    <img src="<?= $base_url ?>/<?= htmlspecialchars($ar_logo) ?>" style="position: absolute; left: 0; top: 0; width: 45px; height: auto;" alt="Logo" onerror="this.src='<?= $base_url ?>/assets/images/school_logo.png'">
                    
                    <div style="flex-grow: 1;">
                        <h3 style="font-size: 16px; font-weight: bold; margin: 0 0 5px;"><?= htmlspecialchars($ar_name) ?></h3>
                        <h4 style="font-size: 14px; margin: 0 0 5px;">แบบบันทึกการเข้าชั้นเรียนและบันทึกการสอน (<?= $term_text ?>)</h4>
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
