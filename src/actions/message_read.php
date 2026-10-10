<?php
// src/actions/message_read.php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$msg_id = $_GET['id'] ?? null;

if (!$msg_id) {
    die("ไม่พบข้อมูลอ้างอิง");
}

// Fetch the message and check permissions (must be sender or receiver)
$stmt = $pdo->prepare("
    SELECT m.*, 
           sender.full_name as sender_name, sender.username as sender_username,
           receiver.full_name as receiver_name, receiver.username as receiver_username
    FROM messages m
    JOIN users sender ON m.sender_id = sender.id
    JOIN users receiver ON m.receiver_id = receiver.id
    WHERE m.id = ?
");
$stmt->execute([$msg_id]);
$msg = $stmt->fetch();

if (!$msg || ($msg['sender_id'] != $user_id && $msg['receiver_id'] != $user_id)) {
    die("คุณไม่มีสิทธิ์เข้าถึงข้อความนี้");
}

// Determine Context (Is this user the sender or receiver?)
$is_sender = ($msg['sender_id'] == $user_id);
$is_receiver = ($msg['receiver_id'] == $user_id);

// If receiver is reading it for the first time, mark as read
if ($is_receiver && $msg['is_read'] == 0) {
    $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id = ?")->execute([$msg_id]);
    
    // Also mark related notifications as read
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND reference_type = 'message' AND reference_id = ?")->execute([$user_id, $msg_id]);
}

$page_title = "อ่านข้อความ";
include '../../includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="page-title" style="margin: 0;">📖 อ่านข้อความ</h2>
        <a href="messages.php?tab=<?= $is_sender ? 'sent' : 'inbox' ?>" class="btn btn-outline">⬅️ กลับ</a>
    </div>

    <div class="card" style="padding: 30px;">
        <div style="border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 20px;">
            <h3 style="margin-top: 0; color: var(--text-color); font-size: 1.4rem;"><?= htmlspecialchars($msg['subject']) ?></h3>
            <div style="color: var(--text-muted); font-size: 0.95rem; margin-top: 10px; display: grid; grid-template-columns: 80px 1fr; gap: 5px;">
                <strong>จาก:</strong> 
                <span><?= htmlspecialchars($msg['sender_name']) ?> <span style="color:#94a3b8;">(@<?= htmlspecialchars($msg['sender_username']) ?>)</span></span>
                
                <strong>ถึง:</strong> 
                <span><?= htmlspecialchars($msg['receiver_name']) ?> <span style="color:#94a3b8;">(@<?= htmlspecialchars($msg['receiver_username']) ?>)</span></span>
                
                <strong>วันที่:</strong> 
                <span><?= date('d/m/Y H:i:s', strtotime($msg['created_at'])) ?></span>
            </div>
        </div>
        
        <div style="font-size: 1.05rem; line-height: 1.6; color: var(--text-color); min-height: 150px; white-space: pre-wrap; word-break: break-word;">
<?= htmlspecialchars($msg['body']) ?>
        </div>

        <?php if (!empty($msg['attachment_path'])): ?>
            <div style="margin-top: 30px; border-top: 1px dashed var(--border-color); padding-top: 20px;">
                <h4 style="margin-top: 0; font-size: 1rem; color: var(--text-muted);">📎 ไฟล์แนบ</h4>
                <?php 
                $ext = strtolower(pathinfo($msg['attachment_path'], PATHINFO_EXTENSION));
                $file_url = $base_url . '/' . $msg['attachment_path'];
                
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                    <a href="<?= $file_url ?>" target="_blank">
                        <img src="<?= $file_url ?>" alt="ไฟล์แนบ" style="max-width: 100%; max-height: 400px; border-radius: 8px; border: 1px solid var(--border-color); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    </a>
                <?php else: ?>
                    <a href="<?= $file_url ?>" target="_blank" class="btn btn-outline" style="display: inline-flex; align-items: center; gap: 8px;">
                        📄 เปิดดูไฟล์ PDF / เอกสารแนบ
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($is_receiver): ?>
            <div style="margin-top: 30px; text-align: right;">
                <a href="message_compose.php?reply_to=<?= $msg['sender_id'] ?>" class="btn btn-primary" style="padding: 10px 25px;">↩️ ตอบกลับ</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
