<?php
session_start();
require_once 'config/database.php';
require_once 'config/auth.php';

// If already logged in, redirect to correct dashboard
if (isLoggedIn()) {
    $roles = $_SESSION['roles'] ?? [];
    if (in_array('teacher', $roles)) header("Location: src/actions/teacher_home.php");
    elseif (in_array('admin', $roles)) header("Location: src/actions/admin_dashboard.php");
    elseif (in_array('academic', $roles) || in_array('executive', $roles)) header("Location: src/actions/academic_dashboard.php");
    elseif (in_array('discipline', $roles)) header("Location: src/actions/discipline_dashboard.php");
    elseif (in_array('inspector', $roles)) header("Location: src/actions/inspector_dashboard.php");
    else header("Location: src/actions/teacher_home.php");
    exit();
}

$error_msg = '';
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'invalid_credentials') $error_msg = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
    elseif ($_GET['error'] === 'empty_fields') $error_msg = 'กรุณากรอกข้อมูลให้ครบถ้วน';
}

$site_settings = [];
try {
    $stmt_set = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
    if ($stmt_set) $site_settings = $stmt_set->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {}

$school_name = !empty($site_settings['school_name']) ? $site_settings['school_name'] : 'โรงเรียนของคุณ';
$school_logo = !empty($site_settings['school_logo']) ? $site_settings['school_logo'] : 'assets/images/login_logo.png';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - <?= htmlspecialchars($school_name) ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($school_logo) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #9333ea;
            --primary-pink: #f472b6;
            --primary-purple: #a855f7;
            --text-main: #2e1065;
            --text-muted: #7e22ce;
            --bg-input: rgba(255, 255, 255, 0.85);
            --border: rgba(216, 180, 254, 0.5);
        }
        
        * {
            box-sizing: border-box;
            font-family: 'Prompt', sans-serif;
            margin: 0;
            padding: 0;
        }
        
        body {
            background: radial-gradient(circle at 10% 20%, #fdf2f8 0%, #faf5ff 35%, #f5f3ff 70%, #fce7f3 100%) fixed;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: var(--text-main);
            padding: 20px;
            position: relative;
            overflow-x: hidden;
            opacity: 0;
            animation: slowFadeIn 1.2s ease-in forwards;
        }

        @keyframes slowFadeIn {
            0% { opacity: 0; transform: translateY(8px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* Ambient Glowing Pastel Orbs */
        .bg-orb-1 {
            position: fixed;
            top: -10%;
            left: -5%;
            width: 480px;
            height: 480px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(244, 114, 182, 0.4) 0%, rgba(244, 114, 182, 0) 70%);
            filter: blur(70px);
            z-index: 0;
            pointer-events: none;
        }

        .bg-orb-2 {
            position: fixed;
            bottom: -10%;
            right: -5%;
            width: 520px;
            height: 520px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(168, 85, 247, 0.4) 0%, rgba(168, 85, 247, 0) 70%);
            filter: blur(70px);
            z-index: 0;
            pointer-events: none;
        }

        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 390px;
            padding: 42px 34px;
            text-align: center;
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.95);
            border-radius: 28px;
            box-shadow: 0 20px 45px rgba(168, 85, 247, 0.12), inset 0 1px 1px rgba(255, 255, 255, 0.95);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .login-container:hover {
            box-shadow: 0 24px 50px rgba(236, 72, 153, 0.16), inset 0 1px 1px rgba(255, 255, 255, 1);
        }

        .login-logo {
            max-width: 120px;
            height: auto;
            margin-bottom: 16px;
            filter: drop-shadow(0 6px 14px rgba(168, 85, 247, 0.2));
            transition: transform 0.3s ease;
        }

        .login-logo:hover {
            transform: scale(1.05);
        }

        .login-title {
            font-size: 26px;
            font-weight: 700;
            background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }

        .login-subtitle {
            font-size: 15px;
            color: var(--text-muted);
            opacity: 0.85;
            margin-bottom: 32px;
            font-weight: 500;
        }

        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #4a044e;
            margin-bottom: 8px;
        }

        .form-control {
            width: 100%;
            padding: 13px 16px;
            font-size: 15px;
            border: 1.5px solid var(--border);
            border-radius: 14px;
            background-color: var(--bg-input);
            color: var(--text-main);
            transition: all 0.25s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #c084fc;
            background-color: #ffffff;
            box-shadow: 0 0 0 4px rgba(232, 121, 249, 0.2);
        }

        .form-control::placeholder {
            color: #c084fc;
            opacity: 0.6;
            font-weight: 400;
        }

        .btn-submit {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            font-weight: 600;
            color: #ffffff;
            background: linear-gradient(135deg, #f472b6 0%, #c084fc 50%, #9333ea 100%);
            border: none;
            border-radius: 14px;
            cursor: pointer;
            box-shadow: 0 10px 25px -5px rgba(168, 85, 247, 0.4), inset 0 1px 1px rgba(255, 255, 255, 0.4);
            transition: all 0.25s ease;
            margin-top: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #fb7185 0%, #d8b4fe 50%, #7e22ce 100%);
            transform: translateY(-2px);
            box-shadow: 0 14px 30px -5px rgba(168, 85, 247, 0.5);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .error-msg {
            color: #be185d;
            background: #fdf2f8;
            border: 1px solid #fbcfe8;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 22px;
            font-size: 14px;
            font-weight: 500;
        }
    </style>
</head>
<body>

    <!-- Ambient Glowing Orbs -->
    <div class="bg-orb-1"></div>
    <div class="bg-orb-2"></div>

    <div class="login-container">
        <!-- Logo -->
        <img src="<?= htmlspecialchars($school_logo) ?>" alt="School Logo" class="login-logo" onerror="this.src='assets/images/login_logo.png'">
        
        <!-- Headers -->
        <h1 class="login-title">เข้าสู่ระบบ</h1>
        <p class="login-subtitle"><?= htmlspecialchars($school_name) ?></p>

        <?php if ($error_msg): ?>
            <div class="error-msg"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <!-- Login Form -->
        <form action="login.php" method="POST">
            <div class="form-group">
                <label class="form-label"><span style="color:#c084fc; margin-right:5px;">👤</span>ชื่อผู้ใช้งาน</label>
                <input type="text" name="username" class="form-control" placeholder="กรอกชื่อผู้ใช้" required>
            </div>

            <div class="form-group">
                <label class="form-label"><span style="color:#f472b6; margin-right:5px;">🔒</span>รหัสผ่าน</label>
                <input type="password" name="password" class="form-control" placeholder="กรอกรหัสผ่าน" required>
            </div>

            <button type="submit" class="btn-submit">เข้าสู่ระบบ ➔</button>
        </form>
    </div>

</body>
</html>
