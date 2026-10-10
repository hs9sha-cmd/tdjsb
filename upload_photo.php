<?php
// public page, no auth check needed
require_once 'config/database.php';

$stmt_set = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
$site_settings = $stmt_set ? $stmt_set->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$school_name = !empty($site_settings['school_name']) ? $site_settings['school_name'] : 'โรงเรียนของคุณ';
$school_logo = !empty($site_settings['school_logo']) ? $site_settings['school_logo'] : 'assets/images/school_logo.png';

$token = $_GET['t'] ?? '';
$error = '';
$success = '';
$student = null;

if (!$token) {
    $error = "ไม่พบรหัสอ้างอิง (Token ไม่ถูกต้อง)";
} else {
    // Lookup token
    $stmt = $pdo->prepare("
        SELECT st.id as token_id, st.is_used, s.id as student_id, s.full_name, s.student_code, s.student_number, s.photo_path 
        FROM student_upload_tokens st
        JOIN students s ON st.student_id = s.id
        WHERE st.token = ?
    ");
    $stmt->execute([$token]);
    $student = $stmt->fetch();
    
    if (!$student) {
        $error = "ลิงก์ไม่ถูกต้อง หรือหมดอายุแล้ว";
    } elseif ($student['is_used']) {
        $error = "ลิงก์นี้ถูกใช้งานไปแล้ว หากต้องการอัปโหลดใหม่ กรุณาแจ้งครูที่ปรึกษา";
    }
}

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $student && !$student['is_used'] && isset($_FILES['photo'])) {
    $file = $_FILES['photo'];
    if ($file['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $upload_dir = 'assets/uploads/students/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            // Generate unique filename using student_code and timestamp
            $new_filename = $student['student_code'] . '_' . time() . '.' . $ext;
            
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_filename)) {
                $photo_path = 'assets/uploads/students/' . $new_filename;
                
                $pdo->beginTransaction();
                try {
                    // Update student photo
                    $updateStmt = $pdo->prepare("UPDATE students SET photo_path = ? WHERE id = ?");
                    $updateStmt->execute([$photo_path, $student['student_id']]);
                    
                    // Mark token as used
                    $tokenStmt = $pdo->prepare("UPDATE student_upload_tokens SET is_used = 1 WHERE id = ?");
                    $tokenStmt->execute([$student['token_id']]);
                    
                    $pdo->commit();
                    $success = "บันทึกรูปภาพสำเร็จ ขอบคุณครับ/ค่ะ!";
                    // Refresh student data to hide upload form and show new photo
                    $student['is_used'] = 1; 
                    $student['photo_path'] = $photo_path;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
                }
            } else {
                $error = "เกิดข้อผิดพลาดในการย้ายไฟล์";
            }
        } else {
            $error = "รองรับเฉพาะไฟล์รูปภาพ (JPG, PNG, WEBP) เท่านั้น";
        }
    } else {
        $error = "เกิดข้อผิดพลาดในการอัปโหลดรูปภาพ กรุณาลองใหม่";
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปโหลดรูปถ่ายประจำตัวนักเรียน</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body { background: #f0f2f5; font-family: 'Prompt', sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .upload-card { background: white; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); max-width: 450px; width: 100%; overflow: hidden; }
        .upload-header { background: #1a7a45; color: white; padding: 25px 20px; text-align: center; }
        .upload-header h2 { margin: 0; font-size: 1.3rem; font-weight: 600; }
        .upload-body { padding: 30px 20px; text-align: center; }
        .student-name { font-size: 1.2rem; font-weight: 600; color: #333; margin-bottom: 20px; }
        .guideline-box { background: #f8fafc; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 4px; text-align: left; margin-bottom: 25px; font-size: 0.9rem; color: #475569; }
        .guideline-box strong { color: #b45309; display: block; margin-bottom: 5px; }
        .preview-container { margin: 20px auto; width: 150px; height: 180px; border: 2px dashed #cbd5e1; border-radius: 12px; display: flex; justify-content: center; align-items: center; overflow: hidden; background: #f8fafc; position: relative; }
        .preview-img { width: 100%; height: 100%; object-fit: cover; display: none; }
        .preview-placeholder { color: #94a3b8; font-size: 0.85rem; }
        .btn-upload { background: #2563eb; color: white; border: none; padding: 12px 20px; border-radius: 8px; width: 100%; font-size: 1rem; font-family: 'Prompt', sans-serif; cursor: pointer; font-weight: 600; transition: background 0.2s; }
        .btn-upload:hover { background: #1d4ed8; }
        .btn-submit { background: #16a34a; color: white; border: none; padding: 12px 20px; border-radius: 8px; width: 100%; font-size: 1rem; font-family: 'Prompt', sans-serif; cursor: pointer; font-weight: 600; margin-top: 15px; display: none; }
        .btn-submit:hover { background: #15803d; }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fecaca; }
        .alert-success { background: #dcfce7; color: #166534; padding: 20px; border-radius: 8px; border: 1px solid #bbf7d0; text-align: center; }
        .alert-success h3 { margin: 0 0 10px 0; font-size: 1.2rem; }
    </style>
</head>
<body>

<div class="upload-card">
    <div class="upload-header">
        <h2>อัปโหลดรูปถ่ายนักเรียน</h2>
        <div style="font-size: 0.85rem; opacity: 0.8; margin-top: 5px;">ระบบฐานข้อมูลโรงเรียน</div>
    </div>
    
    <div class="upload-body">
        <?php if ($error): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($success): ?>
            <div class="alert-success" style="margin-bottom: 20px;">
                <h3>🎉 สำเร็จ!</h3>
                <p><?= htmlspecialchars($success) ?></p>
            </div>
            <?php $student['is_used'] = 1; ?>
        <?php endif; ?>
        
        <?php if ($student && $student['is_used']): 
            // Generate ID Card for download
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $domain = $_SERVER['HTTP_HOST'];
            $base_url = str_replace('/upload_photo.php', '', $_SERVER['SCRIPT_NAME']);
            $profile_url_base = $protocol . "://" . $domain . $base_url . "/src/actions/student_profile.php?id=";
            
            $qr_data = urlencode($profile_url_base . $student['student_id']);
            $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={$qr_data}";
        ?>
            <div style="display: flex; flex-direction: column; align-items: center;">
                <h4 style="margin-top: 0; color: #1a7a45; margin-bottom: 15px;">บัตรประจำตัวของคุณ</h4>
                
                <div id="studentCard" style="width: 5.4cm; height: 8.5cm; border: 1px solid #ccc; border-radius: 8px; padding: 10px; background: #fff; display: flex; flex-direction: column; align-items: center; justify-content: space-between; text-align: center; box-sizing: border-box; position: relative; z-index: 1;">
                    
                    <img src="<?= htmlspecialchars($school_logo) ?>" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 130px; height: 130px; opacity: 0.1; z-index: -1; pointer-events: none;" alt="Watermark" onerror="this.src='assets/images/school_logo.png'">
                    
                    <div style="width: 100%; margin-top: 0;">
                        <h2 style="margin: 0 0 2px 0; font-size: 14px; font-weight: 700; color: #1e3a8a;"><?= htmlspecialchars($school_name) ?></h2>
                        <h3 style="margin: 0 0 5px 0; font-size: 12px; font-weight: 700; color: #1a7a45;">บัตรประจำตัวนักเรียน</h3>
                    </div>
                    
                    <?php if (!empty($student['photo_path'])): ?>
                        <img src="<?= htmlspecialchars($student['photo_path']) ?>" style="width: 70px; height: 90px; object-fit: cover; border: 2px solid #1a7a45; border-radius: 4px; margin-top: 0;" alt="Photo">
                    <?php else: ?>
                        <div style="width: 70px; height: 90px; border: 2px dashed #ccc; border-radius: 4px; margin-top: 0; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: #ccc;">👤</div>
                    <?php endif; ?>
                    
                    <div style="width: 100%; margin-top: 10px;">
                        <p style="margin: 2px 0; font-size: 11px;"><strong><?= htmlspecialchars($student['full_name']) ?></strong></p>
                        <p style="margin: 2px 0; font-size: 11px;">รหัส: <?= htmlspecialchars($student['student_code']) ?> | เลขที่: <?= htmlspecialchars($student['student_number'] ?? '-') ?></p>
                    </div>
                    
                    <div style="margin-bottom: 5px;">
                        <img src="<?= $qr_url ?>" style="width: 90px; height: 90px;" alt="QR Code">
                    </div>
                </div>
                
                <button type="button" class="btn-submit" style="display: block; margin-top: 20px; background: #2563eb;" onclick="downloadMyCard()">
                    💾 ดาวน์โหลดบัตรประจำตัว (บันทึกลงเครื่อง)
                </button>
            </div>
            
            <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
            <script>
            function downloadMyCard() {
                const btn = document.querySelector('.btn-submit');
                const originalText = btn.innerText;
                btn.innerText = '⏳ กำลังสร้างรูปภาพ...';
                btn.disabled = true;
                
                const card = document.getElementById('studentCard');
                const originalBorder = card.style.border;
                card.style.border = "none";
                
                // For better rendering on mobile
                window.scrollTo(0, 0);
                
                html2canvas(card, { scale: 3, useCORS: true, logging: false }).then(canvas => {
                    card.style.border = originalBorder;
                    const imgData = canvas.toDataURL('image/png');
                    
                    // Create a modal for mobile users to long-press
                    const modal = document.createElement('div');
                    modal.style.position = 'fixed';
                    modal.style.top = '0';
                    modal.style.left = '0';
                    modal.style.width = '100%';
                    modal.style.height = '100%';
                    modal.style.backgroundColor = 'rgba(0,0,0,0.95)';
                    modal.style.zIndex = '99999';
                    modal.style.display = 'flex';
                    modal.style.flexDirection = 'column';
                    modal.style.alignItems = 'center';
                    modal.style.justifyContent = 'center';
                    modal.style.padding = '20px';
                    modal.style.boxSizing = 'border-box';
                    
                    const closeBtn = document.createElement('button');
                    closeBtn.innerText = '❌ ปิดหน้าต่างนี้';
                    closeBtn.style.padding = '12px 24px';
                    closeBtn.style.marginBottom = '20px';
                    closeBtn.style.backgroundColor = '#ef4444';
                    closeBtn.style.color = '#fff';
                    closeBtn.style.border = 'none';
                    closeBtn.style.borderRadius = '30px';
                    closeBtn.style.fontSize = '16px';
                    closeBtn.style.fontWeight = 'bold';
                    closeBtn.onclick = () => document.body.removeChild(modal);
                    
                    const msg = document.createElement('p');
                    msg.innerHTML = '📱 <b>แตะค้างที่รูปภาพด้านล่าง</b><br>แล้วเลือก "บันทึกรูปภาพ" (Save Image)';
                    msg.style.color = '#fff';
                    msg.style.marginBottom = '20px';
                    msg.style.textAlign = 'center';
                    msg.style.fontSize = '16px';
                    msg.style.lineHeight = '1.5';
                    
                    const img = document.createElement('img');
                    img.src = imgData;
                    img.style.maxWidth = '100%';
                    img.style.maxHeight = '65vh';
                    img.style.borderRadius = '10px';
                    img.style.boxShadow = '0 0 20px rgba(255,255,255,0.2)';
                    
                    modal.appendChild(closeBtn);
                    modal.appendChild(msg);
                    modal.appendChild(img);
                    
                    document.body.appendChild(modal);
                    
                    btn.innerText = originalText;
                    btn.disabled = false;
                    
                    // Try to auto-download for PC users
                    if (!/iPhone|iPad|iPod|Android/i.test(navigator.userAgent)) {
                        const link = document.createElement('a');
                        link.download = 'My_ID_Card_<?= htmlspecialchars($student['student_code']) ?>.png';
                        link.href = imgData;
                        link.click();
                    }
                }).catch(err => {
                    alert('เกิดข้อผิดพลาดในการสร้างรูปภาพ กรุณาลองใหม่อีกครั้ง');
                    card.style.border = originalBorder;
                    btn.innerText = originalText;
                    btn.disabled = false;
                });
            }
            </script>
        <?php elseif ($student && !$student['is_used']): ?>
            
            <div class="student-name">
                ยินดีต้อนรับ <?= htmlspecialchars($student['full_name']) ?>
            </div>
            
            <div class="guideline-box">
                <strong>📸 คำแนะนำในการถ่ายรูป:</strong>
                <ul style="margin: 0; padding-left: 20px;">
                    <li>ถ่ายภาพ<b>หน้าตรง</b> เห็นใบหน้าชัดเจน</li>
                    <li>สวมใส่<b>ชุดเครื่องแบบนักเรียน</b> หรือชุดที่สุภาพ</li>
                    <li><b>ไม่สวมหมวก</b> และ <b>ไม่สวมแว่นตาดำ</b></li>
                    <li>แสงสว่างเพียงพอ ภาพไม่เบลอ</li>
                </ul>
            </div>
            
            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="file" name="photo" id="photoInput" accept="image/*" style="display: none;" required>
                
                <div class="preview-container" id="previewContainer">
                    <div class="preview-placeholder" id="previewPlaceholder">
                        <div style="font-size: 2rem; margin-bottom: 10px;">👤</div>
                        เลือกรูปภาพ<br>หรือเปิดกล้อง
                    </div>
                    <img id="previewImg" class="preview-img">
                </div>
                
                <button type="button" class="btn-upload" id="btnSelectImage">📷 ถ่ายรูป หรือ เลือกรูปภาพ</button>
                <button type="submit" class="btn-submit" id="btnSubmit">✅ ตกลงและบันทึกรูปภาพ</button>
            </form>
            
            <script>
                const photoInput = document.getElementById('photoInput');
                const btnSelectImage = document.getElementById('btnSelectImage');
                const previewImg = document.getElementById('previewImg');
                const previewPlaceholder = document.getElementById('previewPlaceholder');
                const btnSubmit = document.getElementById('btnSubmit');
                
                btnSelectImage.addEventListener('click', () => {
                    photoInput.click();
                });
                
                photoInput.addEventListener('change', function(e) {
                    if (this.files && this.files[0]) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            previewImg.src = e.target.result;
                            previewImg.style.display = 'block';
                            previewPlaceholder.style.display = 'none';
                            
                            // Change button text
                            btnSelectImage.textContent = '🔄 เปลี่ยนรูปภาพใหม่';
                            btnSelectImage.style.background = '#64748b';
                            
                            // Show submit button
                            btnSubmit.style.display = 'block';
                        }
                        reader.readAsDataURL(this.files[0]);
                    }
                });
            </script>
            
        <?php endif; ?>
    </div>
</div>

</body>
</html>
