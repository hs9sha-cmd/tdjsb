<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
$is_admin = in_array('admin', $user_roles) || in_array('discipline', $user_roles) || in_array('executive', $user_roles) || in_array('inspector', $user_roles);
$is_teacher = in_array('teacher', $user_roles);

if (!$is_admin && !$is_teacher) {
    echo "Access Denied";
    exit();
}

if (!isset($_GET['cycle_id'])) {
    header("Location: discipline_inspection_cycles.php");
    exit();
}

$cycle_id = $_GET['cycle_id'];

// Helper function to extract a numeric sort code from a room name (e.g. "ม.1/10" -> "0110", "ม.1/2" -> "0102")
function getRoomSortCode($room_name) {
    $parts = explode('/', str_replace('ม.', '', $room_name));
    $grade = isset($parts[0]) ? (int)$parts[0] : 0;
    $room = isset($parts[1]) ? (int)$parts[1] : 0;
    return sprintf('%02d%02d', $grade, $room);
}

// Get Cycle Info
$stmt_cycle = $pdo->prepare("SELECT * FROM inspection_cycles WHERE id = ?");
$stmt_cycle->execute([$cycle_id]);
$cycle = $stmt_cycle->fetch();

if (!$cycle) {
    echo "ไม่พบข้อมูลรอบการตรวจ";
    exit();
}

$page_title = "สถิติการตรวจระเบียบ: " . htmlspecialchars($cycle['cycle_name']);
include '../../includes/header.php';

// Handle Unlock Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_room']) && $is_admin) {
    $unlock_classroom_id = $_POST['unlock_classroom_id'];
    $stmt_unlock = $pdo->prepare("DELETE FROM inspection_room_locks WHERE cycle_id = ? AND classroom_id = ?");
    $stmt_unlock->execute([$cycle_id, $unlock_classroom_id]);
    echo "<div class='alert alert-success'>ปลดล็อคห้องเรียนเรียบร้อยแล้ว ครูที่ปรึกษาสามารถเข้าแก้ไขได้อีกครั้ง</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_all_rooms']) && $is_admin) {
    $stmt_unlock_all = $pdo->prepare("DELETE FROM inspection_room_locks WHERE cycle_id = ?");
    $stmt_unlock_all->execute([$cycle_id]);
    echo "<div class='alert alert-success'>ปลดล็อคทุกห้องเรียนเรียบร้อยแล้ว ครูที่ปรึกษาทุกห้องสามารถเข้าแก้ไขได้อีกครั้ง</div>";
}

