<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
$is_level_head = in_array('level_head', $user_roles) || in_array('admin', $user_roles) || in_array('executive', $user_roles);
if (!in_array('discipline', $user_roles) && !in_array('executive', $user_roles) && !in_array('admin', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "จัดการคะแนนความประพฤติ (กิจการนักเรียน)";
include '../../includes/header.php';

$success = '';
$error = '';
$discipline_teacher_id = $_SESSION['user_id'];

// Get active academic term
$stmt_term = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
$term_id = $stmt_term->fetchColumn();

if (!$term_id) {
    echo "<div class='card'><div class='badge badge-danger'>ไม่พบปีการศึกษาที่เปิดใช้งาน</div></div>";
    include '../../includes/footer.php';
    exit();
}

// Function to update current_score based on all approved records + initial_score
function updateCurrentScore($pdo, $student_id, $term_id) {
    // Ensure behavior row exists
    $stmt = $pdo->prepare("INSERT IGNORE INTO student_behaviors (student_id, academic_term_id, initial_score, current_score) VALUES (?, ?, 100, 100)");
    $stmt->execute([$student_id, $term_id]);
    
    // Sum points
    $stmt_sum = $pdo->prepare("SELECT SUM(points) FROM behavior_records WHERE student_id = ? AND academic_term_id = ? AND status = 'approved'");
    $stmt_sum->execute([$student_id, $term_id]);
    $total_points = (int)$stmt_sum->fetchColumn();
    
    // Update
    $new_score = 100 + $total_points;
    if ($new_score > 100) $new_score = 100;
    $stmt_upd = $pdo->prepare("UPDATE student_behaviors SET current_score = ? WHERE student_id = ? AND academic_term_id = ?");
    $stmt_upd->execute([$new_score, $student_id, $term_id]);
}

// Handle Direct Add/Deduct
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['direct_record'])) {
    if (in_array('admin', $user_roles) || in_array('level_head', $user_roles)) {
        $student_id = $_POST['student_id'];
        $points = (int)$_POST['points'];
        $reason = trim($_POST['reason']);
        
        if ($student_id && $reason && $points != 0) {
            $stmt = $pdo->prepare("INSERT INTO behavior_records (student_id, academic_term_id, recorded_by_teacher_id, record_date, points, reason, status) VALUES (?, ?, ?, CURDATE(), ?, ?, 'approved')");
            $stmt->execute([$student_id, $term_id, $discipline_teacher_id, $points, $reason]);
            updateCurrentScore($pdo, $student_id, $term_id);
            $success = "บันทึกและปรับคะแนนเรียบร้อยแล้ว";
        }
    } else {
        $error = "คุณไม่มีสิทธิ์ในการบันทึกคะแนนนี้";
    }
}

// Handle Initial Score Adjust
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adjust_initial'])) {
    $student_id = $_POST['student_id'];
    $new_initial = (int)$_POST['initial_score'];
    
    $stmt = $pdo->prepare("INSERT INTO student_behaviors (student_id, academic_term_id, initial_score) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE initial_score = ?");
    $stmt->execute([$student_id, $term_id, $new_initial, $new_initial]);
    updateCurrentScore($pdo, $student_id, $term_id);
    $success = "ปรับคะแนนเริ่มต้นเรียบร้อยแล้ว";
}

// Get classrooms for filter
$classrooms = $pdo->query("SELECT id, room_name, grade_level FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC")->fetchAll();
$filter_class = $_GET['classroom_id'] ?? '';

