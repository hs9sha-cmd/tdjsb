<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
$is_discipline_role = in_array('discipline', $user_roles) || in_array('admin', $user_roles) || in_array('executive', $user_roles);
$is_teacher = in_array('teacher', $user_roles);
$role_mode = $_GET['role_mode'] ?? 'teacher';

// Apply discipline privileges ONLY if accessed via discipline mode (or if they are not a teacher at all)
$is_discipline = $is_discipline_role && ($role_mode === 'discipline' || !$is_teacher);

if (!$is_discipline && !$is_teacher) {
    echo "Access Denied";
    exit();
}

$page_title = "บันทึกการตรวจระเบียบ";
include '../../includes/header.php';

$teacher_id = $_SESSION['user_id'];
$success = '';
$error = '';
$cycle_id = $_GET['cycle_id'] ?? 0;

$stmt_term = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
$term_id = $stmt_term->fetchColumn();

if (!$term_id || !$cycle_id) {
    echo "<div class='card'><div class='badge badge-danger'>ข้อมูลไม่ถูกต้อง</div></div>";
    include '../../includes/footer.php';
    exit();
}

// Get Cycle Info
$stmt_cycle = $pdo->prepare("SELECT * FROM inspection_cycles WHERE id = ? AND academic_term_id = ?");
$stmt_cycle->execute([$cycle_id, $term_id]);
$cycle = $stmt_cycle->fetch();

