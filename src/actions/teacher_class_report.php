<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'teacher');

$page_title = "บันทึกหลังการสอน";
$teacher_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch require image setting
$require_teaching_image = '1';
try {
    $stmt_set = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'require_teaching_image'");
    $val = $stmt_set->fetchColumn();
    if ($val !== false) $require_teaching_image = $val;
} catch(Exception $e) {}

$log_id = $_GET['log_id'] ?? null;

if (!$log_id) {
    die("Invalid request");
}

// Fetch log details to verify ownership and display
$stmt = $pdo->prepare("
    SELECT tl.*, s.subject_name, s.subject_code, c.room_name 
    FROM teaching_logs tl
    LEFT JOIN subjects s ON tl.subject_id = s.id
    LEFT JOIN classrooms c ON tl.classroom_id = c.id
    WHERE tl.id = ? AND tl.teacher_id = ?
");
$stmt->execute([$log_id, $teacher_id]);
$log = $stmt->fetch();

if (!$log) {
    die("ไม่พบข้อมูล หรือคุณไม่มีสิทธิ์เข้าถึงข้อมูลนี้");
}

// Check how many students are present vs total
$att_stmt = $pdo->prepare("
    SELECT 
        COUNT(id) as total,
        SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN status='leave' THEN 1 ELSE 0 END) as leave_count,
        SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) as absent_count
    FROM class_attendance 
    WHERE teaching_log_id = ?
