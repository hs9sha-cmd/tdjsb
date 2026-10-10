<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';

// Force login check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$page_title = "การแจ้งเตือนของฉัน";
$user_id = $_SESSION['user_id'];

$filter = $_GET['filter'] ?? 'all';
$date_filter = $_GET['date'] ?? '';
$query_filter = "";
$params = [$user_id];

// Determine filter and update read status
try {
    if ($filter === 'late') {
        $query_filter = " AND reference_type = 'late_alert'";
        $update_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND reference_type = 'late_alert' AND is_read = 0");
    } elseif ($filter === 'absent') {
        $query_filter = " AND reference_type = 'absent_alert'";
        $update_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND reference_type = 'absent_alert' AND is_read = 0");
    } else {
        $query_filter = " AND reference_type NOT IN ('late_alert', 'absent_alert')";
        $update_stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND reference_type NOT IN ('late_alert', 'absent_alert') AND is_read = 0");
    }
    $update_stmt->execute([$user_id]);
} catch (Exception $e) {}

if (!empty($date_filter) && $filter === 'late') {
    $query_filter .= " AND DATE(created_at) = ?";
    $params[] = $date_filter;
}

// Fetch recent notifications
$notifs = [];
try {
    $stmt = $pdo->prepare("SELECT message, created_at, link, is_read FROM notifications WHERE user_id = ? {$query_filter} ORDER BY created_at DESC LIMIT 50");
    $stmt->execute($params);
    $notifs = $stmt->fetchAll();
} catch (Exception $e) {}

include '../../includes/header.php';
?>

<div class="card" style="max-width: 800px; margin: 0 auto; padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 class="card-title" style="margin: 0;">🔔 การแจ้งเตือน</h2>
        <button type="button" class="btn btn-secondary" onclick="history.back()">กลับ</button>
    </div>

    <!-- Tabs -->
    <div style="display: flex; gap: 10px; margin-bottom: 20px; overflow-x: auto; padding-bottom: 5px;">
        <a href="?filter=all" class="btn <?= $filter === 'all' ? 'btn-primary' : 'btn-outline' ?>" style="white-space: nowrap; border-radius: 20px; padding: 5px 15px;">ทั่วไป (ข้อความ/สอนแทน)</a>
        <a href="?filter=late" class="btn <?= $filter === 'late' ? 'btn-primary' : 'btn-outline' ?>" style="white-space: nowrap; border-radius: 20px; padding: 5px 15px; <?= $filter === 'late' ? 'background: #ef4444; border-color: #ef4444;' : 'color: #ef4444; border-color: #fca5a5;' ?>">🏃 นร.มาสาย</a>
        <a href="?filter=absent" class="btn <?= $filter === 'absent' ? 'btn-primary' : 'btn-outline' ?>" style="white-space: nowrap; border-radius: 20px; padding: 5px 15px; <?= $filter === 'absent' ? 'background: #f59e0b; border-color: #f59e0b;' : 'color: #f59e0b; border-color: #fcd34d;' ?>">⚠️ เสี่ยง มส.</a>
    </div>

    <?php if ($filter === 'late'): ?>
        <form method="GET" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="filter" value="late">
            <label style="font-weight: 600; color: var(--text-muted);">เลือกวันที่:</label>
            <input type="date" name="date" class="form-control" style="width: auto; border-radius: 12px; padding: 6px 12px;" value="<?= htmlspecialchars($date_filter) ?>" onchange="this.form.submit()">
            <?php if (!empty($date_filter)): ?>
                <a href="?filter=late" class="btn btn-sm btn-outline" style="border-radius: 12px;">ล้างตัวกรอง</a>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if (empty($notifs)): ?>
        <div style="text-align: center; padding: 40px; color: var(--text-muted);">
            <div style="font-size: 3rem; margin-bottom: 10px;">📭</div>
            <p>ไม่มีการแจ้งเตือนใหม่ในหมวดหมู่นี้</p>
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
