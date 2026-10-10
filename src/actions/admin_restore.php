<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';

requireRole($pdo, 'admin');

$page_title = "กู้คืนฐานข้อมูล";
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['backup_file'])) {
    set_time_limit(0); // Prevent timeout
    
    $file = $_FILES['backup_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (strtolower($ext) === 'sql') {
            try {
                $pdo->beginTransaction();
                
                // Disable foreign key checks temporarily
                $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
                
                // Execute the SQL file line by line
                $query = '';
                $sqlScript = file($file['tmp_name']);
                foreach ($sqlScript as $line) {
                    $trimmed = trim($line);
                    $startWith = substr($trimmed, 0 ,2);
                    $endWith = substr($trimmed, -1 ,1);
                    
                    // Skip comments and empty lines
                    if (empty($trimmed) || $startWith == '--' || $startWith == '/*' || $startWith == '//') {
                        continue;
                    }
                    
                    $query = $query . $line;
                    if ($endWith == ';') {
                        $pdo->exec($query);
                        $query = '';
                    }
                }
                
                $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }
                $success_msg = "กู้คืนฐานข้อมูลจากไฟล์ " . htmlspecialchars($file['name']) . " สำเร็จเรียบร้อยแล้ว";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_msg = "เกิดข้อผิดพลาดในการกู้คืน: " . $e->getMessage();
            }
        } else {
            $error_msg = "กรุณาอัปโหลดไฟล์สกุล .sql เท่านั้น";
        }
    } else {
        $error_msg = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์ (Error Code: " . $file['error'] . ")";
    }
}

include '../../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 40px auto;">
    <h2 class="card-title text-danger">⚠️ นำเข้า / กู้คืนฐานข้อมูล (Restore)</h2>
    
    <?php if ($success_msg): ?>
        <div class="alert alert-success" style="padding: 15px; background: #d4edda; color: #155724; border-radius: 4px; margin-bottom: 20px;"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger" style="padding: 15px; background: #f8d7da; color: #721c24; border-radius: 4px; margin-bottom: 20px;"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 8px; border: 1px solid #ffeeba; margin-bottom: 20px;">
        <strong>ข้อควรระวัง:</strong><br>
        1. การนำเข้าไฟล์ Backup จะไป <strong>ทับข้อมูลปัจจุบันทั้งหมด</strong> ในระบบ<br>
        2. หากคุณแค่ต้องการนำข้อมูลเก่ากลับมาดูชั่วคราว <strong>โปรดทำการ Backup ข้อมูลปัจจุบันก่อนนำเข้า</strong> ทุกครั้ง
    </div>

    <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าได้ทำการ Backup ข้อมูลปัจจุบันไว้แล้ว? การอิมพอร์ตจะทับข้อมูลทั้งหมดในระบบทันที'); document.getElementById('loading').style.display='block';">
        <div class="form-group">
            <label class="form-label">เลือกไฟล์ Backup (.sql)</label>
            <input type="file" name="backup_file" class="form-control" accept=".sql" required style="padding: 10px; height: auto;">
        </div>
        
        <button type="submit" class="btn btn-danger btn-block mt-4" style="background-color: #dc3545; color: white;">
            อัปโหลดและกู้คืนฐานข้อมูล
        </button>
    </form>
    
    <div id="loading" style="display: none; margin-top: 20px; color: #64748b; font-weight: 500; text-align: center;">
        กำลังอิมพอร์ตข้อมูล กรุณารอสักครู่... ⏳
    </div>
    
    <div style="margin-top: 20px; text-align: center;">
        <a href="admin_settings.php" class="btn btn-outline">⬅️ กลับหน้าตั้งค่า</a>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
