<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('inspector', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles) && !in_array('discipline', $user_roles)) {
    echo "Access Denied";
    exit();
}

header("Location: inspector_late_check.php");
exit();

?>

<div class="card" style="text-align: center; padding: 50px 20px;">
    <h1 style="color: var(--primary-color); margin-bottom: 20px;">👮 แดชบอร์ดสารวัตรนักเรียน</h1>
    <p style="color: var(--text-muted); font-size: 1.1rem; margin-bottom: 40px;">
        ยินดีต้อนรับสู่ระบบสำหรับสารวัตรนักเรียนและครูเวรประจำวัน<br>
        คุณสามารถบันทึกข้อมูลนักเรียนที่มาสายได้ที่นี่
    </p>

    <a href="inspector_late_check.php" class="btn btn-primary" style="font-size: 1.2rem; padding: 15px 40px; border-radius: 30px;">
        ⏰ เช็คมาสายคาบ 0
    </a>
</div>

<?php include '../../includes/footer.php'; ?>
