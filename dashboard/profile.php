<?php
session_start();
require_once __DIR__ . "/../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. UPDATE DATA DIRI
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'update_profile') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '' || $email === '') {
            $message = "Nama dan email wajib diisi.";
            $message_type = "error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Format email tidak valid.";
            $message_type = "error";
        } else {
            // Cek duplikasi email pada user lain
            $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt_check->execute([$email, $user_id]);
            if ($stmt_check->fetch()) {
                $message = "Email sudah digunakan oleh akun lain.";
                $message_type = "error";
            } else {
                $nip = trim($_POST['nip'] ?? '');
                $title_degree = trim($_POST['title_degree'] ?? '');

                $stmt_up = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, nip = ?, title_degree = ? WHERE id = ?");
                $stmt_up->execute([$name, $email, $phone, $address, $nip, $title_degree, $user_id]);

                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;

                $message = "Profil Anda berhasil diperbarui.";
                $message_type = "success";
            }
        }
    }
}

// -------------------------------------------------------------
// 2. UPDATE TANDA TANGAN DIGITAL (GURU / STAF / ADMIN)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'update_signature') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $sig_dir = __DIR__ . "/../uploads/signatures/";
        if (!is_dir($sig_dir)) {
            mkdir($sig_dir, 0755, true);
        }

        $saved = false;

        // A. Cek jika reset ke grafis bawaan
        if (isset($_POST['reset_signature']) && $_POST['reset_signature'] === '1') {
            $stmt_sig = $pdo->prepare("UPDATE users SET signature_image = 'uploads/signatures/default_teacher_ttd.svg' WHERE id = ?");
            $stmt_sig->execute([$user_id]);
            logActivity($pdo, 'RESET_TEACHER_SIGNATURE', "Mereset tanda tangan akun guru ID $user_id ke bawaan");
            $message = "Tanda tangan berhasil dikembalikan ke grafis vektor bawaan resmi!";
            $message_type = "success";
            $saved = true;
        }

        // B. Cek jika menggunakan Canvas Pad (Base64)
        $canvas_data = $_POST['signature_canvas_data'] ?? '';
        if (!$saved && !empty($canvas_data) && strpos($canvas_data, 'data:image/png;base64,') === 0) {
            $base64_str = str_replace('data:image/png;base64,', '', $canvas_data);
            $img_decoded = base64_decode($base64_str);
            if ($img_decoded !== false) {
                $filename = "teacher_{$user_id}_" . time() . ".png";
                $target_path = $sig_dir . $filename;
                if (file_put_contents($target_path, $img_decoded)) {
                    $db_path = "uploads/signatures/" . $filename;
                    $stmt_sig = $pdo->prepare("UPDATE users SET signature_image = ? WHERE id = ?");
                    $stmt_sig->execute([$db_path, $user_id]);
                    logActivity($pdo, 'UPDATE_TEACHER_SIGNATURE', "Memperbarui tanda tangan digital akun guru ID $user_id (Canvas)");
                    $message = "Goresan tanda tangan digital Anda berhasil disimpan!";
                    $message_type = "success";
                    $saved = true;
                }
            }
        }

        // C. Cek jika unggah berkas gambar/SVG
        if (!$saved && isset($_FILES['signature_file']) && $_FILES['signature_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['signature_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['png', 'svg', 'webp', 'jpg', 'jpeg'];
            if (!in_array($ext, $allowed_exts, true)) {
                $message = "Format berkas tidak valid. Harap gunakan berkas gambar PNG transparan, SVG, atau WEBP.";
                $message_type = "error";
            } else {
                // Sanitasi berkas SVG untuk mencegah Stored XSS via script atau event handler
                if ($ext === 'svg') {
                    $svg_content = (string) file_get_contents($file['tmp_name']);
                    $svg_content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $svg_content);
                    $svg_content = preg_replace('/\bon\w+\s*=\s*(["\']).*?\1/is', '', $svg_content);
                    $svg_content = preg_replace('/\bon\w+\s*=\s*[^>\s]+/is', '', $svg_content);
                    $svg_content = preg_replace('/javascript:/i', 'blocked:', $svg_content);
                    $svg_content = preg_replace('/<foreignObject\b[^>]*>(.*?)<\/foreignObject>/is', '', $svg_content);
                    file_put_contents($file['tmp_name'], $svg_content);
                }

                $filename = "teacher_{$user_id}_" . time() . "." . $ext;
                $target_path = $sig_dir . $filename;
                if (move_uploaded_file($file['tmp_name'], $target_path)) {
                    $db_path = "uploads/signatures/" . $filename;
                    $stmt_sig = $pdo->prepare("UPDATE users SET signature_image = ? WHERE id = ?");
                    $stmt_sig->execute([$db_path, $user_id]);
                    logActivity($pdo, 'UPLOAD_TEACHER_SIGNATURE', "Mengunggah berkas scan tanda tangan akun guru ID $user_id");
                    $message = "Berkas scan tanda tangan Anda berhasil diperbarui!";
                    $message_type = "success";
                    $saved = true;
                } else {
                    $message = "Gagal memindahkan berkas tanda tangan yang diunggah.";
                    $message_type = "error";
                }
            }
        }

        if (!$saved && empty($message)) {
            $message = "Silakan goreskan tanda tangan atau pilih berkas gambar tanda tangan terlebih dahulu.";
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 3. GANTI PASSWORD
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'change_password') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($current_password === '' || $new_password === '') {
            $message = "Password lama dan password baru wajib diisi.";
            $message_type = "error";
        } elseif (strlen($new_password) < 6) {
            $message = "Password baru minimal 6 karakter.";
            $message_type = "error";
        } elseif ($new_password !== $confirm_password) {
            $message = "Konfirmasi password baru tidak cocok.";
            $message_type = "error";
        } else {
            $stmt_pwd = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt_pwd->execute([$user_id]);
            $user_data = $stmt_pwd->fetch();

            if ($user_data && password_verify($current_password, $user_data['password'])) {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt_update_pwd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt_update_pwd->execute([$hashed, $user_id]);

                $message = "Password Anda berhasil diubah.";
                $message_type = "success";
            } else {
                $message = "Password lama yang Anda masukkan salah.";
                $message_type = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// 4. AKTIFKAN TWO-FACTOR AUTHENTICATION (2FA TOTP)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'enable_2fa') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $totp_code = trim($_POST['totp_code'] ?? '');
        $temp_secret = $_SESSION['temp_2fa_secret'] ?? '';

        if (empty($temp_secret)) {
            $message = "Sesi kunci rahasia 2FA telah kedaluwarsa. Silakan muat ulang halaman.";
            $message_type = "error";
        } elseif (verifyTotpCode($temp_secret, $totp_code)) {
            $stmt_2fa = $pdo->prepare("UPDATE users SET two_factor_secret = ?, two_factor_enabled = 1, two_factor_confirmed_at = NOW() WHERE id = ?");
            $stmt_2fa->execute([$temp_secret, $user_id]);
            unset($_SESSION['temp_2fa_secret']);

            logActivity($pdo, 'ENABLE_2FA', "Mengaktifkan Two-Factor Authentication (2FA) Google Authenticator");
            $message = "Two-Factor Authentication (2FA) berhasil diaktifkan! Akun Anda kini terlindungi dengan verifikasi Google Authenticator saat login.";
            $message_type = "success";
        } else {
            $message = "Kode OTP 6-digit tidak valid atau telah kedaluwarsa. Pastikan jam di ponsel Anda telah disinkronkan otomatis dan coba lagi.";
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 5. NONAKTIFKAN TWO-FACTOR AUTHENTICATION (2FA)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'disable_2fa') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $account_pwd = $_POST['account_password'] ?? '';
        $stmt_chk = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt_chk->execute([$user_id]);
        $u_pwd = $stmt_chk->fetchColumn();

        if ($u_pwd && password_verify($account_pwd, $u_pwd)) {
            $stmt_off = $pdo->prepare("UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0, two_factor_confirmed_at = NULL WHERE id = ?");
            $stmt_off->execute([$user_id]);
            unset($_SESSION['temp_2fa_secret']);

            logActivity($pdo, 'DISABLE_2FA', "Menonaktifkan Two-Factor Authentication (2FA)");
            $message = "Two-Factor Authentication (2FA) berhasil dinonaktifkan.";
            $message_type = "success";
        } else {
            $message = "Kata sandi akun yang Anda masukkan salah. 2FA tidak dapat dinonaktifkan.";
            $message_type = "error";
        }
    }
}

