<?php
/**
 * PPDB Online - Portal Pendaftaran Siswa Baru TA 2026/2027
 * Portal pendaftaran mandiri calon siswa, upload berkas, dan pelacakan status seleksi.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/csrf.php';

$active_tab = $_GET['tab'] ?? 'daftar';
$success_reg = null;
$error_msg = null;
$search_result = null;

// Handle Form Submit Pendaftaran Baru
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_ppdb') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        $full_name       = trim($_POST['full_name'] ?? '');
        $nisn            = trim($_POST['nisn'] ?? '');
        $nik             = trim($_POST['nik'] ?? '');
        $gender          = in_array($_POST['gender'] ?? '', ['L', 'P'], true) ? $_POST['gender'] : 'L';
        $birth_place     = trim($_POST['birth_place'] ?? '');
        $birth_date      = trim($_POST['birth_date'] ?? '');
        $religion        = trim($_POST['religion'] ?? 'Islam');
        $phone           = trim($_POST['phone'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $address         = trim($_POST['address'] ?? '');
        $previous_school = trim($_POST['previous_school'] ?? '');
        $chosen_major    = trim($_POST['chosen_major'] ?? 'MIPA (Matematika & IPA)');
        $parent_name     = trim($_POST['parent_name'] ?? '');
        $parent_phone    = trim($_POST['parent_phone'] ?? '');
        $parent_job      = trim($_POST['parent_job'] ?? '');

        if (empty($full_name) || empty($nisn) || empty($birth_place) || empty($birth_date) || empty($phone) || empty($email) || empty($address) || empty($previous_school) || empty($parent_name) || empty($parent_phone)) {
            $error_msg = 'Harap lengkapi semua kolom biodata wajib bertanda bintang (*).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Format alamat email tidak valid.';
        } else {
            try {
                // Cek apakah NISN sudah pernah mendaftar
                $stmt_check = $pdo->prepare("SELECT id, registration_no FROM ppdb_registrations WHERE nisn = ?");
                $stmt_check->execute([$nisn]);
                $existing = $stmt_check->fetch();

                if ($existing) {
                    $error_msg = "NISN {$nisn} sudah terdaftar dengan Nomor Registrasi: {$existing['registration_no']}. Silakan gunakan menu Cek Status.";
                    $active_tab = 'cek';
                } else {
                    // Upload Berkas Dokumen jika ada
                    $upload_dir = __DIR__ . '/uploads/ppdb';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $uploaded_docs = [
                        'report_card_doc' => null,
                        'birth_cert_doc'  => null,
                        'family_card_doc' => null,
                        'photo_doc'       => null
                    ];

                    $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];

                    foreach ($uploaded_docs as $field_key => &$file_path) {
                        if (isset($_FILES[$field_key]) && $_FILES[$field_key]['error'] === UPLOAD_ERR_OK) {
                            $file_tmp = $_FILES[$field_key]['tmp_name'];
                            $file_name = $_FILES[$field_key]['name'];
                            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                            if (in_array($file_ext, $allowed_exts, true) && $_FILES[$field_key]['size'] <= 5 * 1024 * 1024) {
                                $new_filename = $field_key . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
                                if (move_uploaded_file($file_tmp, $upload_dir . '/' . $new_filename)) {
                                    $file_path = 'uploads/ppdb/' . $new_filename;
                                }
                            }
                        }
                    }
                    unset($file_path);

                    // Generate Nomor Registrasi Unik
                    $stmt_count = $pdo->query("SELECT COUNT(*) FROM ppdb_registrations");
                    $next_num = ((int)$stmt_count->fetchColumn()) + 1;
                    $reg_no = 'PPDB-' . date('Y') . '-' . str_pad((string)$next_num, 4, '0', STR_PAD_LEFT);

                    // Insert ke Database
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO ppdb_registrations 
                        (registration_no, full_name, nisn, nik, gender, birth_place, birth_date, religion, phone, email, address, previous_school, chosen_major, parent_name, parent_phone, parent_job, report_card_doc, birth_cert_doc, family_card_doc, photo_doc, status, notes)
                        VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'menunggu_verifikasi', 'Pendaftaran baru masuk secara online.')
                    ");

                    $stmt_ins->execute([
                        $reg_no, $full_name, $nisn, $nik, $gender, $birth_place, $birth_date, $religion, $phone, $email, $address, $previous_school, $chosen_major, $parent_name, $parent_phone, $parent_job,
                        $uploaded_docs['report_card_doc'], $uploaded_docs['birth_cert_doc'], $uploaded_docs['family_card_doc'], $uploaded_docs['photo_doc']
                    ]);

                    $success_reg = [
                        'reg_no'       => $reg_no,
                        'name'         => $full_name,
                        'nisn'         => $nisn,
                        'chosen_major' => $chosen_major
                    ];
                }
            } catch (Exception $e) {
                $error_msg = 'Gagal menyimpan data pendaftaran: ' . $e->getMessage();
            }
        }
    }
}

// Handle Cek Status Pendaftaran
if ($active_tab === 'cek' && isset($_GET['search'])) {
    $search_query = trim($_GET['search'] ?? '');
    if (!empty($search_query)) {
        try {
            $stmt_search = $pdo->prepare("
                SELECT r.*, u.email as student_email, c.name as class_name 
                FROM ppdb_registrations r
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN classes c ON u.class_id = c.id
                WHERE r.registration_no = ? OR r.nisn = ?
                LIMIT 1
            ");
            $stmt_search->execute([$search_query, $search_query]);
            $search_result = $stmt_search->fetch();

            if (!$search_result) {
                $error_msg = "Data pendaftaran dengan Nomor Registrasi / NISN '{$search_query}' tidak ditemukan. Mohon periksa kembali input Anda.";
            }
        } catch (Exception $e) {
            $error_msg = 'Terjadi kesalahan saat mencari data.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PPDB Online 2026/2027 - Portal Penerimaan Siswa Baru</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-emerald-500 selection:text-white">

    <!-- Top Navigation -->
    <header class="sticky top-0 z-40 border-b border-white/10 bg-slate-950/80 backdrop-blur">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 sm:px-6 py-4">
            <a href="index.php" class="flex items-center gap-3 group">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 text-white font-bold text-lg shadow-lg shadow-emerald-500/20 group-hover:scale-105 transition">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div>
                    <span class="text-base font-extrabold tracking-tight text-white block">PPDB Online</span>
                    <span class="text-xs text-emerald-400 font-medium">Tahun Ajaran 2026/2027</span>
                </div>
            </a>

            <div class="flex items-center gap-2 sm:gap-4">
                <a href="index.php" class="rounded-xl px-3 py-1.5 text-xs sm:text-sm font-medium text-slate-300 hover:bg-white/10 transition">
                    ← Kembali ke Beranda
                </a>
                <a href="auth/login.php" class="rounded-xl border border-white/10 bg-white/5 px-4 py-1.5 text-xs sm:text-sm font-semibold hover:bg-white/10 transition">
                    Login Portal
                </a>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 sm:px-6 py-8 sm:py-12">

        <!-- Hero Banner -->
        <div class="relative overflow-hidden rounded-3xl border border-emerald-500/20 bg-gradient-to-r from-emerald-950/40 via-slate-900 to-slate-900 p-6 sm:p-10 mb-8 backdrop-blur shadow-2xl">
            <div class="relative z-10 max-w-3xl">
                <div class="inline-flex items-center gap-2 rounded-full border border-emerald-400/30 bg-emerald-400/10 px-3.5 py-1 text-xs font-semibold text-emerald-300 mb-4">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    Pendaftaran Gelombang 1 Dibuka
                </div>
                <h1 class="text-3xl sm:text-4xl md:text-5xl font-extrabold text-white tracking-tight leading-tight">
                    Penerimaan Peserta Didik Baru (PPDB) 2026/2027
                </h1>
                <p class="mt-3 text-sm sm:text-base text-slate-300 leading-relaxed">
                    Selamat datang di sistem pendaftaran calon siswa terpadu. Lengkapi formulir pendaftaran, unggah berkas administrasi secara digital, dan pantau status seleksi Anda langsung dari halaman ini.
                </p>
            </div>
        </div>

        <!-- Alur / Tahapan Seleksi Guide -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-8">
            <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4">
                <span class="text-xs font-bold text-emerald-400">Tahap 1</span>
                <h4 class="text-sm font-bold text-white mt-1">Daftar Online</h4>
                <p class="text-[11px] text-slate-400 mt-1">Isi biodata & pilihan peminatan.</p>
            </div>
            <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4">
                <span class="text-xs font-bold text-blue-400">Tahap 2</span>
                <h4 class="text-sm font-bold text-white mt-1">Upload Berkas</h4>
                <p class="text-[11px] text-slate-400 mt-1">Rapor, KK, Akta & Foto 3x4.</p>
            </div>
            <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4">
                <span class="text-xs font-bold text-amber-400">Tahap 3</span>
                <h4 class="text-sm font-bold text-white mt-1">Verifikasi</h4>
                <p class="text-[11px] text-slate-400 mt-1">Pemeriksaan berkas oleh panitia.</p>
            </div>
            <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4">
                <span class="text-xs font-bold text-purple-400">Tahap 4</span>
                <h4 class="text-sm font-bold text-white mt-1">Tes & Wawancara</h4>
                <p class="text-[11px] text-slate-400 mt-1">Penilaian kemampuan akademik.</p>
            </div>
            <div class="col-span-2 md:col-span-1 rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4">
                <span class="text-xs font-bold text-emerald-400">Tahap 5</span>
                <h4 class="text-sm font-bold text-white mt-1">Kelulusan & Akun</h4>
                <p class="text-[11px] text-slate-400 mt-1">Diterima & aktif di portal sekolah.</p>
            </div>
        </div>

        <!-- Success Registration Banner -->
        <?php if ($success_reg): ?>
            <div class="mb-8 rounded-3xl border border-emerald-500/30 bg-emerald-500/10 p-6 sm:p-8 backdrop-blur">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6">
                    <div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-300 mb-2">
                            <i class="fa-solid fa-circle-check"></i> Pendaftaran Berhasil Dikirim!
                        </span>
                        <h2 class="text-2xl font-bold text-white">Nomor Registrasi Anda:</h2>
                        <div class="mt-2 inline-block rounded-2xl border border-emerald-400/30 bg-slate-950/80 px-5 py-2.5 font-mono text-2xl sm:text-3xl font-black text-emerald-400 tracking-wider">
                            <?= htmlspecialchars($success_reg['reg_no']) ?>
                        </div>
                        <p class="mt-3 text-sm text-slate-300">
                            Nama: <strong class="text-white"><?= htmlspecialchars($success_reg['name']) ?></strong> | 
                            NISN: <strong class="text-white"><?= htmlspecialchars($success_reg['nisn']) ?></strong> | 
                            Peminatan: <strong class="text-white"><?= htmlspecialchars($success_reg['chosen_major']) ?></strong>
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            Simpan nomor registrasi di atas untuk mencetak kartu pendaftaran dan mengecek hasil pengumuman panitia seleksi.
                        </p>
                    </div>
                    <div class="flex flex-col gap-2 shrink-0 w-full sm:w-auto">
                        <a href="ppdb_card.php?reg=<?= urlencode($success_reg['reg_no']) ?>" target="_blank"
                           class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-6 py-3 font-semibold text-white shadow-lg shadow-emerald-500/20 transition">
                            <i class="fa-solid fa-print"></i> Cetak Kartu Pendaftaran
                        </a>
                        <a href="ppdb.php?tab=cek&search=<?= urlencode($success_reg['reg_no']) ?>"
                           class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-6 py-2.5 text-sm font-semibold text-slate-200 transition">
                            <i class="fa-solid fa-magnifying-glass"></i> Cek Status Seleksi
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Message Alert -->
        <?php if ($error_msg): ?>
            <div class="mb-8 rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-300 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="text-xl"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <span><?= htmlspecialchars($error_msg) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <div class="flex items-center border-b border-white/10 mb-8">
            <a href="ppdb.php?tab=daftar" 
               class="flex items-center gap-2 border-b-2 px-6 py-3.5 text-sm font-bold transition <?= $active_tab === 'daftar' ? 'border-emerald-500 text-emerald-400 bg-white/5 rounded-t-xl' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-file-signature"></i> Formulir Pendaftaran Baru
            </a>
            <a href="ppdb.php?tab=cek" 
               class="flex items-center gap-2 border-b-2 px-6 py-3.5 text-sm font-bold transition <?= $active_tab === 'cek' ? 'border-emerald-500 text-emerald-400 bg-white/5 rounded-t-xl' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-magnifying-glass"></i> Cek Status & Cetak Kartu
            </a>
        </div>

        <?php if ($active_tab === 'daftar'): ?>
            <!-- FORM PENDAFTARAN -->
            <form action="ppdb.php?tab=daftar" method="POST" enctype="multipart/form-data" class="space-y-8">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="register_ppdb">

                <!-- 1. Biodata Calon Siswa -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 pb-4 mb-6">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-500/10 text-lg text-blue-400">
                            <i class="fa-solid fa-user"></i>
                        </span>
                        <div>
                            <h3 class="text-lg font-bold text-white">1. Biodata Calon Siswa</h3>
                            <p class="text-xs text-slate-400">Informasi identitas pribadi calon peserta didik baru</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-5">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Nama Lengkap Siswa *</label>
                            <input type="text" name="full_name" required placeholder="Contoh: Rian Pratama"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">NISN (10 Digit) *</label>
                            <input type="text" name="nisn" required maxlength="10" placeholder="Contoh: 0081234567"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">NIK Siswa (Opsional)</label>
                            <input type="text" name="nik" maxlength="16" placeholder="Contoh: 3201011203080001"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Jenis Kelamin *</label>
                            <select name="gender" required
                                    class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                <option value="L">Laki-Laki (L)</option>
                                <option value="P">Perempuan (P)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Agama</label>
                            <select name="religion"
                                    class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                <option value="Islam">Islam</option>
                                <option value="Kristen">Kristen Protestan</option>
                                <option value="Katolik">Katolik</option>
                                <option value="Hindu">Hindu</option>
                                <option value="Buddha">Buddha</option>
                                <option value="Konghucu">Konghucu</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Tempat Lahir *</label>
                            <input type="text" name="birth_place" required placeholder="Contoh: Jakarta"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Tanggal Lahir *</label>
                            <input type="date" name="birth_date" required
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">No. HP / WhatsApp Siswa *</label>
                            <input type="tel" name="phone" required placeholder="Contoh: 081234567890"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Email Aktif *</label>
                            <input type="email" name="email" required placeholder="Contoh: siswa@gmail.com"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>

                        <div class="sm:col-span-3">
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Alamat Lengkap Tempat Tinggal *</label>
                            <textarea name="address" required rows="2" placeholder="Nama Jalan, RT/RW, Kelurahan, Kecamatan, Kota/Kabupaten..."
                                      class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition"></textarea>
                        </div>
                    </div>
                </div>

                <!-- 2. Asal Sekolah & Pilihan Jurusan -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 pb-4 mb-6">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/10 text-lg text-emerald-400">
                            <i class="fa-solid fa-school"></i>
                        </span>
                        <div>
                            <h3 class="text-lg font-bold text-white">2. Asal Sekolah & Pilihan Peminatan</h3>
                            <p class="text-xs text-slate-400">Riwayat sekolah sebelumnya dan jurusan yang dituju</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Asal Sekolah (SMP / MTs) *</label>
                            <input type="text" name="previous_school" required placeholder="Contoh: SMP Negeri 1 Jakarta"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Pilihan Jurusan / Peminatan *</label>
                            <select name="chosen_major" required
                                    class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                <option value="MIPA (Matematika & IPA)">MIPA (Matematika & IPA)</option>
                                <option value="IPS (Ilmu Pengetahuan Sosial)">IPS (Ilmu Pengetahuan Sosial)</option>
                                <option value="Bahasa & Budaya">Bahasa & Budaya</option>
                                <option value="Teknologi Informasi & Jaringan">Teknologi Informasi & Jaringan</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 3. Data Orang Tua / Wali -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 pb-4 mb-6">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-purple-500/10 text-lg text-purple-400">
                            <i class="fa-solid fa-people-roof"></i>
                        </span>
                        <div>
                            <h3 class="text-lg font-bold text-white">3. Data Orang Tua / Wali</h3>
                            <p class="text-xs text-slate-400">Kontak penanggung jawab calon siswa</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Nama Orang Tua / Wali *</label>
                            <input type="text" name="parent_name" required placeholder="Contoh: Budi Santoso"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">No. HP / WhatsApp Orang Tua *</label>
                            <input type="tel" name="parent_phone" required placeholder="Contoh: 081298765432"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-2">Pekerjaan Orang Tua</label>
                            <input type="text" name="parent_job" placeholder="Contoh: Wiraswasta / PNS / Karyawan"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        </div>
                    </div>
                </div>

                <!-- 4. Upload Dokumen Persyaratan -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl">
                    <div class="flex items-center gap-3 border-b border-white/10 pb-4 mb-6">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-500/10 text-lg text-amber-400">
                            <i class="fa-solid fa-paperclip"></i>
                        </span>
                        <div>
                            <h3 class="text-lg font-bold text-white">4. Upload Berkas Dokumen (Opsional / Dapat Disusulkan)</h3>
                            <p class="text-xs text-slate-400">Format yang didukung: PDF, JPG, PNG (Maksimal 5MB per file)</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-5">
                        <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-4">
                            <label class="block text-xs font-bold text-slate-300 mb-1"><i class="fa-solid fa-file-lines mr-1.5 text-blue-400"></i> Scan Rapor Terakhir</label>
                            <span class="text-[11px] text-slate-400 block mb-3">Semester 1 - 5 SMP</span>
                            <input type="file" name="report_card_doc" accept=".pdf,.jpg,.jpeg,.png"
                                   class="w-full text-xs text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-white/10 file:text-white hover:file:bg-white/20">
                        </div>

                        <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-4">
                            <label class="block text-xs font-bold text-slate-300 mb-1"><i class="fa-solid fa-id-card mr-1.5 text-amber-400"></i> Kartu Keluarga (KK)</label>
                            <span class="text-[11px] text-slate-400 block mb-3">Foto / Scan Asli</span>
                            <input type="file" name="family_card_doc" accept=".pdf,.jpg,.jpeg,.png"
                                   class="w-full text-xs text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-white/10 file:text-white hover:file:bg-white/20">
                        </div>

                        <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-4">
                            <label class="block text-xs font-bold text-slate-300 mb-1"><i class="fa-solid fa-certificate mr-1.5 text-emerald-400"></i> Akta Kelahiran</label>
                            <span class="text-[11px] text-slate-400 block mb-3">Foto / Scan Asli</span>
                            <input type="file" name="birth_cert_doc" accept=".pdf,.jpg,.jpeg,.png"
                                   class="w-full text-xs text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-white/10 file:text-white hover:file:bg-white/20">
                        </div>

                        <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-4">
                            <label class="block text-xs font-bold text-slate-300 mb-1"><i class="fa-solid fa-image mr-1.5 text-purple-400"></i> Pas Foto 3x4</label>
                            <span class="text-[11px] text-slate-400 block mb-3">Latar Belakang Merah/Biru</span>
                            <input type="file" name="photo_doc" accept=".jpg,.jpeg,.png"
                                   class="w-full text-xs text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-white/10 file:text-white hover:file:bg-white/20">
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-4 pt-4">
                    <p class="text-xs text-slate-400">
                        Dengan menekan tombol di bawah, saya menyatakan bahwa data yang saya masukkan adalah benar dan dapat dipertanggungjawabkan.
                    </p>
                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-2xl bg-emerald-600 hover:bg-emerald-500 px-8 py-3.5 font-bold text-white shadow-xl shadow-emerald-600/25 transition cursor-pointer shrink-0">
                        <i class="fa-solid fa-paper-plane"></i>
                        <span>Kirim Pendaftaran Siswa Baru</span>
                    </button>
                </div>
            </form>

        <?php else: ?>
            <!-- TAB CEK STATUS -->
            <div class="space-y-8">
                <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl max-w-2xl mx-auto">
                    <h3 class="text-lg font-bold text-white mb-2">Pelacakan Status Seleksi PPDB</h3>
                    <p class="text-xs text-slate-400 mb-6">Masukkan Nomor Registrasi (contoh: <code>PPDB-2026-0001</code>) atau 10 digit NISN Anda.</p>

                    <form action="ppdb.php" method="GET" class="flex flex-col sm:flex-row gap-3">
                        <input type="hidden" name="tab" value="cek">
                        <input type="text" name="search" required 
                               value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                               placeholder="Contoh: PPDB-2026-0001 atau 0081234567"
                               class="flex-1 rounded-xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        <button type="submit"
                                class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-6 py-3 font-bold text-white shadow-lg shadow-emerald-600/20 transition cursor-pointer">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <span>Lacak Status</span>
                        </button>
                    </form>
                </div>

                <?php if ($search_result): ?>
                    <!-- Hasil Pencarian Status -->
                    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-6 sm:p-8 backdrop-blur shadow-xl max-w-3xl mx-auto">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-white/10 pb-6 mb-6">
                            <div>
                                <span class="text-xs font-mono text-slate-400 block mb-1">Nomor Registrasi:</span>
                                <h3 class="text-2xl font-black text-white font-mono"><?= htmlspecialchars($search_result['registration_no']) ?></h3>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="inline-flex items-center rounded-xl border px-3.5 py-1.5 text-xs font-bold <?= getPpdbStatusBadge($search_result['status']) ?>">
                                    ● <?= htmlspecialchars(getPpdbStatusLabel($search_result['status'])) ?>
                                </span>
                                <a href="ppdb_card.php?reg=<?= urlencode($search_result['registration_no']) ?>" target="_blank"
                                   class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-1.5 text-xs font-semibold text-white transition flex items-center gap-1.5">
                                    <i class="fa-solid fa-print"></i> Cetak Kartu
                                </a>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm mb-6">
                            <div class="rounded-2xl bg-slate-950/60 p-4">
                                <span class="text-xs text-slate-400 block">Nama Calon Siswa</span>
                                <strong class="text-white text-base mt-0.5 block"><?= htmlspecialchars($search_result['full_name']) ?></strong>
                            </div>
                            <div class="rounded-2xl bg-slate-950/60 p-4">
                                <span class="text-xs text-slate-400 block">NISN</span>
                                <strong class="text-white text-base mt-0.5 block"><?= htmlspecialchars($search_result['nisn']) ?></strong>
                            </div>
                            <div class="rounded-2xl bg-slate-950/60 p-4">
                                <span class="text-xs text-slate-400 block">Peminatan / Jurusan</span>
                                <strong class="text-white text-base mt-0.5 block"><?= htmlspecialchars($search_result['chosen_major']) ?></strong>
                            </div>
                            <div class="rounded-2xl bg-slate-950/60 p-4">
                                <span class="text-xs text-slate-400 block">Asal Sekolah</span>
                                <strong class="text-white text-base mt-0.5 block"><?= htmlspecialchars($search_result['previous_school']) ?></strong>
                            </div>
                        </div>

                        <!-- Nilai & Catatan Panitia -->
                        <div class="rounded-2xl border border-white/5 bg-slate-950/80 p-5 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Skor Seleksi Panitia:</span>
                                <span class="text-lg font-black text-emerald-400">
                                    <?= $search_result['selection_score'] !== null ? number_format((float)$search_result['selection_score'], 2) . ' / 100' : 'Sedang Dinilai' ?>
                                </span>
                            </div>
                            <div class="border-t border-white/5 pt-3">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-1">Catatan Panitia PPDB:</span>
                                <p class="text-sm text-slate-300">
                                    <?= htmlspecialchars($search_result['notes'] ?? 'Belum ada catatan tambahan.') ?>
                                </p>
                            </div>
                            <?php if ($search_result['status'] === 'diterima'): ?>
                                <div class="mt-4 rounded-2xl border border-emerald-500/40 bg-gradient-to-br from-emerald-950/60 to-slate-900/90 p-5 space-y-4">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-xl">
                                            <i class="fa-solid fa-award"></i>
                                        </span>
                                        <div>
                                            <h4 class="text-sm font-bold text-white">Selamat! Anda Resmi Diterima sebagai Siswa Baru</h4>
                                            <p class="text-xs text-emerald-300">Akun portal pembelajaran dan administrasi Anda telah aktif.</p>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs bg-slate-950/70 p-4 rounded-xl border border-white/5">
                                        <div>
                                            <span class="text-slate-400 block">Rombongan Belajar / Kelas:</span>
                                            <strong class="text-emerald-400 font-semibold text-sm mt-0.5 block">
                                                <?= htmlspecialchars($search_result['class_name'] ?? 'Kelas X (Menunggu Penempatan)') ?>
                                            </strong>
                                        </div>
                                        <div>
                                            <span class="text-slate-400 block">Email Login Portal:</span>
                                            <strong class="text-white font-mono text-xs mt-0.5 block select-all">
                                                <?= htmlspecialchars($search_result['student_email'] ?? $search_result['email']) ?>
                                            </strong>
                                        </div>
                                        <div>
                                            <span class="text-slate-400 block">Kata Sandi Bawaan:</span>
                                            <span class="font-mono text-amber-300 font-bold select-all">password</span>
                                            <span class="text-[10px] text-slate-500 block">(Ganti sandi saat login pertama)</span>
                                        </div>
                                        <div>
                                            <span class="text-slate-400 block">Akun Portal Wali Murid:</span>
                                            <span class="font-mono text-blue-300 text-xs block select-all">wali.<?= htmlspecialchars($search_result['nisn']) ?>@sekolah.id</span>
                                            <span class="text-[10px] text-slate-500 block">Password: password</span>
                                        </div>
                                    </div>

                                    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2">
                                        <span class="text-[11px] text-slate-400">Silakan masuk untuk melihat jadwal pelajaran, tugas e-learning, dan presensi.</span>
                                        <a href="auth/login.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-emerald-600/25 transition">
                                            <span>Masuk ke Portal Siswa</span>
                                            <i class="fa-solid fa-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </main>

    <footer class="border-t border-white/10 bg-slate-950 py-8 text-center text-xs text-slate-500">
        <div class="mx-auto max-w-6xl px-4">
            <p>© 2026 Panitia PPDB Sekolah Menengah Terpadu. Seluruh hak cipta dilindungi.</p>
        </div>
    </footer>

</body>
</html>