");
$att_stmt->execute([$log_id]);
$att_stats = $att_stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teaching_report_text = trim($_POST['teaching_report_text']);
    
    // File Upload handling
    $image_path = $log['image_path'] ?? null;
    $image_capture_time = $log['image_capture_time'] ?? null;
    
    // 1. Process client-side compressed image (Super fast)
    if (!empty($_POST['compressed_image_data'])) {
        $data = $_POST['compressed_image_data'];
        if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
            $data = substr($data, strpos($data, ',') + 1);
            $type = strtolower($type[1]);
            $data = base64_decode($data);
            if ($data !== false) {
                $upload_dir = '../../uploads/teaching_logs/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $new_filename = uniqid('log_', true) . '.' . ($type === 'jpeg' ? 'jpg' : $type);
                if (file_put_contents($upload_dir . $new_filename, $data)) {
                    $image_path = 'uploads/teaching_logs/' . $new_filename;
                    
                    // Stealth EXIF Handling
                    if (!empty($_POST['client_exif_time'])) {
                        // EXIF format is typically "YYYY:MM:DD HH:MM:SS"
                        $dt = DateTime::createFromFormat('Y:m:d H:i:s', $_POST['client_exif_time']);
                        if ($dt) {
                            $image_capture_time = $dt->format('Y-m-d H:i:s');
                        } else {
                            $image_capture_time = null;
                        }
                    } else {
                        $image_capture_time = null; // Mark as empty to flag as suspicious
                    }
                }
            }
        }
    } 
    // 2. Fallback to server-side GD processing if JS compression failed
    elseif (isset($_FILES['teaching_image']) && $_FILES['teaching_image']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['teaching_image']['tmp_name'];
        $upload_dir = '../../uploads/teaching_logs/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $ext = strtolower(pathinfo($_FILES['teaching_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            $new_filename = uniqid('log_', true) . '.jpg'; // Convert all to jpg
            $target_file = $upload_dir . $new_filename;
            
            // Extract EXIF before resizing (only for jpg/jpeg)
            if ($ext === 'jpg' || $ext === 'jpeg') {
                $exif = @exif_read_data($tmp_name);
                if (!empty($exif['DateTimeOriginal'])) {
                    // EXIF format is typically "YYYY:MM:DD HH:MM:SS"
                    $dt = DateTime::createFromFormat('Y:m:d H:i:s', $exif['DateTimeOriginal']);
                    if ($dt) $image_capture_time = $dt->format('Y-m-d H:i:s');
                }
            }
            
            // Compress and Resize using GD
            $info = getimagesize($tmp_name);
            if ($info) {
                if ($info['mime'] == 'image/jpeg') $image = imagecreatefromjpeg($tmp_name);
                elseif ($info['mime'] == 'image/png') $image = imagecreatefrompng($tmp_name);
                
                if (isset($image) && $image !== false) {
                    // Check rotation from EXIF and rotate if necessary
                    if (isset($exif['Orientation'])) {
                        switch ($exif['Orientation']) {
                            case 3: $image = imagerotate($image, 180, 0); break;
                            case 6: $image = imagerotate($image, -90, 0); break;
                            case 8: $image = imagerotate($image, 90, 0); break;
                        }
                    }

                    $width = imagesx($image);
                    $height = imagesy($image);
                    $maxDim = 800; // Resize to max 800px width/height
                    
                    if ($width > $maxDim || $height > $maxDim) {
                        $ratio = $width / $height;
                        if ($ratio > 1) { $newW = $maxDim; $newH = $maxDim / $ratio; }
                        else { $newH = $maxDim; $newW = $maxDim * $ratio; }
                        
                        $tmp = imagecreatetruecolor((int)$newW, (int)$newH);
                        imagecopyresampled($tmp, $image, 0, 0, 0, 0, (int)$newW, (int)$newH, $width, $height);
                        imagejpeg($tmp, $target_file, 65); // 65% quality
                        imagedestroy($tmp);
                    } else {
                        imagejpeg($image, $target_file, 75);
                    }
                    imagedestroy($image);
                    
                    $image_path = 'uploads/teaching_logs/' . $new_filename;
                }
            }
        } else {
            $error = "รองรับเฉพาะไฟล์รูปภาพ (JPG, PNG) เท่านั้น";
        }
    }
    
    if (!$error) {
        // Handle Signature Upload
        $signature_path = $log['signature_path'] ?? null;
        if (!empty($_POST['signature_data'])) {
            $data = $_POST['signature_data'];
            if (preg_match('/^data:image\/(\w+);base64,/', $data, $type)) {
                $data = substr($data, strpos($data, ',') + 1);
                $type = strtolower($type[1]);
                if (in_array($type, ['jpg', 'jpeg', 'png'])) {
                    $data = base64_decode($data);
                    if ($data !== false) {
                        $upload_dir = '../../uploads/signatures/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                        $ext = ($type === 'jpeg' || $type === 'jpg') ? 'jpg' : 'png';
                        $filename = uniqid('sig_log_', true) . '.' . $ext;
                        if (file_put_contents($upload_dir . $filename, $data)) {
                            $signature_path = 'uploads/signatures/' . $filename;
                        }
                    }
                }
            }
        }

        $update = $pdo->prepare("UPDATE teaching_logs SET teaching_report_text = ?, image_path = ?, image_capture_time = ?, signature_path = ? WHERE id = ?");
        if ($update->execute([$teaching_report_text, $image_path, $image_capture_time, $signature_path, $log_id])) {
            header("Location: teacher_home.php?success=บันทึกข้อมูลการสอนเรียบร้อยแล้ว");
            exit();
        } else {
            $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล";
        }
    }
}

