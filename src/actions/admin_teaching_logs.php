<?php
// src/actions/admin_teaching_logs.php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$error = '';
$success = '';

// Handle Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log_id'])) {
    $delete_id = $_POST['delete_log_id'];
    try {
        // Fetch paths to delete physical files
        $stmt = $pdo->prepare("SELECT image_path, signature_path FROM teaching_logs WHERE id = ?");
        $stmt->execute([$delete_id]);
        $log = $stmt->fetch();
        
        if ($log) {
            $pdo->beginTransaction();
            // Delete attendance records (handled by ON DELETE CASCADE but doing it explicitly is fine)
            $pdo->prepare("DELETE FROM class_attendance WHERE teaching_log_id = ?")->execute([$delete_id]);
            // Delete the log
            $pdo->prepare("DELETE FROM teaching_logs WHERE id = ?")->execute([$delete_id]);
            $pdo->commit();
            
            // Delete physical files
            if (!empty($log['image_path']) && file_exists('../../' . $log['image_path'])) {
                @unlink('../../' . $log['image_path']);
            }
            if (!empty($log['signature_path']) && file_exists('../../' . $log['signature_path'])) {
                @unlink('../../' . $log['signature_path']);
            }
            $success = "ลบข้อมูลการสอนเรียบร้อยแล้ว";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "เกิดข้อผิดพลาดในการลบข้อมูล: " . $e->getMessage();
    }
}

// Fetch Filters
$filter_date = $_GET['date'] ?? '';
$filter_teacher = $_GET['teacher_id'] ?? '';
$filter_room = $_GET['classroom_id'] ?? '';

// Build Query
$query = "
    SELECT tl.*, 
           u.full_name as teacher_name, 
           s.subject_name, s.subject_code,
           c.room_name 
    FROM teaching_logs tl
    LEFT JOIN users u ON tl.teacher_id = u.id
    LEFT JOIN subjects s ON tl.subject_id = s.id
    LEFT JOIN classrooms c ON tl.classroom_id = c.id
    WHERE 1=1
";
$params = [];

if (!empty($filter_date)) {
    $query .= " AND tl.log_date = ?";
    $params[] = $filter_date;
}
if (!empty($filter_teacher)) {
    $query .= " AND tl.teacher_id = ?";
    $params[] = $filter_teacher;
}
if (!empty($filter_room)) {
    $query .= " AND tl.classroom_id = ?";
    $params[] = $filter_room;
}

$query .= " ORDER BY tl.log_date DESC, tl.period ASC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Fetch lists for dropdowns
$teachers = $pdo->query("
    SELECT DISTINCT u.id, u.full_name 
    FROM users u 
    JOIN user_roles ur ON u.id = ur.user_id 
    JOIN roles r ON ur.role_id = r.id 
    WHERE r.role_name = 'teacher' 
    ORDER BY u.full_name
")->fetchAll();
$rooms = $pdo->query("SELECT id, room_name FROM classrooms ORDER BY room_name")->fetchAll();

include '../../includes/header.php';
?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h2 class="page-title" style="margin-bottom: 0;">📝 จัดการบันทึกการสอน (แอดมิน)</h2>
            <a href="admin_dashboard.php" class="btn btn-outline" style="color: #5e5ce6; border-color: #5e5ce6;">⬅️ แดชบอร์ด</a>
        </div>
        <p class="text-muted" style="margin-bottom: 20px;">ค้นหาและลบประวัติการสอนกรณีครูลงบันทึกผิดคาบ เพื่อเคลียร์คาบว่างให้ครูท่านอื่น</p>
        
        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px; padding: 15px; background: #fee2e2; border-left: 4px solid #ef4444; color: #b91c1c;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success" style="margin-bottom: 20px; padding: 15px; background: #dcfce7; border-left: 4px solid #22c55e; color: #15803d;">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-bottom: 20px;">
            <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label>วันที่</label>
                    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label>ครูผู้สอน</label>
                    <select name="teacher_id" class="form-control">
                        <option value="">-- ทุกคน --</option>
                        <?php foreach($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= $filter_teacher == $t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label>ห้องเรียน</label>
                    <select name="classroom_id" class="form-control">
                        <option value="">-- ทุกห้อง --</option>
                        <?php foreach($rooms as $r): ?>
                            <option value="<?= $r['id'] ?>" <?= $filter_room == $r['id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['room_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">🔍 ค้นหา</button>
                <a href="admin_teaching_logs.php" class="btn btn-outline">ล้างค่า</a>
            </form>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>วันที่ / คาบ</th>
                            <th>ครูผู้สอน</th>
                            <th>วิชา</th>
                            <th>ห้องเรียน</th>
                            <th>เนื้อหาที่สอน</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td>
                                <strong><?= date('d/m/Y', strtotime($log['log_date'])) ?></strong><br>
                                <span class="text-muted">คาบ <?= htmlspecialchars($log['period']) ?></span>
                            </td>
                            <td><?= htmlspecialchars($log['teacher_name'] ?? 'ไม่ทราบชื่อ') ?></td>
                            <td><?= htmlspecialchars($log['subject_code'] . ' ' . $log['subject_name']) ?></td>
                            <td><?= htmlspecialchars($log['room_name'] ?? '-') ?></td>
                            <td>
                                <div style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.9rem;" title="<?= htmlspecialchars($log['teaching_report_text']) ?>">
                                    <?= htmlspecialchars($log['teaching_report_text'] ?: '-') ?>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px;">
                                    <a href="edit_teaching_log_time.php?log_id=<?= $log['id'] ?>" class="btn btn-sm btn-outline" style="padding: 4px 10px;">🔄 ย้าย</a>
                                    <form method="POST" onsubmit="return confirm('⚠️ ยืนยันการลบข้อมูลการสอนนี้?\nข้อมูลการเช็คชื่อของคาบนี้จะหายไปด้วย');" style="margin: 0;">
                                        <input type="hidden" name="delete_log_id" value="<?= $log['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline" style="color: #ef4444; border-color: #ef4444; padding: 4px 10px;">🗑️ ลบ</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">ไม่พบข้อมูลประวัติการสอน</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-muted" style="font-size: 0.85rem; margin-top: 10px;">* แสดงผลสูงสุด 100 รายการล่าสุด โปรดใช้การค้นหาด้านบนหากต้องการดูข้อมูลเก่า</div>
        </div>
    
<?php include '../../includes/footer.php'; ?>
