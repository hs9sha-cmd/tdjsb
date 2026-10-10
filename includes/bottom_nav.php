<?php
// includes/bottom_nav.php
$base_url = '';
if (strpos($_SERVER['REQUEST_URI'], '/TDJSB') === 0) {
    $base_url = '/TDJSB';
} elseif (strpos($_SERVER['REQUEST_URI'], '/tdjsb') === 0) {
    $base_url = '/tdjsb';
} elseif (strpos($_SERVER['REQUEST_URI'], '/TeeDiewJob') === 0) {
    $base_url = '/TeeDiewJob';
}

$user_roles = $_SESSION['roles'] ?? [];
$current_page = basename($_SERVER['PHP_SELF']);

// Determine Home Link
$home_link = $base_url . '/src/actions/teacher_home.php';
if (in_array('teacher', $user_roles)) {
    $home_link = $base_url . '/src/actions/teacher_home.php';
} elseif (in_array('admin', $user_roles)) {
    $home_link = $base_url . '/src/actions/admin_dashboard.php';
} elseif (in_array('academic', $user_roles) || in_array('executive', $user_roles)) {
    $home_link = $base_url . '/src/actions/academic_dashboard.php';
} elseif (in_array('discipline', $user_roles)) {
    $home_link = $base_url . '/src/actions/discipline_dashboard.php';
} elseif (in_array('assistant', $user_roles)) {
    $home_link = $base_url . '/src/actions/teacher_home.php';
} elseif (in_array('inspector', $user_roles)) {
    $home_link = $base_url . '/src/actions/inspector_late_check.php';
}

// Determine Report Link
$report_link = '#';
if (in_array('teacher', $user_roles)) {
    $report_link = $base_url . '/src/actions/teacher_report_home.php';
}
if (in_array('academic', $user_roles)) {
    $report_link = $base_url . '/src/actions/academic_report_daily.php';
}
if (in_array('discipline', $user_roles)) {
    $report_link = $base_url . '/src/actions/report_homeroom_monthly.php';
}
if (in_array('admin', $user_roles)) {
    $report_link = $base_url . '/src/actions/teacher_report_home.php';
}

