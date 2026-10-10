<?php
session_start();
require_once '../../config/database.php';

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('discipline', $user_roles) && !in_array('admin', $user_roles) && !in_array('executive', $user_roles)) {
    echo "Access Denied";
    exit();
}

$stmt_term = $pdo->query("SELECT id FROM academic_terms WHERE is_active = 1 LIMIT 1");
$term_id = $stmt_term->fetchColumn();

// Download Template
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=behavior_scores_template.csv');
    $output = fopen('php://output', 'w');
    // Output UTF-8 BOM for Excel compatibility
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['student_code', 'current_score']);
    fputcsv($output, ['12345', '85']);
    fputcsv($output, ['12346', '100']);
    fclose($output);
    exit();
}

$page_title = "นำเข้าคะแนนความประพฤติ (CSV)";
include '../../includes/header.php';

$success = '';
$error = '';
$imported_count = 0;

if (!$term_id) {
    echo "<div class='card'><div class='badge badge-danger'>ไม่พบปีการศึกษาที่เปิดใช้งาน</div></div>";
    include '../../includes/footer.php';
    exit();
}

// Handle Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    
    if (empty($file)) {
        $error = "กรุณาเลือกไฟล์ CSV";
    } else {
        $raw_content = file_get_contents($file);
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
            // Skip header
            $header = fgetcsv($handle, 1000, ",");
            
            $pdo->beginTransaction();
            try {
                $stmt_student = $pdo->prepare("SELECT id FROM students WHERE student_code = ? AND status = 'active'");
                $stmt_insert = $pdo->prepare("INSERT INTO student_behaviors (student_id, academic_term_id, initial_score, current_score) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE current_score = ?");
                
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if (count($data) >= 2) {
                        $student_code = trim($data[0]);
                        if (is_numeric($student_code) && strlen($student_code) > 0 && strlen($student_code) < 5) {
                            $student_code = str_pad($student_code, 5, "0", STR_PAD_LEFT);
                        }
                        $score = (int)trim($data[1]);
                        
                        $stmt_student->execute([$student_code]);
                        $student_id = $stmt_student->fetchColumn();
                        
                        if ($student_id) {
                            $stmt_insert->execute([$student_id, $term_id, $score, $score, $score]);
                            $imported_count++;
                        }
                    }
                }
                $pdo->commit();
                $success = "นำเข้าคะแนนสำเร็จ จำนวน " . $imported_count . " รายการ";
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
            }
            fclose($handle);
        } else {
            $error = "ไม่สามารถอ่านไฟล์ได้";
        }
    }
}
?>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 class="card-title" style="margin: 0;">นำเข้าคะแนนความประพฤติ (ช่วงกลางเทอม)</h2>
        <a href="discipline_dashboard.php" class="btn btn-secondary">กลับหน้าหลัก</a>
    </div>

    <?php if($success): ?><div class="badge badge-success mb-3" style="display: block; padding: 10px;"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="badge badge-danger mb-3" style="display: block; padding: 10px;"><?= $error ?></div><?php endif; ?>

    <div style="background: #e0f2fe; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <h4 style="margin-top: 0; color: #0284c7;">คำแนะนำการเตรียมไฟล์ CSV</h4>
        <ul style="margin-bottom: 15px; color: #0f172a;">
            <li>ไฟล์ต้องมีนามสกุล <strong>.csv</strong></li>
            <li>ประกอบด้วย 2 คอลัมน์: <code>student_code</code> (รหัสนักเรียน) และ <code>current_score</code> (คะแนนความประพฤติปัจจุบัน)</li>
            <li>คะแนนที่นำเข้าจะไปแทนที่ (Overwrite) คะแนนความประพฤติปัจจุบันของนักเรียนคนนั้นทันที</li>
        </ul>
        <a href="?download_template=1" class="btn btn-primary" style="background-color: #0284c7;">ดาวน์โหลดไฟล์เทมเพลต (CSV)</a>
    </div>

    <form method="POST" enctype="multipart/form-data" style="background: var(--apple-bg); padding: 30px; border-radius: 8px; text-align: center;">
        <div style="margin-bottom: 20px;">
            <label style="display: block; font-weight: bold; margin-bottom: 10px; font-size: 1.1rem;">เลือกไฟล์ CSV จากเครื่องของคุณ</label>
            <input type="file" name="csv_file" accept=".csv" required style="padding: 10px; border: 1px dashed var(--border-color); border-radius: 5px; width: 100%; max-width: 400px; display: inline-block;">
        </div>
        <button type="submit" class="btn btn-primary" style="padding: 12px 30px; font-size: 1.1rem;">อัปโหลดและนำเข้าข้อมูล</button>
    </form>
</div>

<?php include '../../includes/footer.php'; ?>
