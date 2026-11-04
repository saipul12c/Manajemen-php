<?php
/**
 * Pusat Pemasangan & Unduh Multi Aplikasi Sekolah (Public Open Access)
 * Memungkinkan siswa, guru, staf, wali murid, dan umum memasang 3 aplikasi:
 * 1. CBT Ujian Sekolah (Layar Penuh, Anti-Curang)
 * 2. Presensi Siswa & Guru (Scan QR Kartu)
 * 3. E-Perpustakaan Digital (Barcode Scanner, Katalog & E-Book)
 *
 * Mendukung instalasi PWA instan, generator QR Code HP, dan unduh APK langsung.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

$school_info = getSchoolSettings($pdo);
$school_name = $school_info['school_name'] ?? 'Manajemen-PHP';

// Hitung Base URL absolut server saat ini untuk generator QR Code di HP
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$protocol = $is_https ? "https://" : "http://";
$host_name = $_SERVER['HTTP_HOST'] ?? 'localhost';
$current_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/');
$root_app_url = $protocol . $host_name . $current_dir;

// Helper deteksi ketersediaan file APK di server
function checkApkStatus(string $filename): array {
    $filePath = __DIR__ . '/uploads/apps/' . $filename;
    if (file_exists($filePath) && filesize($filePath) > 0) {
        $bytes = filesize($filePath);
        $sizeStr = ($bytes >= 1048576) 
            ? round($bytes / 1048576, 1) . ' MB' 
            : round($bytes / 1024, 0) . ' KB';
        return [
            'available' => true,
            'url' => 'uploads/apps/' . urlencode($filename),
            'size' => $sizeStr,
            'updated_at' => date('d M Y', filemtime($filePath))
        ];
    }
    return [
        'available' => false,
        'url' => '#',
        'size' => '',
        'updated_at' => ''
    ];
}

$cbt_apk = checkApkStatus('cbt-app.apk');
$presensi_apk = checkApkStatus('presensi-app.apk');
$perpus_apk = checkApkStatus('perpustakaan-app.apk');

$nav_active = 'apps';
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Pusat Unduh & Pemasangan Aplikasi - <?= htmlspecialchars($school_name) ?></title>
    <meta name="description" content="Pasang dan unduh aplikasi resmi CBT Ujian Sekolah, Presensi Siswa & Guru, dan Perpustakaan Digital di HP Android, iOS, atau Laptop Anda.">

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- QR Code Generator Library -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-card {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col selection:bg-blue-600 selection:text-white">

    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <main class="flex-1">

        <!-- ================= HERO HEADER ================= -->
        <header class="relative overflow-hidden border-b border-white/5 py-12 md:py-20">
            <!-- Ambient Glow Effects -->
            <div class="absolute -top-32 -left-32 w-96 h-96 rounded-full bg-blue-600/15 blur-3xl pointer-events-none"></div>
            <div class="absolute top-1/2 -right-32 w-96 h-96 rounded-full bg-emerald-600/15 blur-3xl pointer-events-none"></div>
            <div class="absolute -bottom-24 left-1/3 w-80 h-80 rounded-full bg-amber-600/10 blur-3xl pointer-events-none"></div>

            <div class="mx-auto max-w-7xl px-4 sm:px-6 relative z-10">
                <div class="max-w-3xl space-y-4">
                    <div class="inline-flex items-center gap-2 rounded-full border border-blue-400/25 bg-blue-500/10 px-3.5 py-1 text-xs text-blue-300 font-semibold">
                        <i class="fa-solid fa-layer-group text-blue-400"></i>
                        <span>Ekosistem Aplikasi Terpadu</span>
                        <span class="inline-block h-1 w-1 rounded-full bg-blue-400"></span>
                        <span class="text-slate-400">1 Basis Data Terhubung</span>
                    </div>

                    <h1 class="text-3xl sm:text-5xl font-extrabold leading-tight text-white tracking-tight">
                        Pusat Pemasangan & Unduh
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 via-sky-300 to-emerald-400">
                            Aplikasi Sekolah
                        </span>
                    </h1>

                    <p class="text-sm sm:text-base leading-relaxed text-slate-300">
                        Pasang aplikasi sekolah langsung ke layar utama smartphone (Android & iPhone) atau komputer Anda. Tersedia dalam mode <strong>Aplikasi Ringan (PWA)</strong> tanpa perlu ruang penyimpanan besar, serta paket <strong>Installer Android (.APK)</strong>.
                    </p>

                    <div class="pt-2 flex flex-wrap items-center gap-3 text-xs text-slate-400">
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/5 border border-white/10 px-3 py-1.5">
                            <i class="fa-brands fa-android text-emerald-400"></i> Android Ready
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/5 border border-white/10 px-3 py-1.5">
                            <i class="fa-brands fa-apple text-slate-300"></i> iOS (iPhone & iPad)
                        </span>
                        <span class="inline-flex items-center gap-1.5 rounded-lg bg-white/5 border border-white/10 px-3 py-1.5">
                            <i class="fa-brands fa-windows text-sky-400"></i> Windows PC / Mac
                        </span>
                    </div>
                </div>
            </div>
        </header>

        <!-- ================= 3 APLIKASI UTAMA SECTION ================= -->
        <section class="py-12 md:py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6">

                <div class="mb-10 text-center sm:text-left">
                    <h2 class="text-xl sm:text-2xl font-bold text-white">Pilih Aplikasi yang Ingin Dipasang</h2>
                    <p class="text-xs sm:text-sm text-slate-400 mt-1">Setiap aplikasi memiliki fungsi spesifik yang dirancang sesuai kebutuhan civitas sekolah.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8">

                    <!-- KARTU 1: CBT UJIAN SEKOLAH -->
                    <div class="glass-card rounded-3xl p-6 sm:p-7 flex flex-col justify-between hover:border-blue-500/50 hover:bg-slate-900/90 transition-all duration-300 group shadow-xl relative overflow-hidden">
                        <div class="absolute -right-16 -top-16 h-36 w-36 rounded-full bg-blue-600/15 blur-2xl pointer-events-none"></div>

                        <div class="space-y-5 relative z-10">
                            <!-- Header Kartu -->
                            <div class="flex items-center justify-between">
                                <div class="h-16 w-16 rounded-2xl bg-gradient-to-br from-blue-700 to-cyan-600 p-1.5 shadow-lg shadow-blue-600/30 group-hover:scale-105 transition duration-300">
                                    <img src="dashboard/Modul-ujian/icons/icon.svg" alt="CBT Icon" class="h-full w-full object-contain">
                                </div>
                                <span class="rounded-full border border-blue-500/30 bg-blue-500/15 px-3 py-1 text-[11px] font-bold text-blue-300 uppercase tracking-wider">
                                    Aplikasi 1
                                </span>
                            </div>

                            <!-- Judul & Deskripsi -->
                            <div>
                                <h3 class="text-lg sm:text-xl font-bold text-white group-hover:text-blue-300 transition">
                                    CBT Ujian Sekolah
                                </h3>
                                <p class="text-xs text-slate-400 mt-1.5 leading-relaxed">
                                    Aplikasi Ujian Berbasis Komputer & Latihan Soal Mandiri dengan proteksi anti-curang, layar penuh (kiosk mode), timer otomatis, dan bank soal terpusat.
                                </p>
                            </div>

                            <!-- Spesifikasi Fitur -->
                            <div class="space-y-2 rounded-2xl bg-slate-950/70 border border-white/5 p-3.5 text-xs">
                                <div class="flex items-center justify-between text-slate-400">
                                    <span>Tampilan:</span>
                                    <span class="text-blue-300 font-semibold">Layar Penuh (Kiosk)</span>
                                </div>
                                <div class="flex items-center justify-between text-slate-400">
                                    <span>Pengguna:</span>
                                    <span class="text-slate-200">Siswa & Guru Pengawas</span>
                                </div>
                                <div class="flex items-center justify-between text-slate-400">
                                    <span>Pencegahan:</span>
                                    <span class="text-emerald-400 font-medium"><i class="fa-solid fa-shield-halved mr-1"></i> Anti Buka Tab Lain</span>
                                </div>
                            </div>
                        </div>

                        <!-- Tombol Aksi -->
                        <div class="pt-6 space-y-2.5 relative z-10 border-t border-white/5 mt-5">
                            <!-- Buka / Pasang PWA -->
                            <a href="dashboard/Modul-ujian/exams.php" 
                               class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-blue-600/25 transition">
                                <i class="fa-solid fa-rocket"></i>
                                <span>Buka / Pasang di Browser</span>
                            </a>

                            <!-- Scan QR di HP -->
                            <button type="button" 
                                    onclick="showAppQrModal('CBT Ujian Sekolah', '<?= $root_app_url ?>/dashboard/Modul-ujian/exams.php', '#2563eb', 'CBT')"
                                    class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 hover:text-white transition cursor-pointer">
                                <i class="fa-solid fa-qrcode text-blue-400"></i>
                                <span>Scan QR Pasang di HP</span>
                            </button>

                            <!-- Unduh APK -->
                            <?php if ($cbt_apk['available']): ?>
                                <a href="<?= htmlspecialchars($cbt_apk['url']) ?>" download="CBT-Sekolah.apk"
                                   class="w-full inline-flex items-center justify-between rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3.5 py-2 text-xs font-semibold text-emerald-300 transition">
                                    <span class="flex items-center gap-1.5">
                                        <i class="fa-brands fa-android text-sm"></i> Unduh File .APK
                                    </span>
                                    <span class="text-[10px] text-emerald-400/80"><?= $cbt_apk['size'] ?></span>
                                </a>
                            <?php else: ?>
                                <button type="button" onclick="showApkGuideModal('CBT Ujian Sekolah')"
                                        class="w-full inline-flex items-center justify-between rounded-xl border border-dashed border-white/15 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs text-slate-400 hover:text-slate-300 transition cursor-pointer">
                                    <span class="flex items-center gap-1.5">
                                        <i class="fa-brands fa-android text-slate-500"></i> Paket .APK Android
                                    </span>
                                    <span class="text-[10px] text-sky-400 font-semibold">Gunakan PWA</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- KARTU 2: PRESENSI SISWA & GURU -->
                    <div class="glass-card rounded-3xl p-6 sm:p-7 flex flex-col justify-between hover:border-emerald-500/50 hover:bg-slate-900/90 transition-all duration-300 group shadow-xl relative overflow-hidden">
                        <div class="absolute -right-16 -top-16 h-36 w-36 rounded-full bg-emerald-600/15 blur-2xl pointer-events-none"></div>

                        <div class="space-y-5 relative z-10">
                            <!-- Header Kartu -->
                            <div class="flex items-center justify-between">
                                <div class="h-16 w-16 rounded-2xl bg-gradient-to-br from-emerald-700 to-teal-600 p-1.5 shadow-lg shadow-emerald-600/30 group-hover:scale-105 transition duration-300">
                                    <img src="dashboard/presensi/icons/icon.svg" alt="Presensi Icon" class="h-full w-full object-contain">
                                </div>
                                <span class="rounded-full border border-emerald-500/30 bg-emerald-500/15 px-3 py-1 text-[11px] font-bold text-emerald-300 uppercase tracking-wider">
                                    Aplikasi 2
                                </span>
                            </div>

                            <!-- Judul & Deskripsi -->
                            <div>
                                <h3 class="text-lg sm:text-xl font-bold text-white group-hover:text-emerald-300 transition">
                                    Presensi Siswa & Guru
                                </h3>
                                <p class="text-xs text-slate-400 mt-1.5 leading-relaxed">
                                    Pencatatan absensi harian kilat menggunakan pemindai QR Code kartu pelajar & guru. Terkoneksi otomatis dengan rekap kehadiran dan notifikasi terlambat.
                                </p>
                            </div>

                            <!-- Spesifikasi Fitur -->
                            <div class="space-y-2 rounded-2xl bg-slate-950/70 border border-white/5 p-3.5 text-xs">
                                <div class="flex items-center justify-between text-slate-400">
                                    <span>Tampilan:</span>
                                    <span class="text-emerald-300 font-semibold">Standalone App</span>
                                </div>
                                <div class="flex items-center justify-between text-slate-400">
                                    <span>Fitur Kamera:</span>
                                    <span class="text-slate-200">Scanner QR Kartu Cepat</span>
                                </div>
                                <div class="flex items-center justify-between text-slate-400">
                                    <span>Sinkronisasi:</span>
                                    <span class="text-emerald-400 font-medium"><i class="fa-solid fa-bolt mr-1"></i> Real-time Database</span>
                                </div>
                            </div>
                        </div>

                        <!-- Tombol Aksi -->
                        <div class="pt-6 space-y-2.5 relative z-10 border-t border-white/5 mt-5">
                            <!-- Buka / Pasang PWA -->
                            <a href="dashboard/presensi/attendance.php" 
                               class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-emerald-600/25 transition">
                                <i class="fa-solid fa-rocket"></i>
                                <span>Buka / Pasang di Browser</span>
                            </a>

                            <!-- Scan QR di HP -->
                            <button type="button" 
                                    onclick="showAppQrModal('Presensi Siswa & Guru', '<?= $root_app_url ?>/dashboard/presensi/attendance.php', '#059669', 'Presensi')"
                                    class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 hover:text-white transition cursor-pointer">
                                <i class="fa-solid fa-qrcode text-emerald-400"></i>
                                <span>Scan QR Pasang di HP</span>
                            </button>

                            <!-- Unduh APK -->
                            <?php if ($presensi_apk['available']): ?>
                                <a href="<?= htmlspecialchars($presensi_apk['url']) ?>" download="Presensi-Sekolah.apk"
                                   class="w-full inline-flex items-center justify-between rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3.5 py-2 text-xs font-semibold text-emerald-300 transition">
                                    <span class="flex items-center gap-1.5">
                                        <i class="fa-brands fa-android text-sm"></i> Unduh File .APK
                                    </span>
                                    <span class="text-[10px] text-emerald-400/80"><?= $presensi_apk['size'] ?></span>
                                </a>
                            <?php else: ?>
                                <button type="button" onclick="showApkGuideModal('Presensi Siswa & Guru')"
                                        class="w-full inline-flex items-center justify-between rounded-xl border border-dashed border-white/15 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs text-slate-400 hover:text-slate-300 transition cursor-pointer">
                                    <span class="flex items-center gap-1.5">
                                        <i class="fa-brands fa-android text-slate-500"></i> Paket .APK Android
                                    </span>
                                    <span class="text-[10px] text-emerald-400 font-semibold">Gunakan PWA</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- KARTU 3: E-PERPUSTAKAAN DIGITAL -->
                    <div class="glass-card rounded-3xl p-6 sm:p-7 flex flex-col justify-between hover:border-amber-500/50 hover:bg-slate-900/90 transition-all duration-300 group shadow-xl relative overflow-hidden">
                        <div class="absolute -right-16 -top-16 h-36 w-36 rounded-full bg-amber-600/15 blur-2xl pointer-events-none"></div>

                        <div class="space-y-5 relative z-10">
                            <!-- Header Kartu -->
                            <div class="flex items-center justify-between">
                                <div class="h-16 w-16 rounded-2xl bg-gradient-to-br from-amber-700 to-yellow-600 p-1.5 shadow-lg shadow-amber-600/30 group-hover:scale-105 transition duration-300">
                                    <img src="dashboard/perpustakaan/icons/icon.svg" alt="Perpus Icon" class="h-full w-full object-contain">
                                </div>
                                <span class="rounded-full border border-amber-500/30 bg-amber-500/15 px-3 py-1 text-[11px] font-bold text-amber-300 uppercase tracking-wider">
                                    Aplikasi 3
                                </span>
                            </div>

                            <!-- Judul & Deskripsi -->
                            <div>
                                <h3 class="text-lg sm:text-xl font-bold text-white group-hover:text-amber-300 transition">
                                    E-Perpustakaan Digital
                                </h3>
                                <p class="text-xs text-slate-400 mt-1.5 leading-relaxed">
                                    Akses katalog ribuan buku fisik & digital (e-book PDF reader), peminjaman barcode mandiri, reservasi buku dari rumah, dan statistik sirkulasi.
                                </p>
                            </div>

                            <!-- Spesifikasi Fitur -->
                            <div class="space-y-2 rounded-2xl bg-slate-950/70 border border-white/5 p-3.5 text-xs">
                                <div class="flex items-center justify-between text-slate-400">
                                    <span>Tampilan:</span>
                                    <span class="text-amber-300 font-semibold">Standalone App</span>
                                </div>
                                <div class="flex items-center justify-between text-slate-400">
                                    <span>Scanner:</span>
                                    <span class="text-slate-200">Barcode Buku & Tamu</span>
                                </div>
                                <div class="flex items-center justify-between text-slate-400">
                                    <span>Katalog:</span>
                                    <span class="text-amber-400 font-medium"><i class="fa-solid fa-book-open mr-1"></i> Terbuka Umum & Siswa</span>
                                </div>
                            </div>
                        </div>

                        <!-- Tombol Aksi -->
                        <div class="pt-6 space-y-2.5 relative z-10 border-t border-white/5 mt-5">
                            <!-- Buka / Pasang PWA -->
                            <a href="perpustakaan.php" 
                               class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-amber-600 to-yellow-600 hover:from-amber-500 hover:to-yellow-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-amber-600/25 transition">
                                <i class="fa-solid fa-rocket"></i>
                                <span>Buka / Pasang di Browser</span>
                            </a>

                            <!-- Scan QR di HP -->
                            <button type="button" 
                                    onclick="showAppQrModal('E-Perpustakaan Digital', '<?= $root_app_url ?>/perpustakaan.php', '#d97706', 'Perpustakaan')"
                                    class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 hover:text-white transition cursor-pointer">
                                <i class="fa-solid fa-qrcode text-amber-400"></i>
                                <span>Scan QR Pasang di HP</span>
                            </button>

                            <!-- Unduh APK -->
                            <?php if ($perpus_apk['available']): ?>
                                <a href="<?= htmlspecialchars($perpus_apk['url']) ?>" download="Perpustakaan-Sekolah.apk"
                                   class="w-full inline-flex items-center justify-between rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3.5 py-2 text-xs font-semibold text-emerald-300 transition">
                                    <span class="flex items-center gap-1.5">
                                        <i class="fa-brands fa-android text-sm"></i> Unduh File .APK
                                    </span>
                                    <span class="text-[10px] text-emerald-400/80"><?= $perpus_apk['size'] ?></span>
                                </a>
                            <?php else: ?>
                                <button type="button" onclick="showApkGuideModal('E-Perpustakaan Digital')"
                                        class="w-full inline-flex items-center justify-between rounded-xl border border-dashed border-white/15 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs text-slate-400 hover:text-slate-300 transition cursor-pointer">
                                    <span class="flex items-center gap-1.5">
                                        <i class="fa-brands fa-android text-slate-500"></i> Paket .APK Android
                                    </span>
                                    <span class="text-[10px] text-amber-400 font-semibold">Gunakan PWA</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

            </div>
        </section>

        <!-- ================= PANDUAN CARA INSTALASI PWA ================= -->
        <section class="py-12 border-t border-white/5 bg-slate-950/80">
            <div class="mx-auto max-w-7xl px-4 sm:px-6">

                <div class="rounded-3xl border border-white/10 bg-slate-900/80 p-6 sm:p-10 space-y-8 shadow-2xl">
                    <div class="flex items-center gap-3 border-b border-white/10 pb-5">
                        <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-500/20 text-blue-400 text-xl">
                            <i class="fa-solid fa-circle-question"></i>
                        </span>
                        <div>
                            <h3 class="text-lg sm:text-xl font-bold text-white">Panduan Mudah Pasang Aplikasi ke Layar Utama</h3>
                            <p class="text-xs sm:text-sm text-slate-400">Teknologi Progressive Web App (PWA) memungkinkan aplikasi berjalan layaknya aplikasi Play Store tanpa menguras memori.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-xs text-slate-300">
                        <!-- Android -->
                        <div class="rounded-2xl border border-emerald-500/20 bg-slate-950/60 p-5 space-y-3">
                            <div class="flex items-center gap-2 text-emerald-400 font-bold text-sm">
                                <i class="fa-brands fa-android text-base"></i> Ponsel Android (Chrome / Edge)
                            </div>
                            <ol class="list-decimal list-inside space-y-2 leading-relaxed text-slate-300">
                                <li>Buka salah satu modul di atas (CBT, Presensi, atau Perpus) melalui browser Chrome.</li>
                                <li>Ketuk tombol titik tiga (⋮) di pojok kanan atas browser Anda.</li>
                                <li>Pilih menu <strong>"Instal aplikasi"</strong> atau <strong>"Tambahkan ke Layar Utama"</strong>.</li>
                                <li>Konfirmasi pemasangan, lalu ikon aplikasi resmi akan langsung muncul di beranda ponsel Anda!</li>
                            </ol>
                        </div>

                        <!-- iOS / iPhone -->
                        <div class="rounded-2xl border border-blue-500/20 bg-slate-950/60 p-5 space-y-3">
                            <div class="flex items-center gap-2 text-blue-400 font-bold text-sm">
                                <i class="fa-brands fa-apple text-base"></i> iPhone & iPad (Safari)
                            </div>
                            <ol class="list-decimal list-inside space-y-2 leading-relaxed text-slate-300">
                                <li>Buka halaman modul aplikasi di browser bawaan <strong>Safari</strong>.</li>
                                <li>Ketuk tombol <strong>Bagikan / Share</strong> (ikon kotak dengan panah ke atas di bilah bawah).</li>
                                <li>Gulir ke bawah dan ketuk <strong>"Tambahkan ke Layar Utama" (Add to Home Screen)</strong>.</li>
                                <li>Ketuk tombol <strong>Tambah (Add)</strong> di pojok kanan atas layar. Selesai!</li>
                            </ol>
                        </div>

                        <!-- Windows / PC -->
                        <div class="rounded-2xl border border-cyan-500/20 bg-slate-950/60 p-5 space-y-3">
                            <div class="flex items-center gap-2 text-cyan-400 font-bold text-sm">
                                <i class="fa-brands fa-windows text-base"></i> Komputer / Laptop (Windows & Mac)
                            </div>
                            <ol class="list-decimal list-inside space-y-2 leading-relaxed text-slate-300">
                                <li>Buka link aplikasi di browser Chrome atau Microsoft Edge pada laptop Anda.</li>
                                <li>Lihat pada bagian kanan bilah alamat (URL bar), akan muncul ikon <strong>Komputer / Instal (⊕)</strong>.</li>
                                <li>Klik ikon tersebut lalu klik tombol <strong>"Instal"</strong>.</li>
                                <li>Aplikasi akan berjalan di jendela mandiri tanpa tab browser dan shortcut terpasang di desktop.</li>
                            </ol>
                        </div>
                    </div>
                </div>

            </div>
        </section>

    </main>

    <!-- ================= MODAL SCAN QR CODE PASANG DI HP ================= -->
    <div id="appQrModal" class="fixed inset-0 z-50 hidden bg-black/85 backdrop-blur-md flex items-center justify-center p-4">
        <div class="relative w-full max-w-sm rounded-3xl border border-white/15 bg-slate-900 p-6 sm:p-7 shadow-2xl text-center space-y-4">
            
            <button type="button" onclick="closeAppQrModal()" class="absolute top-4 right-4 rounded-xl p-1.5 text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>

            <div>
                <span id="qrAppBadge" class="rounded-full border px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-blue-300 border-blue-500/30 bg-blue-500/10">
                    Aplikasi Sekolah
                </span>
                <h3 id="qrAppTitle" class="text-lg font-bold text-white mt-2">Pindai untuk Pasang</h3>
                <p class="text-xs text-slate-400 mt-1">Arahkan kamera HP Anda ke QR Code di bawah untuk membuka dan memasang aplikasi.</p>
            </div>

            <!-- Kontainer QR Code -->
            <div class="flex justify-center p-4 bg-white rounded-2xl shadow-inner w-56 h-56 mx-auto items-center">
                <div id="qrcodeCanvas"></div>
            </div>

            <div class="space-y-2">
                <p class="text-[11px] text-slate-400">Atau salin tautan berikut ke browser HP:</p>
                <div class="flex items-center gap-1.5 bg-slate-950/90 border border-white/10 rounded-xl p-1.5">
                    <input type="text" id="qrAppUrlInput" readonly class="w-full bg-transparent text-[11px] text-slate-300 px-2 outline-none select-all">
                    <button type="button" onclick="copyQrUrl()" class="shrink-0 rounded-lg bg-blue-600 hover:bg-blue-500 px-3 py-1 text-xs font-semibold text-white transition cursor-pointer">
                        <i class="fa-regular fa-copy"></i>
                    </button>
                </div>
            </div>

            <button type="button" onclick="closeAppQrModal()" class="w-full rounded-xl bg-white/10 hover:bg-white/15 py-2.5 text-xs font-semibold text-slate-200 transition cursor-pointer">
                Tutup Jendela
            </button>
        </div>
    </div>

    <!-- ================= MODAL PANDUAN FILE APK ================= -->
    <div id="apkGuideModal" class="fixed inset-0 z-50 hidden bg-black/85 backdrop-blur-md flex items-center justify-center p-4">
        <div class="relative w-full max-w-md rounded-3xl border border-white/15 bg-slate-900 p-6 sm:p-7 shadow-2xl text-left space-y-4">
            
            <button type="button" onclick="closeApkGuideModal()" class="absolute top-4 right-4 rounded-xl p-1.5 text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>

            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-lg">
                    <i class="fa-brands fa-android"></i>
                </span>
                <div>
                    <h3 class="text-base font-bold text-white">Status Paket APK Android</h3>
                    <p class="text-xs text-slate-400" id="apkGuideAppTitle">Aplikasi Sekolah</p>
                </div>
            </div>

            <div class="rounded-2xl border border-sky-500/20 bg-sky-500/10 p-3.5 text-xs text-sky-200 leading-relaxed">
                <p class="font-semibold mb-1"><i class="fa-solid fa-circle-check text-sky-400 mr-1"></i> Rekomendasi: Gunakan Mode PWA (Langsung Pasang)</p>
                <p class="text-slate-300">
                    Anda tidak perlu menunggu file APK diunduh. Cukup klik tombol <strong>"Buka / Pasang di Browser"</strong> atau <strong>"Scan QR Pasang di HP"</strong>, lalu pilih <em>"Tambahkan ke Layar Utama"</em>. Aplikasi akan langsung terpasang dalam hitungan detik!
                </p>
            </div>

            <div class="text-xs text-slate-400 space-y-2 border-t border-white/5 pt-3">
                <p class="font-semibold text-slate-200">Untuk Administrator Sekolah:</p>
                <p>
                    Jika ingin menyediakan unduhan file fisik <code>.apk</code> langsung di tombol ini, Anda dapat meletakkan file APK di folder server:
                </p>
                <code class="block rounded-lg bg-slate-950 p-2 text-slate-300 text-[11px] font-mono border border-white/5">
                    uploads/apps/cbt-app.apk<br>
                    uploads/apps/presensi-app.apk<br>
                    uploads/apps/perpustakaan-app.apk
                </code>
                <p>Tombol unduh akan otomatis aktif saat file tersebut terdeteksi di server.</p>
            </div>

            <button type="button" onclick="closeApkGuideModal()" class="w-full rounded-xl bg-blue-600 hover:bg-blue-500 py-2.5 text-xs font-bold text-white transition cursor-pointer">
                Mengerti
            </button>
        </div>
    </div>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <script>
        let qrcodeInstance = null;

        function showAppQrModal(title, url, color, badgeText) {
            document.getElementById('qrAppTitle').textContent = title;
            document.getElementById('qrAppBadge').textContent = badgeText;
            document.getElementById('qrAppUrlInput').value = url;

            const canvasContainer = document.getElementById('qrcodeCanvas');
            canvasContainer.innerHTML = '';

            // Generate QR Code baru
            qrcodeInstance = new QRCode(canvasContainer, {
                text: url,
                width: 190,
                height: 190,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });

            document.getElementById('appQrModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeAppQrModal() {
            document.getElementById('appQrModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        function copyQrUrl() {
            const input = document.getElementById('qrAppUrlInput');
            input.select();
            input.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(input.value).then(() => {
                alert('Tautan aplikasi berhasil disalin ke clipboard!');
            }).catch(() => {
                document.execCommand('copy');
                alert('Tautan disalin!');
            });
        }

        function showApkGuideModal(appName) {
            document.getElementById('apkGuideAppTitle').textContent = appName;
            document.getElementById('apkGuideModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeApkGuideModal() {
            document.getElementById('apkGuideModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Close on backdrop
        document.getElementById('appQrModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeAppQrModal();
        });
        document.getElementById('apkGuideModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeApkGuideModal();
        });

        // Close on Esc
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAppQrModal();
                closeApkGuideModal();
            }
        });
    </script>

</body>
</html>
