<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('teacher', $user_roles) && !in_array('admin', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "ตรวจระเบียบ/พฤติกรรม (ครูประจำชั้น)";
include '../../includes/header.php';

$teacher_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Get active academic term
$stmt_term = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
$term_id = $stmt_term->fetchColumn();

if (!$term_id) {
    echo "<div class='card'><div class='badge badge-danger'>ไม่พบปีการศึกษาที่เปิดใช้งาน</div></div>";
    include '../../includes/footer.php';
    exit();
}

// Handle submit behavior report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $student_id = $_POST['student_id'];
    $reason = trim($_POST['reason']);
    
    if ($student_id && $reason) {
        $stmt = $pdo->prepare("INSERT INTO behavior_records (student_id, academic_term_id, recorded_by_teacher_id, record_date, points, reason, status) VALUES (?, ?, ?, CURDATE(), 0, ?, 'pending')");
        if ($stmt->execute([$student_id, $term_id, $teacher_id, $reason])) {
            $success = "บันทึกการรายงานพฤติกรรมเรียบร้อยแล้ว รอหัวหน้าระดับพิจารณา";
        } else {
            $error = "เกิดข้อผิดพลาดในการบันทึก";
        }
    }
}



// Get homeroom classroom for this teacher based on advisor_classroom in users table
$stmt_hr = $pdo->prepare("
    SELECT c.id, c.room_name, c.grade_level 
    FROM users u
    JOIN classrooms c ON c.room_name = u.advisor_classroom
    WHERE u.id = ?
");
$stmt_hr->execute([$teacher_id]);
$homerooms = $stmt_hr->fetchAll();
$teacher_grades = array_unique(array_column($homerooms, 'grade_level'));
$is_admin = in_array('admin', $user_roles) || in_array('discipline', $user_roles) || in_array('executive', $user_roles);

$selected_classroom = $_GET['classroom_id'] ?? ($homerooms[0]['id'] ?? null);

$students = [];
if ($selected_classroom) {
    $stmt_s = $pdo->prepare("
        SELECT s.id, s.student_number, s.full_name, COALESCE(sb.current_score, 100) as current_score
        FROM students s
        LEFT JOIN student_behaviors sb ON s.id = sb.student_id AND sb.academic_term_id = ?
        WHERE s.classroom_id = ? AND s.status = 'active'
        ORDER BY CAST(s.student_number AS UNSIGNED) ASC
    ");
    $stmt_s->execute([$term_id, $selected_classroom]);
    $students = $stmt_s->fetchAll();
}

// 1. Get Open Inspection Cycles
if (empty($teacher_grades)) {
    $open_cycles = [];
} else {
    $in_grades = "'" . implode("','", $teacher_grades) . "'";
    $stmt_cycles = $pdo->prepare("SELECT * FROM inspection_cycles WHERE academic_term_id = ? AND status = 'open' AND (target_grade_level = 'all' OR target_grade_level IN ($in_grades)) ORDER BY inspection_date DESC");
    $stmt_cycles->execute([$term_id]);
    $open_cycles = $stmt_cycles->fetchAll();
}
?>

<div class="row">
    <div class="col-12">
        <a href="teacher_home.php" class="btn btn-outline mb-3">⬅️ หน้าหลัก</a>
    </div>
</div>

<?php if (!empty($open_cycles)): ?>
<div class="card" style="margin-bottom: 20px;">
    <h2 class="card-title" style="margin-bottom: 16px; font-size: 0.95rem;">รอบการตรวจระเบียบที่รอตรวจสอบ</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
        <?php foreach($open_cycles as $cycle): ?>
        <div style="border: 1px solid var(--border-color); border-radius: 12px; padding: 14px;">
            <h4 style="margin-top: 0; color: var(--text-main); font-size: 0.9rem; font-weight: 500;"><?= htmlspecialchars($cycle['cycle_name']) ?></h4>
            <p style="margin-bottom: 4px; font-size: 0.8rem; color: var(--text-muted);">วันที่ตรวจ: <?= date('d/m/Y', strtotime($cycle['inspection_date'])) ?></p>
            <p style="margin-bottom: 12px; font-size: 0.8rem; color: var(--text-muted);">ระดับชั้น: <?= $cycle['target_grade_level'] === 'all' ? 'ทุกระดับชั้น' : htmlspecialchars($cycle['target_grade_level']) ?></p>
            <a href="teacher_inspection.php?cycle_id=<?= $cycle['id'] ?>" class="btn btn-primary" style="width: 100%; text-align: center; font-size: 0.8rem; padding: 10px;">เข้าไปตรวจ / แก้ผลการตรวจ</a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($homerooms)): ?>
<div class="card">
    <h2 class="card-title" style="font-size: 0.95rem;">ดูคะแนนความประพฤตินักเรียนในที่ปรึกษา</h2>

        
        <?php if($success): ?><div class="badge badge-success mb-3" style="display: block; padding: 10px;"><?= $success ?></div><?php endif; ?>
        <?php if($error): ?><div class="badge badge-danger mb-3" style="display: block; padding: 10px;"><?= $error ?></div><?php endif; ?>

        <form method="GET" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: flex-end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>ห้องเรียนที่ปรึกษา</label>
                <select name="classroom_id" class="form-control" onchange="this.form.submit()">
                    <?php foreach($homerooms as $hr): ?>
                        <option value="<?= $hr['id'] ?>" <?= $selected_classroom == $hr['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($hr['room_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($selected_classroom && !empty($students)): ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 50px; padding: 8px 10px;">เลขที่</th>
                            <th style="padding: 8px 10px;">ชื่อ-สกุล</th>
                            <th style="width: 150px; text-align: center; padding: 8px 10px;">คะแนนคงเหลือ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($students as $s): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="text-align: center; padding: 8px 10px; vertical-align: middle;"><?= $s['student_number'] ?></td>
                            <td style="padding: 8px 10px; vertical-align: middle; font-size: 0.95rem;"><?= htmlspecialchars(str_replace(['นาย','นางสาว','เด็กชาย','เด็กหญิง'], '', $s['full_name'])) ?></td>
                            <td style="text-align: center; vertical-align: middle; padding: 8px 10px;">
                                <div style="display: flex; align-items: center; justify-content: center; gap: 15px;">
                                    <span style="font-weight: bold; font-size: 1.1rem; color: <?= $s['current_score'] < 60 ? 'var(--danger-color)' : '#059669' ?>;"><?= $s['current_score'] ?></span>
                                    <a href="discipline_student_history.php?student_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline" style="font-size: 0.75rem; padding: 2px 10px; border-radius: 12px; margin: 0; white-space: nowrap;">ดูประวัติ</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
</div>
<?php endif; // End if (!empty($homerooms)) ?>



<?php include '../../includes/footer.php'; ?>
