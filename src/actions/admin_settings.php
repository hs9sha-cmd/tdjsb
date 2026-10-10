<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$page_title = "ตั้งค่าระบบ";
include '../../includes/header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2 class="card-title" style="margin-bottom: 0;">จัดการฐานข้อมูล</h2>
        <a href="admin_dashboard.php" class="btn btn-outline" style="color: #5e5ce6; border-color: #5e5ce6;">⬅️ แดชบอร์ด</a>
    </div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
        
        <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 10px;">💾</div>
            <h3>สำรองฐานข้อมูล (Backup)</h3>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 15px;">ส่งออกข้อมูลเป็นไฟล์ SQL เพื่อสำรองข้อมูล</p>
            <a href="admin_backup.php" class="btn btn-outline btn-block">ไปที่หน้าสำรองฐานข้อมูล</a>
        </div>

        <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 10px;">📥</div>
            <h3 style="color: var(--danger-color);">นำเข้าข้อมูล (Restore)</h3>
            <p style="color: var(--text-muted); font-size: 0.875rem; margin-bottom: 15px;">อัปโหลดไฟล์ SQL เพื่อกู้คืนระบบหรือดูข้อมูลเก่า</p>
            <a href="admin_restore.php" class="btn btn-danger btn-block" style="background-color: var(--danger-color); color: white;">ไปที่หน้านำเข้าข้อมูล</a>
        </div>
        
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
