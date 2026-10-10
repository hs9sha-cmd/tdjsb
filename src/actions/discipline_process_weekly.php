<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "ประมวลผลหักคะแนนรายสัปดาห์";
include '../../includes/header.php';

$success = '';
$error = '';
$discipline_teacher_id = $_SESSION['user_id'];

$stmt_term = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
$term_id = $stmt_term->fetchColumn();

if (!$term_id) {
    echo "<div class='card'><div class='badge badge-danger'>ไม่พบปีการศึกษาที่เปิดใช้งาน</div></div>";
    include '../../includes/footer.php';
    exit();
}

function updateCurrentScore($pdo, $student_id, $term_id) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO student_behaviors (student_id, academic_term_id, initial_score, current_score) VALUES (?, ?, 100, 100)");
    $stmt->execute([$student_id, $term_id]);
    
    $stmt_sum = $pdo->prepare("SELECT SUM(points) FROM behavior_records WHERE student_id = ? AND academic_term_id = ? AND status = 'approved'");
    $stmt_sum->execute([$student_id, $term_id]);
    $total_points = (int)$stmt_sum->fetchColumn();
    
    $new_score = 100 + $total_points;
    if ($new_score > 100) $new_score = 100;
    $stmt_upd = $pdo->prepare("UPDATE student_behaviors SET current_score = ? WHERE student_id = ? AND academic_term_id = ?");
    $stmt_upd->execute([$new_score, $student_id, $term_id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_weekly'])) {
    $pdo->beginTransaction();
    try {
        // Find all unique students who are currently failing, dodged, or excused (and haven't been fixed) in any OPEN inspection cycle
        $stmt_failing = $pdo->prepare("
            SELECT DISTINCT di.student_id 
            FROM discipline_inspections di
            JOIN inspection_cycles ic ON di.cycle_id = ic.id
            WHERE di.overall_status IN ('fail', 'dodged', 'excused')
            AND ic.status = 'open'
        ");
        $stmt_failing->execute();
        $students_to_penalize = $stmt_failing->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($students_to_penalize)) {
            $error = "ไม่มีนักเรียนที่ติดสถานะ 'ไม่ผ่าน', 'หนีตรวจ' หรือ 'ลาป่วย/ลากิจ' ค้างอยู่ในระบบขณะนี้";
        } else {
            $stmt_insert = $pdo->prepare("INSERT INTO behavior_records (student_id, academic_term_id, recorded_by_teacher_id, record_date, points, reason, status) VALUES (?, ?, ?, CURDATE(), -5, 'ไม่ผ่านการตรวจระเบียบ (ประมวลผลรายสัปดาห์)', 'approved')");
            
            $count = 0;
            foreach ($students_to_penalize as $sid) {
                // To prevent double deduction in the exact same week if they click twice,
                // we check if there's already a deduction for this exact reason in the last 5 days
                $stmt_check = $pdo->prepare("
                    SELECT id FROM behavior_records 
                    WHERE student_id = ? AND reason = 'ไม่ผ่านการตรวจระเบียบ (ประมวลผลรายสัปดาห์)' 
                    AND record_date >= DATE_SUB(CURDATE(), INTERVAL 5 DAY)
                ");
                $stmt_check->execute([$sid]);
                if (!$stmt_check->fetch()) {
                    $stmt_insert->execute([$sid, $term_id, $discipline_teacher_id]);
                    updateCurrentScore($pdo, $sid, $term_id);
                    $count++;
                }
            }
            $pdo->commit();
            if ($count > 0) {
                $success = "ประมวลผลหักคะแนน 5 คะแนน ให้นักเรียนจำนวน " . $count . " คน สำเร็จแล้ว";
            } else {
                $error = "ได้ทำการหักคะแนนรายสัปดาห์ไปแล้วเมื่อเร็วๆ นี้ (ระบบป้องกันการกดซ้ำซ้อน)";
            }
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// Preview list of students who will be penalized
$stmt_preview = $pdo->prepare("
    SELECT s.student_code, s.full_name, c.room_name,
        (SELECT GROUP_CONCAT(DISTINCT di2.overall_status) 
         FROM discipline_inspections di2
         JOIN inspection_cycles ic2 ON di2.cycle_id = ic2.id
         WHERE di2.student_id = s.id 
         AND di2.overall_status IN ('fail', 'dodged', 'excused')
         AND ic2.status = 'open'
        ) as reasons
    FROM students s
    JOIN classrooms c ON s.classroom_id = c.id
    WHERE s.id IN (
        SELECT DISTINCT di.student_id 
        FROM discipline_inspections di
        JOIN inspection_cycles ic ON di.cycle_id = ic.id
        WHERE di.overall_status IN ('fail', 'dodged', 'excused')
        AND ic.status = 'open'
    )
    ORDER BY c.grade_level ASC, c.room_number ASC, s.student_number ASC
");
$stmt_preview->execute();
$preview_students = $stmt_preview->fetchAll();

?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin: 0;">ประมวลผลหักคะแนนระเบียบ (รายสัปดาห์)</h2>
        <a href="discipline_dashboard.php" class="btn btn-secondary">กลับหน้าหลัก</a>
    </div>

    <?php if($success): ?><div class="badge badge-success mb-3" style="display: block; padding: 10px;"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="badge badge-danger mb-3" style="display: block; padding: 10px;"><?= $error ?></div><?php endif; ?>

    <div style="background: #fffbeb; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fde68a;">
        <h4 style="margin-top: 0; color: #b45309;">⚠️ คำแนะนำ</h4>
        <p style="margin-bottom: 0; color: #92400e;">
            ปุ่มนี้จะทำการค้นหานักเรียนที่ติดสถานะ <strong>"ไม่ผ่าน"</strong>, <strong>"หนีตรวจ"</strong> หรือ <strong>"ลาป่วย/ลากิจ (ที่ยังไม่มาตรวจแก้)"</strong> จาก <u>รอบการตรวจที่ยัง "เปิด" อยู่เท่านั้น</u> และจะทำการหักคะแนนคนละ <strong>5 คะแนน</strong> โดยอัตโนมัติ<br>
            *ควรจะกดเพียงสัปดาห์ละ 1 ครั้ง (เช่น วันจันทร์) <br>
            *ระบบมีตัวป้องกันการหักซ้ำซ้อน หากมีการกดปุ่มนี้ซ้ำภายใน 5 วัน จะไม่หักซ้ำ
        </p>
    </div>

    <form method="POST" onsubmit="return confirm('ยืนยันการหักคะแนนรายสัปดาห์? นักเรียนทั้งหมดด้านล่างนี้จะถูกหักคนละ 5 คะแนนทันที');">
        <div style="text-align: center; margin-bottom: 30px;">
            <button type="submit" name="process_weekly" class="btn btn-danger" style="padding: 15px 40px; font-size: 1.2rem; border-radius: 30px;">⚡ ประมวลผลหักคะแนน 5 คะแนน ⚡</button>
        </div>
    </form>

    <h3 style="margin-top: 30px;">รายชื่อนักเรียนที่จะถูกหักคะแนนในครั้งนี้ (<?= count($preview_students) ?> คน)</h3>
    <?php if(empty($preview_students)): ?>
        <p style="color: var(--text-muted); text-align: center; margin-top: 20px;">ไม่มีนักเรียนที่ต้องถูกหักคะแนน 🎉</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>ลำดับ</th>
                        <th>รหัสนักเรียน</th>
                        <th>ชื่อ - นามสกุล</th>
                        <th>ห้องเรียน</th>
                        <th>สาเหตุที่โดนหักคะแนน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($preview_students as $index => $s): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= $s['student_code'] ?></td>
                        <td><?= htmlspecialchars($s['full_name']) ?></td>
                        <td><?= htmlspecialchars($s['room_name']) ?></td>
                        <td>
                            <?php 
                                $reason_texts = [];
                                if (strpos($s['reasons'], 'fail') !== false) $reason_texts[] = '<span class="badge" style="background:#fef2f2; color:#ef4444; border: 1px solid #fca5a5;">ไม่ผ่าน</span>';
                                if (strpos($s['reasons'], 'dodged') !== false) $reason_texts[] = '<span class="badge" style="background:#fffbeb; color:#f59e0b; border: 1px solid #fcd34d;">หนีตรวจ</span>';
                                if (strpos($s['reasons'], 'excused') !== false) $reason_texts[] = '<span class="badge" style="background:#eff6ff; color:#3b82f6; border: 1px solid #93c5fd;">ลาป่วย/ลากิจ</span>';
                                echo implode(" ", $reason_texts);
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