// Search for students
$students = [];
if ($filter_class) {
    $stmt_s = $pdo->prepare("
        SELECT s.id, s.student_code, s.student_number, s.full_name, c.room_name, COALESCE(sb.initial_score, 100) as initial_score, COALESCE(sb.current_score, 100) as current_score
        FROM students s
        LEFT JOIN classrooms c ON s.classroom_id = c.id
        LEFT JOIN student_behaviors sb ON s.id = sb.student_id AND sb.academic_term_id = ?
        WHERE s.classroom_id = ? AND s.status = 'active'
        ORDER BY CAST(s.student_number AS UNSIGNED) ASC, s.student_code ASC
    ");
    $stmt_s->execute([$term_id, $filter_class]);
    $students = $stmt_s->fetchAll();
}

// --- LATE STATS LOGIC ---
$stats_filter_type = $_GET['stats_filter_type'] ?? 'term';
$stats_date = $_GET['stats_date'] ?? date('Y-m-d');
$stats_week = $_GET['stats_week'] ?? date('Y-\WW'); // e.g., 2026-W34
$stats_month = $_GET['stats_month'] ?? date('Y-m');
$stats_start = $_GET['stats_start'] ?? date('Y-m-01');
$stats_end = $_GET['stats_end'] ?? date('Y-m-t');

$date_condition = "";
$params = [$term_id];

if ($stats_filter_type === 'date' && $stats_date) {
    $date_condition = " AND br.record_date = ? ";
    $params[] = $stats_date;
} elseif ($stats_filter_type === 'week' && $stats_week) {
    $year = (int)substr($stats_week, 0, 4);
    $week = (int)substr($stats_week, 6);
    $dto = new DateTime();
    $dto->setISODate($year, $week);
    $start_week = $dto->format('Y-m-d');
    $dto->modify('+6 days');
    $end_week = $dto->format('Y-m-d');
    $date_condition = " AND br.record_date BETWEEN ? AND ? ";
    $params[] = $start_week;
    $params[] = $end_week;
} elseif ($stats_filter_type === 'month' && $stats_month) {
    $date_condition = " AND DATE_FORMAT(br.record_date, '%Y-%m') = ? ";
    $params[] = $stats_month;
} elseif ($stats_filter_type === 'range' && $stats_start && $stats_end) {
    $date_condition = " AND br.record_date BETWEEN ? AND ? ";
    $params[] = $stats_start;
    $params[] = $stats_end;
}

$stats_sql = "
    SELECT 
        c.id as classroom_id,
        c.grade_level,
        c.room_name,
        c.room_number,
        COUNT(br.id) as late_count
    FROM classrooms c
    LEFT JOIN students s ON c.id = s.classroom_id AND s.status = 'active'
    LEFT JOIN behavior_records br ON s.id = br.student_id 
        AND br.academic_term_id = ? 
        AND br.status = 'approved' 
        AND (br.reason LIKE 'มาสาย%' OR br.reason LIKE '%สาย%')
        $date_condition
    GROUP BY c.id
    ORDER BY CAST(SUBSTRING_INDEX(c.grade_level, '.', -1) AS UNSIGNED) ASC, CAST(c.room_number AS UNSIGNED) ASC
";
$stmt_stats = $pdo->prepare($stats_sql);
$stmt_stats->execute($params);
$all_late_stats = $stmt_stats->fetchAll(PDO::FETCH_ASSOC);

$grade_stats = [];
foreach ($all_late_stats as $stat) {
    $grade_stats[$stat['grade_level']][] = $stat;
}

// Sort for top 5 (most late)
$sorted_stats = $all_late_stats;
usort($sorted_stats, function($a, $b) {
    return $b['late_count'] <=> $a['late_count'];
});
$top_late = array_slice($sorted_stats, 0, 5);
// ------------------------
?>

<div style="display: grid; grid-template-columns: 1fr; gap: 20px;">
    
    <!-- Quick Actions Grid -->
    <style>
        .cute-card {
            text-align: center;
            text-decoration: none;
            color: inherit;
            padding: 15px 12px;
            border-radius: 16px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(0,0,0,0.03);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02), 0 2px 4px -1px rgba(0,0,0,0.02);
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 0; /* Fix grid blowout */
            word-break: break-word;
        }
        .cute-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 20px -5px rgba(0,0,0,0.08), 0 8px 10px -3px rgba(0,0,0,0.04);
        }
        .cute-icon {
            font-size: 2.8rem;
            margin-bottom: 12px;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .cute-card:hover .cute-icon {
            transform: scale(1.15) rotate(8deg);
        }
        .cute-title {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 600;
            line-height: 1.4;
            letter-spacing: -0.3px;
        }
        .cute-desc {
            margin: 6px 0 0;
            font-size: 0.8rem;
            color: #64748b;
        }
    </style>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 25px;">
        
        <?php if ($is_level_head): ?>
        <a href="discipline_inspection_cycles.php" class="cute-card" style="background-color: #eff6ff;">
            <div class="cute-icon">📅</div>
            <h4 class="cute-title" style="color: #3b82f6;">กำหนดวันตรวจระเบียบ</h4>
            <p class="cute-desc">จัดการรอบตรวจระเบียบ</p>
        </a>

        <a href="discipline_process_weekly.php" class="cute-card" style="background-color: #fffbeb;">
            <div class="cute-icon">✂️</div>
            <h4 class="cute-title" style="color: #d97706;">กำหนดหักคะแนนตรวจระเบียบรายสัปดาห์</h4>
            <p class="cute-desc">หักคะแนนคนไม่ผ่านอัตโนมัติ</p>
        </a>

        <a href="discipline_manage_scores.php" class="cute-card" style="background-color: #fef2f2;">
            <div class="cute-icon">⚖️</div>
            <h4 class="cute-title" style="color: #ef4444;">จัดการคะแนนกรณีพิเศษ</h4>
            <p class="cute-desc">คดีความประพฤติและหัก/เพิ่มด่วน</p>
        </a>
        <?php endif; ?>

        <a href="discipline_late_check.php" class="cute-card" style="background-color: #fdf2f8;">
            <div class="cute-icon">⏰</div>
            <h4 class="cute-title" style="color: #db2777;">ระบบเช็คชื่อมาสาย</h4>
            <p class="cute-desc">เช็คชื่อและหักคะแนนรายวัน</p>
        </a>

        <a href="discipline_late_repeated.php" class="cute-card" style="background-color: #faf5ff;">
            <div class="cute-icon">📊</div>
            <h4 class="cute-title" style="color: #9333ea;">รายงานมาสายบ่อย</h4>
            <p class="cute-desc">สรุปเด็กมาสาย 4 ครั้งขึ้นไป</p>
        </a>

        <?php if ($is_level_head): ?>
        <a href="discipline_import.php" class="cute-card" style="background-color: #f0fdf4;">
            <div class="cute-icon">📥</div>
            <h4 class="cute-title" style="color: #16a34a;">นำเข้าคะแนน CSV</h4>
            <p class="cute-desc">อัปโหลดคะแนนเริ่มต้น</p>
        </a>
        <?php endif; ?>

        <a href="report_homeroom_monthly.php" class="cute-card" style="background-color: #ecfeff;">
            <div class="cute-icon">📋</div>
            <h4 class="cute-title" style="color: #0891b2;">รายงานการเข้าแถวรายเดือน</h4>
            <p class="cute-desc">สรุปผลการโฮมรูม</p>
        </a>

        <a href="discipline_friday_prayer.php" class="cute-card" style="background-color: #f5f3ff;">
            <div class="cute-icon">🕌</div>
            <h4 class="cute-title" style="color: #7c3aed;">สแกนละหมาดวันศุกร์</h4>
            <p class="cute-desc">สแกนเวลาเข้า-ออกละหมาด</p>
        </a>
        
        <?php if ($is_level_head): ?>
        <a href="discipline_student_qrcode.php" class="cute-card" style="background-color: #fdf4ff;">
            <div class="cute-icon">🖨️</div>
            <h4 class="cute-title" style="color: #c026d3;">พิมพ์บัตร QR Code</h4>
            <p class="cute-desc">สร้างและพิมพ์บัตรนักเรียน</p>
        </a>

        <a href="teacher_upload_links.php" class="cute-card" style="background-color: #fff1f2;">
            <div class="cute-icon">📸</div>
            <h4 class="cute-title" style="color: #e11d48;">สร้างลิงก์อัปโหลดรูปนักเรียน</h4>
            <p class="cute-desc">ลิงก์ลับเฉพาะกิจอัปโหลดรูป</p>
        </a>
        
        <a href="discipline_report_scores.php" class="cute-card" style="background-color: #fefce8;">
            <div class="cute-icon">🖨️</div>
            <h4 class="cute-title" style="color: #ca8a04;">สรุปคะแนนความประพฤติ (PDF)</h4>
            <p class="cute-desc">พิมพ์รายงานคะแนนรายห้อง</p>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($is_level_head): ?>
    <!-- Late Stats Section -->
    <div class="card" id="late-stats-section" style="margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
            <h2 class="card-title" style="margin-bottom: 0;">📊 สถิติการมาสายของห้องเรียน</h2>
            
            <form method="GET" action="#late-stats-section" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; background: #f8fafc; padding: 10px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <!-- Preserve other filters if any -->
                <?php if(isset($_GET['grade'])): ?>
                    <input type="hidden" name="grade" value="<?= htmlspecialchars($_GET['grade']) ?>">
                <?php endif; ?>
                <?php if(isset($_GET['classroom_id'])): ?>
                    <input type="hidden" name="classroom_id" value="<?= htmlspecialchars($_GET['classroom_id']) ?>">
                <?php endif; ?>

                <div style="display: flex; align-items: center; gap: 8px;">
                    <label style="margin: 0; font-size: 0.9rem; font-weight: 600; color: #475569;">กรองข้อมูล:</label>
                    <select name="stats_filter_type" class="form-control" style="width: auto; padding: 4px 8px; font-size: 0.9rem; height: 32px;" onchange="this.form.submit()">
                        <option value="term" <?= $stats_filter_type == 'term' ? 'selected' : '' ?>>ภาคเรียนปัจจุบัน</option>
                        <option value="date" <?= $stats_filter_type == 'date' ? 'selected' : '' ?>>เลือกวันที่</option>
                        <option value="week" <?= $stats_filter_type == 'week' ? 'selected' : '' ?>>เลือกทั้งสัปดาห์</option>
                        <option value="month" <?= $stats_filter_type == 'month' ? 'selected' : '' ?>>เลือกทั้งเดือน</option>
                        <option value="range" <?= $stats_filter_type == 'range' ? 'selected' : '' ?>>ช่วงเวลา</option>
                    </select>
                </div>
                
                <?php if($stats_filter_type == 'date'): ?>
                    <input type="date" name="stats_date" class="form-control" value="<?= htmlspecialchars($stats_date) ?>" style="width: auto; padding: 4px 8px; font-size: 0.9rem; height: 32px;" onchange="this.form.submit()">
                <?php elseif($stats_filter_type == 'month'): ?>
                    <input type="month" name="stats_month" class="form-control" value="<?= htmlspecialchars($stats_month) ?>" style="width: auto; padding: 4px 8px; font-size: 0.9rem; height: 32px;" onchange="this.form.submit()">
                <?php elseif($stats_filter_type == 'range'): ?>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <input type="date" name="stats_start" class="form-control" value="<?= htmlspecialchars($stats_start) ?>" style="width: auto; padding: 4px 8px; font-size: 0.9rem; height: 32px;" onchange="if(this.form.stats_end.value) this.form.submit()">
                        <span style="font-size: 0.9rem; color: #64748b;">ถึง</span>
                        <input type="date" name="stats_end" class="form-control" value="<?= htmlspecialchars($stats_end) ?>" style="width: auto; padding: 4px 8px; font-size: 0.9rem; height: 32px;" onchange="if(this.form.stats_start.value) this.form.submit()">
                    </div>
                <?php endif; ?>

                <!-- PDF Report Export Button -->
                <a href="discipline_report_late_stats.php?stats_filter_type=<?= urlencode($stats_filter_type) ?>&stats_date=<?= urlencode($stats_date) ?>&stats_week=<?= urlencode($stats_week) ?>&stats_month=<?= urlencode($stats_month) ?>&stats_start=<?= urlencode($stats_start) ?>&stats_end=<?= urlencode($stats_end) ?>" target="_blank" class="btn btn-sm" style="background: white; border: 1px solid #ef4444; color: #dc2626; padding: 5px 12px; font-size: 0.85rem; font-weight: 600; border-radius: 6px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 1px 2px rgba(220, 38, 38, 0.05);">
                    📄 ส่งออก PDF / พิมพ์
                </a>
            </form>
        </div>
        
        <!-- Top 5 Most Late (Compact) -->
        <div style="background: #fff5f5; border: 1px solid #fed7d7; border-radius: 12px; padding: 15px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 12px 0; color: #dc2626; font-size: 0.95rem; display: flex; align-items: center; gap: 6px; font-weight: 700;">
                <span>🔴</span> 5 อันดับห้องที่มาสายมากที่สุด
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px;">
                <?php foreach($top_late as $index => $room): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; background: white; padding: 7px 12px; border-radius: 8px; border: 1px solid #fee2e2; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                    <span style="font-weight: 600; color: #334155; font-size: 0.85rem;">#<?= $index+1 ?> <?= htmlspecialchars($room['room_name']) ?></span>
                    <span style="background: #fee2e2; color: #dc2626; font-weight: 700; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem;"><?= $room['late_count'] ?> <span style="font-size: 0.7rem; font-weight: normal;">ครั้ง</span></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Grade Tabs -->
        <h3 style="font-size: 0.95rem; color: #334155; margin-bottom: 12px; font-weight: 700;">สถิติแยกตามระดับชั้น</h3>
        
        <div class="tabs-container" style="overflow-x: auto; white-space: nowrap; margin-bottom: 12px; padding-bottom: 5px; border-bottom: 1px solid #e2e8f0; display: flex; gap: 6px;">
            <?php $first = true; foreach($grade_stats as $grade => $rooms): ?>
                <button type="button" class="grade-tab <?= $first ? 'active' : '' ?>" data-target="tab-<?= md5($grade) ?>" 
                        style="background: <?= $first ? 'var(--primary-color)' : '#f1f5f9' ?>; 
                               color: <?= $first ? 'white' : '#475569' ?>; 
                               border: 1px solid <?= $first ? 'var(--primary-color)' : '#cbd5e1' ?>; padding: 6px 16px; border-radius: 20px; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: all 0.2s;">
                    <?= htmlspecialchars($grade) ?>
                </button>
            <?php $first = false; endforeach; ?>
        </div>

        <div class="tabs-content">
            <?php $first = true; foreach($grade_stats as $grade => $rooms): ?>
                <div id="tab-<?= md5($grade) ?>" class="grade-pane" style="display: <?= $first ? 'block' : 'none' ?>;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(105px, 1fr)); gap: 8px;">
                        <?php foreach($rooms as $r): ?>
                            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px 4px; text-align: center; box-shadow: 0 1px 2px rgba(0,0,0,0.02);">
                                <div style="font-weight: 700; color: #334155; font-size: 0.82rem; margin-bottom: 2px;"><?= htmlspecialchars($r['room_name']) ?></div>
                                <div style="color: <?= $r['late_count'] > 0 ? '#ef4444' : '#10b981' ?>; font-weight: 700; font-size: 1.05rem; line-height: 1;">
                                    <?= $r['late_count'] ?> <span style="font-size: 0.72rem; font-weight: normal; color: #64748b;">ครั้ง</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php $first = false; endforeach; ?>
        </div>

        <script>
            document.querySelectorAll('.grade-tab').forEach(tab => {
                tab.addEventListener('click', (e) => {
                    e.preventDefault();
                    document.querySelectorAll('.grade-tab').forEach(t => {
                        t.style.background = '#f1f5f9';
                        t.style.color = '#475569';
                        t.style.borderColor = '#cbd5e1';
                        t.classList.remove('active');
                    });
                    tab.style.background = 'var(--primary-color)';
                    tab.style.color = 'white';
                    tab.style.borderColor = 'var(--primary-color)';
                    tab.classList.add('active');
                    
                    document.querySelectorAll('.grade-pane').forEach(p => p.style.display = 'none');
                    document.getElementById(tab.getAttribute('data-target')).style.display = 'block';
                });
            });
        </script>
    </div>
    <?php
    // --- Individual Student Attendance Stats Logic ---
    $stats_filter = $_GET['stats_filter'] ?? 'all';
    $stats_period = $_GET['stats_period'] ?? 'month';
    $stats_month = $_GET['stats_month'] ?? date('Y-m');
    $stats_start = $_GET['stats_start'] ?? date('Y-m-d');
    $stats_end = $_GET['stats_end'] ?? date('Y-m-d');

    $start_date = '';
    $end_date = '';
    if ($stats_period === 'range') {
        $start_date = $stats_start;
        $end_date = $stats_end;
    } else {
        $start_date = $stats_month . '-01';
        $end_date = date('Y-m-t', strtotime($start_date));
    }

    $where_clauses = ["ha.attendance_date BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];

    if (strpos($stats_filter, 'grade_') === 0) {
        $grade = substr($stats_filter, 6);
        $where_clauses[] = "c.grade_level = ?";
        $params[] = $grade;
    } elseif (strpos($stats_filter, 'room_') === 0) {
        $room_id = substr($stats_filter, 5);
        $where_clauses[] = "c.id = ?";
        $params[] = $room_id;
    }

    $where_sql = implode(' AND ', $where_clauses);

    $stats_sql = "
        SELECT 
            s.id, s.student_code, s.full_name, s.student_number, c.room_name,
            SUM(CASE WHEN ha.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN ha.status = 'leave' THEN 1 ELSE 0 END) as leave_count,
            SUM(CASE WHEN ha.status = 'absent' THEN 1 ELSE 0 END) as absent_count
        FROM homeroom_attendance ha
        JOIN students s ON ha.student_id = s.id
        JOIN classrooms c ON s.classroom_id = c.id
        WHERE $where_sql
          AND ha.status IN ('late', 'leave', 'absent')
        GROUP BY s.id
        HAVING late_count > 0 OR leave_count > 0 OR absent_count > 0
        ORDER BY late_count DESC, leave_count DESC, absent_count DESC
        LIMIT 100
    ";
    $stmt_ind_stats = $pdo->prepare($stats_sql);
    $stmt_ind_stats->execute($params);
    $ind_stats = $stmt_ind_stats->fetchAll();
    ?>

    <!-- Individual Student Attendance Stats -->
    <div class="card" id="ind-stats-section" style="margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px;">
            <h2 class="card-title" style="margin-bottom: 0;">📈 สถิติการมาสายและการลา (รายบุคคล)</h2>
            
            <form method="GET" action="#ind-stats-section" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; background: #f8fafc; padding: 10px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <!-- Preserve main filter params if any -->
                <?php if(isset($_GET['date'])): ?><input type="hidden" name="date" value="<?= htmlspecialchars($_GET['date']) ?>"><?php endif; ?>
                
                <!-- Filter by Room / Grade -->
                <select name="stats_filter" class="form-control" style="width: auto; min-width: 140px;" onchange="this.form.submit()">
                    <option value="all">ทุกระดับชั้น</option>
                    <optgroup label="แยกตามระดับชั้น">
                        <?php 
                        $grades_unique = array_unique(array_column($classrooms, 'grade_level'));
                        foreach($grades_unique as $grade): ?>
                            <option value="grade_<?= htmlspecialchars($grade) ?>" <?= ($stats_filter == 'grade_'.$grade) ? 'selected' : '' ?>>ระดับชั้น <?= htmlspecialchars($grade) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="แยกตามห้องเรียน">
                        <?php foreach($classrooms as $c): ?>
                            <option value="room_<?= $c['id'] ?>" <?= ($stats_filter == 'room_'.$c['id']) ? 'selected' : '' ?>>ห้อง <?= htmlspecialchars($c['room_name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>

                <!-- Filter by Period Type -->
                <select name="stats_period" id="stats_period_select" class="form-control" style="width: auto;" onchange="togglePeriodInput()">
                    <option value="month" <?= $stats_period == 'month' ? 'selected' : '' ?>>รอบเดือน</option>
                    <option value="range" <?= $stats_period == 'range' ? 'selected' : '' ?>>ช่วงวัน</option>
                </select>

                <!-- Inputs -->
                <input type="month" name="stats_month" id="input_stats_month" class="form-control" style="width: auto; <?= $stats_period == 'range' ? 'display:none;' : '' ?>" value="<?= htmlspecialchars($stats_month) ?>" onchange="this.form.submit()">
                
                <div id="input_stats_range" style="display: <?= $stats_period == 'range' ? 'flex' : 'none' ?>; gap: 5px; align-items: center;">
                    <input type="date" name="stats_start" class="form-control" style="width: auto;" value="<?= htmlspecialchars($stats_start) ?>" onchange="this.form.submit()">
                    <span style="color: var(--text-muted); font-size: 0.9rem;">ถึง</span>
                    <input type="date" name="stats_end" class="form-control" style="width: auto;" value="<?= htmlspecialchars($stats_end) ?>" onchange="this.form.submit()">
                </div>
                
                <button type="submit" class="btn" style="background: var(--primary-color); color: white; padding: 6px 12px; border: none; border-radius: 6px; cursor: pointer;">🔍 กรองข้อมูล</button>
            </form>
        </div>

        <script>
            function togglePeriodInput() {
                var period = document.getElementById('stats_period_select').value;
                if (period === 'range') {
                    document.getElementById('input_stats_month').style.display = 'none';
                    document.getElementById('input_stats_range').style.display = 'flex';
                } else {
                    document.getElementById('input_stats_range').style.display = 'none';
                    document.getElementById('input_stats_month').style.display = 'inline-block';
                }
            }
        </script>

        <div class="table-responsive" style="max-height: 1000px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
            <table class="table" style="margin-bottom: 0;">
                <thead style="position: sticky; top: 0; z-index: 10; background: #f8fafc; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <tr>
                        <th style="width: 60px; text-align: center;">ลำดับ</th>
                        <th>ชื่อ-สกุล</th>
                        <th style="text-align: center;">ห้อง</th>
                        <th style="text-align: center; color: #dc2626;">มาสาย</th>
                        <th style="text-align: center; color: #f59e0b;">ลา</th>
                        <th style="text-align: center; color: #ef4444;">ขาด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ind_stats)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">
                            ไม่มีข้อมูลนักเรียนที่มาสาย ขาด หรือลาในรอบเวลาที่เลือก
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php $row_num = 1; foreach ($ind_stats as $st): ?>
                        <tr>
                            <td style="text-align: center; color: var(--text-muted);"><?= $row_num++ ?></td>
                            <td>
                                <strong><?= htmlspecialchars($st['full_name']) ?></strong>
                                <div style="font-size: 0.75rem; color: var(--text-muted);">เลขประจำตัว: <?= htmlspecialchars($st['student_code']) ?> (เลขที่ <?= htmlspecialchars($st['student_number']) ?>)</div>
                            </td>
                            <td style="text-align: center;"><?= htmlspecialchars($st['room_name']) ?></td>
                            <td style="text-align: center; font-weight: bold; color: <?= $st['late_count'] > 0 ? '#dc2626' : '#9ca3af' ?>;">
                                <?= $st['late_count'] ?> ครั้ง
                            </td>
                            <td style="text-align: center; font-weight: bold; color: <?= $st['leave_count'] > 0 ? '#f59e0b' : '#9ca3af' ?>;">
                                <?= $st['leave_count'] ?> ครั้ง
                            </td>
                            <td style="text-align: center; font-weight: bold; color: <?= $st['absent_count'] > 0 ? '#ef4444' : '#9ca3af' ?>;">
                                <?= $st['absent_count'] ?> ครั้ง
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- Student Management Section -->
    <div class="card">
        <h2 class="card-title">🔍 ค้นหาและจัดการคะแนนนักเรียนรายบุคคล</h2>
        
        <?php
        $unique_grades = [];
        foreach($classrooms as $c) {
            if (!in_array($c['grade_level'], $unique_grades)) {
                $unique_grades[] = $c['grade_level'];
            }
        }
        $filter_grade = $_GET['grade'] ?? '';
        if ($filter_class) {
            foreach($classrooms as $c) {
                if ($c['id'] == $filter_class) {
                    $filter_grade = $c['grade_level'];
                    break;
                }
            }
        }
        ?>
        <form method="GET" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>เลือกระดับชั้น</label>
                <select name="grade" class="form-control" onchange="this.form.classroom_id.value=''; this.form.submit()">
                    <option value="">-- ระดับชั้น --</option>
                    <?php foreach($unique_grades as $g): ?>
                        <option value="<?= htmlspecialchars($g) ?>" <?= $filter_grade === $g ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 0;">
                <label>เลือกห้องเรียน</label>
                <select name="classroom_id" class="form-control" onchange="this.form.submit()" <?= empty($filter_grade) ? 'disabled' : '' ?>>
                    <option value="">-- เลือกห้องเรียน --</option>
                    <?php foreach($classrooms as $c): ?>
                        <?php if($filter_grade && $c['grade_level'] !== $filter_grade) continue; ?>
                        <option value="<?= $c['id'] ?>" <?= $filter_class == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['room_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($filter_class && !empty($students)): ?>
            <div class="table-responsive">
                <table class="table" style="font-size: 0.95rem;">
                    <thead>
                        <tr>
                            <th style="width: 50px;">เลขที่</th>
                            <th>รหัสนักเรียน</th>
                            <th>ชื่อ-สกุล</th>
                            <th style="text-align: center;">คะแนนคงเหลือ</th>
                            <th>ดำเนินการ (หัก/เพิ่ม)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($students as $s): ?>
                        <tr>
                            <td style="text-align: center;"><?= htmlspecialchars($s['student_number']) ?></td>
                            <td><?= htmlspecialchars($s['student_code']) ?></td>
                            <td><?= htmlspecialchars($s['full_name']) ?></td>
                            <td style="text-align: center; font-size: 1.1rem; font-weight: bold; color: <?= $s['current_score'] < 60 ? 'var(--danger-color)' : 'var(--text-main)' ?>;">
                                <?= $s['current_score'] ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px; flex-wrap: wrap; align-items: center;">
                                    <a href="discipline_student_history.php?student_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline" style="padding: 5px 10px; margin-right: 5px;">📜 ประวัติ</a>
                                    <?php if (in_array('admin', $user_roles) || in_array('level_head', $user_roles)): ?>
                                    <form method="POST" style="display: flex; gap: 5px; flex-wrap: wrap; align-items: center; margin: 0;">
                                        <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                                        <input type="number" name="points" class="form-control" placeholder="-5 หรือ 5" required style="width: 80px; padding: 5px;">
                                        <input type="text" name="reason" class="form-control" placeholder="สาเหตุ" required style="width: 150px; padding: 5px;">
                                        <button type="submit" name="direct_record" class="btn btn-sm btn-primary">บันทึก</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php endif; ?>
<?php include '../../includes/footer.php'; ?>
