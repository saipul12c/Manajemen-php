<?php
/**
 * Konfigurasi Database & Helper Sistem
 * Manajemen-PHP
 */

// Pengaturan keamanan sesi & cookie terpusat
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');
@ini_set('session.use_only_cookies', '1');
if ($isHttps) {
    @ini_set('session.cookie_secure', '1');
}
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
}

// -17 fix: Dukungan membaca konfigurasi dari .env atau environment variable
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if (getenv($name) === false) {
                putenv("$name=$value");
                $_ENV[$name] = $value;
            }
        }
    }
}

$host = getenv('DB_HOST') ?: "localhost";
$dbname = getenv('DB_NAME') ?: "website_login";
$username = getenv('DB_USER') ?: "root";
$password_db = (getenv('DB_PASS') !== false) ? getenv('DB_PASS') : (getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : "");

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

    // -11 fix: Inisialisasi skema & migrasi tabel hanya dijalankan sekali melalui file flag
    $migrationLockFile = __DIR__ . '/.db_migrated_v20';
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['db_migrated_v20']) && !file_exists($migrationLockFile)) {
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
            `class_id` INT DEFAULT NULL,
            `education_level` ENUM('semua', 'sd', 'smp', 'sma', 'smk') NOT NULL DEFAULT 'semua',
            `category` VARCHAR(50) NOT NULL DEFAULT 'sts',
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
            `is_ppdb` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `exam_questions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `exam_id` INT NOT NULL,
            `question_type` VARCHAR(50) NOT NULL DEFAULT 'multiple_choice',
            `max_score` DECIMAL(5,2) DEFAULT 10.00,
            `question_text` TEXT NOT NULL,
            `image_url` VARCHAR(255) DEFAULT NULL,
            `audio_url` VARCHAR(255) DEFAULT NULL,
            `option_a` TEXT DEFAULT NULL,
            `option_b` TEXT DEFAULT NULL,
            `option_c` TEXT DEFAULT NULL,
            `option_d` TEXT DEFAULT NULL,
            `option_e` TEXT DEFAULT NULL,
            `correct_answer` TEXT DEFAULT NULL,
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
            `violation_count` INT NOT NULL DEFAULT 0,
            `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
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
            `status` ENUM('hadir', 'terlambat', 'sakit', 'izin', 'alpa') NOT NULL DEFAULT 'hadir',
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

        CREATE TABLE IF NOT EXISTS `external_apis` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `api_code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(150) NOT NULL,
            `category` ENUM('whatsapp', 'payment', 'ai', 'mail', 'maps', 'weather', 'custom') NOT NULL DEFAULT 'custom',
            `provider` VARCHAR(100) NOT NULL DEFAULT 'Custom Provider',
            `endpoint_url` TEXT NOT NULL,
            `api_key` TEXT DEFAULT NULL,
            `api_secret` TEXT DEFAULT NULL,
            `auth_type` ENUM('none', 'bearer', 'basic', 'header', 'query') NOT NULL DEFAULT 'bearer',
            `auth_header_name` VARCHAR(100) DEFAULT 'Authorization',
            `http_method` ENUM('GET', 'POST', 'PUT', 'DELETE') NOT NULL DEFAULT 'POST',
            `custom_headers` TEXT DEFAULT NULL,
            `payload_template` TEXT DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `environment` ENUM('production', 'sandbox') NOT NULL DEFAULT 'production',
            `timeout_seconds` INT NOT NULL DEFAULT 10,
            `last_tested_at` DATETIME DEFAULT NULL,
            `last_status_code` INT DEFAULT NULL,
            `last_response` TEXT DEFAULT NULL,
            `description` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
            `achievement_cert_doc` VARCHAR(255) DEFAULT NULL,
            `achievements_json` LONGTEXT DEFAULT NULL,
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
            `price` DECIMAL(10,2) NOT NULL DEFAULT 75000.00,
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
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_book_user_review` (`book_id`, `user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 29. Pesan Kontak Tamu (Inbox Tamu Publik)
        CREATE TABLE IF NOT EXISTS `contact_messages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `ticket_code` VARCHAR(50) DEFAULT NULL,
            `name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NOT NULL,
            `phone` VARCHAR(50) DEFAULT NULL,
            `category` VARCHAR(60) NOT NULL DEFAULT 'Umum',
            `subject` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `status` ENUM('baru', 'diproses', 'selesai') NOT NULL DEFAULT 'baru',
            `ip_address` VARCHAR(45) DEFAULT NULL,
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

        -- 34. Sistem Berita & Publikasi Sekolah (News & Articles)
        CREATE TABLE IF NOT EXISTS `news` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL UNIQUE,
            `excerpt` TEXT DEFAULT NULL,
            `content` LONGTEXT NOT NULL,
            `category` ENUM('kegiatan', 'prestasi', 'akademik', 'artikel', 'fasilitas', 'umum') NOT NULL DEFAULT 'kegiatan',
            `thumbnail` VARCHAR(255) DEFAULT NULL,
            `book_id` INT DEFAULT NULL,
            `event_id` INT DEFAULT NULL,
            `counseling_id` INT DEFAULT NULL,
            `author_id` INT NOT NULL,
            `status` ENUM('draft', 'pending', 'published', 'rejected') NOT NULL DEFAULT 'draft',
            `approved_by` INT DEFAULT NULL,
            `approved_at` DATETIME DEFAULT NULL,
            `rejection_note` TEXT DEFAULT NULL,
            `views` INT NOT NULL DEFAULT 0,
            `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
            `published_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT `fk_news_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_news_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 35. Log Notifikasi WhatsApp Gateway (Fase 1)
        CREATE TABLE IF NOT EXISTS `wa_notification_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `recipient_phone` VARCHAR(30) NOT NULL,
            `recipient_name` VARCHAR(150) NOT NULL,
            `message` TEXT NOT NULL,
            `category` ENUM('presensi', 'keuangan', 'pengumuman', 'akademik', 'sistem') NOT NULL DEFAULT 'sistem',
            `status` ENUM('terkirim', 'pending', 'gagal') NOT NULL DEFAULT 'terkirim',
            `gateway_provider` VARCHAR(50) NOT NULL DEFAULT 'Fonnte (Simulated)',
            `response_payload` TEXT DEFAULT NULL,
            `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        -- 36. Modul Penggajian & Honor Guru/Staf (Fase 5)
        CREATE TABLE IF NOT EXISTS `teacher_payrolls` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `payroll_code` VARCHAR(50) NOT NULL UNIQUE,
            `teacher_id` INT NOT NULL,
            `month_period` VARCHAR(30) NOT NULL,
            `base_salary` DECIMAL(12,2) NOT NULL DEFAULT 3000000.00,
            `position_allowance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `teaching_hours` INT NOT NULL DEFAULT 0,
            `teaching_rate_per_hour` DECIMAL(12,2) NOT NULL DEFAULT 50000.00,
            `total_teaching_honor` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `attendance_deductions` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `net_salary` DECIMAL(12,2) NOT NULL DEFAULT 3000000.00,
            `payment_date` DATE DEFAULT NULL,
            `status` ENUM('draft', 'disetujui', 'dibayar') NOT NULL DEFAULT 'draft',
            `payment_method` VARCHAR(50) DEFAULT 'Transfer Bank',
            `notes` TEXT DEFAULT NULL,
            `created_by` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_teacher_payroll_month` (`teacher_id`, `month_period`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Auto-migration untuk kolom baru jika tabel sudah pernah dibuat sebelumnya
    $alter_queries = [
        "CREATE TABLE IF NOT EXISTS `news` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL UNIQUE,
            `excerpt` TEXT DEFAULT NULL,
            `content` LONGTEXT NOT NULL,
            `category` ENUM('kegiatan', 'prestasi', 'akademik', 'artikel', 'fasilitas', 'umum') NOT NULL DEFAULT 'kegiatan',
            `thumbnail` VARCHAR(255) DEFAULT NULL,
            `author_id` INT NOT NULL,
            `status` ENUM('draft', 'pending', 'published', 'rejected') NOT NULL DEFAULT 'draft',
            `approved_by` INT DEFAULT NULL,
            `approved_at` DATETIME DEFAULT NULL,
            `rejection_note` TEXT DEFAULT NULL,
            `views` INT NOT NULL DEFAULT 0,
            `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
            `published_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "ALTER TABLE `news` ADD COLUMN `book_id` INT DEFAULT NULL AFTER `thumbnail`",
        "ALTER TABLE `news` ADD COLUMN `event_id` INT DEFAULT NULL AFTER `book_id`",
        "ALTER TABLE `news` ADD COLUMN `counseling_id` INT DEFAULT NULL AFTER `event_id`",
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
        "ALTER TABLE `student_attendance` MODIFY COLUMN `status` ENUM('hadir', 'terlambat', 'sakit', 'izin', 'alpa') NOT NULL DEFAULT 'hadir'",
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
            `decision_status` VARCHAR(50) NOT NULL DEFAULT 'belum_ditetapkan',
            `target_next_class` VARCHAR(50) DEFAULT NULL,
            `subject_descriptions` LONGTEXT DEFAULT NULL,
            `pkl_internship` LONGTEXT DEFAULT NULL,
            `ukk_vocational` LONGTEXT DEFAULT NULL,
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
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `achievement_cert_doc` VARCHAR(255) DEFAULT NULL AFTER `photo_doc`",
        "ALTER TABLE `ppdb_registrations` ADD COLUMN `achievements_json` LONGTEXT DEFAULT NULL AFTER `achievement_cert_doc`",
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
        "ALTER TABLE `classes` ADD COLUMN `homeroom_teacher_id` INT DEFAULT NULL AFTER `academic_year`",
        "CREATE TABLE IF NOT EXISTS `teaching_journals` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `teacher_id` INT NOT NULL,
            `class_id` INT NOT NULL,
            `subject_id` INT DEFAULT NULL,
            `subject_name` VARCHAR(100) NOT NULL,
            `date` DATE NOT NULL,
            `start_time` TIME NOT NULL,
            `end_time` TIME NOT NULL,
            `topic` VARCHAR(255) NOT NULL,
            `competency_notes` TEXT DEFAULT NULL,
            `student_attendance_summary` VARCHAR(150) DEFAULT NULL,
            `class_condition_notes` TEXT DEFAULT NULL,
            `status` ENUM('terlaksana', 'inval', 'kendala') NOT NULL DEFAULT 'terlaksana',
            `substitute_teacher_id` INT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `curriculum_documents` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `teacher_id` INT NOT NULL,
            `subject_name` VARCHAR(100) NOT NULL,
            `grade_level` VARCHAR(20) NOT NULL DEFAULT '10',
            `academic_year` VARCHAR(30) NOT NULL DEFAULT '2026/2027',
            `semester` ENUM('Ganjil', 'Genap') NOT NULL DEFAULT 'Ganjil',
            `doc_type` ENUM('modul_ajar', 'rpp', 'silabus', 'prota', 'promes', 'kktp', 'lainnya') NOT NULL DEFAULT 'modul_ajar',
            `title` VARCHAR(200) NOT NULL,
            `file_url` VARCHAR(255) DEFAULT NULL,
            `description` TEXT DEFAULT NULL,
            `status` ENUM('draft', 'diajukan', 'disetujui', 'revisi') NOT NULL DEFAULT 'draft',
            `reviewed_by` INT DEFAULT NULL,
            `review_notes` TEXT DEFAULT NULL,
            `reviewed_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `teacher_attendance` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `teacher_id` INT NOT NULL,
            `date` DATE NOT NULL,
            `check_in_time` TIME DEFAULT NULL,
            `check_out_time` TIME DEFAULT NULL,
            `status` ENUM('hadir', 'terlambat', 'izin', 'sakit', 'cuti') NOT NULL DEFAULT 'hadir',
            `notes` TEXT DEFAULT NULL,
            `proof_doc` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_teacher_attendance_date` (`teacher_id`, `date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "ALTER TABLE `inventory_loans` ADD COLUMN `user_id` INT DEFAULT NULL AFTER `item_id`",
        "ALTER TABLE `inventory_loans` MODIFY COLUMN `status` ENUM('menunggu', 'disetujui', 'dipinjam', 'kembali', 'ditolak') NOT NULL DEFAULT 'menunggu'",
        "ALTER TABLE `inventory_loans` ADD COLUMN `approval_notes` TEXT DEFAULT NULL AFTER `purpose`",
        "ALTER TABLE `inventory_loans` ADD COLUMN `approved_by` INT DEFAULT NULL AFTER `approval_notes`",
        "ALTER TABLE `exam_submissions` ADD COLUMN `violation_count` INT NOT NULL DEFAULT 0 AFTER `status`",
        "ALTER TABLE `exam_submissions` ADD COLUMN `is_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `violation_count`",
        "ALTER TABLE `teacher_attendance` ADD COLUMN `latitude` DECIMAL(10,8) DEFAULT NULL AFTER `proof_doc`",
        "ALTER TABLE `teacher_attendance` ADD COLUMN `longitude` DECIMAL(11,8) DEFAULT NULL AFTER `latitude`",
        "ALTER TABLE `teacher_attendance` ADD COLUMN `distance_meters` INT DEFAULT NULL AFTER `longitude`",
        "ALTER TABLE `student_bills` ADD COLUMN `payment_channel` VARCHAR(50) DEFAULT NULL AFTER `status`",
        "ALTER TABLE `student_bills` ADD COLUMN `gateway_trx_id` VARCHAR(100) DEFAULT NULL AFTER `payment_channel`",
        "ALTER TABLE `student_bills` ADD COLUMN `va_number` VARCHAR(50) DEFAULT NULL AFTER `gateway_trx_id`",
        "ALTER TABLE `student_bills` ADD COLUMN `paid_at` DATETIME DEFAULT NULL AFTER `va_number`",
        "CREATE TABLE IF NOT EXISTS `wa_notification_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `recipient_phone` VARCHAR(30) NOT NULL,
            `recipient_name` VARCHAR(150) NOT NULL,
            `message` TEXT NOT NULL,
            `category` ENUM('presensi', 'keuangan', 'pengumuman', 'akademik', 'sistem') NOT NULL DEFAULT 'sistem',
            `status` ENUM('terkirim', 'pending', 'gagal') NOT NULL DEFAULT 'terkirim',
            `gateway_provider` VARCHAR(50) NOT NULL DEFAULT 'Fonnte (Simulated)',
            `response_payload` TEXT DEFAULT NULL,
            `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `teacher_payrolls` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `payroll_code` VARCHAR(50) NOT NULL UNIQUE,
            `teacher_id` INT NOT NULL,
            `month_period` VARCHAR(30) NOT NULL,
            `base_salary` DECIMAL(12,2) NOT NULL DEFAULT 3000000.00,
            `position_allowance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `teaching_hours` INT NOT NULL DEFAULT 0,
            `teaching_rate_per_hour` DECIMAL(12,2) NOT NULL DEFAULT 50000.00,
            `total_teaching_honor` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `attendance_deductions` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `net_salary` DECIMAL(12,2) NOT NULL DEFAULT 3000000.00,
            `payment_date` DATE DEFAULT NULL,
            `status` ENUM('draft', 'disetujui', 'dibayar') NOT NULL DEFAULT 'draft',
            `payment_method` VARCHAR(50) DEFAULT 'Transfer Bank',
            `notes` TEXT DEFAULT NULL,
            `created_by` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_teacher_payroll_month` (`teacher_id`, `month_period`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "ALTER TABLE `users` ADD COLUMN `status` ENUM('aktif', 'skorsing', 'dikeluarkan', 'mutasi', 'lulus') NOT NULL DEFAULT 'aktif' AFTER `role`",
        "ALTER TABLE `users` ADD COLUMN `status_reason` TEXT DEFAULT NULL AFTER `status`",
        "ALTER TABLE `users` ADD COLUMN `status_updated_at` DATETIME DEFAULT NULL AFTER `status_reason`",
        "ALTER TABLE `users` ADD COLUMN `status_updated_by` INT DEFAULT NULL AFTER `status_updated_at`",
        "ALTER TABLE `library_books` ADD COLUMN `price` DECIMAL(10,2) NOT NULL DEFAULT 75000.00 AFTER `shelf_location`",
        "ALTER TABLE `contact_messages` ADD COLUMN `ticket_code` VARCHAR(50) DEFAULT NULL AFTER `id`",
        "ALTER TABLE `contact_messages` ADD COLUMN `category` VARCHAR(60) NOT NULL DEFAULT 'Umum' AFTER `phone`",
        "ALTER TABLE `contact_messages` ADD COLUMN `ip_address` VARCHAR(45) DEFAULT NULL AFTER `status`",
        "ALTER TABLE `contact_messages` ADD COLUMN `admin_notes` TEXT DEFAULT NULL AFTER `ip_address`",
        "ALTER TABLE `contact_messages` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
        "ALTER TABLE `library_reviews` ADD UNIQUE KEY `uk_book_user_review` (`book_id`, `user_id`)",
        "ALTER TABLE `exams` MODIFY COLUMN `category` VARCHAR(50) NOT NULL DEFAULT 'sts'",
        "ALTER TABLE `exams` ADD COLUMN `class_id` INT DEFAULT NULL AFTER `subject`",
        "ALTER TABLE `exams` ADD COLUMN `education_level` ENUM('semua', 'sd', 'smp', 'sma', 'smk') NOT NULL DEFAULT 'semua' AFTER `class_id`",
        "ALTER TABLE `exam_questions` MODIFY COLUMN `question_type` VARCHAR(50) NOT NULL DEFAULT 'multiple_choice'",
        "ALTER TABLE `exam_questions` ADD COLUMN `option_e` TEXT DEFAULT NULL AFTER `option_d`",
        "ALTER TABLE `exam_questions` ADD COLUMN `audio_url` VARCHAR(255) DEFAULT NULL AFTER `image_url`",
        "ALTER TABLE `student_report_notes` ADD COLUMN `decision_status` VARCHAR(50) NOT NULL DEFAULT 'belum_ditetapkan' AFTER `extracurricular`",
        "ALTER TABLE `student_report_notes` ADD COLUMN `target_next_class` VARCHAR(50) DEFAULT NULL AFTER `decision_status`",
        "ALTER TABLE `student_report_notes` ADD COLUMN `subject_descriptions` LONGTEXT DEFAULT NULL AFTER `target_next_class`",
        "ALTER TABLE `student_report_notes` ADD COLUMN `pkl_internship` LONGTEXT DEFAULT NULL AFTER `subject_descriptions`",
        "ALTER TABLE `student_report_notes` ADD COLUMN `ukk_vocational` LONGTEXT DEFAULT NULL AFTER `pkl_internship`",
        "ALTER TABLE `subjects` ADD COLUMN `kkm` INT NOT NULL DEFAULT 75 AFTER `code`"
    ];
    foreach ($alter_queries as $aq) {
        try {
            $pdo->exec($aq);
        } catch (PDOException $e_alter) {
            // Kolom sudah ada
        }
    }

    // Eksekusi migrasi kolom status users secara eksplisit untuk integritas data
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `status` ENUM('aktif', 'skorsing', 'dikeluarkan', 'mutasi', 'lulus') NOT NULL DEFAULT 'aktif' AFTER `role`");
    } catch (Exception $e_u1) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `status_reason` TEXT DEFAULT NULL AFTER `status`");
    } catch (Exception $e_u2) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `status_updated_at` DATETIME DEFAULT NULL AFTER `status_reason`");
    } catch (Exception $e_u3) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `status_updated_by` INT DEFAULT NULL AFTER `status_updated_at`");
    } catch (Exception $e_u4) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `nip` VARCHAR(35) DEFAULT NULL AFTER `nisn`");
    } catch (Exception $e_u5) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `title_degree` VARCHAR(100) DEFAULT NULL AFTER `name`");
    } catch (Exception $e_u6) {}
    try {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `signature_image` VARCHAR(255) DEFAULT NULL AFTER `address`");
    } catch (Exception $e_u7) {}

    // Auto-create tabel legalisir_requests untuk Layanan Mandiri Siswa & Alumni
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `legalisir_requests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT DEFAULT NULL,
                `alumni_name` VARCHAR(150) NOT NULL,
                `nisn` VARCHAR(30) DEFAULT NULL,
                `graduation_year` VARCHAR(10) DEFAULT NULL,
                `document_type` ENUM('ijazah', 'skl', 'rapor', 'transkrip') NOT NULL DEFAULT 'ijazah',
                `document_number` VARCHAR(100) DEFAULT NULL,
                `purpose` VARCHAR(255) DEFAULT NULL,
                `contact_whatsapp` VARCHAR(30) DEFAULT NULL,
                `contact_email` VARCHAR(100) DEFAULT NULL,
                `file_original` VARCHAR(255) DEFAULT NULL,
                `legalized_code` VARCHAR(64) UNIQUE NOT NULL,
                `status` ENUM('menunggu', 'diverifikasi', 'disetujui', 'ditolak') NOT NULL DEFAULT 'menunggu',
                `rejection_reason` TEXT DEFAULT NULL,
                `processed_by` INT DEFAULT NULL,
                `verified_at` DATETIME DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY `idx_legalisir_user` (`user_id`),
                KEY `idx_legalisir_code` (`legalized_code`),
                KEY `idx_legalisir_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } catch (Exception $e_leg) {}

    // Auto-seed data sampel permohonan legalisir jika masih kosong
    try {
        $count_leg = (int) $pdo->query("SELECT COUNT(*) FROM `legalisir_requests`")->fetchColumn();
        if ($count_leg === 0) {
            $pdo->exec("
                INSERT INTO `legalisir_requests` 
                (`user_id`, `alumni_name`, `nisn`, `graduation_year`, `document_type`, `document_number`, `purpose`, `contact_whatsapp`, `contact_email`, `file_original`, `legalized_code`, `status`, `processed_by`, `verified_at`) VALUES
                (NULL, 'Muhammad Fajar Pratama, S.Kom', '0041829301', '2024', 'ijazah', 'DN-01/M-SMA/13/0089214', 'Pendaftaran Seleksi Calon Pegawai Negeri Sipil (CPNS) & BUMN', '081298765432', 'fajar.pratama@gmail.com', 'uploads/signatures/default_sample_ijazah.svg', 'LEG-202609-0842', 'disetujui', 1, NOW()),
                (NULL, 'Aisyah Putri Rahmadani', '0052938411', '2025', 'skl', '421.3/SKL-SMA/089/2025', 'Registrasi Ulang Program Beasiswa Sarjana Luar Negeri', '085712348899', 'aisyah.putri@yahoo.co.id', 'uploads/signatures/default_sample_ijazah.svg', 'LEG-202609-1129', 'menunggu', NULL, NULL);
            ");
        }
    } catch (Exception $e_leg_seed) {}

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

        // Auto-seed Berita Sekolah (news)
        $count_news = (int) $pdo->query("SELECT COUNT(*) FROM `news`")->fetchColumn();
        if ($count_news === 0) {
            $admin_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'administrator' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            $staff_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'staf' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 2;
            $teacher_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'guru' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 3;

            $pdo->exec("
                INSERT INTO `news` 
                (`title`, `slug`, `excerpt`, `content`, `category`, `thumbnail`, `author_id`, `status`, `approved_by`, `approved_at`, `views`, `is_featured`, `published_at`)
                VALUES
                (
                    'Siswa SMA Teladan Raih Medali Emas Olimpiade Sains Nasional (OSN) 2026',
                    'siswa-sma-teladan-raih-medali-emas-olimpiade-sains-nasional-osn-2026',
                    'Kabar membanggakan datang dari kontingen sains sekolah yang berhasil menorehkan prestasi tertinggi di tingkat nasional.',
                    '<p>Civitas akademika SMA Teladan kembali menorehkan prestasi gemilang di kancah nasional. Siswa perwakilan sekolah berhasil meraih <strong>Medali Emas</strong> pada ajang <em>Olimpiade Sains Nasional (OSN) 2026</em> bidang Astronomi dan Informatika yang diselenggarakan oleh Balai Pengembangan Talenta Indonesia (BPTI).</p><p>Kepala Sekolah menyampaikan rasa syukur dan apresiasi setinggi-tingginya kepada para siswa pembina dan bapak/ibu guru yang telah mendedikasikan waktu dalam program pembinaan intensif selama enam bulan terakhir. Semoga prestasi ini menjadi pemantik semangat bagi seluruh peserta didik lainnya.</p>',
                    'prestasi',
                    NULL,
                    $teacher_id,
                    'published',
                    $admin_id,
                    NOW(),
                    142,
                    1,
                    NOW()
                ),
                (
                    'Pentas Seni & Budaya Nusantara Meriahkan Perayaan Bulan Bahasa',
                    'pentas-seni-budaya-nusantara-meriahkan-perayaan-bulan-bahasa',
                    'Ratusan siswa menampilkan karya seni tari tradisi, teater sastra, dan pameran literasi kreatif di panggung terbuka sekolah.',
                    '<p>Suasana semarak dan penuh warna menghiasi halaman utama sekolah dalam perhelatan <strong>Pentas Seni & Budaya Nusantara</strong>. Kegiatan tahunan ini diselenggarakan dalam rangka memperingati Bulan Bahasa dan Sastra Nasional dengan tema <em>\"Mewujudkan Generasi Berkebinekaan Global yang Berakar Budaya Lokal\"</em>.</p><p>Berbagai pertunjukan memukau ditampilkan oleh siswa dari kelas X hingga XII, mulai dari sendratari daerah, musikalisasi puisi karya sastrawan ternama, drama musikal bertema kepahlawanan, hingga stan bazar kuliner tradisional yang diinisiasi oleh kelompok kewirausahaan siswa.</p>',
                    'kegiatan',
                    NULL,
                    $staff_id,
                    'published',
                    NULL,
                    NOW(),
                    89,
                    1,
                    NOW()
                ),
                (
                    'Workshop Inovasi Pembelajaran Berbasis AI untuk Guru dan Pendidik',
                    'workshop-inovasi-pembelajaran-berbasis-ai-untuk-guru-dan-pendidik',
                    'Pelatihan pemanfaatan teknologi kecerdasan buatan etis guna menyusun bahan ajar adaptif dan asesmen interaktif.',
                    '<p>Sebagai langkah mempercepat transformasi digital sekolah, diselenggarakan workshop bertajuk <strong>\"Pemanfaatan Etis Kecerdasan Buatan (AI) dalam Desain Pembelajaran Berdiferensiasi\"</strong> yang diikuti oleh 45 guru mata pelajaran.</p><p>Narasumber ahli teknologi pendidikan memaparkan teknik menyusun instrumen penilaian autentik, simulasi pembuatan rubrik digital, dan pemanfaatan asisten kecerdasan buatan untuk mengidentifikasi kebutuhan belajar individual setiap peserta didik secara lebih cepat dan akurat.</p>',
                    'akademik',
                    NULL,
                    $admin_id,
                    'published',
                    NULL,
                    NOW(),
                    64,
                    0,
                    NOW()
                ),
                (
                    'Kunjungan Studi Literasi & Pengenalan Riset ke Balai Konservasi Alam',
                    'kunjungan-studi-literasi-pengenalan-riset-ke-balai-konservasi-alam',
                    'Siswa kelas XI melakukan observasi lapangan dan analisis keanekaragaman hayati dalam program pembelajaran luar kelas.',
                    '<p>Siswa-siswi peminatan Biologi dan Geografi mengadakan kunjungan ilmiah lapangan ke Balai Konservasi Alam Daerah. Kegiatan ini bertujuan memperkuat wawasan ekologi dan memberikan pengalaman observasi empiris terhadap flora dan fauna endemik yang dilindungi.</p><p>Laporan hasil observasi akan dipublikasikan dalam bentuk majalah dinding ilmiah serta infografis digital di perpustakaan sekolah.</p>',
                    'kegiatan',
                    NULL,
                    $teacher_id,
                    'pending',
                    NULL,
                    NULL,
                    0,
                    0,
                    NULL
                );
            ");
        }
        // Auto-seed Wali Kelas (Homeroom Teacher) ke Kelas 1 (Dewi Lestari ID: 3)
        try {
            $pdo->exec("UPDATE `classes` SET `homeroom_teacher_id` = 3 WHERE `id` = 1 AND `homeroom_teacher_id` IS NULL");
        } catch (Exception $e) {}

        // Auto-seed Jurnal Mengajar (teaching_journals)
        $count_journals = (int) $pdo->query("SELECT COUNT(*) FROM `teaching_journals`")->fetchColumn();
        if ($count_journals === 0) {
            $teacher_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'guru' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 3;
            $pdo->exec("
                INSERT INTO `teaching_journals` 
                (`teacher_id`, `class_id`, `subject_name`, `date`, `start_time`, `end_time`, `topic`, `competency_notes`, `student_attendance_summary`, `class_condition_notes`, `status`)
                VALUES
                ($teacher_id, 1, 'Matematika', CURRENT_DATE(), '07:30:00', '09:00:00', 'Fungsi Kuadrat dan Grafik Parabola', 'Siswa mampu menentukan titik puncak dan menggambar sketsa grafik parabola dengan benar.', '35 Hadir, 1 Sakit', 'Siswa antusias dan aktif berdiskusi dalam kelompok.', 'terlaksana'),
                ($teacher_id, 1, 'Fisika', DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY), '09:15:00', '10:45:00', 'Hukum Gerak Newton & Penerapan di Kehidupan Sehari-hari', 'Menganalisis hubungan gaya, massa, dan percepatan pada gerak lurus beraturan.', '36 Hadir Lengkap', 'Praktikum demonstrasi dinamika gerak berjalan lancar.', 'terlaksana');
            ");
        }

        // Auto-seed Perangkat Pembelajaran (curriculum_documents)
        $count_curriculum = (int) $pdo->query("SELECT COUNT(*) FROM `curriculum_documents`")->fetchColumn();
        if ($count_curriculum === 0) {
            $teacher_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'guru' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 3;
            $admin_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'administrator' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            $pdo->exec("
                INSERT INTO `curriculum_documents` 
                (`teacher_id`, `subject_name`, `grade_level`, `academic_year`, `semester`, `doc_type`, `title`, `file_url`, `description`, `status`, `reviewed_by`, `review_notes`, `reviewed_at`)
                VALUES
                ($teacher_id, 'Matematika', '10', '2026/2027', 'Ganjil', 'modul_ajar', 'Modul Ajar Matematika Fase E - Fungsi & Aljabar', 'https://drive.google.com', 'Perangkat pembelajaran Kurikulum Merdeka topik aljabar dan fungsi kuadrat kelas X.', 'disetujui', $admin_id, 'Struktur asesmen autentik dan rubrik sudah sangat baik dan sesuai panduan kurikulum.', NOW()),
                ($teacher_id, 'Fisika', '10', '2026/2027', 'Ganjil', 'prota', 'Program Tahunan (Prota) Fisika Kelas X Kurikulum Merdeka', 'https://drive.google.com', 'Alokasi waktu tatap muka 72 jam pelajaran semester ganjil dan genap.', 'diajukan', NULL, NULL, NULL);
            ");
        }

        // Auto-seed Presensi Guru (teacher_attendance)
        $count_t_att = (int) $pdo->query("SELECT COUNT(*) FROM `teacher_attendance`")->fetchColumn();
        if ($count_t_att === 0) {
            $teacher_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'guru' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 3;
            $pdo->exec("
                INSERT INTO `teacher_attendance` 
                (`teacher_id`, `date`, `check_in_time`, `check_out_time`, `status`, `notes`)
                VALUES
                ($teacher_id, CURRENT_DATE(), '06:45:00', NULL, 'hadir', 'Tiba tepat waktu sebelum jam pertama KBM dimulai.'),
                ($teacher_id, DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY), '06:50:00', '15:30:00', 'hadir', 'KBM selesai, mengikuti rapat koordinasi kurikulum sore.');
            ");
        }

        // Auto-seed konfigurasi default tambahan di school_settings (WA, CBT, Geofencing, Payroll)
        $pdo->exec("
            INSERT IGNORE INTO `school_settings` (`setting_key`, `setting_value`) VALUES
            ('wa_gateway_provider', 'Fonnte (Simulated)'),
            ('wa_gateway_token', 'fonnte_demo_token_2026'),
            ('wa_gateway_enabled', '1'),
            ('wa_sender_number', '081234567890'),
            ('school_latitude', '-6.208800'),
            ('school_longitude', '106.845600'),
            ('school_radius_meters', '150'),
            ('cbt_max_violations', '3'),
            ('cbt_strict_mode', '1'),
            ('payroll_teaching_rate_hour', '50000'),
            ('payroll_homeroom_allowance', '500000'),
            ('payroll_base_salary_default', '3000000');
        ");

        // Auto-seed sample WA Notification Logs jika kosong (Fase 1)
        $count_wa = (int) $pdo->query("SELECT COUNT(*) FROM `wa_notification_logs`")->fetchColumn();
        if ($count_wa === 0) {
            $pdo->exec("
                INSERT INTO `wa_notification_logs` 
                (`recipient_phone`, `recipient_name`, `message`, `category`, `status`, `gateway_provider`)
                VALUES
                ('081234567890', 'Bpk. Hendra Wijaya', 'Yth. Bpk/Ibu Hendra Wijaya, ananda Ahmad Fauzi hari ini Jumat, 26 Sep 2026 tercatat HADIR tepat waktu di SMA Bina Bangsa. Terima kasih.', 'presensi', 'terkirim', 'Fonnte (Simulated)'),
                ('081234567890', 'Bpk. Hendra Wijaya', 'Yth. Wali Murid Ahmad Fauzi, tagihan SPP Bulan September 2026 sebesar Rp 350.000 telah LUNAS terverifikasi otomatis melalui Virtual Account BCA. Kuitansi resmi dapat diunduh pada portal.', 'keuangan', 'terkirim', 'Fonnte (Simulated)'),
                ('081298765432', 'Ibu Siti Rahmawati', 'Pemberitahuan: Jadwal Ujian Tengah Semester (UTS) Gasal akan diselenggarakan mulai Senin pekan depan. Harap mendampingi belajar ananda di rumah.', 'pengumuman', 'terkirim', 'Fonnte (Simulated)');
            ");
        }

        // Auto-seed sample Payroll Guru jika kosong (Fase 5)
        $count_payrolls = (int) $pdo->query("SELECT COUNT(*) FROM `teacher_payrolls`")->fetchColumn();
        if ($count_payrolls === 0) {
            $teacher_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'guru' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 3;
            $admin_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'administrator' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            $pdo->exec("
                INSERT INTO `teacher_payrolls`
                (`payroll_code`, `teacher_id`, `month_period`, `base_salary`, `position_allowance`, `teaching_hours`, `teaching_rate_per_hour`, `total_teaching_honor`, `attendance_deductions`, `net_salary`, `payment_date`, `status`, `payment_method`, `notes`, `created_by`)
                VALUES
                ('PAY-202609-001', $teacher_id, 'September 2026', 3200000.00, 500000.00, 48, 50000.00, 2400000.00, 0.00, 6100000.00, '2026-09-25', 'dibayar', 'Transfer BNI', 'Gaji pokok + Tunjangan Wali Kelas X MIPA 1 + Honor KBM 48 JP.', $admin_id);
            ");
        }

        // Auto-seed External APIs jika belum ada data
        $count_apis = (int) $pdo->query("SELECT COUNT(*) FROM `external_apis`")->fetchColumn();
        if ($count_apis === 0) {
            $pdo->exec("
                INSERT IGNORE INTO `external_apis` 
                (`api_code`, `name`, `category`, `provider`, `endpoint_url`, `api_key`, `api_secret`, `auth_type`, `auth_header_name`, `http_method`, `custom_headers`, `payload_template`, `is_active`, `environment`, `timeout_seconds`, `description`)
                VALUES
                ('wa_gateway', 'WhatsApp Notification Gateway', 'whatsapp', 'Fonnte (Official/Demo)', 'https://api.fonnte.com/send', 'fonnte_demo_token_2026', '', 'header', 'Authorization', 'POST', '{\"Accept\":\"application/json\"}', '{\n  \"target\": \"{target_phone}\",\n  \"message\": \"{message_text}\"\n}', 1, 'production', 10, 'Layanan API pengiriman pesan WhatsApp otomatis untuk notifikasi presensi, tagihan SPP, dan pengumuman sekolah.'),
                ('payment_gateway', 'Payment Gateway & Virtual Account (Midtrans)', 'payment', 'Midtrans Snap & Core API', 'https://app.sandbox.midtrans.com/snap/v1/transactions', 'SB-Mid-server-demo-token-2026', 'SB-Mid-client-demo-token-2026', 'basic', 'Authorization', 'POST', '{\"Accept\":\"application/json\",\"Content-Type\":\"application/json\"}', '{\n  \"transaction_details\": {\n    \"order_id\": \"BILL-EXAMPLE-001\",\n    \"gross_amount\": 350000\n  }\n}', 1, 'sandbox', 15, 'Integrasi gateway pembayaran online instan untuk tagihan SPP siswa (QRIS, VA BCA, Mandiri, BNI, BRI).'),
                ('ai_service', 'AI Assistant & Auto-Grading (Gemini / OpenAI)', 'ai', 'Google Gemini AI', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent', 'AIzaSyDemoKeyExample2026', '', 'query', 'key', 'POST', '{\"Content-Type\":\"application/json\"}', '{\n  \"contents\": [\n    {\n      \"parts\": [\n        {\"text\": \"Analisis dan beri penilaian ringkas untuk jawaban esai ini...\"}\n      ]\n    }\n  ]\n}', 1, 'production', 20, 'Kecerdasan Buatan (LLM) untuk asisten penyusunan materi KBM, bank soal otomatis, dan evaluasi esai siswa CBT.'),
                ('email_service', 'Layanan Email Transaksional & SMTP API', 'mail', 'SendGrid / Resend API', 'https://api.sendgrid.com/v3/mail/send', 'SG.demo_sendgrid_token_example', '', 'bearer', 'Authorization', 'POST', '{\"Content-Type\":\"application/json\"}', '{\n  \"personalizations\": [\n    {\"to\": [{\"email\": \"{recipient_email}\"}]}\n  ],\n  \"from\": {\"email\": \"noreply@binabangsa.sch.id\"},\n  \"subject\": \"{subject}\",\n  \"content\": [{\"type\": \"text/plain\", \"value\": \"{body}\"}]\n}', 1, 'production', 10, 'Pengiriman surat elektronik otomatis untuk kuitansi pembayaran SPP, kartu ujian siswa, dan verifikasi PPDB.'),
                ('maps_geocoding', 'Peta Lokasi & Geocoding Geofencing', 'maps', 'Nominatim OpenStreetMap', 'https://nominatim.openstreetmap.org/search', '', '', 'none', 'Authorization', 'GET', '{\"User-Agent\":\"ManajemenPHP-SchoolSystem/1.0\"}', '{}', 1, 'production', 10, 'Verifikasi koordinat GPS latitude/longitude sekolah dan validasi radius geofencing presensi mandiri guru.'),
                ('weather_api', 'Prakiraan Cuaca Terbuka (BMKG / Open-Meteo)', 'weather', 'Open-Meteo Weather API', 'https://api.open-meteo.com/v1/forecast?latitude=-6.2088&longitude=106.8456&current_weather=true', '', '', 'none', '', 'GET', '{\"Accept\":\"application/json\"}', '{}', 1, 'production', 8, 'Data cuaca dan prakiraan suhu lingkungan sekolah secara langsung untuk monitoring kegiatan upacara dan olahraga luar ruangan.');
            ");
        }
    } catch (PDOException $e_seed) {
        // Abaikan jika error insert sampel
    }

    // =========================================================
    // MIGRATION v16: INTEGRASI LINTAS MODUL (CROSS-MODULE AUTOMATION)
    // =========================================================
    try {
        // 1. exams: is_ppdb (Tandai asesmen yang digunakan untuk Ujian Seleksi PPDB)
        $cols_ex = $pdo->query("SHOW COLUMNS FROM `exams` LIKE 'is_ppdb'")->fetchAll();
        if (empty($cols_ex)) {
            $pdo->exec("ALTER TABLE `exams` ADD COLUMN `is_ppdb` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
        }
    } catch (Exception $e_m1) {}

    try {
        // 2. ppdb_registrations: cbt_exam_id, cbt_score, cbt_token, payment_status, payment_amount, payment_method, payment_invoice
        $cols_ppdb = $pdo->query("SHOW COLUMNS FROM `ppdb_registrations` LIKE 'cbt_exam_id'")->fetchAll();
        if (empty($cols_ppdb)) {
            $pdo->exec("
                ALTER TABLE `ppdb_registrations` 
                ADD COLUMN `cbt_exam_id` INT DEFAULT NULL AFTER `calculated_score`,
                ADD COLUMN `cbt_score` DECIMAL(5,2) DEFAULT NULL AFTER `cbt_exam_id`,
                ADD COLUMN `cbt_token` VARCHAR(30) DEFAULT NULL AFTER `cbt_score`,
                ADD COLUMN `payment_status` ENUM('gratis', 'menunggu_pembayaran', 'lunas') NOT NULL DEFAULT 'gratis' AFTER `cbt_token`,
                ADD COLUMN `payment_amount` DECIMAL(10,2) DEFAULT 0.00 AFTER `payment_status`,
                ADD COLUMN `payment_method` VARCHAR(50) DEFAULT NULL AFTER `payment_amount`,
                ADD COLUMN `payment_invoice` VARCHAR(50) DEFAULT NULL AFTER `payment_method`
            ");
        }
    } catch (Exception $e_m2) {}

    try {
        // 2b. ppdb_registrations: exam_date, exam_time, exam_room, exam_session (Jadwal, waktu, dan penempatan ruangan tes masuk offline)
        $cols_ppdb_exam = $pdo->query("SHOW COLUMNS FROM `ppdb_registrations` LIKE 'exam_room'")->fetchAll();
        if (empty($cols_ppdb_exam)) {
            $pdo->exec("
                ALTER TABLE `ppdb_registrations` 
                ADD COLUMN `exam_date` DATE DEFAULT NULL,
                ADD COLUMN `exam_time` VARCHAR(50) DEFAULT NULL,
                ADD COLUMN `exam_room` VARCHAR(100) DEFAULT NULL,
                ADD COLUMN `exam_session` VARCHAR(50) DEFAULT NULL
            ");
        }

        $cols_ppdb_cbt_state = $pdo->query("SHOW COLUMNS FROM `ppdb_registrations` LIKE 'cbt_answers'")->fetchAll();
        if (empty($cols_ppdb_cbt_state)) {
            $pdo->exec("
                ALTER TABLE `ppdb_registrations` 
                ADD COLUMN `cbt_answers` LONGTEXT DEFAULT NULL,
                ADD COLUMN `cbt_doubt_answers` LONGTEXT DEFAULT NULL,
                ADD COLUMN `cbt_start_time` DATETIME DEFAULT NULL,
                ADD COLUMN `cbt_status` ENUM('belum_mulai', 'sedang_mengerjakan', 'selesai') NOT NULL DEFAULT 'belum_mulai'
            ");
        }
    } catch (Exception $e_m2b) {}

    try {
        // 3. mail_archives: source_request_id (Relasi langsung arsip keluar ke permohonan surat TU)
        $cols_arch = $pdo->query("SHOW COLUMNS FROM `mail_archives` LIKE 'source_request_id'")->fetchAll();
        if (empty($cols_arch)) {
            $pdo->exec("ALTER TABLE `mail_archives` ADD COLUMN `source_request_id` INT DEFAULT NULL AFTER `attachment_file`");
        }
    } catch (Exception $e_m3) {}

    try {
        // 4. financial_expenses: source_module & source_ref_id (Integrasi pengeluaran dari Sarpras/Payroll/Perpus)
        $cols_exp = $pdo->query("SHOW COLUMNS FROM `financial_expenses` LIKE 'source_module'")->fetchAll();
        if (empty($cols_exp)) {
            $pdo->exec("
                ALTER TABLE `financial_expenses` 
                ADD COLUMN `source_module` VARCHAR(50) DEFAULT 'manual' AFTER `recorded_by`,
                ADD COLUMN `source_ref_id` INT DEFAULT NULL AFTER `source_module`
            ");
        }
    } catch (Exception $e_m4) {}

    // Seed Ujian CBT Seleksi PPDB jika belum ada
    try {
        $cnt_ppdb_exam = (int)$pdo->query("SELECT COUNT(*) FROM `exams` WHERE `is_ppdb` = 1")->fetchColumn();
        if ($cnt_ppdb_exam === 0) {
            $admin_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'administrator' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            $pdo->exec("
                INSERT INTO `exams` 
                (`title`, `subject`, `category`, `type`, `description`, `duration_minutes`, `passing_grade`, `token`, `randomize_questions`, `hide_answers_until_due`, `due_date`, `teacher_id`, `status`, `is_ppdb`)
                VALUES
                ('Ujian CBT Seleksi Masuk & Minat Bakat PPDB 2026', 'Tes Potensi Akademik', 'ujian_harian', 'ujian', 'Ujian online seleksi penerimaan peserta didik baru (PPDB) menguji kemampuan logika numerik, verbal, dan penalaran umum.', 45, 75, 'PPDB2026', 1, 1, '2026-12-31 23:59:59', $admin_id, 'aktif', 1)
            ");
            $new_ppdb_exam_id = (int)$pdo->lastInsertId();
            if ($new_ppdb_exam_id > 0) {
                $pdo->exec("
                    INSERT INTO `exam_questions` 
                    (`exam_id`, `question_type`, `max_score`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_answer`, `explanation`)
                    VALUES
                    ($new_ppdb_exam_id, 'multiple_choice', 25.00, 'Jika pola deret angka berikut dilanjutkan: 3, 6, 12, 24, ..., berapakah angka berikutnya?', '36', '48', '52', '64', 'B', 'Pola adalah perkalian 2 dari suku sebelumnya: 24 x 2 = 48.'),
                    ($new_ppdb_exam_id, 'multiple_choice', 25.00, 'Sebuah segitiga memiliki panjang alas 10 cm dan tinggi 14 cm. Berapakah luas segitiga tersebut?', '70 cm²', '140 cm²', '35 cm²', '50 cm²', 'A', 'Luas segitiga = 1/2 x alas x tinggi = 1/2 x 10 x 14 = 70 cm².'),
                    ($new_ppdb_exam_id, 'multiple_choice', 25.00, 'Manakah padanan sinonim kata yang paling tepat untuk istilah \'AKUNTABEL\'?', 'Tertutup', 'Dapat dipertanggungjawabkan', 'Berubah-ubah', 'Sederhana', 'B', 'Akuntabel bermakna dapat dipertanggungjawabkan sesuai tata kelola yang terbuka dan terukur.'),
                    ($new_ppdb_exam_id, 'multiple_choice', 25.00, 'Organel sel yang berfungsi utama sebagai tempat respirasi seluler dan menghasilkan energi dalam bentuk ATP adalah...', 'Kloroplas', 'Ribosom', 'Mitokondria', 'Badan Golgi', 'C', 'Mitokondria berfungsi sebagai pusat metabolisme penghasil energi sel (ATP).')
                ");
            }
        }
    } catch (Exception $e_cbt_seed) {}

    // Auto-create tabel password_resets untuk mekanisme pemulihan akun ketat (Google-Grade)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `email` VARCHAR(100) NOT NULL,
                `token` VARCHAR(64) NOT NULL UNIQUE,
                `otp_code` VARCHAR(10) NOT NULL,
                `challenge_digits` VARCHAR(10) DEFAULT NULL,
                `attempts` INT NOT NULL DEFAULT 0,
                `max_attempts` INT NOT NULL DEFAULT 3,
                `status` ENUM('pending', 'verified', 'used', 'expired') NOT NULL DEFAULT 'pending',
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` VARCHAR(255) DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_token` (`token`),
                INDEX `idx_email` (`email`),
                INDEX `idx_user_status` (`user_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e_pwd_res) {}

    // =========================================================
    // MIGRATION v18: KENAIKAN KELAS, KELULUSAN & TRACER STUDY ALUMNI
    // =========================================================
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `academic_promotion_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `action_type` ENUM('promotion', 'graduation') NOT NULL DEFAULT 'promotion',
                `from_class` VARCHAR(50) DEFAULT NULL,
                `to_class` VARCHAR(50) DEFAULT NULL,
                `academic_year` VARCHAR(20) NOT NULL,
                `students_count` INT NOT NULL DEFAULT 0,
                `promoted_count` INT NOT NULL DEFAULT 0,
                `retained_count` INT NOT NULL DEFAULT 0,
                `executed_by` INT NOT NULL,
                `notes` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `alumni_records` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT DEFAULT NULL,
                `nisn` VARCHAR(30) DEFAULT NULL,
                `full_name` VARCHAR(150) NOT NULL,
                `gender` ENUM('L', 'P') DEFAULT 'L',
                `graduation_year` VARCHAR(10) NOT NULL,
                `last_class` VARCHAR(50) DEFAULT NULL,
                `diploma_number` VARCHAR(100) DEFAULT NULL,
                `final_score` DECIMAL(5,2) DEFAULT NULL,
                `phone` VARCHAR(30) DEFAULT NULL,
                `email` VARCHAR(100) DEFAULT NULL,
                `status` ENUM('lulus', 'tidak_lulus') NOT NULL DEFAULT 'lulus',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`graduation_year`),
                INDEX (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `alumni_tracer_studies` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `alumni_id` INT DEFAULT NULL,
                `user_id` INT DEFAULT NULL,
                `full_name` VARCHAR(150) NOT NULL,
                `nisn` VARCHAR(30) DEFAULT NULL,
                `graduation_year` VARCHAR(10) NOT NULL,
                `phone` VARCHAR(30) DEFAULT NULL,
                `email` VARCHAR(100) DEFAULT NULL,
                `current_activity` ENUM('kuliah', 'bekerja', 'wirausaha', 'mencari_kerja', 'lainnya') NOT NULL DEFAULT 'kuliah',
                `campus_name` VARCHAR(150) DEFAULT NULL,
                `campus_major` VARCHAR(150) DEFAULT NULL,
                `degree_level` VARCHAR(20) DEFAULT NULL,
                `admission_track` VARCHAR(50) DEFAULT NULL,
                `company_name` VARCHAR(150) DEFAULT NULL,
                `job_title` VARCHAR(100) DEFAULT NULL,
                `job_sector` VARCHAR(100) DEFAULT NULL,
                `income_range` VARCHAR(50) DEFAULT NULL,
                `business_name` VARCHAR(150) DEFAULT NULL,
                `business_field` VARCHAR(100) DEFAULT NULL,
                `business_revenue` VARCHAR(50) DEFAULT NULL,
                `alignment` ENUM('sangat_selaras', 'selaras', 'kurang_selaras', 'tidak_selaras') DEFAULT 'selaras',
                `testimonial` TEXT DEFAULT NULL,
                `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`graduation_year`),
                INDEX (`current_activity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Seed data sampel alumni dan tracer study jika tabel masih kosong
        $count_alumni = (int)$pdo->query("SELECT COUNT(*) FROM `alumni_records`")->fetchColumn();
        if ($count_alumni === 0) {
            $pdo->exec("
                INSERT INTO `alumni_records` 
                (`nisn`, `full_name`, `gender`, `graduation_year`, `last_class`, `diploma_number`, `final_score`, `phone`, `email`, `status`)
                VALUES 
                ('0041829301', 'Muhammad Fajar Pratama, S.Kom', 'L', '2024', 'XII MIPA 1', 'DN-01/M-SMA/13/0089214', 88.50, '081298765432', 'fajar.pratama@gmail.com', 'lulus'),
                ('0052938411', 'Aisyah Putri Rahmadani', 'P', '2025', 'XII MIPA 2', 'DN-01/M-SMA/14/0091204', 92.40, '085712348899', 'aisyah.putri@yahoo.co.id', 'lulus'),
                ('0049102834', 'Rizky Kurniawan', 'L', '2025', 'XII IPS 1', 'DN-01/M-SMA/14/0091315', 84.20, '082188992211', 'rizky.kurniawan@gmail.com', 'lulus'),
                ('0058291032', 'Nabila Shafa Maharani', 'P', '2025', 'XII MIPA 1', 'DN-01/M-SMA/14/0091420', 89.80, '081377884433', 'nabila.maharani@gmail.com', 'lulus');
            ");
        }

        $count_tracer = (int)$pdo->query("SELECT COUNT(*) FROM `alumni_tracer_studies`")->fetchColumn();
        if ($count_tracer === 0) {
            $pdo->exec("
                INSERT INTO `alumni_tracer_studies`
                (`full_name`, `nisn`, `graduation_year`, `phone`, `email`, `current_activity`, `campus_name`, `campus_major`, `degree_level`, `admission_track`, `company_name`, `job_title`, `job_sector`, `alignment`, `testimonial`)
                VALUES
                ('Muhammad Fajar Pratama, S.Kom', '0041829301', '2024', '081298765432', 'fajar.pratama@gmail.com', 'bekerja', NULL, NULL, NULL, NULL, 'PT Telkom Indonesia Tbk', 'Cloud Software Engineer', 'Teknologi Informasi', 'sangat_selaras', 'Pembekalan logika dan disiplin di sekolah sangat membantu saya bersaing di industri teknologi nasional.'),
                ('Aisyah Putri Rahmadani', '0052938411', '2025', '085712348899', 'aisyah.putri@yahoo.co.id', 'kuliah', 'Universitas Indonesia (UI)', 'Pendidikan Dokter', 'S1', 'SNBP (Prestasi Undangan)', NULL, NULL, NULL, 'sangat_selaras', 'Terima kasih atas bimbingan para guru yang selalu sabar memfasilitasi saya hingga lolos FK UI.'),
                ('Rizky Kurniawan', '0049102834', '2025', '082188992211', 'rizky.kurniawan@gmail.com', 'wirausaha', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'selaras', 'Berkat didikan kewirausahaan di sekolah, kini saya bisa mandiri mengelola bisnis e-commerce.'),
                ('Nabila Shafa Maharani', '0058291032', '2025', '081377884433', 'nabila.maharani@gmail.com', 'kuliah', 'Institut Teknologi Bandung (ITB)', 'Teknik Informatika (STEI)', 'S1', 'SNBT (UTBK)', NULL, NULL, NULL, 'sangat_selaras', 'Fasilitas ujian CBT dan materi belajar sekolah sangat mirip dengan standar UTBK sesungguhnya.');
            ");
        }
    } catch (Exception $e_trc) {}

    // =========================================================
    // MIGRATION v19: EXAM LOCKDOWN, IP RESTRICTION, 2FA & RAPOR P5
    // =========================================================
    // 1. Tambah kolom 2FA di tabel users
    try {
        $c_2fa = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'two_factor_enabled'")->fetch();
        if (!$c_2fa) {
            $pdo->exec("
                ALTER TABLE `users`
                ADD COLUMN `two_factor_secret` VARCHAR(64) DEFAULT NULL AFTER `password`,
                ADD COLUMN `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `two_factor_secret`,
                ADD COLUMN `two_factor_confirmed_at` DATETIME DEFAULT NULL AFTER `two_factor_enabled`
            ");
        }
    } catch (Exception $e_2fa_col) {}

    // 2. Tambah kolom lockdown & IP restriction di tabel exams
    try {
        $c_lock = $pdo->query("SHOW COLUMNS FROM `exams` LIKE 'lockdown_enabled'")->fetch();
        if (!$c_lock) {
            $pdo->exec("
                ALTER TABLE `exams`
                ADD COLUMN `lockdown_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `randomize_questions`,
                ADD COLUMN `allowed_ip_range` VARCHAR(255) DEFAULT NULL AFTER `lockdown_enabled`
            ");
        }
    } catch (Exception $e_exam_col) {}

    // 3. Buat tabel-tabel Modul Projek P5 (Kurikulum Merdeka)
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `p5_projects` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(200) NOT NULL,
                `theme` VARCHAR(100) NOT NULL,
                `description` TEXT NOT NULL,
                `academic_year` VARCHAR(30) NOT NULL DEFAULT '2026/2027',
                `phase` VARCHAR(20) NOT NULL DEFAULT 'Fase E',
                `target_class_ids` VARCHAR(255) DEFAULT NULL,
                `coordinator_teacher_id` INT NOT NULL,
                `status` ENUM('aktif', 'selesai', 'draf') NOT NULL DEFAULT 'aktif',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (`theme`),
                INDEX (`academic_year`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `p5_project_dimensions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `project_id` INT NOT NULL,
                `dimension` VARCHAR(120) NOT NULL,
                `element` VARCHAR(150) NOT NULL,
                `sub_element` VARCHAR(200) NOT NULL,
                `target_capaian` TEXT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`project_id`),
                CONSTRAINT `fk_p5_dim_project` FOREIGN KEY (`project_id`) REFERENCES `p5_projects` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `p5_student_assessments` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `project_id` INT NOT NULL,
                `dimension_id` INT NOT NULL,
                `student_id` INT NOT NULL,
                `predicate` ENUM('BB', 'MB', 'BSH', 'SB') NOT NULL DEFAULT 'BSH',
                `evaluated_by` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_p5_assessment` (`project_id`, `dimension_id`, `student_id`),
                INDEX (`student_id`),
                INDEX (`project_id`),
                CONSTRAINT `fk_p5_as_project` FOREIGN KEY (`project_id`) REFERENCES `p5_projects` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_p5_as_dim` FOREIGN KEY (`dimension_id`) REFERENCES `p5_project_dimensions` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_p5_as_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `p5_student_notes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `project_id` INT NOT NULL,
                `student_id` INT NOT NULL,
                `facilitator_notes` TEXT DEFAULT NULL,
                `evaluated_by` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_p5_note` (`project_id`, `student_id`),
                CONSTRAINT `fk_p5_note_project` FOREIGN KEY (`project_id`) REFERENCES `p5_projects` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_p5_note_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Seed data sampel Projek P5 jika masih kosong
        $count_p5 = (int)$pdo->query("SELECT COUNT(*) FROM `p5_projects`")->fetchColumn();
        if ($count_p5 === 0) {
            $coordinator_id = (int)$pdo->query("SELECT id FROM `users` WHERE role IN ('guru', 'administrator') ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;
            
            $pdo->exec("
                INSERT INTO `p5_projects` 
                (`title`, `theme`, `description`, `academic_year`, `phase`, `target_class_ids`, `coordinator_teacher_id`, `status`)
                VALUES 
                ('Pengolahan Sampah Organik Menjadi Kompos Bernilai Ekonomis & Bank Sampah Sekolah', 'Gaya Hidup Berkelanjutan', 'Projek ini bertujuan membangun kesadaran kritis peserta didik mengenai dampak limbah sekolah dan rumah tangga terhadap biosfer bumi, serta mempraktikkan biokonversi sampah organik menjadi pupuk kompos berkualitas tinggi dan manajemen bank sampah digital.', '2026/2027', 'Fase E', '1,2,3', $coordinator_id, 'aktif'),
                ('Harmoni Keberagaman: Festival Kuliner & Pameran Budaya Nusantara', 'Bhinneka Tunggal Ika', 'Projek kebinekaan untuk menumbuhkan apresiasi terhadap keanekaragaman etnis, kuliner, dan kearifan tradisional seluruh kepulauan Indonesia melalui pameran interaktif dan dokumenter kebudayaan.', '2026/2027', 'Fase E', '1,2', $coordinator_id, 'aktif')
            ");

            $proj1_id = (int)$pdo->query("SELECT id FROM `p5_projects` ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($proj1_id > 0) {
                $pdo->exec("
                    INSERT INTO `p5_project_dimensions` 
                    (`project_id`, `dimension`, `element`, `sub_element`, `target_capaian`)
                    VALUES 
                    ($proj1_id, 'Beriman, Bertakwa kepada Tuhan YME, dan Berakhlak Mulia', 'Akhlak kepada Alam', 'Menjaga Lingkungan Alam Sekitar', 'Mewujudkan rasa syukur dengan membangun kesadaran peduli lingkungan serta mengolah sampah organik secara mandiri.'),
                    ($proj1_id, 'Bergotong Royong', 'Kolaborasi', 'Kerja Sama & Koordinasi Sosial', 'Menyelaraskan tindakan sendiri dengan tindakan kelompok untuk melaksanakan kegiatan pengomposan sampah.'),
                    ($proj1_id, 'Bernalar Kritis', 'Memperoleh dan Memproses Informasi', 'Mengidentifikasi & Menganalisis Masalah', 'Mengidentifikasi akar permasalahan penumpukan limbah dan merumuskan hipotesis biokatalisator kompos secara ilmiah.'),
                    ($proj1_id, 'Kreatif', 'Menghasilkan Gagasan Orisinal', 'Inovasi Produk Turunan Kompos', 'Menghasilkan gagasan orisinal dalam pengemasan pupuk kompos organik serta strategi sosialisasi bank sampah.')
                ");

                // Seed penilaian untuk semua siswa yang ada
                $students = $pdo->query("SELECT id FROM `users` WHERE role = 'siswa' LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
                $dim_ids = $pdo->query("SELECT id FROM `p5_project_dimensions` WHERE project_id = $proj1_id")->fetchAll(PDO::FETCH_COLUMN);
                
                $predicates_pool = ['BSH', 'SB', 'BSH', 'MB', 'SB'];
                $notes_pool = [
                    'Menunjukkan antusiasme tinggi saat praktik biokonversi dan selalu menjadi inisiator dalam pembagian tugas kelompok pengomposan.',
                    'Sangat konsisten dalam menjaga kebersihan area kerja projek dan mampu memberikan solusi cerdas saat proses fermentasi kompos berlangsung.',
                    'Aktif berkolaborasi dalam tim, memiliki kepedulian lingkungan yang kuat dan berani memaparkan hasil projek di hadapan audiens.',
                    'Mampu menganalisis data perbandingan kelembapan pupuk dengan cermat serta menunjukkan kemandirian kerja yang membanggakan.'
                ];

                foreach ($students as $stu_idx => $stu_id) {
                    foreach ($dim_ids as $d_idx => $dim_id) {
                        $p_val = $predicates_pool[($stu_idx + $d_idx) % count($predicates_pool)];
                        $pdo->exec("
                            INSERT IGNORE INTO `p5_student_assessments` 
                            (`project_id`, `dimension_id`, `student_id`, `predicate`, `evaluated_by`)
                            VALUES ($proj1_id, $dim_id, $stu_id, '$p_val', $coordinator_id)
                        ");
                    }
                    $note_txt = $notes_pool[$stu_idx % count($notes_pool)];
                    $note_esc = $pdo->quote($note_txt);
                    $pdo->exec("
                        INSERT IGNORE INTO `p5_student_notes`
                        (`project_id`, `student_id`, `facilitator_notes`, `evaluated_by`)
                        VALUES ($proj1_id, $stu_id, $note_esc, $coordinator_id)
                    ");
                }
            }
        }
    } catch (Exception $e_p5_seed) {}

    // =========================================================
    // MIGRATION v20: MODUL EKSTRAKURIKULER & PRESTASI SISWA TERPADU
    // =========================================================
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `extracurriculars` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `code` VARCHAR(30) UNIQUE NOT NULL,
                `category` ENUM('wajib', 'pilihan') NOT NULL DEFAULT 'pilihan',
                `description` TEXT DEFAULT NULL,
                `leader_instructor_id` INT DEFAULT NULL,
                `schedule_day` ENUM('Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu') NOT NULL DEFAULT 'Jumat',
                `schedule_time` VARCHAR(50) DEFAULT '15:30 - 17:00',
                `location` VARCHAR(100) DEFAULT 'Lapangan Utama',
                `max_members` INT DEFAULT 50,
                `logo_icon` VARCHAR(50) DEFAULT 'fa-futbol',
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (`category`),
                INDEX (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `extracurricular_members` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `extracurricular_id` INT NOT NULL,
                `student_id` INT NOT NULL,
                `role` ENUM('anggota', 'ketua', 'wakil_ketua', 'sekretaris', 'bendahara') NOT NULL DEFAULT 'anggota',
                `status` ENUM('menunggu', 'aktif', 'nonaktif', 'ditolak') NOT NULL DEFAULT 'aktif',
                `grade_predicate` ENUM('A', 'B', 'C', 'D') DEFAULT 'A',
                `grade_notes` TEXT DEFAULT NULL,
                `joined_at` DATE NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_ekskul_student` (`extracurricular_id`, `student_id`),
                INDEX (`student_id`),
                INDEX (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `extracurricular_activities` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `extracurricular_id` INT NOT NULL,
                `activity_date` DATE NOT NULL,
                `activity_title` VARCHAR(200) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `documentation_photo` VARCHAR(255) DEFAULT NULL,
                `instructor_id` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`extracurricular_id`),
                INDEX (`activity_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `extracurricular_attendance` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `activity_id` INT NOT NULL,
                `student_id` INT NOT NULL,
                `status` ENUM('hadir', 'izin', 'sakit', 'alpa') NOT NULL DEFAULT 'hadir',
                `notes` VARCHAR(255) DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_act_student_att` (`activity_id`, `student_id`),
                INDEX (`student_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `extracurricular_achievements` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `extracurricular_id` INT DEFAULT NULL,
                `student_id` INT NOT NULL,
                `competition_name` VARCHAR(200) NOT NULL,
                `level` ENUM('sekolah', 'kecamatan', 'kabupaten', 'provinsi', 'nasional', 'internasional') NOT NULL DEFAULT 'kabupaten',
                `rank_achieved` VARCHAR(100) NOT NULL,
                `achievement_date` DATE NOT NULL,
                `certificate_doc` VARCHAR(255) DEFAULT NULL,
                `description` TEXT DEFAULT NULL,
                `verified_by` INT DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (`student_id`),
                INDEX (`extracurricular_id`),
                INDEX (`level`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // Seed data sampel Ekstrakurikuler jika masih kosong
        $count_ekskul = (int)$pdo->query("SELECT COUNT(*) FROM `extracurriculars`")->fetchColumn();
        if ($count_ekskul === 0) {
            $teacher_id = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'guru' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 3;
            $admin_id   = (int)$pdo->query("SELECT id FROM `users` WHERE role = 'administrator' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1;

            $pdo->exec("
                INSERT INTO `extracurriculars`
                (`name`, `code`, `category`, `description`, `leader_instructor_id`, `schedule_day`, `schedule_time`, `location`, `max_members`, `logo_icon`, `is_active`)
                VALUES
                ('Pramuka (Gugus Depan Inti)', 'EKS-PRA', 'wajib', 'Pendidikan kepanduan untuk pembentukan karakter, kedisiplinan, cinta tanah air, dan keterampilan bertahan hidup di alam terbuka.', $teacher_id, 'Jumat', '14:00 - 16:00', 'Lapangan Utama & Aula Terbuka', 120, 'fa-campground', 1),
                ('Paskibra (Pasukan Pengibar Bendera)', 'EKS-PAS', 'pilihan', 'Pembinaan baris-berbaris presisi, ketahanan fisik, formasi upacara kenegaraan, dan pembentukan kepemimpinan patriotik.', $teacher_id, 'Sabtu', '07:30 - 10:00', 'Lapangan Upacara', 45, 'fa-flag', 1),
                ('PMR & Kesehatan Remaja', 'EKS-PMR', 'pilihan', 'Pelatihan pertolongan pertama pada kecelakaan (PPPK), donor darah, mitigasi bencana, dan promosi hidup sehat civitas sekolah.', $teacher_id, 'Kamis', '15:30 - 17:00', 'Ruang UKS & Laboratorium Biologi', 40, 'fa-kit-medical', 1),
                ('Futsal & Sepak Bola', 'EKS-FUT', 'pilihan', 'Pengembangan teknik dasar futsal modern, strategi permainan tim, dan pembinaan atlet untuk turnamen antar-pelajar.', $teacher_id, 'Selasa', '16:00 - 18:00', 'Lapangan Olahraga Indoor', 35, 'fa-futbol', 1),
                ('Klub IT, Robotika & Coding', 'EKS-ROB', 'pilihan', 'Eksplorasi pemrograman web, algoritma kompetitif, IoT mikrokontroler, dan perancangan robotik untuk kompetisi sains remaja.', $admin_id, 'Rabu', '15:30 - 17:00', 'Lab Komputer 1', 30, 'fa-laptop-code', 1),
                ('English Debate & Speech Club', 'EKS-ENG', 'pilihan', 'Asah kemampuan berargumen dalam Bahasa Inggris standar parlemen Asia/British, public speaking, dan storytelling kompetitif.', $teacher_id, 'Senin', '15:30 - 17:00', 'Ruang Multimedia Bahasa', 25, 'fa-comments', 1),
                ('Seni Tari & Karawitan Tradisional', 'EKS-TAR', 'pilihan', 'Pelestarian seni tari kreasi nusantara, olah gerak ritmis, serta ensemble musik tradisional gamelan dan angklung.', $teacher_id, 'Jumat', '15:30 - 17:30', 'Sanggar Seni Budaya', 30, 'fa-masks-theater', 1),
                ('Bola Basket Club', 'EKS-BAS', 'pilihan', 'Latihan intensif fundamental dribble, shooting, defense, dan partisipasi pada kejuaraan DBL tingkat pelajar.', $teacher_id, 'Sabtu', '15:30 - 17:30', 'Lapangan Basket Outdoor', 30, 'fa-basketball', 1);
            ");

            // Seed keanggotaan siswa demo (ID: 5 Ahmad Fauzi & siswa lainnya)
            $students = $pdo->query("SELECT id FROM `users` WHERE role = 'siswa' ORDER BY id ASC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
            $ekskul_ids = $pdo->query("SELECT id FROM `extracurriculars` ORDER BY id ASC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($students) && !empty($ekskul_ids)) {
                $pra_id = $ekskul_ids[0]; // Pramuka
                $fut_id = $ekskul_ids[3] ?? $ekskul_ids[0]; // Futsal
                $rob_id = $ekskul_ids[4] ?? $ekskul_ids[0]; // Robotik

                foreach ($students as $idx => $sid) {
                    // Semua siswa masuk Pramuka (Wajib)
                    $role_pra = ($idx === 0) ? 'ketua' : 'anggota';
                    $pdo->exec("
                        INSERT IGNORE INTO `extracurricular_members` 
                        (`extracurricular_id`, `student_id`, `role`, `status`, `grade_predicate`, `grade_notes`, `joined_at`)
                        VALUES ($pra_id, $sid, '$role_pra', 'aktif', 'A', 'Sangat aktif dalam kegiatan perkemahan dan memiliki jiwa kepemimpinan regu yang unggul.', '2026-07-15')
                    ");

                    // Siswa pertama dan kedua ikut Robotika
                    if ($idx < 2 && isset($rob_id)) {
                        $role_rob = ($idx === 0) ? 'ketua' : 'anggota';
                        $pdo->exec("
                            INSERT IGNORE INTO `extracurricular_members` 
                            (`extracurricular_id`, `student_id`, `role`, `status`, `grade_predicate`, `grade_notes`, `joined_at`)
                            VALUES ($rob_id, $sid, '$role_rob', 'aktif', 'A', 'Mampu merakit sirkuit sensor mikrokontroler dan menunjukkan logika pemrograman yang cepat.', '2026-07-20')
                        ");
                    }

                    // Siswa ketiga dan keempat ikut Futsal
                    if ($idx >= 2 && isset($fut_id)) {
                        $pdo->exec("
                            INSERT IGNORE INTO `extracurricular_members` 
                            (`extracurricular_id`, `student_id`, `role`, `status`, `grade_predicate`, `grade_notes`, `joined_at`)
                            VALUES ($fut_id, $sid, 'anggota', 'aktif', 'B', 'Memiliki stamina fisik dan kerja sama tim yang baik dalam koordinasi pertahanan.', '2026-07-20')
                        ");
                    }
                }

                // Seed aktivitas pertemuan latihan untuk Pramuka & Robotika
                $pdo->exec("
                    INSERT INTO `extracurricular_activities`
                    (`extracurricular_id`, `activity_date`, `activity_title`, `description`, `instructor_id`)
                    VALUES
                    ($pra_id, DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY), 'Latihan Pionering & Sandi Morse', 'Praktik mendirikan menara pandang dengan tali-temali dan pengiriman pesan rahasia sandi rumput.', $teacher_id),
                    ($pra_id, DATE_SUB(CURRENT_DATE(), INTERVAL 14 DAY), 'Navigasi Darat & Peta Kompas', 'Pembacaan koordinat azimut dan latihan orientasi medan penjelajahan alam.', $teacher_id),
                    ($rob_id, DATE_SUB(CURRENT_DATE(), INTERVAL 3 DAY), 'Pemrograman Robot Line Follower & Sensor Ultrasonik', 'Kalibrasi sensor inframerah pembaca garis dan logika kendali motor PWM.', $admin_id)
                ");

                $act1_id = (int)$pdo->query("SELECT id FROM `extracurricular_activities` WHERE extracurricular_id = $pra_id ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($act1_id > 0) {
                    foreach ($students as $sid) {
                        $pdo->exec("
                            INSERT IGNORE INTO `extracurricular_attendance`
                            (`activity_id`, `student_id`, `status`, `notes`)
                            VALUES ($act1_id, $sid, 'hadir', 'Hadir tepat waktu dan membawa perlengkapan tongkat.')
                        ");
                    }
                }

                // Seed data Prestasi & Kejuaraan Ekskul
                $main_student_id = $students[0];
                $pdo->exec("
                    INSERT INTO `extracurricular_achievements`
                    (`extracurricular_id`, `student_id`, `competition_name`, `level`, `rank_achieved`, `achievement_date`, `description`, `verified_by`)
                    VALUES
                    ($rob_id, $main_student_id, 'Olimpiade Robotika & Inovasi Teknologi Pelajar Nasional (ORITN)', 'nasional', 'Medali Perak (Juara 2)', '2026-08-25', 'Berhasil merancang prototipe robot pemilah sampah otomatis berbasis sensor visi AI.', $admin_id),
                    ($pra_id, $main_student_id, 'Lomba Tingkat Regu Pramuka Penggalang & Penegak (LT-IV)', 'provinsi', 'Juara 1 Regu Berprestasi Tinggi', '2026-06-18', 'Memimpin kontingen sekolah menjuarai cabang pionering cepat dan ketangkasan navigasi malam.', $teacher_id),
                    ($fut_id, $main_student_id, 'Turnamen Futsal Piala Walikota Antar-SMA', 'kabupaten', 'Juara 2 (Runner-Up)', '2026-05-12', 'Meraih posisi kedua dan penghargaan Tim Paling Fairplay.', $teacher_id)
                ");
            }
        }
    } catch (Exception $e_ekskul_seed) {}

    $_SESSION['db_migrated_v20'] = true;
    @file_put_contents($migrationLockFile, date('Y-m-d H:i:s'));
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
 * Dapatkan badge style untuk status kesiswaan / civitas
 */
function getUserStatusBadge(string $status): string {
    switch ($status) {
        case 'aktif':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400';
        case 'skorsing':
            return 'border-amber-500/30 bg-amber-500/10 text-amber-400';
        case 'dikeluarkan':
            return 'border-rose-500/40 bg-rose-500/20 text-rose-300 font-bold';
        case 'mutasi':
            return 'border-slate-500/30 bg-slate-500/10 text-slate-400';
        case 'lulus':
            return 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300';
        default:
            return 'border-white/10 bg-white/5 text-slate-300';
    }
}

/**
 * Dapatkan label Bahasa Indonesia untuk status kesiswaan / civitas
 */
function getUserStatusLabel(string $status): string {
    switch ($status) {
        case 'aktif':
            return 'Aktif';
        case 'skorsing':
            return 'Diskors';
        case 'dikeluarkan':
            return 'Dikeluarkan (DO)';
        case 'mutasi':
            return 'Mutasi Keluar';
        case 'lulus':
            return 'Lulus / Alumni';
        default:
            return ucfirst($status);
    }
}

/**
 * Daftar kategori asesmen resmi: Kurikulum Merdeka & K13 (SD, SMP, SMA/SMK)
 */
const EXAM_CATEGORIES = [
    // Standar Kurikulum Merdeka & K13 Terkini
    'sts'              => ['label' => 'Sumatif Tengah Semester (STS / UTS)',         'type' => 'ujian',   'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300',   'icon' => '<i class="fa-solid fa-file-pen"></i>',         'level' => 'SD, SMP, SMA/SMK'],
    'sas'              => ['label' => 'Sumatif Akhir Semester (SAS / PAS)',          'type' => 'ujian',   'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',         'icon' => '<i class="fa-solid fa-graduation-cap"></i>',   'level' => 'SD, SMP, SMA/SMK'],
    'sat'              => ['label' => 'Sumatif Akhir Tahun (SAT / PAT / UKK)',       'type' => 'ujian',   'badge' => 'border-indigo-500/30 bg-indigo-500/10 text-indigo-300',     'icon' => '<i class="fa-solid fa-award"></i>',            'level' => 'SD, SMP, SMA/SMK'],
    'slm'              => ['label' => 'Sumatif Lingkup Materi / Ulangan Harian',     'type' => 'ujian',   'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',       'icon' => '<i class="fa-solid fa-bolt"></i>',             'level' => 'SD, SMP, SMA/SMK'],
    'us'               => ['label' => 'Asesmen Sekolah / Ujian Sekolah (Kelulusan)', 'type' => 'ujian',   'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '<i class="fa-solid fa-stamp"></i>',            'level' => 'Tingkat Akhir'],
    'tryout'           => ['label' => 'Try Out / Simulasi Ujian (SNBT / ANBK)',      'type' => 'latihan', 'badge' => 'border-sky-500/30 bg-sky-500/10 text-sky-300',             'icon' => '<i class="fa-solid fa-laptop-code"></i>',      'level' => 'SD, SMP, SMA/SMK'],
    'kuis'             => ['label' => 'Latihan Mandiri & Kuis Formatif',             'type' => 'latihan', 'badge' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',           'icon' => '<i class="fa-solid fa-circle-question"></i>',  'level' => 'SD, SMP, SMA/SMK'],
    // Backward Compatibility Aliases:
    'uts'              => ['label' => 'Ujian Tengah Semester (UTS)',                 'type' => 'ujian',   'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300',   'icon' => '<i class="fa-solid fa-file-pen"></i>',         'level' => 'SD, SMP, SMA/SMK'],
    'ukk'              => ['label' => 'Ujian Kenaikan Kelas (UKK)',                  'type' => 'ujian',   'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',         'icon' => '<i class="fa-solid fa-graduation-cap"></i>',   'level' => 'SD, SMP, SMA/SMK'],
    'ujian_harian'     => ['label' => 'Ujian Harian (Fleksibel)',                    'type' => 'ujian',   'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',       'icon' => '<i class="fa-solid fa-bolt"></i>',             'level' => 'SD, SMP, SMA/SMK'],
    'latihan_harian'   => ['label' => 'Latihan Harian',                              'type' => 'latihan', 'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',         'icon' => '<i class="fa-solid fa-book-open"></i>',        'level' => 'SD, SMP, SMA/SMK'],
    'latihan_mingguan' => ['label' => 'Latihan Mingguan',                            'type' => 'latihan', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '<i class="fa-solid fa-calendar-check"></i>',   'level' => 'SD, SMP, SMA/SMK'],
    'latihan_bulanan'  => ['label' => 'Latihan Bulanan',                             'type' => 'latihan', 'badge' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',           'icon' => '<i class="fa-solid fa-bullseye"></i>',         'level' => 'SD, SMP, SMA/SMK'],
];

/**
 * Jenjang Pendidikan yang Didukung Sistem
 */
const EXAM_EDUCATION_LEVELS = [
    'semua' => ['label' => 'Semua Jenjang',                 'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300',     'icon' => '<i class="fa-solid fa-layer-group"></i>'],
    'sd'    => ['label' => 'SD (Sekolah Dasar)',             'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '<i class="fa-solid fa-shapes"></i>'],
    'smp'   => ['label' => 'SMP (Sekolah Menengah Pertama)', 'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',       'icon' => '<i class="fa-solid fa-book"></i>'],
    'sma'   => ['label' => 'SMA (Sekolah Menengah Atas)',    'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300',   'icon' => '<i class="fa-solid fa-graduation-cap"></i>'],
    'smk'   => ['label' => 'SMK (Sekolah Menengah Kejuruan)','badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',     'icon' => '<i class="fa-solid fa-gears"></i>'],
];

/**
 * Tipe Butir Soal Asesmen Standar Nasional & AKM
 */
const EXAM_QUESTION_TYPES = [
    'multiple_choice'         => ['label' => 'Pilihan Ganda Tunggal (A-E)',         'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',       'icon' => '<i class="fa-regular fa-circle-dot"></i>',               'desc' => 'Siswa memilih 1 opsi jawaban paling tepat.'],
    'multiple_choice_complex' => ['label' => 'Pilihan Ganda Kompleks (AKM Centang)', 'badge' => 'border-indigo-500/30 bg-indigo-500/10 text-indigo-300',   'icon' => '<i class="fa-solid fa-square-check"></i>',                'desc' => 'Siswa dapat mencentang lebih dari satu jawaban benar.'],
    'true_false'              => ['label' => 'Benar / Salah (Pernyataan Logika)',    'badge' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',       'icon' => '<i class="fa-solid fa-arrows-split-up-and-left"></i>',  'desc' => 'Siswa menentukan pernyataan bernilai Benar atau Salah.'],
    'short_answer'            => ['label' => 'Isian Singkat (Penilaian Otomatis)',  'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '<i class="fa-solid fa-i-cursor"></i>',                 'desc' => 'Jawaban berupa kata kunci/angka yang dinilai sistem otomatis.'],
    'essay'                   => ['label' => 'Esai / Uraian (Penilaian Guru)',       'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300',   'icon' => '<i class="fa-solid fa-pen-nib"></i>',                  'desc' => 'Jawaban teks panjang yang dinilai secara manual oleh guru.'],
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

function getExamLevelLabel(string $level): string {
    return EXAM_EDUCATION_LEVELS[$level]['label'] ?? strtoupper($level);
}

function getExamLevelBadge(string $level): string {
    return EXAM_EDUCATION_LEVELS[$level]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getExamQuestionTypeLabel(string $type): string {
    return EXAM_QUESTION_TYPES[$type]['label'] ?? ucfirst(str_replace('_', ' ', $type));
}

function getExamQuestionTypeBadge(string $type): string {
    return EXAM_QUESTION_TYPES[$type]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

/**
 * Helper Presensi Siswa
 */
const ATTENDANCE_STATUSES = [
    'hadir'     => ['label' => 'Hadir',     'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '<i class="fa-solid fa-circle-check"></i>'],
    'terlambat' => ['label' => 'Terlambat', 'badge' => 'border-orange-500/30 bg-orange-500/10 text-orange-300',   'icon' => '<i class="fa-solid fa-clock"></i>'],
    'sakit'     => ['label' => 'Sakit',     'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',     'icon' => '<i class="fa-solid fa-notes-medical"></i>'],
    'izin'      => ['label' => 'Izin',      'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',       'icon' => '<i class="fa-solid fa-envelope"></i>'],
    'alpa'      => ['label' => 'Alpa',      'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',       'icon' => '<i class="fa-solid fa-circle-xmark"></i>'],
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
 * -3 fix: Sanitasi konten HTML agar aman dari XSS, attribute injection, and protocol bypass
 */
function sanitizeRichTextHtml(?string $content, bool $allowImages = false): string {
    if (empty($content)) return '';
    if ($content === strip_tags($content)) {
        return nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
    }

    $allowedTags = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li',
        'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'code',
        'pre', 'hr', 'span', 'div', 'table', 'thead', 'tbody', 'tr', 'th', 'td'
    ];
    if ($allowImages) {
        $allowedTags[] = 'img';
    }

    if (class_exists('DOMDocument')) {
        $prevErrors = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="utf-8" ?><div>' . $content . '</div>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        $cleanNode = function(DOMNode $node) use (&$cleanNode, $allowedTags, $allowImages) {
            if ($node->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($node->nodeName);
                if (!in_array($tagName, $allowedTags, true)) {
                    $node->parentNode->removeChild($node);
                    return;
                }

                if ($node->hasAttributes()) {
                    $toRemove = [];
                    foreach ($node->attributes as $attr) {
                        $attrName = strtolower($attr->nodeName);
                        $attrVal = trim($attr->nodeValue);
                        $isAllowed = false;

                        if (in_array($attrName, ['class', 'id', 'title', 'alt'], true)) {
                            $isAllowed = !preg_match('/[<>"\']/', $attrVal);
                        } elseif ($tagName === 'a' && $attrName === 'href') {
                            $decoded = html_entity_decode($attrVal, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $normalized = preg_replace('/[\x00-\x20\s]+/', '', strtolower($decoded));
                            if (preg_match('#^(https?://|mailto:|tel:|#|/)#i', $attrVal) && !preg_match('#^(javascript|data|vbscript):#i', $normalized)) {
                                $isAllowed = true;
                            }
                        } elseif ($tagName === 'a' && in_array($attrName, ['target', 'rel'], true)) {
                            $isAllowed = true;
                        } elseif ($tagName === 'img' && $allowImages && $attrName === 'src') {
                            $decoded = html_entity_decode($attrVal, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            $normalized = preg_replace('/[\x00-\x20\s]+/', '', strtolower($decoded));
                            if (preg_match('#^(https?://|uploads/|/|\./)#i', $attrVal) && !preg_match('#^(javascript|data|vbscript):#i', $normalized)) {
                                $isAllowed = true;
                            }
                        } elseif ($tagName === 'img' && in_array($attrName, ['width', 'height', 'loading'], true)) {
                            $isAllowed = true;
                        } elseif (in_array($attrName, ['colspan', 'rowspan'], true) && in_array($tagName, ['td', 'th'], true)) {
                            $isAllowed = ctype_digit($attrVal);
                        }

                        if (!$isAllowed || strpos($attrName, 'on') === 0 || $attrName === 'style') {
                            $toRemove[] = $attr->nodeName;
                        }
                    }
                    foreach ($toRemove as $name) {
                        $node->removeAttribute($name);
                    }
                }
            }

            $children = [];
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
            foreach ($children as $child) {
                $cleanNode($child);
            }
        };

        $wrapper = $dom->getElementsByTagName('div')->item(0);
        if ($wrapper) {
            $cleanNode($wrapper);
            $output = '';
            foreach ($wrapper->childNodes as $child) {
                $output .= $dom->saveHTML($child);
            }
            return $output;
        }
    }

    $allowed_tags_str = '<' . implode('><', $allowedTags) . '>';
    $clean = strip_tags($content, $allowed_tags_str);
    $clean = preg_replace('/\s*on\w+\s*=\s*(["\']).*?\1/i', '', $clean);
    $clean = preg_replace('/\s*on\w+\s*=\s*[^>\s]+/i', '', $clean);
    $clean = preg_replace('/\s*style\s*=\s*(["\']).*?\1/i', '', $clean);
    $clean = preg_replace('/href\s*=\s*(["\'])\s*(?:javascript|data|vbscript):[^"\']*?\1/i', 'href="#"', $clean);
    return $clean;
}

function sanitizeAnnouncementHtml(?string $content): string {
    return sanitizeRichTextHtml($content, false);
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
 * Helper Berita & Artikel Sekolah (News System)
 */
const NEWS_CATEGORIES = [
    'kegiatan'  => ['label' => 'Kegiatan Sekolah', 'badge' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',       'icon' => '<i class="fa-solid fa-calendar-check"></i>'],
    'prestasi'  => ['label' => 'Prestasi & Lomba',  'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',     'icon' => '<i class="fa-solid fa-trophy"></i>'],
    'akademik'  => ['label' => 'Akademik & Riset',  'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',       'icon' => '<i class="fa-solid fa-book-open"></i>'],
    'artikel'   => ['label' => 'Opini & Artikel Guru', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '<i class="fa-solid fa-pen-nib"></i>'],
    'fasilitas' => ['label' => 'Sarana & Fasilitas', 'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300', 'icon' => '<i class="fa-solid fa-building-columns"></i>'],
    'umum'      => ['label' => 'Berita Umum',       'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300',     'icon' => '<i class="fa-solid fa-newspaper"></i>'],
];

const NEWS_STATUSES = [
    'draft'     => ['label' => 'Draf',                 'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300',   'icon' => '<i class="fa-solid fa-file-pen"></i>'],
    'pending'   => ['label' => 'Menunggu Persetujuan', 'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',   'icon' => '<i class="fa-solid fa-clock-rotate-left"></i>'],
    'published' => ['label' => 'Diterbitkan',          'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '<i class="fa-solid fa-circle-check"></i>'],
    'rejected'  => ['label' => 'Ditolak / Perlu Revisi', 'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',   'icon' => '<i class="fa-solid fa-triangle-exclamation"></i>'],
];

function getNewsCategoryLabel(string $category): string {
    return NEWS_CATEGORIES[$category]['label'] ?? ucfirst($category);
}

function getNewsCategoryBadge(string $category): string {
    return NEWS_CATEGORIES[$category]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getNewsCategoryIcon(string $category): string {
    return NEWS_CATEGORIES[$category]['icon'] ?? '<i class="fa-solid fa-newspaper"></i>';
}

function getNewsStatusLabel(string $status): string {
    return NEWS_STATUSES[$status]['label'] ?? ucfirst($status);
}

function getNewsStatusBadge(string $status): string {
    return NEWS_STATUSES[$status]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getPendingNewsCount(PDO $pdo): int {
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM `news` WHERE `status` = 'pending'")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function generateNewsSlug(string $title, PDO $pdo, int $excludeId = 0): string {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
    if (empty($slug)) {
        $slug = 'berita-' . time();
    }
    $originalSlug = $slug;
    $counter = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM news WHERE slug = ? AND id != ? LIMIT 1");
        $stmt->execute([$slug, $excludeId]);
        if (!$stmt->fetch()) {
            break;
        }
        $slug = $originalSlug . '-' . $counter;
        $counter++;
    }
    return $slug;
}

function sanitizeNewsHtml(?string $content): string {
    return sanitizeRichTextHtml($content, true);
}

/**
 * Mengambil informasi buku perpustakaan yang ditautkan ke artikel berita
 */
function getNewsLinkedBook(PDO $pdo, ?int $bookId): ?array {
    if (!$bookId || $bookId <= 0) return null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM library_books WHERE id = ? LIMIT 1");
        $stmt->execute([$bookId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Mengambil informasi agenda kalender akademik yang ditautkan ke artikel berita
 */
function getNewsLinkedEvent(PDO $pdo, ?int $eventId): ?array {
    if (!$eventId || $eventId <= 0) return null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM calendar_events WHERE id = ? LIMIT 1");
        $stmt->execute([$eventId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Mengambil informasi rekam prestasi siswa (BK) yang ditautkan ke artikel berita
 */
function getNewsLinkedCounseling(PDO $pdo, ?int $counselingId): ?array {
    if (!$counselingId || $counselingId <= 0) return null;
    try {
        $stmt = $pdo->prepare("
            SELECT cr.*, u.name as student_name, u.nisn, c.name as class_name 
            FROM counseling_records cr
            LEFT JOIN users u ON cr.student_id = u.id
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE cr.id = ? LIMIT 1
        ");
        $stmt->execute([$counselingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
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
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        // -14 fix: Set secure cookie parameters (httponly, samesite Lax, dan secure saat HTTPS)
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $isHttps,
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

function csrfInput(): string {
    return csrfField();
}

function csrfTokenInput(): string {
    return csrfField();
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
function getSchoolSettings(PDO $pdo, bool $forceRefresh = false): array {
    // -12 fix: In-memory static cache untuk menghindari query SQL berulang pada request yang sama
    static $cached_settings = null;
    if ($cached_settings !== null && !$forceRefresh) {
        return $cached_settings;
    }
    $defaults = [
        'school_name'         => 'SMA Bina Bangsa Nusantara',
        'school_address'      => 'Jl. Pendidikan Nasional No. 45, Kebayoran Baru, Jakarta',
        'school_phone'        => '(021) 789-0123',
        'school_email'        => 'info@binabangsa.sch.id',
        'school_website'      => 'https://binabangsa.sch.id',
        'headmaster_name'     => 'Dr. H. Bambang Sudirman, M.Pd',
        'headmaster_nip'      => '19750812 199903 1 002',
        'headmaster_title'    => 'Kepala Sekolah',
        'headmaster_signature_image' => 'uploads/signatures/default_headmaster_ttd.svg',
        'tu_head_name'        => 'Hj. Siti Rahmawati, S.Sos',
        'tu_head_nip'         => '19800415 200501 2 007',
        'tu_head_title'       => 'Kepala Bagian Tata Usaha',
        'tu_head_signature_image' => 'uploads/signatures/default_tu_ttd.svg',
        'bk_coordinator_name' => 'Ahmad Rizky, S.Psi., M.Pd',
        'bk_coordinator_nip'  => '19850612 201001 1 018',
        'bk_coordinator_title'=> 'Koordinator Bimbingan Konseling',
        'bk_coordinator_signature_image' => 'uploads/signatures/default_bk_ttd.svg',
        'treasurer_name'      => 'Dewi Lestari, S.E',
        'treasurer_nip'       => '19880920 201201 2 015',
        'treasurer_title'     => 'Bendahara Sekolah',
        'treasurer_signature_image' => 'uploads/signatures/default_tu_ttd.svg',
        'curriculum_head_name'=> 'Muhammad Fauzi, M.Pd',
        'curriculum_head_nip' => '19820311 200801 1 012',
        'curriculum_head_title'=> 'Waka Bidang Kurikulum',
        'curriculum_head_signature_image' => 'uploads/signatures/default_headmaster_ttd.svg',
        'library_head_name'   => 'Dra. Hj. Nurul Hidayati, M.Pd',
        'library_head_nip'    => '19780512 200501 2 006',
        'library_head_title'  => 'Kepala Perpustakaan Sekolah',
        'library_head_signature_image' => 'uploads/signatures/default_library_ttd.svg',
        'school_stamp_image'  => 'uploads/signatures/default_stamp.svg',
        'show_school_stamp'   => '1',
        'signature_mode'      => 'hybrid', // hybrid, digital_qr, digital_image, manual
        'doc_city'            => 'Jakarta',
        'doc_verification_enabled' => '1',
        'academic_year'       => '2026/2027',
        'academic_semester'   => 'Ganjil',
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
        if (empty($defaults['academic_semester'])) {
            $defaults['academic_semester'] = (stripos($defaults['academic_year'] ?? '', 'Genap') !== false) ? 'Genap' : 'Ganjil';
        }
    } catch (Exception $e) {}
    $cached_settings = $defaults;
    return $defaults;
}

/**
 * Daftar Master Pejabat Penandatangan Dokumen Resmi Sekolah
 */
function getSchoolSigners(PDO $pdo): array {
    $s = getSchoolSettings($pdo);
    return [
        'headmaster' => [
            'key' => 'headmaster',
            'role_label' => 'Kepala Sekolah',
            'name' => $s['headmaster_name'] ?? 'Dr. H. Bambang Sudirman, M.Pd',
            'nip' => $s['headmaster_nip'] ?? '19750812 199903 1 002',
            'title' => $s['headmaster_title'] ?? 'Kepala Sekolah',
            'signature_image' => $s['headmaster_signature_image'] ?? 'uploads/signatures/default_headmaster_ttd.svg',
        ],
        'tu_head' => [
            'key' => 'tu_head',
            'role_label' => 'Kepala Bagian Tata Usaha',
            'name' => $s['tu_head_name'] ?? 'Hj. Siti Rahmawati, S.Sos',
            'nip' => $s['tu_head_nip'] ?? '19800415 200501 2 007',
            'title' => $s['tu_head_title'] ?? 'Kepala Bagian Tata Usaha',
            'signature_image' => $s['tu_head_signature_image'] ?? 'uploads/signatures/default_tu_ttd.svg',
        ],
        'bk_coordinator' => [
            'key' => 'bk_coordinator',
            'role_label' => 'Koordinator Bimbingan Konseling',
            'name' => $s['bk_coordinator_name'] ?? 'Ahmad Rizky, S.Psi., M.Pd',
            'nip' => $s['bk_coordinator_nip'] ?? '19850612 201001 1 018',
            'title' => $s['bk_coordinator_title'] ?? 'Koordinator Bimbingan Konseling',
            'signature_image' => $s['bk_coordinator_signature_image'] ?? 'uploads/signatures/default_bk_ttd.svg',
        ],
        'treasurer' => [
            'key' => 'treasurer',
            'role_label' => 'Bendahara / Keuangan',
            'name' => $s['treasurer_name'] ?? 'Dewi Lestari, S.E',
            'nip' => $s['treasurer_nip'] ?? '19880920 201201 2 015',
            'title' => $s['treasurer_title'] ?? 'Bendahara Sekolah',
            'signature_image' => $s['treasurer_signature_image'] ?? 'uploads/signatures/default_tu_ttd.svg',
        ],
        'curriculum_head' => [
            'key' => 'curriculum_head',
            'role_label' => 'Waka Bidang Kurikulum',
            'name' => $s['curriculum_head_name'] ?? 'Muhammad Fauzi, M.Pd',
            'nip' => $s['curriculum_head_nip'] ?? '19820311 200801 1 012',
            'title' => $s['curriculum_head_title'] ?? 'Waka Bidang Kurikulum',
            'signature_image' => $s['curriculum_head_signature_image'] ?? 'uploads/signatures/default_headmaster_ttd.svg',
        ],
        'library_head' => [
            'key' => 'library_head',
            'role_label' => 'Kepala Perpustakaan Sekolah',
            'name' => $s['library_head_name'] ?? 'Dra. Hj. Nurul Hidayati, M.Pd',
            'nip' => $s['library_head_nip'] ?? '19780512 200501 2 006',
            'title' => $s['library_head_title'] ?? 'Kepala Perpustakaan Sekolah',
            'signature_image' => $s['library_head_signature_image'] ?? 'uploads/signatures/default_library_ttd.svg',
        ]
    ];
}

/**
 * Render Blok Tanda Tangan Resmi (Manual / Digital Image / QR TTE / Hybrid)
 */
function renderOfficialSignatureHtml(PDO $pdo, string $signer_key = 'headmaster', array $doc_meta = []): string {
    $s = getSchoolSettings($pdo);
    $signers = getSchoolSigners($pdo);
    $signer = $signers[$signer_key] ?? $signers['headmaster'];

    $mode = $doc_meta['mode'] ?? ($s['signature_mode'] ?? 'hybrid');
    $city = $doc_meta['city'] ?? ($s['doc_city'] ?? 'Jakarta');
    $date_str = $doc_meta['date'] ?? date('d F Y');
    $show_city_date = $doc_meta['show_city_date'] ?? true;
    $align = $doc_meta['align'] ?? 'right';
    $doc_type = $doc_meta['doc_type'] ?? 'DOKUMEN_RESMI';
    $doc_num = $doc_meta['doc_num'] ?? '421.3 / REG / ' . date('Y');
    $student_name = $doc_meta['student_name'] ?? '';

    // Cek apakah penandatangan adalah Guru / Wali Kelas individual
    $is_teacher_signer = in_array($signer_key, ['teacher', 'guru', 'wali_kelas'], true) || !empty($doc_meta['teacher_id']);
    if ($is_teacher_signer) {
        $teacher_id = (int)($doc_meta['teacher_id'] ?? 0);
        $t_data = null;
        if ($teacher_id > 0) {
            $stmt_t = $pdo->prepare("SELECT name, nip, title_degree, signature_image FROM users WHERE id = ?");
            $stmt_t->execute([$teacher_id]);
            $t_data = $stmt_t->fetch();
        }

        $base_name = $t_data['name'] ?? ($doc_meta['custom_name'] ?? 'Dewi Lestari, M.Pd');
        $degree = !empty($t_data['title_degree']) ? (', ' . $t_data['title_degree']) : '';
        $signer_name = $doc_meta['custom_name'] ?? ($base_name . (strpos($base_name, ',') !== false ? '' : $degree));
        $signer_nip = $doc_meta['custom_nip'] ?? ($t_data['nip'] ?? '19820415 200801 2 015');
        $default_title = ($signer_key === 'wali_kelas' ? 'Wali Kelas' : 'Guru Pengampu Mata Pelajaran');
        $signer_title = $doc_meta['custom_title'] ?? $default_title;
        $ttd_img = !empty($t_data['signature_image']) ? $t_data['signature_image'] : ($doc_meta['custom_signature_image'] ?? 'uploads/signatures/default_teacher_ttd.svg');
        // Stempel sekolah biasanya hanya dibubuhkan pada TTD Kepala Sekolah, kecuali diminta eksplisit
        $show_stamp = $doc_meta['show_stamp'] ?? false;
    } else {
        $signer_name = $doc_meta['custom_name'] ?? $signer['name'];
        $signer_nip = $doc_meta['custom_nip'] ?? $signer['nip'];
        $signer_title = $doc_meta['custom_title'] ?? $signer['title'];
        $ttd_img = $doc_meta['custom_signature_image'] ?? ($signer['signature_image'] ?: 'uploads/signatures/default_headmaster_ttd.svg');
        $show_stamp = isset($doc_meta['show_stamp']) ? (bool)$doc_meta['show_stamp'] : (($s['show_school_stamp'] ?? '1') === '1');
    }
    $stamp_img = $s['school_stamp_image'] ?: 'uploads/signatures/default_stamp.svg';

    // Buat URL verifikasi dokumen resmi
    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $base_dir = rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/\\');
    // Cari root web path
    $root_path = preg_replace('/\/dashboard.*$/', '', $base_dir);
    $doc_hash = strtoupper(substr(md5($doc_num . $signer_nip . 'SEKOLAH_AUTH_TTE'), 0, 16));
    $verify_url = $base_url . $root_path . "/verify_document.php?doc_type=" . urlencode($doc_type) 
        . "&doc_num=" . urlencode($doc_num) 
        . "&signer_role=" . urlencode($signer_title) 
        . "&signer_name=" . urlencode($signer_name) 
        . "&signer_nip=" . urlencode($signer_nip) 
        . "&student_name=" . urlencode($student_name)
        . "&date=" . urlencode($date_str)
        . "&hash=" . $doc_hash;

    // Helper path gambar untuk web
    $web_ttd_path = (strpos($ttd_img, '/') === 0 || strpos($ttd_img, 'http') === 0) ? $ttd_img : $root_path . '/' . ltrim($ttd_img, '/');
    $web_stamp_path = (strpos($stamp_img, '/') === 0 || strpos($stamp_img, 'http') === 0) ? $stamp_img : $root_path . '/' . ltrim($stamp_img, '/');

    // Alignment classes
    $align_class = "text-right ml-auto";
    if ($align === 'center') $align_class = "text-center mx-auto";
    if ($align === 'left') $align_class = "text-left mr-auto";

    ob_start();
    ?>
    <div class="inline-block <?= $align_class ?> text-xs text-slate-800 font-sans max-w-xs relative select-none">
        <?php if ($show_city_date): ?>
            <p class="text-slate-600 mb-0.5"><?= htmlspecialchars($city) ?>, <?= htmlspecialchars($date_str) ?></p>
        <?php endif; ?>
        <p class="font-bold text-slate-900 leading-snug"><?= htmlspecialchars($signer_title) ?>,</p>

        <?php if ($mode === 'manual'): ?>
            <!-- Mode Manual: Spasi tanda tangan pulpen fisik -->
            <div class="h-20"></div>

        <?php elseif ($mode === 'digital_image'): ?>
            <!-- Mode Digital Image: Scan TTD + Stempel -->
            <div class="h-20 relative flex items-center <?= $align === 'center' ? 'justify-center' : ($align === 'left' ? 'justify-start' : 'justify-end') ?> my-1">
                <?php if ($show_stamp): ?>
                    <img src="<?= htmlspecialchars($web_stamp_path) ?>" alt="Stempel" class="absolute w-20 h-20 object-contain pointer-events-none opacity-85 rotate-[-8deg] z-0 <?= $align === 'center' ? 'right-1/2 translate-x-1/2' : ($align === 'left' ? 'left-6' : 'right-10') ?>">
                <?php endif; ?>
                <img src="<?= htmlspecialchars($web_ttd_path) ?>" alt="TTD" class="h-16 max-w-[170px] object-contain relative z-10">
            </div>

        <?php elseif ($mode === 'digital_qr'): ?>
            <!-- Mode Digital QR: QR Code TTE Dokumen Sah -->
            <div class="py-2 flex items-center <?= $align === 'center' ? 'justify-center' : ($align === 'left' ? 'justify-start' : 'justify-end') ?> gap-2">
                <a href="<?= htmlspecialchars($verify_url) ?>" target="_blank" title="Klik / Scan untuk Verifikasi Keaslian Dokumen">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=76x76&margin=0&data=<?= urlencode($verify_url) ?>" 
                         alt="QR Validasi" class="w-16 h-16 border border-slate-300 p-0.5 rounded shadow-sm">
                </a>
                <div class="text-left text-[9px] leading-tight">
                    <span class="inline-block font-mono font-bold text-blue-700 bg-blue-50 border border-blue-200 px-1 py-0.5 rounded">TTE RESMI</span>
                    <p class="font-mono text-slate-500 mt-0.5"><?= $doc_hash ?></p>
                    <p class="text-slate-400 text-[8px]">Scan untuk verifikasi</p>
                </div>
            </div>

        <?php else: // 'hybrid' (TTD + Stempel + QR-Code) ?>
            <!-- Mode Hybrid: Gambar TTD + Stempel + Mini QR TTE -->
            <div class="h-20 relative flex items-center <?= $align === 'center' ? 'justify-center' : ($align === 'left' ? 'justify-start' : 'justify-end') ?> gap-2 my-1">
                <?php if ($show_stamp): ?>
                    <img src="<?= htmlspecialchars($web_stamp_path) ?>" alt="Stempel" class="absolute w-20 h-20 object-contain pointer-events-none opacity-85 rotate-[-8deg] z-0 <?= $align === 'center' ? 'right-1/2 translate-x-1/2' : ($align === 'left' ? 'left-8' : 'right-12') ?>">
                <?php endif; ?>
                <img src="<?= htmlspecialchars($web_ttd_path) ?>" alt="TTD" class="h-16 max-w-[150px] object-contain relative z-10">
                <a href="<?= htmlspecialchars($verify_url) ?>" target="_blank" title="Scan QR Verifikasi Keaslian Dokumen" class="relative z-10 ml-1">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=64x64&margin=0&data=<?= urlencode($verify_url) ?>" 
                         alt="QR TTE" class="w-12 h-12 border border-blue-400/40 p-0.5 rounded bg-white shadow-sm">
                </a>
            </div>
        <?php endif; ?>

        <p class="font-bold underline text-slate-950 text-xs sm:text-sm"><?= htmlspecialchars($signer_name) ?></p>
        <p class="text-[11px] text-slate-600 font-mono mt-0.5">NIP. <?= htmlspecialchars($signer_nip) ?></p>
    </div>
    <?php
    return ob_get_clean();
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

/**
 * =========================================================
 * FASE 1: WHATSAPP GATEWAY NOTIFICATION ENGINE
 * =========================================================
 */

/**
 * Kirim Notifikasi WhatsApp dan Simpan ke Log Audit Sistem
 * Mendukung fleksibilitas argumen:
 * - 5 Argumen: sendWhatsAppNotification($pdo, $phone, $name, $message, $category)
 * - 4 Argumen: sendWhatsAppNotification($pdo, $phone, $message, $category)
 */
function sendWhatsAppNotification(PDO $pdo, string $phone, string $param3, string $param4 = '', string $param5 = 'sistem'): array {
    $known_cats = ['presensi', 'keuangan', 'pengumuman', 'akademik', 'sistem', 'payroll', 'surat', 'bk', 'sarpras', 'ppdb', 'payroll_disbursement'];
    if (empty($param4) || in_array(strtolower($param4), $known_cats, true) || is_numeric($param4)) {
        // Parameter ke-3 adalah pesan langsung
        $message = $param3;
        $name = 'Wali Murid / Guru';
        $category = !empty($param4) && !is_numeric($param4) ? strtolower($param4) : 'sistem';
    } else {
        $name = $param3;
        $message = $param4;
        $category = !empty($param5) ? strtolower($param5) : 'sistem';
    }

    // Normalisasi nomor telepon ke format internasional (misal 0812 -> 62812)
    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
    if (str_starts_with($clean_phone, '0')) {
        $clean_phone = '62' . substr($clean_phone, 1);
    }

    // Ambil konfigurasi gateway dari external_apis (prioritas) atau school_settings (fallback)
    $settings = [];
    $endpoint_url = 'https://api.fonnte.com/send';
    $auth_header = 'Authorization';
    $auth_type = 'header';

    try {
        $stmt_api = $pdo->prepare("SELECT * FROM external_apis WHERE api_code = 'wa_gateway' LIMIT 1");
        $stmt_api->execute();
        $ext_api = $stmt_api->fetch();
        if ($ext_api) {
            $provider = $ext_api['provider'];
            $token = $ext_api['api_key'];
            $is_enabled = ((int)$ext_api['is_active'] === 1);
            $endpoint_url = !empty($ext_api['endpoint_url']) ? $ext_api['endpoint_url'] : $endpoint_url;
            $auth_header = !empty($ext_api['auth_header_name']) ? $ext_api['auth_header_name'] : $auth_header;
            $auth_type = $ext_api['auth_type'] ?? 'header';
        } else {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM school_settings WHERE setting_key IN ('wa_gateway_provider', 'wa_gateway_token', 'wa_gateway_enabled')");
            while ($row = $stmt->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            $provider = $settings['wa_gateway_provider'] ?? 'Fonnte (Simulated)';
            $token = $settings['wa_gateway_token'] ?? '';
            $is_enabled = ($settings['wa_gateway_enabled'] ?? '1') === '1';
        }
    } catch (Exception $e) {
        $provider = 'Fonnte (Simulated)';
        $token = '';
        $is_enabled = true;
    }

    $status = 'terkirim';
    $response_payload = [
        'status' => true,
        'target' => $clean_phone,
        'message_length' => strlen($message),
        'timestamp' => date('Y-m-d H:i:s'),
        'provider' => $provider,
        'mode' => 'simulated_instant_delivery'
    ];

    // Jika mode live WhatsApp aktif dan memiliki token riil
    if ($is_enabled && !empty($token) && !str_contains($token, 'demo')) {
        try {
            $headers = [];
            if ($auth_type === 'bearer') {
                $headers[] = "$auth_header: Bearer $token";
            } elseif ($auth_type === 'basic') {
                $headers[] = "$auth_header: Basic " . base64_encode($token);
            } else {
                $headers[] = "$auth_header: $token";
            }

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $endpoint_url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, [
                'target' => $clean_phone,
                'message' => $message,
            ]);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_TIMEOUT, 8);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($curl);
            $err = curl_error($curl);
            curl_close($curl);

            if (!$err && $response) {
                $decoded = json_decode($response, true);
                if (isset($decoded['status']) && $decoded['status'] == false) {
                    $status = 'gagal';
                }
                $response_payload['api_response'] = $decoded ?? $response;
            }
        } catch (Exception $e_curl) {
            $status = 'gagal';
            $response_payload['error'] = $e_curl->getMessage();
        }
    }

    // Catat ke log wa_notification_logs
    try {
        $stmt_log = $pdo->prepare("
            INSERT INTO `wa_notification_logs` 
            (`recipient_phone`, `recipient_name`, `message`, `category`, `status`, `gateway_provider`, `response_payload`)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_log->execute([
            $phone,
            $name,
            $message,
            $category,
            $status,
            $provider,
            json_encode($response_payload)
        ]);
        $log_id = $pdo->lastInsertId();
    } catch (Exception $e_db) {
        $log_id = null;
    }

    return [
        'success' => ($status === 'terkirim'),
        'log_id' => $log_id,
        'status' => $status,
        'provider' => $provider
    ];
}

/**
 * =========================================================
 * HELPER MANAJEMEN API EKSTERNAL (EXTERNAL APIS)
 * Mengelola integrasi API luar dengan auto-save database
 * =========================================================
 */

/**
 * Mengambil daftar seluruh API luar yang terdaftar
 */
function getExternalApis(PDO $pdo, ?string $category = null): array {
    try {
        if ($category && $category !== 'semua') {
            $stmt = $pdo->prepare("SELECT * FROM `external_apis` WHERE `category` = ? ORDER BY `id` ASC");
            $stmt->execute([$category]);
        } else {
            $stmt = $pdo->query("SELECT * FROM `external_apis` ORDER BY `id` ASC");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Mengambil satu detail API luar berdasarkan ID atau api_code
 */
function getExternalApi(PDO $pdo, $idOrCode): ?array {
    try {
        if (is_numeric($idOrCode)) {
            $stmt = $pdo->prepare("SELECT * FROM `external_apis` WHERE `id` = ? LIMIT 1");
            $stmt->execute([(int)$idOrCode]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `external_apis` WHERE `api_code` = ? LIMIT 1");
            $stmt->execute([(string)$idOrCode]);
        }
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Update single field API luar (Mendukung auto-save realtime AJAX)
 */
function updateExternalApiField(PDO $pdo, int $id, string $field, $value): bool {
    $allowed = [
        'name', 'provider', 'endpoint_url', 'api_key', 'api_secret',
        'auth_type', 'auth_header_name', 'http_method', 'custom_headers',
        'payload_template', 'is_active', 'environment', 'timeout_seconds',
        'description', 'category'
    ];
    if (!in_array($field, $allowed, true)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("UPDATE `external_apis` SET `$field` = ?, `updated_at` = NOW() WHERE `id` = ?");
        $res = $stmt->execute([$value, $id]);

        // Sinkronisasi otomatis ke school_settings jika mengubah wa_gateway
        try {
            $stmt_c = $pdo->prepare("SELECT `api_code`, `provider`, `api_key`, `is_active` FROM `external_apis` WHERE `id` = ?");
            $stmt_c->execute([$id]);
            $row = $stmt_c->fetch();
            if ($row && $row['api_code'] === 'wa_gateway') {
                if ($field === 'is_active') {
                    updateSchoolSetting($pdo, 'wa_gateway_enabled', (string)((int)$value));
                } elseif ($field === 'provider') {
                    updateSchoolSetting($pdo, 'wa_gateway_provider', (string)$value);
                } elseif ($field === 'api_key') {
                    updateSchoolSetting($pdo, 'wa_gateway_token', (string)$value);
                }
            }
        } catch (Exception $e_sync) {}

        return $res;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Simpan atau Buat Baru Data API Luar
 */
function saveExternalApi(PDO $pdo, array $data): int {
    $id = (int)($data['id'] ?? 0);
    $api_code = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($data['api_code'] ?? '')));
    if (empty($api_code)) {
        $api_code = 'api_' . time() . '_' . rand(100, 999);
    }
    $name = trim($data['name'] ?? 'API Eksternal');
    $category = trim($data['category'] ?? 'custom');
    $provider = trim($data['provider'] ?? 'Custom Provider');
    $endpoint_url = trim($data['endpoint_url'] ?? '');
    $api_key = trim($data['api_key'] ?? '');
    $api_secret = trim($data['api_secret'] ?? '');
    $auth_type = trim($data['auth_type'] ?? 'bearer');
    $auth_header_name = trim($data['auth_header_name'] ?? 'Authorization');
    $http_method = strtoupper(trim($data['http_method'] ?? 'POST'));
    $custom_headers = trim($data['custom_headers'] ?? '');
    $payload_template = trim($data['payload_template'] ?? '');
    $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    $environment = trim($data['environment'] ?? 'production');
    $timeout_seconds = max(3, (int)($data['timeout_seconds'] ?? 10));
    $description = trim($data['description'] ?? '');

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE `external_apis` 
            SET `name` = ?, `category` = ?, `provider` = ?, `endpoint_url` = ?, `api_key` = ?,
                `api_secret` = ?, `auth_type` = ?, `auth_header_name` = ?, `http_method` = ?,
                `custom_headers` = ?, `payload_template` = ?, `is_active` = ?, `environment` = ?,
                `timeout_seconds` = ?, `description` = ?, `updated_at` = NOW()
            WHERE `id` = ?
        ");
        $stmt->execute([
            $name, $category, $provider, $endpoint_url, $api_key,
            $api_secret, $auth_type, $auth_header_name, $http_method,
            $custom_headers, $payload_template, $is_active, $environment,
            $timeout_seconds, $description, $id
        ]);
        $returnId = $id;
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO `external_apis` 
            (`api_code`, `name`, `category`, `provider`, `endpoint_url`, `api_key`, `api_secret`,
             `auth_type`, `auth_header_name`, `http_method`, `custom_headers`, `payload_template`,
             `is_active`, `environment`, `timeout_seconds`, `description`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $api_code, $name, $category, $provider, $endpoint_url, $api_key, $api_secret,
            $auth_type, $auth_header_name, $http_method, $custom_headers, $payload_template,
            $is_active, $environment, $timeout_seconds, $description
        ]);
        $returnId = (int)$pdo->lastInsertId();
    }

    // Sinkronisasi wa_gateway jika ada
    if ($api_code === 'wa_gateway') {
        updateSchoolSetting($pdo, 'wa_gateway_enabled', (string)$is_active);
        updateSchoolSetting($pdo, 'wa_gateway_provider', $provider);
        updateSchoolSetting($pdo, 'wa_gateway_token', $api_key);
    }

    return $returnId;
}

/**
 * Hapus API Luar (Kecuali API inti bawaan sistem)
 */
function deleteExternalApi(PDO $pdo, int $id): bool {
    try {
        $stmt = $pdo->prepare("SELECT `api_code` FROM `external_apis` WHERE `id` = ?");
        $stmt->execute([$id]);
        $code = $stmt->fetchColumn();
        $protected = ['wa_gateway', 'payment_gateway'];
        if (in_array($code, $protected, true)) {
            return false;
        }
        $stmt_del = $pdo->prepare("DELETE FROM `external_apis` WHERE `id` = ?");
        return $stmt_del->execute([$id]);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Uji Koneksi API Luar (Ping / Live Connection Tester)
 */
function testExternalApiConnection(PDO $pdo, int $id): array {
    $api = getExternalApi($pdo, $id);
    if (!$api) {
        return ['success' => false, 'message' => 'API tidak ditemukan.', 'status_code' => 0, 'latency_ms' => 0];
    }

    $url = trim($api['endpoint_url']);
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'message' => 'URL Endpoint tidak valid atau belum diisi.', 'status_code' => 0, 'latency_ms' => 0];
    }

    $ch = curl_init();
    $headers = [];

    // Header Autentikasi
    if ($api['auth_type'] === 'bearer' && !empty($api['api_key'])) {
        $headers[] = "Authorization: Bearer " . trim($api['api_key']);
    } elseif ($api['auth_type'] === 'header' && !empty($api['api_key'])) {
        $hdr = !empty($api['auth_header_name']) ? trim($api['auth_header_name']) : 'Authorization';
        $headers[] = "$hdr: " . trim($api['api_key']);
    } elseif ($api['auth_type'] === 'basic' && !empty($api['api_key'])) {
        $authStr = base64_encode(trim($api['api_key']) . ':' . trim($api['api_secret'] ?? ''));
        $headers[] = "Authorization: Basic $authStr";
    }

    // Custom Headers Tambahan
    if (!empty($api['custom_headers'])) {
        $parsed = json_decode($api['custom_headers'], true);
        if (is_array($parsed)) {
            foreach ($parsed as $hk => $hv) {
                $headers[] = "$hk: $hv";
            }
        } else {
            $lines = explode("\n", $api['custom_headers']);
            foreach ($lines as $ln) {
                $ln = trim($ln);
                if (!empty($ln) && str_contains($ln, ':')) {
                    $headers[] = $ln;
                }
            }
        }
    }

    $method = strtoupper($api['http_method'] ?? 'GET');
    $timeout = max(3, min(30, (int)($api['timeout_seconds'] ?? 10)));

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Manajemen-PHP-System/1.0');

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $payload = trim($api['payload_template'] ?? '{}');
        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            if (!in_array('Content-Type: application/json', $headers, true)) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
        }
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        $payload = trim($api['payload_template'] ?? '{}');
        if (!empty($payload)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
    } elseif ($method === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }

    $startTime = microtime(true);
    $response = curl_exec($ch);
    $endTime = microtime(true);
    $latencyMs = round(($endTime - $startTime) * 1000);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    // Status 2xx atau 3xx dianggap berhasil terhubung; bahkan 401/403/400 berarti endpoint merespons!
    $isReachable = ($statusCode > 0 && empty($curlErr));
    $isSuccess = ($statusCode >= 200 && $statusCode < 400);

    $snippet = $response ? mb_substr($response, 0, 800) : ($curlErr ?: 'Server tidak memberikan respon balasan.');

    // Simpan ke database hasil test
    try {
        $stmt_upd = $pdo->prepare("
            UPDATE `external_apis` 
            SET `last_tested_at` = NOW(), 
                `last_status_code` = ?, 
                `last_response` = ?
            WHERE `id` = ?
        ");
        $logSummary = "HTTP $statusCode | Latency: {$latencyMs}ms | " . $snippet;
        $stmt_upd->execute([$statusCode, $logSummary, $id]);
    } catch (Exception $e) {}

    return [
        'success' => $isSuccess,
        'reachable' => $isReachable,
        'status_code' => $statusCode,
        'latency_ms' => $latencyMs,
        'response_snippet' => $snippet,
        'error' => $curlErr ?: null,
        'tested_at' => date('d M Y H:i:s')
    ];
}

/**
 * Eksekusi Panggilan Universal ke API Luar Tertentu
 */
function callExternalApi(PDO $pdo, string $apiCode, array $payload = [], array $queryParams = []): array {
    $api = getExternalApi($pdo, $apiCode);
    if (!$api || (int)$api['is_active'] !== 1) {
        return ['success' => false, 'error' => 'API tidak aktif atau tidak terdaftar.'];
    }

    $url = $api['endpoint_url'];
    if (!empty($queryParams)) {
        $queryStr = http_build_query($queryParams);
        $url .= (str_contains($url, '?') ? '&' : '?') . $queryStr;
    }

    $headers = [];
    if ($api['auth_type'] === 'bearer' && !empty($api['api_key'])) {
        $headers[] = "Authorization: Bearer " . trim($api['api_key']);
    } elseif ($api['auth_type'] === 'header' && !empty($api['api_key'])) {
        $hdr = !empty($api['auth_header_name']) ? trim($api['auth_header_name']) : 'Authorization';
        $headers[] = "$hdr: " . trim($api['api_key']);
    } elseif ($api['auth_type'] === 'basic' && !empty($api['api_key'])) {
        $authStr = base64_encode(trim($api['api_key']) . ':' . trim($api['api_secret'] ?? ''));
        $headers[] = "Authorization: Basic $authStr";
    }

    $ch = curl_init();
    $method = strtoupper($api['http_method'] ?? 'GET');
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)($api['timeout_seconds'] ?? 10));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $jsonPayload = json_encode($payload);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        $headers[] = 'Content-Type: application/json';
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($statusCode >= 200 && $statusCode < 300),
        'status_code' => $statusCode,
        'response' => json_decode($response, true) ?: $response,
        'error' => $err ?: null
    ];
}

/**
 * Broadcast event sistem ke seluruh API Luar / Webhook Kustom yang aktif
 * Memungkinkan API buatan administrator menerima event (pengumuman, pembayaran, presensi)
 */
function broadcastExternalEvent(PDO $pdo, string $event, array $payload = []): array {
    $results = [];
    try {
        $stmt = $pdo->query("SELECT * FROM `external_apis` WHERE `is_active` = 1 AND `category` IN ('custom', 'mail')");
        $apis = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($apis as $api) {
            $code = $api['api_code'];
            $fullPayload = array_merge([
                'event' => $event,
                'timestamp' => date('Y-m-d H:i:s'),
                'school_name' => getSetting($pdo, 'school_name', 'Sekolah')
            ], $payload);
            $results[$code] = callExternalApi($pdo, $code, $fullPayload);
        }
    } catch (Exception $e) {}
    return $results;
}

/**
 * =========================================================
 * HAVERSINE FORMULA GEOFENCING GPS
 * =========================================================
 */

/**
 * Hitung Jarak Euclidean di Permukaan Bumi (Meter) antara 2 Koordinat GPS
 */
function calculateDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): int {
    $earth_radius = 6371000; // Radius bumi dalam meter

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return (int) round($earth_radius * $c);
}

/**
 * =========================================================
 * PAYMENT CHANNELS & PAYROLL STATUSES
 * =========================================================
 */

const PAYMENT_CHANNELS = [
    'QRIS'       => ['name' => 'QRIS Realtime (GoPay/OVO/Dana/BCA)', 'icon' => 'fa-qrcode', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'],
    'BCA_VA'     => ['name' => 'BCA Virtual Account',               'icon' => 'fa-building-columns', 'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300'],
    'MANDIRI_VA' => ['name' => 'Mandiri Virtual Account',           'icon' => 'fa-building-columns', 'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300'],
    'BRI_VA'     => ['name' => 'BRI Virtual Account',               'icon' => 'fa-building-columns', 'badge' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300'],
    'BNI_VA'     => ['name' => 'BNI Virtual Account',               'icon' => 'fa-building-columns', 'badge' => 'border-orange-500/30 bg-orange-500/10 text-orange-300'],
];

const PAYROLL_STATUSES = [
    'draft'     => ['label' => 'Draft Pengajuan', 'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300', 'icon' => 'fa-file-lines'],
    'disetujui' => ['label' => 'Disetujui Kepsek', 'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300', 'icon' => 'fa-circle-check'],
    'dibayar'   => ['label' => 'Sudah Dicairkan', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => 'fa-money-bill-transfer'],
];

function getPayrollStatusBadge(string $status): string {
    return PAYROLL_STATUSES[$status]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getPayrollStatusLabel(string $status): string {
    return PAYROLL_STATUSES[$status]['label'] ?? ucfirst($status);
}

/**
 * =========================================================
 * FASE 1: ONE-STOP STUDENT CLEARANCE (BEBAS TANGGUNGAN 3-IN-1)
 * Memvalidasi 3 pilar: Perpustakaan, Keuangan (SPP), dan BK
 * =========================================================
 */
function getStudentClearanceStatus(PDO $pdo, int $studentId): array {
    $result = [
        'student_id'   => $studentId,
        'is_cleared'   => true,
        'library'      => [
            'status'       => true,
            'active_loans' => 0,
            'fines'        => 0.0,
            'details'      => []
        ],
        'finance'      => [
            'status'       => true,
            'unpaid_bills' => 0,
            'total_unpaid' => 0.0,
            'details'      => []
        ],
        'counseling'   => [
            'status'       => true,
            'violations'   => 0,
            'points'       => 0,
            'achievements' => 0,
            'details'      => []
        ]
    ];

    if ($studentId <= 0) {
        $result['is_cleared'] = false;
        return $result;
    }

    try {
        // 1. Validasi Perpustakaan (library_loans)
        $stmt_lib = $pdo->prepare("
            SELECT l.id, l.borrow_date, l.due_date, b.title as book_title,
                   CASE WHEN CURRENT_DATE() > l.due_date THEN DATEDIFF(CURRENT_DATE(), l.due_date) * 1000 ELSE 0 END as calculated_fine
            FROM library_loans l
            JOIN library_books b ON l.book_id = b.id
            WHERE l.user_id = ? AND l.status = 'dipinjam'
        ");
        $stmt_lib->execute([$studentId]);
        $loans = $stmt_lib->fetchAll();

        $active_loans_cnt = count($loans);
        $total_fines = 0.0;
        foreach ($loans as $ln) {
            $total_fines += (float)($ln['calculated_fine'] ?? 0);
        }

        $result['library']['active_loans'] = $active_loans_cnt;
        $result['library']['fines']        = $total_fines;
        $result['library']['details']      = $loans;
        if ($active_loans_cnt > 0 || $total_fines > 0) {
            $result['library']['status'] = false;
            $result['is_cleared'] = false;
        }

        // 2. Validasi Keuangan & SPP (student_bills)
        $stmt_fin = $pdo->prepare("
            SELECT b.id, b.amount, b.status, COALESCE(pt.name, b.title, 'Tagihan Lainnya') as bill_name, b.due_date,
                   COALESCE((SELECT SUM(amount_paid) FROM bill_payments WHERE bill_id = b.id AND status = 'diterima'), 0) as paid_amount
            FROM student_bills b
            LEFT JOIN payment_types pt ON b.payment_type_id = pt.id
            WHERE b.student_id = ? AND b.status != 'lunas'
        ");
        $stmt_fin->execute([$studentId]);
        $bills = $stmt_fin->fetchAll();

        $unpaid_bills_cnt = count($bills);
        $total_unpaid = 0.0;
        foreach ($bills as $bl) {
            $remaining = max(0, (float)$bl['amount'] - (float)$bl['paid_amount']);
            $total_unpaid += $remaining;
        }

        $result['finance']['unpaid_bills'] = $unpaid_bills_cnt;
        $result['finance']['total_unpaid'] = $total_unpaid;
        $result['finance']['details']      = $bills;
        if ($unpaid_bills_cnt > 0 || $total_unpaid > 0) {
            $result['finance']['status'] = false;
            $result['is_cleared'] = false;
        }

        // 3. Validasi Bimbingan Konseling (counseling_records)
        $stmt_bk = $pdo->prepare("
            SELECT id, type, category, title, points, date, action_taken
            FROM counseling_records
            WHERE student_id = ?
            ORDER BY date DESC
        ");
        $stmt_bk->execute([$studentId]);
        $bk_records = $stmt_bk->fetchAll();

        $violation_cnt = 0;
        $total_pts = 0;
        $achieve_cnt = 0;
        foreach ($bk_records as $rec) {
            if ($rec['type'] === 'pelanggaran') {
                $violation_cnt++;
                $total_pts += (int)($rec['points'] ?? 0);
            } elseif ($rec['type'] === 'prestasi') {
                $achieve_cnt++;
            }
        }

        $result['counseling']['violations']   = $violation_cnt;
        $result['counseling']['points']       = $total_pts;
        $result['counseling']['achievements'] = $achieve_cnt;
        $result['counseling']['details']      = $bk_records;
        // Jika poin pelanggaran >= 30, butuh penyelesaian khusus
        if ($total_pts >= 30) {
            $result['counseling']['status'] = false;
            $result['is_cleared'] = false;
        }

    } catch (Exception $e) {
        error_log("Error getStudentClearanceStatus: " . $e->getMessage());
    }

    return $result;
}

/**
 * =========================================================
 * FASE INTEGRASI LINTAS MODUL (INTEGRATION ENGINE HELPERS)
 * =========================================================
 */

/**
 * 1. PERSURATAN -> KEARSIPAN: Catat surat keluar resmi ke Buku Agenda Kearsipan (AG-OUT)
 */
function recordServiceRequestToArchive(PDO $pdo, int $requestId, int $recordedBy): ?int {
    try {
        $stmt_r = $pdo->prepare("
            SELECT r.*, u.name as user_name, u.role as user_role, u.nisn
            FROM service_requests r
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt_r->execute([$requestId]);
        $req = $stmt_r->fetch();
        if (!$req) return null;

        // Cek apakah sudah pernah diarsipkan
        $stmt_chk = $pdo->prepare("SELECT id FROM mail_archives WHERE source_request_id = ?");
        $stmt_chk->execute([$requestId]);
        $existing = $stmt_chk->fetchColumn();
        if ($existing) return (int)$existing;

        // Hitung nomor urut agenda tahun ini
        $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM mail_archives WHERE mail_type = 'keluar' AND YEAR(created_at) = YEAR(CURDATE())");
        $stmt_cnt->execute();
        $cnt = ((int)$stmt_cnt->fetchColumn()) + 1;
        $agenda_no = 'AG-OUT/' . date('Y/m/') . sprintf('%03d', $cnt);

        // Format nomor surat resmi standar Kemendikbud
        $prefix_code = (stripos($req['request_type'], 'SPT') !== false || stripos($req['request_type'], 'Tugas') !== false) ? '800/SPT-GTK' : '421.3';
        $ref_no = $prefix_code . '/' . sprintf('%03d', $requestId) . '/SMA-BB/' . date('Y');

        $recip_label = $req['user_name'] . ' (' . ucfirst($req['user_role']) . ($req['nisn'] ? ' - NISN: ' . $req['nisn'] : '') . ')';
        $subject = 'Penerbitan ' . $req['request_type'];
        $desc = 'Diterbitkan otomatis dari Layanan Surat TU (Permohonan #' . $requestId . '). Catatan: ' . ($req['notes'] ?: '-');
        $link = 'dashboard/surat/request_print.php?id=' . $requestId;

        $stmt_ins = $pdo->prepare("
            INSERT INTO mail_archives 
            (mail_type, agenda_no, reference_no, sender_or_recipient, mail_date, received_or_sent_date, subject, description, attachment_file, source_request_id, recorded_by)
            VALUES ('keluar', ?, ?, ?, CURDATE(), CURDATE(), ?, ?, ?, ?, ?)
        ");
        $stmt_ins->execute([$agenda_no, $ref_no, $recip_label, $subject, $desc, $link, $requestId, $recordedBy]);
        $new_arch_id = (int)$pdo->lastInsertId();

        logActivity($pdo, 'AUTO_ARCHIVE_LETTER', "Otomatis mengarsipkan surat keluar $agenda_no untuk permohonan #$requestId");
        return $new_arch_id;
    } catch (Exception $e) {
        error_log("Error recordServiceRequestToArchive: " . $e->getMessage());
        return null;
    }
}

/**
 * 2. PRESENSI -> WA NOTIFIKASI: Kirim alert ketidakhadiran / keterlambatan ke Orang Tua
 */
function notifyParentAbsence(PDO $pdo, int $studentId, string $status, string $date, string $notes = ''): bool {
    try {
        if (!in_array($status, ['alpa', 'terlambat', 'sakit', 'izin'], true)) {
            return false;
        }

        $stmt_p = $pdo->prepare("
            SELECT u_p.name as parent_name, u_p.phone as parent_phone, u_s.name as student_name, c.name as class_name
            FROM parent_students ps
            JOIN users u_p ON ps.parent_id = u_p.id
            JOIN users u_s ON ps.student_id = u_s.id
            LEFT JOIN classes c ON u_s.class_id = c.id
            WHERE ps.student_id = ?
            LIMIT 1
        ");
        $stmt_p->execute([$studentId]);
        $p = $stmt_p->fetch();

        if (!$p || empty($p['parent_phone'])) {
            return false;
        }

        $date_fmt = date('d/m/Y', strtotime($date));
        $school_name = getSetting($pdo, 'school_name', 'SMA Bina Bangsa');

        if ($status === 'alpa') {
            $msg = "⚠️ *PEMBERITAHUAN KETIDAKHADIRAN SISWA*\n"
                 . "Yth. Bpk/Ibu *{$p['parent_name']}*,\n\n"
                 . "Kami menginformasikan bahwa ananda *{$p['student_name']}* (Kelas {$p['class_name']}) pada hari ini tanggal *{$date_fmt}* tercatat *ALPA (Tanpa Keterangan)* pada kegiatan belajar mengajar di {$school_name}.\n\n"
                 . "Mohon segera mengonfirmasi kabar ananda kepada pihak sekolah atau Wali Kelas. Terima kasih atas kerja samanya.";
        } elseif ($status === 'terlambat') {
            $msg = "⏱️ *PEMBERITAHUAN KETERLAMBATAN SISWA*\n"
                 . "Yth. Bpk/Ibu *{$p['parent_name']}*,\n\n"
                 . "Ananda *{$p['student_name']}* (Kelas {$p['class_name']}) telah tiba di sekolah pada tanggal *{$date_fmt}*, namun tercatat *TERLAMBAT* (" . ($notes ?: 'Melewati jam 07.15 WIB') . ").\n\n"
                 . "Ananda telah diarahkan oleh guru piket untuk mengikuti pembinaan kedisiplinan pagi sebelum masuk kelas. Terima kasih.";
        } else {
            $msg = "ℹ️ *PEMBERITAHUAN PRESENSI SISWA*\n"
                 . "Yth. Bpk/Ibu *{$p['parent_name']}*,\n\n"
                 . "Presensi ananda *{$p['student_name']}* untuk tanggal *{$date_fmt}* telah tercatat berstatus *" . strtoupper($status) . "*" . ($notes ? " (Keterangan: $notes)" : "") . " di sistem {$school_name}.";
        }

        $res = sendWhatsAppNotification($pdo, $p['parent_phone'], $p['parent_name'], $msg, 'presensi');
        return $res['success'] ?? false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 2b. BK -> WHATSAPP GATEWAY: Kirim notifikasi pelanggaran & peringatan ambang batas poin BK ke orang tua
 */
function notifyParentCounselingViolation(PDO $pdo, int $studentId, string $title, int $points, int $totalPoints, string $actionTaken = ''): bool {
    try {
        $stmt_p = $pdo->prepare("
            SELECT u_p.name as parent_name, u_p.phone as parent_phone, u_s.name as student_name, c.name as class_name
            FROM parent_students ps
            JOIN users u_p ON ps.parent_id = u_p.id
            JOIN users u_s ON ps.student_id = u_s.id
            LEFT JOIN classes c ON u_s.class_id = c.id
            WHERE ps.student_id = ?
            LIMIT 1
        ");
        $stmt_p->execute([$studentId]);
        $p = $stmt_p->fetch();

        if (!$p || empty($p['parent_phone'])) {
            return false;
        }

        $school_name = getSetting($pdo, 'school_name', 'SMA Bina Bangsa');

        $level_alert = "";
        if ($totalPoints >= 100) {
            $level_alert = "\n\n🚨 *PERHATIAN KRITIS (POIN ≥ 100)*: Akumulasi poin kedisiplinan ananda telah mencapai ambang batas kritis (*{$totalPoints} Poin*). Pihak sekolah menerbitkan Surat Peringatan / Pemanggilan dan mengundang Bpk/Ibu untuk hadir ke sekolah.";
        } elseif ($totalPoints >= 50) {
            $level_alert = "\n\n⚠️ *PERINGATAN TINGKAT 2 (POIN ≥ 50)*: Akumulasi poin pelanggaran ananda telah mencapai *{$totalPoints} Poin*. Diharapkan perhatian khusus orang tua dalam pendampingan ananda di rumah.";
        } elseif ($totalPoints >= 30) {
            $level_alert = "\n\n⚠️ *PERINGATAN TINGKAT 1 (POIN ≥ 30)*: Akumulasi poin pelanggaran ananda telah mencapai *{$totalPoints} Poin*.";
        }

        $msg = "📋 *PEMBERITAHUAN BIMBINGAN KONSELING (BK)*\n"
             . "Yth. Bpk/Ibu *{$p['parent_name']}*,\n\n"
             . "Kami menginformasikan bahwa ananda *{$p['student_name']}* (Kelas {$p['class_name']}) telah dicatat melakukan pelanggaran tata tertib di {$school_name}:\n"
             . "• *Kasus:* {$title}\n"
             . "• *Poin Kasus:* +{$points} poin\n"
             . "• *Total Akumulasi:* {$totalPoints} poin\n"
             . ($actionTaken ? "• *Tindakan/Arahan:* {$actionTaken}\n" : "")
             . $level_alert . "\n\n"
             . "Bpk/Ibu dapat memantau perkembangan ananda melalui portal sekolah. Terima kasih atas kerja samanya.";

        $res = sendWhatsAppNotification($pdo, $p['parent_phone'], $p['parent_name'], $msg, 'akademik');
        return $res['success'] ?? false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 3. SARPRAS -> KEUANGAN: Ajukan tiket penganggaran servis sarpras langsung ke Buku Kas Keluar (BKU)
 */
function recordExpenseFromSarprasRepair(PDO $pdo, int $itemId, float $estimatedCost, string $vendorOrNotes, int $recordedBy): string {
    $stmt_i = $pdo->prepare("SELECT * FROM inventory_items WHERE id = ?");
    $stmt_i->execute([$itemId]);
    $item = $stmt_i->fetch();
    if (!$item) {
        throw new Exception("Barang inventaris tidak ditemukan.");
    }

    $bkk_no = "BKK-SAR-" . date('Ym') . "-" . sprintf('%03d', $itemId);
    $chk = $pdo->prepare("SELECT id FROM financial_expenses WHERE expense_no = ?");
    $chk->execute([$bkk_no]);
    if ($chk->fetch()) {
        // Beri suffix unik jika sudah pernah diajukan
        $bkk_no .= '-' . substr(uniqid(), -3);
    }

    $title = "Servis & Perbaikan Aset: " . $item['item_name'] . " (" . $item['item_code'] . ")";
    $notes = "Diajukan otomatis dari Modul Sarpras. Ruangan: " . $item['room_location'] . ", Kondisi: " . strtoupper($item['condition_status']) . ". Catatan: " . $vendorOrNotes;

    $stmt_ins = $pdo->prepare("
        INSERT INTO financial_expenses 
        (expense_no, category, title, expense_date, amount, recipient, notes, recorded_by, source_module, source_ref_id)
        VALUES (?, 'Pemeliharaan Gedung & Perbaikan Sarpras', ?, CURDATE(), ?, 'Teknisi / Vendor Servis', ?, ?, 'sarpras', ?)
    ");
    $stmt_ins->execute([$bkk_no, $title, $estimatedCost, $notes, $recordedBy, $itemId]);

    logActivity($pdo, 'SARPRAS_TO_BKU', "Mengajukan penganggaran servis BKU $bkk_no untuk aset #$itemId senilai Rp " . number_format($estimatedCost, 0, ',', '.'));
    return $bkk_no;
}

/**
 * 4. PROFIL SISWA 360°: Mengagregasikan seluruh data siswa lintas modul dalam satu struktur komprehensif
 */
function getStudent360Data(PDO $pdo, int $studentId): ?array {
    if ($studentId <= 0) return null;

    // A. Biodata Pokok & Kelas
    $stmt_u = $pdo->prepare("
        SELECT u.*, c.name as class_name, c.grade_level,
               p.id as parent_id, p.name as parent_name, p.phone as parent_phone, p.email as parent_email, p.address as parent_address,
               ppdb.birth_place, ppdb.birth_date, ppdb.religion, ppdb.previous_school, ppdb.nik, ppdb.registration_no,
               ppdb.track_type as ppdb_track, ppdb.cbt_score as ppdb_cbt_score
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        LEFT JOIN parent_students ps ON u.id = ps.student_id
        LEFT JOIN users p ON ps.parent_id = p.id
        LEFT JOIN ppdb_registrations ppdb ON (u.email = ppdb.email OR (u.nisn IS NOT NULL AND u.nisn != '' AND u.nisn = ppdb.nisn))
        WHERE u.id = ? AND u.role = 'siswa'
    ");
    $stmt_u->execute([$studentId]);
    $student = $stmt_u->fetch();
    if (!$student) return null;

    // B. Statistik Presensi
    $stmt_att = $pdo->prepare("
        SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as count_hadir,
            SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) as count_sakit,
            SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) as count_izin,
            SUM(CASE WHEN status = 'alpa' THEN 1 ELSE 0 END) as count_alpa
        FROM student_attendance 
        WHERE student_id = ?
    ");
    $stmt_att->execute([$studentId]);
    $att = $stmt_att->fetch() ?: ['total_days' => 0, 'count_hadir' => 0, 'count_sakit' => 0, 'count_izin' => 0, 'count_alpa' => 0];
    $total_days = (int)$att['total_days'];
    $hadir_ratio = ($total_days > 0) ? round(((int)$att['count_hadir'] / $total_days) * 100, 1) : 100.0;

    // Presensi 15 hari terakhir
    $stmt_att_logs = $pdo->prepare("SELECT * FROM student_attendance WHERE student_id = ? ORDER BY date DESC LIMIT 15");
    $stmt_att_logs->execute([$studentId]);
    $recent_attendance = $stmt_att_logs->fetchAll();

    // C. Rekam Akademik (Nilai Tugas & Nilai CBT)
    $stmt_assign = $pdo->prepare("
        SELECT a.title, a.subject, s.score, s.submitted_at, s.feedback
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.id
        WHERE s.student_id = ? AND s.status = 'selesai'
        ORDER BY s.submitted_at DESC LIMIT 10
    ");
    $stmt_assign->execute([$studentId]);
    $assignments = $stmt_assign->fetchAll();

    $stmt_exams = $pdo->prepare("
        SELECT e.title, e.subject, e.category, s.score, s.submitted_at, s.is_remedial
        FROM exam_submissions s
        JOIN exams e ON s.exam_id = e.id
        WHERE s.student_id = ? AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
        ORDER BY s.submitted_at DESC LIMIT 10
    ");
    $stmt_exams->execute([$studentId]);
    $exams = $stmt_exams->fetchAll();

    // Rata-rata nilai keseluruhan
    $avg_assign = $pdo->prepare("SELECT AVG(score) FROM assignment_submissions WHERE student_id = ? AND status = 'selesai' AND score IS NOT NULL");
    $avg_assign->execute([$studentId]);
    $avg_assignment_score = round((float)($avg_assign->fetchColumn() ?: 0), 1);

    $avg_ex = $pdo->prepare("SELECT AVG(s.score) FROM exam_submissions s JOIN exams e ON s.exam_id = e.id WHERE s.student_id = ? AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)");
    $avg_ex->execute([$studentId]);
    $avg_exam_score = round((float)($avg_ex->fetchColumn() ?: 0), 1);

    // D. Bimbingan Konseling (BK)
    $stmt_bk = $pdo->prepare("
        SELECT cr.*, u.name as recorder_name 
        FROM counseling_records cr
        LEFT JOIN users u ON cr.recorded_by = u.id
        WHERE cr.student_id = ?
        ORDER BY cr.date DESC
    ");
    $stmt_bk->execute([$studentId]);
    $counseling_records = $stmt_bk->fetchAll();

    $bk_violations_pts = 0;
    $bk_violations_cnt = 0;
    $bk_achievements_cnt = 0;
    foreach ($counseling_records as $rec) {
        if ($rec['type'] === 'pelanggaran') {
            $bk_violations_pts += (int)($rec['points'] ?? 0);
            $bk_violations_cnt++;
        } elseif ($rec['type'] === 'prestasi') {
            $bk_achievements_cnt++;
        }
    }

    // E. Keuangan & SPP
    $stmt_bills = $pdo->prepare("
        SELECT b.*, pt.name as bill_category,
               (SELECT SUM(amount_paid) FROM bill_payments WHERE bill_id = b.id AND status = 'diterima') as total_paid
        FROM student_bills b
        LEFT JOIN payment_types pt ON b.payment_type_id = pt.id
        WHERE b.student_id = ?
        ORDER BY b.due_date DESC
    ");
    $stmt_bills->execute([$studentId]);
    $bills = $stmt_bills->fetchAll();

    $unpaid_bills_cnt = 0;
    $total_unpaid_amount = 0.0;
    foreach ($bills as $b) {
        if ($b['status'] !== 'lunas') {
            $unpaid_bills_cnt++;
            $total_unpaid_amount += max(0, (float)$b['amount'] - (float)($b['total_paid'] ?? 0));
        }
    }

    // F. Perpustakaan (Pinjaman Aktif & Denda)
    $stmt_lib = $pdo->prepare("
        SELECT l.*, b.title as book_title, b.author, b.category as book_category
        FROM library_loans l
        JOIN library_books b ON l.book_id = b.id
        WHERE l.user_id = ?
        ORDER BY l.borrow_date DESC LIMIT 10
    ");
    $stmt_lib->execute([$studentId]);
    $library_loans = $stmt_lib->fetchAll();

    $active_loans_cnt = 0;
    $total_fines = 0.0;
    foreach ($library_loans as $ln) {
        if ($ln['status'] === 'dipinjam') {
            $active_loans_cnt++;
            if (date('Y-m-d') > $ln['due_date']) {
                $days = (strtotime(date('Y-m-d')) - strtotime($ln['due_date'])) / (60 * 60 * 24);
                $total_fines += max(0, $days * 1000);
            }
        }
    }

    // G. Layanan Surat & Dokumen
    $stmt_req = $pdo->prepare("SELECT * FROM service_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
    $stmt_req->execute([$studentId]);
    $service_requests = $stmt_req->fetchAll();

    // H. Status Clearance 3-in-1
    $clearance = getStudentClearanceStatus($pdo, $studentId);

    // I. Ekstrakurikuler & Prestasi Terpadu
    $ekskul_memberships = [];
    $student_achievements = [];
    try {
        $stmt_em = $pdo->prepare("
            SELECT em.*, e.name as ekskul_name, e.code as ekskul_code, e.category as ekskul_category,
                   e.schedule_day, e.schedule_time, e.location, e.logo_icon,
                   u.name as leader_name
            FROM extracurricular_members em
            JOIN extracurriculars e ON em.extracurricular_id = e.id
            LEFT JOIN users u ON e.leader_instructor_id = u.id
            WHERE em.student_id = ?
            ORDER BY em.status ASC, e.name ASC
        ");
        $stmt_em->execute([$studentId]);
        $ekskul_memberships = $stmt_em->fetchAll();

        $stmt_ach = $pdo->prepare("
            SELECT a.*, e.name as ekskul_name, u.name as verifier_name
            FROM extracurricular_achievements a
            LEFT JOIN extracurriculars e ON a.extracurricular_id = e.id
            LEFT JOIN users u ON a.verified_by = u.id
            WHERE a.student_id = ?
            ORDER BY a.achievement_date DESC
        ");
        $stmt_ach->execute([$studentId]);
        $student_achievements = $stmt_ach->fetchAll();
    } catch (Exception $e_stu_ekskul) {}

    return [
        'student' => $student,
        'attendance' => [
            'total_days' => $total_days,
            'hadir' => (int)$att['count_hadir'],
            'sakit' => (int)$att['count_sakit'],
            'izin' => (int)$att['count_izin'],
            'alpa' => (int)$att['count_alpa'],
            'hadir_ratio' => $hadir_ratio,
            'recent' => $recent_attendance
        ],
        'academics' => [
            'avg_assignment_score' => $avg_assignment_score,
            'avg_exam_score' => $avg_exam_score,
            'assignments' => $assignments,
            'exams' => $exams
        ],
        'counseling' => [
            'violations_count' => $bk_violations_cnt,
            'violations_points' => $bk_violations_pts,
            'achievements_count' => $bk_achievements_cnt,
            'records' => $counseling_records
        ],
        'finance' => [
            'unpaid_count' => $unpaid_bills_cnt,
            'total_unpaid' => $total_unpaid_amount,
            'bills' => $bills
        ],
        'library' => [
            'active_loans_count' => $active_loans_cnt,
            'total_fines' => $total_fines,
            'loans' => $library_loans
        ],
        'requests' => $service_requests,
        'clearance' => $clearance,
        'extracurriculars' => $ekskul_memberships,
        'achievements' => $student_achievements
    ];
}

/**
 * =========================================================
 * HELPER KEAMANAN: TWO-FACTOR AUTHENTICATION (TOTP / RFC 6238)
 * Mendukung Google Authenticator, Microsoft Authenticator, Authy
 * =========================================================
 */
function base32Decode(string $b32): string {
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $val = strpos($chars, $b32[$i]);
        if ($val === false) continue;
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $output;
}

function base32Encode(string $data): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $buffer = ($buffer << 8) | ord($data[$i]);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $bitsLeft -= 5;
            $output .= $chars[($buffer >> $bitsLeft) & 0x1F];
        }
    }
    if ($bitsLeft > 0) {
        $output .= $chars[($buffer << (5 - $bitsLeft)) & 0x1F];
    }
    return $output;
}

function generateTotpSecret(int $length = 16): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    try {
        $bytes = random_bytes($length);
    } catch (Exception $e) {
        $bytes = openssl_random_pseudo_bytes($length);
    }
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[ord($bytes[$i]) & 31];
    }
    return $secret;
}

function getTotpCode(string $secret, ?int $timestamp = null): string {
    if ($timestamp === null) {
        $timestamp = time();
    }
    $timeSlice = (int) floor($timestamp / 30);
    $binarySecret = base32Decode($secret);
    
    // Pack 64-bit integer big-endian (pack 'J' in 64-bit PHP, or two 32-bit words)
    $packedTime = (PHP_INT_SIZE >= 8) ? pack('J', $timeSlice) : pack('NN', 0, $timeSlice);
    
    $hash = hash_hmac('sha1', $packedTime, $binarySecret, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $unpacked = unpack('N', substr($hash, $offset, 4));
    $truncated = $unpacked[1] & 0x7FFFFFFF;
    return str_pad((string)($truncated % 1000000), 6, '0', STR_PAD_LEFT);
}

function verifyTotpCode(string $secret, string $code, int $discrepancy = 1): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (strlen($code) !== 6 || !ctype_digit($code)) {
        return false;
    }
    $currentTime = time();
    for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
        $calcTime = $currentTime + ($i * 30);
        if (hash_equals(getTotpCode($secret, $calcTime), $code)) {
            return true;
        }
    }
    return false;
}

function getTotpQrUri(string $issuer, string $accountName, string $secret): string {
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($accountName) . 
           '?secret=' . rawurlencode($secret) . 
           '&issuer=' . rawurlencode($issuer) . 
           '&algorithm=SHA1&digits=6&period=30';
}

/**
 * =========================================================
 * HELPER KEAMANAN JARINGAN & PEMBATASAN IP LAB UJIAN CBT
 * =========================================================
 */
function getClientIp(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // Cegah spoofing IP: hanya percaya header proksi jika permintaan berasal dari reverse proxy lokal/terpercaya
    $trusted_proxies = ['127.0.0.1', '::1'];
    if (in_array($remote, $trusted_proxies, true)) {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP'
        ];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ipList = explode(',', $_SERVER[$header]);
                $ip = trim($ipList[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }

    return $remote;
}

function isIpInSubnet(string $ip, ?string $rangeString): bool {
    if (empty($rangeString)) {
        return true; // Tidak ada pembatasan IP
    }
    
    // Normalisasi IP jika IPv6 localhost
    if ($ip === '::1') {
        $ip = '127.0.0.1';
    }
    
    // Pisahkan berdasarkan koma, titik koma, atau spasi
    $ranges = preg_split('/[\s,;]+/', trim($rangeString));
    
    foreach ($ranges as $range) {
        $range = trim($range);
        if (empty($range)) continue;
        
        // Exact match
        if ($ip === $range) {
            return true;
        }
        
        // Wildcard match (misal: 192.168.1.*)
        if (strpos($range, '*') !== false) {
            $pattern = '/^' . str_replace(['.', '*'], ['\.', '[0-9]+'], $range) . '$/';
            if (preg_match($pattern, $ip)) {
                return true;
            }
        }
        
        // CIDR subnet match (misal: 192.168.1.0/24 atau 10.0.0.0/8)
        if (strpos($range, '/') !== false) {
            [$subnet, $mask] = explode('/', $range, 2);
            $mask = (int)$mask;
            if ($mask >= 0 && $mask <= 32) {
                $ipLong = ip2long($ip);
                $subnetLong = ip2long($subnet);
                if ($ipLong !== false && $subnetLong !== false) {
                    $maskLong = -1 << (32 - $mask);
                    if (($ipLong & $maskLong) === ($subnetLong & $maskLong)) {
                        return true;
                    }
                }
            }
        }
    }
    
    return false;
}

/**
 * =========================================================
 * REFERENSI RESMI PROJEK P5 KURIKULUM MERDEKA (BSKAP KEMENDIKBUD)
 * =========================================================
 */
const P5_THEMES = [
    'Gaya Hidup Berkelanjutan' => [
        'label' => 'Gaya Hidup Berkelanjutan',
        'desc'  => 'Memahami dampak aktivitas manusia terhadap kelestarian lingkungan dan bumi serta membangun aksi nyata ramah lingkungan.',
        'icon'  => 'fa-solid fa-seedling',
        'color' => 'emerald'
    ],
    'Kearifan Lokal' => [
        'label' => 'Kearifan Lokal',
        'desc'  => 'Mengeksplorasi dan melestarikan budaya, tradisi lokal, nilai kearifan warisan nenek moyang dalam kehidupan modern.',
        'icon'  => 'fa-solid fa-landmark',
        'color' => 'amber'
    ],
    'Bhinneka Tunggal Ika' => [
        'label' => 'Bhinneka Tunggal Ika',
        'desc'  => 'Membangun dialog antarbudaya, toleransi keberagaman, dan persatuan dalam kebinekaan Indonesia.',
        'icon'  => 'fa-solid fa-hands-holding-child',
        'color' => 'indigo'
    ],
    'Bangunlah Jiwa dan Raganya' => [
        'label' => 'Bangunlah Jiwa dan Raganya',
        'desc'  => 'Menjaga kesehatan fisik dan mental, pencegahan perundungan (bullying), serta kesejahteraan psikososial.',
        'icon'  => 'fa-solid fa-heart-pulse',
        'color' => 'rose'
    ],
    'Suara Demokrasi' => [
        'label' => 'Suara Demokrasi',
        'desc'  => 'Menerapkan asas demokrasi, musyawarah mufakat, keterbukaan, dan partisipasi aktif warga sekolah.',
        'icon'  => 'fa-solid fa-check-to-slot',
        'color' => 'purple'
    ],
    'Rekayasa dan Teknologi' => [
        'label' => 'Rekayasa dan Teknologi',
        'desc'  => 'Merancang dan membuat inovasi teknologi rekayasa aplikatif untuk menyelesaikan permasalahan lingkungan dan masyarakat.',
        'icon'  => 'fa-solid fa-microchip',
        'color' => 'cyan'
    ],
    'Kewirausahaan' => [
        'label' => 'Kewirausahaan',
        'desc'  => 'Menumbuhkan jiwa mandiri, kreativitas bisnis, inovasi produk, etika berusaha, dan literasi finansial.',
        'icon'  => 'fa-solid fa-coins',
        'color' => 'blue'
    ],
    'Kebekerjaan' => [
        'label' => 'Kebekerjaan',
        'desc'  => 'Mempersiapkan etos kerja, integritas profesional, dan kesiapan memasuki dunia kerja dan industri.',
        'icon'  => 'fa-solid fa-briefcase',
        'color' => 'teal'
    ]
];

const P5_DIMENSIONS = [
    'Beriman, Bertakwa kepada Tuhan YME, dan Berakhlak Mulia' => [
        'label' => 'Beriman, Bertakwa kepada Tuhan YME, dan Berakhlak Mulia',
        'elements' => ['Akhlak Beragama', 'Akhlak Pribadi', 'Akhlak kepada Manusia', 'Akhlak kepada Alam', 'Akhlak Bernegara']
    ],
    'Berkebinekaan Global' => [
        'label' => 'Berkebinekaan Global',
        'elements' => ['Mengenal dan Menghargai Budaya', 'Komunikasi dan Interaksi Antar Budaya', 'Refleksi dan Tanggung Jawab Kebinekaan', 'Berkeadilan Sosial']
    ],
    'Bergotong Royong' => [
        'label' => 'Bergotong Royong',
        'elements' => ['Kolaborasi', 'Kepedulian', 'Berbagi']
    ],
    'Mandiri' => [
        'label' => 'Mandiri',
        'elements' => ['Pemahaman Diri dan Situasi', 'Regulasi Diri']
    ],
    'Bernalar Kritis' => [
        'label' => 'Bernalar Kritis',
        'elements' => ['Memperoleh dan Memproses Informasi', 'Menganalisis dan Mengevaluasi Penalaran', 'Refleksi Pemikiran dan Proses Berpikir']
    ],
    'Kreatif' => [
        'label' => 'Kreatif',
        'elements' => ['Menghasilkan Gagasan Orisinal', 'Menghasilkan Karya dan Tindakan Orisinal', 'Keluwesan Berpikir dalam Mencari Solusi']
    ]
];

const P5_PREDICATES = [
    'BB' => [
        'code'  => 'BB',
        'label' => 'Belum Berkembang',
        'desc'  => 'Peserta didik masih membutuhkan bimbingan intensif dalam mengembangkan kemampuan pada elemen ini.',
        'badge' => 'border-rose-500/40 bg-rose-500/20 text-rose-300'
    ],
    'MB' => [
        'code'  => 'MB',
        'label' => 'Mulai Berkembang',
        'desc'  => 'Peserta didik mulai mengembangkan kemampuan pada elemen ini namun belum konsisten.',
        'badge' => 'border-amber-500/40 bg-amber-500/20 text-amber-300'
    ],
    'BSH' => [
        'code'  => 'BSH',
        'label' => 'Berkembang Sesuai Harapan',
        'desc'  => 'Peserta didik telah mengembangkan kemampuan hingga tahap yang diharapkan pada fase ini secara konsisten.',
        'badge' => 'border-emerald-500/40 bg-emerald-500/20 text-emerald-300'
    ],
    'SB' => [
        'code'  => 'SB',
        'label' => 'Sangat Berkembang',
        'desc'  => 'Peserta didik telah melampaui capaian fase dan mampu menjadi teladan serta inspirasi bagi teman-temannya.',
        'badge' => 'border-cyan-500/40 bg-cyan-500/20 text-cyan-300'
    ]
];

/**
 * =========================================================
 * HELPER MODUL EKSTRAKURIKULER & PRESTASI SISWA
 * =========================================================
 */
function getExtracurriculars(PDO $pdo, ?string $category = null, bool $only_active = true): array {
    $sql = "SELECT e.*, u.name as leader_name, u.email as leader_email,
                   (SELECT COUNT(*) FROM extracurricular_members em WHERE em.extracurricular_id = e.id AND em.status = 'aktif') as active_members_count,
                   (SELECT COUNT(*) FROM extracurricular_members em WHERE em.extracurricular_id = e.id AND em.status = 'menunggu') as pending_members_count,
                   (SELECT COUNT(*) FROM extracurricular_activities ea WHERE ea.extracurricular_id = e.id) as total_activities_count,
                   (SELECT COUNT(*) FROM extracurricular_achievements ach WHERE ach.extracurricular_id = e.id) as total_achievements_count
            FROM extracurriculars e
            LEFT JOIN users u ON e.leader_instructor_id = u.id
            WHERE 1=1";
    $params = [];
    if ($only_active) {
        $sql .= " AND e.is_active = 1";
    }
    if ($category !== null && in_array($category, ['wajib', 'pilihan'], true)) {
        $sql .= " AND e.category = ?";
        $params[] = $category;
    }
    $sql .= " ORDER BY e.category DESC, e.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getExtracurricularById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT e.*, u.name as leader_name, u.email as leader_email, u.phone as leader_phone,
               (SELECT COUNT(*) FROM extracurricular_members em WHERE em.extracurricular_id = e.id AND em.status = 'aktif') as active_members_count,
               (SELECT COUNT(*) FROM extracurricular_members em WHERE em.extracurricular_id = e.id AND em.status = 'menunggu') as pending_members_count,
               (SELECT COUNT(*) FROM extracurricular_activities ea WHERE ea.extracurricular_id = e.id) as total_activities_count,
               (SELECT COUNT(*) FROM extracurricular_achievements ach WHERE ach.extracurricular_id = e.id) as total_achievements_count
        FROM extracurriculars e
        LEFT JOIN users u ON e.leader_instructor_id = u.id
        WHERE e.id = ?
    ");
    $stmt->execute([$id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

function getStudentExtracurriculars(PDO $pdo, int $studentId): array {
    $stmt = $pdo->prepare("
        SELECT em.*, e.name as ekskul_name, e.code as ekskul_code, e.category as ekskul_category,
               e.schedule_day, e.schedule_time, e.location, e.logo_icon,
               u.name as leader_name,
               (SELECT COUNT(*) FROM extracurricular_attendance ea 
                JOIN extracurricular_activities act ON ea.activity_id = act.id 
                WHERE act.extracurricular_id = e.id AND ea.student_id = em.student_id AND ea.status = 'hadir') as total_attended,
               (SELECT COUNT(*) FROM extracurricular_activities act WHERE act.extracurricular_id = e.id) as total_activities
        FROM extracurricular_members em
        JOIN extracurriculars e ON em.extracurricular_id = e.id
        LEFT JOIN users u ON e.leader_instructor_id = u.id
        WHERE em.student_id = ?
        ORDER BY em.status ASC, e.name ASC
    ");
    $stmt->execute([$studentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}