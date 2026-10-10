<?php
// includes/sidebar.php
$base_url = '';
if (strpos($_SERVER['REQUEST_URI'], '/TeeDiewJob') === 0) {
    $base_url = '/TeeDiewJob';
}
$user_roles = $_SESSION['roles'] ?? [];
?>
<?php
$side_school_name = !empty($site_settings['school_name']) ? htmlspecialchars($site_settings['school_name']) : 'TeeDiewJob';
$side_school_logo = !empty($site_settings['school_logo']) ? htmlspecialchars($site_settings['school_logo']) : 'assets/images/school_logo.png';
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div style="display:flex; align-items:center;">
            <img src="<?= $base_url ?>/<?= $side_school_logo ?>?v=<?= time() ?>" alt="Logo" style="width: 46px; height: 46px; object-fit: contain; margin-right: 12px;" onerror="this.src='https://ui-avatars.com/api/?name=TD&background=4CAF50&color=fff'">
            <div style="display: flex; flex-direction: column; line-height: 1.2;">
                <span style="font-size: 0.95rem; font-weight: 700; color: var(--primary-color); max-width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= $side_school_name ?>"><?= $side_school_name ?></span>
                <span style="font-size: 0.75rem; font-weight: 500; color: var(--secondary-color);">ระบบบริหาร (ที่เดียวจบ)</span>
            </div>
        </div>
        <button id="closeSidebar" class="menu-toggle" style="display:none;">✕</button>
    </div>
    
    <ul class="sidebar-menu">
