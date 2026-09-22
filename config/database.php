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

    // Auto-create tables if they don't exist yet for seamless experience
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `announcements` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `title` VARCHAR(200) NOT NULL,
            `content` TEXT NOT NULL,
            `target_role` ENUM('semua', 'guru', 'siswa', 'orang_tua', 'staf') NOT NULL DEFAULT 'semua',
            `category` ENUM('umum', 'akademik', 'kegiatan', 'penting', 'darurat') NOT NULL DEFAULT 'umum',
            `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
            `attachment_url` VARCHAR(255) DEFAULT NULL,
            `status` ENUM('draft', 'published') NOT NULL DEFAULT 'published',
            `expires_at` DATETIME DEFAULT NULL,
            `author_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `assignments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `subject` VARCHAR(100) NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT NOT NULL,
            `due_date` DATE NOT NULL,
            `teacher_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `assignment_submissions` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `assignment_id` INT NOT NULL,
            `student_id` INT NOT NULL,
            `status` ENUM('belum', 'selesai') NOT NULL DEFAULT 'belum',
            `notes` TEXT DEFAULT NULL,
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
        "ALTER TABLE `announcements` ADD COLUMN `is_pinned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `category`",
        "ALTER TABLE `announcements` ADD COLUMN `attachment_url` VARCHAR(255) DEFAULT NULL AFTER `is_pinned`",
        "ALTER TABLE `announcements` ADD COLUMN `status` ENUM('draft', 'published') NOT NULL DEFAULT 'published' AFTER `attachment_url`",
        "ALTER TABLE `announcements` ADD COLUMN `expires_at` DATETIME DEFAULT NULL AFTER `status`",
        "ALTER TABLE `announcements` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
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
                ('school_logo', '⚡');
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
    } catch (PDOException $e_seed) {
        // Abaikan jika error insert sampel
    }

} catch (PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
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
    'uts'              => ['label' => 'Ujian Tengah Semester (UTS)', 'type' => 'ujian', 'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300', 'icon' => '📝'],
    'ukk'              => ['label' => 'Ujian Kenaikan Kelas (UKK)',  'type' => 'ujian', 'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',     'icon' => '🎓'],
    'ujian_harian'     => ['label' => 'Ujian Harian (Fleksibel)',    'type' => 'ujian', 'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',   'icon' => '⚡'],
    'latihan_harian'   => ['label' => 'Latihan Harian',              'type' => 'latihan', 'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',     'icon' => '📖'],
    'latihan_mingguan' => ['label' => 'Latihan Mingguan',            'type' => 'latihan', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '📅'],
    'latihan_bulanan'  => ['label' => 'Latihan Bulanan',             'type' => 'latihan', 'badge' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',       'icon' => '🎯'],
];

function getExamCategoryLabel(string $category): string {
    return EXAM_CATEGORIES[$category]['label'] ?? ucfirst(str_replace('_', ' ', $category));
}

function getExamCategoryBadge(string $category): string {
    return EXAM_CATEGORIES[$category]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getExamCategoryIcon(string $category): string {
    return EXAM_CATEGORIES[$category]['icon'] ?? '📝';
}

/**
 * Helper Presensi Siswa
 */
const ATTENDANCE_STATUSES = [
    'hadir' => ['label' => 'Hadir', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '✅'],
    'sakit' => ['label' => 'Sakit', 'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',     'icon' => '🩺'],
    'izin'  => ['label' => 'Izin',  'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',       'icon' => '✉️'],
    'alpa'  => ['label' => 'Alpa',  'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',       'icon' => '❌'],
];

function getAttendanceLabel(string $status): string {
    return ATTENDANCE_STATUSES[$status]['label'] ?? ucfirst($status);
}

function getAttendanceBadge(string $status): string {
    return ATTENDANCE_STATUSES[$status]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getAttendanceIcon(string $status): string {
    return ATTENDANCE_STATUSES[$status]['icon'] ?? '📌';
}

/**
 * Daftar kategori pengumuman
 */
const ANNOUNCEMENT_CATEGORIES = [
    'umum'     => ['label' => 'Umum',     'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',       'icon' => '📢'],
    'akademik' => ['label' => 'Akademik', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'icon' => '🎓'],
    'kegiatan' => ['label' => 'Kegiatan', 'badge' => 'border-cyan-500/30 bg-cyan-500/10 text-cyan-300',       'icon' => '📌'],
    'penting'  => ['label' => 'Penting',  'badge' => 'border-amber-500/30 bg-amber-500/10 text-amber-300',     'icon' => '⚡'],
    'darurat'  => ['label' => 'Darurat',  'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',       'icon' => '🚨'],
];

function getAnnouncementCategoryLabel(string $category): string {
    return ANNOUNCEMENT_CATEGORIES[$category]['label'] ?? ucfirst($category);
}

function getAnnouncementCategoryBadge(string $category): string {
    return ANNOUNCEMENT_CATEGORIES[$category]['badge'] ?? 'border-slate-500/30 bg-slate-500/10 text-slate-300';
}

function getAnnouncementCategoryIcon(string $category): string {
    return ANNOUNCEMENT_CATEGORIES[$category]['icon'] ?? '📢';
}

/**
 * Helper Kalender Akademik & Agenda
 */
const CALENDAR_CATEGORIES = [
    'akademik' => ['label' => 'Akademik', 'badge' => 'border-blue-500/30 bg-blue-500/10 text-blue-300',       'color' => '#3b82f6', 'icon' => '🎓'],
    'libur'    => ['label' => 'Libur',    'badge' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',       'color' => '#ef4444', 'icon' => '🏖️'],
    'kegiatan' => ['label' => 'Kegiatan', 'badge' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300', 'color' => '#10b981', 'icon' => '📌'],
    'ujian'    => ['label' => 'Ujian',    'badge' => 'border-purple-500/30 bg-purple-500/10 text-purple-300',   'color' => '#a855f7', 'icon' => '📝'],
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
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        $parentFolder = basename(dirname($_SERVER['PHP_SELF']));
        $loginUrl = ($parentFolder === 'dashboard') ? '../auth/login.php' : '../../auth/login.php';
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
 * Helper Konfigurasi Sekolah
 */
function getSchoolSettings(PDO $pdo): array {
    $defaults = [
        'school_name'     => 'SMA Bina Bangsa Nusantara',
        'school_address'  => 'Jl. Pendidikan Nasional No. 45, Kebayoran Baru, Jakarta',
        'school_phone'    => '(021) 789-0123',
        'school_email'    => 'info@binabangsa.sch.id',
        'school_website'  => 'https://binabangsa.sch.id',
        'headmaster_name' => 'Dr. H. Bambang Sudirman, M.Pd',
        'headmaster_nip'  => '19750812 199903 1 002',
        'academic_year'   => '2026/2027 Ganjil',
        'school_logo'     => '⚡'
    ];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM school_settings");
        while ($row = $stmt->fetch()) {
            $defaults[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {}
    return $defaults;
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

