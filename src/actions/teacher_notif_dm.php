<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';

// Force login check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$page_title = "ข้อความ (DM)";
$user_id = $_SESSION['user_id'];

// Mark as read
try {
    $update_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND reference_type = 'message' AND is_read = 0");
    $update_stmt->execute([$user_id]);
} catch (Exception $e) {}

// Fetch recent DMs
$notifs = [];
try {
    $stmt = $pdo->prepare("SELECT message, created_at, link, is_read FROM notifications WHERE user_id = ? AND reference_type = 'message' ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$user_id]);
    $notifs = $stmt->fetchAll();
} catch (Exception $e) {}

include '../../includes/header.php';
?>

<div class="card" style="max-width: 800px; margin: 0 auto; padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 class="card-title" style="margin: 0;">✉️ ข้อความ (DM)</h2>
        <a href="teacher_home.php" class="btn btn-secondary">กลับ</a>
    </div>

    <?php if (empty($notifs)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 3rem; margin-bottom: 10px;">📭</div>
            <p>ไม่มีข้อความใหม่</p>
        </div>
    <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 10px;">
            <?php foreach ($notifs as $n): ?>
                <div style="padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">
                        <div style="font-size: 1rem; line-height: 1.5;">
                            <?php if (!empty($n['link'])): ?>
                                <a href="<?= $base_url ?>/<?= htmlspecialchars($n['link']) ?>" style="color: var(--primary-color); text-decoration: none; font-weight: 500;">
                                    <?= htmlspecialchars($n['message']) ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($n['message']) ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;">
                            <?= date('d/m/Y H:i', strtotime($n['created_at'])) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
