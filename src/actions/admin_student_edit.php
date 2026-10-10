<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

if (!isset($_GET['id'])) {
    header("Location: admin_students.php");
    exit();
}

$id = $_GET['id'];
$page_title = "แก้ไขข้อมูลนักเรียน";
$error = '';
$success = '';

// Fetch Student
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    header("Location: admin_students.php");
    exit();
}

// Fetch classrooms
$classrooms = $pdo->query("SELECT id, room_name FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $status = $_POST['status'];
    $classroom_id = empty($_POST['classroom_id']) ? null : $_POST['classroom_id'];
    $student_number = empty($_POST['student_number']) ? null : $_POST['student_number'];
    $gender = empty($_POST['gender']) ? null : $_POST['gender'];
    $religion = empty($_POST['religion']) ? 'Buddhism' : $_POST['religion'];

    // Handle photo upload
    $photo_path = $student['photo_path'] ?? null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $filename = $_FILES['photo']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $upload_dir = '../../assets/uploads/students/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $new_filename = $student['student_code'] . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_dir . $new_filename)) {
                $photo_path = 'assets/uploads/students/' . $new_filename;
            }
        }
    }

    if ($full_name) {
        try {
            $stmt = $pdo->prepare("UPDATE students SET full_name=?, classroom_id=?, status=?, student_number=?, gender=?, religion=?, photo_path=? WHERE id=?");
            $stmt->execute([$full_name, $classroom_id, $status, $student_number, $gender, $religion, $photo_path, $id]);
            
            $success = "บันทึกข้อมูลเรียบร้อยแล้ว";
            
            // Re-fetch
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
            $stmt->execute([$id]);
            $student = $stmt->fetch();
        } catch (Exception $e) {
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    } else {
        $error = "กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน";
    }
}

include '../../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">แก้ไขข้อมูลนักเรียน</h2>
        <a href="admin_students.php" class="btn btn-outline">⬅️ กลับ</a>
    </div>

    <?php if ($error): ?>
        <div class="badge badge-danger mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="badge badge-success mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $success ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div style="display: grid; grid-template-columns: 1fr 100px; gap: 15px;">
            <div class="form-group">
                <label class="form-label">รหัสประจำตัวนักเรียน - <small style="color:var(--danger-color);">แก้ไขไม่ได้</small></label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($student['student_code']) ?>" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">เลขที่</label>
                <input type="number" name="student_number" min="1" class="form-control" value="<?= htmlspecialchars($student['student_number'] ?? '') ?>">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
            <div class="form-group">
                <label class="form-label">ชื่อ-สกุล (ระบุคำนำหน้า) *</label>
                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($student['full_name']) ?>" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
            <div class="form-group">
                <label class="form-label">เพศ</label>
                <select name="gender" class="form-control">
                    <option value="">-- ไม่ระบุ --</option>
                    <option value="Male" <?= ($student['gender']??'') == 'Male' ? 'selected' : '' ?>>ชาย (Male)</option>
                    <option value="Female" <?= ($student['gender']??'') == 'Female' ? 'selected' : '' ?>>หญิง (Female)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">ศาสนา</label>
                <select name="religion" class="form-control">
                    <option value="Buddhism" <?= ($student['religion']??'Buddhism') == 'Buddhism' ? 'selected' : '' ?>>พุทธ (Buddhism)</option>
                    <option value="Islam" <?= ($student['religion']??'') == 'Islam' ? 'selected' : '' ?>>อิสลาม (Islam)</option>
                    <option value="Christianity" <?= ($student['religion']??'') == 'Christianity' ? 'selected' : '' ?>>คริสต์ (Christianity)</option>
                    <option value="Other" <?= ($student['religion']??'') == 'Other' ? 'selected' : '' ?>>อื่นๆ (Other)</option>
                </select>
            </div>
        </div>
        
        <div class="form-group" style="margin-top: 15px;">
            <label class="form-label">รูปถ่ายนักเรียน (อัปโหลดใหม่เพื่อเปลี่ยน)</label>
            <?php if (!empty($student['photo_path'])): ?>
                <div style="margin-bottom: 10px;">
                    <img src="../../<?= htmlspecialchars($student['photo_path']) ?>" alt="Photo" style="width: 100px; height: 100px; object-fit: cover; border-radius: 10px; border: 1px solid #ccc;">
                </div>
            <?php endif; ?>
            <input type="file" name="photo" class="form-control" accept="image/*">
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
                <label class="form-label">ห้องเรียนปัจจุบัน</label>
                <select name="classroom_id" class="form-control">
                    <option value="">-- ไม่ระบุ --</option>
                    <?php foreach ($classrooms as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $student['classroom_id'] == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['room_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">สถานะการเรียน</label>
                <select name="status" class="form-control">
                    <option value="active" <?= $student['status'] == 'active' ? 'selected' : '' ?>>กำลังศึกษา</option>
                    <option value="graduated" <?= $student['status'] == 'graduated' ? 'selected' : '' ?>>สำเร็จการศึกษา</option>
                    <option value="resigned" <?= $student['status'] == 'resigned' ? 'selected' : '' ?>>ลาออก/พ้นสภาพ</option>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary mt-3 btn-block">บันทึกข้อมูล</button>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
