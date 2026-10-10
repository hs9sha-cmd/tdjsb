<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

$page_title = "ลงเวลาปฏิบัติงาน (เซลฟี่)";
$user_id = $_SESSION['user_id'];
$today = date('Y-m-d');
$now_time = date('H:i:s');

// Fetch today's record
$stmt = $pdo->prepare("SELECT * FROM teacher_attendance WHERE user_id = ? AND work_date = ?");
$stmt->execute([$user_id, $today]);
$record = $stmt->fetch();

// Fetch settings
$stmt_setting = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('attendance_radius', 'school_lat', 'school_lng', 'late_cutoff_time')");
$settings_map = $stmt_setting ? $stmt_setting->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$setting_radius = isset($settings_map['attendance_radius']) ? (int)$settings_map['attendance_radius'] : 100;
$late_cutoff_time = !empty($settings_map['late_cutoff_time']) ? $settings_map['late_cutoff_time'] : '08:00:00';

// School Location Coordinates from site_settings
$SCHOOL_LAT = isset($settings_map['school_lat']) && is_numeric($settings_map['school_lat']) ? (float)$settings_map['school_lat'] : 13.7563;
$SCHOOL_LNG = isset($settings_map['school_lng']) && is_numeric($settings_map['school_lng']) ? (float)$settings_map['school_lng'] : 100.5018;
$MAX_DISTANCE = $setting_radius; // meters

// Helper for distance calculation in PHP (server-side validation)
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * asin(sqrt($a));
    return $earth_radius * $c;
}

