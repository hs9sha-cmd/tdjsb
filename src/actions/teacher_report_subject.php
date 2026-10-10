<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'teacher');

$page_title = "รายงานการเข้าเรียนรายวิชา";
$teacher_id = $_SESSION['user_id'];

// Get Active Term
$term_stmt = $pdo->query("SELECT id, year_name, term_number FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term = $term_stmt->fetch();

$selected_subject = $_GET['subject_id'] ?? '';
$selected_classroom = $_GET['classroom_id'] ?? '';

// Fetch ONLY Subjects assigned to this teacher
$stmt_sub = $pdo->prepare("SELECT DISTINCT s.id, s.subject_code, s.subject_name FROM teacher_subjects ts JOIN subjects s ON ts.subject_id = s.id WHERE ts.teacher_id = ? ORDER BY s.subject_code ASC");
$stmt_sub->execute([$teacher_id]);
$subjects = $stmt_sub->fetchAll();

// Fetch ONLY Classrooms assigned to this teacher (and filter by subject if selected)
if ($selected_subject) {
    $stmt_cls = $pdo->prepare("SELECT DISTINCT c.id, c.grade_level, c.room_number, c.room_name FROM teacher_subjects ts JOIN classrooms c ON ts.classroom_id = c.id WHERE ts.teacher_id = ? AND ts.subject_id = ? ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC");
    $stmt_cls->execute([$teacher_id, $selected_subject]);
} else {
    $stmt_cls = $pdo->prepare("SELECT DISTINCT c.id, c.grade_level, c.room_number, c.room_name FROM teacher_subjects ts JOIN classrooms c ON ts.classroom_id = c.id WHERE ts.teacher_id = ? ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC");
    $stmt_cls->execute([$teacher_id]);
}
$classrooms = $stmt_cls->fetchAll();

$error = '';
if (!$active_term) {
    $error = "ยังไม่มีการตั้งค่าเทอมปัจจุบัน กรุณาติดต่อแอดมิน";
}

$students = [];
$logs_info = []; // To hold date & period
$attendance_matrix = []; 
$summary = [];

if (!$error && $selected_subject && $selected_classroom) {
    // Validate assignment
    $stmt_check = $pdo->prepare("SELECT id FROM teacher_subjects WHERE teacher_id=? AND subject_id=? AND classroom_id=?");
    $stmt_check->execute([$teacher_id, $selected_subject, $selected_classroom]);
    if (!$stmt_check->fetch()) {
        $error = "คุณไม่ได้รับมอบหมายให้สอนวิชานี้กับห้องเรียนนี้";
    }

    if (!$error) {
        // A. Get Students
        $st_stmt = $pdo->prepare("SELECT id, student_code, full_name, student_number FROM students WHERE classroom_id = ? ORDER BY CAST(student_number AS UNSIGNED) ASC, student_code ASC");
        $st_stmt->execute([$selected_classroom]);
        $students = $st_stmt->fetchAll();

        foreach ($students as $s) {
            $summary[$s['id']] = ['present'=>0, 'late'=>0, 'leave'=>0, 'absent'=>0];
        }

        // B. Get all teaching logs for this term/subject/class
        // Note: For simplicity and assuming logs are within the active term. In a full system, logs should be filtered by date ranges mapped to the term.
        $log_stmt = $pdo->prepare("SELECT id, log_date, period FROM teaching_logs WHERE teacher_id = ? AND subject_id = ? AND classroom_id = ? ORDER BY log_date ASC, period ASC");
        $log_stmt->execute([$teacher_id, $selected_subject, $selected_classroom]);
        $logs = $log_stmt->fetchAll();

        $log_ids = [];
        foreach ($logs as $l) {
            $logs_info[$l['id']] = ['date' => $l['log_date'], 'period' => $l['period']];
            $log_ids[] = $l['id'];
        }

        // C. Get Attendance Data
        if (!empty($log_ids)) {
            $in_clause = implode(',', array_fill(0, count($log_ids), '?'));
            $att_stmt = $pdo->prepare("SELECT teaching_log_id, student_id, status FROM class_attendance WHERE teaching_log_id IN ($in_clause)");
            $att_stmt->execute($log_ids);
            $att_data = $att_stmt->fetchAll();

            foreach ($att_data as $row) {
                $sid = $row['student_id'];
                $status = $row['status'];
                $lid = $row['teaching_log_id'];
                
                $attendance_matrix[$sid][$lid] = $status;
                
                if (isset($summary[$sid][$status])) {
                    $summary[$sid][$status]++;
                }
            }
        }
    }
}

// Logic to check 3 consecutive absences
function hasThreeConsecutiveAbsences($student_id, $log_ids, $attendance_matrix) {
    if (count($log_ids) < 3) return false;
    
    $consecutive = 0;
    foreach ($log_ids as $lid) {
        $status = $attendance_matrix[$student_id][$lid] ?? 'present';
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
        
        .warn-row { background-color: #fee2e2 !important; -webkit-print-color-adjust: exact; }
        
        th.rotate { height: 60px !important; position: relative; padding: 0 !important; }
        th.rotate > div { font-size: 6pt !important; transform: rotate(270deg); width: 16px; margin: 0 auto; position: absolute; bottom: 5px; left: 50%; transform-origin: left bottom; margin-left: -5px; }
        
        .text-left { text-align: left !important; font-size: 7.5pt !important; white-space: normal !important; }
        .selection-form { display: none !important; }
        .warn-badge { display: none !important; }
        .report-header { text-align: center; margin-bottom: 5px; }
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
    height: 120px;
    white-space: nowrap;
    position: relative;
    vertical-align: bottom;
    padding-bottom: 10px;
}
th.rotate > div {
    transform: rotate(270deg);
    width: 20px;
    font-size: 10px;
}
.print-only { display: none; }
</style>

<div class="card" style="max-width: 100%; padding-bottom: 40px;">
    <div class="no-print" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">รายงานการเข้าเรียนรายวิชา</h2>
        <div>
            <a href="teacher_home.php" class="btn btn-outline" style="margin-right: 10px;">⬅️ กลับ</a>
            <?php if (!empty($students) && !$error): ?>
                <button onclick="window.print()" class="btn btn-outline" style="border-color: #1d4ed8; color: #1d4ed8;">🖨️ พิมพ์ / PDF</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="badge badge-danger mb-3" style="display:block; padding:15px; font-size:16px;"><?= $error ?></div>
    <?php endif; ?>

    <!-- Selection Form -->
    <form method="GET" class="selection-form no-print" style="background: rgba(0,0,0,0.02); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 20px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
            <div class="form-group mb-0">
                <label class="form-label">รายวิชา</label>
                <select name="subject_id" class="form-control" required onchange="this.form.submit()">
                    <option value="">-- เลือกวิชา --</option>
                    <?php foreach($subjects as $subj): ?>
                        <option value="<?= $subj['id'] ?>" <?= $selected_subject == $subj['id'] ? 'selected' : '' ?>><?= htmlspecialchars($subj['subject_code'] . ' ' . $subj['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group mb-0">
                <label class="form-label">ห้องเรียน</label>
                <select name="classroom_id" class="form-control" required onchange="this.form.submit()">
                    <option value="">-- เลือกห้อง --</option>
                    <?php foreach($classrooms as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $selected_classroom == $cls['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cls['room_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>

    <?php if ($selected_subject && $selected_classroom && empty($error)): ?>
        <?php
        $subj_name = '';
        foreach($subjects as $s) {
            if ($s['id'] == $selected_subject) $subj_name = $s['subject_code'] . ' ' . $s['subject_name'];
        }
        $cls_name = '';
        foreach($classrooms as $c) {
            if ($c['id'] == $selected_classroom) $cls_name = $c['room_name'];
        }
        ?>
        
        <div class="report-header" style="text-align: center; margin-bottom: 20px; padding-top: 10px;">
            <h2 style="margin-bottom: 10px;">รายงานการเช็คชื่อรายวิชา</h2>
            <p style="font-size: 1.1rem; margin-bottom: 5px;">วิชา: <strong><?= htmlspecialchars($subj_name) ?></strong> | ห้อง: <strong><?= htmlspecialchars($cls_name) ?></strong></p>
            <p style="font-size: 1.1rem; margin-bottom: 15px;">ภาคเรียนที่: <strong><?= $active_term['term_number'] ?>/<?= $active_term['year_name'] ?></strong></p>
        </div>

        <div class="scroll-table">
            <table class="table" style="min-width: 1000px;">
                <thead>
                    <tr>
                        <th class="col-num" style="text-align:center;">ลำดับ</th>
                        <th class="col-id" style="text-align:center;">รหัส</th>
                        <th class="col-name">ชื่อ-สกุล</th>
                        <?php foreach($log_ids as $lid): ?>
                            <th class="rotate" style="text-align:center;" title="วันที่ <?= date('d/m', strtotime($logs_info[$lid]['date'])) ?> คาบ <?= htmlspecialchars($logs_info[$lid]['period']) ?>">
                                <div>
                                    <?= date('d/m', strtotime($logs_info[$lid]['date'])) ?><br>
                                    <small>(ค.<?= htmlspecialchars($logs_info[$lid]['period']) ?>)</small>
                                </div>
                            </th>
                        <?php endforeach; ?>
                        <?php if (empty($log_ids)): ?>
                            <th style="text-align:center; color:#ccc;">ยังไม่มีการสอน</th>
                        <?php endif; ?>
                        
                        <th style="text-align:center; background:#dcfce7 !important;">มา</th>
                        <th style="text-align:center; background:#fef08a !important;">สาย</th>
                        <th style="text-align:center; background:#e0e7ff !important;">ลา</th>
                        <th style="text-align:center; background:#fee2e2 !important; color:#991B1B;">ขาด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($students)): ?>
                        <tr><td colspan="<?= count($log_ids)+7 ?>" style="text-align:center;">ไม่พบรายชื่อนักเรียนในห้องนี้</td></tr>
                    <?php endif; ?>
                    
                    <?php foreach($students as $index => $s): ?>
                        <?php 
                        $sid = $s['id'];
                        $is_warn = hasThreeConsecutiveAbsences($sid, $log_ids, $attendance_matrix);
                        $row_class = $is_warn ? 'warn-row' : '';
                        ?>
                        <tr class="<?= $row_class ?>">
                            <td style="text-align:center;"><?= $s['student_number'] ?: ($index+1) ?></td>
                            <td style="text-align:center;"><?= htmlspecialchars($s['student_code']) ?></td>
                            <td class="text-left">
                                <?= htmlspecialchars($s['full_name']) ?>
                                <?php if($is_warn): ?><span class="warn-badge">⚠️ ขาด 3 ครั้งติด</span><?php endif; ?>
                            </td>
                            
                            <?php foreach($log_ids as $lid): ?>
                                <?php 
                                $status = $attendance_matrix[$sid][$lid] ?? null; 
                                echo '<td style="text-align:center; font-size:11px;">' . ($status ? $status_symbols[$status] : '-') . '</td>';
                                ?>
                            <?php endforeach; ?>
                            <?php if (empty($log_ids)): ?>
                                <td style="text-align:center;">-</td>
                            <?php endif; ?>
                            
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
