<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: ../dashboard/index.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$school_info    = getSchoolSettings($pdo);
$school_name    = $school_info['school_name'] ?? 'SMA Bina Bangsa';
$school_phone   = $school_info['school_phone'] ?? '081234567890';
$school_email   = $school_info['school_email'] ?? 'info@sekolah.id';

// Helpdesk WhatsApp
$wa_helpdesk = preg_replace('/[^0-9]/', '', $school_phone);
if (!empty($wa_helpdesk) && substr($wa_helpdesk, 0, 1) === '0') {
    $wa_helpdesk = '62' . substr($wa_helpdesk, 1);
}
if (empty($wa_helpdesk)) {
    $wa_helpdesk = '6281234567890';
}

$message = "";
$message_type = "";
$step = $_GET['step'] ?? 'identify';
$token = $_GET['token'] ?? '';

// Helper masking
function maskEmail($email) {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $name = $parts[0];
    $domain = $parts[1];
    $len = strlen($name);
    if ($len <= 2) {
        $maskedName = substr($name, 0, 1) . '*';
    } else {
        $maskedName = substr($name, 0, 2) . str_repeat('•', max(1, $len - 4)) . substr($name, -2);
    }
    return $maskedName . '@' . $domain;
}

function maskPhone($phone) {
    $clean = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($clean) < 7) return '••••••••';
    return substr($clean, 0, 4) . '-••••-••••';
}

function maskName($name) {
    $words = explode(' ', trim($name));
    $masked = [];
    foreach ($words as $w) {
        $len = mb_strlen($w);
        if ($len <= 2) {
            $masked[] = '••';
        } else {
            $masked[] = mb_substr($w, 0, 1) . str_repeat('•', max(1, $len - 1));
        }
    }
    return implode(' ', $masked);
}

// -------------------------------------------------------------
// VERIFIKASI STATUS TOKEN PADA TAHAP-TAHAP LANJUTAN
// -------------------------------------------------------------
$reset_record = null;
$user_record = null;

