<?php
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('admin', $user_roles) && !in_array('executive', $user_roles) && !in_array('discipline', $user_roles)) {
    echo "Access Denied";
    exit();
}

$is_discipline_user = true; // Always true for this page
$page_title = "เช็คมาสาย (กิจการนักเรียน)";
$back_url = "discipline_dashboard.php";

$success = '';
$error = '';
$inspector_id = $_SESSION['user_id'];

date_default_timezone_set('Asia/Bangkok');
$today = date('Y-m-d');
$filter_date = $_GET['filter_date'] ?? $today;
$filter_level = $_GET['filter_level'] ?? '';
$filter_room = $_GET['filter_room'] ?? '';
$today_thai = date('d/m/Y');
$current_time_str = date('H:i');

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

// 1. Handle Delete Late Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_late_record'])) {
    $delete_id = $_POST['record_id'];
    $stmt_del_info = $pdo->prepare("SELECT student_id, record_date FROM behavior_records WHERE id = ? AND (reason LIKE 'มาสาย%' OR reason LIKE '%สาย%')");
    $stmt_del_info->execute([$delete_id]);
    $del_info = $stmt_del_info->fetch();
    
    if ($del_info) {
        $sid = $del_info['student_id'];
        $rdate = $del_info['record_date'];
        
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM behavior_records WHERE id = ?")->execute([$delete_id]);
            $pdo->prepare("UPDATE homeroom_attendance SET status = 'present' WHERE student_id = ? AND attendance_date = ? AND status = 'late'")->execute([$sid, $rdate]);
            
            updateCurrentScore($pdo, $sid, $term_id);
            $pdo->commit();
            $success = "ลบรายการและคืนคะแนนเรียบร้อยแล้ว";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "เกิดข้อผิดพลาดในการลบ: " . $e->getMessage();
        }
    }
}

