<?php

session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/index.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$school_info = getSchoolSettings($pdo);
$school_name = $school_info['school_name'] ?? 'SMA Bina Bangsa';
$school_phone = $school_info['school_phone'] ?? '081234567890';
$school_email = $school_info['school_email'] ?? 'info@sekolah.id';
$school_address = $school_info['school_address'] ?? 'Jl. Pendidikan No. 123';

// Bersihkan nomor HP untuk tautan Helpdesk WhatsApp
$wa_helpdesk = preg_replace('/[^0-9]/', '', $school_phone);
if (!empty($wa_helpdesk) && substr($wa_helpdesk, 0, 1) === '0') {
    $wa_helpdesk = '62' . substr($wa_helpdesk, 1);
}
if (empty($wa_helpdesk)) {
    $wa_helpdesk = '6281234567890';
}

$message = "";
$message_type = "";

if (isset($_GET["registered"])) {
    $message = "Pendaftaran berhasil! Akun Anda telah aktif, silakan login.";
    $message_type = "success";
} elseif (isset($_GET["reset"]) && $_GET["reset"] === "success") {
    $message = "Kata sandi akun Anda berhasil diperbarui dengan standar keamanan ketat! Silakan login dengan kata sandi baru Anda.";
    $message_type = "success";
} elseif (isset($_GET["auth"]) && $_GET["auth"] === "required") {
    $message = "Sesi Anda telah berakhir atau Anda belum login. Silakan masukkan kredensial.";
    $message_type = "info";
}

// Batalkan sesi 2FA yang tertunda jika diminta
if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['2fa_pending'], $_SESSION['2fa_user']);
    header("Location: login.php");
    exit;
}

