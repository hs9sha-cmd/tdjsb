<?php
session_start();
date_default_timezone_set('Asia/Bangkok');
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
    echo "ไม่พบข้อมูลรอบการตรวจ";
    exit();
}

$cycle_id = $_GET['cycle_id'];
$filter_type = $_GET['filter_type'] ?? 'all';
$filter_level = $_GET['filter_level'] ?? '';
$filter_room = $_GET['filter_room'] ?? '';

// Get Cycle Info
$stmt_cycle = $pdo->prepare("SELECT * FROM inspection_cycles WHERE id = ?");
$stmt_cycle->execute([$cycle_id]);
$cycle = $stmt_cycle->fetch();

if (!$cycle) {
    echo "ไม่พบข้อมูลรอบการตรวจ";
    exit();
}

$filter_issue_status = $_GET['issue_status'] ?? '';
$status_condition = "('fail', 'dodged', 'excused')";
$report_issue_title = " (ไม่ผ่าน / หนี / ลา)";

if ($filter_issue_status === 'ไม่ผ่าน') {
    $status_condition = "('fail')";
    $report_issue_title = " (เฉพาะไม่ผ่าน)";
} elseif ($filter_issue_status === 'หนีตรวจ') {
    $status_condition = "('dodged')";
    $report_issue_title = " (เฉพาะหนีตรวจ)";
} elseif ($filter_issue_status === 'ลาป่วย') {
    $status_condition = "('excused')";
    $report_issue_title = " (เฉพาะลาป่วย/ลากิจ)";
}

// Build Query
$query = "
    SELECT di.*, s.student_code, s.full_name, s.student_number, c.room_name, c.grade_level, c.room_number 
    FROM discipline_inspections di
    JOIN students s ON di.student_id = s.id
    JOIN classrooms c ON s.classroom_id = c.id
    WHERE di.cycle_id = ? AND di.overall_status IN $status_condition
";
$params = [$cycle_id];

if ($filter_type === 'level' && $filter_level !== '') {
    $query .= " AND c.grade_level = ?";
    $params[] = $filter_level;
    $report_title = "ระดับชั้น " . htmlspecialchars($filter_level);
} elseif ($filter_type === 'room' && $filter_room !== '') {
    $query .= " AND c.room_name = ?";
    $params[] = $filter_room;
    $report_title = "ห้องเรียน " . htmlspecialchars($filter_room);
} else {
    $report_title = "ทั้งหมดทุกระดับชั้น";
}

