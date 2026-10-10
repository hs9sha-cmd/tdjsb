<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';

requireRole($pdo, 'admin');

$page_title = "แดชบอร์ดผู้ดูแลระบบ";
include '../../includes/header.php';

// Quick stats
$stats = [
    'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'students' => $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn(),
    'classrooms' => $pdo->query("SELECT COUNT(*) FROM classrooms")->fetchColumn()
];

// Active term
$active_term = $pdo->query("SELECT term_number, year_name FROM academic_terms WHERE is_active = 1")->fetch();
$term_display = $active_term ? "ภาคเรียนที่ " . $active_term['term_number'] . " ปีการศึกษา " . $active_term['year_name'] : "ยังไม่ได้กำหนดปีการศึกษา";
?>

<style>
    .admin-dashboard-container {
        display: flex;
        flex-direction: column;
        gap: 30px;
        padding-bottom: 30px;
    }
    
    .apple-card {
        background: rgba(30, 30, 32, 0.6);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 20px;
        padding: 28px;
        color: #f5f5f7;
        box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
    }
    
    .apple-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        background: rgba(40, 40, 42, 0.7);
        border: 1px solid rgba(255, 255, 255, 0.12);
    }

    .stat-card {
        text-align: center;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 12px;
    }
    
    .stat-card h3 {
        font-size: 1.05rem;
        font-weight: 500;
        color: #a1a1a6;
        margin: 0;
        letter-spacing: 0.5px;
    }
    
    .stat-card p {
        font-size: 3.5rem;
        font-weight: 700;
        margin: 0;
        line-height: 1;
        background: linear-gradient(135deg, #fff 0%, #a1a1a6 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    .menu-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 16px;
    }

    .menu-item {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px;
        text-decoration: none;
        color: #f5f5f7;
        background: rgba(255, 255, 255, 0.04);
        border-radius: 16px;
        border: 1px solid rgba(255, 255, 255, 0.05);
        transition: all 0.3s ease;
    }

    .menu-item:hover {
        background: rgba(255, 255, 255, 0.08);
        transform: translateY(-2px) scale(1.01);
        border: 1px solid rgba(255, 255, 255, 0.15);
    }

    .menu-icon {
        font-size: 1.6rem;
        width: 48px;
        height: 48px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(255, 255, 255, 0.06);
        border-radius: 12px;
        transition: all 0.3s ease;
        box-shadow: inset 0 1px 1px rgba(255,255,255,0.1);
    }
    
    .menu-item:hover .menu-icon {
        transform: scale(1.08);
        background: rgba(255, 255, 255, 0.12);
    }

    .menu-text {
        font-size: 1rem;
        font-weight: 500;
        letter-spacing: 0.3px;
    }

    .menu-danger {
        color: #ff453a;
    }
    .menu-item:hover.menu-danger {
        background: rgba(255, 69, 58, 0.1);
        border-color: rgba(255, 69, 58, 0.3);
    }
    .menu-item:hover.menu-danger .menu-icon {
        background: rgba(255, 69, 58, 0.2);
    }
    
    .page-header-apple {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
        padding: 10px 0;
    }
    
    .page-title-apple {
        font-size: 2rem;
        font-weight: 600;
        margin: 0;
        letter-spacing: -0.5px;
        color: #f5f5f7;
    }
    
    .term-badge-apple {
        background: rgba(10, 132, 255, 0.15);
        border: 1px solid rgba(10, 132, 255, 0.3);
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.95rem;
        font-weight: 500;
        color: #5e5ce6;
        display: flex;
        align-items: center;
        gap: 8px;
        backdrop-filter: blur(10px);
    }
</style>

<div class="admin-dashboard-container">
    <div class="page-header-apple">
        <h2 class="page-title-apple">ภาพรวมระบบ (Admin)</h2>
        <div class="term-badge-apple">
            <span>📅</span> <?= htmlspecialchars($term_display) ?>
        </div>
    </div>

    <!-- Stats Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px;">
        <div class="apple-card stat-card">
            <h3>บุคลากรทั้งหมด</h3>
            <p><?= $stats['users'] ?></p>
        </div>
        <div class="apple-card stat-card">
            <h3>นักเรียนทั้งหมด</h3>
            <p><?= $stats['students'] ?></p>
        </div>
        <div class="apple-card stat-card">
            <h3>ห้องเรียนทั้งหมด</h3>
            <p><?= $stats['classrooms'] ?></p>
        </div>
    </div>

    <!-- Admin Menu Cards Grid -->
    <div class="apple-card" style="margin-top: 10px;">
        <h3 style="font-size: 1.3rem; font-weight: 600; margin-bottom: 24px; color: #f5f5f7; letter-spacing: -0.3px;">เมนูการจัดการ</h3>
        <div class="menu-grid">
            <a href="admin_terms.php" class="menu-item">
                <div class="menu-icon">📅</div>
                <div class="menu-text">จัดการปีการศึกษา</div>
            </a>
            <a href="admin_users.php" class="menu-item">
                <div class="menu-icon">👨‍🏫</div>
                <div class="menu-text">จัดการข้อมูลครู</div>
            </a>
            <a href="admin_students.php" class="menu-item">
                <div class="menu-icon">👨‍🎓</div>
                <div class="menu-text">จัดการข้อมูลนักเรียน</div>
            </a>
            <a href="admin_import_religion.php" class="menu-item">
                <div class="menu-icon">🕌</div>
                <div class="menu-text">นำเข้าข้อมูลศาสนา</div>
            </a>
            <!-- ซ่อนตามความต้องการ: จัดการรายวิชา, จัดการภาระงานสอน, ปรับแก้ไขบันทึกการสอน
            <a href="admin_subjects.php" class="menu-item">
                <div class="menu-icon">📚</div>
                <div class="menu-text">จัดการรายวิชา</div>
            </a>
            <a href="admin_assign_subjects.php" class="menu-item">
                <div class="menu-icon">🎓</div>
                <div class="menu-text">จัดการภาระงานสอน</div>
            </a>
            <a href="admin_teaching_logs.php" class="menu-item">
                <div class="menu-icon">📝</div>
                <div class="menu-text">ปรับแก้ไขบันทึกการสอน</div>
            </a>
            -->
            <a href="admin_web_management.php" class="menu-item">
                <div class="menu-icon">🌐</div>
                <div class="menu-text">การจัดการหน้าเว็บ</div>
            </a>
            <a href="admin_settings.php" class="menu-item">
                <div class="menu-icon">⚙️</div>
                <div class="menu-text">ตั้งค่าระบบ</div>
            </a>
            <a href="admin_clear_data.php" class="menu-item menu-danger">
                <div class="menu-icon">⚠️</div>
                <div class="menu-text">ล้างข้อมูล</div>
            </a>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
