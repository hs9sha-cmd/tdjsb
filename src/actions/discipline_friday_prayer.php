<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

$page_title = "สแกนเข้า-ออก ละหมาดวันศุกร์";
$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');
$filter_date = $_GET['filter_date'] ?? $today;
$now = date('H:i:s');
$is_friday = (date('w') == 5); 

function thai_date_full($date_str) {
    if (!$date_str) return '';
    $thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $t = strtotime($date_str);
    $day = date('j', $t);
    $month = $thai_months[(int)date('n', $t)];
    $year = date('Y', $t) + 543;
    return "$day $month $year";
} 
// time constraint
$start_time = '11:30:00';
$end_time = '16:00:00';

$is_time_valid = ($now >= $start_time && $now <= $end_time);

// Override for testing purposes (Remove or comment out in production)
if (isset($_GET['test_mode']) && $_GET['test_mode'] == '1') {
    $is_friday = true; 
    $is_time_valid = true;
    
    // Clear logs for testing
    if (isset($_GET['clear_logs']) && $_GET['clear_logs'] == '1') {
        $pdo->query("DELETE FROM friday_prayers_log WHERE log_date = '$today'");
        header("Location: discipline_friday_prayer.php?test_mode=1");
        exit;
    }
}

// Handle AJAX Scan Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scan') {
    header('Content-Type: application/json');
    $scan_data = trim($_POST['scan_data'] ?? ''); // usually a URL or student_id
    
    if (!$is_friday || !$is_time_valid) {
        echo json_encode(['success' => false, 'message' => 'นอกเวลาที่อนุญาตให้สแกน (เฉพาะวันศุกร์ 11:30 - 16:00 น.)']);
        exit;
    }

    // Extract student ID. QR Code is: https://.../student_profile.php?id=123
    $student_id = null;
    if (preg_match('/[?&]id=(\d+)/', $scan_data, $matches)) {
        $student_id = $matches[1];
    } else if (is_numeric($scan_data)) {
        // Fallback if they scanned a barcode containing only ID or student_code
        // Try id first
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

    // Verify student is Male and Islam
    $stmt = $pdo->prepare("
        SELECT s.full_name, s.gender, s.religion, s.student_code, s.photo_path, c.room_name 
        FROM students s 
        LEFT JOIN classrooms c ON s.classroom_id = c.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลนักเรียน']);
        exit;
    }
    
    if ($student['gender'] !== 'Male' || $student['religion'] !== 'Islam') {
        echo json_encode(['success' => false, 'message' => "ไม่อนุญาต: ข้อมูลไม่ตรงตามเงื่อนไข (พบข้อมูล: เพศ ".($student['gender']??'').", ศาสนา ".($student['religion']??'').")"]);
        exit;
    }

    // Check if already logged today
    $stmt = $pdo->prepare("SELECT * FROM friday_prayers_log WHERE student_id = ? AND log_date = ?");
    $stmt->execute([$student_id, $today]);
    $log = $stmt->fetch();

    if (!$log) {
        // Wants to scan out
        if ($now > '12:50:00' && (!isset($_GET['test_mode']) || $_GET['test_mode'] != '1')) {
            echo json_encode(['success' => false, 'message' => "หมดเวลาสแกนออกแล้ว (อนุญาตสแกนออกเวลา 11:30 - 12:50 น.)"]);
            exit;
        }

        // First scan = time_out (leaving school)
        $stmt = $pdo->prepare("INSERT INTO friday_prayers_log (student_id, log_date, time_out, status, created_by) VALUES (?, ?, ?, 'out', ?)");
        $stmt->execute([$student_id, $today, $now, $user_id]);
        echo json_encode([
            'success' => true, 
            'action' => 'out', 
            'message' => "บันทึกเวลาออกละหมาด: " . $student['full_name'],
            'time_out' => substr($now, 0, 5),
            'time_in' => '-',
            'student_code' => $student['student_code'],
            'full_name' => $student['full_name'],
            'room_name' => $student['room_name'] ?? 'ไม่มีห้อง',
            'photo_path' => $student['photo_path'] ?? null
        ]);
        exit;
    } else {
        if ($log['status'] === 'out') {
            // Wants to scan in
            if ($now < '12:50:00' && (!isset($_GET['test_mode']) || $_GET['test_mode'] != '1')) {
                echo json_encode(['success' => false, 'message' => "ยังไม่ถึงเวลาสแกนกลับ (เริ่ม 12:50 น.) หากเผลอสแกนซ้ำระบบจะไม่บันทึก"]);
                exit;
            }

            // Second scan = time_in (returning)
            $stmt = $pdo->prepare("UPDATE friday_prayers_log SET time_in = ?, status = 'returned' WHERE id = ?");
            $stmt->execute([$now, $log['id']]);
            echo json_encode([
                'success' => true, 
                'action' => 'in', 
                'message' => "บันทึกเวลากลับเข้าโรงเรียน: " . $student['full_name'],
                'time_out' => substr($log['time_out'], 0, 5),
                'time_in' => substr($now, 0, 5),
                'student_code' => $student['student_code'],
                'full_name' => $student['full_name'],
                'room_name' => $student['room_name'] ?? 'ไม่มีห้อง',
                'photo_path' => $student['photo_path'] ?? null
            ]);
            exit;
        } else {
            echo json_encode([
                'success' => false, 
                'already_scanned' => true,
                'message' => $student['full_name'] . " ได้บันทึกการกลับเข้ามาแล้ว",
                'student_code' => $student['student_code'],
                'full_name' => $student['full_name'],
                'room_name' => $student['room_name'] ?? 'ไม่มีห้อง',
                'photo_path' => $student['photo_path'] ?? null
            ]);
            exit;
        }
    }
}

