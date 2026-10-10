<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$page_title = "มอบหมายวิชาสอน";
$success = '';
$error = '';

$selected_teacher = $_GET['teacher_id'] ?? '';

// Handle Delete
if (isset($_POST['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM teacher_subjects WHERE id = ?");
    $stmt->execute([$_POST['delete_id']]);
    $success = "ลบการมอบหมายเรียบร้อยแล้ว";
}

// Handle Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $teacher_id = $_POST['teacher_id'];
    $subject_id = $_POST['subject_id'];
    $classroom_ids = $_POST['classroom_id'] ?? [];
    $is_elective = isset($_POST['is_elective']) ? 1 : 0;
    
    if ($teacher_id && $subject_id && !empty($classroom_ids)) {
        $added = 0;
        $skipped = 0;
        if (!is_array($classroom_ids)) $classroom_ids = [$classroom_ids]; // fallback
        
        foreach ($classroom_ids as $classroom_id) {
            $stmt = $pdo->prepare("SELECT id FROM teacher_subjects WHERE teacher_id=? AND subject_id=? AND classroom_id=?");
            $stmt->execute([$teacher_id, $subject_id, $classroom_id]);
            if ($stmt->fetch()) {
                $skipped++;
            } else {
                $ins = $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id, classroom_id, is_elective) VALUES (?, ?, ?, ?)");
                $ins->execute([$teacher_id, $subject_id, $classroom_id, $is_elective]);
                $added++;
            }
        }
        $success = "เพิ่มการมอบหมายสำเร็จแล้ว (บันทึกใหม่ $added ห้อง" . ($skipped > 0 ? ", ซ้ำ/ข้าม $skipped ห้อง" : "") . ")";
        $selected_teacher = $teacher_id; // Keep selected
    } else {
        $error = "กรุณาเลือกข้อมูลให้ครบถ้วน";
    }
}

// Fetch Master Data
$stmt = $pdo->query("SELECT u.id, u.full_name FROM users u JOIN user_roles ur ON u.id = ur.user_id JOIN roles r ON ur.role_id = r.id WHERE r.role_name = 'teacher' ORDER BY u.full_name ASC");
$teachers = $stmt->fetchAll();

$subjects = $pdo->query("SELECT id, subject_code, subject_name FROM subjects ORDER BY subject_code ASC")->fetchAll();
$classrooms = $pdo->query("SELECT id, grade_level, room_number, room_name FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC")->fetchAll();

