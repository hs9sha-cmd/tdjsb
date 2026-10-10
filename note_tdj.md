# 📘 พิมพ์เขียวการส่งต่อและติดตั้งระบบ TeeDiewJob (สำหรับโรงเรียนใหม่)
> **เอกสารโดย:** Wednesday (ผู้ช่วยวิศวกรซอฟต์แวร์และการจัดการศึกษา)  
> **สร้างเมื่อ:** 10 ตุลาคม 2026  
> **วัตถุประสงค์:** คู่มือสรุปโครงสร้างสถาปัตยกรรม แผนการ Refactor และขั้นตอนการสร้างโปรเจกต์ใหม่ (White-label Template) เพื่อส่งมอบให้โรงเรียนอื่นนำไปใช้งานได้อย่างปลอดภัยและสมบูรณ์ 100%

---

## 1. 🎯 ภาพรวมระบบ (System Overview & Tech Stack)
ระบบ **TeeDiewJob** เป็น Web Application สำหรับการบริหารจัดการกิจการนักเรียนและงานบุคลากรในโรงเรียน ออกแบบเพื่อรองรับการใช้งานผ่านสมาร์ตโฟน (Mobile First / PWA Ready)

* **Backend:** Native PHP (7.4 - 8.x+) + PDO MySQL/MariaDB
* **Frontend:** Responsive UI (Mobile First), CSS Glassmorphism & Minimal Pastel Theme, JavaScript (Vanilla JS + jQuery)
* **Libraries ภายใน:** 
  * `SimpleXLSXGen.php` (ส่งออกไฟล์ Excel .xlsx)
  * `DataTables` (จัดการตารางข้อมูลขนาดใหญ่)
  * `HTML5 Canvas / Video API` (กล้องถ่ายรูปเซลฟี่ + ปั๊มลายน้ำ GPS)
  * `HTML5 Geolocation API` (ตรวจสอบพิกัดเข้าแถว/ลงเวลา)
  * `QR Code API / JS Scanner` (ระบบสแกนบัตรนักเรียน)

---

## 2. 👥 ระบบสิทธิ์และบทบาท (Roles & Permissions)
ระบบควบคุมการเข้าถึงผ่านระบบตาราง `roles` และ `user_roles`:
1. `admin` : ผู้ดูแลระบบสูงสุด (จัดการผู้ใช้, ตารางข้อมูล, ตั้งค่าระบบ)
2. `executive` : ผู้บริหาร (ดู Dashboard ภาพรวม, อนุมัติ, สถิติรวม)
3. `academic` : งานวิชาการ (รายงานการเรียนการสอน, คาบสอน)
4. `discipline` : กิจการนักเรียน (คดีความประพฤติ, เช็คมาสาย, ตัดคะแนน)
5. `inspector` : สารวัตรนักเรียน (เช็คมาสาย คาบ 0)
6. `level_head` : หัวหน้าระดับชั้น (ดูสถิติและกำกับดูแลระดับชั้น)
7. `personnel` : กลุ่มงานบุคลากร (ดูรายงานลงเวลาครู, ตรวจรูปเซลฟี่ GPS)
8. `teacher` : ครูผู้สอน / ครูที่ปรึกษา (เช็คชื่อโฮมรูม, เช็คชื่อในคาบ, ดูสถิติห้องตนเอง)
9. `assistant` : ผู้ช่วยครู (สิทธิ์เฉพาะช่วยเช็คชื่อเข้าแถว)

---

## 3. 📁 โครงสร้างโฟลเดอร์ของโปรเจกต์ใหม่ (Clean Architecture)
```text
TeeDiewJob-NewSchool/
├── assets/
│   ├── css/                  # สไตล์ชีททั้งหมด
│   ├── js/                   # สคริปต์กล้อง, QR Code, DataTables
│   └── uploads/              # โฟลเดอร์เก็บรูป (ต้องสร้างโฟลเดอร์เปล่า และตั้ง Permission 755)
│       ├── students/         # เก็บรูปถ่ายนักเรียน (ว่างไว้)
│       ├── attendance/       # เก็บรูปเซลฟี่ลงเวลาครู (ว่างไว้)
│       ├── cases/            # เก็บรูปหลักฐานคดี (ว่างไว้)
│       └── system/           # เก็บโลโก้ รร. / ป๊อปอัปแจ้งเตือน
├── config/
│   ├── database.php          # การเชื่อมต่อฐานข้อมูล PDO
│   └── auth.php              # ฟังก์ชันตรวจสอบ Session และ Role
├── database/
│   ├── init.php              # สคริปต์ Initialize
│   └── schema.sql            # ไฟล์ SQL โครงสร้างตารางสะอาด (Clean Schema)
├── includes/
│   ├── header.php            # เมนูบน, ดึงค่า site_settings
│   ├── footer.php            # ปิดโครงสร้างเว็บ, สคริปต์หลัก
│   ├── sidebar.php           # เมนูด้านข้าง (มีเงื่อนไขเปิด/ปิดเมนูตามสิทธิ์)
│   ├── bottom_nav.php        # เมนูด้านล่างสำหรับมือถือ
│   └── SimpleXLSXGen.php     # คลาสสร้างไฟล์ Excel
├── src/
│   └── actions/              # ไฟล์ประมวลผลและหน้าจอแสดงผลทั้งหมด (Admin, Teacher, ฯลฯ)
├── index.php                 # หน้า Login เข้าสู่ระบบ
└── manifest.json             # ตั้งค่า PWA สำหรับติดตั้งบนมือถือ
```

