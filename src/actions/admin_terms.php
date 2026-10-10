<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$page_title = "จัดการปีการศึกษา";
$error = '';
$success = '';

// Handle Active Term Toggle
if (isset($_GET['set_active'])) {
    $id = $_GET['set_active'];
    $pdo->query("UPDATE academic_terms SET is_active = 0");
    $stmt = $pdo->prepare("UPDATE academic_terms SET is_active = 1 WHERE id = ?");
    $stmt->execute([$id]);
    $success = "ตั้งค่าเทอมปัจจุบันเรียบร้อยแล้ว";
}

// Handle Delete
if (isset($_POST['delete_id'])) {
    $delete_id = $_POST['delete_id'];
    // Check if it's active
    $stmt = $pdo->prepare("SELECT is_active FROM academic_terms WHERE id = ?");
    $stmt->execute([$delete_id]);
    $term = $stmt->fetch();
    
    if ($term && $term['is_active']) {
        $error = "ไม่สามารถลบปีการศึกษาที่กำลังเปิดใช้งาน (Active) อยู่ได้";
    } else {
        $stmt = $pdo->prepare("DELETE FROM academic_terms WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success = "ลบข้อมูลปีการศึกษาเรียบร้อยแล้ว";
    }
}

// Handle Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $year = trim($_POST['year_name']);
    $term = trim($_POST['term_number']);
    
    if ($year && $term) {
        $stmt = $pdo->prepare("SELECT id FROM academic_terms WHERE year_name=? AND term_number=?");
        $stmt->execute([$year, $term]);
        if ($stmt->fetch()) {
            $error = "ปีการศึกษาและเทอมนี้มีในระบบแล้ว";
        } else {
            // if it's the first one, make it active
            $check = $pdo->query("SELECT COUNT(*) FROM academic_terms")->fetchColumn();
            $is_active = ($check == 0) ? 1 : 0;
            
            $ins = $pdo->prepare("INSERT INTO academic_terms (year_name, term_number, is_active) VALUES (?, ?, ?)");
            $ins->execute([$year, $term, $is_active]);
            $success = "เพิ่มข้อมูลปีการศึกษาเรียบร้อย";
        }
    } else {
        $error = "กรุณากรอกข้อมูลให้ครบ";
    }
}

// Fetch Terms
$terms = $pdo->query("SELECT * FROM academic_terms ORDER BY year_name DESC, term_number DESC")->fetchAll();

include '../../includes/header.php';
?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
    <!-- Add Form -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 class="card-title" style="margin-bottom: 0;">เพิ่มปีการศึกษา/เทอม</h2>
            <a href="admin_dashboard.php" class="btn btn-outline" style="color: #5e5ce6; border-color: #5e5ce6;">⬅️ แดชบอร์ด</a>
        </div>
        <?php if ($error): ?><div class="badge badge-danger mb-3" style="display:block; padding:10px; font-size:14px; text-align:left;"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="badge badge-success mb-3" style="display:block; padding:10px; font-size:14px; text-align:left;"><?= $success ?></div><?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label class="form-label">ปีการศึกษา (พ.ศ.)</label>
                <input type="number" name="year_name" class="form-control" placeholder="เช่น 2567" required>
            </div>
            <div class="form-group">
                <label class="form-label">ภาคเรียนที่</label>
                <select name="term_number" class="form-control">
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">3 (ซัมเมอร์)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">เพิ่มข้อมูล</button>
        </form>
    </div>

    <!-- List -->
    <div class="card">
        <h2 class="card-title">รายการปีการศึกษาทั้งหมด</h2>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ปีการศึกษา</th>
                        <th>ภาคเรียนที่</th>
                        <th>สถานะ</th>
                        <th style="text-align: right;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($terms as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['year_name']) ?></td>
                            <td>เทอม <?= htmlspecialchars($t['term_number']) ?></td>
                            <td>
                                <?php if ($t['is_active']): ?>
                                    <span class="badge badge-success">เทอมปัจจุบัน (Active)</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#E2E8F0; color:#64748B;">ไม่ได้ใช้งาน</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; display: flex; justify-content: flex-end; gap: 8px;">
                                <?php if (!$t['is_active']): ?>
                                    <a href="?set_active=<?= $t['id'] ?>" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px; border-color:var(--success-color); color:var(--success-color);">✅ ตั้งเป็นเทอมปัจจุบัน</a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('ยืนยันการลบข้อมูลปีการศึกษานี้?');">
                                        <input type="hidden" name="delete_id" value="<?= $t['id'] ?>">
                                        <button type="submit" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px; color:var(--danger-color); border-color:var(--danger-color);">🗑️ ลบ</button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size: 12px; color: var(--text-muted); font-style: italic;">(เทอมปัจจุบัน ลบไม่ได้)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mt-3">
            <a href="admin_settings.php" class="btn btn-outline">⬅️ กลับหน้าตั้งค่า</a>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
