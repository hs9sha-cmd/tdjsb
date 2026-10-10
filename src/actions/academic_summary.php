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

include '../../includes/header.php';

// Default to current month if not specified
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-t');

try {
    // Get active term
    $term_stmt = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
    $active_term = $term_stmt->fetch();
    $term_id = $active_term ? $active_term['id'] : 0;

    // 1. Fetch all teachers
    $teacher_stmt = $pdo->query("
        SELECT t.id, t.full_name
        FROM users t
        JOIN user_roles ur ON t.id = ur.user_id
        JOIN roles r ON ur.role_id = r.id AND r.role_name = 'teacher'
        ORDER BY t.full_name ASC
    ");
    $all_teachers = [];
    while ($row = $teacher_stmt->fetch()) {
        $all_teachers[$row['id']] = [
            'name' => $row['full_name'],
            'expected' => 0,
            'submitted' => 0
        ];
    }

    // 2. Fetch schedules grouped by teacher and day_of_week
    $sched_stmt = $pdo->prepare("
        SELECT teacher_id, day_of_week, COUNT(id) as periods
        FROM schedules
        WHERE academic_term_id = ?
        GROUP BY teacher_id, day_of_week
    ");
    $sched_stmt->execute([$term_id]);
    $schedules_by_dow = [];
    while ($row = $sched_stmt->fetch()) {
        $schedules_by_dow[$row['teacher_id']][$row['day_of_week']] = (int)$row['periods'];
    }

    // 3. Iterate dates in range to calculate exact scheduled periods
    $current_date = new DateTime($start_date);
    $end_dt = new DateTime($end_date);
    
    // Safety limit of 365 days max iteration
    $diff = $current_date->diff($end_dt)->days;
    if ($diff <= 365) {
        while ($current_date <= $end_dt) {
            $dow = $current_date->format('N');
            foreach ($schedules_by_dow as $tid => $dows) {
                if (isset($dows[$dow]) && isset($all_teachers[$tid])) {
                    $all_teachers[$tid]['expected'] += $dows[$dow];
                }
            }
            $current_date->modify('+1 day');
        }
    }

    // 4. Fetch actual submissions within range
    $log_stmt = $pdo->prepare("
        SELECT teacher_id, COUNT(id) as submitted_periods
        FROM teaching_logs
        WHERE log_date >= ? AND log_date <= ?
        GROUP BY teacher_id
    ");
    $log_stmt->execute([$start_date, $end_date]);
    
    while ($row = $log_stmt->fetch()) {
        $tid = $row['teacher_id'];
        if (isset($all_teachers[$tid])) {
            $all_teachers[$tid]['submitted'] = (int)$row['submitted_periods'];
        }
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}

// Calculate Summary
$sum_expected = 0;
$sum_submitted = 0;
foreach ($all_teachers as $t) {
    $sum_expected += $t['expected'];
    $sum_submitted += $t['submitted'];
}
$overall_percent = ($sum_expected > 0) ? round(($sum_submitted / $sum_expected) * 100, 1) : 0;
?>

<div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">ภาพรวมสถิติการส่งรายงาน (รายสัปดาห์ / รายเดือน)</h2>
        <a href="academic_dashboard.php" class="btn btn-outline">◀ กลับหน้าแดชบอร์ดภาพรวม</a>
    </div>

    <!-- Filter Bar -->
    <div class="card" style="margin-bottom: 20px; padding: 15px;">
        <form method="GET" style="display: flex; gap: 15px; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>ตั้งแต่วันที่</label>
                <input type="date" id="start_date" name="start" class="form-control" value="<?= htmlspecialchars($start_date) ?>" required>
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>ถึงวันที่</label>
                <input type="date" id="end_date" name="end" class="form-control" value="<?= htmlspecialchars($end_date) ?>" required>
            </div>
            <button type="submit" class="btn btn-primary">ดูข้อมูล</button>
            
            <!-- Quick Filters -->
            <div style="margin-left: auto; display: flex; gap: 10px;">
                <button type="button" class="btn btn-outline" onclick="setThisWeek()">สัปดาห์นี้</button>
                <button type="button" class="btn btn-outline" onclick="setThisMonth()">เดือนนี้</button>
            </div>
        </form>
    </div>

    <!-- Main Results -->
    <div style="display: grid; grid-template-columns: 3fr 1fr; gap: 20px;">
        
        <!-- Detail Table -->
        <div class="card">
            <h3 class="card-title">สรุปสถิติครูผู้สอน ระหว่างวันที่ <?= date('d/m/Y', strtotime($start_date)) ?> ถึง <?= date('d/m/Y', strtotime($end_date)) ?></h3>
            <div class="scroll-table">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ลำดับ</th>
                            <th>ชื่อ-สกุล (ครูผู้สอน)</th>
                            <th style="text-align: center;">ภาระงานรวม (ตาราง)</th>
                            <th style="text-align: center;">ปฏิบัติจริง (รายงาน)</th>
                            <th style="text-align: center;">สถานะ</th>
                            <th style="text-align: center;">% ส่งงาน</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1; 
                        foreach ($all_teachers as $data): 
                            $expected = $data['expected'];
                            $submitted = $data['submitted'];
                            $pct = ($expected > 0) ? round(($submitted / $expected) * 100) : 0;
                        ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= htmlspecialchars($data['name']) ?></td>
                                <td style="text-align: center;"><?= $expected ?></td>
                                <td style="text-align: center;"><strong><?= $submitted ?></strong></td>
                                <td style="text-align: center;">
                                    <?php if ($expected == 0): ?>
                                        <span class="badge" style="background:#eee;color:#666;">ไม่มีตารางสอน</span>
                                    <?php elseif ($submitted >= $expected): ?>
                                        <span class="badge badge-success">ส่งครบถ้วน ✅</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" style="background:#FF9800; color:white;">ส่งขาด <?= $expected - $submitted ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($expected > 0): ?>
                                        <span style="color: <?= $pct >= 80 ? 'var(--success-color)' : ($pct >= 50 ? '#FF9800' : 'var(--danger-color)') ?>; font-weight: bold;">
                                            <?= $pct ?>%
                                        </span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Overall Card -->
        <div>
            <div class="card" style="text-align: center; padding: 30px;">
                <h3 style="color: var(--text-muted); font-size: 1.1rem; margin-bottom: 20px;">ภาพรวมความสมบูรณ์</h3>
                <div style="font-size: 3.5rem; font-weight: 800; color: <?= $overall_percent >= 80 ? 'var(--success-color)' : ($overall_percent >= 50 ? '#FF9800' : 'var(--danger-color)') ?>;">
                    <?= $overall_percent ?>%
                </div>
                <p style="color: var(--text-muted); margin-top: 10px;">ของรายงานสอนทั้งหมด</p>
                <hr style="margin: 20px 0; border: 0; border-top: 1px solid var(--border-color);">
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span>ภาระงานตามตารางรวม:</span>
                    <strong><?= number_format($sum_expected) ?> คาบ</strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>รายงานสอนที่ส่งเข้ามา:</span>
                    <strong style="color: var(--success-color);"><?= number_format($sum_submitted) ?> คาบ</strong>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
function setThisWeek() {
    let curr = new Date(); // get current date
    let first = curr.getDate() - curr.getDay() + 1; // First day is the day of the month - the day of the week
    let last = first + 6; // last day is the first day + 6

    let firstday = new Date(curr.setDate(first)).toISOString().split('T')[0];
    let lastday = new Date(curr.setDate(last)).toISOString().split('T')[0];

    document.getElementById('start_date').value = firstday;
    document.getElementById('end_date').value = lastday;
}

function setThisMonth() {
    let date = new Date();
    let firstDay = new Date(date.getFullYear(), date.getMonth(), 1).toISOString().split('T')[0];
    let lastDay = new Date(date.getFullYear(), date.getMonth() + 1, 0).toISOString().split('T')[0];
    
    document.getElementById('start_date').value = firstDay;
    document.getElementById('end_date').value = lastDay;
}
</script>

<?php include '../../includes/footer.php'; ?>
