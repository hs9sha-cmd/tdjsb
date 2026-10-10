<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

$id = $_GET['id'] ?? null;
if (!$id) {
    echo "ไม่พบรหัสนักเรียน";
    exit;
}

// Fetch student
$stmt = $pdo->prepare("
    SELECT s.*, c.room_name 
    FROM students s
    LEFT JOIN classrooms c ON s.classroom_id = c.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    echo "ไม่พบข้อมูลนักเรียนนี้ในระบบ";
    exit;
}

$page_title = "โปรไฟล์นักเรียน - " . htmlspecialchars($student['full_name']);

// Fetch active term for scores and subjects
$termStmt = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term_id = $termStmt->fetchColumn();

$behavior_score = 100;
if ($active_term_id) {
    $bStmt = $pdo->prepare("SELECT current_score FROM student_behaviors WHERE student_id = ? AND academic_term_id = ?");
    $bStmt->execute([$id, $active_term_id]);
    $b_score = $bStmt->fetchColumn();
    if ($b_score !== false) $behavior_score = $b_score;
}

// Fetch subjects
$subjects = [];
if ($active_term_id && $student['classroom_id']) {
    $sStmt = $pdo->prepare("
        SELECT sub.subject_code, sub.subject_name 
        FROM teacher_subjects ts
        JOIN subjects sub ON ts.subject_id = sub.id
        WHERE ts.classroom_id = ?
    ");
    $sStmt->execute([$student['classroom_id']]);
    $subjects = $sStmt->fetchAll();
}

include '../../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto; text-align: center;">
    <?php if (!empty($student['photo_path'])): ?>
        <img src="../../<?= htmlspecialchars($student['photo_path']) ?>" style="width: 150px; height: 150px; object-fit: cover; border-radius: 50%; border: 4px solid #1a7a45; margin-bottom: 15px;">
    <?php else: ?>
        <div style="width: 150px; height: 150px; background: #e2e8f0; border-radius: 50%; margin: 0 auto 15px; display: flex; align-items: center; justify-content: center; font-size: 3rem; color: #94a3b8;">👤</div>
    <?php endif; ?>
    
    <h2 style="margin: 0; color: #1e293b;"><?= htmlspecialchars($student['full_name']) ?></h2>
    <p style="margin: 5px 0 20px; color: #64748b; font-size: 1.1rem;">
        <?= htmlspecialchars($student['student_code']) ?> | ห้อง <?= htmlspecialchars($student['room_name']) ?> | เลขที่ <?= htmlspecialchars($student['student_number']) ?>
    </p>

    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 25px;">
        <div style="background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0;">
            <div style="font-size: 0.85rem; color: #64748b;">เพศ</div>
            <div style="font-size: 1.1rem; font-weight: 600; color: #0f172a;">
                <?= $student['gender'] === 'Male' ? 'ชาย' : ($student['gender'] === 'Female' ? 'หญิง' : '-') ?>
            </div>
        </div>
        <div style="background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0;">
            <div style="font-size: 0.85rem; color: #64748b;">ศาสนา</div>
            <div style="font-size: 1.1rem; font-weight: 600; color: #0f172a;">
                <?php 
                $rel = $student['religion'] ?? 'Buddhism';
                $r_text = 'พุทธ';
                if ($rel == 'Islam') $r_text = 'อิสลาม';
                elseif ($rel == 'Christianity') $r_text = 'คริสต์';
                elseif ($rel == 'Other') $r_text = 'อื่นๆ';
                echo $r_text;
                ?>
            </div>
        </div>
        <div style="background: <?= $behavior_score < 60 ? '#fef2f2' : '#f0fdf4' ?>; padding: 15px; border-radius: 10px; border: 1px solid <?= $behavior_score < 60 ? '#fecaca' : '#bbf7d0' ?>;">
            <div style="font-size: 0.85rem; color: <?= $behavior_score < 60 ? '#991b1b' : '#166534' ?>;">คะแนนความประพฤติ</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: <?= $behavior_score < 60 ? '#dc2626' : '#16a34a' ?>; line-height: 1;">
                <?= $behavior_score ?>
            </div>
        </div>
    </div>

    <div style="text-align: left;">
        <h3 style="font-size: 1.1rem; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px;">📚 รายวิชาที่เรียน (ภาคเรียนปัจจุบัน)</h3>
        <?php if (empty($subjects)): ?>
            <p style="color: #94a3b8; font-style: italic;">ยังไม่มีข้อมูลรายวิชา</p>
        <?php else: ?>
            <ul style="padding-left: 20px; color: #334155; line-height: 1.6;">
                <?php foreach ($subjects as $sub): ?>
                    <li><strong><?= htmlspecialchars($sub['subject_code']) ?></strong> - <?= htmlspecialchars($sub['subject_name']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