<?php
        $unread_dm_count = 0;
        if (isset($pdo) && isset($_SESSION['user_id'])) {
            try {
                $stmt_dm = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0 AND is_deleted_by_receiver = 0");
                $stmt_dm->execute([$_SESSION['user_id']]);
                $unread_dm_count = $stmt_dm->fetchColumn();
            } catch (Exception $e) {}
        }
        ?>
        <li style="margin-bottom: 10px;">
            <a href="<?= $base_url ?>/src/actions/messages.php" style="display: flex; align-items: center; padding: 12px 20px; color: var(--text-sidebar); text-decoration: none; border-radius: 8px; margin: 0 10px; background: rgba(255,255,255,0.05); font-weight: 500; position: relative;">
                <span style="margin-right: 12px; font-size: 1.1rem;">💬</span>
                ข้อความส่วนตัว (DM)
                <?php if ($unread_dm_count > 0): ?>
                    <span style="margin-left: auto; background: #ef4444; color: white; border-radius: 12px; padding: 2px 8px; font-size: 0.75rem; font-weight: bold;"><?= $unread_dm_count ?></span>
                <?php endif; ?>
            </a>
        </li>
        
        <?php if (in_array('admin', $user_roles)): ?>
            <li class="menu-group">
                <div class="menu-header toggle-menu" data-target="menu-admin" style="padding: 10px 20px 5px; font-size: 0.8rem; color: var(--text-sidebar-muted); text-transform: uppercase; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <a href="<?= $base_url ?>/src/actions/admin_dashboard.php" style="color: inherit; text-decoration: none; flex-grow: 1;" onclick="event.stopPropagation();">Admin</a>
                    <span class="menu-arrow" style="padding: 5px;">▼</span>
                </div>
                <ul class="submenu" id="menu-admin">
                    <li><a href="<?= $base_url ?>/src/actions/admin_dashboard.php">📊 แดชบอร์ด (Admin)</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/admin_terms.php">📅 จัดการปีการศึกษา</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/admin_users.php">👨‍🏫 จัดการข้อมูลครู</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/admin_students.php">👨‍🎓 จัดการข้อมูลนักเรียน</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/admin_import_religion.php">🕌 นำเข้าข้อมูลศาสนา</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/admin_subjects.php">📚 จัดการรายวิชา</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/admin_assign_subjects.php">🎓 จัดการภาระงานสอน</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/admin_teaching_logs.php">📝 ปรับแก้ไขบันทึกการสอน</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/admin_web_management.php">🌐 การจัดการหน้าเว็บ</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/admin_settings.php">⚙️ ตั้งค่าระบบ</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/admin_clear_data.php" style="color: var(--danger-color);">⚠️ ล้างข้อมูล</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if (in_array('teacher', $user_roles)): ?>
            <li class="menu-group">
                <div class="menu-header toggle-menu" data-target="menu-teacher" style="padding: 10px 20px 5px; font-size: 0.8rem; color: var(--text-sidebar-muted); text-transform: uppercase; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <a href="<?= $base_url ?>/src/actions/teacher_home.php" style="color: inherit; text-decoration: none; flex-grow: 1;" onclick="event.stopPropagation();">ครู</a>
                    <span class="menu-arrow" style="padding: 5px;">▼</span>
                </div>
                <ul class="submenu" id="menu-teacher">
                    <li><a href="<?= $base_url ?>/src/actions/teacher_home.php">🏠 หน้าหลัก (ครู)</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/teacher_dashboard.php">📊 ข้อมูลเชิงลึก</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/teacher_homeroom.php">เช็คชื่อเข้าแถว</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/teacher_class.php">บันทึกการสอน</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/teacher_substitute.php">มอบหมายสอนแทน</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/teacher_report_homeroom.php">สรุปนักเรียนเข้าแถว</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/teacher_homeroom_log.php">แบบบันทึกประจำห้องที่ปรึกษา</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/teacher_report_subject.php">สรุปการเข้าเรียนรายวิชา</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/teacher_behavior.php">ตรวจระเบียบ / คะแนนพฤติกรรม</a></li>
                    <!-- <li><a href="<?= $base_url ?>/src/actions/academic_feed.php?view=teacher">ไทม์ไลน์ภาพการสอน</a></li> -->
                </ul>
            </li>
        <?php endif; ?>

                <?php if (in_array('assistant', $user_roles) && !in_array('teacher', $user_roles) && !in_array('admin', $user_roles)): ?>
            <li class="menu-group">
                <div class="menu-header toggle-menu" data-target="menu-assistant" style="padding: 10px 20px 5px; font-size: 0.8rem; color: var(--text-sidebar-muted); text-transform: uppercase; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <a href="<?= $base_url ?>/src/actions/teacher_home.php" style="color: inherit; text-decoration: none; flex-grow: 1;" onclick="event.stopPropagation();">ผู้ช่วยครู</a>
                    <span class="menu-arrow" style="padding: 5px;">▼</span>
                </div>
                <ul class="submenu" id="menu-assistant">
                    <li><a href="<?= $base_url ?>/src/actions/teacher_home.php">🏠 หน้าหลัก</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/teacher_homeroom.php">เช็คชื่อเข้าแถว</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if (in_array('executive', $user_roles) || in_array('admin', $user_roles)): ?>
            <li class="menu-group">
                <div class="menu-header toggle-menu" data-target="menu-executive" style="padding: 10px 20px 5px; font-size: 0.8rem; color: var(--text-sidebar-muted); text-transform: uppercase; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <a href="<?= $base_url ?>/src/actions/executive_dashboard.php" style="color: inherit; text-decoration: none; flex-grow: 1;" onclick="event.stopPropagation();">ผู้บริหาร</a>
                    <span class="menu-arrow" style="padding: 5px;">▼</span>
                </div>
                <ul class="submenu" id="menu-executive">
                    <li><a href="<?= $base_url ?>/src/actions/executive_dashboard.php">📊 แดชบอร์ด (ผู้บริหาร)</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if (in_array('academic', $user_roles) || in_array('admin', $user_roles)): ?>
            <li class="menu-group">
                <div class="menu-header toggle-menu" data-target="menu-academic" style="padding: 10px 20px 5px; font-size: 0.8rem; color: var(--text-sidebar-muted); text-transform: uppercase; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <a href="<?= $base_url ?>/src/actions/academic_dashboard.php" style="color: inherit; text-decoration: none; flex-grow: 1;" onclick="event.stopPropagation();">วิชาการ</a>
                    <span class="menu-arrow" style="padding: 5px;">▼</span>
                </div>
                <ul class="submenu" id="menu-academic">
                    <li><a href="<?= $base_url ?>/src/actions/academic_dashboard.php">📊 แดชบอร์ด (วิชาการ)</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/academic_feed.php">🖼️ ไทม์ไลน์ภาพการสอน</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/academic_report_daily.php">📓 แบบบันทึกประจำห้อง</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if (in_array('discipline', $user_roles)): ?>
            <li class="menu-group">
                <div class="menu-header toggle-menu" data-target="menu-discipline" style="padding: 10px 20px 5px; font-size: 0.8rem; color: var(--text-sidebar-muted); text-transform: uppercase; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <a href="<?= $base_url ?>/src/actions/discipline_dashboard.php" style="color: inherit; text-decoration: none; flex-grow: 1;" onclick="event.stopPropagation();">กิจการนักเรียน</a>
                    <span class="menu-arrow" style="padding: 5px;">▼</span>
                </div>
                <ul class="submenu" id="menu-discipline">
                    <li><a href="<?= $base_url ?>/src/actions/discipline_dashboard.php">🏠 แดชบอร์ดหลัก</a></li>
                    <?php if (in_array('level_head', $user_roles) || in_array('admin', $user_roles) || in_array('executive', $user_roles)): ?>
                    <li><a href="<?= $base_url ?>/src/actions/discipline_student_qrcode.php">🖨 พิมพ์บัตร QR Code นักเรียน</a></li>
                    <li><a href="<?= $base_url ?>/src/actions/teacher_upload_links.php">📸 สร้างลิงก์อัปโหลดรูปนักเรียน</a></li>
                    <?php endif; ?>
                    <?php if (!empty($site_settings['enable_friday_prayer']) && $site_settings['enable_friday_prayer'] == '1'): ?>
                    <li><a href="<?= $base_url ?>/src/actions/discipline_friday_prayer.php">🕌 สแกนละหมาดวันศุกร์</a></li>
                    <?php endif; ?>
                    <li><a href="<?= $base_url ?>/src/actions/report_homeroom_monthly.php">📄 รายงานการเข้าแถวรายเดือน</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if (in_array('personnel', $user_roles) || in_array('admin', $user_roles)): ?>
            <li class="menu-group">
                <div class="menu-header toggle-menu" data-target="menu-personnel" style="padding: 10px 20px 5px; font-size: 0.8rem; color: var(--text-sidebar-muted); text-transform: uppercase; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <a href="<?= $base_url ?>/src/actions/personnel_attendance_report.php" style="color: inherit; text-decoration: none; flex-grow: 1;" onclick="event.stopPropagation();">กลุ่มงานบุคลากร</a>
                    <span class="menu-arrow" style="padding: 5px;">▼</span>
                </div>
                <ul class="submenu" id="menu-personnel">
                    <li><a href="<?= $base_url ?>/src/actions/personnel_attendance_report.php">📋 รายงานลงเวลาปฏิบัติงาน</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if (!empty($site_settings['enable_friday_prayer']) && $site_settings['enable_friday_prayer'] == '1' && in_array('friday_prayer_duty', $user_roles)): ?>
            <li class="menu-group">
                <div class="menu-header toggle-menu" data-target="menu-friday" style="padding: 10px 20px 5px; font-size: 0.8rem; color: var(--text-sidebar-muted); text-transform: uppercase; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <a href="<?= $base_url ?>/src/actions/discipline_friday_prayer.php" style="color: inherit; text-decoration: none; flex-grow: 1;" onclick="event.stopPropagation();">ครูเวรละหมาดชายวันศุกร์</a>
                    <span class="menu-arrow" style="padding: 5px;">▼</span>
                </div>
                <ul class="submenu" id="menu-friday">
                    <li><a href="<?= $base_url ?>/src/actions/discipline_friday_prayer.php">🕌 สแกนเข้า-ออกละหมาดวันศุกร์</a></li>
                </ul>
            </li>
        <?php endif; ?>

        <?php if (in_array('inspector', $user_roles)): ?>
            <li class="menu-group">
                <div class="menu-header toggle-menu" data-target="menu-inspector" style="padding: 10px 20px 5px; font-size: 0.8rem; color: var(--text-sidebar-muted); text-transform: uppercase; font-weight: 600; cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                    <a href="<?= $base_url ?>/src/actions/inspector_late_check.php" style="color: inherit; text-decoration: none; flex-grow: 1;" onclick="event.stopPropagation();">สารวัตรนักเรียน</a>
                    <span class="menu-arrow" style="padding: 5px;">▼</span>
                </div>
                <ul class="submenu" id="menu-inspector">
                    <li><a href="<?= $base_url ?>/src/actions/inspector_late_check.php">👮 เช็คมาสาย (คาบ 0)</a></li>
                </ul>
            </li>
        <?php endif; ?>
        
        <li style="margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 10px;">
            <a href="<?= $base_url ?>/logout.php" style="color: var(--danger-color);">🚪 ออกจากระบบ</a>
        </li>
    </ul>
