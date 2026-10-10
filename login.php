<?php
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
    else header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, full_name, force_password_change FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['force_password_change'] = $user['force_password_change'];
            
            // Re-fetch roles
            $roles = getUserRoles($pdo, $user['id']);
            
            if ($user['force_password_change']) {
                header("Location: change_password.php");
                exit();
            }
            
            // Role-based redirect
            if (in_array('teacher', $roles)) {
                header("Location: src/actions/teacher_home.php");
            } elseif (in_array('admin', $roles)) {
                header("Location: src/actions/admin_dashboard.php");
            } elseif (in_array('academic', $roles) || in_array('executive', $roles)) {
                header("Location: src/actions/academic_dashboard.php");
            } elseif (in_array('discipline', $roles)) {
                header("Location: src/actions/discipline_dashboard.php");
            } elseif (in_array('inspector', $roles)) {
                header("Location: src/actions/inspector_late_check.php");
            } else {
                header("Location: index.php");
            }
            exit();
        } else {
            // Incorrect credentials
            header("Location: index.php?error=invalid_credentials");
            exit();
        }
    } else {
        header("Location: index.php?error=empty_fields");
        exit();
    }
} else {
    // If accessed via GET, redirect to index
    header("Location: index.php");
    exit();
}
