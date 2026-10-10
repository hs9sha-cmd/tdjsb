<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireLogin();

$user_roles = $_SESSION['roles'] ?? [];
$is_level_head = in_array('level_head', $user_roles) || in_array('admin', $user_roles) || in_array('executive', $user_roles);

if (!$is_level_head) {
    header("Location: " . getBaseUrl() . "/index.php");
    exit();
}

$page_title = "สร้างลิงก์อัปโหลดรูปถ่ายนักเรียน";
$user_id = $_SESSION['user_id'];

$classrooms = [];
// Level heads, admin, executive can see ALL classrooms
$stmt = $pdo->query("SELECT id, grade_level, room_number, room_name FROM classrooms ORDER BY grade_level ASC, room_number ASC");
$classrooms = $stmt->fetchAll();

$selected_classroom_id = $_GET['classroom_id'] ?? ($classrooms[0]['id'] ?? null);
$students = [];
$links = [];

if ($selected_classroom_id) {
    // Check if the teacher actually has access to this classroom
    $has_access = false;
    foreach ($classrooms as $c) {
        if ($c['id'] == $selected_classroom_id) $has_access = true;
    }
    
    if ($has_access) {
        // Fetch students in this class
        $stmt = $pdo->prepare("SELECT id, student_code, full_name, student_number, photo_path FROM students WHERE classroom_id = ? AND status = 'active' ORDER BY CAST(student_number AS UNSIGNED) ASC, student_code ASC");
        $stmt->execute([$selected_classroom_id]);
        $students = $stmt->fetchAll();
        
        // Fetch existing tokens
        $student_ids = array_column($students, 'id');
        if (count($student_ids) > 0) {
            $in = str_repeat('?,', count($student_ids) - 1) . '?';
            $stmt = $pdo->prepare("SELECT student_id, token, is_used FROM student_upload_tokens WHERE student_id IN ($in)");
            $stmt->execute($student_ids);
            $token_rows = $stmt->fetchAll();
            foreach ($token_rows as $row) {
                $links[$row['student_id']] = $row;
            }
        }
    }
}

// Handle Generate Tokens
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_tokens'])) {
    $cid = $_POST['classroom_id'];
    
    // Fetch active students for this classroom
    $stmt = $pdo->prepare("SELECT id FROM students WHERE classroom_id = ? AND status = 'active'");
    $stmt->execute([$cid]);
    $st_list = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $pdo->beginTransaction();
    try {
        $insertStmt = $pdo->prepare("INSERT INTO student_upload_tokens (student_id, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), is_used = 0");
        foreach ($st_list as $sid) {
            // Generate random 8 chars
            $token = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 8);
            $insertStmt->execute([$sid, $token]);
        }
        $pdo->commit();
        header("Location: teacher_upload_links.php?classroom_id=$cid&success=1");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// Handle Generate Single Token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_single_token'])) {
    $cid = $_POST['classroom_id'];
    $sid = $_POST['student_id'];
    
    try {
        $token = substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 8);
        $stmt = $pdo->prepare("INSERT INTO student_upload_tokens (student_id, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), is_used = 0");
        $stmt->execute([$sid, $token]);
        header("Location: teacher_upload_links.php?classroom_id=$cid&success=1");
        exit();
    } catch (Exception $e) {
        $error = "เกิดข้อผิดพลาด: " . $e->getMessage();
    }
}

// Get base URL for links
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$domain = $_SERVER['HTTP_HOST'];
$base_url = getBaseUrl();
$full_domain = $protocol . "://" . $domain . $base_url;

include '../../includes/header.php';
?>

