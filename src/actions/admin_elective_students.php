<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$ts_id = $_GET['ts_id'] ?? '';
if (!$ts_id) {
    header("Location: admin_assign_subjects.php");
    exit();
}

$stmt = $pdo->prepare("
    SELECT ts.id, s.subject_code, s.subject_name, u.full_name as teacher_name, c.room_name as base_room
    FROM teacher_subjects ts
    JOIN subjects s ON ts.subject_id = s.id
    JOIN users u ON ts.teacher_id = u.id
    JOIN classrooms c ON ts.classroom_id = c.id
    WHERE ts.id = ? AND ts.is_elective = 1
");
$stmt->execute([$ts_id]);
$ts_info = $stmt->fetch();

if (!$ts_info) {
    die("ไม่พบข้อมูลวิชาเลือกที่ระบุ");
}

$page_title = "จัดการผู้เรียนวิชาเลือก";
$success = '';
$error = '';

// Handle Add Students
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_students') {
    if (!empty($_POST['student_ids'])) {
        $ins = $pdo->prepare("INSERT IGNORE INTO elective_enrollments (teacher_subject_id, student_id) VALUES (?, ?)");
        $added = 0;
        foreach ($_POST['student_ids'] as $sid) {
            $ins->execute([$ts_id, $sid]);
            if ($ins->rowCount() > 0) $added++;
        }
        $success = "เพิ่มนักเรียนเข้ากลุ่มสำเร็จ $added คน";
    } else {
        $error = "กรุณาเลือกนักเรียนที่ต้องการเพิ่ม";
    }
}

// Handle Remove
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_student') {
    $del = $pdo->prepare("DELETE FROM elective_enrollments WHERE teacher_subject_id = ? AND student_id = ?");
    $del->execute([$ts_id, $_POST['student_id']]);
    $success = "นำนักเรียนออกจากกลุ่มเรียบร้อยแล้ว";
}

