<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'teacher');

$page_title = "บันทึกการสอน & เช็คชื่อ";
$teacher_id = $_SESSION['user_id'];
$error = '';

// Get active inputs from GET
$sub_id = $_GET['sub_id'] ?? ($_POST['sub_id'] ?? null);
$substitution_info = null;

if ($sub_id) {
    $stmt = $pdo->prepare("SELECT * FROM teaching_substitutions WHERE id = ? AND substitute_teacher_id = ?");
    $stmt->execute([$sub_id, $teacher_id]);
    $substitution_info = $stmt->fetch();
}

if ($substitution_info) {
    $selected_date = $substitution_info['substitution_date'];
    $selected_periods_raw = $substitution_info['period'];
    $selected_subject = $substitution_info['subject_id'];
    $selected_classroom = $substitution_info['classroom_id'];
} else {
    $selected_date = $_GET['date'] ?? date('Y-m-d');
    $selected_periods_raw = $_GET['period'] ?? [];
    $selected_subject = $_GET['subject_id'] ?? '';
    $selected_classroom = $_GET['classroom_id'] ?? '';
}

// Support period as array or comma string
$selected_periods = is_array($selected_periods_raw) ? $selected_periods_raw : (trim($selected_periods_raw) ? explode(',', $selected_periods_raw) : []);

// Fetch ONLY Subjects assigned to this teacher
$stmt_sub = $pdo->prepare("SELECT DISTINCT s.id, s.subject_code, s.subject_name FROM teacher_subjects ts JOIN subjects s ON ts.subject_id = s.id WHERE ts.teacher_id = ? ORDER BY s.subject_code ASC");
$stmt_sub->execute([$teacher_id]);
$subjects = $stmt_sub->fetchAll();

// Fetch ONLY Classrooms assigned to this teacher
$stmt_cls = $pdo->prepare("SELECT DISTINCT c.id, c.grade_level, c.room_number, c.room_name FROM teacher_subjects ts JOIN classrooms c ON ts.classroom_id = c.id WHERE ts.teacher_id = ? ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC");
$stmt_cls->execute([$teacher_id]);
$classrooms = $stmt_cls->fetchAll();

// Fetch mapping for JS logic (Subject -> Classrooms)
$stmt_map = $pdo->prepare("SELECT subject_id, classroom_id FROM teacher_subjects WHERE teacher_id = ?");
$stmt_map->execute([$teacher_id]);
$sub_class_map = [];
foreach ($stmt_map->fetchAll() as $row) {
    $sub_class_map[$row['subject_id']][] = $row['classroom_id'];
}

// If substituting, append the specific subject and classroom if missing
if ($substitution_info) {
    $stmt_sub_extra = $pdo->prepare("SELECT id, subject_code, subject_name FROM subjects WHERE id = ?");
    $stmt_sub_extra->execute([$selected_subject]);
    $extra_sub = $stmt_sub_extra->fetch();
    if (!in_array($extra_sub['id'], array_column($subjects, 'id'))) $subjects[] = $extra_sub;

    $stmt_cls_extra = $pdo->prepare("SELECT id, grade_level, room_number, room_name FROM classrooms WHERE id = ?");
    $stmt_cls_extra->execute([$selected_classroom]);
    $extra_cls = $stmt_cls_extra->fetch();
    if (!in_array($extra_cls['id'], array_column($classrooms, 'id'))) $classrooms[] = $extra_cls;
    
    $sub_class_map[$selected_subject][] = $selected_classroom;
}

$students = [];
$show_attendance = false;

