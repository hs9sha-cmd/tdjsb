<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_roles = $_SESSION['roles'] ?? [];
if (!in_array('teacher', $user_roles) && !in_array('assistant', $user_roles)) {
    echo "Access Denied";
    exit();
}

$teacher_id = $_SESSION['user_id'];
$attendance_date = $_GET['date'] ?? date('Y-m-d');

// Get teacher info
$stmt_teacher = $pdo->prepare("SELECT full_name, advisor_classroom FROM users WHERE id = ?");
$stmt_teacher->execute([$teacher_id]);
$teacher = $stmt_teacher->fetch();
$advisor_classroom = $teacher['advisor_classroom'];

if (!$advisor_classroom) {
    echo "คุณยังไม่ได้ถูกระบุให้เป็นครูที่ปรึกษาของห้องใดๆ";
    exit();
}

// Handle AJAX Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'scan') {
        $scan_data = trim($_POST['scan_data'] ?? '');
        
        $student_id = null;
        // Check if the scan data is a URL containing ?id=XXXX
        if (preg_match('/[?&]id=(\d+)/', $scan_data, $matches)) {
            $student_id = $matches[1];
        }

        if ($student_id !== null) {
            $stmt = $pdo->prepare("
                SELECT s.id, s.student_code, s.full_name, s.student_number, c.room_name 
                FROM students s
                JOIN classrooms c ON s.classroom_id = c.id
                WHERE s.id = ? AND s.status = 'active'
            ");
            $stmt->execute([$student_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT s.id, s.student_code, s.full_name, s.student_number, c.room_name 
                FROM students s
                JOIN classrooms c ON s.classroom_id = c.id
                WHERE s.student_code = ? AND s.status = 'active'
            ");
            $stmt->execute([$scan_data]);
        }
        
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($student) {
            if ($student['room_name'] !== $advisor_classroom) {
                echo json_encode(['success' => false, 'message' => 'นักเรียนคนนี้ไม่ได้อยู่ห้อง ' . $advisor_classroom . ' (อยู่ห้อง ' . $student['room_name'] . ')']);
                exit;
            }
            echo json_encode(['success' => true, 'student' => $student]);
        } else {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลนักเรียน (รหัส: ' . htmlspecialchars($scan_data) . ')']);
        }
        exit;
    }

    if ($action === 'save_batch') {
        $scanned_ids = json_decode($_POST['scanned_ids'] ?? '[]');
        if (!is_array($scanned_ids)) $scanned_ids = [];
        
        try {
            $pdo->beginTransaction();
            
            // Get all active students in this classroom
            $stmt_all = $pdo->prepare("
                SELECT s.id 
                FROM students s 
                JOIN classrooms c ON s.classroom_id = c.id 
                WHERE c.room_name = ? AND s.status = 'active'
            ");
            $stmt_all->execute([$advisor_classroom]);
            $all_students = $stmt_all->fetchAll(PDO::FETCH_COLUMN);

            $stmt_del = $pdo->prepare("DELETE FROM homeroom_attendance WHERE attendance_date = ? AND student_id = ?");
            $stmt_ins = $pdo->prepare("INSERT INTO homeroom_attendance (student_id, attendance_date, status, recorded_by_teacher_id) VALUES (?, ?, ?, ?)");

            foreach ($all_students as $sid) {
                $status = in_array($sid, $scanned_ids) ? 'present' : 'absent';
                
                $stmt_del->execute([$attendance_date, $sid]);
                $stmt_ins->execute([$sid, $attendance_date, $status, $teacher_id]);
            }

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

$page_title = "สแกนเช็คชื่อเข้าแถว";
include '../../includes/header.php';
?>

<div style="max-width: 600px; margin: 0 auto; padding-bottom: 50px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2 style="margin: 0; font-size: 1.3rem;">📷 สแกน QR เช็คชื่อเข้าแถว</h2>
        <a href="teacher_homeroom.php?date=<?= urlencode($attendance_date) ?>" class="btn btn-outline" style="border-radius: 20px; padding: 6px 12px; font-size: 0.85rem;">⬅️ กลับ</a>
    </div>

    <div style="background: #EEF2FF; border-radius: 8px; padding: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <div style="font-size: 0.8rem; color: #64748b;">ห้องเรียน</div>
            <strong style="font-size: 1.1rem; color: #1e40af;"><?= htmlspecialchars($advisor_classroom) ?></strong>
        </div>
        <div style="text-align: right;">
            <div style="font-size: 0.8rem; color: #64748b;">วันที่เช็คชื่อ</div>
            <strong style="font-size: 1.1rem; color: #1e40af;"><?= date('d/m/Y', strtotime($attendance_date)) ?></strong>
        </div>
    </div>

    <!-- Scanner Section -->
    <div class="card" style="text-align: center; padding: 15px; margin-bottom: 20px;">
        <div id="reader" style="width: 100%; border-radius: 12px; overflow: hidden; border: 2px solid #bfdbfe; margin-bottom: 15px;"></div>
        
        <button id="btnStartScan" type="button" class="btn btn-primary" style="padding: 10px; width: 100%; font-size: 1.05rem; border-radius: 10px;">เปิดกล้องสแกน</button>
        <button id="btnStopScan" type="button" class="btn btn-outline" style="display: none; padding: 10px; width: 100%; border-radius: 10px;">ปิดกล้อง</button>
        
        <!-- Status Notification -->
        <div id="scanResult" style="display:none; padding: 12px; border-radius: 8px; margin-top: 15px; font-weight: 600; font-size: 0.95rem; text-align: center;"></div>
    </div>

    <div class="card" style="padding: 15px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3 style="margin: 0; font-size: 1.1rem;">✅ รายชื่อที่สแกนแล้ว (<span id="scannedCount">0</span>)</h3>
            <button id="btnSaveBatch" type="button" class="btn" style="background: var(--success-color); color: white; border: none; padding: 6px 15px; border-radius: 20px; font-weight: bold; cursor: pointer;">✔️ บันทึกเข้าแถว</button>
        </div>
        <div style="font-size: 0.8rem; color: #ef4444; margin-bottom: 15px;">*นักเรียนที่ไม่ได้สแกนในรายการนี้ จะถูกบันทึกเป็น <strong>"ขาด"</strong> ทั้งหมด (สามารถกดเข้าไปแก้เป็น สาย/ลา ได้ภายหลัง)</div>
        
        <div id="scannedList" style="display: flex; flex-direction: column; gap: 8px; max-height: 400px; overflow-y: auto;">
            <!-- Scanned items go here -->
            <div id="emptyState" style="text-align: center; color: #94a3b8; padding: 20px 0; font-size: 0.9rem;">ยังไม่มีผู้สแกน</div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode"></script>
<script>
    let html5QrcodeScanner = null;
    let isProcessing = false;
    let scannedStudents = {}; // map of student_code -> student object

    const btnStartScan = document.getElementById('btnStartScan');
    const btnStopScan = document.getElementById('btnStopScan');
    const readerDiv = document.getElementById('reader');
    const scanResult = document.getElementById('scanResult');
    const scannedList = document.getElementById('scannedList');
    const scannedCount = document.getElementById('scannedCount');
    const emptyState = document.getElementById('emptyState');
    const btnSaveBatch = document.getElementById('btnSaveBatch');

    function playBeep(freq = 800, duration = 100) {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = freq;
            osc.type = 'sine';
            osc.start();
            setTimeout(() => {
                osc.stop();
                ctx.close();
            }, duration);
        } catch (e) {}
    }

    function renderScannedList() {
        const students = Object.values(scannedStudents);
        scannedCount.innerText = students.length;
        
        if (students.length === 0) {
            emptyState.style.display = 'block';
            scannedList.innerHTML = '';
            scannedList.appendChild(emptyState);
            return;
        }

        emptyState.style.display = 'none';
        scannedList.innerHTML = '';

        // Render latest first
        [...students].reverse().forEach(st => {
            const row = document.createElement('div');
            row.style.cssText = 'display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px;';
            row.innerHTML = `
                <div>
                    <div style="font-weight: 600; color: #166534; font-size: 0.95rem;">${st.full_name}</div>
                    <div style="font-size: 0.8rem; color: #15803d;">เลขที่ ${st.student_number} | รหัส ${st.student_code}</div>
                </div>
                <div style="background: #22c55e; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: bold;">มา</div>
            `;
            scannedList.appendChild(row);
        });
    }

    function processScan(decodedText) {
        if (isProcessing) return;
        
        // Check if already scanned locally
        if (scannedStudents[decodedText]) {
            showResult('⚠️ สแกนซ้ำ: ' + scannedStudents[decodedText].full_name, 'warning');
            playBeep(400, 200);
            return;
        }

        isProcessing = true;
        showResult('กำลังตรวจสอบ...', 'info');

        const formData = new FormData();
        formData.append('action', 'scan');
        formData.append('scan_data', decodedText);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                scannedStudents[decodedText] = data.student;
                renderScannedList();
                showResult('✅ เช็คมา: ' + data.student.full_name, 'success');
                playBeep(800, 100);
                if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
            } else {
                showResult('❌ ' + data.message, 'error');
                playBeep(200, 300);
                if (navigator.vibrate) navigator.vibrate(300);
            }
            setTimeout(() => { isProcessing = false; }, 1000);
        })
        .catch(err => {
            showResult('❌ เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
            setTimeout(() => { isProcessing = false; }, 1500);
        });
    }

    function showResult(text, type) {
        scanResult.style.display = 'block';
        scanResult.innerHTML = text;
        if (type === 'success') {
            scanResult.style.background = '#dcfce7';
            scanResult.style.color = '#166534';
            scanResult.style.border = '1px solid #bbf7d0';
        } else if (type === 'warning') {
            scanResult.style.background = '#fef3c7';
            scanResult.style.color = '#92400e';
            scanResult.style.border = '1px solid #fde68a';
        } else if (type === 'error') {
            scanResult.style.background = '#fee2e2';
            scanResult.style.color = '#991b1b';
            scanResult.style.border = '1px solid #fecaca';
        } else {
            scanResult.style.background = '#f1f5f9';
            scanResult.style.color = '#334155';
            scanResult.style.border = '1px solid #cbd5e1';
        }
        
        setTimeout(() => {
            if (!isProcessing) scanResult.style.display = 'none';
        }, 2000);
    }

    btnStartScan.addEventListener('click', () => {
        if (!html5QrcodeScanner) {
            html5QrcodeScanner = new Html5Qrcode("reader");
        }
        const config = { fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 };
        
        html5QrcodeScanner.start(
            { facingMode: "environment" }, 
            config, 
            (decodedText, decodedResult) => {
                processScan(decodedText);
            },
            (errorMessage) => {}
        ).then(() => {
            btnStartScan.style.display = 'none';
            btnStopScan.style.display = 'block';
        }).catch(err => {
            alert("ไม่สามารถเปิดกล้องได้ กรุณาตรวจสอบการอนุญาตใช้งานกล้อง");
        });
    });

    btnStopScan.addEventListener('click', () => {
        if (html5QrcodeScanner) {
            html5QrcodeScanner.stop().then(() => {
                btnStopScan.style.display = 'none';
                btnStartScan.style.display = 'block';
            }).catch(err => console.log(err));
        }
    });

    btnSaveBatch.addEventListener('click', () => {
        const studentIds = Object.values(scannedStudents).map(s => s.id);
        
        if (studentIds.length === 0) {
            if (!confirm('คุณยังไม่ได้สแกนนักเรียนเลย (ระบบจะบันทึกว่า "ขาด" ทุกคน) ยืนยันใช่หรือไม่?')) {
                return;
            }
        } else {
            if (!confirm(`ยืนยันการบันทึกนักเรียน ${studentIds.length} คนเป็น "มา"?\n(คนที่เหลือในห้องจะถูกบันทึกเป็น "ขาด" ทั้งหมด)`)) {
                return;
            }
        }

        btnSaveBatch.disabled = true;
        btnSaveBatch.innerText = 'กำลังบันทึก...';

        const formData = new FormData();
        formData.append('action', 'save_batch');
        formData.append('scanned_ids', JSON.stringify(studentIds));

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                window.location.href = `teacher_homeroom.php?date=<?= urlencode($attendance_date) ?>&success=` + encodeURIComponent('บันทึกเช็คชื่อจากการสแกนเรียบร้อยแล้ว');
            } else {
                alert('เกิดข้อผิดพลาด: ' + data.message);
                btnSaveBatch.disabled = false;
                btnSaveBatch.innerText = '✔️ บันทึกเข้าแถว';
            }
        })
        .catch(err => {
            alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            btnSaveBatch.disabled = false;
            btnSaveBatch.innerText = '✔️ บันทึกเข้าแถว';
        });
    });
</script>
