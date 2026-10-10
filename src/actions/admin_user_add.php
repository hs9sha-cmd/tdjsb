<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$page_title = "เพิ่มบุคลากร";
$error = '';
$success = '';

// Fetch all possible roles
$all_roles = $pdo->query("SELECT id, role_name, description FROM roles")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $full_name = trim($_POST['full_name']);
    $department = trim($_POST['department']);
    $advisor_classroom = empty($_POST['advisor_classroom']) ? null : $_POST['advisor_classroom'];
    $selected_roles = $_POST['roles'] ?? [];

    if ($username && $password && $full_name) {
        // Check uniqueness 
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = "ชื่อผู้ใช้นี้มีในระบบแล้ว";
        } else {
            try {
                $pdo->beginTransaction();
                
                $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, department, advisor_classroom) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $pass_hash, $full_name, $department, $advisor_classroom]);
                $new_user_id = $pdo->lastInsertId();

                // Insert roles
                if (!empty($selected_roles)) {
                    $insert_roles = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    foreach ($selected_roles as $role_id) {
                        $insert_roles->execute([$new_user_id, $role_id]);
                    }
                }

                $pdo->commit();
                header("Location: admin_users.php");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
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
        <h2 class="card-title" style="margin-bottom: 0;">เพิ่มบุคลากรระบบ</h2>
        <a href="admin_users.php" class="btn btn-outline">⬅️ กลับ</a>
    </div>

    <?php if ($error): ?>
        <div class="badge badge-danger mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
                <label class="form-label">ชื่อผู้ใช้งาน (Username) *</label>
                <input type="text" name="username" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">รหัสผ่าน (Password) *</label>
                <input type="password" name="password" class="form-control" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
            <div class="form-group">
                <label class="form-label">ชื่อ-สกุล (ระบุคำนำหน้า) *</label>
                <input type="text" name="full_name" class="form-control" placeholder="เช่น นายสมชาย ใจดี" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
                <label class="form-label">กลุ่มสาระการเรียนรู้</label>
                <select name="department" class="form-control">
                    <option value="">-- ไม่ระบุ --</option>
                    <option value="วิทยาศาสตร์และเทคโนโลยี">วิทยาศาสตร์และเทคโนโลยี</option>
                    <option value="ภาษาไทย">ภาษาไทย</option>
                    <option value="คณิตศาสตร์">คณิตศาสตร์</option>
                    <option value="สังคมศึกษา ศาสนา และวัฒนธรรม">สังคมศึกษา ศาสนา และวัฒนธรรม</option>
                    <option value="สุขศึกษาและพลศึกษา">สุขศึกษาและพลศึกษา</option>
                    <option value="ศิลปะ">ศิลปะ</option>
                    <option value="การงานอาชีพ">การงานอาชีพ</option>
                    <option value="ภาษาต่างประเทศ">ภาษาต่างประเทศ</option>
                    <option value="แนะแนว">แนะแนว</option>
                    <option value="บริหาร">บริหาร</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">ห้องเรียนที่ปรึกษา</label>
                <select name="advisor_classroom" class="form-control">
                    <option value="">-- ไม่มี --</option>
                    <?php 
                    for ($m = 1; $m <= 6; $m++) {
                        for ($r = 1; $r <= 13; $r++) {
                            $room = "ม.{$m}/{$r}";
                            echo "<option value=\"{$room}\">{$room}</option>";
                        }
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="form-group" style="padding: 15px; border: 1px dashed var(--border-color); border-radius: 6px; margin-top: 10px;">
            <label class="form-label" style="font-weight: 600;">กำหนดสิทธิ์การใช้งาน (เลือกได้มากกว่า 1)</label>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                <?php foreach ($all_roles as $role): ?>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="roles[]" value="<?= $role['id'] ?>"> 
                        <span style="font-weight: 500; text-transform: uppercase;">
                            <?= htmlspecialchars($role['role_name']) ?>
                        </span>
                        <span style="color: var(--text-muted); font-size: 0.8rem;">(<?= htmlspecialchars($role['description']) ?>)</span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <button type="submit" class="btn btn-primary mt-3 btn-block">บันทึกข้อมูล</button>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
