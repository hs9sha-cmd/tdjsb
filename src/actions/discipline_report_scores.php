<?php
session_start();
require_once '../../config/database.php';

// Check roles
$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "พิมพ์รายงานสรุปคะแนนความประพฤติ";
include '../../includes/header.php';

// Get active academic term
$stmt_term = $pdo->query("SELECT id, term_number, year_name FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term = $stmt_term->fetch();
$term_id = $active_term['id'] ?? null;
$term_text = $term_id ? "ภาคเรียนที่ {$active_term['term_number']} ปีการศึกษา {$active_term['year_name']}" : "ไม่พบปีการศึกษาที่เปิดใช้งาน";

// Fetch all classrooms for select
$classrooms_all = $pdo->query("SELECT id, room_name, grade_level FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC")->fetchAll();
$grades_all = array_unique(array_column($classrooms_all, 'grade_level'));

// Form handling
$filter_type = $_GET['filter_type'] ?? 'room'; // 'room' or 'grade'
$filter_grade = $_GET['filter_grade'] ?? '';
$stmt_set = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'school_name'");
$default_school_name = $stmt_set ? ($stmt_set->fetchColumn() ?: 'โรงเรียนของคุณ') : 'โรงเรียนของคุณ';
$school_name = $_GET['school_name'] ?? $default_school_name;

$target_classrooms = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($filter_grade || $filter_room)) {
    if ($filter_type === 'grade' && $filter_grade) {
        $stmt_c = $pdo->prepare("SELECT id, room_name FROM classrooms WHERE grade_level = ? ORDER BY CAST(room_number AS UNSIGNED) ASC");
        $stmt_c->execute([$filter_grade]);
        $target_classrooms = $stmt_c->fetchAll();
    } elseif ($filter_type === 'room' && $filter_room) {
        $stmt_c = $pdo->prepare("SELECT id, room_name FROM classrooms WHERE id = ?");
        $stmt_c->execute([$filter_room]);
        $target_classrooms = $stmt_c->fetchAll();
    }
}
?>

<style>
/* Print Settings */
@page {
    size: A4 portrait;
    margin: 5mm; /* ขอบกระดาษน้อยที่สุด */
}
@media print {
    body { background-color: white !important; font-size: 12px !important; line-height: 1.1 !important; }
    .sidebar, .top-header, .no-print, .card, .topbar { display: none !important; }
    .app-wrapper, .content-area, .main-content { 
        display: block !important; 
        position: static !important;
        height: auto !important; 
        min-height: auto !important; 
        overflow: visible !important; 
        margin: 0 !important; 
        padding: 0 !important; 
        width: 100% !important; 
    }
    
    .print-page {
        page-break-after: always;
        width: 100%;
        margin: 0 !important;
        padding: 0 !important; /* ใช้ margin จาก @page แทน */
        box-shadow: none !important;
        border: none !important;
        min-height: auto !important;
        height: auto !important;
    }
    .print-page:last-of-type {
        page-break-after: auto;
    }
    .report-table th, .report-table td {
        padding: 2px 4px !important; /* บีบช่องว่างตารางสุดๆ */
        font-size: 12px !important;
        height: 16px !important;
    }
    .report-header { margin-bottom: 5px !important; }
    .report-title { font-size: 16px !important; margin-bottom: 2px !important; }
    .report-subtitle { font-size: 14px !important; margin-bottom: 2px !important; }
    .report-info { font-size: 12px !important; margin-bottom: 2px !important; }
    .signature-section { margin-top: 15px !important; font-size: 12px !important; }
    .signature-section p { margin-bottom: 2px !important; }
}

/* Screen Preview Settings */
.print-page {
    background: white;
    max-width: 210mm; /* A4 width */
    min-height: 297mm; /* A4 height */
    margin: 0 auto 30px;
    padding: 15mm;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    color: #000;
}

