<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';

requireLogin();

$user_id = $_SESSION['user_id'];
$is_admin = hasRole($pdo, $user_id, 'admin');

if (!hasRole($pdo, $user_id, 'teacher') && !$is_admin) {
    header("Location: ../../index.php");
    exit;
}

// AJAX Handler for fetching teacher's specific subjects and classrooms
if (isset($_GET['ajax']) && $_GET['ajax'] == 'teacher_subjects') {
    $tid = $_GET['teacher_id'] ?? 0;
    
    // Fetch subjects assigned to this teacher
    $stmt_sub = $pdo->prepare("SELECT DISTINCT s.id, s.subject_code, s.subject_name FROM teacher_subjects ts JOIN subjects s ON ts.subject_id = s.id WHERE ts.teacher_id = ? ORDER BY s.subject_code ASC");
    $stmt_sub->execute([$tid]);
    $subjects = $stmt_sub->fetchAll(PDO::FETCH_ASSOC);

    // Fetch classrooms assigned to this teacher
    $stmt_cls = $pdo->prepare("SELECT DISTINCT c.id, c.grade_level, c.room_number, c.room_name FROM teacher_subjects ts JOIN classrooms c ON ts.classroom_id = c.id WHERE ts.teacher_id = ? ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC");
    $stmt_cls->execute([$tid]);
    $classrooms = $stmt_cls->fetchAll(PDO::FETCH_ASSOC);

    // Fetch mapping
    $stmt_map = $pdo->prepare("SELECT subject_id, classroom_id FROM teacher_subjects WHERE teacher_id = ?");
    $stmt_map->execute([$tid]);
    $map = [];
    foreach ($stmt_map->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[$row['subject_id']][] = $row['classroom_id'];
    }

    // Fetch department
    $stmt_dept = $pdo->prepare("SELECT department FROM users WHERE id = ?");
    $stmt_dept->execute([$tid]);
    $dept = $stmt_dept->fetchColumn();

    header('Content-Type: application/json');
    echo json_encode(['subjects' => $subjects, 'classrooms' => $classrooms, 'map' => $map, 'department' => $dept]);
    exit;
}

$error = '';
$success = '';

