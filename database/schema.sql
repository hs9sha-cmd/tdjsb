-- =====================================================================
-- TDJSB - Clean Database Schema (White-label Template)
-- Created: 2026-10-10
-- Compliant with PDPA: Zero residual personal student/teacher data
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. Academic Terms (ปีการศึกษาและภาคเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `academic_terms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year_name` varchar(10) NOT NULL,
  `term_number` int(11) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `year_name` (`year_name`,`term_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Classrooms (ห้องเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `classrooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `grade_level` varchar(20) NOT NULL,
  `room_number` varchar(10) NOT NULL,
  `room_name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `grade_level` (`grade_level`,`room_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Roles (บทบาทและสิทธิ์ผู้ใช้งาน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Users (ผู้ใช้งานระบบ / ครูและบุคลากร)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `advisor_classroom` varchar(20) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `force_password_change` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. User Roles (การกำหนดสิทธิ์ผู้ใช้)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Students (ข้อมูลนักเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_code` varchar(20) NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `classroom_id` int(11) DEFAULT NULL,
  `status` enum('active','graduated','resigned') DEFAULT 'active',
  `student_number` int(11) DEFAULT NULL,
  `gender` enum('Male','Female') DEFAULT NULL,
  `religion` varchar(50) DEFAULT 'Buddhism',
  `photo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_code` (`student_code`),
  KEY `classroom_id` (`classroom_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Subjects (วิชาเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_code` varchar(20) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `credit` decimal(3,1) DEFAULT 1.0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `subject_code` (`subject_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. Teacher Subjects (ครูผู้สอนประจำรายวิชา)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teacher_subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `classroom_id` int(11) DEFAULT NULL,
  `is_elective` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_id` (`teacher_id`,`subject_id`,`classroom_id`),
  KEY `subject_id` (`subject_id`),
  KEY `classroom_id` (`classroom_id`),
  CONSTRAINT `teacher_subjects_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teacher_subjects_ibfk_3` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. Elective Enrollments (ลงทะเบียนวิชาเพิ่มเติม/กิจกรรม)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `elective_enrollments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_subject_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_subject_id` (`teacher_subject_id`,`student_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `elective_enrollments_ibfk_1` FOREIGN KEY (`teacher_subject_id`) REFERENCES `teacher_subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `elective_enrollments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. Schedules (ตารางสอน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `classroom_id` int(11) DEFAULT NULL,
  `academic_term_id` int(11) DEFAULT NULL,
  `day_of_week` int(11) NOT NULL,
  `period` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_id` (`teacher_id`,`academic_term_id`,`day_of_week`,`period`),
  KEY `subject_id` (`subject_id`),
  KEY `classroom_id` (`classroom_id`),
  KEY `academic_term_id` (`academic_term_id`),
  CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedules_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedules_ibfk_3` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedules_ibfk_4` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. Homeroom Teachers (ครูที่ปรึกษาประจำห้อง)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `homeroom_teachers` (
  `classroom_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `academic_term_id` int(11) NOT NULL,
  PRIMARY KEY (`classroom_id`,`teacher_id`,`academic_term_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `academic_term_id` (`academic_term_id`),
  CONSTRAINT `homeroom_teachers_ibfk_1` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `homeroom_teachers_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `homeroom_teachers_ibfk_3` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. Homeroom Attendance (เช็คชื่อเข้าแถวตอนเช้า)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `homeroom_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','late','leave','absent') NOT NULL,
  `recorded_by_teacher_id` int(11) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`,`attendance_date`),
  KEY `recorded_by_teacher_id` (`recorded_by_teacher_id`),
  CONSTRAINT `homeroom_attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `homeroom_attendance_ibfk_2` FOREIGN KEY (`recorded_by_teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. Teaching Logs (บันทึกการสอนประจำคาบ)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teaching_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `classroom_id` int(11) DEFAULT NULL,
  `log_date` date NOT NULL,
  `period` varchar(50) NOT NULL,
  `teaching_report_text` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `image_capture_time` datetime DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `teacher_id` (`teacher_id`,`log_date`,`period`),
  KEY `subject_id` (`subject_id`),
  KEY `classroom_id` (`classroom_id`),
  CONSTRAINT `teaching_logs_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teaching_logs_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teaching_logs_ibfk_3` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. Class Attendance (เช็คชื่อในคาบเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `class_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teaching_log_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `status` enum('present','late','leave','absent') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `teaching_log_id` (`teaching_log_id`,`student_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `class_attendance_ibfk_1` FOREIGN KEY (`teaching_log_id`) REFERENCES `teaching_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_attendance_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. Teaching Substitutions (สอนแทน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teaching_substitutions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `original_teacher_id` int(11) NOT NULL,
  `substitute_teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `classroom_id` int(11) NOT NULL,
  `substitution_date` date NOT NULL,
  `period` varchar(50) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `original_teacher_id` (`original_teacher_id`),
  KEY `substitute_teacher_id` (`substitute_teacher_id`),
  KEY `subject_id` (`subject_id`),
  KEY `classroom_id` (`classroom_id`),
  CONSTRAINT `teaching_substitutions_ibfk_1` FOREIGN KEY (`original_teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teaching_substitutions_ibfk_2` FOREIGN KEY (`substitute_teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teaching_substitutions_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teaching_substitutions_ibfk_4` FOREIGN KEY (`classroom_id`) REFERENCES `classrooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. Student Behaviors (คะแนนความประพฤตินักเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_behaviors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `academic_term_id` int(11) NOT NULL,
  `initial_score` int(11) DEFAULT 100,
  `current_score` int(11) DEFAULT 100,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`,`academic_term_id`),
  KEY `academic_term_id` (`academic_term_id`),
  CONSTRAINT `student_behaviors_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `student_behaviors_ibfk_2` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 17. Behavior Records (บันทึกการตัด/เพิ่มคะแนนความประพฤติ)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `behavior_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `academic_term_id` int(11) NOT NULL,
  `recorded_by_teacher_id` int(11) DEFAULT NULL,
  `record_date` date NOT NULL,
  `points` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `academic_term_id` (`academic_term_id`),
  KEY `recorded_by_teacher_id` (`recorded_by_teacher_id`),
  CONSTRAINT `behavior_records_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `behavior_records_ibfk_2` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `behavior_records_ibfk_3` FOREIGN KEY (`recorded_by_teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18. Inspection Cycles (รอบการตรวจเครื่องแบบและระเบียบวินัย)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inspection_cycles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_term_id` int(11) NOT NULL,
  `cycle_name` varchar(100) NOT NULL,
  `inspection_date` date NOT NULL,
  `target_grade_level` varchar(50) DEFAULT 'all',
  `status` enum('open','closed') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `academic_term_id` (`academic_term_id`),
  CONSTRAINT `inspection_cycles_ibfk_1` FOREIGN KEY (`academic_term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 19. Discipline Inspections (ผลการตรวจเครื่องแบบ/วินัยนักเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `discipline_inspections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cycle_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `inspector_id` int(11) DEFAULT NULL,
  `hair_status` tinyint(1) DEFAULT 1,
  `nail_status` tinyint(1) DEFAULT 1,
  `accessory_status` tinyint(1) DEFAULT 1,
  `shoes_status` tinyint(1) DEFAULT 1,
  `overall_status` enum('pending','pass','fail','excused','dodged') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `cycle_student` (`cycle_id`,`student_id`),
  KEY `inspector_id` (`inspector_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `discipline_inspections_ibfk_1` FOREIGN KEY (`cycle_id`) REFERENCES `inspection_cycles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `discipline_inspections_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `discipline_inspections_ibfk_3` FOREIGN KEY (`inspector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 20. Inspection Room Locks (ล็อกห้องหลังตรวจเครื่องแบบเสร็จ)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inspection_room_locks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cycle_id` int(11) NOT NULL,
  `classroom_id` int(11) NOT NULL,
  `locked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `locked_by_teacher_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cycle_room_unique` (`cycle_id`,`classroom_id`),
  KEY `cycle_id` (`cycle_id`),
  KEY `classroom_id` (`classroom_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 21. Discipline Cases (คดีความประพฤติ/งานกิจการนักเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `discipline_cases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_code` varchar(50) NOT NULL,
  `academic_term_id` int(11) NOT NULL,
  `case_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `reported_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `case_code` (`case_code`),
  KEY `academic_term_id` (`academic_term_id`),
  KEY `reported_by` (`reported_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 22. Discipline Case Students (นักเรียนที่เกี่ยวข้องในคดี)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `discipline_case_students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `points_deducted` int(11) NOT NULL,
  `behavior_record_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `case_id` (`case_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `fk_case_students_case` FOREIGN KEY (`case_id`) REFERENCES `discipline_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_case_students_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 23. Discipline Case Photos (รูปภาพหลักฐานคดี)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `discipline_case_photos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `case_id` int(11) NOT NULL,
  `photo_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `case_id` (`case_id`),
  CONSTRAINT `fk_case_photos_case` FOREIGN KEY (`case_id`) REFERENCES `discipline_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 24. Teacher Attendance (ลงเวลาปฏิบัติงานครู/เซลฟี่ GPS)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teacher_attendance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_in_lat` decimal(10,8) DEFAULT NULL,
  `time_in_lng` decimal(11,8) DEFAULT NULL,
  `time_in_status` enum('on_time','late') DEFAULT NULL,
  `time_in_photo` varchar(255) DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `time_out_lat` decimal(10,8) DEFAULT NULL,
  `time_out_lng` decimal(11,8) DEFAULT NULL,
  `time_out_photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_date_unique` (`user_id`,`work_date`),
  CONSTRAINT `fk_teacher_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 25. Friday Prayers Log (การขออนุญาตไปละหมาดวันศุกร์)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `friday_prayers_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `log_date` date NOT NULL,
  `time_out` time DEFAULT NULL,
  `time_in` time DEFAULT NULL,
  `status` enum('out','returned','late') DEFAULT 'out',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `fk_friday_prayer_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 26. Student Upload Tokens (โทเคนอัปโหลดรูปถ่ายนักเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_upload_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `token` varchar(50) NOT NULL,
  `is_used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `fk_token_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 27. Messages (กล่องข้อความภายในระบบ)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `is_deleted_by_sender` tinyint(1) DEFAULT 0,
  `is_deleted_by_receiver` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 28. Notifications (การแจ้งเตือนระบบ)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` varchar(100) DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `is_read` (`is_read`),
  CONSTRAINT `fk_notifications_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 29. Banners (แบนเนอร์ประชาสัมพันธ์)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `banners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `image_path` varchar(255) NOT NULL,
  `target_link` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `order_index` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 30. Highlights (ปุ่มไฮไลต์ด่วนหน้าแรก)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `highlights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `target_link` varchar(255) DEFAULT NULL,
  `color_theme` varchar(50) DEFAULT 'green',
  `views_count` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `order_index` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 31. Site Settings (การตั้งค่าระบบและข้อมูลโรงเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `site_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- ข้อมูลเริ่มต้นของระบบ (Initial Seed Data)
-- =====================================================================

-- 1. บทบาทผู้ใช้งานทั้งหมด (Roles)
INSERT INTO `roles` (`id`, `role_name`, `description`) VALUES
(1, 'admin', 'ผู้ดูแลระบบสูงสุด'),
(2, 'teacher', 'ครูผู้สอน / ครูที่ปรึกษา'),
(3, 'executive', 'ผู้บริหาร'),
(4, 'academic', 'งานวิชาการ'),
(5, 'discipline', 'กิจการนักเรียน'),
(6, 'inspector', 'สารวัตรนักเรียน'),
(7, 'level_head', 'หัวหน้าระดับชั้น'),
(8, 'personnel', 'กลุ่มงานบุคลากร'),
(9, 'assistant', 'ผู้ช่วยครู (สิทธิ์เฉพาะเช็คชื่อเข้าแถว)'),
(10, 'friday_prayer_duty', 'ครูเวรละหมาดวันศุกร์')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- 2. บัญชี Superadmin เริ่มต้น (Username: admin, Password: 12345)
INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `force_password_change`) VALUES
(1, 'admin', '$2y$12$h6RQPJMz2tOEuEy1eDnfVuNbfpKrb.x/DA5sfaI0OpiSMa18z8dyW', 'System Administrator', 0)
ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`);

-- 3. กำหนดสิทธิ์ Admin ให้บัญชีเริ่มต้น
INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(1, 1)
ON DUPLICATE KEY UPDATE `role_id` = VALUES(`role_id`);

-- 4. ภาคเรียนเริ่มต้น (Active Term)
INSERT INTO `academic_terms` (`id`, `year_name`, `term_number`, `start_date`, `end_date`, `is_active`) VALUES
(1, '2569', 1, '2026-05-16', '2026-10-15', 1)
ON DUPLICATE KEY UPDATE `is_active` = VALUES(`is_active`);

-- 5. การตั้งค่าระบบเริ่มต้น (Site Settings)
INSERT INTO `site_settings` (`setting_key`, `setting_value`) VALUES
('school_name', 'โรงเรียนของคุณ'),
('school_logo', 'assets/images/logo.png'),
('school_lat', '13.7563'),
('school_lng', '100.5018'),
('attendance_radius', '100'),
('director_name', 'ผู้อำนวยการโรงเรียน'),
('director_position', 'ผู้อำนวยการโรงเรียน'),
('enable_friday_prayer', '0'),
('late_cutoff_time', '08:00:00'),
('popup_active', '0'),
('popup_message', ''),
('popup_link', ''),
('popup_image_path', ''),
('require_teaching_image', '1')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
