<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
$is_admin = in_array('admin', $user_roles) || in_array('discipline', $user_roles) || in_array('executive', $user_roles) || in_array('inspector', $user_roles);
$is_teacher = in_array('teacher', $user_roles);

if (!$is_admin && !$is_teacher) {
    echo "Access Denied";
    exit();
}

if (!isset($_GET['cycle_id'])) {
    header("Location: discipline_inspection_cycles.php");
    exit();
}

$cycle_id = $_GET['cycle_id'];

// Helper function to extract a numeric sort code from a room name (e.g. "ม.1/10" -> "0110", "ม.1/2" -> "0102")
function getRoomSortCode($room_name) {
    $parts = explode('/', str_replace('ม.', '', $room_name));
    $grade = isset($parts[0]) ? (int)$parts[0] : 0;
    $room = isset($parts[1]) ? (int)$parts[1] : 0;
    return sprintf('%02d%02d', $grade, $room);
}

// Get Cycle Info
$stmt_cycle = $pdo->prepare("SELECT * FROM inspection_cycles WHERE id = ?");
$stmt_cycle->execute([$cycle_id]);
$cycle = $stmt_cycle->fetch();

if (!$cycle) {
    echo "ไม่พบข้อมูลรอบการตรวจ";
    exit();
}

$page_title = "ข้อมูลอย่างละเอียด: " . htmlspecialchars($cycle['cycle_name']);
include '../../includes/header.php';

// Handle Unlock Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_room']) && $is_admin) {
    $unlock_classroom_id = $_POST['unlock_classroom_id'];
    $stmt_unlock = $pdo->prepare("DELETE FROM inspection_room_locks WHERE cycle_id = ? AND classroom_id = ?");
    $stmt_unlock->execute([$cycle_id, $unlock_classroom_id]);
    echo "<div class='alert alert-success'>ปลดล็อคห้องเรียนเรียบร้อยแล้ว ครูที่ปรึกษาสามารถเข้าแก้ไขได้อีกครั้ง</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_all_rooms']) && $is_admin) {
    $stmt_unlock_all = $pdo->prepare("DELETE FROM inspection_room_locks WHERE cycle_id = ?");
    $stmt_unlock_all->execute([$cycle_id]);
    echo "<div class='alert alert-success'>ปลดล็อคทุกห้องเรียนเรียบร้อยแล้ว ครูที่ปรึกษาทุกห้องสามารถเข้าแก้ไขได้อีกครั้ง</div>";
}

