-- =========================================================
-- Skema Database untuk Project: manajemen-php
-- Mendukung 5 Role Pengguna:
-- 1. administrator
-- 2. staf
-- 3. guru
-- 4. orang_tua (Orang Tua)
-- 5. siswa
-- =========================================================

CREATE DATABASE IF NOT EXISTS `website_login` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `website_login`;

-- 0. Tabel Rombel & Kelas (classes)
CREATE TABLE IF NOT EXISTS `classes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `grade_level` VARCHAR(20) NOT NULL DEFAULT '10',
    `academic_year` VARCHAR(30) NOT NULL DEFAULT '2026/2027',
    `homeroom_teacher_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `classes` (`id`, `name`, `grade_level`, `academic_year`, `homeroom_teacher_id`) VALUES
(1, 'X-MIPA-1', '10', '2026/2027', 3),
(2, 'XI-MIPA-2', '11', '2026/2027', NULL),
(3, 'XII-IPS-1', '12', '2026/2027', NULL)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 1. Tabel `users`
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `two_factor_secret` VARCHAR(64) DEFAULT NULL,
    `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `two_factor_confirmed_at` DATETIME DEFAULT NULL,
    `role` ENUM('administrator', 'staf', 'guru', 'orang_tua', 'siswa') NOT NULL DEFAULT 'siswa',
    `class_id` INT DEFAULT NULL,
    `nisn` VARCHAR(20) DEFAULT NULL,
    `gender` ENUM('L', 'P') DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `address` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabel `announcements` (Pengumuman Sekolah oleh Admin/Staf untuk Role Tertentu)
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
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_announcements_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabel `assignments` (Tugas Pembelajaran oleh Guru untuk Siswa & Orang Tua)
CREATE TABLE IF NOT EXISTS `assignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject` VARCHAR(100) NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `due_date` DATE NOT NULL,
    `attachment_url` VARCHAR(255) DEFAULT NULL,
    `teacher_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_assignments_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel `assignment_submissions` (Status Pengerjaan Tugas & Pengumpulan Berkas oleh Siswa)
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
    UNIQUE KEY `uk_student_assignment` (`assignment_id`, `student_id`),
    CONSTRAINT `fk_sub_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sub_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabel `service_requests` (Layanan Pengajuan Surat oleh Siswa/Orang Tua untuk Staf)
CREATE TABLE IF NOT EXISTS `service_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `request_type` VARCHAR(100) NOT NULL,
    `notes` TEXT DEFAULT NULL,
    `status` ENUM('menunggu', 'diproses', 'selesai', 'ditolak') NOT NULL DEFAULT 'menunggu',
    `processed_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_requests_processor` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabel `exams` (Modul Asesmen: UTS, UKK, Ujian Harian Fleksibel, Latihan Harian, Mingguan, Bulanan)
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
    `lockdown_enabled` TINYINT(1) DEFAULT 1,
    `allowed_ip_range` VARCHAR(255) DEFAULT NULL,
    `start_time` DATETIME DEFAULT NULL,
    `due_date` DATETIME NOT NULL,
    `teacher_id` INT NOT NULL,
    `status` ENUM('aktif', 'selesai', 'draft') NOT NULL DEFAULT 'aktif',
    `is_ppdb` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_exams_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabel `exam_questions` (Butir Soal Campuran: Pilihan Ganda Tunggal/Kompleks, Benar/Salah, Isian Singkat, & Esai)
CREATE TABLE IF NOT EXISTS `exam_questions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `exam_id` INT NOT NULL,
    `question_type` VARCHAR(50) DEFAULT 'multiple_choice',
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
    `explanation` TEXT DEFAULT NULL,
    CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabel `exam_submissions` (Riwayat & Hasil Pengerjaan Siswa dengan Auto-grading PG + Manual Grading Esai)
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
    UNIQUE KEY `uk_exam_student` (`exam_id`, `student_id`),
    CONSTRAINT `fk_exam_sub_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_exam_sub_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Tabel `student_attendance` (Presensi Siswa Harian oleh Guru/Staf)
CREATE TABLE IF NOT EXISTS `student_attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `status` ENUM('hadir', 'sakit', 'izin', 'alpa') NOT NULL DEFAULT 'hadir',
    `notes` VARCHAR(255) DEFAULT NULL,
    `recorded_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_student_attendance_date` (`student_id`, `date`),
    CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_recorder` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Tabel `calendar_events` (Kalender Akademik & Agenda Kegiatan Sekolah)
CREATE TABLE IF NOT EXISTS `calendar_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `event_date` DATE NOT NULL,
    `end_date` DATE DEFAULT NULL,
    `category` ENUM('akademik', 'libur', 'kegiatan', 'ujian') NOT NULL DEFAULT 'kegiatan',
    `color` VARCHAR(20) DEFAULT 'blue',
    `created_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_calendar_author` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Tabel `classes` (Manajemen Kelas & Rombel)
CREATE TABLE IF NOT EXISTS `classes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `grade_level` VARCHAR(20) NOT NULL DEFAULT '10',
    `academic_year` VARCHAR(50) NOT NULL DEFAULT '2026/2027',
    `homeroom_teacher_id` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_class_homeroom` FOREIGN KEY (`homeroom_teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Tabel `parent_students` (Relasi Akun Orang Tua & Siswa)
CREATE TABLE IF NOT EXISTS `parent_students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `parent_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `relation_type` VARCHAR(50) DEFAULT 'Wali Murid',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_parent_student` (`parent_id`, `student_id`),
    CONSTRAINT `fk_ps_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ps_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Tabel `school_settings` (Pengaturan Profil Lembaga & Semester)
CREATE TABLE IF NOT EXISTS `school_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Tabel `audit_logs` (Audit Trail & Log Aktivitas Sistem)
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(50) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Akun Sampel untuk Pengujian 5 Role
-- Password default semua akun sampel: 'password'
-- Hash: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
-- =========================================================

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `phone`, `address`) VALUES
(1, 'Administrator Sistem', 'admin@sekolah.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'administrator', '081234567890', 'Kantor Pusat Administrasi'),
(2, 'Budi Santoso, S.Kom', 'staf@sekolah.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'staf', '081234567891', 'Ruang Tata Usaha Lt. 1'),
(3, 'Dewi Lestari, M.Pd', 'guru@sekolah.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'guru', '081234567892', 'Ruang Guru Gedung B'),
(4, 'Hendra Wijaya (Wali Murid)', 'orangtua@sekolah.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'orang_tua', '081234567893', 'Jl. Melati No. 45'),
(5, 'Ahmad Fauzi (Siswa)', 'siswa@sekolah.id', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'siswa', '081234567894', 'Jl. Mawar No. 12')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`), `role`=VALUES(`role`);