// Handle Action
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $lat = $_POST['lat'] ?? 0;
    $lng = $_POST['lng'] ?? 0;
    $photo_base64 = $_POST['photo_base64'] ?? '';
    
    // Server-side distance check
    $distance = calculateDistance($lat, $lng, $SCHOOL_LAT, $SCHOOL_LNG);
    
    if ($distance > $MAX_DISTANCE + 10) { // added small buffer 10m for GPS inaccuracies
        $message = "บันทึกไม่สำเร็จ: คุณอยู่นอกพื้นที่โรงเรียน (" . round($distance) . " เมตร)";
        $message_type = "danger";
    } else if (empty($photo_base64)) {
        $message = "บันทึกไม่สำเร็จ: กรุณาถ่ายภาพเซลฟี่ก่อนลงเวลา";
        $message_type = "danger";
    } else {
        $action = $_POST['action'];
        
        // Save Image
        $upload_dir = '../../uploads/attendance/' . date('Y-m') . '/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $img = str_replace('data:image/jpeg;base64,', '', $photo_base64);
        $img = str_replace(' ', '+', $img);
        $data = base64_decode($img);
        
        $file_name = 'user_' . $user_id . '_' . $action . '_' . time() . '.jpg';
        $file_path = $upload_dir . $file_name;
        $db_path = 'uploads/attendance/' . date('Y-m') . '/' . $file_name;
        
        file_put_contents($file_path, $data);
        
        if ($action === 'check_in') {
            if ($now_time >= '07:00:00' && $now_time <= '12:00:00') {
                $status = ($now_time > $late_cutoff_time) ? 'late' : 'on_time';
                if (!$record) {
                    $stmt_in = $pdo->prepare("INSERT INTO teacher_attendance (user_id, work_date, time_in, time_in_lat, time_in_lng, time_in_status, time_in_photo) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt_in->execute([$user_id, $today, $now_time, $lat, $lng, $status, $db_path]);
                } else {
                    $stmt_up = $pdo->prepare("UPDATE teacher_attendance SET time_in = ?, time_in_lat = ?, time_in_lng = ?, time_in_status = ?, time_in_photo = ? WHERE id = ?");
                    $stmt_up->execute([$now_time, $lat, $lng, $status, $db_path, $record['id']]);
                }
                $message = "ลงเวลาทำงานสำเร็จเวลา " . substr($now_time,0,5) . " น.";
                $message_type = "success";
            } else {
                $message = "นอกเวลาลงเวลาทำงาน (07:00 - 12:00)";
                $message_type = "danger";
            }
        } else if ($action === 'check_out') {
            if ($now_time >= '16:10:00' && $now_time <= '18:00:00') {
                if ($record) {
                    $stmt_out = $pdo->prepare("UPDATE teacher_attendance SET time_out = ?, time_out_lat = ?, time_out_lng = ?, time_out_photo = ? WHERE id = ?");
                    $stmt_out->execute([$now_time, $lat, $lng, $db_path, $record['id']]);
                } else {
                    $stmt_out = $pdo->prepare("INSERT INTO teacher_attendance (user_id, work_date, time_out, time_out_lat, time_out_lng, time_out_photo) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt_out->execute([$user_id, $today, $now_time, $lat, $lng, $db_path]);
                }
                $message = "ลงเวลาทำงานสำเร็จเวลา " . substr($now_time,0,5) . " น.";
                $message_type = "success";
            } else {
                $message = "นอกเวลาลงเวลาทำงาน (16:10 - 18:00)";
                $message_type = "danger";
            }
        }
    }
    
    // Refresh record
    $stmt->execute([$user_id, $today]);
    $record = $stmt->fetch();
}

include '../../includes/header.php';
?>

<style>
    .geo-card { background: white; border-radius: 20px; padding: 25px; border: 1px solid #e2e8f0; box-shadow: 0 4px 15px rgba(0,0,0,0.05); text-align: center; margin-bottom: 25px; }
    .status-badge { display: inline-block; padding: 8px 16px; border-radius: 20px; font-weight: 700; margin-bottom: 15px; font-size: 0.95rem; }
    .status-waiting { background: #fef08a; color: #854d0e; }
    .status-ok { background: #bbf7d0; color: #166534; }
    .status-error { background: #fecaca; color: #991b1b; }
    .time-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; margin-bottom: 15px; }
    
    .camera-container {
        position: relative;
        width: 100%;
        max-width: 400px;
        margin: 0 auto 20px auto;
        border-radius: 16px;
        overflow: hidden;
        background: #0f172a;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
    }
    #cameraView {
        width: 100%;
        height: auto;
        display: block;
        transform: scaleX(-1); /* Mirror effect */
    }
    #photoPreview {
        width: 100%;
        height: auto;
        display: none;
    }
    .watermark-overlay {
        position: absolute;
        bottom: 10px;
        left: 10px;
        right: 10px;
        color: white;
        text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
        text-align: left;
        font-size: 0.85rem;
        z-index: 10;
        pointer-events: none;
    }
    
    .btn-camera { padding: 12px 24px; font-size: 1.1rem; border-radius: 50px; font-weight: 700; background: #3b82f6; color: white; border: none; box-shadow: 0 4px 10px rgba(59,130,246,0.3); margin-bottom: 15px; cursor: pointer; }
    .btn-retake { background: #ef4444; box-shadow: 0 4px 10px rgba(239,68,68,0.3); }
    
    .btn-checkin { padding: 15px 30px; font-size: 1.2rem; border-radius: 50px; font-weight: 700; width: 100%; max-width: 300px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border: none; transition: transform 0.2s; }
    .btn-checkin:active { transform: scale(0.95); }
    .btn-checkin:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
</style>

<div class="main-grid" style="grid-template-columns: 1fr;">
    <div class="card" style="border: none; box-shadow: none; background: transparent; padding: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 class="card-title" style="margin: 0; font-size: 1.4rem; color: #1e293b; font-weight: 800;">📍 ลงเวลาปฏิบัติงาน</h2>
            <button type="button" class="btn btn-outline" style="border-radius: 50px; padding: 6px 16px; font-weight: 600;" onclick="history.back()">กลับ</button>
        </div>

        <?php if($message): ?>
            <div class="badge badge-<?= $message_type ?>" style="display: block; padding: 12px; border-radius: 12px; font-size: 1rem; margin-bottom: 20px; text-align: center;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="geo-card">
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; text-align: left; margin-bottom: 20px;">
                <div class="time-card">
                    <div style="color: #64748b; font-weight: 600; font-size: 0.85rem; margin-bottom: 5px;">เวลาเข้างาน (07:00 - 12:00)</div>
                    <div style="font-size: 1.4rem; font-weight: 800; color: #10b981;">
                        <?= $record && $record['time_in'] ? substr($record['time_in'],0,5) . ' น.' : '--:-- น.' ?>
                    </div>
                    <?php if($record && $record['time_in_status'] == 'late'): ?>
                        <span style="background: #fef08a; color: #854d0e; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 700;">สาย</span>
                    <?php endif; ?>
                </div>
                <div class="time-card">
                    <div style="color: #64748b; font-weight: 600; font-size: 0.85rem; margin-bottom: 5px;">เวลาเลิกงาน (16:10 - 18:00)</div>
                    <div style="font-size: 1.4rem; font-weight: 800; color: #f59e0b;">
                        <?= $record && $record['time_out'] ? substr($record['time_out'],0,5) . ' น.' : '--:-- น.' ?>
                    </div>
                </div>
            </div>

            <?php
            $is_morning = ($now_time >= '07:00:00' && $now_time <= '12:00:00');
            $is_evening = ($now_time >= '16:10:00' && $now_time <= '18:00:00');
            $show_in = $is_morning;
            $show_out = $is_evening;
            $is_done = (!$show_in && !$show_out);
            
            if (!$is_done): 
            ?>
            <h3 style="margin-top: 0; color: #0f172a; font-weight: 700; font-size: 1.2rem;">ถ่ายภาพยืนยันตัวตน</h3>
            <div id="geo-status" class="status-badge status-waiting">กำลังค้นหาตำแหน่ง GPS... ⏳</div>
            <div id="geo-detail" style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">กรุณาอนุญาตให้เบราว์เซอร์เข้าถึงตำแหน่งและกล้องถ่ายรูป</div>
            
            <div class="camera-container" id="cameraContainer" style="display: none;">
                <video id="cameraView" autoplay playsinline></video>
                <img id="photoPreview" src="" alt="Photo Preview">
                <div class="watermark-overlay" id="watermarkOverlay">
                    <div style="font-weight: bold;"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
                    <div id="wm-date"><?= thai_date_full($today) ?> <span id="wm-time"></span></div>
                    <div id="wm-gps" style="font-size: 0.75rem; color: #cbd5e1;"></div>
                </div>
            </div>
            
            <button type="button" id="btnCapture" class="btn-camera" style="display: none;">📸 ถ่ายรูป</button>
            <button type="button" id="btnRetake" class="btn-camera btn-retake" style="display: none;">🔄 ถ่ายใหม่</button>
            <canvas id="canvas" style="display: none;"></canvas>

            <form method="POST" id="attendanceForm" style="margin-top: 15px;">
                <input type="hidden" name="lat" id="lat" value="">
                <input type="hidden" name="lng" id="lng" value="">
                <input type="hidden" name="photo_base64" id="photo_base64" value="">
                
                <?php
                if ($show_in) {
                    echo '<button type="submit" name="action" value="check_in" id="btnAction" class="btn-checkin" style="background: #10b981; color: white;" disabled>☀️ ลงเวลาทำงาน</button>';
                } else if ($show_out) {
                    echo '<button type="submit" name="action" value="check_out" id="btnAction" class="btn-checkin" style="background: #f59e0b; color: white;" disabled>🌙 ลงเวลาทำงาน</button>';
                }
                ?>
            </form>
            <?php else: ?>
                <div style="background: #f1f5f9; color: #475569; padding: 20px; border-radius: 12px; font-weight: 700; font-size: 1.1rem; margin-top: 20px;">
                    ไม่อยู่ในช่วงเวลาลงเวลาทำงาน<br>
                    <span style="font-size: 0.85rem; font-weight: 400;">(ระบบเปิดให้ลงเวลา 07:00-12:00 น. และ 16:10-18:00 น.)</span>
                </div>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; color: #94a3b8; font-size: 0.8rem;">
            พิกัดอ้างอิงโรงเรียน: <?= htmlspecialchars($SCHOOL_LAT) ?>, <?= htmlspecialchars($SCHOOL_LNG) ?><br>
            ระยะที่อนุญาต: <?= htmlspecialchars($MAX_DISTANCE) ?> เมตร
        </div>
    </div>
</div>

<?php 
// Helper for JS
function thai_date_full($date_str) {
    if (!$date_str) return '';
    $thai_months = ["", "มกราคม", "กุมภาพันธ์", "มีนาคม", "เมษายน", "พฤษภาคม", "มิถุนายน", "กรกฎาคม", "สิงหาคม", "กันยายน", "ตุลาคม", "พฤศจิกายน", "ธันวาคม"];
    $t = strtotime($date_str);
    return date('j', $t) . ' ' . $thai_months[(int)date('n', $t)] . ' ' . (date('Y', $t) + 543);
}
?>

<script>
    const SCHOOL_LAT = <?= (float)$SCHOOL_LAT ?>;
    const SCHOOL_LNG = <?= (float)$SCHOOL_LNG ?>;
    const MAX_DISTANCE = <?= (int)$MAX_DISTANCE ?>;

    const geoStatus = document.getElementById('geo-status');
    const geoDetail = document.getElementById('geo-detail');
    const btnAction = document.getElementById('btnAction');
    const latInput = document.getElementById('lat');
    const lngInput = document.getElementById('lng');
    const photoInput = document.getElementById('photo_base64');
    
    // Camera Elements
    const cameraContainer = document.getElementById('cameraContainer');
    const video = document.getElementById('cameraView');
    const photoPreview = document.getElementById('photoPreview');
    const btnCapture = document.getElementById('btnCapture');
    const btnRetake = document.getElementById('btnRetake');
    const canvas = document.getElementById('canvas');
    const wmTime = document.getElementById('wm-time');
    const wmGps = document.getElementById('wm-gps');
    const watermarkOverlay = document.getElementById('watermarkOverlay');
    
    let currentStream = null;
    let gpsPassed = false;
    let photoCaptured = false;
    let currentLat = 0;
    let currentLng = 0;

    // Clock
    setInterval(() => {
        if(wmTime) {
            const now = new Date();
            wmTime.innerText = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0') + ':' + now.getSeconds().toString().padStart(2, '0');
        }
    }, 1000);

    // Camera Init
    async function startCamera() {
        if (!cameraContainer) return;
        try {
            const constraints = {
                video: { facingMode: "user", width: { ideal: 640 }, height: { ideal: 480 } }
            };
            currentStream = await navigator.mediaDevices.getUserMedia(constraints);
            video.srcObject = currentStream;
            video.style.display = 'block';
            photoPreview.style.display = 'none';
            photoCaptured = false;
            updateButtonState();
        } catch (err) {
            alert('ไม่สามารถเข้าถึงกล้องถ่ายรูปได้: ' + err.message);
        }
    }
    
    function stopCamera() {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
        }
    }

    // Capture Image and Embed Watermark
    if (btnCapture) {
        btnCapture.addEventListener('click', () => {
            if (!gpsPassed) {
                alert("ต้องอยู่ในพื้นที่โรงเรียนก่อนจึงจะถ่ายรูปได้");
                return;
            }
            
            // Set canvas size (max 800px width for compression)
            let vWidth = video.videoWidth || video.clientWidth || 640;
            let vHeight = video.videoHeight || video.clientHeight || 480;
            const MAX_WIDTH = 800;
            const scale = Math.min(MAX_WIDTH / vWidth, 1);
            canvas.width = Math.round(vWidth * scale);
            canvas.height = Math.round(vHeight * scale);
            
            const ctx = canvas.getContext('2d');
            
            // 1. Draw video frame (mirrored)
            ctx.translate(canvas.width, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            // 2. Reset transform to draw text normally
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            
            // 3. Draw Watermark Background
            ctx.fillStyle = "rgba(0, 0, 0, 0.4)";
            ctx.fillRect(0, canvas.height - 80, canvas.width, 80);
            
            // 4. Draw Text
            ctx.fillStyle = "white";
            ctx.font = "bold 18px sans-serif";
            ctx.shadowColor = "black";
            ctx.shadowBlur = 4;
            
            const fullName = "<?= htmlspecialchars($_SESSION['full_name']) ?>";
            const dateStr = "<?= thai_date_full($today) ?> " + wmTime.innerText;
            const gpsStr = `GPS: ${currentLat.toFixed(6)}, ${currentLng.toFixed(6)}`;
            
            ctx.fillText(fullName, 15, canvas.height - 55);
            ctx.font = "16px sans-serif";
            ctx.fillText(dateStr, 15, canvas.height - 35);
            ctx.font = "14px sans-serif";
            ctx.fillStyle = "#cbd5e1";
            ctx.fillText(gpsStr, 15, canvas.height - 15);
            
            // 5. Convert to Base64 JPEG (Quality 0.7 = 70%)
            const base64Data = canvas.toDataURL('image/jpeg', 0.7);
            photoInput.value = base64Data;
            
            // 6. Update UI
            photoPreview.src = base64Data;
            video.style.display = 'none';
            photoPreview.style.display = 'block';
            watermarkOverlay.style.display = 'none'; // hide html watermark since it's now painted on img
            
            btnCapture.style.display = 'none';
            btnRetake.style.display = 'inline-block';
            
            photoCaptured = true;
            stopCamera();
            updateButtonState();
        });
    }

    if (btnRetake) {
        btnRetake.addEventListener('click', () => {
            photoInput.value = "";
            btnRetake.style.display = 'none';
            btnCapture.style.display = 'inline-block';
            watermarkOverlay.style.display = 'block';
            startCamera();
        });
    }

    // Haversine formula
    function getDistanceFromLatLonInM(lat1, lon1, lat2, lon2) {
        var R = 6371000; 
        var dLat = deg2rad(lat2-lat1);
        var dLon = deg2rad(lon2-lon1); 
        var a = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(deg2rad(lat1)) * Math.cos(deg2rad(lat2)) * Math.sin(dLon/2) * Math.sin(dLon/2); 
        var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)); 
        return R * c;
    }
    function deg2rad(deg) { return deg * (Math.PI/180); }
    
    function updateButtonState() {
        if (btnAction) {
            btnAction.disabled = !(gpsPassed && photoCaptured);
        }
    }

    if (navigator.geolocation && geoStatus) {
        navigator.geolocation.watchPosition(
            (position) => {
                currentLat = position.coords.latitude;
                currentLng = position.coords.longitude;
                latInput.value = currentLat;
                lngInput.value = currentLng;
                
                if (wmGps) {
                    wmGps.innerText = `GPS: ${currentLat.toFixed(6)}, ${currentLng.toFixed(6)}`;
                }
                
                const distance = getDistanceFromLatLonInM(currentLat, currentLng, SCHOOL_LAT, SCHOOL_LNG);
                const distRounded = Math.round(distance);
                
                if (distance <= MAX_DISTANCE) {
                    geoStatus.className = "status-badge status-ok";
                    geoStatus.innerHTML = "✅ อยู่ในพื้นที่โรงเรียน";
                    geoDetail.innerHTML = "ระยะห่าง: " + distRounded + " เมตร";
                    gpsPassed = true;
                    
                    if (cameraContainer && cameraContainer.style.display === 'none') {
                        cameraContainer.style.display = 'block';
                        btnCapture.style.display = 'inline-block';
                        startCamera();
                    }
                } else {
                    geoStatus.className = "status-badge status-error";
                    geoStatus.innerHTML = "❌ อยู่นอกพื้นที่โรงเรียน";
                    geoDetail.innerHTML = "ระยะห่าง: " + distRounded + " เมตร (อนุญาต " + MAX_DISTANCE + "ม.)";
                    gpsPassed = false;
                    
                    if (cameraContainer) {
                        cameraContainer.style.display = 'none';
                        btnCapture.style.display = 'none';
                        stopCamera();
                    }
                }
                updateButtonState();
            },
            (error) => {
                geoStatus.className = "status-badge status-error";
                gpsPassed = false;
                if (error.code === error.PERMISSION_DENIED) {
                    geoStatus.innerHTML = "❌ ถูกปฏิเสธการเข้าถึง GPS";
                    geoDetail.innerHTML = "กรุณาตั้งค่าอนุญาต Location ในเบราว์เซอร์ของคุณ";
                } else {
                    geoStatus.innerHTML = "❌ ไม่สามารถหาตำแหน่งได้";
                    geoDetail.innerHTML = "เกิดข้อผิดพลาดในการดึงตำแหน่ง (Code: " + error.code + ")";
                }
                updateButtonState();
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    }
</script>

<?php include '../../includes/footer.php'; ?>
