<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';

// Force login check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$page_title = "นักเรียนเสี่ยง มส.";
$user_id = $_SESSION['user_id'];

// Auto-migrate: Add profile_photo column if it doesn't exist yet
try {
    $pdo->query("SELECT profile_photo FROM users LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER department");
    } catch (Exception $e2) {}
}

// Get teacher's advisor classroom
$stmt_adv = $pdo->prepare("SELECT advisor_classroom FROM users WHERE id = ?");
$stmt_adv->execute([$user_id]);
$advisor_classroom = $stmt_adv->fetchColumn();

// Mark as read only for this room
try {
    $update_stmt = $pdo->prepare("
        UPDATE notifications n
        JOIN students s ON n.reference_id LIKE CONCAT('absent_', s.id, '\_%')
        JOIN classrooms c ON s.classroom_id = c.id
        SET n.is_read = 1 
        WHERE n.user_id = ? AND n.reference_type = 'absent_alert' AND n.is_read = 0
        AND c.room_name = ?
    ");
    $update_stmt->execute([$user_id, $advisor_classroom]);
} catch (Exception $e) {}

// Fetch absent alerts for this room
$notifs = [];
try {
    $sql = "
        SELECT n.message, n.created_at, n.link, n.is_read 
        FROM notifications n
        JOIN students s ON n.reference_id LIKE CONCAT('absent_', s.id, '\_%')
        JOIN classrooms c ON s.classroom_id = c.id
        WHERE n.user_id = ? AND n.reference_type = 'absent_alert'
        AND c.room_name = ?
        ORDER BY n.created_at DESC LIMIT 50
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user_id, $advisor_classroom]);
    $notifs = $stmt->fetchAll();
} catch (Exception $e) {}

// Get Teacher Profile
$teacher = null;
try {
    $stmt_u = $pdo->prepare("SELECT full_name, advisor_classroom, department, profile_photo FROM users WHERE id = ?");
    $stmt_u->execute([$user_id]);
    $teacher = $stmt_u->fetch();
} catch (PDOException $e) {
    $stmt_u = $pdo->prepare("SELECT full_name, advisor_classroom, department FROM users WHERE id = ?");
    $stmt_u->execute([$user_id]);
    $teacher = $stmt_u->fetch();
    if ($teacher) $teacher['profile_photo'] = null;
}

