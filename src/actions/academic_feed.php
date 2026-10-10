<?php
session_start();
require_once '../../config/database.php';

// Check roles
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('academic', $user_roles) && !in_array('executive', $user_roles) && !in_array('admin', $user_roles) && !in_array('teacher', $user_roles)) {
    echo "Access Denied";
    exit();
}

include '../../includes/header.php';

$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_department = $_GET['department'] ?? '';
$search_name = $_GET['search_name'] ?? '';

$is_executive = in_array('executive', $user_roles) || in_array('academic', $user_roles) || in_array('admin', $user_roles);
if (isset($_GET['view']) && $_GET['view'] === 'teacher') {
    $is_executive = false;
}

// --- For Summary Table (Executive only) ---
$departments = [];
$teachers = [];
$reported_count = 0;
$not_reported_count = 0;

if ($is_executive) {
    $dept_stmt = $pdo->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department ASC");
    $departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);

    $sql_summary = "
        SELECT 
            u.id AS teacher_id, 
            u.full_name, 
            u.department,
            COUNT(tl.id) as log_count
        FROM users u
        JOIN user_roles ur ON u.id = ur.user_id
        JOIN roles r ON ur.role_id = r.id AND r.role_name = 'teacher'
        LEFT JOIN teaching_logs tl ON u.id = tl.teacher_id AND tl.log_date = :filter_date
        WHERE 1=1
    ";
    
    $params_summary = ['filter_date' => $filter_date];
    if ($filter_department) {
        $sql_summary .= " AND u.department = :filter_department";
        $params_summary['filter_department'] = $filter_department;
    }
    if ($search_name) {
        $sql_summary .= " AND u.full_name LIKE :search_name";
        $params_summary['search_name'] = '%' . $search_name . '%';
    }
    $sql_summary .= " GROUP BY u.id, u.full_name, u.department ORDER BY u.department ASC, u.full_name ASC";
    
    $stmt_sum = $pdo->prepare($sql_summary);
    $stmt_sum->execute($params_summary);
    $teachers = $stmt_sum->fetchAll(PDO::FETCH_ASSOC);

    foreach ($teachers as $t) {
        if ($t['log_count'] > 0) $reported_count++;
    }
    $not_reported_count = count($teachers) - $reported_count;
}