</aside>

<style>
.submenu {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 0;
    overflow: hidden;
}
.sidebar-ready .submenu {
    transition: max-height 0.3s ease-out;
}
.submenu.open {
    max-height: 800px;
}

/* 🎀 Main Menu Design */
.toggle-menu {
    transition: all 0.25s ease;
    user-select: none;
    background: transparent;
    border-radius: 10px;
    margin: 1px 12px;
    padding: 10px 16px !important;
    border: 1px solid transparent;
}
.toggle-menu:hover, .toggle-menu.active-main {
    background: var(--bg-hover);
    border-color: var(--border-color);
    color: var(--primary-color) !important;
}
.toggle-menu:hover a, .toggle-menu.active-main a {
    color: var(--primary-color) !important;
    font-weight: 700;
}
.toggle-menu:hover .menu-arrow, .toggle-menu.active-main .menu-arrow {
    color: var(--primary-color) !important;
}

/* 🌸 Submenu Items Design */
.submenu li a {
    display: flex;
    align-items: center;
    padding: 10px 16px 10px 42px !important;
    color: #64748b !important;
    font-size: 0.85rem;
    position: relative;
    transition: all 0.2s ease;
    text-decoration: none;
    margin: 2px 12px;
    border-radius: 8px;
}
.submenu li a::before {
    content: '';
    position: absolute;
    left: 22px;
    top: 50%;
    transform: translateY(-50%);
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background-color: var(--border-color);
    transition: all 0.2s ease;
}
.submenu li a:hover, .submenu li a.active-sub {
    color: var(--primary-color) !important;
    background-color: var(--bg-hover) !important;
    font-weight: 600;
}
.submenu li a:hover::before, .submenu li a.active-sub::before {
    background-color: var(--primary-color);
    transform: translateY(-50%) scale(1.5);
    box-shadow: 0 0 4px var(--primary-color);
}

