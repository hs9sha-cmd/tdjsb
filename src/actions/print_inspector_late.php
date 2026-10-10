<?php
session_start();
require_once '../../config/database.php';

// Check authentication and roles
$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('inspector', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles) && !in_array('discipline', $user_roles)) {
    die("คุณไม่มีสิทธิ์เข้าถึงหน้านี้");
}

$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_level = $_GET['level'] ?? '';
$filter_room = $_GET['room'] ?? '';

// Load site settings
$stmt_set = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('school_name', 'school_logo')");
$site_settings = $stmt_set ? $stmt_set->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$school_name = !empty($site_settings['school_name']) ? $site_settings['school_name'] : 'โรงเรียนของคุณ';
$school_logo = !empty($site_settings['school_logo']) ? $site_settings['school_logo'] : 'assets/images/school_logo.png';

// Format date to Thai
$thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
$d = date('j', strtotime($filter_date));
$m = $thai_months[(int)date('n', strtotime($filter_date))];
$y = date('Y', strtotime($filter_date)) + 543;
$thai_date_str = "$d $m $y";

// Query late records
$sql = "
    SELECT br.id, br.record_date, s.student_code, s.full_name, c.room_name, c.grade_level, br.points, br.reason, br.created_at
    FROM behavior_records br
    JOIN students s ON br.student_id = s.id
    LEFT JOIN classrooms c ON s.classroom_id = c.id
    WHERE (br.reason LIKE 'มาสาย%' OR br.reason LIKE '%สาย%') AND br.status = 'approved' AND br.record_date = :filter_date
";
if ($filter_level) {
    $sql .= " AND c.grade_level = :filter_level ";
}
if ($filter_room) {
    $sql .= " AND c.room_name = :filter_room ";
}
$sql .= " ORDER BY c.grade_level ASC, c.room_number ASC, s.student_code ASC ";

$stmt = $pdo->prepare($sql);
$params = ['filter_date' => $filter_date];
if ($filter_level) {
    $params['filter_level'] = $filter_level;
}
if ($filter_room) {
    $params['filter_room'] = $filter_room;
}
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by grade level
$grouped_records = [];
foreach ($records as $r) {
    $level = $r['grade_level'] ?: 'ไม่ระบุ';
    $grouped_records[$level][] = $r;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงานการมาสาย - <?= $thai_date_str ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Prompt', sans-serif;
            color: #000;
            margin: 0;
            padding: 20px;
            background: #fff;
            line-height: 1.5;
        }
        .text-center { text-align: center; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-4 { margin-bottom: 1.5rem; }
        h1, h2, h3, h4 { margin-top: 0; }
        
        .print-btn {
            display: block;
            margin: 20px auto;
            padding: 10px 20px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-family: inherit;
            cursor: pointer;
        }
        .print-btn:hover { background: #1d4ed8; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 13px;
        }
        th, td {
            border: 1px solid #000;
            padding: 3px 6px;
            text-align: left;
        }
        th {
            background-color: #f3f4f6;
            font-weight: bold;
        }
        .text-right { text-align: right; }
        .text-center-cell { text-align: center; }
        
        .level-header {
            background: #e5e7eb;
            padding: 4px 8px;
            font-weight: bold;
            font-size: 14px;
            border: 1px solid #000;
            border-bottom: none;
            margin-top: 15px;
        }

        @media print {
            .no-print { display: none !important; }
            body { padding: 0; margin: 0; }
            @page { margin: 1.5cm; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
            thead { display: table-header-group; }
            tfoot { display: table-footer-group; }
        }
    </style>
</head>
<body>

    <button class="print-btn no-print" onclick="window.print()">🖨️ สั่งพิมพ์ / บันทึกเป็น PDF</button>

    <div style="position: relative; text-align: center; margin-bottom: 15px;">
        <img src="../../<?= htmlspecialchars($school_logo) ?>" style="position: absolute; top: 0; left: 0; width: 65px; height: auto;" alt="โลโก้โรงเรียน" onerror="this.src='../../assets/images/school_logo.png'">
        <h2 style="margin-bottom: 5px; font-size: 1.4rem;"><?= htmlspecialchars($school_name) ?></h2>
        <h3 style="margin-bottom: 5px; font-size: 1.2rem;">รายงานนักเรียนมาสาย (คาบ 0)</h3>
        <div style="font-size: 1.05rem; font-weight: bold;">
            ประจำวันที่ <?= $thai_date_str ?> 
            <?php if ($filter_level): ?>
                (ระดับชั้น: <?= htmlspecialchars($filter_level) ?>)
            <?php else: ?>
                (ระดับชั้น: ทั้งหมด)
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($grouped_records)): ?>
        <p class="text-center">-- ไม่มีข้อมูลการมาสายในวันที่เลือก --</p>
    <?php else: ?>
        <?php foreach ($grouped_records as $level => $items): ?>
            <div class="level-header">ระดับชั้น <?= htmlspecialchars($level) ?> (จำนวน <?= count($items) ?> คน)</div>
            <table>
                <thead>
                    <tr>
                        <th width="8%" class="text-center-cell">ที่</th>
                        <th width="15%" class="text-center-cell">เวลา</th>
                        <th width="15%" class="text-center-cell">รหัสนักเรียน</th>
                        <th width="35%">ชื่อ - นามสกุล</th>
                        <th width="12%" class="text-center-cell">ห้อง</th>
                        <th width="15%" class="text-center-cell">ประเภท</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $i = 1;
                    foreach ($items as $r): 
                        $display_reason = $r['reason'];
                        if (strpos($display_reason, 'มาสาย (07.50') !== false) {
                            $display_reason = 'สาย';
                        } elseif (strpos($display_reason, 'มาสาย (หลัง') !== false) {
                            $display_reason = 'สายมาก';
                        }
                    ?>
                    <tr>
                        <td class="text-center-cell"><?= $i++ ?></td>
                        <td class="text-center-cell"><?= date('H:i', strtotime($r['created_at'])) ?> น.</td>
                        <td class="text-center-cell"><?= htmlspecialchars($r['student_code']) ?></td>
                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                        <td class="text-center-cell"><?= htmlspecialchars($r['room_name']) ?></td>
                        <td class="text-center-cell"><?= htmlspecialchars($display_reason) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
        // Auto print dialog on load
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>
