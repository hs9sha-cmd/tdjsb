<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();
$user_roles = getUserRoles($pdo, $_SESSION['user_id']);

if (!in_array('admin', $user_roles) && !in_array('personnel', $user_roles) && !in_array('executive', $user_roles)) {
    die("Access Denied");
}

$today = date('Y-m-d');
$filter_date = $_GET['filter_date'] ?? $today;
$filter_dept = $_GET['filter_dept'] ?? '';
$reporter_name = $_SESSION['full_name'];

// Load site settings
$stmt_set = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
$site_settings = $stmt_set ? $stmt_set->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$school_name = !empty($site_settings['school_name']) ? $site_settings['school_name'] : 'โรงเรียนของคุณ';
$school_logo = !empty($site_settings['school_logo']) ? $site_settings['school_logo'] : 'assets/images/school_logo.png';
$director_name = !empty($site_settings['director_name']) ? $site_settings['director_name'] : '';
$director_position = !empty($site_settings['director_position']) ? $site_settings['director_position'] : 'ผู้อำนวยการโรงเรียน';

function thai_date_full($date_str) {
    if (!$date_str) return '';
    $thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $t = strtotime($date_str);
    return date('j', $t) . ' ' . $thai_months[(int)date('n', $t)] . ' ' . (date('Y', $t) + 543);
}

$sql = "
    SELECT u.full_name, u.department, 
           ta.time_in, ta.time_in_status, ta.time_out
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
$sql .= " ORDER BY CASE WHEN ta.time_in IS NULL THEN 1 ELSE 0 END ASC, ta.time_in ASC, u.department ASC, u.full_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$max_rows_per_page = 35; // Adjusted to safely fit A4 (font reduced to 10.5pt)
$max_rows_last_page = 26; // Adjusted to leave room for signatures at the bottom

$chunks = [];
$remaining = $records;