// Ambil data profil terbaru dari database
$stmt_me = $pdo->prepare("SELECT id, name, email, role, phone, address, nip, title_degree, signature_image, two_factor_secret, two_factor_enabled, two_factor_confirmed_at, created_at, updated_at FROM users WHERE id = ?");
$stmt_me->execute([$user_id]);
$me = $stmt_me->fetch();

if (!$me) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$is_educator = in_array($me['role'], ['guru', 'staf', 'administrator'], true);

$page_title = "Profil Pengguna";
require_once __DIR__ . "/includes/header.php";
?>

<div class="mb-8">
    <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
        <span class="text-blue-400"><i class="fa-solid fa-user-gear"></i></span> Profil Pengguna
    </h1>
    <p class="mt-1 text-sm text-slate-400">
        Kelola informasi akun pribadi dan kata sandi keamanan Anda.
    </p>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== ''): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/20 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <span><i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-amber-400' ?>"></i></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
    
    <!-- Kartu Informasi Akun -->
    <div class="space-y-6">
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-2xl text-center">
            <div class="mx-auto flex h-24 w-24 items-center justify-center rounded-3xl bg-gradient-to-tr from-blue-600 to-indigo-500 text-4xl font-extrabold text-white shadow-xl shadow-blue-500/20 mb-4">
                <?= strtoupper(mb_substr($me['name'], 0, 1)) ?>
            </div>

            <h2 class="text-xl font-bold text-white"><?= htmlspecialchars($me['name']) ?></h2>
            <p class="text-xs text-slate-400 mt-1"><?= htmlspecialchars($me['email']) ?></p>

            <div class="mt-4 inline-flex items-center rounded-xl border px-3 py-1 text-xs font-semibold uppercase tracking-wider <?= getRoleBadge($me['role']) ?>">
                <span>Role: <?= htmlspecialchars(getRoleLabel($me['role'])) ?></span>
            </div>

            <div class="mt-6 border-t border-white/10 pt-4 space-y-3 text-left text-xs text-slate-400">
                <div class="flex justify-between">
                    <span>ID Pengguna:</span>
                    <span class="font-mono text-slate-200">#<?= $me['id'] ?></span>
                </div>
                <div class="flex justify-between">
                    <span>Terdaftar Pada:</span>
                    <span class="text-slate-200"><?= date('d M Y', strtotime($me['created_at'])) ?></span>
                </div>
                <div class="flex justify-between">
                    <span>Terakhir Update:</span>
                    <span class="text-slate-200"><?= date('d M Y, H:i', strtotime($me['updated_at'])) ?></span>
                </div>
            </div>
        </div>

        <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
            <h3 class="text-sm font-bold text-white mb-2 flex items-center gap-2"><i class="fa-regular fa-lightbulb text-amber-400"></i> Tips Keamanan</h3>
            <p class="text-xs text-slate-400 leading-relaxed">
                Gunakan kombinasi huruf besar, angka, dan karakter khusus saat memperbarui kata sandi Anda agar akun Anda selalu terlindungi.
            </p>
        </div>
    </div>

    <!-- Form Edit Profil & Ganti Password -->
    <div class="lg:col-span-2 space-y-8">
        
        <!-- Form Data Diri -->
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6 sm:p-8 shadow-2xl">
            <h3 class="text-lg font-bold text-white mb-1 flex items-center gap-2">
                <span class="text-blue-400"><i class="fa-solid fa-user-pen"></i></span> Perbarui Informasi Data Diri
            </h3>
            <p class="text-xs text-slate-400 mb-6">Ubah data nama, email kontak, dan informasi alamat Anda.</p>

            <form method="POST" class="space-y-4">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="update_profile">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-300">Nama Lengkap *</label>
                        <input type="text" name="name" required value="<?= htmlspecialchars($me['name']) ?>" class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-300">Email *</label>
                        <input type="email" name="email" required value="<?= htmlspecialchars($me['email']) ?>" class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-300">Nomor Telepon / WhatsApp</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($me['phone'] ?? '') ?>" placeholder="08123456789" class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-300">Role Sistem</label>
                        <input type="text" disabled value="<?= htmlspecialchars(getRoleLabel($me['role'])) ?>" class="w-full rounded-xl border border-white/5 bg-slate-950/60 px-4 py-2.5 text-sm text-slate-500 cursor-not-allowed">
                    </div>
                </div>

                <?php if ($is_educator): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-white/5">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-amber-300 flex items-center gap-1.5">
                                <i class="fa-solid fa-id-card"></i> NIP / NUPTK Resmi
                            </label>
                            <input type="text" name="nip" value="<?= htmlspecialchars($me['nip'] ?? '') ?>" placeholder="Contoh: 19820415 200801 2 015" class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-2.5 text-sm text-white font-mono outline-none focus:border-amber-500">
                            <span class="text-[10px] text-slate-400 mt-1 block">Dicantumkan pada rapor siswa, kuitansi, atau lembar ujian</span>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-amber-300 flex items-center gap-1.5">
                                <i class="fa-solid fa-graduation-cap"></i> Gelar Akademik / Kualifikasi
                            </label>
                            <input type="text" name="title_degree" value="<?= htmlspecialchars($me['title_degree'] ?? '') ?>" placeholder="Contoh: S.Pd., M.Si / Gr." class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-2.5 text-sm text-white outline-none focus:border-amber-500">
                            <span class="text-[10px] text-slate-400 mt-1 block">Otomatis digabungkan di belakang nama Anda pada dokumen cetak</span>
                        </div>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Alamat Lengkap / Keterangan</label>
                    <textarea name="address" rows="3" placeholder="Masukkan alamat tempat tinggal..." class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500"><?= htmlspecialchars($me['address'] ?? '') ?></textarea>
                </div>

                <div class="flex justify-end pt-2">
                    <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition">
                        Simpan Perubahan Profil
                    </button>
                </div>
            </form>
        </div>

        <?php if ($is_educator): ?>
            <!-- CARD MANAJEMEN TANDA TANGAN DIGITAL (TTE GURU) -->
            <div id="signature" class="rounded-3xl border border-amber-500/20 bg-gradient-to-b from-slate-900/90 to-slate-950 p-6 sm:p-8 shadow-2xl backdrop-blur">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-4 border-b border-white/10 gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-white flex items-center gap-2">
                            <span class="text-amber-400"><i class="fa-solid fa-signature"></i></span> Tanda Tangan Digital Guru (TTE) & Arsip Pengesahan
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">
                            Kelola tanda tangan pribadi Anda untuk pengesahan otomatis pada Rapor Siswa (Wali Kelas), Soal Ujian, dan Jurnal Mengajar.
                        </p>
                    </div>
                    
                    <form method="POST" onsubmit="return confirm('Kembalikan tanda tangan ke grafis vektor bawaan resmi sistem?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="form_action" value="update_signature">
                        <input type="hidden" name="reset_signature" value="1">
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-1.5 text-xs font-semibold text-slate-300 hover:text-white transition cursor-pointer">
                            <i class="fa-solid fa-rotate-left text-amber-400"></i> Reset Bawaan
                        </button>
                    </form>
                </div>

                <!-- TAMPILAN TTD AKTIF SAAT INI -->
                <div class="my-6 p-4 rounded-2xl bg-white/5 border border-white/10 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="h-20 w-36 bg-white rounded-xl p-2 flex items-center justify-center border border-slate-200 shadow-inner overflow-hidden">
                            <?php 
                            $cur_ttd = !empty($me['signature_image']) ? $me['signature_image'] : 'uploads/signatures/default_teacher_ttd.svg';
                            ?>
                            <img src="../<?= ltrim($cur_ttd, '/') ?>" alt="TTD Saya" class="max-h-full max-w-full object-contain">
                        </div>
                        <div>
                            <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 mb-1">
                                <i class="fa-solid fa-circle-check"></i> Tanda Tangan Aktif
                            </span>
                            <h4 class="text-sm font-bold text-white"><?= htmlspecialchars($me['name']) ?><?= !empty($me['title_degree']) ? ', ' . htmlspecialchars($me['title_degree']) : '' ?></h4>
                            <p class="text-xs text-slate-400 font-mono">NIP: <?= !empty($me['nip']) ? htmlspecialchars($me['nip']) : 'Belum diisi' ?></p>
                        </div>
                    </div>

                    <div class="text-right text-[11px] text-slate-400">
                        <span class="block text-slate-500">Diterapkan otomatis pada:</span>
                        <strong class="text-slate-300">Rapor Siswa, Lembar Soal Ujian, Arsip Guru</strong>
                    </div>
                </div>

                <!-- DUA PILIHAN: KANVAS ATAU UNGGAH SCAN -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- OPSI 1: KANVAS TANDA TANGAN LANGSUNG -->
                    <div class="p-5 rounded-2xl bg-slate-950/80 border border-white/10 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="text-xs font-bold text-amber-300 flex items-center gap-1.5 uppercase tracking-wider">
                                    <i class="fa-solid fa-pen-nib"></i> Opsi A: Goreskan Langsung
                                </h4>
                                <span class="text-[10px] text-slate-400">Mouse / Layar Sentuh HP</span>
                            </div>
                            <p class="text-[11px] text-slate-400 mb-3">
                                Buat goresan tanda tangan Anda secara langsung di kanvas digital ini:
                            </p>

                            <div class="relative rounded-xl border border-slate-300 bg-white overflow-hidden shadow-inner flex items-center justify-center">
                                <canvas id="signaturePad" width="340" height="150" class="w-full h-36 touch-none cursor-crosshair"></canvas>
                                <span class="absolute bottom-1 right-2 text-[9px] text-slate-400 pointer-events-none select-none">Area TTD</span>
                            </div>
                        </div>

                        <form id="signaturePadForm" method="POST" class="mt-4 flex items-center justify-between gap-2">
                            <?= csrfField() ?>
                            <input type="hidden" name="form_action" value="update_signature">
                            <input type="hidden" id="sigCanvasData" name="signature_canvas_data" value="">

                            <button type="button" id="btnClearPad" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer flex items-center gap-1">
                                <i class="fa-solid fa-eraser"></i> Hapus
                            </button>

                            <button type="submit" class="rounded-xl bg-amber-600 hover:bg-amber-500 px-4 py-2 text-xs font-bold text-white shadow-md shadow-amber-600/25 transition cursor-pointer flex items-center gap-1.5">
                                <i class="fa-solid fa-check"></i> Simpan Goresan Ini
                            </button>
                        </form>
                    </div>

                    <!-- OPSI 2: UNGGAH SCAN TTD (PNG/SVG) -->
                    <div class="p-5 rounded-2xl bg-slate-950/80 border border-white/10 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <h4 class="text-xs font-bold text-blue-300 flex items-center gap-1.5 uppercase tracking-wider">
                                    <i class="fa-solid fa-upload"></i> Opsi B: Unggah Berkas Scan
                                </h4>
                                <span class="text-[10px] text-slate-400">PNG / SVG / WEBP</span>
                            </div>
                            <p class="text-[11px] text-slate-400 mb-3">
                                Unggah foto pindaian tanda tangan berlatar belakang transparan untuk hasil cetak maksimal:
                            </p>

                            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                                <?= csrfField() ?>
                                <input type="hidden" name="form_action" value="update_signature">

                                <div class="border-2 border-dashed border-white/15 rounded-xl p-4 text-center hover:border-blue-500/50 transition">
                                    <i class="fa-solid fa-file-signature text-2xl text-blue-400 mb-2"></i>
                                    <input type="file" name="signature_file" required accept=".png,.svg,.webp,.jpg,.jpeg" 
                                           class="w-full text-xs text-slate-300 file:mr-2 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500 cursor-pointer">
                                    <p class="text-[10px] text-slate-500 mt-2">Maksimal 2 MB. Disarankan PNG transparan 300x120 px.</p>
                                </div>

                                <div class="flex justify-end pt-1">
                                    <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-md shadow-blue-600/25 transition cursor-pointer flex items-center gap-1.5">
                                        <i class="fa-solid fa-cloud-arrow-up"></i> Unggah Scan TTD
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Info TTE Dokumen -->
                        <div class="mt-4 pt-3 border-t border-white/5 flex items-center gap-2 text-[10px] text-slate-400">
                            <i class="fa-solid fa-shield-halved text-emerald-400"></i>
                            <span>Tanda tangan ini otomatis diverifikasi pada sistem QR TTE Dokumen Sekolah.</span>
                        </div>
                    </div>

                </div>

                <!-- SIMULASI TAMPILAN PADA RAPOR SISWA -->
                <div class="mt-6 p-4 rounded-2xl bg-white text-slate-900 border border-slate-200 shadow-md">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500 block mb-2">
                        <i class="fa-solid fa-eye text-blue-600 mr-1"></i> Simulasi Tampilan Tanda Tangan Anda pada Lembar Rapor Siswa / Soal Ujian:
                    </span>
                    <div class="border-t border-dashed border-slate-300 pt-3 flex justify-end">
                        <?= renderOfficialSignatureHtml($pdo, 'wali_kelas', [
                            'teacher_id' => $me['id'],
                            'custom_title' => 'Wali Kelas / Guru Pengampu',
                            'doc_type' => 'RAPOR',
                            'doc_num' => 'RAPOR-SIMULASI-' . date('Y')
                        ]) ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form Ganti Password -->
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6 sm:p-8 shadow-2xl">
            <h3 class="text-lg font-bold text-white mb-1 flex items-center gap-2">
                <span class="text-amber-400"><i class="fa-solid fa-key"></i></span> Ganti Kata Sandi (Password)
            </h3>
            <p class="text-xs text-slate-400 mb-6">Pastikan password baru Anda kuat dan tidak mudah ditebak.</p>

            <form method="POST" class="space-y-4">
                <?= csrfField() ?>
                <input type="hidden" name="form_action" value="change_password">

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Password Saat Ini (Lama) *</label>
                    <input type="password" name="current_password" required placeholder="Masukkan password akun Anda saat ini" class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-300">Password Baru *</label>
                        <input type="password" name="new_password" required placeholder="Minimal 6 karakter" class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-300">Konfirmasi Password Baru *</label>
                        <input type="password" name="confirm_password" required placeholder="Ulangi password baru" class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <button type="submit" class="rounded-xl border border-white/10 bg-white/10 hover:bg-white/15 px-6 py-2.5 text-sm font-semibold text-white transition">
                        Perbarui Kata Sandi
                    </button>
                </div>
            </form>
        </div>

        <!-- ============================================================== -->
        <!-- TWO-FACTOR AUTHENTICATION (2FA GOOGLE AUTHENTICATOR / TOTP)     -->
        <!-- ============================================================== -->
        <?php
        $is_2fa_enabled = (!empty($me['two_factor_enabled']) && (int)$me['two_factor_enabled'] === 1 && !empty($me['two_factor_secret']));
        if (!$is_2fa_enabled) {
            if (empty($_SESSION['temp_2fa_secret'])) {
                $_SESSION['temp_2fa_secret'] = generateTotpSecret(16);
            }
            $active_secret = $_SESSION['temp_2fa_secret'];
            $totp_qr_uri = getTotpQrUri('SMA Bina Bangsa', $me['email'], $active_secret);
        }
        ?>
        <div class="rounded-3xl border border-white/10 bg-slate-900/90 p-6 sm:p-8 shadow-2xl relative overflow-hidden">
            <!-- Ambient Glow -->
            <div class="absolute -top-16 -right-16 w-48 h-48 bg-blue-600/10 rounded-full blur-3xl pointer-events-none"></div>

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 pb-5 border-b border-white/10">
                <div>
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600/20 border border-blue-500/30 text-blue-400">
                            <i class="fa-solid fa-shield-halved text-base"></i>
                        </span>
                        <div>
                            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                                <span>Autentikasi Dua Langkah (2FA)</span>
                                <?php if ($is_2fa_enabled): ?>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/20 border border-emerald-500/30 px-2.5 py-0.5 text-[10px] font-extrabold text-emerald-300">
                                        <i class="fa-solid fa-circle-check"></i> Aktif & Terlindungi
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-500/20 border border-slate-500/30 px-2.5 py-0.5 text-[10px] font-bold text-slate-400">
                                        Belum Aktif
                                    </span>
                                <?php endif; ?>
                            </h3>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Google Authenticator / TOTP (Time-based One-Time Password) RFC 6238.
                            </p>
                        </div>
                    </div>
                </div>

                <?php if (in_array($me['role'], ['administrator', 'staf'], true)): ?>
                    <span class="inline-flex items-center gap-1.5 rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs font-semibold text-amber-300">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>Sangat Direkomendasikan untuk Role <?= htmlspecialchars(ucfirst($me['role'])) ?></span>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($is_2fa_enabled): ?>
                <!-- STATUS 2FA AKTIF -->
                <div class="rounded-2xl border border-emerald-500/30 bg-emerald-950/20 p-5 space-y-4">
                    <div class="flex items-start gap-4">
                        <div class="h-12 w-12 rounded-2xl bg-emerald-500/20 border border-emerald-500/30 flex items-center justify-center text-emerald-400 text-xl shrink-0">
                            <i class="fa-solid fa-lock"></i>
                        </div>
                        <div class="space-y-1">
                            <h4 class="text-sm font-bold text-white">Akun Anda Telah Dilindungi Verifikasi 2FA</h4>
                            <p class="text-xs text-slate-300 leading-relaxed">
                                Setiap kali Anda masuk ke portal, sistem akan mewajibkan 6-digit kode OTP dari aplikasi <strong>Google Authenticator</strong> pada perangkat Anda setelah kata sandi berhasil dicocokkan.
                            </p>
                            <p class="text-[11px] text-slate-400 pt-1">
                                <i class="fa-regular fa-calendar-check mr-1 text-emerald-400"></i>
                                Diaktifkan pada: <strong><?= !empty($me['two_factor_confirmed_at']) ? date('d M Y, H:i', strtotime($me['two_factor_confirmed_at'])) : date('d M Y') ?> WIB</strong>
                            </p>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-white/10 flex items-center justify-between">
                        <span class="text-xs text-slate-400">Ingin menonaktifkan proteksi ini?</span>
                        <button type="button" onclick="document.getElementById('modalDisable2fa').classList.remove('hidden')" class="rounded-xl border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 px-4 py-2 text-xs font-bold text-rose-300 transition cursor-pointer">
                            <i class="fa-solid fa-power-off mr-1"></i> Nonaktifkan 2FA
                        </button>
                    </div>
                </div>

                <!-- MODAL NONAKTIFKAN 2FA -->
                <div id="modalDisable2fa" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm p-4 animate-in fade-in duration-200">
                    <div class="max-w-md w-full rounded-3xl border border-rose-500/30 bg-slate-900 p-6 sm:p-7 shadow-2xl space-y-4">
                        <div class="flex items-center gap-3">
                            <div class="h-10 w-10 rounded-xl bg-rose-500/20 text-rose-400 flex items-center justify-center text-lg">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                            <div>
                                <h4 class="text-base font-bold text-white">Konfirmasi Nonaktifkan 2FA</h4>
                                <p class="text-xs text-slate-400">Akun Anda tidak lagi terlindungi verifikasi dua langkah.</p>
                            </div>
                        </div>

                        <form method="POST" class="space-y-4 pt-2">
                            <?= csrfField() ?>
                            <input type="hidden" name="form_action" value="disable_2fa">

                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1.5">
                                    Masukkan Kata Sandi Akun untuk Konfirmasi:
                                </label>
                                <input type="password" name="account_password" required placeholder="Kata sandi akun Anda saat ini" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-xs text-white outline-none focus:border-rose-500">
                            </div>

                            <div class="flex items-center justify-end gap-2 pt-2">
                                <button type="button" onclick="document.getElementById('modalDisable2fa').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                                    Batal
                                </button>
                                <button type="submit" class="rounded-xl bg-rose-600 hover:bg-rose-500 px-5 py-2 text-xs font-bold text-white transition cursor-pointer shadow-lg shadow-rose-600/25">
                                    Ya, Nonaktifkan 2FA
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- FORM AKTIVASI 2FA -->
                <div class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
                        
                        <!-- Kolom Kiri: QR Code & Secret Text -->
                        <div class="md:col-span-5 flex flex-col items-center justify-center p-5 rounded-2xl border border-white/10 bg-slate-950/60 text-center">
                            <span class="text-[11px] font-bold text-slate-300 mb-3 flex items-center gap-1.5 uppercase tracking-wider">
                                <i class="fa-solid fa-qrcode text-blue-400"></i> Pindai dengan Aplikasi
                            </span>
                            
                            <!-- QR Code Container -->
                            <div class="p-3 bg-white rounded-2xl shadow-xl border border-white/20 inline-block">
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($totp_qr_uri) ?>" 
                                     alt="QR Code 2FA" 
                                     class="w-40 h-40 object-contain rounded-lg">
                            </div>

                            <div class="mt-4 w-full">
                                <span class="block text-[10px] text-slate-400 mb-1">Atau masukkan Kunci Rahasia secara manual:</span>
                                <div class="flex items-center justify-between gap-1 p-2 rounded-xl bg-white/5 border border-white/10 text-xs font-mono font-bold text-blue-300">
                                    <span id="secretKeyText" class="tracking-wider select-all"><?= htmlspecialchars($active_secret) ?></span>
                                    <button type="button" onclick="copySecretKey()" class="text-slate-400 hover:text-white p-1 rounded transition" title="Salin Kunci">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Kolom Kanan: Panduan Langkah & Form Verifikasi -->
                        <div class="md:col-span-7 space-y-4">
                            <h4 class="text-sm font-bold text-white">Langkah Aktivasi Pengamanan 2FA:</h4>
                            
                            <ol class="space-y-2 text-xs text-slate-300">
                                <li class="flex items-start gap-2">
                                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-blue-500/20 text-blue-400 text-[10px] font-bold shrink-0 mt-0.5">1</span>
                                    <span>Buka aplikasi <strong>Google Authenticator</strong> (atau Authy / Microsoft Authenticator) pada smartphone Anda.</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-blue-500/20 text-blue-400 text-[10px] font-bold shrink-0 mt-0.5">2</span>
                                    <span>Pilih opsi <strong>Pindai Kode QR</strong> lalu arahkan kamera ke barcode di samping.</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-blue-500/20 text-blue-400 text-[10px] font-bold shrink-0 mt-0.5">3</span>
                                    <span>Masukkan 6-digit angka OTP yang muncul untuk mengonfirmasi aktivasi di bawah ini:</span>
                                </li>
                            </ol>

                            <form method="POST" class="pt-3 space-y-3">
                                <?= csrfField() ?>
                                <input type="hidden" name="form_action" value="enable_2fa">

                                <div>
                                    <label class="block text-xs font-semibold text-slate-200 mb-1.5">
                                        Kode Verifikasi 6-Digit dari Aplikasi Authenticator *
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <input type="text" 
                                               name="totp_code" 
                                               required 
                                               maxlength="6" 
                                               pattern="[0-9]{6}" 
                                               inputmode="numeric" 
                                               autocomplete="one-time-code" 
                                               placeholder="Contoh: 489201" 
                                               class="w-full tracking-widest text-lg font-mono font-bold rounded-xl border border-white/20 bg-slate-950 px-4 py-2.5 text-white placeholder-slate-600 outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                                        <button type="submit" class="shrink-0 rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-3 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition cursor-pointer flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-check"></i> Verifikasi & Aktifkan
                                        </button>
                                    </div>
                                    <p class="text-[11px] text-slate-500 mt-1">Kode berganti setiap 30 detik pada layar ponsel Anda.</p>
                                </div>
                            </form>
                        </div>

                    </div>
                </div>
            <?php endif; ?>
        </div>
        <script>
        function copySecretKey() {
            const txt = document.getElementById('secretKeyText').innerText;
            navigator.clipboard.writeText(txt).then(() => {
                alert('Kunci rahasia 2FA berhasil disalin: ' + txt);
            }).catch(() => {
                prompt('Salin manual kunci rahasia:', txt);
            });
        }
        </script>

    </div>