// Active Term
$term_stmt = $pdo->query("SELECT id, year_name, term_number FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term = $term_stmt->fetch();

$photo_url = '';
$has_profile_photo = !empty($teacher['profile_photo']) && (file_exists(__DIR__ . '/../../' . $teacher['profile_photo']) || file_exists('../../' . $teacher['profile_photo']));
if ($has_profile_photo) {
    $photo_url = '../../' . $teacher['profile_photo'] . '?v=' . time();
} else {
    $encoded_name = urlencode($teacher['full_name'] ?? 'User');
    $photo_url = "https://ui-avatars.com/api/?name={$encoded_name}&size=120&background=10b981&color=fff&bold=true&font-size=0.4";
}

// Counts for the 3 pills
$unread_alerts_count = 0;
$daily_late_count = 0;
$unread_absent = count($notifs);
try {
    $stmt_ui = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND reference_type = 'message'");
    $stmt_ui->execute([$user_id]);
    $unread_inbox = $stmt_ui->fetchColumn();

    $stmt_ua = $pdo->prepare("
        SELECT COUNT(DISTINCT n.id) FROM notifications n
        LEFT JOIN students s ON n.reference_type = 'late_alert' AND n.reference_id = s.id
        LEFT JOIN classrooms c ON s.classroom_id = c.id
        WHERE n.user_id = ? AND n.is_read = 0 AND n.reference_type NOT IN ('message', 'absent_alert')
        AND (
            n.reference_type != 'late_alert' 
            OR c.room_name = ?
        )
    ");
    $stmt_ua->execute([$user_id, $advisor_classroom]);
    $unread_alerts_count = $unread_inbox + $stmt_ua->fetchColumn();
} catch (Exception $e) {}

if (!empty($advisor_classroom)) {
    try {
        $stmt_late_c = $pdo->prepare("
            SELECT COUNT(*) FROM homeroom_attendance ha
            JOIN students s ON ha.student_id = s.id
            JOIN classrooms c ON s.classroom_id = c.id
            WHERE ha.attendance_date = CURDATE() AND ha.status = 'late'
            AND c.room_name = ?
        ");
        $stmt_late_c->execute([$advisor_classroom]);
        $total_late_today = (int)$stmt_late_c->fetchColumn();

        $seen_data = $_SESSION['seen_late_' . $user_id] ?? ($_COOKIE['seen_late_' . $user_id] ?? '');
        $daily_late_count = $total_late_today;
        if (!empty($seen_data)) {
            $parts = explode('_', $seen_data);
            if (($parts[0] ?? '') === date('Y-m-d')) {
                $daily_late_count = max(0, $total_late_today - (int)($parts[1] ?? 0));
            }
        }
    } catch (Exception $e) {}
}

include '../../includes/header.php';
?>

<div style="max-width: 900px; margin: 0 auto;">


    <!-- 2. Status Pills (ภาพที่ 2) -->
    <div style="display: flex; gap: 8px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch; scrollbar-width: none;">
        <a href="messages.php" class="btn btn-sm" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; border-radius: 50px; font-weight: 600; font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform 0.15s;" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
            🔔 ข้อความ & แจ้งเตือน
            <?php if ($unread_alerts_count > 0): ?>
                <span style="background: #ef4444; color: white; border-radius: 12px; padding: 1px 7px; font-size: 0.72rem; font-weight: bold;"><?= $unread_alerts_count ?></span>
            <?php endif; ?>
        </a>
        
        <a href="teacher_notif_late.php" class="btn btn-sm" style="background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; border-radius: 50px; font-weight: 600; font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform 0.15s;" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
            🏃 ข้อมูลมาสาย
            <?php if ($daily_late_count > 0): ?>
                <span style="background: #ef4444; color: white; border-radius: 12px; padding: 1px 7px; font-size: 0.72rem; font-weight: bold;"><?= $daily_late_count ?></span>
            <?php endif; ?>
        </a>
        
        <a href="teacher_notif_absent.php" class="btn btn-sm" style="background: #fffbeb; color: #d97706; border: 2px solid #f59e0b; border-radius: 50px; font-weight: 700; font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; white-space: nowrap; box-shadow: 0 2px 6px rgba(245,158,11,0.15);">
            ⚠️ เสี่ยง มส.
            <?php if ($unread_absent > 0): ?>
                <span style="background: #ef4444; color: white; border-radius: 12px; padding: 1px 7px; font-size: 0.72rem; font-weight: bold;"><?= $unread_absent ?></span>
            <?php endif; ?>
        </a>
    </div>

<div class="card" style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 class="card-title" style="margin: 0; font-size: 1.2rem;">⚠️ แจ้งเตือนนักเรียนเสี่ยง มส. (เฉพาะที่ปรึกษา <?= htmlspecialchars($advisor_classroom ?: 'ไม่มี') ?>)</h2>
        <a href="teacher_home.php" class="btn btn-secondary">กลับ</a>
    </div>

    <?php if (empty($notifs)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 3rem; margin-bottom: 10px;">📭</div>
            <p>ไม่มีการแจ้งเตือนนักเรียนเสี่ยง มส. ในห้องของคุณ</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php foreach ($notifs as $n): ?>
                <div style="padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); background: #fffbeb; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                        <div style="font-size: 1rem; line-height: 1.5;">
                            <?php if (!empty($n['link'])): ?>
                                <a href="<?= $base_url ?>/<?= htmlspecialchars($n['link']) ?>" style="color: #d97706; text-decoration: none; font-weight: 500;">
                                    <?= htmlspecialchars($n['message']) ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($n['message']) ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 0.8rem; color: #f59e0b; white-space: nowrap;">
                            <?= date('d/m/Y H:i', strtotime($n['created_at'])) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</div>

<?php include '../../includes/footer.php'; ?>
