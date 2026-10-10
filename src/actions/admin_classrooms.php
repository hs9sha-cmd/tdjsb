<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$page_title = "จัดการห้องเรียน";
$error = '';
$success = '';

// Handle Delete
if (isset($_POST['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM classrooms WHERE id = ?");
    $stmt->execute([$_POST['delete_id']]);
    $success = "ลบข้อมูลห้องเรียนเรียบร้อยแล้ว";
}

// Handle Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $grade = trim($_POST['grade_level']);
    $room = trim($_POST['room_number']);
    $name = $grade . "/" . $room;
    
    if ($grade && $room) {
        $stmt = $pdo->prepare("SELECT id FROM classrooms WHERE grade_level=? AND room_number=?");
        $stmt->execute([$grade, $room]);
        if ($stmt->fetch()) {
            $error = "ห้องเรียนนี้มีในระบบแล้ว";
        } else {
            $ins = $pdo->prepare("INSERT INTO classrooms (grade_level, room_number, room_name) VALUES (?, ?, ?)");
            $ins->execute([$grade, $room, $name]);
            $success = "เพิ่มห้องเรียน $name เรียบร้อย";
        }
    } else {
        $error = "กรุณากรอกข้อมูลให้ครบ";
    }
}

$active_term = $pdo->query("SELECT id, year_name, term_number FROM academic_terms WHERE is_active = 1")->fetch();
$active_term_id = $active_term ? $active_term['id'] : null;

$sql = "
    SELECT id, grade_level, room_number, room_name
    FROM classrooms
    ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC
";
$stmt = $pdo->query($sql);
$classrooms = $stmt->fetchAll();

include '../../includes/header.php';
?>

<div style="display: grid; grid-template-columns: 1fr 3fr; gap: 20px;">
    <!-- Add Form -->
    <div class="card">
        <h2 class="card-title">เพิ่มห้องเรียนใหม่</h2>
        <?php if ($error): ?><div class="badge badge-danger mb-3" style="display:block; padding:10px; font-size:14px; text-align:left;"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="badge badge-success mb-3" style="display:block; padding:10px; font-size:14px; text-align:left;"><?= $success ?></div><?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label class="form-label">ระดับชั้น</label>
                <select name="grade_level" class="form-control">
                    <option value="ม.1">ม.1</option>
                    <option value="ม.2">ม.2</option>
                    <option value="ม.3">ม.3</option>
                    <option value="ม.4">ม.4</option>
                    <option value="ม.5">ม.5</option>
                    <option value="ม.6">ม.6</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">ห้องที่</label>
                <input type="number" min="1" name="room_number" class="form-control" placeholder="เช่น 1" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">เพิ่มห้องเรียน</button>
        </form>
        <hr style="margin:20px 0; border:0; border-top:1px solid var(--border-color);">
        <a href="admin_settings.php" class="btn btn-outline btn-block">⬅️ กลับหน้าตั้งค่า</a>
    </div>

    <!-- List & Assign Form -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 class="card-title" style="margin-bottom: 0;">รายการห้องเรียนทั้งหมด</h2>
        </div>
        
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID ห้อง</th>
                        <th>ชื่อห้อง</th>
                        <th style="text-align: right;">ลบห้อง</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classrooms as $c): ?>
                        <tr>
                            <td><?= $c['id'] ?></td>
                            <td style="font-weight: 600;"><?= htmlspecialchars($c['room_name']) ?></td>
                            <td style="text-align: right;">
                                <form method="POST" style="display:inline;" onsubmit="return confirm('ยืนยันลบห้องเรียนนี้? ข้อมูลนักเรียนในห้องจะได้รับผลกระทบ');">
                                    <input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px; color:var(--danger-color); border-color:var(--danger-color);">🗑️ ลบ</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