// Unread Notifications Count (Combined: DM/Alerts + Today's Late Students + At-Risk Absent Alerts)
$bottom_unread = 0;
if (isset($pdo) && isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    try {
        // 1. Fetch User's advisor_classroom once to avoid repeated subqueries
        $stmt_adv = $pdo->prepare("SELECT advisor_classroom FROM users WHERE id = ?");
        $stmt_adv->execute([$uid]);
        $advisor_classroom = $stmt_adv->fetchColumn() ?: '';

        // 2. Unread DM + System Alerts
        $stmt_alerts = $pdo->prepare("
            SELECT COUNT(DISTINCT n.id) FROM notifications n
            LEFT JOIN students s ON n.reference_type = 'late_alert' AND n.reference_id = s.id
            LEFT JOIN classrooms c ON s.classroom_id = c.id
            WHERE n.user_id = ? AND n.is_read = 0 AND n.reference_type != 'absent_alert'
            AND (
                n.reference_type != 'late_alert' 
                OR c.room_name = ?
            )
        ");
        $stmt_alerts->execute([$uid, $advisor_classroom]);
        $c_alerts = (int)$stmt_alerts->fetchColumn();

        // 3. Daily Late count (for advisor classroom today, unseen only)
        $c_late = 0;
        if ($advisor_classroom) {
            $stmt_late = $pdo->prepare("
                SELECT COUNT(*) FROM homeroom_attendance ha
                JOIN students s ON ha.student_id = s.id
                JOIN classrooms c ON s.classroom_id = c.id
                WHERE ha.attendance_date = CURDATE() AND ha.status = 'late'
                AND c.room_name = ?
            ");
            $stmt_late->execute([$advisor_classroom]);
            $total_late_today = (int)$stmt_late->fetchColumn();

            $seen_data = $_SESSION['seen_late_' . $uid] ?? ($_COOKIE['seen_late_' . $uid] ?? '');
            $c_late = $total_late_today;
            if (!empty($seen_data)) {
                $parts = explode('_', $seen_data);
                if (($parts[0] ?? '') === date('Y-m-d')) {
                    $c_late = max(0, $total_late_today - (int)($parts[1] ?? 0));
                }
            }
        }

        // 4. Absent / At-risk alerts (for advisor classroom)
        $c_absent = 0;
        if ($advisor_classroom) {
            $stmt_absent = $pdo->prepare("
                SELECT COUNT(*) FROM notifications n
                JOIN students s ON n.reference_id LIKE CONCAT('absent_', s.id, '\_%')
                JOIN classrooms c ON s.classroom_id = c.id
                WHERE n.user_id = ? AND n.is_read = 0 AND n.reference_type = 'absent_alert'
                AND c.room_name = ?
            ");
            $stmt_absent->execute([$uid, $advisor_classroom]);
            $c_absent = (int)$stmt_absent->fetchColumn();
        }

        $bottom_unread = $c_alerts + $c_late + $c_absent;
    } catch (Exception $e) {
        try {
            $stmt_notif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt_notif->execute([$uid]);
            $bottom_unread = (int)$stmt_notif->fetchColumn();
        } catch (Exception $e2) {}
    }
}

// Check active state
$is_home_active = (strpos($current_page, 'dashboard') !== false || $current_page === 'inspector_late_check.php' || $current_page === 'teacher_home.php') ? 'active' : '';
$is_notif_active = ($current_page === 'notifications.php' || strpos($current_page, 'messages') !== false || strpos($current_page, 'message_') !== false || strpos($current_page, 'teacher_notif') !== false) ? 'active' : '';
$is_report_active = (strpos($current_page, 'report') !== false || strpos($current_page, 'log') !== false) ? 'active' : '';

?>
<nav class="bottom-nav no-print">
    <a href="<?= htmlspecialchars($home_link) ?>" class="bottom-nav-item <?= $is_home_active ?>">
        <span class="material-symbols-rounded">home</span>
        <span class="label">หน้าหลัก</span>
    </a>
    
    <a href="<?= $base_url ?>/src/actions/messages.php" class="bottom-nav-item <?= $is_notif_active ?>">
        <div class="icon-wrapper">
            <span class="material-symbols-rounded">notifications</span>
            <?php if ($bottom_unread > 0): ?>
                <span class="badge-count"><?= $bottom_unread > 99 ? '99+' : $bottom_unread ?></span>
            <?php endif; ?>
        </div>
        <span class="label">แจ้งเตือน</span>
    </a>

    <a href="<?= htmlspecialchars($report_link) ?>" class="bottom-nav-item <?= $is_report_active ?>">
        <span class="material-symbols-rounded">assessment</span>
        <span class="label">รายงาน</span>
    </a>

    <?php 
    $is_timeline_active = (strpos($current_page, 'academic_feed.php') !== false) ? 'active' : '';
    if (in_array('teacher', $user_roles) || in_array('admin', $user_roles)): 
    ?>
    <a href="<?= $base_url ?>/src/actions/academic_feed.php?view=teacher" class="bottom-nav-item <?= $is_timeline_active ?>">
        <span class="material-symbols-rounded">view_timeline</span>
        <span class="label">Timeline</span>
    </a>
    <?php else: ?>
    <a href="#" class="bottom-nav-item" id="bottomNavMenuToggle">
        <span class="material-symbols-rounded">menu</span>
        <span class="label">อื่นๆ</span>
    </a>
    <?php endif; ?>

    <?php 
    $is_admin_active = ($current_page === 'admin_dashboard.php') ? 'active' : '';
    if (in_array('admin', $user_roles)): 
    ?>
    <a href="<?= $base_url ?>/src/actions/admin_dashboard.php" class="bottom-nav-item <?= $is_admin_active ?>">
        <span class="material-symbols-rounded">admin_panel_settings</span>
        <span class="label">ADMIN</span>
    </a>
    <?php endif; ?>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bottomNavMenuToggle = document.getElementById('bottomNavMenuToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (bottomNavMenuToggle && sidebar) {
        bottomNavMenuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            sidebar.classList.toggle('open');
            // Provide haptic feedback if available
            if (navigator.vibrate) {
                navigator.vibrate(50);
            }
        });
    }
});
</script>
