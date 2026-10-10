<?php
session_start();
require_once '../../config/database.php';

// Check admin role
if (!isset($_SESSION['user_id']) || !in_array('admin', $_SESSION['roles'] ?? [])) {
    header("Location: ../../login.php");
    exit();
}

$page_title = "จัดการประกาศ (Highlight)";
include '../../includes/header.php';

// Handle Form Submissions
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action == 'add_highlight') {
        $title = trim($_POST['title'] ?? '');
        $target_link = trim($_POST['target_link'] ?? '');
        $color_theme = $_POST['color_theme'] ?? 'green';

        // Check if already 4 active
        $cnt = $pdo->query("SELECT COUNT(*) FROM highlights WHERE is_active = 1")->fetchColumn();
        if (!$title) {
            $error_msg = "กรุณากรอกหัวข้อประกาศ";
        } elseif ($cnt >= 4) {
            $error_msg = "มีประกาศที่เปิดใช้งานครบ 4 ใบแล้ว กรุณาปิดใบเดิมก่อน";
        } else {
            $stmt = $pdo->prepare("INSERT INTO highlights (title, target_link, color_theme, order_index) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $target_link, $color_theme, (int)$cnt + 1]);
            $success_msg = "เพิ่มประกาศสำเร็จ";
        }

    } elseif ($action == 'edit_highlight') {
        $id = (int)$_POST['highlight_id'];
        $title = trim($_POST['title'] ?? '');
        $target_link = trim($_POST['target_link'] ?? '');
        $color_theme = $_POST['color_theme'] ?? 'green';
        if (!$title) {
            $error_msg = "กรุณากรอกหัวข้อประกาศ";
        } else {
            $stmt = $pdo->prepare("UPDATE highlights SET title=?, target_link=?, color_theme=? WHERE id=?");
            $stmt->execute([$title, $target_link, $color_theme, $id]);
            $success_msg = "แก้ไขประกาศสำเร็จ";
        }

    } elseif ($action == 'toggle_status') {
        $id = (int)$_POST['highlight_id'];
        $status = (int)$_POST['status'];
        $new_status = $status == 1 ? 0 : 1;

        // If activating, check count
        if ($new_status == 1) {
            $cnt = $pdo->query("SELECT COUNT(*) FROM highlights WHERE is_active = 1")->fetchColumn();
            if ($cnt >= 4) {
                $error_msg = "มีประกาศที่เปิดใช้งานครบ 4 ใบแล้ว กรุณาปิดใบเดิมก่อน";
                $new_status = 1; // revert
                goto fetch_data;
            }
        }
        $stmt = $pdo->prepare("UPDATE highlights SET is_active = ? WHERE id = ?");
        $stmt->execute([$new_status, $id]);
        $success_msg = "อัปเดตสถานะสำเร็จ";

    } elseif ($action == 'delete_highlight') {
        $id = (int)$_POST['highlight_id'];
        $pdo->prepare("DELETE FROM highlights WHERE id = ?")->execute([$id]);
        $success_msg = "ลบประกาศสำเร็จ";
    }
}

fetch_data:
// Fetch all highlights
$stmt = $pdo->query("SELECT * FROM highlights ORDER BY is_active DESC, order_index ASC, id ASC");
$highlights = $stmt->fetchAll();

