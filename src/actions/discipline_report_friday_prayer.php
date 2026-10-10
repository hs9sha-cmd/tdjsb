<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

// Check roles: Allow discipline, level_head, admin, executive, or teacher
$user_roles = $_SESSION['roles'] ?? [];

date_default_timezone_set('Asia/Bangkok');
$today = date('Y-m-d');

$filter_date = $_GET['filter_date'] ?? $today;

// Load site settings
$stmt_set = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('school_name', 'school_logo')");
$site_settings = $stmt_set ? $stmt_set->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$school_name = !empty($site_settings['school_name']) ? $site_settings['school_name'] : 'โรงเรียนของคุณ';
$school_logo = !empty($site_settings['school_logo']) ? $site_settings['school_logo'] : 'assets/images/school_logo.png';

function thai_date_full($date_str) {
    if (!$date_str) return '';
    $thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $t = strtotime($date_str);
    $day = date('j', $t);
    $month = $thai_months[(int)date('n', $t)];
    $year = date('Y', $t) + 543;
    return "$day $month $year";
}

$pdf_filename = "รายงานการออกไปละหมาดวันศุกร์_วันที่_" . $filter_date;

// Fetch logs for the selected date
$stmt = $pdo->prepare("
    SELECT f.*, s.student_code, s.full_name, c.room_name 
    FROM friday_prayers_log f
    JOIN students s ON f.student_id = s.id
    LEFT JOIN classrooms c ON s.classroom_id = c.id
    WHERE f.log_date = ?
    ORDER BY f.time_out ASC, s.student_code ASC
");
$stmt->execute([$filter_date]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_count = count($logs);
$total_out = 0;
$total_returned = 0;
foreach ($logs as $l) {
    if ($l['status'] === 'out') $total_out++;
    if ($l['status'] === 'returned') $total_returned++;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pdf_filename) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            margin: 0;
            padding: 20px;
            background: #f1f5f9;
            color: #000;
        }
        @page {
            size: A4 portrait;
            margin: 12mm 14mm 14mm 14mm;
        }
        @media print {
            body {
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
            }
            .no-print {
                display: none !important;
            }
            .print-page {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
            }
            .table-bw th, .table-bw td {
                border: 1px solid #000 !important;
            }
            .print-footer-onscreen {
                display: none !important;
            }
        }
        .print-page {
            background: white;
            max-width: 210mm;
            margin: 0 auto;
            padding: 14mm 16mm;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            box-sizing: border-box;
            position: relative;
        }
        .report-header {
            text-align: center;
            position: relative;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #000;
            min-height: 60px;
        }
        .report-logo {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 46px;
            height: auto;
        }
        .report-title {
            font-size: 20px;
            font-weight: 800;
            color: #000;
            margin: 0 0 4px 0;
            line-height: 1.2;
        }
        .report-subtitle {
            font-size: 15px;
            font-weight: 700;
            color: #000;
            margin: 0 0 4px 0;
            line-height: 1.2;
        }
        .report-filter {
            font-size: 13px;
            font-weight: 500;
            color: #000;
            margin: 0;
            line-height: 1.2;
        }
        .summary-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid #000;
            background-color: #f8fafc;
            padding: 8px 14px;
            margin-bottom: 15px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
        }
        .table-bw {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
            line-height: 1.25;
            color: #000;
        }
        .table-bw th {
            background-color: #f2f2f2;
            color: #000;
            font-weight: 800;
            padding: 6px 6px;
            border: 1px solid #000;
            text-align: center;
            white-space: nowrap;
        }
        .table-bw td {
            padding: 5.5px 6px;
            border: 1px solid #000;
            text-align: center;
            color: #000;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .table-bw tr:nth-child(even) td {
            background-color: #fafafa;
        }
        .report-footer {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            font-size: 11px;
            color: #000;
            line-height: 1.3;
        }
    </style>
</head>
<body>

