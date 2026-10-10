<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
$teacher_id = $_SESSION['user_id'] ?? 0;

if (!in_array('teacher', $user_roles) && !in_array('admin', $user_roles)) {
    echo "Access Denied";
    exit();
}

$page_title = "รายงาน";
include '../../includes/header.php';
?>

<div class="card" style="border-radius: 14px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); background: white;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px;">
        <h2 style="margin: 0; font-size: 1.3rem; color: #1e293b; display: flex; align-items: center; gap: 10px; font-weight: 700;">
            <div style="background: var(--primary-color); width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; box-shadow: 0 4px 10px rgba(var(--primary-rgb), 0.3);">
                <span class="material-symbols-rounded">assessment</span>
            </div>
            เมนูรายงาน
        </h2>
    </div>

    <div class="quick-actions" style="grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px;">
        
        <a href="teacher_report_subject.php" class="quick-action-btn" style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; transition: all 0.2s ease;">
            <div class="action-icon" style="background: #e0f2fe; color: #0284c7; width: 50px; height: 50px; font-size: 26px;">
                <span class="material-symbols-rounded" style="font-size: 28px;">menu_book</span>
            </div>
            <span class="action-label" style="font-size: 1rem; font-weight: 600; color: #334155; margin-top: 5px;">สรุปการเข้าเรียนรายวิชา</span>
            <span style="font-size: 0.75rem; color: #64748b; margin-top: 5px; font-weight: normal;">รายงานการเช็คชื่อตามรายวิชาที่สอน</span>
        </a>

        <a href="teacher_homeroom_log.php" class="quick-action-btn" style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; transition: all 0.2s ease;">
            <div class="action-icon" style="background: #fef3c7; color: #d97706; width: 50px; height: 50px; font-size: 26px;">
                <span class="material-symbols-rounded" style="font-size: 28px;">description</span>
            </div>
            <span class="action-label" style="font-size: 1rem; font-weight: 600; color: #334155; margin-top: 5px;">บันทึกประจำห้องที่ปรึกษา</span>
            <span style="font-size: 0.75rem; color: #64748b; margin-top: 5px; font-weight: normal;">สมุดบันทึกประจำห้องเรียน (ปพ.5/โฮมรูม)</span>
        </a>

        <a href="teacher_report_homeroom.php" class="quick-action-btn" style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; transition: all 0.2s ease;">
            <div class="action-icon" style="background: #dcfce7; color: #16a34a; width: 50px; height: 50px; font-size: 26px;">
                <span class="material-symbols-rounded" style="font-size: 28px;">groups</span>
            </div>
            <span class="action-label" style="font-size: 1rem; font-weight: 600; color: #334155; margin-top: 5px;">สรุปนักเรียนเข้าแถว</span>
            <span style="font-size: 0.75rem; color: #64748b; margin-top: 5px; font-weight: normal;">รายงานการเข้าแถวของห้องที่ปรึกษา</span>
        </a>

        <a href="teacher_report_late_stats.php" class="quick-action-btn" style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; transition: all 0.2s ease;">
            <div class="action-icon" style="background: #fee2e2; color: #ef4444; width: 50px; height: 50px; font-size: 26px;">
                <span class="material-symbols-rounded" style="font-size: 28px;">schedule</span>
            </div>
            <span class="action-label" style="font-size: 1rem; font-weight: 600; color: #334155; margin-top: 5px;">จำนวนครั้งที่มาสายรายบุคคล</span>
            <span style="font-size: 0.75rem; color: #64748b; margin-top: 5px; font-weight: normal;">สถิติการมาสายของนักเรียนในที่ปรึกษา</span>
        </a>

    </div>
</div>

<style>
/* Quick Actions Grid */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
}
@media (max-width: 768px) {
    .quick-actions {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .quick-actions {
        grid-template-columns: repeat(1, 1fr);
    }
}
.quick-action-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    text-align: center;
    border-radius: 14px;
    position: relative;
}
.quick-action-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 15px rgba(0,0,0,0.05);
    border-color: #cbd5e1 !important;
}
.action-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 14px;
    margin-bottom: 8px;
}
</style>
<style>
.quick-action-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 15px rgba(0,0,0,0.05);
    border-color: #cbd5e1 !important;
}
</style>

<?php include '../../includes/footer.php'; ?>
