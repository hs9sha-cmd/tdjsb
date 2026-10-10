<?php
session_start();
require_once '../../config/database.php';

// Check roles
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('academic', $user_roles) && !in_array('admin', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "แดชบอร์ด (วิชาการ)";
include '../../includes/header.php';

$filter_date = $_GET['date'] ?? date('Y-m-d');

// Initialize variables
$grades = ['ม.1', 'ม.2', 'ม.3', 'ม.4', 'ม.5', 'ม.6'];
$school_stats = [];

try {
    // 1. Get Baseline (Total Students per classroom)
    $base_stmt = $pdo->query("
        SELECT c.grade_level, c.id as classroom_id, c.room_name, COUNT(s.id) as total_students
        FROM classrooms c
        LEFT JOIN students s ON s.classroom_id = c.id AND s.status = 'active'
        GROUP BY c.id
        ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC
    ");
    while ($row = $base_stmt->fetch()) {
        $gl = $row['grade_level'];
        $cname = $row['room_name'];
        if (!isset($school_stats[$gl])) $school_stats[$gl] = [];
        $school_stats[$gl][$cname] = [
            'id' => $row['classroom_id'],
            'total_students' => (int)$row['total_students'],
            'present' => 0, 'late' => 0, 'leave' => 0, 'absent' => 0
        ];
    }

    // 2. Get Homeroom Attendance Data
    $att_stmt = $pdo->prepare("
        SELECT c.grade_level, c.room_name, ha.status, COUNT(ha.id) as student_count
        FROM homeroom_attendance ha
        JOIN students s ON ha.student_id = s.id
        JOIN classrooms c ON s.classroom_id = c.id
        WHERE ha.attendance_date = ?
        GROUP BY c.id, ha.status
    ");
    $att_stmt->execute([$filter_date]);
    
    while ($row = $att_stmt->fetch()) {
        $gl = $row['grade_level'];
        $cname = $row['room_name'];
        $status = $row['status'];
        $count = (int)$row['student_count'];

        if (isset($school_stats[$gl][$cname])) {
            $school_stats[$gl][$cname][$status] += $count;
        }
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}

// 3. Find At-Risk Students (Missing > 3 times in any subject)
$at_risk_students = [];
try {
    // Only check for the current term (approx. last 5 months) to prevent massive full table scans
    $at_risk_stmt = $pdo->prepare("
        SELECT s.full_name, s.student_number, c.room_name, sub.subject_code, sub.subject_name, COUNT(ca.id) as absent_count
        FROM class_attendance ca
        JOIN teaching_logs tl ON ca.teaching_log_id = tl.id
        JOIN subjects sub ON tl.subject_id = sub.id
        JOIN students s ON ca.student_id = s.id
        LEFT JOIN classrooms c ON s.classroom_id = c.id
        WHERE ca.status = 'absent' AND tl.log_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY s.id, sub.id
        HAVING absent_count > 3
        ORDER BY absent_count DESC, c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC
    ");
    $at_risk_stmt->execute();
    $at_risk_students = $at_risk_stmt->fetchAll();
} catch (Exception $e) {}

// Calculate Table Stats
$stats = [];
foreach ($grades as $g) {
    $stats[$g] = [
        'total_rooms' => 0, 
        'checked_rooms' => 0, 
        'total_students' => 0, 
        'present' => 0, 
        'late' => 0, 
        'leave' => 0, 
        'absent' => 0,
        'missing' => 0
    ];
    if (isset($school_stats[$g])) {
        foreach ($school_stats[$g] as $room) {
            $stats[$g]['total_rooms']++;
            $stats[$g]['total_students'] += $room['total_students'];
            $stats[$g]['present'] += $room['present'];
            $stats[$g]['late'] += $room['late'];
            $stats[$g]['leave'] += $room['leave'];
            $stats[$g]['absent'] += $room['absent'];
            $pl = $room['present'] + $room['late'];
            $la = $room['leave'] + $room['absent'];
            if (($pl + $la) > 0) {
                $stats[$g]['checked_rooms']++;
            }
        }
        $checked_total = $stats[$g]['present'] + $stats[$g]['late'] + $stats[$g]['leave'] + $stats[$g]['absent'];
        $stats[$g]['missing'] = $stats[$g]['total_students'] - $checked_total;
    }
}
?>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

<div>
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">ภาพรวมการจัดการเรียนการสอน (วิชาการ)</h2>
    </div>

    <!-- Filter Bar -->
    <div class="card" style="margin-bottom: 20px; padding: 15px; overflow: hidden; max-width: 100%;">
        <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>วันที่ตรวจสอบภาพรวม</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>">
            </div>
            <button type="submit" class="btn btn-primary">ดูข้อมูล</button>
        </form>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 30px;">
        <!-- Card 1: ไทม์ไลน์การสอน -->
        <a href="academic_feed.php" class="cute-card" style="background-color: #eff6ff;">
            <div class="cute-icon">🕒</div>
            <h3 class="cute-title">ไทม์ไลน์การสอน</h3>
            <p class="cute-desc">ตรวจสอบการเข้าสอนแบบเรียลไทม์</p>
        </a>

        <!-- Card 2: แบบบันทึกประจำห้อง -->
        <a href="academic_report_daily.php" class="cute-card" style="background-color: #f0fdf4;">
            <div class="cute-icon">📓</div>
            <h3 class="cute-title">แบบบันทึกประจำห้อง</h3>
            <p class="cute-desc">รายงานการเข้าชั้นเรียนรายวัน</p>
        </a>
    </div>

    <!-- At-Risk Students Section -->
    <?php if (count($at_risk_students) > 0): ?>
    <div class="card" style="margin-bottom: 20px; border-left: 4px solid var(--danger-color); overflow: hidden; max-width: 100%;">
        <h3 class="card-title" style="font-size: 1.1rem; color: var(--danger-color); display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            🚨 นักเรียนกลุ่มเสี่ยง (ขาดเรียนรายวิชาสะสมเกิน 3 ครั้ง)
        </h3>
        <div class="scroll-table table-fixed-header" style="border: 1px solid var(--border-color); border-radius: 6px; border-bottom: none;">
            <table class="table" style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th>ห้องเรียน</th>
                        <th>เลขที่</th>
                        <th>ชื่อ-นามสกุล</th>
                        <th>รายวิชา</th>
                        <th style="text-align: center;">จำนวนครั้งที่ขาด</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($at_risk_students as $student): ?>
                        <tr>
                            <td><?= htmlspecialchars($student['room_name']) ?></td>
                            <td><?= htmlspecialchars($student['student_number']) ?></td>
                            <td><?= htmlspecialchars($student['full_name']) ?></td>
                            <td><?= htmlspecialchars($student['subject_code'] . ' ' . $student['subject_name']) ?></td>
                            <td style="text-align: center; color: var(--danger-color); font-weight: bold;"><?= $student['absent_count'] ?> ครั้ง</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Summary Table Section -->
    <div class="card" style="margin-bottom: 20px; overflow: hidden; max-width: 100%;">
        <h3 class="card-title" style="font-size: 1.1rem; line-height: 1.4; word-wrap: break-word;">รายงานการโฮมรูม (คาบ 0) แบบสรุปรวม - วันที่ <?= date('d/m/Y', strtotime($filter_date)) ?></h3>
        <div class="scroll-table">
            <table class="table">
                <thead>
                    <tr>
                        <th>ระดับชั้น</th>
                        <th style="text-align: center;">ห้องทั้งหมด</th>
                        <th style="text-align: center;">เช็คครบแล้ว (ห้อง)</th>
                        <th style="text-align: center;">นักเรียนทั้งหมด</th>
                        <th style="text-align: center; color: var(--success-color);">มาเรียน/สาย</th>
                        <th style="text-align: center; color: var(--danger-color);">ลา/ขาด</th>
                        <th style="text-align: center; color: #888;">ยังไม่เช็คชื่อ</th>
                        <th style="text-align: center;">% การมาเรียน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($grades as $g): ?>
                        <?php if ($stats[$g]['total_rooms'] > 0): ?>
                            <tr>
                                <td><strong><?= $g ?></strong></td>
                                <td style="text-align: center;"><?= $stats[$g]['total_rooms'] ?></td>
                                <td style="text-align: center;">
                                    <?php if($stats[$g]['checked_rooms'] == $stats[$g]['total_rooms']): ?>
                                        <span class="badge badge-success"><?= $stats[$g]['checked_rooms'] ?> / <?= $stats[$g]['total_rooms'] ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-warning"><?= $stats[$g]['checked_rooms'] ?> / <?= $stats[$g]['total_rooms'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;"><?= $stats[$g]['total_students'] ?></td>
                                <td style="text-align: center; color: var(--success-color); font-weight: 500;">
                                    <?= $stats[$g]['present'] + $stats[$g]['late'] ?>
                                </td>
                                <td style="text-align: center; color: var(--danger-color); font-weight: 500;">
                                    <?= $stats[$g]['leave'] + $stats[$g]['absent'] ?>
                                </td>
                                <td style="text-align: center; color: #888;">
                                    <?= $stats[$g]['missing'] ?>
                                </td>
                                <td style="text-align: center; font-weight: bold;">
                                    <?php 
                                        $total = $stats[$g]['total_students'];
                                        $pl = $stats[$g]['present'] + $stats[$g]['late'];
                                        $pct = $total > 0 ? round(($pl / $total) * 100, 1) : 0;
                                        $color = $pct >= 80 ? 'var(--success-color)' : ($pct >= 50 ? 'orange' : 'var(--danger-color)');
                                    ?>
                                    <span style="color: <?= $color ?>"><?= $pct ?>%</span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Charts Section (Heatmap) -->
    <h2 style="margin: 30px 0 15px; font-size: 1.3rem;">สถิติการมาโรงเรียนของนักเรียน</h2>
    
    <?php
    // Find max rooms
    $max_rooms = 0;
    foreach ($grades as $g) {
        if (isset($school_stats[$g])) {
            $count = count($school_stats[$g]);
            if ($count > $max_rooms) {
                $max_rooms = $count;
            }
        }
    }
    
    // Process Heatmap Data and Missing Reports
    $unreported_rooms = [];
    ?>

    <div class="card" style="padding: 20px; overflow-x: auto; background: white; margin-bottom: 20px;">
        <div style="min-width: <?= 60 + ($max_rooms * 55) ?>px; display: flex; flex-direction: column;">
            
            <!-- Header Row -->
            <div style="display: flex; margin-bottom: 5px;">
                <div style="width: 60px; flex-shrink: 0;"></div>
                <?php for ($i = 1; $i <= $max_rooms; $i++): ?>
                    <div style="flex: 1; text-align: center; font-size: 0.8rem; font-weight: 600; color: #64748b; height: 30px; display: flex; align-items: flex-end; justify-content: center;">
                        <?= $i ?>
                    </div>
                <?php endfor; ?>
            </div>

            <!-- Grade Rows -->
            <?php foreach ($grades as $g): ?>
                <?php if (!isset($school_stats[$g])) continue; ?>
                <div style="display: flex; margin-bottom: 4px;">
                    <div style="width: 60px; flex-shrink: 0; display: flex; align-items: center; justify-content: flex-end; padding-right: 15px; font-weight: bold; font-size: 0.9rem; color: #475569;">
                        <?= $g ?>
                    </div>
                    
                    <?php 
                    $rooms_in_grade = array_values($school_stats[$g]);
                    $room_names = array_keys($school_stats[$g]);
                    
                    for ($i = 1; $i <= $max_rooms; $i++): 
                        $absent_text = '';
                        if (isset($rooms_in_grade[$i-1])) {
                            $room = $rooms_in_grade[$i-1];
                            $cname = $room_names[$i-1];
                            
                            $total = $room['total_students'];
                            $reported = $room['present'] + $room['late'] + $room['leave'] + $room['absent'];
                            
                            if ($reported == 0) {
                                // Not reported
                                $color = '#1e293b'; // Dark color (almost black)
                                // Extract just the room number for brevity, e.g., '1/1' instead of 'ม.1/1'
                                $short_cname = str_replace('ม.', '', $cname);
                                $text = $short_cname;
                            } else {
                                $pl = $room['present'] + $room['late'];
                                $pct = $total > 0 ? round(($pl / $total) * 100, 1) : 0;
                                
                                // Color gradient logic (Red -> Yellow -> Green)
                                // If pct < 50, hue = 0 (Red)
                                // If pct = 75, hue = 60 (Yellow)
                                // If pct = 100, hue = 120 (Green)
                                $hue = max(0, min(120, ($pct - 50) * 2.4));
                                $color = "hsl({$hue}, 75%, 45%)";
                                $text = number_format($pct, 1);
                                $absent_text = number_format(100 - $pct, 1);
                            }
                        } else {
                            $color = 'transparent';
                            $text = '';
                        }
                    ?>
                        <div style="flex: 1; margin: 0 2px; height: 50px; background-color: <?= $color ?>; display: flex; flex-direction: column; align-items: center; justify-content: center; color: white; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); position: relative;">
                            <span style="font-weight: 700; font-size: 0.85rem;"><?= $text ?></span>
                            <?php if ($absent_text !== ''): ?>
                                <span style="position: absolute; bottom: 2px; right: 4px; font-size: 0.6rem; color: rgba(255, 255, 255, 0.7);"><?= $absent_text ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Legend -->
        <div style="margin-top: 25px; display: flex; align-items: center; justify-content: flex-end; gap: 15px; font-size: 0.8rem; color: #64748b;">
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 15px; height: 15px; background: #1e293b; border-radius: 3px;"></div>
                <span>ยังไม่รายงาน</span>
            </div>
            <div style="display: flex; align-items: center; gap: 5px;">
                <div style="width: 60px; height: 15px; background: linear-gradient(to right, hsl(0, 75%, 45%), hsl(60, 75%, 45%), hsl(120, 75%, 45%)); border-radius: 3px;"></div>
                <span>50% - 100%</span>
            </div>
        </div>
    </div>

<?php include '../../includes/footer.php'; ?>