<div class="no-print" style="max-width: 210mm; margin: 0 auto 15px; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
    <a href="discipline_friday_prayer.php?filter_date=<?= urlencode($filter_date) ?>" style="display: inline-block; background: #64748b; color: white; padding: 9px 18px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">⬅️ กลับหน้าสแกนละหมาด</a>
    <div style="display: flex; gap: 8px; align-items: center;">
        <button onclick="window.print()" style="padding: 9px 18px; font-size: 14px; font-weight: 700; background: #000; color: white; border: none; border-radius: 6px; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2); display: inline-flex; align-items: center; gap: 6px;">
            🖨️ สั่งพิมพ์
        </button>
    </div>
</div>

<div class="print-page" id="report-page">
    <!-- Data Table -->
    <table class="table-bw">
        <thead style="display: table-header-group;">
            <tr>
                <th colspan="7" style="background: white; border: none; padding: 0; white-space: normal; text-align: center;">
                    <!-- Header -->
                    <div class="report-header">
                        <img src="../../<?= htmlspecialchars($school_logo) ?>" class="report-logo" alt="Logo" onerror="this.src='../../assets/images/school_logo.png'">
                        <div style="padding-left: 55px; padding-right: 55px;">
                            <h1 class="report-title"><?= htmlspecialchars($school_name) ?></h1>
                            <h2 class="report-subtitle">รายงานการออกไปละหมาดวันศุกร์ของนักเรียน</h2>
                            <p class="report-filter">สถิติการออกไปละหมาด ประจำวันที่ <?= thai_date_full($filter_date) ?></p>
                        </div>
                    </div>

                    <!-- Summary Box -->
                    <div class="summary-box">
                        <div>🕌 ออกไปละหมาดทั้งหมด: <strong><?= $total_count ?></strong> คน</div>
                        <div>✅ กลับเข้าโรงเรียนแล้ว: <strong><?= $total_returned ?></strong> คน</div>
                        <div>⏳ อยู่ระหว่างละหมาด: <strong><?= $total_out ?></strong> คน</div>
                    </div>
                </th>
            </tr>
            <tr>
                <th style="width: 50px;">ลำดับ</th>
                <th style="width: 90px;">รหัสนักเรียน</th>
                <th style="text-align: left; padding-left: 10px;">ชื่อ - นามสกุล</th>
                <th style="width: 80px;">ชั้น/ห้อง</th>
                <th style="width: 75px;">เวลาออก</th>
                <th style="width: 75px;">เวลากลับ</th>
                <th style="width: 110px;">สถานะ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; color: #555; padding: 15px;">ไม่มีข้อมูลการบันทึกเวลาออกละหมาดในวันที่เลือก</td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $idx => $l): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= htmlspecialchars($l['student_code']) ?></td>
                        <td style="text-align: left; padding-left: 10px; font-weight: 600;"><?= htmlspecialchars($l['full_name']) ?></td>
                        <td><?= htmlspecialchars($l['room_name'] ?: '-') ?></td>
                        <td><?= substr($l['time_out'], 0, 5) ?> น.</td>
                        <td><?= $l['time_in'] ? substr($l['time_in'], 0, 5) . ' น.' : '-' ?></td>
                        <td style="font-weight: 700;">
                            <?php if ($l['status'] === 'out'): ?>
                                ⏳ ออกไปละหมาด
                            <?php elseif ($l['status'] === 'returned'): ?>
                                ✅ กลับมาแล้ว
                            <?php else: ?>
                                <?= htmlspecialchars($l['status']) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot style="display: table-footer-group;">
            <tr>
                <td colspan="7" style="border: none; padding-top: 25px; padding-bottom: 10px; text-align: left; white-space: normal;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; font-size: 11px; color: #000; line-height: 1.4;">
                        <div>
                            พิมพ์เมื่อ: <?= thai_date_full($today) ?> เวลา <?= date('H:i') ?> น.
                        </div>
                        <div style="text-align: right;">
                            <div style="font-weight: 800; font-size: 13px;">กลุ่มงานบริหารกิจการนักเรียน</div>
                            <div style="font-size: 11.5px;"><?= htmlspecialchars($school_name) ?></div>
                        </div>
                    </div>
                </td>
            </tr>
        </tfoot>
    </table>
</div>


</body>
</html>
