<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, ['teacher', 'assistant', 'admin']);

$page_title = "เช็คชื่อเข้าแถว";
$success = '';
$error = '';
$teacher_id = $_SESSION['user_id'];
$attendance_date = $_GET['date'] ?? date('Y-m-d');

// 1. Get Teacher's assigned homeroom
$stmt = $pdo->prepare("SELECT advisor_classroom FROM users WHERE id = ?");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();
$advisor_classroom = $teacher['advisor_classroom'] ?? null;

// 2. Fetch students for this homeroom
$students = [];
if ($advisor_classroom) {
    $stmt = $pdo->prepare("
        SELECT s.id, s.student_code, s.full_name, s.student_number 
        FROM students s
        JOIN classrooms c ON s.classroom_id = c.id
        WHERE c.room_name = ?
        ORDER BY CAST(s.student_number AS UNSIGNED) ASC, s.student_code ASC
    ");
    $stmt->execute([$advisor_classroom]);
    $students = $stmt->fetchAll();
}

// 3. Handle Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['attendance'])) {
    if (!$advisor_classroom) {
        $error = "คุณไม่มีห้องเรียนที่ปรึกษา ไม่สามารถบันทึกข้อมูลได้";
    } else {
        $date_to_save = $_POST['attendance_date'];
        
        // Handle Signature Upload
        $signature_path = null;
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
                        $filename = uniqid('sig_hr_', true) . '.' . $ext;
                        if (file_put_contents($upload_dir . $filename, $data)) {
                            $signature_path = 'uploads/signatures/' . $filename;
                        }
                    }
                }
            }
        }
        
        $pdo->beginTransaction();
        try {
            // Prepared statements for insertion/update
            $check_stmt = $pdo->prepare("SELECT id, signature_path FROM homeroom_attendance WHERE student_id = ? AND attendance_date = ?");
            $update_stmt = $pdo->prepare("UPDATE homeroom_attendance SET status = ?, recorded_by_teacher_id = ?, signature_path = COALESCE(?, signature_path) WHERE id = ?");
            $insert_stmt = $pdo->prepare("INSERT INTO homeroom_attendance (student_id, attendance_date, status, recorded_by_teacher_id, signature_path) VALUES (?, ?, ?, ?, ?)");
            
            foreach ($_POST['attendance'] as $student_id => $status) {
                 $check_stmt->execute([$student_id, $date_to_save]);
                 $existing = $check_stmt->fetch();
                 
                 if ($existing) {
                     $update_stmt->execute([$status, $teacher_id, $signature_path, $existing['id']]);
                 } else {
                     $insert_stmt->execute([$student_id, $date_to_save, $status, $teacher_id, $signature_path]);
                 }
            }
            $pdo->commit();
            $success = "บันทึกการเช็คชื่อของวันที่ " . date('d/m/Y', strtotime($date_to_save)) . " เรียบร้อยแล้ว";
            
            // Check for Late >= 3 times in this month
            $month = date('m', strtotime($date_to_save));
            $year = date('Y', strtotime($date_to_save));
            
            // Get all discipline teachers
            $stmt_disc = $pdo->query("SELECT ur.user_id FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE r.role_name = 'discipline'");
            $discipline_teachers = $stmt_disc ? $stmt_disc->fetchAll(PDO::FETCH_COLUMN) : [];

            foreach ($_POST['attendance'] as $student_id => $status) {
                if ($status === 'late') {
                    $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM homeroom_attendance WHERE student_id = ? AND status = 'late' AND MONTH(attendance_date) = ? AND YEAR(attendance_date) = ?");
                    $stmt_count->execute([$student_id, $month, $year]);
                    $late_count = (int)$stmt_count->fetchColumn();
                    
                    if ($late_count >= 3) {
                        $stmt_st = $pdo->prepare("SELECT full_name, student_code FROM students WHERE id = ?");
                        $stmt_st->execute([$student_id]);
                        $st = $stmt_st->fetch();
                        if ($st) {
                            $msg = "⚠️ แจ้งเตือน! นักเรียน " . $st['full_name'] . " (รหัส " . $st['student_code'] . ") มาสายสะสมเป็นครั้งที่ " . $late_count . " ของเดือนนี้";
                            
                            $stmt_chk = $pdo->prepare("SELECT id FROM notifications WHERE reference_type = 'late_alert' AND reference_id = ? AND message = ? LIMIT 1");
                            $stmt_chk->execute([$student_id, $msg]);
                            
                            if (!$stmt_chk->fetch()) {
                                $insert_notif = $pdo->prepare("INSERT INTO notifications (user_id, message, reference_type, reference_id, link) VALUES (?, ?, 'late_alert', ?, ?)");
                                
                                // Notify Homeroom Teacher
                                $insert_notif->execute([$teacher_id, $msg, $student_id, 'discipline_student_history.php?student_id='.$student_id]);
                                
                                // Notify Discipline Teachers
                                foreach ($discipline_teachers as $dt_id) {
                                    if ($dt_id != $teacher_id) { // don't duplicate if HR is also discipline
                                        $insert_notif->execute([$dt_id, $msg, $student_id, 'discipline_student_history.php?student_id='.$student_id]);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage();
        }
        
        if (empty($error)) {
            header("Location: teacher_home.php?success=" . urlencode("บันทึกการเช็คชื่อเข้าแถวเรียบร้อยแล้ว"));
            exit();
        }
    }
}

// 4. Load Saved Attendance (if editing previous day or re-loading today)
$saved_attendance = [];
if ($advisor_classroom) {
    $stmt = $pdo->prepare("SELECT student_id, status FROM homeroom_attendance WHERE attendance_date = ? AND student_id IN (SELECT id FROM students WHERE classroom_id = (SELECT id FROM classrooms WHERE room_name = ? LIMIT 1))");
    $stmt->execute([$attendance_date, $advisor_classroom]);
    while ($row = $stmt->fetch()) {
        $saved_attendance[$row['student_id']] = $row['status'];
    }
}

include '../../includes/header.php';
?>

<div style="max-width: 800px; margin: 0 auto; padding-bottom: 100px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0; font-size: 1.5rem; color: var(--text-main);">เช็คชื่อเข้าแถว</h2>
        <a href="teacher_home.php" class="btn btn-outline" style="border-radius: 20px; padding: 6px 16px;">⬅️ กลับ</a>
    </div>

    <?php if (!$advisor_classroom): ?>
        <div class="badge badge-warning" style="display: block; padding: 20px; font-size: 16px; text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 10px;">⚠️</div>
            คุณยังไม่ได้ถูกระบุให้เป็นครูที่ปรึกษาของห้องใดๆ<br>
            <span style="font-size: 14px; font-weight: normal; margin-top: 10px; display: inline-block;">กรุณาติดต่อผู้ดูแลระบบ (Admin) เพื่อตั้งค่าห้องเรียนที่ปรึกษาในโปรไฟล์ของคุณ</span>
        </div>
    <?php else: ?>

        <?php if ($success): ?>
            <div class="badge badge-success mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: center;">✅ <?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="badge badge-danger mb-3" style="display: block; padding: 15px; font-size: 15px; text-align: center;">❌ <?= $error ?></div>
        <?php endif; ?>

        <div style="background: #EEF2FF; border-radius: 8px; padding: 15px; margin-bottom: 15px; display: flex; flex-wrap: wrap; gap: 15px; align-items: center; justify-content: space-between;">
            <div>
                <span style="font-size: 0.9rem; color: var(--text-muted);">ห้องเรียนที่ปรึกษา</span><br>
                <strong style="font-size: 1.2rem; color: #1e40af;"><?= htmlspecialchars($advisor_classroom) ?></strong>
            </div>
            
            <form method="GET" style="display: flex; align-items: center; gap: 10px;">
                <label style="font-size: 0.9rem;">วันที่:</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($attendance_date) ?>" onchange="this.form.submit()" style="padding: 6px; font-size: 14px; width: auto;">
            </form>
        </div>
        
        <div style="margin-bottom: 20px; text-align: center;">
            <a href="teacher_homeroom_scan.php?date=<?= urlencode($attendance_date) ?>" class="btn" style="background: var(--primary-color); color: white; border-radius: 12px; padding: 12px 20px; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-size: 1.05rem; font-weight: 600; width: 100%; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <span class="material-symbols-rounded" style="font-size: 24px;">qr_code_scanner</span> สแกน QR เช็คชื่อเข้าแถว
            </a>
        </div>

        <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 10px 15px; margin-bottom: 20px; border-radius: 4px; font-size: 0.9rem; color: #b45309;">
            <strong>คำแนะนำ:</strong> กรุณากดเลือกสถานะการมาเรียนให้ครบทุกคน (หรือกดปุ่ม <strong>"มาทั้งหมด"</strong>) จากนั้นเลื่อนลงไปด้านล่างสุดเพื่อกดปุ่ม <strong>"บันทึกสมบูรณ์"</strong> ทุกครั้ง
        </div>

        <?php if (empty($students)): ?>
            <div style="text-align: center; padding: 40px 20px; border: 1px dashed var(--border-color); border-radius: 8px; color: var(--text-muted);">
                ไม่พบรายชื่อนักเรียนในห้อง <?= htmlspecialchars($advisor_classroom) ?>
            </div>
        <?php else: ?>
            <form method="POST" id="attendanceForm">
                <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($attendance_date) ?>">
                
                <!-- Summary bar floating on mobile -->
                <div style="display: flex; justify-content: space-between; align-items: center; background: var(--bg-main); padding: 15px 0; position: sticky; top: 56px; z-index: 10; flex-wrap: wrap; gap: 10px;">
                    <div style="font-size: 1rem; color: var(--text-main); font-weight: 600;">
                        รวม <strong><?= count($students) ?></strong> คน
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <!-- Bulk select helpers -->
                        <button type="button" class="btn btn-outline" style="padding: 6px 12px; font-size: 0.85rem; border-radius: 20px; color: var(--text-main);" onclick="setAll('present')">✅ มาทั้งหมด</button>
                        <button type="button" class="btn btn-outline" style="padding: 6px 12px; font-size: 0.85rem; border-radius: 20px; color: var(--text-main);" onclick="setAll('leave')">📄 ลาทั้งหมด</button>
                        <button type="button" class="btn btn-outline" style="padding: 6px 12px; font-size: 0.85rem; border-radius: 20px; color: var(--text-main);" onclick="setAll('absent')">❌ ขาดทั้งหมด</button>
                    </div>
                </div>

                <div style="margin-top: 10px;">
                    <?php foreach ($students as $s): ?>
                        <?php 
                        // Auto-fill logic: Default to 'present' as requested, but track if it's actually saved
                        $current_status = $saved_attendance[$s['id']] ?? 'present'; 
                        $is_saved = isset($saved_attendance[$s['id']]);
                        ?>
                        <div style="margin-bottom: 4px; padding: 8px 10px; border-radius: 8px; background: white; border: 1px solid <?= $is_saved ? '#bbf7d0' : '#f1f5f9' ?>; display:flex; flex-direction: column; gap: 4px; position: relative;">
                            
                            <?php if ($is_saved): ?>
                            <div style="position: absolute; top: -5px; right: -5px; background: #22c55e; color: white; font-size: 10px; padding: 2px 6px; border-radius: 10px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                บันทึกแล้ว
                            </div>
                            <?php endif; ?>

                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div style="display: flex; align-items: center; gap: 4px;">
                                    <span style="display: inline-block; background: var(--bg-hover); padding: 2px 6px; border-radius: 4px; font-size: 11px; color: var(--text-muted); font-weight: bold; min-width: 35px; text-align: center;"><?= htmlspecialchars($s['student_number'] ?? '-') ?></span>
                                    <strong style="font-size: 1rem;"><?= htmlspecialchars($s['full_name']) ?></strong>
                                </div>
                                <div style="font-size: 11px; color: var(--text-muted);"><?= htmlspecialchars($s['student_code']) ?></div>
                            </div>
                            
                            <!-- Custom Radio Box for Mobile Touch -->
                            <div class="attendance-options" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px;">
                                <?php
                                $options = [
                                    'present' => ['label' => 'มา', 'color' => 'var(--success-color)', 'bg' => '#dcfce7'],
                                    'late' => ['label' => 'สาย', 'color' => 'var(--warning-color)', 'bg' => '#fef08a', 'disabled' => true],
                                    'leave' => ['label' => 'ลา', 'color' => '#1d4ed8', 'bg' => '#e0e7ff'],
                                    'absent' => ['label' => 'ขาด', 'color' => 'var(--danger-color)', 'bg' => '#fee2e2']
                                ];
                                ?>
                                <?php foreach ($options as $val => $opt): ?>
                                    <?php 
                                        $is_disabled = !empty($opt['disabled']);
                                        $opacity = $is_disabled ? '0.4' : '1';
                                        $cursor = $is_disabled ? 'not-allowed' : 'pointer';
                                    ?>
                                    <label style="cursor: <?= $cursor ?>; position: relative; margin: 0; opacity: <?= $opacity ?>;">
                                        <input type="radio" <?= $is_disabled ? 'disabled' : 'required' ?> name="attendance[<?= $s['id'] ?>]" value="<?= $val ?>" <?= $current_status == $val ? 'checked' : '' ?> class="hide-radio item-<?= $val ?>">
                                        <div class="radio-card" style="text-align: center; padding: 6px 4px; border-radius: 8px; font-size: 13px; transition: all 0.2s ease; font-weight: 500;">
                                            <?= $opt['label'] ?>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <input type="hidden" name="signature_data" id="signature_data">

                <!-- Inline save button -->
                <div style="margin-top: 30px; margin-bottom: 20px;">
                    <button type="button" class="btn btn-primary btn-block" style="font-size: 1.1rem; padding: 14px; border-radius: 14px; font-weight: 600; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);" onclick="openSignatureModal()">✅ บันทึกสมบูรณ์ (พร้อมลายเซ็น)</button>
                </div>

                
                </div>

            </form>
        <?php endif; ?>

    <?php endif; ?>
</div>

<style>
/* CSS for making standard radios look like tappable cards */
.hide-radio {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.radio-card {
    background-color: var(--bg-hover);
    color: var(--text-muted);
    border: 1px solid transparent !important;
}
.hide-radio.item-present:checked + .radio-card {
    background-color: #bbf7d0;
    color: #166534;
    font-weight: 600;
}
.hide-radio.item-late:checked + .radio-card {
    background-color: #fde047;
    color: #854d0e;
    font-weight: 600;
}
.hide-radio.item-leave:checked + .radio-card {
    background-color: #bfdbfe;
    color: #1e3a8a;
    font-weight: 600;
}
.hide-radio.item-absent:checked + .radio-card {
    background-color: #fecaca;
    color: #991b1b;
    font-weight: 600;
}

/* Adjust floating button layout when sidebar is hidden on mobile */
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
function setAll(statusValue) {
    const radios = document.querySelectorAll('.hide-radio.item-' + statusValue);
    radios.forEach(r => r.checked = true);
}

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
    document.getElementById('attendanceForm').submit();
}
</script>

<?php include '../../includes/footer.php'; ?>
