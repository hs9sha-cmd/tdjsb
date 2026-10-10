<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../includes/SimpleXLSXGen.php';
requireRole($pdo, 'admin');

if (isset($_GET['type'])) {
    $type = $_GET['type'];
    $excel_data = [];
    
    if ($type === 'students') {
        // Headers
        $excel_data[] = ['รหัสนักเรียน', 'เลขที่', 'ชื่อ-สกุล', 'ห้องเรียน', 'เพศ', 'ศาสนา', 'สถานะ'];
        
        $sql = "
            SELECT s.student_code, s.student_number, s.full_name, c.room_name, s.gender, s.religion, s.status
            FROM students s
            LEFT JOIN classrooms c ON s.classroom_id = c.id
            ORDER BY c.grade_level ASC, CAST(c.room_number AS UNSIGNED) ASC, s.student_number ASC
        ";
        $stmt = $pdo->query($sql);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Translate values for better readability
            $gender = $row['gender'] === 'Male' ? 'ชาย' : ($row['gender'] === 'Female' ? 'หญิง' : $row['gender']);
            $status = $row['status'] === 'active' ? 'กำลังศึกษา' : ($row['status'] === 'graduated' ? 'สำเร็จการศึกษา' : 'ลาออก/พ้นสภาพ');
            
            $excel_data[] = [
                $row['student_code'],
                $row['student_number'],
                $row['full_name'],
                $row['room_name'],
                $gender,
                $row['religion'],
                $status
            ];
        }
    } elseif ($type === 'teachers') {
        // Headers
        $excel_data[] = ['ชื่อผู้ใช้ (Username)', 'ชื่อ-สกุล', 'บทบาท (Role)', 'ห้องประจำชั้น'];
        
        $sql = "
            SELECT u.username, u.full_name, 
                   GROUP_CONCAT(r.description SEPARATOR ', ') as roles, 
                   u.advisor_classroom 
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id
            LEFT JOIN roles r ON ur.role_id = r.id
            GROUP BY u.id
            ORDER BY u.full_name ASC
        ";
        $stmt = $pdo->query($sql);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $excel_data[] = [
                $row['username'],
                $row['full_name'],
                $row['roles'],
                $row['advisor_classroom']
            ];
        }
    }
    
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($excel_data);
    $xlsx->downloadAs('export_' . $type . '_' . date('Ymd_His') . '.xlsx');
    exit;
}
?>