include '../../includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto; padding-bottom: 150px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0; font-size: 1.5rem; color: var(--text-main);">❷ บันทึกเนื้อหาที่สอน</h2>
        <a href="teacher_class.php?date=<?= $log['log_date'] ?>&period=<?= $log['period'] ?>&subject_id=<?= $log['subject_id'] ?>&classroom_id=<?= $log['classroom_id'] ?>" class="btn btn-outline" style="border-radius: 20px; padding: 6px 12px; font-size: 0.85rem;">⬅️ กลับไปแก้เช็คชื่อ</a>
    </div>

    <!-- Summary of Step 1 -->
    <div style="background: white; border: 1px solid #f1f5f9; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.02);">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
            <div>
                <span style="font-size: 0.85rem; color: var(--text-muted);">วันที่</span><br>
                <strong><?= date('d/m/Y', strtotime($log['log_date'])) ?></strong>
            </div>
            <div>
                <span style="font-size: 0.85rem; color: var(--text-muted);">คาบที่เรียน</span><br>
                <strong>คาบที่ <?= htmlspecialchars($log['period']) ?></strong>
            </div>
            <div>
                <span style="font-size: 0.85rem; color: var(--text-muted);">วิชา</span><br>
                <strong><?= htmlspecialchars($log['subject_code'] . ' ' . $log['subject_name']) ?></strong>
            </div>
            <div>
                <span style="font-size: 0.85rem; color: var(--text-muted);">ห้องเรียน</span><br>
                <strong><?= htmlspecialchars($log['room_name']) ?></strong>
            </div>
        </div>
        
        <div style="border-top: 1px dashed var(--border-color); padding-top: 15px; display: flex; gap: 15px; flex-wrap: wrap;">
            <div style="padding: 5px 10px; border-radius: 8px; font-weight: 600; background: #bbf7d0; color: #166534;">มา <?= $att_stats['present_count'] ?></div>
            <div style="padding: 5px 10px; border-radius: 8px; font-weight: 600; background: #fde047; color: #854d0e;">สาย <?= $att_stats['late_count'] ?></div>
            <div style="padding: 5px 10px; border-radius: 8px; font-weight: 600; background: #bfdbfe; color: #1e3a8a;">ลา <?= $att_stats['leave_count'] ?></div>
            <div style="padding: 5px 10px; border-radius: 8px; font-weight: 600; background: #fecaca; color: #991b1b;">ขาด <?= $att_stats['absent_count'] ?></div>
            <div style="margin-left: auto; font-size: 0.9rem; color: var(--text-muted);">รวม <?= $att_stats['total'] ?> คน</div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="badge badge-danger mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: center;">❌ <?= $error ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label class="form-label" style="font-weight: bold; font-size: 1.1rem; display: flex; align-items: center; justify-content: space-between;">
                <span>เนื้อหาที่สอน / ปัญหา / อุปสรรค *</span>
            </label>
            <textarea name="teaching_report_text" class="form-control" rows="6" style="border-radius: 12px; padding: 12px;" placeholder="กรอกรายละเอียดการจัดการเรียนการสอนในคาบนี้..." required><?= htmlspecialchars($log['teaching_report_text'] ?? '') ?></textarea>
        </div>
        
        <div class="form-group" style="margin-top: 20px;">
            <?php if ($require_teaching_image === '1'): ?>
                <label class="form-label" style="font-weight: bold;">แนบภาพประกอบการสอน <span style="color: red;">(บังคับอัปโหลด)</span></label>
            <?php else: ?>
                <label class="form-label" style="font-weight: bold;">แนบภาพประกอบการสอน <span style="color: #16a34a;">(ไม่บังคับ)</span></label>
            <?php endif; ?>
            
            <?php if (!empty($log['image_path'])): ?>
                <div style="margin-bottom: 10px; border-radius: 8px; overflow: hidden; max-width: 300px; border: 1px solid var(--border-color);">
                    <img src="../../<?= htmlspecialchars($log['image_path']) ?>" alt="Uploaded Image" style="width: 100%; display: block;">
                </div>
            <?php endif; ?>

            <div style="border: 2px dashed #1d4ed8; padding: 30px; text-align: center; border-radius: 12px; background: #f0f7ff; cursor: pointer;" onclick="document.getElementById('imageUpload').click()">
                <div style="font-size: 2rem; margin-bottom: 10px;">📸</div>
                <p style="color: #1d4ed8; font-weight: bold; margin-bottom: 5px;">คลิกเพื่อถ่ายรูป / เลือกไฟล์รูปภาพ</p>
                <p style="color: var(--text-muted); font-size: 0.8rem; margin-bottom: 0;">รองรับภาพ JPG, PNG (ระบบจะลดขนาดให้อัตโนมัติ)</p>
                <input type="file" id="imageUpload" name="teaching_image" accept="image/jpeg, image/png, image/jpg" style="display: none;" onchange="showPreview(event)">
            </div>
            <div id="previewContainer" style="display: none; margin-top: 15px; text-align: center;">
                <p style="color: var(--success); font-weight: bold; font-size: 0.9rem;">✅ เลือกไฟล์ภาพใหม่แล้ว <span id="fileName"></span></p>
            </div>
        </div>

        <!-- Stealth EXIF Extraction -->
        <script src="https://cdn.jsdelivr.net/npm/exif-js"></script>