-- Data Sampel Pengumuman
INSERT INTO `announcements` (`id`, `title`, `content`, `target_role`, `author_id`) VALUES
(1, 'Jadwal Libur Semester Ganjil & Masuk Sekolah', 'Diberitahukan kepada seluruh civitas akademika bahwa libur semester akan dimulai tanggal 25 Desember hingga 08 Januari.', 'semua', 2),
(2, 'Undangan Pertemuan Wali Murid & Pembagian Rapor', 'Pertemuan wali murid akan diselenggarakan hari Sabtu pukul 08.30 WIB di Aula Utama Sekolah.', 'orang_tua', 2),
(3, 'Rapat Koordinasi Penyusunan Modul Ajar Kurikulum Baru', 'Diharapkan seluruh dewan guru menghadiri rapat koordinasi pada hari Kamis pukul 13.00 WIB.', 'guru', 1),
(4, 'Persiapan Ujian Tengah Semester (UTS) Siswa', 'Seluruh siswa diharapkan menyelesaikan administrasi dan tugas belajar sebelum pekan ujian dimulai.', 'siswa', 2)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- Data Sampel Tugas Pembelajaran oleh Guru
INSERT INTO `assignments` (`id`, `subject`, `title`, `description`, `due_date`, `teacher_id`) VALUES
(1, 'Matematika', 'Latihan Soal Aljabar & Fungsi Kuadrat', 'Kerjakan latihan soal buku paket halaman 45 nomor 1 sampai 10 di buku catatan.', DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY), 3),
(2, 'Bahasa Indonesia', 'Menulis Teks Eksplanasi Lingkungan Hidup', 'Buat esai sepanjang 300 kata mengenai pelestarian lingkungan di sekitar tempat tinggal Anda.', DATE_ADD(CURRENT_DATE, INTERVAL 5 DAY), 3)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- Data Sampel Pengajuan Layanan Surat
INSERT INTO `service_requests` (`id`, `user_id`, `request_type`, `notes`, `status`, `processed_by`) VALUES
(1, 5, 'Surat Keterangan Aktif Siswa', 'Untuk keperluan pengajuan beasiswa pendidikan pemerintah.', 'diproses', 2),
(2, 4, 'Surat Izin Dispensasi Mengikuti Acara Keluarga', 'Izin tidak masuk sekolah tanggal 28-29 selama 2 hari.', 'selesai', 2)
ON DUPLICATE KEY UPDATE `request_type`=VALUES(`request_type`);

-- =========================================================
-- Data Sampel 6 Kategori Ujian & Latihan
-- =========================================================

-- 1. Paket Asesmen (exams)
INSERT INTO `exams` (`id`, `title`, `subject`, `category`, `type`, `description`, `duration_minutes`, `passing_grade`, `token`, `start_time`, `due_date`, `teacher_id`, `status`) VALUES
(1, 'Ujian Tengah Semester (UTS) Matematika', 'Matematika', 'uts', 'ujian', 'Ujian Tengah Semester resmi mencakup materi Fungsi Kuadrat, Logaritma, dan Matriks. Wajib token.', 60, 75, 'UTS8A', CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 5 DAY), 3, 'aktif'),
(2, 'Ujian Kenaikan Kelas (UKK) Bahasa Indonesia', 'Bahasa Indonesia', 'ukk', 'ujian', 'Penilaian Akhir Tahun / Kenaikan Kelas materi Teks Negosiasi, Debat, dan Resensi Buku.', 90, 75, 'UKK26', DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 DAY), DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 DAY), 3, 'aktif'),
(3, 'Ulangan Harian Bab 3 - Sistem Ekskresi & Respirasi', 'Biologi', 'ujian_harian', 'ujian', 'Ujian harian fleksibel untuk menguji pemahaman materi organ ekskresi manusia.', 30, 70, NULL, CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 3 DAY), 3, 'aktif'),
(4, 'Latihan Harian: Grammar & Reading Comprehension', 'Bahasa Inggris', 'latihan_harian', 'latihan', 'Latihan rutin harian santai tanpa batas waktu untuk memperkaya kosakata dan tata bahasa.', 0, 65, NULL, CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 7 DAY), 3, 'aktif'),
(5, 'Latihan Mingguan: Review Hukum Newton & Gerak Lurus', 'Fisika', 'latihan_mingguan', 'latihan', 'Evaluasi mingguan pemecahan soal gaya, percepatan, dan GLBB.', 0, 70, NULL, CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 4 DAY), 3, 'aktif'),
(6, 'Latihan Bulanan: Evaluasi Masa Kolonialisme di Nusantara', 'Sejarah', 'latihan_bulanan', 'latihan', 'Tryout bulanan persiapan pemahaman linimasa peristiwa bersejarah abad ke-17 hingga ke-19.', 45, 75, NULL, CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 8 DAY), 3, 'aktif')
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`), `token`=VALUES(`token`);

-- 2. Butir Soal Sampel (exam_questions)
INSERT INTO `exam_questions` (`id`, `exam_id`, `question_text`, `image_url`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_answer`, `explanation`) VALUES
-- Soal UTS Matematika (Exam ID 1)
(1, 1, 'Jika persamaan kuadrat x² - 5x + 6 = 0, maka akar-akar persamaannya adalah...', NULL, 'x = 2 dan x = 3', 'x = -2 dan x = -3', 'x = 1 dan x = 6', 'x = -1 dan x = -6', 'A', 'Faktorisasi dari (x - 2)(x - 3) = 0 menghasilkan x = 2 atau x = 3.'),
(2, 1, 'Nilai dari 2log(16) adalah...', NULL, '2', '3', '4', '8', 'C', 'Karena 2 pangkat 4 adalah 16, maka nilai logaritmanya adalah 4.'),
(3, 1, 'Determinan dari matriks [[3, 2], [1, 4]] adalah...', NULL, '10', '14', '12', '8', 'A', 'Determinan = (3 * 4) - (2 * 1) = 12 - 2 = 10.'),

-- Soal UKK Bahasa Indonesia (Exam ID 2)
(4, 2, 'Teks yang berisi pengajuan dan penawaran antara dua pihak untuk mencapai kesepakatan bersama disebut...', NULL, 'Teks Prosedur', 'Teks Negosiasi', 'Teks Eksplanasi', 'Teks Anekdot', 'B', 'Teks negosiasi bertujuan mencapai kesepakatan antara dua pihak yang memiliki perbedaan kepentingan.'),
(5, 2, 'Struktur utama dalam teks debat adalah...', NULL, 'Pengenalan, argumen, dan kesimpulan/penutup', 'Orientasi, krisis, reaksi', 'Pernyataan umum, deretan penjelas, interpretasi', 'Tesis, argumentasi, penegasan ulang', 'A', 'Debat diawali pengenalan mosi/isu, dilanjutkan adu argumen afirmatif-oposisi, diakhiri kesimpulan.'),

-- Soal Ujian Harian Biologi (Exam ID 3) - Contoh dengan Ilustrasi Gambar
(6, 3, 'Perhatikan ilustrasi diagram organ berikut! Organ ekskresi utama manusia yang berfungsi menyaring darah dan memproduksi urine adalah...', 'https://images.unsplash.com/photo-1579684385127-1ef15d508118?w=800&auto=format&fit=crop&q=60', 'Hati', 'Paru-paru', 'Ginjal', 'Kulit', 'C', 'Ginjal merupakan organ ekskresi utama yang memfiltrasi darah menjadi urine melalui nefron.'),
(7, 3, 'Proses penyaringan darah pada nefron ginjal berlangsung di...', NULL, 'Tubulus Kontortus Proksimal', 'Glomerulus', 'Lengkung Henle', 'Tubulus Kolektivus', 'B', 'Filtrasi plasma darah berlangsung di glomerulus menghasilkan urine primer.'),

-- Soal Latihan Harian Bahasa Inggris (Exam ID 4)
(8, 4, 'Choose the correct form: She _____ to the national library every Saturday morning.', NULL, 'go', 'goes', 'going', 'gone', 'B', 'Third-person singular subject (She) in Simple Present Tense uses verb + s/es (goes).'),
(9, 4, 'What is the synonym of the word "rapid"?', NULL, 'Slow', 'Fast', 'Heavy', 'Weak', 'B', 'Rapid means occurring within a short time or at a great rate, synonym of fast.'),

