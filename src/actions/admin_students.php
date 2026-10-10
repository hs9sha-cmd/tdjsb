<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$page_title = "จัดการข้อมูลนักเรียน";
include '../../includes/header.php';

// Handle Single Delete
if (isset($_POST['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
    $stmt->execute([$_POST['delete_id']]);
    $success_msg = "ลบข้อมูลนักเรียนเรียบร้อยแล้ว";
}

// Handle Bulk Delete
if (isset($_POST['bulk_delete']) && isset($_POST['selected_ids'])) {
    $ids = $_POST['selected_ids'];
    if (!empty($ids)) {
        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM students WHERE id IN ($inQuery)");
        $stmt->execute($ids);
        $success_msg = "ลบข้อมูลนักเรียนที่เลือกจำนวน " . count($ids) . " รายการเรียบร้อยแล้ว";
    }
}

// Fetch all students with their classroom info
$sql = "
    SELECT s.*, c.room_name
    FROM students s
    LEFT JOIN classrooms c ON s.classroom_id = c.id
    ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC, s.student_code ASC
";
$students = $pdo->query($sql)->fetchAll();
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">รายชื่อนักเรียนระบบ</h2>
        <div style="display: flex; gap: 10px;">
            <a href="admin_dashboard.php" class="btn btn-outline" style="color: #5e5ce6; border-color: #5e5ce6;">⬅️ แดชบอร์ด</a>
            <button type="submit" form="bulkDeleteStudentForm" name="bulk_delete" class="btn btn-outline" style="color: var(--danger-color); border-color: var(--danger-color);" onclick="return confirm('ยืนยันการลบนักเรียนที่เลือกทั้งหมด?');">🗑️ ลบที่เลือก</button>
            <a href="admin_student_add.php" class="btn btn-primary">+ เพิ่มนักเรียน</a>
            <a href="admin_user_import.php?type=students" class="btn btn-success">นำเข้าจาก CSV</a>
            <a href="admin_export.php?type=students" class="btn btn-outline" style="color: #059669; border-color: #059669;">📥 ส่งออก (Excel/CSV)</a>
        </div>
    </div>
    
    <?php if (isset($success_msg)): ?>
        <div class="badge badge-success mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $success_msg ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <form method="POST" id="bulkDeleteStudentForm">
            <table class="table" id="studentsTable">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;" data-orderable="false"><input type="checkbox" id="selectAllStudents"></th>
                        <th>รหัสประจำตัว</th>
                    <th>เลขที่</th>
                    <th>ชื่อ - สกุล</th>
                    <th>ห้องเรียน</th>
                    <th>สถานะ</th>
                    <th style="text-align: right;" data-orderable="false">จัดการ</th>
                </tr>
            </thead><tbody></tbody>
            
            </table>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<script>
$(document).ready(function() {
    var studentsData = <?php echo json_encode(array_map(function($s) {
    return [
        'id' => $s['id'],
        'student_code' => htmlspecialchars($s['student_code']),
        'student_number' => htmlspecialchars($s['student_number'] ?? '-'),
        'full_name' => htmlspecialchars($s['full_name']),
        'room_name' => htmlspecialchars($s['room_name'] ?? 'ไม่ได้ระบุห้อง'),
        'status' => $s['status']
    ];
}, $students)); ?>;

    $('#studentsTable').DataTable({
        data: studentsData,
        deferRender: true,
        columns: [
            {
                data: 'id',
                orderable: false,
                createdCell: function (td, cellData, rowData, row, col) {
                    $(td).css('text-align', 'center');
                },
                render: function(data, type, row) {
                    return '<input type="checkbox" name="selected_ids[]" value="'+data+'" class="rowStudentCheckbox">';
                }
            },
            { data: 'student_code' },
            { data: 'student_number' },
            { data: 'full_name' },
            { data: 'room_name' },
            {
                data: 'status',
                render: function(data, type, row) {
                    if (data === 'active') return '<span class="badge badge-success">กำลังศึกษา</span>';
                    if (data === 'graduated') return '<span class="badge badge-info">สำเร็จการศึกษา</span>';
                    return '<span class="badge badge-danger">ลาออก/พ้นสภาพ</span>';
                }
            },
            {
                data: 'id',
                orderable: false,
                createdCell: function (td, cellData, rowData, row, col) {
                    $(td).css('text-align', 'right');
                },
                render: function(data, type, row) {
                    return '<a href="admin_student_edit.php?id=' + data + '" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px;">✏️ แก้ไข</a> ' +
                           '<button type="submit" form="bulkDeleteStudentForm" name="delete_id" value="' + data + '" class="btn btn-outline" style="padding: 4px 8px; font-size: 12px; color: var(--danger-color); border-color: var(--danger-color);" onclick="return confirm(\'ยืนยันการลบข้อมูลนักเรียน?\');">🗑️ ลบ</button>';
                }
            }
        ],
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

document.getElementById('selectAllStudents').addEventListener('change', function() {
    var checkboxes = document.querySelectorAll('.rowStudentCheckbox');
    for (var checkbox of checkboxes) {
        checkbox.checked = this.checked;
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
