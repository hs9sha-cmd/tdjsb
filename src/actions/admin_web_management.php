<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

// Fetch current settings FIRST
$stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
$settings_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Handle form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_settings'])) {
        // 1. School Profile
        $school_name = trim($_POST['school_name'] ?? 'โรงเรียนของคุณ');
        $director_name = trim($_POST['director_name'] ?? '');
        $director_position = trim($_POST['director_position'] ?? 'ผู้อำนวยการโรงเรียน');
        
        // Handle School Logo upload
        $school_logo = $settings_data['school_logo'] ?? 'assets/images/school_logo.png';
        if (isset($_FILES['school_logo_file']) && $_FILES['school_logo_file']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['school_logo_file']['tmp_name'];
            $upload_dir = '../../assets/uploads/system/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['school_logo_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'])) {
                $new_logo_name = 'logo_' . time() . '.' . $ext;
                if (move_uploaded_file($tmp_name, $upload_dir . $new_logo_name)) {
                    $school_logo = 'assets/uploads/system/' . $new_logo_name;
                }
            }
        }

        // 2. GPS & Attendance
        $school_lat = trim($_POST['school_lat'] ?? '13.7563');
        $school_lng = trim($_POST['school_lng'] ?? '100.5018');
        $attendance_radius = isset($_POST['attendance_radius']) ? (int)$_POST['attendance_radius'] : 100;

        // 3. Rules & Features
        $late_cutoff_time = trim($_POST['late_cutoff_time'] ?? '08:00:00');
        $enable_friday_prayer = isset($_POST['enable_friday_prayer']) ? '1' : '0';
        $require_teaching_image = isset($_POST['require_teaching_image']) ? '1' : '0';

        // 4. Popup
        $popup_active = isset($_POST['popup_active']) ? '1' : '0';
        $popup_message = trim($_POST['popup_message'] ?? '');
        $popup_link = trim($_POST['popup_link'] ?? '');
        $popup_image_path = $settings_data['popup_image_path'] ?? '';
        
        if (isset($_FILES['popup_image']) && $_FILES['popup_image']['error'] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['popup_image']['tmp_name'];
            $upload_dir = '../../assets/uploads/system/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['popup_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $new_filename = 'popup_' . time() . '.' . $ext;
                if (move_uploaded_file($tmp_name, $upload_dir . $new_filename)) {
                    $popup_image_path = 'assets/uploads/system/' . $new_filename;
                }
            }
        }
        
        if (isset($_POST['remove_popup_image'])) {
            $popup_image_path = '';
        }

        // Save all settings
        $stmt_save = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        
        $settings_to_save = [
            'school_name' => $school_name,
            'school_logo' => $school_logo,
            'director_name' => $director_name,
            'director_position' => $director_position,
            'school_lat' => $school_lat,
            'school_lng' => $school_lng,
            'attendance_radius' => $attendance_radius,
            'late_cutoff_time' => $late_cutoff_time,
            'enable_friday_prayer' => $enable_friday_prayer,
            'require_teaching_image' => $require_teaching_image,
            'popup_active' => $popup_active,
            'popup_message' => $popup_message,
            'popup_link' => $popup_link,
            'popup_image_path' => $popup_image_path,
        ];

        foreach ($settings_to_save as $key => $val) {
            $stmt_save->execute([$key, (string)$val]);
        }

        // Re-fetch after update
        $stmt_refresh = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
        $settings_data = $stmt_refresh->fetchAll(PDO::FETCH_KEY_PAIR);

        $message = "<div style='color: #15803d; margin-bottom: 20px; padding: 14px 18px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; font-weight: 500;'>✅ บันทึกข้อมูลการตั้งค่าระบบเรียบร้อยแล้ว</div>";
    }
}

// Data for form
$school_name = $settings_data['school_name'] ?? 'โรงเรียนของคุณ';
$school_logo = $settings_data['school_logo'] ?? 'assets/images/school_logo.png';
$director_name = $settings_data['director_name'] ?? '';
$director_position = $settings_data['director_position'] ?? 'ผู้อำนวยการโรงเรียน';

$school_lat = $settings_data['school_lat'] ?? '13.7563';
$school_lng = $settings_data['school_lng'] ?? '100.5018';
$attendance_radius = $settings_data['attendance_radius'] ?? '100';

$late_cutoff_time = $settings_data['late_cutoff_time'] ?? '08:00:00';
$enable_friday_prayer = $settings_data['enable_friday_prayer'] ?? '0';
$require_teaching_image = $settings_data['require_teaching_image'] ?? '1';

$popup_active = $settings_data['popup_active'] ?? '0';
$popup_message = $settings_data['popup_message'] ?? '';
$popup_link = $settings_data['popup_link'] ?? '';
$popup_image_path = $settings_data['popup_image_path'] ?? '';

