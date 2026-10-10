<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, ['teacher', 'admin']);

$teacher_id = $_SESSION['user_id'];
$error = '';

// Handle Decline Substitution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'decline_sub') {
    $sub_id = $_POST['decline_sub_id'];
    $stmt_info = $pdo->prepare("SELECT ts.original_teacher_id, s.subject_name, ts.period FROM teaching_substitutions ts JOIN subjects s ON ts.subject_id = s.id WHERE ts.id = ? AND ts.substitute_teacher_id = ?");
    $stmt_info->execute([$sub_id, $teacher_id]);
    if ($sinfo = $stmt_info->fetch()) {
        $stmt_del = $pdo->prepare("DELETE FROM teaching_substitutions WHERE id = ?");
        $stmt_del->execute([$sub_id]);
        
        $msg = "คุณ {$_SESSION['full_name']} ได้ปฏิเสธ/ยกเลิกการรับสอนแทนในวิชา {$sinfo['subject_name']} คาบที่ {$sinfo['period']}";
        $link = "teacher_substitute.php";
        $ins_notif = $pdo->prepare("INSERT INTO notifications (user_id, message, reference_type, reference_id, link) VALUES (?, ?, 'sub_declined', ?, ?)");
        $ins_notif->execute([$sinfo['original_teacher_id'], $msg, "sub_dec_".$sub_id."_".time(), $link]);
        
        header("Location: teacher_dashboard.php?success=" . urlencode("ปฏิเสธการสอนแทนเรียบร้อยแล้ว"));
        exit;
    }
}

// Handle Delete Log MUST be before HTML/Header output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log_id'])) {
    $delete_log_id = $_POST['delete_log_id'];
    
    // Verify ownership
    $check_stmt = $pdo->prepare("SELECT image_path, signature_path FROM teaching_logs WHERE id = ? AND teacher_id = ?");
    $check_stmt->execute([$delete_log_id, $teacher_id]);
    $log_to_delete = $check_stmt->fetch();
    
    if ($log_to_delete) {
        $pdo->beginTransaction();
        try {
            // Delete child records first
            $pdo->prepare("DELETE FROM class_attendance WHERE teaching_log_id = ?")->execute([$delete_log_id]);
            
            // Delete main record
            $pdo->prepare("DELETE FROM teaching_logs WHERE id = ?")->execute([$delete_log_id]);
            
            $pdo->commit();
            
            // Delete physical files after DB transaction succeeds
            if (!empty($log_to_delete['image_path']) && file_exists('../../' . $log_to_delete['image_path'])) {
                @unlink('../../' . $log_to_delete['image_path']);
            }
            if (!empty($log_to_delete['signature_path']) && file_exists('../../' . $log_to_delete['signature_path'])) {
                @unlink('../../' . $log_to_delete['signature_path']);
            }
            
            header("Location: teacher_dashboard.php?success=ลบข้อมูลการสอนเรียบร้อยแล้ว");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "เกิดข้อผิดพลาดในการลบข้อมูล: " . $e->getMessage();
        }
    } else {
        $error = "ไม่พบข้อมูล หรือคุณไม่มีสิทธิ์ลบข้อมูลนี้";
    }
}

$page_title = "แดชบอร์ด (Teacher)";
include '../../includes/header.php';

