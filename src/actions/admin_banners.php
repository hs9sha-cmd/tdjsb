<?php
session_start();
require_once '../../config/database.php';

// Check admin role
if (!isset($_SESSION['user_id']) || !in_array('admin', $_SESSION['roles'] ?? [])) {
    header("Location: ../../login.php");
    exit();
}

$page_title = "จัดการแบนเนอร์ประชาสัมพันธ์";
include '../../includes/header.php';

$upload_dir = '../../uploads/banners/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action == 'add_banner') {
            $target_link = trim($_POST['target_link'] ?? '');
            
            if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['banner_image']['tmp_name'];
                $ext = strtolower(pathinfo($_FILES['banner_image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                
                if (in_array($ext, $allowed)) {
                    $new_name = uniqid('banner_') . '.' . $ext;
                    $dest_path = $upload_dir . $new_name;
                    
                    if (move_uploaded_file($tmp_name, $dest_path)) {
                        $db_path = 'uploads/banners/' . $new_name;
                        $stmt = $pdo->prepare("INSERT INTO banners (image_path, target_link) VALUES (?, ?)");
                        $stmt->execute([$db_path, $target_link]);
                        $success_msg = "อัปโหลดแบนเนอร์สำเร็จ";
                    } else {
                        // Attempt to fix permissions automatically
                        chmod($upload_dir, 0777);
                        if (move_uploaded_file($tmp_name, $dest_path)) {
                            $db_path = 'uploads/banners/' . $new_name;
                            $stmt = $pdo->prepare("INSERT INTO banners (image_path, target_link) VALUES (?, ?)");
                            $stmt->execute([$db_path, $target_link]);
                            $success_msg = "อัปโหลดแบนเนอร์สำเร็จ";
                        } else {
                            $error_msg = "ไม่สามารถบันทึกไฟล์ได้ (Permission Denied) กรุณาตั้งค่า chmod 777 ให้โฟลเดอร์ uploads/banners/ บนเซิร์ฟเวอร์";
                        }
                    }
                } else {
                    $error_msg = "รองรับเฉพาะไฟล์ภาพ (jpg, png, webp, gif) เท่านั้น";
                }
            } else {
                $error_msg = "กรุณาเลือกไฟล์ภาพแบนเนอร์";
            }
        } elseif ($action == 'toggle_status') {
            $id = $_POST['banner_id'];
            $status = $_POST['status'] == '1' ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE banners SET is_active = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            $success_msg = "อัปเดตสถานะแบนเนอร์สำเร็จ";
        } elseif ($action == 'delete_banner') {
            $id = $_POST['banner_id'];
            // Get path to delete file
            $stmt = $pdo->prepare("SELECT image_path FROM banners WHERE id = ?");
            $stmt->execute([$id]);
            $banner = $stmt->fetch();
            
            if ($banner) {
                $file_to_delete = '../../' . $banner['image_path'];
                if (file_exists($file_to_delete)) {
                    unlink($file_to_delete);
                }
                $del_stmt = $pdo->prepare("DELETE FROM banners WHERE id = ?");
                $del_stmt->execute([$id]);
                $success_msg = "ลบแบนเนอร์สำเร็จ";
            }
        }
    }
}

// Fetch Banners
$stmt = $pdo->query("SELECT * FROM banners ORDER BY id DESC");
$banners = $stmt->fetchAll();
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">🖼️ จัดการแบนเนอร์ประชาสัมพันธ์ (PR Banners)</h2>
    </div>

    <?php if (isset($success_msg)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <div style="background: #f8fafc; padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 1px solid var(--border-color);">
        <h3 style="margin-top: 0; font-size: 1.1rem; color: var(--primary-color);">อัปโหลดแบนเนอร์ใหม่</h3>
        <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="action" value="add_banner">
            
            <div class="form-group" style="flex: 1; min-width: 250px; margin-bottom: 0;">
                <label>ไฟล์ภาพแบนเนอร์ (แนะนำขนาด 1920x600 หรืออัตราส่วน 16:9)</label>
                <input type="file" name="banner_image" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp" required>
            </div>
            
            <div class="form-group" style="flex: 1; min-width: 250px; margin-bottom: 0;">
                <label>ลิงก์ปลายทาง (เมื่อคลิกแบนเนอร์ - ปล่อยว่างได้)</label>
                <input type="url" name="target_link" class="form-control" placeholder="https://facebook.com/...">
            </div>
            
            <button type="submit" class="btn btn-primary">อัปโหลด</button>
        </form>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th style="width: 50px; text-align: center;">ID</th>
                <th style="width: 250px;">ภาพแบนเนอร์</th>
                <th>ลิงก์ปลายทาง</th>
                <th style="width: 100px; text-align: center;">สถานะ</th>
                <th style="width: 150px; text-align: center;">จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($banners)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 30px; color: var(--text-muted);">ยังไม่มีแบนเนอร์ในระบบ</td>
                </tr>
            <?php else: ?>
                <?php foreach ($banners as $b): ?>
                    <tr>
                        <td style="text-align: center; vertical-align: middle;"><?= $b['id'] ?></td>
                        <td>
                            <img src="../../<?= htmlspecialchars($b['image_path']) ?>" alt="Banner" style="width: 100%; max-width: 250px; height: auto; border-radius: 4px; border: 1px solid #ccc; object-fit: cover;">
                        </td>
                        <td style="vertical-align: middle;">
                            <?php if ($b['target_link']): ?>
                                <a href="<?= htmlspecialchars($b['target_link']) ?>" target="_blank" style="color: var(--primary-color); word-break: break-all; font-size: 0.9rem;">
                                    <?= htmlspecialchars($b['target_link']) ?>
                                </a>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 0.9rem;">(ไม่มีลิงก์)</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: middle;">
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="banner_id" value="<?= $b['id'] ?>">
                                <input type="hidden" name="status" value="<?= $b['is_active'] ?>">
                                <?php if ($b['is_active']): ?>
                                    <button type="submit" class="btn" style="background: #10b981; color: white; padding: 4px 10px; font-size: 0.8rem;">เปิดใช้งาน</button>
                                <?php else: ?>
                                    <button type="submit" class="btn" style="background: #94a3b8; color: white; padding: 4px 10px; font-size: 0.8rem;">ปิดการแสดง</button>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td style="text-align: center; vertical-align: middle;">
                            <form method="POST" style="margin: 0;" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการลบแบนเนอร์นี้?');">
                                <input type="hidden" name="action" value="delete_banner">
                                <input type="hidden" name="banner_id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="padding: 4px 10px; font-size: 0.8rem;">🗑️ ลบ</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../../includes/footer.php'; ?>
