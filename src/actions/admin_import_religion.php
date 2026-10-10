<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'admin');

$page_title = "นำเข้าข้อมูลศาสนา (CSV)";
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    
    if ($file['error'] == 0) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $raw_content = file_get_contents($file['tmp_name']);
            $bom = pack('H*','EFBBBF');
            $raw_content = preg_replace("/^$bom/", '', $raw_content);
            if (!mb_check_encoding($raw_content, 'UTF-8')) {
                $converted = @mb_convert_encoding($raw_content, 'UTF-8', 'Windows-874, TIS-620, ISO-8859-1');
                if ($converted) $raw_content = $converted;
            }

            $handle = fopen('php://temp', 'r+');
            fwrite($handle, $raw_content);
            rewind($handle);

            if ($handle !== FALSE) {
                // skip header
                fgetcsv($handle, 1000, ",");
                
                $updated = 0;
                $skipped = 0;
                $not_found_codes = [];
                
                $pdo->beginTransaction();
                try {
                    $checkStmt = $pdo->prepare("SELECT id, religion FROM students WHERE student_code = ?");
                    $updateStmt = $pdo->prepare("UPDATE students SET religion = ? WHERE id = ?");
                    
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $student_code = trim($data[0] ?? '');
                        // Fix for Excel stripping leading zeros (assume 5 digit codes typically)
                        if (is_numeric($student_code) && strlen($student_code) > 0 && strlen($student_code) < 5) {
                            $student_code = str_pad($student_code, 5, "0", STR_PAD_LEFT);
                        }
                        
                        $religion_raw = trim($data[1] ?? '');
                        
                        if ($student_code && $religion_raw) {
                            // Map thai text to English DB enum if needed
                            $religion = 'Buddhism';
                            if (strpos($religion_raw, 'อิสลาม') !== false || strtolower($religion_raw) == 'islam') $religion = 'Islam';
                            elseif (strpos($religion_raw, 'คริสต์') !== false || strtolower($religion_raw) == 'christianity') $religion = 'Christianity';
                            elseif (strpos($religion_raw, 'พุทธ') !== false || strtolower($religion_raw) == 'buddhism') $religion = 'Buddhism';
                            else $religion = 'Other';

                            $checkStmt->execute([$student_code]);
                            $student = $checkStmt->fetch();
                            
                            if ($student) {
                                if ($student['religion'] !== $religion) {
                                    $updateStmt->execute([$religion, $student['id']]);
                                    $updated++;
                                } else {
                                    $skipped++;
                                }
                            } else {
                                $not_found_codes[] = $student_code;
                            }
                        }
                    }
                    $pdo->commit();
                    
                    // Build HTML success message
                    $success = "<strong>สรุปผลการนำเข้าข้อมูล:</strong><br>";
                    $success .= "<ul>";
                    $success .= "<li>✅ อัปเดตข้อมูลสำเร็จ: <b>$updated</b> รายการ</li>";
                    $success .= "<li>⏩ ข้อมูลตรงกันอยู่แล้ว (ข้าม): <b>$skipped</b> รายการ</li>";
                    $success .= "<li>❌ ไม่พบรหัสนักเรียนในระบบ: <b>" . count($not_found_codes) . "</b> รายการ</li>";
                    $success .= "</ul>";
                    
                    if (count($not_found_codes) > 0) {
                        $success .= "<div style='margin-top: 10px; padding: 10px; background: #fff; border: 1px solid #fecaca; border-radius: 6px; max-height: 150px; overflow-y: auto;'>";
                        $success .= "<strong style='color: #991b1b;'>รหัสที่ระบบหาไม่พบ:</strong><br>";
                        $success .= "<span style='color: #666; font-size: 0.9rem; word-break: break-all; line-height: 1.6;'>" . htmlspecialchars(implode(', ', $not_found_codes)) . "</span>";
                        $success .= "</div>";
                    }
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
                }
                fclose($handle);
            } else {
                $error = "ไม่สามารถอ่านไฟล์ได้";
            }
        } else {
            $error = "กรุณาอัปโหลดไฟล์นามสกุล .csv เท่านั้น";
        }
    } else {
        $error = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์";
    }
}

include '../../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 0 auto;">
    <h2 class="card-title">นำเข้าข้อมูลศาสนา (CSV)</h2>
    
    <div style="background: var(--bg-body); padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem;">
        <strong>รูปแบบไฟล์ CSV:</strong><br>
        คอลัมน์ A: รหัสนักเรียน<br>
        คอลัมน์ B: ศาสนา (พุทธ, อิสลาม, คริสต์, หรืออื่นๆ)<br>
        <em>* แถวแรก (Header) จะถูกละเว้น</em>
    </div>

    <?php if ($error): ?>
        <div class="badge badge-danger mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="badge badge-success mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;"><?= $success ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label class="form-label">เลือกไฟล์ CSV</label>
            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">เริ่มอัปเดตข้อมูล</button>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
