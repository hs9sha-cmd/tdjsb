<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

// Check roles
$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('level_head', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    echo "Access Denied";
    exit();
}

// Get active academic term
$stmt_term = $pdo->query("SELECT id, term_number, year_name FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt_term->fetch();
$term_id = $active_term['id'] ?? null;
$term_text = $term_id ? "ภาคเรียนที่ {$active_term['term_number']} ปีการศึกษา {$active_term['year_name']}" : "ไม่พบปีการศึกษาที่เปิดใช้งาน";

date_default_timezone_set('Asia/Bangkok');
$today = date('Y-m-d');

// Load site settings
$stmt_set = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('school_name', 'school_logo')");
$site_settings = $stmt_set ? $stmt_set->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$school_name = !empty($site_settings['school_name']) ? $site_settings['school_name'] : 'โรงเรียนของคุณ';
$school_logo = !empty($site_settings['school_logo']) ? $site_settings['school_logo'] : 'assets/images/school_logo.png';

$stats_filter_type = $_GET['stats_filter_type'] ?? 'term';
$stats_date = $_GET['stats_date'] ?? $today;
$stats_week = $_GET['stats_week'] ?? date('Y-\WW');
$stats_month = $_GET['stats_month'] ?? date('Y-m');
$stats_start = $_GET['stats_start'] ?? date('Y-m-01');
$stats_end = $_GET['stats_end'] ?? $today;

$date_condition = "";
$params = [$term_id];
$filter_label = "";
$file_suffix = "";

function thai_date_full($date_str) {
    if (!$date_str) return '';
    $thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $t = strtotime($date_str);
    $day = date('j', $t);
    $month = $thai_months[(int)date('n', $t)];
    $year = date('Y', $t) + 543;
    return "$day $month $year";
}

function thai_month_name($ym_str) {
    if (!$ym_str) return '';
    $thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $parts = explode('-', $ym_str);
    $year = ((int)$parts[0]) + 543;
    $month = $thai_months[(int)$parts[1]];
    return "$month $year";
}

if ($stats_filter_type === 'date' && $stats_date) {
    $date_condition = " AND br.record_date = ? ";
    $params[] = $stats_date;
    $filter_label = "ประจำวันที่ " . thai_date_full($stats_date);
    $file_suffix = "วันที่_" . $stats_date;
} elseif ($stats_filter_type === 'week' && $stats_week) {
    $year = substr($stats_week, 0, 4);
    $week = substr($stats_week, 6);
    $dto = new DateTime();
    $dto->setISODate($year, $week);
    $start_week = $dto->format('Y-m-d');
    $dto->modify('+6 days');
    $end_week = $dto->format('Y-m-d');
    $date_condition = " AND br.record_date BETWEEN ? AND ? ";
    $params[] = $start_week;
    $params[] = $end_week;
    $filter_label = "ประจำสัปดาห์ (" . thai_date_full($start_week) . " - " . thai_date_full($end_week) . ")";
    $file_suffix = "สัปดาห์_{$start_week}_ถึง_{$end_week}";
} elseif ($stats_filter_type === 'month' && $stats_month) {
    $date_condition = " AND DATE_FORMAT(br.record_date, '%Y-%m') = ? ";
    $params[] = $stats_month;
    $filter_label = "ประจำเดือน " . thai_month_name($stats_month);
    $file_suffix = "เดือน_" . $stats_month;
} elseif ($stats_filter_type === 'range' && $stats_start && $stats_end) {
    $date_condition = " AND br.record_date BETWEEN ? AND ? ";
    $params[] = $stats_start;
    $params[] = $stats_end;
    $filter_label = "ระหว่างวันที่ " . thai_date_full($stats_start) . " ถึง " . thai_date_full($stats_end);
    $file_suffix = "ช่วงวันที่_{$stats_start}_ถึง_{$stats_end}";
} else {
    $filter_label = "ภาคเรียนปัจจุบัน (" . $term_text . ")";
    $file_suffix = "ภาคเรียนปัจจุบัน";
}

$pdf_filename = "รายงานสถิติการมาสาย_" . $file_suffix;

$stats_sql = "
    SELECT 
        c.id as classroom_id,
        c.grade_level,
        c.room_name,
        c.room_number,
        COUNT(br.id) as late_count
    FROM classrooms c
    LEFT JOIN students s ON c.id = s.classroom_id AND s.status = 'active'
    LEFT JOIN behavior_records br ON s.id = br.student_id 
        AND br.academic_term_id = ? 
        AND br.status = 'approved' 
        AND (br.reason LIKE 'มาสาย%' OR br.reason LIKE '%สาย%')
        $date_condition
    GROUP BY c.id
    ORDER BY CAST(SUBSTRING_INDEX(c.grade_level, '.', -1) AS UNSIGNED) ASC, CAST(c.room_number AS UNSIGNED) ASC
";
$stmt_stats = $pdo->prepare($stats_sql);
$stmt_stats->execute($params);
$all_late_stats = $stmt_stats->fetchAll(PDO::FETCH_ASSOC);

$grade_stats = [];
$grade_totals = [];
$total_all_late = 0;
foreach ($all_late_stats as $stat) {
    $grade = $stat['grade_level'];
    $grade_stats[$grade][] = $stat;
    $grade_totals[$grade] = ($grade_totals[$grade] ?? 0) + $stat['late_count'];
    $total_all_late += $stat['late_count'];
}

// Sort for top 5 (most late)
$sorted_stats = $all_late_stats;
usort($sorted_stats, function($a, $b) {
    return $b['late_count'] <=> $a['late_count'];
});
$top_late = array_slice($sorted_stats, 0, 5);

// Max room count across grades
$max_rooms = 0;
foreach ($grade_stats as $rooms) {
    if (count($rooms) > $max_rooms) {
        $max_rooms = count($rooms);
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pdf_filename) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="../../assets/js/html2pdf.bundle.min.js"></script>
    <style>
        * {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: 'Prompt', sans-serif;
            margin: 0;
            padding: 20px;
            background: #f1f5f9;
            color: #000;
        }
        @page {
            size: A4 portrait;
            margin: 12mm 14mm 12mm 14mm;
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
                page-break-inside: avoid !important;
                page-break-after: avoid !important;
            }
            .table-bw th, .table-bw td {
                border: 1px solid #000 !important;
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
        }
        .report-header {
            text-align: center;
            position: relative;
            margin-bottom: 18px;
            padding-bottom: 14px;
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
        .section-box {
            margin-bottom: 18px;
        }
        .section-title {
            font-size: 14.5px;
            font-weight: 800;
            color: #000;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .table-bw {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            line-height: 1.3;
            color: #000;
        }
        .table-bw th {
            background-color: #f2f2f2;
            color: #000;
            font-weight: 800;
            padding: 6.5px 6px;
            border: 1px solid #000;
            text-align: center;
        }
        .table-bw td {
            padding: 6px 6px;
            border: 1px solid #000;
            text-align: center;
            color: #000;
        }
        .table-bw tr:nth-child(even) td {
            background-color: #fafafa;
        }
        .top-table th, .top-table td {
            padding: 6.5px 12px;
        }
        .grade-table th {
            font-size: 12px;
            padding: 6px 3px;
        }
        .grade-table td {
            font-size: 12.5px;
            padding: 5.5px 3px;
        }
        .report-footer {
            margin-top: 22px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            font-size: 11.5px;
            color: #000;
            line-height: 1.3;
        }
    </style>
</head>
<body>

<div class="no-print" style="max-width: 210mm; margin: 0 auto 15px; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap;">
    <a href="discipline_dashboard.php" style="display: inline-block; background: #64748b; color: white; padding: 9px 18px; border-radius: 6px; text-decoration: none; font-size: 14px; font-weight: 600;">⬅️ กลับหน้าแดชบอร์ด</a>
    <div style="display: flex; gap: 8px; align-items: center;">
        <button id="btn-download" onclick="downloadPDF()" style="padding: 9px 20px; font-size: 14px; font-weight: 700; background: #059669; color: white; border: none; border-radius: 6px; cursor: pointer; box-shadow: 0 2px 5px rgba(5, 150, 105, 0.3); display: inline-flex; align-items: center; gap: 6px;">
            📥 ดาวน์โหลดเป็นไฟล์ PDF ทันที (.pdf)
        </button>
        <button onclick="window.print()" style="padding: 9px 18px; font-size: 14px; font-weight: 700; background: #000; color: white; border: none; border-radius: 6px; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.2); display: inline-flex; align-items: center; gap: 6px;">
            🖨️ สั่งพิมพ์
        </button>
    </div>
</div>

<div class="print-page" id="report-page">
    <!-- Header -->
    <div class="report-header">
        <img src="../../<?= htmlspecialchars($school_logo) ?>" class="report-logo" alt="Logo" onerror="this.src='../../assets/images/school_logo.png'">
        <div style="padding-left: 55px; padding-right: 55px;">
            <h1 class="report-title"><?= htmlspecialchars($school_name) ?></h1>
            <h2 class="report-subtitle">รายงานสรุปสถิติการมาสายของนักเรียน</h2>
            <p class="report-filter">สถิติการมาสาย <?= htmlspecialchars($filter_label) ?></p>
        </div>
    </div>

    <!-- Section 1: Top 5 Most Late -->
    <div class="section-box">
        <div class="section-title">1. ห้าอันดับห้องเรียนที่มาสายมากที่สุด</div>
        <table class="table-bw top-table">
            <thead>
                <tr>
                    <th style="width: 80px;">อันดับ</th>
                    <th style="text-align: left; padding-left: 14px;">ห้องเรียน</th>
                    <th style="width: 170px;">จำนวนการมาสาย (ครั้ง)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($top_late)): ?>
                    <?php foreach ($top_late as $idx => $room): ?>
                        <tr>
                            <td style="font-weight: 800;">#<?= $idx + 1 ?></td>
                            <td style="text-align: left; padding-left: 14px; font-weight: 700;"><?= htmlspecialchars($room['room_name']) ?></td>
                            <td style="font-weight: 800;"><?= $room['late_count'] ?> ครั้ง</td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="3" style="text-align: center; color: #555; padding: 12px;">ไม่มีข้อมูลการมาสายในช่วงเวลาที่เลือก</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Section 2: All Grades Stats -->
    <div class="section-box">
        <div class="section-title">2. สถิติการมาสายแยกตามระดับชั้น (ม.1 - ม.6)</div>
        <table class="table-bw grade-table">
            <thead>
                <tr>
                    <th style="width: 80px;">ระดับชั้น</th>
                    <?php for ($i = 1; $i <= $max_rooms; $i++): ?>
                        <th>ห้อง <?= $i ?></th>
                    <?php endfor; ?>
                    <th style="width: 85px; background-color: #e5e5e5;">รวม (ครั้ง)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grade_stats as $grade => $rooms): ?>
                    <tr>
                        <td style="font-weight: 800; background-color: #f7f7f7;"><?= htmlspecialchars($grade) ?></td>
                        <?php for ($i = 0; $i < $max_rooms; $i++): ?>
                            <?php if (isset($rooms[$i])): ?>
                                <td style="<?= $rooms[$i]['late_count'] > 0 ? 'font-weight: 800;' : '' ?>">
                                    <?= $rooms[$i]['late_count'] ?>
                                </td>
                            <?php else: ?>
                                <td style="color: #999;">-</td>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <td style="font-weight: 800; background-color: #f0f0f0;">
                            <?= $grade_totals[$grade] ?? 0 ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight: 800; background-color: #e5e5e5;">
                    <td colspan="<?= $max_rooms + 1 ?>" style="text-align: right; padding-right: 14px; font-size: 13px;">รวมการมาสายทั้งหมด</td>
                    <td style="text-align: center; font-weight: 800; font-size: 13.5px;"><?= $total_all_late ?> ครั้ง</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Footer -->
    <div class="report-footer">
        <div style="font-size: 11px; color: #444;">
            พิมพ์เมื่อ: <?= thai_date_full($today) ?> เวลา <?= date('H:i') ?> น.
        </div>
        <div style="text-align: right;">
            <div style="font-weight: 800; color: #000; font-size: 13px;">กลุ่มงานบริหารกิจการนักเรียน</div>
            <div style="font-size: 11.5px; color: #333;"><?= htmlspecialchars($school_name) ?></div>
        </div>
    </div>
</div>

<script>
function downloadPDF() {
    const btn = document.getElementById('btn-download');
    const originalText = btn.innerHTML;
    btn.innerHTML = '⏳ กำลังสร้างไฟล์ PDF...';
    btn.disabled = true;

    const element = document.getElementById('report-page');
    
    const opt = {
        margin: [10, 12, 10, 12],
        filename: '<?= $pdf_filename ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { 
            scale: 2, 
            useCORS: true,
            letterRendering: true
        },
        jsPDF: { 
            unit: 'mm', 
            format: 'a4', 
            orientation: 'portrait' 
        }
    };

    html2pdf().set(opt).from(element).save().then(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }).catch(err => {
        console.error(err);
        btn.innerHTML = originalText;
        btn.disabled = false;
        alert('เกิดข้อผิดพลาดในการบันทึกไฟล์ PDF: ' + err.message);
    });
}
</script>

</body>
</html>
