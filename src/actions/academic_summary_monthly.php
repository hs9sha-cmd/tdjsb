<?php
session_start();
require_once '../../config/database.php';

// Check roles
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('academic', $user_roles) && !in_array('executive', $user_roles) && !in_array('admin', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "รายงานสรุปประจำเดือน";
include '../../includes/header.php';

// Filter
$filter_month = $_GET['month'] ?? date('Y-m'); // Format: YYYY-MM
$month_start = $filter_month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));

$thai_months = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
];
$display_month = $thai_months[(int)date('m', strtotime($month_start))] . ' พ.ศ. ' . (date('Y', strtotime($month_start)) + 543);

// 1. Fetch Teacher Statistics for the month
$stats = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            u.id as teacher_id,
            u.full_name as teacher_name,
            COUNT(tl.id) as total_logs,
            COUNT(DISTINCT tl.log_date) as days_taught,
            SUM(CASE WHEN tl.image_path IS NOT NULL THEN 1 ELSE 0 END) as photos_uploaded,
            (SELECT COUNT(*) FROM homeroom_attendance ha WHERE ha.recorded_by_teacher_id = u.id AND ha.attendance_date BETWEEN ? AND ?) as homeroom_count
        FROM users u
        JOIN user_roles ur ON u.id = ur.user_id
        JOIN roles r ON ur.role_id = r.id
        LEFT JOIN teaching_logs tl ON u.id = tl.teacher_id AND tl.log_date BETWEEN ? AND ?
        WHERE r.role_name = 'teacher'
        GROUP BY u.id
        ORDER BY total_logs DESC, u.full_name ASC
    ");
    $stmt->execute([$month_start, $month_end, $month_start, $month_end]);
    $stats = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching data: " . $e->getMessage();
}

// Prepare summary cards
$total_school_logs = 0;
$total_photos = 0;
foreach($stats as $s) {
    $total_school_logs += $s['total_logs'];
    $total_photos += $s['photos_uploaded'];
}

?>

<div style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 15px;">
    <div>
        <h2 style="margin: 0; color: var(--primary-color);">📊 รายงานประเมินผลรายเดือน</h2>
        <p style="color: var(--text-muted); margin: 5px 0 0 0;">สรุปสถิติการปฏิบัติงานและการส่งบันทึกการสอนของบุคลากร</p>
    </div>
    
    <form method="GET" style="display: flex; gap: 10px; align-items: center; background: white; padding: 10px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
        <label style="font-weight: bold; margin: 0;">เลือกเดือน:</label>
        <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($filter_month) ?>" style="width: auto; margin: 0; padding: 6px 12px;">
        <button type="submit" class="btn btn-primary" style="padding: 6px 15px;">ดูสถิติ</button>
    </form>
</div>

<!-- Overview Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div class="card" style="padding: 20px; border-left: 4px solid var(--primary-color);">
        <div style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 5px;">ประจำเดือน</div>
        <div style="font-size: 1.5rem; font-weight: bold; color: var(--primary-color);"><?= $display_month ?></div>
    </div>
    <div class="card" style="padding: 20px; border-left: 4px solid var(--success);">
        <div style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 5px;">ยอดการส่งรายงานทั้งโรงเรียน</div>
        <div style="font-size: 1.8rem; font-weight: bold; color: var(--success);"><?= number_format($total_school_logs) ?> <span style="font-size: 1rem;">คาบ</span></div>
    </div>
    <div class="card" style="padding: 20px; border-left: 4px solid #f59e0b;">
        <div style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 5px;">จำนวนภาพถ่ายแนบหลักฐาน</div>
        <div style="font-size: 1.8rem; font-weight: bold; color: #f59e0b;"><?= number_format($total_photos) ?> <span style="font-size: 1rem;">รูปภาพ</span></div>
    </div>
</div>

<!-- Main Table -->
<div class="card">
    <div style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0;">ผลการปฏิบัติงานรายบุคคล</h3>
        <button onclick="window.print()" class="btn btn-outline" style="padding: 5px 15px; border-color: #1d4ed8; color: #1d4ed8;">🖨️ พิมพ์รายงาน</button>
    </div>
    
    <div style="overflow-x: auto; padding: 20px;">
        <table style="width: 100%; border-collapse: collapse; min-width: 700px;">
            <thead>
                <tr>
                    <th style="padding: 12px; text-align: left; background: #f8fafc; border-bottom: 2px solid var(--border-color);">อันดับ</th>
                    <th style="padding: 12px; text-align: left; background: #f8fafc; border-bottom: 2px solid var(--border-color);">ชื่อ-สกุล (ครูผู้สอน)</th>
                    <th style="padding: 12px; text-align: center; background: #f8fafc; border-bottom: 2px solid var(--border-color);">จำนวนคาบที่ส่ง (คาบ)</th>
                    <th style="padding: 12px; text-align: center; background: #f8fafc; border-bottom: 2px solid var(--border-color);">มาปฏิบัติงาน (วัน)</th>
                    <th style="padding: 12px; text-align: center; background: #f8fafc; border-bottom: 2px solid var(--border-color);">บันทึกโฮมรูม (ครั้ง)</th>
                    <th style="padding: 12px; text-align: center; background: #f8fafc; border-bottom: 2px solid var(--border-color);">แนบรูปภาพ (รูป)</th>
                    <th style="padding: 12px; text-align: center; background: #f8fafc; border-bottom: 2px solid var(--border-color);">ความถี่ในการรายงาน</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($stats)): ?>
                    <tr><td colspan="7" style="text-align: center; padding: 30px; color: var(--text-muted);">ไม่มีข้อมูลสำหรับเดือนนี้</td></tr>
                <?php else: ?>
                    <?php 
                        $max_logs = max(array_column($stats, 'total_logs'));
                        if ($max_logs == 0) $max_logs = 1; // Prevent div by zero
                    ?>
                    <?php foreach($stats as $index => $s): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px; text-align: center;">
                                <?php if($index == 0 && $s['total_logs'] > 0): ?>🥇
                                <?php elseif($index == 1 && $s['total_logs'] > 0): ?>🥈
                                <?php elseif($index == 2 && $s['total_logs'] > 0): ?>🥉
                                <?php else: ?><span style="color: #94a3b8;"><?= $index + 1 ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; font-weight: bold;"><?= htmlspecialchars($s['teacher_name']) ?></td>
                            <td style="padding: 12px; text-align: center; font-size: 1.1rem; color: var(--primary-color); font-weight: bold;">
                                <?= $s['total_logs'] ?>
                            </td>
                            <td style="padding: 12px; text-align: center;"><?= $s['days_taught'] ?></td>
                            <td style="padding: 12px; text-align: center;"><?= $s['homeroom_count'] ?></td>
                            <td style="padding: 12px; text-align: center;">
                                <?php if($s['photos_uploaded'] > 0): ?>
                                    <span class="badge badge-success">📸 <?= $s['photos_uploaded'] ?></span>
                                <?php else: ?>
                                    <span style="color: #cbd5e1;">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; width: 150px;">
                                <div style="background: #e2e8f0; border-radius: 4px; height: 8px; width: 100%; overflow: hidden;">
                                    <?php $pct = ($s['total_logs'] / $max_logs) * 100; ?>
                                    <div style="background: var(--primary-color); height: 100%; width: <?= $pct ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
@media print {
    body * { visibility: hidden; }
    form { display: none !important; }
    .card, .card *, h2, p { visibility: visible; }
    .card { position: absolute; left: 0; top: 0; width: 100%; box-shadow: none; }
}
</style>

<?php include '../../includes/footer.php'; ?>
