<?php
// src/actions/messages.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$user_roles = $_SESSION['roles'] ?? [];

// Auto-migrate: Add profile_photo column if it doesn't exist yet
try {
    $pdo->query("SELECT profile_photo FROM users LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER department");
    } catch (Exception $e2) {}
}

$tab = $_GET['tab'] ?? 'inbox'; // inbox, sent, or alerts

// Mark notifications as read depending on tab
try {
    if ($tab === 'inbox') {
        $update_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND reference_type = 'message' AND is_read = 0");
        $update_stmt->execute([$user_id]);
    } elseif ($tab === 'alerts') {
        $update_stmt = $pdo->prepare("
            UPDATE notifications n
            LEFT JOIN students s ON n.reference_type = 'late_alert' AND n.reference_id = s.id
            LEFT JOIN classrooms c ON s.classroom_id = c.id
            SET n.is_read = 1 
            WHERE n.user_id = ? AND n.reference_type NOT IN ('message', 'absent_alert') AND n.is_read = 0
            AND (
                n.reference_type != 'late_alert' 
                OR c.room_name = (SELECT advisor_classroom FROM users WHERE id = ?)
            )
        ");
        $update_stmt->execute([$user_id, $user_id]);
    }
} catch (Exception $e) {}

// Handle deletion for inbox/sent (messages table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_id']) || isset($_POST['delete_ids'])) && $tab !== 'alerts') {
    $delete_ids = [];
    if (isset($_POST['delete_ids']) && is_array($_POST['delete_ids'])) {
        $delete_ids = $_POST['delete_ids'];
    } elseif (isset($_POST['delete_id'])) {
        $delete_ids = [$_POST['delete_id']];
    }
    
    foreach ($delete_ids as $delete_id) {
        $stmt = $pdo->prepare("SELECT sender_id, receiver_id FROM messages WHERE id = ?");
        $stmt->execute([$delete_id]);
        $msg = $stmt->fetch();
        
        if ($msg) {
            if ($msg['receiver_id'] == $user_id) {
                $pdo->prepare("UPDATE messages SET is_deleted_by_receiver = 1 WHERE id = ?")->execute([$delete_id]);
            }
            if ($msg['sender_id'] == $user_id) {
                $pdo->prepare("UPDATE messages SET is_deleted_by_sender = 1 WHERE id = ?")->execute([$delete_id]);
            }
        }
    }
    
    header("Location: messages.php?tab=" . urlencode($tab) . "&success=1");
    exit();
}

// Handle deletion for alerts (notifications table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_alert_id']) || isset($_POST['delete_alert_ids'])) && $tab === 'alerts') {
    $delete_ids = [];
    if (isset($_POST['delete_alert_ids']) && is_array($_POST['delete_alert_ids'])) {
        $delete_ids = $_POST['delete_alert_ids'];
    } elseif (isset($_POST['delete_alert_id'])) {
        $delete_ids = [$_POST['delete_alert_id']];
    }
    
    $del_stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
    foreach ($delete_ids as $did) {
        $del_stmt->execute([$did, $user_id]);
    }
    header("Location: messages.php?tab=alerts&success=1");
    exit();
}

// Fetch data
$messages = [];
$alerts = [];

