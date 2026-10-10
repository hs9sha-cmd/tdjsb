<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$page_title = "นำเข้าข้อมูลจาก CSV";
$error = '';
$success = '';
$import_type = $_GET['type'] ?? 'users'; // default to users

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    $import_type = $_POST['import_type'] ?? 'users';
    
    if ($file['error'] == UPLOAD_ERR_OK) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (strtolower($ext) !== 'csv') {
            $error = "กรุณาอัปโหลดไฟล์นามสกุล .csv เท่านั้น";
        } else {
            $raw_content = file_get_contents($file['tmp_name']);
            // Strip UTF-8 BOM if present
            $bom = pack('H*','EFBBBF');
            $raw_content = preg_replace("/^$bom/", '', $raw_content);

            // Convert Thai encoding (Windows-874 / TIS-620) to UTF-8 if needed
            if (!mb_check_encoding($raw_content, 'UTF-8')) {
                $converted = @mb_convert_encoding($raw_content, 'UTF-8', 'Windows-874, TIS-620, ISO-8859-1');
                if ($converted) $raw_content = $converted;
            }

            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $raw_content);
            rewind($handle);

            if ($handle !== FALSE) {
                // Skip header row
                fgetcsv($handle);
                
                $row_count = 0;
                $success_count = 0;
                $fail_count = 0;
                $import_errors = [];

                // Fetch active academic term for student behaviors
                $stmt_term = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
                $active_term_id = $stmt_term ? $stmt_term->fetchColumn() : null;
                
                $pdo->beginTransaction();
                try {
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $row_count++;
                        
                        // users CSV format: username, password(plain), full_name, department, advisor_classroom
                        if ($import_type == 'users') {
                            if (count($data) >= 4) {
                                $username = trim($data[0]);
                                $password = trim($data[1]);
                                $full_name = trim($data[2]);
                                $department = trim($data[3] ?? '');
                                $advisor_classroom = trim($data[4] ?? '');
                                
                                if (empty($username) || empty($password) || empty($full_name)) {
                                    $import_errors[] = "แถวที่ {$row_count}: ข้อมูลไม่ครบถ้วน";
                                    $fail_count++; continue;
                                }

                                // check duplicate
                                $stmt = $pdo->prepare("SELECT id FROM users WHERE username=?");
                                $stmt->execute([$username]);
                                if ($stmt->fetch()) {
                                    $import_errors[] = "แถวที่ {$row_count}: Username ($username) มีในระบบแล้ว";
                                    $fail_count++; continue;
                                }

                                $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                                $ins = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, department, advisor_classroom) VALUES (?, ?, ?, ?, ?)");
                                $ins->execute([$username, $pass_hash, $full_name, $department, $advisor_classroom]);
                                $new_id = $pdo->lastInsertId();

                                // default role: teacher (id: 2)
                                $role_id = 2; 
                                $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$new_id, $role_id]);
                                
                                $success_count++;
                            } else {
                                $import_errors[] = "แถวที่ {$row_count}: จำนวนคอลัมน์ไม่ถูกต้อง (ต้องการอย่างน้อย 4)";
                                $fail_count++; continue;
                            }
                        }
                        
                        // students CSV format: student_code, full_name, classroom (e.g. ม.1/1), student_number
                        if ($import_type == 'students') {
                            if (count($data) >= 4) {
                                $student_code = trim($data[0]);
                                $full_name = trim($data[1]);
                                $classroom_str = trim($data[2] ?? ''); // e.g. ม.1/1
                                $student_number = trim($data[3] ?? '');
                                
                                if (empty($student_code) || empty($full_name)) {
                                    $import_errors[] = "แถวที่ {$row_count}: ข้อมูลนักเรียนไม่ครบถ้วน";
                                    $fail_count++; continue;
                                }

                                // Fix Excel stripping leading zeros (pad to 5 digits if purely numeric and < 5 chars)
                                if (is_numeric($student_code) && strlen($student_code) > 0 && strlen($student_code) < 5) {
                                    $student_code = str_pad($student_code, 5, "0", STR_PAD_LEFT);
                                }

                                // Auto detect gender from Thai title prefixes
                                $gender = null;
                                if (strpos($full_name, 'เด็กชาย') === 0 || strpos($full_name, 'นาย') === 0 || strpos($full_name, 'ด.ช.') === 0) {
                                    $gender = 'Male';
                                } elseif (strpos($full_name, 'เด็กหญิง') === 0 || strpos($full_name, 'นางสาว') === 0 || strpos($full_name, 'ด.ญ.') === 0 || strpos($full_name, 'นาง') === 0) {
                                    $gender = 'Female';
                                }

                                // Map classroom string to classroom_id
                                $classroom_id = null;
                                if (!empty($classroom_str) && strpos($classroom_str, '/') !== false) {
                                    list($grade_level, $room_number) = explode('/', $classroom_str);
                                    $grade_level = trim($grade_level);
                                    $room_number = trim($room_number);
                                    $cls = $pdo->prepare("SELECT id FROM classrooms WHERE grade_level=? AND room_number=?");
                                    $cls->execute([$grade_level, $room_number]);
                                    $c_row = $cls->fetch();
                                    if ($c_row) {
                                        $classroom_id = $c_row['id'];
                                    } else {
                                        // auto create classroom
                                        $room_name = $grade_level . '/' . $room_number;
                                        $ins_c = $pdo->prepare("INSERT INTO classrooms (grade_level, room_number, room_name) VALUES (?, ?, ?)");
                                        $ins_c->execute([$grade_level, $room_number, $room_name]);
                                        $classroom_id = $pdo->lastInsertId();
                                    }
                                }

                                // check duplicate and update or insert
                                $stmt = $pdo->prepare("SELECT id FROM students WHERE student_code=?");
                                $stmt->execute([$student_code]);
                                $existing_student = $stmt->fetch();
                                $current_student_id = null;

                                if ($existing_student) {
                                    // Update existing student
                                    $current_student_id = $existing_student['id'];
                                    $upd = $pdo->prepare("UPDATE students SET full_name=?, classroom_id=?, student_number=?, gender=COALESCE(?, gender) WHERE id=?");
                                    $upd->execute([$full_name, $classroom_id, empty($student_number)?null:$student_number, $gender, $current_student_id]);
                                } else {
                                    // Insert new student
                                    $ins = $pdo->prepare("INSERT INTO students (student_code, full_name, classroom_id, student_number, gender) VALUES (?, ?, ?, ?, ?)");
                                    $ins->execute([$student_code, $full_name, $classroom_id, empty($student_number)?null:$student_number, $gender]);
                                    $current_student_id = $pdo->lastInsertId();
                                }

                                // Auto-initialize behavior score (100) for active term if configured
                                if ($current_student_id && $active_term_id) {
                                    $stmt_sb = $pdo->prepare("INSERT IGNORE INTO student_behaviors (student_id, academic_term_id, initial_score, current_score) VALUES (?, ?, 100, 100)");
                                    $stmt_sb->execute([$current_student_id, $active_term_id]);
                                }
                                
                                $success_count++;
                            } else {
                                $import_errors[] = "แถวที่ {$row_count}: จำนวนคอลัมน์ไม่ถูกต้อง (ต้องการอย่างน้อย 4)";
                                $fail_count++; continue;
                            }
                        }
                        
                        // subjects CSV format: subject_code, subject_name, credit
                        if ($import_type == 'subjects') {
                            if (count($data) >= 3) {
                                $code = trim($data[0]);
                                $name = trim($data[1]);
                                $credit = trim($data[2]);
                                
                                if (empty($code) || empty($name)) {
                                    $import_errors[] = "แถวที่ {$row_count}: รหัสวิชาหรือชื่อวิชาว่างเปล่า";
                                    $fail_count++; continue;
                                }

                                // check duplicate
                                $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_code=?");
                                $stmt->execute([$code]);
                                if ($stmt->fetch()) {
                                    $import_errors[] = "แถวที่ {$row_count}: รหัสวิชา ($code) มีในระบบแล้ว";
                                    $fail_count++; continue;
                                }

                                $ins = $pdo->prepare("INSERT INTO subjects (subject_code, subject_name, credit) VALUES (?, ?, ?)");
                                $ins->execute([$code, $name, empty($credit)?1.0:$credit]);
                                
                                $success_count++;
                            } else {
                                $import_errors[] = "แถวที่ {$row_count}: จำนวนคอลัมน์ไม่ถูกต้อง (ต้องการอย่างน้อย 3)";
                                $fail_count++; continue;
                            }
                        }
                        
                        // assignments CSV format: username, subject_code, classroom_name (e.g. ม.1/1)
                        if ($import_type == 'assignments') {
                            if (count($data) >= 3) {
                                $username = trim($data[0]);
                                $subject_code = trim($data[1]);
                                $classroom_name = trim($data[2]);
                                
                                if (empty($username) || empty($subject_code) || empty($classroom_name)) {
                                    $import_errors[] = "แถวที่ {$row_count}: ข้อมูลไม่ครบถ้วน";
                                    $fail_count++; continue;
                                }

                                // find foreign keys
                                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                                $stmt->execute([$username]);
                                $t_row = $stmt->fetch();
                                
                                $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = ?");
                                $stmt->execute([$subject_code]);
                                $s_row = $stmt->fetch();
                                
                                $stmt = $pdo->prepare("SELECT id FROM classrooms WHERE room_name = ?");
                                $stmt->execute([$classroom_name]);
                                $c_row = $stmt->fetch();
                                
                                if (!$t_row || !$s_row || !$c_row) {
                                    $missing = [];
                                    if (!$t_row) $missing[] = "ครู ($username)";
                                    if (!$s_row) $missing[] = "วิชา ($subject_code)";
                                    if (!$c_row) $missing[] = "ห้อง ($classroom_name)";
                                    $import_errors[] = "แถวที่ {$row_count}: ไม่พบ " . implode(', ', $missing) . " ในระบบ";
                                    $fail_count++; continue;
                                }

                                // check duplicate
                                $stmt = $pdo->prepare("SELECT id FROM teacher_subjects WHERE teacher_id=? AND subject_id=? AND classroom_id=?");
                                $stmt->execute([$t_row['id'], $s_row['id'], $c_row['id']]);
                                if ($stmt->fetch()) {
                                    $import_errors[] = "แถวที่ {$row_count}: มีการมอบหมายวิชานี้ให้ครูท่านนี้แล้ว";
                                    $fail_count++; continue;
                                }

                                $ins = $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id, classroom_id) VALUES (?, ?, ?)");
                                $ins->execute([$t_row['id'], $s_row['id'], $c_row['id']]);
                                
                                $success_count++;
                            } else {
                                $import_errors[] = "แถวที่ {$row_count}: จำนวนคอลัมน์ไม่ถูกต้อง (ต้องการอย่างน้อย 3)";
                                $fail_count++; continue;
                            }
                        }
                        
                        // elective_enrollments CSV format: teacher_username, subject_code, base_classroom, student_code
                        if ($import_type == 'elective_enrollments') {
                            if (count($data) >= 4) {
                                $username = trim($data[0]);
                                $subject_code = trim($data[1]);
                                $classroom_name = trim($data[2]);
                                $student_code = trim($data[3]);
                                
                                if (empty($username) || empty($subject_code) || empty($classroom_name) || empty($student_code)) {
                                    $import_errors[] = "แถวที่ {$row_count}: ข้อมูลไม่ครบถ้วน";
                                    $fail_count++; continue;
                                }

                                // find teacher, subject, classroom
                                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                                $stmt->execute([$username]);
                                $t_row = $stmt->fetch();
                                
                                $stmt = $pdo->prepare("SELECT id FROM subjects WHERE subject_code = ?");
                                $stmt->execute([$subject_code]);
                                $s_row = $stmt->fetch();
                                
                                $stmt = $pdo->prepare("SELECT id FROM classrooms WHERE room_name = ?");
                                $stmt->execute([$classroom_name]);
                                $c_row = $stmt->fetch();
                                
                                $stmt = $pdo->prepare("SELECT id FROM students WHERE student_code = ?");
                                $stmt->execute([$student_code]);
                                $stu_row = $stmt->fetch();
                                
                                if (!$t_row || !$s_row || !$c_row || !$stu_row) {
                                    $missing = [];
                                    if (!$t_row) $missing[] = "ครู ($username)";
                                    if (!$s_row) $missing[] = "วิชา ($subject_code)";
                                    if (!$c_row) $missing[] = "ห้อง ($classroom_name)";
                                    if (!$stu_row) $missing[] = "นร. ($student_code)";
                                    $import_errors[] = "แถวที่ {$row_count}: ไม่พบ " . implode(', ', $missing) . " ในระบบ";
                                    $fail_count++; continue;
                                }

                                // check if teacher_subjects exists
                                $stmt = $pdo->prepare("SELECT id, is_elective FROM teacher_subjects WHERE teacher_id=? AND subject_id=? AND classroom_id=?");
                                $stmt->execute([$t_row['id'], $s_row['id'], $c_row['id']]);
                                $ts_row = $stmt->fetch();
                                
                                if ($ts_row) {
                                    if (!$ts_row['is_elective']) {
                                        // Update to be elective
                                        $pdo->prepare("UPDATE teacher_subjects SET is_elective = 1 WHERE id = ?")->execute([$ts_row['id']]);
                                    }
                                    $ts_id = $ts_row['id'];
                                } else {
                                    // Create new teacher_subject assignment as elective
                                    $ins = $pdo->prepare("INSERT INTO teacher_subjects (teacher_id, subject_id, classroom_id, is_elective) VALUES (?, ?, ?, 1)");
                                    $ins->execute([$t_row['id'], $s_row['id'], $c_row['id']]);
                                    $ts_id = $pdo->lastInsertId();
                                }

                                // Check if already enrolled
                                $stmt = $pdo->prepare("SELECT id FROM elective_enrollments WHERE teacher_subject_id=? AND student_id=?");
                                $stmt->execute([$ts_id, $stu_row['id']]);
                                if ($stmt->fetch()) {
                                    $import_errors[] = "แถวที่ {$row_count}: นร. ($student_code) อยู่ในกลุ่มวิชานี้แล้ว";
                                    $fail_count++; continue; // duplicate
                                }

                                // Insert enrollment
                                $ins = $pdo->prepare("INSERT INTO elective_enrollments (teacher_subject_id, student_id) VALUES (?, ?)");
                                $ins->execute([$ts_id, $stu_row['id']]);
                                
                                $success_count++;
                            } else {
                                $import_errors[] = "แถวที่ {$row_count}: จำนวนคอลัมน์ไม่ถูกต้อง (ต้องการอย่างน้อย 4)";
                                $fail_count++; continue;
                            }
                        }
                    }
                    $pdo->commit();
                    $success = "นำเข้าข้อมูลสำเร็จ: สำเร็จ $success_count รายการ, ข้ามเพราะรูปแบบผิดซ้ำ/ข้อมูลไม่ครบ $fail_count รายการ";
                } catch(Exception $e) {
                    $pdo->rollBack();
                    $error = "เกิดข้อผิดพลาดในการประมวลผลบรรทัดที่ $row_count: " . $e->getMessage();
                }
                fclose($handle);
            } else {
                $error = "ไม่สามารถเปิดไฟล์ได้";
            }
        }
    } else {
        $error = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์";
    }
}

