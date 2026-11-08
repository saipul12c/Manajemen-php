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

// Defensive migration for contact_messages table & columns
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `contact_messages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `ticket_code` VARCHAR(50) DEFAULT NULL,
            `name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NOT NULL,
            `phone` VARCHAR(50) DEFAULT NULL,
            `category` VARCHAR(60) NOT NULL DEFAULT 'Umum',
            `subject` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'baru',
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `admin_notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $col_check = $pdo->query("SHOW COLUMNS FROM `contact_messages`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('ticket_code', $col_check, true)) {
        $pdo->exec("ALTER TABLE `contact_messages` ADD COLUMN `ticket_code` VARCHAR(50) DEFAULT NULL AFTER `id`");
    }
    if (!in_array('category', $col_check, true)) {
        $pdo->exec("ALTER TABLE `contact_messages` ADD COLUMN `category` VARCHAR(60) NOT NULL DEFAULT 'Umum' AFTER `phone`");
    }
    if (!in_array('ip_address', $col_check, true)) {
        $pdo->exec("ALTER TABLE `contact_messages` ADD COLUMN `ip_address` VARCHAR(45) DEFAULT NULL");
    }
    if (!in_array('admin_notes', $col_check, true)) {
        $pdo->exec("ALTER TABLE `contact_messages` ADD COLUMN `admin_notes` TEXT DEFAULT NULL AFTER `ip_address`");
    }
    if (!in_array('updated_at', $col_check, true)) {
        $pdo->exec("ALTER TABLE `contact_messages` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    }
} catch (Exception $e) {}

// Helper function: Mask email for public tracking privacy
function maskEmail(string $email): string {
    if (strpos($email, '@') === false) return $email;
    [$user, $domain] = explode('@', $email, 2);
    $len = strlen($user);
    if ($len <= 2) {
        return substr($user, 0, 1) . '***@' . $domain;
    }
    return substr($user, 0, 2) . str_repeat('*', min(4, max(1, $len - 3))) . substr($user, -1) . '@' . $domain;
}

// Helper function: Mask phone for public tracking privacy
function maskPhone(?string $phone): string {
    if (!$phone) return '-';
    $clean = preg_replace('/[^0-9]/', '', $phone);
    $len = strlen($clean);
    if ($len <= 6) return substr($clean, 0, 2) . '****';
    return substr($clean, 0, 4) . '****' . substr($clean, -3);
}

// Helper function: Category label
function getContactCatLabel(string $cat): string {
    return match(strtolower($cat)) {
        'ppdb'      => 'PPDB / Pendaftaran',
        'akademik'  => 'Akademik & KBM',
        'keuangan'  => 'Keuangan / SPP',
        'teknis'    => 'Bantuan Teknis & Portal',
        'saran'     => 'Kritik & Saran',
        default     => ucfirst($cat)
    };
}

// Helper function: Category badge styling
function getContactCatBadge(string $cat): string {
    return match(strtolower($cat)) {
        'ppdb'      => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
        'akademik'  => 'border-sky-500/30 bg-sky-500/10 text-sky-300',
        'keuangan'  => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
        'teknis'    => 'border-indigo-500/30 bg-indigo-500/10 text-indigo-300',
        'saran'     => 'border-purple-500/30 bg-purple-500/10 text-purple-300',
        default     => 'border-slate-500/30 bg-slate-500/10 text-slate-300'
    };
}

// -------------------------------------------------------------
// AJAX API ENDPOINT: Track Ticket (Dynamic JSON)
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'track_api') {
    header('Content-Type: application/json; charset=utf-8');
    $api_code = trim((string)($_GET['ticket'] ?? ''));
    if ($api_code === '') {
        echo json_encode(['success' => false, 'message' => 'Silakan masukkan nomor tiket yang valid.']);
        exit;
    }
    try {
        $stmt_api = $pdo->prepare("SELECT * FROM `contact_messages` WHERE UPPER(TRIM(ticket_code)) = UPPER(TRIM(?)) LIMIT 1");
        $stmt_api->execute([$api_code]);
        $ticket = $stmt_api->fetch(PDO::FETCH_ASSOC);
        if ($ticket) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'ticket_code' => $ticket['ticket_code'],
                    'name' => $ticket['name'],
                    'email_masked' => maskEmail($ticket['email']),
                    'phone_masked' => maskPhone($ticket['phone']),
                    'category' => $ticket['category'],
                    'category_label' => getContactCatLabel($ticket['category']),
                    'category_badge' => getContactCatBadge($ticket['category']),
                    'subject' => $ticket['subject'],
                    'message' => $ticket['message'],
                    'status' => $ticket['status'],
                    'admin_notes' => $ticket['admin_notes'] ?? '',
                    'created_at' => $ticket['created_at'],
                    'created_at_fmt' => date('d M Y, H:i', strtotime($ticket['created_at'])) . ' WIB',
                    'updated_at' => $ticket['updated_at'] ?? $ticket['created_at'],
                    'updated_at_fmt' => date('d M Y, H:i', strtotime($ticket['updated_at'] ?? $ticket['created_at'])) . ' WIB'
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Tiket dengan nomor "' . htmlspecialchars($api_code) . '" tidak ditemukan. Mohon periksa kembali kode tiket Anda (contoh: TIK-' . date('Ymd') . '-XXXXX).'
            ]);
        }
    } catch (Exception $e) {
        error_log("Contact track error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan pada sistem saat memeriksa tiket. Silakan coba kembali.']);
    }
    exit;
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_contact') {
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
            try {
                $ticket_code = 'TIK-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

                $stmt = $pdo->prepare("
                    INSERT INTO `contact_messages` (`ticket_code`, `name`, `email`, `phone`, `category`, `subject`, `message`, `ip_address`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$ticket_code, $name, $email, $phone, $category, $subject, $message, $ip_address]);

                // Reset CSRF token
                $_SESSION['contact_csrf_token'] = bin2hex(random_bytes(32));
                $submitted_ticket = $ticket_code;
                $success_msg = 'Pesan Anda berhasil terkirim ke Pusat Layanan ' . htmlspecialchars($school_info['school_name'] ?? 'Sekolah') . '. Tiket resmi Anda telah dibuat. Anda dapat langsung melacak progres penanganan tiket menggunakan nomor di bawah.';
            } catch (Exception $e) {
                error_log("Contact submit error: " . $e->getMessage());
                $error_msg = 'Terjadi kendala saat menyimpan pesan Anda. Silakan coba beberapa saat lagi.';
            }
        }
    }
}

// Handle Server-Side Ticket Tracking (via GET / POST)
$track_code = trim((string)($_GET['ticket'] ?? $_GET['lacak'] ?? $_POST['ticket_code'] ?? ''));
$tracked_ticket = null;
$track_error = '';

if ($track_code !== '') {
    try {
        $stmt_tr = $pdo->prepare("SELECT * FROM `contact_messages` WHERE UPPER(TRIM(ticket_code)) = UPPER(TRIM(?)) LIMIT 1");
        $stmt_tr->execute([$track_code]);
        $tracked_ticket = $stmt_tr->fetch(PDO::FETCH_ASSOC);
        if (!$tracked_ticket) {
            $track_error = 'Tiket dengan nomor "' . htmlspecialchars($track_code) . '" tidak ditemukan. Mohon periksa kembali kode tiket Anda.';
        }
    } catch (Exception $e) {
        $track_error = 'Terjadi kendala saat memeriksa tiket: ' . htmlspecialchars($e->getMessage());
    }
}

// Active Tab determination: 'form' or 'track'
$active_tab = 'form';
if (!empty($tracked_ticket) || !empty($track_error) || isset($_GET['ticket']) || isset($_GET['lacak']) || (isset($_GET['tab']) && $_GET['tab'] === 'track')) {
    $active_tab = 'track';
}

// Clean phone number for WhatsApp link
$raw_phone = $school_info['school_phone'] ?? '021-789-0123';
$clean_wa = preg_replace('/[^0-9]/', '', $raw_phone);
if (str_starts_with($clean_wa, '0')) {
    $clean_wa = '62' . substr($clean_wa, 1);
}

// Live Office & Service Status (WIB Timezone)
date_default_timezone_set('Asia/Jakarta');
$day_num = (int)date('N'); // 1 = Senin, 6 = Sabtu, 7 = Minggu
$current_time_str = date('H:i');

$is_office_open = false;
$office_status_title = 'Layanan Offline Selesai (Di Luar Jam Kerja)';
$office_status_desc = 'Pesan atau tiket online tetap dapat dikirim 24 jam dan akan ditindaklanjuti oleh staf di jam kerja berikutnya.';
$office_badge_class = 'border-rose-500/30 bg-rose-500/10 text-rose-300';
$office_dot_class = 'bg-rose-500';
$office_sla_text = 'Respon jam kerja berikutnya';

if ($day_num >= 1 && $day_num <= 5) { // Senin - Jumat
    if ($current_time_str >= '07:30' && $current_time_str <= '15:30') {
        $is_office_open = true;
        $office_status_title = 'Kantor TU & Hotline Online Sedang Buka';
        $office_status_desc = 'Staf tata usaha aktif bertugas. Respon chat dan pengaduan tiket berjalan cepat.';
        $office_badge_class = 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300';
        $office_dot_class = 'bg-emerald-400 animate-pulse';
        $office_sla_text = 'Respon aktif: < 30 - 60 Menit';
    } else {
        $office_status_title = 'Jam Pelayanan Offline Hari Ini Selesai';
        $office_status_desc = 'Buka kembali besok pukul 07.30 WIB. Formulir tiket online tetap aktif 24 jam.';
        $office_badge_class = 'border-amber-500/30 bg-amber-500/10 text-amber-300';
        $office_dot_class = 'bg-amber-400';
        $office_sla_text = 'Buka besok 07.30 WIB';
    }
} elseif ($day_num === 6) { // Sabtu
    if ($current_time_str >= '08:00' && $current_time_str <= '12:00') {
        $is_office_open = true;
        $office_status_title = 'Layanan Akhir Pekan (Terbatas) Sedang Buka';
        $office_status_desc = 'Pelayanan khusus informasi PPDB dan legalisir dokumen s/d 12.00 WIB.';
        $office_badge_class = 'border-amber-500/30 bg-amber-500/10 text-amber-300';
        $office_dot_class = 'bg-amber-400 animate-pulse';
        $office_sla_text = 'Layanan khusus s/d 12.00 WIB';
    } else {
        $office_status_title = 'Layanan Akhir Pekan Tutup';
        $office_status_desc = 'Kantor buka kembali Senin pukul 07.30 WIB. Sistem tiket daring tetap aktif.';
        $office_badge_class = 'border-slate-500/30 bg-slate-500/10 text-slate-300';
        $office_dot_class = 'bg-slate-400';
        $office_sla_text = 'Buka Senin 07.30 WIB';
    }
} else { // Minggu
    $office_status_title = 'Hari Libur Pelayanan (Minggu)';
    $office_status_desc = 'Kantor administrasi dan hotline libur. Buka kembali Senin pukul 07.30 WIB.';
    $office_badge_class = 'border-slate-500/30 bg-slate-500/10 text-slate-300';
    $office_dot_class = 'bg-slate-500';
    $office_sla_text = 'Buka Senin 07.30 WIB';
}

$indo_days = [
    'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
];
$indo_months = [
    'Jan' => 'Jan', 'Feb' => 'Feb', 'Mar' => 'Mar', 'Apr' => 'Apr', 'May' => 'Mei', 'Jun' => 'Jun',
    'Jul' => 'Jul', 'Aug' => 'Agt', 'Sep' => 'Sep', 'Oct' => 'Okt', 'Nov' => 'Nov', 'Dec' => 'Des'
];
$current_day_indo = $indo_days[date('l')] ?? date('l');
$current_date_indo = date('d ') . ($indo_months[date('M')] ?? date('M')) . date(' Y');
$current_wib_display = $current_day_indo . ', ' . $current_date_indo . ' · ' . date('H:i') . ' WIB';
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Hubungi Kami & Lacak Tiket - <?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?></title>
    <meta name="description" content="Pusat informasi, kontak resmi, dan pelacakan tiket pengaduan publik <?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?>. Layanan bantuan PPDB, tata usaha, hotline WhatsApp, dan pengaduan civitas sekolah.">

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
        @media print {
            body {
                background: #ffffff !important;
                color: #0f172a !important;
            }
            header, footer, nav, #quickContactSection, #heroSection, #faqSection, .no-print, #tabSwitcherBar, #searchTicketBox, #rightDirectoryCol, .ambient-glow {
                display: none !important;
            }
            #mainInteractionBox {
                border: none !important;
                background: transparent !important;
                padding: 0 !important;
                box-shadow: none !important;
            }
            #ticketPrintArea {
                display: block !important;
                background: #ffffff !important;
                border: 2px solid #0f172a !important;
                color: #0f172a !important;
                box-shadow: none !important;
                border-radius: 12px !important;
                padding: 24px !important;
                margin: 0 auto !important;
                width: 100% !important;
            }
            #ticketPrintArea * {
                color: #0f172a !important;
                border-color: #cbd5e1 !important;
                background-color: transparent !important;
                text-shadow: none !important;
            }
            .print-only-block {
                display: block !important;
            }
        }
        .print-only-block {
            display: none;
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
        <section id="heroSection" class="relative pt-12 pb-10 sm:pt-16 sm:pb-12 text-center px-4 max-w-4xl mx-auto no-print">
            <div class="inline-flex items-center gap-2 rounded-full border border-amber-500/30 bg-amber-500/10 px-3.5 py-1 text-xs font-semibold text-amber-300 mb-4">
                <i class="fa-solid fa-headset animate-bounce text-[10px]"></i>
                <span>Pusat Layanan & Bantuan Terpadu</span>
            </div>

            <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight text-white mb-4">
                Hubungi Kami & Layanan <span class="bg-gradient-to-r from-amber-400 via-orange-400 to-amber-200 bg-clip-text text-transparent">Civitas Sekolah</span>
            </h1>

            <p class="text-sm sm:text-base text-slate-300 max-w-2xl mx-auto leading-relaxed">
                Punya pertanyaan seputar penerimaan siswa baru (PPDB), administrasi akademik, legalisir surat, atau kendala portal? Tim tata usaha dan pengelola siap melayani Anda.
            </p>

            <!-- Real-time Live Office & Service Desk Status Banner -->
            <div class="mt-5 inline-flex flex-wrap items-center justify-center gap-2 rounded-2xl border <?= $office_badge_class ?> px-4 py-2 text-xs backdrop-blur-md shadow-lg">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="absolute inline-flex h-full w-full rounded-full <?= $office_dot_class ?> opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 <?= $office_dot_class ?>"></span>
                </span>
                <span class="font-bold text-white"><?= htmlspecialchars($office_status_title) ?></span>
                <span class="text-slate-400 hidden sm:inline">•</span>
                <span class="text-slate-200 hidden sm:inline"><?= htmlspecialchars($office_sla_text) ?></span>
                <span class="text-slate-400 hidden sm:inline">•</span>
                <span class="text-amber-300 font-mono text-[11px]"><?= htmlspecialchars($current_wib_display) ?></span>
            </div>

            <!-- Quick Action Buttons in Hero -->
            <div class="mt-6 flex flex-wrap items-center justify-center gap-2.5 sm:gap-3">
                <button type="button" onclick="switchContactTab('form'); document.getElementById('mainInteractionBox').scrollIntoView({behavior: 'smooth'});" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 px-5 py-2.5 text-xs sm:text-sm font-bold text-slate-950 transition shadow-lg shadow-amber-500/20 cursor-pointer">
                    <i class="fa-solid fa-paper-plane text-xs"></i>
                    <span>Kirim Pesan / Tiket Baru</span>
                </button>
                <button type="button" onclick="switchContactTab('track'); document.getElementById('mainInteractionBox').scrollIntoView({behavior: 'smooth'});" class="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/5 hover:bg-white/10 hover:border-amber-400/50 px-5 py-2.5 text-xs sm:text-sm font-semibold text-slate-200 hover:text-white transition shadow-lg cursor-pointer">
                    <i class="fa-solid fa-magnifying-glass text-amber-400 text-xs"></i>
                    <span>Lacak Status Tiket</span>
                </button>
                <a href="#faqSection" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-xs sm:text-sm font-medium text-slate-300 hover:text-white transition cursor-pointer">
                    <i class="fa-solid fa-circle-question text-sky-400 text-xs"></i>
                    <span>Pusat FAQ</span>
                </a>
            </div>
        </section>

        <!-- QUICK CONTACT CARDS (4 COLUMNS) -->
        <section id="quickContactSection" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-12 no-print">
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
                            <strong>Hari Kerja:</strong> <?= htmlspecialchars($school_info['school_work_hours_weekday'] ?? 'Senin – Jumat: 07.30 – 15.30 WIB') ?>
                        </p>
                        <p class="text-xs text-slate-400 mt-1">
                            <strong>Akhir Pekan:</strong> <?= htmlspecialchars($school_info['school_work_hours_weekend'] ?? 'Sabtu: 08.00 – 12.00 WIB (Terbatas)') ?>
                        </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-white/5 flex items-center justify-between">
                        <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold px-2.5 py-0.5 rounded-full border <?= $office_badge_class ?>">
                            <span class="h-1.5 w-1.5 rounded-full <?= $office_dot_class ?>"></span>
                            <?= $is_office_open ? 'Layanan Buka' : 'Layanan Tutup' ?>
                        </span>
                        <span class="text-[10px] text-slate-400 font-mono">WIB</span>
                    </div>
                </div>

            </div>
        </section>

        <!-- MAIN SECTION: FORM & TRACKER + SIDEBAR DIRECTORY -->
        <section id="interactionSection" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-16">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                
                <!-- LEFT COLUMN: INTERACTIVE HUB (FORM + TRACKER) (7 COLS) -->
                <div class="lg:col-span-7 rounded-3xl border border-white/10 bg-slate-900/80 backdrop-blur-md p-6 sm:p-8 shadow-2xl relative overflow-hidden" id="mainInteractionBox">
                    <div class="absolute -right-20 -bottom-20 w-64 h-64 bg-amber-500/5 rounded-full blur-3xl pointer-events-none ambient-glow"></div>

                    <!-- SEGMENTED TAB SWITCHER -->
                    <div id="tabSwitcherBar" class="flex items-center gap-1.5 p-1.5 rounded-2xl bg-white/5 border border-white/10 mb-6 no-print">
                        <button type="button" id="tabBtnForm" onclick="switchContactTab('form')"
                                class="flex-1 py-2.5 px-3 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer <?= $active_tab === 'form' ? 'bg-gradient-to-r from-amber-500 to-orange-500 text-slate-950 shadow-md shadow-amber-500/20' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5' ?>">
                            <i class="fa-solid fa-paper-plane text-xs"></i>
                            <span>Kirim Pesan Baru</span>
                        </button>
                        <button type="button" id="tabBtnTrack" onclick="switchContactTab('track')"
                                class="flex-1 py-2.5 px-3 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer <?= $active_tab === 'track' ? 'bg-gradient-to-r from-amber-500 to-orange-500 text-slate-950 shadow-md shadow-amber-500/20' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5' ?>">
                            <i class="fa-solid fa-magnifying-glass text-xs"></i>
                            <span>Lacak Status Tiket</span>
                            <span class="hidden sm:inline-block px-2 py-0.5 rounded-full text-[10px] <?= $active_tab === 'track' ? 'bg-slate-950/20 text-slate-950 font-extrabold' : 'bg-amber-500/20 text-amber-300 border border-amber-500/30' ?>">Cek Progres</span>
                        </button>
                    </div>

                    <!-- ================= TAB 1: FORMULIR HUBUNGI KAMI ================= -->
                    <div id="viewFormContainer" class="<?= $active_tab === 'form' ? '' : 'hidden' ?>">

                        <!-- Alerts -->
                        <?php if ($success_msg): ?>
                            <div class="mb-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-5 text-emerald-200 shadow-xl">
                                <div class="flex items-start gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-sm">
                                        <i class="fa-solid fa-circle-check text-lg"></i>
                                    </span>
                                    <div class="space-y-2 flex-1">
                                        <p class="text-sm font-bold text-white">Tiket Pengaduan / Pesan Diterima!</p>
                                        <p class="text-xs leading-relaxed text-emerald-200"><?= $success_msg ?></p>
                                        <?php if ($submitted_ticket): ?>
                                            <div class="mt-3 p-3 rounded-xl bg-slate-950/80 border border-emerald-500/30 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                                <div class="space-y-0.5">
                                                    <span class="text-[10px] text-slate-400 font-semibold block uppercase tracking-wider">Nomor Tiket Anda:</span>
                                                    <span class="font-mono text-base font-black text-amber-400 tracking-wider select-all"><?= htmlspecialchars($submitted_ticket) ?></span>
                                                </div>
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <button type="button" onclick="copyTicketNumber('<?= htmlspecialchars($submitted_ticket) ?>')" class="rounded-lg border border-white/10 bg-white/5 hover:bg-white/15 px-3 py-1.5 text-xs font-semibold text-slate-200 transition cursor-pointer flex items-center gap-1.5">
                                                        <i class="fa-regular fa-copy text-[11px]"></i>
                                                        <span id="copyBtnText">Salin Nomor</span>
                                                    </button>
                                                    <button type="button" onclick="loadTrackTicket('<?= htmlspecialchars($submitted_ticket) ?>')" class="rounded-lg bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 px-3.5 py-1.5 text-xs font-bold text-slate-950 transition cursor-pointer flex items-center gap-1.5 shadow-md shadow-amber-500/20">
                                                        <i class="fa-solid fa-magnifying-glass text-[11px]"></i>
                                                        <span>Lacak Tiket Ini &rarr;</span>
                                                    </button>
                                                </div>
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
                        <div class="mb-5 border-b border-white/10 pb-4">
                            <h2 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-paper-plane text-amber-400 text-lg"></i>
                                <span>Kirim Pesan atau Pertanyaan</span>
                            </h2>
                            <p class="text-xs text-slate-400 mt-1">
                                Sampaikan pertanyaan, kendala teknis, pengaduan, atau kritik saran Anda. Kami akan menindaklanjuti secara resmi dengan penomoran tiket otomatis.
                            </p>
                        </div>

                        <!-- QUICK TOPIC TEMPLATE PILLS -->
                        <div class="mb-6 rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-amber-400 flex items-center gap-1.5">
                                    <i class="fa-solid fa-wand-magic-sparkles text-xs"></i> Template Pesan Cepat (1-Klik Isi)
                                </span>
                                <button type="button" onclick="resetFormFields()" class="text-[11px] text-slate-400 hover:text-rose-400 transition cursor-pointer flex items-center gap-1">
                                    <i class="fa-solid fa-rotate-left text-[10px]"></i> Kosongkan
                                </button>
                            </div>
                            <p class="text-[11px] text-slate-400 mb-3 leading-relaxed">
                                Pilih jenis kebutuhan Anda di bawah agar sistem otomatis mengisi kategori, subjek, dan draf pesan terpandu:
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" onclick="applyQuickTemplate('ppdb')" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3 py-1.5 text-xs font-semibold text-emerald-300 transition cursor-pointer">
                                    <i class="fa-solid fa-graduation-cap text-[11px]"></i> Info PPDB & Berkas
                                </button>
                                <button type="button" onclick="applyQuickTemplate('legalisir')" class="inline-flex items-center gap-1.5 rounded-xl border border-sky-500/30 bg-sky-500/10 hover:bg-sky-500/20 px-3 py-1.5 text-xs font-semibold text-sky-300 transition cursor-pointer">
                                    <i class="fa-solid fa-file-circle-check text-[11px]"></i> Legalisir Dokumen
                                </button>
                                <button type="button" onclick="applyQuickTemplate('keuangan')" class="inline-flex items-center gap-1.5 rounded-xl border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 px-3 py-1.5 text-xs font-semibold text-amber-300 transition cursor-pointer">
                                    <i class="fa-solid fa-money-bill-wave text-[11px]"></i> Konfirmasi SPP
                                </button>
                                <button type="button" onclick="applyQuickTemplate('password')" class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-500/30 bg-indigo-500/10 hover:bg-indigo-500/20 px-3 py-1.5 text-xs font-semibold text-indigo-300 transition cursor-pointer">
                                    <i class="fa-solid fa-key text-[11px]"></i> Bantuan Akun / Sandi
                                </button>
                                <button type="button" onclick="applyQuickTemplate('saran')" class="inline-flex items-center gap-1.5 rounded-xl border border-purple-500/30 bg-purple-500/10 hover:bg-purple-500/20 px-3 py-1.5 text-xs font-semibold text-purple-300 transition cursor-pointer">
                                    <i class="fa-solid fa-comment-dots text-[11px]"></i> Kritik & Saran
                                </button>
                            </div>
                        </div>

                        <!-- Contact Form -->
                        <form id="contactFormElement" action="kontak.php" method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="submit_contact">
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
                                            <option value="ppdb">Penerimaan Siswa Baru (PPDB)</option>
                                            <option value="akademik">Akademik, Nilai & KBM</option>
                                            <option value="keuangan">Keuangan & Pembayaran SPP</option>
                                            <option value="teknis">Bantuan Teknis & Akun Portal</option>
                                            <option value="saran">Kritik, Saran & Pengaduan</option>
                                            <option value="umum" selected>Layanan Umum & Informasi</option>
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
                                           placeholder="Tuliskan inti pertanyaan atau subjek pengaduan"
                                           class="w-full rounded-xl border border-white/10 bg-white/5 py-2.5 pl-10 pr-3.5 text-xs sm:text-sm text-white placeholder-slate-500 focus:border-amber-400 focus:bg-white/10 focus:outline-none transition">
                                </div>
                            </div>

                            <!-- Message with Live Counter -->
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <label for="message" class="block text-xs font-semibold text-slate-300">
                                        Pesan Lengkap <span class="text-rose-400">*</span>
                                    </label>
                                    <span id="charCountBadge" class="text-[11px] text-slate-400 font-mono">0 karakter (min. 10)</span>
                                </div>
                                <textarea id="message" name="message" rows="5" required
                                          oninput="updateCharCount(this)"
                                          placeholder="Uraikan detail pertanyaan, nomor registrasi PPDB, atau kronologi pengaduan Anda..."
                                          class="w-full rounded-xl border border-white/10 bg-white/5 p-3.5 text-xs sm:text-sm text-white placeholder-slate-500 focus:border-amber-400 focus:bg-white/10 focus:outline-none transition leading-relaxed"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                                
                                <!-- Character length feedback bar -->
                                <div class="mt-1.5 flex items-center justify-between gap-3 text-[10px]">
                                    <div class="h-1 flex-1 bg-white/5 rounded-full overflow-hidden">
                                        <div id="charProgressBar" class="h-full bg-rose-500 w-0 transition-all duration-200"></div>
                                    </div>
                                    <span id="charQualityFeedback" class="text-slate-500 shrink-0">Wajib diisi (min. 10 karakter)</span>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <div class="pt-2 flex flex-col sm:flex-row items-center justify-between gap-3">
                                <p class="text-[11px] text-slate-400">
                                    <i class="fa-solid fa-shield-halved text-amber-400 mr-1"></i> Data Anda dijamin kerahasiaannya oleh pihak sekolah.
                                </p>
                                <div class="flex items-center gap-2 w-full sm:w-auto">
                                    <button type="submit"
                                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 px-6 py-3 text-xs sm:text-sm font-bold text-slate-950 transition shadow-lg shadow-amber-500/20 cursor-pointer">
                                        <i class="fa-solid fa-paper-plane text-xs"></i>
                                        <span>Kirim Pesan & Buat Tiket</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- ================= TAB 2: PELACAKAN STATUS TIKET ================= -->
                    <div id="viewTrackContainer" class="<?= $active_tab === 'track' ? '' : 'hidden' ?>">

                        <!-- Tracker Header -->
                        <div class="mb-6 border-b border-white/10 pb-4 no-print">
                            <h2 class="text-xl sm:text-2xl font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-magnifying-glass text-amber-400 text-lg"></i>
                                <span>Pelacakan Tiket Pengaduan Publik</span>
                            </h2>
                            <p class="text-xs text-slate-400 mt-1">
                                Masukkan nomor tiket Anda untuk memantau status pemrosesan, tahapan penanganan, dan respon resmi dari pihak sekolah secara langsung.
                            </p>
                        </div>

                        <!-- Search Ticket Box -->
                        <div id="searchTicketBox" class="mb-6 rounded-2xl border border-white/10 bg-slate-950/70 p-4 sm:p-5 no-print">
                            <form onsubmit="event.preventDefault(); doTrackTicket();" class="space-y-3">
                                <label for="trackInput" class="block text-xs font-semibold text-slate-300">
                                    Masukkan Kode / Nomor Tiket Anda:
                                </label>
                                <div class="flex flex-col sm:flex-row items-center gap-2">
                                    <div class="relative flex-1 w-full">
                                        <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-xs">
                                            <i class="fa-solid fa-ticket text-amber-400"></i>
                                        </span>
                                        <input type="text" id="trackInput" name="ticket_code"
                                               value="<?= htmlspecialchars($track_code) ?>"
                                               placeholder="Contoh: TIK-<?= date('Ymd') ?>-ABCDE"
                                               class="w-full rounded-xl border border-white/10 bg-white/5 py-2.5 pl-10 pr-3.5 font-mono text-xs sm:text-sm text-white uppercase placeholder-slate-500 focus:border-amber-400 focus:bg-white/10 focus:outline-none transition">
                                    </div>
                                    <button type="submit" id="btnSubmitTrack"
                                            class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 px-5 py-2.5 text-xs sm:text-sm font-bold text-slate-950 transition shadow-lg shadow-amber-500/20 cursor-pointer shrink-0">
                                        <i class="fa-solid fa-magnifying-glass text-xs"></i>
                                        <span>Lacak Tiket</span>
                                    </button>
                                </div>
                            </form>

                            <!-- Recent Tickets LocalStorage Container -->
                            <div id="recentTicketsContainer" class="hidden mt-3 pt-3 border-t border-white/5 text-xs flex flex-wrap items-center gap-2">
                                <span class="text-[11px] text-slate-400 flex items-center gap-1">
                                    <i class="fa-solid fa-clock-rotate-left text-amber-400/80"></i> Tiket Terakhir Anda:
                                </span>
                                <div id="recentTicketsChips" class="flex flex-wrap gap-1.5"></div>
                            </div>
                        </div>

                        <!-- Track Error Alert -->
                        <div id="trackErrorAlert" class="<?= !empty($track_error) ? '' : 'hidden' ?> mb-6 rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-rose-200 flex items-start gap-3 no-print">
                            <i class="fa-solid fa-circle-exclamation text-rose-400 text-lg mt-0.5"></i>
                            <div class="text-xs leading-relaxed">
                                <p class="font-bold text-white mb-0.5">Tiket Tidak Ditemukan</p>
                                <p id="trackErrorMessage"><?= htmlspecialchars($track_error) ?></p>
                            </div>
                        </div>

                        <!-- Track Result Area -->
                        <div id="trackResultArea">
                            <?php if ($tracked_ticket): ?>
                                <?php
                                $status = strtolower($tracked_ticket['status'] ?? 'baru');
                                $status_label = match($status) {
                                    'diproses' => 'SEDANG DIPROSES STAF',
                                    'selesai'  => 'SELESAI / TERJAWAB',
                                    default    => 'MENUNGGU RESPON (BARU)'
                                };
                                $status_badge_class = match($status) {
                                    'diproses' => 'border-sky-500/30 bg-sky-500/10 text-sky-300',
                                    'selesai'  => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
                                    default    => 'border-amber-500/30 bg-amber-500/10 text-amber-300'
                                };
                                $status_desc = match($status) {
                                    'diproses' => 'Pengaduan Anda saat ini sedang dalam penanganan aktif oleh tim terkait di sekolah.',
                                    'selesai'  => 'Pihak sekolah telah menyelesaikan penanganan tiket dan memberikan solusi/tanggapan resmi.',
                                    default    => 'Pesan Anda telah kami terima dalam antrean dan menunggu giliran penelaahan oleh tim Tata Usaha / Admin.'
                                };
                                ?>

                                <!-- TICKET PRINT & DISPLAY CARD -->
                                <div id="ticketPrintArea" class="rounded-2xl border border-white/10 bg-slate-950/60 p-5 sm:p-6 space-y-6">

                                    <!-- Print Letterhead Header (Only displayed when printing) -->
                                    <div class="print-only-block border-b-2 border-slate-900 pb-3 mb-4 text-center">
                                        <h2 class="text-lg font-bold uppercase tracking-tight text-slate-900"><?= htmlspecialchars($school_info['school_name'] ?? 'SEKOLAH') ?></h2>
                                        <p class="text-xs text-slate-600"><?= htmlspecialchars($school_info['school_address'] ?? '') ?> | Telp: <?= htmlspecialchars($school_info['school_phone'] ?? '') ?></p>
                                        <p class="text-xs font-bold uppercase tracking-wider text-slate-800 mt-2 border-t border-slate-300 pt-1">LEMBAR BUKTI STATUS TIKET PENGADUAN PUBLIK</p>
                                    </div>

                                    <!-- Card Header: Ticket Code & Status Badge -->
                                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-white/10 pb-4">
                                        <div class="space-y-1">
                                            <span class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold block">Nomor Tiket:</span>
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-base sm:text-lg font-black text-amber-400 tracking-wider">
                                                    <?= htmlspecialchars($tracked_ticket['ticket_code']) ?>
                                                </span>
                                                <button type="button" onclick="copyTicketNumber('<?= htmlspecialchars($tracked_ticket['ticket_code']) ?>')" class="no-print text-slate-400 hover:text-white transition p-1 text-xs" title="Salin Nomor Tiket">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-bold border <?= $status_badge_class ?>">
                                                <?php if ($status === 'baru'): ?>
                                                    <span class="h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
                                                <?php elseif ($status === 'diproses'): ?>
                                                    <i class="fa-solid fa-spinner animate-spin text-[10px]"></i>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-circle-check text-[11px]"></i>
                                                <?php endif; ?>
                                                <span><?= $status_label ?></span>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- VISUAL 3-STAGE TIMELINE / STEPPER -->
                                    <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 sm:p-5">
                                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-4">
                                            Tahapan Penanganan Tiket:
                                        </h4>
                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 relative">
                                            
                                            <!-- Step 1: Diterima -->
                                            <div class="flex items-start gap-3">
                                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-xs font-bold">
                                                    <i class="fa-solid fa-check"></i>
                                                </div>
                                                <div>
                                                    <p class="text-xs font-bold text-white">1. Tiket Terkirim</p>
                                                    <p class="text-[11px] text-slate-400 mt-0.5">Diterima sistem antrean</p>
                                                    <span class="text-[10px] text-emerald-400 font-mono block mt-1">
                                                        <?= date('d M Y, H:i', strtotime($tracked_ticket['created_at'])) ?> WIB
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Step 2: Ditinjau / Diproses -->
                                            <div class="flex items-start gap-3">
                                                <?php if ($status === 'baru'): ?>
                                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400 border border-amber-500/30 text-xs font-bold">
                                                        <i class="fa-regular fa-clock"></i>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-bold text-amber-300">2. Menunggu Respon</p>
                                                        <p class="text-[11px] text-slate-400 mt-0.5">Dalam antrean telaah staf</p>
                                                        <span class="text-[10px] text-amber-400/80 block mt-1">Antrean Layanan</span>
                                                    </div>
                                                <?php elseif ($status === 'diproses'): ?>
                                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-sky-500/20 text-sky-400 border border-sky-500/30 text-xs font-bold animate-pulse">
                                                        <i class="fa-solid fa-arrows-rotate animate-spin text-[10px]"></i>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-bold text-sky-300">2. Sedang Diproses</p>
                                                        <p class="text-[11px] text-slate-400 mt-0.5">Aktif ditangani oleh staf</p>
                                                        <span class="text-[10px] text-sky-400 font-mono block mt-1">Dalam Penanganan</span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-xs font-bold">
                                                        <i class="fa-solid fa-check"></i>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-bold text-white">2. Selesai Ditelaah</p>
                                                        <p class="text-[11px] text-slate-400 mt-0.5">Telah diverifikasi staf</p>
                                                        <span class="text-[10px] text-emerald-400 font-mono block mt-1">Terverifikasi</span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Step 3: Penyelesaian -->
                                            <div class="flex items-start gap-3">
                                                <?php if ($status === 'selesai'): ?>
                                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-xs font-bold">
                                                        <i class="fa-solid fa-circle-check"></i>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-bold text-emerald-300">3. Selesai / Terjawab</p>
                                                        <p class="text-[11px] text-slate-400 mt-0.5">Tindak lanjut tuntas</p>
                                                        <span class="text-[10px] text-emerald-400 font-mono block mt-1">
                                                            <?= date('d M Y, H:i', strtotime($tracked_ticket['updated_at'] ?? $tracked_ticket['created_at'])) ?> WIB
                                                        </span>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-white/5 text-slate-500 border border-white/10 text-xs">
                                                        <i class="fa-solid fa-flag-checkered"></i>
                                                    </div>
                                                    <div>
                                                        <p class="text-xs font-bold text-slate-400">3. Penyelesaian</p>
                                                        <p class="text-[11px] text-slate-500 mt-0.5">Menunggu respon akhir</p>
                                                        <span class="text-[10px] text-slate-500 block mt-1">Tahap Akhir</span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                        </div>
                                    </div>

                                    <!-- OFFICIAL SCHOOL RESPONSE BOX -->
                                    <?php if (!empty($tracked_ticket['admin_notes'])): ?>
                                        <div class="rounded-2xl border border-emerald-500/30 bg-emerald-950/30 p-5 relative overflow-hidden shadow-lg">
                                            <div class="flex items-start gap-3.5">
                                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-lg border border-emerald-500/30">
                                                    <i class="fa-solid fa-stamp"></i>
                                                </span>
                                                <div class="space-y-1.5 flex-1">
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <span class="text-xs font-bold text-emerald-300 uppercase tracking-wider flex items-center gap-1.5">
                                                            <i class="fa-solid fa-circle-check text-[11px]"></i> Tanggapan Resmi Sekolah / Staf
                                                        </span>
                                                        <span class="text-[11px] text-emerald-400/80 font-mono">
                                                            <?= date('d M Y, H:i', strtotime($tracked_ticket['updated_at'] ?? $tracked_ticket['created_at'])) ?> WIB
                                                        </span>
                                                    </div>
                                                    <p class="text-xs sm:text-sm text-slate-200 leading-relaxed whitespace-pre-wrap font-medium">
                                                        <?= nl2br(htmlspecialchars($tracked_ticket['admin_notes'])) ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-xs text-slate-300 flex items-start gap-3">
                                            <i class="fa-solid fa-circle-info text-amber-400 text-base mt-0.5"></i>
                                            <div class="space-y-0.5">
                                                <p class="font-bold text-white">Status Penanganan Saat Ini:</p>
                                                <p class="text-slate-400 leading-relaxed"><?= $status_desc ?></p>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- TICKET DETAILS SUMMARY -->
                                    <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 sm:p-5 space-y-4">
                                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 border-b border-white/5 pb-2">
                                            Rincian Pengajuan Tiket:
                                        </h4>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                            <div>
                                                <span class="text-slate-400 block text-[11px]">Nama Pengirim:</span>
                                                <strong class="text-white"><?= htmlspecialchars($tracked_ticket['name']) ?></strong>
                                            </div>
                                            <div>
                                                <span class="text-slate-400 block text-[11px]">Kategori Layanan:</span>
                                                <span class="inline-block px-2 py-0.5 rounded-md text-[10px] font-bold border mt-0.5 <?= getContactCatBadge($tracked_ticket['category']) ?>">
                                                    <?= htmlspecialchars(getContactCatLabel($tracked_ticket['category'])) ?>
                                                </span>
                                            </div>
                                            <div>
                                                <span class="text-slate-400 block text-[11px]">Alamat Email (Sensor):</span>
                                                <span class="text-slate-200 font-mono"><?= htmlspecialchars(maskEmail($tracked_ticket['email'])) ?></span>
                                            </div>
                                            <div>
                                                <span class="text-slate-400 block text-[11px]">No. Telepon / WhatsApp:</span>
                                                <span class="text-emerald-400 font-mono"><?= htmlspecialchars(maskPhone($tracked_ticket['phone'])) ?></span>
                                            </div>
                                        </div>

                                        <!-- Subject & Message -->
                                        <div class="pt-2 border-t border-white/5 space-y-2 text-xs">
                                            <div>
                                                <span class="text-slate-400 block text-[11px]">Pokok Masalah / Subjek:</span>
                                                <strong class="text-white text-sm"><?= htmlspecialchars($tracked_ticket['subject']) ?></strong>
                                            </div>
                                            <div>
                                                <span class="text-slate-400 block text-[11px] mb-1">Isi Pesan Lengkap:</span>
                                                <div class="rounded-xl border border-white/5 bg-slate-950 p-3 text-slate-300 leading-relaxed whitespace-pre-wrap text-xs">
                                                    <?= htmlspecialchars($tracked_ticket['message']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- SERVICE SATISFACTION RATING (For resolved tickets) -->
                                    <?php if ($status === 'selesai'): ?>
                                        <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-center sm:text-left sm:flex sm:items-center sm:justify-between gap-4 no-print">
                                            <div class="space-y-0.5 mb-3 sm:mb-0">
                                                <p class="text-xs font-bold text-white flex items-center justify-center sm:justify-start gap-1.5">
                                                    <i class="fa-solid fa-star text-amber-400 text-xs"></i> Tingkat Kepuasan Layanan Tiket
                                                </p>
                                                <p class="text-[11px] text-slate-300">Berikan penilaian atas ketepatan solusi dan keramahan staf tata usaha kami:</p>
                                            </div>
                                            <div class="flex items-center justify-center gap-1.5" id="phpRatingStars">
                                                <button type="button" onclick="rateTicketService(1, 'phpRatingStars')" class="text-slate-500 hover:text-amber-400 text-lg transition p-1 cursor-pointer"><i class="fa-solid fa-star"></i></button>
                                                <button type="button" onclick="rateTicketService(2, 'phpRatingStars')" class="text-slate-500 hover:text-amber-400 text-lg transition p-1 cursor-pointer"><i class="fa-solid fa-star"></i></button>
                                                <button type="button" onclick="rateTicketService(3, 'phpRatingStars')" class="text-slate-500 hover:text-amber-400 text-lg transition p-1 cursor-pointer"><i class="fa-solid fa-star"></i></button>
                                                <button type="button" onclick="rateTicketService(4, 'phpRatingStars')" class="text-slate-500 hover:text-amber-400 text-lg transition p-1 cursor-pointer"><i class="fa-solid fa-star"></i></button>
                                                <button type="button" onclick="rateTicketService(5, 'phpRatingStars')" class="text-slate-500 hover:text-amber-400 text-lg transition p-1 cursor-pointer"><i class="fa-solid fa-star"></i></button>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- ACTION BUTTONS -->
                                    <div class="pt-2 flex flex-wrap items-center justify-between gap-3 no-print border-t border-white/10">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <button type="button" onclick="window.print()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/15 px-3.5 py-2 text-xs font-semibold text-slate-200 transition cursor-pointer flex items-center gap-1.5 shadow">
                                                <i class="fa-solid fa-print text-amber-400"></i>
                                                <span>Cetak Bukti</span>
                                            </button>
                                            <button type="button" onclick="copyTrackingLink('<?= htmlspecialchars($tracked_ticket['ticket_code']) ?>')" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/15 px-3.5 py-2 text-xs font-semibold text-slate-200 transition cursor-pointer flex items-center gap-1.5 shadow">
                                                <i class="fa-solid fa-share-nodes text-sky-400"></i>
                                                <span>Salin Link</span>
                                            </button>
                                            <a href="https://api.whatsapp.com/send?text=<?= urlencode('Simpanan Tiket Pengaduan [' . $tracked_ticket['ticket_code'] . '] di ' . ($school_info['school_name'] ?? 'Sekolah') . ': ' . $tracked_ticket['subject'] . ' - Cek progres: ' . (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] : '') . '/kontak.php?ticket=' . $tracked_ticket['ticket_code']) ?>"
                                               target="_blank"
                                               class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3.5 py-2 text-xs font-semibold text-emerald-300 transition flex items-center gap-1.5 shadow">
                                                <i class="fa-brands fa-whatsapp text-sm"></i>
                                                <span>Simpan ke WhatsApp</span>
                                            </a>
                                            <a href="https://wa.me/<?= htmlspecialchars($clean_wa) ?>?text=<?= urlencode('Halo Admin ' . ($school_info['school_name'] ?? 'Sekolah') . ', saya ingin menanyakan status tindak lanjut tiket pengaduan [' . $tracked_ticket['ticket_code'] . ']: ' . $tracked_ticket['subject']) ?>" target="_blank" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-3.5 py-2 text-xs font-semibold text-white transition flex items-center gap-1.5 shadow">
                                                <i class="fa-solid fa-headset text-xs"></i>
                                                <span>Tanya Staf</span>
                                            </a>
                                        </div>

                                        <button type="button" onclick="resetTrackSearch()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-xs font-semibold text-slate-400 hover:text-white transition cursor-pointer">
                                            <span>Lacak Tiket Lain</span>
                                        </button>
                                    </div>

                                </div>
                            <?php else: ?>
                                <!-- EMPTY PLACEHOLDER STATE -->
                                <div class="rounded-2xl border border-dashed border-white/15 p-8 text-center no-print">
                                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-500/10 text-amber-400 text-2xl mx-auto mb-3 border border-amber-500/20">
                                        <i class="fa-solid fa-ticket"></i>
                                    </span>
                                    <h3 class="text-base font-bold text-white">Masukkan Nomor Tiket</h3>
                                    <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1 leading-relaxed">
                                        Gunakan nomor tiket yang Anda peroleh setelah mengirimkan formulir kontak (contoh: <code class="text-amber-300">TIK-<?= date('Ymd') ?>-XXXXX</code>) untuk memantau status secara langsung.
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>

                </div>

                <!-- RIGHT COLUMN: DIRECTORY & OPERATIONAL INFO (5 COLS) -->
                <div class="lg:col-span-5 space-y-6 no-print" id="rightDirectoryCol">
                    
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
                        
                        <?php 
                        $map_src = !empty($school_info['maps_embed_url']) 
                            ? $school_info['maps_embed_url'] 
                            : "https://maps.google.com/maps?q=" . urlencode($school_info['school_address'] ?? 'Jakarta Indonesia') . "&t=&z=14&ie=UTF8&iwloc=&output=embed";
                        ?>
                        <div class="relative rounded-2xl overflow-hidden border border-white/10 h-44 bg-slate-950 flex items-center justify-center text-center p-4">
                            <iframe 
                                title="Peta Lokasi Sekolah"
                                width="100%" 
                                height="100%" 
                                style="border:0;" 
                                loading="lazy" 
                                allowfullscreen
                                referrerpolicy="no-referrer-when-downgrade"
                                src="<?= htmlspecialchars($map_src) ?>"
                                class="absolute inset-0 opacity-80 hover:opacity-100 transition duration-300">
                            </iframe>
                            <div class="pointer-events-none absolute bottom-2 left-2 right-2 rounded-xl bg-slate-950/85 backdrop-blur-sm px-3 py-1.5 border border-white/10 text-left">
                                <p class="text-[11px] font-bold text-white truncate"><?= htmlspecialchars($school_info['school_name'] ?? 'Kampus Utama') ?></p>
                                <p class="text-[10px] text-slate-400 truncate"><?= htmlspecialchars($school_info['school_address'] ?? '') ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Social Media Channels Card -->
                    <?php if (!empty($school_info['social_instagram']) || !empty($school_info['social_youtube']) || !empty($school_info['social_facebook']) || !empty($school_info['social_tiktok'])): ?>
                    <div class="rounded-3xl border border-white/10 bg-slate-900/60 backdrop-blur-md p-6 shadow-xl space-y-3">
                        <h3 class="text-sm font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-share-nodes text-indigo-400"></i>
                            <span>Saluran Media Sosial Resmi</span>
                        </h3>
                        <p class="text-xs text-slate-400">Ikuti berita, prestasi, dan kegiatan harian civitas sekolah melalui akun resmi kami:</p>
                        <div class="grid grid-cols-2 gap-2.5 pt-1">
                            <?php if (!empty($school_info['social_instagram'])): ?>
                            <a href="<?= htmlspecialchars($school_info['social_instagram']) ?>" target="_blank" class="flex items-center gap-2 rounded-xl border border-white/10 bg-slate-950/80 px-3 py-2 text-xs font-semibold text-rose-300 hover:border-rose-500/40 hover:bg-rose-500/10 transition">
                                <i class="fa-brands fa-instagram text-base"></i>
                                <span>Instagram</span>
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($school_info['social_youtube'])): ?>
                            <a href="<?= htmlspecialchars($school_info['social_youtube']) ?>" target="_blank" class="flex items-center gap-2 rounded-xl border border-white/10 bg-slate-950/80 px-3 py-2 text-xs font-semibold text-red-300 hover:border-red-500/40 hover:bg-red-500/10 transition">
                                <i class="fa-brands fa-youtube text-base"></i>
                                <span>YouTube</span>
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($school_info['social_facebook'])): ?>
                            <a href="<?= htmlspecialchars($school_info['social_facebook']) ?>" target="_blank" class="flex items-center gap-2 rounded-xl border border-white/10 bg-slate-950/80 px-3 py-2 text-xs font-semibold text-blue-300 hover:border-blue-500/40 hover:bg-blue-500/10 transition">
                                <i class="fa-brands fa-facebook text-base"></i>
                                <span>Facebook</span>
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($school_info['social_tiktok'])): ?>
                            <a href="<?= htmlspecialchars($school_info['social_tiktok']) ?>" target="_blank" class="flex items-center gap-2 rounded-xl border border-white/10 bg-slate-950/80 px-3 py-2 text-xs font-semibold text-slate-200 hover:border-white/40 hover:bg-white/10 transition">
                                <i class="fa-brands fa-tiktok text-base"></i>
                                <span>TikTok</span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>

            </div>
        </section>

        <!-- FAQ SECTION ACCORDION -->
        <section id="faqSection" class="max-w-4xl mx-auto px-4 sm:px-6 mb-16 no-print">
            <div class="text-center mb-6">
                <span class="inline-block text-xs font-bold uppercase tracking-wider text-amber-400 mb-2">FAQ Bantuan</span>
                <h2 class="text-2xl font-bold text-white">Pertanyaan yang Sering Diajukan</h2>
                <p class="text-xs text-slate-400 mt-1">Jawaban cepat untuk pertanyaan lazim perihal layanan sekolah dan pengaduan.</p>
            </div>

            <!-- Live FAQ Search Filter -->
            <div class="mb-6 relative max-w-md mx-auto">
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500 text-xs">
                        <i class="fa-solid fa-magnifying-glass text-amber-400"></i>
                    </span>
                    <input type="text" id="faqSearchInput" oninput="filterFaq(this.value)"
                           placeholder="Cari kata kunci: ppdb, legalisir, password, spp, jam..."
                           class="w-full rounded-2xl border border-white/10 bg-slate-900/90 py-2.5 pl-10 pr-10 text-xs sm:text-sm text-white placeholder-slate-500 focus:border-amber-400 focus:bg-slate-900 focus:outline-none transition shadow-lg">
                    <button type="button" id="faqClearBtn" onclick="clearFaqSearch()" class="hidden absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-white transition cursor-pointer">
                        <i class="fa-solid fa-circle-xmark text-xs"></i>
                    </button>
                </div>
                <div class="mt-2 flex items-center justify-between text-[11px] px-1 text-slate-400">
                    <span id="faqMatchCount">Menampilkan semua 5 pertanyaan umum</span>
                    <span class="text-slate-500">Filter pencarian instan</span>
                </div>
            </div>

            <!-- Empty Search Fallback State -->
            <div id="faqEmptySearch" class="hidden rounded-2xl border border-dashed border-white/15 p-6 text-center bg-white/5 mb-4">
                <i class="fa-solid fa-circle-question text-amber-400 text-2xl mb-2"></i>
                <p class="text-xs font-bold text-white mb-1">Pertanyaan Tidak Ditemukan</p>
                <p class="text-[11px] text-slate-400 max-w-md mx-auto mb-3">Tidak menemukan topik yang Anda cari? Ajukan pertanyaan langsung melalui formulir kontak atau chat WhatsApp staf kami.</p>
                <button type="button" onclick="switchContactTab('form'); document.getElementById('mainInteractionBox').scrollIntoView({behavior: 'smooth'});" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-slate-950 font-bold px-4 py-2 text-xs transition cursor-pointer shadow-md shadow-amber-500/20">
                    <i class="fa-solid fa-paper-plane text-[10px]"></i> Buka Formulir Pesan
                </button>
            </div>

            <div class="space-y-3" id="faqAccordionList">
                <!-- FAQ 1 -->
                <div class="faq-item rounded-2xl border border-white/10 bg-slate-900/60 overflow-hidden" data-keywords="tiket lacak status progres kode nomor nomor tiket bukti respons tanggapan">
                    <button type="button" onclick="toggleFaq(this)" class="faq-toggle w-full p-4 text-left flex items-center justify-between text-xs sm:text-sm font-bold text-white hover:bg-white/5 transition">
                        <span>Bagaimana cara memantau status atau tindak lanjut tiket pengaduan saya?</span>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-200"></i>
                    </button>
                    <div class="faq-content hidden p-4 pt-0 text-xs text-slate-300 leading-relaxed border-t border-white/5">
                        Setiap kali Anda mengirim pesan melalui formulir kontak, sistem akan memberikan <strong>Nomor Tiket resmi</strong> (contoh: <code>TIK-YYYYMMDD-XXXXX</code>). Anda dapat memantau apakah tiket Anda masih berstatus <em>Baru</em>, <em>Sedang Diproses</em>, atau <em>Selesai</em> beserta catatan respon resmi dari pihak sekolah melalui tab <strong>"Lacak Status Tiket"</strong> di atas tanpa harus login.
                    </div>
                </div>

                <!-- FAQ 2 -->
                <div class="faq-item rounded-2xl border border-white/10 bg-slate-900/60 overflow-hidden" data-keywords="ppdb daftar pendaftaran siswa baru formulir berkas zonasi syarat jurusan">
                    <button type="button" onclick="toggleFaq(this)" class="faq-toggle w-full p-4 text-left flex items-center justify-between text-xs sm:text-sm font-bold text-white hover:bg-white/5 transition">
                        <span>Bagaimana cara mendaftar sebagai calon siswa baru (PPDB)?</span>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-200"></i>
                    </button>
                    <div class="faq-content hidden p-4 pt-0 text-xs text-slate-300 leading-relaxed border-t border-white/5">
                        Anda dapat mengakses menu <a href="ppdb/ppdb.php" class="text-emerald-400 underline font-semibold">PPDB Online</a> pada navigasi utama, mengisi formulir biodata lengkap, dan mengunggah dokumen persyaratan. Bukti registrasi dapat dicetak dan dipantau status verifikasinya secara berkala.
                    </div>
                </div>

                <!-- FAQ 3 -->
                <div class="faq-item rounded-2xl border border-white/10 bg-slate-900/60 overflow-hidden" data-keywords="password sandi lupa kata sandi login reset akun portal helpdesk it">
                    <button type="button" onclick="toggleFaq(this)" class="faq-toggle w-full p-4 text-left flex items-center justify-between text-xs sm:text-sm font-bold text-white hover:bg-white/5 transition">
                        <span>Bagaimana jika lupa kata sandi akun portal siswa/guru?</span>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-200"></i>
                    </button>
                    <div class="faq-content hidden p-4 pt-0 text-xs text-slate-300 leading-relaxed border-t border-white/5">
                        Silakan hubungi Administrator IT atau Staf Tata Usaha melalui formulir di atas dengan memilih kategori <strong>Kendala Akun & Portal</strong> atau kirim pesan WhatsApp langsung ke hotline resmi dengan menyertakan NISN / NIP dan bukti identitas.
                    </div>
                </div>

                <!-- FAQ 4 -->
                <div class="faq-item rounded-2xl border border-white/10 bg-slate-900/60 overflow-hidden" data-keywords="orang tua wali murid presensi absensi nilai rapor pantau kbm anak">
                    <button type="button" onclick="toggleFaq(this)" class="faq-toggle w-full p-4 text-left flex items-center justify-between text-xs sm:text-sm font-bold text-white hover:bg-white/5 transition">
                        <span>Apakah orang tua dapat memantau presensi dan nilai anak?</span>
                        <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-200"></i>
                    </button>
                    <div class="faq-content hidden p-4 pt-0 text-xs text-slate-300 leading-relaxed border-t border-white/5">
                        Ya, sistem Manajemen-PHP menyediakan hak akses khusus role <strong>Orang Tua / Wali Murid</strong>. Anda dapat masuk menggunakan akun orang tua yang telah terhubung dengan NISN putra/putri Anda untuk memantau rekap absensi harian dan perkembangan nilai rapor.
                    </div>
                </div>

                <!-- FAQ 5 -->
                <div class="faq-item rounded-2xl border border-white/10 bg-slate-900/60 overflow-hidden" data-keywords="jam operasional buka tutup tata usaha jam kerja sabtu senin jumat jadwal libur">
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

    <!-- Vanilla JS Interactivity & Ticket Tracking -->
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

        // Live FAQ Client-side Search Filter
        function filterFaq(query) {
            const q = (query || '').toLowerCase().trim();
            const clearBtn = document.getElementById('faqClearBtn');
            const matchCount = document.getElementById('faqMatchCount');
            const emptyBox = document.getElementById('faqEmptySearch');
            const items = document.querySelectorAll('.faq-item');

            if (clearBtn) {
                clearBtn.classList.toggle('hidden', q === '');
            }

            let matches = 0;
            items.forEach(item => {
                const text = (item.innerText || '').toLowerCase();
                const keywords = (item.getAttribute('data-keywords') || '').toLowerCase();
                if (q === '' || text.includes(q) || keywords.includes(q)) {
                    item.classList.remove('hidden');
                    matches++;
                } else {
                    item.classList.add('hidden');
                }
            });

            if (matchCount) {
                matchCount.textContent = q === '' 
                    ? `Menampilkan semua ${items.length} pertanyaan umum` 
                    : `Menemukan ${matches} dari ${items.length} pertanyaan`;
            }

            if (emptyBox) {
                emptyBox.classList.toggle('hidden', matches > 0);
            }
        }

        // Clear FAQ Search
        function clearFaqSearch() {
            const input = document.getElementById('faqSearchInput');
            if (input) {
                input.value = '';
                filterFaq('');
                input.focus();
            }
        }

        // Quick Topic Message Templates
        const quickTemplates = {
            ppdb: {
                category: 'ppdb',
                subject: 'Pertanyaan Alur & Berkas Pendaftaran PPDB',
                message: 'Halo Panitia PPDB <?= addslashes($school_info['school_name'] ?? 'Sekolah') ?>,\n\nSaya ingin berkonsultasi mengenai:\n- Jalur Pendaftaran: [Zonasi / Prestasi / Afirmasi]\n- Pilihan Jurusan / Program: [Nama Jurusan]\n- No. Registrasi (jika sudah daftar): -\n\nPertanyaan saya:\n'
            },
            legalisir: {
                category: 'akademik',
                subject: 'Permohonan Layanan Legalisir Ijazah / Rapor',
                message: 'Halo Tim Tata Usaha <?= addslashes($school_info['school_name'] ?? 'Sekolah') ?>,\n\nSaya ingin mengajukan permohonan legalisir dokumen dengan rincian:\n- Nama Lengkap Alumni: \n- Tahun Kelulusan: \n- Dokumen: [Ijazah / Rapor / Transkrip]\n- Jumlah Lembar: \n\nMohon informasi persyaratan dan estimasi waktu pengambilan. Terima kasih.'
            },
            keuangan: {
                category: 'keuangan',
                subject: 'Konfirmasi Pembayaran Administrasi / SPP',
                message: 'Halo Bagian Keuangan <?= addslashes($school_info['school_name'] ?? 'Sekolah') ?>,\n\nSaya ingin mengonfirmasi perihal pembayaran:\n- Nama Siswa: \n- Kelas & NIS: \n- Pembayaran Bulan: \n- Metode & Tanggal Transfer: \n\nMohon bantuan verifikasi status pembayaran akun kami. Terima kasih.'
            },
            password: {
                category: 'teknis',
                subject: 'Bantuan Akun & Reset Kata Sandi Portal',
                message: 'Halo Tim IT Helpdesk <?= addslashes($school_info['school_name'] ?? 'Sekolah') ?>,\n\nSaya mengalami kendala akses login ke sistem:\n- Role Pengguna: [Siswa / Guru / Orang Tua]\n- Username / NISN / NIP: \n- Kendala: [Lupa kata sandi / Akun terkunci / Email pemulihan tidak masuk]\n\nMohon bantuan reset atau instruksi akses. Terima kasih.'
            },
            saran: {
                category: 'saran',
                subject: 'Aspirasi & Saran Pengembangan Fasilitas',
                message: 'Halo Pengelola <?= addslashes($school_info['school_name'] ?? 'Sekolah') ?>,\n\nSaya ingin menyampaikan masukan konstruktif untuk kemajuan civitas sekolah:\n- Bidang: [Fasilitas / Pembelajaran / Kebersihan / Keamanan]\n- Uraian Masukan & Rekomendasi Solusi:\n'
            }
        };

        // Apply Quick Template to Form
        function applyQuickTemplate(type) {
            const tpl = quickTemplates[type];
            if (!tpl) return;

            const catSelect = document.getElementById('category');
            const subjInput = document.getElementById('subject');
            const msgText = document.getElementById('message');

            if (catSelect) catSelect.value = tpl.category;
            if (subjInput) subjInput.value = tpl.subject;
            if (msgText) {
                msgText.value = tpl.message;
                msgText.focus();
                updateCharCount(msgText);
            }
            showToast('Template "' + tpl.subject + '" berhasil diterapkan!');
        }

        // Reset Form Fields
        function resetFormFields() {
            const subjInput = document.getElementById('subject');
            const msgText = document.getElementById('message');
            const phoneInput = document.getElementById('phone');
            if (subjInput) subjInput.value = '';
            if (msgText) {
                msgText.value = '';
                updateCharCount(msgText);
            }
            if (phoneInput) phoneInput.value = '';
            showToast('Kolom formulir telah dikosongkan.');
        }

        // Live Character and Word Counter
        function updateCharCount(textarea) {
            const countBadge = document.getElementById('charCountBadge');
            const bar = document.getElementById('charProgressBar');
            const quality = document.getElementById('charQualityFeedback');
            if (!textarea || !countBadge) return;

            const len = textarea.value.trim().length;
            const words = textarea.value.trim() ? textarea.value.trim().split(/\s+/).length : 0;

            countBadge.textContent = `${len} karakter · ${words} kata`;

            if (!bar || !quality) return;

            if (len === 0) {
                bar.style.width = '0%';
                bar.className = 'h-full bg-rose-500 transition-all duration-200';
                quality.textContent = 'Wajib diisi (min. 10 karakter)';
                quality.className = 'text-slate-500 shrink-0';
            } else if (len < 10) {
                bar.style.width = Math.min(100, (len / 10) * 35) + '%';
                bar.className = 'h-full bg-rose-500 transition-all duration-200';
                quality.textContent = `Kurang ${10 - len} karakter lagi`;
                quality.className = 'text-rose-400 font-semibold shrink-0';
            } else if (len < 60) {
                bar.style.width = '60%';
                bar.className = 'h-full bg-amber-500 transition-all duration-200';
                quality.textContent = 'Cukup ringkas';
                quality.className = 'text-amber-400 font-medium shrink-0';
            } else {
                bar.style.width = '100%';
                bar.className = 'h-full bg-emerald-500 transition-all duration-200';
                quality.textContent = 'Sangat informatif & jelas ✓';
                quality.className = 'text-emerald-400 font-bold shrink-0';
            }
        }

        // Service Satisfaction Rating
        function rateTicketService(stars, containerId) {
            const container = document.getElementById(containerId);
            if (container) {
                container.innerHTML = '';
                for (let i = 1; i <= 5; i++) {
                    const icon = document.createElement('i');
                    icon.className = i <= stars ? 'fa-solid fa-star text-amber-400 text-base' : 'fa-regular fa-star text-slate-600 text-base';
                    container.appendChild(icon);
                }
            }
            showToast(`Terima kasih atas apresiasi bintang ${stars} Anda! Penilaian ini membantu kami terus meningkatkan kualitas pelayanan.`);
        }

        // Switch Between Form and Tracking Tabs
        function switchContactTab(tab) {
            const tabBtnForm = document.getElementById('tabBtnForm');
            const tabBtnTrack = document.getElementById('tabBtnTrack');
            const viewForm = document.getElementById('viewFormContainer');
            const viewTrack = document.getElementById('viewTrackContainer');

            if (!tabBtnForm || !tabBtnTrack || !viewForm || !viewTrack) return;

            if (tab === 'track') {
                tabBtnForm.className = "flex-1 py-2.5 px-3 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer text-slate-400 hover:text-slate-200 hover:bg-white/5";
                tabBtnTrack.className = "flex-1 py-2.5 px-3 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer bg-gradient-to-r from-amber-500 to-orange-500 text-slate-950 shadow-md shadow-amber-500/20";
                viewForm.classList.add('hidden');
                viewTrack.classList.remove('hidden');

                const trackInput = document.getElementById('trackInput');
                if (trackInput && !trackInput.value) {
                    setTimeout(() => trackInput.focus(), 150);
                }
            } else {
                tabBtnTrack.className = "flex-1 py-2.5 px-3 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer text-slate-400 hover:text-slate-200 hover:bg-white/5";
                tabBtnForm.className = "flex-1 py-2.5 px-3 rounded-xl text-xs sm:text-sm font-bold flex items-center justify-center gap-2 transition cursor-pointer bg-gradient-to-r from-amber-500 to-orange-500 text-slate-950 shadow-md shadow-amber-500/20";
                viewTrack.classList.add('hidden');
                viewForm.classList.remove('hidden');
            }
        }

        // Copy Ticket Number to Clipboard
        function copyTicketNumber(code) {
            if (!code) return;
            navigator.clipboard.writeText(code).then(() => {
                showToast('Nomor tiket berhasil disalin: ' + code);
                const copyBtnText = document.getElementById('copyBtnText');
                if (copyBtnText) copyBtnText.textContent = 'Tersalin!';
                setTimeout(() => {
                    if (copyBtnText) copyBtnText.textContent = 'Salin Nomor';
                }, 2000);
            }).catch(() => {
                prompt('Salin nomor tiket ini:', code);
            });
        }

        // Copy Direct Tracking Link
        function copyTrackingLink(code) {
            if (!code) return;
            const url = window.location.origin + window.location.pathname + '?ticket=' + encodeURIComponent(code);
            navigator.clipboard.writeText(url).then(() => {
                showToast('Tautan pelacakan tiket berhasil disalin!');
            }).catch(() => {
                prompt('Salin tautan pelacakan ini:', url);
            });
        }

        // Show Toast Notification
        function showToast(msg) {
            const toast = document.createElement('div');
            toast.className = 'fixed bottom-5 right-5 z-50 flex items-center gap-2.5 rounded-2xl border border-emerald-500/30 bg-slate-900/95 px-4 py-3 text-xs font-semibold text-emerald-300 shadow-2xl backdrop-blur-md transition-all duration-300 transform translate-y-4 opacity-0 no-print';
            toast.innerHTML = `<i class="fa-solid fa-circle-check text-emerald-400 text-sm"></i><span>${msg}</span>`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.classList.remove('translate-y-4', 'opacity-0');
            }, 50);
            setTimeout(() => {
                toast.classList.add('translate-y-4', 'opacity-0');
                setTimeout(() => toast.remove(), 350);
            }, 3000);
        }

        // Save Ticket to Browser LocalStorage
        function saveRecentTicket(code) {
            if (!code) return;
            try {
                let list = JSON.parse(localStorage.getItem('recent_tickets_school') || '[]');
                if (!Array.isArray(list)) list = [];
                list = list.filter(item => item !== code);
                list.unshift(code);
                if (list.length > 3) list = list.slice(0, 3);
                localStorage.setItem('recent_tickets_school', JSON.stringify(list));
                renderRecentTickets();
            } catch (e) {}
        }

        // Render Recent Ticket Chips
        function renderRecentTickets() {
            const container = document.getElementById('recentTicketsContainer');
            const chips = document.getElementById('recentTicketsChips');
            if (!container || !chips) return;

            try {
                const list = JSON.parse(localStorage.getItem('recent_tickets_school') || '[]');
                if (Array.isArray(list) && list.length > 0) {
                    chips.innerHTML = '';
                    list.forEach(code => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'px-2 py-0.5 rounded-md bg-white/5 hover:bg-white/10 border border-white/10 text-amber-300 font-mono text-[11px] transition cursor-pointer';
                        btn.textContent = code;
                        btn.title = 'Klik untuk melacak nomor tiket ini';
                        btn.onclick = () => loadTrackTicket(code);
                        chips.appendChild(btn);
                    });
                    container.classList.remove('hidden');
                } else {
                    container.classList.add('hidden');
                }
            } catch (e) {}
        }

        // Quick Load and Track
        function loadTrackTicket(code) {
            if (!code) return;
            switchContactTab('track');
            const input = document.getElementById('trackInput');
            if (input) input.value = code;
            doTrackTicket(code);
            const box = document.getElementById('mainInteractionBox');
            if (box) {
                box.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        // Reset Tracker Search
        function resetTrackSearch() {
            const input = document.getElementById('trackInput');
            if (input) {
                input.value = '';
                input.focus();
            }
            const errAlert = document.getElementById('trackErrorAlert');
            if (errAlert) errAlert.classList.add('hidden');
            window.history.replaceState({}, '', window.location.pathname + '?tab=track');
        }

        // Perform Live AJAX Ticket Tracking
        async function doTrackTicket(explicitCode = '') {
            const input = document.getElementById('trackInput');
            const code = (explicitCode || (input ? input.value : '')).trim();
            const errAlert = document.getElementById('trackErrorAlert');
            const errMsg = document.getElementById('trackErrorMessage');
            const resultArea = document.getElementById('trackResultArea');
            const btnSubmit = document.getElementById('btnSubmitTrack');

            if (!code) {
                if (errAlert && errMsg) {
                    errMsg.textContent = 'Silakan masukkan nomor tiket pengaduan Anda.';
                    errAlert.classList.remove('hidden');
                }
                return;
            }

            if (errAlert) errAlert.classList.add('hidden');
            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<i class="fa-solid fa-spinner animate-spin text-xs"></i> <span>Mencari...</span>';
            }

            try {
                const response = await fetch(`kontak.php?action=track_api&ticket=${encodeURIComponent(code)}`);
                const res = await response.json();

                if (res.success && res.data) {
                    saveRecentTicket(res.data.ticket_code);
                    window.history.replaceState({}, '', `kontak.php?ticket=${encodeURIComponent(res.data.ticket_code)}`);
                    renderTrackedTicketHtml(res.data);
                } else {
                    if (errAlert && errMsg) {
                        errMsg.textContent = res.message || 'Tiket tidak ditemukan. Pastikan format nomor tiket sudah sesuai.';
                        errAlert.classList.remove('hidden');
                    }
                }
            } catch (err) {
                if (errAlert && errMsg) {
                    errMsg.textContent = 'Gagal memeriksa tiket. Silakan periksa koneksi internet Anda atau coba lagi.';
                    errAlert.classList.remove('hidden');
                }
            } finally {
                if (btnSubmit) {
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = '<i class="fa-solid fa-magnifying-glass text-xs"></i> <span>Lacak Tiket</span>';
                }
            }
        }

        // Render Dynamic Ticket Result HTML
        function renderTrackedTicketHtml(data) {
            const resultArea = document.getElementById('trackResultArea');
            if (!resultArea) return;

            const status = (data.status || 'baru').toLowerCase();
            let statusLabel = 'MENUNGGU RESPON (BARU)';
            let statusBadgeClass = 'border-amber-500/30 bg-amber-500/10 text-amber-300';
            let statusIcon = '<span class="h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>';
            let statusDesc = 'Pesan Anda telah kami terima dalam antrean dan menunggu giliran penelaahan oleh tim Tata Usaha / Admin.';

            if (status === 'diproses') {
                statusLabel = 'SEDANG DIPROSES STAF';
                statusBadgeClass = 'border-sky-500/30 bg-sky-500/10 text-sky-300';
                statusIcon = '<i class="fa-solid fa-spinner animate-spin text-[10px]"></i>';
                statusDesc = 'Pengaduan Anda saat ini sedang dalam penanganan aktif oleh tim terkait di sekolah.';
            } else if (status === 'selesai') {
                statusLabel = 'SELESAI / TERJAWAB';
                statusBadgeClass = 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300';
                statusIcon = '<i class="fa-solid fa-circle-check text-[11px]"></i>';
                statusDesc = 'Pihak sekolah telah menyelesaikan penanganan tiket dan memberikan solusi/tanggapan resmi.';
            }

            // Clean WA phone
            let rawPhone = <?= json_encode($clean_wa) ?>;
            let waText = encodeURIComponent(`Halo Admin, saya ingin menanyakan status tindak lanjut tiket pengaduan [${data.ticket_code}]: ${data.subject}`);
            let trackingDirectUrl = window.location.origin + window.location.pathname + '?ticket=' + encodeURIComponent(data.ticket_code);
            let shareWaText = encodeURIComponent(`Simpanan Tiket Pengaduan [${data.ticket_code}] di <?= addslashes($school_info['school_name'] ?? 'Sekolah') ?>: ${data.subject} - Cek progres: ${trackingDirectUrl}`);

            // Stepper Stage 2
            let stage2Html = '';
            if (status === 'baru') {
                stage2Html = `
                    <div class="flex items-start gap-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400 border border-amber-500/30 text-xs font-bold">
                            <i class="fa-regular fa-clock"></i>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-amber-300">2. Menunggu Respon</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Dalam antrean telaah staf</p>
                            <span class="text-[10px] text-amber-400/80 block mt-1">Antrean Layanan</span>
                        </div>
                    </div>`;
            } else if (status === 'diproses') {
                stage2Html = `
                    <div class="flex items-start gap-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-sky-500/20 text-sky-400 border border-sky-500/30 text-xs font-bold animate-pulse">
                            <i class="fa-solid fa-arrows-rotate animate-spin text-[10px]"></i>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-sky-300">2. Sedang Diproses</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Aktif ditangani oleh staf</p>
                            <span class="text-[10px] text-sky-400 font-mono block mt-1">Dalam Penanganan</span>
                        </div>
                    </div>`;
            } else {
                stage2Html = `
                    <div class="flex items-start gap-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-xs font-bold">
                            <i class="fa-solid fa-check"></i>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-white">2. Selesai Ditelaah</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Telah diverifikasi staf</p>
                            <span class="text-[10px] text-emerald-400 font-mono block mt-1">Terverifikasi</span>
                        </div>
                    </div>`;
            }

            // Stepper Stage 3
            let stage3Html = '';
            if (status === 'selesai') {
                stage3Html = `
                    <div class="flex items-start gap-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-xs font-bold">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-emerald-300">3. Selesai / Terjawab</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Tindak lanjut tuntas</p>
                            <span class="text-[10px] text-emerald-400 font-mono block mt-1">${data.updated_at_fmt}</span>
                        </div>
                    </div>`;
            } else {
                stage3Html = `
                    <div class="flex items-start gap-3">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-white/5 text-slate-500 border border-white/10 text-xs">
                            <i class="fa-solid fa-flag-checkered"></i>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-400">3. Penyelesaian</p>
                            <p class="text-[11px] text-slate-500 mt-0.5">Menunggu respon akhir</p>
                            <span class="text-[10px] text-slate-500 block mt-1">Tahap Akhir</span>
                        </div>
                    </div>`;
            }

            // Official Response HTML
            let responseBoxHtml = '';
            if (data.admin_notes && data.admin_notes.trim() !== '') {
                const escapedNotes = data.admin_notes.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
                responseBoxHtml = `
                    <div class="rounded-2xl border border-emerald-500/30 bg-emerald-950/30 p-5 relative overflow-hidden shadow-lg">
                        <div class="flex items-start gap-3.5">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-lg border border-emerald-500/30">
                                <i class="fa-solid fa-stamp"></i>
                            </span>
                            <div class="space-y-1.5 flex-1">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-xs font-bold text-emerald-300 uppercase tracking-wider flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle-check text-[11px]"></i> Tanggapan Resmi Sekolah / Staf
                                    </span>
                                    <span class="text-[11px] text-emerald-400/80 font-mono">${data.updated_at_fmt}</span>
                                </div>
                                <p class="text-xs sm:text-sm text-slate-200 leading-relaxed whitespace-pre-wrap font-medium">
                                    ${escapedNotes}
                                </p>
                            </div>
                        </div>
                    </div>`;
            } else {
                responseBoxHtml = `
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-xs text-slate-300 flex items-start gap-3">
                        <i class="fa-solid fa-circle-info text-amber-400 text-base mt-0.5"></i>
                        <div class="space-y-0.5">
                            <p class="font-bold text-white">Status Penanganan Saat Ini:</p>
                            <p class="text-slate-400 leading-relaxed">${statusDesc}</p>
                        </div>
                    </div>`;
            }

            // Rating widget if status === 'selesai'
            let ratingWidgetHtml = '';
            if (status === 'selesai') {
                ratingWidgetHtml = `
                    <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-center sm:text-left sm:flex sm:items-center sm:justify-between gap-4 no-print">
                        <div class="space-y-0.5 mb-3 sm:mb-0">
                            <p class="text-xs font-bold text-white flex items-center justify-center sm:justify-start gap-1.5">
                                <i class="fa-solid fa-star text-amber-400 text-xs"></i> Tingkat Kepuasan Layanan Tiket
                            </p>
                            <p class="text-[11px] text-slate-300">Berikan penilaian atas ketepatan solusi dan keramahan staf tata usaha kami:</p>
                        </div>
                        <div class="flex items-center justify-center gap-1.5" id="dynamicRatingStars">
                            <button type="button" onclick="rateTicketService(1, 'dynamicRatingStars')" class="text-slate-500 hover:text-amber-400 text-lg transition p-1 cursor-pointer"><i class="fa-solid fa-star"></i></button>
                            <button type="button" onclick="rateTicketService(2, 'dynamicRatingStars')" class="text-slate-500 hover:text-amber-400 text-lg transition p-1 cursor-pointer"><i class="fa-solid fa-star"></i></button>
                            <button type="button" onclick="rateTicketService(3, 'dynamicRatingStars')" class="text-slate-500 hover:text-amber-400 text-lg transition p-1 cursor-pointer"><i class="fa-solid fa-star"></i></button>
                            <button type="button" onclick="rateTicketService(4, 'dynamicRatingStars')" class="text-slate-500 hover:text-amber-400 text-lg transition p-1 cursor-pointer"><i class="fa-solid fa-star"></i></button>
                            <button type="button" onclick="rateTicketService(5, 'dynamicRatingStars')" class="text-slate-500 hover:text-amber-400 text-lg transition p-1 cursor-pointer"><i class="fa-solid fa-star"></i></button>
                        </div>
                    </div>`;
            }

            // Escape text fields
            const escapeHtml = (str) => (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

            resultArea.innerHTML = `
                <div id="ticketPrintArea" class="rounded-2xl border border-white/10 bg-slate-950/60 p-5 sm:p-6 space-y-6">

                    <!-- Print Letterhead Header -->
                    <div class="print-only-block border-b-2 border-slate-900 pb-3 mb-4 text-center">
                        <h2 class="text-lg font-bold uppercase tracking-tight text-slate-900"><?= htmlspecialchars($school_info['school_name'] ?? 'SEKOLAH') ?></h2>
                        <p class="text-xs text-slate-600"><?= htmlspecialchars($school_info['school_address'] ?? '') ?> | Telp: <?= htmlspecialchars($school_info['school_phone'] ?? '') ?></p>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-800 mt-2 border-t border-slate-300 pt-1">LEMBAR BUKTI STATUS TIKET PENGADUAN PUBLIK</p>
                    </div>

                    <!-- Card Header: Ticket Code & Status Badge -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-white/10 pb-4">
                        <div class="space-y-1">
                            <span class="text-[10px] text-slate-400 uppercase tracking-wider font-semibold block">Nomor Tiket:</span>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-base sm:text-lg font-black text-amber-400 tracking-wider">
                                    ${escapeHtml(data.ticket_code)}
                                </span>
                                <button type="button" onclick="copyTicketNumber('${escapeHtml(data.ticket_code)}')" class="no-print text-slate-400 hover:text-white transition p-1 text-xs" title="Salin Nomor Tiket">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-bold border ${statusBadgeClass}">
                                ${statusIcon}
                                <span>${statusLabel}</span>
                            </span>
                        </div>
                    </div>

                    <!-- VISUAL 3-STAGE TIMELINE / STEPPER -->
                    <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 sm:p-5">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-4">
                            Tahapan Penanganan Tiket:
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 relative">
                            
                            <!-- Step 1: Diterima -->
                            <div class="flex items-start gap-3">
                                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-xs font-bold">
                                    <i class="fa-solid fa-check"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-white">1. Tiket Terkirim</p>
                                    <p class="text-[11px] text-slate-400 mt-0.5">Diterima sistem antrean</p>
                                    <span class="text-[10px] text-emerald-400 font-mono block mt-1">
                                        ${data.created_at_fmt}
                                    </span>
                                </div>
                            </div>

                            <!-- Step 2: Ditinjau / Diproses -->
                            ${stage2Html}

                            <!-- Step 3: Penyelesaian -->
                            ${stage3Html}

                        </div>
                    </div>

                    <!-- OFFICIAL SCHOOL RESPONSE BOX -->
                    ${responseBoxHtml}

                    <!-- TICKET DETAILS SUMMARY -->
                    <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 sm:p-5 space-y-4">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 border-b border-white/5 pb-2">
                            Rincian Pengajuan Tiket:
                        </h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                            <div>
                                <span class="text-slate-400 block text-[11px]">Nama Pengirim:</span>
                                <strong class="text-white">${escapeHtml(data.name)}</strong>
                            </div>
                            <div>
                                <span class="text-slate-400 block text-[11px]">Kategori Layanan:</span>
                                <span class="inline-block px-2 py-0.5 rounded-md text-[10px] font-bold border mt-0.5 ${data.category_badge}">
                                    ${escapeHtml(data.category_label)}
                                </span>
                            </div>
                            <div>
                                <span class="text-slate-400 block text-[11px]">Alamat Email (Sensor):</span>
                                <span class="text-slate-200 font-mono">${escapeHtml(data.email_masked)}</span>
                            </div>
                            <div>
                                <span class="text-slate-400 block text-[11px]">No. Telepon / WhatsApp:</span>
                                <span class="text-emerald-400 font-mono">${escapeHtml(data.phone_masked)}</span>
                            </div>
                        </div>

                        <!-- Subject & Message -->
                        <div class="pt-2 border-t border-white/5 space-y-2 text-xs">
                            <div>
                                <span class="text-slate-400 block text-[11px]">Pokok Masalah / Subjek:</span>
                                <strong class="text-white text-sm">${escapeHtml(data.subject)}</strong>
                            </div>
                            <div>
                                <span class="text-slate-400 block text-[11px] mb-1">Isi Pesan Lengkap:</span>
                                <div class="rounded-xl border border-white/5 bg-slate-950 p-3 text-slate-300 leading-relaxed whitespace-pre-wrap text-xs">
                                    ${escapeHtml(data.message)}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RATING WIDGET (IF RESOLVED) -->
                    ${ratingWidgetHtml}

                    <!-- ACTION BUTTONS -->
                    <div class="pt-2 flex flex-wrap items-center justify-between gap-3 no-print border-t border-white/10">
                        <div class="flex items-center gap-2 flex-wrap">
                            <button type="button" onclick="window.print()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/15 px-3.5 py-2 text-xs font-semibold text-slate-200 transition cursor-pointer flex items-center gap-1.5 shadow">
                                <i class="fa-solid fa-print text-amber-400"></i>
                                <span>Cetak Bukti</span>
                            </button>
                            <button type="button" onclick="copyTrackingLink('${escapeHtml(data.ticket_code)}')" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/15 px-3.5 py-2 text-xs font-semibold text-slate-200 transition cursor-pointer flex items-center gap-1.5 shadow">
                                <i class="fa-solid fa-share-nodes text-sky-400"></i>
                                <span>Salin Link</span>
                            </button>
                            <a href="https://api.whatsapp.com/send?text=${shareWaText}" target="_blank" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3.5 py-2 text-xs font-semibold text-emerald-300 transition flex items-center gap-1.5 shadow">
                                <i class="fa-brands fa-whatsapp text-sm"></i>
                                <span>Simpan ke WhatsApp</span>
                            </a>
                            <a href="https://wa.me/${rawPhone}?text=${waText}" target="_blank" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-3.5 py-2 text-xs font-semibold text-white transition flex items-center gap-1.5 shadow">
                                <i class="fa-solid fa-headset text-xs"></i>
                                <span>Tanya Staf</span>
                            </a>
                        </div>

                        <button type="button" onclick="resetTrackSearch()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-xs font-semibold text-slate-400 hover:text-white transition cursor-pointer">
                            <span>Lacak Tiket Lain</span>
                        </button>
                    </div>

                </div>`;
        }

        // On DOM Ready: Sync localStorage and initial tickets & input counters
        document.addEventListener('DOMContentLoaded', () => {
            renderRecentTickets();
            const msgInput = document.getElementById('message');
            if (msgInput && msgInput.value) {
                updateCharCount(msgInput);
            }
            <?php if (!empty($submitted_ticket)): ?>
                saveRecentTicket('<?= $submitted_ticket ?>');
            <?php elseif (!empty($tracked_ticket)): ?>
                saveRecentTicket('<?= $tracked_ticket['ticket_code'] ?>');
            <?php endif; ?>
        });
    </script>
</body>
</html>