while (count($remaining) > 0) {
    if (count($remaining) <= $max_rows_last_page) {
        $chunks[] = array_splice($remaining, 0, count($remaining));
    } elseif (count($remaining) <= $max_rows_per_page) {
        $chunks[] = array_splice($remaining, 0, $max_rows_last_page);
    } else {
        $chunks[] = array_splice($remaining, 0, $max_rows_per_page);
    }
}
if (empty($chunks)) {
    $chunks = [[]];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงานการลงเวลาปฏิบัติหน้าที่</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; background: #f0f0f0; color: #000; margin: 0; padding: 20px; }
        .a4-page { width: 210mm; min-height: 297mm; margin: 0 auto 20px; padding: 15mm; box-sizing: border-box; background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 10.5pt; }
        th, td { border: 1px solid #000; padding: 2px 4px; line-height: 1.2; vertical-align: middle; }
        .no-border, .no-border th, .no-border td { border: none !important; }
        th { background-color: #f0f0f0; font-weight: 700; text-align: center; padding: 4px; }
        .text-center { text-align: center; }
        
        .name-cell { white-space: nowrap; overflow: hidden; width: 100%; }

        .header { display: block; position: relative; text-align: center; margin-bottom: 20px; }
        .logo { width: 70px; height: auto; position: absolute; top: 0; left: 0; }
        .header-text { display: inline-block; text-align: center; }
        .header-title { font-size: 16pt; font-weight: 700; margin: 0 0 5px 0; }
        .header-subtitle { font-size: 14pt; margin: 0 0 5px 0; }
        .header-date { font-size: 12pt; margin: 0; }
        .filter-info { text-align: right; font-size: 11pt; margin-bottom: 10px; }

        .footer-signatures { margin-top: 40px; display: flex; justify-content: space-between; page-break-inside: avoid; }
        .sig-box { flex: 1; text-align: center; padding: 0 5px; }
        .sig-line { margin-bottom: 5px; font-size: 11pt; }
        .sig-name { font-size: 11pt; margin-bottom: 5px; }
        .sig-title { font-size: 10pt; white-space: nowrap; }

        .print-btn-container { text-align: center; margin-bottom: 20px; }
        .print-btn { background: #3b82f6; color: white; border: none; padding: 10px 20px; font-size: 1.1rem; border-radius: 8px; cursor: pointer; font-family: 'Prompt'; }

        @media print {
            body { background: none; padding: 0; }
            .a4-page { width: 100%; height: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .print-btn-container { display: none; }
            @page { size: A4 portrait; margin: 15mm; }
        }
    </style>
</head>
<body>
    <div class="print-btn-container">
        <button class="print-btn" onclick="window.print()">🖨️ พิมพ์ / บันทึกเป็น PDF</button>
    </div>

    <?php 
    $global_i = 1;
    $total_pages = count($chunks);
    foreach($chunks as $page_idx => $chunk): 
        $current_page = $page_idx + 1;
        $is_last_page = ($page_idx === $total_pages - 1);
    ?>
    <div class="a4-page" <?= !$is_last_page ? 'style="page-break-after: always;"' : '' ?>>
        
        <div style="text-align: right; font-size: 10pt; margin-bottom: 5px; color: #555;">
            หน้า <?= $current_page ?> / <?= $total_pages ?>
        </div>

        <table>
            <colgroup>
                <col style="width: 8%;">
                <col style="width: 35%;">
                <col style="width: 25%;">
                <col style="width: 10%;">
                <col style="width: 12%;">
                <col style="width: 10%;">
            </colgroup>
            <thead>
                <tr class="no-border">
                    <td colspan="6" style="padding-bottom: 10px;">
                        <div class="header">
                            <img src="../../<?= htmlspecialchars($school_logo) ?>" alt="โลโก้โรงเรียน" class="logo" onerror="this.src='../../assets/images/school_logo.png'">
                            <div class="header-text">
                                <h1 class="header-title">เวลาการมาปฏิบัติหน้าที่</h1>
                                <h2 class="header-subtitle">ครูและบุคลากร<?= htmlspecialchars($school_name) ?></h2>
                                <p class="header-date">ประจำวันที่ <?= thai_date_full($filter_date) ?></p>
                            </div>
                        </div>
                        <?php if ($filter_dept): ?>
                        <div class="filter-info">
                            กลุ่มสาระการเรียนรู้: <?= htmlspecialchars($filter_dept) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>ลำดับ</th>
                    <th>ชื่อ-สกุล</th>
                    <th>กลุ่มสาระการเรียนรู้</th>
                    <th>เวลาเข้า</th>
                    <th>สถานะ</th>
                    <th>เวลาออก</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($chunk)): ?>
                    <tr><td colspan="6" class="text-center">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                    <?php 
                    foreach($chunk as $r): 
                        $in_time = $r['time_in'] ? substr($r['time_in'], 0, 5) : '-';
                        $out_time = $r['time_out'] ? substr($r['time_out'], 0, 5) : '-';
                        $status_str = '-';
                        if ($r['time_in_status'] === 'on_time') $status_str = 'ปกติ';
                        else if ($r['time_in_status'] === 'late') $status_str = 'สาย';
                        else if (!$r['time_in']) $status_str = 'ขาด';
                    ?>
                        <tr>
                            <td class="text-center"><?= $global_i++ ?></td>
                            <td><div class="name-cell"><?= htmlspecialchars($r['full_name']) ?></div></td>
                            <td><div class="name-cell"><?= htmlspecialchars($r['department'] ?? '') ?></div></td>
                            <td class="text-center"><?= $in_time ?></td>
                            <td class="text-center"><?= $status_str ?></td>
                            <td class="text-center"><?= $out_time ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if($is_last_page): ?>
        <div class="footer-signatures">
            <div class="sig-box">
                <div class="sig-line">ลงชื่อ................................................</div>
                <div class="sig-name">( <?= htmlspecialchars($reporter_name) ?> )</div>
                <div class="sig-title">เจ้าหน้าที่ผู้รายงาน</div>
            </div>
            <div class="sig-box">
                <div class="sig-line">ลงชื่อ................................................</div>
                <div class="sig-name">(...................................................)</div>
                <div class="sig-title">รองผู้อำนวยการกลุ่มบริหารงานบุคคล</div>
            </div>
            <div class="sig-box">
                <div class="sig-line">ลงชื่อ................................................</div>
                <div class="sig-name">( <?= htmlspecialchars($director_name ?: '...................................................') ?> )</div>
                <div class="sig-title"><?= htmlspecialchars($director_position) ?><?= htmlspecialchars($school_name) ?></div>
            </div>
        </div>
        <?php endif; ?>
        
    </div>
    <?php endforeach; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.name-cell').forEach(cell => {
                let fontSize = 11;
                cell.style.fontSize = fontSize + 'pt';
                while (cell.scrollWidth > cell.clientWidth && fontSize > 7) {
                    fontSize -= 0.5;
                    cell.style.fontSize = fontSize + 'pt';
                }
            });
        });
    </script>
</body>
</html>
