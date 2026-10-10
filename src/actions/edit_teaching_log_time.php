<?php
// src/actions/edit_teaching_log_time.php
require_once '../../config/database.php';
require_once '../../config/auth.php';

// Users must be either admin or teacher
if (!isset($_SESSION['roles'])) {
    getUserRoles($pdo, $_SESSION['user_id']);
}
$is_admin = in_array('admin', $_SESSION['roles'] ?? []);
$is_teacher = in_array('teacher', $_SESSION['roles'] ?? []);

if (!$is_admin && !$is_teacher) {
    die("ไม่มีสิทธิ์เข้าถึงหน้านี้");
}

$error = '';
$success = '';
$log_id = $_GET['log_id'] ?? ($_POST['log_id'] ?? null);
$user_id = $_SESSION['user_id'];

if (!$log_id) {
    die("ไม่พบข้อมูลอ้างอิง (Invalid request)");
}

// Fetch the log details
$stmt = $pdo->prepare("
    SELECT tl.*, s.subject_name, s.subject_code, c.room_name, u.full_name as teacher_name
    FROM teaching_logs tl
    LEFT JOIN subjects s ON tl.subject_id = s.id
    LEFT JOIN classrooms c ON tl.classroom_id = c.id
    LEFT JOIN users u ON tl.teacher_id = u.id
    WHERE tl.id = ?
");
$stmt->execute([$log_id]);
$log = $stmt->fetch();

if (!$log) {
    die("ไม่พบข้อมูลประวัติการสอน");
}

// Security: If not admin, must be the owner of the log
if (!$is_admin && $log['teacher_id'] != $user_id) {
    die("คุณสามารถแก้ไขได้เฉพาะประวัติการสอนของตนเองเท่านั้น");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_date = $_POST['log_date'] ?? '';
    $new_period = $_POST['period'] ?? '';

    if (empty($new_date) || empty($new_period)) {
        $error = "กรุณากรอกวันที่และคาบให้ครบถ้วน";
    } else {
        // Validation: Check overlaps
        $overlap = false;
        
        // 1. Check if the teacher already taught something else at this new time
        $stmt_check_teacher = $pdo->prepare("SELECT id FROM teaching_logs WHERE teacher_id = ? AND log_date = ? AND period = ? AND id != ?");
        $stmt_check_teacher->execute([$log['teacher_id'], $new_date, $new_period, $log_id]);
        if ($stmt_check_teacher->fetch()) {
            $overlap = true;
            $error = "คุณ/ครูผู้สอนท่านนี้มีการบันทึกการสอนในวันที่และคาบนี้ไปแล้ว";
        }

        // 2. Check if the room is already occupied at this new time by ANY teacher
        if (!$overlap) {
            $stmt_check_room = $pdo->prepare("SELECT id FROM teaching_logs WHERE classroom_id = ? AND log_date = ? AND period = ? AND id != ?");
            $stmt_check_room->execute([$log['classroom_id'], $new_date, $new_period, $log_id]);
            if ($stmt_check_room->fetch()) {
                $overlap = true;
                $error = "ไม่สามารถย้ายได้ เนื่องจากห้องเรียนนี้ถูกบันทึกการสอนในคาบดังกล่าวไปแล้วโดยครูท่านอื่น";
            }
        }

        // If no overlap, execute UPDATE
        if (!$overlap) {
            try {
                $pdo->beginTransaction();
                $stmt_update = $pdo->prepare("UPDATE teaching_logs SET log_date = ?, period = ? WHERE id = ?");
                $stmt_update->execute([$new_date, $new_period, $log_id]);
                $pdo->commit();
                
                $success = "ย้ายวันที่/คาบสอนเรียบร้อยแล้ว";
                // Update local variable to reflect new values on the page
                $log['log_date'] = $new_date;
                $log['period'] = $new_period;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "เกิดข้อผิดพลาดในการบันทึก: " . $e->getMessage();
            }
        }
    }
}

include '../../includes/header.php';
?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 class="page-title" style="margin: 0;">🔄 ย้ายวันที่/คาบสอน</h2>
            <a href="<?= $is_admin ? 'admin_teaching_logs.php' : 'teacher_home.php' ?>" class="btn btn-outline">⬅️ กลับ</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px; padding: 15px; background: #fee2e2; border-left: 4px solid #ef4444; color: #b91c1c;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success" style="margin-bottom: 20px; padding: 15px; background: #dcfce7; border-left: 4px solid #22c55e; color: #15803d;">
                <?= htmlspecialchars($success) ?>
                <br>
                <a href="<?= $is_admin ? 'admin_teaching_logs.php' : 'teacher_home.php' ?>" style="text-decoration:underline; font-weight:bold; color:inherit;">คลิกที่นี่เพื่อกลับไปหน้ารวม</a>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 600px;">
            <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid var(--border-color);">
                <h4 style="margin-top:0; margin-bottom: 10px; color: var(--text-color);">รายละเอียดการสอนเดิม</h4>
                <p style="margin: 5px 0;"><strong>ผู้สอน:</strong> <?= htmlspecialchars($log['teacher_name']) ?></p>
                <p style="margin: 5px 0;"><strong>วิชา:</strong> <?= htmlspecialchars($log['subject_code'] . ' ' . $log['subject_name']) ?></p>
                <p style="margin: 5px 0;"><strong>ห้องเรียน:</strong> <?= htmlspecialchars($log['room_name'] ?? '-') ?></p>
            </div>

            <form method="POST">
                <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                
                <div class="form-group">
                    <label>วันที่สอนใหม่ <span style="color:red;">*</span></label>
                    <input type="date" name="log_date" class="form-control" value="<?= htmlspecialchars($log['log_date']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>คาบใหม่ <span style="color:red;">*</span></label>
                    <select name="period" class="form-control" required>
                        <?php for($i=1; $i<=10; $i++): ?>
                            <option value="<?= $i ?>" <?= $log['period'] == $i ? 'selected' : '' ?>>คาบ <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" onclick="return confirm('คุณแน่ใจหรือไม่ที่จะย้ายคาบการสอนนี้? (ข้อมูลเช็คชื่อและประวัติจะย้ายตามไปทั้งหมด)');">💾 บันทึกการย้าย</button>
            </form>
        </div>

<?php include '../../includes/footer.php'; ?>
