<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "ระบบเช็คมาสาย (กิจการนักเรียน)";
include '../../includes/header.php';

date_default_timezone_set('Asia/Bangkok');
$current_hour = (int)date('H');
$current_minute = (int)date('i');
$current_time_str = date('H:i');
$today = date('Y-m-d');
$today_thai = date('d/m/Y');

// Get active term
$stmt_term = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
$term_id = $stmt_term->fetchColumn();

// Today's late stats
$late_today_count = 0;
$late_normal_count = 0;
$late_extreme_count = 0;
$total_points_deducted = 0;

if ($term_id) {
    // Count late students today
    $stmt_late = $pdo->prepare("SELECT COUNT(*) FROM behavior_records WHERE record_date = ? AND (reason LIKE 'มาสาย%' OR reason LIKE '%สาย%') AND status = 'approved'");
    $stmt_late->execute([$today]);
    $late_today_count = (int)$stmt_late->fetchColumn();

    // Count by type
    $stmt_normal = $pdo->prepare("SELECT COUNT(*) FROM behavior_records WHERE record_date = ? AND (reason LIKE 'มาสาย (07.50%' OR reason = 'สาย') AND status = 'approved'");
    $stmt_normal->execute([$today]);
    $late_normal_count = (int)$stmt_normal->fetchColumn();

    $stmt_extreme = $pdo->prepare("SELECT COUNT(*) FROM behavior_records WHERE record_date = ? AND (reason LIKE 'มาสาย (หลัง%' OR reason = 'สายมาก') AND status = 'approved'");
    $stmt_extreme->execute([$today]);
    $late_extreme_count = (int)$stmt_extreme->fetchColumn();

    // Total points deducted today
    $stmt_pts = $pdo->prepare("SELECT COALESCE(SUM(ABS(points)), 0) FROM behavior_records WHERE record_date = ? AND (reason LIKE 'มาสาย%' OR reason LIKE '%สาย%') AND status = 'approved'");
    $stmt_pts->execute([$today]);
    $total_points_deducted = (int)$stmt_pts->fetchColumn();

    // This month's late count
    $month = date('m');
    $year = date('Y');
    $stmt_month = $pdo->prepare("SELECT COUNT(*) FROM behavior_records WHERE MONTH(record_date) = ? AND YEAR(record_date) = ? AND (reason LIKE 'มาสาย%' OR reason LIKE '%สาย%') AND status = 'approved'");
    $stmt_month->execute([$month, $year]);
    $late_this_month = (int)$stmt_month->fetchColumn();

    // Recent late records (last 10)
    $stmt_recent = $pdo->prepare("
        SELECT br.record_date, s.student_code, s.full_name, c.room_name, br.points, br.reason, br.created_at
        FROM behavior_records br
        JOIN students s ON br.student_id = s.id
        LEFT JOIN classrooms c ON s.classroom_id = c.id
        WHERE (br.reason LIKE 'มาสาย%' OR br.reason LIKE '%สาย%') AND br.status = 'approved'
        ORDER BY br.created_at DESC
        LIMIT 10
    ");
    $stmt_recent->execute();
    $recent_records = $stmt_recent->fetchAll();
}

// Determine deduction level display
if ($current_time_str >= '08:24') {
    $current_deduction = 10;
    $deduction_label = 'สายมาก (หลัง 08:24 น.)';
    $deduction_color = '#dc2626';
} else {
    $current_deduction = 5;
    $deduction_label = 'สายปกติ (07.50-08.24 น.)';
    $deduction_color = '#f59e0b';
}
?>

<style>
    .late-hero {
        background: linear-gradient(135deg, #fce7f3 0%, #fbcfe8 50%, #f9a8d4 100%);
        border-radius: 16px;
        padding: 35px;
        margin-bottom: 25px;
        text-align: center;
        border: 1px solid #f9a8d4;
        position: relative;
        overflow: hidden;
    }
    .late-hero::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 100%;
        height: 100%;
        background: radial-gradient(circle, rgba(255,255,255,0.3) 0%, transparent 70%);
        pointer-events: none;
    }
    .late-hero h1 {
        font-size: 1.8rem;
        color: #831843;
        margin-bottom: 8px;
        position: relative;
    }
    .late-hero .subtitle {
        color: #9d174d;
        font-size: 1rem;
        margin-bottom: 20px;
        position: relative;
    }
    .late-hero .time-badge {
        display: inline-block;
        background: white;
        color: #831843;
        padding: 8px 20px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 1.1rem;
        box-shadow: 0 2px 10px rgba(131, 24, 67, 0.15);
        position: relative;
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        text-align: center;
        border: 1px solid #fce7f3;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(131, 24, 67, 0.1);
    }
    .stat-card .stat-icon {
        font-size: 1.8rem;
        margin-bottom: 8px;
    }
    .stat-card .stat-value {
        font-size: 2rem;
        font-weight: 800;
        color: #831843;
        line-height: 1;
    }
    .stat-card .stat-label {
        font-size: 0.8rem;
        color: #9d174d;
        margin-top: 5px;
    }

    .action-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 25px;
    }
    .action-card {
        background: white;
        border-radius: 14px;
        padding: 30px;
        text-align: center;
        text-decoration: none;
        color: inherit;
        border: 2px solid #fce7f3;
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        overflow: hidden;
    }
    .action-card:hover {
        border-color: #f9a8d4;
        transform: translateY(-4px);
        box-shadow: 0 10px 30px rgba(131, 24, 67, 0.12);
    }
    .action-card .action-icon {
        font-size: 2.5rem;
        margin-bottom: 15px;
    }
    .action-card h3 {
        margin: 0 0 8px;
        color: #831843;
        font-size: 1.2rem;
    }
    .action-card p {
        margin: 0;
        font-size: 0.85rem;
        color: #9d174d;
    }
    .action-card .action-badge {
        position: absolute;
        top: 12px;
        right: 12px;
        background: linear-gradient(135deg, #ec4899, #be185d);
        color: white;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 0.7rem;
        font-weight: 700;
    }

    .deduction-indicator {
        background: white;
        border-radius: 12px;
        padding: 18px 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 15px;
        margin-bottom: 25px;
        border: 2px solid <?= $deduction_color ?>;
    }
    .deduction-indicator .pulse-dot {
        width: 12px;
        height: 12px;
        background: <?= $deduction_color ?>;
        border-radius: 50%;
        animation: pulse 1.5s infinite;
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.5; transform: scale(1.3); }
    }

    .recent-table {
        width: 100%;
        border-collapse: collapse;
    }
    .recent-table th {
        background: #fdf2f8;
        color: #831843;
        padding: 12px 10px;
        text-align: left;
        font-size: 0.8rem;
        text-transform: uppercase;
        font-weight: 700;
        border-bottom: 2px solid #fbcfe8;
    }
    .recent-table td {
        padding: 10px;
        border-bottom: 1px solid #fce7f3;
        font-size: 0.85rem;
    }
    .recent-table tr:hover td {
        background: #fdf2f8;
    }
    .points-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 8px;
        font-weight: 700;
        font-size: 0.8rem;
    }
    .points-badge.severe { background: #fef2f2; color: #dc2626; }
    .points-badge.normal { background: #fffbeb; color: #f59e0b; }
</style>

<!-- Hero Section -->
<div class="late-hero">
    <h1>🌸 ระบบเช็คมาสาย (คาบ 0)</h1>
    <p class="subtitle">ฝ่ายกิจการนักเรียน • วันที่ <?= $today_thai ?></p>
    <div class="time-badge">🕐 เวลาปัจจุบัน <?= $current_time_str ?> น.</div>
</div>

<!-- Current Deduction Level Indicator -->
<div class="deduction-indicator">
    <div class="pulse-dot"></div>
    <span style="font-weight: 700; color: <?= $deduction_color ?>;">
        ⚡ ระดับหักคะแนนปัจจุบัน: <?= $deduction_label ?> → หัก <?= $current_deduction ?> คะแนน
    </span>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">🕐</div>
        <div class="stat-value"><?= $late_today_count ?></div>
        <div class="stat-label">มาสายวันนี้ (คน)</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">⚠️</div>
        <div class="stat-value"><?= $late_normal_count ?></div>
        <div class="stat-label">สายปกติ (-5)</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🚨</div>
        <div class="stat-value"><?= $late_extreme_count ?></div>
        <div class="stat-label">สายมาก (-10)</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📉</div>
        <div class="stat-value"><?= $total_points_deducted ?></div>
        <div class="stat-label">คะแนนที่หักวันนี้</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📅</div>
        <div class="stat-value"><?= $late_this_month ?? 0 ?></div>
        <div class="stat-label">มาสายเดือนนี้ (ครั้ง)</div>
    </div>
</div>

<!-- Action Cards -->
<div class="action-grid">
    <a href="discipline_late_check.php" class="action-card">
        <div class="action-badge">หลัก</div>
        <div class="action-icon">⏰</div>
        <h3>บันทึกนักเรียนมาสาย</h3>
        <p>เลือกชื่อนักเรียนเพื่อบันทึกมาสายและหักคะแนนอัตโนมัติ</p>
    </a>
    <a href="discipline_dashboard.php" class="action-card">
        <div class="action-icon">🛡️</div>
        <h3>จัดการคะแนนความประพฤติ</h3>
        <p>ดูภาพรวมคะแนนความประพฤตินักเรียนทั้งหมด</p>
    </a>
    <a href="report_homeroom_monthly.php" class="action-card">
        <div class="action-icon">📄</div>
        <h3>รายงานเข้าแถวรายเดือน</h3>
        <p>ดูสถิติการเข้าแถวและมาสายของนักเรียน</p>
    </a>
</div>

<!-- Recent Late Records -->
<?php if (!empty($recent_records)): ?>
<div class="card" style="border: 1px solid #fce7f3;">
    <h3 style="margin-top: 0; color: #831843;">📋 บันทึกมาสายล่าสุด</h3>
    <div style="overflow-x: auto;">
        <table class="recent-table">
            <thead>
                <tr>
                    <th>วันที่</th>
                    <th>รหัส</th>
                    <th>ชื่อ-สกุล</th>
                    <th>ห้อง</th>
                    <th>คะแนน</th>
                    <th>ประเภท</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_records as $rec): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($rec['record_date'])) ?></td>
                    <td><?= htmlspecialchars($rec['student_code']) ?></td>
                    <td><?= htmlspecialchars($rec['full_name']) ?></td>
                    <td><?= htmlspecialchars($rec['room_name'] ?? '-') ?></td>
                    <td>
                        <span class="points-badge <?= $rec['points'] <= -10 ? 'severe' : 'normal' ?>">
                            <?= $rec['points'] ?> แต้ม
                        </span>
                    </td>
                    <td style="font-size: 0.8rem; color: #6b7280;"><?= htmlspecialchars($rec['reason']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