// 2. Handle Add Late Record (Form or AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['submit_late']) || (isset($_POST['action']) && $_POST['action'] === 'scan'))) {
    $is_ajax = isset($_POST['action']) && $_POST['action'] === 'scan';
    
    if ($is_ajax) {
        header('Content-Type: application/json');
        $scan_data = trim($_POST['scan_data'] ?? '');
        $student_id = null;
        if (preg_match('/[?&]id=(\d+)/', $scan_data, $matches)) {
            $student_id = $matches[1];
        } else if (is_numeric($scan_data)) {
            $stmt = $pdo->prepare("SELECT id FROM students WHERE id = ?");
            $stmt->execute([$scan_data]);
            if ($stmt->fetch()) {
                $student_id = $scan_data;
            } else {
                $stmt = $pdo->prepare("SELECT id FROM students WHERE student_code = ?");
                $stmt->execute([$scan_data]);
                $res = $stmt->fetch();
                if ($res) $student_id = $res['id'];
            }
        }
        
        if (!$student_id) {
            echo json_encode(['success' => false, 'message' => 'QR Code ไม่ถูกต้องหรือไม่พบข้อมูลนักเรียน']);
            exit;
        }
        $student_ids = [$student_id];
    } else {
        $student_ids = $_POST['student_ids'] ?? [];
    }
    
    if ($current_time_str < '07:45' || $current_time_str > '16:00') {
        if ($is_ajax) {
            echo json_encode(['success' => false, 'message' => 'ไม่อยู่ในช่วงเวลาที่สามารถบันทึกมาสายได้ (07:45 - 16:00)']);
            exit;
        }
        $error = "ไม่อยู่ในช่วงเวลาที่สามารถบันทึกมาสายได้ (07:45 - 16:00)";
        $student_ids = []; // Prevent proceeding
    } else {
        if ($current_time_str >= '08:26') {
            $points_deduct = -10;
            $reason_text = 'สายมาก';
        } else {
            $points_deduct = -5;
            $reason_text = 'สาย';
        }
        
        if (empty($student_ids)) {
            if ($is_ajax) {
                echo json_encode(['success' => false, 'message' => 'กรุณาเลือกนักเรียน']);
                exit;
            }
            $error = "กรุณาเลือกนักเรียนอย่างน้อย 1 คน";
        }
    }
    
    if (empty($error) && !empty($student_ids)) {
        $pdo->beginTransaction();
        try {
            $stmt_homeroom = $pdo->prepare("INSERT INTO homeroom_attendance (student_id, attendance_date, status, recorded_by_teacher_id) VALUES (?, ?, 'late', ?) ON DUPLICATE KEY UPDATE status = 'late', recorded_by_teacher_id = ?");
            $stmt_behavior = $pdo->prepare("INSERT INTO behavior_records (student_id, academic_term_id, recorded_by_teacher_id, record_date, points, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'approved')");
            
            $count = 0;
            $processed_names = [];
            foreach ($student_ids as $sid) {
                // Check if already marked late today to avoid double points
                $stmt_check = $pdo->prepare("SELECT id FROM behavior_records WHERE student_id = ? AND record_date = ? AND (reason LIKE 'มาสาย%' OR reason LIKE '%สาย%')");
                $stmt_check->execute([$sid, $today]);
                
                if (!$stmt_check->fetch()) {
                    // Fetch name and photo for response
                    $stmt_st = $pdo->prepare("SELECT s.full_name, s.student_code, s.photo_path, c.room_name, u.id as homeroom_teacher_id FROM students s LEFT JOIN classrooms c ON s.classroom_id = c.id LEFT JOIN users u ON c.room_name = u.advisor_classroom WHERE s.id = ?");
                    $stmt_st->execute([$sid]);
                    $st = $stmt_st->fetch();
                    if ($st) $processed_names[] = $st;

                    // 1. Mark Late in Homeroom
                    $stmt_homeroom->execute([$sid, $today, $inspector_id, $inspector_id]);
                    
                    // 2. Deduct points based on type
                    $stmt_behavior->execute([$sid, $term_id, $inspector_id, $today, $points_deduct, $reason_text]);
                    
                    // 3. Update Current Score
                    updateCurrentScore($pdo, $sid, $term_id);
                    $count++;
                    
                    // 4. Check for Late >= 3 times in this month for notification
                    $month = date('m', strtotime($today));
                    $year = date('Y', strtotime($today));
                    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM homeroom_attendance WHERE student_id = ? AND status = 'late' AND MONTH(attendance_date) = ? AND YEAR(attendance_date) = ?");
                    $stmt_count->execute([$sid, $month, $year]);
                    $late_count = (int)$stmt_count->fetchColumn();
                    
                    if ($late_count >= 4) {
                        if ($st) {
                            $msg = "⚠️ แจ้งเตือน! นักเรียน " . $st['full_name'] . " (รหัส " . $st['student_code'] . ") มาสายสะสมเป็นครั้งที่ " . $late_count . " ของเดือนนี้";
                            
                            $stmt_chk = $pdo->prepare("SELECT id FROM notifications WHERE reference_type = 'late_alert' AND reference_id = ? AND message = ? LIMIT 1");
                            $stmt_chk->execute([$sid, $msg]);
                            
                            if (!$stmt_chk->fetch()) {
                                $insert_notif = $pdo->prepare("INSERT INTO notifications (user_id, message, reference_type, reference_id, link) VALUES (?, ?, 'late_alert', ?, ?)");
                                
                                if ($st['homeroom_teacher_id']) {
                                    $insert_notif->execute([$st['homeroom_teacher_id'], $msg, $sid, 'src/actions/discipline_student_history.php?student_id='.$sid]);
                                }
                                
                                $stmt_disc = $pdo->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE r.role_name = 'discipline'");
                                $discipline_teachers = $stmt_disc ? $stmt_disc->fetchAll(PDO::FETCH_COLUMN) : [];
                                foreach ($discipline_teachers as $dt_id) {
                                    if ($dt_id != $st['homeroom_teacher_id']) {
                                        $insert_notif->execute([$dt_id, $msg, $sid, 'src/actions/discipline_student_history.php?student_id='.$sid]);
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $pdo->commit();
            if ($is_ajax) {
                if ($count > 0) {
                    $stu = $processed_names[0];
                    echo json_encode([
                        'success' => true, 
                        'message' => "บันทึกมาสาย: " . $stu['full_name'],
                        'points' => $points_deduct,
                        'reason' => $reason_text,
                        'student_code' => $stu['student_code'],
                        'full_name' => $stu['full_name'],
                        'room_name' => $stu['room_name'] ?? '-',
                        'photo_path' => $stu['photo_path'] ?? null
                    ]);
                } else {
                    $stmt_st = $pdo->prepare("SELECT s.full_name, s.student_code, s.photo_path, c.room_name FROM students s LEFT JOIN classrooms c ON s.classroom_id = c.id WHERE s.id = ?");
                    $stmt_st->execute([$student_id]);
                    $already_st = $stmt_st->fetch();
                    echo json_encode([
                        'success' => false, 
                        'already_scanned' => true,
                        'message' => "บันทึกมาสายไปแล้วในวันนี้",
                        'student_code' => $already_st['student_code'] ?? '',
                        'full_name' => $already_st['full_name'] ?? '',
                        'room_name' => $already_st['room_name'] ?? '-',
                        'photo_path' => $already_st['photo_path'] ?? null
                    ]);
                }
                exit;
            } else {
                if ($count > 0) {
                    $success = "บันทึกมาสายและหักคะแนน (" . abs($points_deduct) . " แต้ม) ให้นักเรียนจำนวน " . $count . " คน สำเร็จแล้ว";
                } else {
                    $error = "นักเรียนที่เลือกถูกบันทึกว่ามาสายในวันนี้ไปแล้ว";
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            if ($is_ajax) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

include '../../includes/header.php';
// Stats queries
$late_today_count = 0;
$late_normal_count = 0;
$late_extreme_count = 0;
$total_points_deducted = 0;
$late_this_month = 0;

if ($term_id) {
    $stmt_late = $pdo->prepare("SELECT COUNT(*) FROM behavior_records WHERE record_date = ? AND (reason LIKE 'มาสาย%' OR reason LIKE '%สาย%') AND status = 'approved'");
    $stmt_late->execute([$filter_date]);
    $late_today_count = (int)$stmt_late->fetchColumn();

    $stmt_normal = $pdo->prepare("SELECT COUNT(*) FROM behavior_records WHERE record_date = ? AND (reason LIKE 'มาสาย (07.50%' OR reason = 'สาย') AND status = 'approved'");
    $stmt_normal->execute([$filter_date]);
    $late_normal_count = (int)$stmt_normal->fetchColumn();

    $stmt_extreme = $pdo->prepare("SELECT COUNT(*) FROM behavior_records WHERE record_date = ? AND (reason LIKE 'มาสาย (หลัง%' OR reason = 'สายมาก') AND status = 'approved'");
    $stmt_extreme->execute([$filter_date]);
    $late_extreme_count = (int)$stmt_extreme->fetchColumn();

    $stmt_pts = $pdo->prepare("SELECT COALESCE(SUM(ABS(points)), 0) FROM behavior_records WHERE record_date = ? AND (reason LIKE 'มาสาย%' OR reason LIKE '%สาย%') AND status = 'approved'");
    $stmt_pts->execute([$filter_date]);
    $total_points_deducted = (int)$stmt_pts->fetchColumn();

    $month = date('m', strtotime($filter_date));
    $year = date('Y', strtotime($filter_date));
    $stmt_month = $pdo->prepare("SELECT COUNT(*) FROM behavior_records WHERE MONTH(record_date) = ? AND YEAR(record_date) = ? AND (reason LIKE 'มาสาย%' OR reason LIKE '%สาย%') AND status = 'approved'");
    $stmt_month->execute([$month, $year]);
    $late_this_month = (int)$stmt_month->fetchColumn();

    $stmt_recent = $pdo->prepare("
        SELECT br.id, br.record_date, s.student_code, s.full_name, c.room_name, br.points, br.reason, br.created_at
        FROM behavior_records br
        JOIN students s ON br.student_id = s.id
        LEFT JOIN classrooms c ON s.classroom_id = c.id
        WHERE (br.reason LIKE 'มาสาย%' OR br.reason LIKE '%สาย%') AND br.status = 'approved' AND br.record_date = :f_date
        " . ($filter_level ? " AND c.grade_level = :f_level " : "") . "
        " . ($filter_room ? " AND c.room_name = :f_room " : "") . "
        ORDER BY br.created_at DESC
        LIMIT 50
    ");
    
    $params = ['f_date' => $filter_date];
    if ($filter_level) {
        $params['f_level'] = $filter_level;
    }
    if ($filter_room) {
        $params['f_room'] = $filter_room;
    }
    $stmt_recent->execute($params);
    $recent_records = $stmt_recent->fetchAll();
}

$stmt_rooms = $pdo->query("SELECT DISTINCT room_name FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC");
$all_rooms = $stmt_rooms->fetchAll(PDO::FETCH_COLUMN);

if ($current_time_str < '07:45' || $current_time_str > '16:00') {
    $current_deduction = 0;
    $deduction_label = 'นอกเวลาทำการ';
    $deduction_color = '#64748b';
} elseif ($current_time_str >= '08:26') {
    $current_deduction = 10;
    $deduction_label = 'สายมาก';
    $deduction_color = '#dc2626';
} else {
    $current_deduction = 5;
    $deduction_label = 'สาย';
    $deduction_color = '#f59e0b';
}

$stmt_all_students = $pdo->prepare("SELECT id, student_code, full_name FROM students WHERE status = 'active' ORDER BY student_code ASC");
$stmt_all_students->execute();
$all_students = $stmt_all_students->fetchAll();
?>

<!-- Tom Select CSS & JS -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<style>
    /* Minimalist Theme matching teacher_home */
    .late-hero, .deduction-indicator { display: none; } /* Hide old huge elements */
    
    .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px; }
    .stat-card { background: white; border-radius: 12px; padding: 12px 8px; text-align: center; border: 1px solid #fbcfe8; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
    .stat-card .stat-icon { display: none; } /* Remove huge emojis */
    .stat-card .stat-value { font-size: 1.25rem; font-weight: 700; line-height: 1; margin-bottom: 4px; }
    .stat-card .stat-label { font-size: 0.75rem; font-weight: 600; }
    
    .modern-card { background: white; border-radius: 12px; padding: 15px; border: 1px solid #fbcfe8; box-shadow: 0 1px 3px rgba(0,0,0,0.02); margin-bottom: 20px; }
    
    /* Buttons */
    .btn-mobile-full { width: 100%; display: block; border-radius: 8px; font-weight: 600; font-size: 0.95rem; }
    
    @media (max-width: 600px) {
        .header-flex { flex-direction: column; align-items: flex-start !important; gap: 8px; margin-bottom: 15px !important; }
        .filter-flex { flex-direction: column !important; align-items: stretch !important; gap: 8px !important; }
        .filter-flex > div { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .filter-flex > div > select, .filter-flex > div > input { width: 65% !important; flex: none !important; }
    }
    
    /* Recent Table */
    .table-responsive { overflow-x: auto; border-radius: 8px; border: 1px solid #fbcfe8; }
    .recent-table { width: 100%; border-collapse: collapse; min-width: 450px; }
    .recent-table th { background: #fdf2f8; color: #be185d; padding: 10px; text-align: left; font-size: 0.8rem; font-weight: 600; border-bottom: 1px solid #fbcfe8; }
    .recent-table td { padding: 10px; border-bottom: 1px solid #fce7f3; font-size: 0.85rem; color: #1e293b; vertical-align: middle; }
    
    /* TomSelect */
    .ts-control { border-radius: 8px !important; padding: 8px 12px !important; border-color: #cbd5e1 !important; font-size: 0.9rem !important; }
</style>

<div class="card" style="border: none; box-shadow: none; background: transparent; padding: 0;">
    <div class="header-flex" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <div>
            <h2 class="card-title" style="margin: 0; font-size: 1.15rem; color: #9d174d; font-weight: 700;">บันทึกมาสาย (คาบ 0)</h2>
            <div style="font-size: 0.75rem; color: #64748b; margin-top: 4px;">📅 <?= $today_thai ?> • ⏰ <?= $current_time_str ?> น.</div>
        </div>
        <a href="<?= $back_url ?>" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px; font-weight: 600; font-size: 0.8rem; padding: 4px 10px;">⬅️ กลับหน้าหลัก</a>
    </div>

    <?php if($success): ?><div class="badge badge-success mb-3" style="display: block; padding: 12px; border-radius: 12px; font-size: 1rem;"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="badge badge-danger mb-3" style="display: block; padding: 12px; border-radius: 12px; font-size: 1rem;"><?= $error ?></div><?php endif; ?>

    

    <?php if ($is_discipline_user || in_array('admin', $user_roles) || in_array('executive', $user_roles)): ?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">🕒</div>
            <div class="stat-value" id="stat_late_today" style="color: #831843;"><?= $late_today_count ?></div>
            <div class="stat-label" style="color: #64748b;">มาสายวันนี้ (คน)</div>
        </div>
        <div class="stat-card" style="border-color: #e2e8f0;">
            <div class="stat-icon">⚠️</div>
            <div class="stat-value" id="stat_late_normal" style="color: #d97706;"><?= $late_normal_count ?></div>
            <div class="stat-label" style="color: #d97706;">สายปกติ (-5)</div>
        </div>
        <div class="stat-card" style="border-color: #e2e8f0;">
            <div class="stat-icon">🚨</div>
            <div class="stat-value" id="stat_late_extreme" style="color: #dc2626;"><?= $late_extreme_count ?></div>
            <div class="stat-label" style="color: #dc2626;">สายมาก (-10)</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Scanner Section -->
    <div class="modern-card" style="text-align: center;">
        <div style="position: relative; width: 100%; max-width: 500px; margin: 0 auto;">
            <div id="reader" style="width: 100%; display: none; border-radius: 12px; overflow: hidden; border: 2px solid #fbcfe8; margin-bottom: 15px;"></div>
            
            <!-- Camera HUD Popup Overlay (Tap anywhere to dismiss or auto 1.5s) -->
            <div id="scanPopupOverlay" onclick="dismissScanPopup()" style="display: none; position: absolute; top: 0; left: 0; right: 0; bottom: 15px; background: rgba(0,0,0,0.45); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); border-radius: 12px; z-index: 50; justify-content: center; align-items: center; padding: 12px; cursor: pointer;">
                <div id="scanPopupCard" style="background: white; border-radius: 16px; padding: 16px; width: 100%; max-width: 380px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); text-align: left;">
                </div>
            </div>
        </div>
        
        <?php $is_out_of_hours = ($current_time_str < '07:45' || $current_time_str > '16:00'); ?>
        <button id="btnStartScan" type="button" class="btn btn-primary" <?= $is_out_of_hours ? 'disabled' : '' ?> style="padding: 10px; <?= $is_out_of_hours ? 'background: #cbd5e1; border: none;' : '' ?> width: 33.33%; min-width: 150px; margin: 0 auto; display: block;">📷 เปิดกล้องสแกน</button>
        <button id="btnStopScan" type="button" class="btn btn-outline-secondary" style="display: none; padding: 10px; width: 33.33%; min-width: 150px; margin: 0 auto;">ปิดกล้อง</button>
        
        <!-- Status Notification -->
        <div id="scanResult" style="display:none; padding: 15px; border-radius: 12px; margin-top: 15px; font-weight: 600; font-size: 1.1rem; text-align: center;"></div>
    </div>

    <form method="POST" class="modern-card">
        <div style="margin-bottom: 20px;">
            <label style="font-weight: 600; margin-bottom: 8px; display: block; color: #831843; font-size: 0.9rem;">หรือ พิมพ์รหัส/ชื่อ (เลือกได้หลายคน)</label>
            <select id="student-select" name="student_ids[]" multiple placeholder="<?= ($current_time_str < '07:45' || $current_time_str > '16:00') ? 'นอกเวลาทำการ' : 'ค้นหานักเรียนที่มาสาย...' ?>" <?= ($current_time_str < '07:45' || $current_time_str > '16:00') ? 'disabled' : 'required' ?>>
                <option value="">พิมพ์รหัส 5 หลัก หรือชื่อ...</option>
                <?php foreach($all_students as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= $s['student_code'] ?> - <?= htmlspecialchars($s['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="text-align: right;">
            <button type="submit" name="submit_late" class="btn btn-danger" <?= ($current_time_str < '07:45' || $current_time_str > '16:00') ? 'disabled' : '' ?> style="padding: 6px 20px; font-size: 0.95rem; border-radius: 8px; margin-top: 5px; <?= ($current_time_str < '07:45' || $current_time_str > '16:00') ? 'background: #cbd5e1; border: none;' : '' ?>">บันทึกมาสาย</button>
        </div>
    </form>

    <!-- Recent Late Table -->
    <div class="modern-card" style="padding: 20px;">
        <div class="header-flex" style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #9d174d; font-size: 1rem; font-weight: 700;">📋 บันทึกมาสายล่าสุด <span style="font-size: 0.85rem; color: #64748b; font-weight: normal;">(<?= $filter_date == $today ? 'วันนี้' : date('d/m/Y', strtotime($filter_date)) ?>)</span></h3>
            <?php if ($is_discipline_user || in_array('admin', $user_roles) || in_array('executive', $user_roles)): ?>
            <form method="GET" class="filter-flex" style="display: flex; gap: 10px; align-items: center; width: 100%; flex-wrap: wrap; padding-bottom: 5px;">
                <div style="display: flex; align-items: center; gap: 6px; flex: 1; min-width: 140px;">
                    <label for="filter_level" style="font-size: 0.85rem; color: #be185d; font-weight: 600; white-space: nowrap;">ระดับชั้น:</label>
                    <select name="filter_level" id="filter_level" class="form-control" style="padding: 6px 10px; border-radius: 8px; font-size: 0.85rem; width: 100%;" onchange="this.form.submit()">
                        <option value="">ทั้งหมด</option>
                        <option value="ม.1" <?= $filter_level == 'ม.1' ? 'selected' : '' ?>>ม.1</option>
                        <option value="ม.2" <?= $filter_level == 'ม.2' ? 'selected' : '' ?>>ม.2</option>
                        <option value="ม.3" <?= $filter_level == 'ม.3' ? 'selected' : '' ?>>ม.3</option>
                        <option value="ม.4" <?= $filter_level == 'ม.4' ? 'selected' : '' ?>>ม.4</option>
                        <option value="ม.5" <?= $filter_level == 'ม.5' ? 'selected' : '' ?>>ม.5</option>
                        <option value="ม.6" <?= $filter_level == 'ม.6' ? 'selected' : '' ?>>ม.6</option>
                    </select>
                </div>
                
                <div style="display: flex; align-items: center; gap: 6px; flex: 1; min-width: 140px;">
                    <label for="filter_room" style="font-size: 0.85rem; color: #be185d; font-weight: 600; white-space: nowrap;">ห้อง:</label>
                    <select name="filter_room" id="filter_room" class="form-control" style="padding: 6px 10px; border-radius: 8px; font-size: 0.85rem; width: 100%;" onchange="this.form.submit()">
                        <option value="">ทุกห้อง</option>
                        <?php foreach($all_rooms as $rm): ?>
                            <option value="<?= htmlspecialchars($rm) ?>" <?= $filter_room === $rm ? 'selected' : '' ?>><?= htmlspecialchars($rm) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display: flex; align-items: center; gap: 6px; flex: 1; min-width: 160px;">
                    <label for="filter_date" style="font-size: 0.85rem; color: #be185d; font-weight: 600; white-space: nowrap;">วันที่:</label>
                    <input type="date" id="filter_date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>" class="form-control" style="padding: 6px 10px; border-radius: 8px; font-size: 0.85rem; width: 100%;" onchange="this.form.submit()">
                </div>
                
                <a href="print_inspector_late.php?date=<?= $filter_date ?>&level=<?= urlencode($filter_level) ?>&room=<?= urlencode($filter_room) ?>" target="_blank" class="btn btn-sm" style="background: #e11d48; color: white; border: none; border-radius: 12px; padding: 8px 16px; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 6px; flex-shrink: 0; box-shadow: 0 4px 10px rgba(225,29,72,0.2);">
                    🖨️ <span class="d-none d-sm-inline">ส่งออก PDF</span>
                </a>
            </form>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="recent-table">
                <thead>
                    <tr>
                        <th>เวลา</th>
                        <th>รหัส</th>
                        <th>ชื่อ-สกุล</th>
                        <th>ห้อง</th>
                        <th>คะแนน</th>
                        <th>ประเภท</th>
                        <th style="text-align: right;">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($recent_records)): ?>
                        <?php foreach($recent_records as $r): ?>
                        <tr>
                            <td><?= date('H:i', strtotime($r['created_at'])) ?> น.</td>
                            <td><?= htmlspecialchars($r['student_code']) ?></td>
                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                            <td><?= htmlspecialchars($r['room_name']) ?></td>
                            <td><span style="background: #fef3c7; color: #d97706; padding: 2px 8px; border-radius: 10px; font-weight: 700; font-size: 0.75rem;"><?= $r['points'] ?> แต้ม</span></td>
                            <?php 
                                $display_reason = $r['reason'];
                                if (strpos($display_reason, 'มาสาย (07.50') !== false) {
                                    $display_reason = 'สาย';
                                } elseif (strpos($display_reason, 'มาสาย (หลัง') !== false) {
                                    $display_reason = 'สายมาก';
                                }
                            ?>
                            <td><?= htmlspecialchars($display_reason) ?></td>
                            <td style="text-align: right;">
                                <?php if ($is_discipline_user || in_array('admin', $user_roles) || in_array('executive', $user_roles)): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('ยืนยันที่จะลบรายการนี้ และคืนคะแนนให้นักเรียน?');">
                                    <input type="hidden" name="record_id" value="<?= $r['id'] ?>">
                                    <button type="submit" name="delete_late_record" class="btn btn-sm btn-outline" style="color: #e11d48; border-color: #fecaca; padding: 4px 10px; font-size: 0.75rem; border-radius: 20px; font-weight: 600; background: #fff1f2;">
                                        🗑️ คืนคะแนน
                                    </button>
                                </form>
                                <?php else: ?>
                                <span style="color: #9ca3af; font-size: 0.8rem;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #9d174d; padding: 20px;">ยังไม่มีข้อมูลการมาสายใน <?= $filter_date == $today ? 'วันนี้' : 'วันที่เลือก' ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script src="https://unpkg.com/html5-qrcode"></script>
<script>
    new TomSelect("#student-select", {
        plugins: ['remove_button', 'clear_button'],
        maxItems: null,
        valueField: 'value',
        labelField: 'text',
        searchField: 'text',
        onItemAdd: function() {
            this.setTextboxValue('');
            this.refreshOptions(false);
        }
    });

    let html5QrcodeScanner = null;
    const btnStartScan = document.getElementById('btnStartScan');
    const btnStopScan = document.getElementById('btnStopScan');
    const readerDiv = document.getElementById('reader');
    const scanResult = document.getElementById('scanResult');
    const scanPopupOverlay = document.getElementById('scanPopupOverlay');
    const scanPopupCard = document.getElementById('scanPopupCard');
    
    let isProcessing = false;
    let popupTimer = null;

    function dismissScanPopup() {
        if (popupTimer) {
            clearTimeout(popupTimer);
            popupTimer = null;
        }
        if (scanPopupOverlay) {
            scanPopupOverlay.style.display = 'none';
        }
        isProcessing = false;
    }

    function processScan(decodedText) {
        if (isProcessing) return;
        isProcessing = true;
        
        scanResult.style.display = 'block';
        scanResult.style.background = '#f1f5f9';
        scanResult.style.color = '#334155';
        scanResult.style.border = '1px solid #cbd5e1';
        scanResult.innerHTML = '<div style="font-weight: 600;">กำลังตรวจสอบข้อมูล...</div>';

        const formData = new FormData();
        formData.append('action', 'scan');
        formData.append('scan_data', decodedText);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            let photoHtml = '';
            if (data.photo_path) {
                photoHtml = `<img src="../../${data.photo_path}" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.outerHTML='<div style=\\\'font-size: 2.2rem; display:flex; align-items:center; justify-content:center; width:100%; height:100%;\\\'>👤</div>'">`;
            } else {
                photoHtml = `<div style="font-size: 2.2rem; display:flex; align-items:center; justify-content:center; width:100%; height:100%; color: #94a3b8;">👤</div>`;
            }

            if (data.success) {
                const ptsColor = data.points <= -10 ? '#dc2626' : '#d97706';

                const cardHtml = `
                    <div style="display: flex; align-items: center; gap: 14px; text-align: left;">
                        <div style="flex-shrink: 0; width: 72px; height: 72px; border-radius: 12px; overflow: hidden; border: 2.5px solid #22c55e; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: center;">
                            ${photoHtml}
                        </div>
                        <div style="flex-grow: 1; min-width: 0;">
                            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">
                                <span style="background: #22c55e; color: white; padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700;">✅ บันทึกมาสาย</span>
                                <span style="color: #64748b; font-size: 0.8rem; font-weight: 600;">ห้อง ${data.room_name || '-'}</span>
                            </div>
                            <div style="font-size: 1.15rem; font-weight: 800; color: #9d174d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px;">
                                ${data.full_name}
                            </div>
                            <div style="font-size: 0.82rem; color: #be185d; display: flex; gap: 10px; flex-wrap: wrap;">
                                <span>🆔 <strong>${data.student_code}</strong></span>
                                <span style="font-weight: 700; color: ${ptsColor};">⚠️ ${data.reason} (${data.points} แต้ม)</span>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #f1f5f9; text-align: center; font-size: 0.75rem; color: #94a3b8;">
                        👆 แตะตรงไหนก็ได้เพื่อสแกนคนต่อไป (ปิดเองใน 1.5 วิ)
                    </div>
                `;

                if (scanPopupOverlay && scanPopupCard && readerDiv.style.display !== 'none') {
                    scanPopupCard.innerHTML = cardHtml;
                    scanPopupOverlay.style.display = 'flex';
                }

                scanResult.style.background = '#f0fdf4';
                scanResult.style.border = '2px solid #86efac';
                scanResult.style.color = '#1e293b';
                scanResult.innerHTML = cardHtml;
                
                playBeep(800, 100);
                if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
                
                // Update stats if they exist
                let todayEl = document.getElementById('stat_late_today');
                let normalEl = document.getElementById('stat_late_normal');
                let extremeEl = document.getElementById('stat_late_extreme');
                let ptsEl = document.getElementById('stat_total_pts');
                
                if (todayEl) todayEl.innerText = parseInt(todayEl.innerText) + 1;
                if (ptsEl) ptsEl.innerText = parseInt(ptsEl.innerText) + Math.abs(data.points);
                
                if (data.reason === 'สาย' && normalEl) {
                    normalEl.innerText = parseInt(normalEl.innerText) + 1;
                } else if (data.reason === 'สายมาก' && extremeEl) {
                    extremeEl.innerText = parseInt(extremeEl.innerText) + 1;
                }

                // Add to table
                const tbody = document.querySelector('.recent-table tbody');
                if (tbody) {
                    if (tbody.children.length === 1 && tbody.children[0].innerText.includes('ยังไม่มีข้อมูล')) {
                        tbody.innerHTML = '';
                    }

                    const now = new Date();
                    const timeStr = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');
                    
                    const newRow = tbody.insertRow(0);
                    
                    newRow.innerHTML = `
                        <td>${timeStr} น.</td>
                        <td>${data.student_code}</td>
                        <td>${data.full_name}</td>
                        <td>${data.room_name}</td>
                        <td><span style="background: #fef3c7; color: #d97706; padding: 2px 8px; border-radius: 10px; font-weight: 700; font-size: 0.75rem;">${data.points} แต้ม</span></td>
                        <td>${data.reason}</td>
                        <td style="text-align: right;"><span style="color: #9ca3af; font-size: 0.8rem;">(รีเฟรชเพื่อจัดการ)</span></td>
                    `;
                    newRow.style.backgroundColor = '#fef3c7';
                    setTimeout(() => { newRow.style.backgroundColor = ''; }, 2000);
                }
                
                if (popupTimer) clearTimeout(popupTimer);
                popupTimer = setTimeout(() => {
                    dismissScanPopup();
                }, 1600);
                
            } else if (data.already_scanned) {
                const cardHtml = `
                    <div style="display: flex; align-items: center; gap: 14px; text-align: left;">
                        <div style="flex-shrink: 0; width: 72px; height: 72px; border-radius: 12px; overflow: hidden; border: 2.5px solid #f59e0b; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: center;">
                            ${photoHtml}
                        </div>
                        <div style="flex-grow: 1; min-width: 0;">
                            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">
                                <span style="background: #f59e0b; color: white; padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700;">⚠️ สแกนซ้ำ</span>
                                <span style="color: #64748b; font-size: 0.8rem; font-weight: 600;">ห้อง ${data.room_name || '-'}</span>
                            </div>
                            <div style="font-size: 1.15rem; font-weight: 800; color: #9d174d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px;">
                                ${data.full_name}
                            </div>
                            <div style="font-size: 0.82rem; color: #b45309; font-weight: 600;">
                                บันทึกมาสายไปแล้วในวันนี้ (ไม่หักคะแนนซ้ำ)
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 10px; padding-top: 8px; border-top: 1px solid #f1f5f9; text-align: center; font-size: 0.75rem; color: #94a3b8;">
                        👆 แตะตรงไหนก็ได้เพื่อสแกนคนต่อไป (ปิดเองใน 1.5 วิ)
                    </div>
                `;

                if (scanPopupOverlay && scanPopupCard && readerDiv.style.display !== 'none') {
                    scanPopupCard.innerHTML = cardHtml;
                    scanPopupOverlay.style.display = 'flex';
                }

                scanResult.style.background = '#fffbeb';
                scanResult.style.border = '2px solid #fde68a';
                scanResult.style.color = '#1e293b';
                scanResult.innerHTML = cardHtml;
                
                playBeep(400, 200);
                if (popupTimer) clearTimeout(popupTimer);
                popupTimer = setTimeout(() => {
                    dismissScanPopup();
                }, 1600);
            } else {
                const cardHtml = `
                    <div style="text-align: center; padding: 10px;">
                        <div style="font-size: 2rem; margin-bottom: 5px;">❌</div>
                        <div style="font-weight: 800; color: #991b1b; font-size: 1.1rem;">${data.message}</div>
                    </div>
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #fee2e2; text-align: center; font-size: 0.75rem; color: #94a3b8;">
                        👆 แตะตรงไหนก็ได้เพื่อปิด
                    </div>
                `;

                if (scanPopupOverlay && scanPopupCard && readerDiv.style.display !== 'none') {
                    scanPopupCard.innerHTML = cardHtml;
                    scanPopupOverlay.style.display = 'flex';
                }

                scanResult.style.background = '#fee2e2';
                scanResult.style.color = '#991b1b';
                scanResult.style.border = '2px solid #fecaca';
                scanResult.innerHTML = cardHtml;
                
                playBeep(200, 300);
                if (navigator.vibrate) navigator.vibrate(300);
                
                if (popupTimer) clearTimeout(popupTimer);
                popupTimer = setTimeout(() => {
                    dismissScanPopup();
                }, 2000);
            }
        })
        .catch(err => {
            scanResult.style.background = '#fee2e2';
            scanResult.style.color = '#991b1b';
            scanResult.innerText = 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์';
            if (popupTimer) clearTimeout(popupTimer);
            popupTimer = setTimeout(() => {
                dismissScanPopup();
            }, 2000);
        });
    }

    

    btnStartScan.addEventListener('click', () => {
        readerDiv.style.display = 'block';
        btnStartScan.style.display = 'none';
        btnStopScan.style.display = 'block';
        
        html5QrcodeScanner = new Html5Qrcode("reader");
        html5QrcodeScanner.start(
            { facingMode: "environment" }, 
            { fps: 10, qrbox: { width: 250, height: 250 } },
            (decodedText, decodedResult) => {
                processScan(decodedText);
            },
            (errorMessage) => {}
        ).catch(err => {
            alert('ไม่สามารถเปิดกล้องได้: ' + err);
            btnStopScan.click();
        });
    });

    btnStopScan.addEventListener('click', () => {
        dismissScanPopup();
        if (html5QrcodeScanner) {
            html5QrcodeScanner.stop().then(() => {
                readerDiv.style.display = 'none';
                btnStartScan.style.display = 'block';
                btnStopScan.style.display = 'none';
            });
        }
    });

    function playBeep(freq, duration) {
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = freq;
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            oscillator.start();
            setTimeout(function(){ oscillator.stop(); }, duration);
        } catch(e) {}
    }
</script>

<?php include '../../includes/footer.php'; ?>
