<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';

// ต้องมี role อย่างน้อย teacher ถึงจะเข้าหน้านี้ได้
// แต่ไม่บังคับเฉพาะ teacher เพราะคนอาจมีหลาย role
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../index.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$user_roles = $_SESSION['roles'] ?? [];

// Auto-migrate: Add profile_photo column if it doesn't exist yet
try {
    $pdo->query("SELECT profile_photo FROM users LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) DEFAULT NULL AFTER department");
    } catch (Exception $e2) {}
}

// Ensure teaching_substitutions status supports 'rejected'
try {
    $pdo->exec("ALTER TABLE teaching_substitutions MODIFY COLUMN status ENUM('pending','completed','rejected') DEFAULT 'pending'");
} catch (Exception $e) {}

// Handle Reject Substitution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_sub' && isset($_POST['sub_id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE teaching_substitutions SET status = 'rejected' WHERE id = ? AND substitute_teacher_id = ? AND status = 'pending'");
        $stmt->execute([$_POST['sub_id'], $teacher_id]);
        
        if ($stmt->rowCount() > 0) {
            // Notify original teacher
            $stmt_info = $pdo->prepare("
                SELECT ts.original_teacher_id, s.subject_name, ts.period, ts.substitution_date
                FROM teaching_substitutions ts
                JOIN subjects s ON ts.subject_id = s.id
                WHERE ts.id = ?
            ");
            $stmt_info->execute([$_POST['sub_id']]);
            if ($sinfo = $stmt_info->fetch()) {
                $teacher_name = $_SESSION['full_name'];
                $date_str = date('d/m/Y', strtotime($sinfo['substitution_date']));
                $msg = "❌ {$teacher_name} ได้ปฏิเสธการสอนแทน (วิชา {$sinfo['subject_name']} คาบ {$sinfo['period']} วันที่ {$date_str})";
                $link = "teacher_substitute.php";
                
                $ref_id = "sub_rej_" . $_POST['sub_id'];
                $check_notif = $pdo->prepare("SELECT id FROM notifications WHERE reference_id = ? LIMIT 1");
                $check_notif->execute([$ref_id]);
                if (!$check_notif->fetch()) {
                    $ins_notif = $pdo->prepare("INSERT INTO notifications (user_id, message, reference_type, reference_id, link) VALUES (?, ?, 'sub_rejected', ?, ?)");
                    $ins_notif->execute([$sinfo['original_teacher_id'], $msg, $ref_id, $link]);
                }
            }
        }
        
        header("Location: teacher_home.php?success=" . urlencode("ปฏิเสธคาบสอนแทนเรียบร้อยแล้ว"));
        exit;
    } catch (Exception $e) {}
}

// Handle Profile Photo Upload (Supports any size + client-side & server-side compression)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['profile_photo_base64']) || isset($_FILES['profile_photo']))) {
    $upload_dir = __DIR__ . '/../../assets/uploads/teachers/';
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0777, true);
    }
    
    $raw_image_data = null;
    
    // 1. High-speed client-compressed base64
    if (!empty($_POST['profile_photo_base64'])) {
        $base64_str = $_POST['profile_photo_base64'];
        if (strpos($base64_str, 'base64,') !== false) {
            $base64_str = substr($base64_str, strpos($base64_str, 'base64,') + 7);
            $raw_image_data = base64_decode($base64_str);
        }
    } 
    // 2. Direct file upload fallback
    elseif (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $raw_image_data = file_get_contents($_FILES['profile_photo']['tmp_name']);
    }
    
    if ($raw_image_data) {
        $src_img = @imagecreatefromstring($raw_image_data);
        if ($src_img !== false) {
            $orig_w = imagesx($src_img);
            $orig_h = imagesy($src_img);
            $max_dim = 450; // Crisp & light
            
            if ($orig_w > $max_dim || $orig_h > $max_dim) {
                $ratio = $orig_w / $orig_h;
                if ($ratio > 1) {
                    $new_w = $max_dim;
                    $new_h = (int)($max_dim / $ratio);
                } else {
                    $new_h = $max_dim;
                    $new_w = (int)($max_dim * $ratio);
                }
            } else {
                $new_w = $orig_w;
                $new_h = $orig_h;
            }
            
            $dst_img = imagecreatetruecolor($new_w, $new_h);
            $white = imagecolorallocate($dst_img, 255, 255, 255);
            imagefilledrectangle($dst_img, 0, 0, $new_w, $new_h, $white);
            imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $new_w, $new_h, $orig_w, $orig_h);
            
            $filename = "teacher_{$teacher_id}_" . time() . ".jpg";
            $filepath = $upload_dir . $filename;
            
            // Save as crystal-clear 85% JPEG (~30-60KB)
            imagejpeg($dst_img, $filepath, 85);
            imagedestroy($src_img);
            imagedestroy($dst_img);
            
            // Delete old photo if exists
            try {
                $old_photo_stmt = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
                $old_photo_stmt->execute([$teacher_id]);
                $old_photo = $old_photo_stmt->fetchColumn();
                if ($old_photo && file_exists(__DIR__ . '/../../' . $old_photo)) {
                    @unlink(__DIR__ . '/../../' . $old_photo);
                }
                
                $relative_path = "assets/uploads/teachers/{$filename}";
                $stmt_update = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
                $stmt_update->execute([$relative_path, $teacher_id]);
            } catch (Exception $e) {}
            
            header("Location: teacher_home.php?success=" . urlencode("อัปโหลดและปรับขนาดรูปโปรไฟล์เรียบร้อยแล้ว"));
            exit;
        }
    }
    
    header("Location: teacher_home.php?error=" . urlencode("ไม่สามารถประมวลผลไฟล์ภาพได้ กรุณาลองใหม่อีกครั้ง"));
    exit;
}