// Fetch filtered logs
$stmt = $pdo->prepare("
    SELECT f.*, s.student_code, s.full_name, c.room_name 
    FROM friday_prayers_log f
    JOIN students s ON f.student_id = s.id
    LEFT JOIN classrooms c ON s.classroom_id = c.id
    WHERE f.log_date = ?
    ORDER BY f.time_out DESC
");
$stmt->execute([$filter_date]);
$logs = $stmt->fetchAll();

$total_out = 0;
$total_returned = 0;
foreach ($logs as $l) {
    if ($l['status'] == 'out') $total_out++;
    if ($l['status'] == 'returned') $total_returned++;
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_prayer'])) {
    $student_ids = $_POST['student_ids'] ?? [];
    
    if (!$is_friday || !$is_time_valid) {
        $error = "นอกเวลาที่อนุญาตให้สแกน (เฉพาะวันศุกร์ 11:30 - 16:00 น.)";
    } else if (empty($student_ids)) {
        $error = "กรุณาเลือกนักเรียนอย่างน้อย 1 คน";
    } else {
        $pdo->beginTransaction();
        try {
            $count_out = 0;
            $count_in = 0;
            
            foreach ($student_ids as $sid) {
                // Verify student is Male and Islam
                $stmt = $pdo->prepare("SELECT full_name, gender, religion FROM students WHERE id = ?");
                $stmt->execute([$sid]);
                $st = $stmt->fetch();
                
                if ($st && $st['gender'] === 'Male' && $st['religion'] === 'Islam') {
                    $stmt_log = $pdo->prepare("SELECT * FROM friday_prayers_log WHERE student_id = ? AND log_date = ?");
                    $stmt_log->execute([$sid, $today]);
                    $log = $stmt_log->fetch();
                    
                    if (!$log) {
                        if ($now > '12:50:00' && (!isset($_GET['test_mode']) || $_GET['test_mode'] != '1')) {
                            // Skip out
                            continue;
                        }
                        $stmt_ins = $pdo->prepare("INSERT INTO friday_prayers_log (student_id, log_date, time_out, status, created_by) VALUES (?, ?, ?, 'out', ?)");
                        $stmt_ins->execute([$sid, $today, $now, $user_id]);
                        $count_out++;
                    } else if ($log['status'] === 'out') {
                        if ($now < '12:50:00' && (!isset($_GET['test_mode']) || $_GET['test_mode'] != '1')) {
                            // Skip returned
                            continue;
                        }
                        $stmt_upd = $pdo->prepare("UPDATE friday_prayers_log SET time_in = ?, status = 'returned' WHERE id = ?");
                        $stmt_upd->execute([$now, $log['id']]);
                        $count_in++;
                    }
                }
            }
            $pdo->commit();
            
            if ($count_out > 0 || $count_in > 0) {
                $success = "บันทึกข้อมูลเรียบร้อยแล้ว (ออกไปละหมาด: $count_out คน, กลับมาแล้ว: $count_in คน)";
            } else {
                $error = "ไม่มีการบันทึก (อาจสแกนกลับไปแล้ว, ไม่ใช่นักเรียนชายมุสลิม หรือสแกนผิดช่วงเวลา: ออก 11:30-12:50 / กลับ 12:50-16:00)";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
        }
    }
}

// Fetch all eligible students for TomSelect
$stmt_all_students = $pdo->prepare("SELECT id, student_code, full_name FROM students WHERE status = 'active' AND gender = 'Male' AND religion = 'Islam' ORDER BY student_code ASC");
$stmt_all_students->execute();
$all_students = $stmt_all_students->fetchAll();

include '../../includes/header.php';
?>

<style>
    /* Minimalist Theme matching teacher_home (Green Pastel) */
    body { background-color: #f0fdf4; }
    
    .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 20px; }
    .stat-card { background: white; border-radius: 12px; padding: 12px 8px; text-align: center; border: 1px solid #bbf7d0; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
    .stat-card .stat-icon { display: none; }
    .stat-card .stat-value { font-size: 1.25rem; font-weight: 700; line-height: 1; margin-bottom: 4px; }
    .stat-card .stat-label { font-size: 0.75rem; font-weight: 600; }
    
    .modern-card { background: white; border-radius: 12px; padding: 15px; border: 1px solid #bbf7d0; box-shadow: 0 1px 3px rgba(0,0,0,0.02); margin-bottom: 20px; }
    
    /* Buttons */
    .btn-mobile-full { width: 100%; display: block; border-radius: 8px; font-weight: 600; font-size: 0.95rem; }
    .btn-primary-custom { background: #16a34a; color: white; border: none; }
    .btn-danger-custom { background: #16a34a; color: white; border: none; } /* Using green for save */
    
    @media (max-width: 600px) {
        .header-flex { flex-direction: column; align-items: flex-start !important; gap: 8px; margin-bottom: 15px !important; }
        .filter-flex { flex-direction: column !important; align-items: stretch !important; gap: 8px !important; }
        .filter-flex > div { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .filter-flex > div > select, .filter-flex > div > input { width: 65% !important; flex: none !important; }
    }
    
    /* Recent Table */
    .table-responsive { overflow-x: auto; border-radius: 8px; border: 1px solid #bbf7d0; }
    .recent-table { width: 100%; border-collapse: collapse; min-width: 450px; }
    .recent-table th { background: #f0fdf4; color: #15803d; padding: 10px; text-align: left; font-size: 0.8rem; font-weight: 600; border-bottom: 1px solid #bbf7d0; }
    .recent-table td { padding: 10px; border-bottom: 1px solid #dcfce7; font-size: 0.85rem; color: #166534; vertical-align: middle; }
    
    /* TomSelect */
    .ts-control { border-radius: 8px !important; padding: 8px 12px !important; border-color: #bbf7d0 !important; font-size: 0.9rem !important; }
</style>

<div style="max-width: 600px; margin: 0 auto;">
    <div class="header-card" style="background: #fff; border-radius: 12px; padding: 15px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #bbf7d0;">
        <div>
            <h2 class="card-title" style="margin: 0; font-size: 1.15rem; color: #14532d; font-weight: 700;">สแกนละหมาดวันศุกร์</h2>
            <div style="font-size: 0.75rem; color: #16a34a; margin-top: 4px;">📅 <?= thai_date_full($today) ?> • ⏰ <span id="clock_time"><?= $now ?></span> น.</div>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" style="border-radius: 8px; font-weight: 600; font-size: 0.8rem; padding: 4px 10px;" onclick="history.back()">⬅️ กลับหน้าหลัก</button>
    </div>
        
        <?php if (isset($_GET['test_mode']) && $_GET['test_mode'] == '1'): ?>
            <div class="badge badge-warning" style="display:block; padding:15px; border-radius: 12px; font-size:1rem; text-align:center; margin-bottom:20px; background-color: #fef08a; color: #854d0e; border: 1px solid #fde047;">
                ⚠️ <b>โหมดทดสอบ (Test Mode) เปิดใช้งานอยู่</b><br>ระบบจะข้ามการเช็ควันศุกร์และเวลา เพื่อให้ทดสอบสแกนได้
                <div style="margin-top: 10px;">
                    <a href="?test_mode=1&clear_logs=1" class="btn btn-sm btn-outline" style="background: white; color: #dc2626; border: 1px solid #dc2626; font-size: 0.9rem; border-radius: 20px; padding: 6px 16px;" onclick="return confirm('ยืนยันการล้างข้อมูลการสแกนของวันนี้ทั้งหมดเพื่อเริ่มทดสอบใหม่?');">
                        🗑️ ล้างข้อมูลการสแกนวันนี้ (เพื่อเริ่มเทสใหม่)
                    </a>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!$is_friday): ?>
            <div class="badge badge-warning" style="display:block; padding:15px; border-radius: 12px; font-size:1rem; text-align:left; margin-bottom:20px; border: 1px solid #fde047; background: #fef9c3; color: #854d0e;">
                🚫 วันนี้ไม่ใช่วันศุกร์ ระบบปิดรับการสแกน
            </div>
        <?php elseif (!$is_time_valid): ?>
            <div class="badge badge-warning" style="display:block; padding:15px; border-radius: 12px; font-size:1rem; text-align:left; margin-bottom:20px; border: 1px solid #fde047; background: #fef9c3; color: #854d0e;">
                🚫 นอกเวลาสแกน (สามารถสแกนได้เฉพาะเวลา 11:30 - 16:00 น. ของวันศุกร์เท่านั้น)
            </div>
        <?php endif; ?>

        <?php if($success): ?><div class="badge badge-success mb-3" style="display: block; padding: 12px; border-radius: 12px; font-size: 1rem;"><?= $success ?></div><?php endif; ?>
        <?php if($error): ?><div class="badge badge-danger mb-3" style="display: block; padding: 12px; border-radius: 12px; font-size: 1rem;"><?= $error ?></div><?php endif; ?>

        

        <!-- Scanner Section -->
        <div class="modern-card" style="text-align: center;">
            <div style="position: relative; width: 100%; max-width: 500px; margin: 0 auto;">
                <div id="reader" style="width: 100%; display: none; border-radius: 12px; overflow: hidden; border: 2px solid #bbf7d0; margin-bottom: 15px;"></div>
                
                <!-- Camera HUD Popup Overlay -->
                <div id="scanPopupOverlay" onclick="dismissScanPopup()" style="display: none; position: absolute; top: 0; left: 0; right: 0; bottom: 15px; background: rgba(0,0,0,0.45); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); border-radius: 12px; z-index: 50; justify-content: center; align-items: center; padding: 12px; cursor: pointer;">
                    <div id="scanPopupCard" style="background: white; border-radius: 16px; padding: 16px; width: 100%; max-width: 380px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); text-align: left;">
                    </div>
                </div>
            </div>
            
            <button id="btnStartScan" type="button" class="btn btn-primary-custom" <?= (!$is_time_valid && !isset($_GET["test_mode"])) ? 'disabled' : '' ?> style="padding: 10px; width: 33.33%; min-width: 150px; margin: 0 auto; display: block; <?= (!$is_time_valid && !isset($_GET["test_mode"])) ? 'background: #cbd5e1; border: none;' : '' ?>">📷 เปิดกล้องสแกน</button>
            <button id="btnStopScan" type="button" class="btn btn-outline-secondary" style="display: none; padding: 10px; width: 33.33%; min-width: 150px; margin: 0 auto;">ปิดกล้อง</button>
            
            <!-- Status Notification -->
            <div id="scanResult" style="display:none; padding: 15px; border-radius: 12px; margin-top: 15px; font-weight: 600; font-size: 1.1rem; text-align: center;"></div>
        </div>

        <!-- Smart Search Form -->
        <form method="POST" class="modern-card">
            <div style="margin-bottom: 20px;">
                <label style="font-weight: 700; margin-bottom: 12px; display: block; color: #14532d; font-size: 1.1rem;">📝 ค้นหาอัจฉริยะ: พิมพ์รหัส หรือ ชื่อนักเรียน (เลือกได้หลายคน)</label>
                <select id="student-select" name="student_ids[]" multiple placeholder="ค้นหานักเรียนชายมุสลิม..." required>
                    <option value="">พิมพ์รหัส 5 หลัก หรือชื่อ...</option>
                    <?php foreach($all_students as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= $s['student_code'] ?> - <?= htmlspecialchars($s['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="text-align: right; margin-top: 5px;">
                <button type="submit" name="submit_prayer" class="btn btn-danger-custom" <?= (!$is_time_valid && !isset($_GET["test_mode"])) ? 'disabled' : '' ?> style="padding: 6px 20px; font-size: 0.95rem; border-radius: 8px; margin-top: 5px; <?= (!$is_time_valid && !isset($_GET["test_mode"])) ? 'background: #cbd5e1; border: none;' : '' ?>">บันทึกเวลา</button>
            </div>
        </form>

        <!-- Summary -->
        <div class="stats-grid">
            <div class="stat-card" style="border-color: #fde047; background: #fefce8;">
                <div class="stat-icon">⏳</div>
                <div id="total_out_count" class="stat-value" style="color: #ca8a04;"><?= $total_out ?></div>
                <div class="stat-label" style="color: #a16207;">ออกไปแล้ว (ยังไม่กลับ)</div>
            </div>
            <div class="stat-card" style="border-color: #bbf7d0; background: #f0fdf4;">
                <div class="stat-icon">✅</div>
                <div id="total_returned_count" class="stat-value" style="color: #16a34a;"><?= $total_returned ?></div>
                <div class="stat-label" style="color: #15803d;">กลับมาแล้ว</div>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="modern-card" style="padding: 20px;">
            <div class="header-flex" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; border-bottom: 2px solid #f0fdf4; padding-bottom: 15px;">
                <h3 style="margin: 0; color: #14532d; font-size: 1rem; font-weight: 700;">📋 บันทึกล่าสุด <span style="font-size: 0.85rem; color: #16a34a; font-weight: normal;">(<?= $filter_date == $today ? 'วันนี้' : date('d/m/Y', strtotime($filter_date)) ?>)</span></h3>
                
                <form method="GET" class="filter-flex" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <?php if(isset($_GET['test_mode'])): ?><input type="hidden" name="test_mode" value="1"><?php endif; ?>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <label style="margin: 0; font-size: 0.9rem; font-weight: 700; color: #166534;">เลือกวันที่:</label>
                        <input type="date" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>" class="form-control" style="width: auto; padding: 6px 12px; border-radius: 12px; border: 1px solid #bbf7d0; outline: none; color: #14532d; font-size: 0.95rem; height: auto; background: #f0fdf4;" onchange="this.form.submit()">
                    </div>
                    
                    <a href="discipline_report_friday_prayer.php?filter_date=<?= urlencode($filter_date) ?>" target="_blank" class="btn btn-sm" style="background: white; border: 1px solid #16a34a; color: #16a34a; padding: 8px 16px; font-size: 0.9rem; font-weight: 700; border-radius: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 10px rgba(22,163,74,0.1);">
                        📄 ส่งออก PDF / พิมพ์
                    </a>
                </form>
            </div>
            <div class="table-responsive">
                <table class="recent-table">
                    <thead>
                        <tr>
                            <th>เวลาออก</th>
                            <th>รหัสนักเรียน</th>
                            <th>ชื่อ-สกุล</th>
                            <th>ห้อง</th>
                            <th>เวลากลับ</th>
                            <th>สถานะ</th>
                        </tr>
                    </thead>
                <tbody id="logTableBody">
                    <?php if(empty($logs)): ?>
                        <tr><td colspan="6" style="text-align:center; color:#94a3b8;">ยังไม่มีข้อมูลการสแกน</td></tr>
                    <?php else: ?>
                        <?php foreach($logs as $l): ?>
                            <tr>
                                <td><?= substr($l['time_out'], 0, 5) ?></td>
                                <td><?= htmlspecialchars($l['student_code']) ?></td>
                                <td style="text-align:left;"><?= htmlspecialchars($l['full_name']) ?></td>
                                <td><?= htmlspecialchars($l['room_name']) ?></td>
                                <td><?= $l['time_in'] ? substr($l['time_in'], 0, 5) : '-' ?></td>
                                <td>
                                    <?php if($l['status'] == 'out'): ?>
                                        <span style="color:#d97706; font-weight:600;">ออกไปละหมาด</span>
                                    <?php elseif($l['status'] == 'returned'): ?>
                                        <span style="color:#16a34a; font-weight:600;">กลับมาแล้ว</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
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
        
        // Visual feedback
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
                const isOut = (data.action === 'out');
                const badgeText = isOut ? '⏳ ออกไปละหมาด' : '✅ กลับเข้าโรงเรียนแล้ว';
                const badgeBg = isOut ? '#d97706' : '#16a34a';
                const borderClr = isOut ? '#f59e0b' : '#22c55e';
                const bgClr = isOut ? '#fffbeb' : '#f0fdf4';

                const cardHtml = `
                    <div style="display: flex; align-items: center; gap: 14px; text-align: left;">
                        <div style="flex-shrink: 0; width: 72px; height: 72px; border-radius: 12px; overflow: hidden; border: 2.5px solid ${borderClr}; background: #fff; box-shadow: 0 2px 5px rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: center;">
                            ${photoHtml}
                        </div>
                        <div style="flex-grow: 1; min-width: 0;">
                            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">
                                <span style="background: ${badgeBg}; color: white; padding: 2px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700;">${badgeText}</span>
                                <span style="color: #64748b; font-size: 0.8rem; font-weight: 600;">ห้อง ${data.room_name || '-'}</span>
                            </div>
                            <div style="font-size: 1.15rem; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px;">
                                ${data.full_name}
                            </div>
                            <div style="font-size: 0.82rem; color: #475569; display: flex; gap: 10px; flex-wrap: wrap;">
                                <span>🆔 <strong>${data.student_code}</strong></span>
                                <span>⏱️ ออก: <strong>${data.time_out} น.</strong> | กลับ: <strong>${data.time_in === '-' ? '-' : data.time_in + ' น.'}</strong></span>
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

                scanResult.style.background = bgClr;
                scanResult.style.border = `2px solid ${borderClr}`;
                scanResult.style.color = '#1e293b';
                scanResult.innerHTML = cardHtml;
                
                // Play success sound and vibrate
                playBeep(800, 100);
                if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
                
                // Update stats
                let totalOutEl = document.getElementById('total_out_count');
                let totalRetEl = document.getElementById('total_returned_count');
                
                if (data.action === 'out') {
                    totalOutEl.innerText = parseInt(totalOutEl.innerText) + 1;
                } else if (data.action === 'in') {
                    totalOutEl.innerText = parseInt(totalOutEl.innerText) - 1;
                    totalRetEl.innerText = parseInt(totalRetEl.innerText) + 1;
                }

                // Update table
                const tbody = document.getElementById('logTableBody');
                if (tbody) {
                    if (tbody.children.length === 1 && tbody.children[0].innerText.includes('ยังไม่มีข้อมูล')) {
                        tbody.innerHTML = '';
                    }

                    let existingRow = Array.from(tbody.rows).find(row => row.cells[1] && row.cells[1].innerText === data.student_code);
                    
                    if (existingRow && data.action === 'in') {
                        existingRow.cells[4].innerText = data.time_in;
                        existingRow.cells[5].innerHTML = '<span style="color:#16a34a; font-weight:600;">กลับมาแล้ว</span>';
                        existingRow.style.backgroundColor = '#dcfce7';
                        setTimeout(() => { existingRow.style.backgroundColor = ''; }, 2000);
                    } else if (data.action === 'out') {
                        const newRow = tbody.insertRow(0);
                        newRow.innerHTML = `
                            <td>${data.time_out}</td>
                            <td>${data.student_code}</td>
                            <td style="text-align:left;">${data.full_name}</td>
                            <td>${data.room_name}</td>
                            <td>${data.time_in}</td>
                            <td><span style="color:#d97706; font-weight:600;">ออกไปละหมาด</span></td>
                        `;
                        newRow.style.backgroundColor = '#fef3c7';
                        setTimeout(() => { newRow.style.backgroundColor = ''; }, 2000);
                    }
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
                            <div style="font-size: 1.15rem; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px;">
                                ${data.full_name}
                            </div>
                            <div style="font-size: 0.82rem; color: #b45309; font-weight: 600;">
                                ได้บันทึกการกลับเข้ามาแล้วในวันนี้
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
                
                // Play error sound and vibrate
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


    // HTML5 QR Code setup
    btnStartScan.addEventListener('click', () => {
        readerDiv.style.display = 'block';
        btnStartScan.style.display = 'none';
        btnStopScan.style.display = 'block';
        
        html5QrcodeScanner = new Html5Qrcode("reader");
        html5QrcodeScanner.start(
            { facingMode: "environment" }, 
            { fps: 10, qrbox: { width: 250, height: 250 } },
            (decodedText, decodedResult) => {
                // We rely on isProcessing flag in processScan to prevent multiple fires
                processScan(decodedText);
            },
            (errorMessage) => {
                // parse error, ignore
            }
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

    // Simple beep function using Web Audio API
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

</div>
<?php include '../../includes/footer.php'; ?>