$is_2fa_step = (isset($_GET['step']) && $_GET['step'] === '2fa' && !empty($_SESSION['2fa_pending']) && !empty($_SESSION['2fa_user']));

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Validasi token CSRF
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid atau telah kedaluwarsa. Silakan refresh halaman.";
        $message_type = "error";
    } else {

        // A. PENANGANAN VERIFIKASI KODE 2FA (GOOGLE AUTHENTICATOR)
        if (isset($_POST['action']) && $_POST['action'] === 'verify_2fa') {
            if (!$is_2fa_step) {
                header("Location: login.php");
                exit;
            }

            $totp_code = trim($_POST['totp_code'] ?? '');
            $u = $_SESSION['2fa_user'];

            // Ambil secret 2FA langsung dari database, jangan disimpan di session plaintext
            $stmt_sec = $pdo->prepare("SELECT two_factor_secret FROM users WHERE id = ?");
            $stmt_sec->execute([$u['id']]);
            $user_secret = $stmt_sec->fetchColumn();

            if ($user_secret && verifyTotpCode($user_secret, $totp_code)) {
                session_regenerate_id(true);
                unset($_SESSION['2fa_pending'], $_SESSION['2fa_user'], $_SESSION['csrf_token']);
                generateCsrfToken(); // Langsung generate token CSRF baru setelah login
                $_SESSION['login_attempts'] = [];

                $_SESSION["user_id"] = $u["id"];
                $_SESSION["user_name"] = $u["name"];
                $_SESSION["user_email"] = $u["email"];
                $_SESSION["user_role"] = $u["role"] ?? "siswa";
                $_SESSION["user_status"] = $u["status"];

                logActivity($pdo, 'LOGIN_2FA', "Pengguna {$u['name']} ({$u['role']}) berhasil login melalui verifikasi Google Authenticator (2FA).");

                header("Location: ../dashboard/index.php");
                exit;
            } else {
                $message = "Kode OTP Authenticator 6-digit salah atau telah kedaluwarsa. Silakan periksa jam di ponsel Anda dan masukkan kode terbaru.";
                $message_type = "error";
            }

        } else {
            // B. PENANGANAN LOGIN STANDAR EMAIL & PASSWORD
            // -15 fix: Rate limiting berbasis IP di database dan session (menggunakan getClientIp agar akurat di balik reverse proxy)
            $client_ip = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            $now = time();
            $window = 900; // 15 menit
            $max_attempts = 5;

            // Inisialisasi tabel rate limits jika belum ada
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS `login_rate_limits` (
                        `id` INT AUTO_INCREMENT PRIMARY KEY,
                        `ip_address` VARCHAR(45) NOT NULL,
                        `attempted_at` INT NOT NULL,
                        INDEX `idx_ip_attempt` (`ip_address`, `attempted_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");
                // Bersihkan record lama
                $stmt_clean = $pdo->prepare("DELETE FROM login_rate_limits WHERE attempted_at < ?");
                $stmt_clean->execute([$now - $window]);

                // Hitung percobaan gagal untuk IP ini
                $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM login_rate_limits WHERE ip_address = ?");
                $stmt_cnt->execute([$client_ip]);
                $ip_attempts = (int)$stmt_cnt->fetchColumn();
            } catch (Exception $e_rl) {
                $ip_attempts = 0;
            }

            if (!isset($_SESSION['login_attempts'])) {
                $_SESSION['login_attempts'] = [];
            }
            $_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'], fn($t) => ($now - $t) < $window);
            $session_attempts = count($_SESSION['login_attempts']);

            if ($ip_attempts >= $max_attempts || $session_attempts >= $max_attempts) {
                $message = "Terlalu banyak percobaan login gagal. Demi keamanan, akses dibekukan sementara selama 15 menit.";
                $message_type = "error";
            } else {

                $email = trim($_POST["email"] ?? "");
                $password = $_POST["password"] ?? "";

                $stmt = $pdo->prepare(
                    "SELECT id, name, email, password, role, status, status_reason, two_factor_secret, two_factor_enabled
                     FROM users
                     WHERE email = ?"
                );

                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user["password"])) {
                    $user_status = $user["status"] ?? 'aktif';

                    if ($user_status === 'dikeluarkan') {
                        $reason = !empty($user['status_reason']) ? " (Alasan: " . htmlspecialchars($user['status_reason']) . ")" : "";
                        $message = "Akses Ditolak: Akun Anda berstatus DIKELUARKAN DARI SEKOLAH{$reason}. Silakan hubungi pihak Bimbingan Konseling (BK) atau Pimpinan Sekolah.";
                        $message_type = "error";
                    } elseif ($user_status === 'skorsing') {
                        $reason = !empty($user['status_reason']) ? " (Alasan: " . htmlspecialchars($user['status_reason']) . ")" : "";
                        $message = "Akses Ditolak: Akun Anda sedang dalam masa SANKSI SKORSING{$reason}. Akses portal dinonaktifkan sementara.";
                        $message_type = "error";
                    } elseif ($user_status === 'mutasi') {
                        $message = "Akses Ditolak: Akun ini tercatat telah MUTASI KELUAR dari sekolah.";
                        $message_type = "error";
                    } else {
                        // Reset rate limiter saat berhasil login
                        try {
                            $pdo->prepare("DELETE FROM login_rate_limits WHERE ip_address = ?")->execute([$client_ip]);
                        } catch (Exception $e_del) {}

                        // CEK JIKA USER MENGAKTIFKAN 2FA (GOOGLE AUTHENTICATOR)
                        $has_2fa = (!empty($user['two_factor_enabled']) && (int)$user['two_factor_enabled'] === 1 && !empty($user['two_factor_secret']));

                        if ($has_2fa) {
                            $_SESSION['2fa_pending'] = true;
                            // -4 fix: Jangan simpan secret di session plaintext
                            $_SESSION['2fa_user'] = [
                                'id'     => $user['id'],
                                'name'   => $user['name'],
                                'email'  => $user['email'],
                                'role'   => $user['role'] ?? 'siswa',
                                'status' => $user_status
                            ];
                            header("Location: login.php?step=2fa");
                            exit;
                        }

                        // JIKA TIDAK MEMAKAI 2FA, SELESAIKAN LOGIN
                        session_regenerate_id(true);
                        unset($_SESSION['csrf_token']);
                        generateCsrfToken(); // -5 fix: Langsung generate token CSRF baru setelah login
                        $_SESSION['login_attempts'] = [];

                        $_SESSION["user_id"] = $user["id"];
                        $_SESSION["user_name"] = $user["name"];
                        $_SESSION["user_email"] = $user["email"];
                        $_SESSION["user_role"] = $user["role"] ?? "siswa";
                        $_SESSION["user_status"] = $user_status;

                        logActivity($pdo, 'LOGIN', "Pengguna {$user['name']} ({$user['role']}) berhasil login.");

                        header("Location: ../dashboard/index.php");
                        exit;
                    }

                } else {
                    // -15 fix: Catat percobaan gagal di session dan basis data
                    $_SESSION['login_attempts'][] = $now;
                    try {
                        $pdo->prepare("INSERT INTO login_rate_limits (ip_address, attempted_at) VALUES (?, ?)")
                            ->execute([$client_ip, $now]);
                    } catch (Exception $e_log) {}

                    // -16 fix: Pesan gagal seragam tanpa membocorkan sisa percobaan brute force
                    $message = "Email atau password yang Anda masukkan tidak cocok. Silakan coba kembali.";
                    $message_type = "error";
                }

            } // end rate limit check
        } // end login POST check
    } // end CSRF check
}

$current_attempts = count($_SESSION['login_attempts'] ?? []);
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Portal Terpadu — <?= htmlspecialchars($school_name) ?></title>
    
    <!-- Tailwind CSS v4 & FontAwesome -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        * {
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.35) transparent;
        }
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3);
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.6);
        }
        @keyframes pulseGlow {
            0%, 100% { opacity: 0.4; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.05); }
        }
        .glow-orb-1 {
            animation: pulseGlow 8s ease-in-out infinite;
        }
        .glow-orb-2 {
            animation: pulseGlow 10s ease-in-out infinite reverse;
        }
    </style>
</head>

<body class="min-h-full bg-slate-950 text-slate-100 antialiased selection:bg-blue-600 selection:text-white">

<div class="min-h-screen flex flex-col lg:flex-row relative overflow-hidden">

    <!-- AMBIENT BACKGROUND GLOWS -->
    <div class="fixed top-0 left-0 w-96 h-96 bg-blue-600/15 rounded-full blur-3xl pointer-events-none glow-orb-1"></div>
    <div class="fixed bottom-0 right-0 w-[500px] h-[500px] bg-indigo-600/15 rounded-full blur-3xl pointer-events-none glow-orb-2"></div>
    <div class="fixed top-1/2 left-1/3 w-80 h-80 bg-purple-600/10 rounded-full blur-3xl pointer-events-none"></div>

    <!-- ========================================================================= -->
    <!-- LEFT PANE: HERO BRANDING & SHOWCASE (Desktop 52% / Hidden on small screen)-->
    <!-- ========================================================================= -->
    <div class="relative hidden lg:flex lg:w-7/12 flex-col justify-between p-10 xl:p-14 border-r border-white/5 bg-gradient-to-br from-slate-950 via-slate-900/60 to-indigo-950/30">
        
        <!-- Top Nav / Back to Home -->
        <div class="flex items-center justify-between z-10">
            <a href="../index.php" class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-white/5 hover:bg-white/10 border border-white/10 text-xs font-semibold text-slate-300 hover:text-white transition duration-200 group">
                <i class="fa-solid fa-arrow-left text-slate-400 group-hover:-translate-x-0.5 transition duration-200"></i>
                <span>Kembali ke Beranda</span>
            </a>

            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-400 text-xs font-medium">
                <span class="h-2 w-2 rounded-full bg-blue-400 animate-ping"></span>
                <span>Tahun Ajaran 2026/2027 Aktif</span>
            </div>
        </div>

        <!-- Center Showcase Content -->
        <div class="my-auto py-10 z-10 max-w-xl">
            <!-- Brand Badge -->
            <div class="inline-flex items-center gap-2.5 px-3 py-1.5 rounded-2xl bg-white/5 border border-white/10 backdrop-blur mb-6">
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-sky-400 text-white shadow-md shadow-blue-500/25">
                    <i class="fa-solid fa-graduation-cap text-xs"></i>
                </span>
                <span class="text-xs font-bold text-white tracking-wide uppercase">Sistem Informasi Manajemen Sekolah</span>
                <span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 font-bold border border-emerald-500/30">Akreditasi A</span>
            </div>

            <!-- Big Headline -->
            <h1 class="text-4xl xl:text-5xl font-extrabold text-white tracking-tight leading-[1.15]">
                Satu Portal Terpadu untuk Seluruh 
                <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 via-indigo-300 to-sky-400">
                    Civitas Sekolah
                </span>
            </h1>

            <p class="mt-4 text-sm xl:text-base text-slate-300/80 leading-relaxed">
                Platform digital cerdas terintegrasi menghubungkan Tenaga Pendidik, Peserta Didik, Staf Administrasi, hingga Wali Murid secara transparan dan real-time.
            </p>

            <!-- 3 Glassmorphic Feature Showcase Cards -->
            <div class="mt-8 grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                <!-- Card 1 -->
                <div class="p-4 rounded-2xl bg-white/[0.03] hover:bg-white/[0.06] border border-white/10 backdrop-blur transition duration-300">
                    <div class="h-9 w-9 rounded-xl bg-emerald-500/15 border border-emerald-500/25 text-emerald-400 flex items-center justify-center text-sm mb-3">
                        <i class="fa-solid fa-qrcode"></i>
                    </div>
                    <h3 class="text-xs font-bold text-white">Smart QR Presensi</h3>
                    <p class="text-[11px] text-slate-400 mt-1 leading-snug">Absensi harian instan terhubung notifikasi WhatsApp wali murid.</p>
                </div>

                <!-- Card 2 -->
                <div class="p-4 rounded-2xl bg-white/[0.03] hover:bg-white/[0.06] border border-white/10 backdrop-blur transition duration-300">
                    <div class="h-9 w-9 rounded-xl bg-blue-500/15 border border-blue-500/25 text-blue-400 flex items-center justify-center text-sm mb-3">
                        <i class="fa-solid fa-laptop-code"></i>
                    </div>
                    <h3 class="text-xs font-bold text-white">Ujian CBT & Rapor</h3>
                    <p class="text-[11px] text-slate-400 mt-1 leading-snug">Evaluasi online otomatis, bank soal, dan penerbitan e-Rapor resmi.</p>
                </div>

                <!-- Card 3 -->
                <div class="p-4 rounded-2xl bg-white/[0.03] hover:bg-white/[0.06] border border-white/10 backdrop-blur transition duration-300">
                    <div class="h-9 w-9 rounded-xl bg-purple-500/15 border border-purple-500/25 text-purple-400 flex items-center justify-center text-sm mb-3">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <h3 class="text-xs font-bold text-white">Multi-Role RBAC</h3>
                    <p class="text-[11px] text-slate-400 mt-1 leading-snug">Akses terisolasi aman untuk 5 peran dengan enkripsi data berlapis.</p>
                </div>
            </div>

            <!-- Ticker Banner PPDB -->
            <div class="mt-8 flex items-center gap-3 p-3.5 rounded-2xl bg-gradient-to-r from-blue-950/40 via-indigo-950/30 to-purple-950/20 border border-blue-500/20">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400 text-xs">
                    <i class="fa-solid fa-bullhorn"></i>
                </span>
                <p class="text-xs text-slate-300">
                    <strong class="text-blue-400 font-semibold">PPDB 2026/2027:</strong> Pendaftaran siswa baru jalur Prestasi & Zonasi kini telah dibuka secara online.
                </p>
            </div>
        </div>

        <!-- Left Footer: Trust Indicators -->
        <div class="flex items-center justify-between text-xs text-slate-400 pt-6 border-t border-white/5 z-10">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-lock text-emerald-400"></i>
                <span>Enkripsi SSL 256-Bit Dilindungi</span>
            </div>
            <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($school_name) ?></span>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- RIGHT PANE: LOGIN FORM (Full width on mobile / 48% on desktop)          -->
    <!-- ========================================================================= -->
    <div class="flex-1 flex flex-col justify-center items-center px-6 py-12 lg:px-12 xl:px-16 z-10">
        
        <div class="w-full max-w-md">

            <!-- Mobile Brand Header (Tampil hanya di mobile) -->
            <div class="lg:hidden text-center mb-6">
                <a href="../index.php" class="inline-flex items-center gap-2.5 mb-2">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-500/30">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </span>
                    <span class="text-xl font-bold text-white"><?= htmlspecialchars($school_name) ?></span>
                </a>
                <p class="text-xs text-slate-400">Portal Layanan Terpadu Civitas Sekolah</p>
            </div>

            <!-- Login Card Container -->
            <div class="rounded-3xl border border-white/10 bg-slate-900/80 p-7 sm:p-9 shadow-2xl backdrop-blur-xl">

                <?php if ($is_2fa_step): ?>
                    <!-- TAMPILAN LANGKAH 2FA (GOOGLE AUTHENTICATOR) -->
                    <div class="mb-6 text-center">
                        <div class="mx-auto inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white shadow-xl shadow-blue-500/25 mb-3.5">
                            <i class="fa-solid fa-shield-halved text-2xl"></i>
                        </div>
                        <h2 class="text-xl font-bold tracking-tight text-white flex items-center justify-center gap-2">
                            <span>Verifikasi Dua Langkah</span>
                            <span class="inline-block w-2 h-2 rounded-full bg-blue-400 animate-ping"></span>
                        </h2>
                        <p class="text-xs text-slate-400 mt-1.5 leading-relaxed">
                            Akun Anda (<strong><?= htmlspecialchars($_SESSION['2fa_user']['email'] ?? '') ?></strong>) dilindungi oleh <strong>Google Authenticator</strong>. Masukkan 6 digit kode keamanan yang tampil di aplikasi authenticator ponsel Anda.
                        </p>
                    </div>

                    <!-- Alert Feedback Box -->
                    <?php if ($message !== ""): ?>
                        <div class="mb-5 rounded-2xl border p-4 text-xs flex items-start gap-3 
                            <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-950/40 text-emerald-200' : 
                               ($message_type === 'info' ? 'border-blue-500/30 bg-blue-950/40 text-blue-200' : 'border-rose-500/30 bg-rose-950/40 text-rose-200') ?>">
                            <div class="shrink-0 mt-0.5">
                                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : ($message_type === 'info' ? 'fa-circle-info text-blue-400' : 'fa-circle-exclamation text-rose-400') ?> text-sm"></i>
                            </div>
                            <div class="leading-relaxed">
                                <?= htmlspecialchars($message) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php?step=2fa" class="space-y-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="verify_2fa">

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2 text-center">
                                Masukkan Kode OTP 6-Digit
                            </label>
                            <input
                                type="text"
                                name="totp_code"
                                required
                                maxlength="6"
                                pattern="[0-9]{6}"
                                inputmode="numeric"
                                autofocus
                                autocomplete="one-time-code"
                                placeholder="000000"
                                class="w-full tracking-[0.4em] text-center text-3xl font-mono font-bold rounded-2xl border border-white/20 bg-slate-950 px-4 py-3 text-white placeholder-slate-600 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                            >
                            <p class="text-[11px] text-slate-500 text-center mt-2">
                                <i class="fa-regular fa-clock mr-1"></i>Kode diperbarui otomatis setiap 30 detik pada aplikasi ponsel Anda.
                            </p>
                        </div>

                        <button
                            type="submit"
                            class="w-full mt-3 rounded-xl bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-500 hover:from-blue-500 hover:to-indigo-500 py-3 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition duration-200 flex items-center justify-center gap-2 cursor-pointer active:scale-[0.99]">
                            <span>Verifikasi & Masuk Dashboard</span>
                            <i class="fa-solid fa-arrow-right text-[11px]"></i>
                        </button>

                        <div class="pt-2 text-center">
                            <a href="login.php?cancel_2fa=1" class="text-xs text-slate-400 hover:text-white transition inline-flex items-center gap-1.5">
                                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                                <span>Batal & Masuk dengan Akun Lain</span>
                            </a>
                        </div>
                    </form>

                <?php else: ?>

                    <!-- Header Login Standar -->
                    <div class="mb-6">
                        <div class="flex items-center justify-between">
                            <h2 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                                <span>Masuk Portal</span>
                                <span class="inline-block w-2 h-2 rounded-full bg-emerald-400"></span>
                            </h2>
                            <span class="text-[11px] font-semibold text-slate-400 bg-white/5 px-2.5 py-1 rounded-full border border-white/5">
                                RBAC Multi-Role
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 mt-1.5">
                            Gunakan email dan kata sandi yang terdaftar di sistem.
                        </p>
                    </div>

                    <!-- Alert Feedback Box -->
                    <?php if ($message !== ""): ?>
                        <div class="mb-5 rounded-2xl border p-4 text-xs flex items-start gap-3 
                            <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-950/40 text-emerald-200' : 
                               ($message_type === 'info' ? 'border-blue-500/30 bg-blue-950/40 text-blue-200' : 'border-rose-500/30 bg-rose-950/40 text-rose-200') ?>">
                            <div class="shrink-0 mt-0.5">
                                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : ($message_type === 'info' ? 'fa-circle-info text-blue-400' : 'fa-circle-exclamation text-rose-400') ?> text-sm"></i>
                            </div>
                            <div class="leading-relaxed">
                                <?= htmlspecialchars($message) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Form Login -->
                    <form method="POST" action="" class="space-y-4">
                        <?= csrfField() ?>

                        <!-- Email Field -->
                        <div>
                            <label for="emailInput" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                Alamat Email
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400 text-xs">
                                    <i class="fa-solid fa-envelope"></i>
                                </span>
                                <input
                                    id="emailInput"
                                    type="email"
                                    name="email"
                                    required
                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                    placeholder="nama@sekolah.id"
                                    autocomplete="email"
                                    class="w-full rounded-xl border border-white/10 bg-slate-950/70 pl-10 pr-4 py-2.5 text-xs text-white placeholder-slate-500 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                >
                            </div>
                        </div>

                        <!-- Password Field -->
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label for="passwordInput" class="block text-xs font-semibold text-slate-300">
                                    Kata Sandi
                                </label>
                                <a href="forgot_password.php"
                                   class="text-[11px] text-blue-400 hover:text-blue-300 hover:underline transition">
                                    Lupa sandi?
                                </a>
                            </div>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400 text-xs">
                                    <i class="fa-solid fa-lock"></i>
                                </span>
                                <input
                                    id="passwordInput"
                                    type="password"
                                    name="password"
                                    required
                                    placeholder="••••••••"
                                    autocomplete="current-password"
                                    class="w-full rounded-xl border border-white/10 bg-slate-950/70 pl-10 pr-10 py-2.5 text-xs text-white placeholder-slate-500 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                >
                                <button
                                    type="button"
                                    onclick="togglePasswordVisibility()"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-200 transition text-xs cursor-pointer"
                                    title="Lihat/Sembunyikan Kata Sandi">
                                    <i id="eyeIcon" class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button
                            type="submit"
                            class="w-full mt-2 rounded-xl bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-500 hover:from-blue-500 hover:to-indigo-500 py-3 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition duration-200 flex items-center justify-center gap-2 cursor-pointer active:scale-[0.99]">
                            <span>Masuk ke Akun Portal</span>
                            <i class="fa-solid fa-arrow-right text-[11px]"></i>
                        </button>
                    </form>
                <?php endif; ?>

                <!-- PUBLIC SERVICES SHORTCUTS -->
                <div class="mt-6 pt-5 border-t border-white/5">
                    <p class="text-[11px] font-semibold text-slate-400 text-center mb-3">
                        Layanan Terbuka (Tanpa Harus Login)
                    </p>
                    <div class="grid grid-cols-2 gap-2 text-[11px]">
                        <a href="../ppdb/ppdb.php" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/5 text-slate-300 hover:text-white transition flex items-center gap-2 justify-center">
                            <i class="fa-solid fa-user-plus text-emerald-400"></i>
                            <span>Daftar PPDB</span>
                        </a>
                        <a href="../perpustakaan.php" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/5 text-slate-300 hover:text-white transition flex items-center gap-2 justify-center">
                            <i class="fa-solid fa-book-open text-blue-400"></i>
                            <span>Katalog OPAC</span>
                        </a>
                        <a href="../informasi.php" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/5 text-slate-300 hover:text-white transition flex items-center gap-2 justify-center">
                            <i class="fa-solid fa-newspaper text-purple-400"></i>
                            <span>Berita Sekolah</span>
                        </a>
                        <a href="../kontak.php" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/5 text-slate-300 hover:text-white transition flex items-center gap-2 justify-center">
                            <i class="fa-solid fa-comments text-amber-400"></i>
                            <span>Buku Tamu</span>
                        </a>
                    </div>
                </div>

            </div>

            <!-- Mobile Footer Back to Home -->
            <div class="mt-6 text-center lg:hidden">
                <a href="../index.php" class="text-xs text-slate-400 hover:text-white transition inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-arrow-left text-[10px]"></i>
                    <span>Kembali ke Beranda Utama</span>
                </a>
            </div>

        </div>

    </div>

</div>

<!-- SCRIPT SHOW/HIDE PASSWORD -->
<script>
    function togglePasswordVisibility() {
        const pwdInput = document.getElementById('passwordInput');
        const eyeIcon = document.getElementById('eyeIcon');
        if (pwdInput.type === 'password') {
            pwdInput.type = 'text';
            eyeIcon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            pwdInput.type = 'password';
            eyeIcon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
</script>

</body>
</html>