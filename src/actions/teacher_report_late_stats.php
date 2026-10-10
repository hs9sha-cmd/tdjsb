<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('teacher', $user_roles) && !in_array('admin', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "สถิติมาสายรายบุคคล";
include '../../includes/header.php';

$teacher_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT advisor_classroom FROM users WHERE id = ?");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();
$advisor_classroom = $teacher['advisor_classroom'] ?? null;

// Get Active Term dates
$term_stmt = $pdo->query("SELECT id, year_name, term_number, start_date, end_date FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term = $term_stmt->fetch();
$term_start = $active_term['start_date'] ?? date('Y-01-01');
$term_end = $active_term['end_date'] ?? date('Y-12-31');

// Filter Logic
$filter_type = $_GET['filter_type'] ?? 'term';
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$filter_week = $_GET['filter_week'] ?? date('Y-\WW');
$filter_month = $_GET['filter_month'] ?? date('Y-m');

$start_date = $term_start;
$end_date = $term_end;
$display_range = $active_term ? "ภาคเรียนที่ {$active_term['term_number']} ปีการศึกษา {$active_term['year_name']}" : "ทุกภาคเรียน";

if ($filter_type === 'daily') {
    $start_date = $filter_date;
    $end_date = $filter_date;
    $display_range = "ประจำวันที่ " . date('d/m/Y', strtotime($filter_date));
} elseif ($filter_type === 'weekly') {
    $dto = new DateTime();
    $dto->setISODate((int)substr($filter_week, 0, 4), (int)substr($filter_week, 6));
    $start_date = $dto->format('Y-m-d');
    $dto->modify('+6 days');
    $end_date = $dto->format('Y-m-d');
    $display_range = "สัปดาห์ที่ " . substr($filter_week, 6) . " (" . date('d/m/Y', strtotime($start_date)) . " - " . date('d/m/Y', strtotime($end_date)) . ")";
} elseif ($filter_type === 'monthly') {
    $start_date = $filter_month . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
    $display_range = "ประจำเดือน " . date('m/Y', strtotime($start_date));
}

$students = [];
if ($advisor_classroom) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.student_code, s.full_name, s.student_number,
               (SELECT COUNT(*) FROM homeroom_attendance ha WHERE ha.student_id = s.id AND ha.status = 'late' AND ha.attendance_date BETWEEN ? AND ?) as late_count
        FROM students s
        JOIN classrooms c ON s.classroom_id = c.id
        WHERE c.room_name = ?
        ORDER BY CAST(s.student_number AS UNSIGNED) ASC, s.student_code ASC
    ");
    $stmt->execute([$start_date, $end_date, $advisor_classroom]);
    $students = $stmt->fetchAll();
}
?>

<div class="content-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h2 style="margin: 0; font-size: 1.4rem; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
            <a href="teacher_report_home.php" style="color: var(--text-muted); text-decoration: none; display: flex; align-items: center;">
                <span class="material-symbols-rounded">arrow_back</span>
            </a>
            จำนวนครั้งที่มาสายรายบุคคล
        </h2>
        <div style="color: var(--text-muted); font-size: 0.9rem; margin-top: 5px;">
            ห้องที่ปรึกษา: <?= htmlspecialchars($advisor_classroom ?? 'ไม่มี') ?> | <?= htmlspecialchars($display_range) ?>
        </div>
    </div>
</div>

<?php if (!$advisor_classroom): ?>
    <div style="background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px; text-align: center;">
        คุณไม่มีห้องเรียนที่ปรึกษา จึงไม่สามารถดูรายงานนี้ได้
    </div>
<?php else: ?>

    <!-- Filter Card -->
    <div class="card" style="background: white; border-radius: 12px; padding: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 15px;">
        <form method="GET" id="filterForm" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; align-items: end;">
            <div>
                <label style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 3px; font-weight: 500;">รูปแบบการกรอง</label>
                <select name="filter_type" id="filter_type" class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1; background: #f8fafc; font-size: 0.9rem;" onchange="toggleFilterInputs()">
                    <option value="daily" <?= $filter_type === 'daily' ? 'selected' : '' ?>>รายวัน</option>
                    <option value="weekly" <?= $filter_type === 'weekly' ? 'selected' : '' ?>>รายสัปดาห์</option>
                    <option value="monthly" <?= $filter_type === 'monthly' ? 'selected' : '' ?>>รายเดือน</option>
                    <option value="term" <?= $filter_type === 'term' ? 'selected' : '' ?>>รายภาคเรียน</option>
                </select>
            </div>
            
            <div id="dynamic_filter_container" style="display: <?= $filter_type === 'term' ? 'none' : 'block' ?>;">
                <div id="div_filter_date" style="display: <?= $filter_type === 'daily' ? 'block' : 'none' ?>;">
                    <label style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 3px; font-weight: 500;">เลือกวันที่</label>
                    <input type="date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>" class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1; background: white; font-size: 0.9rem;" onchange="document.getElementById('filterForm').submit()">
                </div>

                <div id="div_filter_week" style="display: <?= $filter_type === 'weekly' ? 'block' : 'none' ?>;">
                    <label style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 3px; font-weight: 500;">เลือกสัปดาห์</label>
                    <input type="week" name="filter_week" value="<?= htmlspecialchars($filter_week) ?>" class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1; background: white; font-size: 0.9rem;" onchange="document.getElementById('filterForm').submit()">
                </div>

                <div id="div_filter_month" style="display: <?= $filter_type === 'monthly' ? 'block' : 'none' ?>;">
                    <label style="display: block; font-size: 0.8rem; color: var(--text-muted); margin-bottom: 3px; font-weight: 500;">เลือกเดือน</label>
                    <input type="month" name="filter_month" value="<?= htmlspecialchars($filter_month) ?>" class="form-control" style="width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #cbd5e1; background: white; font-size: 0.9rem;" onchange="document.getElementById('filterForm').submit()">
                </div>
            </div>
        </form>
    </div>

    <script>
    function toggleFilterInputs() {
        const type = document.getElementById('filter_type').value;
        document.getElementById('dynamic_filter_container').style.display = (type === 'term') ? 'none' : 'block';
        document.getElementById('div_filter_date').style.display = (type === 'daily') ? 'block' : 'none';
        document.getElementById('div_filter_week').style.display = (type === 'weekly') ? 'block' : 'none';
        document.getElementById('div_filter_month').style.display = (type === 'monthly') ? 'block' : 'none';
        
        document.getElementById('filterForm').submit();
    }
    </script>

    <?php if (empty($students)): ?>
        <div style="text-align: center; padding: 40px; background: white; border-radius: 12px; border: 1px dashed var(--border-color); color: var(--text-muted);">
            ไม่พบรายชื่อนักเรียนในห้อง <?= htmlspecialchars($advisor_classroom) ?> หรือไม่มีข้อมูลในช่วงเวลานี้
        </div>
    <?php else: ?>
        <div style="background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); overflow: hidden;">
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                            <th style="padding: 10px; text-align: center; color: #475569; font-weight: 600; width: 50px;">เลขที่</th>
                            <th style="padding: 10px; text-align: left; color: #475569; font-weight: 600;">รหัส/ชื่อ-สกุล</th>
                            <th style="padding: 10px; text-align: center; color: #475569; font-weight: 600; width: 80px;">มาสาย</th>
                            <th style="padding: 10px; text-align: center; color: #475569; font-weight: 600; width: 80px;">ประวัติ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9; transition: background-color 0.2s ease;">
                                <td style="padding: 8px 10px; text-align: center; color: #64748b;"><?= htmlspecialchars($s['student_number']) ?></td>
                                <td style="padding: 8px 10px;">
                                    <div style="color: #64748b; font-size: 0.75rem; margin-bottom: 1px;"><?= htmlspecialchars($s['student_code']) ?></div>
                                    <div style="color: #1e293b; font-weight: 500;"><?= htmlspecialchars($s['full_name']) ?></div>
                                </td>
                                <td style="padding: 8px 10px; text-align: center;">
                                    <?php if ($s['late_count'] > 0): ?>
                                        <span style="display: inline-block; background: <?= $s['late_count'] >= 3 ? '#fee2e2' : '#fef3c7' ?>; color: <?= $s['late_count'] >= 3 ? '#dc2626' : '#d97706' ?>; padding: 2px 8px; border-radius: 12px; font-weight: 700; font-size: 0.85rem;">
                                            <?= $s['late_count'] ?> ครั้ง
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-block; background: #f1f5f9; color: #94a3b8; padding: 2px 8px; border-radius: 12px; font-weight: 600; font-size: 0.85rem;">
                                            0
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 8px 10px; text-align: center;">
                                    <a href="discipline_student_history.php?student_id=<?= $s['id'] ?>" class="btn btn-outline" style="padding: 4px 8px; font-size: 0.75rem; border-radius: 6px; color: #0ea5e9; border-color: #e0f2fe; background: #f0f9ff; text-decoration: none; display: inline-flex; align-items: center; gap: 2px;">
                                        <span class="material-symbols-rounded" style="font-size: 14px;">history</span>
                                        ดู
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<style>
tbody tr:hover {
    background-color: #f8fafc;
}
</style>

<?php include '../../includes/footer.php'; ?>
