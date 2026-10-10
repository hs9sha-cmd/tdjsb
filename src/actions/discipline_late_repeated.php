<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "รายงานนักเรียนมาสายสะสม (เกิน 4 ครั้ง)";
include '../../includes/header.php';

$selected_month = $_GET['month'] ?? date('m');
$selected_year = $_GET['year'] ?? date('Y');
$selected_room = $_GET['room'] ?? 'all';

$thai_months = [
    '01' => 'มกราคม', '02' => 'กุมภาพันธ์', '03' => 'มีนาคม', '04' => 'เมษายน',
    '05' => 'พฤษภาคม', '06' => 'มิถุนายน', '07' => 'กรกฎาคม', '08' => 'สิงหาคม',
    '09' => 'กันยายน', '10' => 'ตุลาคม', '11' => 'พฤศจิกายน', '12' => 'ธันวาคม'
];

$stmt_term = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
$term_id = $stmt_term->fetchColumn();

// Fetch all classrooms for dropdown
$classrooms = $pdo->query("SELECT id, room_name FROM classrooms ORDER BY room_name ASC")->fetchAll();

$room_display_name = "-- ทุกห้องเรียน --";
if ($selected_room !== 'all') {
    foreach($classrooms as $c) {
        if ($c['id'] == $selected_room) {
            $room_display_name = $c['room_name'];
            break;
        }
    }
}