.report-header {
    text-align: center;
    position: relative;
    margin-bottom: 25px;
}
.report-logo {
    position: absolute;
    left: 0;
    top: 0;
    width: 45px;
    height: auto;
}
.report-title {
    font-size: 1.3rem;
    font-weight: bold;
    margin: 0 0 5px;
}
.report-subtitle {
    font-size: 1.1rem;
    margin: 0 0 5px;
}
.report-info {
    font-size: 1rem;
    margin: 0;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    font-size: 0.95rem;
}
.report-table th, .report-table td {
    border: 1px solid #cbd5e1;
    padding: 8px;
    text-align: center;
}
.report-table th {
    background-color: #f8fafc;
    font-weight: bold;
}
.report-table td.text-left {
    text-align: left;
}
</style>

<div class="card no-print" style="margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2 class="card-title" style="margin: 0;">🖨️ พิมพ์รายงานสรุปคะแนนความประพฤติ</h2>
        <a href="discipline_dashboard.php" class="btn btn-secondary">กลับหน้าหลัก</a>
    </div>

    <form method="GET" style="background: var(--apple-bg); padding: 20px; border-radius: 8px; border: 1px solid var(--border-color);">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>ชื่อโรงเรียน (สำหรับออกรายงาน)</label>
                <input type="text" name="school_name" class="form-control" value="<?= htmlspecialchars($school_name) ?>">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>รูปแบบการพิมพ์</label>
                <select name="filter_type" id="filter_type" class="form-control" onchange="toggleFilter()">
                    <option value="room" <?= $filter_type === 'room' ? 'selected' : '' ?>>เลือกทีละห้อง</option>
                    <option value="grade" <?= $filter_type === 'grade' ? 'selected' : '' ?>>เลือกทั้งระดับชั้น (พิมพ์หลายหน้า)</option>
                </select>
            </div>
            
            <div class="form-group" id="div_room" style="margin-bottom: 0; <?= $filter_type === 'grade' ? 'display: none;' : '' ?>">
                <label>เลือกห้องเรียน</label>
                <select name="filter_room" class="form-control">
                    <option value="">-- เลือกห้องเรียน --</option>
                    <?php foreach($classrooms_all as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filter_room == $c['id'] ? 'selected' : '' ?>><?= $c['room_name'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="div_grade" style="margin-bottom: 0; <?= $filter_type === 'room' ? 'display: none;' : '' ?>">
                <label>เลือกระดับชั้น</label>
                <select name="filter_grade" class="form-control">
                    <option value="">-- เลือกระดับชั้น --</option>
                    <?php foreach($grades_all as $g): ?>
                        <option value="<?= $g ?>" <?= $filter_grade == $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button type="submit" class="btn btn-primary">ดูตัวอย่างรายงาน</button>
            <?php if (!empty($target_classrooms)): ?>
                <button type="button" class="btn btn-success" onclick="window.print()">🖨️ สั่งพิมพ์ (PDF)</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
function toggleFilter() {
    var type = document.getElementById('filter_type').value;
    if (type === 'room') {
        document.getElementById('div_room').style.display = 'block';
        document.getElementById('div_grade').style.display = 'none';
    } else {
        document.getElementById('div_room').style.display = 'none';
        document.getElementById('div_grade').style.display = 'block';
    }
}
</script>

<?php if (empty($target_classrooms) && ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['filter_type']))): ?>
    <div class="card no-print" style="text-align: center; color: var(--danger-color);">
        ไม่พบข้อมูลห้องเรียน กรุณาเลือกเงื่อนไขให้ถูกต้อง
    </div>
<?php endif; ?>