if ($tab === 'sent') {
    $stmt = $pdo->prepare("
        SELECT m.*, u.full_name as other_party_name 
        FROM messages m
        JOIN users u ON m.receiver_id = u.id
        WHERE m.sender_id = ? AND m.is_deleted_by_sender = 0
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $messages = $stmt->fetchAll();
} elseif ($tab === 'alerts') {
    $stmt = $pdo->prepare("
        SELECT n.* 
        FROM notifications n
        LEFT JOIN students s ON n.reference_type = 'late_alert' AND n.reference_id = s.id
        LEFT JOIN classrooms c ON s.classroom_id = c.id
        WHERE n.user_id = ? AND n.reference_type NOT IN ('message', 'absent_alert')
        AND (
            n.reference_type != 'late_alert' 
            OR c.room_name = (SELECT advisor_classroom FROM users WHERE id = ?)
        )
        ORDER BY n.created_at DESC LIMIT 50
    ");
    $stmt->execute([$user_id, $user_id]);
    $alerts = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT m.*, u.full_name as other_party_name 
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.receiver_id = ? AND m.is_deleted_by_receiver = 0
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $messages = $stmt->fetchAll();
}

// Calculate Unread Counts for Tabs
$unread_inbox = 0;
$unread_alerts = 0;
try {
    // Unread Inbox (Direct Messages)
    $stmt_ui = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND reference_type = 'message'");
    $stmt_ui->execute([$user_id]);
    $unread_inbox = $stmt_ui->fetchColumn();

    // Unread System Alerts (excluding absent_alert and message)
    $stmt_ua = $pdo->prepare("
        SELECT COUNT(DISTINCT n.id) FROM notifications n
        LEFT JOIN students s ON n.reference_type = 'late_alert' AND n.reference_id = s.id
        LEFT JOIN classrooms c ON s.classroom_id = c.id
        WHERE n.user_id = ? AND n.is_read = 0 AND n.reference_type NOT IN ('message', 'absent_alert')
        AND (
            n.reference_type != 'late_alert' 
            OR c.room_name = (SELECT advisor_classroom FROM users WHERE id = ?)
        )
    ");
    $stmt_ua->execute([$user_id, $user_id]);
    $unread_alerts = $stmt_ua->fetchColumn();
} catch (Exception $e) {}

// User / Teacher Profile
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
$total_unread_notifs = $unread_inbox + $unread_alerts;
$daily_late_count = 0;
$unread_absent = 0;

if (!empty($teacher['advisor_classroom'])) {
    try {
        $stmt_late_c = $pdo->prepare("
            SELECT COUNT(*) FROM homeroom_attendance ha
            JOIN students s ON ha.student_id = s.id
            JOIN classrooms c ON s.classroom_id = c.id
            WHERE ha.attendance_date = CURDATE() AND ha.status = 'late'
            AND c.room_name = ?
        ");
        $stmt_late_c->execute([$teacher['advisor_classroom']]);
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

    try {
        $stmt_absent_c = $pdo->prepare("
            SELECT COUNT(*) FROM notifications n
            JOIN students s ON n.reference_id LIKE CONCAT('absent_', s.id, '\_%')
            JOIN classrooms c ON s.classroom_id = c.id
            WHERE n.user_id = ? AND n.is_read = 0 AND n.reference_type = 'absent_alert'
            AND c.room_name = ?
        ");
        $stmt_absent_c->execute([$user_id, $teacher['advisor_classroom']]);
        $unread_absent = $stmt_absent_c->fetchColumn();
    } catch (Exception $e) {}
}

$page_title = "กล่องข้อความ & แจ้งเตือนระบบ";
include '../../includes/header.php';
?>

<div style="max-width: 900px; margin: 0 auto;">

    <!-- 2. Status Pills (ภาพที่ 2) -->
    <div style="display: flex; gap: 8px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch; scrollbar-width: none;">
        <a href="messages.php" class="btn btn-sm" style="background: #f1f5f9; color: #334155; border: 2px solid var(--primary-color); border-radius: 50px; font-weight: 700; font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; white-space: nowrap; box-shadow: 0 2px 6px rgba(0,0,0,0.08);">
            🔔 ข้อความ & แจ้งเตือน
            <?php if ($total_unread_notifs > 0): ?>
                <span style="background: #ef4444; color: white; border-radius: 12px; padding: 1px 7px; font-size: 0.72rem; font-weight: bold;"><?= $total_unread_notifs ?></span>
            <?php endif; ?>
        </a>
        
        <a href="teacher_notif_late.php" class="btn btn-sm" style="background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; border-radius: 50px; font-weight: 600; font-size: 0.82rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; white-space: nowrap; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform 0.15s;" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
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

    <!-- 3. Message Center UI (ภาพที่ 3) -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 class="page-title" style="margin: 0; font-size: 1.25rem; display: flex; align-items: center; gap: 8px;">
            💬 ข้อความส่วนตัว & แจ้งเตือน
        </h2>
        <div>
            <a href="teacher_home.php" class="btn btn-secondary" style="padding: 8px 18px; font-weight: 600; margin-right: 8px; border-radius: 8px;">กลับ</a>
            <button type="submit" form="bulk-delete-form" class="btn btn-outline" style="padding: 8px 18px; font-weight: 600; color: #ef4444; border-color: #ef4444; margin-right: 8px; border-radius: 8px;">🗑️ ลบที่เลือก</button>
            <a href="message_compose.php" class="btn btn-primary" style="padding: 8px 18px; font-weight: 600; border-radius: 8px;">+ ส่งข้อความใหม่</a>
        </div>
    </div>
    
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; padding: 12px; background: #dcfce7; color: #15803d; border-radius: 8px;">
            ✅ ดำเนินการเรียบร้อยแล้ว
        </div>
    <?php endif; ?>

    <div class="card" style="padding: 0; overflow: hidden;">
        <!-- Tabs -->
        <div style="display: flex; border-bottom: 1px solid var(--border-color); background: #f8fafc; flex-wrap: wrap;">
            <a href="messages.php?tab=inbox" style="position: relative; flex: 1; min-width: 150px; text-align: center; padding: 15px; text-decoration: none; color: <?= $tab === 'inbox' ? 'var(--primary-color)' : 'var(--text-muted)' ?>; font-weight: <?= $tab === 'inbox' ? 'bold' : 'normal' ?>; border-bottom: <?= $tab === 'inbox' ? '3px solid var(--primary-color)' : '3px solid transparent' ?>;">
                📥 กล่องข้อความเข้า
                <?php if ($unread_inbox > 0): ?>
                    <span style="position: absolute; top: 10px; right: 10px; background: #ef4444; color: white; border-radius: 50%; padding: 2px 6px; font-size: 0.7rem; font-weight: bold; min-width: 20px; display: inline-block; text-align: center;"><?= $unread_inbox ?></span>
                <?php endif; ?>
            </a>
            <a href="messages.php?tab=sent" style="flex: 1; min-width: 150px; text-align: center; padding: 15px; text-decoration: none; color: <?= $tab === 'sent' ? 'var(--primary-color)' : 'var(--text-muted)' ?>; font-weight: <?= $tab === 'sent' ? 'bold' : 'normal' ?>; border-bottom: <?= $tab === 'sent' ? '3px solid var(--primary-color)' : '3px solid transparent' ?>;">
                📤 ข้อความที่ส่งแล้ว
            </a>
            <a href="messages.php?tab=alerts" style="position: relative; flex: 1; min-width: 150px; text-align: center; padding: 15px; text-decoration: none; color: <?= $tab === 'alerts' ? 'var(--primary-color)' : 'var(--text-muted)' ?>; font-weight: <?= $tab === 'alerts' ? 'bold' : 'normal' ?>; border-bottom: <?= $tab === 'alerts' ? '3px solid var(--primary-color)' : '3px solid transparent' ?>;">
                🔔 แจ้งเตือนระบบ
                <?php if ($unread_alerts > 0): ?>
                    <span style="position: absolute; top: 10px; right: 10px; background: #ef4444; color: white; border-radius: 50%; padding: 2px 6px; font-size: 0.7rem; font-weight: bold; min-width: 20px; display: inline-block; text-align: center;"><?= $unread_alerts ?></span>
                <?php endif; ?>
            </a>
        </div>

        <div class="table-responsive">
            <form id="bulk-delete-form" method="POST" onsubmit="return confirm('ยืนยันการลบรายการที่เลือกทั้งหมดหรือไม่?');">
            
            <?php if ($tab === 'alerts'): ?>
                <table class="table" style="margin: 0;">
                    <thead>
                        <tr style="background: #f1f5f9;">
                            <th style="width: 5%; text-align: center;"><input type="checkbox" id="select-all" onclick="toggleSelectAll(this)"></th>
                            <th style="width: 75%;">รายละเอียดการแจ้งเตือน</th>
                            <th style="width: 20%; text-align: center;">วันที่ / เวลา</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($alerts)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                    📭 ไม่มีการแจ้งเตือนระบบ
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($alerts as $alt): ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="delete_alert_ids[]" value="<?= $alt['id'] ?>" class="message-checkbox">
                                    </td>
                                    <td>
                                        <?php if (!empty($alt['link'])): ?>
                                            <a href="<?= $base_url ?>/<?= htmlspecialchars($alt['link']) ?>" style="color: inherit; text-decoration: none; display: block; font-weight: 500;">
                                                <?= htmlspecialchars($alt['message']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="font-weight: 500;"><?= htmlspecialchars($alt['message']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="color: #64748b; font-size: 0.9em; text-align: center;">
                                        <?= date('d/m/Y H:i', strtotime($alt['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <table class="table" style="margin: 0;">
                    <thead>
                        <tr style="background: #f1f5f9;">
                            <th style="width: 5%; text-align: center;"><input type="checkbox" id="select-all" onclick="toggleSelectAll(this)"></th>
                            <th style="width: 20%;"><?= $tab === 'inbox' ? 'จาก' : 'ถึง' ?></th>
                            <th style="width: 45%;">หัวข้อเรื่อง</th>
                            <th style="width: 20%;">วันที่ / เวลา</th>
                            <th style="width: 10%; text-align: center;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($messages)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                    📭 ไม่มีข้อความในหมวดหมู่นี้
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($messages as $msg): 
                                $is_unread = ($tab === 'inbox' && $msg['is_read'] == 0);
                            ?>
                                <tr style="background: <?= $is_unread ? '#eff6ff' : 'transparent' ?>; font-weight: <?= $is_unread ? 'bold' : 'normal' ?>;">
                                    <td style="text-align: center;">
                                        <input type="checkbox" name="delete_ids[]" value="<?= $msg['id'] ?>" class="message-checkbox">
                                    </td>
                                    <td>
                                        <?php if ($is_unread): ?>
                                            <span style="display: inline-block; width: 8px; height: 8px; background: #3b82f6; border-radius: 50%; margin-right: 5px;"></span>
                                        <?php endif; ?>
                                        <?= htmlspecialchars($msg['other_party_name']) ?>
                                    </td>
                                    <td>
                                        <a href="message_read.php?id=<?= $msg['id'] ?>" style="color: inherit; text-decoration: none; display: block;">
                                            <?= htmlspecialchars($msg['subject']) ?>
                                            <?php if (!empty($msg['attachment_path'])): ?>
                                                <span style="color: #64748b; font-size: 0.85em; margin-left: 5px;">📎 มีไฟล์แนบ</span>
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                    <td style="color: #64748b; font-size: 0.9em;">
                                        <?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <button type="submit" name="delete_id" value="<?= $msg['id'] ?>" style="background: none; border: none; color: #ef4444; cursor: pointer; padding: 5px;" title="ลบข้อความนี้">🗑️</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
function toggleSelectAll(source) {
    var checkboxes = document.getElementsByClassName('message-checkbox');
    for(var i=0, n=checkboxes.length;i<n;i++) {
        checkboxes[i].checked = source.checked;
    }
}
</script>