include '../../includes/header.php';
?>

<div class="card" style="max-width: 700px; margin: 0 auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <?php 
        $display_type = 'รายชื่อครู';
        if ($import_type == 'students') $display_type = 'รายชื่อนักเรียน';
        if ($import_type == 'subjects') $display_type = 'ข้อมูลรายวิชา';
        if ($import_type == 'assignments') $display_type = 'ภาระงานสอน';
        if ($import_type == 'elective_enrollments') $display_type = 'ลงทะเบียนวิชาเลือก';
        ?>
        <h2 class="card-title" style="margin-bottom: 0;">นำเข้าข้อมูล (CSV / Excel Export) - <?= $display_type ?></h2>
        <?php if ($import_type == 'users'): ?>
            <a href="admin_users.php" class="btn btn-outline">⬅️ กลับ</a>
        <?php elseif ($import_type == 'subjects'): ?>
            <a href="admin_subjects.php" class="btn btn-outline">⬅️ กลับ</a>
        <?php elseif ($import_type == 'assignments' || $import_type == 'elective_enrollments'): ?>
            <a href="admin_assign_subjects.php" class="btn btn-outline">⬅️ กลับ</a>
        <?php else: ?>
            <a href="admin_students.php" class="btn btn-outline">⬅️ กลับ</a>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="badge badge-danger mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="badge badge-success mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $success ?></div>
        <?php if (!empty($import_errors)): ?>
            <div style="background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
                <h5 style="color: #92400E; margin-top: 0; margin-bottom: 10px; font-size: 14px;">⚠️ รายการที่ไม่สามารถนำเข้าได้:</h5>
                <ul style="margin: 0; padding-left: 20px; color: #B45309; font-size: 13px; max-height: 200px; overflow-y: auto;">
                    <?php foreach($import_errors as $err): ?>
                        <li style="margin-bottom: 4px;"><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div style="padding: 15px; background: var(--bg-hover); border-left: 4px solid var(--primary-color); margin-bottom: 20px; border-radius: 4px; position: relative;">
        <h4 style="margin-bottom: 10px; color: var(--primary-color);">คำแนะนำรูปแบบไฟล์ CSV</h4>
        <?php if ($import_type == 'users'): ?>
            <a href="download_template.php?type=teacher" target="_blank" download="teacher_template.csv" class="btn btn-outline" style="position: absolute; top: 15px; right: 15px; font-size: 12px;">📥 ดาวน์โหลดไฟล์ตัวอย่าง</a>
            <p style="font-size: 0.9rem;"><strong>ไฟล์ต้องมีโครงสร้าง 5 คอลัมน์ดังนี้ (แถวแรกเป็น Header):</strong></p>
            <ol style="font-size: 0.85rem; margin-left: 20px; margin-top: 5px; color: var(--text-muted);">
                <li>ชื่อผู้ใช้งาน (Username)</li>
                <li>รหัสผ่าน</li>
                <li>ชื่อ-สกุล (เช่น นายสมชาย ใจดี)</li>
                <li>กลุ่มสาระการเรียนรู้</li>
                <li>ห้องเรียนที่ปรึกษา (ตัวอย่าง: ม.1/1) - <i>(เว้นว่างได้)</i></li>
            </ol>
            <p style="font-size: 0.85rem; margin-top: 10px; color: var(--danger-color);">* ผู้ใช้ที่นำเข้าจะได้รับสิทธิ์ Teacher (ครูผู้สอน) เป็นค่าเริ่มต้น</p>
        <?php elseif ($import_type == 'students'): ?>
            <a href="download_template.php?type=student" target="_blank" download="student_template.csv" class="btn btn-outline" style="position: absolute; top: 15px; right: 15px; font-size: 12px;">📥 ดาวน์โหลดไฟล์ตัวอย่าง</a>
            <p style="font-size: 0.9rem;"><strong>ไฟล์ต้องมีโครงสร้าง 4 คอลัมน์ดังนี้ (แถวแรกเป็น Header):</strong></p>
            <ol style="font-size: 0.85rem; margin-left: 20px; margin-top: 5px; color: var(--text-muted);">
                <li>รหัสประจำตัวนักเรียน</li>
                <li>ชื่อ-สกุล (เช่น ด.ช.เรียนดี ขยันยิ่ง)</li>
                <li>ชั้นเรียน (ตัวอย่าง: ม.1/1) - ระบบจะสร้างห้องให้อัตโนมัติถ้าไม่มี</li>
                <li>เลขที่</li>
            </ol>
        <?php elseif ($import_type == 'subjects'): ?>
            <a href="download_template.php?type=subject" target="_blank" download="subject_template.csv" class="btn btn-outline" style="position: absolute; top: 15px; right: 15px; font-size: 12px;">📥 ดาวน์โหลดไฟล์ตัวอย่าง</a>
            <p style="font-size: 0.9rem;"><strong>ไฟล์ต้องมีโครงสร้าง 3 คอลัมน์ดังนี้ (แถวแรกเป็น Header):</strong></p>
            <ol style="font-size: 0.85rem; margin-left: 20px; margin-top: 5px; color: var(--text-muted);">
                <li>รหัสวิชา (เช่น ท21101)</li>
                <li>ชื่อวิชา (เช่น ภาษาไทยพื้นฐาน)</li>
                <li>หน่วยกิต (เช่น 1.0 หรือ 1.5)</li>
            </ol>
        <?php elseif ($import_type == 'assignments'): ?>
            <a href="download_template.php?type=assignment" target="_blank" download="assignment_template.csv" class="btn btn-outline" style="position: absolute; top: 15px; right: 15px; font-size: 12px;">📥 ดาวน์โหลดไฟล์ตัวอย่าง</a>
            <p style="font-size: 0.9rem;"><strong>ไฟล์ต้องมีโครงสร้าง 3 คอลัมน์ดังนี้ (แถวแรกเป็น Header):</strong></p>
            <ol style="font-size: 0.85rem; margin-left: 20px; margin-top: 5px; color: var(--text-muted);">
                <li>Username ของครูผู้สอน</li>
                <li>รหัสวิชา (เช่น ท21101)</li>
                <li>ห้องเรียน (เช่น ม.1/1) - ต้องตรงกับที่มีในระบบ</li>
            </ol>
            <p style="font-size: 0.85rem; color: var(--danger-color); margin-top: 10px;">* หากข้อมูลไม่ตรงกับครู/วิชา/ห้องเรียน ที่มีในระบบ ระบบจะข้ามแถวนั้นไป</p>
        <?php elseif ($import_type == 'elective_enrollments'): ?>
            <a href="download_template.php?type=elective_enrollment" target="_blank" download="elective_enrollment_template.csv" class="btn btn-outline" style="position: absolute; top: 15px; right: 15px; font-size: 12px;">📥 ดาวน์โหลดไฟล์ตัวอย่าง</a>
            <p style="font-size: 0.9rem;"><strong>ไฟล์ต้องมีโครงสร้าง 4 คอลัมน์ดังนี้ (แถวแรกเป็น Header):</strong></p>
            <ol style="font-size: 0.85rem; margin-left: 20px; margin-top: 5px; color: var(--text-muted);">
                <li>Username ของครูผู้สอน</li>
                <li>รหัสวิชา (เช่น ง30201)</li>
                <li>ห้องหลักของวิชานี้ (เช่น ม.4/1)</li>
                <li>รหัสประจำตัวนักเรียน (ที่ต้องการเพิ่มเข้ากลุ่ม)</li>
            </ol>
            <p style="font-size: 0.85rem; color: var(--danger-color); margin-top: 10px;">* ระบบจะกำหนดให้วิชานี้เป็น "วิชาเลือก" โดยอัตโนมัติ และนำนักเรียนเข้ากลุ่มเรียนทันที</p>
        <?php endif; ?>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="import_type" value="<?= htmlspecialchars($import_type) ?>">
        
        <div class="form-group mt-3">
            <label class="form-label">เลือกไฟล์ระดับตาราง (.csv)</label>
            <input type="file" name="csv_file" class="form-control" accept=".csv" required style="padding: 10px;">
        </div>

        <button type="submit" class="btn btn-success mt-3 btn-block">อัปโหลดและนำเข้าข้อมูล 🚀</button>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
