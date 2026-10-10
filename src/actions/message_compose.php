<?php
// src/actions/message_compose.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Pre-fill recipient if replied to
$reply_to = $_GET['reply_to'] ?? '';

// Fetch all users for dropdown
$users = $pdo->query("SELECT id, full_name, username FROM users WHERE id != " . (int)$user_id . " ORDER BY full_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $receiver_id = $_POST['receiver_id'] ?? '';
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');
    
    if (empty($receiver_id) || empty($subject) || empty($body)) {
        $error = "กรุณากรอกข้อมูลให้ครบถ้วน";
    } else {
        $attachment_path = null;
        
        // Handle file upload
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
            $file_type = mime_content_type($_FILES['attachment']['tmp_name']);
            $file_size = $_FILES['attachment']['size'];
            
            if (!in_array($file_type, $allowed_types)) {
                $error = "อนุญาตเฉพาะไฟล์รูปภาพ (JPG, PNG, GIF) หรือไฟล์เอกสาร PDF เท่านั้น";
            } elseif ($file_size > 5 * 1024 * 1024) { // 5MB Limit
                $error = "ขนาดไฟล์ต้องไม่เกิน 5MB";
            } else {
                $ext = pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
                $upload_dir = '../../uploads/messages/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $filename = uniqid('msg_') . '.' . $ext;
                $target_path = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $target_path)) {
                    $attachment_path = 'uploads/messages/' . $filename;
                } else {
                    $error = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์แนบ";
                }
            }
        }
        
        if (empty($error)) {
            try {
                $pdo->beginTransaction();
                
                $receivers = [];
                if ($receiver_id === 'all' && in_array('admin', $_SESSION['roles'] ?? [])) {
                    // Extract all user IDs from the already fetched $users array
                    $receivers = array_column($users, 'id');
                } else {
                    $receivers = [$receiver_id];
                }
                
                $notif_msg = "📬 ข้อความใหม่จาก " . $_SESSION['full_name'] . ": " . mb_strimwidth($subject, 0, 30, '...');
                $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, subject, body, attachment_path) VALUES (?, ?, ?, ?, ?)");
                $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, reference_type, reference_id, link) VALUES (?, ?, ?, ?, ?)");
                
                foreach ($receivers as $rec_id) {
                    $stmt->execute([$user_id, $rec_id, $subject, $body, $attachment_path]);
                    $msg_id = $pdo->lastInsertId();
                    
                    $notif_link = "src/actions/message_read.php?id=" . $msg_id;
                    $notif_stmt->execute([$rec_id, $notif_msg, 'message', $msg_id, $notif_link]);
                }
                
                $pdo->commit();
                $success = "ส่งข้อความเรียบร้อยแล้ว (" . count($receivers) . " คน)";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
            }
        }
    }
}

$page_title = "ส่งข้อความใหม่";
include '../../includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="page-title" style="margin: 0;">✍️ เขียนข้อความใหม่</h2>
        <a href="messages.php" class="btn btn-outline">⬅️ กลับไปกล่องข้อความ</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom: 20px; padding: 15px; background: #fee2e2; border-left: 4px solid #ef4444; color: #b91c1c;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom: 20px; padding: 15px; background: #dcfce7; border-left: 4px solid #22c55e; color: #15803d;">
            <?= htmlspecialchars($success) ?>
            <br>
            <a href="messages.php" style="font-weight: bold; color: inherit; text-decoration: underline; margin-top: 10px; display: inline-block;">ดูข้อความที่ส่งแล้วคลิกที่นี่</a>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>ผู้รับ <span style="color:red;">*</span></label>
                <select name="receiver_id" id="receiver_select" class="form-control" required style="width: 100%;">
                    <option value="">-- เลือกผู้รับ --</option>
                    <?php if (in_array('admin', $_SESSION['roles'] ?? [])): ?>
                        <option value="all">📢 ส่งถึงทุกคนในระบบ (Broadcast)</option>
                    <?php endif; ?>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $reply_to == $u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>หัวข้อเรื่อง <span style="color:red;">*</span></label>
                <input type="text" name="subject" class="form-control" placeholder="ระบุหัวข้อที่ต้องการติดต่อ" required>
            </div>
            
            <div class="form-group">
                <label>เนื้อหาข้อความ <span style="color:red;">*</span></label>
                <textarea name="body" class="form-control" rows="8" placeholder="พิมพ์ข้อความของคุณที่นี่..." required></textarea>
            </div>
            
            <div class="form-group">
                <label>แนบไฟล์ (ไม่บังคับ)</label>
                <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.gif,.pdf">
                <small class="text-muted" style="display: block; margin-top: 5px;">* รองรับไฟล์รูปภาพ (JPG, PNG, GIF) หรือ PDF ขนาดไม่เกิน 5MB</small>
            </div>
            
            <div style="text-align: right; margin-top: 20px;">
                <button type="submit" class="btn btn-primary" style="padding: 12px 30px; font-size: 16px; font-weight: bold;">ส่งข้อความ ✈️</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<!-- Include Tom Select for searchable dropdown -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    new TomSelect("#receiver_select", {
        create: false,
        placeholder: "-- พิมพ์เพื่อค้นหาผู้รับ --"
    });
});
</script>