if (in_array($step, ['challenge', 'verify_otp', 'reset_password'], true)) {
    if (empty($token)) {
        header("Location: forgot_password.php?step=identify");
        exit;
    }

    $stmt_chk = $pdo->prepare("
        SELECT pr.*, u.name, u.email as user_email, u.phone as user_phone, u.status as user_status, u.status_reason, u.password as current_password
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token = ?
        LIMIT 1
    ");
    $stmt_chk->execute([$token]);
    $reset_record = $stmt_chk->fetch();

    if (!$reset_record) {
        $message = "Sesi pemulihan akun tidak ditemukan atau token tidak valid. Silakan ulangi proses.";
        $message_type = "error";
        $step = "identify";
    } elseif ($reset_record['status'] === 'expired' || strtotime($reset_record['expires_at']) < time()) {
        $message = "Sesi pemulihan telah kedaluwarsa demi keamanan (Batas: 10 Menit). Silakan mulai kembali.";
        $message_type = "error";
        $step = "identify";
    } elseif ($reset_record['status'] === 'used') {
        $message = "Tautan pemulihan ini telah digunakan sebelumnya.";
        $message_type = "error";
        $step = "identify";
    } elseif ($reset_record['attempts'] >= $reset_record['max_attempts']) {
        $message = "Percobaan keamanan gagal melebihi batas (3 kali). Sesi pemulihan dibatalkan secara otomatis.";
        $message_type = "error";
        $step = "identify";
    } else {
        $user_record = $reset_record;
    }
}

// -------------------------------------------------------------
// PROSES POST BERDASARKAN TAHAP
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan CSRF tidak valid. Silakan muat ulang halaman.";
        $message_type = "error";
    } else {
        $action = $_POST['action'] ?? '';

        // TAHAP 1: IDENTIFIKASI PENGGUNA
        if ($action === 'submit_identify') {
            $ip_addr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $is_rate_limited = false;
            try {
                $stmt_rl = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
                $stmt_rl->execute([$ip_addr]);
                if ((int)$stmt_rl->fetchColumn() >= 5) {
                    $is_rate_limited = true;
                    $message = "Terlalu banyak permintaan pemulihan kata sandi dari perangkat Anda. Silakan coba kembali setelah 15 menit demi keamanan.";
                    $message_type = "error";
                }
            } catch (Exception $e_rl) {}

            $identifier = trim($_POST['identifier'] ?? '');
            if ($is_rate_limited) {
                // Jangan lanjutkan jika terblokir limit
            } elseif (empty($identifier)) {
                $message = "Silakan masukkan alamat email atau NISN/NIP akun Anda.";
                $message_type = "error";
            } else {
                $stmt_u = $pdo->prepare("
                    SELECT id, name, email, phone, role, status, status_reason 
                    FROM users 
                    WHERE email = ? OR nisn = ? OR nip = ?
                    LIMIT 1
                ");
                $stmt_u->execute([$identifier, $identifier, $identifier]);
                $u = $stmt_u->fetch();

                if (!$u) {
                    // Anti-Enumeration: Jangan berikan konfirmasi apakah akun ada atau tidak
                    $message = "Jika akun terdaftar pada sistem sekolah, instruksi verifikasi keamanan telah dipersiapkan.";
                    $message_type = "info";
                } elseif (in_array($u['status'], ['dikeluarkan', 'skorsing', 'mutasi'], true)) {
                    $reason = !empty($u['status_reason']) ? " (Catatan: " . htmlspecialchars($u['status_reason']) . ")" : "";
                    $message = "Akses Pemulihan Dibatasi: Akun Anda sedang berstatus " . strtoupper($u['status']) . "{$reason}. Pemulihan sandi mandiri dinonaktifkan. Silakan hubungi Guru BK / Admin Sekolah.";
                    $message_type = "error";
                } else {
                    // Siapkan nomor HP pemulihan
                    $phone = $u['phone'] ?: '081234567890';
                    $clean_phone = preg_replace('/[^0-9]/', '', $phone);
                    $last2 = substr($clean_phone, -2);

                    // Buat Token Kriptografi & OTP 6 Digit
                    $new_token = bin2hex(random_bytes(32));
                    $otp_code  = sprintf('%06d', random_int(100000, 999999));
                    $ip_addr   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $user_agent= substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

                    // Bersihkan request lama user ini yang masih pending
                    $pdo->prepare("UPDATE password_resets SET status = 'expired' WHERE user_id = ? AND status = 'pending'")->execute([$u['id']]);

                    // Simpan request baru
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO password_resets 
                        (user_id, email, token, otp_code, challenge_digits, attempts, max_attempts, status, ip_address, user_agent, expires_at)
                        VALUES (?, ?, ?, ?, ?, 0, 3, 'pending', ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
                    ");
                    $stmt_ins->execute([$u['id'], $u['email'], $new_token, $otp_code, $last2, $ip_addr, $user_agent]);

                    logActivity($pdo, 'PASSWORD_RESET_INIT', "Inisiasi pemulihan akun untuk {$u['email']} dari IP $ip_addr");

                    header("Location: forgot_password.php?step=challenge&token=" . $new_token);
                    exit;
                }
            }
        }

        // TAHAP 2: TANTANGAN IDENTITAS (GOOGLE PHONE CHALLENGE)
        elseif ($action === 'submit_challenge' && $reset_record) {
            $input_digits = trim($_POST['phone_digits'] ?? '');
            $expected_digits = $reset_record['challenge_digits'];

            if ($input_digits === $expected_digits) {
                // Berhasil verifikasi kepemilikan nomor HP -> Kirim OTP via WhatsApp
                $dest_phone = $user_record['user_phone'] ?: '081234567890';
                $wa_msg = "🔐 *KODE KEAMANAN PEMULIHAN SANDI*\n"
                        . "Yth. *{$user_record['name']}*,\n\n"
                        . "Seseorang (mungkin Anda) meminta pengaturan ulang kata sandi akun {$school_name}.\n\n"
                        . "Kode Verifikasi OTP Anda adalah:\n"
                        . "👉 *{$reset_record['otp_code']}*\n\n"
                        . "⚠️ *PENTING:* Kode ini berlaku selama *10 Menit*. JANGAN PERNAH membagikan kode ini kepada siapa pun termasuk staf sekolah.\n"
                        . "Jika bukan Anda yang meminta, abaikan pesan ini dan amankan akun Anda.";

                sendWhatsAppNotification($pdo, $dest_phone, $user_record['name'], $wa_msg, 'sistem');

                header("Location: forgot_password.php?step=verify_otp&token=" . $token . "&sent=1");
                exit;
            } else {
                $new_attempts = $reset_record['attempts'] + 1;
                $rem = max(0, $reset_record['max_attempts'] - $new_attempts);

                if ($new_attempts >= $reset_record['max_attempts']) {
                    $pdo->prepare("UPDATE password_resets SET attempts = ?, status = 'expired' WHERE id = ?")->execute([$new_attempts, $reset_record['id']]);
                    logActivity($pdo, 'PASSWORD_RESET_LOCKED', "Tantangan keamanan gagal 3x untuk {$reset_record['email']}");
                    $message = "Tantangan keamanan gagal 3 kali. Sesi pemulihan akun ini telah dibatalkan demi melindungi privasi akun.";
                    $message_type = "error";
                    $step = "identify";
                } else {
                    $pdo->prepare("UPDATE password_resets SET attempts = ? WHERE id = ?")->execute([$new_attempts, $reset_record['id']]);
                    $message = "Dua digit nomor ponsel tidak cocok dengan data terdaftar. Sisa kesempatan: {$rem} kali.";
                    $message_type = "error";
                }
            }
        }

        // TAHAP 3: VERIFIKASI KODE OTP
        elseif ($action === 'submit_otp' && $reset_record) {
            $input_otp = trim($_POST['otp_code'] ?? '');

            if ($input_otp === $reset_record['otp_code']) {
                // OTP Benar -> Tandai terverifikasi dan izinkan ganti sandi
                $pdo->prepare("UPDATE password_resets SET status = 'verified' WHERE id = ?")->execute([$reset_record['id']]);
                header("Location: forgot_password.php?step=reset_password&token=" . $token);
                exit;
            } else {
                $new_attempts = $reset_record['attempts'] + 1;
                $rem = max(0, $reset_record['max_attempts'] - $new_attempts);

                if ($new_attempts >= $reset_record['max_attempts']) {
                    $pdo->prepare("UPDATE password_resets SET attempts = ?, status = 'expired' WHERE id = ?")->execute([$new_attempts, $reset_record['id']]);
                    logActivity($pdo, 'PASSWORD_RESET_BRUTE_BLOCKED', "Percobaan OTP gagal 3x untuk {$reset_record['email']}");
                    $message = "Kode OTP salah sebanyak 3 kali. Sesi pemulihan telah dikunci demi keamanan.";
                    $message_type = "error";
                    $step = "identify";
                } else {
                    $pdo->prepare("UPDATE password_resets SET attempts = ? WHERE id = ?")->execute([$new_attempts, $reset_record['id']]);
                    $message = "Kode OTP tidak cocok. Periksa kembali WhatsApp Anda. Sisa percobaan: {$rem} kali.";
                    $message_type = "error";
                }
            }
        }

        // TAHAP 4: ATUR KATA SANDI BARU (STRICT PASSWORD POLICY)
        elseif ($action === 'submit_new_password' && $reset_record) {
            if ($reset_record['status'] !== 'verified') {
                $message = "Otorisasi tidak valid. Anda belum menyelesaikan tahap verifikasi kode OTP.";
                $message_type = "error";
            } else {
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';

                // Validasi Kebijakan Sandi Ketat (Google-Grade)
                $has_upper   = preg_match('/[A-Z]/', $new_password);
                $has_lower   = preg_match('/[a-z]/', $new_password);
                $has_number  = preg_match('/[0-9]/', $new_password);
                $has_special = preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $new_password);
                $is_long     = strlen($new_password) >= 8;

                if (!$is_long || !$has_upper || !$has_lower || !$has_number || !$has_special) {
                    $message = "Kata sandi belum memenuhi kriteria keamanan: Minimal 8 karakter, serta harus memadukan huruf besar, huruf kecil, angka, dan simbol khusus.";
                    $message_type = "error";
                } elseif ($new_password !== $confirm_password) {
                    $message = "Konfirmasi kata sandi tidak cocok. Silakan ketik ulang.";
                    $message_type = "error";
                } elseif (password_verify($new_password, $reset_record['current_password'])) {
                    $message = "Kata sandi baru tidak boleh sama dengan kata sandi lama Anda.";
                    $message_type = "error";
                } else {
                    // Hash dengan algoritma Bcrypt Cost 12
                    $hashed = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);

                    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $reset_record['user_id']]);
                    $pdo->prepare("UPDATE password_resets SET status = 'used' WHERE id = ?")->execute([$reset_record['id']]);

                    // Audit Log Keamanan
                    logActivity($pdo, 'PASSWORD_RESET_SUCCESS', "Kata sandi akun {$reset_record['user_email']} berhasil diperbarui via OTP.");

                    // Kirim Notifikasi WhatsApp Peringatan Keamanan
                    $dest_phone = $reset_record['user_phone'] ?: '081234567890';
                    $alert_msg  = "🛡️ *PERINGATAN KEAMANAN AKUN*\n"
                                . "Yth. *{$reset_record['name']}*,\n\n"
                                . "Kata sandi akun portal {$school_name} Anda baru saja berhasil diubah pada *" . date('d/m/Y H:i') . " WIB*.\n\n"
                                . "Jika ini dilakukan oleh Anda, tidak ada tindakan lebih lanjut yang diperlukan.\n"
                                . "⚠️ *Jika bukan Anda yang melakukannya*, segera amankan akun Anda dan laporkan ke IT Helpdesk sekolah.";
                    sendWhatsAppNotification($pdo, $dest_phone, $reset_record['name'], $alert_msg, 'sistem');

                    header("Location: login.php?reset=success");
                    exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemulihan Akun Terpadu — <?= htmlspecialchars($school_name) ?></title>
    
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        @keyframes pulseGlow {
            0%, 100% { opacity: 0.4; transform: scale(1); }
            50% { opacity: 0.7; transform: scale(1.05); }
        }
        .glow-orb-1 { animation: pulseGlow 8s ease-in-out infinite; }
        .glow-orb-2 { animation: pulseGlow 10s ease-in-out infinite reverse; }
    </style>
</head>

<body class="min-h-full bg-slate-950 text-slate-100 antialiased selection:bg-blue-600 selection:text-white flex flex-col justify-between">

    <!-- AMBIENT BACKGROUND GLOWS -->
    <div class="fixed top-0 left-1/4 w-96 h-96 bg-blue-600/10 rounded-full blur-3xl pointer-events-none glow-orb-1"></div>
    <div class="fixed bottom-0 right-1/4 w-[500px] h-[500px] bg-indigo-600/10 rounded-full blur-3xl pointer-events-none glow-orb-2"></div>

    <!-- TOP HEADER -->
    <header class="p-6 sm:px-12 flex items-center justify-between z-10 border-b border-white/5 bg-slate-950/60 backdrop-blur">
        <a href="login.php" class="inline-flex items-center gap-2.5 group">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white shadow-md shadow-blue-500/25">
                <i class="fa-solid fa-graduation-cap text-xs"></i>
            </span>
            <span class="text-sm font-bold text-white group-hover:text-blue-400 transition"><?= htmlspecialchars($school_name) ?></span>
        </a>

        <div class="flex items-center gap-3">
            <span class="hidden sm:inline-flex items-center gap-1.5 text-xs text-slate-400">
                <i class="fa-solid fa-shield-halved text-emerald-400"></i> Standar Keamanan Berlapis
            </span>
            <a href="login.php" class="text-xs font-semibold text-blue-400 hover:text-blue-300 transition">
                Kembali ke Login &rarr;
            </a>
        </div>
    </header>

    <!-- MAIN CONTAINER -->
    <main class="flex-1 flex items-center justify-center px-4 py-12 z-10">
        <div class="w-full max-w-lg">

            <!-- Card Container -->
            <div class="rounded-3xl border border-white/10 bg-slate-900/80 p-7 sm:p-9 shadow-2xl backdrop-blur-xl">

                <!-- Google-Like Multi-Step Progress Indicator -->
                <div class="mb-8">
                    <div class="flex items-center justify-between text-[11px] font-semibold text-slate-400 mb-2">
                        <span class="<?= $step === 'identify' ? 'text-blue-400' : 'text-emerald-400' ?>">1. Identifikasi</span>
                        <span class="<?= $step === 'challenge' ? 'text-blue-400' : (in_array($step, ['verify_otp', 'reset_password']) ? 'text-emerald-400' : '') ?>">2. Tantangan</span>
                        <span class="<?= $step === 'verify_otp' ? 'text-blue-400' : ($step === 'reset_password' ? 'text-emerald-400' : '') ?>">3. Kode OTP</span>
                        <span class="<?= $step === 'reset_password' ? 'text-blue-400' : '' ?>">4. Sandi Baru</span>
                    </div>
                    <div class="h-1.5 w-full bg-slate-800 rounded-full overflow-hidden flex">
                        <div class="h-full bg-blue-500 transition-all duration-500 <?= $step === 'identify' ? 'w-1/4' : ($step === 'challenge' ? 'w-2/4' : ($step === 'verify_otp' ? 'w-3/4' : 'w-full bg-emerald-500')) ?>"></div>
                    </div>
                </div>

                <!-- Alert Message -->
                <?php if ($message !== ""): ?>
                    <div class="mb-6 rounded-2xl border p-4 text-xs flex items-start gap-3 
                        <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-950/40 text-emerald-200' : 
                           ($message_type === 'info' ? 'border-blue-500/30 bg-blue-950/40 text-blue-200' : 'border-rose-500/30 bg-rose-950/40 text-rose-200') ?>">
                        <div class="shrink-0 mt-0.5">
                            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : ($message_type === 'info' ? 'fa-circle-info text-blue-400' : 'fa-triangle-exclamation text-rose-400') ?> text-sm"></i>
                        </div>
                        <div class="leading-relaxed">
                            <?= htmlspecialchars($message) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ========================================== -->
                <!-- TAHAP 1: IDENTIFIKASI AKUN                 -->
                <!-- ========================================== -->
                <?php if ($step === 'identify'): ?>
                    <div class="mb-6">
                        <div class="h-12 w-12 rounded-2xl bg-blue-500/10 border border-blue-500/20 text-blue-400 flex items-center justify-center text-xl mb-4">
                            <i class="fa-solid fa-user-shield"></i>
                        </div>
                        <h2 class="text-xl font-bold text-white tracking-tight">Pemulihan Akun</h2>
                        <p class="text-xs text-slate-400 mt-1.5 leading-relaxed">
                            Masukkan alamat email resmi, NISN (Siswa), atau NIP (Guru/Staf) yang terdaftar di database sekolah.
                        </p>
                    </div>

                    <form method="POST" action="" class="space-y-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="submit_identify">

                        <div>
                            <label for="identifierInput" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                Email / NISN / NIP
                            </label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400 text-xs">
                                    <i class="fa-solid fa-id-badge"></i>
                                </span>
                                <input
                                    id="identifierInput"
                                    type="text"
                                    name="identifier"
                                    required
                                    autofocus
                                    placeholder="contoh: guru@sekolah.id atau 0081234567"
                                    class="w-full rounded-xl border border-white/10 bg-slate-950/70 pl-10 pr-4 py-2.5 text-xs text-white placeholder-slate-500 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                >
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="w-full mt-2 rounded-xl bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-500 hover:from-blue-500 hover:to-indigo-500 py-3 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition duration-200 flex items-center justify-center gap-2 cursor-pointer">
                            <span>Lanjutkan Verifikasi</span>
                            <i class="fa-solid fa-arrow-right text-[11px]"></i>
                        </button>
                    </form>

                <!-- ========================================== -->
                <!-- TAHAP 2: TANTANGAN IDENTITAS (GOOGLE PHONE)-->
                <!-- ========================================== -->
                <?php elseif ($step === 'challenge' && $user_record): ?>
                    <div class="mb-6">
                        <div class="h-12 w-12 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center text-xl mb-4">
                            <i class="fa-solid fa-mobile-screen-button"></i>
                        </div>
                        <h2 class="text-xl font-bold text-white tracking-tight">Tantangan Keamanan</h2>
                        <div class="flex items-center gap-2 mt-2 px-3 py-1.5 rounded-xl bg-white/5 border border-white/5 text-xs text-slate-300">
                            <i class="fa-solid fa-user-circle text-blue-400"></i>
                            <span class="font-medium"><?= htmlspecialchars(maskName($user_record['name'])) ?></span>
                            <span class="text-slate-500 font-mono">(<?= maskEmail($user_record['user_email']) ?>)</span>
                        </div>
                        <p class="text-xs text-slate-300 mt-3 leading-relaxed">
                            Google Security Challenge: Konfirmasikan <strong>2 digit terakhir</strong> dari nomor telepon WhatsApp pemulihan Anda:
                            <span class="font-mono font-bold text-amber-300"><?= maskPhone($user_record['user_phone'] ?: '081234567890') ?></span>
                        </p>
                    </div>

                    <form method="POST" action="" class="space-y-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="submit_challenge">

                        <div>
                            <label for="digitsInput" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                Masukkan 2 Digit Terakhir Nomor Ponsel:
                            </label>
                            <div class="relative max-w-xs">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 pointer-events-none text-slate-400 text-xs font-mono">
                                    ••
                                </span>
                                <input
                                    id="digitsInput"
                                    type="text"
                                    name="phone_digits"
                                    maxlength="2"
                                    pattern="[0-9]{2}"
                                    required
                                    autofocus
                                    placeholder="XX"
                                    class="w-full text-center tracking-widest font-mono text-base font-bold rounded-xl border border-white/10 bg-slate-950/70 pl-8 pr-4 py-2.5 text-white placeholder-slate-600 outline-none transition focus:border-amber-500 focus:ring-1 focus:ring-amber-500"
                                >
                            </div>
                            <span class="text-[11px] text-slate-500 mt-1 block">
                                Sisa percobaan keamanan: <strong><?= max(0, 3 - $user_record['attempts']) ?> kali</strong>
                            </span>
                        </div>

                        <button
                            type="submit"
                            class="w-full mt-2 rounded-xl bg-gradient-to-r from-amber-600 to-amber-500 hover:from-amber-500 hover:to-amber-400 py-3 text-xs font-bold text-white shadow-lg shadow-amber-500/25 transition duration-200 flex items-center justify-center gap-2 cursor-pointer">
                            <span>Kirim Kode OTP ke WhatsApp</span>
                            <i class="fa-solid fa-paper-plane text-[11px]"></i>
                        </button>
                    </form>

                <!-- ========================================== -->
                <!-- TAHAP 3: VERIFIKASI KODE OTP               -->
                <!-- ========================================== -->
                <?php elseif ($step === 'verify_otp' && $user_record): ?>
                    <div class="mb-6">
                        <div class="h-12 w-12 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center text-xl mb-4">
                            <i class="fa-solid fa-key"></i>
                        </div>
                        <h2 class="text-xl font-bold text-white tracking-tight">Masukkan Kode OTP</h2>
                        <p class="text-xs text-slate-300 mt-1.5 leading-relaxed">
                            Kode keamanan 6 digit telah dikirimkan via WhatsApp ke nomor 
                            <strong class="font-mono text-emerald-400"><?= maskPhone($user_record['user_phone'] ?: '081234567890') ?></strong>.
                        </p>
                    </div>

                    <!-- Simulator Demo OTP Helper Box (Khusus Pengujian Lokal) -->
                    <div class="mb-5 p-3 rounded-2xl bg-white/[0.03] border border-dashed border-emerald-500/30 flex items-center justify-between text-xs">
                        <div class="flex items-center gap-2 text-slate-300">
                            <i class="fa-brands fa-whatsapp text-emerald-400 text-sm"></i>
                            <span>OTP Simulasi: <strong class="font-mono text-emerald-300 tracking-wider font-bold"><?= htmlspecialchars($reset_record['otp_code']) ?></strong></span>
                        </div>
                        <button type="button" onclick="document.getElementById('otpInput').value = '<?= $reset_record['otp_code'] ?>'"
                                class="px-2.5 py-1 rounded-lg bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30 text-[11px] font-semibold transition cursor-pointer">
                            Salin OTP
                        </button>
                    </div>

                    <form method="POST" action="" class="space-y-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="submit_otp">

                        <div>
                            <label for="otpInput" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                Kode Verifikasi (6 Digit)
                            </label>
                            <input
                                id="otpInput"
                                type="text"
                                name="otp_code"
                                maxlength="6"
                                pattern="[0-9]{6}"
                                required
                                autofocus
                                placeholder="123456"
                                class="w-full text-center tracking-[0.5em] font-mono text-2xl font-black rounded-xl border border-white/10 bg-slate-950/70 py-3 text-white placeholder-slate-700 outline-none transition focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                            >
                            <div class="flex items-center justify-between text-[11px] text-slate-500 mt-2">
                                <span>Masa berlaku: <strong>10 Menit</strong></span>
                                <span>Sisa percobaan: <strong><?= max(0, 3 - $user_record['attempts']) ?> kali</strong></span>
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="w-full mt-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-500 hover:from-emerald-500 hover:to-teal-400 py-3 text-xs font-bold text-white shadow-lg shadow-emerald-500/25 transition duration-200 flex items-center justify-center gap-2 cursor-pointer">
                            <span>Verifikasi & Lanjutkan</span>
                            <i class="fa-solid fa-check text-[11px]"></i>
                        </button>
                    </form>

                <!-- ========================================== -->
                <!-- TAHAP 4: ATUR KATA SANDI BARU              -->
                <!-- ========================================== -->
                <?php elseif ($step === 'reset_password' && $user_record): ?>
                    <div class="mb-6">
                        <div class="h-12 w-12 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center text-xl mb-4">
                            <i class="fa-solid fa-lock"></i>
                        </div>
                        <h2 class="text-xl font-bold text-white tracking-tight">Buat Kata Sandi Baru</h2>
                        <p class="text-xs text-slate-400 mt-1.5 leading-relaxed">
                            Tentukan kata sandi kuat baru untuk akun <strong class="text-white"><?= htmlspecialchars($user_record['name']) ?></strong>.
                        </p>
                    </div>

                    <form method="POST" action="" class="space-y-4" onsubmit="return validateStrengthBeforeSubmit()">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="submit_new_password">

                        <!-- Password Baru -->
                        <div>
                            <label for="newPassword" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                Kata Sandi Baru
                            </label>
                            <div class="relative">
                                <input
                                    id="newPassword"
                                    type="password"
                                    name="new_password"
                                    required
                                    placeholder="Minimal 8 karakter unik"
                                    oninput="checkPasswordStrength(this.value)"
                                    class="w-full rounded-xl border border-white/10 bg-slate-950/70 pl-4 pr-10 py-2.5 text-xs text-white placeholder-slate-500 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                >
                                <button
                                    type="button"
                                    onclick="togglePwdVisibility('newPassword', 'eyeIcon1')"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-200 transition text-xs cursor-pointer">
                                    <i id="eyeIcon1" class="fa-solid fa-eye"></i>
                                </button>
                            </div>

                            <!-- Live Password Strength Meter Bar -->
                            <div class="mt-2">
                                <div class="flex items-center justify-between text-[10px] font-semibold text-slate-400 mb-1">
                                    <span>Tingkat Kekuatan:</span>
                                    <span id="strengthLabel" class="text-slate-500">Belum diisi</span>
                                </div>
                                <div class="h-1.5 w-full bg-slate-800 rounded-full overflow-hidden flex gap-1">
                                    <div id="bar1" class="h-full w-1/4 bg-slate-700 transition duration-300"></div>
                                    <div id="bar2" class="h-full w-1/4 bg-slate-700 transition duration-300"></div>
                                    <div id="bar3" class="h-full w-1/4 bg-slate-700 transition duration-300"></div>
                                    <div id="bar4" class="h-full w-1/4 bg-slate-700 transition duration-300"></div>
                                </div>
                            </div>

                            <!-- Interactive Requirements Checklist -->
                            <div class="mt-3 p-3 rounded-2xl bg-white/[0.02] border border-white/5 text-[11px] space-y-1.5">
                                <div id="reqLen" class="flex items-center gap-2 text-slate-500">
                                    <i class="fa-solid fa-circle-xmark text-[10px]"></i> Minimal 8 karakter
                                </div>
                                <div id="reqUpper" class="flex items-center gap-2 text-slate-500">
                                    <i class="fa-solid fa-circle-xmark text-[10px]"></i> Huruf Besar (A-Z)
                                </div>
                                <div id="reqLower" class="flex items-center gap-2 text-slate-500">
                                    <i class="fa-solid fa-circle-xmark text-[10px]"></i> Huruf Kecil (a-z)
                                </div>
                                <div id="reqNum" class="flex items-center gap-2 text-slate-500">
                                    <i class="fa-solid fa-circle-xmark text-[10px]"></i> Angka (0-9)
                                </div>
                                <div id="reqSym" class="flex items-center gap-2 text-slate-500">
                                    <i class="fa-solid fa-circle-xmark text-[10px]"></i> Simbol Khusus (!@#$%^&*)
                                </div>
                            </div>
                        </div>

                        <!-- Konfirmasi Password -->
                        <div>
                            <label for="confirmPassword" class="block text-xs font-semibold text-slate-300 mb-1.5">
                                Konfirmasi Kata Sandi Baru
                            </label>
                            <div class="relative">
                                <input
                                    id="confirmPassword"
                                    type="password"
                                    name="confirm_password"
                                    required
                                    placeholder="Ketik ulang kata sandi baru"
                                    class="w-full rounded-xl border border-white/10 bg-slate-950/70 pl-4 pr-10 py-2.5 text-xs text-white placeholder-slate-500 outline-none transition focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                >
                                <button
                                    type="button"
                                    onclick="togglePwdVisibility('confirmPassword', 'eyeIcon2')"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-slate-200 transition text-xs cursor-pointer">
                                    <i id="eyeIcon2" class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button
                            type="submit"
                            id="btnSubmitPass"
                            class="w-full mt-3 rounded-xl bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-500 hover:from-blue-500 hover:to-indigo-500 py-3 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition duration-200 flex items-center justify-center gap-2 cursor-pointer">
                            <i class="fa-solid fa-shield-check text-sm"></i>
                            <span>Simpan Kata Sandi & Selesaikan</span>
                        </button>
                    </form>
                <?php endif; ?>

            </div>

            <!-- Footer Helpdesk -->
            <div class="mt-6 text-center text-xs text-slate-400">
                Mengalami kendala pemulihan akun?
                <a href="https://wa.me/<?= $wa_helpdesk ?>?text=<?= urlencode("Halo Helpdesk {$school_name}, saya mengalami kendala pada pemulihan kata sandi akun saya.") ?>"
                   target="_blank"
                   class="font-semibold text-blue-400 hover:text-blue-300 transition underline underline-offset-2 ml-1">
                    Hubungi IT Helpdesk
                </a>
            </div>

        </div>
    </main>

    <!-- FOOTER COPYRIGHT -->
    <footer class="p-6 text-center text-xs text-slate-500 border-t border-white/5 z-10">
        &copy; <?= date('Y') ?> <?= htmlspecialchars($school_name) ?> &bull; Sistem Pemulihan Akun Terenkripsi SSL 256-Bit
    </footer>

    <!-- REAL-TIME STRENGTH METER JAVASCRIPT -->
    <script>
        function togglePwdVisibility(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        let isStrongEnough = false;

        function checkPasswordStrength(val) {
            const hasLen = val.length >= 8;
            const hasUpper = /[A-Z]/.test(val);
            const hasLower = /[a-z]/.test(val);
            const hasNum = /[0-9]/.test(val);
            const hasSym = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(val);

            updateChecklist('reqLen', hasLen);
            updateChecklist('reqUpper', hasUpper);
            updateChecklist('reqLower', hasLower);
            updateChecklist('reqNum', hasNum);
            updateChecklist('reqSym', hasSym);

            let score = 0;
            if (hasLen) score++;
            if (hasUpper) score++;
            if (hasLower) score++;
            if (hasNum) score++;
            if (hasSym) score++;

            const bar1 = document.getElementById('bar1');
            const bar2 = document.getElementById('bar2');
            const bar3 = document.getElementById('bar3');
            const bar4 = document.getElementById('bar4');
            const label = document.getElementById('strengthLabel');

            // Reset bars
            [bar1, bar2, bar3, bar4].forEach(b => {
                b.className = 'h-full w-1/4 bg-slate-700 transition duration-300';
            });

            if (score <= 1) {
                label.innerText = 'Sangat Lemah';
                label.className = 'text-rose-400 font-bold';
                bar1.className = 'h-full w-1/4 bg-rose-500';
                isStrongEnough = false;
            } else if (score === 2 || score === 3) {
                label.innerText = 'Cukup / Sedang';
                label.className = 'text-amber-400 font-bold';
                bar1.className = 'h-full w-1/4 bg-amber-500';
                bar2.className = 'h-full w-1/4 bg-amber-500';
                isStrongEnough = false;
            } else if (score === 4) {
                label.innerText = 'Kuat';
                label.className = 'text-blue-400 font-bold';
                bar1.className = 'h-full w-1/4 bg-blue-500';
                bar2.className = 'h-full w-1/4 bg-blue-500';
                bar3.className = 'h-full w-1/4 bg-blue-500';
                isStrongEnough = false;
            } else if (score === 5) {
                label.innerText = 'Sangat Kuat (Google-Grade)';
                label.className = 'text-emerald-400 font-bold';
                bar1.className = 'h-full w-1/4 bg-emerald-500';
                bar2.className = 'h-full w-1/4 bg-emerald-500';
                bar3.className = 'h-full w-1/4 bg-emerald-500';
                bar4.className = 'h-full w-1/4 bg-emerald-500';
                isStrongEnough = true;
            }
        }

        function updateChecklist(elementId, isValid) {
            const el = document.getElementById(elementId);
            if (!el) return;
            const icon = el.querySelector('i');
            if (isValid) {
                el.className = 'flex items-center gap-2 text-emerald-400 font-medium transition duration-200';
                icon.className = 'fa-solid fa-circle-check text-[10px] text-emerald-400';
            } else {
                el.className = 'flex items-center gap-2 text-slate-500 transition duration-200';
                icon.className = 'fa-solid fa-circle-xmark text-[10px] text-slate-600';
            }
        }

        function validateStrengthBeforeSubmit() {
            const p1 = document.getElementById('newPassword').value;
            const p2 = document.getElementById('confirmPassword').value;

            if (!isStrongEnough) {
                alert('Kata sandi belum memenuhi seluruh kriteria keamanan ketat (Semua 5 indikator harus hijau).');
                return false;
            }
            if (p1 !== p2) {
                alert('Konfirmasi kata sandi tidak cocok!');
                return false;
            }
            return true;
        }
    </script>
</body>
</html>
