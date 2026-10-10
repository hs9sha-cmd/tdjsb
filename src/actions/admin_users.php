<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

// --- Auto-migrate assistant role ---
try {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE role_name = 'assistant'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO roles (role_name, description) VALUES ('assistant', 'ผู้ช่วยครู (สิทธิ์เฉพาะเช็คชื่อเข้าแถว)')")->execute();
    } else {
        $pdo->prepare("UPDATE roles SET description = 'ผู้ช่วยครู (สิทธิ์เฉพาะเช็คชื่อเข้าแถว)' WHERE role_name = 'assistant'")->execute();
    }
} catch (Exception $e) {}
// -----------------------------------

// --- Bulk Export Selected (XLSX) ---
if (isset($_POST['bulk_export']) && !empty($_POST['selected_ids'])) {
    $ids = $_POST['selected_ids'];
    $inQuery = implode(',', array_fill(0, count($ids), '?'));
    
    $sql_export = "
        SELECT u.username, u.full_name, u.department, u.advisor_classroom,
               GROUP_CONCAT(r.role_name SEPARATOR ', ') as roles
        FROM users u
        LEFT JOIN user_roles ur ON u.id = ur.user_id
        LEFT JOIN roles r ON ur.role_id = r.id
        WHERE u.id IN ($inQuery)
        GROUP BY u.id
        ORDER BY u.id ASC
    ";
    $stmt_export = $pdo->prepare($sql_export);
    $stmt_export->execute($ids);
    $export_users = $stmt_export->fetchAll(PDO::FETCH_ASSOC);
    
    require_once '../../includes/SimpleXLSXGen.php';
    
    $excel_data = [];
    $excel_data[] = ['Username', 'ชื่อ-สกุล', 'กลุ่มสาระการเรียนรู้', 'ห้องที่ปรึกษา', 'Roles'];
    
    foreach ($export_users as $u) {
        $excel_data[] = [
            $u['username'],
            $u['full_name'],
            $u['department'],
            $u['advisor_classroom'],
            $u['roles']
        ];
    }
    
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray( $excel_data );
    $xlsx->downloadAs('selected_teachers_' . date('Y-m-d_His') . '.xlsx');
    exit();
}
// -----------------------------------

$page_title = "จัดการข้อมูลครู";
include '../../includes/header.php';

// Handle Single Delete
if (isset($_POST['delete_id'])) {
    $delete_id = $_POST['delete_id'];
    if ($delete_id != $_SESSION['user_id']) { // Check can't delete self
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success_msg = "ลบข้อมูลบุคลากรเรียบร้อยแล้ว";
    } else {
        $error_msg = "ไม่สามารถลบบัญชีของตนเองได้";
    }
}

// Handle Bulk Delete
if (isset($_POST['bulk_delete']) && isset($_POST['selected_ids'])) {
    $ids = $_POST['selected_ids'];
    if (!empty($ids)) {
        // filter out self
        $ids = array_filter($ids, function($id) { return $id != $_SESSION['user_id']; });
        if (!empty($ids)) {
            $inQuery = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM users WHERE id IN ($inQuery) AND username != 'admin'");
            $stmt->execute($ids);
            $success_msg = "ลบข้อมูลครูที่เลือกจำนวน " . count($ids) . " รายการเรียบร้อยแล้ว";
        }
    }
}

// Fetch all users with their roles combined in a string
$sql = "
    SELECT u.id, u.username, u.full_name, u.department, u.advisor_classroom,
           GROUP_CONCAT(r.role_name SEPARATOR ', ') as roles
    FROM users u
    LEFT JOIN user_roles ur ON u.id = ur.user_id
    LEFT JOIN roles r ON ur.role_id = r.id
    GROUP BY u.id
    ORDER BY u.id DESC
