<?php
session_start();

// Prevent aggressive browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getBaseUrl() {
    return (strpos($_SERVER['REQUEST_URI'], '/TeeDiewJob') === 0) ? '/TeeDiewJob' : '';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: " . getBaseUrl() . "/login.php");
        exit();
    }
    
    // Force password change interception
    if (isset($_SESSION['force_password_change']) && $_SESSION['force_password_change'] == 1) {
        $current_script = basename($_SERVER['PHP_SELF']);
        if ($current_script !== 'change_password.php' && $current_script !== 'logout.php') {
            header("Location: " . getBaseUrl() . "/change_password.php");
            exit();
        }
    }
}

function getUserRoles($pdo, $user_id) {
    if (isset($_SESSION['roles'])) {
        return $_SESSION['roles'];
    }
    
    $stmt = $pdo->prepare("SELECT r.role_name FROM roles r JOIN user_roles ur ON r.id = ur.role_id WHERE ur.user_id = ?");
    $stmt->execute([$user_id]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $_SESSION['roles'] = $roles;
    return $roles;
}

function hasRole($pdo, $user_id, $role_name) {
    $roles = getUserRoles($pdo, $user_id);
    if (is_array($role_name)) {
        return count(array_intersect($role_name, $roles)) > 0;
    }
    return in_array($role_name, $roles);
}

function requireRole($pdo, $role_name) {
    requireLogin();
    if (!hasRole($pdo, $_SESSION['user_id'], $role_name)) {
        // Option to redirect to a general dashboard depending on their role
        header("Location: " . getBaseUrl() . "/index.php");
        exit();
    }
}
