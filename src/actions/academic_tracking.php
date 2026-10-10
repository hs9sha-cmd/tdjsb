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

$filter_date = $_GET['date'] ?? date('Y-m-d');
$dow = date('N', strtotime($filter_date)); // 1=Mon, 7=Sun

try {
    // Get active term
    $term_stmt = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
    $active_term = $term_stmt->fetch();
    $term_id = $active_term ? $active_term['id'] : 0;

    // 1. Get all teachers and count their scheduled periods for this day of the week
    $sql_teachers = "
        SELECT t.id, t.full_name, COUNT(s.id) as expected_periods
        FROM users t
        JOIN user_roles ur ON t.id = ur.user_id
        JOIN roles r ON ur.role_id = r.id AND r.role_name = 'teacher'
        LEFT JOIN schedules s ON s.teacher_id = t.id AND s.academic_term_id = ? AND s.day_of_week = ?
        GROUP BY t.id, t.full_name
        ORDER BY t.full_name ASC
    ";
    $stmt1 = $pdo->prepare($sql_teachers);
    $stmt1->execute([$term_id, $dow]);
    $teachers = $stmt1->fetchAll(PDO::FETCH_KEY_PAIR | PDO::FETCH_ASSOC); // Result: [id => ['full_name' => ..., 'expected_periods' => ...]]
    // wait, FETCH_KEY_PAIR expects exactly 2 columns. We have 3 columns.
    
    // Fallback manual formatting
    $stmt1 = $pdo->prepare($sql_teachers);
    $stmt1->execute([$term_id, $dow]);
    $teachers = [];
    foreach ($stmt1->fetchAll() as $t) {
        $teachers[$t['id']] = [
            'name' => $t['full_name'],
            'expected' => (int)$t['expected_periods'],
            'submitted' => 0
        ];
    }

    // 2. Get submitted logs count for the specific date
    $sql_logs = "
        SELECT teacher_id, COUNT(id) as submitted_periods
        FROM teaching_logs
        WHERE log_date = ?
        GROUP BY teacher_id
    ";
    $stmt_logs = $pdo->prepare($sql_logs);
    $stmt_logs->execute([$filter_date]);
    
    while ($row = $stmt_logs->fetch()) {
        $tid = $row['teacher_id'];
        if (isset($teachers[$tid])) {
            $teachers[$tid]['submitted'] = (int)$row['submitted_periods'];
        }
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}

// Summary numbers
$summary = ['complete' => 0, 'partial' => 0, 'missing' => 0, 'none' => 0];
foreach ($teachers as $tid => $data) {
    if ($data['expected'] == 0) {
        if ($data['submitted'] > 0) {
            // Submitted extra, but had 0 expected
            $summary['complete']++;
        } else {
            $summary['none']++;
        }
        continue;
    }

    if ($data['submitted'] >= $data['expected']) {
        $summary['complete']++;
    } elseif ($data['submitted'] > 0) {
        $summary['partial']++;
    } else {
        $summary['missing']++;
    }
}
?>

<div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">ตรวจสอบการส่งรายงานสอน (รายวัน)</h2>
        <a href="academic_dashboard.php" class="btn btn-outline">◀ กลับหน้าแดชบอร์ดภาพรวม</a>
    </div>

    <!-- Filter Bar -->
    <div class="card" style="margin-bottom: 20px; padding: 15px;">
        <form method="GET" style="display: flex; gap: 15px; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>หน้าการตรวจสอบของวันที่</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>">
            </div>
            <button type="submit" class="btn btn-primary">เรียกดูสถิติ</button>
        </form>
    </div>

    <!-- Summary Widgets -->
    <div style="display: flex; gap: 20px; margin-bottom: 20px;">
        <div class="card" style="flex: 1; text-align: center; border-left: 5px solid var(--success-color);">
            <h3 style="margin: 0; color: var(--text-muted); font-size: 1rem;">ส่งครบถ้วน</h3>
            <p style="font-size: 2rem; font-weight: bold; margin: 10px 0; color: var(--success-color);"><?= $summary['complete'] ?></p>
            <small>คน</small>
        </div>
        <div class="card" style="flex: 1; text-align: center; border-left: 5px solid #FF9800;">
            <h3 style="margin: 0; color: var(--text-muted); font-size: 1rem;">ส่งบางส่วน</h3>
            <p style="font-size: 2rem; font-weight: bold; margin: 10px 0; color: #FF9800;"><?= $summary['partial'] ?></p>
            <small>คน</small>
        </div>
        <div class="card" style="flex: 1; text-align: center; border-left: 5px solid var(--danger-color);">
            <h3 style="margin: 0; color: var(--text-muted); font-size: 1rem;">ยังไม่ส่ง</h3>
            <p style="font-size: 2rem; font-weight: bold; margin: 10px 0; color: var(--danger-color);"><?= $summary['missing'] ?></p>
            <small>คน</small>
        </div>
        <div class="card" style="flex: 1; text-align: center; border-left: 5px solid #999;">
            <h3 style="margin: 0; color: var(--text-muted); font-size: 1rem;">ไม่มีคาบสอน</h3>
            <p style="font-size: 2rem; font-weight: bold; margin: 10px 0; color: #999;"><?= $summary['none'] ?></p>
            <small>คน</small>
        </div>
    </div>

    <div class="card">
        <h3 class="card-title">รายชื่อครูและสถานะการส่งรายงาน - ประจำวันที่ <?= date('d/m/Y', strtotime($filter_date)) ?></h3>
        <div class="scroll-table">
            <table class="table">
                <thead>
                    <tr>
                        <th>ลำดับ</th>
                        <th>ชื่อ-สกุล (ครูผู้สอน)</th>
                        <th style="text-align: center;">จำนวนคาบที่สอนวันนี้</th>
                        <th style="text-align: center;">ส่งรายงานแล้ว (คาบ)</th>
                        <th style="text-align: center;">สถานะ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $i = 1; 
                    foreach ($teachers as $tid => $data): 
                    ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($data['name']) ?></td>
                            <td style="text-align: center;"><?= $data['expected'] ?></td>
                            <td style="text-align: center;">
                                <strong><?= $data['submitted'] ?></strong>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($data['expected'] == 0): ?>
                                    <?php if ($data['submitted'] > 0): ?>
                                        <span class="badge badge-success">ส่งครบ (สอนเสริม)</span>
                                    <?php else: ?>
                                        <span class="badge" style="background-color: #eee; color: #666;">ไม่มีคาบสอน</span>
                                    <?php endif; ?>
                                <?php elseif ($data['submitted'] >= $data['expected']): ?>
                                    <span class="badge badge-success">ส่งครบถ้วน ✅</span>
                                <?php elseif ($data['submitted'] > 0): ?>
                                    <span class="badge badge-warning" style="background: #FF9800; color: white;">ไม่ครบ (ขาด <?= $data['expected'] - $data['submitted'] ?> คาบ)</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">ยังไม่ส่ง ❌</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
