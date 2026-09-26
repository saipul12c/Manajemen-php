<?php
/**
 * Konfigurasi Database & Helper Sistem
 * Manajemen-PHP
 */

$host = "localhost";
$dbname = "website_login";
$username = "root";
$password_db = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password_db,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // Auto-create tables only once per session for performance (BUG-19 fix)
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['db_migrated_v11'])) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `announcements` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `content` TEXT NOT NULL,
            `target_role` ENUM('semua', 'guru', 'siswa', 'orang_tua', 'staf') NOT NULL DEFAULT 'semua',
            `class_id` INT DEFAULT NULL,
            `category` ENUM('umum', 'akademik', 'kegiatan', 'penting', 'darurat') NOT NULL DEFAULT 'umum',
            `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
            `attachment_url` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('draft', 'published') NOT NULL DEFAULT 'published',
            `expires_at` DATETIME DEFAULT NULL,
            `event_id` INT DEFAULT NULL,
            `author_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `announcement_reads` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `announcement_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_announcement_user` (`announcement_id`, `user_id`),
            CONSTRAINT `fk_reads_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_reads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `assignments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `subject` VARCHAR(100) NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT NOT NULL,
            `due_date` DATE NOT NULL,
            `attachment_url` VARCHAR(255) DEFAULT NULL,
            `teacher_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `assignment_submissions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `assignment_id` INT NOT NULL,
            `student_id` INT NOT NULL,
            `status` ENUM('belum', 'selesai') NOT NULL DEFAULT 'belum',
            `notes` TEXT DEFAULT NULL,
            `file_url` VARCHAR(255) DEFAULT NULL,
            `score` DECIMAL(5,2) DEFAULT NULL,
            `feedback` TEXT DEFAULT NULL,
            `submitted_at` DATETIME DEFAULT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_student_assignment` (`assignment_id`, `student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `service_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `request_type` VARCHAR(100) NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `status` ENUM('menunggu', 'diproses', 'selesai', 'ditolak') NOT NULL DEFAULT 'menunggu',
            `processed_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `exams` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `subject` VARCHAR(100) NOT NULL,
            `category` ENUM('uts', 'ukk', 'ujian_harian', 'latihan_harian', 'latihan_mingguan', 'latihan_bulanan') NOT NULL,
            `type` ENUM('ujian', 'latihan') NOT NULL DEFAULT 'ujian',
            `description` TEXT DEFAULT NULL,
            `duration_minutes` INT DEFAULT 0,
            `passing_grade` INT DEFAULT 75,
            `token` VARCHAR(20) DEFAULT NULL,
            `randomize_questions` TINYINT(1) DEFAULT 0,
            `hide_answers_until_due` TINYINT(1) DEFAULT 0,
            `start_time` DATETIME DEFAULT NULL,
            `due_date` DATETIME NOT NULL,
            `teacher_id` INT NOT NULL,
            `status` ENUM('aktif', 'selesai', 'draft') NOT NULL DEFAULT 'aktif',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `exam_questions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `exam_id` INT NOT NULL,
            `question_type` ENUM('multiple_choice', 'essay') DEFAULT 'multiple_choice',
            `max_score` DECIMAL(5,2) DEFAULT 10.00,
            `question_text` TEXT NOT NULL,
            `image_url` VARCHAR(255) DEFAULT NULL,
            `option_a` TEXT DEFAULT NULL,
            `option_b` TEXT DEFAULT NULL,
            `option_c` TEXT DEFAULT NULL,
            `option_d` TEXT DEFAULT NULL,
            `correct_answer` VARCHAR(10) DEFAULT NULL,
            `explanation` TEXT DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `exam_submissions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `exam_id` INT NOT NULL,
            `student_id` INT NOT NULL,
            `score` DECIMAL(5,2) DEFAULT 0.00,
            `total_correct` INT DEFAULT 0,
            `total_questions` INT DEFAULT 0,
            `answers` TEXT DEFAULT NULL,
            `essay_scores` TEXT DEFAULT NULL,
            `essay_graded` TINYINT(1) DEFAULT 1,
            `status` ENUM('selesai') NOT NULL DEFAULT 'selesai',
            `is_remedial` TINYINT(1) DEFAULT 0,
            `remedial_granted` TINYINT(1) DEFAULT 0,
            `previous_score` DECIMAL(5,2) DEFAULT NULL,
            `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_exam_student` (`exam_id`, `student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `student_attendance` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `student_id` INT NOT NULL,
            `date` DATE NOT NULL,
            `status` ENUM('hadir', 'sakit', 'izin', 'alpa') NOT NULL DEFAULT 'hadir',
            `notes` VARCHAR(255) DEFAULT NULL,
            `recorded_by` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_student_attendance_date` (`student_id`, `date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `calendar_events` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `event_date` DATE NOT NULL,
            `end_date` DATE DEFAULT NULL,
            `category` ENUM('akademik', 'libur', 'kegiatan', 'ujian') NOT NULL DEFAULT 'kegiatan',
            `color` VARCHAR(20) DEFAULT 'blue',
            `created_by` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `parent_students` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `parent_id` INT NOT NULL,
            `student_id` INT NOT NULL,
            `relation_type` VARCHAR(50) DEFAULT 'Wali Murid',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_parent_student` (`parent_id`, `student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `classes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `grade_level` VARCHAR(20) NOT NULL DEFAULT '10',
            `academic_year` VARCHAR(50) NOT NULL DEFAULT '2026/2027',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `school_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `setting_key` VARCHAR(100) NOT NULL UNIQUE,
            `setting_value` TEXT DEFAULT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT DEFAULT NULL,
            `action` VARCHAR(100) NOT NULL,
            `details` TEXT DEFAULT NULL,
            `ip_address` VARCHAR(50) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 15. Tabel Mata Pelajaran
        CREATE TABLE IF NOT EXISTS `subjects` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `code` VARCHAR(20) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 16. Tabel Jadwal Pelajaran Mingguan
        CREATE TABLE IF NOT EXISTS `timetables` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `class_id` INT NOT NULL,
            `subject_id` INT DEFAULT NULL,
            `subject_name` VARCHAR(100) NOT NULL,
            `teacher_id` INT NOT NULL,
            `day` ENUM('Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu') NOT NULL,
            `start_time` TIME NOT NULL,
            `end_time` TIME NOT NULL,
            `room` VARCHAR(50) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 17. Tabel Materi Pembelajaran & E-Learning
        CREATE TABLE IF NOT EXISTS `learning_materials` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `subject` VARCHAR(100) NOT NULL,
            `class_id` INT DEFAULT NULL,
            `teacher_id` INT NOT NULL,
            `description` TEXT DEFAULT NULL,
            `file_url` VARCHAR(255) DEFAULT NULL,
            `link_url` VARCHAR(255) DEFAULT NULL,
            `file_type` VARCHAR(50) DEFAULT 'document',
            `download_count` INT DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 18. Tabel Jenis Tagihan Keuangan
        CREATE TABLE IF NOT EXISTS `payment_types` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `description` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 19. Tabel Tagihan Siswa
        CREATE TABLE IF NOT EXISTS `student_bills` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `student_id` INT NOT NULL,
            `payment_type_id` INT DEFAULT NULL,
            `title` VARCHAR(200) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `due_date` DATE NOT NULL,
            `month_period` VARCHAR(30) DEFAULT NULL,
            `academic_year` VARCHAR(30) DEFAULT '2026/2027',
            `status` ENUM('belum_lunas', 'menunggu_verifikasi', 'lunas') NOT NULL DEFAULT 'belum_lunas',
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 20. Tabel Transaksi Pembayaran Tagihan
        CREATE TABLE IF NOT EXISTS `bill_payments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `bill_id` INT NOT NULL,
            `student_id` INT NOT NULL,
            `amount_paid` DECIMAL(12,2) NOT NULL,
            `payment_method` ENUM('transfer_bank', 'tunai', 'qris') NOT NULL DEFAULT 'transfer_bank',
            `payment_date` DATE NOT NULL,
            `proof_url` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('menunggu', 'diterima', 'ditolak') NOT NULL DEFAULT 'menunggu',
            `notes` TEXT DEFAULT NULL,
            `verified_by` INT DEFAULT NULL,
            `verified_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 21. Tabel Bimbingan Konseling (BK) - Pelanggaran & Prestasi
        CREATE TABLE IF NOT EXISTS `counseling_records` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `student_id` INT NOT NULL,
            `type` ENUM('pelanggaran', 'prestasi') NOT NULL,
            `category` VARCHAR(100) NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `points` INT NOT NULL DEFAULT 0,
            `action_taken` TEXT DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `recorded_by` INT NOT NULL,
            `date` DATE NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 22. Tabel Pesan & Konsultasi Internal
        CREATE TABLE IF NOT EXISTS `messages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `sender_id` INT NOT NULL,
            `receiver_id` INT NOT NULL,
            `message` TEXT NOT NULL,
            `attachment_url` VARCHAR(255) DEFAULT NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 23. PPDB (Penerimaan Peserta Didik Baru)
        CREATE TABLE IF NOT EXISTS `ppdb_registrations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `registration_no` VARCHAR(50) NOT NULL UNIQUE,
            `full_name` VARCHAR(150) NOT NULL,
            `nisn` VARCHAR(20) NOT NULL,
            `nik` VARCHAR(30) DEFAULT NULL,
            `gender` ENUM('L', 'P') NOT NULL,
            `birth_place` VARCHAR(100) NOT NULL,
            `birth_date` DATE NOT NULL,
            `religion` VARCHAR(50) DEFAULT 'Islam',
            `phone` VARCHAR(30) NOT NULL,
            `email` VARCHAR(100) NOT NULL,
            `address` TEXT NOT NULL,
            `previous_school` VARCHAR(150) NOT NULL,
            `chosen_major` VARCHAR(100) NOT NULL DEFAULT 'Umum',
            `track_type` ENUM('reguler', 'zonasi', 'prestasi', 'afirmasi') NOT NULL DEFAULT 'reguler',
            `distance_km` DECIMAL(6,2) DEFAULT NULL,
            `achievement_desc` VARCHAR(255) DEFAULT NULL,
            `achievement_level` ENUM('sekolah', 'kecamatan', 'kabupaten', 'provinsi', 'nasional', 'internasional') DEFAULT NULL,
            `affirmation_no` VARCHAR(50) DEFAULT NULL,
            `score_math` DECIMAL(5,2) DEFAULT NULL,
            `score_science` DECIMAL(5,2) DEFAULT NULL,
            `score_indonesian` DECIMAL(5,2) DEFAULT NULL,
            `score_english` DECIMAL(5,2) DEFAULT NULL,
            `calculated_score` DECIMAL(5,2) DEFAULT NULL,
            `parent_name` VARCHAR(150) NOT NULL,
            `parent_phone` VARCHAR(30) NOT NULL,
            `parent_job` VARCHAR(100) DEFAULT NULL,
            `report_card_doc` VARCHAR(255) DEFAULT NULL,
            `birth_cert_doc` VARCHAR(255) DEFAULT NULL,
            `family_card_doc` VARCHAR(255) DEFAULT NULL,
            `photo_doc` VARCHAR(255) DEFAULT NULL,
            `document_status` ENUM('lengkap', 'perlu_revisi', 'ditolak') NOT NULL DEFAULT 'lengkap',
            `rejection_reason` TEXT DEFAULT NULL,
            `status` ENUM('menunggu_verifikasi', 'diverifikasi', 'lulus_seleksi', 'tidak_lulus', 'diterima') NOT NULL DEFAULT 'menunggu_verifikasi',
            `selection_score` DECIMAL(5,2) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `user_id` INT DEFAULT NULL,
            `qr_token` VARCHAR(64) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 24. Perpustakaan: Buku & E-Book
        CREATE TABLE IF NOT EXISTS `library_books` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(50) NOT NULL UNIQUE,
            `isbn` VARCHAR(50) DEFAULT NULL,
            `title` VARCHAR(200) NOT NULL,
            `author` VARCHAR(150) NOT NULL,
            `publisher` VARCHAR(150) DEFAULT NULL,
            `year` INT DEFAULT NULL,
            `category` VARCHAR(100) NOT NULL DEFAULT 'Umum',
            `stock_total` INT NOT NULL DEFAULT 1,
            `stock_available` INT NOT NULL DEFAULT 1,
            `shelf_location` VARCHAR(100) DEFAULT 'Rak A-1',
            `cover_image` VARCHAR(255) DEFAULT NULL,
            `ebook_file` VARCHAR(255) DEFAULT NULL,
            `description` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 25. Perpustakaan: Sirkulasi Peminjaman & Pengembalian
        CREATE TABLE IF NOT EXISTS `library_loans` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `book_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `borrow_date` DATE NOT NULL,
            `due_date` DATE NOT NULL,
            `return_date` DATE DEFAULT NULL,
            `fine_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('dipinjam', 'kembali', 'hilang') NOT NULL DEFAULT 'dipinjam',
            `renewal_count` TINYINT NOT NULL DEFAULT 0,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 26. Perpustakaan: Buku Tamu & Presensi Pengunjung
        CREATE TABLE IF NOT EXISTS `library_visitors` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT DEFAULT NULL,
            `name` VARCHAR(150) NOT NULL,
            `role` ENUM('siswa', 'guru', 'staf', 'umum') NOT NULL DEFAULT 'siswa',
            `identifier` VARCHAR(50) DEFAULT NULL,
            `class_name` VARCHAR(50) DEFAULT NULL,
            `gender` ENUM('L', 'P') DEFAULT NULL,
            `purpose` VARCHAR(150) NOT NULL DEFAULT 'Membaca / Belajar',
            `visit_date` DATE NOT NULL,
            `visit_time` TIME NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 27. Perpustakaan: Reservasi / Booking Buku Mandiri
        CREATE TABLE IF NOT EXISTS `library_reservations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `book_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `reservation_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expiry_date` DATE NOT NULL,
            `status` ENUM('menunggu', 'disiapkan', 'selesai', 'dibatalkan', 'kedaluwarsa') NOT NULL DEFAULT 'menunggu',
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 28. Perpustakaan: Review & Rating Buku
        CREATE TABLE IF NOT EXISTS `library_reviews` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `book_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `rating` TINYINT NOT NULL,
            `review` TEXT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 29. Pesan Kontak Tamu (Inbox Tamu Publik)
        CREATE TABLE IF NOT EXISTS `contact_messages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NOT NULL,
            `phone` VARCHAR(50) DEFAULT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `status` ENUM('baru', 'diproses', 'selesai') NOT NULL DEFAULT 'baru',
            `admin_notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 30. Buku Agenda Surat Masuk & Surat Keluar (Arsip Persuratan TU)
        CREATE TABLE IF NOT EXISTS `mail_archives` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `mail_type` ENUM('masuk', 'keluar') NOT NULL DEFAULT 'masuk',
            `agenda_no` VARCHAR(50) NOT NULL,
            `reference_no` VARCHAR(100) NOT NULL,
            `sender_or_recipient` VARCHAR(200) NOT NULL,
            `mail_date` DATE NOT NULL,
            `received_or_sent_date` DATE NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `disposition_instruction` TEXT DEFAULT NULL,
            `disposition_target` VARCHAR(150) DEFAULT NULL,
            `attachment_file` VARCHAR(255) DEFAULT NULL,
            `recorded_by` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 31. Sarpras: Buku Inventaris Barang & Aset Sekolah
        CREATE TABLE IF NOT EXISTS `inventory_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `item_code` VARCHAR(50) NOT NULL UNIQUE,
            `item_name` VARCHAR(150) NOT NULL,
            `category` VARCHAR(100) NOT NULL DEFAULT 'Elektronik',
            `room_location` VARCHAR(100) NOT NULL DEFAULT 'Ruang Lab Komputer',
            `condition_status` ENUM('baik', 'rusak_ringan', 'rusak_berat') NOT NULL DEFAULT 'baik',
            `quantity` INT NOT NULL DEFAULT 1,
            `unit` VARCHAR(30) NOT NULL DEFAULT 'Unit',
            `funding_source` VARCHAR(100) DEFAULT 'BOS',
            `purchase_year` INT DEFAULT 2026,
            `purchase_cost` DECIMAL(12,2) DEFAULT 0.00,
            `notes` TEXT DEFAULT NULL,
            `recorded_by` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 32. Sarpras: Peminjaman Sarana & Prasarana
        CREATE TABLE IF NOT EXISTS `inventory_loans` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `item_id` INT NOT NULL,
            `borrower_name` VARCHAR(150) NOT NULL,
            `borrower_role` VARCHAR(50) NOT NULL DEFAULT 'guru',
            `borrower_phone` VARCHAR(30) DEFAULT NULL,
            `borrow_date` DATE NOT NULL,
            `expected_return_date` DATE NOT NULL,
            `actual_return_date` DATE DEFAULT NULL,
            `status` ENUM('dipinjam', 'kembali') NOT NULL DEFAULT 'dipinjam',
            `purpose` VARCHAR(255) NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `recorded_by` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 33. Keuangan: Buku Kas Umum (BKU) / Pengeluaran Operasional Sekolah
        CREATE TABLE IF NOT EXISTS `financial_expenses` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `expense_no` VARCHAR(50) NOT NULL UNIQUE,
            `category` VARCHAR(100) NOT NULL DEFAULT 'ATK & Operasional Kantor',
            `title` VARCHAR(200) NOT NULL,
            `expense_date` DATE NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL,
            `recipient` VARCHAR(150) DEFAULT NULL,
            `receipt_doc` VARCHAR(255) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `recorded_by` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Auto-migration untuk kolom baru jika tabel sudah pernah dibuat sebelumnya
    $alter_queries = [
        "ALTER TABLE `exams` ADD COLUMN `start_time` DATETIME DEFAULT NULL AFTER `duration_minutes`",
        "ALTER TABLE `exams` ADD COLUMN `token` VARCHAR(20) DEFAULT NULL AFTER `passing_grade`",
        "ALTER TABLE `exams` ADD COLUMN `randomize_questions` TINYINT(1) DEFAULT 0 AFTER `token`",
        "ALTER TABLE `exams` ADD COLUMN `hide_answers_until_due` TINYINT(1) DEFAULT 0 AFTER `randomize_questions`",
        "ALTER TABLE `exam_questions` ADD COLUMN `image_url` VARCHAR(255) DEFAULT NULL AFTER `question_text`",
        "ALTER TABLE `exam_questions` ADD COLUMN `question_type` ENUM('multiple_choice', 'essay') DEFAULT 'multiple_choice' AFTER `exam_id`",
        "ALTER TABLE `exam_questions` ADD COLUMN `max_score` DECIMAL(5,2) DEFAULT 10.00 AFTER `question_type`",
        "ALTER TABLE `exam_questions` MODIFY COLUMN `option_a` TEXT DEFAULT NULL",
        "ALTER TABLE `exam_questions` MODIFY COLUMN `option_b` TEXT DEFAULT NULL",
        "ALTER TABLE `exam_questions` MODIFY COLUMN `option_c` TEXT DEFAULT NULL",
        "ALTER TABLE `exam_questions` MODIFY COLUMN `option_d` TEXT DEFAULT NULL",
        "ALTER TABLE `exam_questions` MODIFY COLUMN `correct_answer` VARCHAR(10) DEFAULT NULL",
        "ALTER TABLE `exam_submissions` ADD COLUMN `essay_scores` TEXT DEFAULT NULL AFTER `answers`",
        "ALTER TABLE `exam_submissions` ADD COLUMN `essay_graded` TINYINT(1) DEFAULT 1 AFTER `essay_scores`",
        "ALTER TABLE `exam_submissions` ADD COLUMN `is_remedial` TINYINT(1) DEFAULT 0 AFTER `status`",
        "ALTER TABLE `exam_submissions` ADD COLUMN `remedial_granted` TINYINT(1) DEFAULT 0 AFTER `is_remedial`",
        "ALTER TABLE `exam_submissions` ADD COLUMN `previous_score` DECIMAL(5,2) DEFAULT NULL AFTER `remedial_granted`",
        "ALTER TABLE `assignments` ADD COLUMN `attachment_url` VARCHAR(255) DEFAULT NULL AFTER `due_date`",
        "ALTER TABLE `assignment_submissions` ADD COLUMN `file_url` VARCHAR(255) DEFAULT NULL AFTER `notes`",
        "ALTER TABLE `assignment_submissions` ADD COLUMN `score` DECIMAL(5,2) DEFAULT NULL AFTER `file_url`",
        "ALTER TABLE `assignment_submissions` ADD COLUMN `feedback` TEXT DEFAULT NULL AFTER `score`",
        "ALTER TABLE `assignment_submissions` ADD COLUMN `submitted_at` DATETIME DEFAULT NULL AFTER `feedback`",
        "ALTER TABLE `student_attendance` ADD COLUMN `subject` VARCHAR(100) DEFAULT NULL AFTER `date`",
        "ALTER TABLE `users` ADD COLUMN `class_id` INT DEFAULT NULL AFTER `role`",
        "ALTER TABLE `users` ADD COLUMN `nisn` VARCHAR(20) DEFAULT NULL AFTER `address`",
        "ALTER TABLE `users` ADD COLUMN `gender` ENUM('L', 'P') DEFAULT 'L' AFTER `nisn`",
        "ALTER TABLE `service_requests` ADD COLUMN `target_date` DATE DEFAULT NULL AFTER `request_type`",
        "ALTER TABLE `service_requests` ADD COLUMN `attachment_url` VARCHAR(255) DEFAULT NULL AFTER `notes`",
        "ALTER TABLE `announcements` ADD COLUMN `category` ENUM('umum', 'akademik', 'kegiatan', 'penting', 'darurat') NOT NULL DEFAULT 'umum' AFTER `target_role`",
        "ALTER TABLE `announcements` ADD COLUMN `class_id` INT DEFAULT NULL AFTER `target_role`",
        "ALTER TABLE `announcements` ADD COLUMN `is_pinned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `category`",
        "ALTER TABLE `announcements` ADD COLUMN `attachment_url` VARCHAR(255) DEFAULT NULL AFTER `is_pinned`",
        "ALTER TABLE `announcements` ADD COLUMN `status` ENUM('draft', 'published') NOT NULL DEFAULT 'published' AFTER `attachment_url`",
        "ALTER TABLE `announcements` ADD COLUMN `expires_at` DATETIME DEFAULT NULL AFTER `status`",
        "ALTER TABLE `announcements` ADD COLUMN `event_id` INT DEFAULT NULL AFTER `expires_at`",
        "ALTER TABLE `announcements` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
        "CREATE TABLE IF NOT EXISTS `announcement_reads` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `announcement_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_announcement_user` (`announcement_id`, `user_id`),
            CONSTRAINT `fk_reads_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_reads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `student_report_notes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `student_id` INT NOT NULL,
            `academic_year` VARCHAR(50) NOT NULL DEFAULT '2026/2027',
            `semester` VARCHAR(20) NOT NULL DEFAULT 'Ganjil',
            `homeroom_notes` TEXT DEFAULT NULL,
            `extracurricular` TEXT DEFAULT NULL,
            `created_by` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_student_report_period` (`student_id`, `academic_year`, `semester`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "ALTER TABLE `library_loans` ADD COLUMN `renewal_count` TINYINT NOT NULL DEFAULT 0 AFTER `status`",
        "CREATE TABLE IF NOT EXISTS `library_visitors` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT DEFAULT NULL,
            `name` VARCHAR(150) NOT NULL,
            `role` ENUM('siswa', 'guru', 'staf', 'umum') NOT NULL DEFAULT 'siswa',
            `identifier` VARCHAR(50) DEFAULT NULL,
            `class_name` VARCHAR(50) DEFAULT NULL,
            `gender` ENUM('L', 'P') DEFAULT NULL,
            `purpose` VARCHAR(150) NOT NULL DEFAULT 'Membaca / Belajar',
            `visit_date` DATE NOT NULL,
            `visit_time` TIME NOT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `track_type` ENUM('reguler', 'zonasi', 'prestasi', 'afirmasi') NOT NULL DEFAULT 'reguler' AFTER `chosen_major`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `distance_km` DECIMAL(6,2) DEFAULT NULL AFTER `track_type`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `achievement_desc` VARCHAR(255) DEFAULT NULL AFTER `distance_km`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `achievement_level` ENUM('sekolah', 'kecamatan', 'kabupaten', 'provinsi', 'nasional', 'internasional') DEFAULT NULL AFTER `achievement_desc`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `affirmation_no` VARCHAR(50) DEFAULT NULL AFTER `achievement_level`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `score_math` DECIMAL(5,2) DEFAULT NULL AFTER `affirmation_no`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `score_science` DECIMAL(5,2) DEFAULT NULL AFTER `score_math`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `score_indonesian` DECIMAL(5,2) DEFAULT NULL AFTER `score_science`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `score_english` DECIMAL(5,2) DEFAULT NULL AFTER `score_indonesian`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `calculated_score` DECIMAL(5,2) DEFAULT NULL AFTER `score_english`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `document_status` ENUM('lengkap', 'perlu_revisi', 'ditolak') NOT NULL DEFAULT 'lengkap' AFTER `photo_doc`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `rejection_reason` TEXT DEFAULT NULL AFTER `document_status`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `qr_token` VARCHAR(64) DEFAULT NULL AFTER `user_id`",
        "CREATE TABLE IF NOT EXISTS `library_reservations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `book_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `reservation_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expiry_date` DATE NOT NULL,
            `status` ENUM('menunggu', 'disiapkan', 'selesai', 'dibatalkan', 'kedaluwarsa') NOT NULL DEFAULT 'menunggu',
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `library_reviews` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `book_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `rating` TINYINT NOT NULL,
            `review` TEXT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_book_user_review` (`book_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($alter_queries as $aq) {
        try {
            $pdo->exec($aq);
        } catch (PDOException $e_alter) {
            // Kolom sudah ada
        }
    }

    // Auto-seed data sampel kalender & presensi jika masih kosong
    try {
        $count_events = (int) $pdo->query("SELECT COUNT(*) FROM `calendar_events`")->fetchColumn();
        if ($count_events === 0) {
            $pdo->exec("
                INSERT INTO `calendar_events` (`title`, `description`, `event_date`, `end_date`, `category`, `color`, `created_by`) VALUES
                ('Rapat Pleno Dewan Guru & Staf', 'Penyelarasan silabus dan jadwal asesmen semester ganjil.', CURRENT_DATE(), NULL, 'kegiatan', 'emerald', 1),
                ('Pekan Ulangan Tengah Semester (UTS)', 'Pelaksanaan UTS serentak seluruh kelas ganjil.', DATE_ADD(CURRENT_DATE(), INTERVAL 5 DAY), DATE_ADD(CURRENT_DATE(), INTERVAL 10 DAY), 'ujian', 'purple', 3),
                ('Peringatan Hari Guru Nasional', 'Upacara bendera dan pentas seni kebersamaan civitas sekolah.', DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY), NULL, 'akademik', 'blue', 2),
                ('Libur Cuti Bersama Nasional', 'Libur pembelajaran daring dan luring sesuai surat edaran dinas.', DATE_ADD(CURRENT_DATE(), INTERVAL 20 DAY), DATE_ADD(CURRENT_DATE(), INTERVAL 21 DAY), 'libur', 'rose', 1);
            ");
        }

        // Auto-seed school_settings
        $count_settings = (int) $pdo->query("SELECT COUNT(*) FROM `school_settings`")->fetchColumn();
        if ($count_settings === 0) {
            $pdo->exec("
                INSERT INTO `school_settings` (`setting_key`, `setting_value`) VALUES
                ('school_name', 'SMA Bina Bangsa Nusantara'),
                ('school_address', 'Jl. Pendidikan Nasional No. 45, Kebayoran Baru, Jakarta'),
                ('school_phone', '(021) 789-0123'),
                ('school_email', 'info@binabangsa.sch.id'),
                ('school_website', 'https://binabangsa.sch.id'),
                ('headmaster_name', 'Dr. H. Bambang Sudirman, M.Pd'),
                ('headmaster_nip', '19750812 199903 1 002'),
                ('academic_year', '2026/2027 Ganjil'),
                ('school_logo', '');
            ");
        }

        // Auto-seed classes
        $count_classes = (int) $pdo->query("SELECT COUNT(*) FROM `classes`")->fetchColumn();
        if ($count_classes === 0) {
            $pdo->exec("
                INSERT INTO `classes` (`id`, `name`, `grade_level`, `academic_year`) VALUES
                (1, 'X MIPA 1', '10', '2026/2027'),
                (2, 'XI MIPA 2', '11', '2026/2027'),
                (3, 'XII IPS 1', '12', '2026/2027');
            ");
            // Kaitkan siswa Ahmad Fauzi (ID: 5) ke kelas 1
            $pdo->exec("UPDATE `users` SET `class_id` = 1, `nisn` = '0081234567', `gender` = 'L' WHERE `id` = 5");
        }

        // Auto-seed parent_students (Orang tua Hendra Wijaya ID 4 mengawasi Siswa Ahmad Fauzi ID 5)
        $count_ps = (int) $pdo->query("SELECT COUNT(*) FROM `parent_students`")->fetchColumn();
        if ($count_ps === 0) {
            $pdo->exec("
                INSERT IGNORE INTO `parent_students` (`parent_id`, `student_id`, `relation_type`)
                VALUES (4, 5, 'Ayah Kandung');
            ");
        }

        $count_att = (int) $pdo->query("SELECT COUNT(*) FROM `student_attendance`")->fetchColumn();
        if ($count_att === 0) {
            // Ambil ID siswa yang ada
            $student_rows = $pdo->query("SELECT id FROM users WHERE role = 'siswa' LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($student_rows)) {
                $stmt_seed_att = $pdo->prepare("
                    INSERT IGNORE INTO `student_attendance` (`student_id`, `date`, `status`, `notes`, `recorded_by`)
                    VALUES (?, ?, ?, ?, 3)
                ");
                $today = date('Y-m-d');
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                $two_days_ago = date('Y-m-d', strtotime('-2 days'));
                
                foreach ($student_rows as $s_id) {
                    $stmt_seed_att->execute([$s_id, $today, 'hadir', 'Tepat waktu']);
                    $stmt_seed_att->execute([$s_id, $yesterday, 'hadir', 'Hadir']);
                    $stmt_seed_att->execute([$s_id, $two_days_ago, 'hadir', 'Hadir']);
                }
            }
        }
        // Auto-seed Mata Pelajaran (subjects)
        $count_subj = (int) $pdo->query("SELECT COUNT(*) FROM `subjects`")->fetchColumn();
        if ($count_subj === 0) {
            $pdo->exec("
                INSERT INTO `subjects` (`id`, `name`, `code`) VALUES
                (1, 'Matematika', 'MTK'),
                (2, 'Bahasa Indonesia', 'BIN'),
                (3, 'Bahasa Inggris', 'BIG'),
                (4, 'Fisika', 'FIS'),
                (5, 'Biologi', 'BIO'),
                (6, 'Kimia', 'KIM'),
                (7, 'Sejarah Indonesia', 'SEJ'),
                (8, 'PJOK / Penjas', 'PJK'),
                (9, 'Pendidikan Agama Islam', 'PAI'),
                (10, 'Seni Budaya', 'SNB');
            ");
        }

        // Auto-seed Jadwal Pelajaran (timetables)
        $count_tt = (int) $pdo->query("SELECT COUNT(*) FROM `timetables`")->fetchColumn();
        if ($count_tt === 0) {
            $pdo->exec("
                INSERT INTO `timetables` (`class_id`, `subject_id`, `subject_name`, `teacher_id`, `day`, `start_time`, `end_time`, `room`) VALUES
                (1, 1, 'Matematika', 3, 'Senin', '07:30:00', '09:00:00', 'R. 101'),
                (1, 4, 'Fisika', 3, 'Senin', '09:15:00', '10:45:00', 'Lab Fisika'),
                (1, 2, 'Bahasa Indonesia', 3, 'Selasa', '07:30:00', '09:00:00', 'R. 101'),
                (1, 3, 'Bahasa Inggris', 3, 'Selasa', '09:15:00', '10:45:00', 'R. 101'),
                (1, 5, 'Biologi', 3, 'Rabu', '07:30:00', '09:30:00', 'Lab Biologi'),
                (1, 6, 'Kimia', 3, 'Rabu', '09:45:00', '11:15:00', 'Lab Kimia'),
                (1, 7, 'Sejarah Indonesia', 3, 'Kamis', '07:30:00', '09:00:00', 'R. 101'),
                (1, 9, 'Pendidikan Agama Islam', 3, 'Kamis', '09:15:00', '10:45:00', 'R. 101'),
                (1, 8, 'PJOK / Penjas', 3, 'Jumat', '07:00:00', '08:30:00', 'Lapangan Utama'),
                (1, 10, 'Seni Budaya', 3, 'Sabtu', '08:00:00', '09:30:00', 'Studio Seni');
            ");
        }

        // Auto-seed Materi Pembelajaran (learning_materials)
        $count_mat = (int) $pdo->query("SELECT COUNT(*) FROM `learning_materials`")->fetchColumn();
        if ($count_mat === 0) {
            $pdo->exec("
                INSERT INTO `learning_materials` (`title`, `subject`, `class_id`, `teacher_id`, `description`, `file_url`, `link_url`, `file_type`, `download_count`) VALUES
                ('Modul Mandiri Aljabar & Fungsi Kuadrat Lengkap', 'Matematika', 1, 3, 'Bahan ajar materi fungsi kuadrat, grafik parabola, dan latihan soal persiapan UTS.', NULL, 'https://drive.google.com', 'document', 14),
                ('Video Pembelajaran: Dinamika Gerak & Hukum II Newton', 'Fisika', 1, 3, 'Penjelasan konsep gaya dan percepatan benda beserta contoh fenomena sehari-hari.', NULL, 'https://www.youtube.com/watch?v=kKKM8Y-u7ds', 'video', 28),
                ('Slide Presentasi Struktur Teks Eksplanasi & Debat', 'Bahasa Indonesia', 1, 3, 'Materi panduan menyusun argumen dalam debat ilmiah dan struktur teks negosiasi.', NULL, 'https://drive.google.com', 'document', 19);
            ");
        }

        // Auto-seed Jenis Tagihan (payment_types)
        $count_pt = (int) $pdo->query("SELECT COUNT(*) FROM `payment_types`")->fetchColumn();
        if ($count_pt === 0) {
            $pdo->exec("
                INSERT INTO `payment_types` (`id`, `name`, `amount`, `description`) VALUES
                (1, 'SPP Bulanan Semester Ganjil', 350000.00, 'Iuran penyelenggaraan pendidikan rutin setiap bulan.'),
                (2, 'Uang Kegiatan & Ekstrakurikuler', 150000.00, 'Iuran operasional perlombaan dan kegiatan siswa tahunan.'),
                (3, 'Sumbangan Pengembangan Sarana Gedung', 500000.00, 'Biaya perawatan laboratorium dan fasilitas belajar digital.');
            ");
        }

        // Auto-seed Tagihan Siswa (student_bills)
        $count_sb = (int) $pdo->query("SELECT COUNT(*) FROM `student_bills`")->fetchColumn();
        if ($count_sb === 0) {
            $pdo->exec("
                INSERT INTO `student_bills` (`id`, `student_id`, `payment_type_id`, `title`, `amount`, `due_date`, `month_period`, `status`, `notes`) VALUES
                (1, 5, 1, 'SPP Bulanan - September 2026', 350000.00, '2026-09-10', 'September 2026', 'lunas', 'Lunas dibayar tepat waktu.'),
                (2, 5, 1, 'SPP Bulanan - Oktober 2026', 350000.00, '2026-10-10', 'Oktober 2026', 'belum_lunas', 'Jatuh tempo tanggal 10 setiap bulan.'),
                (3, 5, 2, 'Uang Kegiatan Siswa Ganjil 2026/2027', 150000.00, '2026-09-30', 'Semester Ganjil', 'lunas', 'Termasuk atribut lomba dan ekstrakurikuler.');
            ");
        }

        // Auto-seed Transaksi Pembayaran (bill_payments)
        $count_bp = (int) $pdo->query("SELECT COUNT(*) FROM `bill_payments`")->fetchColumn();
        if ($count_bp === 0) {
            $pdo->exec("
                INSERT INTO `bill_payments` (`bill_id`, `student_id`, `amount_paid`, `payment_method`, `payment_date`, `proof_url`, `status`, `notes`, `verified_by`, `verified_at`) VALUES
                (1, 5, 350000.00, 'transfer_bank', '2026-09-08', NULL, 'diterima', 'Pembayaran via Bank Transfer BCA telah terverifikasi.', 2, NOW()),
                (3, 5, 150000.00, 'tunai', '2026-09-12', NULL, 'diterima', 'Pembayaran langsung di loket Tata Usaha sekolah.', 2, NOW());
            ");
        }

        // Auto-seed Catatan BK (counseling_records)
        $count_cr = (int) $pdo->query("SELECT COUNT(*) FROM `counseling_records`")->fetchColumn();
        if ($count_cr === 0) {
            $pdo->exec("
                INSERT INTO `counseling_records` (`student_id`, `type`, `category`, `title`, `points`, `action_taken`, `notes`, `recorded_by`, `date`) VALUES
                (5, 'prestasi', 'Akademik', 'Juara 2 Olimpiade Sains Matematika Tingkat Kota', 25, 'Diberikan piagam penghargaan dan apresiasi beasiswa prestasi.', 'Siswa berprestasi mengharumkan nama sekolah di ajang OSN.', 3, CURRENT_DATE()),
                (5, 'pelanggaran', 'Kedisiplinan', 'Terlambat Masuk Jam Pertama Sekolah (15 Menit)', 5, 'Peringatan lisan dan pembinaan tata tertib oleh guru piket.', 'Terlambat karena kendala transportasi di perjalanan.', 3, DATE_SUB(CURRENT_DATE(), INTERVAL 3 DAY));
            ");
        }

        // Auto-seed Pesan & Konsultasi (messages)
        $count_msg = (int) $pdo->query("SELECT COUNT(*) FROM `messages`")->fetchColumn();
        if ($count_msg === 0) {
            $pdo->exec("
                INSERT INTO `messages` (`sender_id`, `receiver_id`, `message`, `is_read`, `created_at`) VALUES
                (4, 3, 'Selamat pagi Ibu Dewi, mohon konfirmasi untuk materi praktikum biologi anak kami Ahmad Fauzi pekan depan.', 1, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
                (3, 4, 'Selamat pagi Pak Hendra. Praktikum akan fokus pada materi sistem ekskresi, seluruh alat lab sudah dipersiapkan sekolah.', 1, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
                (5, 3, 'Selamat siang Ibu Dewi, apakah tugas matematika halaman 45 nomor 10 dikumpulkan dalam bentuk softcopy atau buku tulis?', 0, DATE_SUB(NOW(), INTERVAL 20 MINUTE));
            ");
        }

        // Auto-seed PPDB Registrations
        $count_ppdb = (int) $pdo->query("SELECT COUNT(*) FROM `ppdb_registrations`")->fetchColumn();
        if ($count_ppdb === 0) {
            $pdo->exec("
                INSERT INTO `ppdb_registrations` 
                (`registration_no`, `full_name`, `nisn`, `nik`, `gender`, `birth_place`, `birth_date`, `religion`, `phone`, `email`, `address`, `previous_school`, `chosen_major`, `parent_name`, `parent_phone`, `parent_job`, `status`, `selection_score`, `notes`, `created_at`) 
                VALUES
                ('PPDB-2026-0001', 'Rian Pratama', '0081234567', '3201011203080001', 'L', 'Jakarta', '2009-04-12', 'Islam', '081234567890', 'rian.pratama@gmail.com', 'Jl. Kenanga No. 15, Jakarta Selatan', 'SMP Negeri 1 Jakarta', 'MIPA (Matematika & IPA)', 'Budi Santoso', '081298765432', 'Wiraswasta', 'lulus_seleksi', 88.50, 'Nilai rapor semester 1-5 sangat memuaskan.', DATE_SUB(NOW(), INTERVAL 5 DAY)),
                ('PPDB-2026-0002', 'Siti Nur Aisyah', '0087654321', '3201015607080002', 'P', 'Bandung', '2009-07-25', 'Islam', '081345678901', 'siti.aisyah@gmail.com', 'Jl. Melati No. 8, Bandung', 'SMP IT Al-Falah', 'IPS (Ilmu Pengetahuan Sosial)', 'Ahmad Hidayat', '081387654321', 'PNS', 'menunggu_verifikasi', NULL, 'Menunggu verifikasi kartu keluarga.', DATE_SUB(NOW(), INTERVAL 2 DAY)),
                ('PPDB-2026-0003', 'Bayu Anggara', '0089988776', '3201012309080003', 'L', 'Bogor', '2009-09-18', 'Islam', '081456789012', 'bayu.anggara@gmail.com', 'Jl. Flamboyan No. 22, Bogor', 'SMP Budi Mulia', 'Bahasa & Budaya', 'Hendra Gunawan', '081476543210', 'Karyawan Swasta', 'diverifikasi', 82.00, 'Berkas lengkap, dijadwalkan tes wawancara.', DATE_SUB(NOW(), INTERVAL 1 DAY));
            ");
        }

        // Auto-seed Buku Perpustakaan
        $count_books = (int) $pdo->query("SELECT COUNT(*) FROM `library_books`")->fetchColumn();
        if ($count_books === 0) {
            $pdo->exec("
                INSERT INTO `library_books` 
                (`code`, `isbn`, `title`, `author`, `publisher`, `year`, `category`, `stock_total`, `stock_available`, `shelf_location`, `description`) 
                VALUES
                ('BK-001', '978-602-01-2345-1', 'Fisika Dasar untuk SMA/MA Kelas X', 'Prof. Bambang Subagyo', 'Erlangga', 2023, 'Sains & Teknologi', 10, 9, 'Rak Sains A-1', 'Buku teks fisika kurikulum terbaru mencakup kinematika gerak, dinamika, dan energi kinetik.'),
                ('BK-002', '978-979-3062-79-2', 'Laskar Pelangi', 'Andrea Hirata', 'Bentang Pustaka', 2008, 'Novel & Sastra', 5, 5, 'Rak Sastra B-2', 'Kisah inspiratif tentang 10 anak di Pulau Belitung yang berjuang menuntut ilmu di tengah keterbatasan.'),
                ('BK-003', '978-979-22-3841-5', 'Kamus Lengkap Inggris - Indonesia', 'John M. Echols & Hassan Shadily', 'Gramedia Pustaka Utama', 2021, 'Referensi & Bahasa', 4, 3, 'Rak Referensi R-1', 'Kamus standar acuan utama untuk pembelajaran bahasa Inggris di sekolah dan perguruan tinggi.'),
                ('BK-004', '978-979-407-123-4', 'Sejarah Perjuangan Kemerdekaan Indonesia', 'Dr. Nugroho Notosusanto', 'Balai Pustaka', 2019, 'IPS & Sejarah', 6, 6, 'Rak Sejarah C-1', 'Rangkuman kronologis diplomasi dan revolusi fisik kemerdekaan Republik Indonesia 1945-1949.'),
                ('BK-005', '978-623-00-1122-3', 'Pemrograman Web Modern dengan PHP & MySQL', 'Dr. Budi Raharjo', 'Informatika Bandung', 2024, 'Teknologi & Komputer', 8, 8, 'Rak IT D-1', 'Panduan aplikatif pembuatan aplikasi web interaktif enterprise menggunakan PHP 8+ dan PDO.');
            ");
        }

        // Auto-seed Peminjaman Buku Perpustakaan
        $count_loans = (int) $pdo->query("SELECT COUNT(*) FROM `library_loans`")->fetchColumn();
        if ($count_loans === 0) {
            $pdo->exec("
                INSERT INTO `library_loans` 
                (`book_id`, `user_id`, `borrow_date`, `due_date`, `return_date`, `fine_amount`, `status`, `notes`) 
                VALUES
                (1, 5, DATE_SUB(CURRENT_DATE(), INTERVAL 5 DAY), DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY), NULL, 0.00, 'dipinjam', 'Peminjaman untuk tugas kelompok Fisika.'),
                (3, 5, DATE_SUB(CURRENT_DATE(), INTERVAL 20 DAY), DATE_SUB(CURRENT_DATE(), INTERVAL 13 DAY), DATE_SUB(CURRENT_DATE(), INTERVAL 12 DAY), 0.00, 'kembali', 'Dikembalikan tepat waktu dalam kondisi baik.');
            ");
        }

        // Set sample ebook URL for book 5 if empty
        $pdo->exec("UPDATE `library_books` SET `ebook_file` = 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf' WHERE `id` = 5 AND (`ebook_file` IS NULL OR `ebook_file` = '')");

        // Auto-seed Ulasan & Rating Buku
        $count_reviews = (int) $pdo->query("SELECT COUNT(*) FROM `library_reviews`")->fetchColumn();
        if ($count_reviews === 0) {
            $pdo->exec("
                INSERT INTO `library_reviews` (`book_id`, `user_id`, `rating`, `review`, `created_at`) VALUES
                (1, 5, 5, 'Buku fisika ini sangat mudah dipahami, rumus dijabarkan langkah demi langkah. Sangat membantu persiapan ujian semester.', DATE_SUB(NOW(), INTERVAL 3 DAY)),
                (2, 5, 5, 'Novel karya Andrea Hirata ini luar biasa inspiratif. Wajib dibaca semua siswa untuk memompa semangat belajar!', DATE_SUB(NOW(), INTERVAL 2 DAY)),
                (5, 4, 5, 'Penjelasan konsep PHP 8 dan PDO database sangat terstruktur dan aplikatif untuk tugas praktikum.', DATE_SUB(NOW(), INTERVAL 1 DAY)),
                (3, 5, 4, 'Kosakatanya sangat lengkap dan terjemahannya akurat, cocok untuk referensi tugas bahasa Inggris.', DATE_SUB(NOW(), INTERVAL 4 DAY));
            ");
        }

        // Auto-seed Reservasi Buku
        $count_reservations = (int) $pdo->query("SELECT COUNT(*) FROM `library_reservations`")->fetchColumn();
        if ($count_reservations === 0) {
            $pdo->exec("
                INSERT INTO `library_reservations` (`book_id`, `user_id`, `reservation_date`, `expiry_date`, `status`, `notes`) VALUES
                (2, 5, CURRENT_TIMESTAMP(), DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY), 'menunggu', 'Reservasi untuk membaca lanjutan bab 15.'),
                (5, 5, DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL 1 DAY), DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY), 'disiapkan', 'Siap diambil di rak reservasi sirkulasi.');
            ");
        }

        // Auto-seed Buku Tamu Perpustakaan
        $count_visitors = (int) $pdo->query("SELECT COUNT(*) FROM `library_visitors`")->fetchColumn();
        if ($count_visitors === 0) {
            $pdo->exec("
                INSERT INTO `library_visitors` 
                (`user_id`, `name`, `role`, `identifier`, `class_name`, `gender`, `purpose`, `visit_date`, `visit_time`, `notes`) 
                VALUES
                (5, 'Ahmad Fauzi', 'siswa', '0081234567', 'X MIPA 1', 'L', 'Membaca Buku / Majalah', CURRENT_DATE(), '08:30:00', 'Membaca buku referensi fisika.'),
                (NULL, 'Rian Pratama', 'siswa', '0081234567', 'X MIPA 1', 'L', 'Mengerjakan Tugas / Belajar Mandiri', CURRENT_DATE(), '09:15:00', 'Mengerjakan tugas matematika.'),
                (3, 'Siti Rahmawati, S.Pd', 'guru', '19850315 201001 2 018', 'Dewan Guru', 'P', 'Peminjaman / Pengembalian Buku', CURRENT_DATE(), '10:00:00', 'Meminjam buku materi ajar biologi.'),
                (NULL, 'Budi Santoso', 'umum', 'Wali Murid', 'Umum', 'L', 'Konsultasi / Kunjungan Perpustakaan', CURRENT_DATE(), '10:45:00', 'Melihat fasilitas koleksi buku sekolah.');
            ");
        }

        // Auto-seed Pengumuman Sekolah jika kosong
        $count_announcements = (int) $pdo->query("SELECT COUNT(*) FROM `announcements`")->fetchColumn();
        if ($count_announcements === 0) {
            $first_user_id = (int)$pdo->query("SELECT id FROM `users` ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            $pdo->exec("
                INSERT INTO `announcements` (`title`, `content`, `target_role`, `category`, `is_pinned`, `status`, `author_id`, `created_at`) VALUES
                (
                    'Jadwal Pelaksanaan Penilaian Akhir Semester (PAS) TA 2026/2027',
                    'Diberitahukan kepada seluruh siswa, orang tua, dan bapak/ibu guru bahwa Penilaian Akhir Semester (PAS) Semester Ganjil akan dilaksanakan mulai tanggal 15 s.d 22 Desember 2026.\n\nKetentuan pelaksanaan ujian:\n1. Siswa wajib membawa kartu peserta ujian digital yang dapat dicetak melalui dashboard siswa.\n2. Siswa hadir di ruangan 15 menit sebelum bel masuk berbunyi.\n3. Dilarang membawa catatan, buku, atau perangkat komunikasi tidak resmi ke dalam ruang ujian.\n\nMari persiapkan diri dengan tekun dan junjung tinggi kejujuran akademik!',
                    'semua', 'penting', 1, 'published', $first_user_id, DATE_SUB(NOW(), INTERVAL 1 DAY)
                ),
                (
                    'Penerimaan Peserta Didik Baru (PPDB) TA 2026/2027 Resmi Dibuka',
                    'Pendaftaran Peserta Didik Baru (PPDB) Tahun Ajaran 2026/2027 telah dibuka secara daring (online) untuk Jalur Zonasi, Prestasi Akademik/Non-Akademik, dan Afirmasi.\n\nCalon siswa dapat langsung mendaftar melalui menu PPDB Online di halaman utama website ini, melengkapi data diri, dan mengunggah berkas persyaratan tanpa dipungut biaya pendaftaran.\n\nInformasi lebih lanjut dapat menghubungi narahubung panitia PPDB di jam kerja.',
                    'semua', 'kegiatan', 1, 'published', $first_user_id, DATE_SUB(NOW(), INTERVAL 3 DAY)
                ),
                (
                    'Undangan Pertemuan Wali Murid & Konsultasi Perkembangan Belajar',
                    'Kepada Yth. Bapak/Ibu Orang Tua / Wali Murid,\n\nSekolah mengundang kehadiran Bapak/Ibu pada acara Pertemuan Rutin Triwulan & Konsultasi Perkembangan Belajar Siswa yang akan diselenggarakan pada:\n\nHari/Tanggal : Sabtu, 28 November 2026\nWaktu : Pukul 08.30 - 11.30 WIB\nTempat : Aula Graha Utama Sekolah\n\nKehadiran Bapak/Ibu sangat diharapkan demi terjalinnya sinergi yang baik antara pihak sekolah dan orang tua.',
                    'orang_tua', 'umum', 0, 'published', $first_user_id, DATE_SUB(NOW(), INTERVAL 5 DAY)
                ),
                (
                    'Gladi Bersih Asesmen Nasional Berbasis Komputer (ANBK) di Lab Komputer',
                    'Diberitahukan kepada seluruh dewan guru dan tim proktor teknologi bahwa gladi bersih ANBK akan diselenggarakan pada hari Kamis mendatang di Lab Komputer 1 & 2.\n\nDimohon kepada proktor untuk mengecek kesiapan server lokal, kestabilan jaringan internet, dan daya cadangan (UPS) demi kelancaran simulasi asesmen.',
                    'guru', 'akademik', 0, 'published', $first_user_id, DATE_SUB(NOW(), INTERVAL 7 DAY)
                ),
                (
                    'Sosialisasi Layanan Pustaka Digital & Akses E-Book Sekolah',
                    'Kini seluruh siswa dan guru dapat mengakses koleksi e-book, modul ajar, dan melakukan reservasi buku fisik mandiri secara daring melalui menu Katalog Perpustakaan.\n\nManfaatkan fasilitas literasi digital sekolah untuk menunjang kegiatan pembelajaran dan riset karya ilmiah Anda!',
                    'siswa', 'kegiatan', 0, 'published', $first_user_id, DATE_SUB(NOW(), INTERVAL 2 DAY)
                );
            ");
        }

        // Auto-seed Kalender Agenda Sekolah jika kosong
        $count_calendar = (int) $pdo->query("SELECT COUNT(*) FROM `calendar_events`")->fetchColumn();
        if ($count_calendar === 0) {
            $first_user_id = (int)$pdo->query("SELECT id FROM `users` ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            $pdo->exec("
                INSERT INTO `calendar_events` (`title`, `description`, `event_date`, `end_date`, `category`, `color`, `created_by`) VALUES
                ('Penilaian Akhir Semester (PAS)', 'Ujian akhir semester ganjil seluruh jenjang kelas', DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY), DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY), 'akademik', 'blue', $first_user_id),
                ('Pameran Literasi & Bulan Bahasa', 'Gelar karya sastra dan kreasi siswa di lapangan utama', DATE_ADD(CURRENT_DATE(), INTERVAL 4 DAY), NULL, 'kegiatan', 'emerald', $first_user_id),
                ('Pertemuan Paguyuban Orang Tua Murid', 'Sosialisasi program sekolah dan laporan akademik', DATE_ADD(CURRENT_DATE(), INTERVAL 12 DAY), NULL, 'akademik', 'amber', $first_user_id),
                ('Batas Verifikasi Berkas PPDB Tahap 1', 'Verifikasi akhir dokumen calon siswa baru', DATE_ADD(CURRENT_DATE(), INTERVAL 18 DAY), NULL, 'kegiatan', 'purple', $first_user_id);
            ");
        }
        // Auto-seed Buku Agenda Surat Masuk & Keluar (mail_archives)
        $count_mail = (int) $pdo->query("SELECT COUNT(*) FROM `mail_archives`")->fetchColumn();
        if ($count_mail === 0) {
            $first_user_id = (int)$pdo->query("SELECT id FROM `users` ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            $pdo->exec("
                INSERT INTO `mail_archives` 
                (`mail_type`, `agenda_no`, `reference_no`, `sender_or_recipient`, `mail_date`, `received_or_sent_date`, `subject`, `description`, `disposition_instruction`, `disposition_target`, `recorded_by`)
                VALUES
                ('masuk', 'AG-IN/2026/09/001', '420/1254/Disdik/IX/2026', 'Dinas Pendidikan Provinsi DKI Jakarta', DATE_SUB(CURRENT_DATE(), INTERVAL 4 DAY), DATE_SUB(CURRENT_DATE(), INTERVAL 3 DAY), 'Sosialisasi Program Indonesia Pintar (PIP) Fase 2', 'Undangan menghadiri sosialisasi pencairan dana bantuan siswa PIP di Aula Dinas Pendidikan.', 'Hadiri dan siapkan data nominasi siswa penerima PIP.', 'Staf Kesiswaan & Kurikulum', $first_user_id),
                ('masuk', 'AG-IN/2026/09/002', '440/Pusk-KB/089/2026', 'Puskesmas Kecamatan Kebayoran Baru', DATE_SUB(CURRENT_DATE(), INTERVAL 2 DAY), DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY), 'Jadwal Skrining Kesehatan Berkala & Penyuluhan Remaja', 'Pemberitahuan jadwal tim medis puskesmas untuk pemeriksaan kesehatan berkala peserta didik kelas X.', 'Koordinasikan jadwal dengan wali kelas X dan ruang UKS.', 'Pembina UKS & Tata Usaha', $first_user_id),
                ('keluar', 'AG-OUT/2026/09/001', '421.3/089/SMA-BBN/IX/2026', 'Kepala Balai Penjaminan Mutu Pendidikan (BPMP)', CURRENT_DATE(), CURRENT_DATE(), 'Pengantar Laporan Pemutakhiran Data Dapodik Semester Ganjil', 'Penyampaian berkas rekap data pokok kesiswaan dan rombongan belajar tahun ajaran 2026/2027.', NULL, NULL, $first_user_id);
            ");
        }

        // Auto-seed Sarpras & Inventaris (inventory_items)
        $count_inventory = (int) $pdo->query("SELECT COUNT(*) FROM `inventory_items`")->fetchColumn();
        if ($count_inventory === 0) {
            $first_user_id = (int)$pdo->query("SELECT id FROM `users` ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            $pdo->exec("
                INSERT INTO `inventory_items` 
                (`item_code`, `item_name`, `category`, `room_location`, `condition_status`, `quantity`, `unit`, `funding_source`, `purchase_year`, `purchase_cost`, `notes`, `recorded_by`)
                VALUES
                ('INV-LAB-001', 'PC All-in-One Core i7 16GB RAM 512GB SSD', 'Elektronik', 'Lab Komputer 1', 'baik', 30, 'Unit', 'BOS', 2024, 12500000.00, 'Perangkat utama praktikum informatika dan CBT.', $first_user_id),
                ('INV-SAR-002', 'Proyektor Epson EB-X500 3600 Lumens', 'Elektronik', 'Ruang Tata Usaha', 'baik', 4, 'Unit', 'Komite', 2023, 6200000.00, 'Tersedia untuk dipinjam dewan guru saat KBM.', $first_user_id),
                ('INV-SAR-003', 'Sound System Portabel Wireless 12 Inch + 2 Mic', 'Elektronik', 'Ruang Tata Usaha', 'baik', 2, 'Unit', 'BOS', 2024, 3800000.00, 'Digunakan untuk upacara, senam, dan pertemuan wali murid.', $first_user_id),
                ('INV-KLS-004', 'Set Meja & Kursi Siswa Standar Kayu Jati', 'Mebel', 'Ruang Kelas X MIPA 1', 'baik', 36, 'Set', 'BOS', 2023, 650000.00, 'Kondisi kokoh dan terawat rapi.', $first_user_id),
                ('INV-LAB-005', 'Mikroskop Binokuler Olympus CX23', 'Alat Praktik', 'Lab Biologi & Kimia', 'baik', 10, 'Unit', 'DAK Pendidikan', 2023, 8500000.00, 'Alat praktikum pengamatan preparat sel siswa.', $first_user_id);
            ");
        }

        // Auto-seed Peminjaman Sarpras (inventory_loans)
        $count_inv_loans = (int) $pdo->query("SELECT COUNT(*) FROM `inventory_loans`")->fetchColumn();
        if ($count_inv_loans === 0) {
            $first_user_id = (int)$pdo->query("SELECT id FROM `users` ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            $pdo->exec("
                INSERT INTO `inventory_loans` 
                (`item_id`, `borrower_name`, `borrower_role`, `borrower_phone`, `borrow_date`, `expected_return_date`, `actual_return_date`, `status`, `purpose`, `recorded_by`)
                VALUES
                (2, 'Siti Rahmawati, S.Pd', 'guru', '081234567890', CURRENT_DATE(), DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY), NULL, 'dipinjam', 'Media presentasi pembelajaran Biologi Kelas XI', $first_user_id);
            ");
        }

        // Auto-seed Pengeluaran Kas Operasional / BKU (financial_expenses)
        $count_expenses = (int) $pdo->query("SELECT COUNT(*) FROM `financial_expenses`")->fetchColumn();
        if ($count_expenses === 0) {
            $first_user_id = (int)$pdo->query("SELECT id FROM `users` ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            $pdo->exec("
                INSERT INTO `financial_expenses` 
                (`expense_no`, `category`, `title`, `expense_date`, `amount`, `recipient`, `notes`, `recorded_by`)
                VALUES
                ('EXP-2026/09/001', 'ATK & Operasional Kantor', 'Belanja Kertas HVS F4/A4, Tinta Printer & Map Arsip TU', DATE_SUB(CURRENT_DATE(), INTERVAL 6 DAY), 680000.00, 'Toko Alat Tulis Grama Mandiri', 'Kebutuhan administrasi arsip dan formulir penilaian.', $first_user_id),
                ('EXP-2026/09/002', 'Listrik & Internet', 'Pembayaran Tagihan Listrik PLN Pasca Bayar Gedung Sekolah', DATE_SUB(CURRENT_DATE(), INTERVAL 4 DAY), 1950000.00, 'PT PLN (Persero)', 'Tagihan periode pemakaian bulan berjalan.', $first_user_id),
                ('EXP-2026/09/003', 'Konsumsi & Rapat', 'Konsumsi Rapat Pleno Dewan Guru & Staf Tata Usaha', DATE_SUB(CURRENT_DATE(), INTERVAL 2 DAY), 450000.00, 'Katering Berkah Bersama', 'Snack dan makan siang 30 guru & staf.', $first_user_id);
            ");
        }
    } catch (PDOException $e_seed) {
        // Abaikan jika error insert sampel
    }

    $_SESSION['db_migrated_v11'] = true;
    } // end migration check

} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());

    // Tampilkan halaman error visual premium dari folder error/ jika tersedia
    $errorRenderer = __DIR__ . "/../error/render.php";
    if (file_exists($errorRenderer)) {
        require_once $errorRenderer;

        $errorMsg = $e->getMessage();
        $isConnectionRefused = (strpos($errorMsg, '2002') !== false || stripos($errorMsg, 'Connection refused') !== false || stripos($errorMsg, 'actively refused') !== false);
        $isDbNotFound        = (strpos($errorMsg, '1049') !== false || stripos($errorMsg, 'Unknown database') !== false);
        $isAccessDenied      = (strpos($errorMsg, '1045') !== false || stripos($errorMsg, 'Access denied') !== false);

        if ($isConnectionRefused) {
            $customDesc = "Server MySQL belum aktif pada host '$host'. Pastikan modul MySQL pada XAMPP / Laragon Control Panel sudah dalam status 'Start' (Running).";
        } elseif ($isDbNotFound) {
            $customDesc = "Database '$dbname' belum ditemukan di server MySQL Anda. Silakan buka phpMyAdmin dan buat basis data bernama '$dbname', atau impor file 'sql/database.sql'.";
        } elseif ($isAccessDenied) {
            $customDesc = "Kredensial login MySQL (username: '$username') ditolak oleh server basis data. Silakan periksa kembali konfigurasi di file 'config/database.php'.";
        } else {
            $customDesc = "Sistem Manajemen-PHP mengalami kendala saat berkomunikasi dengan server basis data: " . $errorMsg;
        }

        renderErrorPage(
            500,
            "Koneksi Database Terputus",
            $customDesc
        );
        exit;
    }

    http_response_code(500);
    die("Terjadi kesalahan koneksi database. Silakan hubungi administrator.");
}

/**
 * Daftar role yang tersedia dalam sistem
 */
const ROLES = [
    'administrator' => 'Administrator',
    'staf'          => 'Staf',
    'guru'          => 'Guru',
    'orang_tua'     => 'Orang Tua',
    'siswa'         => 'Siswa'
];

/**
 * Dapatkan label nama role yang ramah pengguna
 */
function getRoleLabel(string $role): string {
    return ROLES[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

/**
 * Dapatkan style badge Tailwind berdasarkan role
 */
function getRoleBadge(string $role): string {
    switch ($role) {
        case 'administrator':
            return 'border-rose-500/30 bg-rose-500/10 text-rose-300';
        case 'staf':
            return 'border-amber-500/30 bg-amber-500/10 text-amber-300';
        case 'guru':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300';
        case 'orang_tua':
            return 'border-purple-500/30 bg-purple-500/10 text-purple-300';
        case 'siswa':
        default:
            return 'border-blue-500/30 bg-blue-500/10 text-blue-300';
    }
}

/**
 * Dapatkan icon Font Awesome berdasarkan role
 */
function getRoleIcon(string $role): string {
    switch ($role) {
        case 'administrator':
            return 'fa-solid fa-user-shield';
        case 'staf':
            return 'fa-solid fa-id-badge';
        case 'guru':
            return 'fa-solid fa-chalkboard-user';
        case 'orang_tua':
            return 'fa-solid fa-users';
        case 'siswa':
        default:
            return 'fa-solid fa-user-graduate';
    }
}

/**
 * Dapatkan badge style untuk status surat layanan
 */
function getStatusBadge(string $status): string {
    switch ($status) {
        case 'selesai':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300';
        case 'diproses':
            return 'border-blue-500/30 bg-blue-500/10 text-blue-300';
        case 'ditolak':
            return 'border-rose-500/30 bg-rose-500/10 text-rose-300';
        case 'menunggu':
        default:
            return 'border-amber-500/30 bg-amber-500/10 text-amber-300';
    }
}

/**
 * Daftar kategori asesmen: 3 Ujian & 3 Latihan
 */
const EXAM_CATEGORIES = [
    'uts'              => ['label' => 'Ujian Tengah Semester (UTS)', 'type' => 'ujian', 'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300', 'icon' => '<i class="fa-solid fa-file-pen"></i>'],
    'ukk'              => ['label' => 'Ujian Kenaikan Kelas (UKK)',  'type' => 'ujian', 'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',     'icon' => '<i class="fa-solid fa-graduation-cap"></i>'],
    'ujian_harian'     => ['label' => 'Ujian Harian (Fleksibel)',    'type' => 'ujian', 'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',   'icon' => '<i class="fa-solid fa-bolt"></i>'],
    'latihan_harian'   => ['label' => 'Latihan Harian',              'type' => 'latihan', 'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',     'icon' => '<i class="fa-solid fa-book-open"></i>'],
    'latihan_mingguan' => ['label' => 'Latihan Mingguan',            'type' => 'latihan', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '<i class="fa-solid fa-calendar-check"></i>'],
    'latihan_bulanan'  => ['label' => 'Latihan Bulanan',             'type' => 'latihan', 'badge' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',       'icon' => '<i class="fa-solid fa-bullseye"></i>'],
];

function getExamCategoryLabel(string $category): string {
    return EXAM_CATEGORIES[$category]['label'] ?? ucfirst(str_replace('_', ' ', $category));
}

function getExamCategoryBadge(string $category): string {
    return EXAM_CATEGORIES[$category]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getExamCategoryIcon(string $category): string {
    return EXAM_CATEGORIES[$category]['icon'] ?? '<i class="fa-solid fa-file-pen"></i>';
}

/**
 * Helper Presensi Siswa
 */
const ATTENDANCE_STATUSES = [
    'hadir' => ['label' => 'Hadir', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '<i class="fa-solid fa-circle-check"></i>'],
    'sakit' => ['label' => 'Sakit', 'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',     'icon' => '<i class="fa-solid fa-notes-medical"></i>'],
    'izin'  => ['label' => 'Izin',  'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',       'icon' => '<i class="fa-solid fa-envelope"></i>'],
    'alpa'  => ['label' => 'Alpa',  'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',       'icon' => '<i class="fa-solid fa-circle-xmark"></i>'],
];

function getAttendanceLabel(string $status): string {
    return ATTENDANCE_STATUSES[$status]['label'] ?? ucfirst($status);
}

function getAttendanceBadge(string $status): string {
    return ATTENDANCE_STATUSES[$status]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getAttendanceIcon(string $status): string {
    return ATTENDANCE_STATUSES[$status]['icon'] ?? '<i class="fa-solid fa-clipboard-user"></i>';
}

/**
 * Daftar kategori pengumuman
 */
const ANNOUNCEMENT_CATEGORIES = [
    'umum'     => ['label' => 'Umum',     'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',       'icon' => '<i class="fa-solid fa-bullhorn"></i>'],
    'akademik' => ['label' => 'Akademik', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '<i class="fa-solid fa-graduation-cap"></i>'],
    'kegiatan' => ['label' => 'Kegiatan', 'badge' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',       'icon' => '<i class="fa-solid fa-thumbtack"></i>'],
    'penting'  => ['label' => 'Penting',  'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',     'icon' => '<i class="fa-solid fa-bolt"></i>'],
    'darurat'  => ['label' => 'Darurat',  'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',       'icon' => '<i class="fa-solid fa-bell"></i>'],
];

function getAnnouncementCategoryLabel(string $category): string {
    return ANNOUNCEMENT_CATEGORIES[$category]['label'] ?? ucfirst($category);
}

function getAnnouncementCategoryBadge(string $category): string {
    return ANNOUNCEMENT_CATEGORIES[$category]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getAnnouncementCategoryIcon(string $category): string {
    return ANNOUNCEMENT_CATEGORIES[$category]['icon'] ?? '<i class="fa-solid fa-bullhorn"></i>';
}

/**
 * Sanitasi konten HTML pengumuman agar aman dari XSS tetapi tetap menjaga formatting rich text
 */
function sanitizeAnnouncementHtml(?string $content): string {
    if (empty($content)) return '';
    // Jika konten adalah plain text tanpa tag html, konversi newline ke <br>
    if ($content === strip_tags($content)) {
        return nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
    }
    // Izinkan tag styling standar
    $allowed_tags = '<p><br><strong><b><em><i><u><s><ul><ol><li><a><h1><h2><h3><h4><h5><h6><blockquote><code><pre><hr><span><div><table><thead><tbody><tr><th><td>';
    $clean = strip_tags($content, $allowed_tags);
    // Hapus event handlers (onload, onerror, onclick, dll.)
    $clean = preg_replace('/\s*on\w+\s*=\s*(["\']).*?\1/i', '', $clean);
    $clean = preg_replace('/\s*on\w+\s*=\s*[^>\s]+/i', '', $clean);
    // Hapus skema javascript: pada atribut href
    $clean = preg_replace('/href\s*=\s*(["\'])\s*javascript:[^"\']*?\1/i', 'href="#"', $clean);
    return $clean;
}

/**
 * Cek apakah user sudah membaca pengumuman tertentu
 */
function isAnnouncementRead(PDO $pdo, int $announcementId, int $userId): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM announcement_reads WHERE announcement_id = ? AND user_id = ?");
        $stmt->execute([$announcementId, $userId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Hitung jumlah user yang sudah membaca pengumuman
 */
function getAnnouncementReadCount(PDO $pdo, int $announcementId): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM announcement_reads WHERE announcement_id = ?");
        $stmt->execute([$announcementId]);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Ambil daftar pengguna yang sudah membaca pengumuman
 */
function getAnnouncementReaders(PDO $pdo, int $announcementId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT ar.read_at, u.id as user_id, u.name, u.role, c.name as class_name 
            FROM announcement_reads ar
            JOIN users u ON ar.user_id = u.id
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE ar.announcement_id = ?
            ORDER BY ar.read_at DESC
        ");
        $stmt->execute([$announcementId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}


/**
 * Helper Kalender Akademik & Agenda
 */
const CALENDAR_CATEGORIES = [
    'akademik' => ['label' => 'Akademik', 'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',       'color' => '#3b82f6', 'icon' => '<i class="fa-solid fa-graduation-cap"></i>'],
    'libur'    => ['label' => 'Libur',    'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',       'color' => '#ef4444', 'icon' => '<i class="fa-solid fa-umbrella-beach"></i>'],
    'kegiatan' => ['label' => 'Kegiatan', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'color' => '#10b981', 'icon' => '<i class="fa-solid fa-thumbtack"></i>'],
    'ujian'    => ['label' => 'Ujian',    'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300',   'color' => '#a855f7', 'icon' => '<i class="fa-solid fa-file-pen"></i>'],
];

function getCalendarCategoryLabel(string $category): string {
    return CALENDAR_CATEGORIES[$category]['label'] ?? ucfirst($category);
}

function getCalendarCategoryBadge(string $category): string {
    return CALENDAR_CATEGORIES[$category]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

/**
 * Helper verifikasi login
 */
function requireLogin(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // BUG-20 fix: Set secure cookie parameters
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        $scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
        if (preg_match('#/dashboard/[^/]+/[^/]+#i', $scriptPath)) {
            $loginUrl = '../../auth/login.php?auth=required';
        } elseif (preg_match('#/dashboard/#i', $scriptPath)) {
            $loginUrl = '../auth/login.php?auth=required';
        } else {
            $loginUrl = 'auth/login.php?auth=required';
        }
        header("Location: " . $loginUrl);
        exit;
    }
}

/**
 * Helper verifikasi hak akses role tertentu
 */
function requireRole(array $allowedRoles): void {
    requireLogin();
    $currentRole = $_SESSION['user_role'] ?? 'siswa';
    if (!in_array($currentRole, $allowedRoles, true)) {
        $parentFolder = basename(dirname($_SERVER['PHP_SELF']));
        $dashIndex = ($parentFolder === 'dashboard') ? 'index.php?error=unauthorized' : '../index.php?error=unauthorized';
        header("Location: " . $dashIndex);
        exit;
    }
}

/**
 * Helper pengalihan ke halaman error kustom
 */
function abortError(int $code = 404): void {
    $errorFile = __DIR__ . "/../error/{$code}.php";
    if (file_exists($errorFile)) {
        require_once __DIR__ . "/../error/render.php";
        renderErrorPage($code);
        exit;
    }
    http_response_code($code);
    exit;
}

/**
 * Helper Audit Trail Log
 */
function logActivity(PDO $pdo, string $action, string $details = ''): void {
    $uid = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$uid, $action, $details, $ip]);
    } catch (Exception $e) {}
}

/**
 * CSRF Protection Helpers
 */
function generateCsrfToken(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken()) . '">';
}

function validateCsrfToken(?string $token = null): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $token ?? $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Helper Konfigurasi Sekolah
 */
function getSchoolSettings(PDO $pdo): array {
    $defaults = [
        'school_name'         => 'SMA Bina Bangsa Nusantara',
        'school_address'      => 'Jl. Pendidikan Nasional No. 45, Kebayoran Baru, Jakarta',
        'school_phone'        => '(021) 789-0123',
        'school_email'        => 'info@binabangsa.sch.id',
        'school_website'      => 'https://binabangsa.sch.id',
        'headmaster_name'     => 'Dr. H. Bambang Sudirman, M.Pd',
        'headmaster_nip'      => '19750812 199903 1 002',
        'academic_year'       => '2026/2027 Ganjil',
        'school_logo'         => '',
        'ppdb_status'         => 'buka',
        'ppdb_wave_name'      => 'Gelombang 1',
        'ppdb_quota'          => '150',
        'ppdb_start_date'     => '',
        'ppdb_end_date'       => '',
        'ppdb_closed_message' => 'Pendaftaran Peserta Didik Baru (PPDB) saat ini sedang ditutup atau batas kuota telah terpenuhi.'
    ];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM school_settings");
        while ($row = $stmt->fetch()) {
            $defaults[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {}
    return $defaults;
}

function getSetting(PDO $pdo, string $key, string $default = ''): string {
    $settings = getSchoolSettings($pdo);
    return (string)($settings[$key] ?? $default);
}

function updateSchoolSetting(PDO $pdo, string $key, string $value): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO school_settings (setting_key, setting_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$key, $value]);
    } catch (Exception $e) {}
}

/**
 * Format Angka Menjadi Format Rupiah
 */
function formatRupiah($amount): string {
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}

/**
 * Status Tagihan Keuangan Siswa
 */
const BILL_STATUSES = [
    'lunas'               => ['label' => 'Lunas',               'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'],
    'menunggu_verifikasi' => ['label' => 'Menunggu Verifikasi', 'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300'],
    'belum_lunas'         => ['label' => 'Belum Lunas',         'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300'],
];

function getBillStatusLabel(string $status): string {
    return BILL_STATUSES[$status]['label'] ?? ucfirst(str_replace('_', ' ', $status));
}

function getBillStatusBadge(string $status): string {
    return BILL_STATUSES[$status]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

/**
 * Helper Catatan Bimbingan Konseling (BK)
 */
function getCounselingBadge(string $type): string {
    return $type === 'prestasi' 
        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' 
        : 'border-rose-500/30 bg-rose-500/10 text-rose-300';
}

function getCounselingLabel(string $type): string {
    return $type === 'prestasi' ? 'Prestasi Siswa' : 'Pelanggaran Disiplin';
}

/**
 * Hitung Jumlah Pesan Masuk Belum Dibaca
 */
function getUnreadMessagesCount(PDO $pdo, int $userId): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Status PPDB (Penerimaan Peserta Didik Baru)
 */
const PPDB_STATUSES = [
    'menunggu_verifikasi' => ['label' => 'Menunggu Verifikasi', 'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300'],
    'diverifikasi'        => ['label' => 'Terverifikasi Berkas', 'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300'],
    'lulus_seleksi'       => ['label' => 'Lulus Seleksi',       'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'],
    'tidak_lulus'         => ['label' => 'Tidak Lulus',         'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300'],
    'diterima'            => ['label' => 'Diterima Resmi Siswa', 'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300'],
];

function getPpdbStatusLabel(string $status): string {
    return PPDB_STATUSES[$status]['label'] ?? ucfirst(str_replace('_', ' ', $status));
}

function getPpdbStatusBadge(string $status): string {
    return PPDB_STATUSES[$status]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

/**
 * Status Sirkulasi Perpustakaan
 */
const LIBRARY_LOAN_STATUSES = [
    'dipinjam' => ['label' => 'Sedang Dipinjam',    'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300'],
    'kembali'  => ['label' => 'Sudah Dikembalikan', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'],
    'hilang'   => ['label' => 'Buku Hilang',        'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300'],
];

function getLoanStatusLabel(string $status): string {
    return LIBRARY_LOAN_STATUSES[$status]['label'] ?? ucfirst($status);
}

function getLoanStatusBadge(string $status): string {
    return LIBRARY_LOAN_STATUSES[$status]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

/**
 * Jalur Pendaftaran PPDB
 */
const PPDB_TRACKS = [
    'reguler'  => ['label' => 'Reguler / Tes Akademik', 'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300', 'icon' => 'fa-graduation-cap'],
    'zonasi'   => ['label' => 'Zonasi Domisili',        'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => 'fa-location-dot'],
    'prestasi' => ['label' => 'Jalur Prestasi',         'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300', 'icon' => 'fa-trophy'],
    'afirmasi' => ['label' => 'Afirmasi / KIP',         'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300', 'icon' => 'fa-hand-holding-heart'],
];

function getPpdbTrackLabel(string $track): string {
    return PPDB_TRACKS[$track]['label'] ?? ucfirst($track);
}

function getPpdbTrackBadge(string $track): string {
    return PPDB_TRACKS[$track]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getPpdbTrackIcon(string $track): string {
    return PPDB_TRACKS[$track]['icon'] ?? 'fa-file-lines';
}

/**
 * Status Verifikasi Berkas Dokumen PPDB
 */
const PPDB_DOC_STATUSES = [
    'lengkap'      => ['label' => 'Berkas Lengkap & Valid', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'],
    'perlu_revisi' => ['label' => 'Perlu Revisi Berkas',   'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300'],
    'ditolak'      => ['label' => 'Berkas Ditolak',        'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300'],
];

function getPpdbDocStatusLabel(string $status): string {
    return PPDB_DOC_STATUSES[$status]['label'] ?? ucfirst(str_replace('_', ' ', $status));
}

function getPpdbDocStatusBadge(string $status): string {
    return PPDB_DOC_STATUSES[$status]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

/**
 * Kalkulator Nilai PPDB Otomatis (Rapor + Bobot Prestasi / Zonasi)
 */
function calculatePpdbScore(float $math, float $science, float $indo, float $eng, string $track = 'reguler', ?string $ach_level = null, ?float $distance_km = null): float {
    // 1. Rata-rata 4 Mapel Pokok (Maks 100)
    $avg_report = ($math + $science + $indo + $eng) / 4.0;

    // 2. Bonus Jalur Prestasi
    $bonus_prestasi = 0.0;
    if ($track === 'prestasi' && !empty($ach_level)) {
        switch ($ach_level) {
            case 'internasional': $bonus_prestasi = 15.0; break;
            case 'nasional':      $bonus_prestasi = 10.0; break;
            case 'provinsi':      $bonus_prestasi = 7.0;  break;
            case 'kabupaten':     $bonus_prestasi = 5.0;  break;
            case 'kecamatan':     $bonus_prestasi = 3.0;  break;
            case 'sekolah':       $bonus_prestasi = 1.5;  break;
        }
    }

    // 3. Poin Zonasi (Jarak lebih dekat = prioritas nilai zonasi lebih tinggi)
    $bonus_zonasi = 0.0;
    if ($track === 'zonasi' && $distance_km !== null) {
        if ($distance_km <= 1.0) {
            $bonus_zonasi = 10.0;
        } elseif ($distance_km <= 3.0) {
            $bonus_zonasi = 7.0;
        } elseif ($distance_km <= 5.0) {
            $bonus_zonasi = 4.0;
        } elseif ($distance_km <= 10.0) {
            $bonus_zonasi = 2.0;
        }
    }

    // Total skor akhir maksimal 100
    $final_score = min(100.0, round($avg_report + $bonus_prestasi + $bonus_zonasi, 2));
    return max(0.0, $final_score);
}

/**
 * Generate Token Unik Verifikasi QR Code Dokumen PPDB
 */
function generatePpdbQrToken(string $registration_no): string {
    return hash('sha256', $registration_no . '|PPDB_SECRET_SALT_2026|' . microtime(true) . '|' . bin2hex(random_bytes(8)));
}