// Handle POST request to add a substitution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $sub_date = $_POST['substitution_date'];
    $sub_teacher_id = $_POST['substitute_teacher_id'];
    $subject_id = $_POST['subject_id'];
    $classroom_id = $_POST['classroom_id'];
    $period_array = $_POST['period'] ?? [];
    $reason = trim($_POST['reason']);
    
    $orig_teacher_id = $is_admin ? $_POST['original_teacher_id'] : $user_id;
    
    if (empty($sub_date) || empty($sub_teacher_id) || empty($subject_id) || empty($classroom_id) || empty($period_array)) {
        $error = "กรุณากรอกข้อมูลให้ครบถ้วน โดยเฉพาะคาบเรียนต้องเลือกอย่างน้อย 1 คาบ";
    } else {
        $period = implode(',', $period_array);
        try {
            $stmt = $pdo->prepare("INSERT INTO teaching_substitutions (original_teacher_id, substitute_teacher_id, subject_id, classroom_id, substitution_date, period, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orig_teacher_id, $sub_teacher_id, $subject_id, $classroom_id, $sub_date, $period, $reason]);
            header("Location: teacher_home.php?success=" . urlencode("บันทึกการมอบหมายสอนแทนเรียบร้อยแล้ว")); exit;
        } catch (Exception $e) {
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// Handle POST request to delete a substitution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $del_id = $_POST['delete_id'];
    try {
        if ($is_admin) {
            $stmt = $pdo->prepare("DELETE FROM teaching_substitutions WHERE id = ?");
            $stmt->execute([$del_id]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM teaching_substitutions WHERE id = ? AND original_teacher_id = ?");
            $stmt->execute([$del_id, $user_id]);
        }
        $success = "ลบการมอบหมายสอนแทนเรียบร้อยแล้ว";
    } catch (Exception $e) {
        $error = "เกิดข้อผิดพลาดในการลบ: " . $e->getMessage();
    }
}

// Fetch Teachers list (with department)
$teachers_stmt = $pdo->query("SELECT id, full_name, department FROM users WHERE id IN (SELECT user_id FROM user_roles WHERE role_id IN (SELECT id FROM roles WHERE role_name = 'teacher')) ORDER BY full_name ASC");
$teachers = $teachers_stmt->fetchAll();

$departments = [];
foreach ($teachers as $t) {
    if (!empty($t['department'])) {
        $departments[$t['department']] = $t['department'];
    }
}
ksort($departments);

// Fetch my assigned substitutions
$my_query = "
    SELECT ts.*, s.subject_name, c.room_name, u.full_name as sub_name
    FROM teaching_substitutions ts
    JOIN subjects s ON ts.subject_id = s.id
    JOIN classrooms c ON ts.classroom_id = c.id
    JOIN users u ON ts.substitute_teacher_id = u.id
    WHERE ts.original_teacher_id = ?
    ORDER BY ts.substitution_date DESC, ts.period ASC
";
if ($is_admin) {
    $my_query = "
        SELECT ts.*, s.subject_name, c.room_name, u.full_name as sub_name, u2.full_name as orig_name
        FROM teaching_substitutions ts
        JOIN subjects s ON ts.subject_id = s.id
        JOIN classrooms c ON ts.classroom_id = c.id
        JOIN users u ON ts.substitute_teacher_id = u.id
        JOIN users u2 ON ts.original_teacher_id = u2.id
        ORDER BY ts.substitution_date DESC, ts.period ASC
    ";
    $my_assignments_stmt = $pdo->prepare($my_query);
    $my_assignments_stmt->execute();
} else {
    $my_assignments_stmt = $pdo->prepare($my_query);
    $my_assignments_stmt->execute([$user_id]);
}
$my_assignments = $my_assignments_stmt->fetchAll();

$page_title = "มอบหมายสอนแทน";
include '../../includes/header.php';
?>

<!-- Load TomSelect -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<div style="max-width: 800px; margin: 0 auto; padding-bottom: 40px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--text-main);">➕ มอบหมายสอนแทน</h2>
        <a href="teacher_home.php" class="btn btn-outline" style="border-radius: 20px; padding: 6px 16px; font-size: 0.85rem; border: 1px solid var(--border-color); color: var(--text-main); text-decoration: none;">⬅️ หน้าหลัก</a>
    </div>

    <?php if ($success): ?>
        <div style="background: #ecfdf5; color: #059669; border: 1px solid #34d399; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 600; text-align: center;">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="background: #fef2f2; color: #dc2626; border: 1px solid #f87171; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; font-weight: 600; text-align: center;">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 25px;">
        <form method="POST" id="substitutionForm" onsubmit="return validateSubstitutionForm()">
            <input type="hidden" name="action" value="add">
            
            <?php if ($is_admin): ?>
            <div class="form-group mb-3">
                <label style="font-size: 0.9rem; font-weight: 600;">ครูเจ้าของวิชา (Admin)</label>
                <select name="original_teacher_id" id="original_teacher_id" class="form-control" required style="font-size: 0.95rem;">
                    <option value="">-- เลือกครูเจ้าของวิชา --</option>
                    <?php foreach($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $t['id'] == $user_id ? 'selected' : '' ?>><?= htmlspecialchars($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <div class="form-group mb-3">
                <label style="font-size: 0.9rem; font-weight: 600;">ครูเจ้าของวิชา (ผู้มอบหมาย)</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['full_name'] ?? 'คุณครู') ?>" readonly style="background: #f1f5f9; font-size: 0.95rem; font-weight: 600; color: #475569;">
                <input type="hidden" name="original_teacher_id" id="original_teacher_id" value="<?= $user_id ?>">
            </div>
            <?php endif; ?>

            <div class="form-group mb-3">
                <label style="font-size: 0.9rem; font-weight: 600;">วันที่ให้สอนแทน</label>
                <input type="date" name="substitution_date" class="form-control" required value="<?= date('Y-m-d') ?>" style="font-size: 0.95rem;">
            </div>

            <div class="form-group mb-3">
                <label style="font-size: 0.9rem; font-weight: 600;">คาบเรียน (เลือกได้หลายคาบ)</label>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <?php for($i=1; $i<=9; $i++): ?>
                        <label style="display: flex; align-items: center; gap: 4px; background: white; border: 1px solid #cbd5e1; padding: 6px 10px; border-radius: 8px; cursor: pointer; font-size: 0.85rem;">
                            <input type="checkbox" name="period[]" value="<?= $i ?>"> คาบ <?= $i ?>
                        </label>
                    <?php endfor; ?>
                </div>
            </div>

            <div class="form-group mb-3">
                <label style="font-size: 0.9rem; font-weight: 600;">รายวิชา (เฉพาะที่ครูรับผิดชอบ)</label>
                <select name="subject_id" id="subject_id" class="form-control" required disabled style="font-size: 0.95rem;">
                    <option value="">-- กรุณาเลือกครูเจ้าของวิชาก่อน --</option>
                </select>
            </div>

            <div class="form-group mb-3">
                <label style="font-size: 0.9rem; font-weight: 600;">ห้องเรียน (เฉพาะที่เรียนวิชานี้)</label>
                <select name="classroom_id" id="classroom_id" class="form-control" required disabled style="font-size: 0.95rem;">
                    <option value="">-- เลือกรายวิชาก่อน --</option>
                </select>
            </div>

            <div style="border-top: 1px dashed #cbd5e1; margin: 20px 0;"></div>

            <div class="form-group mb-3">
                <label style="font-size: 0.9rem; font-weight: 600;">กรองตามกลุ่มสาระฯ (ผู้สอนแทน)</label>
                <select id="department_filter" class="form-control" style="font-size: 0.95rem;">
                    <option value="">-- แสดงทุกกลุ่มสาระฯ --</option>
                    <?php foreach($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-3">
                <label style="font-size: 0.9rem; font-weight: 600;">ครูผู้สอนแทน</label>
                <select name="substitute_teacher_id" id="substitute_teacher_id" class="form-control" required style="font-size: 0.95rem;">
                    <option value="">-- เลือกครูผู้สอนแทน --</option>
                    <?php foreach($teachers as $t): ?>
                        <?php if (!$is_admin && $t['id'] == $user_id) continue; ?>
                        <option value="<?= $t['id'] ?>" data-dept="<?= htmlspecialchars($t['department'] ?? '') ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mb-4">
                <label style="font-size: 0.9rem; font-weight: 600;">เหตุผล</label>
                <select name="reason" class="form-control" required style="font-size: 0.95rem;">
                    <option value="">-- เลือกเหตุผล --</option>
                    <option value="ลากิจ">ลากิจ</option>
                    <option value="ลาป่วย">ลาป่วย</option>
                    <option value="ไปราชการ">ไปราชการ</option>
                </select>
            </div>

                        <div style="text-align: center; margin-top: 10px;">
                <button type="submit" class="btn btn-primary" style="padding: 8px 30px; font-size: 0.95rem; font-weight: 600; border-radius: 12px; box-shadow: 0 4px 10px rgba(37,99,235,0.2);">✅ บันทึกมอบหมาย</button>
            </div>
        </form>
    </div>

    <!-- List Section -->
    <div style="background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 20px;">
        <h3 style="font-size: 1.1rem; font-weight: 700; margin-bottom: 15px; color: var(--text-main);"><?= $is_admin ? "ประวัติการมอบหมายสอนแทนทั้งหมด" : "ประวัติที่คุณมอบหมายให้ผู้อื่น" ?></h3>
        <div style="max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
            <table class="table" style="margin-bottom: 0; font-size: 0.85rem;">
                <thead style="background: #f8fafc; position: sticky; top: 0; z-index: 10;">
                    <tr>
                        <th style="padding: 10px; border-bottom: 1px solid #e2e8f0;">วันที่</th>
                        <th style="padding: 10px; border-bottom: 1px solid #e2e8f0; text-align: center;">คาบ</th>
                        <th style="padding: 10px; border-bottom: 1px solid #e2e8f0;">วิชา/ห้อง</th>
                        <?php if ($is_admin): ?><th style="padding: 10px; border-bottom: 1px solid #e2e8f0;">ครูเจ้าของ</th><?php endif; ?>
                        <th style="padding: 10px; border-bottom: 1px solid #e2e8f0;">ผู้สอนแทน</th>
                        <th style="padding: 10px; border-bottom: 1px solid #e2e8f0;">เหตุผล</th>
                        <th style="padding: 10px; border-bottom: 1px solid #e2e8f0;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($my_assignments as $assign): ?>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #f1f5f9;"><?= date('d/m/Y', strtotime($assign['substitution_date'])) ?></td>
                        <td style="text-align:center; padding: 10px; border-bottom: 1px solid #f1f5f9;"><strong><?= htmlspecialchars($assign['period']) ?></strong></td>
                        <td style="padding: 10px; border-bottom: 1px solid #f1f5f9;">
                            <div style="font-weight: 600; color: #0284c7;"><?= htmlspecialchars($assign['subject_name']) ?></div>
                            <div style="font-size: 0.75rem; color: #64748b;">ห้อง <?= htmlspecialchars($assign['room_name']) ?></div>
                        </td>
                        <?php if ($is_admin): ?><td style="padding: 10px; border-bottom: 1px solid #f1f5f9;"><?= htmlspecialchars($assign['orig_name']) ?></td><?php endif; ?>
                        <td style="color:#059669; font-weight:600; padding: 10px; border-bottom: 1px solid #f1f5f9;"><?= htmlspecialchars($assign['sub_name']) ?></td>
                        <td style="padding: 10px; border-bottom: 1px solid #f1f5f9;"><span style="background: #fef3c7; color: #d97706; padding: 3px 8px; border-radius: 8px; font-size: 0.75rem; font-weight: 600;"><?= htmlspecialchars($assign['reason']) ?></span></td>
                        <td style="padding: 10px; border-bottom: 1px solid #f1f5f9;">
                            <form method="POST" onsubmit="return confirm('ยกเลิกการมอบหมายนี้หรือไม่?');" style="margin:0;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="delete_id" value="<?= $assign['id'] ?>">
                                <button type="submit" style="background: #fef2f2; color: #ef4444; border: 1px solid #fecaca; padding: 4px 10px; font-size: 0.75rem; border-radius: 6px; cursor: pointer; font-weight: 600;">ลบ</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($my_assignments)): ?>
                    <tr>
                        <td colspan="<?= $is_admin ? '7' : '6' ?>" style="text-align:center; color:#94a3b8; padding:30px;">ไม่มีประวัติการมอบหมาย</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    const allSubTeachers = [
        <?php foreach($teachers as $t): ?>
            <?php if (!$is_admin && $t['id'] == $user_id) continue; ?>
            { id: "<?= $t['id'] ?>", text: <?= json_encode($t['full_name']) ?>, dept: <?= json_encode($t['department'] ?? '') ?> },
        <?php endforeach; ?>
    ];

document.addEventListener('DOMContentLoaded', function() {
    const origTeacherSelect = document.getElementById('original_teacher_id');
    const subjectSelect = document.getElementById('subject_id');
    const classroomSelect = document.getElementById('classroom_id');
    const deptFilter = document.getElementById('department_filter');
    const subTeacherSelect = document.getElementById('substitute_teacher_id');

    let subClassMap = {};
    let currentClassrooms = [];

    // Initialize TomSelect for Substitute Teacher
    let subTeacherTs = new TomSelect(subTeacherSelect, {
        create: false,
        sortField: { field: "text", direction: "asc" },
        valueField: 'id',
        labelField: 'text',
        searchField: 'text',
        options: allSubTeachers,
        placeholder: '-- เลือกครูผู้สอนแทน --'
    });

    // Filter Substitute Teacher by Department
    deptFilter.addEventListener('change', function() {
        const selectedDept = this.value;
        subTeacherTs.clear();
        subTeacherTs.clearOptions();
        
        let filtered = allSubTeachers.filter(t => selectedDept === "" || t.dept === selectedDept);
        subTeacherTs.addOptions(filtered);
        subTeacherTs.refreshOptions(false);
    });
    // Trigger initial filter
    deptFilter.dispatchEvent(new Event('change'));

    // Fetch Subjects and Classrooms via AJAX
    function fetchTeacherData(tid) {
        if (!tid) {
            subjectSelect.innerHTML = '<option value="">-- กรุณาเลือกครูเจ้าของวิชาก่อน --</option>';
            subjectSelect.disabled = true;
            classroomSelect.innerHTML = '<option value="">-- เลือกร้านวิชาก่อน --</option>';
            classroomSelect.disabled = true;
            subClassMap = {};
            return;
        }

        subjectSelect.innerHTML = '<option value="">กำลังโหลด...</option>';
        subjectSelect.disabled = true;
        classroomSelect.innerHTML = '<option value="">กำลังโหลด...</option>';
        classroomSelect.disabled = true;

        fetch('teacher_substitute.php?ajax=teacher_subjects&teacher_id=' + tid)
            .then(res => res.json())
            .then(data => {
                subClassMap = data.map || {};
                currentClassrooms = data.classrooms || [];
                
                if (data.department) {
                    deptFilter.value = data.department;
                    deptFilter.dispatchEvent(new Event('change'));
                }

                subjectSelect.innerHTML = '<option value="">-- เลือกรายวิชา --</option>';
                (data.subjects || []).forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.subject_code + ' ' + s.subject_name;
                    subjectSelect.appendChild(opt);
                });
                
                subjectSelect.disabled = false;
                
                // Reset classroom
                classroomSelect.innerHTML = '<option value="">-- เลือกร้านวิชาก่อน --</option>';
                classroomSelect.disabled = true;
            });
    }

    // Update Classroom dropdown when Subject changes
    subjectSelect.addEventListener('change', function() {
        const sid = this.value;
        if (!sid) {
            classroomSelect.innerHTML = '<option value="">-- เลือกร้านวิชาก่อน --</option>';
            classroomSelect.disabled = true;
            return;
        }

        const validClassroomIds = subClassMap[sid] ? subClassMap[sid].map(String) : [];
        classroomSelect.innerHTML = '<option value="">-- เลือกห้องเรียน --</option>';
        
        currentClassrooms.forEach(c => {
            if (validClassroomIds.includes(String(c.id))) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.room_name;
                classroomSelect.appendChild(opt);
            }
        });

        classroomSelect.disabled = false;
    });

    
    function validateSubstitutionForm() {
        const periods = document.querySelectorAll('input[name="period[]"]:checked');
        if (periods.length === 0) {
            alert("⚠️ กรุณาเลือกคาบเรียนที่ต้องการให้สอนแทน (อย่างน้อย 1 คาบ)");
            return false;
        }
        return true;
    }
    
    // Assign to form
    const form = document.getElementById('substitutionForm');
    if(form) {
        form.onsubmit = validateSubstitutionForm;
    }

    // Trigger initial load for teacher (since input is hidden and has value)
    if (origTeacherSelect && origTeacherSelect.tagName === 'SELECT') {
        // If Admin
        origTeacherSelect.addEventListener('change', function() {
            fetchTeacherData(this.value);
        });
        
        // Init TomSelect
        new TomSelect(origTeacherSelect, {
            create: false,
            sortField: {
                field: "text",
                direction: "asc"
            }
        });
        
        if (origTeacherSelect.value) {
            fetchTeacherData(origTeacherSelect.value);
        }
    } else {
        // If normal teacher, original_teacher_id is hidden input
        const hiddenTid = document.getElementById('original_teacher_id').value;
        if (hiddenTid) fetchTeacherData(hiddenTid);
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
