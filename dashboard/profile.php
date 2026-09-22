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
            $stmt_up = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
            $stmt_up->execute([$name, $email, $phone, $address, $user_id]);

            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;

            $message = "Profil Anda berhasil diperbarui.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 2. GANTI PASSWORD
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'change_password') {
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

// Ambil data profil terbaru dari database
$stmt_me = $pdo->prepare("SELECT id, name, email, role, phone, address, created_at, updated_at FROM users WHERE id = ?");
$stmt_me->execute([$user_id]);
$me = $stmt_me->fetch();

if (!$me) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$page_title = "Profil Pengguna";
require_once __DIR__ . "/includes/header.php";
?>

<div class="mb-8">
    <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
        <span>👤</span> Profil Pengguna
    </h1>
    <p class="mt-1 text-sm text-slate-400">
        Kelola informasi akun pribadi dan kata sandi keamanan Anda.
    </p>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== ''): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/20 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <span><?= $message_type === 'success' ? '✅' : '⚠️' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs opacity-70 hover:opacity-100">✕</button>
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
            <h3 class="text-sm font-bold text-white mb-2">💡 Tips Keamanan</h3>
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
                <span>✏️</span> Perbarui Informasi Data Diri
            </h3>
            <p class="text-xs text-slate-400 mb-6">Ubah data nama, email kontak, dan informasi alamat Anda.</p>

            <form method="POST" class="space-y-4">
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

        <!-- Form Ganti Password -->
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6 sm:p-8 shadow-2xl">
            <h3 class="text-lg font-bold text-white mb-1 flex items-center gap-2">
                <span>🔐</span> Ganti Kata Sandi (Password)
            </h3>
            <p class="text-xs text-slate-400 mb-6">Pastikan password baru Anda kuat dan tidak mudah ditebak.</p>

            <form method="POST" class="space-y-4">
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

    </div>

</div>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