<script>
        function showPreview(event) {
            const file = event.target.files[0];
            if (file) {
                document.getElementById('previewContainer').style.display = 'block';
                document.getElementById('fileName').innerHTML = '(' + file.name + ') <br><span style="color:orange;">กำลังเตรียมภาพ...</span>';
                
                // 1. Stealthily extract EXIF before any modification
                EXIF.getData(file, function() {
                    const dt = EXIF.getTag(this, "DateTimeOriginal");
                    if (dt) {
                        document.getElementById('client_exif_time').value = dt;
                    } else {
                        document.getElementById('client_exif_time').value = "";
                    }
                });

                // 2. Proceed to compress the image
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        const canvas = document.createElement('canvas');
                        let width = img.width;
                        let height = img.height;
                        const maxDim = 800; // Max 800px
                        
                        if (width > maxDim || height > maxDim) {
                            if (width > height) {
                                height *= maxDim / width;
                                width = maxDim;
                            } else {
                                width *= maxDim / height;
                                height = maxDim;
                            }
                        }
                        
                        canvas.width = width;
                        canvas.height = height;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, width, height);
                        
                        // Compress to 60% quality JPEG
                        const compressedData = canvas.toDataURL('image/jpeg', 0.6);
                        document.getElementById('compressed_image_data').value = compressedData;
                        
                        // Remove the name attribute so the browser DOES NOT upload the heavy original file
                        document.getElementById('imageUpload').removeAttribute('name');
                        
                        document.getElementById('fileName').innerHTML = '(' + file.name + ') <br><span style="color:green;">ภาพพร้อมบันทึก!</span>';
                    };
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        }
        </script>

        <input type="hidden" name="client_exif_time" id="client_exif_time">
        <input type="hidden" name="compressed_image_data" id="compressed_image_data">
        <input type="hidden" name="signature_data" id="signature_data">

        <!-- Inline save button (always visible when scrolled to bottom) -->
        <div style="margin-top: 30px; margin-bottom: 20px;">
            <button type="button" class="btn btn-primary btn-block" style="font-size: 1.1rem; padding: 14px; border-radius: 14px; font-weight: 600; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);" onclick="openSignatureModal()">✅ บันทึกเสร็จสมบูรณ์</button>
        </div>



        
        </div>
    </form>
</div>

<style>
@media (max-width: 768px) {
    .footer-btn-container {
        padding-left: 0 !important;
    }
}
</style>

<!-- Signature Modal -->
        <div id="signatureModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 1000; justify-content: center; align-items: center;">
            <div style="background: white; width: 90%; max-width: 500px; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
                <div style="padding: 15px; background: var(--bg-panel); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 1.2rem;">ลงลายมือชื่อ (Signature)</h3>
                    <button type="button" onclick="closeSignatureModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
                </div>
                <div style="padding: 20px; text-align: center;">
                    <div style="border: 2px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; margin-bottom: 15px; position: relative;">
                        <canvas id="signatureCanvas" width="400" height="200" style="width: 100%; height: 200px; touch-action: none; cursor: crosshair; display: block;"></canvas>
                        <div style="position: absolute; top: 10px; right: 10px;">
                            <button type="button" onclick="clearSignature()" class="btn btn-outline" style="padding: 4px 10px; font-size: 0.8rem; border-color: #ef4444; color: #ef4444;">🗑️ ล้างลายเซ็น</button>
                        </div>
                    </div>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 20px;">กรุณาใช้นิ้วหรือเมาส์เซ็นชื่อของคุณในกรอบด้านบน</p>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn btn-outline" style="flex: 1; border-radius: 12px;" onclick="closeSignatureModal()">ยกเลิก</button>
                        <button type="button" class="btn btn-primary" style="flex: 2; border-radius: 12px;" onclick="submitWithSignature()">💾 ยืนยันและบันทึกข้อมูล</button>
                    </div>
                </div>
            </div>
        </div>