---

## 4. ⚙️ แผนการปลดล็อคการ Hardcode สู่ `site_settings`
เพื่อให้โปรเจกต์ใหม่สามารถนำไปใช้กับโรงเรียนใดก็ได้โดยไม่ต้องแก้โค้ด ให้จัดการผ่านตาราง `site_settings`:

### 4.1 ตาราง `site_settings` (Key-Value):
* `school_name` : ชื่อโรงเรียน (ค่าเริ่มต้น: `'โรงเรียนของคุณ'`)
* `school_logo` : เส้นทางไฟล์โลโก้ (ค่าเริ่มต้น: `'assets/images/logo.png'`)
* `school_lat` : ละติจูดพิกัดศูนย์กลางโรงเรียน (ตัวเลขทศนิยม)
* `school_lng` : ลองจิจูดพิกัดศูนย์กลางโรงเรียน (ตัวเลขทศนิยม)
* `attendance_radius` : รัศมีการลงเวลาเป็นเมตร (ค่าเริ่มต้น: `100`)
* `director_name` : ชื่อ-สกุล ผู้อำนวยการ (สำหรับใบรายงาน PDF)
* `director_position`: ตำแหน่งผู้บริหาร (ค่าเริ่มต้น: `'ผู้อำนวยการโรงเรียน'`)
* `enable_friday_prayer` : เปิด/ปิดระบบละหมาดวันศุกร์ (`1` หรือ `0`)
* `late_cutoff_time` : เวลาตัดสายเข้าแถว (ค่าเริ่มต้น: `'08:00:00'`)

### 4.2 รายการไฟล์ที่ต้องเปลี่ยนจากการ Hardcode ไปดึงจาก `$site_settings`:
1. **ชื่อโรงเรียน:**
   * `index.php` (Title และ Subtitle หน้าล็อกอิน)
   * `includes/header.php` (Title แถบ Browser)
   * `manifest.json` (ชื่อ App PWA)
   * `src/actions/discipline_case_pdf.php` (หัวรายงาน PDF คดี)
   * `src/actions/print_teacher_attendance.php` (หัวรายงานและชื่อ ผอ. ท้ายกระดาษ)
   * `src/actions/discipline_student_qrcode.php` (หัวบัตรประจำตัวนักเรียน)
   * `src/actions/discipline_report_scores.php`, `discipline_report_late_stats.php`, `report_homeroom_monthly.php`
2. **พิกัด GPS โรงเรียน:**
   * `src/actions/teacher_attendance.php` : แก้ไข `$SCHOOL_LAT`, `$SCHOOL_LNG` ให้ดึงจาก `$site_settings['school_lat']` และ `school_lng`
3. **ระบบละหมาดวันศุกร์:**
   * `includes/sidebar.php` : ครอบเงื่อนไข `if (!empty($site_settings['enable_friday_prayer']) && $site_settings['enable_friday_prayer'] == '1')` รอบเมนูละหมาดวันศุกร์

---

## 5. 🛡️ มาตรการความปลอดภัยและ PDPA ก่อนส่งมอบ (Sanitization)
1. **ห้าม Copy ข้อมูลในตารางต่อไปนี้เด็ดขาด (ให้ Truncate ล้างข้อมูลเป็น 0):**
   * `students` (ข้อมูลนักเรียน)
   * `users` (ลบครูเดิมออกทั้งหมด ยกเว้น Admin กลาง)
   * `user_roles` (ลบสิทธิ์เดิมออกทั้งหมด ยกเว้น Admin)
   * `homeroom_attendance` (ประวัติการเข้าแถว)
   * `class_attendance` (ประวัติการเข้าเรียน)
   * `discipline_cases` / `discipline_case_students` / `discipline_case_photos` (ประวัติคดี)
   * `teacher_attendance` (ประวัติลงเวลาครู)
   * `messages` / `notifications` (ข้อความเดิม)
2. **สร้างบัญชีผู้ดูแลเริ่มต้น (Default Superadmin):**
   * Username: `admin`
   * Password: `12345` (และสามารถเข้าสู่ระบบใช้งานได้ทันที)
