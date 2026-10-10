<?php
require_once '../../config/auth.php';
requireLogin();


$type = $_GET['type'] ?? '';

if ($type === 'teacher') {
  $filename = "teacher_template.csv";
  $header = ["username", "password", "full_name", "department", "advisor_classroom"];
  $data = [
      ["teacher01", "123456", "นายสมชาย ใจดี", "วิทยาศาสตร์และเทคโนโลยี", "ม.1/1"],
      ["teacher02", "123456", "นางสมหญิง รักเรียน", "คณิตศาสตร์", "ม.2/3"]
  ];
} elseif ($type === 'student') {
  $filename = "student_template.csv";
  $header = ["student_code", "full_name", "classroom", "student_number"];
  $data = [
      ["67001", "ด.ช.ใจดี เรียนเก่ง", "ม.1/1", "1"],
      ["67002", "ด.ญ.สวย รักดี", "ม.4/13", "2"]
  ];
} elseif ($type === 'subject') {
  $filename = "subject_template.csv";
  $header = ["subject_code", "subject_name", "credit"];
  $data = [
      ["ท21101", "ภาษาไทยพื้นฐาน 1", "1.5"],
      ["อ21101", "ภาษาอังกฤษพื้นฐาน 1", "1.5"]
  ];
} elseif ($type === 'assignment') {
  $filename = "assignment_template.csv";
  $header = ["username", "subject_code", "classroom_name"];
  $data = [
      ["teacher01", "ท21101", "ม.1/1"],
      ["teacher01", "ท21101", "ม.1/2"]
  ];
} elseif ($type === 'elective_enrollment') {
  $filename = "elective_enrollment_template.csv";
  $header = ["teacher_username", "subject_code", "base_classroom", "student_code"];
  $data = [
      ["teacher01", "ง30201", "ม.4/1", "67001"],
      ["teacher01", "ง30201", "ม.4/1", "67002"]
  ];
} else {
    die("Invalid template type");
}

if (ob_get_length()) ob_clean();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
// Add UTF-8 BOM for Excel
fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

fputcsv($output, $header);
foreach ($data as $row) {
  fputcsv($output, $row);
}
fclose($output);
exit();