.menu-arrow {
    transition: transform 0.3s ease;
    font-size: 0.65rem;
    opacity: 0.7;
}
.menu-arrow.open {
    transform: rotate(180deg);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggles = document.querySelectorAll('.toggle-menu');
    const currentUrl = window.location.href;
    
    // First, determine which single menu should be open
    let activeTargetId = null;
    
    // 1. Check if any menu has an active link
    toggles.forEach(toggle => {
        const targetId = toggle.getAttribute('data-target');
        const submenu = document.getElementById(targetId);
        if (submenu) {
            const links = submenu.querySelectorAll('a');
            links.forEach(link => {
                if (currentUrl.split('#')[0] === link.href.split('#')[0]) {
                    activeTargetId = targetId;
                    link.classList.add('active-sub'); // highlight submenu item
                }
            });
        }
    });

    // 2. If no active link found, check localStorage for the first one that is open
    if (!activeTargetId) {
        toggles.forEach(toggle => {
            const targetId = toggle.getAttribute('data-target');
            if (!activeTargetId && localStorage.getItem('sidebar_' + targetId) === 'true') {
                activeTargetId = targetId;
            }
        });
    }

    // Now apply the states (only one open, others closed)
    toggles.forEach(toggle => {
        const targetId = toggle.getAttribute('data-target');
        const submenu = document.getElementById(targetId);
        const arrow = toggle.querySelector('.menu-arrow');
        
        if (!submenu || !arrow) return;

        if (targetId === activeTargetId) {
            submenu.classList.add('open');
            arrow.classList.add('open');
            toggle.classList.add('active-main');
            localStorage.setItem('sidebar_' + targetId, 'true');
        } else {
            submenu.classList.remove('open');
            arrow.classList.remove('open');
            toggle.classList.remove('active-main');
            localStorage.setItem('sidebar_' + targetId, 'false');
        }
        
        // Click event listener
        toggle.addEventListener('click', function() {
            const isCurrentlyOpen = submenu.classList.contains('open');
            
            // Close all other submenus first (Accordion effect)
            if (!isCurrentlyOpen) {
                toggles.forEach(otherToggle => {
                    const otherTargetId = otherToggle.getAttribute('data-target');
                    if (otherTargetId !== targetId) {
                        const otherSubmenu = document.getElementById(otherTargetId);
                        const otherArrow = otherToggle.querySelector('.menu-arrow');
                        if (otherSubmenu && otherSubmenu.classList.contains('open')) {
                            otherSubmenu.classList.remove('open');
                            if (otherArrow) otherArrow.classList.remove('open');
                            otherToggle.classList.remove('active-main');
                            localStorage.setItem('sidebar_' + otherTargetId, 'false');
                        }
                    }
                });
            }
            
            if (isCurrentlyOpen) {
                submenu.classList.remove('open');
                arrow.classList.remove('open');
                toggle.classList.remove('active-main');
                localStorage.setItem('sidebar_' + targetId, 'false');
            } else {
                submenu.classList.add('open');
                arrow.classList.add('open');
                toggle.classList.add('active-main');
                localStorage.setItem('sidebar_' + targetId, 'true');
            }
        });
    });
    
    // Enable transitions after initial layout to prevent animation on page load
    setTimeout(() => {
        document.body.classList.add('sidebar-ready');
    }, 50);
});
</script>
