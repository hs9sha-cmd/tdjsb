<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';

// Force login check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$page_title = "ข้อมูลนักเรียนมาสาย (รายวัน)";
$user_id = $_SESSION['user_id'];
$date_filter = $_GET['date'] ?? date('Y-m-d'); // Default to today

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

// Fetch daily late students for this room
$lates = [];
try {
    $sql = "
        SELECT s.full_name, s.student_code, ha.attendance_date
        FROM homeroom_attendance ha
        JOIN students s ON ha.student_id = s.id
        JOIN classrooms c ON s.classroom_id = c.id
        WHERE c.room_name = ? AND ha.status = 'late'
    ";
    $params = [$advisor_classroom];

    if (!empty($date_filter)) {
        $sql .= " AND DATE(ha.attendance_date) = ?";
        $params[] = $date_filter;
    }

    $sql .= " ORDER BY s.student_code ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lates = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error in teacher_notif_late: " . $e->getMessage());
}

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
$unread_absent = 0;
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

        // Mark as seen for today
        $seen_val = date('Y-m-d') . '_' . $total_late_today;
        $_SESSION['seen_late_' . $user_id] = $seen_val;
        if (!headers_sent()) {
            setcookie('seen_late_' . $user_id, $seen_val, time() + 86400 * 7, '/');
        }

        // On this page, since it's currently viewed, badge count is 0
        $daily_late_count = 0;
    } catch (Exception $e) {}

    try {
        $stmt_absent_c = $pdo->prepare("
            SELECT COUNT(*) FROM notifications n
            JOIN students s ON n.reference_id LIKE CONCAT('absent_', s.id, '\_%')
            JOIN classrooms c ON s.classroom_id = c.id
            WHERE n.user_id = ? AND n.is_read = 0 AND n.reference_type = 'absent_alert'
            AND c.room_name = ?
        ");
        $stmt_absent_c->execute([$user_id, $advisor_classroom]);
        $unread_absent = $stmt_absent_c->fetchColumn();
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
        
        <a href="teacher_notif_late.php" class="btn btn-sm" style="background: #fef2f2; color: #b91c1c; border: 2px solid #ef4444; border-radius: 50px; font-weight: 700; font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; white-space: nowrap; box-shadow: 0 2px 6px rgba(239,68,68,0.15);">
            🏃 ข้อมูลมาสาย
            <?php if ($daily_late_count > 0): ?>
                <span style="background: #ef4444; color: white; border-radius: 12px; padding: 1px 7px; font-size: 0.72rem; font-weight: bold;"><?= $daily_late_count ?></span>
            <?php endif; ?>
        </a>
        
        <a href="teacher_notif_absent.php" class="btn btn-sm" style="background: #fffbeb; color: #d97706; border: 1px solid #fcd34d; border-radius: 50px; font-weight: 600; font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform 0.15s;" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
            ⚠️ เสี่ยง มส.
            <?php if ($unread_absent > 0): ?>
                <span style="background: #ef4444; color: white; border-radius: 12px; padding: 1px 7px; font-size: 0.72rem; font-weight: bold;"><?= $unread_absent ?></span>
            <?php endif; ?>
        </a>
    </div>

<div class="card" style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 class="card-title" style="margin: 0; font-size: 1.2rem;">🏃 ข้อมูลนักเรียนมาสาย (เฉพาะที่ปรึกษา <?= htmlspecialchars($advisor_classroom ?: 'ไม่มี') ?>)</h2>
        <a href="teacher_home.php" class="btn btn-secondary">กลับ</a>
    </div>

    <form method="GET" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <label style="font-weight: 600; color: var(--text-muted);">เลือกวันที่:</label>
        <input type="date" name="date" class="form-control" style="width: auto; border-radius: 12px; padding: 6px 12px;" value="<?= htmlspecialchars($date_filter) ?>" onchange="this.form.submit()">
        <?php if (!empty($_GET['date']) && $_GET['date'] !== date('Y-m-d')): ?>
            <a href="teacher_notif_late.php" class="btn btn-sm btn-outline" style="border-radius: 12px;">กลับเป็นวันนี้</a>
        <?php endif; ?>
    </form>

    <?php if (empty($lates)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 3rem; margin-bottom: 10px;">👏</div>
            <p>ยอดเยี่ยม! ไม่มีนักเรียนในห้องมาสายในวันที่เลือก</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php foreach ($lates as $index => $late): ?>
                <div style="padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); background: #fef2f2; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                        <div style="font-size: 1rem; line-height: 1.5; color: #b91c1c; font-weight: 500;">
                            <?= ($index + 1) ?>. <?= htmlspecialchars($late['student_code']) ?> - <?= htmlspecialchars($late['full_name']) ?>
                        </div>
                        <div style="font-size: 0.8rem; color: #ef4444; white-space: nowrap;">
                            มาสาย
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</div>

<?php include '../../includes/footer.php'; ?>
