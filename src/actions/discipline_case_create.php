<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "สร้างคดีความประพฤติใหม่";
include '../../includes/header.php';

$success = '';
$error = '';
$discipline_teacher_id = $_SESSION['user_id'];

$stmt_term = $pdo->query("SELECT id, year_name, term_number FROM academic_terms WHERE is_active = 1 LIMIT 1");
$term = $stmt_term->fetch(PDO::FETCH_ASSOC);
$term_id = $term['id'] ?? null;

if (!$term_id) {
    echo "<div class='card'><div class='badge badge-danger'>ไม่พบปีการศึกษาที่เปิดใช้งาน</div></div>";
    include '../../includes/footer.php';
    exit();
}

// Generate Case Code: CASE-{YEAR}-{TERM}-{RUNNING_NUMBER}
function generateCaseCode($pdo, $term_id, $year_name, $term_number) {
    $prefix = "CASE-{$year_name}-{$term_number}-";
    $stmt = $pdo->prepare("SELECT case_code FROM discipline_cases WHERE academic_term_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$term_id]);
    $last_code = $stmt->fetchColumn();
    
    if ($last_code && strpos($last_code, $prefix) === 0) {
        $number = (int)str_replace($prefix, '', $last_code);
        $next_number = str_pad($number + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $next_number = '001';
    }
    return $prefix . $next_number;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_case'])) {
    $case_name = trim($_POST['case_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $student_ids = $_POST['students'] ?? [];
    $points_array = $_POST['points'] ?? [];
    
    if (empty($case_name)) {
        $error = "กรุณาระบุชื่อคดี";
    } elseif (empty($student_ids)) {
        $error = "กรุณาเพิ่มนักเรียนที่เกี่ยวข้องอย่างน้อย 1 คน";
    } else {
        $pdo->beginTransaction();
        try {
            // 1. Create Case
            $case_code = generateCaseCode($pdo, $term_id, $term['year_name'], $term['term_number']);
            $stmt_case = $pdo->prepare("INSERT INTO discipline_cases (case_code, academic_term_id, case_name, description, reported_by) VALUES (?, ?, ?, ?, ?)");
            $stmt_case->execute([$case_code, $term_id, $case_name, $description, $discipline_teacher_id]);
            $case_id = $pdo->lastInsertId();
            
            // 2. Add Students and deduct points
            $stmt_record = $pdo->prepare("INSERT INTO behavior_records (student_id, academic_term_id, recorded_by_teacher_id, record_date, points, reason, status) VALUES (?, ?, ?, CURDATE(), ?, ?, 'approved')");
            $stmt_case_std = $pdo->prepare("INSERT INTO discipline_case_students (case_id, student_id, points_deducted, behavior_record_id) VALUES (?, ?, ?, ?)");
            
            foreach ($student_ids as $sid) {
                $deduct = (int)($points_array[$sid] ?? 0);
                if ($deduct >= 0) $deduct = -20; // default negative if they typed positive by mistake or left empty
                
                $reason_text = "คดี: " . $case_name . " (รหัส: $case_code)";
                
                // insert to behavior_records
                $stmt_record->execute([$sid, $term_id, $discipline_teacher_id, $deduct, $reason_text]);
                $record_id = $pdo->lastInsertId();
                
                // link to case
                $stmt_case_std->execute([$case_id, $sid, $deduct, $record_id]);
                
                // update current score
                $stmt_init = $pdo->prepare("INSERT IGNORE INTO student_behaviors (student_id, academic_term_id, initial_score, current_score) VALUES (?, ?, 100, 100)");
                $stmt_init->execute([$sid, $term_id]);
                
                $stmt_sum = $pdo->prepare("SELECT SUM(points) FROM behavior_records WHERE student_id = ? AND academic_term_id = ? AND status = 'approved'");
                $stmt_sum->execute([$sid, $term_id]);
                $total_points = (int)$stmt_sum->fetchColumn();
                $new_score = 100 + $total_points;
                if ($new_score > 100) $new_score = 100;
                
                $stmt_upd = $pdo->prepare("UPDATE student_behaviors SET current_score = ? WHERE student_id = ? AND academic_term_id = ?");
                $stmt_upd->execute([$new_score, $sid, $term_id]);
            }
            
            // 3. Upload Photos
            if (isset($_FILES['photos'])) {
                $upload_dir = '../../uploads/cases/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                
                $stmt_photo = $pdo->prepare("INSERT INTO discipline_case_photos (case_id, photo_path) VALUES (?, ?)");
                
                foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
                    if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                        $ext = pathinfo($_FILES['photos']['name'][$key], PATHINFO_EXTENSION);
                        $filename = $case_code . '_' . time() . '_' . $key . '.' . $ext;
                        $filepath = $upload_dir . $filename;
                        
                        if (move_uploaded_file($tmp_name, $filepath)) {
                            // store relative path for DB
                            $db_path = 'uploads/cases/' . $filename;
                            $stmt_photo->execute([$case_id, $db_path]);
                        }
                    }
                }
            }
            
            $pdo->commit();
            
            // Redirect to view page
            echo "<script>window.location.href = 'discipline_case_view.php?id={$case_id}&success=created';</script>";
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// Fetch all students for Tom Select
$stmt_all_students = $pdo->prepare("
    SELECT s.id, s.student_code, s.full_name, c.room_name 
    FROM students s
    LEFT JOIN classrooms c ON s.classroom_id = c.id
    WHERE s.status = 'active' 
    ORDER BY s.student_code ASC
");
$stmt_all_students->execute();
$all_students = $stmt_all_students->fetchAll(PDO::FETCH_ASSOC);
?>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin: 0;">📝 สร้างคดีความประพฤติใหม่</h2>
        <a href="discipline_manage_scores.php?tab=cases" class="btn btn-secondary">ยกเลิก / กลับ</a>
    </div>

    <?php if($error): ?><div class="badge badge-danger mb-3" style="display: block; padding: 10px;"><?= $error ?></div><?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="caseForm">
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- Left Column: Case Details -->
            <div style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <h4 style="margin-top: 0; color: var(--primary-color);">ข้อมูลคดี</h4>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">ชื่อคดี (เช่น ทะเลาะวิวาท, สูบบุหรี่)</label>
                    <input type="text" name="case_name" class="form-control" required>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">รายละเอียดพฤติการณ์</label>
                    <textarea name="description" class="form-control" rows="4" placeholder="ระบุเหตุการณ์ วันเวลาที่เกิดเหตุ หรือสถานที่..."></textarea>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">อัปโหลดรูปภาพหลักฐาน</label>
                    <input type="file" name="photos[]" class="form-control" multiple accept="image/*" style="padding: 10px;">
                    <small style="color: #64748b; display: block; margin-top: 5px;">* เลือกได้หลายรูป รองรับการถ่ายจากมือถือ</small>
                </div>
            </div>
            
            <!-- Right Column: Students Involved -->
            <div style="background: #fef2f2; padding: 20px; border-radius: 8px; border: 1px solid #fecaca;">
                <h4 style="margin-top: 0; color: #dc2626;">นักเรียนที่เกี่ยวข้องและการตัดคะแนน</h4>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">ค้นหานักเรียน</label>
                    <select id="student-picker" placeholder="พิมพ์ชื่อ หรือ รหัส เพื่อค้นหา...">
                        <option value="">ค้นหานักเรียน...</option>
                        <?php foreach($all_students as $s): ?>
                            <option value="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['full_name']) ?>" data-code="<?= $s['student_code'] ?>" data-room="<?= htmlspecialchars($s['room_name']) ?>">
                                <?= $s['student_code'] ?> - <?= htmlspecialchars($s['full_name']) ?> (<?= htmlspecialchars($s['room_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <table class="table" style="background: white; border-radius: 8px; overflow: hidden;" id="studentsTable">
                    <thead style="background: #fee2e2;">
                        <tr>
                            <th>ชื่อนักเรียน</th>
                            <th style="width: 120px;">คะแนนที่หัก</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody id="studentsBody">
                        <tr id="emptyRow">
                            <td colspan="3" style="text-align: center; color: #94a3b8; padding: 20px;">ยังไม่ได้เพิ่มนักเรียน</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <button type="submit" name="create_case" class="btn btn-primary" style="font-size: 1.2rem; padding: 12px 40px; background: var(--success-color);">บันทึกคดีและหักคะแนนทันที</button>
        </div>
    </form>
</div>

<script>
    let tom = new TomSelect("#student-picker", {
        maxItems: 1,
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        onChange: function(value) {
            if (value) {
                addStudentRow(value);
                this.clear();
            }
        }
    });
    
    function addStudentRow(studentId) {
        // check if already added
        if (document.getElementById('row-' + studentId)) {
            alert('เพิ่มนักเรียนคนนี้ไปแล้ว');
            return;
        }
        
        // hide empty row
        const emptyRow = document.getElementById('emptyRow');
        if (emptyRow) emptyRow.style.display = 'none';
        
        // get student data from option
        const option = document.querySelector(`option[value="${studentId}"]`);
        const name = option.getAttribute('data-name');
        const code = option.getAttribute('data-code');
        const room = option.getAttribute('data-room');
        
        const tbody = document.getElementById('studentsBody');
        const tr = document.createElement('tr');
        tr.id = 'row-' + studentId;
        
        tr.innerHTML = `
            <td>
                <div style="font-weight: bold;">${name}</div>
                <div style="font-size: 0.85rem; color: #64748b;">${code} | ${room}</div>
                <input type="hidden" name="students[]" value="${studentId}">
            </td>
            <td>
                <input type="number" name="points[${studentId}]" class="form-control" value="-20" required style="width: 100%;">
            </td>
            <td style="text-align: center; vertical-align: middle;">
                <button type="button" class="btn btn-sm" style="background: #fee2e2; color: #dc2626;" onclick="removeStudent('${studentId}')">X</button>
            </td>
        `;
        tbody.appendChild(tr);
    }
    
    function removeStudent(studentId) {
        const tr = document.getElementById('row-' + studentId);
        if (tr) tr.remove();
        
        // check if empty
        const tbody = document.getElementById('studentsBody');
        if (tbody.children.length === 1 && tbody.children[0].id === 'emptyRow') {
            document.getElementById('emptyRow').style.display = 'table-row';
        }
    }
</script>

<?php include '../../includes/footer.php'; ?>
