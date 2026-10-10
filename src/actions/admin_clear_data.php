<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';

requireRole($pdo, 'admin');

$page_title = "ล้างข้อมูลระบบ";
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'clear_students') {
            // Delete all students
            $pdo->exec("DELETE FROM students");
            $pdo->exec("ALTER TABLE students AUTO_INCREMENT = 1");
            $success_msg = "ล้างข้อมูลนักเรียนทั้งหมดเรียบร้อยแล้ว";
            
        } elseif ($action === 'clear_subjects') {
            // Delete all subjects
            $pdo->exec("DELETE FROM subjects");
            $pdo->exec("ALTER TABLE subjects AUTO_INCREMENT = 1");
            $success_msg = "ล้างข้อมูลรายวิชาทั้งหมดเรียบร้อยแล้ว";
            
        } elseif ($action === 'clear_classrooms') {
            // Delete all classrooms
            $pdo->exec("DELETE FROM classrooms");
            $pdo->exec("ALTER TABLE classrooms AUTO_INCREMENT = 1");
            $success_msg = "ล้างข้อมูลห้องเรียนทั้งหมดเรียบร้อยแล้ว";
            
        } elseif ($action === 'clear_teaching_loads') {
            // Clear teaching loads (schedules, teacher_subjects, homeroom_teachers)
            $pdo->exec("DELETE FROM schedules");
            $pdo->exec("DELETE FROM teacher_subjects");
            $pdo->exec("DELETE FROM homeroom_teachers");
            $pdo->exec("ALTER TABLE schedules AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE teacher_subjects AUTO_INCREMENT = 1");
            $success_msg = "ล้างข้อมูลภาระงานสอนทั้งหมดเรียบร้อยแล้ว";
            
        } elseif ($action === 'clear_teachers') {
            // Delete users who are teachers but not admins
            $stmt = $pdo->prepare("
                DELETE FROM users 
                WHERE id IN (
                    SELECT user_id FROM user_roles ur 
                    JOIN roles r ON ur.role_id = r.id 
                    WHERE r.role_name = 'teacher'
                ) 
                AND id NOT IN (
                    SELECT user_id FROM user_roles ur2 
                    JOIN roles r2 ON ur2.role_id = r2.id 
                    WHERE r2.role_name = 'admin'
                )
            ");
            $stmt->execute();
            $success_msg = "ล้างข้อมูลครูผู้สอนทั้งหมดเรียบร้อยแล้ว (ไม่รวมผู้ดูแลระบบ)";
        }
        
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error_msg = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

include '../../includes/header.php';
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2 class="card-title text-danger" style="margin-bottom: 0;">⚠️ ล้างข้อมูลระบบ (Clear Data)</h2>
        <a href="admin_dashboard.php" class="btn btn-outline" style="color: #5e5ce6; border-color: #5e5ce6;">⬅️ แดชบอร์ด</a>
    </div>
    <p class="text-muted mb-4">ส่วนนี้ใช้สำหรับล้างข้อมูลในระบบเพื่อเตรียมพร้อมสำหรับปีการศึกษาใหม่ หรือเริ่มต้นใช้งานใหม่ <strong>โปรดระมัดระวังเนื่องจากข้อมูลที่ถูกลบจะไม่สามารถกู้คืนได้</strong></p>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-top: 20px;">
        
        <!-- ล้างข้อมูลนักเรียน -->
        <div style="border: 1px solid var(--border-color); padding: 20px; border-radius: 8px; text-align: center;">
            <h3 style="color: var(--danger-color); margin-bottom: 10px;">👨‍🎓 ล้างข้อมูลนักเรียน</h3>
            <p style="font-size: 0.9rem; color: var(--secondary-color); margin-bottom: 20px;">
                ลบรายชื่อนักเรียนทั้งหมด รวมถึงประวัติการเช็คชื่อของนักเรียนทุกคน
            </p>
            <form method="POST" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการล้างข้อมูลนักเรียนทั้งหมด? การกระทำนี้ไม่สามารถย้อนกลับได้');">
                <input type="hidden" name="action" value="clear_students">
                <button type="submit" class="btn btn-danger" style="width: 100%;">ล้างข้อมูลนักเรียน</button>
            </form>
        </div>

        <!-- ล้างข้อมูลรายวิชา -->
        <div style="border: 1px solid var(--border-color); padding: 20px; border-radius: 8px; text-align: center;">
            <h3 style="color: var(--danger-color); margin-bottom: 10px;">📚 ล้างข้อมูลรายวิชา</h3>
            <p style="font-size: 0.9rem; color: var(--secondary-color); margin-bottom: 20px;">
                ลบรายวิชาทั้งหมดในระบบ รวมถึงตารางสอนที่ผูกกับวิชาเหล่านั้น
            </p>
            <form method="POST" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการล้างข้อมูลรายวิชาทั้งหมด? การกระทำนี้ไม่สามารถย้อนกลับได้');">
                <input type="hidden" name="action" value="clear_subjects">
                <button type="submit" class="btn btn-danger" style="width: 100%;">ล้างข้อมูลรายวิชา</button>
            </form>
        </div>

        <!-- ล้างข้อมูลภาระงานสอน -->
        <div style="border: 1px solid var(--border-color); padding: 20px; border-radius: 8px; text-align: center;">
            <h3 style="color: var(--danger-color); margin-bottom: 10px;">🎓 ล้างภาระงานสอน</h3>
            <p style="font-size: 0.9rem; color: var(--secondary-color); margin-bottom: 20px;">
                ลบการมอบหมายวิชาสอน ตารางสอน และการเป็นที่ปรึกษาโฮมรูมของครูทั้งหมด
            </p>
            <form method="POST" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการล้างภาระงานสอนทั้งหมด? ตารางสอนของครูทุกคนจะถูกลบ');">
                <input type="hidden" name="action" value="clear_teaching_loads">
                <button type="submit" class="btn btn-danger" style="width: 100%;">ล้างภาระงานสอน</button>
            </form>
        </div>

        <!-- ล้างข้อมูลครู -->
        <div style="border: 1px solid var(--border-color); padding: 20px; border-radius: 8px; text-align: center;">
            <h3 style="color: var(--danger-color); margin-bottom: 10px;">👨‍🏫 ล้างข้อมูลครู</h3>
            <p style="font-size: 0.9rem; color: var(--secondary-color); margin-bottom: 20px;">
                ลบบัญชีผู้ใช้ครูทั้งหมด (ยกเว้นผู้ดูแลระบบ) รวมถึงตารางสอนและประวัติการบันทึกข้อมูล
            </p>
            <form method="POST" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการล้างข้อมูลครูทั้งหมด? บัญชีครูจะถูกลบทิ้ง (ยกเว้นแอดมิน)');">
                <input type="hidden" name="action" value="clear_teachers">
                <button type="submit" class="btn btn-danger" style="width: 100%;">ล้างข้อมูลครู</button>
            </form>
        </div>

        <!-- ล้างข้อมูลห้องเรียน -->
        <div style="border: 1px solid var(--border-color); padding: 20px; border-radius: 8px; text-align: center;">
            <h3 style="color: var(--danger-color); margin-bottom: 10px;">🏫 ล้างข้อมูลห้องเรียน</h3>
            <p style="font-size: 0.9rem; color: var(--secondary-color); margin-bottom: 20px;">
                ลบข้อมูลห้องเรียนทั้งหมด (รวมถึงตารางสอนและที่ปรึกษาที่ผูกกับห้องเรียน)
            </p>
            <form method="POST" onsubmit="return confirm('คุณแน่ใจหรือไม่ว่าต้องการล้างข้อมูลห้องเรียนทั้งหมด? ตารางสอนและการผูกห้องเรียนต่างๆ จะถูกลบด้วย');">
                <input type="hidden" name="action" value="clear_classrooms">
                <button type="submit" class="btn btn-danger" style="width: 100%;">ล้างข้อมูลห้องเรียน</button>
            </form>
        </div>

    </div>
</div>

<style>
    .btn-danger {
        background-color: #dc3545;
        color: white;
    }
    .btn-danger:hover {
        background-color: #c82333;
    }
    .text-danger {
        color: #dc3545;
    }
    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid transparent;
        border-radius: 4px;
    }
    .alert-success {
        color: #155724;
        background-color: #d4edda;
        border-color: #c3e6cb;
    }
    .alert-danger {
        color: #721c24;
        background-color: #f8d7da;
        border-color: #f5c6cb;
    }
</style>

<?php include '../../includes/footer.php'; ?>