// Count active
$active_count = 0;
foreach ($highlights as $h) { if ($h['is_active']) $active_count++; }
?>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2 class="card-title" style="margin-bottom:0;">📣 จัดการประกาศ (4 การ์ด)</h2>
        <span class="badge" style="background:<?= $active_count>=4?'#ef4444':'#10b981' ?>;color:white;padding:6px 14px;border-radius:20px;font-size:0.9rem;">
            เปิดอยู่ <?= $active_count ?>/4 ใบ
        </span>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- Add Form -->
    <div style="background:#f8fafc;padding:20px;border-radius:10px;margin-bottom:24px;border:1px solid var(--border-color);">
        <h3 style="margin-top:0;font-size:1rem;color:#16a34a;">➕ เพิ่มประกาศใหม่ <?= $active_count >= 4 ? '<span style="color:#ef4444;font-size:0.85rem;">(ครบ 4 ใบแล้ว — ปิดใบเดิมก่อน)</span>' : '' ?></h3>
        <form method="POST" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="action" value="add_highlight">
            <div class="form-group" style="flex:2;min-width:250px;margin-bottom:0;">
                <label>หัวข้อประกาศ *</label>
                <input type="text" name="title" class="form-control" placeholder="เช่น ประกาศรายชื่อนักเรียน..." required <?= $active_count>=4?'disabled':'' ?>>
            </div>
            <div class="form-group" style="flex:2;min-width:200px;margin-bottom:0;">
                <label>ลิงก์ URL (เมื่อคลิกการ์ด)</label>
                <input type="url" name="target_link" class="form-control" placeholder="https://..." <?= $active_count>=4?'disabled':'' ?>>
            </div>
            <div class="form-group" style="flex:1;min-width:140px;margin-bottom:0;">
                <label>สีการ์ด</label>
                <select name="color_theme" class="form-control" <?= $active_count>=4?'disabled':'' ?>>
                    <option value="green">🟢 เขียว</option>
                    <option value="blue">🔵 น้ำเงิน</option>
                    <option value="orange">🟠 ส้ม</option>
                    <option value="red">🔴 แดง</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="height:42px;" <?= $active_count>=4?'disabled':'' ?>>เพิ่ม</button>
        </form>
    </div>

    <!-- Active Cards Preview -->
    <?php
    $active_hl = array_filter($highlights, fn($h) => $h['is_active']);
    $color_map = ['green'=>'#16a34a','blue'=>'#1d4ed8','orange'=>'#d97706','red'=>'#dc2626'];
    ?>
    <?php if (!empty($active_hl)): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:24px;">
        <?php foreach($active_hl as $h): ?>
        <div style="background:<?= $color_map[$h['color_theme']]??'#16a34a' ?>;border-radius:12px;padding:16px;color:white;">
            <div style="font-size:0.75rem;opacity:0.8;margin-bottom:4px;">การ์ดที่แสดงบนหน้าหลัก</div>
            <div style="font-weight:700;font-size:0.9rem;line-height:1.3;"><?= htmlspecialchars($h['title']) ?></div>
            <?php if($h['target_link']): ?>
            <div style="font-size:0.72rem;opacity:0.75;margin-top:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">🔗 <?= htmlspecialchars($h['target_link']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php for($i=count($active_hl); $i<4; $i++): ?>
        <div style="background:#e5e7eb;border-radius:12px;padding:16px;border:2px dashed #d1d5db;">
            <div style="font-size:0.8rem;color:#9ca3af;text-align:center;padding:10px 0;">ช่องว่าง</div>
        </div>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <table class="table">
        <thead>
            <tr>
                <th style="width:50px;text-align:center;">ID</th>
                <th>หัวข้อ / ลิงก์</th>
                <th style="width:80px;text-align:center;">สี</th>
                <th style="width:80px;text-align:center;">วิว</th>
                <th style="width:100px;text-align:center;">สถานะ</th>
                <th style="width:130px;text-align:center;">จัดการ</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($highlights)): ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);">ยังไม่มีประกาศในระบบ</td></tr>
            <?php else: ?>
                <?php foreach ($highlights as $h): ?>
                <tr style="<?= !$h['is_active'] ? 'opacity:0.55;' : '' ?>">
                    <td style="text-align:center;vertical-align:middle;"><?= $h['id'] ?></td>
                    <td style="vertical-align:middle;">
                        <div style="font-weight:700;"><?= htmlspecialchars($h['title']) ?></div>
                        <?php if($h['target_link']): ?>
                        <div style="font-size:0.82rem;color:var(--text-muted);margin-top:3px;">
                            🔗 <a href="<?= htmlspecialchars($h['target_link']) ?>" target="_blank" style="color:var(--primary-color);"><?= htmlspecialchars($h['target_link']) ?></a>
                        </div>
                        <?php else: ?>
                        <div style="font-size:0.8rem;color:#ccc;">(ไม่มีลิงก์)</div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;vertical-align:middle;">
                        <?php $c2 = $color_map[$h['color_theme']] ?? '#888'; ?>
                        <span style="display:inline-block;width:22px;height:22px;border-radius:50%;background:<?= $c2 ?>;"></span>
                    </td>
                    <td style="text-align:center;vertical-align:middle;">
                        <span class="badge" style="background:#e2e8f0;color:#333;padding:4px 10px;border-radius:12px;"><?= number_format($h['views_count']) ?></span>
                    </td>
                    <td style="text-align:center;vertical-align:middle;">
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="highlight_id" value="<?= $h['id'] ?>">
                            <input type="hidden" name="status" value="<?= $h['is_active'] ?>">
                            <button type="submit" class="btn" style="background:<?= $h['is_active']?'#10b981':'#94a3b8' ?>;color:white;padding:4px 10px;font-size:0.8rem;">
                                <?= $h['is_active'] ? '✅ เปิดอยู่' : '⛔ ปิดอยู่' ?>
                            </button>
                        </form>
                    </td>
                    <td style="text-align:center;vertical-align:middle;">
                        <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                            <!-- Edit Button (opens modal) -->
                            <button class="btn btn-secondary" style="padding:4px 10px;font-size:0.8rem;"
                                onclick="openEdit(<?= $h['id'] ?>, '<?= addslashes(htmlspecialchars($h['title'])) ?>', '<?= addslashes($h['target_link']) ?>', '<?= $h['color_theme'] ?>')">
                                ✏️ แก้ไข
                            </button>
                            <!-- Delete -->
                            <form method="POST" style="margin:0;" onsubmit="return confirm('ลบประกาศนี้?');">
                                <input type="hidden" name="action" value="delete_highlight">
                                <input type="hidden" name="highlight_id" value="<?= $h['id'] ?>">
                                <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:0.8rem;">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:16px;padding:28px;width:90%;max-width:520px;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <h3 style="margin-top:0;color:var(--primary-color);">✏️ แก้ไขประกาศ</h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit_highlight">
            <input type="hidden" name="highlight_id" id="edit_id">
            <div class="form-group">
                <label>หัวข้อประกาศ *</label>
                <input type="text" name="title" id="edit_title" class="form-control" required>
            </div>
            <div class="form-group">
                <label>ลิงก์ URL (เมื่อคลิกการ์ด)</label>
                <input type="url" name="target_link" id="edit_link" class="form-control" placeholder="https://...">
            </div>
            <div class="form-group">
                <label>สีการ์ด</label>
                <select name="color_theme" id="edit_color" class="form-control">
                    <option value="green">🟢 เขียว</option>
                    <option value="blue">🔵 น้ำเงิน</option>
                    <option value="orange">🟠 ส้ม</option>
                    <option value="red">🔴 แดง</option>
                </select>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn" style="background:#e5e7eb;color:#333;" onclick="closeEdit()">ยกเลิก</button>
                <button type="submit" class="btn btn-primary">💾 บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(id, title, link, color) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_title').value = title;
    document.getElementById('edit_link').value = link;
    document.getElementById('edit_color').value = color;
    const modal = document.getElementById('editModal');
    modal.style.display = 'flex';
}
function closeEdit() {
    document.getElementById('editModal').style.display = 'none';
}
document.getElementById('editModal').addEventListener('click', function(e){
    if(e.target === this) closeEdit();
});
</script>

<?php include '../../includes/footer.php'; ?>
