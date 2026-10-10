<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "จัดการรอบการตรวจระเบียบ";
include '../../includes/header.php';

$success = '';
$error = '';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_cycle'])) {
        $cycle_name = trim($_POST['cycle_name']);
        $inspection_date = $_POST['inspection_date'];
        $target_grade = $_POST['target_grade_level'];
        
        if ($cycle_name && $inspection_date) {
            $stmt = $pdo->prepare("INSERT INTO inspection_cycles (academic_term_id, cycle_name, inspection_date, target_grade_level, status) VALUES (?, ?, ?, ?, 'open')");
            $stmt->execute([$term_id, $cycle_name, $inspection_date, $target_grade]);
            
            // Pre-populate discipline_inspections for all students in the target grade
            $cycle_id = $pdo->lastInsertId();
            
            if ($target_grade === 'all') {
                $stmt_students = $pdo->prepare("SELECT id FROM students WHERE status = 'active'");
                $stmt_students->execute();
            } else {
                $stmt_students = $pdo->prepare("
                    SELECT s.id 
                    FROM students s
                    JOIN classrooms c ON s.classroom_id = c.id
                    WHERE s.status = 'active' AND c.grade_level = ?
                ");
                $stmt_students->execute([$target_grade]);
            }
            
            $students = $stmt_students->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($students)) {
                $insert_sql = "INSERT IGNORE INTO discipline_inspections (cycle_id, student_id, overall_status) VALUES ";
                $insert_values = [];
                $params = [];
                foreach ($students as $sid) {
                    $insert_values[] = "(?, ?, 'pending')";
                    $params[] = $cycle_id;
                    $params[] = $sid;
                }
                $insert_sql .= implode(", ", $insert_values);
                $stmt_ins = $pdo->prepare($insert_sql);
                $stmt_ins->execute($params);
            }
            
            $success = "สร้างรอบการตรวจระเบียบและเพิ่มรายชื่อนักเรียนสำเร็จแล้ว";
        }
    } elseif (isset($_POST['close_cycle'])) {
        $cycle_id = $_POST['cycle_id'];
        $stmt = $pdo->prepare("UPDATE inspection_cycles SET status = 'closed' WHERE id = ?");
        $stmt->execute([$cycle_id]);
        $success = "ปิดรอบการตรวจเรียบร้อยแล้ว";
    } elseif (isset($_POST['open_cycle'])) {
        $cycle_id = $_POST['cycle_id'];
        $stmt = $pdo->prepare("UPDATE inspection_cycles SET status = 'open' WHERE id = ?");
        $stmt->execute([$cycle_id]);
        $success = "เปิดรอบการตรวจใหม่อีกครั้งแล้ว";
    } elseif (isset($_POST['delete_cycle'])) {
        $cycle_id = $_POST['cycle_id'];
        
        $stmt_cn = $pdo->prepare("SELECT cycle_name, academic_term_id FROM inspection_cycles WHERE id = ?");
        $stmt_cn->execute([$cycle_id]);
        $cyc = $stmt_cn->fetch();
        
        if ($cyc) {
            $reason_fail = "ไม่ผ่านการตรวจระเบียบรอบ: " . $cyc['cycle_name'];
            $reason_dodged = "หนีการตรวจระเบียบรอบ: " . $cyc['cycle_name'];
            
            $stmt_aff = $pdo->prepare("SELECT DISTINCT student_id FROM behavior_records WHERE reason IN (?, ?)");
            $stmt_aff->execute([$reason_fail, $reason_dodged]);
            $affected_students = $stmt_aff->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($affected_students)) {
                $stmt_del_br = $pdo->prepare("DELETE FROM behavior_records WHERE reason IN (?, ?)");
                $stmt_del_br->execute([$reason_fail, $reason_dodged]);
                
                foreach ($affected_students as $sid) {
                    updateCurrentScore($pdo, $sid, $cyc['academic_term_id']);
                }
            }
        }
        
        // Delete related inspections
        $stmt_del_ins = $pdo->prepare("DELETE FROM discipline_inspections WHERE cycle_id = ?");
        $stmt_del_ins->execute([$cycle_id]);
        
        // Delete cycle
        $stmt_del_cyc = $pdo->prepare("DELETE FROM inspection_cycles WHERE id = ?");
        $stmt_del_cyc->execute([$cycle_id]);
        
        $success = "ลบรอบการตรวจและข้อมูลที่เกี่ยวข้องเรียบร้อยแล้ว";
    }
}

