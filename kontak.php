<?php
/**
 * Halaman Kontak & Pusat Layanan Informasi
 * Manajemen-PHP — Sistem Terpadu Manajemen Sekolah
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

$school_info = getSchoolSettings($pdo);

// CSRF Token setup
if (empty($_SESSION['contact_csrf_token'])) {
    $_SESSION['contact_csrf_token'] = bin2hex(random_bytes(32));
}

$is_logged_in = isset($_SESSION['user_id']);
$user_name = $_SESSION['user_name'] ?? '';
$user_email = $_SESSION['user_email'] ?? '';
$user_role = $_SESSION['user_role'] ?? '';

// Flash message variables
$success_msg = '';
$error_msg = '';
$submitted_ticket = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $honeypot = $_POST['website_hp'] ?? ''; // Honeypot bot protection

    if (!hash_equals($_SESSION['contact_csrf_token'], $token)) {
        $error_msg = 'Sesi pengiriman kedaluwarsa. Silakan refresh dan coba lagi.';
    } elseif (!empty($honeypot)) {
        // Spam bot triggered honeypot
        $error_msg = 'Deteksi otomatis: Pengiriman ditolak.';
    } else {
        $name     = trim((string)($_POST['name'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $phone    = trim((string)($_POST['phone'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'Umum'));
        $subject  = trim((string)($_POST['subject'] ?? ''));
        $message  = trim((string)($_POST['message'] ?? ''));

        // Validation
        if ($name === '' || $email === '' || $subject === '' || $message === '') {
            $error_msg = 'Mohon lengkapi semua kolom wajib bertanda bintang (*).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Format alamat email tidak valid.';
        } elseif (mb_strlen($message) < 10) {
            $error_msg = 'Pesan terlalu singkat. Mohon jelaskan kebutuhan atau pertanyaan Anda minimal 10 karakter.';
        } else {
            // Auto create contact_messages table if not exists
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `contact_messages` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `ticket_code` VARCHAR(32) NOT NULL UNIQUE,
                        `name` VARCHAR(150) NOT NULL,
                        `email` VARCHAR(150) NOT NULL,
                        `phone` VARCHAR(50) DEFAULT NULL,
                        `category` VARCHAR(60) NOT NULL DEFAULT 'Umum',
                        `subject` VARCHAR(255) NOT NULL,
                        `message` TEXT NOT NULL,
                        `status` ENUM('unread', 'read', 'replied') NOT NULL DEFAULT 'unread',
                        `ip_address` VARCHAR(45) DEFAULT NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $ticket_code = 'TIK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

                $stmt = $pdo->prepare("
                    INSERT INTO `contact_messages` (`ticket_code`, `name`, `email`, `phone`, `category`, `subject`, `message`, `ip_address`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$ticket_code, $name, $email, $phone, $category, $subject, $message]);

                // Reset CSRF token
                $_SESSION['contact_csrf_token'] = bin2hex(random_bytes(32));
                $submitted_ticket = $ticket_code;
                $success_msg = 'Pesan Anda berhasil terkirim ke Pusat Layanan ' . htmlspecialchars($school_info['school_name'] ?? 'Sekolah') . '. Tim kami akan menghubungi Anda melalui email atau WhatsApp.';
            } catch (Exception $e) {
                $error_msg = 'Terjadi kendala saat menyimpan pesan: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Clean phone number for WhatsApp link
$raw_phone = $school_info['school_phone'] ?? '021-789-0123';
$clean_wa = preg_replace('/[^0-9]/', '', $raw_phone);
if (str_starts_with($clean_wa, '0')) {
    $clean_wa = '62' . substr($clean_wa, 1);
}
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Hubungi Kami - <?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?></title>
    <meta name="description" content="Pusat informasi dan kontak resmi <?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?>. Layanan bantuan PPDB, tata usaha, hotline WhatsApp, dan pengaduan civitas sekolah.">

    <!-- Tailwind CSS v4 Browser CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    
    <!-- Font Awesome 6 Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">

    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .glow-radial-blue {
            background: radial-gradient(circle at 50% 0%, rgba(37, 99, 235, 0.18) 0%, rgba(15, 23, 42, 0) 70%);
        }
        .glow-radial-amber {
            background: radial-gradient(circle at 50% 100%, rgba(245, 158, 11, 0.12) 0%, rgba(15, 23, 42, 0) 70%);
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-blue-600 selection:text-white flex flex-col justify-between">

    <?php
    $nav_active = 'kontak';
    require_once __DIR__ . '/includes/navbar.php';
    ?>

    <!-- ================= MAIN CONTENT ================= -->
    <main class="flex-1 relative">

        <!-- Background Ambient Lights -->
        <div class="pointer-events-none absolute inset-x-0 top-0 h-96 glow-radial-blue"></div>
        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-96 glow-radial-amber"></div>

        <!-- HERO HEADER -->
        <section class="relative pt-12 pb-10 sm:pt-16 sm:pb-12 text-center px-4 max-w-4xl mx-auto">
            <div class="inline-flex items-center gap-2 rounded-full border border-amber-500/30 bg-amber-500/10 px-3.5 py-1 text-xs font-semibold text-amber-300 mb-5">
                <i class="fa-solid fa-headset animate-bounce text-[10px]"></i>
                <span>Pusat Layanan & Bantuan Terpadu</span>
            </div>

            <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight text-white mb-4">
                Hubungi Kami & Layanan <span class="bg-gradient-to-r from-amber-400 via-orange-400 to-amber-200 bg-clip-text text-transparent">Civitas Sekolah</span>
            </h1>

            <p class="text-sm sm:text-base text-slate-300 max-w-2xl mx-auto leading-relaxed">
                Punya pertanyaan seputar penerimaan siswa baru (PPDB), administrasi akademik, legalisir surat, atau kendala portal? Tim tata usaha dan pengelola siap melayani Anda.
            </p>
        </section>

        <!-- QUICK CONTACT CARDS (4 COLUMNS) -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-12">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                
                <!-- Card 1: Alamat Kampus -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/60 backdrop-blur-md p-5 flex flex-col justify-between hover:border-blue-500/30 hover:bg-slate-900/80 transition group shadow-lg">
                    <div>
                        <div class="h-11 w-11 rounded-2xl bg-blue-600/20 border border-blue-500/30 text-blue-400 flex items-center justify-center text-lg mb-4 group-hover:scale-105 transition">
                            <i class="fa-solid fa-location-dot"></i>
                        </div>
                        <h3 class="text-sm font-bold text-white mb-1.5">Alamat Sekolah</h3>
                        <p class="text-xs text-slate-300 leading-relaxed">
                            <?= htmlspecialchars($school_info['school_address'] ?? 'Jl. Pendidikan Nasional No. 45, Kebayoran Baru, Jakarta') ?>
                        </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-white/5">
                        <a href="https://maps.google.com/?q=<?= urlencode($school_info['school_address'] ?? 'Jakarta') ?>" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-400 hover:text-blue-300 transition">
                            <span>Buka di Google Maps</span>
                            <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                        </a>
                    </div>
                </div>

                <!-- Card 2: Telepon & WhatsApp -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/60 backdrop-blur-md p-5 flex flex-col justify-between hover:border-emerald-500/30 hover:bg-slate-900/80 transition group shadow-lg">
                    <div>
                        <div class="h-11 w-11 rounded-2xl bg-emerald-600/20 border border-emerald-500/30 text-emerald-400 flex items-center justify-center text-lg mb-4 group-hover:scale-105 transition">
                            <i class="fa-solid fa-phone"></i>
                        </div>
                        <h3 class="text-sm font-bold text-white mb-1.5">Hotline & WhatsApp</h3>
                        <p class="text-xs text-slate-300 leading-relaxed font-mono">
                            <?= htmlspecialchars($school_info['school_phone'] ?? '(021) 789-0123') ?>
                        </p>
                        <p class="text-[11px] text-slate-400 mt-1">Layanan cepat chat respon staf TU</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-white/5">
                        <a href="https://wa.me/<?= htmlspecialchars($clean_wa) ?>?text=<?= urlencode('Halo Admin ' . ($school_info['school_name'] ?? 'Sekolah') . ', saya ingin bertanya seputar layanan sekolah.') ?>" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-400 hover:text-emerald-300 transition">
                            <i class="fa-brands fa-whatsapp text-sm"></i>
                            <span>Chat WhatsApp Langsung</span>
                        </a>
                    </div>
                </div>

                <!-- Card 3: Email Resmi -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/60 backdrop-blur-md p-5 flex flex-col justify-between hover:border-indigo-500/30 hover:bg-slate-900/80 transition group shadow-lg">
                    <div>
                        <div class="h-11 w-11 rounded-2xl bg-indigo-600/20 border border-indigo-500/30 text-indigo-400 flex items-center justify-center text-lg mb-4 group-hover:scale-105 transition">
                            <i class="fa-solid fa-envelope"></i>
                        </div>
                        <h3 class="text-sm font-bold text-white mb-1.5">Email Resmi</h3>
                        <p class="text-xs text-slate-300 leading-relaxed break-all">
                            <?= htmlspecialchars($school_info['school_email'] ?? 'info@binabangsa.sch.id') ?>
                        </p>
                        <p class="text-[11px] text-slate-400 mt-1">Respon maksimal 1 x 24 jam kerja</p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-white/5">
                        <a href="mailto:<?= htmlspecialchars($school_info['school_email'] ?? 'info@binabangsa.sch.id') ?>" class="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-400 hover:text-indigo-300 transition">
                            <span>Kirim Email Langsung</span>
                            <i class="fa-solid fa-paper-plane text-[10px]"></i>
                        </a>
                    </div>
                </div>

                <!-- Card 4: Jam Operasional -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/60 backdrop-blur-md p-5 flex flex-col justify-between hover:border-amber-500/30 hover:bg-slate-900/80 transition group shadow-lg">
                    <div>
                        <div class="h-11 w-11 rounded-2xl bg-amber-600/20 border border-amber-500/30 text-amber-400 flex items-center justify-center text-lg mb-4 group-hover:scale-105 transition">
                            <i class="fa-regular fa-clock"></i>
                        </div>
                        <h3 class="text-sm font-bold text-white mb-1.5">Jam Kerja Layanan</h3>
                        <p class="text-xs text-slate-300">
                            <strong>Senin – Jumat:</strong> 07.30 – 15.30 WIB
                        </p>
                        <p class="text-xs text-slate-400 mt-1">
                            <strong>Sabtu:</strong> 08.00 – 12.00 WIB (Terbatas)
                        </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-white/5">
                        <span class="inline-flex items-center gap-1.5 text-[11px] text-emerald-400 bg-emerald-500/10 px-2.5 py-0.5 rounded-full border border-emerald-500/20">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                            Hotline Aktif Hari Kerja
                        </span>
                    </div>
                </div>

            </div>
        </section>

        <!-- MAIN SECTION: FORM + SIDEBAR DIRECTORY -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-16">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                
                <!-- LEFT COLUMN: FORM HUBUNGI KAMI (7 COLS) -->
                <div class="lg:col-span-7 rounded-3xl border border-white/10 bg-slate-900/80 backdrop-blur-md p-6 sm:p-8 shadow-2xl relative overflow-hidden">
                    <div class="absolute -right-20 -bottom-20 w-64 h-64 bg-amber-500/5 rounded-full blur-3xl pointer-events-none"></div>

                    <!-- Alerts -->
                    <?php if ($success_msg): ?>
                        <div class="mb-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-5 text-emerald-200">
                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-sm">
                                    <i class="fa-solid fa-circle-check"></i>
                                </span>
                                <div class="space-y-1">
                                    <p class="text-sm font-bold text-white">Tiket Pengaduan / Pesan Diterima!</p>
                                    <p class="text-xs leading-relaxed"><?= $success_msg ?></p>
                                    <?php if ($submitted_ticket): ?>
                                        <div class="mt-3 inline-flex items-center gap-2 rounded-xl bg-emerald-950/70 border border-emerald-500/30 px-3.5 py-1.5 text-xs font-mono font-bold text-emerald-300">
                                            <span>Nomor Tiket:</span>
                                            <span class="underline decoration-dotted"><?= htmlspecialchars($submitted_ticket) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($error_msg): ?>
                        <div class="mb-6 rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-rose-200 flex items-start gap-3">
                            <i class="fa-solid fa-circle-exclamation text-rose-400 text-lg mt-0.5"></i>
                            <div class="text-xs leading-relaxed">
                                <p class="font-bold text-white mb-0.5">Perhatian</p>
                                <p><?= $error_msg ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Form Header -->
                    <div class="mb-6 border-b border-white/10 pb-4">
                        <h2 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-paper-plane text-amber-400 text-lg"></i>
                            <span>Kirim Pesan atau Pertanyaan</span>
                        </h2>
                        <p class="text-xs text-slate-400 mt-1">
                            Sampaikan pertanyaan, kendala teknis, pengaduan, atau kritik saran Anda. Kami akan menindaklanjuti secara resmi.
                        </p>
                    </div>

                    <!-- Contact Form -->
                    <form action="kontak.php" method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['contact_csrf_token']) ?>">
                        
                        <!-- Anti-spam Honeypot -->
                        <div class="hidden" aria-hidden="true">
                            <label for="website_hp">Jangan isi kolom ini jika Anda manusia</label>
                            <input type="text" id="website_hp" name="website_hp" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Name -->
                            <div>
                                <label for="name" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                    Nama Lengkap <span class="text-rose-400">*</span>
                                </label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-xs">
                                        <i class="fa-solid fa-user"></i>
                                    </span>
                                    <input type="text" id="name" name="name" required
                                           value="<?= htmlspecialchars($_POST['name'] ?? $user_name) ?>"
                                           placeholder="Nama lengkap Anda"
                                           class="w-full rounded-xl border border-white/10 bg-white/5 py-2.5 pl-10 pr-3.5 text-xs sm:text-sm text-white placeholder-slate-500 focus:border-amber-400 focus:bg-white/10 focus:outline-none transition">
                                </div>
                            </div>

                            <!-- Email -->
                            <div>
                                <label for="email" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                    Alamat Email <span class="text-rose-400">*</span>
                                </label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-xs">
                                        <i class="fa-solid fa-envelope"></i>
                                    </span>
                                    <input type="email" id="email" name="email" required
                                           value="<?= htmlspecialchars($_POST['email'] ?? $user_email) ?>"
                                           placeholder="alamat@email.com"
                                           class="w-full rounded-xl border border-white/10 bg-white/5 py-2.5 pl-10 pr-3.5 text-xs sm:text-sm text-white placeholder-slate-500 focus:border-amber-400 focus:bg-white/10 focus:outline-none transition">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <!-- Phone / WhatsApp -->
                            <div>
                                <label for="phone" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                    No. Handphone / WhatsApp
                                </label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-xs">
                                        <i class="fa-solid fa-phone"></i>
                                    </span>
                                    <input type="text" id="phone" name="phone"
                                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                                           placeholder="Contoh: 081234567890"
                                           class="w-full rounded-xl border border-white/10 bg-white/5 py-2.5 pl-10 pr-3.5 text-xs sm:text-sm text-white placeholder-slate-500 focus:border-amber-400 focus:bg-white/10 focus:outline-none transition">
                                </div>
                            </div>

                            <!-- Category -->
                            <div>
                                <label for="category" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                    Kategori Layanan <span class="text-rose-400">*</span>
                                </label>
                                <div class="relative">
                                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-xs">
                                        <i class="fa-solid fa-list-check"></i>
                                    </span>
                                    <select id="category" name="category" required
                                            class="w-full rounded-xl border border-white/10 bg-slate-900 py-2.5 pl-10 pr-8 text-xs sm:text-sm text-white focus:border-amber-400 focus:bg-slate-800 focus:outline-none transition appearance-none">
                                        <option value="PPDB Online">Penerimaan Siswa Baru (PPDB)</option>
                                        <option value="Administrasi & Tata Usaha" selected>Administrasi & Tata Usaha</option>
                                        <option value="Kendala Akun & Portal">Kendala Login / Akun Portal</option>
                                        <option value="Akademik & Rapor">Akademik, Nilai & Pembelajaran</option>
                                        <option value="Bimbingan Konseling">Bimbingan Konseling (BK)</option>
                                        <option value="Pengaduan & Saran">Kritik, Saran & Pengaduan</option>
                                        <option value="Kerjasama Lembaga">Kerjasama & Kemitraan</option>
                                    </select>
                                    <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-slate-500 text-xs">
                                        <i class="fa-solid fa-chevron-down"></i>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Subject -->
                        <div>
                            <label for="subject" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                Subjek / Pokok Masalah <span class="text-rose-400">*</span>
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-xs">
                                    <i class="fa-solid fa-tag"></i>
                                </span>
                                <input type="text" id="subject" name="subject" required
                                       value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
                                       placeholder="Tuliskan inti pertanyaan atau subjek"
                                       class="w-full rounded-xl border border-white/10 bg-white/5 py-2.5 pl-10 pr-3.5 text-xs sm:text-sm text-white placeholder-slate-500 focus:border-amber-400 focus:bg-white/10 focus:outline-none transition">
                            </div>
                        </div>

                        <!-- Message -->
                        <div>
                            <label for="message" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                Pesan Lengkap <span class="text-rose-400">*</span>
                            </label>
                            <textarea id="message" name="message" rows="5" required
                                      placeholder="Uraikan detail pertanyaan, nomor registrasi (jika berkaitan dengan PPDB), atau kronologi kendala..."
                                      class="w-full rounded-xl border border-white/10 bg-white/5 p-3.5 text-xs sm:text-sm text-white placeholder-slate-500 focus:border-amber-400 focus:bg-white/10 focus:outline-none transition leading-relaxed"></textarea>
                        </div>

                        <!-- Submit Button -->
                        <div class="pt-2 flex flex-col sm:flex-row items-center justify-between gap-3">
                            <p class="text-[11px] text-slate-400">
                                <i class="fa-solid fa-shield-halved text-amber-400 mr-1"></i> Data Anda dijamin kerahasiaannya oleh pihak sekolah.
                            </p>
                            <button type="submit"
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 px-6 py-3 text-xs sm:text-sm font-bold text-slate-950 transition shadow-lg shadow-amber-500/20 cursor-pointer">
                                <i class="fa-solid fa-paper-plane text-xs"></i>
                                <span>Kirim Pesan Sekarang</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- RIGHT COLUMN: DIRECTORY & OPERATIONAL INFO (5 COLS) -->
                <div class="lg:col-span-5 space-y-6">
                    
                    <!-- WhatsApp Direct Support Box -->
                    <div class="rounded-3xl border border-emerald-500/30 bg-emerald-950/20 backdrop-blur-md p-6 relative overflow-hidden shadow-xl">
                        <div class="flex items-start gap-4">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-500/20 text-emerald-400 text-2xl border border-emerald-500/30">
                                <i class="fa-brands fa-whatsapp"></i>
                            </span>
                            <div>
                                <h3 class="text-base font-bold text-white mb-1">Layanan Cepat WhatsApp</h3>
                                <p class="text-xs text-slate-300 leading-relaxed mb-4">
                                    Butuh jawaban kilat perihal pendaftaran PPDB atau persyaratan administrasi? Terhubung langsung dengan narahubung sekolah.
                                </p>
                                <a href="https://wa.me/<?= htmlspecialchars($clean_wa) ?>?text=<?= urlencode('Halo Staf Tata Usaha ' . ($school_info['school_name'] ?? 'Sekolah') . ', saya membutuhkan informasi.') ?>"
                                   target="_blank"
                                   class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2.5 text-xs font-bold text-white transition shadow-lg shadow-emerald-600/30">
                                    <i class="fa-brands fa-whatsapp text-sm"></i>
                                    <span>Mulai Chat WhatsApp</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Department Direct Directory -->
                    <div class="rounded-3xl border border-white/10 bg-slate-900/60 backdrop-blur-md p-6 shadow-xl">
                        <h3 class="text-sm font-bold text-white mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-sitemap text-amber-400"></i>
                            <span>Direktori Layanan Internal</span>
                        </h3>
                        
                        <div class="divide-y divide-white/5 space-y-3 text-xs">
                            <!-- Item 1 -->
                            <div class="pt-3 first:pt-0">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="font-bold text-white">Sekretariat Tata Usaha (TU)</p>
                                        <p class="text-[11px] text-slate-400">Legalisir, surat aktif, mutasi siswa</p>
                                    </div>
                                    <span class="text-[10px] text-slate-300 bg-white/5 px-2 py-0.5 rounded-md border border-white/10">Ruang TU</span>
                                </div>
                            </div>

                            <!-- Item 2 -->
                            <div class="pt-3">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="font-bold text-emerald-400">Panitia PPDB Online</p>
                                        <p class="text-[11px] text-slate-400">Verifikasi berkas calon peserta didik</p>
                                    </div>
                                    <a href="ppdb/ppdb.php" class="text-[10px] text-emerald-300 hover:underline">Halaman PPDB &rarr;</a>
                                </div>
                            </div>

                            <!-- Item 3 -->
                            <div class="pt-3">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="font-bold text-indigo-400">Perpustakaan Digital</p>
                                        <p class="text-[11px] text-slate-400">Peminjaman buku, e-book, dan literasi</p>
                                    </div>
                                    <a href="perpustakaan.php" class="text-[10px] text-indigo-300 hover:underline">Katalog &rarr;</a>
                                </div>
                            </div>

                            <!-- Item 4 -->
                            <div class="pt-3">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="font-bold text-sky-400">Helpdesk IT & Portal Akun</p>
                                        <p class="text-[11px] text-slate-400">Reset kata sandi, kendala role & sesi</p>
                                    </div>
                                    <a href="auth/login.php" class="text-[10px] text-sky-300 hover:underline">Login &rarr;</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Interactive Map Card -->
                    <div class="rounded-3xl border border-white/10 bg-slate-900/60 backdrop-blur-md p-6 shadow-xl">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-map-location-dot text-blue-400"></i>
                                <span>Peta Lokasi Kampus</span>
                            </h3>
                            <a href="https://maps.google.com/?q=<?= urlencode($school_info['school_address'] ?? 'Jakarta') ?>" target="_blank" class="text-[11px] text-blue-400 hover:underline">
                                Petunjuk Arah &rarr;
                            </a>
                        </div>
                        
                        <div class="relative rounded-2xl overflow-hidden border border-white/10 h-44 bg-slate-950 flex items-center justify-center text-center p-4">
                            <iframe 
                                title="Peta Lokasi Sekolah"
                                width="100%" 
                                height="100%" 
                                style="border:0;" 
                                loading="lazy" 
                                allowfullscreen
                                referrerpolicy="no-referrer-when-downgrade"
                                src="https://maps.google.com/maps?q=<?= urlencode($school_info['school_address'] ?? 'Jakarta Indonesia') ?>&t=&z=14&ie=UTF8&iwloc=&output=embed"
                                class="absolute inset-0 opacity-80 hover:opacity-100 transition duration-300">
                            </iframe>
                            <div class="pointer-events-none absolute bottom-2 left-2 right-2 rounded-xl bg-slate-950/85 backdrop-blur-sm px-3 py-1.5 border border-white/10 text-left">
                                <p class="text-[11px] font-bold text-white truncate"><?= htmlspecialchars($school_info['school_name'] ?? 'Kampus Utama') ?></p>
                                <p class="text-[10px] text-slate-400 truncate"><?= htmlspecialchars($school_info['school_address'] ?? '') ?></p>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </section>

        <!-- FAQ SECTION ACCORDION -->
        <section class="max-w-4xl mx-auto px-4 sm:px-6 mb-16">
            <div class="text-center mb-8">
                <span class="inline-block text-xs font-bold uppercase tracking-wider text-amber-400 mb-2">FAQ Bantuan</span>
                <h2 class="text-2xl font-bold text-white">Pertanyaan yang Sering Diajukan</h2>
                <p class="text-xs text-slate-400 mt-1">Jawaban cepat untuk pertanyaan lazim perihal layanan sekolah.</p>
            </div>

            <div class="space-y-3">
                <!-- FAQ 1 -->
                <div class="rounded-2xl border border-white/10 bg-slate-900/60 overflow-hidden">
                    <button type="button" onclick="toggleFaq(this)" class="faq-toggle w-full p-4 text-left flex items-center justify-between text-xs sm:text-sm font-bold text-white hover:bg-white/5 transition">
                        <span>Bagaimana cara mendaftar sebagai calon siswa baru (PPDB)?</span>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-200"></i>
                    </button>
                    <div class="faq-content hidden p-4 pt-0 text-xs text-slate-300 leading-relaxed border-t border-white/5">
                        Anda dapat mengakses menu <a href="ppdb/ppdb.php" class="text-emerald-400 underline font-semibold">PPDB Online</a> pada navigasi utama, mengisi formulir biodata lengkap, dan mengunggah dokumen persyaratan. Bukti registrasi dapat dicetak dan dipantau status verifikasinya secara berkala.
                    </div>
                </div>

                <!-- FAQ 2 -->
                <div class="rounded-2xl border border-white/10 bg-slate-900/60 overflow-hidden">
                    <button type="button" onclick="toggleFaq(this)" class="faq-toggle w-full p-4 text-left flex items-center justify-between text-xs sm:text-sm font-bold text-white hover:bg-white/5 transition">
                        <span>Bagaimana jika lupa kata sandi akun portal siswa/guru?</span>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-200"></i>
                    </button>
                    <div class="faq-content hidden p-4 pt-0 text-xs text-slate-300 leading-relaxed border-t border-white/5">
                        Silakan hubungi Administrator IT atau Staf Tata Usaha melalui formulir di atas dengan memilih kategori <strong>Kendala Akun & Portal</strong> atau kirim pesan WhatsApp langsung ke hotline resmi dengan menyertakan NISN / NIP dan bukti identitas.
                    </div>
                </div>

                <!-- FAQ 3 -->
                <div class="rounded-2xl border border-white/10 bg-slate-900/60 overflow-hidden">
                    <button type="button" onclick="toggleFaq(this)" class="faq-toggle w-full p-4 text-left flex items-center justify-between text-xs sm:text-sm font-bold text-white hover:bg-white/5 transition">
                        <span>Apakah orang tua dapat memantau presensi dan nilai anak?</span>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-200"></i>
                    </button>
                    <div class="faq-content hidden p-4 pt-0 text-xs text-slate-300 leading-relaxed border-t border-white/5">
                        Ya, sistem Manajemen-PHP menyediakan hak akses khusus role <strong>Orang Tua / Wali Murid</strong>. Anda dapat masuk menggunakan akun orang tua yang telah terhubung dengan NISN putra/putri Anda untuk memantau rekap absensi harian dan perkembangan nilai rapor.
                    </div>
                </div>

                <!-- FAQ 4 -->
                <div class="rounded-2xl border border-white/10 bg-slate-900/60 overflow-hidden">
                    <button type="button" onclick="toggleFaq(this)" class="faq-toggle w-full p-4 text-left flex items-center justify-between text-xs sm:text-sm font-bold text-white hover:bg-white/5 transition">
                        <span>Kapan jam operasional pelayanan langsung di kantor Tata Usaha?</span>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-200"></i>
                    </button>
                    <div class="faq-content hidden p-4 pt-0 text-xs text-slate-300 leading-relaxed border-t border-white/5">
                        Pelayanan tatap muka di kantor Tata Usaha buka setiap hari kerja Senin sampai Jumat pukul 07.30 hingga 15.30 WIB. Khusus hari Sabtu dilayani secara terbatas untuk keperluan legalisir dan informasi PPDB mulai 08.00 hingga 12.00 WIB.
                    </div>
                </div>
            </div>
        </section>

    </main>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    ?>

    <!-- Vanilla JS Interactivity -->
    <script>
        // Toggle Mobile Menu
        const btnMobileMenu = document.getElementById('btnMobileMenu');
        const mobileMenu = document.getElementById('mobileMenu');
        if (btnMobileMenu && mobileMenu) {
            btnMobileMenu.addEventListener('click', () => {
                mobileMenu.classList.toggle('hidden');
            });
        }

        // Toggle FAQ Accordion
        function toggleFaq(btn) {
            const content = btn.nextElementSibling;
            const icon = btn.querySelector('.fa-chevron-down');
            const isHidden = content.classList.contains('hidden');

            document.querySelectorAll('.faq-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.faq-toggle i').forEach(el => el.classList.remove('rotate-180'));

            if (isHidden) {
                content.classList.remove('hidden');
                icon.classList.add('rotate-180');
            }
        }
    </script>
</body>
</html>