-- Soal Latihan Mingguan Fisika (Exam ID 5)
(10, 5, 'Hukum I Newton sering juga dikenal sebagai hukum...', NULL, 'Aksi-Reaksi', 'Kelembaman / Inersia', 'Gravitasi Universal', 'Kekekalan Energi', 'B', 'Hukum I Newton menyatakan benda cenderung mempertahankan keadaannya (inersia/kelembaman).'),
(11, 5, 'Sebuah mobil bergerak dengan kecepatan awal 10 m/s dan percepatan 2 m/s² selama 5 detik. Kecepatan akhirnya adalah...', NULL, '15 m/s', '20 m/s', '25 m/s', '30 m/s', 'B', 'vt = v0 + a*t = 10 + (2 * 5) = 10 + 10 = 20 m/s.'),

-- Soal Latihan Bulanan Sejarah (Exam ID 6)
(12, 6, 'Organisasi pergerakan nasional pertama di Indonesia yang didirikan pada tanggal 20 Mei 1908 adalah...', NULL, 'Sarekat Islam', 'Budi Utomo', 'Indische Partij', 'Perhimpunan Indonesia', 'B', 'Budi Utomo didirikan oleh dr. Soetomo dkk atas gagasan dr. Wahidin Sudirohusodo pada 20 Mei 1908.')
ON DUPLICATE KEY UPDATE `question_text`=VALUES(`question_text`), `image_url`=VALUES(`image_url`);

-- 3. Data Sampel Pengerjaan Siswa (exam_submissions)
-- 3a. Siswa Ahmad Fauzi (ID: 5) sudah mengerjakan Latihan Harian Bahasa Inggris (Exam ID: 4) dengan nilai sempurna 100
INSERT INTO `exam_submissions` (`exam_id`, `student_id`, `score`, `total_correct`, `total_questions`, `answers`, `status`, `is_remedial`, `remedial_granted`, `previous_score`) VALUES
(4, 5, 100.00, 2, 2, '{"8":"B","9":"B"}', 'selesai', 0, 0, NULL),
-- 3b. Siswa Ahmad Fauzi (ID: 5) pada Ulangan Harian Biologi (Exam ID: 3) mendapat nilai 50 (KKM 70) dan guru sudah memberi izin remedial!
(3, 5, 50.00, 1, 2, '{"6":"C","7":"A"}', 'selesai', 0, 1, NULL)
ON DUPLICATE KEY UPDATE `score`=VALUES(`score`), `remedial_granted`=VALUES(`remedial_granted`);

-- =========================================================
-- 4. Data Sampel Kalender Akademik & Agenda
-- =========================================================
INSERT INTO `calendar_events` (`id`, `title`, `description`, `event_date`, `end_date`, `category`, `color`, `created_by`) VALUES
(1, 'Rapat Pleno Dewan Guru & Staf', 'Penyelarasan silabus dan jadwal asesmen semester ganjil.', CURRENT_DATE(), NULL, 'kegiatan', 'emerald', 1),
(2, 'Pekan Ulangan Tengah Semester (UTS)', 'Pelaksanaan UTS serentak seluruh kelas ganjil.', DATE_ADD(CURRENT_DATE(), INTERVAL 5 DAY), DATE_ADD(CURRENT_DATE(), INTERVAL 10 DAY), 'ujian', 'purple', 3),
(3, 'Peringatan Hari Guru Nasional', 'Upacara bendera dan pentas seni kebersamaan civitas sekolah.', DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY), NULL, 'akademik', 'blue', 2),
(4, 'Libur Cuti Bersama Nasional', 'Libur pembelajaran daring dan luring sesuai surat edaran dinas.', DATE_ADD(CURRENT_DATE(), INTERVAL 20 DAY), DATE_ADD(CURRENT_DATE(), INTERVAL 21 DAY), 'libur', 'rose', 1)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- =========================================================
-- 5. Data Sampel Presensi Siswa (student_attendance)
-- =========================================================
INSERT INTO `student_attendance` (`student_id`, `date`, `status`, `notes`, `recorded_by`) VALUES
(5, CURRENT_DATE(), 'hadir', 'Tepat waktu dan tertib', 3),
(5, DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY), 'hadir', 'Hadir tepat waktu', 3),
(5, DATE_SUB(CURRENT_DATE(), INTERVAL 2 DAY), 'hadir', 'Hadir tepat waktu', 3)
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`);

-- =========================================================
-- 6. Data Sampel Kelas & Rombel
-- =========================================================
INSERT INTO `classes` (`id`, `name`, `grade_level`, `academic_year`) VALUES
(1, 'X MIPA 1', '10', '2026/2027'),
(2, 'XI MIPA 2', '11', '2026/2027'),
(3, 'XII IPS 1', '12', '2026/2027')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

UPDATE `users` SET `class_id` = 1, `nisn` = '0081234567', `gender` = 'L' WHERE `id` = 5;

-- =========================================================
-- 7. Data Sampel Relasi Orang Tua & Siswa (parent_students)
-- =========================================================
INSERT INTO `parent_students` (`id`, `parent_id`, `student_id`, `relation_type`) VALUES
(1, 4, 5, 'Ayah Kandung')
ON DUPLICATE KEY UPDATE `relation_type`=VALUES(`relation_type`);

-- =========================================================
-- 8. Data Sampel Pengaturan Identitas Sekolah (school_settings)
-- =========================================================
INSERT INTO `school_settings` (`setting_key`, `setting_value`) VALUES
('school_name', 'SMA Bina Bangsa Nusantara'),
('school_address', 'Jl. Pendidikan Nasional No. 45, Kebayoran Baru, Jakarta'),
('school_phone', '(021) 789-0123'),
('school_email', 'info@binabangsa.sch.id'),
('school_website', 'https://binabangsa.sch.id'),
('headmaster_name', 'Dr. H. Bambang Sudirman, M.Pd'),
('headmaster_nip', '19750812 199903 1 002'),
('academic_year', '2026/2027 Ganjil'),
('school_logo', '⚡')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);

-- =========================================================
-- 9. Tabel Mata Pelajaran (subjects)
-- =========================================================
CREATE TABLE IF NOT EXISTS `subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(20) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(10, 'Seni Budaya', 'SNB')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- =========================================================
-- 10. Tabel Jadwal Pelajaran Mingguan (timetables)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(1, 10, 'Seni Budaya', 3, 'Sabtu', '08:00:00', '09:30:00', 'Studio Seni')
ON DUPLICATE KEY UPDATE `subject_name`=VALUES(`subject_name`);

