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

-- 1. Tabel `users`
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
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
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_exams_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabel `exam_questions` (Butir Soal Campuran: Pilihan Ganda & Esai/Uraian)
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
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
    `document_status` ENUM('lengkap', 'perlu_revisi', 'ditolak') NOT NULL DEFAULT 'lengkap',
    `rejection_reason` TEXT DEFAULT NULL,
    `status` ENUM('menunggu_verifikasi', 'diverifikasi', 'lulus_seleksi', 'tidak_lulus', 'diterima') NOT NULL DEFAULT 'menunggu_verifikasi',
    `selection_score` DECIMAL(5,2) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
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






