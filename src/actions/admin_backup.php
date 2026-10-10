<?php
session_start();
require_once '../../config/database.php';

// Check admin role
if (!isset($_SESSION['user_id']) || !in_array('admin', $_SESSION['roles'] ?? [])) {
    header("Location: ../../login.php");
    exit();
}

// Handle Backup Request
if (isset($_POST['action']) && $_POST['action'] === 'backup') {
    // Prevent timeout for large databases
    set_time_limit(0);
    ini_set('memory_limit', '512M');

    $db_name = $pdo->query('SELECT DATABASE()')->fetchColumn();
    
    // Fetch active term to append to backup file name
    $active_term = $pdo->query("SELECT term_number, year_name FROM academic_terms WHERE is_active = 1")->fetch();
    $term_prefix = '';
    $term_display = '';
    if ($active_term) {
        $term_prefix = $active_term['term_number'] . '_' . $active_term['year_name'] . '_';
        $term_display = "เทอม " . $active_term['term_number'] . "/" . $active_term['year_name'];
    }

    $backup_file_name = $db_name . '_backup_' . $term_prefix . date('Y-m-d_H-i-s') . '.sql';

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $backup_file_name . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Header info
    fwrite($output, "-- ---------------------------------------------------------\n");
    fwrite($output, "-- Database Backup for: $db_name\n");
    if ($term_display) {
        fwrite($output, "-- Academic Term: $term_display\n");
    }
    fwrite($output, "-- Generated at: " . date('Y-m-d H:i:s') . "\n");
    fwrite($output, "-- ---------------------------------------------------------\n\n");

    fwrite($output, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($output, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
    fwrite($output, "START TRANSACTION;\n");
    fwrite($output, "SET time_zone = \"+07:00\";\n\n");

    try {
        // Get all tables
        $tables = [];
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        foreach ($tables as $table) {
            fwrite($output, "--\n-- Table structure for table `$table`\n--\n\n");
            fwrite($output, "DROP TABLE IF EXISTS `$table`;\n");

            // Table structure
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $createTable = $stmt->fetch(PDO::FETCH_NUM);
            fwrite($output, $createTable[1] . ";\n\n");

            // Table data
            fwrite($output, "--\n-- Dumping data for table `$table`\n--\n\n");
            
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($rows) > 0) {
                // Determine column types to handle quotes properly
                $colStmt = $pdo->query("SHOW COLUMNS FROM `$table`");
                $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);
                
                $chunkSize = 100;
                $chunks = array_chunk($rows, $chunkSize);

                foreach ($chunks as $chunk) {
                    $insertStr = "INSERT INTO `$table` VALUES ";
                    $valuesArr = [];

                    foreach ($chunk as $row) {
                        $rowVals = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $rowVals[] = 'NULL';
                            } else {
                                // Basic escaping
                                $val = str_replace(["\\", "'", "\r", "\n"], ["\\\\", "''", "\\r", "\\n"], $val);
                                $rowVals[] = "'" . $val . "'";
                            }
                        }
                        $valuesArr[] = "(" . implode(", ", $rowVals) . ")";
                    }

                    $insertStr .= implode(",\n", $valuesArr) . ";\n";
                    fwrite($output, $insertStr);
                }
            }
            fwrite($output, "\n\n");
        }

        fwrite($output, "SET FOREIGN_KEY_CHECKS=1;\n");
        fwrite($output, "COMMIT;\n");

    } catch (Exception $e) {
        fwrite($output, "\n\n-- ERROR OCCURRED DURING BACKUP: " . $e->getMessage() . "\n");
    }

    fclose($output);
    exit();
}

$page_title = "สำรองฐานข้อมูล";
include '../../includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 40px auto; text-align: center; padding: 40px 20px;">
    <div style="font-size: 4rem; margin-bottom: 20px;">💾</div>
    <h2 class="card-title" style="margin-bottom: 15px; color: var(--primary-color);">สำรองฐานข้อมูล (Database Backup)</h2>
    <p style="color: var(--text-muted); margin-bottom: 30px; font-size: 1.05rem; line-height: 1.6;">
        การสำรองข้อมูลเป็นสิ่งสำคัญเพื่อป้องกันข้อมูลสูญหาย<br>
        กรุณาดาวน์โหลดไฟล์สำรองข้อมูล (Backup) เก็บไว้ในเครื่องอย่างน้อยสัปดาห์ละ 1 ครั้ง<br>
        ไฟล์ที่ได้จะเป็นสกุล <strong>.sql</strong> ซึ่งจะถูกตั้งชื่อตาม <strong>เทอมและปีการศึกษาปัจจุบัน</strong> โดยอัตโนมัติ (เช่น 1_2569) ทำให้คุณสามารถเก็บข้อมูลแยกแต่ละเทอมเป็นคนละก้อนกันได้อย่างชัดเจน ก่อนที่จะทำการล้างข้อมูลเพื่อเริ่มเทอมใหม่
    </p>

    <form method="POST" onsubmit="document.getElementById('loading').style.display='block';">
        <input type="hidden" name="action" value="backup">
        <button type="submit" class="btn btn-primary" style="font-size: 1.1rem; padding: 12px 30px; box-shadow: 0 4px 15px rgba(33, 150, 243, 0.3);">
            ดาวน์โหลดไฟล์สำรองฐานข้อมูล
        </button>
    </form>

    <div id="loading" style="display: none; margin-top: 20px; color: #64748b; font-weight: 500;">
        กำลังสร้างไฟล์สำรองข้อมูล กรุณารอสักครู่... ⏳
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