// Get Active Term
$term_stmt = $pdo->query("SELECT id, year_name, term_number FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term = $term_stmt->fetch();

// Get Teacher Profile
$stmt = $pdo->prepare("SELECT full_name, advisor_classroom FROM users WHERE id = ?");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();

// Check for open inspection cycles
$stmt_open_cycles = $pdo->query("SELECT COUNT(*) FROM inspection_cycles WHERE status = 'open'");
$open_cycles_count = (int)$stmt_open_cycles->fetchColumn();

// Get Recent Teaching Logs (Timeline)
$log_query = "
    SELECT tl.id, tl.log_date, tl.period, s.subject_name, c.room_name, 
           tl.teaching_report_text, tl.image_path,
           (SELECT COUNT(*) FROM class_attendance WHERE teaching_log_id = tl.id AND status = 'absent') as absent_count,
           (SELECT COUNT(*) FROM class_attendance WHERE teaching_log_id = tl.id AND status = 'leave') as leave_count
    FROM teaching_logs tl
    LEFT JOIN subjects s ON tl.subject_id = s.id
    LEFT JOIN classrooms c ON tl.classroom_id = c.id
    WHERE tl.teacher_id = ? 
    ORDER BY tl.log_date DESC, tl.period DESC 
    LIMIT 10
";
$logs_stmt = $pdo->prepare($log_query);
$logs_stmt->execute([$teacher_id]);
$recent_logs = $logs_stmt->fetchAll();

// Find At-Risk Students for Subject Teacher (Missing > 3 times in subjects taught by this teacher)
$subject_at_risk_students = [];
try {
    $subj_risk_stmt = $pdo->prepare("
        SELECT s.full_name, s.student_number, c.room_name, sub.subject_code, sub.subject_name, COUNT(ca.id) as absent_count
        FROM class_attendance ca
        JOIN teaching_logs tl ON ca.teaching_log_id = tl.id
        JOIN subjects sub ON tl.subject_id = sub.id
        JOIN students s ON ca.student_id = s.id
        LEFT JOIN classrooms c ON s.classroom_id = c.id
        WHERE ca.status = 'absent' AND tl.teacher_id = ?
        GROUP BY s.id, sub.id
        HAVING absent_count > 3
        ORDER BY absent_count DESC, c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC, CAST(s.student_number AS UNSIGNED) ASC
    ");
    $subj_risk_stmt->execute([$teacher_id]);
    $subject_at_risk_students = $subj_risk_stmt->fetchAll();
} catch (Exception $e) {}

// Find At-Risk Students for Advisor (Missing > 3 times in any subject)
$advisor_at_risk_students = [];
if ($active_term) {
    try {
        $adv_rooms_stmt = $pdo->prepare("SELECT classroom_id FROM homeroom_teachers WHERE teacher_id = ? AND academic_term_id = ?");
        $adv_rooms_stmt->execute([$teacher_id, $active_term['id']]);
        $room_ids = $adv_rooms_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($room_ids)) {
            $inQuery = implode(',', array_fill(0, count($room_ids), '?'));
            $at_risk_stmt = $pdo->prepare("
                SELECT s.full_name, s.student_number, c.room_name, sub.subject_code, sub.subject_name, COUNT(ca.id) as absent_count
                FROM class_attendance ca
                JOIN teaching_logs tl ON ca.teaching_log_id = tl.id
                JOIN subjects sub ON tl.subject_id = sub.id
                JOIN students s ON ca.student_id = s.id
                LEFT JOIN classrooms c ON s.classroom_id = c.id
                WHERE ca.status = 'absent' AND s.classroom_id IN ($inQuery)
                GROUP BY s.id, sub.id
                HAVING absent_count > 3
                ORDER BY absent_count DESC, CAST(s.student_number AS UNSIGNED) ASC
            ");
            $at_risk_stmt->execute($room_ids);
            $advisor_at_risk_students = $at_risk_stmt->fetchAll();
        }
    } catch (Exception $e) {}
}
// Find Pending Substitutions assigned to this teacher
$sub_assigned_stmt = $pdo->prepare("
    SELECT ts.id, ts.substitution_date, ts.period, s.subject_name, c.room_name, u.full_name as original_teacher_name
    FROM teaching_substitutions ts
    JOIN subjects s ON ts.subject_id = s.id
    JOIN classrooms c ON ts.classroom_id = c.id
    JOIN users u ON ts.original_teacher_id = u.id
    WHERE ts.substitute_teacher_id = ? AND ts.substitution_date >= CURDATE() AND ts.status = 'pending'
    ORDER BY ts.substitution_date ASC, ts.period ASC
");
$sub_assigned_stmt->execute([$teacher_id]);
$sub_assigned_classes = $sub_assigned_stmt->fetchAll();

?>

<div class="row">
    <div class="col-12">
        <?php if (isset($_GET['success'])): ?>
            <div class="badge badge-success mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: center;">✅ <?= htmlspecialchars($_GET['success']) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="badge badge-danger mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: center;">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <div class="card mb-4" style="padding: 20px;">
            <?php
            // Fetch counts for the buttons
            $unread_alerts = 0;
            $daily_late_count = 0;
            $unread_absent = 0;
            try {
                // Alerts (DM + System Alerts like 4x Late, Sub teaching)
                $stmt_alerts = $pdo->prepare("
                    SELECT COUNT(DISTINCT n.id) FROM notifications n
                    LEFT JOIN students s ON n.reference_type = 'late_alert' AND n.reference_id = s.id
                    LEFT JOIN classrooms c ON s.classroom_id = c.id
                    WHERE n.user_id = ? AND n.is_read = 0 AND n.reference_type != 'absent_alert'
                    AND (
                        n.reference_type != 'late_alert' 
                        OR c.room_name = (SELECT advisor_classroom FROM users WHERE id = ?)
                    )
                ");
                $stmt_alerts->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
                $unread_alerts = $stmt_alerts->fetchColumn();

                // Daily Late: check homeroom_attendance for TODAY
                $stmt_late = $pdo->prepare("
                    SELECT COUNT(*) FROM homeroom_attendance ha
                    JOIN students s ON ha.student_id = s.id
                    JOIN classrooms c ON s.classroom_id = c.id
                    WHERE ha.attendance_date = CURDATE() AND ha.status = 'late'
                    AND c.room_name = (SELECT advisor_classroom FROM users WHERE id = ?)
                ");
                $stmt_late->execute([$_SESSION['user_id']]);
                $daily_late_count = $stmt_late->fetchColumn();

                // Absent: must be for students in his advisor class (reference_id format: absent_{sid}_{subj}_{count})
                $stmt_absent = $pdo->prepare("
                    SELECT COUNT(*) FROM notifications n
                    JOIN students s ON n.reference_id LIKE CONCAT('absent_', s.id, '\_%')
                    JOIN classrooms c ON s.classroom_id = c.id
                    WHERE n.user_id = ? AND n.is_read = 0 AND n.reference_type = 'absent_alert'
                    AND c.room_name = (SELECT advisor_classroom FROM users WHERE id = ?)
                ");
                $stmt_absent->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
                $unread_absent = $stmt_absent->fetchColumn();
            } catch (Exception $e) {
                // log error if any
            }
            ?>
            
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h2 style="font-size: 1.15rem; font-weight: 600; margin: 0;">สวัสดี, <?= htmlspecialchars($teacher['full_name']) ?></h2>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 4px; margin-bottom: 0;">
                        เทอมปัจจุบัน: <strong style="color: var(--text-main);"><?= $active_term ? "{$active_term['term_number']}/{$active_term['year_name']}" : "ยังไม่ได้กำหนด" ?></strong> · 
                        ครูที่ปรึกษาประจำชั้น: <strong style="color: var(--text-main);"><?= htmlspecialchars($teacher['advisor_classroom'] ?: 'ไม่มี') ?></strong>
                    </p>
                </div>

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="teacher_home.php" class="btn btn-sm" style="background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd; border-radius: 20px; font-weight: 600; font-size: 0.8rem; text-decoration: none; display: flex; align-items: center; gap: 5px; padding: 4px 12px;">
                        🏠 กลับหน้าหลัก
                    </a>
                    <a href="messages.php" class="btn btn-sm" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 20px; font-weight: 600; font-size: 0.8rem; text-decoration: none; display: flex; align-items: center; gap: 5px; padding: 4px 12px;">
                        🔔 ข้อความ & แจ้งเตือน
                        <?php if ($unread_alerts > 0): ?>
                            <span style="background: #ef4444; color: white; border-radius: 10px; padding: 1px 6px; font-size: 0.7rem;"><?= $unread_alerts ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <a href="teacher_notif_late.php" class="btn btn-sm" style="background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; border-radius: 20px; font-weight: 600; font-size: 0.8rem; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                        🏃 ข้อมูลมาสาย
                        <?php if ($daily_late_count > 0): ?>
                            <span style="background: #ef4444; color: white; border-radius: 10px; padding: 1px 6px; font-size: 0.7rem;"><?= $daily_late_count ?></span>
                        <?php endif; ?>
                    </a>
                    
                    <a href="teacher_notif_absent.php" class="btn btn-sm" style="background: #fffbeb; color: #d97706; border: 1px solid #fcd34d; border-radius: 20px; font-weight: 600; font-size: 0.8rem; text-decoration: none; display: flex; align-items: center; gap: 5px;">
                        ⚠️ เสี่ยง มส.
                        <?php if ($unread_absent > 0): ?>
                            <span style="background: #ef4444; color: white; border-radius: 10px; padding: 1px 6px; font-size: 0.7rem;"><?= $unread_absent ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 24px;">
    <a href="teacher_homeroom.php" class="card" style="text-decoration: none; color: inherit; text-align: center; padding: 15px 12px; min-width: 0; word-break: break-word; transition: transform 0.2s; cursor: pointer;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
        <div style="font-size: 1.8rem; margin-bottom: 8px;">🚩</div>
        <h3 style="color: var(--text-main); font-size: 0.9rem;">เช็คชื่อเข้าแถว</h3>
        <p style="color: var(--text-muted); font-size: 0.78rem;">บันทึกการเข้าแถวสำหรับห้องที่ปรึกษา</p>
    </a>
    
    <a href="teacher_class.php" class="card" style="text-decoration: none; color: inherit; text-align: center; padding: 15px 12px; min-width: 0; word-break: break-word; transition: transform 0.2s; cursor: pointer;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
        <div style="font-size: 1.8rem; margin-bottom: 8px;">📝</div>
        <h3 style="color: var(--text-main); font-size: 0.9rem;">บันทึกการสอน</h3>
        <p style="color: var(--text-muted); font-size: 0.78rem;">เช็คชื่อนักเรียนในรายวิชา และบันทึกหลังการสอน</p>
    </a>

    <a href="teacher_substitute.php" class="card" style="text-decoration: none; color: inherit; text-align: center; padding: 15px 12px; min-width: 0; word-break: break-word; transition: transform 0.2s; cursor: pointer;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
        <div style="font-size: 1.8rem; margin-bottom: 8px;">🤝</div>
        <h3 style="color: var(--text-main); font-size: 0.9rem;">มอบหมายสอนแทน</h3>
        <p style="color: var(--text-muted); font-size: 0.78rem;">จัดการการสอนแทนหรือดูคาบที่ได้รับมอบหมาย</p>
    </a>

    <a href="teacher_behavior.php" class="card" style="text-decoration: none; color: inherit; text-align: center; padding: 15px 12px; min-width: 0; word-break: break-word; transition: transform 0.2s; cursor: pointer; position: relative;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
        <div style="font-size: 1.8rem; margin-bottom: 8px;">📋</div>
        <?php if ($open_cycles_count > 0): ?>
            <span style="position: absolute; top: 10px; right: 10px; background: var(--danger-color); color: white; border-radius: 50%; width: 22px; height: 22px; display: flex; justify-content: center; align-items: center; font-size: 0.75rem; font-weight: bold; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"><?= $open_cycles_count ?></span>
        <?php endif; ?>
        <h3 style="color: var(--text-main); font-size: 0.9rem;">ตรวจระเบียบ</h3>
        <p style="color: var(--text-muted); font-size: 0.78rem;">บันทึกคะแนนพฤติกรรมนักเรียน</p>
    </a>


    <!-- <a href="academic_feed.php?view=teacher" class="card" style="text-decoration: none; color: inherit; text-align: center; padding: 15px 12px; min-width: 0; word-break: break-word; transition: transform 0.2s; cursor: pointer;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
        <div style="font-size: 1.8rem; margin-bottom: 8px;">🖼️</div>
        <h3 style="color: var(--text-main); font-size: 0.9rem;">ไทม์ไลน์ภาพการสอน</h3>
        <p style="color: var(--text-muted); font-size: 0.78rem;">ดูภาพการสอนทั้งหมดของโรงเรียน</p>
    </a> -->
</div>

<?php if (count($sub_assigned_classes) > 0): ?>
<div class="card" style="margin-bottom: 24px; border-left: 4px solid #0ea5e9; background-color: #f0f9ff;">
    <h3 class="card-title" style="font-size: 1.1rem; color: #0369a1; display: flex; align-items: center; gap: 10px;">
        📢 คาบเรียนที่คุณได้รับมอบหมายให้สอนแทน (รอรายงาน)
    </h3>
    <ul style="list-style: none; padding: 0; margin: 0;">
        <?php foreach($sub_assigned_classes as $sub): ?>
        <li style="padding: 10px 0; border-bottom: 1px dashed #bae6fd;">
            🗓️ วันที่ <strong><?= date('d/m/Y', strtotime($sub['substitution_date'])) ?></strong> | คาบที่ <strong><?= htmlspecialchars($sub['period']) ?></strong> 
            <br>📘 วิชา <strong><?= htmlspecialchars($sub['subject_name']) ?></strong> (ห้อง <?= htmlspecialchars($sub['room_name']) ?>) 
            <br>👤 แทนครู <strong><?= htmlspecialchars($sub['original_teacher_name']) ?></strong>
            <div style="margin-top: 8px; display: flex; gap: 10px; align-items: center;">
                <a href="teacher_class.php?sub_id=<?= $sub['id'] ?>" class="btn btn-sm" style="background-color: #0ea5e9; color: white; padding: 5px 10px; font-size: 12px; border-radius: 4px; text-decoration: none;">📝 กดบันทึกการสอนคาบนี้</a>
                <form method="POST" onsubmit="return confirm('คุณแน่ใจหรือไม่ที่จะยกเลิกการสอนแทนนี้? (จะมีการแจ้งเตือนกลับไปยังครูเจ้าของวิชา)');" style="margin: 0;">
                    <input type="hidden" name="action" value="decline_sub">
                    <input type="hidden" name="decline_sub_id" value="<?= $sub['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline" style="border-color: #ef4444; color: #ef4444; padding: 4px 10px; font-size: 12px; border-radius: 4px;">❌ ปฏิเสธ/ยกเลิก</button>
                </form>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- At-Risk Students Section (For Subject Teacher) -->
<?php if (count($subject_at_risk_students) > 0): ?>
<div class="card" style="margin-bottom: 24px; border-left: 4px solid var(--danger-color);">
    <h3 class="card-title" style="font-size: 1.1rem; color: var(--danger-color); display: flex; align-items: center; gap: 10px;">
        🚨 นักเรียนกลุ่มเสี่ยงในรายวิชาที่ท่านสอน (ขาดเรียนสะสมเกิน 3 ครั้ง)
    </h3>
    <div class="scroll-table table-fixed-header" style="border: 1px solid var(--border-color); border-radius: 6px; border-bottom: none;">
        <table class="table" style="margin-bottom: 0;">
            <thead>
                <tr>
                    <th>ห้องเรียน</th>
                    <th>เลขที่</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>รายวิชา</th>
                    <th style="text-align: center;">จำนวนครั้งที่ขาด</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($subject_at_risk_students as $student): ?>
                    <tr>
                        <td><?= htmlspecialchars($student['room_name']) ?></td>
                        <td><?= htmlspecialchars($student['student_number']) ?></td>
                        <td><?= htmlspecialchars($student['full_name']) ?></td>
                        <td><?= htmlspecialchars($student['subject_code'] . ' ' . $student['subject_name']) ?></td>
                        <td style="text-align: center; color: var(--danger-color); font-weight: bold;"><?= $student['absent_count'] ?> ครั้ง</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- At-Risk Students Section (For Advisor) -->
<?php if (count($advisor_at_risk_students) > 0): ?>
<div class="card" style="margin-bottom: 24px; border-left: 4px solid var(--danger-color);">
    <h3 class="card-title" style="font-size: 1.1rem; color: var(--danger-color); display: flex; align-items: center; gap: 10px;">
        🚨 นักเรียนห้องที่ปรึกษากลุ่มเสี่ยง (ขาดเรียนวิชาเดิมสะสมเกิน 3 ครั้ง)
    </h3>
    <div class="scroll-table">
        <table class="table">
            <thead>
                <tr>
                    <th>ห้องเรียน</th>
                    <th>เลขที่</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>รายวิชา</th>
                    <th style="text-align: center;">จำนวนครั้งที่ขาด</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($advisor_at_risk_students as $student): ?>
                    <tr>
                        <td><?= htmlspecialchars($student['room_name']) ?></td>
                        <td><?= htmlspecialchars($student['student_number']) ?></td>
                        <td><?= htmlspecialchars($student['full_name']) ?></td>
                        <td><?= htmlspecialchars($student['subject_code'] . ' ' . $student['subject_name']) ?></td>
                        <td style="text-align: center; color: var(--danger-color); font-weight: bold;"><?= $student['absent_count'] ?> ครั้ง</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-12">
        <h3 style="margin-bottom: 12px; color: var(--text-muted); font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">รายงานสรุปและพิมพ์</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px;">
            <a href="teacher_report_homeroom.php" class="card" style="text-decoration: none; color: inherit; padding: 15px 12px; display: flex; flex-direction: column; text-align: center; justify-content: center; gap: 8px; min-width: 0; word-break: break-word;">
                <div style="font-size: 1.8rem;">📊</div>
                <div>
                    <h4 style="margin: 0; color: var(--text-main); font-size: 0.88rem; font-weight: 600;">สรุปนักเรียนเข้าแถว</h4>
                    <p style="margin: 0; font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">สรุปการมาเข้าแถวห้องที่ปรึกษา</p>
                </div>
            </a>
            
            <a href="teacher_homeroom_log.php" class="card" style="text-decoration: none; color: inherit; padding: 15px 12px; display: flex; flex-direction: column; text-align: center; justify-content: center; gap: 8px; min-width: 0; word-break: break-word; background-color: #f0fdf4;">
                <div style="font-size: 1.8rem;">📝</div>
                <div>
                    <h4 style="margin: 0; color: var(--text-main); font-size: 0.88rem; font-weight: 600;">แบบบันทึกประจำห้องที่ปรึกษา</h4>
                    <p style="margin: 0; font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">ประวัติการเข้าเรียนของห้องที่ปรึกษา</p>
                </div>
            </a>
            
            <a href="teacher_report_subject.php" class="card" style="text-decoration: none; color: inherit; padding: 15px 12px; display: flex; flex-direction: column; text-align: center; justify-content: center; gap: 8px; min-width: 0; word-break: break-word;">
                <div style="font-size: 1.8rem;">📈</div>
                <div>
                    <h4 style="margin: 0; color: var(--text-main); font-size: 0.88rem; font-weight: 600;">สรุปการเข้าเรียนรายวิชา</h4>
                    <p style="margin: 0; font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">สรุปการเข้าเรียนแต่ละวิชา</p>
                </div>
            </a>

        </div>
    </div>
</div>

<div class="card" style="margin-bottom: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h3 class="card-title" style="margin-bottom: 0; font-size: 0.95rem;">Timeline การสอนล่าสุด</h3>
        <a href="teacher_class.php" class="btn btn-outline" style="font-size: 0.78rem; padding: 6px 14px;">+ ลงบันทึกใหม่</a>
    </div>
    
    <div class="timeline" style="position: relative; border-left: 2px solid var(--border-color); margin-left: 10px; padding-left: 20px;">
        <?php foreach ($recent_logs as $log): ?>
        <div class="timeline-item" style="position: relative; margin-bottom: 20px;">
            <div class="timeline-dot" style="position: absolute; left: -27px; top: 0; width: 10px; height: 10px; border-radius: 50%; background: var(--text-muted); border: 2px solid var(--bg-panel);"></div>
            <div style="background: white; border: 1px solid var(--border-color); border-radius: 8px; padding: 15px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <h4 style="margin: 0 0 5px 0; color: var(--text-main); font-size: 0.9rem; font-weight: 600;">วันที่ <?= date('d/m/Y', strtotime($log['log_date'])) ?> · คาบที่ <?= htmlspecialchars($log['period']) ?></h4>
                        <p style="margin: 0; font-weight: 500; font-size: 0.95rem; color: var(--text-color);">📘 <?= htmlspecialchars($log['subject_name'] ?? '-') ?> - ห้อง <?= htmlspecialchars($log['room_name'] ?? '-') ?></p>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <span class="badge badge-danger" style="padding: 6px 10px;">ขาด <?= $log['absent_count'] ?></span>
                        <span class="badge" style="background: #f59e0b; color: white; padding: 6px 10px;">ลา <?= $log['leave_count'] ?></span>
                    </div>
                </div>
                
                <?php if (!empty($log['teaching_report_text'])): ?>
                <div style="background: var(--bg-main); padding: 12px; border-radius: 8px; font-size: 0.85rem; color: var(--text-main); margin-bottom: 12px;">
                    <strong>เนื้อหาที่สอน / งานที่มอบหมาย:</strong><br>
                    <?= nl2br(htmlspecialchars($log['teaching_report_text'])) ?>
                </div>
                <?php else: ?>
                <div style="background: #f8fafc; padding: 10px; border-radius: 6px; font-size: 0.9rem; color: var(--text-muted); font-style: italic; margin-bottom: 12px;">
                    ยังไม่ได้ระบุเนื้อหาที่สอน
                </div>
                <?php endif; ?>
                
                <?php if (!empty($log['image_path'])): ?>
                <div style="margin-bottom: 15px; border-radius: 6px; overflow: hidden; max-width: 300px; border: 1px solid var(--border-color);">
                    <img src="../../<?= htmlspecialchars($log['image_path']) ?>" alt="ภาพประกอบการสอน" style="width: 100%; display: block; object-fit: contain; cursor: pointer;" onclick="window.open(this.src, '_blank')">
                </div>
                <?php endif; ?>
                
                <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px dashed var(--border-color); padding-top: 10px; gap: 10px; flex-wrap: wrap;">
                    <form method="POST" onsubmit="return confirm('⚠️ คุณแน่ใจหรือไม่ที่จะลบข้อมูลการสอนและเช็คชื่อนี้?\n(ข้อมูลจะไม่สามารถกู้คืนได้)');" style="margin: 0;">
                        <input type="hidden" name="delete_log_id" value="<?= $log['id'] ?>">
                        <button type="submit" class="btn btn-outline" style="padding: 5px 12px; font-size: 13px; border-color: #ef4444; color: #ef4444;">🗑️ ลบ</button>
                    </form>
                    <div style="display: flex; gap: 10px;">
                        <a href="edit_teaching_log_time.php?log_id=<?= $log['id'] ?>" class="btn btn-outline" style="padding: 5px 12px; font-size: 13px;">🔄 ย้ายวัน/คาบ</a>
                        <a href="teacher_class_report.php?log_id=<?= $log['id'] ?>" class="btn btn-outline" style="padding: 5px 12px; font-size: 13px;">✏️ แก้ไขเนื้อหา / รูปภาพ</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($recent_logs)): ?>
            <div style="color: var(--text-muted); background: #f8fafc; padding: 20px; border-radius: 8px; text-align: center; border: 1px dashed var(--border-color);">
                ยังไม่มีประวัติการบันทึกการสอน
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
