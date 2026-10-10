<?php
session_start();
require_once '../../config/database.php';

// Check roles
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('executive', $user_roles) && !in_array('admin', $user_roles)) {
    echo "Access Denied";
    exit();
}

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

// 3. Get Teacher Attendance Summary
$teacher_att = ['total' => 0, 'on_time' => 0, 'late' => 0, 'missing' => 0];
try {
    $ta_stmt = $pdo->prepare("
        SELECT 
            COUNT(u.id) as total_teachers,
            SUM(CASE WHEN ta.time_in_status = 'on_time' THEN 1 ELSE 0 END) as on_time,
            SUM(CASE WHEN ta.time_in_status = 'late' THEN 1 ELSE 0 END) as late,
            SUM(CASE WHEN ta.time_in IS NULL THEN 1 ELSE 0 END) as missing
        FROM users u
        JOIN user_roles ur ON u.id = ur.user_id
        JOIN roles r ON ur.role_id = r.id AND r.role_name = 'teacher'
        LEFT JOIN teacher_attendance ta ON u.id = ta.user_id AND ta.work_date = ?
    ");
    $ta_stmt->execute([$filter_date]);
    if ($row = $ta_stmt->fetch()) {
        $teacher_att = [
            'total' => (int)$row['total_teachers'],
            'on_time' => (int)$row['on_time'],
            'late' => (int)$row['late'],
            'missing' => (int)$row['missing']
        ];
    }
} catch (Exception $e) {}

// 4. Get Student Late Records (Detailed list for dashboard)
$filter_level = $_GET['filter_level'] ?? '';
$filter_room = $_GET['filter_room'] ?? '';
$recent_records = [];

try {
    $stmt_recent = $pdo->prepare("
        SELECT br.id, br.record_date, s.student_code, s.full_name, c.room_name, br.points, br.reason, br.created_at
        FROM behavior_records br
        JOIN students s ON br.student_id = s.id
        LEFT JOIN classrooms c ON s.classroom_id = c.id
        WHERE (br.reason LIKE 'มาสาย%' OR br.reason LIKE '%สาย%') AND br.status = 'approved' AND br.record_date = :f_date
        " . ($filter_level ? " AND c.grade_level = :f_level " : "") . "
        " . ($filter_room ? " AND c.room_name = :f_room " : "") . "
        ORDER BY br.created_at DESC
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
    
    // Also fetch all rooms for the filter dropdown
    $stmt_rooms = $pdo->query("SELECT DISTINCT room_name FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC");
    $all_rooms = $stmt_rooms->fetchAll(PDO::FETCH_COLUMN);
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
        <h2 class="card-title" style="margin-bottom: 0;">ภาพรวมโรงเรียน (แดชบอร์ดผู้บริหาร)</h2>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 30px;">
        <!-- Card 1: ไทม์ไลน์การสอน -->
        <a href="academic_feed.php" class="cute-card" style="background-color: #eff6ff;">
            <div class="cute-icon">🕒</div>
            <h3 class="cute-title">ไทม์ไลน์การสอน</h3>
            <p class="cute-desc">ตรวจสอบการเข้าสอนแบบเรียลไทม์</p>
        </a>

        <!-- Card 2: ลงเวลาบุคลากร -->
        <a href="personnel_attendance_report.php" class="cute-card" style="background-color: #fffbeb;">
            <div class="cute-icon">👥</div>
            <h3 class="cute-title">ลงเวลาบุคลากร</h3>
            <p class="cute-desc" style="font-weight: 500; color: #b45309;">มา <?= $teacher_att['on_time'] ?> | สาย <?= $teacher_att['late'] ?> | ยืนยันแล้ว <?= $teacher_att['on_time'] + $teacher_att['late'] ?>/<?= $teacher_att['total'] ?></p>
        </a>
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

    <!-- Recent Late Table -->
    <div class="modern-card card" style="padding: 20px; margin-bottom: 30px;">
        <div class="header-flex" style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #831843; font-size: 1.2rem; font-weight: 800;">📋 บันทึกมาสายล่าสุด <span style="font-size: 1rem; color: #db2777; font-weight: 600;">(<?= $filter_date == date('Y-m-d') ? 'วันนี้' : date('d/m/Y', strtotime($filter_date)) ?>)</span></h3>
            <form method="GET" class="filter-flex" style="display: flex; gap: 10px; align-items: center; width: 100%; flex-wrap: wrap; padding-bottom: 5px;">
                <input type="hidden" name="date" value="<?= htmlspecialchars($filter_date) ?>">
                
                <div style="display: flex; align-items: center; gap: 6px; flex: 1; min-width: 140px;">
                    <label for="filter_level" style="font-size: 0.85rem; color: #9d174d; font-weight: 700; white-space: nowrap;">ระดับชั้น:</label>
                    <select name="filter_level" id="filter_level" class="form-control" style="padding: 8px 12px; border-radius: 12px; border: 1px solid #f9a8d4; outline: none; color: #831843; font-size: 0.95rem; width: 100%; background: #fdf2f8;" onchange="this.form.submit()">
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
                    <label for="filter_room" style="font-size: 0.85rem; color: #9d174d; font-weight: 700; white-space: nowrap;">ห้อง:</label>
                    <select name="filter_room" id="filter_room" class="form-control" style="padding: 8px 12px; border-radius: 12px; border: 1px solid #f9a8d4; outline: none; color: #831843; font-size: 0.95rem; width: 100%; background: #fdf2f8;" onchange="this.form.submit()">
                        <option value="">ทุกห้อง</option>
                        <?php foreach($all_rooms as $rm): ?>
                            <option value="<?= htmlspecialchars($rm) ?>" <?= $filter_room === $rm ? 'selected' : '' ?>><?= htmlspecialchars($rm) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <a href="print_inspector_late.php?date=<?= $filter_date ?>&level=<?= urlencode($filter_level) ?>&room=<?= urlencode($filter_room) ?>" target="_blank" class="btn btn-sm" style="background: #e11d48; color: white; border: none; border-radius: 12px; padding: 8px 16px; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 6px; flex-shrink: 0; box-shadow: 0 4px 10px rgba(225,29,72,0.2);">
                    🖨️ <span class="d-none d-sm-inline">ส่งออก PDF</span>
                </a>
            </form>
        </div>
        
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
            <table class="table" style="margin-bottom: 0;">
                <thead style="position: sticky; top: 0; z-index: 10;">
                    <tr style="background: #fdf2f8; color: #831843;">
                        <th style="border: none; padding: 12px;">เวลา</th>
                        <th style="border: none; padding: 12px;">รหัส</th>
                        <th style="border: none; padding: 12px;">ชื่อ-สกุล</th>
                        <th style="border: none; padding: 12px;">ห้อง</th>
                        <th style="border: none; padding: 12px;">คะแนน</th>
                        <th style="border: none; padding: 12px;">ประเภท</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($recent_records)): ?>
                        <?php foreach($recent_records as $r): ?>
                        <tr style="border-bottom: 1px solid #fce7f3;">
                            <td style="padding: 12px; color: #64748b;"><?= date('H:i', strtotime($r['created_at'])) ?> น.</td>
                            <td style="padding: 12px; color: #334155;"><?= htmlspecialchars($r['student_code']) ?></td>
                            <td style="padding: 12px; color: #1e293b; font-weight: 500;"><?= htmlspecialchars($r['full_name']) ?></td>
                            <td style="padding: 12px; color: #334155;"><?= htmlspecialchars($r['room_name']) ?></td>
                            <td style="padding: 12px;"><span style="background: #fef3c7; color: #d97706; padding: 4px 10px; border-radius: 12px; font-weight: 700; font-size: 0.8rem;"><?= $r['points'] ?> แต้ม</span></td>
                            <td style="padding: 12px; color: #3b82f6; font-size: 0.9rem;">
                                <?php 
                                    $display_reason = $r['reason'];
                                    if (strpos($display_reason, 'มาสาย (07.50') !== false) {
                                        echo 'สาย';
                                    } elseif (strpos($display_reason, 'มาสายเกินเวลา') !== false) {
                                        echo 'สายมาก';
                                    } else {
                                        echo htmlspecialchars($display_reason);
                                    }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 30px; color: #be185d; font-weight: 500;">ยังไม่มีข้อมูลการมาสายใน วันนี้</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

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

    <!-- Charts Section -->
    <h2 style="margin: 30px 0 15px; font-size: 1.3rem;">กราฟแสดงอัตราการมาเรียนรายห้องเรียน (แยกตามระดับชั้น)</h2>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 20px;">
        <?php foreach($grades as $index => $g): ?>
            <?php if (isset($school_stats[$g]) && count($school_stats[$g]) > 0): ?>
                <div class="card" style="margin-bottom: 0;">
                    <h3 class="card-title" style="font-size: 1.1rem; border-bottom: 2px solid var(--primary-color); padding-bottom: 10px; display:inline-block;">ชั้น <?= $g ?></h3>
                    <div style="height: 250px; width: 100%;">
                        <canvas id="chart-<?= $index ?>"></canvas>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach($grades as $index => $g): ?>
        <?php if (isset($school_stats[$g]) && count($school_stats[$g]) > 0): 
            $labels = [];
            $att_data = [];
            $absent_data = [];
            foreach ($school_stats[$g] as $cname => $room) {
                $pl = $room['present'] + $room['late'];
                $la = $room['leave'] + $room['absent'];
                
                // Shorten labels from "ม.1/1" to "1" or "1/1" for chart readability
                $label = str_replace($g.'/', '', $cname);
                $label = str_replace('ม.', '', $label); // Fallback
                $labels[] = $label;
                
                if (($pl + $la) == 0) {
                    // Not reported yet: no bars
                    $att_data[] = 0;
                    $absent_data[] = 0;
                } else {
                    $t = $room['total_students'];
                    $pct = $t > 0 ? round(($pl / $t) * 100, 1) : 0;
                    $att_data[] = $pct;
                    $absent_data[] = 100 - $pct;
                }
            }
        ?>
        const ctx<?= $index ?> = document.getElementById('chart-<?= $index ?>').getContext('2d');
        new Chart(ctx<?= $index ?>, {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels) ?>,
                datasets: [
                    {
                        label: '% มา/สาย',
                        data: <?= json_encode($att_data) ?>,
                        backgroundColor: 'rgba(76, 175, 80, 0.8)',
                        borderRadius: 4
                    },
                    {
                        label: '% ลา/ขาด',
                        data: <?= json_encode($absent_data) ?>,
                        backgroundColor: '#9ca3af',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                layout: {
                    padding: { top: 20 }
                },
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: { font: { size: 10 } }
                    },
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + Number(context.raw).toFixed(1) + '%';
                            }
                        }
                    }
                }
            },
            plugins: [{
                id: 'customDataLabels',
                afterDatasetsDraw: function(chart) {
                    const ctx = chart.ctx;
                    chart.data.datasets.forEach((dataset, i) => {
                        const meta = chart.getDatasetMeta(i);
                        if (!meta.hidden) {
                            meta.data.forEach((element, index) => {
                                const data = dataset.data[index];
                                if (data > 0) {
                                    ctx.fillStyle = i === 0 ? '#15803d' : '#4b5563'; // Dark green or dark gray
                                    ctx.font = 'bold 9px sans-serif';
                                    ctx.textAlign = 'center';
                                    ctx.textBaseline = 'bottom';
                                    const position = element.tooltipPosition();
                                    ctx.fillText(Number(data).toFixed(1) + '%', position.x, position.y - 2);
                                }
                            });
                        }
                    });
                }
            }]
        });
        <?php endif; ?>
    <?php endforeach; ?>
});
</script>

<?php include '../../includes/footer.php'; ?>