3. **ล้างรูปภาพเดิม:**
   * เคลียร์โฟลเดอร์ `assets/uploads/students/*`, `assets/uploads/cases/*`, `assets/uploads/attendance/*` ให้ว่างเปล่า 100%

---

## 6. 🚀 ขั้นตอนการติดตั้งโปรเจกต์ใหม่แบบ Step-by-Step

### ขั้นที่ 1: เตรียมโฟลเดอร์โปรเจกต์ใหม่
1. สร้างโฟลเดอร์โปรเจกต์ใหม่ (เช่น `TeeDiewJob-Template`)
2. คัดลอกโค้ดจากโปรเจกต์ต้นแบบไปใส่ ยกเว้นไฟล์ข้อมูลและรูปภาพใน `assets/uploads/`
3. ตั้งค่าสิทธิ์โฟลเดอร์ `assets/uploads/` เป็น `755` (หรือ `777` ตามสภาพแวดล้อม Hosting)

### ขั้นที่ 2: ติดตั้งฐานข้อมูล (Database Setup)
1. สร้างฐานข้อมูลใหม่ใน MariaDB/MySQL (เช่น `teediewjob_db`)
2. Import ไฟล์ `schema.sql` (ที่มีเฉพาะโครงสร้างตารางและ default admin)
3. ตั้งค่าการเชื่อมต่อใน `config/database.php`:
   ```php
   $host = 'localhost';
   $db   = 'ชื่อฐานข้อมูลใหม่';
   $user = 'username';
   $pass = 'password';
   ```

### ขั้นที่ 3: ตั้งค่าข้อมูลโรงเรียนครั้งแรก (Initial Setup)
1. ล็อกอินด้วยบัญชี `admin`
2. เข้าไปที่เมนู **"จัดการระบบ / ข้อมูลโรงเรียน"** (`admin_web_management.php`)
3. กรอกชื่อโรงเรียน, อัปโหลดโลโก้, ปักหมุดพิกัด GPS, และเปิด/ปิดฟังก์ชันละหมาด
4. เปลี่ยนรหัสผ่านของบัญชี admin ทันที

### ขั้นที่ 4: นำเข้าข้อมูลนักเรียนและบุคลากร (Data Onboarding)
1. เตรียมไฟล์ Excel รายชื่อนักเรียน (รหัส, คำนำหน้า, ชื่อ, สกุล, ชั้น, ห้อง, เลขที่) จากระบบ DMC/SGS
2. ใช้ฟังก์ชัน **นำเข้านักเรียนผ่าน Excel** เข้าสู่ระบบ
3. นำเข้าข้อมูลครูและกำหนดบทบาทประจำห้องเรียน (ครูที่ปรึกษา)
4. พร้อมเปิดใช้งานระบบได้ทันที!

---

## 7. ✅ บันทึกผลการทดสอบและความพร้อมของ Template (Verification Report)

| รายการทดสอบ | ผลการตรวจสอบ | หมายเหตุ |
| :--- | :---: | :--- |
| **ความสะอาดของโฟลเดอร์ & โค้ด** | ผ่าน (100%) | ตัดไฟล์สคริปต์แพตช์ชั่วคราวออกทั้งหมด ไม่มีไฟล์ขยะตกค้าง |
| **ความปลอดภัยตามหลัก PDPA** | ผ่าน (100%) | ลบประวัตินักเรียน บุคลากร ภาพถ่าย และคดีเดิมทั้งหมด 0 ตกค้าง |
| **โครงสร้างฐานข้อมูล (schema.sql)** | ผ่าน (100%) | ครบ 31 ตาราง พร้อม Initial Seeds ภาคเรียน 2569/1 และ 10 Roles |
| **บัญชี Superadmin เริ่มต้น** | ผ่าน (100%) | Username: `admin` / Password: `12345` พร้อมใช้งาน |
| **การปลดล็อค Hardcode** | ผ่าน (100%) | ดึงชื่อโรงเรียน, โลโก้, พิกัด GPS, รัศมี, เวลาตัดสาย และเปิด/ปิดละหมาดจาก `site_settings` |
| **ระบบนำเข้าข้อมูล (Data Onboarding)** | ผ่าน (100%) | รองรับ UTF-8 BOM, แปลงรหัส Windows-874/TIS-620, เติมเลข 0 นำหน้ารหัสนักเรียน, ตรวจจับเพศอัตโนมัติ และเซ็ตคะแนนพฤติกรรม 100 อัตโนมัติ |
| **PHP Syntax Check** | ผ่าน (100%) | ทดสอบ `php -l` ทุกไฟล์สำคัญ ไร้ข้อผิดพลาด |

---
*เอกสารนี้ได้รับการปรับปรุงและพร้อมใช้งานเป็น Master Template สำหรับการติดตั้งในทุกโรงเรียน*