$query .= " ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC, s.student_number ASC";
$stmt_issues = $pdo->prepare($query);
$stmt_issues->execute($params);
$students_with_issues = $stmt_issues->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>พิมพ์รายชื่อนักเรียนที่ไม่ผ่านการตรวจระเบียบ - <?= htmlspecialchars($cycle['cycle_name']) ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap');
        
        body {
            font-family: 'Sarabun', sans-serif;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 20px;
            font-size: 10pt;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            position: relative;
        }
        
        .header .logo {
            position: absolute;
            top: 0;
            left: 0;
            width: 55px;
            height: auto;
        }
        
        .header h1 {
            font-size: 13pt;
            margin: 0 0 5px 0;
            font-weight: 600;
        }
        
        .header p {
            margin: 0;
            line-height: 1.3;
            font-size: 10pt;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-family: 'Sarabun', sans-serif;
            font-size: 10pt;
        }
        
        th, td {
            border: 1px solid #000;
            padding: 3px 6px;
            line-height: 1.2;
            text-align: left;
            white-space: nowrap;
        }
        
        th {
            background-color: #f3f4f6;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            font-weight: 600;
            text-align: center;
        }
        
        .text-center { text-align: center; }
        
        @page {
            size: A4 portrait;
            margin: 1.5cm;
        }
        
        .no-print {
            display: none;
        }
        
        @media screen {
            .no-print {
                display: block;
                text-align: center;
                margin-bottom: 20px;
            }
            .btn {
                background: #2563eb;
                color: #fff;
                border: none;
                padding: 10px 20px;
                font-size: 1rem;
                font-family: inherit;
                border-radius: 6px;
                cursor: pointer;
            }
            body {
                background: #e2e8f0;
            }
            .print-container {
                background: #fff;
                width: 210mm;
                min-height: 297mm;
                padding: 1.5cm;
                margin: 0 auto;
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
                box-sizing: border-box;
            }
        }
        @media print {
            .print-container {
                width: 100%;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn" onclick="window.print()">🖨️ กดเพื่อสั่งพิมพ์ (Print / Save as PDF)</button>
    </div>
    
    <div class="print-container">
        <?php if(empty($students_with_issues)): ?>
            <div class="header">
                <img src="../../assets/images/school_logo.png" alt="School Logo" class="logo">
                <h1>รายชื่อนักเรียนที่ไม่ผ่านการตรวจระเบียบ<?= $report_issue_title ?></h1>
                <p><strong>รอบการตรวจ:</strong> <?= htmlspecialchars($cycle['cycle_name']) ?></p>
                <?php
                $thai_months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
                $t_date = strtotime($cycle['inspection_date']);
                $fmt_date = date('j', $t_date) . ' ' . $thai_months[date('n', $t_date)] . ' ' . (date('Y', $t_date) + 543);
                ?>
                <p><strong>วันที่ตรวจ:</strong> <?= $fmt_date ?></p>
                <p><strong>กลุ่มเป้าหมายการพิมพ์:</strong> <?= $report_title ?></p>
            </div>
            <div style="text-align: center; margin-top: 50px; font-weight: bold;">
                ไม่มีรายชื่อนักเรียนที่มีปัญหาในกลุ่มที่เลือก
            </div>
        <?php else: ?>
            <table>
                <thead style="display: table-header-group;">
                    <tr>
                        <td colspan="5" style="border: none; padding: 0; background-color: transparent;">
                            <div class="header">
                                <img src="../../assets/images/school_logo.png" alt="School Logo" class="logo">
                                <h1>รายชื่อนักเรียนที่ไม่ผ่านการตรวจระเบียบ<?= $report_issue_title ?></h1>
                                <p><strong>รอบการตรวจ:</strong> <?= htmlspecialchars($cycle['cycle_name']) ?></p>
                                <?php
                                $thai_months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
                                $t_date = strtotime($cycle['inspection_date']);
                                $fmt_date = date('j', $t_date) . ' ' . $thai_months[date('n', $t_date)] . ' ' . (date('Y', $t_date) + 543);
                                ?>
                                <p><strong>วันที่ตรวจ:</strong> <?= $fmt_date ?></p>
                                <p><strong>กลุ่มเป้าหมายการพิมพ์:</strong> <?= $report_title ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th style="width: 1%; white-space: nowrap;">ที่</th>
                        <th style="width: 1%; white-space: nowrap;">รหัส</th>
                        <th style="width: auto;">ชื่อ - นามสกุล</th>
                        <th style="width: 1%; white-space: nowrap;">ห้องเรียน</th>
                        <th style="width: 1%; white-space: nowrap;">สาเหตุ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach($students_with_issues as $s): ?>
                    <tr>
                        <td class="text-center"><?= $i++ ?></td>
                        <td class="text-center"><?= $s['student_code'] ?></td>
                        <td><?= htmlspecialchars($s['full_name']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($s['room_name']) ?></td>
                        <td>
                            <?php
                            if ($s['overall_status'] == 'fail') {
                                $failed_items = [];
                                if ($s['hair_status'] == 0) $failed_items[] = 'ทรงผม';
                                if ($s['nail_status'] == 0) $failed_items[] = 'เล็บ';
                                if ($s['accessory_status'] == 0) $failed_items[] = 'ไม่แต่งหน้า';
                                if ($s['shoes_status'] == 0) $failed_items[] = 'เครื่องแบบ';
                                echo count($failed_items) > 0 ? implode(', ', $failed_items) : 'ไม่ระบุสาเหตุ';
                            }
                            elseif ($s['overall_status'] == 'dodged') echo 'หนีตรวจ';
                            elseif ($s['overall_status'] == 'excused') echo 'ลาป่วย/ลากิจ';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div style="margin-top: 50px; text-align: right;">
            <?php
            $d = date('j');
            $m = $thai_months[date('n')];
            $y = date('Y') + 543;
            $t = date('H:i');
            ?>
            <p style="font-size: 12pt; color: #555;">พิมพ์รายงานเมื่อวันที่ <?= $d ?> <?= $m ?> <?= $y ?> เวลา <?= $t ?> น.</p>
        </div>
    </div>
    
</body>
</html>
