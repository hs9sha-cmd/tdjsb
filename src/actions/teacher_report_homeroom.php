<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'teacher');

$page_title = "รายงานการเช็คชื่อคาบโฮมรูม";
$teacher_id = $_SESSION['user_id'];

// 1. Get Teacher's Advisor Classroom
$stmt = $pdo->prepare("
    SELECT u.advisor_classroom, c.id as classroom_id 
    FROM users u 
    LEFT JOIN classrooms c ON c.room_name = u.advisor_classroom
    WHERE u.id = ?
");
$stmt->execute([$teacher_id]);
$teacher_info = $stmt->fetch();

$advisor_room_name = $teacher_info['advisor_classroom'] ?? '';
$classroom_id = $teacher_info['classroom_id'] ?? null;

// 2. Get Active Term
$term_stmt = $pdo->query("SELECT id, year_name, term_number FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term = $term_stmt->fetch();

$error = '';
if (!$active_term) {
    $error = "ยังไม่มีการตั้งค่าเทอมปัจจุบัน กรุณาติดต่อแอดมิน";
} elseif (!$classroom_id) {
    $error = "คุณไม่มีห้องประจำชั้น หรือชื่อห้องประจำชั้นของคุณไม่ตรงกับระบบ";
}

$students = [];
$dates = [];
$attendance_matrix = []; // [student_id][date] = status
$summary = []; // [student_id] = ['present'=>0, 'late'=>0, 'leave'=>0, 'absent'=>0]

if (!$error) {
    // A. Get Students
    $st_stmt = $pdo->prepare("SELECT id, student_code, full_name, student_number FROM students WHERE classroom_id = ? ORDER BY CAST(student_number AS UNSIGNED) ASC, student_code ASC");
    $st_stmt->execute([$classroom_id]);
    $students = $st_stmt->fetchAll();

    foreach ($students as $s) {
        $summary[$s['id']] = ['present'=>0, 'late'=>0, 'leave'=>0, 'absent'=>0];
    }

    // B. Get unique dates from homeroom_attendance for this classroom
    $date_stmt = $pdo->prepare("SELECT DISTINCT ha.attendance_date FROM homeroom_attendance ha JOIN students s ON ha.student_id = s.id WHERE s.classroom_id = ? ORDER BY attendance_date ASC");
    $date_stmt->execute([$classroom_id]);
    $dates_data = $date_stmt->fetchAll();

    foreach ($dates_data as $row) {
        $dates[] = $row['attendance_date'];
    }

    // C. Get Attendance Data
    if (!empty($dates)) {
        $att_stmt = $pdo->prepare("SELECT ha.attendance_date, ha.student_id, ha.status FROM homeroom_attendance ha JOIN students s ON ha.student_id = s.id WHERE s.classroom_id = ?");
        $att_stmt->execute([$classroom_id]);
        $att_data = $att_stmt->fetchAll();

        foreach ($att_data as $row) {
            $sid = $row['student_id'];
            $status = $row['status'];
            $date = $row['attendance_date'];
            
            $attendance_matrix[$sid][$date] = $status;
            
            if (isset($summary[$sid][$status])) {
                $summary[$sid][$status]++;
            }
        }
    }
}

// Logic to check 3 consecutive absences
function hasThreeConsecutiveAbsences($student_id, $dates, $attendance_matrix) {
    if (count($dates) < 3) return false;
    
    $consecutive = 0;
    foreach ($dates as $d) {
        $status = $attendance_matrix[$student_id][$d] ?? 'present'; // default present if not marked? Actually it should be empty but let's say absent.
        // Wait, if not marked, it's null.
        if ($status === 'absent') {
            $consecutive++;
            if ($consecutive >= 3) return true;
        } else {
            $consecutive = 0;
        }
    }
    return false;
}

$status_symbols = [
    'present' => '<span style="color:var(--success-color);">มา</span>',
    'late' => '<span style="color:var(--warning-color);">สาย</span>',
    'leave' => '<span style="color:#1d4ed8;">ลา</span>',
    'absent' => '<span style="color:var(--danger-color); font-weight:bold;">ขาด</span>',
];

include '../../includes/header.php';
?>

    <style>
    .col-num { width: 40px; }
    .col-id { width: 80px; }
    .col-name { width: 200px; }
    
    @media print {
        @page { size: A4 portrait; margin: 5mm; }
        body, .main-content, .card { 
            width: 100% !important; 
            max-width: 100% !important; 
            margin: 0 !important; 
            padding: 0 !important; 
            zoom: unset !important;
        }
        .scroll-table { 
            overflow: visible !important; 
            width: 100% !important; 
        }
        table { 
            width: 100% !important; 
            min-width: 0 !important;
            table-layout: fixed !important; 
            border-collapse: collapse !important;
        }
        table td, table th { font-size: 7pt !important; padding: 2px 1px !important; text-align: center; border: 1px solid #777 !important; word-wrap: break-word; overflow: hidden; }
        
        .col-num { width: 25px !important; }
        .col-id { width: 45px !important; }
        .col-name { width: 140px !important; white-space: normal !important; text-align: left !important; }
        
        .report-header { text-align: center; margin-bottom: 5px; }
        .warn-row { background-color: #fee2e2 !important; -webkit-print-color-adjust: exact; }
        
        th.rotate { height: 60px !important; position: relative; padding: 0 !important; }
        th.rotate > div { font-size: 6pt !important; transform: rotate(270deg); width: 16px; margin: 0 auto; letter-spacing: -0.5px; position: absolute; bottom: 5px; left: 50%; transform-origin: left bottom; margin-left: -5px; }
        
        .text-left { text-align: left !important; font-size: 7.5pt !important; white-space: normal !important; }
        .warn-badge { display: none !important; }
    }
    .scroll-table {
        overflow-x: auto;
        width: 100%;
    }
.warn-row {
    background-color: #fee2e2;
}
.warn-badge {
    background: var(--danger-color);
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 11px;
    margin-left: 5px;
    font-weight: normal;
}
th.rotate {
    height: 100px;
    white-space: nowrap;
    position: relative;
    vertical-align: bottom;
    padding-bottom: 10px;
}
th.rotate > div {
    transform: rotate(270deg);
    width: 20px;
}
</style>

<div class="card" style="max-width: 100%; padding-bottom: 40px;">
    <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">รายงานการเช็คชื่อคาบ 0 (โฮมรูม)</h2>
        <div>
            <a href="teacher_home.php" class="btn btn-outline" style="margin-right: 10px;">⬅️ กลับ</a>
            <button onclick="window.print()" class="btn btn-outline" style="border-color: #1d4ed8; color: #1d4ed8;">🖨️ พิมพ์ / PDF</button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="badge badge-danger" style="display:block; padding:15px; font-size:16px;"><?= $error ?></div>
    <?php else: ?>
        <div class="report-header" style="text-align: center; margin-bottom: 20px;">
            <h2 style="margin-bottom: 10px;">รายงานการเช็คชื่อคาบโฮมรูม</h2>
            <p style="font-size: 1.1rem; margin-bottom: 5px;">ห้อง: <strong><?= htmlspecialchars($advisor_room_name) ?></strong></p>
            <p style="font-size: 1.1rem; margin-bottom: 15px;">ภาคเรียนที่: <strong><?= $active_term['term_number'] ?>/<?= $active_term['year_name'] ?></strong></p>
        </div>

        <div class="scroll-table">
            <table class="table" style="min-width: 1000px;">
                <thead>
                    <tr>
                        <th class="col-num" style="text-align:center;">ลำดับ</th>
                        <th class="col-id" style="text-align:center;">รหัส</th>
                        <th class="col-name">ชื่อ-สกุล</th>
                        <?php foreach($dates as $d): ?>
                            <th class="rotate" style="text-align:center;"><div><?= date('d/m', strtotime($d)) ?></div></th>
                        <?php endforeach; ?>
                        <th style="text-align:center; background:#dcfce7 !important;">มา</th>
                        <th style="text-align:center; background:#fef08a !important;">สาย</th>
                        <th style="text-align:center; background:#e0e7ff !important;">ลา</th>
                        <th style="text-align:center; background:#fee2e2 !important; color:#991B1B;">ขาด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($students)): ?>
                        <tr><td colspan="<?= count($dates)+7 ?>" style="text-align:center;">ไม่พบรายชื่อนักเรียนในห้องนี้</td></tr>
                    <?php endif; ?>
                    
                    <?php foreach($students as $index => $s): ?>
                        <?php 
                        $sid = $s['id'];
                        $is_warn = hasThreeConsecutiveAbsences($sid, $dates, $attendance_matrix);
                        $row_class = $is_warn ? 'warn-row' : '';
                        ?>
                        <tr class="<?= $row_class ?>">
                            <td style="text-align:center;"><?= $s['student_number'] ?: ($index+1) ?></td>
                            <td style="text-align:center;"><?= htmlspecialchars($s['student_code']) ?></td>
                            <td class="text-left">
                                <?= htmlspecialchars($s['full_name']) ?>
                                <?php if($is_warn): ?><span class="warn-badge">⚠️ ขาด 3 ครั้งติด</span><?php endif; ?>
                            </td>
                            
                            <?php foreach($dates as $d): ?>
                                <?php 
                                $status = $attendance_matrix[$sid][$d] ?? null; 
                                echo '<td style="text-align:center; font-size:11px;">' . ($status ? $status_symbols[$status] : '-') . '</td>';
                                ?>
                            <?php endforeach; ?>
                            
                            <td style="text-align:center; font-weight:bold; color:var(--success-color);"><?= $summary[$sid]['present'] ?></td>
                            <td style="text-align:center; font-weight:bold; color:var(--warning-color);"><?= $summary[$sid]['late'] ?></td>
                            <td style="text-align:center; font-weight:bold; color:#1d4ed8;"><?= $summary[$sid]['leave'] ?></td>
                            <td style="text-align:center; font-weight:bold; color:var(--danger-color);"><?= $summary[$sid]['absent'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="mt-3 no-print" style="font-size: 0.9rem; color: var(--text-muted);">
            <strong>หมายเหตุ:</strong> ระบบจะไฮไลท์สีแดงและแจ้งเตือนอัตโนมัติหากนักเรียนมีประวัติ <u>ขาดเรียน 3 ครั้งติดต่อกัน</u>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