if ($selected_date && !empty($selected_periods) && $selected_subject && $selected_classroom) {
    // Check if the combination is valid (teacher is actually assigned to this subject + classroom pair)
    if ($substitution_info) {
        $stmt_check = $pdo->prepare("SELECT id, is_elective FROM teacher_subjects WHERE teacher_id=? AND subject_id=? AND classroom_id=?");
        $stmt_check->execute([$substitution_info['original_teacher_id'], $selected_subject, $selected_classroom]);
        $ts_info = $stmt_check->fetch();
    } else {
        $stmt_check = $pdo->prepare("SELECT id, is_elective FROM teacher_subjects WHERE teacher_id=? AND subject_id=? AND classroom_id=?");
        $stmt_check->execute([$teacher_id, $selected_subject, $selected_classroom]);
        $ts_info = $stmt_check->fetch();
        if (!$ts_info) {
            $error = "คุณไม่ได้รับมอบหมายให้สอนวิชานี้กับห้องเรียนนี้";
        }
    }

    $period_string = implode(',', $selected_periods);
    
    // Check if the user already logged this EXACT subject, classroom, and period on this date
    $stmt = $pdo->prepare("SELECT id, subject_id, classroom_id FROM teaching_logs WHERE teacher_id = ? AND log_date = ? AND period = ? AND subject_id = ? AND classroom_id = ?");
    $stmt->execute([$teacher_id, $selected_date, $period_string, $selected_subject, $selected_classroom]);
    $existing_log = $stmt->fetch();
    
    // If it exists, load it
    if ($existing_log) {
        $show_attendance = true;
    } else if (!$error) {
        // OVERLAP CHECK: Check if any requested periods are already taken for THIS CLASSROOM
        $stmt_overlap = $pdo->prepare("
            SELECT tl.period, u.full_name, c.room_name, tl.teacher_id, tl.classroom_id 
            FROM teaching_logs tl 
            LEFT JOIN users u ON tl.teacher_id = u.id 
            LEFT JOIN classrooms c ON tl.classroom_id = c.id
            WHERE tl.log_date = ? AND tl.classroom_id = ?
        ");
        $stmt_overlap->execute([$selected_date, $selected_classroom]);
        $overlaps = [];
        while ($row = $stmt_overlap->fetch()) {
            $ex_p = array_map('trim', explode(',', $row['period']));
            $intersect = array_intersect($selected_periods, $ex_p);
            if (count($intersect) > 0) {
                foreach($intersect as $p) {
                    if ($row['teacher_id'] == $teacher_id) {
                        // Normally this shouldn't happen because we check existing_log first, but just in case of partial overlap
                        $overlaps[] = "คาบ $p (คุณบันทึกห้องนี้ไปแล้วในรายการอื่น)";
                    } else {
                        $overlaps[] = "คาบ $p (ห้องนี้สอนโดย " . ($row['full_name'] ?: 'ไม่ทราบชื่อ') . " ไปแล้ว)";
                    }
                }
            }
        }
        if (count($overlaps) > 0) {
            $error = "ตรวจพบการบันทึกสอนซ้ำซ้อน: " . implode(', ', array_unique($overlaps)) . " กรุณาตรวจสอบหรือแก้ไขรายการเดิม";
        } else {
            $show_attendance = true;
        }
    }
    
    if ($show_attendance) {
        if ($ts_info && $ts_info['is_elective']) {
            // Fetch students from elective_enrollments
            $stmt_students = $pdo->prepare("
                SELECT s.id, s.student_code, s.full_name, s.student_number, c.room_name as original_room
                FROM elective_enrollments ee
                JOIN students s ON ee.student_id = s.id
                LEFT JOIN classrooms c ON s.classroom_id = c.id
                WHERE ee.teacher_subject_id = ?
                ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC, CAST(s.student_number AS UNSIGNED) ASC
            ");
            $stmt_students->execute([$ts_info['id']]);
            $students = $stmt_students->fetchAll();
        } else {
            // Fetch students for the selected classroom
            $stmt_students = $pdo->prepare("
                SELECT s.id, s.student_code, s.full_name, s.student_number, NULL as original_room 
                FROM students s
                WHERE s.classroom_id = ?
                ORDER BY CAST(s.student_number AS UNSIGNED) ASC, s.student_code ASC
            ");
            $stmt_students->execute([$selected_classroom]);
            $students = $stmt_students->fetchAll();
        }

        // 🟢 Fetch the most recent PREVIOUS log for this subject and classroom
        $existing_id = $existing_log['id'] ?? 0;
        $prev_stmt = $pdo->prepare("
            SELECT log_date, period, teaching_report_text 
            FROM teaching_logs 
            WHERE teacher_id = ? AND subject_id = ? AND classroom_id = ? AND (teaching_report_text IS NOT NULL AND TRIM(teaching_report_text) != '') AND id != ?
            ORDER BY log_date DESC, period DESC 
            LIMIT 1
        ");
        $prev_stmt->execute([$teacher_id, $selected_subject, $selected_classroom, $existing_id]);
        $prev_log = $prev_stmt->fetch();
    }
}

// Handle Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $date_to_save = $_POST['log_date'];
    $period_array = $_POST['period'] ?? [];
    $period_to_save = implode(',', $period_array);
    $subject_to_save = $_POST['subject_id'];
    $classroom_to_save = $_POST['classroom_id'];
    $attendance_data = $_POST['attendance'] ?? [];
    
    if (empty($period_array)) {
        $error = "กรุณาเลือกอย่างน้อย 1 คาบเรียน";
        $show_attendance = false;
    } else {
        // OVERLAP CHECK (POST) - Check only if CLASSROOM already has a report
        $is_overlap = false;
        $error_msg = "";
        $stmt_overlap = $pdo->prepare("
            SELECT tl.id, tl.period, tl.teacher_id, tl.classroom_id, c.room_name 
            FROM teaching_logs tl 
            LEFT JOIN classrooms c ON tl.classroom_id = c.id
            WHERE tl.log_date = ? AND tl.classroom_id = ?
        ");
        $stmt_overlap->execute([$date_to_save, $classroom_to_save]);
        foreach ($stmt_overlap->fetchAll() as $row) {
            // Allow update for exact same period string by the SAME teacher in the SAME classroom
            if ($row['period'] === $period_to_save && $row['teacher_id'] == $teacher_id && $row['classroom_id'] == $classroom_to_save) {
                continue;
            }
            $ex_p = array_map('trim', explode(',', $row['period']));
            $intersect = array_intersect($period_array, $ex_p);
            if (count($intersect) > 0) {
                $is_overlap = true;
                $p = reset($intersect);
                $error_msg = "ไม่สามารถบันทึกได้ เนื่องจากห้องนี้มีการสอนคาบ $p ไปแล้ว";
                break;
            }
        }

        // อนุญาตให้รายงานซ้ำคาบได้ในกรณีสอนแทน
        if (!empty($_POST['sub_id'])) {
            $is_overlap = false;
        }

        if ($is_overlap) {
            $error = $error_msg;
            $show_attendance = false;
        } else {
            $pdo->beginTransaction();
            try {
            // 1. Get or Create teaching_log
            $log_id = null;
            $stmt = $pdo->prepare("SELECT id FROM teaching_logs WHERE teacher_id = ? AND log_date = ? AND period = ?");
            $stmt->execute([$teacher_id, $date_to_save, $period_to_save]);
            if ($row = $stmt->fetch()) {
                $log_id = $row['id'];
            } else {
                $ins = $pdo->prepare("INSERT INTO teaching_logs (teacher_id, subject_id, classroom_id, log_date, period) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$teacher_id, $subject_to_save, $classroom_to_save, $date_to_save, $period_to_save]);
                $log_id = $pdo->lastInsertId();
            }
        
        // 2. Save class_attendance
        $check_att = $pdo->prepare("SELECT id FROM class_attendance WHERE teaching_log_id = ? AND student_id = ?");
        $update_att = $pdo->prepare("UPDATE class_attendance SET status = ? WHERE id = ?");
        $insert_att = $pdo->prepare("INSERT INTO class_attendance (teaching_log_id, student_id, status) VALUES (?, ?, ?)");
        
        foreach ($attendance_data as $student_id => $status) {
             $check_att->execute([$log_id, $student_id]);
             $existing_att = $check_att->fetch();
             
             if ($existing_att) {
                 $update_att->execute([$status, $existing_att['id']]);
             } else {
                 $insert_att->execute([$log_id, $student_id, $status]);
             }
        }
        $pdo->commit();
        
        // --- NOTIFICATION TRIGGER LOGIC ---
        try {
            // Get subject details (needed for message)
            $subj_stmt = $pdo->prepare("SELECT subject_code, subject_name FROM subjects WHERE id = ?");
            $subj_stmt->execute([$subject_to_save]);
            $subject_info = $subj_stmt->fetch();
            $subject_text = $subject_info ? ($subject_info['subject_code'] . ' ' . $subject_info['subject_name']) : 'วิชาที่ไม่ทราบชื่อ';

            // Mark substitution as completed and send notification
            if (!empty($_POST['sub_id'])) {
                $sub_id_to_update = $_POST['sub_id'];
                $stmt_sub_up = $pdo->prepare("UPDATE teaching_substitutions SET status = 'completed' WHERE id = ?");
                $stmt_sub_up->execute([$sub_id_to_update]);
                
                $stmt_sub_info = $pdo->prepare("SELECT original_teacher_id FROM teaching_substitutions WHERE id = ?");
                $stmt_sub_info->execute([$sub_id_to_update]);
                if ($sinfo = $stmt_sub_info->fetch()) {
                    $teacher_name = $_SESSION['full_name'];
                    $msg = "ได้รับการสอนแทนจากคุณ {$teacher_name} เรียบร้อยแล้ว (วิชา {$subject_text} คาบที่ " . implode(',', $period_array) . ")";
                    $link = "teacher_report_daily.php?date=" . $date_to_save;
                    $ins_notif = $pdo->prepare("INSERT INTO notifications (user_id, message, reference_type, reference_id, link) VALUES (?, ?, 'sub_completed', ?, ?)");
                    $ref_id = "sub_comp_" . $sub_id_to_update;
                    
                    // check if not already sent
                    $check_notif_sub = $pdo->prepare("SELECT id FROM notifications WHERE reference_id = ? LIMIT 1");
                    $check_notif_sub->execute([$ref_id]);
                    if (!$check_notif_sub->fetch()) {
                        $ins_notif->execute([$sinfo['original_teacher_id'], $msg, $ref_id, $link]);
                    }
                }
            }

            // Get academic users
            $acad_stmt = $pdo->query("SELECT user_id FROM user_roles JOIN roles ON user_roles.role_id = roles.id WHERE roles.role_name IN ('academic', 'executive')");
            $academic_users = $acad_stmt->fetchAll(PDO::FETCH_COLUMN);

            // Prepared queries to optimize the loop
            $count_stmt = $pdo->prepare("
                SELECT COUNT(*) FROM class_attendance ca
                JOIN teaching_logs tl ON ca.teaching_log_id = tl.id
                WHERE ca.student_id = ? AND tl.subject_id = ? AND ca.status = 'absent'
            ");
            $check_notif = $pdo->prepare("SELECT id FROM notifications WHERE reference_id = ? LIMIT 1");
            $stu_stmt = $pdo->prepare("SELECT s.full_name, s.classroom_id, c.room_name FROM students s LEFT JOIN classrooms c ON s.classroom_id = c.id WHERE s.id = ?");
            $insert_notif = $pdo->prepare("INSERT INTO notifications (user_id, message, reference_type, reference_id, link) VALUES (?, ?, 'absent_alert', ?, ?)");
            $delete_notif = $pdo->prepare("DELETE FROM notifications WHERE reference_id LIKE ?");
            $adv_stmt = $pdo->prepare("SELECT id FROM users WHERE advisor_classroom = ?");

            foreach ($attendance_data as $sid => $status) {
                // Count total absences in this subject for this student
                $count_stmt->execute([$sid, $subject_to_save]);
                $absence_count = (int)$count_stmt->fetchColumn();

                if ($absence_count > 3) {
                    $ref_id = "absent_{$sid}_{$subject_to_save}_{$absence_count}";
                    
                    // Check if notification already exists for this exact absence count
                    $check_notif->execute([$ref_id]);
                    if (!$check_notif->fetch()) {
                        // Get student info
                        $stu_stmt->execute([$sid]);
                        $student = $stu_stmt->fetch();
                        $student_name = $student['full_name'] ?? 'ไม่ทราบชื่อ';
                        $room_name = $student['room_name'] ?? '';
                        $room_display = $room_name ? " ({$room_name})" : "";
                        
                        // Message
                        $message = "🚨 [แจ้งเตือน] {$student_name}{$room_display} ขาดเรียนวิชา {$subject_text} เป็นครั้งที่ {$absence_count}";
                        $link = "javascript:void(0);";
                        
                        // Insert for Academic
                        foreach ($academic_users as $acad_uid) {
                            $insert_notif->execute([$acad_uid, $message, $ref_id, $link]);
                        }

                        // Insert for Subject Teacher (The one saving attendance)
                        if (!in_array($teacher_id, $academic_users)) {
                            $insert_notif->execute([$teacher_id, $message, $ref_id, $link]);
                        }

                        // Insert for Advisor(s) - lookup by room_name
                        if ($room_name) {
                            $adv_stmt->execute([$room_name]);
                            $advisors = $adv_stmt->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($advisors as $adv_uid) {
                                // Avoid duplicate if advisor is also academic or is the subject teacher
                                if (!in_array($adv_uid, $academic_users) && $adv_uid != $teacher_id) {
                                    $insert_notif->execute([$adv_uid, $message, $ref_id, $link]);
                                }
                            }
                        }
                    }
                } else {
                    // If <= 3, delete any existing absence alerts for this student/subject
                    $like_ref = "absent_{$sid}_{$subject_to_save}_%";
                    $delete_notif->execute([$like_ref]);
                }
            }
        } catch (Exception $e) {
            // Ignore notification errors so it doesn't break attendance saving
        }
        // ----------------------------------
        
        // Redirect to Step 2 (Report)
        header("Location: teacher_class_report.php?log_id=" . $log_id);
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        $show_attendance = false;
    }
        }
    }
}

// Pre-fill previously saved attendance if they are editing
$saved_attendance = [];
if ($show_attendance && isset($existing_log) && $existing_log) {
    $stmt = $pdo->prepare("SELECT student_id, status FROM class_attendance WHERE teaching_log_id = ?");
    $stmt->execute([$existing_log['id']]);
    while ($row = $stmt->fetch()) {
        $saved_attendance[$row['student_id']] = $row['status'];
    }
}

include '../../includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto; padding-bottom: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0; font-size: 1.5rem; color: var(--text-main);">❶ ข้อมูลการสอน & เช็คชื่อ</h2>
        <a href="teacher_home.php" class="btn btn-outline" style="border-radius: 20px; padding: 6px 16px;">⬅️ หน้าหลัก</a>
    </div>
    
    <?php if ($error): ?>
        <div class="badge badge-danger mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: center;">❌ <?= $error ?></div>
    <?php endif; ?>

    <!-- Step 1: Selection Form (GET) -->
    <form id="selectionForm" method="GET" style="background: rgba(0,0,0,0.02); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); margin-bottom: 20px;">
        <?php if ($sub_id): ?>
            <input type="hidden" name="sub_id" value="<?= htmlspecialchars($sub_id) ?>">
        <?php endif; ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
            <div class="form-group mb-0">
                <label class="form-label">วันที่</label>
                <input type="date" name="date" class="form-control" onchange="checkAutoSubmit()" value="<?= htmlspecialchars($selected_date) ?>" required>
            </div>
            
            <div class="form-group mb-0" style="grid-column: 1 / -1; margin-bottom: 5px;">
                <label class="form-label">คาบที่สอน (เลือกได้หลายคาบ)</label>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php for($i=1; $i<=9; $i++): ?>
                        <label style="display: flex; align-items: center; gap: 5px; background: white; border: 1px solid var(--border-color); padding: 5px 12px; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="period[]" onchange="checkAutoSubmit()" value="<?= $i ?>" <?= in_array($i, $selected_periods) ? 'checked' : '' ?>> คาบ <?= $i ?>
                        </label>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="form-group mb-0">
                <label class="form-label">รายวิชา</label>
                <select name="subject_id" class="form-control" onchange="checkAutoSubmit()" required>
                    <option value="">-- เลือกวิชา --</option>
                    <?php foreach($subjects as $subj): ?>
                        <option value="<?= $subj['id'] ?>" <?= $selected_subject == $subj['id'] ? 'selected' : '' ?>><?= htmlspecialchars($subj['subject_code'] . ' ' . $subj['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($subjects)): ?>
                    <small style="color: var(--danger-color);">* ยังไม่ได้รับมอบหมายวิชา</small>
                <?php endif; ?>
            </div>
            <div class="form-group mb-0">
                <label class="form-label">ห้องเรียนที่สอน</label>
                <select name="classroom_id" class="form-control" onchange="checkAutoSubmit()" required>
                    <option value="">-- เลือกห้อง --</option>
                    <?php foreach($classrooms as $cls): ?>
                        <option value="<?= $cls['id'] ?>" <?= $selected_classroom == $cls['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cls['room_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($classrooms)): ?>
                    <small style="color: var(--danger-color);">* ยังไม่ได้รับมอบหมายห้องสอน</small>
                <?php endif; ?>
            </div>
        </div>
        <div id="loadingIndicator" style="display: none; margin-top: 15px; text-align: right; color: var(--text-muted); font-size: 0.9rem;">
            ⏳ กำลังโหลดรายชื่อ...
        </div>
        <script>
        function checkAutoSubmit() {
            const form = document.getElementById('selectionForm');
            const date = form.querySelector('[name="date"]').value;
            const subject = form.querySelector('[name="subject_id"]').value;
            const classroom = form.querySelector('[name="classroom_id"]').value;
            const periods = form.querySelectorAll('input[name="period[]"]:checked').length;
            
            if (date && subject && classroom && periods > 0) {
                document.getElementById('loadingIndicator').style.display = 'block';
                form.submit();
            }
        }
        </script>
    </form>

    <!-- Step 2: Student List (POST) -->
    <?php if ($show_attendance): ?>
        <?php if (empty($students)): ?>
            <div style="text-align: center; padding: 40px 20px; border: 1px dashed var(--border-color); border-radius: 8px; color: var(--text-muted);">
                ไม่พบรายชื่อนักเรียนในห้องที่เลือก
            </div>
        <?php else: ?>
            <?php if (!empty($prev_log)): ?>
                <div style="background: #FFFBEB; border-left: 4px solid #F59E0B; padding: 12px 15px; margin-bottom: 20px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="font-size: 0.85rem; color: #B45309; font-weight: bold; margin-bottom: 5px; display: flex; align-items: center; gap: 5px;">
                        <span>🕰️ ประวัติการสอนคาบที่แล้ววิชาวิชานี้ (<?= date('d/m/Y', strtotime($prev_log['log_date'])) ?> คาบ <?= htmlspecialchars($prev_log['period']) ?>)</span>
                    </div>
                    <div style="color: var(--text-color); font-size: 0.95rem; line-height: 1.5; white-space: pre-wrap;"><?= htmlspecialchars($prev_log['teaching_report_text']) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" id="classAttendanceForm">
                <input type="hidden" name="save_attendance" value="1">
                <input type="hidden" name="log_date" value="<?= htmlspecialchars($selected_date) ?>">
                <?php foreach ($selected_periods as $p): ?>
                    <input type="hidden" name="period[]" value="<?= htmlspecialchars($p) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="subject_id" value="<?= htmlspecialchars($selected_subject) ?>">
                <input type="hidden" name="classroom_id" value="<?= htmlspecialchars($selected_classroom) ?>">
                <?php if ($sub_id): ?>
                <input type="hidden" name="sub_id" value="<?= htmlspecialchars($sub_id) ?>">
                <?php endif; ?>
                
                <!-- Summary bar floating on mobile -->
                <div style="display: flex; justify-content: space-between; align-items: center; background: white; padding: 10px; border-bottom: 2px solid var(--border-color); position: sticky; top: 56px; z-index: 10; margin-top: 15px; flex-wrap: wrap; gap: 10px;">
                    <div style="font-size: 0.9rem; color: var(--text-muted); white-space: nowrap;">
                        รวม: <strong><?= count($students) ?></strong> คน
                    </div>
                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                        <!-- Bulk select helpers -->
                        <button type="button" class="btn btn-outline" style="padding: 4px 8px; font-size: 11px; border-color: var(--success-color); color: var(--success-color); border-radius: 4px;" onclick="setAll('present')">✅ มาทั้งหมด</button>
                        <button type="button" class="btn btn-outline" style="padding: 4px 8px; font-size: 11px; border-color: var(--warning-color); color: #a16207; border-radius: 4px;" onclick="setAll('late')">⏰ สายทั้งหมด</button>
                        <button type="button" class="btn btn-outline" style="padding: 4px 8px; font-size: 11px; border-color: #1d4ed8; color: #1d4ed8; border-radius: 4px;" onclick="setAll('leave')">📄 ลาทั้งหมด</button>
                        <button type="button" class="btn btn-outline" style="padding: 4px 8px; font-size: 11px; border-color: var(--danger-color); color: var(--danger-color); border-radius: 4px;" onclick="setAll('absent')">❌ ขาดทั้งหมด</button>
                    </div>
                </div>

                <div style="margin-top: 10px;">
                    <?php foreach ($students as $s): ?>
                        <?php 
                        // Auto-fill logic
                        $current_status = $saved_attendance[$s['id']] ?? 'present'; 
                        ?>
                        <div style="margin-bottom: 4px; padding: 8px 10px; border-radius: 8px; background: white; border: 1px solid #f1f5f9; display:flex; flex-direction: column; gap: 4px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div style="display: flex; align-items: center; gap: 4px;">
                                    <span style="display: inline-block; background: var(--bg-hover); padding: 2px 6px; border-radius: 4px; font-size: 11px; color: var(--text-muted); font-weight: bold; min-width: 35px; text-align: center;"><?= htmlspecialchars($s['student_number'] ?? '-') ?></span>
                                    <strong style="font-size: 1rem;"><?= htmlspecialchars($s['full_name']) ?></strong>
                                    <?php if (!empty($s['original_room'])): ?>
                                        <span style="font-size: 10px; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; color: #475569;"><?= htmlspecialchars($s['original_room']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($s['student_code']) ?></div>
                            </div>
                            
                            <!-- Custom Radio Box for Mobile Touch -->
                            <div class="attendance-options" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px;">
                                <?php
                                $options = [
                                    'present' => ['label' => 'มา', 'color' => 'var(--success-color)', 'bg' => '#dcfce7'],
                                    'late' => ['label' => 'สาย', 'color' => 'var(--warning-color)', 'bg' => '#fef08a'],
                                    'leave' => ['label' => 'ลา', 'color' => '#1d4ed8', 'bg' => '#e0e7ff'],
                                    'absent' => ['label' => 'ขาด', 'color' => 'var(--danger-color)', 'bg' => '#fee2e2']
                                ];
                                ?>
                                <?php foreach ($options as $val => $opt): ?>
                                    <label style="cursor: pointer; position: relative; margin: 0;">
                                        <input type="radio" required name="attendance[<?= $s['id'] ?>]" value="<?= $val ?>" <?= $current_status == $val ? 'checked' : '' ?> class="hide-radio item-<?= $val ?>">
                                        <div class="radio-card" style="text-align: center; padding: 6px 4px; border-radius: 8px; font-size: 13px; transition: all 0.2s ease; font-weight: 500;">
                                            <?= $opt['label'] ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Inline save button -->
                <div style="margin-top: 20px; margin-bottom: 20px;">
                    <button type="submit" class="btn btn-primary btn-block" style="font-size: 1.1rem; padding: 14px; border-radius: 14px; font-weight: 600; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);">✅ ถัดไป: บันทึกเนื้อหาที่สอน ➡️</button>
                </div>

            </form>
        <?php endif; ?>
    <?php else: ?>
        <div style="text-align: center; padding: 60px 20px; opacity: 0.5;">
            <div style="font-size: 4rem; margin-bottom: 20px;">👆</div>
            <h3>เลือกระบุข้อมูลด้านบนให้ครบถ้วน</h3>
            <p>เพื่อดึงรายชื่อนักเรียนในรายวิชานั้นๆ ขึ้นมาเช็คชื่อ</p>
        </div>
    <?php endif; ?>

</div>

<style>
/* Same CSS as homeroom */
.hide-radio {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.radio-card {
    background-color: var(--bg-hover);
    color: var(--text-muted);
    border: 1px solid transparent !important;
}
.hide-radio.item-present:checked + .radio-card {
    background-color: #bbf7d0;
    color: #166534;
    font-weight: 600;
}
.hide-radio.item-late:checked + .radio-card {
    background-color: #fde047;
    color: #854d0e;
    font-weight: 600;
}
.hide-radio.item-leave:checked + .radio-card {
    background-color: #bfdbfe;
    color: #1e3a8a;
    font-weight: 600;
}
.hide-radio.item-absent:checked + .radio-card {
    background-color: #fecaca;
    color: #991b1b;
    font-weight: 600;
}

@media (max-width: 768px) {
    .footer-btn-container {
        padding-left: 0 !important;
    }
}
</style>

<script>
function setAll(statusValue) {
    const radios = document.querySelectorAll('.hide-radio.item-' + statusValue);
    radios.forEach(r => r.checked = true);
}

// Logic to filter classrooms based on selected subject
document.addEventListener('DOMContentLoaded', function() {
    const subClassMap = <?= json_encode($sub_class_map) ?>;
    const subjectSelect = document.querySelector('select[name="subject_id"]');
    const classroomSelect = document.querySelector('select[name="classroom_id"]');
    
    if (subjectSelect && classroomSelect) {
        // Master copy of all original options
        const allOptions = Array.from(classroomSelect.options).map(opt => opt.cloneNode(true));
        
        function updateClassroomDropdown() {
            const selectedSub = subjectSelect.value;
            const validClassrooms = subClassMap[selectedSub] ? subClassMap[selectedSub].map(String) : [];
            const currentSelectedClassroom = classroomSelect.value;
            
            // Clear current dropdown options
            classroomSelect.innerHTML = '';
            
            // Re-populate only with valid options
            let isCurrentSelectionStillValid = false;
            
            allOptions.forEach(opt => {
                if (opt.value === "" || selectedSub === "" || validClassrooms.includes(opt.value)) {
                    const newOpt = opt.cloneNode(true);
                    // Keep previously selected option if still valid
                    if (newOpt.value === currentSelectedClassroom && newOpt.value !== "") {
                        newOpt.selected = true;
                        isCurrentSelectionStillValid = true;
                    }
                    classroomSelect.appendChild(newOpt);
                }
            });
            
            // If the selected classroom was filtered out, reset to the placeholder
            if (!isCurrentSelectionStillValid && currentSelectedClassroom !== "") {
                classroomSelect.value = '';
            }
        }
        
        subjectSelect.addEventListener('change', updateClassroomDropdown);
        updateClassroomDropdown(); // Run on page load
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
