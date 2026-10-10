<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
$is_teacher = in_array('teacher', $user_roles);
$can_view_all = in_array('academic', $user_roles) || in_array('discipline', $user_roles) || in_array('executive', $user_roles) || in_array('admin', $user_roles);

if (!$is_teacher && !$can_view_all) {
    echo "Access Denied";
    exit();
}

$page_title = "สมุดบันทึกกิจกรรมหน้าเสาธง (รายเดือน)";
include '../../includes/header.php';

// Get active academic term
$stmt_term = $pdo->query("SELECT term_number, year_name FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt_term->fetch();
$term_text = $active_term ? "ภาคเรียนที่ {$active_term['term_number']} ปีการศึกษา {$active_term['year_name']}" : "";

// Inputs
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

// Fetch classrooms
$classrooms = [];
if ($can_view_all) {
    $classrooms = $pdo->query("SELECT id, room_name, grade_level FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC")->fetchAll();
} else {
    // Teacher sees only their homeroom(s)
    $stmt = $pdo->prepare("
        SELECT c.id, c.room_name, c.grade_level 
        FROM homeroom_teachers ht
        JOIN classrooms c ON ht.classroom_id = c.id
        WHERE ht.teacher_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $classrooms = $stmt->fetchAll();
    
    // Auto-select if they only have one
    if (count($classrooms) == 1 && !$classroom_id) {
        $classroom_id = $classrooms[0]['id'];
    }
}

// Get unique grade levels
$grade_levels = [];
foreach ($classrooms as $c) {
    $grade_levels[$c['grade_level']] = $c['grade_level'];
}
sort($grade_levels);

$students = [];
$matrix = []; // student_id -> [date -> status]
$totals = []; // student_id -> present_count
$room_names = []; // student_id -> room_name (if viewing whole grade)

if ($classroom_id || $grade_level) {
    $query_class_filter = "";
    $params = [];
    
    if ($classroom_id) {
        $query_class_filter = "s.classroom_id = ?";
        $params[] = $classroom_id;
    } else if ($grade_level) {
        $query_class_filter = "c.grade_level = ?";
        $params[] = $grade_level;
    }
    
    // Fetch students
    $stmt_s = $pdo->prepare("
        SELECT s.id, s.student_number, s.full_name, c.room_name 
        FROM students s
        JOIN classrooms c ON s.classroom_id = c.id
        WHERE $query_class_filter AND s.status = 'active'
        ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC, s.student_number ASC
    ");
    $stmt_s->execute($params);
    $students = $stmt_s->fetchAll();
    
    // Pre-fill matrix
    foreach ($students as $s) {
        $matrix[$s['id']] = array_fill_keys($dates, '');
        $totals[$s['id']] = 0;
        $room_names[$s['id']] = $s['room_name'];
    }
    
    // Fetch attendance for the month
    if (!empty($students) && !empty($dates)) {
        $start_date = $dates[0];
        $end_date = end($dates);
        
        $stmt_att = $pdo->prepare("
            SELECT ha.student_id, ha.attendance_date, ha.status 
            FROM homeroom_attendance ha
            JOIN students s ON ha.student_id = s.id
            JOIN classrooms c ON s.classroom_id = c.id
            WHERE $query_class_filter AND ha.attendance_date BETWEEN ? AND ?
        ");
        $params_att = array_merge($params, [$start_date, $end_date]);
        $stmt_att->execute($params_att);
        
        while ($row = $stmt_att->fetch()) {
            $sid = $row['student_id'];
            $d = $row['attendance_date'];
            $st = $row['status'];
            
            if (isset($matrix[$sid][$d])) {
                $matrix[$sid][$d] = $st;
                if ($st === 'present' || $st === 'late') {
                    $totals[$sid]++;
                }
            }
        }
    }
}

// Format translation
function formatStatus($status) {
    switch ($status) {
        case 'present': return '✓';
        case 'late': return 'ส';
        case 'leave': return 'ล';
        case 'absent': return 'ข';
        default: return '';
    }
}

$thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];

?>

<div class="card no-print">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">📄 รายงานกิจกรรมหน้าเสาธง (รายเดือน)</h2>
    </div>

    <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
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
                    <?php for($i=1; $i<=12; $i++): ?>
                        <option value="<?= $i ?>" <?= $selected_month == $i ? 'selected' : '' ?>><?= $thai_months[$i] ?></option>
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
        
        <?php if ($can_view_all): ?>
            <!-- Admin/Academic/Discipline can filter by Grade OR Classroom -->
            <div class="form-group" style="margin-bottom: 0; padding-left: 15px; border-left: 2px solid #eee;">
                <label>ระดับชั้น (ทั้งระดับ)</label>
                <select name="grade_level" class="form-control" onchange="document.getElementById('classroom_select').value = '';">
                    <option value="">-- เลือกทั้งหมดในระดับ --</option>
                    <?php foreach($grade_levels as $gl): ?>
                        <option value="<?= $gl ?>" <?= $grade_level == $gl ? 'selected' : '' ?>><?= $gl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>หรือ เลือกห้องเรียน</label>
                <select name="classroom_id" id="classroom_select" class="form-control" onchange="document.querySelector('select[name=grade_level]').value = '';">
                    <option value="">-- เลือกห้องเรียน --</option>
                    <?php foreach($classrooms as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $classroom_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['room_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <!-- Teacher can only filter by their assigned classrooms -->
            <div class="form-group" style="margin-bottom: 0; padding-left: 15px; border-left: 2px solid #eee;">
                <label>ห้องเรียน</label>
                <select name="classroom_id" class="form-control" required>
                    <option value="">-- เลือกห้องเรียน --</option>
                    <?php foreach($classrooms as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $classroom_id == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['room_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <button type="submit" class="btn" style="background-color: #ec4899; color: white;">แสดงรายงาน</button>
        <?php if ($classroom_id || $grade_level): ?>
            <button type="button" class="btn btn-outline" style="border-color: #ec4899; color: #ec4899;" onclick="window.print()">🖨️ พิมพ์ (Print PDF)</button>
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
</div>

<?php if ($classroom_id || $grade_level): ?>
<style>
/* Base styles for screen and print to ensure they look identical */
#print-area {
    width: 100%;
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-top: 20px;
    overflow-x: auto;
}
.print-page {
    margin-bottom: 40px;
    padding-bottom: 20px;
    border-bottom: 2px dashed #ccc;
}
.print-page:last-of-type {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}
.t-print {
    width: 100%;
    border-collapse: collapse;
    font-size: 10pt;
    margin-top: 15px;
}
.t-print th, .t-print td {
    border: 1px solid #000 !important;
    padding: 4px 2px !important;
    text-align: center;
    vertical-align: middle;
}
.t-print .name-col {
    text-align: left;
    padding-left: 5px !important;
    white-space: nowrap;
}
.report-title {
    text-align: center;
    font-size: 14pt;
    font-weight: bold;
    margin-bottom: 5px;
}
.report-subtitle {
    text-align: center;
    font-size: 12pt;
    margin-bottom: 10px;
}
.date-col {
    height: 60px;
    vertical-align: middle !important;
    padding: 2px 0 !important;
}
.rotate-text {
    writing-mode: vertical-rl;
    transform: rotate(180deg);
    white-space: nowrap;
    font-size: 7.5pt;
    display: inline-block;
}

@media print {
    @page {
        size: A4 portrait;
        margin: 8mm;
    }
    body * {
        visibility: hidden;
    }
    #print-area, #print-area * {
        visibility: visible;
    }
    #print-area {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 0;
        box-shadow: none;
        margin-top: 0;
        border-radius: 0;
    }
    .print-page {
        page-break-after: always;
        margin-bottom: 0;
        padding-bottom: 0;
        border-bottom: none;
        display: block;
        width: 100%;
        overflow: hidden;
    }
    .print-page:last-of-type {
        page-break-after: auto;
    }
    .t-print {
        font-size: 8pt; /* Smaller to fit up to 50 rows */
        line-height: 1;
    }
    .t-print th, .t-print td {
        padding: 1px !important;
        height: 16px !important; /* Force small row height */
    }
    .date-col {
        height: 60px; /* Reduce header height */
    }
    .rotate-text {
        font-size: 7.5pt;
    }
    .report-title {
        font-size: 12pt;
        margin-bottom: 2px;
    }
    .report-subtitle {
        font-size: 10pt;
        margin-bottom: 5px;
    }
}
</style>

<div id="print-area">
    <?php
    // Group by classroom (1 room = 1 page)
    $student_groups = [];
    $grouped = [];
    foreach ($students as $s) {
        $grouped[$s['room_name']][] = $s;
    }
    foreach ($grouped as $room_name => $room_students) {
        // Fetch homeroom teacher info for this room
        // Extract grade_level and room_number if possible, or just query by classroom_id
        // We know students have classroom_id, but here we just have room_name. 
        // Let's get the classroom_id from the first student in the room.
        $cid = null;
        $stmt_cid = $pdo->prepare("SELECT id FROM classrooms WHERE room_name = ?");
        $stmt_cid->execute([$room_name]);
        $cid = $stmt_cid->fetchColumn();

        $teacher_name = "";
        $signature_path = "";

        if ($cid) {
            $stmt_ht = $pdo->prepare("SELECT u.full_name FROM homeroom_teachers ht JOIN users u ON ht.teacher_id = u.id WHERE ht.classroom_id = ? LIMIT 1");
            $stmt_ht->execute([$cid]);
            $teacher_name = $stmt_ht->fetchColumn() ?: "";

            // Try to get the latest signature and teacher name from homeroom_attendance for this class in this month
            if (!empty($dates)) {
                $start_d = $dates[0];
                $end_d = end($dates);
                $stmt_sig = $pdo->prepare("
                    SELECT ha.signature_path, u.full_name as recorded_by
                    FROM homeroom_attendance ha 
                    JOIN students s ON ha.student_id = s.id 
                    LEFT JOIN users u ON ha.recorded_by_teacher_id = u.id
                    WHERE s.classroom_id = ? AND ha.attendance_date BETWEEN ? AND ? AND ha.signature_path IS NOT NULL AND ha.signature_path != ''
                    ORDER BY ha.attendance_date DESC LIMIT 1
                ");
                $stmt_sig->execute([$cid, $start_d, $end_d]);
                $sig_row = $stmt_sig->fetch(PDO::FETCH_ASSOC);
                
                if ($sig_row) {
                    $signature_path = $sig_row['signature_path'];
                    if (!empty($sig_row['recorded_by'])) {
                        $teacher_name = $sig_row['recorded_by'];
                    }
                }
            }
        }

        $student_groups[] = [
            'room' => $room_name, 
            'students' => $room_students,
            'teacher_name' => $teacher_name,
            'signature_path' => $signature_path
        ];
    }
    ?>

    <?php foreach ($student_groups as $group): 
        // Fetch homeroom teachers for this room
        $stmt_ht = $pdo->prepare("SELECT full_name FROM users WHERE TRIM(advisor_classroom) = ?");
        $stmt_ht->execute([trim($group['room'])]);
        $teachers = $stmt_ht->fetchAll(PDO::FETCH_COLUMN);
        $teachers = array_filter($teachers);
        $teachers_str = !empty($teachers) ? implode(", ", $teachers) : "-";
    ?>
    <div class="print-page">
        <div class="report-header" style="position: relative; text-align: center; margin-bottom: 25px;">
            <?php 
            $rh_logo = !empty($site_settings['school_logo']) ? $site_settings['school_logo'] : 'assets/images/school_logo.png';
            $rh_name = !empty($site_settings['school_name']) ? $site_settings['school_name'] : 'โรงเรียนของคุณ';
            ?>
            <img src="<?= $base_url ?>/<?= htmlspecialchars($rh_logo) ?>" style="position: absolute; left: 0; top: 0; width: 45px; height: auto;" alt="Logo" onerror="this.src='<?= $base_url ?>/assets/images/school_logo.png'">
            
            <div style="flex-grow: 1;">
                <h3 style="font-size: 16px; font-weight: bold; margin: 0 0 5px;"><?= htmlspecialchars($rh_name) ?></h3>
                <h4 style="font-size: 14px; margin: 0 0 5px;">สมุดบันทึกกิจกรรมหน้าเสาธง (<?= $term_text ?>)</h4>
                <p style="font-size: 12px; margin: 0;">
                    <strong>ชั้น :</strong> <?= htmlspecialchars($group['room']) ?> &nbsp;&nbsp;&nbsp;&nbsp; 
                    <strong>ครูที่ปรึกษา :</strong> <?= htmlspecialchars($teachers_str) ?>
                    <br>
                    <?php 
                    if ($filter_mode == 'month') {
                        echo "ประจำเดือน {$thai_months[(int)$selected_month]} พ.ศ. " . ($selected_year + 543);
                    } else {
                        $sd = date('j', strtotime($start_date_input));
                        $sm = $thai_months[date('n', strtotime($start_date_input))];
                        $sy = date('Y', strtotime($start_date_input)) + 543;
                        $ed = date('j', strtotime($end_date_input));
                        $em = $thai_months[date('n', strtotime($end_date_input))];
                        $ey = date('Y', strtotime($end_date_input)) + 543;
                        echo "ประจำช่วงวันที่ {$sd} {$sm} {$sy} ถึง {$ed} {$em} {$ey}";
                    }
                    ?>
                </p>
            </div>
        </div>

        <div style="width: 100%;">
            <table class="t-print" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="width: 30px;">ที่</th>
                        <th class="name-col" style="min-width: 150px;">ชื่อ - นามสกุล</th>
                        <?php foreach ($dates as $d): ?>
                            <th class="date-col"><div class="rotate-text"><?= date('d/m', strtotime($d)) ?></div></th>
                        <?php endforeach; ?>
                        <th style="width: 50px;">รวมจำนวน<br>วันที่มา</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($group['students'] as $index => $s): ?>
                    <tr>
                        <td><?= $s['student_number'] ?></td>
                        <td class="name-col"><?= htmlspecialchars($s['full_name']) ?></td>
                        <?php foreach ($dates as $d): ?>
                            <td style="font-weight: bold;"><?= formatStatus($matrix[$s['id']][$d]) ?></td>
                        <?php endforeach; ?>
                        <td style="font-weight: bold;"><?= $totals[$s['id']] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Signatures (Only Homeroom Teacher) -->
        <div style="margin-top: 20px; display: flex; justify-content: center; text-align: center; line-height: 2;">
            <div>
                ลงชื่อ
                <span style="display: inline-block; min-width: 160px; border-bottom: 1px dotted #000; text-align: center; margin: 0 5px; vertical-align: bottom; height: 35px;">
                    <?php if ($group['signature_path']): ?>
                        <img src="../../<?= htmlspecialchars($group['signature_path']) ?>" style="max-height: 35px; margin-bottom: -5px; mix-blend-mode: multiply;">
                    <?php endif; ?>
                </span>
                ผู้บันทึก<br>
                ( <?= $group['teacher_name'] ? htmlspecialchars($group['teacher_name']) : '............................................................' ?> )<br>
                ครูที่ปรึกษา
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