$page_title = "หน้าหลัก";
include '../../includes/header.php';

// Get Active Term
$term_stmt = $pdo->query("SELECT id, year_name, term_number FROM academic_terms WHERE is_active = 1 LIMIT 1");
$active_term = $term_stmt->fetch();

// Get Teacher Profile (with fallback if profile_photo column doesn't exist yet)
try {
    $stmt = $pdo->prepare("SELECT full_name, advisor_classroom, department, profile_photo FROM users WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $teacher = $stmt->fetch();
} catch (PDOException $e) {
    // Fallback if migration hasn't been run
    $stmt = $pdo->prepare("SELECT full_name, advisor_classroom, department FROM users WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $teacher = $stmt->fetch();
    $teacher['profile_photo'] = null; // Mock the column
}

// Check for open inspection cycles (for badge on behavior button)
$open_cycles_count = 0;
if (in_array('teacher', $user_roles) && !empty($teacher['advisor_classroom'])) {
    try {
        $stmt_open_cycles = $pdo->prepare("
            SELECT COUNT(DISTINCT ic.id) 
            FROM inspection_cycles ic
            JOIN classrooms c ON c.room_name = ?
            WHERE ic.status = 'open' 
              AND (ic.target_grade_level = 'all' OR ic.target_grade_level = c.grade_level)
              AND (
                  NOT EXISTS (
                      SELECT 1 FROM inspection_room_locks irl 
                      WHERE irl.cycle_id = ic.id AND irl.classroom_id = c.id
                  )
                  OR EXISTS (
                      SELECT 1 FROM discipline_inspections di
                      JOIN students s ON di.student_id = s.id
                      WHERE di.cycle_id = ic.id AND s.classroom_id = c.id AND di.overall_status = 'pending'
                  )
              )
        ");
        $stmt_open_cycles->execute([trim($teacher['advisor_classroom'])]);
        $open_cycles_count = (int)$stmt_open_cycles->fetchColumn();
    } catch (Exception $e) {}
}

// Get today's late students (for advisor's classroom)
$late_students_today = [];
if ($teacher['advisor_classroom'] && (in_array('teacher', $user_roles) || in_array('assistant', $user_roles))) {
    try {
        $stmt_late = $pdo->prepare("
            SELECT s.student_code, s.full_name, s.student_number, s.photo_path, c.room_name
            FROM homeroom_attendance ha
            JOIN students s ON ha.student_id = s.id
            JOIN classrooms c ON s.classroom_id = c.id
            WHERE ha.attendance_date = CURDATE() AND ha.status = 'late'
            AND c.room_name = ?
            ORDER BY CAST(s.student_number AS UNSIGNED) ASC
        ");
        $stmt_late->execute([$teacher['advisor_classroom']]);
        $late_students_today = $stmt_late->fetchAll();
    } catch (Exception $e) {}
}

// Get today's absent students (for advisor's classroom)
$absent_students_today = [];
if ($teacher['advisor_classroom'] && (in_array('teacher', $user_roles) || in_array('assistant', $user_roles))) {
    try {
        $stmt_absent = $pdo->prepare("
            SELECT s.student_code, s.full_name, s.student_number, s.photo_path, c.room_name
            FROM homeroom_attendance ha
            JOIN students s ON ha.student_id = s.id
            JOIN classrooms c ON s.classroom_id = c.id
            WHERE ha.attendance_date = CURDATE() AND ha.status = 'absent'
            AND c.room_name = ?
            ORDER BY CAST(s.student_number AS UNSIGNED) ASC
        ");
        $stmt_absent->execute([$teacher['advisor_classroom']]);
        $absent_students_today = $stmt_absent->fetchAll();
    } catch (Exception $e) {}
}

// Homeroom Summary
$hm_summary = ['present' => 0, 'late' => 0, 'leave' => 0, 'absent' => 0, 'total' => 0, 'percent' => 0];
if ($teacher['advisor_classroom'] && (in_array('teacher', $user_roles) || in_array('assistant', $user_roles))) {
    try {
        $stmt_sum = $pdo->prepare("
            SELECT ha.status, COUNT(*) as cnt
            FROM homeroom_attendance ha
            JOIN students s ON ha.student_id = s.id
            JOIN classrooms c ON s.classroom_id = c.id
            WHERE ha.attendance_date = CURDATE() AND c.room_name = ?
            GROUP BY ha.status
        ");
        $stmt_sum->execute([$teacher['advisor_classroom']]);
        $rows = $stmt_sum->fetchAll();
        foreach ($rows as $r) {
            $st = $r['status'];
            if (isset($hm_summary[$st])) {
                $hm_summary[$st] = (int)$r['cnt'];
            }
        }
        
        $stmt_tot = $pdo->prepare("SELECT COUNT(*) FROM students s JOIN classrooms c ON s.classroom_id = c.id WHERE c.room_name = ? AND s.status = 'active'");
        $stmt_tot->execute([$teacher['advisor_classroom']]);
        $hm_summary['total'] = (int)$stmt_tot->fetchColumn();
        
        $arrived = $hm_summary['present'] + $hm_summary['late'];
        if ($hm_summary['total'] > 0) {
            $hm_summary['percent'] = round(($arrived / $hm_summary['total']) * 100);
        }
    } catch (Exception $e) {}
}

// Pending Substitutions for next 7 days
$sub_today = [];
if (in_array('teacher', $user_roles)) {
    try {
        $sub_stmt = $pdo->prepare("
            SELECT ts.id, ts.substitution_date, ts.period, s.subject_name, c.room_name, u.full_name as original_teacher_name
            FROM teaching_substitutions ts
            JOIN subjects s ON ts.subject_id = s.id
            JOIN classrooms c ON ts.classroom_id = c.id
            JOIN users u ON ts.original_teacher_id = u.id
            WHERE ts.substitute_teacher_id = ? 
              AND ts.substitution_date >= CURDATE() 
              AND ts.substitution_date < DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
              AND ts.status = 'pending'
            ORDER BY ts.substitution_date ASC, ts.period ASC
        ");
        $sub_stmt->execute([$teacher_id]);
        $sub_today = $sub_stmt->fetchAll();
    } catch (Exception $e) {}
}

// At-risk students (advisor's class, absent > 3 times in any subject)
$at_risk_students = [];
if ($teacher['advisor_classroom'] && in_array('teacher', $user_roles) && $active_term) {
    try {
        $adv_rooms_stmt = $pdo->prepare("SELECT classroom_id FROM homeroom_teachers WHERE teacher_id = ? AND academic_term_id = ?");
        $adv_rooms_stmt->execute([$teacher_id, $active_term['id']]);
        $room_ids = $adv_rooms_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($room_ids)) {
            $inQuery = implode(',', array_fill(0, count($room_ids), '?'));
            $at_risk_stmt = $pdo->prepare("
                SELECT s.full_name, s.student_number, c.room_name, sub.subject_name, COUNT(ca.id) as absent_count
                FROM class_attendance ca
                JOIN teaching_logs tl ON ca.teaching_log_id = tl.id
                JOIN subjects sub ON tl.subject_id = sub.id
                JOIN students s ON ca.student_id = s.id
                LEFT JOIN classrooms c ON s.classroom_id = c.id
                WHERE ca.status = 'absent' AND tl.log_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) AND s.classroom_id IN ($inQuery)
                GROUP BY s.id, sub.id
                HAVING absent_count > 3
                ORDER BY absent_count DESC, CAST(s.student_number AS UNSIGNED) ASC
                LIMIT 10
            ");
            $at_risk_stmt->execute($room_ids);
            $at_risk_students = $at_risk_stmt->fetchAll();
        }
    } catch (Exception $e) {}
}

// Notification counts
$unread_alerts = 0;
$daily_late_count = count($late_students_today);
try {
    $stmt_alerts = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt_alerts->execute([$_SESSION['user_id']]);
    $unread_alerts = $stmt_alerts->fetchColumn();
} catch (Exception $e) {}

// Profile photo URL
$photo_url = '';
$has_profile_photo = !empty($teacher['profile_photo']) && (file_exists(__DIR__ . '/../../' . $teacher['profile_photo']) || file_exists('../../' . $teacher['profile_photo']));
if ($has_profile_photo) {
    $photo_url = '../../' . $teacher['profile_photo'] . '?v=' . time();
} else {
    $encoded_name = urlencode($teacher['full_name'] ?? 'User');
    $photo_url = "https://ui-avatars.com/api/?name={$encoded_name}&size=120&background=10b981&color=fff&bold=true&font-size=0.4";
}
?>

<style>

/* Quick Actions Grid */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}
@media (max-width: 1024px) {
    .quick-actions {
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
    }
}
@media (max-width: 480px) {
    .quick-actions {
        grid-template-columns: repeat(3, 1fr);
    }
}
.quick-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    color: var(--text-main);
    background: var(--bg-panel);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 14px 8px;
    text-align: center;
    transition: all 0.2s;
    position: relative;
    min-height: 90px;
}
.quick-action-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}
.quick-action-btn .action-icon {
    font-size: 28px;
    margin-bottom: 6px;
}
.quick-action-btn .action-icon .material-symbols-rounded {
    font-size: 28px;
    color: var(--primary-color);
}
.quick-action-btn .action-label {
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1.3;
}
.quick-action-btn .action-badge {
    position: absolute;
    top: 6px;
    right: 6px;
    background: var(--danger-color);
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 0.7rem;
    font-weight: bold;
    border: 2px solid var(--bg-panel);
}

/* Section Cards */
.home-section {
    background: var(--bg-panel);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 16px;
}
.home-section-title {
    font-size: 0.95rem;
    font-weight: 700;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Student List Item */
.student-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 0;
    border-bottom: 1px solid var(--border-color);
}
.student-item:last-child {
    border-bottom: none;
}
.student-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
    background: #f0f0f0;
}
.student-info {
    flex: 1;
    min-width: 0;
}
.student-info .name {
    font-size: 0.85rem;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.student-info .detail {
    font-size: 0.75rem;
    color: var(--text-muted);
}
.student-status {
    font-size: 0.75rem;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 8px;
    flex-shrink: 0;
}
.status-late {
    background: #fef2f2;
    color: #dc2626;
}
.status-absent {
    background: #fffbeb;
    color: #d97706;
}

/* Sub teaching card */
.sub-card {
    background: #f0f9ff;
    border-left: 3px solid #0ea5e9;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 8px;
}
.sub-card:last-child { margin-bottom: 0; }

/* Upload Modal */
.upload-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 2000;
    justify-content: center;
    align-items: center;
}
.upload-overlay.active { display: flex; }
.upload-modal {
    background: var(--bg-panel);
    border-radius: 16px;
    padding: 24px;
    max-width: 400px;
    width: 90%;
    text-align: center;
}
.upload-modal h3 {
    margin-bottom: 16px;
    font-size: 1.1rem;
}
</style>

<?php if (isset($_GET['success'])): ?>
    <div class="badge badge-success mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: center;">✅ <?= htmlspecialchars($_GET['success']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <div class="badge badge-danger mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: center;">❌ <?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>



<!-- Upload Photo Modal -->
<div class="upload-overlay" id="uploadOverlay">
    <div class="upload-modal" style="max-width: 380px;">
        <h3 style="margin-bottom: 8px; font-size: 1.15rem;">📷 เปลี่ยนรูปโปรไฟล์</h3>
        <p style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 16px;">
            เลือกภาพถ่ายขนาดใดก็ได้ ระบบจะปรับให้คมชัดและขนาดเบาโดยอัตโนมัติ
        </p>
        
        <form method="POST" id="profileUploadForm" enctype="multipart/form-data">
            <!-- Preview Box -->
            <div id="previewContainer" style="display: none; margin-bottom: 16px;">
                <img id="imagePreview" src="" alt="Preview" style="width: 110px; height: 110px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary-color); box-shadow: 0 4px 12px rgba(0,0,0,0.15); margin: 0 auto; display: block;">
                <div id="compressStatus" style="font-size: 0.75rem; color: var(--primary-color); margin-top: 8px; font-weight: 600;"></div>
            </div>

            <input type="file" id="profileFileInput" accept="image/*" required
                   style="margin-bottom: 16px; width: 100%; font-size: 0.85rem;">
            <input type="hidden" name="profile_photo_base64" id="profilePhotoBase64">
            
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button type="submit" id="submitUploadBtn" class="btn" style="background: var(--primary-color); color: white; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                    <span class="material-symbols-rounded" style="font-size: 18px;">upload</span> บันทึกรูปโปรไฟล์
                </button>
                <button type="button" class="btn" onclick="document.getElementById('uploadOverlay').classList.remove('active')"
                        style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 10px 18px; border-radius: 10px; font-weight: 600; cursor: pointer;">
                    ยกเลิก
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Quick Actions Grid -->
<div class="quick-actions">

    <?php if (in_array('teacher', $user_roles)): ?>
        <a href="teacher_homeroom.php" class="quick-action-btn">
            <div class="action-icon"><span class="material-symbols-rounded">flag</span></div>
            <span class="action-label">เช็คชื่อเข้าแถว</span>
        </a>

        <a href="teacher_class.php" class="quick-action-btn">
            <div class="action-icon"><span class="material-symbols-rounded">edit_note</span></div>
            <span class="action-label">บันทึกการสอน</span>
        </a>



        <a href="teacher_substitute.php" class="quick-action-btn">
            <div class="action-icon"><span class="material-symbols-rounded">swap_horiz</span></div>
            <span class="action-label">มอบหมายสอนแทน</span>
        </a>

        <a href="teacher_behavior.php" class="quick-action-btn">
            <?php if ($open_cycles_count > 0): ?>
                <span class="action-badge"><?= $open_cycles_count ?></span>
            <?php endif; ?>
            <div class="action-icon"><span class="material-symbols-rounded">checklist</span></div>
            <span class="action-label">ตรวจระเบียบ/พฤติกรรม</span>
        </a>
    <?php endif; ?>

    <?php if (in_array('assistant', $user_roles) && !in_array('teacher', $user_roles)): ?>
        <a href="teacher_homeroom.php" class="quick-action-btn">
            <div class="action-icon"><span class="material-symbols-rounded">flag</span></div>
            <span class="action-label">เช็คชื่อเข้าแถว</span>
        </a>
    <?php endif; ?>

    <?php if (in_array('discipline', $user_roles)): ?>
        <a href="discipline_late_check.php" class="quick-action-btn">
            <div class="action-icon"><span class="material-symbols-rounded">directions_run</span></div>
            <span class="action-label">เช็คมาสาย(กิจการฯ)</span>
        </a>
    <?php endif; ?>

    <?php if (in_array('friday_prayer_duty', $user_roles) || in_array('discipline', $user_roles) || in_array('student_affairs', $user_roles) || in_array('level_head', $user_roles)): ?>
        <a href="discipline_friday_prayer.php" class="quick-action-btn">
            <div class="action-icon"><span class="material-symbols-rounded">mosque</span></div>
            <span class="action-label">สแกนละหมาดวันศุกร์</span>
        </a>
    <?php endif; ?>

    <?php if (in_array('inspector', $user_roles)): ?>
        <a href="inspector_late_check.php" class="quick-action-btn">
            <div class="action-icon"><span class="material-symbols-rounded">security</span></div>
            <span class="action-label">เช็คมาสาย (สารวัตร)</span>
        </a>
    <?php endif; ?>

    <?php if (in_array('discipline', $user_roles) || in_array('level_head', $user_roles) || in_array('admin', $user_roles) || in_array('executive', $user_roles)): ?>
        <a href="discipline_dashboard.php" class="quick-action-btn" style="background: #eff6ff; border-color: #bfdbfe;">
            <div class="action-icon"><span class="material-symbols-rounded" style="color: #2563eb;">dashboard</span></div>
            <span class="action-label" style="color: #1e3a8a;">แดชบอร์ดกิจการฯ</span>
        </a>
    <?php endif; ?>

</div>

<!-- Homeroom Attendance Summary Card -->
<?php if ($teacher['advisor_classroom'] && (in_array('teacher', $user_roles) || in_array('assistant', $user_roles))): ?>
<div class="card" style="margin-bottom: 20px; padding: 15px; border-radius: 14px; background: white; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="background: #10b981; color: white; width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                <span class="material-symbols-rounded" style="font-size: 20px;">group</span>
            </div>
            <div style="line-height: 1.2;">
                <div style="font-weight: 700; font-size: 1.05rem; color: #1e293b;"><?= htmlspecialchars($teacher['advisor_classroom']) ?></div>
                <div style="font-size: 0.75rem; color: #64748b;">ห้องที่ปรึกษาวันนี้</div>
            </div>
        </div>
        <div style="text-align: right; line-height: 1.2;">
            <div style="font-weight: 800; font-size: 1.2rem; color: #10b981;"><?= $hm_summary['percent'] ?>%</div>
            <div style="font-size: 0.7rem; color: #64748b;">มาเรียน / <?= $hm_summary['total'] ?> คน</div>
        </div>
    </div>
    <div style="height: 1px; background: #f1f5f9; margin-bottom: 12px; border-radius: 2px;"></div>
    
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
        <!-- มา -->
        <div style="background: #ecfdf5; border: 1px solid #d1fae5; border-radius: 8px; padding: 6px 4px; text-align: center;">
            <div style="font-size: 1.1rem; font-weight: 700; color: #059669;"><?= $hm_summary['present'] ?></div>
            <div style="font-size: 0.7rem; color: #059669; font-weight: 600;">มา</div>
        </div>
        <!-- สาย -->
        <div style="background: #fffbeb; border: 1px solid #fef08a; border-radius: 8px; padding: 6px 4px; text-align: center;">
            <div style="font-size: 1.1rem; font-weight: 700; color: #d97706;"><?= $hm_summary['late'] ?></div>
            <div style="font-size: 0.7rem; color: #d97706; font-weight: 600;">สาย</div>
        </div>
        <!-- ลา -->
        <div style="background: #f5f3ff; border: 1px solid #ede9fe; border-radius: 8px; padding: 6px 4px; text-align: center;">
            <div style="font-size: 1.1rem; font-weight: 700; color: #7c3aed;"><?= $hm_summary['leave'] ?></div>
            <div style="font-size: 0.7rem; color: #7c3aed; font-weight: 600;">ลา</div>
        </div>
        <!-- ขาด -->
        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 6px 4px; text-align: center;">
            <div style="font-size: 1.1rem; font-weight: 700; color: #dc2626;"><?= $hm_summary['absent'] ?></div>
            <div style="font-size: 0.7rem; color: #dc2626; font-weight: 600;">ขาด</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Substitution Teaching 7 Days -->
<?php if (count($sub_today) > 0): ?>
<div class="home-section" style="border-left: 3px solid #0ea5e9;">
    <div class="home-section-title">
        <span class="material-symbols-rounded" style="color: #0ea5e9;">campaign</span>
        คาบสอนแทน (7 วันนี้)
    </div>
    <?php 
    $today_dt = new DateTime();
    $today_dt->setTime(0,0,0);
    $thai_months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    foreach ($sub_today as $sub): 
        $sub_date = new DateTime($sub['substitution_date']);
        $sub_date->setTime(0,0,0);
        $diff = $today_dt->diff($sub_date);
        $diff_days = $diff->invert ? -$diff->days : $diff->days;
        
        $date_text = "";
        $date_color = "#0ea5e9";
        if ($diff_days == 0) {
            $date_text = "วันนี้";
            $date_color = "#ef4444";
        } elseif ($diff_days == 1) {
            $date_text = "พรุ่งนี้";
            $date_color = "#f59e0b";
        } else {
            $date_text = "วันที่ " . $sub_date->format('j') . " " . $thai_months[(int)$sub_date->format('n')];
        }
    ?>
        <div class="sub-card" style="border-left: 2px solid <?= $date_color ?>; padding-left: 12px;">
            <div style="font-weight: 600; font-size: 0.9rem; display: flex; justify-content: space-between; align-items: flex-start;">
                <span>📘 <?= htmlspecialchars($sub['subject_name']) ?> — คาบที่ <?= htmlspecialchars($sub['period']) ?></span>
                <span style="color: <?= $date_color ?>; font-size: 0.75rem; background: <?= $date_color ?>20; padding: 2px 8px; border-radius: 12px; font-weight: 700; white-space: nowrap; margin-left: 8px;"><?= $date_text ?></span>
            </div>
            <div style="font-size: 0.8rem; color: #64748b; margin-top: 4px;">
                ห้อง <?= htmlspecialchars($sub['room_name']) ?> · แทนครู <?= htmlspecialchars($sub['original_teacher_name']) ?>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 10px; justify-content: flex-start;">
                <a href="teacher_class.php?sub_id=<?= $sub['id'] ?>" class="btn btn-sm" style="background: #0ea5e9; color: white; padding: 5px 12px; font-size: 0.78rem; border-radius: 8px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                    <span class="material-symbols-rounded" style="font-size: 16px;">edit_note</span> บันทึกการสอน
                </a>
                <form method="POST" onsubmit="return confirm('คุณต้องการปฏิเสธการสอนแทนคาบนี้ใช่หรือไม่?');" style="margin: 0;">
                    <input type="hidden" name="action" value="reject_sub">
                    <input type="hidden" name="sub_id" value="<?= $sub['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; padding: 5px 12px; font-size: 0.78rem; border-radius: 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                        <span class="material-symbols-rounded" style="font-size: 16px;">close</span> ปฏิเสธ
                    </button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Late Students Today -->
<?php if ((in_array('teacher', $user_roles) || in_array('assistant', $user_roles)) && $teacher['advisor_classroom']): ?>
<div class="home-section">
    <div class="home-section-title">
        <span class="material-symbols-rounded" style="color: #dc2626;">schedule</span>
        นร.ที่ปรึกษามาสายวันนี้ 
        <?php if ($daily_late_count > 0): ?>
            <span style="background: #fef2f2; color: #dc2626; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem;"><?= $daily_late_count ?> คน</span>
        <?php endif; ?>
    </div>
    <?php if (count($late_students_today) > 0): ?>
        <?php foreach ($late_students_today as $student): ?>
            <div class="student-item" style="justify-content: space-between;">
                <div class="student-info" style="flex: 1;">
                    <div class="name" style="font-size: 0.95rem;">
                        <span style="color: #64748b; font-size: 0.85rem; font-weight: normal; margin-right: 4px;">รหัส <?= htmlspecialchars($student['student_code']) ?></span>
                        <?= htmlspecialchars($student['full_name']) ?> 
                        <span style="color: #64748b; font-size: 0.85rem; font-weight: normal; margin-left: 4px;">เลขที่ <?= htmlspecialchars($student['student_number']) ?></span>
                    </div>
                </div>
                <span class="student-status status-late">สาย</span>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="text-align: center; color: var(--text-muted); font-size: 0.85rem; padding: 12px 0;">
            <span class="material-symbols-rounded" style="font-size: 32px; display: block; margin-bottom: 4px; opacity: 0.4;">check_circle</span>
            ยังไม่มี นร. มาสายวันนี้
        </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Absent Students Today -->
<?php if ((in_array('teacher', $user_roles) || in_array('assistant', $user_roles)) && $teacher['advisor_classroom'] && count($absent_students_today) > 0): ?>
<div class="home-section">
    <div class="home-section-title">
        <span class="material-symbols-rounded" style="color: #d97706;">person_off</span>
        นร.ที่ปรึกษาขาดเรียนวันนี้
        <span style="background: #fffbeb; color: #d97706; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem;"><?= count($absent_students_today) ?> คน</span>
    </div>
    <?php foreach ($absent_students_today as $student): ?>
        <div class="student-item" style="justify-content: space-between;">
            <div class="student-info" style="flex: 1;">
                <div class="name" style="font-size: 0.95rem;">
                    <span style="color: #64748b; font-size: 0.85rem; font-weight: normal; margin-right: 4px;">รหัส <?= htmlspecialchars($student['student_code']) ?></span>
                    <?= htmlspecialchars($student['full_name']) ?> 
                    <span style="color: #64748b; font-size: 0.85rem; font-weight: normal; margin-left: 4px;">เลขที่ <?= htmlspecialchars($student['student_number']) ?></span>
                </div>
            </div>
            <span class="student-status status-absent">ขาด</span>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- At-Risk Students -->
<?php if (in_array('teacher', $user_roles) && count($at_risk_students) > 0): ?>
<div class="home-section" style="border-left: 3px solid #d97706;">
    <div class="home-section-title">
        <span class="material-symbols-rounded" style="color: #d97706;">warning</span>
        นร.ที่ปรึกษาเสี่ยง มส. (ขาดเกิน 3 ครั้ง)
    </div>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
            <thead>
                <tr style="background: #fffbeb;">
                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid var(--border-color);">เลขที่</th>
                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid var(--border-color);">ชื่อ-สกุล</th>
                    <th style="padding: 8px; text-align: left; border-bottom: 1px solid var(--border-color);">วิชา</th>
                    <th style="padding: 8px; text-align: center; border-bottom: 1px solid var(--border-color);">ขาด</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($at_risk_students as $s): ?>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid var(--border-color);"><?= htmlspecialchars($s['student_number']) ?></td>
                    <td style="padding: 8px; border-bottom: 1px solid var(--border-color);"><?= htmlspecialchars($s['full_name']) ?></td>
                    <td style="padding: 8px; border-bottom: 1px solid var(--border-color);"><?= htmlspecialchars($s['subject_name']) ?></td>
                    <td style="padding: 8px; text-align: center; border-bottom: 1px solid var(--border-color);">
                        <span style="background: #fef2f2; color: #dc2626; padding: 2px 8px; border-radius: 8px; font-weight: 700;"><?= $s['absent_count'] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div style="text-align: center; margin-top: 10px;">
        <a href="teacher_notif_absent.php" style="color: var(--primary-color); font-size: 0.82rem; text-decoration: none; font-weight: 600;">
            ดูทั้งหมด →
        </a>
    </div>
</div>
<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function() {
    const avatarBtn = document.querySelector('.topbar-avatar');
    const overlay = document.getElementById('uploadOverlay');
    const fileInput = document.getElementById('profileFileInput');
    const base64Input = document.getElementById('profilePhotoBase64');
    const previewContainer = document.getElementById('previewContainer');
    const imagePreview = document.getElementById('imagePreview');
    const compressStatus = document.getElementById('compressStatus');
    const submitBtn = document.getElementById('submitUploadBtn');

    if (avatarBtn && overlay) {
        avatarBtn.style.cursor = 'pointer';
        avatarBtn.title = 'กดเพื่อเปลี่ยนรูปโปรไฟล์';
        avatarBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            overlay.classList.add('active');
        });
        overlay.addEventListener('click', function(e) {
            if (e.target === this) overlay.classList.remove('active');
        });
        
        <?php if (isset($_GET['action']) && $_GET['action'] === 'edit_profile'): ?>
        // Auto-open modal if requested via URL
        setTimeout(() => overlay.classList.add('active'), 100);
        // Clean URL to prevent reopening on reload
        window.history.replaceState({}, document.title, window.location.pathname);
        <?php endif; ?>
    }

    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            compressStatus.innerText = '⏳ กำลังประมวลผลภาพให้คมชัดและขนาดเบา...';
            previewContainer.style.display = 'block';
            submitBtn.disabled = true;

            const reader = new FileReader();
            reader.onload = function(event) {
                const img = new Image();
                img.onload = function() {
                    // Create canvas for smart high-quality downscaling
                    const canvas = document.createElement('canvas');
                    const maxDim = 450;
                    let width = img.width;
                    let height = img.height;

                    if (width > maxDim || height > maxDim) {
                        if (width > height) {
                            height = Math.round((height * maxDim) / width);
                            width = maxDim;
                        } else {
                            width = Math.round((width * maxDim) / height);
                            height = maxDim;
                        }
                    }

                    canvas.width = width;
                    canvas.height = height;
                    const ctx = canvas.getContext('2d');
                    ctx.imageSmoothingEnabled = true;
                    ctx.imageSmoothingQuality = 'high';
                    ctx.drawImage(img, 0, 0, width, height);

                    // Compress to lightweight 85% JPEG
                    const compressedDataUrl = canvas.toDataURL('image/jpeg', 0.85);
                    base64Input.value = compressedDataUrl;
                    imagePreview.src = compressedDataUrl;
                    
                    const origSizeKb = Math.round(file.size / 1024);
                    const newSizeKb = Math.round((compressedDataUrl.length * 3 / 4) / 1024);
                    compressStatus.innerText = `✨ ภาพพร้อมบันทึก (ขนาดเหลือเพียง ${newSizeKb} KB จากเดิม ${origSizeKb} KB)`;
                    submitBtn.disabled = false;
                };
                img.src = event.target.result;
            };
            reader.readAsDataURL(file);
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
