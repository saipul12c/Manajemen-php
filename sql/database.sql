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
    `author_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_announcements_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabel `assignments` (Tugas Pembelajaran oleh Guru untuk Siswa & Orang Tua)
CREATE TABLE IF NOT EXISTS `assignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject` VARCHAR(100) NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `due_date` DATE NOT NULL,
    `teacher_id` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_assignments_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabel `assignment_submissions` (Status Pengerjaan Tugas oleh Siswa)
CREATE TABLE IF NOT EXISTS `assignment_submissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `assignment_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `status` ENUM('belum', 'selesai') NOT NULL DEFAULT 'belum',
    `notes` TEXT DEFAULT NULL,
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