// Fetch Current Assignments
$assignments = [];
if ($selected_teacher) {
    $stmt = $pdo->prepare("
        SELECT ts.id, ts.is_elective, s.subject_code, s.subject_name, c.room_name 
        FROM teacher_subjects ts
        JOIN subjects s ON ts.subject_id = s.id
        JOIN classrooms c ON ts.classroom_id = c.id
        WHERE ts.teacher_id = ?
        ORDER BY s.subject_code ASC, c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC
    ");
    $stmt->execute([$selected_teacher]);
    $assignments = $stmt->fetchAll();
}

include '../../includes/header.php';
?>

<div class="card mb-4">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="card-title" style="margin-bottom: 0;">จัดการมอบหมายวิชาสอน</h2>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="admin_dashboard.php" class="btn btn-outline" style="color: #5e5ce6; border-color: #5e5ce6;">⬅️ แดชบอร์ด</a>
            <a href="admin_user_import.php?type=assignments" class="btn btn-success">นำเข้าจาก CSV</a>
            <a href="admin_user_import.php?type=elective_enrollments" class="btn btn-primary">นำเข้านักเรียนวิชาเลือก (CSV)</a>
            <a href="admin_settings.php" class="btn btn-outline">⬅️ กลับ</a>
        </div>
    </div>
    
    <div style="margin-top: 20px; padding: 15px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: 8px;">
        <form method="GET" style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
            <label style="font-weight: bold; min-width: 80px;">เลือกครู:</label>
            <div style="flex: 1; min-width: 250px;">
                <select name="teacher_id" id="teacherSelect" class="form-control" onchange="this.form.submit()">
                    <option value="">-- พิมพ์ชื่อเพื่อค้นหาครูผู้สอน --</option>
                    <?php foreach($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $selected_teacher == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if ($success): ?>
    <div class="badge badge-success mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: left;">✅ <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="badge badge-danger mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: left;">❌ <?= $error ?></div>
<?php endif; ?>

<?php if ($selected_teacher): ?>
<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
    <!-- Add Form -->
    <div class="card">
        <h3 class="card-title">เพิ่มวิชาและห้องที่สอน</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="teacher_id" value="<?= htmlspecialchars($selected_teacher) ?>">
            
            <div class="form-group">
                <label class="form-label">รายวิชา</label>
                <select name="subject_id" id="subjectSelect" class="form-control" required>
                    <option value="">-- พิมพ์ชื่อ/รหัส เพื่อค้นหาวิชา --</option>
                    <?php foreach($subjects as $subj): ?>
                        <option value="<?= $subj['id'] ?>"><?= htmlspecialchars($subj['subject_code'] . ' ' . $subj['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">ห้องเรียน (เลือกได้หลายห้อง)</label>
                <select name="classroom_id[]" id="classroomSelect" class="form-control" multiple required>
                    <option value="">-- เลือกห้อง --</option>
                    <?php foreach($classrooms as $cls): ?>
                        <option value="<?= $cls['id'] ?>"><?= htmlspecialchars($cls['room_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="color: #64748b; margin-top: 5px; display: block;">* สามารถคลิกเลือกหลายห้อง หรือพิมพ์ค้นหาและกดเลือกได้เลย</small>
            </div>
            
            <div class="form-group" style="margin-top: 15px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="is_elective" value="1" style="width: 18px; height: 18px;">
                    <span style="font-weight: bold; color: var(--primary-color);">เป็นวิชาเลือก / เรียนรวมหลายห้อง</span>
                </label>
                <small style="display: block; margin-top: 4px; color: var(--text-muted); margin-left: 26px;">หากเลือก ห้องเรียนที่ระบุด้านบนจะเป็นเพียงห้องหลัก และจะสามารถเพิ่มนักเรียนจากห้องอื่นเข้ามาเรียนร่วมกันได้</small>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block mt-3">+ เพิ่มการมอบหมาย</button>
        </form>
    </div>

    <!-- Assignments List -->
    <div class="card">
        <h3 class="card-title">รายการวิชาที่สอนทั้งหมด</h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>รหัสวิชา</th>
                        <th>ชื่อวิชา</th>
                        <th>ห้องเรียน</th>
                        <th style="text-align: right;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td><?= htmlspecialchars($a['subject_code']) ?></td>
                            <td><?= htmlspecialchars($a['subject_name']) ?></td>
                            <td><?= htmlspecialchars($a['room_name']) ?></td>
                            <td style="text-align: right;">
                                <?php if ($a['is_elective']): ?>
                                    <a href="admin_elective_students.php?ts_id=<?= $a['id'] ?>" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px; color:var(--primary-color); border-color:var(--primary-color); margin-right: 5px;">👥 จัดการผู้เรียน</a>
                                <?php endif; ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('ยืนยันลบข้อมูลการสอนห้องนี้?');">
                                    <input type="hidden" name="delete_id" value="<?= $a['id'] ?>">
                                    <button type="submit" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px; color:var(--danger-color); border-color:var(--danger-color);">🗑️ ลบ</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($assignments)): ?>
                        <tr><td colspan="4" class="text-center" style="padding: 20px; color: var(--text-muted);">ยังไม่มีการมอบหมายวิชาสอน</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
    <div class="card" style="text-align: center; padding: 60px 20px; opacity: 0.6;">
        <div style="font-size: 4rem; margin-bottom: 20px;">👆</div>
        <h3>กรุณาเลือกครูผู้สอนเพื่อจัดการข้อมูล</h3>
    </div>
<?php endif; ?>

<!-- Tom Select -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    new TomSelect("#teacherSelect", {
        create: false,
        placeholder: "-- พิมพ์เพื่อค้นหาชื่อครู --"
    });
    
    if (document.getElementById('subjectSelect')) {
        new TomSelect("#subjectSelect", {
            create: false,
            placeholder: "-- พิมพ์เพื่อค้นหาวิชา --"
        });
    }
    
    if (document.getElementById('classroomSelect')) {
        new TomSelect("#classroomSelect", {
            create: false,
            placeholder: "-- พิมพ์เพื่อค้นหาหรือเลือกหลายห้อง --",
            plugins: ['remove_button']
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