$repeated_students = [];
if ($term_id) {
    $query = "
        SELECT 
            s.id, s.student_code, s.full_name, c.room_name,
            COUNT(*) as late_count
        FROM behavior_records br
        JOIN students s ON br.student_id = s.id
        LEFT JOIN classrooms c ON s.classroom_id = c.id
        WHERE MONTH(br.record_date) = ? AND YEAR(br.record_date) = ? 
        AND (br.reason LIKE 'มาสาย%' OR br.reason LIKE '%สาย%') AND br.status = 'approved'
    ";
    
    $params = [$selected_month, $selected_year];
    
    if ($selected_room !== 'all') {
        $query .= " AND c.id = ?";
        $params[] = $selected_room;
    }
    
    $query .= "
        GROUP BY s.id
        HAVING late_count >= 4
        ORDER BY late_count DESC, c.room_name ASC, s.student_code ASC
    ";
    
    $stmt_rep = $pdo->prepare($query);
    $stmt_rep->execute($params);
    $repeated_students = $stmt_rep->fetchAll();
}
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 class="card-title" style="margin: 0;">⚠️ นักเรียนมาสายสะสม (ตั้งแต่ 4 ครั้งขึ้นไป)</h2>
        <div style="display: flex; gap: 10px;">
            <button type="button" class="btn btn-secondary" onclick="window.location.href='discipline_dashboard.php'">กลับหน้าหลัก</button>
            <button type="button" class="btn btn-primary" id="export-btn" onclick="exportPDF()" style="background-color: #ef4444; border-color: #ef4444;">📄 ส่งออก PDF</button>
        </div>
    </div>

    <form method="GET" style="background: var(--apple-bg); padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
        <div>
            <label style="font-weight: bold; margin-bottom: 5px; display: block;">เลือกระดับชั้น/ห้องเรียน</label>
            <select name="room" class="form-control" style="width: 180px;">
                <option value="all">-- ทุกห้องเรียน --</option>
                <?php foreach($classrooms as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selected_room == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['room_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-weight: bold; margin-bottom: 5px; display: block;">เลือกเดือน</label>
            <select name="month" class="form-control" style="width: 150px;">
                <?php foreach($thai_months as $num => $name): ?>
                    <option value="<?= $num ?>" <?= $selected_month === $num ? 'selected' : '' ?>><?= $name ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-weight: bold; margin-bottom: 5px; display: block;">เลือกปี (พ.ศ.)</label>
            <select name="year" class="form-control" style="width: 120px;">
                <?php 
                $current_y = date('Y');
                for($y = $current_y - 1; $y <= $current_y + 1; $y++): 
                ?>
                    <option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y + 543 ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">ค้นหา</button>
        </div>
    </form>

    <div class="no-print" style="background: #fffbeb; border: 1px solid #fde68a; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
        <p style="margin: 0; color: #b45309;">
            <strong>📌 หมายเหตุ:</strong> ข้อมูลนี้แสดงเฉพาะนักเรียนที่โดนหักคะแนน "มาสาย" สะสมครบ 4 ครั้งขึ้นไป 
            ในเดือน <strong><?= $thai_months[$selected_month] ?> ปี <?= $selected_year + 543 ?></strong> 
            (ระบบได้ส่งการแจ้งเตือนไปยังครูที่ปรึกษาแล้วเมื่อถึงครั้งที่ 4)
        </p>
    </div>

    <div id="print-area" style="background: white;">
    <div id="pdf-header" style="display: none; text-align: center; margin-bottom: 5px; padding-top: 0;">
        <img src="../../<?= htmlspecialchars($site_settings['school_logo'] ?? 'assets/images/school_logo.png') ?>" alt="Logo" style="width: 60px; height: 60px; margin-bottom: 2px;" onerror="this.src='../../assets/images/school_logo.png'">
        <h2 style="margin: 0; font-size: 1.2rem; color: #000; line-height: 1.2;"><?= htmlspecialchars($site_settings['school_name'] ?? 'โรงเรียนของคุณ') ?></h2>
        <h3 style="margin: 2px 0; font-size: 1.1rem; color: #000; line-height: 1.2;">รายงานนักเรียนมาสายสะสม (ตั้งแต่ 4 ครั้งขึ้นไป)</h3>
        <p style="margin: 0; font-size: 0.9rem; color: #000; line-height: 1.2;">
            ประจำเดือน <?= $thai_months[$selected_month] ?> พ.ศ. <?= $selected_year + 543 ?>
            &nbsp;|&nbsp; ห้องเรียน: <?= htmlspecialchars($room_display_name) ?>
        </p>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 60px; text-align: center;">ลำดับ</th>
                    <th style="width: 100px;">รหัสนักเรียน</th>
                    <th>ชื่อ-สกุล</th>
                    <th style="width: 120px;">ห้องเรียน</th>
                    <th style="width: 150px; text-align: center;">จำนวนครั้งที่สาย</th>
                    <th class="action-col" style="width: 120px; text-align: center;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($repeated_students)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                            ไม่มีนักเรียนที่มาสายเกิน 4 ครั้งในเดือนนี้ 🎉
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $i = 1; foreach($repeated_students as $st): ?>
                    <tr>
                        <td style="text-align: center;"><?= $i++ ?></td>
                        <td><?= htmlspecialchars($st['student_code']) ?></td>
                        <td><?= htmlspecialchars($st['full_name']) ?></td>
                        <td><?= htmlspecialchars($st['room_name']) ?></td>
                        <td style="text-align: center;">
                            <span class="badge badge-danger" style="font-size: 1rem; padding: 5px 12px; border-radius: 20px;">
                                <?= $st['late_count'] ?> ครั้ง
                            </span>
                            <span class="print-text"><?= $st['late_count'] ?> ครั้ง</span>
                        </td>
                        <td class="action-col" style="text-align: center;">
                            <a href="discipline_student_history.php?student_id=<?= $st['id'] ?>" class="btn btn-sm btn-outline">
                                📜 ประวัติ
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div id="pdf-footer" style="display: none; text-align: right; margin-top: 10px; padding-bottom: 0px; font-size: 0.9rem; color: #333;">
            กลุ่มงานกิจการนักเรียน
        </div>
    </div>

</div>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap');

@media print {
    @page {
        size: A4 portrait;
        margin: 10mm;
    }

    * {
        font-family: 'Sarabun', sans-serif !important;
    }

    form, .card-title, .btn, .action-col {
        display: none !important;
    }
    
    #pdf-header, #pdf-footer {
        display: block !important;
    }
    
    .table {
        width: 100% !important;
        border-collapse: collapse !important;
        margin-bottom: 20px !important;
        
    }
    .table th, .table td {
        border: 1px solid #000 !important;
        padding: 1px 4px !important;
        color: #000 !important;
        font-size: 11pt !important;
        line-height: 1 !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }
    .table th {
        background-color: #f3f4f6 !important;
        -webkit-print-color-adjust: exact;
        font-weight: 600 !important;
    }
    
    .badge {
        display: none !important;
    }
    .print-text {
        display: inline !important;
    }
    
    .card {
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
        background: transparent !important;
    }
    body {
        background: white !important;
    }
}

@media screen {
    .print-text {
        display: none !important;
    }
}
</style>

<script>
function exportPDF() {
    window.print();
}
</script>

<?php include '../../includes/footer.php'; ?>