// 1. Overall Stats
$stmt_overall = $pdo->prepare("
    SELECT overall_status, COUNT(*) as count 
    FROM discipline_inspections 
    WHERE cycle_id = ?
    GROUP BY overall_status
");
$stmt_overall->execute([$cycle_id]);
$status_counts = [];
$total_students = 0;
while ($row = $stmt_overall->fetch()) {
    $status_counts[$row['overall_status']] = (int)$row['count'];
    $total_students += (int)$row['count'];
}

$pass_count = $status_counts['pass'] ?? 0;
$fail_count = $status_counts['fail'] ?? 0;
$dodged_count = $status_counts['dodged'] ?? 0;
$excused_count = $status_counts['excused'] ?? 0;
$pending_count = $status_counts['pending'] ?? 0; // If any

$pass_rate = $total_students > 0 ? round(($pass_count / $total_students) * 100, 1) : 0;
$fail_dodged_total = $fail_count + $dodged_count;

// 2. Stats by Grade Level
$stmt_grade = $pdo->prepare("
    SELECT c.grade_level,
           SUM(CASE WHEN di.overall_status = 'pass' THEN 1 ELSE 0 END) as pass_count,
           SUM(CASE WHEN di.overall_status IN ('fail', 'dodged') THEN 1 ELSE 0 END) as fail_count,
           COUNT(*) as total
    FROM discipline_inspections di
    JOIN students s ON di.student_id = s.id
    JOIN classrooms c ON s.classroom_id = c.id
    WHERE di.cycle_id = ?
    GROUP BY c.grade_level
    ORDER BY c.grade_level ASC
");
$stmt_grade->execute([$cycle_id]);
$grade_stats = $stmt_grade->fetchAll();

$grade_labels = [];
$grade_pass_data = [];
$grade_fail_data = [];
foreach ($grade_stats as $gs) {
    $grade_labels[] = $gs['grade_level'];
    $grade_pass_data[] = $gs['pass_count'];
    $grade_fail_data[] = $gs['fail_count'];
}

// 3. Leaderboards (By Classroom)
$stmt_room = $pdo->prepare("
    SELECT c.room_name,
           SUM(CASE WHEN di.overall_status = 'pass' THEN 1 ELSE 0 END) as pass_count,
           SUM(CASE WHEN di.overall_status IN ('fail', 'dodged') THEN 1 ELSE 0 END) as fail_count,
           COUNT(*) as total,
           (SUM(CASE WHEN di.overall_status = 'pass' THEN 1 ELSE 0 END) / COUNT(*)) * 100 as pass_rate
    FROM discipline_inspections di
    JOIN students s ON di.student_id = s.id
    JOIN classrooms c ON s.classroom_id = c.id
    WHERE di.cycle_id = ?
    GROUP BY c.id, c.room_name
");
$stmt_room->execute([$cycle_id]);
$room_stats = $stmt_room->fetchAll();

// Sort for Best 5
usort($room_stats, function($a, $b) {
    // Sort by pass_rate DESC, then pass_count DESC
    if ($b['pass_rate'] == $a['pass_rate']) {
        return $b['pass_count'] <=> $a['pass_count'];
    }
    return $b['pass_rate'] <=> $a['pass_rate'];
});
$top_5_best = array_slice($room_stats, 0, 5);

// Sort for Worst 5
usort($room_stats, function($a, $b) {
    // Sort by fail_count DESC
    return $b['fail_count'] <=> $a['fail_count'];
});
$top_5_worst = array_slice($room_stats, 0, 5);

// 4. Issue Breakdown
$stmt_issues = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN hair_status = 0 THEN 1 ELSE 0 END) as hair_fails,
        SUM(CASE WHEN nail_status = 0 THEN 1 ELSE 0 END) as nail_fails,
        SUM(CASE WHEN accessory_status = 0 THEN 1 ELSE 0 END) as accessory_fails,
        SUM(CASE WHEN shoes_status = 0 THEN 1 ELSE 0 END) as shoes_fails
    FROM discipline_inspections
    WHERE cycle_id = ?
");
$stmt_issues->execute([$cycle_id]);
$issue_stats = $stmt_issues->fetch();

$issue_counts = [
    'ทรงผม' => (int)$issue_stats['hair_fails'],
    'เล็บ' => (int)$issue_stats['nail_fails'],
    'เครื่องประดับ/แต่งหน้า' => (int)$issue_stats['accessory_fails'],
    'รองเท้า/เครื่องแบบ' => (int)$issue_stats['shoes_fails']
];
arsort($issue_counts);
$total_issue_fails = array_sum($issue_counts);

?>

<style>
@media print {
    @page {
        size: A4 portrait;
        margin: 10mm;
    }
    body {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        background: #fff !important;
        font-size: 11px !important;
    }
    .no-print {
        display: none !important;
    }
    .card {
        box-shadow: none !important;
        border: 1px solid #ddd !important;
        margin-bottom: 10px !important;
        padding: 10px !important;
        break-inside: avoid;
    }
    /* Grid adjustments for 1 A4 page */
    .row {
        margin-bottom: 0 !important;
    }
    .print-flex-row {
        display: flex !important;
        flex-wrap: nowrap !important;
        gap: 10px;
        width: 100%;
    }
    .print-col-5 { width: 40% !important; flex: 0 0 40% !important; padding: 0 !important; }
    .print-col-7 { width: 60% !important; flex: 0 0 60% !important; padding: 0 !important; }
    .print-col-6 { width: 50% !important; flex: 0 0 50% !important; padding: 0 !important; }
    .stat-val { font-size: 16px !important; }
    .print-grid { grid-template-columns: repeat(5, 1fr) !important; padding: 5px !important; }
    
    .chart-container {
        height: 160px !important; /* Smaller charts for print */
    }
    h2, h3 {
        font-size: 13px !important;
        margin-bottom: 8px !important;
    }
    .table-responsive { overflow: visible !important; }
    .table td, .table th {
        padding: 3px !important;
        font-size: 10px !important;
    }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="row mb-3 no-print">
    <div class="col-12" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 style="margin: 0; font-size: 1.2rem; font-weight: bold;">สถิติ: <?= htmlspecialchars($cycle['cycle_name']) ?></h2>
        <div>
            <button onclick="window.print()" class="btn btn-primary" style="margin-right: 10px;">🖨️ บันทึกเป็น PDF</button>
            <?php if($is_admin): ?>
                <a href="discipline_inspection_cycles.php" class="btn btn-outline">⬅️ กลับไปหน้าจัดการรอบ</a>
            <?php else: ?>
                <a href="teacher_behavior.php" class="btn btn-outline">⬅️ กลับ</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="print-header" style="display: none; text-align: center; margin-bottom: 20px;">
    <!-- Visible only in print via inline CSS overrides if needed, but we can just use the card title below -->
</div>

<div class="card mb-4">
    <p style="margin: 0 0 10px 0; color: var(--text-muted);">วันที่ตรวจ: <strong><?= date('d/m/Y', strtotime($cycle['inspection_date'])) ?></strong> | เป้าหมาย: <strong><?= $cycle['target_grade_level'] === 'all' ? 'ทุกระดับชั้น' : htmlspecialchars($cycle['target_grade_level']) ?></strong> | สถานะ: <strong><?= $cycle['status'] === 'open' ? 'กำลังเปิด' : 'ปิดแล้ว' ?></strong></p>
    
    <!-- Overview Widgets -->
    <div class="print-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-top: 15px;">
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; text-align: center;">
            <div style="font-size: 0.85rem; color: #64748b;">นักเรียนทั้งหมด</div>
            <div class="stat-val" style="font-size: 1.5rem; font-weight: bold; color: #0f172a;"><?= number_format($total_students) ?></div>
        </div>
        <div style="background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 12px; padding: 15px; text-align: center;">
            <div style="font-size: 0.85rem; color: #475569;">ยังไม่ตรวจ (Pending)</div>
            <div class="stat-val" style="font-size: 1.5rem; font-weight: bold; color: #334155;"><?= number_format($pending_count) ?></div>
        </div>
        <div style="background: #ecfdf5; border: 1px solid #d1fae5; border-radius: 12px; padding: 15px; text-align: center;">
            <div style="font-size: 0.85rem; color: #059669;">ผ่าน (%)</div>
            <div class="stat-val" style="font-size: 1.5rem; font-weight: bold; color: #047857;"><?= $pass_rate ?>%</div>
        </div>
        <div style="background: #fef2f2; border: 1px solid #fee2e2; border-radius: 12px; padding: 15px; text-align: center;">
            <div style="font-size: 0.85rem; color: #dc2626;">ไม่ผ่าน/หนี</div>
            <div class="stat-val" style="font-size: 1.5rem; font-weight: bold; color: #b91c1c;"><?= number_format($fail_dodged_total) ?></div>
        </div>
        <div style="background: #eff6ff; border: 1px solid #dbeafe; border-radius: 12px; padding: 15px; text-align: center;">
            <div style="font-size: 0.85rem; color: #2563eb;">ลาป่วย/กิจ</div>
            <div class="stat-val" style="font-size: 1.5rem; font-weight: bold; color: #1d4ed8;"><?= number_format($excused_count) ?></div>
        </div>
    </div>
</div>


<div class="row print-flex-row">
    <!-- Top Issues Breakdown -->
    <div class="col-12 mb-4">
        <div class="card" style="height: 100%;">
            <h3 class="card-title" style="font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 15px;">⚠️ สถิติหัวข้อที่ผิดระเบียบ</h3>
            <?php if ($total_issue_fails > 0): ?>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <?php foreach ($issue_counts as $label => $count): ?>
                        <?php 
                        $percent = ($count / $total_issue_fails) * 100;
                        ?>
                        <div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.9rem; margin-bottom: 5px;">
                                <span><?= htmlspecialchars($label) ?></span>
                                <strong><?= $count ?> รายการ (<?= number_format($percent, 1) ?>%)</strong>
                            </div>
                            <div style="width: 100%; background: #e2e8f0; border-radius: 8px; height: 12px; overflow: hidden;">
                                <div style="height: 100%; width: <?= $percent ?>%; background: var(--danger-color); border-radius: 8px;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; color: var(--text-muted); padding: 20px;">ไม่มีข้อมูลการผิดระเบียบ</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row print-flex-row">
    <!-- Doughnut Chart -->
    <div class="col-md-5 mb-4 print-col-5">
        <div class="card h-100">
            <h3 style="font-size: 1rem; margin-bottom: 20px;">สัดส่วนผลการตรวจภาพรวม</h3>
            <div class="chart-container" style="position: relative; height: 300px; width: 100%; display: flex; justify-content: center;">
                <canvas id="overallChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Bar Chart -->
    <div class="col-md-7 mb-4 print-col-7">
        <div class="card h-100">
            <h3 style="font-size: 1rem; margin-bottom: 20px;">เปรียบเทียบ ผ่าน/ไม่ผ่าน แยกระดับชั้น</h3>
            <div class="chart-container" style="position: relative; height: 300px; width: 100%;">
                <canvas id="gradeChart"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row print-flex-row">
    <!-- Top 5 Best -->
    <div class="col-md-6 mb-4 print-col-6">
        <div class="card h-100">
            <h3 style="font-size: 1rem; margin-bottom: 20px; color: #047857;">🏆 Top 5 ห้องเรียนระเบียบดีเด่น (ผ่านเยอะที่สุด)</h3>
            <div class="table-responsive">
                <table class="table" style="font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th>อันดับ</th>
                            <th>ห้องเรียน</th>
                            <th>% การผ่าน</th>
                            <th>ไม่ผ่าน/หนี</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach($top_5_best as $room): ?>
                        <tr>
                            <td><?= $rank++ ?></td>
                            <td><strong><?= htmlspecialchars($room['room_name']) ?></strong></td>
                            <td style="color: #047857; font-weight: bold;"><?= round($room['pass_rate'], 1) ?>%</td>
                            <td><?= $room['fail_count'] ?> คน</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Top 5 Worst -->
    <div class="col-md-6 mb-4 print-col-6">
        <div class="card h-100">
            <h3 style="font-size: 1rem; margin-bottom: 20px; color: #b91c1c;">🚨 Top 5 ห้องเรียนต้องเฝ้าระวัง (ไม่ผ่านเยอะที่สุด)</h3>
            <div class="table-responsive">
                <table class="table" style="font-size: 0.9rem;">
                    <thead>
                        <tr>
                            <th>อันดับ</th>
                            <th>ห้องเรียน</th>
                            <th>ไม่ผ่าน/หนี</th>
                            <th>ลา</th>
                            <th>% การผ่าน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 1; foreach($top_5_worst as $room): ?>
                        <tr>
                            <td><?= $rank++ ?></td>
                            <td><strong><?= htmlspecialchars($room['room_name']) ?></strong></td>
                            <td style="color: #b91c1c; font-weight: bold;"><?= $room['fail_count'] ?> คน</td>
                            <td><?= $room['total'] - $room['pass_count'] - $room['fail_count'] ?> คน</td>
                            <td><?= round($room['pass_rate'], 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if($top_5_worst[0]['fail_count'] == 0): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #64748b;">สุดยอด! ไม่มีห้องไหนที่มีนักเรียนไม่ผ่านเลย 🎉</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Overall Doughnut Chart
    const ctxOverall = document.getElementById('overallChart').getContext('2d');
    new Chart(ctxOverall, {
        type: 'doughnut',
        data: {
            labels: ['ผ่าน', 'ไม่ผ่าน', 'หนีตรวจ', 'ลาป่วย/ลากิจ', 'ยังไม่ตรวจ'],
            datasets: [{
                data: [<?= $pass_count ?>, <?= $fail_count ?>, <?= $dodged_count ?>, <?= $excused_count ?>, <?= $pending_count ?>],
                backgroundColor: [
                    '#10b981', // green
                    '#ef4444', // red
                    '#f59e0b', // orange
                    '#3b82f6', // blue
                    '#94a3b8'  // gray
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: { family: "'Prompt', sans-serif" }
                    }
                }
            }
        }
    });

    // 2. Grade Level Bar Chart
    const ctxGrade = document.getElementById('gradeChart').getContext('2d');
    new Chart(ctxGrade, {
        type: 'bar',
        data: {
            labels: <?= json_encode($grade_labels) ?>,
            datasets: [
                {
                    label: 'ผ่าน',
                    data: <?= json_encode($grade_pass_data) ?>,
                    backgroundColor: '#10b981',
                    borderRadius: 4
                },
                {
                    label: 'ไม่ผ่าน / หนีตรวจ',
                    data: <?= json_encode($grade_fail_data) ?>,
                    backgroundColor: '#ef4444',
                    borderRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: { family: "'Prompt', sans-serif" }
                    }
                }
            },
            scales: {
                x: {
                    stacked: false,
                    grid: { display: false }
                },
                y: {
                    stacked: false,
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });
});
</script>

