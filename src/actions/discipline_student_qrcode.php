<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
requireRole($pdo, 'discipline');

$page_title = "พิมพ์บัตร QR Code ประจำตัวนักเรียน";

// Fetch classrooms
$classrooms = $pdo->query("SELECT id, room_name, grade_level, room_number FROM classrooms ORDER BY grade_level ASC, CAST(room_number AS UNSIGNED) ASC")->fetchAll();

$selected_classroom_id = $_GET['classroom_id'] ?? null;
$selected_classroom = null;
$students = [];

if ($selected_classroom_id) {
    $stmt_c = $pdo->prepare("SELECT * FROM classrooms WHERE id = ?");
    $stmt_c->execute([$selected_classroom_id]);
    $selected_classroom = $stmt_c->fetch();

    $stmt = $pdo->prepare("
        SELECT s.id, s.student_code, s.full_name, s.student_number, s.photo_path, c.room_name, c.grade_level, c.room_number 
        FROM students s 
        LEFT JOIN classrooms c ON s.classroom_id = c.id 
        WHERE s.classroom_id = ? AND s.status = 'active' 
        ORDER BY CAST(s.student_number AS UNSIGNED) ASC, s.student_code ASC
    ");
    $stmt->execute([$selected_classroom_id]);
    $students = $stmt->fetchAll();
}

// Base URL for QR codes
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$domain = $_SERVER['HTTP_HOST'];
$base_url = getBaseUrl();
$profile_url_base = $protocol . "://" . $domain . $base_url . "/src/actions/student_profile.php?id=";

include '../../includes/header.php';
?>

<div class="card no-print">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h2 class="card-title" style="margin-bottom: 4px;">🖨 พิมพ์บัตรประจำตัวนักเรียน (QR Code ID Card)</h2>
            <p style="color: #64748b; margin: 0; font-size: 0.9rem;">รูปแบบใหม่มาตรฐาน<?= htmlspecialchars($site_settings['school_name'] ?? 'โรงเรียนของคุณ') ?> พร้อมระบบสแกน QR Code</p>
        </div>
        <button type="button" class="btn btn-secondary" onclick="history.back()">กลับ</button>
    </div>

    <form method="GET" style="display:flex; gap:12px; align-items:center; flex-wrap: wrap; background: #f8fafc; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <label style="font-weight: 600; margin: 0; color: #334155;">เลือกห้องเรียน:</label>
            <select name="classroom_id" class="form-control" style="min-width: 220px;" required onchange="this.form.submit()">
                <option value="">-- กรุณาเลือกห้องเรียน --</option>
                <?php foreach ($classrooms as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selected_classroom_id == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['room_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <button type="submit" class="btn btn-primary" style="font-weight: 600;">🔍 แสดงข้อมูล</button>
        
        <?php if (!empty($students)): ?>
            <button type="button" class="btn btn-outline" style="background: white; border: 1.5px solid #0284c7; color: #0284c7; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;" onclick="window.print()">
                🖨 พิมพ์บัตรทั้งห้อง (A4)
            </button>
            <span style="color: #64748b; font-size: 0.88rem; margin-left: auto;">
                พบนักเรียนทั้งหมด <b><?= count($students) ?></b> คน
            </span>
        <?php endif; ?>
    </form>
</div>

<?php if (!empty($students)): ?>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800&display=swap');

        .qr-card-container { 
            display: flex; 
            flex-wrap: wrap; 
            gap: 28px; 
            justify-content: center; 
            padding: 20px 0;
        }
        .qr-card-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 12px;
        }

        /* ─── THE CARD ─── */
        .qr-card { 
            width: 280px; 
            height: 476px;
            position: relative; 
            overflow: hidden;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0,60,100,0.15), 0 2px 8px rgba(0,0,0,0.05);
            page-break-inside: avoid;
            box-sizing: border-box;
            user-select: none;
            font-family: 'Prompt', sans-serif;
            background: #ffffff;
        }

        /* ─── Top green accent bar ─── */
        .card-top-bar {
            height: 6px;
            background: linear-gradient(90deg, #1b5e20 0%, #2e7d32 30%, #43a047 60%, #fdd835 100%);
        }

        /* ─── Decorative corner accents ─── */
        .qr-card::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 140px;
            height: 140px;
            background: linear-gradient(135deg, rgba(46,125,50,0.08), rgba(253,216,53,0.06));
            border-radius: 50%;
            z-index: 0;
        }
        .qr-card::after {
            content: '';
            position: absolute;
            bottom: -50px;
            left: -50px;
            width: 130px;
            height: 130px;
            background: linear-gradient(315deg, rgba(46,125,50,0.06), rgba(253,216,53,0.04));
            border-radius: 50%;
            z-index: 0;
        }

        /* ─── School Header ─── */
        .card-header-section {
            position: relative;
            z-index: 2;
            text-align: center;
            padding: 10px 16px 6px;
        }
        .card-school-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 2px;
        }
        .card-school-logo {
            width: 36px;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 1px 3px rgba(0,0,0,0.12));
        }
        .card-school-name-block {
            text-align: left;
        }
        .card-school-name-th {
            font-size: 0.82rem;
            font-weight: 700;
            color: #1b5e20;
            line-height: 1.15;
        }
        .card-school-name-en {
            font-size: 0.46rem;
            font-weight: 400;
            color: #78909c;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            line-height: 1;
        }
        .card-title-label {
            display: inline-block;
            background: linear-gradient(135deg, #2e7d32, #43a047);
            padding: 3px 16px;
            border-radius: 20px;
            font-size: 0.56rem;
            font-weight: 600;
            color: #ffffff;
            letter-spacing: 1.5px;
            margin-top: 4px;
        }

        /* ─── Photo Section ─── */
        .card-photo-section {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: center;
            padding: 8px 0 0;
        }
        .card-photo-frame {
            width: 120px;
            height: 155px;
            border-radius: 16px;
            overflow: hidden;
            border: 3px solid #e8f5e9;
            box-shadow: 0 4px 16px rgba(27,94,32,0.12);
            background: #f1f8e9;
        }
        .card-photo-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .card-photo-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: #a5d6a7;
            background: #f1f8e9;
        }

        /* ─── Info Card ─── */
        .card-info-glass {
            position: relative;
            z-index: 2;
            margin: 8px 20px 0;
            padding: 7px 12px;
            background: #f9fbe7;
            border: 1px solid #e8f5e9;
            border-radius: 12px;
        }
        .info-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-bottom: 2px;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }
        .info-label {
            font-size: 0.58rem;
            font-weight: 400;
            color: #78909c;
            white-space: nowrap;
        }
        .info-code {
            font-size: 0.7rem;
            font-weight: 600;
            color: #1b5e20;
            letter-spacing: 1px;
        }
        .info-name {
            font-size: 0.92rem;
            font-weight: 700;
            color: #263238;
            text-align: center;
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.3;
        }
        .info-class {
            font-size: 0.68rem;
            font-weight: 500;
            color: #546e7a;
            text-align: center;
            letter-spacing: 0.3px;
        }
        .info-divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, #c8e6c9, transparent);
            margin: 4px 0;
        }

        /* ─── QR Code Section ─── */
        .card-qr-section {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: center;
            padding: 8px 0 0;
        }
        .card-qr-frame {
            width: 95px;
            height: 95px;
            padding: 5px;
            background: #ffffff;
            border-radius: 12px;
            border: 2px solid #e8f5e9;
            box-shadow: 0 3px 10px rgba(0,0,0,0.07);
        }
        .card-qr-frame img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 6px;
        }
        .card-qr-hint {
            text-align: center;
            font-size: 0.44rem;
            font-weight: 500;
            color: #90a4ae;
            margin-top: 5px;
            letter-spacing: 0.6px;
            position: relative;
            z-index: 2;
        }

        /* ─── Bottom accent stripe ─── */
        .card-bottom-stripe {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #fdd835 0%, #ffb300 40%, #2e7d32 100%);
            z-index: 3;
        }

        /* ─── Print ─── */
        @media print {
            body * { visibility: hidden; }
            .no-print { display: none !important; }
            #printableArea, #printableArea * { visibility: visible; }
            #printableArea { 
                position: absolute; 
                left: 0; 
                top: 0; 
                width: 100%; 
                padding: 0;
                margin: 0;
            }
            .qr-card-container { 
                gap: 12px; 
                justify-content: flex-start;
                padding: 0;
            }
            .qr-card-wrapper {
                margin-bottom: 12px;
                page-break-inside: avoid;
            }
            .qr-card { 
                width: 5.4cm; 
                height: 9.2cm; 
                box-shadow: none; 
                border-radius: 10px;
            }
        }
    </style>

    <div id="printableArea">
        <div class="qr-card-container">
            <?php foreach ($students as $s): 
                $qr_data = urlencode($profile_url_base . $s['id']);
                $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={$qr_data}";
                $photo_path = $s['photo_path'] ?? null;
                $room_name = $s['room_name'] ?? ($selected_classroom['room_name'] ?? '-');
                
                // Auto-scale font size for 1-line name
                $name_len = mb_strlen($s['full_name'], 'UTF-8');
                if ($name_len > 30) {
                    $name_font_size = '0.68rem';
                } elseif ($name_len > 24) {
                    $name_font_size = '0.74rem';
                } elseif ($name_len > 18) {
                    $name_font_size = '0.82rem';
                } else {
                    $name_font_size = '0.92rem';
                }
            ?>
                <div class="qr-card-wrapper">
                    <div class="qr-card" id="card-<?= $s['id'] ?>">

                        <!-- Top accent bar -->
                        <div class="card-top-bar"></div>

                        <!-- School Header -->
                        <div class="card-header-section">
                            <div class="card-school-badge">
                                <img src="../../<?= htmlspecialchars($site_settings['school_logo'] ?? 'assets/images/school_logo.png') ?>" class="card-school-logo" alt="Logo" onerror="this.src='../../assets/images/school_logo.png'">
                                <div class="card-school-name-block">
                                    <div class="card-school-name-th"><?= htmlspecialchars($site_settings['school_name'] ?? 'โรงเรียนของคุณ') ?></div>
                                </div>
                            </div>
                            <div class="card-title-label">บัตรประจำตัวนักเรียน</div>
                        </div>

                        <!-- Photo -->
                        <div class="card-photo-section">
                            <div class="card-photo-frame">
                                <?php if ($photo_path): ?>
                                    <img src="../../<?= htmlspecialchars($photo_path) ?>" alt="Photo" onerror="this.outerHTML='<div class=\'card-photo-placeholder\'>👤</div>'">
                                <?php else: ?>
                                    <div class="card-photo-placeholder">👤</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Student Info -->
                        <div class="card-info-glass">
                            <div class="info-row">
                                <span class="info-label">รหัส</span>
                                <span class="info-code"><?= htmlspecialchars($s['student_code']) ?></span>
                            </div>
                            <div class="info-divider"></div>
                            <div class="info-name" style="font-size: <?= $name_font_size ?>;" title="<?= htmlspecialchars($s['full_name']) ?>">
                                <?= htmlspecialchars($s['full_name']) ?>
                            </div>
                            <div class="info-class">
                                ชั้น <?= htmlspecialchars($room_name) ?> &nbsp;&middot;&nbsp; เลขที่ <?= htmlspecialchars($s['student_number'] ?? '-') ?>
                            </div>
                        </div>

                        <!-- QR Code -->
                        <div class="card-qr-section">
                            <div class="card-qr-frame">
                                <img src="<?= $qr_url ?>" alt="QR Code">
                            </div>
                        </div>
                        <div class="card-qr-hint">M&amp;M Service &amp; Wednesday</div>

                        <!-- Bottom accent -->
                        <div class="card-bottom-stripe"></div>
                    </div>
                    
                    <!-- Action Button -->
                    <div class="no-print" style="text-align: center; margin-top: 10px;">
                        <button type="button" class="btn btn-sm btn-outline" style="font-size: 12px; padding: 5px 16px; border-radius: 24px; background: white; border: 1.5px solid #00897b; color: #00695c; font-weight: 600; box-shadow: 0 2px 6px rgba(0,0,0,0.06); transition: all 0.2s;" onmouseover="this.style.background='#00897b';this.style.color='#fff'" onmouseout="this.style.background='white';this.style.color='#00695c'" onclick="downloadCard('<?= $s['id'] ?>', '<?= htmlspecialchars($s['student_code']) ?>')">
                            💾 ดาวน์โหลด
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
    function downloadCard(id, name) {
        const card = document.getElementById('card-' + id);
        
        html2canvas(card, { scale: 3, useCORS: true, logging: false }).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            
            if (/iPhone|iPad|iPod|Android/i.test(navigator.userAgent)) {
                const modal = document.createElement('div');
                modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.95);z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;';
                
                const closeBtn = document.createElement('button');
                closeBtn.innerText = '❌ ปิด';
                closeBtn.style.cssText = 'padding:12px 28px;margin-bottom:20px;background:#ef4444;color:#fff;border:none;border-radius:30px;font-size:16px;font-weight:bold;';
                closeBtn.onclick = () => document.body.removeChild(modal);
                
                const msg = document.createElement('p');
                msg.innerHTML = '📱 <b>แตะค้างที่รูปภาพ</b> แล้วเลือก "บันทึกรูปภาพ"';
                msg.style.cssText = 'color:#fff;margin-bottom:20px;text-align:center;font-size:16px;line-height:1.5;';
                
                const img = document.createElement('img');
                img.src = imgData;
                img.style.cssText = 'max-width:100%;max-height:65vh;border-radius:16px;box-shadow:0 0 30px rgba(255,255,255,0.2);';
                
                modal.appendChild(closeBtn);
                modal.appendChild(msg);
                modal.appendChild(img);
                document.body.appendChild(modal);
            } else {
                const link = document.createElement('a');
                link.download = 'Student_Card_' + name + '.png';
                link.href = imgData;
                link.click();
            }
        }).catch(() => {
            alert('เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง');
        });
    }
    </script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