// --- For Timeline Feed ---
$logs = [];
try {
    $sql_feed = "
        SELECT tl.*, t.full_name, s.subject_code, s.subject_name, c.room_name 
        FROM teaching_logs tl
        JOIN users t ON tl.teacher_id = t.id
        LEFT JOIN subjects s ON tl.subject_id = s.id
        LEFT JOIN classrooms c ON tl.classroom_id = c.id
        WHERE tl.log_date = :filter_date
    ";
    $params_feed = ['filter_date' => $filter_date];
    
    if ($filter_department) {
        $sql_feed .= " AND t.department = :filter_department";
        $params_feed['filter_department'] = $filter_department;
    }
    if ($search_name) {
        $sql_feed .= " AND t.full_name LIKE :search_name";
        $params_feed['search_name'] = '%' . $search_name . '%';
    }
    
    $sql_feed .= " ORDER BY tl.created_at DESC";
    
    $stmt = $pdo->prepare($sql_feed);
    $stmt->execute($params_feed);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

<div>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin-bottom: 0;">ภาพรวมการจัดการเรียนการสอน (Timeline)</h2>
        <?php
        $back_link = 'academic_dashboard.php';
        if ((isset($_GET['view']) && $_GET['view'] === 'teacher') || (in_array('teacher', $user_roles) && !in_array('academic', $user_roles) && !in_array('executive', $user_roles) && !in_array('admin', $user_roles))) {
            $back_link = 'teacher_home.php';
        } elseif (in_array('admin', $user_roles) && !in_array('academic', $user_roles) && !in_array('executive', $user_roles)) {
            $back_link = 'admin_dashboard.php';
        }
        ?>
        <a href="<?= $back_link ?>" class="btn btn-outline">◀ กลับหน้าแดชบอร์ดหลัก</a>
    </div>

    <!-- Filter Bar -->
    <div class="card" style="margin-bottom: 20px; padding: 15px;">
        <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <?php if (isset($_GET['view'])): ?>
                <input type="hidden" name="view" value="<?= htmlspecialchars($_GET['view']) ?>">
            <?php endif; ?>
            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 150px;">
                <label>วันที่</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>" onchange="this.form.submit()">
            </div>
            
            <?php if ($is_executive): ?>
            <div class="form-group" style="margin-bottom: 0; flex: 1; min-width: 180px;">
                <label>กลุ่มสาระฯ</label>
                <select name="department" class="form-control" onchange="this.form.submit()">
                    <option value="">ทั้งหมด</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>" <?= $filter_department == $dept ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom: 0; flex: 1.5; min-width: 200px;">
                <label>ค้นหาชื่อครู</label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" name="search_name" value="<?= htmlspecialchars($search_name) ?>" placeholder="ชื่อ/นามสกุล..." class="form-control">
                    <button type="submit" class="btn btn-primary" style="white-space: nowrap;">🔍 ค้นหา</button>
                </div>
            </div>
            <?php else: ?>
            <div class="form-group" style="margin-bottom: 0;">
                <button type="submit" class="btn btn-primary">เรียกดูสถิติ</button>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($is_executive): ?>
    <!-- Summary Table (Executive Only) -->
    <div class="card" style="margin-bottom: 30px; padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 15px;">
            <h3 style="margin: 0; color: #1e3a8a; font-size: 1.2rem;">📋 สรุปการส่งบันทึกของครู</h3>
            <div style="display: flex; gap: 15px;">
                <div style="background: #ecfdf5; padding: 5px 12px; border-radius: 6px; border: 1px solid #a7f3d0; color: #065f46; font-size: 0.9rem; font-weight: 600;">
                    🟢 ส่งแล้ว: <?= $reported_count ?> คน
                </div>
                <div style="background: #fef2f2; padding: 5px 12px; border-radius: 6px; border: 1px solid #fecaca; color: #991b1b; font-size: 0.9rem; font-weight: 600;">
                    🔴 ยังไม่ส่ง: <?= $not_reported_count ?> คน
                </div>
            </div>
        </div>
        
        <div class="table-responsive" style="max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                <thead style="position: sticky; top: 0; background: #f8fafc; z-index: 1;">
                    <tr style="border-bottom: 2px solid #cbd5e1;">
                        <th style="padding: 10px; text-align: left;">ชื่อ - นามสกุล</th>
                        <th style="padding: 10px; text-align: left;">กลุ่มสาระฯ</th>
                        <th style="padding: 10px; text-align: center;">สถานะ</th>
                        <th style="padding: 10px; text-align: center;">แอคชัน</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($teachers)): ?>
                        <tr><td colspan="4" class="text-center" style="padding: 20px;">ไม่พบข้อมูล</td></tr>
                    <?php else: ?>
                        <?php foreach ($teachers as $t): ?>
                            <tr style="border-bottom: 1px solid #e2e8f0;">
                                <td style="padding: 10px; font-weight: 500;"><?= htmlspecialchars($t['full_name']) ?></td>
                                <td style="padding: 10px; color: #64748b;"><?= htmlspecialchars($t['department'] ?: '-') ?></td>
                                <td style="padding: 10px; text-align: center;">
                                    <?php if ($t['log_count'] > 0): ?>
                                        <span style="color: #059669; font-weight: bold;">🟢 (<?= $t['log_count'] ?> คาบ)</span>
                                    <?php else: ?>
                                        <span style="color: #dc2626; font-weight: bold;">🔴 ยังไม่ส่ง</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px; text-align: center;">
                                    <?php if ($t['log_count'] > 0): ?>
                                        <a href="?date=<?= $filter_date ?>&department=<?= urlencode($filter_department) ?>&search_name=<?= urlencode($t['full_name']) ?>#timeline-feed" class="btn btn-sm" style="background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; font-size: 0.8rem; padding: 4px 8px;">
                                            ดู Timeline
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div id="timeline-feed"></div>
    <?php endif; ?>

    <?php if (empty($logs)): ?>
        <div style="text-align: center; padding: 60px 20px; opacity: 0.5;">
            <div style="font-size: 4rem; margin-bottom: 20px;">📭</div>
            <h3>ยังไม่มีบันทึกการสอนในวันนี้</h3>
            <p>เมื่อครูกดส่งรายงานสอน ข้อมูลจะไหลเข้ามาที่นี่แบบ Realtime</p>
        </div>
    <?php else: ?>
        <div style="max-width: 800px; margin: 0 auto;">
            <?php foreach($logs as $log): ?>
                <div class="card" style="margin-bottom: 20px; padding: 25px;">
                    <!-- Head: Profile & Meta -->
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                        <div style="width: 50px; height: 50px; background-color: var(--primary-color); color: white; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 1.5rem; font-weight: bold;">
                            <?= mb_substr($log['full_name'], 0, 1) ?>
                        </div>
                        <div>
                            <strong style="font-size: 1.1rem; display: block;"><?= htmlspecialchars($log['full_name']) ?></strong>
                            <span style="font-size: 0.85rem; color: var(--text-muted);">
                                🕒 ส่งบันทึกเมื่อ: <?= date('H:i', strtotime($log['created_at'])) ?> น.
                            </span>
                        </div>
                    </div>

                    <!-- Context: Subject & Room -->
                    <div style="background-color: #f8fafc; border-radius: 8px; padding: 12px; margin-bottom: 15px; border: 1px solid var(--border-color); display: flex; gap: 15px; flex-wrap: wrap;">
                        <div>
                            <span style="color: var(--text-muted); font-size: 0.85rem;">คาบที่</span><br>
                            <strong><?= htmlspecialchars($log['period']) ?></strong>
                        </div>
                        <div style="border-left: 1px solid #ccc; padding-left: 15px;">
                            <span style="color: var(--text-muted); font-size: 0.85rem;">วิชา</span><br>
                            <strong><?= htmlspecialchars($log['subject_code'] . ' ' . $log['subject_name']) ?></strong>
                        </div>
                        <div style="border-left: 1px solid #ccc; padding-left: 15px;">
                            <span style="color: var(--text-muted); font-size: 0.85rem;">ห้องเรียน</span><br>
                            <strong><?= htmlspecialchars($log['room_name']) ?></strong>
                        </div>
                    </div>

                    <!-- Content Text -->
                    <div style="margin-bottom: 15px; font-size: 1rem; line-height: 1.6; white-space: pre-wrap;"><?= htmlspecialchars($log['teaching_report_text']) ?></div>

                    <!-- Image Content -->
                    <?php if (!empty($log['image_path'])): ?>
                        <div style="border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color); position: relative; display: inline-block; max-width: 320px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                            <img src="../../<?= htmlspecialchars($log['image_path']) ?>" alt="Teaching Log Image" style="width: 100%; height: auto; display: block;">
                            
                            <?php if (in_array('executive', $user_roles) || in_array('academic', $user_roles) || in_array('admin', $user_roles)): ?>
                                <?php 
                                    $is_suspicious = false;
                                    if (empty($log['image_capture_time'])) {
                                        $is_suspicious = true;
                                    } else {
                                        $capture_time = strtotime($log['image_capture_time']);
                                        $upload_time = strtotime($log['created_at']);
                                        // ถ่ายก่อนเวลาส่งรายงานเกิน 48 ชั่วโมง
                                        if ($capture_time < ($upload_time - (48 * 3600))) {
                                            $is_suspicious = true;
                                        }
                                        // ป้องกันเวลามั่วไปในอนาคตมากเกินไป
                                        if ($capture_time > ($upload_time + (24 * 3600))) {
                                            $is_suspicious = true;
                                        }
                                    }
                                ?>
                                <?php if ($is_suspicious): ?>
                                <span style="position: absolute; top: 10px; right: 10px; color: red; font-weight: bold; font-size: 32px; text-shadow: 1px 1px 2px rgba(255,255,255,0.8), -1px -1px 0 rgba(255,255,255,0.8), 1px -1px 0 rgba(255,255,255,0.8), -1px 1px 0 rgba(255,255,255,0.8); line-height: 1; pointer-events: none;">**</span>
                                <?php endif; ?>
                                
                                <?php if (in_array('admin', $user_roles)): ?>
                                <!-- Secret EXIF time for Admin only -->
                                <div style="position: absolute; bottom: 8px; right: 8px; background: rgba(0,0,0,0.7); color: white; padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; border: 1px solid rgba(255,255,255,0.2); pointer-events: none; white-space: nowrap;">
                                    🕵️‍♂️ 
                                    <?php if (!empty($log['image_capture_time'])): ?>
                                        ถ่าย: <?= date('H:i', strtotime($log['image_capture_time'])) ?>
                                    <?php else: ?>
                                        ไม่มี EXIF (ภาพแคป)
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
