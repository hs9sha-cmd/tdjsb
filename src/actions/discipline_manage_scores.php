<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "จัดการคะแนนกรณีพิเศษ";
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

// Handle Quick Score Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_scores'])) {
    $student_ids = $_POST['student_ids'] ?? [];
    $points = (int)($_POST['points'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $record_date = $_POST['record_date'] ?? date('Y-m-d');
    
    if (empty($student_ids)) {
        $error = "กรุณาเลือกนักเรียนอย่างน้อย 1 คน";
    } elseif ($points == 0) {
        $error = "กรุณาระบุคะแนน (ห้ามเป็น 0)";
    } elseif (empty($reason)) {
        $error = "กรุณาระบุเหตุผล";
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO behavior_records (student_id, academic_term_id, recorded_by_teacher_id, record_date, points, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'approved')");
            foreach ($student_ids as $sid) {
                $stmt->execute([$sid, $term_id, $discipline_teacher_id, $record_date, $points, $reason]);
                updateCurrentScore($pdo, $sid, $term_id);
            }
            $pdo->commit();
            $success = "บันทึกคะแนนให้นักเรียนจำนวน " . count($student_ids) . " คน สำเร็จแล้ว";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// Fetch Cases (Safe Check)
$cases = [];
$db_needs_migration = false;
try {
    $stmt_cases = $pdo->prepare("
        SELECT dc.*, u.full_name as reported_by_name, 
               (SELECT COUNT(*) FROM discipline_case_students WHERE case_id = dc.id) as student_count
        FROM discipline_cases dc
        LEFT JOIN users u ON dc.reported_by = u.id
        WHERE dc.academic_term_id = ?
        ORDER BY dc.created_at DESC
    ");
    $stmt_cases->execute([$term_id]);
    $cases = $stmt_cases->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Base table or view not found") !== false) {
        $db_needs_migration = true;
    }
}

// Fetch all students for Tom Select
$stmt_all_students = $pdo->prepare("SELECT id, student_code, full_name FROM students WHERE status = 'active' ORDER BY student_code ASC");
$stmt_all_students->execute();
$all_students = $stmt_all_students->fetchAll();

$tab = $_GET['tab'] ?? 'cases';
?>

<!-- Tom Select CSS & JS -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 class="card-title" style="margin: 0;">⚖️ จัดการคะแนนกรณีพิเศษ</h2>
        <a href="discipline_dashboard.php" class="btn btn-secondary">กลับหน้าหลัก</a>
    </div>

    <?php if($success): ?><div class="badge badge-success mb-3" style="display: block; padding: 10px;"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="badge badge-danger mb-3" style="display: block; padding: 10px;"><?= $error ?></div><?php endif; ?>

    <!-- Tabs Navigation -->
    <div style="display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px;">
        <a href="?tab=cases" style="text-decoration: none; padding: 8px 16px; border-radius: 8px; font-weight: bold; background: <?= $tab == 'cases' ? 'var(--primary-color)' : 'transparent' ?>; color: <?= $tab == 'cases' ? 'white' : 'var(--text-main)' ?>;">📂 ทะเบียนคดีความประพฤติ</a>
        <a href="?tab=quick" style="text-decoration: none; padding: 8px 16px; border-radius: 8px; font-weight: bold; background: <?= $tab == 'quick' ? 'var(--primary-color)' : 'transparent' ?>; color: <?= $tab == 'quick' ? 'white' : 'var(--text-main)' ?>;">⚡ เพิ่ม/หักคะแนนด่วน</a>
    </div>

    <?php if ($tab == 'cases'): ?>
        <!-- CASE MANAGEMENT SECTION -->
        <?php if ($db_needs_migration): ?>
            <div style="background: #fffbeb; border: 1px solid #fde68a; padding: 20px; border-radius: 8px; text-align: center;">
                <h3 style="color: #d97706; margin-top: 0;">⚠️ ระบบยังไม่พร้อมใช้งาน</h3>
                <p>พบว่ายังไม่ได้สร้างตารางฐานข้อมูลสำหรับระบบคดี กรุณารันสคริปต์ Migration เพื่อสร้างตารางก่อนใช้งานครับ</p>
                <a href="db_migrate_cases.php" target="_blank" class="btn btn-primary" style="margin-top: 10px;">รันการตั้งค่าฐานข้อมูล (Migration)</a>
                <p style="font-size: 0.85rem; color: #6b7280; margin-top: 10px;">* เมื่อรันเสร็จแล้ว ให้รีเฟรชหน้านี้อีกครั้ง</p>
            </div>
        <?php else: ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; color: var(--text-main);">รายการคดีภาคเรียนปัจจุบัน</h3>
                <a href="discipline_case_create.php" class="btn btn-primary" style="background: var(--success-color);">+ สร้างคดีใหม่</a>
            </div>

            <?php if (empty($cases)): ?>
                <div style="text-align: center; padding: 40px; background: #f8fafc; border-radius: 8px; border: 1px dashed #cbd5e1;">
                    <p style="color: #64748b; font-size: 1.1rem; margin-bottom: 0;">ยังไม่มีการบันทึกคดีในภาคเรียนนี้</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead style="background: #f1f5f9;">
                            <tr>
                                <th>รหัสคดี</th>
                                <th>ชื่อคดี</th>
                                <th style="text-align: center;">นร. ที่เกี่ยวข้อง</th>
                                <th>ผู้บันทึก</th>
                                <th>วันที่บันทึก</th>
                                <th style="text-align: center;">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cases as $c): ?>
                            <tr>
                                <td style="font-weight: 600; color: var(--primary-color);"><?= htmlspecialchars($c['case_code']) ?></td>
                                <td><?= htmlspecialchars($c['case_name']) ?></td>
                                <td style="text-align: center;"><span class="badge" style="background: #e2e8f0; color: #475569;"><?= $c['student_count'] ?> คน</span></td>
                                <td><?= htmlspecialchars($c['reported_by_name']) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></td>
                                <td style="text-align: center;">
                                    <a href="discipline_case_view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline">ดูข้อมูล/พิมพ์</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($tab == 'quick'): ?>
        <!-- QUICK SCORE ADJUST SECTION (Legacy) -->
        <form method="POST" style="background: var(--apple-bg); padding: 20px; border-radius: 8px;">
            <div style="background: #eff6ff; padding: 15px; border-radius: 8px; border-left: 4px solid #3b82f6; margin-bottom: 20px;">
                <strong>💡 หมายเหตุ:</strong> การหักคะแนนส่วนนี้เป็นการหักแบบอิสระ ไม่ได้ผูกกับรูปคดี เหมาะสำหรับความผิดเล็กน้อยทั่วไป หรือการเพิ่มคะแนนความดี
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: bold; margin-bottom: 5px; display: block;">วันที่บันทึก</label>
                <input type="date" name="record_date" class="form-control" value="<?= date('Y-m-d') ?>" required style="max-width: 250px;">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="font-weight: bold; margin-bottom: 5px; display: block;">ค้นหาและเลือกนักเรียน (เลือกได้หลายคน)</label>
                <select id="student-select" name="student_ids[]" multiple placeholder="พิมพ์รหัส หรือ ชื่อนักเรียน เพื่อค้นหา..." required>
                    <option value="">ค้นหานักเรียน...</option>
                    <?php foreach($all_students as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= $s['student_code'] ?> - <?= htmlspecialchars($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                <div>
                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">คะแนนที่จะหัก/เพิ่ม (ใส่เครื่องหมาย - เพื่อหัก)</label>
                    <input type="number" name="points" class="form-control" placeholder="-10 หรือ 5" required>
                </div>
                <div>
                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">เหตุผล</label>
                    <input type="text" name="reason" class="form-control" placeholder="เช่น ช่วยเหลืองานโรงเรียน, คุยในห้องเรียน" required>
                </div>
            </div>

            <div style="text-align: right;">
                <button type="submit" name="submit_scores" class="btn btn-primary" style="padding: 10px 30px; font-size: 1.1rem;">บันทึกข้อมูลด่วน</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
    if (document.getElementById('student-select')) {
        new TomSelect("#student-select", {
            plugins: ['remove_button'],
            maxItems: null,
            valueField: 'value',
            labelField: 'text',
            searchField: 'text'
        });
    }
</script>

<?php include '../../includes/footer.php'; ?>