// Fetch enrolled students
$stmt_enrolled = $pdo->prepare("
    SELECT e.student_id, s.student_code, s.full_name, s.student_number, c.room_name
    FROM elective_enrollments e
    JOIN students s ON e.student_id = s.id
    LEFT JOIN classrooms c ON s.classroom_id = c.id
    WHERE e.teacher_subject_id = ?
    ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC, CAST(s.student_number AS UNSIGNED) ASC
");
$stmt_enrolled->execute([$ts_id]);
$enrolled = $stmt_enrolled->fetchAll();

// Handle search for new students
$search_classroom = $_GET['search_classroom'] ?? '';
$search_students = [];

if ($search_classroom) {
    // Get students in the classroom NOT enrolled yet
    $stmt_search = $pdo->prepare("
        SELECT s.id, s.student_code, s.full_name, s.student_number
        FROM students s
        WHERE s.classroom_id = ? 
        AND s.id NOT IN (SELECT student_id FROM elective_enrollments WHERE teacher_subject_id = ?)
        ORDER BY CAST(s.student_number AS UNSIGNED) ASC
    ");
    $stmt_search->execute([$search_classroom, $ts_id]);
    $search_students = $stmt_search->fetchAll();
}

$classrooms = $pdo->query("SELECT id, room_name FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC")->fetchAll();

include '../../includes/header.php';
?>

<div class="card mb-4">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="card-title" style="margin-bottom: 0;">จัดการผู้เรียน: <?= htmlspecialchars($ts_info['subject_name']) ?></h2>
        <a href="admin_assign_subjects.php" class="btn btn-outline">⬅️ กลับหน้ามอบหมายวิชา</a>
    </div>
    
    <div style="margin-top: 15px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; flex-wrap: wrap; gap: 20px;">
        <div><strong>รหัสวิชา:</strong> <?= htmlspecialchars($ts_info['subject_code']) ?></div>
        <div><strong>ครูผู้สอน:</strong> <?= htmlspecialchars($ts_info['teacher_name']) ?></div>
        <div><strong>ห้องหลัก:</strong> <?= htmlspecialchars($ts_info['base_room']) ?></div>
        <div><strong>จำนวนผู้เรียนปัจจุบัน:</strong> <?= count($enrolled) ?> คน</div>
    </div>
</div>

<?php if ($success): ?>
    <div class="badge badge-success mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: left;">✅ <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="badge badge-danger mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: left;">❌ <?= $error ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
    <!-- Add Students Form -->
    <div class="card">
        <h3 class="card-title">เพิ่มผู้เรียนจากห้องอื่น</h3>
        <form method="GET" class="mb-3">
            <input type="hidden" name="ts_id" value="<?= htmlspecialchars($ts_id) ?>">
            <div class="form-group">
                <label class="form-label">เลือกห้องเรียนเพื่อดึงรายชื่อ</label>
                <div style="display: flex; gap: 10px;">
                    <select name="search_classroom" class="form-control" required>
                        <option value="">-- เลือกห้อง --</option>
                        <?php foreach($classrooms as $cls): ?>
                            <option value="<?= $cls['id'] ?>" <?= $search_classroom == $cls['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cls['room_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-secondary">ค้นหา</button>
                </div>
            </div>
        </form>

        <?php if ($search_classroom): ?>
            <?php if (empty($search_students)): ?>
                <div class="alert alert-info" style="padding: 10px; background: #e0f2fe; color: #0369a1; border-radius: 4px;">นักเรียนทุกคนในห้องนี้ถูกเพิ่มเข้ากลุ่มแล้ว หรือไม่มีนักเรียนในห้อง</div>
            <?php else: ?>
                <form method="POST" onsubmit="return confirm('ยืนยันเพิ่มนักเรียนที่เลือกเข้ากลุ่มเรียนนี้?');">
                    <input type="hidden" name="action" value="add_students">
                    
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <label style="cursor: pointer;"><input type="checkbox" id="selectAllSearch" style="margin-right: 5px;"> เลือกทั้งหมด</label>
                    </div>
                    
                    <div style="max-height: 400px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 4px; padding: 10px;">
                        <?php foreach ($search_students as $s): ?>
                            <label style="display: block; padding: 8px; border-bottom: 1px solid #f1f5f9; cursor: pointer;">
                                <input type="checkbox" name="student_ids[]" value="<?= $s['id'] ?>" class="search-checkbox" style="margin-right: 10px;">
                                เลขที่ <?= htmlspecialchars($s['student_number'] ?? '-') ?> : <?= htmlspecialchars($s['full_name']) ?> (<?= htmlspecialchars($s['student_code']) ?>)
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block mt-3">+ เพิ่มเข้ากลุ่มวิชาเลือก</button>
                </form>
                <script>
                document.getElementById('selectAllSearch').addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.search-checkbox');
                    checkboxes.forEach(cb => cb.checked = this.checked);
                });
                </script>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Enrolled Students List -->
    <div class="card">
        <h3 class="card-title">รายชื่อผู้เรียนปัจจุบัน</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ห้อง</th>
                        <th>เลขที่</th>
                        <th>รหัสนักเรียน</th>
                        <th>ชื่อ-สกุล</th>
                        <th style="text-align: right;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enrolled as $e): ?>
                        <tr>
                            <td><?= htmlspecialchars($e['room_name']) ?></td>
                            <td><?= htmlspecialchars($e['student_number'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($e['student_code']) ?></td>
                            <td><?= htmlspecialchars($e['full_name']) ?></td>
                            <td style="text-align: right;">
                                <form method="POST" style="display:inline;" onsubmit="return confirm('ยืนยันนำนักเรียนออกจากกลุ่ม?');">
                                    <input type="hidden" name="action" value="remove_student">
                                    <input type="hidden" name="student_id" value="<?= $e['student_id'] ?>">
                                    <button type="submit" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px; color:var(--danger-color); border-color:var(--danger-color);">❌ นำออก</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($enrolled)): ?>
                        <tr><td colspan="5" class="text-center" style="padding: 20px; color: var(--text-muted);">ยังไม่มีผู้เรียนในกลุ่มวิชานี้</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
