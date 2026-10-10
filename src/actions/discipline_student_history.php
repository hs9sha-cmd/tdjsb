<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles) && !in_array('teacher', $user_roles)) {
    echo "Access Denied";
    exit();
}

$student_id = $_GET['student_id'] ?? null;
if (!$student_id) {
    echo "ไม่พบข้อมูลนักเรียน";
    exit();
}

$success = '';
$error = '';
if (isset($_SESSION['success_msg'])) {
    $success = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
}

$can_manage_behavior = in_array('admin', $user_roles) || in_array('level_head', $user_roles);

// Get active academic term
$stmt_term = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
$term_id = $stmt_term->fetchColumn();

function updateCurrentScore($pdo, $student_id, $term_id) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO student_behaviors (student_id, academic_term_id, initial_score, current_score) VALUES (?, ?, 100, 100)");
    $stmt->execute([$student_id, $term_id]);
    
    $stmt_sum = $pdo->prepare("SELECT SUM(points) FROM behavior_records WHERE student_id = ? AND academic_term_id = ? AND status = 'approved'");
    $stmt_sum->execute([$student_id, $term_id]);
    $total_points = (int)$stmt_sum->fetchColumn();
    
    $new_score = 100 + $total_points;
    if ($new_score > 100) $new_score = 100;
    $stmt_upd = $pdo->prepare("UPDATE student_behaviors SET current_score = ? WHERE student_id = ? AND academic_term_id = ?");
    $stmt_upd->execute([$new_score, $student_id, $term_id]);
}

if ($can_manage_behavior && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_record'])) {
    $delete_id = $_POST['record_id'];
    $stmt_del_info = $pdo->prepare("SELECT student_id, record_date, reason FROM behavior_records WHERE id = ?");
    $stmt_del_info->execute([$delete_id]);
    $del_info = $stmt_del_info->fetch();
    
    if ($del_info) {
        $sid = $del_info['student_id'];
        $rdate = $del_info['record_date'];
        $reason = $del_info['reason'];
        
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM behavior_records WHERE id = ?")->execute([$delete_id]);
            
            if (strpos($reason, 'มาสาย') !== false) {
                $pdo->prepare("UPDATE homeroom_attendance SET status = 'present' WHERE student_id = ? AND attendance_date = ? AND status = 'late'")->execute([$sid, $rdate]);
            }
            
            updateCurrentScore($pdo, $sid, $term_id);
            $pdo->commit();
            
            $_SESSION['success_msg'] = "ลบรายการและคืนคะแนนเรียบร้อยแล้ว";
            header("Location: discipline_student_history.php?student_id=" . $student_id);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "เกิดข้อผิดพลาดในการลบ: " . $e->getMessage();
        }
    }
}

// Fetch student info
$stmt_s = $pdo->prepare("
    SELECT s.full_name, s.student_code, s.student_number, c.room_name, COALESCE(sb.initial_score, 100) as initial_score, COALESCE(sb.current_score, 100) as current_score
    FROM students s
    LEFT JOIN classrooms c ON s.classroom_id = c.id
    LEFT JOIN student_behaviors sb ON s.id = sb.student_id AND sb.academic_term_id = ?
    WHERE s.id = ?
");
$stmt_s->execute([$term_id, $student_id]);
$student = $stmt_s->fetch();

if (!$student) {
    echo "ไม่พบข้อมูลนักเรียน";
    exit();
}

$page_title = "ประวัติคะแนนความประพฤติ: " . htmlspecialchars($student['full_name']);
include '../../includes/header.php';

// Fetch history
$stmt_hist = $pdo->prepare("
    SELECT br.*, u.full_name as teacher_name
    FROM behavior_records br
    LEFT JOIN users u ON br.recorded_by_teacher_id = u.id
    WHERE br.student_id = ? AND br.academic_term_id = ? AND br.status = 'approved'
    ORDER BY br.created_at DESC
");
$stmt_hist->execute([$student_id, $term_id]);
$history = $stmt_hist->fetchAll();
?>

<div class="card" style="max-width: 800px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin: 0;">ประวัติคะแนนความประพฤติ</h2>
        <a href="javascript:history.back()" class="btn btn-secondary">ย้อนกลับ</a>
    </div>

    <?php if($success): ?><div class="badge badge-success mb-3" style="display: block; padding: 10px;"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="badge badge-danger mb-3" style="display: block; padding: 10px;"><?= $error ?></div><?php endif; ?>

    <div style="background: var(--apple-bg); padding: 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; flex-wrap: wrap;">
        <div>
            <p style="margin: 0 0 5px;"><strong>ชื่อ-สกุล:</strong> <?= htmlspecialchars($student['full_name']) ?></p>
            <p style="margin: 0 0 5px;"><strong>รหัสนักเรียน:</strong> <?= htmlspecialchars($student['student_code']) ?> <strong>เลขที่:</strong> <?= htmlspecialchars($student['student_number']) ?></p>
            <p style="margin: 0;"><strong>ห้องเรียน:</strong> <?= htmlspecialchars($student['room_name']) ?></p>
        </div>
        <div style="text-align: right;">
            <p style="margin: 0 0 5px;"><strong>คะแนนเริ่มต้น:</strong> <?= $student['initial_score'] ?></p>
            <h3 style="margin: 0; color: <?= $student['current_score'] < 60 ? 'var(--danger-color)' : 'var(--primary-color)' ?>;">
                คะแนนคงเหลือ: <?= $student['current_score'] ?>
            </h3>
        </div>
    </div>

    <?php if (empty($history)): ?>
        <p style="text-align: center; color: var(--text-muted); padding: 20px;">ไม่มีประวัติการหัก/เพิ่มคะแนน</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 120px;">วันที่</th>
                        <th>เหตุผล/พฤติกรรม</th>
                        <th style="width: 150px;">ผู้บันทึก</th>
                        <th style="width: 100px; text-align: center;">คะแนน</th>
                        <?php if ($can_manage_behavior): ?>
                        <th style="width: 100px; text-align: center;">จัดการ</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($h['record_date'])) ?></td>
                        <td><?= htmlspecialchars($h['reason']) ?></td>
                        <td><?= htmlspecialchars($h['teacher_name']) ?></td>
                        <td style="text-align: center; font-weight: bold; color: <?= $h['points'] < 0 ? 'var(--danger-color)' : ($h['points'] > 0 ? 'var(--success-color)' : '#999') ?>;">
                            <?= $h['points'] > 0 ? '+'.$h['points'] : $h['points'] ?>
                        </td>
                        <?php if ($can_manage_behavior): ?>
                        <td style="text-align: center;">
                            <form method="POST" style="margin:0;" onsubmit="return confirm('ยืนยันที่จะลบรายการนี้ และคืนคะแนน?');">
                                <input type="hidden" name="record_id" value="<?= $h['id'] ?>">
                                <button type="submit" name="delete_record" class="btn btn-sm btn-outline" style="color: #dc2626; border-color: #fecaca; padding: 4px 8px; font-size: 0.8rem;">
                                    🗑️ ลบ
                                </button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
