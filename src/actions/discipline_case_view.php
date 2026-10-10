<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    echo "Access Denied";
    exit();
}

$case_id = $_GET['id'] ?? null;
if (!$case_id) {
    echo "Invalid Case ID";
    exit();
}

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

if (!$case) {
    echo "Case Not Found";
    exit();
}

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

$page_title = "รายละเอียดคดี: " . htmlspecialchars($case['case_code']);
include '../../includes/header.php';
?>

<div class="card">
    <?php if(isset($_GET['success'])): ?>
        <div class="badge badge-success mb-3" style="display: block; padding: 10px;">บันทึกข้อมูลคดีเรียบร้อยแล้ว</div>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 class="card-title" style="margin: 0;">🔍 รายละเอียดคดีความประพฤติ</h2>
        <div>
            <a href="discipline_case_pdf.php?id=<?= $case_id ?>" target="_blank" class="btn btn-primary" style="background: var(--apple-blue); margin-right: 5px;">🖨️ พิมพ์รายงาน (PDF)</a>
            <a href="discipline_manage_scores.php?tab=cases" class="btn btn-secondary">กลับหน้ารวม</a>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <!-- Left: Details -->
        <div>
            <div style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; height: 100%;">
                <h4 style="margin-top: 0; color: var(--primary-color); border-bottom: 2px solid #cbd5e1; padding-bottom: 10px;">ข้อมูลคดี</h4>
                
                <table style="width: 100%; border-collapse: collapse; line-height: 1.8;">
                    <tr>
                        <td style="font-weight: bold; width: 120px; color: #475569;">รหัสคดี:</td>
                        <td style="font-weight: bold; color: var(--apple-blue); font-size: 1.1rem;"><?= htmlspecialchars($case['case_code']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; color: #475569;">ชื่อคดี:</td>
                        <td><?= htmlspecialchars($case['case_name']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; color: #475569;">ภาคเรียนที่:</td>
                        <td><?= $case['term_number'] ?>/<?= $case['year_name'] ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; color: #475569;">ผู้บันทึก:</td>
                        <td><?= htmlspecialchars($case['reported_by_name']) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; color: #475569;">วันเวลาที่บันทึก:</td>
                        <td><?= date('d/m/Y H:i:s', strtotime($case['created_at'])) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight: bold; color: #475569; vertical-align: top;">พฤติการณ์:</td>
                        <td style="white-space: pre-wrap; background: white; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0; margin-top: 5px; display: block;"><?= htmlspecialchars($case['description']) ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Right: Students -->
        <div>
            <div style="background: #fef2f2; padding: 20px; border-radius: 8px; border: 1px solid #fecaca; height: 100%;">
                <h4 style="margin-top: 0; color: #dc2626; border-bottom: 2px solid #fca5a5; padding-bottom: 10px;">นักเรียนที่เกี่ยวข้อง (<?= count($students) ?> คน)</h4>
                
                <table class="table" style="background: white; border-radius: 8px; overflow: hidden; font-size: 0.95rem;">
                    <thead style="background: #fee2e2;">
                        <tr>
                            <th>รหัส</th>
                            <th>ชื่อ - สกุล</th>
                            <th>ห้อง</th>
                            <th style="text-align: right;">คะแนนที่หัก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($students as $s): ?>
                        <tr>
                            <td><?= $s['student_code'] ?></td>
                            <td><?= htmlspecialchars($s['full_name']) ?></td>
                            <td><?= htmlspecialchars($s['room_name']) ?></td>
                            <td style="text-align: right; color: #dc2626; font-weight: bold;"><?= $s['points_deducted'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Photos -->
    <?php if(!empty($photos)): ?>
    <div style="margin-top: 30px;">
        <h4 style="border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; color: #334155;">📷 รูปภาพหลักฐาน</h4>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
            <?php foreach($photos as $p): ?>
                <div style="border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; aspect-ratio: 1; background: #f8fafc; display: flex; align-items: center; justify-content: center;">
                    <a href="../../<?= htmlspecialchars($p['photo_path']) ?>" target="_blank">
                        <img src="../../<?= htmlspecialchars($p['photo_path']) ?>" style="max-width: 100%; max-height: 100%; object-fit: cover;" alt="Evidence">
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include '../../includes/footer.php'; ?>