$page_title = "การตั้งค่าระบบและข้อมูลโรงเรียน";
include '../../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="margin: 0; font-size: 1.5rem; color: var(--text-main);">⚙️ จัดการข้อมูลโรงเรียนและระบบ</h2>
        <p style="margin: 4px 0 0; color: var(--text-muted); font-size: 0.9rem;">ตั้งค่าชื่อโรงเรียน พิกัด GPS สิทธิ์การใช้งาน และฟังก์ชันหลักของระบบ</p>
    </div>
    <a href="admin_dashboard.php" class="btn btn-outline" style="color: var(--primary-color); border-color: var(--primary-color);">⬅️ แดชบอร์ด</a>
</div>

<?= $message ?>

<form method="POST" action="" enctype="multipart/form-data">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
        
        <!-- Section 1: School Profile & Branding -->
        <div class="card" style="padding: 24px; border-radius: 14px;">
            <h3 style="margin-top: 0; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; font-size: 1.15rem; color: var(--primary-color);">
                🏫 ข้อมูลโรงเรียน & ตราสัญลักษณ์
            </h3>
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">ชื่อโรงเรียน</label>
                <input type="text" name="school_name" class="form-control" value="<?= htmlspecialchars($school_name) ?>" required placeholder="เช่น โรงเรียนตัวอย่างวิทยา">
                <small style="color: var(--text-muted); display: block; margin-top: 4px;">* ใช้แสดงบนหัวรายงาน PDF, หน้าล็อกอิน, บัตรนักเรียน และแถบเบราว์เซอร์</small>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">ตราสัญลักษณ์โรงเรียน (Logo)</label>
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
                    <img src="../../<?= htmlspecialchars($school_logo) ?>" alt="School Logo" style="width: 55px; height: 55px; object-fit: contain; border-radius: 8px; border: 1px solid var(--border-color); background: #f8fafc; padding: 4px;" onerror="this.src='../../assets/images/school_logo.png'">
                    <div>
                        <input type="file" name="school_logo_file" class="form-control" accept="image/*" style="font-size: 0.85rem;">
                        <small style="color: var(--text-muted); display: block; margin-top: 4px;">รองรับไฟล์ PNG, JPG, WEBP (แนะนำภาพโปร่งใส)</small>
                    </div>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">ชื่อ-สกุล ผู้อำนวยการโรงเรียน</label>
                <input type="text" name="director_name" class="form-control" value="<?= htmlspecialchars($director_name) ?>" placeholder="เช่น นายสมชาย ใจดี">
                <small style="color: var(--text-muted); display: block; margin-top: 4px;">* สำหรับลงนามท้ายใบรายงานการปฏิบัติงาน</small>
            </div>

            <div class="form-group">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">ตำแหน่งผู้บริหาร</label>
                <input type="text" name="director_position" class="form-control" value="<?= htmlspecialchars($director_position) ?>" placeholder="ผู้อำนวยการโรงเรียน">
            </div>
        </div>

        <!-- Section 2: GPS Location & Attendance -->
        <div class="card" style="padding: 24px; border-radius: 14px;">
            <h3 style="margin-top: 0; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; font-size: 1.15rem; color: #10b981;">
                📍 พิกัดศูนย์กลางโรงเรียน (GPS)
            </h3>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 15px;">
                <div class="form-group">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">ละติจูด (Latitude)</label>
                    <input type="text" name="school_lat" id="school_lat" class="form-control" value="<?= htmlspecialchars($school_lat) ?>" required placeholder="เช่น 13.7563">
                </div>
                <div class="form-group">
                    <label style="font-weight: 600; margin-bottom: 6px; display: block;">ลองจิจูด (Longitude)</label>
                    <input type="text" name="school_lng" id="school_lng" class="form-control" value="<?= htmlspecialchars($school_lng) ?>" required placeholder="เช่น 100.5018">
                </div>
            </div>

            <div style="margin-bottom: 15px;">
                <button type="button" class="btn btn-outline btn-sm" onclick="getCurrentGPS()" style="font-size: 0.85rem; border-color: #10b981; color: #059669;">
                    🎯 ดึงพิกัด GPS ตำแหน่งปัจจุบันของฉัน
                </button>
                <span id="gps_msg" style="font-size: 0.8rem; margin-left: 8px; color: var(--text-muted);"></span>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">รัศมีการลงเวลา (เมตร)</label>
                <input type="number" name="attendance_radius" class="form-control" value="<?= htmlspecialchars($attendance_radius) ?>" min="10" max="10000" required>
                <small style="color: var(--text-muted); display: block; margin-top: 4px;">* กำหนดระยะห่างที่ยอมให้ครูเซลฟี่ลงเวลาได้ (ค่าเริ่มต้น: 100 เมตร)</small>
            </div>

            <div class="form-group">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">เวลาตัดสายเข้าแถว</label>
                <input type="time" step="1" name="late_cutoff_time" class="form-control" value="<?= htmlspecialchars($late_cutoff_time) ?>">
                <small style="color: var(--text-muted); display: block; margin-top: 4px;">* เวลาเข้าแถวหน้าเสาธง (ค่าเริ่มต้น: 08:00:00 น.)</small>
            </div>
        </div>

        <!-- Section 3: Rules & Specific Features -->
        <div class="card" style="padding: 24px; border-radius: 14px;">
            <h3 style="margin-top: 0; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; font-size: 1.15rem; color: #6366f1;">
                ⚙️ กฎเกณฑ์และฟังก์ชันเฉพาะโรงเรียน
            </h3>
            
            <div class="form-group" style="margin-bottom: 15px; background: #f8fafc; padding: 14px; border-radius: 10px; border: 1px solid var(--border-color);">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; margin-bottom: 4px;">
                    <input type="checkbox" name="enable_friday_prayer" value="1" <?= $enable_friday_prayer === '1' ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                    เปิดใช้งานระบบละหมาดวันศุกร์
                </label>
                <p style="margin: 0; font-size: 0.85rem; color: var(--text-muted); padding-left: 28px;">
                    หาก <b>เปิดใช้งาน</b> จะแสดงเมนูสแกนเข้า-ออกและรายงานละหมาดวันศุกร์สำหรับนักเรียนมุสลิม (เหมาะสำหรับโรงเรียนในภาคใต้หรือโรงเรียนที่มีนักเรียนมุสลิม)
                </p>
            </div>

            <div class="form-group" style="margin-bottom: 15px; background: #f8fafc; padding: 14px; border-radius: 10px; border: 1px solid var(--border-color);">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; margin-bottom: 4px;">
                    <input type="checkbox" name="require_teaching_image" value="1" <?= $require_teaching_image === '1' ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                    บังคับแนบรูปภาพรายงานการสอน
                </label>
                <p style="margin: 0; font-size: 0.85rem; color: var(--text-muted); padding-left: 28px;">
                    หาก <b>เปิดใช้งาน</b> ครูผู้สอนจะต้องแนบรูปถ่ายการสอนในคาบทุกครั้งจึงจะส่งรายงานได้
                </p>
            </div>
        </div>

        <!-- Section 4: Popup Notification -->
        <div class="card" style="padding: 24px; border-radius: 14px;">
            <h3 style="margin-top: 0; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; font-size: 1.15rem; color: #f59e0b;">
                📢 การแจ้งเตือนหน้าแรก (Popup Modal)
            </h3>
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600;">
                    <input type="checkbox" name="popup_active" value="1" <?= $popup_active === '1' ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                    เปิดใช้งานการแจ้งเตือนป๊อปอัป
                </label>
            </div>
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">ข้อความแจ้งเตือน</label>
                <textarea name="popup_message" class="form-control" rows="3" placeholder="พิมพ์ข้อความที่ต้องการประกาศ..."><?= htmlspecialchars($popup_message) ?></textarea>
            </div>
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">รูปภาพประกอบ Popup</label>
                <?php if (!empty($popup_image_path)): ?>
                    <div style="margin-bottom: 10px; border-radius: 8px; overflow: hidden; max-width: 180px; border: 1px solid var(--border-color);">
                        <img src="../../<?= htmlspecialchars($popup_image_path) ?>" style="width: 100%; display: block;">
                    </div>
                    <label style="display: flex; align-items: center; gap: 8px; color: var(--danger-color); cursor: pointer; margin-bottom: 8px; font-size: 0.85rem;">
                        <input type="checkbox" name="remove_popup_image" value="1"> ลบรูปภาพปัจจุบัน
                    </label>
                <?php endif; ?>
                <input type="file" name="popup_image" class="form-control" accept="image/*">
            </div>
            
            <div class="form-group">
                <label style="font-weight: 600; margin-bottom: 6px; display: block;">ลิงก์ปลายทาง (ถ้ามี)</label>
                <input type="text" name="popup_link" class="form-control" value="<?= htmlspecialchars($popup_link) ?>" placeholder="https://example.com">
            </div>
        </div>
        
    </div>

    <div style="margin-top: 25px; text-align: right; margin-bottom: 30px;">
        <button type="submit" name="update_settings" class="btn btn-primary" style="padding: 12px 36px; font-size: 1.1rem; border-radius: 10px; font-weight: 600; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);">
            💾 บันทึกการตั้งค่าระบบ
        </button>
    </div>
</form>

<script>
function getCurrentGPS() {
    const msg = document.getElementById('gps_msg');
    if (!navigator.geolocation) {
        msg.innerHTML = "❌ เบราว์เซอร์ไม่รองรับ Geolocation";
        return;
    }
    msg.innerHTML = "⏳ กำลังดึงพิกัดจากอุปกรณ์...";
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            document.getElementById('school_lat').value = pos.coords.latitude.toFixed(6);
            document.getElementById('school_lng').value = pos.coords.longitude.toFixed(6);
            msg.innerHTML = "✅ ดึงพิกัดสำเร็จ!";
        },
        function(err) {
            msg.innerHTML = "❌ ไม่สามารถดึงพิกัดได้ (" + err.message + ")";
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}
</script>

<?php include '../../includes/footer.php'; ?>