<div class="card">
    <h2 class="card-title">🔗 สร้างลิงก์อัปโหลดรูปถ่ายนักเรียน</h2>
    <p style="color: var(--text-muted); font-size: 0.9rem;">
        ครูสามารถสร้าง "ลิงก์ลับเฉพาะกิจ" เพื่อส่งให้นักเรียนในที่ปรึกษา อัปโหลดรูปถ่ายของตนเองผ่านมือถือได้ 
        โดยไม่ต้องให้นักเรียนเข้าสู่ระบบ ลิงก์แต่ละเส้นจะใช้ได้แค่ 1 ครั้งเท่านั้น
    </p>

    <?php if (empty($classrooms)): ?>
        <div class="badge badge-warning" style="display:block; padding: 15px; font-size: 1rem; text-align: left;">
            คุณยังไม่มีห้องเรียนประจำชั้นในปีการศึกษานี้
        </div>
    <?php else: ?>
        <!-- Classroom Selector -->
        <form method="GET" style="margin-bottom: 20px; display:flex; gap:10px;">
            <select name="classroom_id" class="form-control" style="max-width: 300px;" onchange="this.form.submit()">
                <?php foreach ($classrooms as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selected_classroom_id == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['room_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if (isset($_GET['success'])): ?>
            <div class="badge badge-success mb-3" style="display: block; padding: 10px; font-size: 14px; text-align: left;">✅ สร้าง/รีเซ็ตลิงก์สำหรับห้องนี้เรียบร้อยแล้ว!</div>
        <?php endif; ?>

        <?php if (!empty($students)): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="font-size: 1.1rem; margin: 0;">รายชื่อนักเรียน</h3>
                
                <form method="POST" onsubmit="return confirm('การสร้างลิงก์ใหม่ จะทำให้ลิงก์เก่าที่ยังไม่ได้ใช้ ถูกยกเลิกทั้งหมด ต้องการดำเนินการต่อหรือไม่?');">
                    <input type="hidden" name="classroom_id" value="<?= $selected_classroom_id ?>">
                    <button type="submit" name="generate_tokens" class="btn btn-primary">🔄 สร้างลิงก์ให้ทั้งห้อง</button>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>เลขที่</th>
                            <th>รหัส</th>
                            <th>ชื่อ-สกุล</th>
                            <th>สถานะรูปถ่าย</th>
                            <th>ลิงก์อัปโหลด</th>
                            <th style="width: 100px;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $copy_text = "📌 ลิงก์สำหรับอัปโหลดรูปถ่ายนักเรียน\n(กดของใครของมัน ห้ามสลับกันนะ!)\n\n";
                        foreach ($students as $s): 
                            $sid = $s['id'];
                            $has_photo = !empty($s['photo_path']);
                            $token_info = $links[$sid] ?? null;
                            
                            $status_html = $has_photo ? '<span style="color:green;">✅ มีรูปแล้ว</span>' : '<span style="color:red;">❌ ยังไม่มี</span>';
                            
                            $link_url = "";
                            $link_html = "<span style='color:#888;'>- กรุณากดสร้างลิงก์ -</span>";
                            
                            if ($token_info) {
                                if ($token_info['is_used']) {
                                    $link_html = "<span style='color:green;'>ใช้งานแล้ว</span>";
                                } else {
                                    $link_url = $full_domain . "/upload_photo.php?t=" . $token_info['token'];
                                    $link_html = "<input type='text' value='$link_url' class='form-control' style='font-size:12px; padding:5px;' readonly onclick='this.select();'>";
                                    $copy_text .= $s['student_number'] . ". " . $s['full_name'] . "\n" . $link_url . "\n\n";
                                }
                            }
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($s['student_number']) ?></td>
                                <td><?= htmlspecialchars($s['student_code']) ?></td>
                                <td style="text-align:left;">
                                    <?php if($has_photo): ?>
                                        <img src="../../<?= htmlspecialchars($s['photo_path']) ?>" style="width:30px; height:30px; border-radius:50%; object-fit:cover; vertical-align:middle; margin-right:5px;">
                                    <?php endif; ?>
                                    <?= htmlspecialchars($s['full_name']) ?>
                                </td>
                                <td><?= $status_html ?></td>
                                <td style="width: 250px;"><?= $link_html ?></td>
                                <td>
                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('ยืนยันการรีเซ็ตลิงก์สำหรับ <?= htmlspecialchars($s['full_name']) ?> ใช่หรือไม่?');">
                                        <input type="hidden" name="classroom_id" value="<?= $selected_classroom_id ?>">
                                        <input type="hidden" name="student_id" value="<?= $sid ?>">
                                        <button type="submit" name="generate_single_token" class="btn btn-sm" style="padding: 4px 8px; font-size: 0.8rem; background-color: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; border-radius: 4px;" title="สร้างลิงก์ใหม่">🔄 รีเซ็ต</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($links) > 0): ?>
                <div style="margin-top: 30px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px;">
                    <h3 style="margin-top:0; font-size:1.1rem; color: var(--text-main);">📋 คัดลอกข้อความส่งให้ไลน์กลุ่ม</h3>
                    <p style="font-size: 0.85rem; color: var(--text-muted);">กดปุ่มด้านล่างเพื่อคัดลอกข้อความและลิงก์ทั้งหมด ไปวางในแชทกลุ่มนักเรียนได้เลย (ลิงก์ที่ถูกใช้งานแล้วจะไม่แสดง)</p>
                    <textarea id="copyArea" class="form-control" style="height: 150px; font-size: 0.85rem; margin-bottom: 10px;" readonly><?= htmlspecialchars($copy_text) ?></textarea>
                    <button class="btn btn-outline" onclick="copyToClipboard()">📋 คัดลอกข้อความ</button>
                </div>
            <?php endif; ?>
            
            <script>
            function copyToClipboard() {
                var copyText = document.getElementById("copyArea");
                copyText.select();
                copyText.setSelectionRange(0, 99999);
                document.execCommand("copy");
                alert("คัดลอกข้อความเรียบร้อยแล้ว! นำไปวางในแชทได้เลย");
            }
            </script>

        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