-- =========================================================
-- 11. Tabel Materi Pembelajaran & E-Learning (learning_materials)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `learning_materials` (`title`, `subject`, `class_id`, `teacher_id`, `description`, `file_url`, `link_url`, `file_type`, `download_count`) VALUES
('Modul Mandiri Aljabar & Fungsi Kuadrat Lengkap', 'Matematika', 1, 3, 'Bahan ajar materi fungsi kuadrat, grafik parabola, dan latihan soal persiapan UTS.', NULL, 'https://drive.google.com', 'document', 14),
('Video Pembelajaran: Dinamika Gerak & Hukum II Newton', 'Fisika', 1, 3, 'Penjelasan konsep gaya dan percepatan benda beserta contoh fenomena sehari-hari.', NULL, 'https://www.youtube.com/watch?v=kKKM8Y-u7ds', 'video', 28),
('Slide Presentasi Struktur Teks Eksplanasi & Debat', 'Bahasa Indonesia', 1, 3, 'Materi panduan menyusun argumen dalam debat ilmiah dan struktur teks negosiasi.', NULL, 'https://drive.google.com', 'document', 19)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- =========================================================
-- 12. Tabel Jenis Tagihan Keuangan (payment_types)
-- =========================================================
CREATE TABLE IF NOT EXISTS `payment_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `description` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payment_types` (`id`, `name`, `amount`, `description`) VALUES
(1, 'SPP Bulanan Semester Ganjil', 350000.00, 'Iuran penyelenggaraan pendidikan rutin setiap bulan.'),
(2, 'Uang Kegiatan & Ekstrakurikuler', 150000.00, 'Iuran operasional perlombaan dan kegiatan siswa tahunan.'),
(3, 'Sumbangan Pengembangan Sarana Gedung', 500000.00, 'Biaya perawatan laboratorium dan fasilitas belajar digital.')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- =========================================================
-- 13. Tabel Tagihan Siswa (student_bills)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `student_bills` (`id`, `student_id`, `payment_type_id`, `title`, `amount`, `due_date`, `month_period`, `status`, `notes`) VALUES
(1, 5, 1, 'SPP Bulanan - September 2026', 350000.00, '2026-09-10', 'September 2026', 'lunas', 'Lunas dibayar tepat waktu.'),
(2, 5, 1, 'SPP Bulanan - Oktober 2026', 350000.00, '2026-10-10', 'Oktober 2026', 'belum_lunas', 'Jatuh tempo tanggal 10 setiap bulan.'),
(3, 5, 2, 'Uang Kegiatan Siswa Ganjil 2026/2027', 150000.00, '2026-09-30', 'Semester Ganjil', 'lunas', 'Termasuk atribut lomba dan ekstrakurikuler.')
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- =========================================================
-- 14. Tabel Transaksi Pembayaran Tagihan (bill_payments)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `bill_payments` (`id`, `bill_id`, `student_id`, `amount_paid`, `payment_method`, `payment_date`, `proof_url`, `status`, `notes`, `verified_by`, `verified_at`) VALUES
(1, 1, 5, 350000.00, 'transfer_bank', '2026-09-08', NULL, 'diterima', 'Pembayaran via Bank Transfer BCA telah terverifikasi.', 2, NOW()),
(2, 3, 5, 150000.00, 'tunai', '2026-09-12', NULL, 'diterima', 'Pembayaran langsung di loket Tata Usaha sekolah.', 2, NOW())
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`);

-- =========================================================
-- 15. Tabel Bimbingan Konseling (BK) (counseling_records)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `counseling_records` (`student_id`, `type`, `category`, `title`, `points`, `action_taken`, `notes`, `recorded_by`, `date`) VALUES
(5, 'prestasi', 'Akademik', 'Juara 2 Olimpiade Sains Matematika Tingkat Kota', 25, 'Diberikan piagam penghargaan dan apresiasi beasiswa prestasi.', 'Siswa berprestasi mengharumkan nama sekolah di ajang OSN.', 3, CURRENT_DATE()),
(5, 'pelanggaran', 'Kedisiplinan', 'Terlambat Masuk Jam Pertama Sekolah (15 Menit)', 5, 'Peringatan lisan dan pembinaan tata tertib oleh guru piket.', 'Terlambat karena kendala transportasi di perjalanan.', 3, DATE_SUB(CURRENT_DATE(), INTERVAL 3 DAY))
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- =========================================================
-- 16. Tabel Pesan & Konsultasi Internal (messages)
-- =========================================================
CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sender_id` INT NOT NULL,
    `receiver_id` INT NOT NULL,
    `message` TEXT NOT NULL,
    `attachment_url` VARCHAR(255) DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `messages` (`sender_id`, `receiver_id`, `message`, `is_read`, `created_at`) VALUES
