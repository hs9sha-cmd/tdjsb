<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$page_title = "จัดการรายวิชา";
$error = '';
$success = '';

// Handle Delete
if (!empty($_POST['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
    $stmt->execute([$_POST['delete_id']]);
    $success = "ลบข้อมูลวิชาเรียบร้อยแล้ว";
}

// Handle Bulk Delete
if (isset($_POST['bulk_delete_action']) && $_POST['bulk_delete_action'] === 'bulk' && !empty($_POST['selected_ids'])) {
    $ids = $_POST['selected_ids'];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM subjects WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $success = "ลบข้อมูลวิชาที่เลือกเรียบร้อยแล้ว";
}

// Handle Add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    $code = trim($_POST['subject_code']);
    $name = trim($_POST['subject_name']);
    $credit = trim($_POST['credit']);
    
    if ($code && $name) {
        $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_code=?");
        $stmt->execute([$code]);
        if ($stmt->fetch()) {
            $error = "รหัสวิชานี้มีในระบบแล้ว";
        } else {
            $ins = $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, credit) VALUES (?, ?, ?)");
            $ins->execute([$code, $name, $credit]);
            $success = "เพิ่มรายวิชาเรียบร้อย";
        }
    } else {
        $error = "กรุณากรอกรหัสและชื่อวิชา";
    }
}

// Fetch 
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_code ASC")->fetchAll();

include '../../includes/header.php';
?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
    <!-- Add Form -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 class="card-title" style="margin-bottom: 0;">เพิ่มรายวิชา</h2>
            <a href="admin_dashboard.php" class="btn btn-outline" style="color: #5e5ce6; border-color: #5e5ce6;">⬅️ แดชบอร์ด</a>
        </div>
        <?php if ($error): ?><div class="badge badge-danger mb-3" style="display:block; padding:10px; font-size:14px; text-align:left;"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="badge badge-success mb-3" style="display:block; padding:10px; font-size:14px; text-align:left;"><?= $success ?></div><?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label class="form-label">รหัสวิชา</label>
                <input type="text" name="subject_code" class="form-control" placeholder="เช่น ว31101" required>
            </div>
            <div class="form-group">
                <label class="form-label">ชื่อวิชา</label>
                <input type="text" name="subject_name" class="form-control" placeholder="เช่น ฟิสิกส์ 1" required>
            </div>
            <div class="form-group">
                <label class="form-label">หน่วยกิต</label>
                <input type="number" step="0.5" min="0" name="credit" class="form-control" value="1.0" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">เพิ่มรายวิชา</button>
        </form>
    </div>

    <!-- List -->
    <div class="card">
        <form method="POST" id="bulkDeleteForm">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 class="card-title" style="margin-bottom: 0;">รายการวิชาทั้งหมด</h2>
                <div>
                    <button type="button" class="btn btn-outline" style="font-size: 14px; color:var(--danger-color); border-color:var(--danger-color); margin-right: 10px; display: none;" id="btnDeleteSelected" onclick="if(confirm('ยืนยันลบวิชาที่เลือกทั้งหมด?')) { document.getElementById('bulkDeleteAction').value = 'bulk'; document.getElementById('bulkDeleteForm').submit(); }">🗑️ ลบที่เลือก</button>
                    <a href="admin_user_import.php?type=subjects" class="btn btn-success" style="font-size: 14px;">นำเข้าจาก CSV</a>
                </div>
            </div>
            
            <input type="hidden" name="bulk_delete_action" id="bulkDeleteAction" value="">
            <input type="hidden" name="delete_id" id="singleDeleteId" value="">
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;"><input type="checkbox" id="selectAll"></th>
                            <th>รหัสวิชา</th>
                            <th>ชื่อวิชา</th>
                            <th>หน่วยกิต</th>
                            <th style="text-align: right;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $s): ?>
                            <tr>
                                <td style="text-align: center;"><input type="checkbox" name="selected_ids[]" value="<?= $s['id'] ?>" class="subject-checkbox"></td>
                                <td><?= htmlspecialchars($s['subject_code']) ?></td>
                                <td><?= htmlspecialchars($s['subject_name']) ?></td>
                                <td><?= htmlspecialchars($s['credit']) ?></td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px; color:var(--danger-color); border-color:var(--danger-color);" onclick="if(confirm('ยืนยันลบวิชานี้?')) { document.getElementById('singleDeleteId').value = '<?= $s['id'] ?>'; document.getElementById('bulkDeleteForm').submit(); }">🗑️ ลบ</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <div class="mt-3">
            <a href="admin_settings.php" class="btn btn-outline">⬅️ กลับหน้าตั้งค่า</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.subject-checkbox');
    const btnDelete = document.getElementById('btnDeleteSelected');

    function updateDeleteBtn() {
        const checkedCount = document.querySelectorAll('.subject-checkbox:checked').length;
        if (checkedCount > 0) {
            btnDelete.style.display = 'inline-block';
        } else {
            btnDelete.style.display = 'none';
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
            updateDeleteBtn();
        });
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            if (!this.checked) {
                selectAll.checked = false;
            } else if (document.querySelectorAll('.subject-checkbox:checked').length === checkboxes.length) {
                selectAll.checked = true;
            }
            updateDeleteBtn();
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