<?php
// Render pages
$total_pages = count($target_classrooms);
$current_page_idx = 0;
foreach ($target_classrooms as $c) {
    $current_page_idx++;
    $c_id = $c['id'];
    $c_name = htmlspecialchars($c['room_name']);

    // 1. Fetch homeroom teachers for this room
    $stmt_ht = $pdo->prepare("SELECT full_name FROM users WHERE TRIM(advisor_classroom) = ?");
    $stmt_ht->execute([trim($c_name)]);
    $teachers = $stmt_ht->fetchAll(PDO::FETCH_COLUMN);
    // Filter out any empty names
    $teachers = array_filter($teachers);
    $teachers_str = !empty($teachers) ? implode(", ", $teachers) : "ไม่มีครูที่ปรึกษาประจำห้อง";

    // 2. Fetch students and their current score
    $stmt_s = $pdo->prepare("
        SELECT s.student_code, s.student_number, s.full_name, COALESCE(sb.current_score, 100) as current_score
        FROM students s
        LEFT JOIN student_behaviors sb ON s.id = sb.student_id AND sb.academic_term_id = ?
        WHERE s.classroom_id = ? AND s.status = 'active'
        ORDER BY CAST(s.student_number AS UNSIGNED) ASC, s.student_code ASC
    ");
    $stmt_s->execute([$term_id, $c_id]);
    $students = $stmt_s->fetchAll();

    ?>
    
    <div class="print-page" <?= ($current_page_idx === $total_pages) ? 'style="page-break-after: auto !important;"' : '' ?>>
        <div class="report-header">
            <!-- Logo (ใช้โลโก้ของระบบถ้ามี ถ้าไม่มีจะเว้นว่างไว้) -->
            <?php if (file_exists('../../assets/images/school_logo.png')): ?>
                <img src="<?= $base_url ?>/assets/images/school_logo.png" class="report-logo" alt="Logo">
            <?php endif; ?>
            
            <div style="flex-grow: 1;">
                <h3 class="report-title"><?= htmlspecialchars($school_name) ?></h3>
                <h4 class="report-subtitle">รายงานสรุปคะแนนความประพฤตินักเรียน (<?= $term_text ?>)</h4>
                <p class="report-info">
                    <strong>ชั้น/ห้อง:</strong> <?= $c_name ?> &nbsp;&nbsp;|&nbsp;&nbsp; 
                    <strong>ครูที่ปรึกษา:</strong> <?= htmlspecialchars($teachers_str) ?>
                </p>
                <p class="report-info" style="font-size: 0.9rem; margin-top: 3px; color: #64748b;">
                    ข้อมูล ณ วันที่: <?= date('d/m/Y') ?>
                </p>
            </div>
        </div>

        <table class="report-table">
            <thead>
                <tr>
                    <th style="width: 15%;">เลขที่</th>
                    <th style="width: 20%;">เลขประจำตัว</th>
                    <th style="width: 45%; text-align: left;">ชื่อ - สกุล</th>
                    <th style="width: 20%;">คะแนนปัจจุบัน</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($students) > 0): ?>
                    <?php foreach($students as $st): 
                        $score = (int)$st['current_score'];
                        $score_color = ($score < 60) ? 'color: red; font-weight: bold;' : '';
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($st['student_number']) ?></td>
                            <td><?= htmlspecialchars($st['student_code']) ?></td>
                            <td class="text-left"><?= htmlspecialchars($st['full_name']) ?></td>
                            <td style="<?= $score_color ?>"><?= $score ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: #64748b; padding: 20px;">ไม่มีข้อมูลนักเรียนในห้องนี้</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="signature-section" style="margin-top: 40px; display: flex; justify-content: space-around; flex-wrap: wrap; gap: 20px; font-size: 0.95rem;">
            <?php if (!empty($teachers)): ?>
                <?php foreach($teachers as $t_name): ?>
                    <div style="text-align: center;">
                        <p>ลงชื่อ..............................................................</p>
                        <p>(<?= htmlspecialchars($t_name) ?>)</p>
                        <p>ครูที่ปรึกษา</p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align: center;">
                    <p>ลงชื่อ..............................................................</p>
                    <p>(..............................................................)</p>
                    <p>ครูที่ปรึกษา</p>
                </div>
            <?php endif; ?>
            
            <div style="text-align: center;">
                <p>ลงชื่อ..............................................................</p>
                <p>(..............................................................)</p>
                <p>หัวหน้าระดับชั้น</p>
            </div>
        </div>
    </div>
    
<?php 
} // End foreach target_classrooms
?>

<?php include '../../includes/footer.php'; ?>