<script>
// Signature Pad Logic
const canvas = document.getElementById('signatureCanvas');
let ctx, isDrawing = false;

if (canvas) {
    ctx = canvas.getContext('2d');
    
    // Resize canvas to fix mobile resolution issues
    function resizeCanvas() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 3;
        ctx.lineCap = 'round';
        ctx.strokeStyle = '#000000';
    }

    function getMousePos(evt) {
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width / (window.devicePixelRatio || 1);
        const scaleY = canvas.height / rect.height / (window.devicePixelRatio || 1);
        
        let clientX = evt.clientX;
        let clientY = evt.clientY;
        if (evt.touches && evt.touches.length > 0) {
            clientX = evt.touches[0].clientX;
            clientY = evt.touches[0].clientY;
        }

        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top) * scaleY
        };
    }

    function startDrawing(e) {
        if (e.cancelable) e.preventDefault();
        isDrawing = true;
        const pos = getMousePos(e);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    }

    function draw(e) {
        if (!isDrawing) return;
        if (e.cancelable) e.preventDefault();
        const pos = getMousePos(e);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
    }

    function stopDrawing() {
        if (isDrawing) {
            ctx.stroke();
            ctx.closePath();
            isDrawing = false;
        }
    }

    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseout', stopDrawing);

    canvas.addEventListener('touchstart', startDrawing, {passive: false});
    canvas.addEventListener('touchmove', draw, {passive: false});
    canvas.addEventListener('touchend', stopDrawing);
}

function openSignatureModal() {
    window.scrollTo({top: 0, behavior: 'instant'});
    document.body.style.overflow = 'hidden';
    document.getElementById('signatureModal').style.display = 'flex';
    if(canvas) {
        resizeCanvas();
        clearSignature(); // reset on open
    }
}

function closeSignatureModal() {
    document.body.style.overflow = '';
    document.getElementById('signatureModal').style.display = 'none';
}

function clearSignature() {
    if(ctx && canvas) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
}

// Check if canvas is blank
function isCanvasBlank() {
    const blank = document.createElement('canvas');
    blank.width = canvas.width;
    blank.height = canvas.height;
    return canvas.toDataURL() === blank.toDataURL();
}

function submitWithSignature() {
    <?php if ($require_teaching_image === '1'): ?>
    const hasImage = document.getElementById('compressed_image_data').value !== "" || document.getElementById('imageUpload').files.length > 0 || <?= !empty($log['image_path']) ? 'true' : 'false' ?>;
    if (!hasImage) {
        alert("กรุณาอัปโหลดรูปภาพประกอบการสอน (ระบบบังคับ)");
        closeSignatureModal();
        return;
    }
    <?php endif; ?>

    if (isCanvasBlank()) {
        // Just submit without signature
        document.getElementById('signature_data').value = "";
    } else {
        // Compress the signature to fix slow uploads (Scale down and convert to JPEG)
        const tempCanvas = document.createElement('canvas');
        tempCanvas.width = 400; // Fixed small width
        tempCanvas.height = 200; // Fixed small height
        const tCtx = tempCanvas.getContext('2d');
        
        // Fill white background for JPEG format
        tCtx.fillStyle = '#ffffff';
        tCtx.fillRect(0, 0, tempCanvas.width, tempCanvas.height);
        
        // Draw the high-resolution signature onto the small canvas
        tCtx.drawImage(canvas, 0, 0, tempCanvas.width, tempCanvas.height);
        
        // Export as heavily compressed JPEG
        document.getElementById('signature_data').value = tempCanvas.toDataURL('image/jpeg', 0.6);
    }
    // Form is the closest form to the submit button
    document.querySelector('form').submit();
}
</script>
</div>

<style>
@media (max-width: 768px) {
    .footer-btn-container {
        padding-left: 0 !important;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>
