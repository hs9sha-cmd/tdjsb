<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    die("Access Denied");
}

$case_id = $_GET['id'] ?? null;
if (!$case_id) die("Invalid Case ID");

// Fetch Case Details
$stmt = $pdo->prepare("
    SELECT dc.*, u.full_name as reported_by_name, t.year_name, t.term_number
    FROM discipline_cases dc
    LEFT JOIN users u ON dc.reported_by = u.id
    LEFT JOIN academic_terms t ON dc.academic_term_id = t.id
    WHERE dc.id = ?
");
$stmt->execute([$case_id]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$case) die("Case Not Found");

// Fetch Students
$stmt_students = $pdo->prepare("
    SELECT dcs.*, s.student_code, s.full_name, c.room_name, c.grade_level, c.room_number
    FROM discipline_case_students dcs
    JOIN students s ON dcs.student_id = s.id
    LEFT JOIN classrooms c ON s.classroom_id = c.id
    WHERE dcs.case_id = ?
    ORDER BY c.grade_level, c.room_number, s.student_code
");
$stmt_students->execute([$case_id]);
$students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);

// Fetch Photos
$stmt_photos = $pdo->prepare("SELECT * FROM discipline_case_photos WHERE case_id = ? ORDER BY uploaded_at ASC");
$stmt_photos->execute([$case_id]);
$photos = $stmt_photos->fetchAll(PDO::FETCH_ASSOC);

// Site Settings
$stmt_set = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'school_name'");
$school_name = $stmt_set ? ($stmt_set->fetchColumn() ?: 'โรงเรียนของคุณ') : 'โรงเรียนของคุณ';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>รายงานคดี - <?= htmlspecialchars($case['case_code']) ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Prompt', sans-serif;
            color: #000;
            margin: 0;
            padding: 0;
            background: #fff;
            font-size: 14pt;
        }
        
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm;
            margin: 10mm auto;
            background: white;
            box-sizing: border-box;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        
        .header-title {
            font-size: 18pt;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .header-subtitle {
            font-size: 16pt;
            margin-bottom: 20px;
        }
        
        .section-title {
            font-size: 16pt;
            font-weight: bold;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .table-bordered th, .table-bordered td {
            border: 1px solid #000;
            padding: 8px;
        }
        
        .table-bordered th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        
        .info-table td {
            padding: 5px 0;
            vertical-align: top;
        }
        
        .info-label {
            font-weight: bold;
            width: 150px;
        }
        
        .photos-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 15px;
        }
        
        .photo-item {
            text-align: center;
        }
        
        .photo-item img {
            max-width: 100%;
            max-height: 200px;
            border: 1px solid #ccc;
            object-fit: contain;
        }
        
        .signature-box {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }
        
        .sig-line {
            text-align: center;
            width: 250px;
        }
        
        .sig-line hr {
            border-top: 1px dashed #000;
            margin: 40px 0 10px 0;
        }
        
        @media print {
            body { background: none; }
            .page { margin: 0; box-shadow: none; padding: 0; width: 100%; height: 100%; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
        }
        
        .print-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 15px 30px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 50px;
            font-size: 16px;
            cursor: pointer;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            font-family: 'Prompt', sans-serif;
            font-weight: bold;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">🖨️ พิมพ์เอกสาร</button>
    
    <div class="page" style="position: relative;">
        <img src="../../assets/images/school_logo.png" style="position: absolute; top: 20mm; left: 20mm; width: 60px; height: auto;" alt="Logo">
        <div class="text-center">
            <div class="header-title">บันทึกข้อความ / รายงานพฤติกรรมนักเรียน</div>
            <div class="header-subtitle"><?= htmlspecialchars($school_name) ?></div>
        </div>
        
        <table class="info-table">
            <tr>
                <td class="info-label">รหัสคดี:</td>
                <td style="font-weight: bold;"><?= htmlspecialchars($case['case_code']) ?></td>
                <td class="info-label text-right">วันที่บันทึก:</td>
                <td><?= date('d/m/Y', strtotime($case['created_at'])) ?></td>
            </tr>
            <tr>
                <td class="info-label">ชื่อคดี:</td>
                <td colspan="3"><?= htmlspecialchars($case['case_name']) ?></td>
            </tr>
            <tr>
                <td class="info-label">ภาคเรียนที่:</td>
                <td colspan="3"><?= $case['term_number'] ?>/<?= $case['year_name'] ?></td>
            </tr>
        </table>
        
        <div class="section-title">รายละเอียดพฤติการณ์</div>
        <p style="white-space: pre-wrap; line-height: 1.6; min-height: 100px;"><?= htmlspecialchars($case['description']) ?></p>
        
        <div class="section-title">รายชื่อนักเรียนที่เกี่ยวข้องและผลการพิจารณา</div>
        <table class="table-bordered">
            <thead>
                <tr>
                    <th style="width: 50px; text-align: center;">ลำดับ</th>
                    <th style="width: 100px;">รหัสนักเรียน</th>
                    <th>ชื่อ - สกุล</th>
                    <th style="width: 100px;">ระดับชั้น</th>
                    <th style="width: 150px; text-align: center;">คะแนนที่หัก</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($students as $index => $s): ?>
                <tr>
                    <td class="text-center"><?= $index + 1 ?></td>
                    <td class="text-center"><?= $s['student_code'] ?></td>
                    <td><?= htmlspecialchars($s['full_name']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($s['room_name']) ?></td>
                    <td class="text-center" style="font-weight: bold; color: red;"><?= $s['points_deducted'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="signature-box" style="justify-content: center;">
            <div class="sig-line">
                <hr>
                ( <?= htmlspecialchars($case['reported_by_name']) ?> )<br>
                ผู้รายงาน/บันทึกข้อมูล
            </div>
        </div>
        
        <?php if(!empty($photos)): ?>
            <?php foreach($photos as $index => $p): ?>
            <div class="page-break"></div>
            <div class="section-title">รูปภาพหลักฐานประกอบ (รหัสคดี: <?= htmlspecialchars($case['case_code']) ?>) - รูปที่ <?= $index + 1 ?></div>
            <div class="text-center" style="margin-top: 20px;">
                <img src="../../<?= htmlspecialchars($p['photo_path']) ?>" style="max-width: 100%; max-height: 850px; border: 1px solid #ccc; object-fit: contain;" alt="Evidence Photo">
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <script>
        window.onload = function() {
            // Uncomment next line to auto-print
            // window.print();
        }
    </script>
</body>
</html>
