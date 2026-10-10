<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

if (!isset($_GET['id'])) {
    header("Location: admin_users.php");
    exit();
}

$id = $_GET['id'];
$page_title = "แก้ไขข้อมูลบุคลากร";
$error = '';
$success = '';

// Fetch User
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: admin_users.php");
    exit();
}

// Fetch all possible roles
$all_roles = $pdo->query("SELECT id, role_name, description FROM roles")->fetchAll();

// Fetch current user roles
$stmt = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
$stmt->execute([$id]);
$current_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $department = trim($_POST['department']);
    $advisor_classroom = empty($_POST['advisor_classroom']) ? null : $_POST['advisor_classroom'];
    $password = $_POST['password']; // optional
    $selected_roles = $_POST['roles'] ?? [];

    if ($full_name) {
        try {
            $pdo->beginTransaction();
            
            if (!empty($password)) {
                $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET full_name=?, department=?, advisor_classroom=?, password_hash=?, force_password_change=1 WHERE id=?");
                $stmt->execute([$full_name, $department, $advisor_classroom, $pass_hash, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET full_name=?, department=?, advisor_classroom=? WHERE id=?");
                $stmt->execute([$full_name, $department, $advisor_classroom, $id]);
            }

            // Update roles (delete all and re-insert)
            // Prevent removing admin role from oneself
            if ($id == $_SESSION['user_id'] && !in_array(1, $selected_roles)) {
                // Force admin role for self to prevent locking out
                $selected_roles[] = 1;
            }

            $pdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$id]);
            
            if (!empty($selected_roles)) {
                $insert_roles = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($selected_roles as $role_id) {
                    $insert_roles->execute([$id, $role_id]);
                }
            }

            $pdo->commit();
            $success = "บันทึกข้อมูลเรียบร้อยแล้ว";
            
            // Re-fetch current user roles for display
            $stmt = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
            $stmt->execute([$id]);
            $current_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

        } catch (Exception $e) {
            $pdo->rollBack();
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
        <h2 class="card-title" style="margin-bottom: 0;">แก้ไขข้อมูลบุคลากร</h2>
        <a href="admin_users.php" class="btn btn-outline">⬅️ กลับ</a>
    </div>

    <?php if ($error): ?>
        <div class="badge badge-danger mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="badge badge-success mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $success ?></div>
    <?php endif; ?>

    <form method="POST">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
                <label class="form-label">ชื่อผู้ใช้งาน (Username) - <small style="color:var(--danger-color);">แก้ไขไม่ได้</small></label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">ตั้งรหัสผ่านใหม่ (ปล่อยว่างหากไม่เปลี่ยน)</label>
                <input type="password" name="password" class="form-control">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
            <div class="form-group">
                <label class="form-label">ชื่อ-สกุล (ระบุคำนำหน้า) *</label>
                <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
                <label class="form-label">กลุ่มสาระการเรียนรู้</label>
                <select name="department" class="form-control">
                    <option value="">-- ไม่ระบุ --</option>
                    <option value="วิทยาศาสตร์และเทคโนโลยี" <?= ($user['department'] ?? '') == 'วิทยาศาสตร์และเทคโนโลยี' ? 'selected' : '' ?>>วิทยาศาสตร์และเทคโนโลยี</option>
                    <option value="ภาษาไทย" <?= ($user['department'] ?? '') == 'ภาษาไทย' ? 'selected' : '' ?>>ภาษาไทย</option>
                    <option value="คณิตศาสตร์" <?= ($user['department'] ?? '') == 'คณิตศาสตร์' ? 'selected' : '' ?>>คณิตศาสตร์</option>
                    <option value="สังคมศึกษา ศาสนา และวัฒนธรรม" <?= ($user['department'] ?? '') == 'สังคมศึกษา ศาสนา และวัฒนธรรม' ? 'selected' : '' ?>>สังคมศึกษา ศาสนา และวัฒนธรรม</option>
                    <option value="สุขศึกษาและพลศึกษา" <?= ($user['department'] ?? '') == 'สุขศึกษาและพลศึกษา' ? 'selected' : '' ?>>สุขศึกษาและพลศึกษา</option>
                    <option value="ศิลปะ" <?= ($user['department'] ?? '') == 'ศิลปะ' ? 'selected' : '' ?>>ศิลปะ</option>
                    <option value="การงานอาชีพ" <?= ($user['department'] ?? '') == 'การงานอาชีพ' ? 'selected' : '' ?>>การงานอาชีพ</option>
                    <option value="ภาษาต่างประเทศ" <?= ($user['department'] ?? '') == 'ภาษาต่างประเทศ' ? 'selected' : '' ?>>ภาษาต่างประเทศ</option>
                    <option value="แนะแนว" <?= ($user['department'] ?? '') == 'แนะแนว' ? 'selected' : '' ?>>แนะแนว</option>
                    <option value="บริหาร" <?= ($user['department'] ?? '') == 'บริหาร' ? 'selected' : '' ?>>บริหาร</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">ห้องเรียนที่ปรึกษา</label>
                <select name="advisor_classroom" class="form-control">
                    <option value="">-- ไม่มี --</option>
                    <?php 
                    $current_room = $user['advisor_classroom'] ?? '';
                    for ($m = 1; $m <= 6; $m++) {
                        for ($r = 1; $r <= 13; $r++) {
                            $room = "ม.{$m}/{$r}";
                            $selected = ($room == $current_room) ? 'selected' : '';
                            echo "<option value=\"{$room}\" {$selected}>{$room}</option>";
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
                        <input type="checkbox" name="roles[]" value="<?= $role['id'] ?>" <?= in_array($role['id'], $current_roles) ? 'checked' : '' ?>> 
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
