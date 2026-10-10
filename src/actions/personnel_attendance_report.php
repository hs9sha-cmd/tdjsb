<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();
$user_roles = getUserRoles($pdo, $_SESSION['user_id']);

// Only admin and personnel
if (!in_array('admin', $user_roles) && !in_array('personnel', $user_roles)) {
    die("Access Denied");
}

$page_title = "รายงานการลงเวลาปฏิบัติงาน";
$today = date('Y-m-d');
$filter_date = $_GET['filter_date'] ?? $today;
$filter_dept = $_GET['filter_dept'] ?? '';

// Get distinct departments from users table
$stmt_dept = $pdo->prepare("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
$stmt_dept->execute();
$departments = $stmt_dept->fetchAll(PDO::FETCH_COLUMN);

// Build query
$sql = "
    SELECT u.full_name, u.department, 
           ta.time_in, ta.time_in_status, ta.time_out, ta.time_in_photo, ta.time_out_photo
    FROM users u
    LEFT JOIN teacher_attendance ta ON u.id = ta.user_id AND ta.work_date = ?
    WHERE EXISTS (
        SELECT 1 FROM user_roles ur 
        JOIN roles r ON ur.role_id = r.id 
        WHERE ur.user_id = u.id AND r.role_name = 'teacher'
    )
";
$params = [$filter_date];

if ($filter_dept) {
    $sql .= " AND u.department = ?";
    $params[] = $filter_dept;
}

$sql .= " ORDER BY u.department ASC, u.full_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

include '../../includes/header.php';
?>

<style>
    .report-card { background: white; border-radius: 20px; padding: 25px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 25px; }
    .table-responsive { overflow-x: auto; border-radius: 12px; border: 1px solid #e2e8f0; }
    .data-table { width: 100%; border-collapse: collapse; min-width: 800px; }
    .data-table th { background: #f8fafc; color: #475569; padding: 14px; text-align: left; font-size: 0.9rem; font-weight: 700; border-bottom: 2px solid #cbd5e1; }
    .data-table td { padding: 14px; border-bottom: 1px solid #e2e8f0; font-size: 0.9rem; color: #1e293b; vertical-align: middle; }
    .data-table tr:hover td { background-color: #f1f5f9; }
    
    .photo-thumb { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; border: 1px solid #cbd5e1; cursor: pointer; transition: transform 0.2s; }
    .photo-thumb:hover { transform: scale(1.1); box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
    
    /* Modal styles */
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; justify-content: center; align-items: center; padding: 20px; }
    .modal-content { position: relative; max-width: 500px; width: 100%; background: white; padding: 10px; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
    .modal-img { width: 100%; height: auto; border-radius: 12px; display: block; }
    .modal-close { position: absolute; top: -15px; right: -15px; background: #ef4444; color: white; width: 35px; height: 35px; border-radius: 50%; border: 2px solid white; font-size: 1.2rem; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
</style>

<div class="main-grid" style="grid-template-columns: 1fr;">
    <div class="report-card">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 2px solid #f1f5f9;">
            <h2 class="card-title" style="margin: 0; font-size: 1.4rem; color: #1e293b; font-weight: 800;">📋 รายงานการลงเวลา (<?= date('d/m/Y', strtotime($filter_date)) ?>)</h2>
            <button type="button" class="btn btn-outline" style="border-radius: 50px; padding: 6px 16px; font-weight: 600;" onclick="history.back()">กลับ</button>
        </div>

        <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 20px;">
            <div>
                <label style="display: block; font-weight: 700; color: #475569; margin-bottom: 5px; font-size: 0.9rem;">เลือกวันที่:</label>
                <input type="date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>" class="form-control" style="border-radius: 12px; padding: 8px 12px;" onchange="this.form.submit()">
            </div>
            <div>
                <label style="display: block; font-weight: 700; color: #475569; margin-bottom: 5px; font-size: 0.9rem;">กลุ่มสาระฯ:</label>
                <select name="filter_dept" class="form-control" style="border-radius: 12px; padding: 8px 12px; min-width: 200px;" onchange="this.form.submit()">
                    <option value="">ทั้งหมด (ทุกกลุ่มสาระ)</option>
                    <?php foreach($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>" <?= $filter_dept === $dept ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="margin-left: auto;">
                <a href="print_teacher_attendance.php?filter_date=<?= urlencode($filter_date) ?>&filter_dept=<?= urlencode($filter_dept) ?>" target="_blank" class="btn" style="background: #ef4444; color: white; border: none; border-radius: 12px; padding: 10px 20px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 10px rgba(239,68,68,0.2);">
                    🖨️ ส่งออก PDF / พิมพ์
                </a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">ลำดับ</th>
                        <th>ชื่อ-สกุล</th>
                        <th>กลุ่มสาระการเรียนรู้</th>
                        <th style="text-align: center;">เวลาเข้างาน</th>
                        <th>สถานะ</th>
                        <th style="text-align: center;">เวลาเลิกงาน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($records)): ?>
                        <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding: 20px;">ไม่พบข้อมูลครูในระบบ</td></tr>
                    <?php else: ?>
                        <?php 
                        $i = 1;
                        foreach($records as $r): 
                            $in_time = $r['time_in'] ? substr($r['time_in'], 0, 5) . ' น.' : '-';
                            $out_time = $r['time_out'] ? substr($r['time_out'], 0, 5) . ' น.' : '-';
                            
                            $status_html = '-';
                            if ($r['time_in_status'] === 'on_time') {
                                $status_html = '<span style="color: #10b981; font-weight: 700;">ปกติ</span>';
                            } else if ($r['time_in_status'] === 'late') {
                                $status_html = '<span style="color: #f59e0b; font-weight: 700;">สาย</span>';
                            } else if (!$r['time_in']) {
                                $status_html = '<span style="color: #ef4444; font-weight: 700;">ขาด/ยังไม่ลงเวลา</span>';
                            }
                        ?>
                            <tr>
                                <td style="text-align: center; color: #64748b;"><?= $i++ ?></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($r['full_name']) ?></td>
                                <td><?= htmlspecialchars($r['department'] ?? 'ไม่ระบุ') ?></td>
                                <td style="text-align: center;">
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 10px;">
                                        <span style="font-weight: 600; color: <?= $r['time_in'] ? '#0f172a' : '#cbd5e1' ?>;"><?= $in_time ?></span>
                                        <?php if(!empty($r['time_in_photo'])): ?>
                                            <img src="../../<?= htmlspecialchars($r['time_in_photo']) ?>" class="photo-thumb" onclick="openModal('../../<?= htmlspecialchars($r['time_in_photo']) ?>')" title="ดูรูปถ่ายตอนเข้างาน">
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= $status_html ?></td>
                                <td style="text-align: center;">
                                    <div style="display: flex; align-items: center; justify-content: center; gap: 10px;">
                                        <span style="font-weight: 600; color: <?= $r['time_out'] ? '#0f172a' : '#cbd5e1' ?>;"><?= $out_time ?></span>
                                        <?php if(!empty($r['time_out_photo'])): ?>
                                            <img src="../../<?= htmlspecialchars($r['time_out_photo']) ?>" class="photo-thumb" onclick="openModal('../../<?= htmlspecialchars($r['time_out_photo']) ?>')" title="ดูรูปถ่ายตอนเลิกงาน">
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- Image Modal -->
<div id="imageModal" class="modal-overlay" onclick="closeModal()">
    <div class="modal-content" onclick="event.stopPropagation()">
        <button class="modal-close" onclick="closeModal()">&times;</button>
        <img id="modalImg" src="" class="modal-img">
    </div>
</div>

<script>
function openModal(src) {
    document.getElementById('modalImg').src = src;
    document.getElementById('imageModal').style.display = 'flex';
}
function closeModal() {
    document.getElementById('imageModal').style.display = 'none';
    document.getElementById('modalImg').src = '';
}
</script>

<?php include '../../includes/footer.php'; ?>
