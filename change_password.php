<?php
// change_password.php
require_once 'config/database.php';
require_once 'config/auth.php';

if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

// Check if they actually need to change it
if (!isset($_SESSION['force_password_change']) || $_SESSION['force_password_change'] == 0) {
    header("Location: index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = 'กรุณากรอกรหัสผ่านใหม่ทั้ง 2 ช่อง';
    } elseif ($new_password !== $confirm_password) {
        $error = 'รหัสผ่านใหม่และการยืนยันไม่ตรงกัน';
    } elseif (strlen($new_password) < 4) {
        $error = 'รหัสผ่านควรมีความยาวอย่างน้อย 4 ตัวอักษร';
    } else {
        $pass_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, force_password_change = 0 WHERE id = ?");
        $stmt->execute([$pass_hash, $user_id]);
        
        $_SESSION['force_password_change'] = 0;
        
        header("Location: index.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ตั้งรหัสผ่านใหม่ - ระบบจัดการโรงเรียน</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background-color: var(--bg-main);
            position: relative;
        }
        .login-card {
            width: 100%;
            max-width: 450px;
            padding: 40px 30px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            text-align: center;
        }
        .login-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        .alert-error {
            background-color: #FEE2E2;
            color: #991B1B;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.875rem;
        }
        .alert-info {
            background-color: #DBEAFE;
            color: #1E40AF;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            text-align: left;
            border-left: 4px solid #3B82F6;
        }
        .logo-container {
            margin-bottom: 15px;
        }
        .logo-img {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="logo-container">
        <img src="assets/images/school_logo.png?v=<?= time() ?>" alt="School Logo" class="logo-img" onerror="this.src='https://ui-avatars.com/api/?name=DS&background=4CAF50&color=fff&size=100'">
    </div>
    <div class="login-title">เปลี่ยนรหัสผ่านเพื่อความปลอดภัย</div>
    <div class="alert-info">
        คุณ <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'ผู้ใช้งาน') ?></strong>,<br>
        เนื่องจากนี่เป็นการเข้าใช้งานครั้งแรก (หรือรหัสผ่านเพิ่งถูกแก้ไขโดยผู้ดูแล) กรุณาตั้งรหัสผ่านใหม่ของตนเองก่อนเข้าสู่ระบบครับ
    </div>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="change_password.php" style="text-align: left;">
        <div class="form-group">
            <label class="form-label" for="new_password">รหัสผ่านใหม่ (อย่างน้อย 4 ตัวอักษร)</label>
            <input type="password" id="new_password" name="new_password" class="form-control" required autofocus>
        </div>
        <div class="form-group mb-4">
            <label class="form-label" for="confirm_password">ยืนยันรหัสผ่านใหม่อีกครั้ง</label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block" style="padding: 12px; font-size: 1rem;">บันทึกรหัสผ่านและดำเนินการต่อ</button>
    </form>
</div>

<footer style="position: absolute; bottom: 20px; text-align: center; color: var(--text-muted); font-size: 0.8rem; width: 100%;">
    <div style="font-weight: 600; color: var(--text-main); margin-bottom: 3px;">2026 &copy; HS9SHA & Wednesday LLC.</div>
    <div style="margin-bottom: 1px;">M&M Service- All Rights Reserved.</div>
    <div><span style="color: var(--info-color); text-decoration: none;">Tel.0817381914</span></div>
</footer>

</body>
</html>