if (!$cycle) {
    echo "<div class='card'><div class='badge badge-danger'>ไม่พบรอบการตรวจนี้</div></div>";
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_inspections']) || isset($_POST['submit_and_lock']))) {
    $submitting_classroom_id = $_POST['submit_classroom_id'] ?? null;
    
    // Check if room is already locked
    $is_already_locked = false;
    if ($submitting_classroom_id) {
        $stmt_lock = $pdo->prepare("SELECT id FROM inspection_room_locks WHERE cycle_id = ? AND classroom_id = ?");
        $stmt_lock->execute([$cycle_id, $submitting_classroom_id]);
        $is_already_locked = $stmt_lock->fetch() ? true : false;
    }

    if ($cycle['status'] !== 'open') {
        $error = "รอบการตรวจนี้ถูกปิดไปแล้ว ไม่สามารถแก้ไขได้";
    } elseif ($is_already_locked && !$is_discipline) {
        $error = "ห้องเรียนนี้ถูกยืนยันส่งผลตรวจและล็อคไปแล้ว ไม่สามารถแก้ไขได้";
    } else {
        $inspections = $_POST['inspections'] ?? [];
        $pdo->beginTransaction();
        try {
            $stmt_update = $pdo->prepare("
                UPDATE discipline_inspections 
                SET hair_status = ?, nail_status = ?, accessory_status = ?, shoes_status = ?, overall_status = ?, inspector_id = ?
                WHERE id = ? AND cycle_id = ?
            ");
            
            $stmt_behavior = $pdo->prepare("INSERT INTO behavior_records (student_id, academic_term_id, recorded_by_teacher_id, record_date, points, reason, status) VALUES (?, ?, ?, CURDATE(), ?, ?, 'approved')");
            
            foreach ($inspections as $insp_id => $data) {
                $stmt_orig = $pdo->prepare("SELECT overall_status, student_id FROM discipline_inspections WHERE id = ?");
                $stmt_orig->execute([$insp_id]);
                $orig = $stmt_orig->fetch();
                if (!$orig) continue;
                
                $hair = isset($data['hair']) ? 1 : 0;
                $nail = isset($data['nail']) ? 1 : 0;
                $accessory = isset($data['accessory']) ? 1 : 0;
                $shoes = isset($data['shoes']) ? 1 : 0;
                $overall = $data['overall_status'];
                
                // Removing the strict lock on 'fail' and 'dodged' so that if a room is unlocked, the teacher can fully edit these statuses.
                $stmt_update->execute([$hair, $nail, $accessory, $shoes, $overall, $teacher_id, $insp_id, $cycle_id]);
                
                if (in_array($orig['overall_status'], ['pass', 'excused', 'pending'])) {
                    if ($overall === 'fail' || $overall === 'dodged') {
                        $reason = $overall === 'fail' ? "ไม่ผ่านการตรวจระเบียบรอบ: " . $cycle['cycle_name'] : "หนีการตรวจระเบียบรอบ: " . $cycle['cycle_name'];
                        
                        $stmt_chk = $pdo->prepare("SELECT id FROM behavior_records WHERE student_id = ? AND reason = ?");
                        $stmt_chk->execute([$orig['student_id'], $reason]);
                        if (!$stmt_chk->fetch()) {
                            $stmt_behavior->execute([$orig['student_id'], $term_id, $teacher_id, -5, $reason]);
                            updateCurrentScore($pdo, $orig['student_id'], $term_id);
                        }
                    }
                }
            }
            
            if (isset($_POST['submit_and_lock']) && $submitting_classroom_id) {
                // Check if any student is still pending
                $stmt_check_pending = $pdo->prepare("
                    SELECT COUNT(*) FROM discipline_inspections di
                    JOIN students s ON di.student_id = s.id
                    WHERE di.cycle_id = ? AND s.classroom_id = ? AND di.overall_status = 'pending'
                ");
                $stmt_check_pending->execute([$cycle_id, $submitting_classroom_id]);
                $pending_count = $stmt_check_pending->fetchColumn();

                if ($pending_count > 0) {
                    // Do not lock, throw an error
                    $error = "ไม่สามารถล็อคห้องได้! ยังมีนักเรียนที่ไม่ได้ระบุผลการตรวจ (⏳ รอดำเนินการ) จำนวน $pending_count คน";
                } else {
                    // Insert Lock
                    $stmt_ins_lock = $pdo->prepare("INSERT IGNORE INTO inspection_room_locks (cycle_id, classroom_id, locked_by_teacher_id) VALUES (?, ?, ?)");
                    $stmt_ins_lock->execute([$cycle_id, $submitting_classroom_id, $teacher_id]);
                    $success = "บันทึกผลและยืนยันการส่ง (ล็อคห้อง) เรียบร้อยแล้ว";
                }
            } else {
                $success = "บันทึกข้อมูล(ชั่วคราว)เรียบร้อยแล้ว";
            }
            
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// Get Students for this cycle
// If teacher, only their homeroom. If discipline, all.
$query = "
    SELECT di.*, s.student_code, s.full_name, s.student_number, c.room_name, c.grade_level, c.room_number,
           ha.status as today_attendance
    FROM discipline_inspections di
    JOIN students s ON di.student_id = s.id
    JOIN classrooms c ON s.classroom_id = c.id
    LEFT JOIN homeroom_attendance ha ON ha.student_id = s.id AND ha.attendance_date = ?
    WHERE di.cycle_id = ?
";
$params = [$cycle['inspection_date'], $cycle_id];

// Get available classrooms for filter
$stmt_classrooms = $pdo->prepare("
    SELECT DISTINCT c.id, c.room_name, c.grade_level, c.room_number
    FROM discipline_inspections di
    JOIN students s ON di.student_id = s.id
    JOIN classrooms c ON s.classroom_id = c.id
    " . (!$is_discipline ? "JOIN users u ON c.room_name = u.advisor_classroom" : "") . "
    WHERE di.cycle_id = ?
    " . (!$is_discipline ? "AND u.id = ?" : "") . "
    ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC
");

if (!$is_discipline) {
    $stmt_classrooms->execute([$cycle_id, $teacher_id]);
    
    // Check homeroom in main query
    $query .= " AND c.room_name IN (SELECT advisor_classroom FROM users WHERE id = ?)";
    $params[] = $teacher_id;
} else {
    $stmt_classrooms->execute([$cycle_id]);
}
$available_classrooms = $stmt_classrooms->fetchAll();

$selected_classroom = $_GET['classroom_id'] ?? ($available_classrooms[0]['id'] ?? null);

if ($selected_classroom) {
    $query .= " AND c.id = ?";
    $params[] = $selected_classroom;
}

$query .= " ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC, s.student_number ASC";

$stmt_students = $pdo->prepare($query);
$stmt_students->execute($params);
$students = $stmt_students->fetchAll();

$is_room_locked = false;
if ($selected_classroom) {
    $stmt_check_lock = $pdo->prepare("SELECT id FROM inspection_room_locks WHERE cycle_id = ? AND classroom_id = ?");
    $stmt_check_lock->execute([$cycle_id, $selected_classroom]);
    $is_room_locked = $stmt_check_lock->fetch() ? true : false;
}


?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin: 0;">ตรวจระเบียบ: <?= htmlspecialchars($cycle['cycle_name']) ?></h2>
        <div>
            <?php if ($is_discipline): ?>
                <a href="discipline_inspection_cycles.php" class="btn btn-secondary">กลับหน้าจัดการรอบ</a>
            <?php else: ?>
                <a href="teacher_behavior.php" class="btn btn-secondary">กลับ</a>
            <?php endif; ?>
        </div>
    </div>

    <p style="color: var(--text-muted); margin-bottom: 20px;">
        วันที่ตรวจ: <?= date('d/m/Y', strtotime($cycle['inspection_date'])) ?> | 
        ระดับชั้นเป้าหมาย: <?= $cycle['target_grade_level'] === 'all' ? 'ทุกระดับชั้น' : htmlspecialchars($cycle['target_grade_level']) ?>
    </p>

    <?php if($success): ?><div class="badge badge-success mb-3" style="display: block; padding: 10px;"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="badge badge-danger mb-3" style="display: block; padding: 10px;"><?= $error ?></div><?php endif; ?>

    <div style="background: var(--bg-main); padding: 14px; border-radius: 12px; margin-bottom: 20px; border: 1px solid var(--border-color);">
        <h4 style="margin-top: 0; color: var(--text-main); margin-bottom: 5px; font-size: 0.85rem; font-weight: 600;">คำแนะนำ</h4>
        <ul style="margin-bottom: 0; color: var(--text-muted); padding-left: 20px; font-size: 0.8rem; line-height: 1.7;">
            <li>สถานะ <strong style="color: var(--text-main);">ไม่ผ่าน</strong> หรือ <strong style="color: var(--text-main);">หนีตรวจ</strong> จะถูกหัก 5 คะแนนทันที และจะถูกหักอีก 5 คะแนนทุกสัปดาห์จนกว่าครูจะเปลี่ยนเป็น "ผ่าน"</li>
            <li>สถานะ <strong style="color: var(--text-main);">ลาป่วย/ลากิจ</strong> จะไม่ถูกหักคะแนน <em>(ผู้ปกครองต้องแจ้งเรื่องนี้ต่อครูที่ปรึกษา)</em> และสถานะจะแขวนไว้รอให้เด็กมาตรวจย้อนหลัง</li>
            <li>เครื่องหมายถูก (✅) ในช่อง ทรงผม/เล็บ/ไม่แต่งหน้า/เครื่องแบบ มีไว้เพื่อช่วยจำจุดที่ผิดระเบียบ สถานะผลลัพธ์จริงๆ จะยึดตามช่อง <strong style="color: var(--text-main);">"สถานะรวม"</strong> เป็นหลัก</li>
        </ul>
    </div>

    <?php if (!empty($available_classrooms)): ?>
        <form method="GET" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: flex-end;">
            <input type="hidden" name="cycle_id" value="<?= $cycle_id ?>">
            <?php if (isset($_GET['role_mode'])): ?>
                <input type="hidden" name="role_mode" value="<?= htmlspecialchars($_GET['role_mode']) ?>">
            <?php endif; ?>
            <div class="form-group" style="margin-bottom: 0;">
                <label>เลือกห้องเรียนเพื่อตรวจ</label>
                <select name="classroom_id" class="form-control" onchange="this.form.submit()">
                    <?php foreach($available_classrooms as $hr): ?>
                        <option value="<?= $hr['id'] ?>" <?= $selected_classroom == $hr['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($hr['room_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($is_room_locked): ?>
        <div class="badge badge-warning mb-3" style="display: block; padding: 10px; font-size: 0.9rem;">
            🔒 ห้องเรียนนี้ยืนยันผลการตรวจและล็อคแล้ว หากต้องการแก้ไขกรุณาแจ้งฝ่ายปกครอง
        </div>
    <?php endif; ?>

    <?php if (empty($students)): ?>
        <p style="text-align: center; color: var(--text-muted); padding: 30px;">ไม่มีนักเรียนในความดูแลของคุณในรอบการตรวจนี้</p>
    <?php else: ?>
        <form method="POST">
            <div class="table-responsive" style="max-height: calc(100vh - 200px); overflow-y: auto; overflow-x: auto; padding-bottom: 20px;">
                <style>
    @media (max-width: 600px) {
        .compact-select { font-size: 0.75rem !important; padding: 4px !important; }
        .cb-scale { transform: scale(1.2) !important; }
    }
    @media (min-width: 601px) {
        .compact-select { font-size: 0.9rem !important; }
        .cb-scale { transform: scale(1.5) !important; }
    }
</style>
<table class="table" style="width: 100%; text-align: center; font-size: 0.85rem; margin-bottom: 0;">
                    <thead style="position: sticky; top: 0; background: #fff; z-index: 10; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                        <tr>
                            <th style="text-align: left; padding: 10px 5px;">ชื่อ-สกุล</th>
                            <th style="width: 40px; padding: 10px 2px; text-align: center;">ผม</th>
                            <th style="width: 40px; padding: 10px 2px; text-align: center;">เล็บ</th>
                            <th style="width: 40px; padding: 10px 2px; text-align: center;">หน้า</th>
                            <th style="width: 40px; padding: 10px 2px; text-align: center;" title="โบว์, เสื้อ, กางเกง, กระโปรง, ถุงเท้า, รองเท้า, เข็ม">ชุด ℹ️</th>
                            <th style="width: 100px; padding: 10px 5px;">สถานะรวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($students as $s): ?>
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="text-align: left; padding: 10px 5px; vertical-align: middle;">
                                <div style="display: flex; flex-direction: column; gap: 2px;">
                                    <div style="display: flex; align-items: center; gap: 4px;">
                                        <span style="background: #cbd5e1; color: #1e293b; font-size: 0.65rem; padding: 1px 4px; border-radius: 4px; font-weight: bold; min-width: 18px; text-align: center;"><?= $s['student_number'] ?></span>
                                        <span style="font-size: 0.65rem; color: #64748b;"><?= $s['student_code'] ?></span>
                                    </div>
                                    <div style="font-weight: 600; font-size: 0.85rem; color: #0f172a; line-height: 1.1;">
                                        <?= htmlspecialchars(str_replace(['นาย','นางสาว','เด็กชาย','เด็กหญิง'], '', $s['full_name'])) ?>
                                        <?php if (in_array($s['today_attendance'], ['sick', 'leave', 'absent'])): ?>
                                            <div style="display: inline-block; background: #fef08a; color: #854d0e; font-size: 0.6rem; padding: 1px 4px; border-radius: 4px; font-weight: bold; margin-left: 2px;">
                                                ⚠️ ลา/ขาด
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <?php
                            $is_locked_input = ($is_room_locked && !$is_discipline);
                            ?>
                            <td style="padding: 10px 2px; vertical-align: middle;"><input type="checkbox" name="inspections[<?= $s['id'] ?>][hair]" value="1" <?= $s['hair_status'] ? 'checked' : '' ?> <?= $is_locked_input ? 'disabled' : '' ?> class="cb-scale"></td>
                            <td style="padding: 10px 2px; vertical-align: middle;"><input type="checkbox" name="inspections[<?= $s['id'] ?>][nail]" value="1" <?= $s['nail_status'] ? 'checked' : '' ?> <?= $is_locked_input ? 'disabled' : '' ?> class="cb-scale"></td>
                            <td style="padding: 10px 2px; vertical-align: middle;"><input type="checkbox" name="inspections[<?= $s['id'] ?>][accessory]" value="1" <?= $s['accessory_status'] ? 'checked' : '' ?> <?= $is_locked_input ? 'disabled' : '' ?> class="cb-scale"></td>
                            <td style="padding: 10px 2px; vertical-align: middle;"><input type="checkbox" name="inspections[<?= $s['id'] ?>][shoes]" value="1" <?= $s['shoes_status'] ? 'checked' : '' ?> <?= $is_locked_input ? 'disabled' : '' ?> class="cb-scale"></td>
                            <td style="padding: 10px 5px; vertical-align: middle;">
                                <?php if ($is_locked_input): ?>
                                    <input type="hidden" name="inspections[<?= $s['id'] ?>][overall_status]" value="<?= $s['overall_status'] ?>">
                                    <input type="hidden" name="inspections[<?= $s['id'] ?>][hair]" value="<?= $s['hair_status'] ? 1 : 0 ?>">
                                    <input type="hidden" name="inspections[<?= $s['id'] ?>][nail]" value="<?= $s['nail_status'] ? 1 : 0 ?>">
                                    <input type="hidden" name="inspections[<?= $s['id'] ?>][accessory]" value="<?= $s['accessory_status'] ? 1 : 0 ?>">
                                    <input type="hidden" name="inspections[<?= $s['id'] ?>][shoes]" value="<?= $s['shoes_status'] ? 1 : 0 ?>">
                                    <select class="form-control compact-select" disabled style="font-weight: bold; color: <?= $s['overall_status'] == 'dodged' ? '#991b1b' : ($s['overall_status'] == 'pass' ? '#16a34a' : '#dc2626') ?>; background-color: #f3f4f6; cursor: not-allowed; border: none; padding: 2px;">
                                        <option><?= $s['overall_status'] == 'pass' ? 'ผ่าน 🔒' : ($s['overall_status'] == 'dodged' ? 'หนี 🔒' : 'ไม่ผ่าน 🔒') ?></option>
                                    </select>
                                    <?php if(in_array($s['overall_status'], ['fail', 'dodged'])): ?>
                                        <div style="font-size: 0.65rem; color: #dc2626; margin-top: 2px; line-height: 1;">*ติดต่อฝ่ายปกครอง</div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <select name="inspections[<?= $s['id'] ?>][overall_status]" data-original-status="<?= $s['overall_status'] ?>" class="form-control compact-select status-select" style="font-weight: bold; padding-left: 2px; padding-right: 2px; color: <?= $s['overall_status'] == 'pass' ? '#16a34a' : ($s['overall_status'] == 'excused' ? '#f59e0b' : ($s['overall_status'] == 'pending' ? '#6b7280' : '#dc2626')) ?>;" onchange="this.style.color = this.value == 'pass' ? '#16a34a' : (this.value == 'excused' ? '#f59e0b' : (this.value == 'pending' ? '#6b7280' : '#dc2626'));">
                                        <option value="pending" <?= $s['overall_status'] === 'pending' ? 'selected' : '' ?> style="color: #6b7280;">รอตรวจ</option>
                                        <option value="pass" <?= $s['overall_status'] === 'pass' ? 'selected' : '' ?> style="color: #16a34a;">ผ่าน</option>
                                        <?php if ($s['overall_status'] === 'fail'): ?>
                                            <option value="fail" selected style="color: #dc2626;">ไม่ผ่าน (-5)</option>
                                        <?php endif; ?>
                                        <option value="dodged" <?= $s['overall_status'] === 'dodged' ? 'selected' : '' ?> style="color: #991b1b;">หนีตรวจ (-5)</option>
                                        <option value="excused" <?= $s['overall_status'] === 'excused' ? 'selected' : '' ?> style="color: #f59e0b;">ลา (รอ)</option>
                                    </select>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <input type="hidden" name="submit_classroom_id" value="<?= $selected_classroom ?>">
            <?php if ($cycle['status'] === 'open' && (!$is_room_locked || $is_discipline)): ?>
            <div style="margin-top: 20px; text-align: center; position: sticky; bottom: 0; background: rgba(255,255,255,0.95); padding: 15px; border-top: 1px solid #e2e8f0; z-index: 20;">
                <button type="submit" name="submit_and_lock" class="btn btn-success" style="padding: 12px 30px; font-size: 1.1rem; border-radius: 30px; box-shadow: 0 4px 12px rgba(22,163,74,0.3);" onclick="return validateAndConfirmSubmit();">✅ ยืนยันส่งผลตรวจ (ล็อคห้อง)</button>
            </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<script>
let isDirty = false;

// Auto-change overall status if any checkbox is unchecked
document.addEventListener('DOMContentLoaded', function() {
    const table = document.querySelector('.table');
    if (table) {
        table.addEventListener('change', function(e) {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') {
                isDirty = true; // Mark as dirty when any input changes
            }

            if (e.target.type === 'checkbox') {
                const tr = e.target.closest('tr');
                const checkboxes = tr.querySelectorAll('input[type="checkbox"]');
                const select = tr.querySelector('select');
                const originalStatus = select.getAttribute('data-original-status') || 'pending';
                
                let allChecked = true;
                checkboxes.forEach(cb => {
                    if (!cb.checked) allChecked = false;
                });
                
                // Auto-change to 'fail' if any checkbox is unchecked
                if (!allChecked && select.value !== 'fail') {
                    let failOpt = select.querySelector('option[value="fail"]');
                    if (!failOpt) {
                        failOpt = document.createElement('option');
                        failOpt.value = 'fail';
                        failOpt.text = 'ไม่ผ่าน (-5)';
                        failOpt.style.color = '#dc2626';
                        select.appendChild(failOpt);
                    }
                    select.value = 'fail';
                    select.dispatchEvent(new Event('change'));
                } else if (allChecked && select.value === 'fail') {
                    // If they fixed their mistake, go back to original status (unless originally fail, then pass)
                    if (originalStatus !== 'fail') {
                        select.value = originalStatus;
                    } else {
                        select.value = 'pass';
                    }
                    let failOpt = select.querySelector('option[value="fail"]');
                    if (failOpt) failOpt.remove();
                    select.dispatchEvent(new Event('change'));
                }
            } else if (e.target.tagName === 'SELECT') {
                // If they manually change the dropdown away from 'fail', remove the option 
                // so they can't manually select it again (forces them to use checkboxes)
                const select = e.target;
                if (select.value !== 'fail') {
                    let failOpt = select.querySelector('option[value="fail"]');
                    if (failOpt) {
                        failOpt.remove();
                    }
                }
            }
        });
    }

    // Clear dirty flag when the POST form is submitted (Saving)
    const postForm = document.querySelector('form[method="POST"]');
    if (postForm) {
        postForm.addEventListener('submit', function() {
            isDirty = false;
        });
    }

    // --- Custom Thai Dialog for clicking links ---
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        // If it's a link to another page (not #) and there are unsaved changes
        if (link && link.href && !link.href.includes('javascript:') && !link.href.includes('#') && isDirty) {
            e.preventDefault();
            if (confirm('⚠️ แจ้งเตือน!\nคุณมีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก\nต้องการออกจากหน้านี้โดยไม่บันทึกข้อมูลหรือไม่?')) {
                isDirty = false;
                window.location.href = link.href;
            }
        }
    });

    // --- Custom Thai Dialog for changing Classroom (GET Form) ---
    const getForm = document.querySelector('form[method="GET"]');
    if (getForm) {
        getForm.addEventListener('submit', function(e) {
            if (isDirty) {
                if (!confirm('⚠️ แจ้งเตือน!\nคุณมีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก\nต้องการเปลี่ยนห้องโดยไม่บันทึกข้อมูลหรือไม่?')) {
                    e.preventDefault(); // Stop changing classroom
                } else {
                    isDirty = false; // Allow changing
                }
            }
        });
    }

    // Intercept native browser navigation (Closing tab, Native back button)
    // Note: Browsers force their own language here for security reasons.
    window.addEventListener('beforeunload', function(e) {
        if (isDirty) {
            e.preventDefault();
            e.returnValue = 'คุณมีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก'; // Modern browsers ignore this text
        }
    });
});

function validateAndConfirmSubmit() {
    let pendingCount = 0;
    document.querySelectorAll('select.status-select').forEach(sel => {
        if (sel.value === 'pending') {
            pendingCount++;
            sel.closest('tr').style.backgroundColor = '#fef2f2'; // highlight row
        } else {
            sel.closest('tr').style.backgroundColor = '';
        }
    });
    
    if (pendingCount > 0) {
        alert('ไม่สามารถส่งผลตรวจได้! กรุณาระบุผลการตรวจ (ผ่าน/ไม่ผ่าน/ลา) ให้ครบทุกคนก่อนกดยืนยัน\n(พบนักเรียนตกค้าง ' + pendingCount + ' คน)');
        return false;
    }
    
    return confirm('ยืนยันการส่งผลตรวจและล็อคห้องนี้?\n(เมื่อส่งแล้วจะไม่สามารถกลับมาแก้ไขได้อีก นอกจากการกดแก้ไขรายบุคคล)');
}
</script>

<?php include '../../includes/footer.php'; ?>
