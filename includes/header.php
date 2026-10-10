<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$base_url = '';
if (strpos($_SERVER['REQUEST_URI'], '/TeeDiewJob') === 0) {
    $base_url = '/TeeDiewJob';
}

// Ensure roles are available
if (isset($pdo) && isset($_SESSION['user_id']) && !isset($_SESSION['roles'])) {
    getUserRoles($pdo, $_SESSION['user_id']);
}
$user_roles = $_SESSION['roles'] ?? [];
$current_role_display = count($user_roles) > 0 ? implode(', ', $user_roles) : 'ไม่มีสิทธิ์';

// Determine Theme
$current_page = basename($_SERVER['PHP_SELF']);
$theme_class = 'theme-green'; // Default for teacher or normal users

if (strpos($current_page, 'admin_') === 0) {
    $theme_class = 'theme-dark';
} elseif (strpos($current_page, 'academic_') === 0 || strpos($current_page, 'executive_') === 0) {
    $theme_class = 'theme-academic';
} elseif (strpos($current_page, 'discipline_') === 0) {
    $theme_class = 'theme-discipline';
} elseif (strpos($current_page, 'inspector_') === 0) {
    $theme_class = 'theme-inspector';
}

// Override for shared pages based on roles
if ($current_page === 'report_homeroom_monthly.php') {
    if (in_array('discipline', $user_roles)) {
        $theme_class = 'theme-discipline';
    } elseif (in_array('academic', $user_roles) || in_array('executive', $user_roles)) {
        $theme_class = 'theme-academic';
    }
} elseif ($current_page === 'inspector_late_check.php' || $current_page === 'discipline_late_check.php') {
    if (in_array('discipline', $user_roles)) {
        $theme_class = 'theme-discipline';
    }
} elseif ($current_page === 'academic_feed.php') {
    if (isset($_GET['view']) && $_GET['view'] === 'teacher') {
        $theme_class = 'theme-green';
    } elseif (in_array('teacher', $user_roles) && !in_array('academic', $user_roles) && !in_array('executive', $user_roles) && !in_array('admin', $user_roles)) {
        $theme_class = 'theme-green';
    } elseif (in_array('admin', $user_roles) && !in_array('academic', $user_roles) && !in_array('executive', $user_roles)) {
        $theme_class = 'theme-dark';
    }
} elseif ($current_page === 'discipline_student_history.php') {
    if (!in_array('discipline', $user_roles) && in_array('teacher', $user_roles)) {
        $theme_class = 'theme-green';
    }
} elseif ($current_page === 'teacher_inspection.php') {
    if (in_array('discipline', $user_roles)) {
        $theme_class = 'theme-discipline';
    }
}