</div>

<!-- SCRIPT KANVAS TANDA TANGAN INTERAKTIF -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('signaturePad');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    let isDrawing = false;
    let hasDrawn = false;

    ctx.lineWidth = 2.8;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#1d4ed8'; // Tinta biru resmi TTD

    function getPos(e) {
        const rect = canvas.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        return {
            x: (clientX - rect.left) * (canvas.width / rect.width),
            y: (clientY - rect.top) * (canvas.height / rect.height)
        };
    }

    function startDrawing(e) {
        isDrawing = true;
        hasDrawn = true;
        const pos = getPos(e);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
        if (e.cancelable) e.preventDefault();
    }

    function draw(e) {
        if (!isDrawing) return;
        const pos = getPos(e);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        if (e.cancelable) e.preventDefault();
    }

    function stopDrawing() {
        if (isDrawing) {
            isDrawing = false;
            ctx.closePath();
        }
    }

    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    window.addEventListener('mouseup', stopDrawing);

    canvas.addEventListener('touchstart', startDrawing, { passive: false });
    canvas.addEventListener('touchmove', draw, { passive: false });
    window.addEventListener('touchend', stopDrawing);

    const clearBtn = document.getElementById('btnClearPad');
    if (clearBtn) {
        clearBtn.addEventListener('click', function() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            hasDrawn = false;
            document.getElementById('sigCanvasData').value = '';
        });
    }

    const padForm = document.getElementById('signaturePadForm');
    if (padForm) {
        padForm.addEventListener('submit', function(e) {
            if (!hasDrawn) {
                alert('Silakan goreskan tanda tangan Anda pada bidang kanvas terlebih dahulu.');
                e.preventDefault();
                return false;
            }
            document.getElementById('sigCanvasData').value = canvas.toDataURL('image/png');
        });
    }
});
</script>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
