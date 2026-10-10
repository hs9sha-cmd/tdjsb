<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$page_title = "เพิ่มข้อมูลนักเรียน";
$error = '';
$success = '';

// Fetch all classrooms for dropdown
$classrooms = $pdo->query("SELECT id, room_name FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_code = trim($_POST['student_code']);
    $full_name = trim($_POST['full_name']);
    $classroom_id = empty($_POST['classroom_id']) ? null : $_POST['classroom_id'];
    $student_number = empty($_POST['student_number']) ? null : $_POST['student_number'];
    
    // Auto detect gender if not explicitly selected
    $gender = empty($_POST['gender']) ? null : $_POST['gender'];
    if (!$gender) {
        if (strpos($full_name, 'เด็กชาย') === 0 || strpos($full_name, 'นาย') === 0 || strpos($full_name, 'ด.ช.') === 0) {
            $gender = 'Male';
        } elseif (strpos($full_name, 'เด็กหญิง') === 0 || strpos($full_name, 'นางสาว') === 0 || strpos($full_name, 'ด.ญ.') === 0) {
            $gender = 'Female';
        }
    }
    
    $religion = empty($_POST['religion']) ? 'Buddhism' : $_POST['religion'];

    if ($student_code && $full_name) {
        $stmt = $pdo->prepare("SELECT id FROM students WHERE student_code = ?");
        $stmt->execute([$student_code]);
        if ($stmt->fetch()) {
            $error = "รหัสประจำตัวนี้มีในระบบแล้ว";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO students (student_code, full_name, classroom_id, student_number, gender, religion) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$student_code, $full_name, $classroom_id, $student_number, $gender, $religion]);
                $new_id = $pdo->lastInsertId();

                // Auto-initialize behavior score (100) for active term
                $stmt_term = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
                $active_term_id = $stmt_term ? $stmt_term->fetchColumn() : null;
                if ($new_id && $active_term_id) {
                    $pdo->prepare("INSERT IGNORE INTO student_behaviors (student_id, academic_term_id, initial_score, current_score) VALUES (?, ?, 100, 100)")->execute([$new_id, $active_term_id]);
                }

                header("Location: admin_students.php");
                exit();
            } catch (Exception $e) {
                $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
            }
        }
    } else {
        $error = "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน";
    }
}

include '../../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">เพิ่มข้อมูลนักเรียน</h2>
        <a href="admin_students.php" class="btn btn-outline">⬅️ กลับ</a>
    </div>

    <?php if ($error): ?>
        <div class="badge badge-danger mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <div style="display: grid; grid-template-columns: 1fr 100px; gap: 15px;">
            <div class="form-group">
                <label class="form-label">รหัสประจำตัวนักเรียน *</label>
                <input type="text" name="student_code" class="form-control" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">เลขที่</label>
                <input type="number" name="student_number" min="1" class="form-control">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
            <div class="form-group">
                <label class="form-label">ชื่อ-สกุล (ระบุคำนำหน้า) *</label>
                <input type="text" name="full_name" class="form-control" placeholder="เช่น ด.ช.ใจดี เรียนเก่ง" required>
                <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">* ระบบจะคำนวณเพศอัตโนมัติจากคำนำหน้าชื่อ แต่สามารถเลือกเองได้ด้านล่าง</small>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
            <div class="form-group">
                <label class="form-label">เพศ (ตัวเลือก)</label>
                <select name="gender" class="form-control">
                    <option value="">-- คำนวณอัตโนมัติ --</option>
                    <option value="Male">ชาย (Male)</option>
                    <option value="Female">หญิง (Female)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">ศาสนา</label>
                <select name="religion" class="form-control">
                    <option value="Buddhism">พุทธ (Buddhism)</option>
                    <option value="Islam">อิสลาม (Islam)</option>
                    <option value="Christianity">คริสต์ (Christianity)</option>
                    <option value="Other">อื่นๆ (Other)</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">ห้องเรียนปัจจุบัน</label>
            <select name="classroom_id" class="form-control">
                <option value="">-- ไม่ระบุ --</option>
                <?php foreach ($classrooms as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['room_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" class="btn btn-primary mt-3 btn-block">บันทึกข้อมูล</button>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