$cycles = $pdo->prepare("SELECT * FROM inspection_cycles WHERE academic_term_id = ? ORDER BY inspection_date DESC, created_at DESC");
$cycles->execute([$term_id]);
$cycles = $cycles->fetchAll();
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin: 0; font-size: 0.95rem;">รอบการตรวจระเบียบ</h2>
        <a href="discipline_dashboard.php" class="btn btn-outline btn-sm">กลับหน้าหลัก</a>
    </div>

    <?php if($success): ?><div class="badge badge-success mb-3" style="display: block; padding: 10px;"><?= $success ?></div><?php endif; ?>

    <form method="POST" style="background: var(--bg-main); padding: 16px; border-radius: 12px; margin-bottom: 24px; border: 1px solid var(--border-color);">
        <h3 style="margin-top: 0; font-size: 0.88rem; font-weight: 600; color: var(--text-main);">สร้างรอบการตรวจใหม่</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: end;">
            <div>
                <label style="font-size: 0.8rem; color: var(--text-muted);">ชื่อรอบการตรวจ</label>
                <input type="text" name="cycle_name" class="form-control" required>
            </div>
            <div>
                <label style="font-size: 0.8rem; color: var(--text-muted);">วันที่ตรวจ</label>
                <input type="date" name="inspection_date" class="form-control" required>
            </div>
            <div>
                <label style="font-size: 0.8rem; color: var(--text-muted);">ระดับชั้น</label>
                <select name="target_grade_level" class="form-control">
                    <option value="all">ทั้งหมด (ทุกระดับชั้น)</option>
                    <option value="ม.1">ม.1</option>
                    <option value="ม.2">ม.2</option>
                    <option value="ม.3">ม.3</option>
                    <option value="ม.4">ม.4</option>
                    <option value="ม.5">ม.5</option>
                    <option value="ม.6">ม.6</option>
                </select>
            </div>
            <div>
                <button type="submit" name="create_cycle" class="btn btn-primary btn-sm" style="width: 100%;">สร้างรอบตรวจ</button>
            </div>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>วันที่ตรวจ</th>
                    <th>ชื่อรอบการตรวจ</th>
                    <th>ระดับชั้นเป้าหมาย</th>
                    <th>สถานะ</th>
                    <th>จำนวนนักเรียน</th>
                    <th>การจัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($cycles as $c): 
                    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM discipline_inspections WHERE cycle_id = ?");
                    $stmt_count->execute([$c['id']]);
                    $student_count = $stmt_count->fetchColumn();
                ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($c['inspection_date'])) ?></td>
                    <td><?= htmlspecialchars($c['cycle_name']) ?></td>
                    <td><?= $c['target_grade_level'] === 'all' ? 'ทุกระดับชั้น' : $c['target_grade_level'] ?></td>
                    <td>
                        <?php if($c['status'] == 'open'): ?>
                            <span class="badge badge-success">กำลังเปิดตรวจ</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">ปิดแล้ว</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $student_count ?> คน</td>
                    <td>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="cycle_id" value="<?= $c['id'] ?>">
                            <?php if($c['status'] == 'open'): ?>
                                <button type="submit" name="close_cycle" class="btn btn-outline btn-sm" onclick="return confirm('ยืนยันการปิดรอบนี้? จะไม่สามารถตรวจหรือแก้ตัวเพิ่มได้อีก');">ปิดรอบ</button>
                            <?php else: ?>
                                <button type="submit" name="open_cycle" class="btn btn-outline btn-sm">เปิดรอบใหม่</button>
                            <?php endif; ?>
                            <button type="submit" name="delete_cycle" class="btn btn-outline btn-sm" onclick="return confirm('⚠️ คำเตือน! ยืนยันการลบรอบการตรวจนี้หรือไม่? ข้อมูลการตรวจของนักเรียนทุกคนในรอบนี้จะถูกลบทิ้งถาวร!');" style="border-color: var(--danger-color); color: var(--danger-color);">ลบ</button>
                        </form>
                        <a href="teacher_inspection.php?cycle_id=<?= $c['id'] ?>&role_mode=discipline" class="btn btn-primary btn-sm">เข้าไปตรวจ</a>
                        <a href="discipline_cycle_stats.php?cycle_id=<?= $c['id'] ?>" class="btn btn-sm" style="background: var(--info-color); color: white; border: none;">📊 สถิติ</a>
                        <a href="discipline_cycle_details.php?cycle_id=<?= $c['id'] ?>" class="btn btn-sm" style="background: #fef08a; color: #854d0e; border: none;">🔍 ข้อมูลอย่างละเอียด</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($cycles)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--text-muted);">ยังไม่มีรอบการตรวจ</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