";
$users = $pdo->query($sql)->fetchAll();
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">รายชื่อครูในระบบ</h2>
        <div style="display: flex; gap: 10px;">
            <a href="admin_dashboard.php" class="btn btn-outline" style="color: #5e5ce6; border-color: #5e5ce6;">⬅️ แดชบอร์ด</a>
            <button type="submit" form="bulkDeleteForm" name="bulk_delete" class="btn btn-outline" style="color: var(--danger-color); border-color: var(--danger-color);" onclick="return confirm('ยืนยันการลบข้อมูลที่เลือกทั้งหมด?');">🗑️ ลบที่เลือก</button>
            <button type="submit" form="bulkDeleteForm" name="bulk_export" class="btn btn-outline" style="color: #059669; border-color: #059669;" onclick="return confirm('ยืนยันการส่งออกข้อมูลที่เลือกเป็น XLSX?');">📥 ส่งออกที่เลือก (XLSX)</button>
            <a href="admin_user_add.php" class="btn btn-primary">+ เพิ่มข้อมูลครู</a>
            <a href="admin_user_import.php?type=users" class="btn btn-success">นำเข้าจาก CSV</a>
            <a href="admin_export.php?type=teachers" class="btn btn-outline" style="color: #059669; border-color: #059669;">📥 ส่งออกทั้งหมด (Excel/CSV)</a>
        </div>
    </div>
    
    <?php if (isset($success_msg)): ?>
        <div class="badge badge-success mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $success_msg ?></div>
    <?php endif; ?>
    <?php if (isset($error_msg)): ?>
        <div class="badge badge-danger mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $error_msg ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <form method="POST" id="bulkDeleteForm">
            <table class="table" id="teachersTable">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;" data-orderable="false"><input type="checkbox" id="selectAll"></th>
                        <th>#</th>
                    <th>ชื่อผู้ใช้ (Username)</th>
                    <th>ชื่อ - สกุล</th>
                    <th>กลุ่มสาระการเรียนรู้</th>
                    <th>ครูที่ปรึกษา (ห้อง)</th>
                    <th>สิทธิ์การใช้งาน (Roles)</th>
                    <th style="text-align: right;" data-orderable="false">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $index => $u): ?>
                    <tr>
                        <td style="text-align: center;">
                            <?php if ($u['username'] !== 'admin'): ?>
                                <input type="checkbox" name="selected_ids[]" value="<?= $u['id'] ?>" class="rowCheckbox">
                            <?php endif; ?>
                        </td>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['full_name']) ?></td>
                        <td><?= htmlspecialchars($u['department'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($u['advisor_classroom'] ?? '-') ?></td>
                        <td>
                            <?php 
                            if ($u['roles']) {
                                $role_arr = explode(', ', $u['roles']);
                                foreach ($role_arr as $role) {
                                    $badge_class = 'badge-info';
                                    if ($role == 'admin') $badge_class = 'badge-danger';
                                    elseif ($role == 'teacher') $badge_class = 'badge-success';
                                    elseif ($role == 'academic') $badge_class = 'badge-warning';
                                    elseif ($role == 'executive') $badge_class = 'badge-primary';
                                    elseif ($role == 'discipline') $badge_class = 'badge-dark';
                                    elseif ($role == 'friday_prayer_duty') $badge_class = 'badge-info';
                                    elseif ($role == 'level_head') $badge_class = 'badge-warning';
                                    
                                    echo "<span class='badge {$badge_class}'>{$role}</span> ";
                                }
                            } else {
                                echo "<span class='badge badge-secondary'>ไม่มีสิทธิ์</span>";
                            }
                            ?>
                        </td>
                        <td style="text-align: right;">
                            <a href="admin_user_edit.php?id=<?= $u['id'] ?>" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px;">✏️ แก้ไข</a>
                            <?php if ($u['username'] !== 'admin'): ?>
                                <button type="submit" form="bulkDeleteForm" name="delete_id" value="<?= $u['id'] ?>" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px; color: var(--danger-color); border-color: var(--danger-color);" onclick="return confirm('ยืนยันการลบข้อมูลผู้ใช้นี้?');">🗑️ ลบ</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<script>
$(document).ready(function() {
    $('#teachersTable').DataTable({
        "language": {
            "lengthMenu": "แสดง _MENU_ รายการต่อหน้า",
            "zeroRecords": "ไม่พบข้อมูลที่ค้นหา",
            "info": "แสดงหน้า _PAGE_ จาก _PAGES_",
            "infoEmpty": "ไม่มีข้อมูล",
            "infoFiltered": "(กรองจากทั้งหมด _MAX_ รายการ)",
            "search": "ค้นหา:",
            "paginate": {
                "first": "หน้าแรก",
                "last": "หน้าสุดท้าย",
                "next": "ถัดไป",
                "previous": "ก่อนหน้า"
            }
        },
        "pageLength": 25,
        "ordering": true
    });
});

document.getElementById('selectAll').addEventListener('change', function() {
    var checkboxes = document.querySelectorAll('.rowCheckbox');
    for (var checkbox of checkboxes) {
        checkbox.checked = this.checked;
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