// Fetch Site Settings
$site_settings = [];
if (isset($pdo)) {
    try {
        $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
        $site_settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {}
}

// Fetch Unread Notifications (Combined)
$unread_notifications_count = 0;
if (isset($pdo) && isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    try {
        // 1. Unread DM + System Alerts
        $stmt_alerts = $pdo->prepare("
            SELECT COUNT(DISTINCT n.id) FROM notifications n
            LEFT JOIN students s ON n.reference_type = 'late_alert' AND n.reference_id = s.id
            LEFT JOIN classrooms c ON s.classroom_id = c.id
            WHERE n.user_id = ? AND n.is_read = 0 AND n.reference_type != 'absent_alert'
            AND (
                n.reference_type != 'late_alert' 
                OR c.room_name = (SELECT advisor_classroom FROM users WHERE id = ?)
            )
        ");
        $stmt_alerts->execute([$uid, $uid]);
        $c_alerts = (int)$stmt_alerts->fetchColumn();

        // 2. Daily Late count (for advisor classroom today, unseen only)
        $stmt_late = $pdo->prepare("
            SELECT COUNT(*) FROM homeroom_attendance ha
            JOIN students s ON ha.student_id = s.id
            JOIN classrooms c ON s.classroom_id = c.id
            WHERE ha.attendance_date = CURDATE() AND ha.status = 'late'
            AND c.room_name = (SELECT advisor_classroom FROM users WHERE id = ?)
        ");
        $stmt_late->execute([$uid]);
        $total_late_today = (int)$stmt_late->fetchColumn();

        $seen_data = $_SESSION['seen_late_' . $uid] ?? ($_COOKIE['seen_late_' . $uid] ?? '');
        $c_late = $total_late_today;
        if (!empty($seen_data)) {
            $parts = explode('_', $seen_data);
            if (($parts[0] ?? '') === date('Y-m-d')) {
                $c_late = max(0, $total_late_today - (int)($parts[1] ?? 0));
            }
        }

        // 3. Absent / At-risk alerts (for advisor classroom)
        $stmt_absent = $pdo->prepare("
            SELECT COUNT(*) FROM notifications n
            JOIN students s ON n.reference_id LIKE CONCAT('absent_', s.id, '\_%')
            JOIN classrooms c ON s.classroom_id = c.id
            WHERE n.user_id = ? AND n.is_read = 0 AND n.reference_type = 'absent_alert'
            AND c.room_name = (SELECT advisor_classroom FROM users WHERE id = ?)
        ");
        $stmt_absent->execute([$uid, $uid]);
        $c_absent = (int)$stmt_absent->fetchColumn();

        $unread_notifications_count = $c_alerts + $c_late + $c_absent;
    } catch (Exception $e) {
        try {
            $stmt_notif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt_notif->execute([$uid]);
            $unread_notifications_count = (int)$stmt_notif->fetchColumn();
        } catch (Exception $e2) {}
    }
}

// Fetch teacher profile for topbar display
$topbar_teacher = null;
if (isset($pdo) && isset($_SESSION['user_id'])) {
    try {
        $stmt_tp = $pdo->prepare("SELECT full_name, advisor_classroom, department, profile_photo FROM users WHERE id = ?");
        $stmt_tp->execute([$_SESSION['user_id']]);
        $topbar_teacher = $stmt_tp->fetch();
    } catch (Exception $e) {
        try {
            $stmt_tp = $pdo->prepare("SELECT full_name, advisor_classroom, department FROM users WHERE id = ?");
            $stmt_tp->execute([$_SESSION['user_id']]);
            $topbar_teacher = $stmt_tp->fetch();
            if ($topbar_teacher) $topbar_teacher['profile_photo'] = null;
        } catch (Exception $e2) {}
    }
}
$topbar_photo_url = '';
if ($topbar_teacher) {
    if (!empty($topbar_teacher['profile_photo'])) {
        $topbar_photo_url = $base_url . '/' . $topbar_teacher['profile_photo'] . '?v=' . time();
    } else {
        $enc = urlencode($topbar_teacher['full_name'] ?? 'User');
        $topbar_photo_url = "https://ui-avatars.com/api/?name={$enc}&size=80&background=94a3b8&color=fff&bold=true&font-size=0.4";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php 
    $sys_school_name = !empty($site_settings['school_name']) ? htmlspecialchars($site_settings['school_name']) : 'TeeDiewJob';
    $sys_school_logo = !empty($site_settings['school_logo']) ? htmlspecialchars($site_settings['school_logo']) : 'assets/images/school_logo.png';
    ?>
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - ' : ''; ?><?= $sys_school_name ?></title>
    
    <!-- PWA & Mobile App Meta Tags -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= $sys_school_name ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#fbfbfd">
    <link rel="apple-touch-icon" href="<?= $base_url ?>/<?= $sys_school_logo ?>">
    <link rel="manifest" href="<?= $base_url ?>/manifest.json">
    
    <link rel="icon" type="image/png" href="<?= $base_url ?>/<?= $sys_school_logo ?>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" />
    <link rel="stylesheet" href="<?= $base_url ?>/assets/css/style.css?v=<?= time() ?>">
    <!-- ⬇️ Liquid Glass Theme (ลบบรรทัดนี้ออกถ้าต้องการกลับไปใช้สีเขียวเดิม) ⬇️ -->
    <link rel="stylesheet" href="<?= $base_url ?>/assets/css/liquid-glass.css?v=<?= time() ?>">
    <!-- ⬆️ Liquid Glass Theme ⬆️ -->
    <!-- Instant Scroll Restoration (Zero-Flicker) -->
    <script>
        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }
        (function() {
            try {
                var p = sessionStorage.getItem('restore_scroll_path');
                var s = sessionStorage.getItem('restore_scroll_pos');
                if (p && p === window.location.pathname && s) {
                    var y = parseInt(s, 10);
                    if (!isNaN(y) && y > 0) {
                        window.scrollTo(0, y);
                    }
                }
            } catch(e) {}
        })();
    </script>
</head>
<body class="<?= $theme_class ?>">
    <?php
    $popup_active = $site_settings['popup_active'] ?? '0';
    $popup_message = $site_settings['popup_message'] ?? '';
    $popup_link = $site_settings['popup_link'] ?? '';
    $popup_image_path = $site_settings['popup_image_path'] ?? '';
    
    // Show only once per session
    if ($popup_active === '1' && !empty($popup_message) && empty($_SESSION['popup_shown'])) {
        $_SESSION['popup_shown'] = true;
    ?>
        <div id="systemPopupOverlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); z-index: 9999; display: flex; justify-content: center; align-items: center; animation: fadeIn 0.4s ease; padding: 20px;">
            <div style="background: rgba(255, 255, 255, 0.1); border-radius: 36px; min-width: 320px; max-width: clamp(320px, 90vw, 800px); width: fit-content; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 30px 60px rgba(0,0,0,0.3), inset 0 1px 1px rgba(255,255,255,0.4), inset 0 -1px 1px rgba(255,255,255,0.1); position: relative; text-align: center; animation: glassPopIn 0.6s cubic-bezier(0.2, 0.8, 0.2, 1); border: 1px solid rgba(255, 255, 255, 0.3); backdrop-filter: blur(40px); -webkit-backdrop-filter: blur(40px); overflow-y: auto; overflow-x: hidden;">
                
                <button onclick="document.getElementById('systemPopupOverlay').style.display='none';" style="position: absolute; top: 16px; right: 16px; background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); font-size: 1.5rem; cursor: pointer; color: #fff; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; z-index: 10; transition: all 0.3s cubic-bezier(0.2, 0.8, 0.2, 1); box-shadow: 0 4px 10px rgba(0,0,0,0.1);" onmouseover="this.style.background='rgba(255,255,255,0.4)'; this.style.transform='scale(1.05)';" onmouseout="this.style.background='rgba(255,255,255,0.2)'; this.style.transform='scale(1)';">&times;</button>
                
                <?php if (!empty($popup_image_path)): ?>
                    <div style="padding: 24px 24px 0 24px; flex-shrink: 0;">
                        <img src="<?= $base_url ?>/<?= htmlspecialchars($popup_image_path) ?>" style="max-width: 100%; max-height: 55vh; width: auto; height: auto; object-fit: contain; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); display: block; margin: 0 auto; border: 1px solid rgba(255,255,255,0.2);">
                    </div>
                <?php else: ?>
                    <div style="padding-top: 40px; font-size: 4rem; flex-shrink: 0; filter: drop-shadow(0 10px 10px rgba(0,0,0,0.2));">✨</div>
                <?php endif; ?>
                
                <div style="padding: 28px 36px 36px; flex-shrink: 0;">
                    <p style="font-size: 1.15rem; font-weight: 500; color: #fff; line-height: 1.6; margin-bottom: 32px; white-space: pre-wrap; letter-spacing: 0.3px; text-shadow: 0 2px 4px rgba(0,0,0,0.3);"><?= htmlspecialchars($popup_message) ?></p>
                    
                    <?php if (!empty($popup_link)): ?>
                        <a href="<?= htmlspecialchars($popup_link) ?>" target="_blank" class="btn" style="display: block; width: 100%; background: linear-gradient(135deg, rgba(255,255,255,0.4) 0%, rgba(255,255,255,0.1) 100%); color: #fff; border: 1px solid rgba(255,255,255,0.5); padding: 16px 24px; border-radius: 50px; text-decoration: none; font-weight: 600; font-size: 1.1rem; box-shadow: 0 10px 20px rgba(0,0,0,0.15), inset 0 1px 1px rgba(255,255,255,0.6); transition: all 0.3s cubic-bezier(0.2, 0.8, 0.2, 1); text-shadow: 0 1px 2px rgba(0,0,0,0.2);" onmouseover="this.style.transform='translateY(-2px) scale(1.02)'; this.style.background='linear-gradient(135deg, rgba(255,255,255,0.5) 0%, rgba(255,255,255,0.2) 100%)'; this.style.boxShadow='0 15px 25px rgba(0,0,0,0.2), inset 0 1px 1px rgba(255,255,255,0.8)';" onmouseout="this.style.transform='translateY(0) scale(1)'; this.style.background='linear-gradient(135deg, rgba(255,255,255,0.4) 0%, rgba(255,255,255,0.1) 100%)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.15), inset 0 1px 1px rgba(255,255,255,0.6)';" onclick="document.getElementById('systemPopupOverlay').style.display='none';">ดูรายละเอียดเพิ่มเติม</a>
                    <?php else: ?>
                        <button class="btn" style="width: 100%; background: linear-gradient(135deg, rgba(255,255,255,0.4) 0%, rgba(255,255,255,0.1) 100%); color: #fff; border: 1px solid rgba(255,255,255,0.5); padding: 16px 24px; border-radius: 50px; cursor: pointer; font-weight: 600; font-size: 1.1rem; box-shadow: 0 10px 20px rgba(0,0,0,0.15), inset 0 1px 1px rgba(255,255,255,0.6); transition: all 0.3s cubic-bezier(0.2, 0.8, 0.2, 1); text-shadow: 0 1px 2px rgba(0,0,0,0.2);" onmouseover="this.style.transform='translateY(-2px) scale(1.02)'; this.style.background='linear-gradient(135deg, rgba(255,255,255,0.5) 0%, rgba(255,255,255,0.2) 100%)'; this.style.boxShadow='0 15px 25px rgba(0,0,0,0.2), inset 0 1px 1px rgba(255,255,255,0.8)';" onmouseout="this.style.transform='translateY(0) scale(1)'; this.style.background='linear-gradient(135deg, rgba(255,255,255,0.4) 0%, rgba(255,255,255,0.1) 100%)'; this.style.boxShadow='0 10px 20px rgba(0,0,0,0.15), inset 0 1px 1px rgba(255,255,255,0.6)';" onclick="document.getElementById('systemPopupOverlay').style.display='none';">รับทราบ</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <style>
            @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
            @keyframes glassPopIn { 0% { transform: scale(0.9) translateY(20px); opacity: 0; } 100% { transform: scale(1) translateY(0); opacity: 1; } }
        </style>
    <?php } ?>

    <div class="app-wrapper">
        
        <?php include __DIR__ . '/sidebar.php'; ?>
        
        <main class="main-content">
            <header class="topbar" style="height: auto; min-height: 56px; padding: 8px 16px;">
                <div class="topbar-left" style="gap: 10px; align-items: center;">
                    <button class="menu-toggle" id="menuToggle">☰</button>
                    <?php if ($topbar_teacher): ?>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <a href="<?= $base_url ?>/src/actions/teacher_home.php?action=edit_profile" title="เปลี่ยนรูปโปรไฟล์" style="display: flex; flex-shrink: 0; transition: opacity 0.2s;" onmouseover="this.style.opacity=0.8" onmouseout="this.style.opacity=1">
                            <img src="<?= htmlspecialchars($topbar_photo_url) ?>" 
                                 alt="Profile" 
                                 class="topbar-avatar"
                                 style="width: 38px; height: 38px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border-color); cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.05);"
                                 onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($topbar_teacher['full_name'] ?? 'User') ?>&size=80&background=94a3b8&color=fff'">
                        </a>
                        <a href="<?= $base_url ?>/src/actions/teacher_home.php" style="display: flex; flex-direction: column; min-width: 0; line-height: 1.25; text-decoration: none; color: inherit; transition: opacity 0.2s;" onmouseover="this.style.opacity=0.8" onmouseout="this.style.opacity=1" title="กลับหน้าหลัก">
                            <h2 class="page-title" style="font-size: clamp(0.9rem, 3vw, 1.1rem); margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer;">
                                <?= htmlspecialchars($topbar_teacher['full_name'] ?? 'ผู้ใช้งาน') ?>
                            </h2>
                            <span style="font-size: 0.7rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer;">
                                <?php 
                                $sub_parts = [];
                                if (!empty($topbar_teacher['advisor_classroom'])) $sub_parts[] = '🏫 ' . htmlspecialchars($topbar_teacher['advisor_classroom']);
                                if (!empty($topbar_teacher['department'])) $sub_parts[] = htmlspecialchars($topbar_teacher['department']);
                                echo !empty($sub_parts) ? implode(' · ', $sub_parts) : htmlspecialchars($current_role_display);
                                ?>
                            </span>
                        </a>
                    </div>
                    <?php else: ?>
                    <h2 class="page-title"><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Dashboard'; ?></h2>
                    <?php endif; ?>
                </div>
                <div class="topbar-right" style="display: flex; align-items: center; gap: 10px;">
                    <?php if (strpos($current_page, 'teacher_') !== 0): ?>
                        <a href="<?= $base_url ?>/src/actions/notifications.php" class="topbar-notif" style="position: relative; text-decoration: none; color: inherit; font-size: 1.2rem;" title="การแจ้งเตือน">
                            🔔
                            <?php if ($unread_notifications_count > 0): ?>
                                <span style="position: absolute; top: -5px; right: -8px; background: red; color: white; border-radius: 50%; padding: 2px 5px; font-size: 0.7rem; font-weight: bold; line-height: 1;"><?= $unread_notifications_count ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <span class="user-roles badge" style="background: var(--bg-panel); color: var(--primary-color); font-weight: 700; font-size: 0.72rem; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color); padding: 3px 8px; border-radius: 12px;"><?= htmlspecialchars($current_role_display) ?></span>
                    <?php if (in_array('teacher', $user_roles) || in_array('personnel', $user_roles) || in_array('admin', $user_roles)): ?>
                        <a href="<?= $base_url ?>/src/actions/teacher_attendance.php" class="btn btn-sm topbar-attendance" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border-radius: 20px; padding: 4px 10px; font-size: 0.78rem; font-weight: 700; text-decoration: none; box-shadow: 0 4px 10px rgba(16,185,129,0.25); display: flex; align-items: center; gap: 4px; border: none; white-space: nowrap;">
                            📍 ลงเวลา
                        </a>
                    <?php endif; ?>
                </div>
            </header>
            
            <div class="content-area">

<!-- Pull to Refresh Script -->
<script>
(function() {
    let startY = 0;
    let isPulling = false;
    let pullDistance = 0;
    const threshold = 70; // px
    
    window.addEventListener('DOMContentLoaded', () => {
        // Create the spinner container
        const ptrContainer = document.createElement('div');
        ptrContainer.innerHTML = '<div style="width: 40px; height: 40px; border-radius: 50%; background: white; box-shadow: 0 4px 10px rgba(0,0,0,0.15); display: flex; align-items: center; justify-content: center;"><div style="width: 22px; height: 22px; border: 3px solid #db2777; border-top-color: transparent; border-radius: 50%; animation: ptr-spin 0.8s linear infinite;"></div></div>';
        ptrContainer.style.cssText = 'position: fixed; top: -60px; left: 0; width: 100%; height: 60px; display: flex; justify-content: center; align-items: center; z-index: 99999; transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.25s ease; opacity: 0; pointer-events: none;';
        
        const style = document.createElement('style');
        style.innerHTML = '@keyframes ptr-spin { to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
        document.body.appendChild(ptrContainer);

        document.addEventListener('touchstart', (e) => {
            if (window.scrollY === 0) {
                startY = e.touches[0].clientY;
                isPulling = true;
                pullDistance = 0;
                ptrContainer.style.transition = 'none'; // Follow finger exactly
            }
        }, { passive: true });

        document.addEventListener('touchmove', (e) => {
            if (!isPulling) return;
            
            const y = e.touches[0].clientY;
            pullDistance = y - startY;

            if (pullDistance > 0 && window.scrollY === 0) {
                if (e.cancelable) e.preventDefault(); // Stop page scroll
                
                // Resistance factor (pull harder the further it goes)
                const visualDistance = Math.min(pullDistance * 0.45, threshold + 30);
                ptrContainer.style.opacity = Math.min(visualDistance / (threshold * 0.8), 1);
                ptrContainer.style.transform = `translateY(${visualDistance}px)`;
            } else if (pullDistance < 0) {
                // Scrolled up, cancel
                isPulling = false;
            }
        }, { passive: false });

        document.addEventListener('touchend', () => {
            if (!isPulling) return;
            isPulling = false;
            
            ptrContainer.style.transition = 'transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease';
            
            if (pullDistance * 0.45 > threshold) {
                // Refresh!
                ptrContainer.style.transform = `translateY(${threshold}px)`;
                setTimeout(() => {
                    window.location.reload();
                }, 200); // slight delay to show it locked in
            } else {
                // Snap back
                ptrContainer.style.transform = 'translateY(0)';
                ptrContainer.style.opacity = '0';
            }
        }, { passive: true });
    });
})();
</script>