// 4. Students with Issues (Fail, Dodged, Excused)
$stmt_issues = $pdo->prepare("
    SELECT di.*, s.student_code, s.full_name, s.student_number, c.room_name, c.grade_level, c.room_number, c.id AS classroom_id
    FROM discipline_inspections di
    JOIN students s ON di.student_id = s.id
    JOIN classrooms c ON s.classroom_id = c.id
    WHERE di.cycle_id = ? AND di.overall_status IN ('fail', 'dodged', 'excused')
    ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC, s.student_number ASC
");
$stmt_issues->execute([$cycle_id]);
$students_with_issues = $stmt_issues->fetchAll();

// 5. Room Lock Statuses (For Admin)
$query_locks = "
    SELECT c.id as classroom_id, c.room_name, c.grade_level, c.room_number, l.locked_at 
    FROM classrooms c
    LEFT JOIN inspection_room_locks l ON c.id = l.classroom_id AND l.cycle_id = ?
";
$params_locks = [$cycle_id];
if ($cycle['target_grade_level'] !== 'all') {
    $query_locks .= " WHERE c.grade_level = ?";
    $params_locks[] = $cycle['target_grade_level'];
}
$query_locks .= " ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC";

$stmt_locks = $pdo->prepare($query_locks);
$stmt_locks->execute($params_locks);
$all_rooms = $stmt_locks->fetchAll();

?>


<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<style>
/* FORCE DATATABLES STYLE (Bypass Cache) */
.dataTables_wrapper::after { content: ""; display: table; clear: both; }
.dataTables_wrapper .dataTables_info { float: left; padding-top: 8px !important; color: #666; font-size: 0.9rem; }
.dataTables_wrapper .dataTables_paginate {
    float: right;
    display: flex !important;
    flex-direction: row !important;
    justify-content: flex-end;
    align-items: center;
    gap: 5px;
    margin-top: 8px !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button {
    display: inline-block !important;
    padding: 6px 12px !important;
    border-radius: 8px !important;
    border: 1px solid rgba(0,0,0,0.1) !important;
    background: #fff !important;
    color: #333 !important;
    cursor: pointer !important;
    text-decoration: none !important;
    font-size: 0.85rem !important;
    margin: 0 !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button.current {
    background: #15803d !important;
    color: #fff !important;
    border-color: #15803d !important;
    font-weight: bold !important;
}
.dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.current):not(.disabled) { background: #f0f0f0 !important; }
.dataTables_wrapper .dataTables_paginate .paginate_button.disabled { opacity: 0.5 !important; cursor: not-allowed !important; }
.dataTables_wrapper .dataTables_paginate span { display: flex !important; flex-direction: row !important; gap: 5px !important; }
</style>

<div class="row mb-3">
    <div class="col-12" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 style="margin: 0; font-size: 1.2rem; font-weight: bold;">ข้อมูลอย่างละเอียด: <?= htmlspecialchars($cycle['cycle_name']) ?></h2>
        <?php if($is_admin): ?>
            <a href="discipline_inspection_cycles.php" class="btn btn-outline">⬅️ กลับไปหน้าจัดการรอบ</a>
        <?php else: ?>
            <a href="teacher_behavior.php" class="btn btn-outline">⬅️ กลับ</a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <p style="margin: 0 0 10px 0; color: var(--text-muted);">วันที่ตรวจ: <strong><?= date('d/m/Y', strtotime($cycle['inspection_date'])) ?></strong> | เป้าหมาย: <strong><?= $cycle['target_grade_level'] === 'all' ? 'ทุกระดับชั้น' : htmlspecialchars($cycle['target_grade_level']) ?></strong> | สถานะ: <strong><?= $cycle['status'] === 'open' ? 'กำลังเปิด' : 'ปิดแล้ว' ?></strong></p>
    
</div>

<!-- Room Lock Status (Admin Only) -->
<?php if ($is_admin): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="font-size: 1rem; margin: 0;">สถานะการส่งผลตรวจของแต่ละห้องเรียน</h3>
                <form method="POST" style="margin: 0;">
                    <button type="submit" name="unlock_all_rooms" class="btn btn-sm btn-outline" style="border-color: #dc2626; color: #dc2626; padding: 5px 15px;" onclick="return confirm('ยืนยัน ปลดล็อคทุกห้องเรียน ในรอบการตรวจนี้ใช่หรือไม่?');">🔓 ปลดล็อคทั้งหมด</button>
                </form>
            </div>
            
            <!-- Room Filters -->
            <div style="margin-bottom: 15px; font-size: 0.9rem;">
                <strong style="margin-right: 10px; color: #475569;">กรองสถานะ: </strong>
                <label style="margin-right: 15px; cursor: pointer;"><input type="radio" name="filter_rooms" value="" checked> ทั้งหมด</label>
                <label style="margin-right: 15px; cursor: pointer; color: #d97706;"><input type="radio" name="filter_rooms" value="ยังไม่ได้ส่งผล"> ยังไม่ได้ส่งผล</label>
                <label style="cursor: pointer; color: #16a34a;"><input type="radio" name="filter_rooms" value="ส่งผลแล้ว"> ส่งผลแล้ว</label>
            </div>

            <div class="table-responsive">
                <table class="table" id="roomsTable" style="font-size: 0.85rem; width: 100%;">
                    <thead>
                        <tr>
                            <th>ห้องเรียน</th>
                            <th>สถานะการส่งผล</th>
                            <th>เวลาที่ล็อค</th>
                            <th>การจัดการ (แอดมิน)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($all_rooms as $r): ?>
                        <tr>
                            <td><span style="display:none;"><?= getRoomSortCode($r['room_name']) ?></span><strong><?= htmlspecialchars($r['room_name']) ?></strong></td>
                            <td>
                                <?php if ($r['locked_at']): ?>
                                    <span class="badge badge-success">ส่งผลแล้ว (ล็อค)</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">ยังไม่ได้ส่งผล</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $r['locked_at'] ? date('d/m/Y H:i', strtotime($r['locked_at'])) : '-' ?></td>
                            <td>
                                <?php if ($r['locked_at']): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="unlock_classroom_id" value="<?= $r['classroom_id'] ?>">
                                    <button type="submit" name="unlock_room" class="btn btn-sm btn-outline" style="border-color: var(--danger-color); color: var(--danger-color);" onclick="return confirm('ยืนยันการปลดล็อคห้องนี้? ครูที่ปรึกษาจะสามารถกลับไปแก้ไขข้อมูลได้');">🔓 ปลดล็อค</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Students with Issues List -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card" style="border-top: 4px solid #ef4444;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="font-size: 1rem; margin: 0; color: #ef4444;">รายชื่อนักเรียนที่มีปัญหา (ไม่ผ่าน / หนี / ลา)</h3>
                <button type="button" class="btn btn-sm btn-outline" style="border-color: #ef4444; color: #ef4444;" onclick="openPrintModal()">ส่งออกไฟล์ PDF</button>
            </div>
            
            <!-- Issue Filters -->
            <div style="margin-bottom: 15px; font-size: 0.9rem;">
                <strong style="margin-right: 10px; color: #475569;">กรองสถานะ: </strong>
                <label style="margin-right: 15px; cursor: pointer;"><input type="radio" name="filter_issues" value="" checked> ทั้งหมด</label>
                <label style="margin-right: 15px; cursor: pointer; color: #dc2626;"><input type="radio" name="filter_issues" value="ไม่ผ่าน"> ไม่ผ่าน</label>
                <label style="margin-right: 15px; cursor: pointer; color: #d97706;"><input type="radio" name="filter_issues" value="หนีตรวจ"> หนีตรวจ</label>
                <label style="cursor: pointer; color: #2563eb;"><input type="radio" name="filter_issues" value="ลาป่วย"> ลาป่วย/ลากิจ</label>
            </div>

            <div class="table-responsive">
                <table class="table" id="issuesTable" style="font-size: 0.9rem; width: 100%;">
                    <thead>
                        <tr>
                            <th>รหัส</th>
                            <th>ชื่อ - สกุล</th>
                            <th>ห้องเรียน</th>
                            <th>สถานะ</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($students_with_issues as $s): ?>
                        <tr>
                            <td><?= $s['student_code'] ?></td>
                            <td><strong><?= htmlspecialchars($s['full_name']) ?></strong></td>
                            <td><span style="display:none;"><?= getRoomSortCode($s['room_name']) ?></span><?= htmlspecialchars($s['room_name']) ?></td>
                            <td>
                                <?php
                                if ($s['overall_status'] == 'fail') {
                                    $failed_items = [];
                                    if ($s['hair_status'] == 0) $failed_items[] = 'ทรงผม';
                                    if ($s['nail_status'] == 0) $failed_items[] = 'เล็บ';
                                    if ($s['accessory_status'] == 0) $failed_items[] = 'ไม่แต่งหน้า';
                                    if ($s['shoes_status'] == 0) $failed_items[] = 'เครื่องแบบ';
                                    
                                    $reason_text = count($failed_items) > 0 ? ' <small style="color: #64748b; font-size: 0.8rem; margin-left: 5px;">(' . implode(', ', $failed_items) . ')</small>' : '';
                                    echo '<span class="badge" style="background:#fee2e2; color:#dc2626;">ไม่ผ่าน</span>' . $reason_text;
                                }
                                elseif ($s['overall_status'] == 'dodged') echo '<span class="badge" style="background:#fef3c7; color:#d97706;">หนีตรวจ</span>';
                                elseif ($s['overall_status'] == 'excused') echo '<span class="badge" style="background:#dbeafe; color:#2563eb;">ลาป่วย/ลากิจ</span>';
                                ?>
                            </td>
                            <td>
                                <a href="teacher_inspection.php?cycle_id=<?= $cycle_id ?>&classroom_id=<?= $s['classroom_id'] ?>&role_mode=discipline" class="btn btn-sm" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1;">✏️ ไปที่ห้องนี้</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($students_with_issues)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 20px; color: #64748b;">สุดยอดมาก! ไม่มีนักเรียนที่มีปัญหาในรอบนี้เลย 🎉</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Print Modal -->
<div id="printModal" class="sidebar-overlay" style="display: none; align-items: center; justify-content: center; z-index: 1050;">
    <div class="card" style="width: 90%; max-width: 400px; margin: auto; position: relative; z-index: 1051;">
        <h3 style="font-size: 1.1rem; margin-bottom: 15px;">🖨️ ตั้งค่าการพิมพ์ (PDF)</h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">เลือกรูปแบบการกรองรายชื่อนักเรียนที่มีปัญหาเพื่อพิมพ์หรือบันทึกเป็น PDF</p>
        
        <form action="print_discipline_issues.php" method="GET" target="_blank">
            <input type="hidden" name="cycle_id" value="<?= $cycle_id ?>">
            <input type="hidden" name="issue_status" id="printIssueStatus" value="">
            
            <div class="form-group mb-3">
                <label style="display: block; margin-bottom: 8px;"><input type="radio" name="filter_type" value="all" checked onchange="togglePrintFilters()"> พิมพ์ทั้งหมดทุกห้อง</label>
                <label style="display: block; margin-bottom: 8px;"><input type="radio" name="filter_type" value="level" onchange="togglePrintFilters()"> พิมพ์แยกตามระดับชั้น</label>
                <label style="display: block; margin-bottom: 15px;"><input type="radio" name="filter_type" value="room" onchange="togglePrintFilters()"> พิมพ์แยกตามห้อง</label>
            </div>

            <div class="form-group mb-3" id="printLevelGroup" style="display: none;">
                <label class="form-label">เลือกระดับชั้น</label>
                <select name="filter_level" class="form-control">
                    <?php 
                    $levels = [];
                    foreach($all_rooms as $r) $levels[$r['grade_level']] = true;
                    ksort($levels);
                    foreach(array_keys($levels) as $lvl): 
                    ?>
                        <option value="<?= htmlspecialchars($lvl) ?>"><?= htmlspecialchars($lvl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-4" id="printRoomGroup" style="display: none;">
                <label class="form-label">เลือกห้องเรียน</label>
                <select name="filter_room" class="form-control">
                    <?php foreach($all_rooms as $r): ?>
                        <option value="<?= htmlspecialchars($r['room_name']) ?>"><?= htmlspecialchars($r['room_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closePrintModal()">ยกเลิก</button>
                <button type="submit" class="btn btn-primary" onclick="closePrintModal()">ยืนยัน</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    var roomsTable = $('#roomsTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        },
        "lengthMenu": [[15, 30, 50, 80], [15, 30, 50, 80]],
        "pageLength": 15,
        "ordering": true,
        "order": [], // Prevent initial sorting
        "info": true
    });
    
    var issuesTable = $('#issuesTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        },
        "lengthMenu": [[15, 30, 50, 80], [15, 30, 50, 80]],
        "pageLength": 15,
        "ordering": true,
        "order": [], // Prevent initial sorting
        "info": true
    });

    // Custom Filters
    $('input[name="filter_rooms"]').on('change', function() {
        roomsTable.column(1).search(this.value).draw();
    });

    $('input[name="filter_issues"]').on('change', function() {
        issuesTable.column(3).search(this.value).draw();
    });
});

function openPrintModal() {
    var selectedIssue = $('input[name="filter_issues"]:checked').val();
    $('#printIssueStatus').val(selectedIssue);
    $('#printModal').css('display', 'flex');
}
function closePrintModal() {
    $('#printModal').css('display', 'none');
}
function togglePrintFilters() {
    var type = $('input[name="filter_type"]:checked').val();
    if (type === 'level') {
        $('#printLevelGroup').show();
        $('#printRoomGroup').hide();
    } else if (type === 'room') {
        $('#printLevelGroup').hide();
        $('#printRoomGroup').show();
    } else {
        $('#printLevelGroup').hide();
        $('#printRoomGroup').hide();
    }
}
</script>