(4, 3, 'Selamat pagi Ibu Dewi, mohon konfirmasi untuk materi praktikum biologi anak kami Ahmad Fauzi pekan depan.', 1, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(3, 4, 'Selamat pagi Pak Hendra. Praktikum akan fokus pada materi sistem ekskresi, seluruh alat lab sudah dipersiapkan sekolah.', 1, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(5, 3, 'Selamat siang Ibu Dewi, apakah tugas matematika halaman 45 nomor 10 dikumpulkan dalam bentuk softcopy atau buku tulis?', 0, DATE_SUB(NOW(), INTERVAL 20 MINUTE));

-- =========================================================
-- 17. Tabel PPDB Online (ppdb_registrations)
-- =========================================================
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
    `exam_date` DATE DEFAULT NULL,
    `exam_time` VARCHAR(50) DEFAULT NULL,
    `exam_room` VARCHAR(100) DEFAULT NULL,
    `exam_session` VARCHAR(50) DEFAULT NULL,
    `cbt_answers` LONGTEXT DEFAULT NULL,
    `cbt_doubt_answers` LONGTEXT DEFAULT NULL,
    `cbt_start_time` DATETIME DEFAULT NULL,
    `cbt_status` ENUM('belum_mulai', 'sedang_mengerjakan', 'selesai') NOT NULL DEFAULT 'belum_mulai',
    `user_id` INT DEFAULT NULL,
    `qr_token` VARCHAR(64) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ppdb_registrations` 
(`registration_no`, `full_name`, `nisn`, `nik`, `gender`, `birth_place`, `birth_date`, `religion`, `phone`, `email`, `address`, `previous_school`, `chosen_major`, `track_type`, `distance_km`, `achievement_desc`, `achievement_level`, `affirmation_no`, `score_math`, `score_science`, `score_indonesian`, `score_english`, `calculated_score`, `parent_name`, `parent_phone`, `parent_job`, `document_status`, `rejection_reason`, `status`, `selection_score`, `notes`, `qr_token`, `created_at`) 
VALUES
('PPDB-2026-0001', 'Rian Pratama', '0081234567', '3201011203080001', 'L', 'Jakarta', '2009-04-12', 'Islam', '081234567890', 'rian.pratama@gmail.com', 'Jl. Kenanga No. 15, Jakarta Selatan', 'SMP Negeri 1 Jakarta', 'MIPA (Matematika & IPA)', 'prestasi', 3.50, 'Juara 2 OSN Matematika Kota', 'kabupaten', NULL, 88.00, 90.00, 85.00, 86.00, 88.50, 'Budi Santoso', '081298765432', 'Wiraswasta', 'lengkap', NULL, 'lulus_seleksi', 88.50, 'Nilai rapor semester 1-5 sangat memuaskan.', 'a1b2c3d4e5f67890123456789abcdef0123456789abcdef0123456789abcdef0', DATE_SUB(NOW(), INTERVAL 5 DAY)),
('PPDB-2026-0002', 'Siti Nur Aisyah', '0087654321', '3201015607080002', 'P', 'Bandung', '2009-07-25', 'Islam', '081345678901', 'siti.aisyah@gmail.com', 'Jl. Melati No. 8, Bandung', 'SMP IT Al-Falah', 'IPS (Ilmu Pengetahuan Sosial)', 'zonasi', 0.80, NULL, NULL, NULL, 80.00, 78.00, 85.00, 82.00, 88.25, 'Ahmad Hidayat', '081387654321', 'PNS', 'perlu_revisi', 'Kartu Keluarga kurang jelas / buram. Mohon unggah ulang.', 'menunggu_verifikasi', NULL, 'Menunggu verifikasi kartu keluarga.', 'b2c3d4e5f6a17890123456789abcdef0123456789abcdef0123456789abcdef0', DATE_SUB(NOW(), INTERVAL 2 DAY)),
('PPDB-2026-0003', 'Bayu Anggara', '0089988776', '3201012309080003', 'L', 'Bogor', '2009-09-18', 'Islam', '081456789012', 'bayu.anggara@gmail.com', 'Jl. Flamboyan No. 22, Bogor', 'SMP Budi Mulia', 'Bahasa & Budaya', 'reguler', 5.20, NULL, NULL, NULL, 82.00, 80.00, 84.00, 82.00, 82.00, 'Hendra Gunawan', '081476543210', 'Karyawan Swasta', 'lengkap', NULL, 'diverifikasi', 82.00, 'Berkas lengkap, dijadwalkan tes wawancara.', 'c3d4e5f6a1b27890123456789abcdef0123456789abcdef0123456789abcdef0', DATE_SUB(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `full_name`=VALUES(`full_name`);

-- =========================================================
-- 18. Tabel Buku Perpustakaan (library_books)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `library_books` 
(`code`, `isbn`, `title`, `author`, `publisher`, `year`, `category`, `stock_total`, `stock_available`, `shelf_location`, `description`) 
VALUES
('BK-001', '978-602-01-2345-1', 'Fisika Dasar untuk SMA/MA Kelas X', 'Prof. Bambang Subagyo', 'Erlangga', 2023, 'Sains & Teknologi', 10, 9, 'Rak Sains A-1', 'Buku teks fisika kurikulum terbaru mencakup kinematika gerak, dinamika, dan energi kinetik.'),
('BK-002', '978-979-3062-79-2', 'Laskar Pelangi', 'Andrea Hirata', 'Bentang Pustaka', 2008, 'Novel & Sastra', 5, 5, 'Rak Sastra B-2', 'Kisah inspiratif tentang 10 anak di Pulau Belitung yang berjuang menuntut ilmu di tengah keterbatasan.'),
('BK-003', '978-979-22-3841-5', 'Kamus Lengkap Inggris - Indonesia', 'John M. Echols & Hassan Shadily', 'Gramedia Pustaka Utama', 2021, 'Referensi & Bahasa', 4, 3, 'Rak Referensi R-1', 'Kamus standar acuan utama untuk pembelajaran bahasa Inggris di sekolah dan perguruan tinggi.'),
('BK-004', '978-979-407-123-4', 'Sejarah Perjuangan Kemerdekaan Indonesia', 'Dr. Nugroho Notosusanto', 'Balai Pustaka', 2019, 'IPS & Sejarah', 6, 6, 'Rak Sejarah C-1', 'Rangkuman kronologis diplomasi dan revolusi fisik kemerdekaan Republik Indonesia 1945-1949.'),
('BK-005', '978-623-00-1122-3', 'Pemrograman Web Modern dengan PHP & MySQL', 'Dr. Budi Raharjo', 'Informatika Bandung', 2024, 'Teknologi & Komputer', 8, 8, 'Rak IT D-1', 'Panduan aplikatif pembuatan aplikasi web interaktif enterprise menggunakan PHP 8+ dan PDO.')
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- =========================================================
-- 19. Tabel Sirkulasi Peminjaman Buku (library_loans)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `library_loans` 
(`book_id`, `user_id`, `borrow_date`, `due_date`, `return_date`, `fine_amount`, `status`, `notes`) 
VALUES
(1, 5, DATE_SUB(CURRENT_DATE(), INTERVAL 5 DAY), DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY), NULL, 0.00, 'dipinjam', 'Peminjaman untuk tugas kelompok Fisika.'),
(3, 5, DATE_SUB(CURRENT_DATE(), INTERVAL 20 DAY), DATE_SUB(CURRENT_DATE(), INTERVAL 13 DAY), DATE_SUB(CURRENT_DATE(), INTERVAL 12 DAY), 0.00, 'kembali', 'Dikembalikan tepat waktu dalam kondisi baik.')
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`);

-- =========================================================
-- 19b. Tabel Buku Tamu Perpustakaan (library_visitors)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `library_visitors` 
(`user_id`, `name`, `role`, `identifier`, `class_name`, `gender`, `purpose`, `visit_date`, `visit_time`, `notes`) 
VALUES
(5, 'Ahmad Fauzi', 'siswa', '0081234567', 'X MIPA 1', 'L', 'Membaca Buku / Majalah', CURRENT_DATE(), '08:30:00', 'Membaca buku referensi fisika.'),
(NULL, 'Rian Pratama', 'siswa', '0081234567', 'X MIPA 1', 'L', 'Mengerjakan Tugas / Belajar Mandiri', CURRENT_DATE(), '09:15:00', 'Mengerjakan tugas matematika.'),
(3, 'Siti Rahmawati, S.Pd', 'guru', '19850315 201001 2 018', 'Dewan Guru', 'P', 'Peminjaman / Pengembalian Buku', CURRENT_DATE(), '10:00:00', 'Meminjam buku materi ajar biologi.'),
(NULL, 'Budi Santoso', 'umum', 'Wali Murid', 'Umum', 'L', 'Konsultasi / Kunjungan Perpustakaan', CURRENT_DATE(), '10:45:00', 'Melihat fasilitas koleksi buku sekolah.')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- =========================================================
-- 20. Tabel Konfirmasi Baca Pengumuman (announcement_reads)
-- =========================================================
CREATE TABLE IF NOT EXISTS `announcement_reads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `announcement_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `read_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_announcement_user` (`announcement_id`, `user_id`),
    CONSTRAINT `fk_reads_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reads_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 21. Tabel Catatan Wali Kelas & Ekstrakurikuler Rapor (student_report_notes)
-- =========================================================
CREATE TABLE IF NOT EXISTS `student_report_notes` (
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
    UNIQUE KEY `uk_student_report_period` (`student_id`, `academic_year`, `semester`),
    CONSTRAINT `fk_srn_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `student_report_notes` (`student_id`, `academic_year`, `semester`, `homeroom_notes`, `extracurricular`, `created_by`) VALUES
(5, '2026/2027', 'Ganjil', 'Ahmad menunjukkan ketekunan belajar yang sangat baik, terutama pada bidang sains dan matematika. Tingkatkan rasa percaya diri saat presentasi di depan kelas.', 'Pramuka (A - Sangat Aktif), Kelompok Ilmiah Remaja/KIR (A - Ketua Tim Penelitian)', 3)
ON DUPLICATE KEY UPDATE `homeroom_notes`=VALUES(`homeroom_notes`);

-- =========================================================
-- 22. Tabel PPDB (ppdb_registrations)
-- =========================================================
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
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ppdb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ppdb_registrations` (
    `id`, `registration_no`, `full_name`, `nisn`, `nik`, `gender`, `birth_place`, `birth_date`, `religion`, `phone`, `email`, `address`,
    `previous_school`, `chosen_major`, `track_type`, `distance_km`, `achievement_desc`, `achievement_level`, `affirmation_no`,
    `score_math`, `score_science`, `score_indonesian`, `score_english`, `calculated_score`,
    `parent_name`, `parent_phone`, `parent_job`,
    `document_status`, `status`, `selection_score`, `notes`, `qr_token`
) VALUES
(1, 'PPDB-2026-0001', 'Farhan Ramadhan', '0081234567', '3201012304080001', 'L', 'Jakarta', '2008-04-12', 'Islam', '081298765432', 'farhan.ppdb@gmail.com', 'Jl. Kenanga No. 18, Kebayoran Baru, Jakarta Selatan', 'SMP Negeri 1 Jakarta', 'MIPA (Matematika & IPA)', 'prestasi', NULL, 'Juara 1 OSN Matematika Tingkat Kota', 'kabupaten', NULL, 92.00, 90.00, 88.00, 86.00, 94.00, 'Rahmat Hidayat', '081398765432', 'Wiraswasta', 'lengkap', 'diverifikasi', 94.00, 'Berkas lengkap dan prestasi terverifikasi sertifikat asli.', 'f4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5'),
(2, 'PPDB-2026-0002', 'Siti Nurhaliza', '0089876543', '3201015609080002', 'P', 'Bandung', '2008-09-25', 'Islam', '085712349876', 'siti.ppdb@gmail.com', 'Jl. Anggrek No. 04, Cilandak, Jakarta Selatan', 'SMP Negeri 5 Jakarta', 'IPS (Ilmu Pengetahuan Sosial)', 'zonasi', 1.80, NULL, NULL, NULL, 85.00, 84.00, 90.00, 87.00, 93.50, 'Bambang Supriyanto', '085812349876', 'PNS', 'lengkap', 'menunggu_verifikasi', 93.50, 'Jarak domisili terverifikasi melalui titik peta KK.', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2')
ON DUPLICATE KEY UPDATE `full_name`=VALUES(`full_name`);

-- =========================================================
-- 23. Tabel `news` (Berita & Artikel Sekolah Terintegrasi)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `news` (`id`, `title`, `slug`, `excerpt`, `content`, `category`, `thumbnail`, `author_id`, `status`, `approved_by`, `approved_at`, `views`, `is_featured`, `published_at`) VALUES
(1, 'Siswa SMA Teladan Raih Medali Emas Olimpiade Sains Nasional (OSN) 2026', 'siswa-sma-teladan-raih-medali-emas-olimpiade-sains-nasional-osn-2026', 'Kabar membanggakan datang dari kontingen sains sekolah yang berhasil menorehkan prestasi tertinggi di tingkat nasional.', '<p>Civitas akademika SMA Teladan kembali menorehkan prestasi gemilang di kancah nasional. Siswa perwakilan sekolah berhasil meraih <strong>Medali Emas</strong> pada ajang <em>Olimpiade Sains Nasional (OSN) 2026</em> bidang Astronomi dan Informatika yang diselenggarakan oleh Balai Pengembangan Talenta Indonesia (BPTI).</p><p>Kepala Sekolah menyampaikan rasa syukur dan apresiasi setinggi-tingginya kepada para siswa pembina dan bapak/ibu guru yang telah mendedikasikan waktu dalam program pembinaan intensif selama enam bulan terakhir. Semoga prestasi ini menjadi pemantik semangat bagi seluruh peserta didik lainnya.</p>', 'prestasi', NULL, 3, 'published', 1, NOW(), 142, 1, NOW()),
(2, 'Pentas Seni & Budaya Nusantara Meriahkan Perayaan Bulan Bahasa', 'pentas-seni-budaya-nusantara-meriahkan-perayaan-bulan-bahasa', 'Ratusan siswa menampilkan karya seni tari tradisi, teater sastra, dan pameran literasi kreatif di panggung terbuka sekolah.', '<p>Suasana semarak dan penuh warna menghiasi halaman utama sekolah dalam perhelatan <strong>Pentas Seni & Budaya Nusantara</strong>. Kegiatan tahunan ini diselenggarakan dalam rangka memperingati Bulan Bahasa dan Sastra Nasional dengan tema <em>\"Mewujudkan Generasi Berkebinekaan Global yang Berakar Budaya Lokal\"</em>.</p><p>Berbagai pertunjukan memukau ditampilkan oleh siswa dari kelas X hingga XII, mulai dari sendratari daerah, musikalisasi puisi karya sastrawan ternama, drama musikal bertema kepahlawanan, hingga stan bazar kuliner tradisional yang diinisiasi oleh kelompok kewirausahaan siswa.</p>', 'kegiatan', NULL, 2, 'published', NULL, NOW(), 89, 1, NOW()),
(3, 'Workshop Inovasi Pembelajaran Berbasis AI untuk Guru dan Pendidik', 'workshop-inovasi-pembelajaran-berbasis-ai-untuk-guru-dan-pendidik', 'Pelatihan pemanfaatan teknologi kecerdasan buatan etis guna menyusun bahan ajar adaptif dan asesmen interaktif.', '<p>Sebagai langkah mempercepat transformasi digital sekolah, diselenggarakan workshop bertajuk <strong>\"Pemanfaatan Etis Kecerdasan Buatan (AI) dalam Desain Pembelajaran Berdiferensiasi\"</strong> yang diikuti oleh 45 guru mata pelajaran.</p><p>Narasumber ahli teknologi pendidikan memaparkan teknik menyusun instrumen penilaian autentik, simulasi pembuatan rubrik digital, dan pemanfaatan asisten kecerdasan buatan untuk mengidentifikasi kebutuhan belajar individual setiap peserta didik secara lebih cepat dan akurat.</p>', 'akademik', NULL, 1, 'published', NULL, NOW(), 64, 0, NOW()),
(4, 'Kunjungan Studi Literasi & Pengenalan Riset ke Balai Konservasi Alam', 'kunjungan-studi-literasi-pengenalan-riset-ke-balai-konservasi-alam', 'Siswa kelas XI melakukan observasi lapangan dan analisis keanekaragaman hayati dalam program pembelajaran luar kelas.', '<p>Siswa-siswi peminatan Biologi dan Geografi mengadakan kunjungan ilmiah lapangan ke Balai Konservasi Alam Daerah. Kegiatan ini bertujuan memperkuat wawasan ekologi dan memberikan pengalaman observasi empiris terhadap flora dan fauna endemik yang dilindungi.</p><p>Laporan hasil observasi akan dipublikasikan dalam bentuk majalah dinding ilmiah serta infografis digital di perpustakaan sekolah.</p>', 'kegiatan', NULL, 3, 'pending', NULL, NULL, 0, 0, NULL)
ON DUPLICATE KEY UPDATE `title`=VALUES(`title`);

-- =========================================================
-- 24. Modul Tata Usaha: Arsip Surat Masuk & Keluar (mail_archives)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 25. Modul Sarpras: Inventaris Barang & Aset Sekolah (inventory_items)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `inventory_items` (`item_code`, `item_name`, `category`, `room_location`, `condition_status`, `quantity`, `unit`, `funding_source`, `purchase_year`, `purchase_cost`, `notes`, `recorded_by`) VALUES
('INV-2026-001', 'Proyektor Epson EB-X500', 'Elektronik & Multimedia', 'Ruang Multimedia / R. Guru', 'baik', 4, 'Unit', 'BOS Reguler', 2026, 6500000.00, 'Dapat dipinjam guru untuk presentasi KBM.', 2),
('INV-2026-002', 'Laptop Lenovo ThinkPad L14', 'Elektronik & Multimedia', 'Laboratorium Komputer', 'baik', 25, 'Unit', 'DAK Fisik', 2026, 11200000.00, 'Unit client ujian CBT dan praktikum siswa.', 2),
('INV-2026-003', 'Mikroskop Binokuler Olympus CX23', 'Alat Peraga Lab IPA', 'Laboratorium Biologi', 'baik', 8, 'Set', 'BOS Kinerja', 2025, 14500000.00, 'Lengkap dengan lensa okuler dan preparat awetan.', 2)
ON DUPLICATE KEY UPDATE `item_name`=VALUES(`item_name`);

-- =========================================================
-- 26. Modul Sarpras: Peminjaman Inventaris (inventory_loans)
-- =========================================================
CREATE TABLE IF NOT EXISTS `inventory_loans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT NOT NULL,
    `borrower_name` VARCHAR(150) NOT NULL,
    `borrower_role` VARCHAR(50) NOT NULL DEFAULT 'guru',
    `borrower_phone` VARCHAR(30) DEFAULT NULL,
    `user_id` INT DEFAULT NULL,
    `borrow_date` DATE NOT NULL,
    `expected_return_date` DATE NOT NULL,
    `actual_return_date` DATE DEFAULT NULL,
    `status` ENUM('menunggu', 'dipinjam', 'kembali', 'ditolak') NOT NULL DEFAULT 'dipinjam',
    `purpose` VARCHAR(255) NOT NULL,
    `notes` TEXT DEFAULT NULL,
    `approval_notes` TEXT DEFAULT NULL,
    `recorded_by` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_inv_loan_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 27. Modul Keuangan: Buku Kas Umum / Pengeluaran (financial_expenses)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 28. Modul Tambahan Guru: Jurnal Mengajar Harian (teaching_journals)
-- =========================================================
CREATE TABLE IF NOT EXISTS `teaching_journals` (
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
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_journal_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_journal_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 29. Modul Tambahan Guru: Perangkat Ajar & Kurikulum (curriculum_documents)
-- =========================================================
CREATE TABLE IF NOT EXISTS `curriculum_documents` (
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
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_curriculum_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 30. Modul Tambahan Guru: Presensi Mandiri Guru (teacher_attendance)
-- =========================================================
CREATE TABLE IF NOT EXISTS `teacher_attendance` (
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
    UNIQUE KEY `uk_teacher_attendance_date` (`teacher_id`, `date`),
    CONSTRAINT `fk_t_att_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Seed Data untuk Fitur Guru Baru
INSERT INTO `teaching_journals` (`teacher_id`, `class_id`, `subject_name`, `date`, `start_time`, `end_time`, `topic`, `competency_notes`, `student_attendance_summary`, `class_condition_notes`, `status`) VALUES
(3, 1, 'Matematika Wajib', CURRENT_DATE(), '07:30:00', '09:00:00', 'Penyelesaian Sistem Persamaan Linier Tiga Variabel (SPLTV)', 'Siswa mampu menentukan himpunan penyelesaian dengan metode eliminasi-substitusi gabungan.', 'Hadir: 31, Izin: 1, Sakit: 0, Alpa: 0', 'KBM berjalan interaktif dan kondusif, seluruh siswa aktif berdiskusi.', 'terlaksana'),
(3, 2, 'Matematika Peminatan', DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY), '09:15:00', '10:45:00', 'Persamaan Trigonometri Dasar Sinus dan Cosinus', 'Pemahaman konsep kuadran dan rumus sudut berelasi dalam lingkaran satuan.', 'Hadir: 30, Izin: 0, Sakit: 2, Alpa: 0', 'Siswa antusias mengerjakan latihan mandiri di papan tulis.', 'terlaksana');

INSERT INTO `curriculum_documents` (`teacher_id`, `subject_name`, `grade_level`, `academic_year`, `semester`, `doc_type`, `title`, `description`, `status`, `review_notes`) VALUES
(3, 'Matematika', '10', '2026/2027', 'Ganjil', 'modul_ajar', 'Modul Ajar SPLTV Berbasis Pembelajaran Saintifik', 'Modul ajar lengkap dengan lembar kerja peserta didik (LKPD) dan rubrik asesmen formatif.', 'disetujui', 'Komponen asesmen dan langkah KBM sudah sesuai Kurikulum Merdeka.'),
(3, 'Matematika', '10', '2026/2027', 'Ganjil', 'silabus', 'Alur Tujuan Pembelajaran (ATP) Matematika Fase E', 'Peta alur tujuan pembelajaran dan capaian kompetensi semester ganjil dan genap.', 'diajukan', NULL);

INSERT INTO `teacher_attendance` (`teacher_id`, `date`, `check_in_time`, `check_out_time`, `status`, `notes`) VALUES
(3, CURRENT_DATE(), '06:55:00', NULL, 'hadir', 'Tiba sebelum bel pagi, siap mengajar KBM jam pertama.');

-- =========================================================
-- 31. Modul Integrasi: WhatsApp Gateway Notification Logs (wa_notification_logs)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- 32. Modul Penggajian & Honor Guru/Staf (teacher_payrolls)
-- =========================================================
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
    UNIQUE KEY `uk_teacher_payroll_month` (`teacher_id`, `month_period`),
    CONSTRAINT `fk_payroll_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auto-migration kolom baru pada tabel eksisting
ALTER TABLE `exam_submissions` ADD COLUMN IF NOT EXISTS `violation_count` INT NOT NULL DEFAULT 0 AFTER `status`;
ALTER TABLE `exam_submissions` ADD COLUMN IF NOT EXISTS `is_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `violation_count`;
ALTER TABLE `teacher_attendance` ADD COLUMN IF NOT EXISTS `latitude` DECIMAL(10,8) DEFAULT NULL AFTER `proof_doc`;
ALTER TABLE `teacher_attendance` ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(11,8) DEFAULT NULL AFTER `latitude`;
ALTER TABLE `teacher_attendance` ADD COLUMN IF NOT EXISTS `distance_meters` INT DEFAULT NULL AFTER `longitude`;
ALTER TABLE `student_bills` ADD COLUMN IF NOT EXISTS `payment_channel` VARCHAR(50) DEFAULT NULL AFTER `status`;
ALTER TABLE `student_bills` ADD COLUMN IF NOT EXISTS `gateway_trx_id` VARCHAR(100) DEFAULT NULL AFTER `payment_channel`;
ALTER TABLE `student_bills` ADD COLUMN IF NOT EXISTS `va_number` VARCHAR(50) DEFAULT NULL AFTER `gateway_trx_id`;
ALTER TABLE `student_bills` ADD COLUMN IF NOT EXISTS `paid_at` DATETIME DEFAULT NULL AFTER `va_number`;
ALTER TABLE `ppdb_registrations` ADD COLUMN IF NOT EXISTS `achievement_cert_doc` VARCHAR(255) DEFAULT NULL AFTER `photo_doc`;
ALTER TABLE `ppdb_registrations` ADD COLUMN IF NOT EXISTS `achievements_json` LONGTEXT DEFAULT NULL AFTER `achievement_cert_doc`;

-- Seed Konfigurasi Tambahan Fase 1-5
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

-- Sample Log Notifikasi WA
INSERT INTO `wa_notification_logs` (`recipient_phone`, `recipient_name`, `message`, `category`, `status`, `gateway_provider`) VALUES
('081234567890', 'Bpk. Hendra Wijaya', 'Yth. Bpk/Ibu Hendra Wijaya, ananda Ahmad Fauzi hari ini Jumat, 26 Sep 2026 tercatat HADIR tepat waktu di SMA Bina Bangsa. Terima kasih.', 'presensi', 'terkirim', 'Fonnte (Simulated)'),
('081234567890', 'Bpk. Hendra Wijaya', 'Yth. Wali Murid Ahmad Fauzi, tagihan SPP Bulan September 2026 sebesar Rp 350.000 telah LUNAS terverifikasi otomatis melalui Virtual Account BCA. Kuitansi resmi dapat diunduh pada portal.', 'keuangan', 'terkirim', 'Fonnte (Simulated)'),
('081298765432', 'Ibu Siti Rahmawati', 'Pemberitahuan: Jadwal Ujian Tengah Semester (UTS) Gasal akan diselenggarakan mulai Senin pekan depan. Harap mendampingi belajar ananda di rumah.', 'pengumuman', 'terkirim', 'Fonnte (Simulated)');

-- Sample Payroll Penggajian Guru
INSERT INTO `teacher_payrolls` (`payroll_code`, `teacher_id`, `month_period`, `base_salary`, `position_allowance`, `teaching_hours`, `teaching_rate_per_hour`, `total_teaching_honor`, `attendance_deductions`, `net_salary`, `payment_date`, `status`, `payment_method`, `notes`, `created_by`) VALUES
('PAY-202609-001', 3, 'September 2026', 3200000.00, 500000.00, 48, 50000.00, 2400000.00, 0.00, 6100000.00, '2026-09-25', 'dibayar', 'Transfer BNI', 'Gaji pokok + Tunjangan Wali Kelas X MIPA 1 + Honor KBM 48 JP.', 1);

-- =========================================================
-- Tabel `external_apis` (Manajemen API Eksternal & Gateway Luar)
-- =========================================================
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `external_apis` 
(`api_code`, `name`, `category`, `provider`, `endpoint_url`, `api_key`, `api_secret`, `auth_type`, `auth_header_name`, `http_method`, `custom_headers`, `payload_template`, `is_active`, `environment`, `timeout_seconds`, `description`)
VALUES
('wa_gateway', 'WhatsApp Notification Gateway', 'whatsapp', 'Fonnte (Official/Demo)', 'https://api.fonnte.com/send', 'fonnte_demo_token_2026', '', 'header', 'Authorization', 'POST', '{"Accept":"application/json"}', '{\n  "target": "{target_phone}",\n  "message": "{message_text}"\n}', 1, 'production', 10, 'Layanan API pengiriman pesan WhatsApp otomatis untuk notifikasi presensi, tagihan SPP, dan pengumuman sekolah.'),
('payment_gateway', 'Payment Gateway & Virtual Account (Midtrans)', 'payment', 'Midtrans Snap & Core API', 'https://app.sandbox.midtrans.com/snap/v1/transactions', 'SB-Mid-server-demo-token-2026', 'SB-Mid-client-demo-token-2026', 'basic', 'Authorization', 'POST', '{"Accept":"application/json","Content-Type":"application/json"}', '{\n  "transaction_details": {\n    "order_id": "BILL-EXAMPLE-001",\n    "gross_amount": 350000\n  }\n}', 1, 'sandbox', 15, 'Integrasi gateway pembayaran online instan untuk tagihan SPP siswa (QRIS, VA BCA, Mandiri, BNI, BRI).'),
('ai_service', 'AI Assistant & Auto-Grading (Gemini / OpenAI)', 'ai', 'Google Gemini AI', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent', 'AIzaSyDemoKeyExample2026', '', 'query', 'key', 'POST', '{"Content-Type":"application/json"}', '{\n  "contents\": [\n    {\n      "parts": [\n        {"text": "Analisis dan beri penilaian ringkas untuk jawaban esai ini..."}\n      ]\n    }\n  ]\n}', 1, 'production', 20, 'Kecerdasan Buatan (LLM) untuk asisten penyusunan materi KBM, bank soal otomatis, dan evaluasi esai siswa CBT.'),
('email_service', 'Layanan Email Transaksional & SMTP API', 'mail', 'SendGrid / Resend API', 'https://api.sendgrid.com/v3/mail/send', 'SG.demo_sendgrid_token_example', '', 'bearer', 'Authorization', 'POST', '{"Content-Type":"application/json"}', '{\n  "personalizations\": [\n    {"to": [{"email": "{recipient_email}"}]}\n  ],\n  "from": {"email": "noreply@binabangsa.sch.id"},\n  "subject": "{subject}",\n  "content": [{"type": "text/plain", "value": "{body}"}]\n}', 1, 'production', 10, 'Pengiriman surat elektronik otomatis untuk kuitansi pembayaran SPP, kartu ujian siswa, dan verifikasi PPDB.'),
('maps_geocoding', 'Peta Lokasi & Geocoding Geofencing', 'maps', 'Nominatim OpenStreetMap', 'https://nominatim.openstreetmap.org/search', '', '', 'none', 'Authorization', 'GET', '{"User-Agent":"ManajemenPHP-SchoolSystem/1.0"}', '{}', 1, 'production', 10, 'Verifikasi koordinat GPS latitude/longitude sekolah dan validasi radius geofencing presensi mandiri guru.'),
('weather_api', 'Prakiraan Cuaca Terbuka (BMKG / Open-Meteo)', 'weather', 'Open-Meteo Weather API', 'https://api.open-meteo.com/v1/forecast?latitude=-6.2088&longitude=106.8456&current_weather=true', '', '', 'none', '', 'GET', '{"Accept":"application/json"}', '{}', 1, 'production', 8, 'Data cuaca dan prakiraan suhu lingkungan sekolah secara langsung untuk monitoring kegiatan upacara dan olahraga luar ruangan.');

-- =========================================================
-- MODUL PROJEK PENGUATAN PROFIL PELAJAR PANCASILA (P5)
-- Standar BSKAP Kemendikbudristek (Kurikulum Merdeka)
-- =========================================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================================
-- MODUL EKSTRAKURIKULER & PRESTASI SISWA
-- ========================================================
CREATE TABLE IF NOT EXISTS `extracurriculars` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `category` ENUM('Olahraga', 'Seni & Budaya', 'Keagamaan', 'Sains & Teknologi', 'Kepemimpinan & Organisasi', 'Bahasa & Literasi', 'Lainnya') NOT NULL DEFAULT 'Lainnya',
    `description` TEXT DEFAULT NULL,
    `instructor_id` INT DEFAULT NULL,
    `location` VARCHAR(150) DEFAULT NULL,
    `schedule_day` ENUM('Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu') NOT NULL DEFAULT 'Jumat',
    `start_time` TIME NOT NULL DEFAULT '15:30:00',
    `end_time` TIME NOT NULL DEFAULT '17:00:00',
    `max_members` INT NOT NULL DEFAULT 40,
    `badge_color` VARCHAR(50) DEFAULT 'indigo',
    `icon` VARCHAR(100) DEFAULT 'fa-star',
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (`instructor_id`),
    INDEX (`category`),
    INDEX (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `extracurricular_members` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `extracurricular_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `academic_year` VARCHAR(20) NOT NULL DEFAULT '2024/2025',
    `semester` ENUM('1', '2') NOT NULL DEFAULT '1',
    `role` ENUM('Ketua', 'Wakil Ketua', 'Sekretaris', 'Bendahara', 'Anggota') NOT NULL DEFAULT 'Anggota',
    `status` ENUM('active', 'pending', 'rejected', 'graduated') NOT NULL DEFAULT 'active',
    `report_predicate` ENUM('A', 'B', 'C', 'D') DEFAULT NULL,
    `report_notes` TEXT DEFAULT NULL,
    `joined_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ekskul_member` (`extracurricular_id`, `student_id`, `academic_year`, `semester`),
    INDEX (`student_id`),
    INDEX (`extracurricular_id`),
    INDEX (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `extracurricular_activities` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `extracurricular_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `activity_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `activity_type` ENUM('Latihan Rutin', 'Pentas / Pertandingan', 'Ujian Tingkat / Pelantikan', 'Bakti Sosial', 'Lainnya') NOT NULL DEFAULT 'Latihan Rutin',
    `location` VARCHAR(150) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (`extracurricular_id`),
    INDEX (`activity_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `extracurricular_attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `activity_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `status` ENUM('hadir', 'izin', 'sakit', 'alpa') NOT NULL DEFAULT 'hadir',
    `remarks` VARCHAR(255) DEFAULT NULL,
    `recorded_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_activity_attendance` (`activity_id`, `student_id`),
    INDEX (`student_id`),
    INDEX (`activity_id`),
    INDEX (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `extracurricular_achievements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `extracurricular_id` INT DEFAULT NULL,
    `student_id` INT NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `competition_name` VARCHAR(255) NOT NULL,
    `level` ENUM('Sekolah', 'Kecamatan', 'Kabupaten', 'Provinsi', 'Nasional', 'Internasional') NOT NULL DEFAULT 'Kabupaten',
    `rank_position` VARCHAR(100) NOT NULL DEFAULT 'Juara 1',
    `award_date` DATE NOT NULL,
    `organizer` VARCHAR(200) DEFAULT NULL,
    `certificate_number` VARCHAR(150) DEFAULT NULL,
    `certificate_file` VARCHAR(255) DEFAULT NULL,
    `verification_status` ENUM('verified', 'pending', 'rejected') NOT NULL DEFAULT 'verified',
    `verified_by` INT DEFAULT NULL,
    `description` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (`student_id`),
    INDEX (`extracurricular_id`),
    INDEX (`level`),
    INDEX (`verification_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;