<?php
/**
 * Halaman Tentang Kami (About Us)
 * Profil Lembaga Sekolah, Visi & Misi, Ekosistem 5 Role, dan Keunggulan Manajemen-PHP
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

$school_info = getSchoolSettings($pdo);

// Statistik dinamis dari database (dengan fallback)
$total_students = 0;
$total_teachers = 0;
$total_books = 0;
$total_ppdb = 0;

try {
    $total_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'siswa'")->fetchColumn();
    $total_teachers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'guru'")->fetchColumn();
    $total_books    = (int)$pdo->query("SELECT COUNT(*) FROM library_books")->fetchColumn();
    $total_ppdb     = (int)$pdo->query("SELECT COUNT(*) FROM ppdb_registrations")->fetchColumn();
} catch (Exception $e) {}

// Fallback display numbers if fresh DB install
$display_students = $total_students > 0 ? $total_students : 720;
$display_teachers = $total_teachers > 0 ? $total_teachers : 48;
$display_books    = $total_books > 0 ? $total_books : 1450;
$display_ppdb     = $total_ppdb > 0 ? $total_ppdb : 150;

$is_logged_in = isset($_SESSION['user_id']);
$user_name = $_SESSION['user_name'] ?? '';
$user_role = $_SESSION['user_role'] ?? '';
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Tentang Kami - <?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?></title>
    <meta name="description" content="Profil resmi <?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?>. Pelajari visi, misi, tenaga pendidik, sistem manajemen sekolah 5 role, dan keunggulan ekosistem digital kami.">

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
        .glow-radial-emerald {
            background: radial-gradient(circle at 50% 100%, rgba(16, 185, 129, 0.12) 0%, rgba(15, 23, 42, 0) 70%);
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-blue-600 selection:text-white flex flex-col justify-between">

    <?php
    $nav_active = 'about';
    require_once __DIR__ . '/includes/navbar.php';
    ?>

    <main class="relative z-10 flex-grow">

        <!-- ================= HERO SECTION ================= -->
        <section class="relative overflow-hidden pt-12 pb-20 md:pt-16 md:pb-28 glow-radial-blue">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                
                <div class="text-center max-w-3xl mx-auto space-y-5">
                    
                    <div class="inline-flex items-center gap-2 rounded-full border border-blue-500/30 bg-blue-500/10 px-4 py-1.5 text-xs sm:text-sm font-semibold text-blue-300 backdrop-blur-sm">
                        <span class="flex h-2 w-2 rounded-full bg-blue-500 animate-pulse"></span>
                        Profil Resmi & Ekosistem Digital Pendidikan
                    </div>

                    <h1 class="text-3xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight text-white leading-tight">
                        Transformasi Digital untuk <span class="bg-gradient-to-r from-blue-400 via-indigo-400 to-teal-400 bg-clip-text text-transparent">Pendidikan Masa Depan</span>
                    </h1>

                    <p class="text-base sm:text-lg text-slate-300 leading-relaxed max-w-2xl mx-auto">
                        <strong class="text-white"><?= htmlspecialchars($school_info['school_name'] ?? 'SMA Bina Bangsa Nusantara') ?></strong> mengintegrasikan sistem informasi modern berbasis 5 role pengguna guna menghadirkan tata kelola sekolah yang transparan, aman, dan berdaya saing global.
                    </p>

                    <div class="flex flex-wrap items-center justify-center gap-3 pt-4">
                        <a href="#profil-sekolah" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-6 py-3 text-sm font-bold text-white shadow-xl shadow-blue-600/30 hover:bg-blue-500 transition duration-300">
                            <i class="fa-solid fa-landmark"></i>
                            <span>Profil Lembaga</span>
                        </a>
                        <a href="ppdb/ppdb.php" class="inline-flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-6 py-3 text-sm font-bold text-emerald-300 hover:bg-emerald-500/20 transition duration-300">
                            <i class="fa-solid fa-graduation-cap"></i>
                            <span>PPDB Online TA 2026/2027</span>
                        </a>
                    </div>

                </div>

                <!-- STATS COUNTER BAR -->
                <div class="mt-14 sm:mt-18 grid grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6">
                    
                    <div class="group relative rounded-2xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 backdrop-blur transition hover:border-blue-500/40 hover:bg-slate-900/90">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Siswa Aktif</span>
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/10 text-blue-400 text-sm group-hover:scale-110 transition">
                                <i class="fa-solid fa-user-graduate"></i>
                            </span>
                        </div>
                        <div class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight"><?= number_format($display_students) ?>+</div>
                        <p class="text-xs text-slate-400 mt-1">Terdaftar resmi di Dapodik</p>
                    </div>

                    <div class="group relative rounded-2xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 backdrop-blur transition hover:border-indigo-500/40 hover:bg-slate-900/90">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Guru & Tenaga Ahli</span>
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-400 text-sm group-hover:scale-110 transition">
                                <i class="fa-solid fa-chalkboard-user"></i>
                            </span>
                        </div>
                        <div class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight"><?= number_format($display_teachers) ?></div>
                        <p class="text-xs text-slate-400 mt-1">Pendidik tersertifikasi nasional</p>
                    </div>

                    <div class="group relative rounded-2xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 backdrop-blur transition hover:border-emerald-500/40 hover:bg-slate-900/90">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Koleksi E-Book & Modul</span>
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-400 text-sm group-hover:scale-110 transition">
                                <i class="fa-solid fa-book-bookmark"></i>
                            </span>
                        </div>
                        <div class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight"><?= number_format($display_books) ?>+</div>
                        <p class="text-xs text-slate-400 mt-1">Katalog perpustakaan digital</p>
                    </div>

                    <div class="group relative rounded-2xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 backdrop-blur transition hover:border-amber-500/40 hover:bg-slate-900/90">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Pendaftar PPDB</span>
                            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-400 text-sm group-hover:scale-110 transition">
                                <i class="fa-solid fa-award"></i>
                            </span>
                        </div>
                        <div class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight"><?= number_format($display_ppdb) ?></div>
                        <p class="text-xs text-slate-400 mt-1">Tahun ajaran <?= htmlspecialchars($school_info['academic_year'] ?? '2026/2027 Ganjil') ?></p>
                    </div>

                </div>

            </div>
        </section>

        <!-- ================= PROFIL SEKOLAH SECTION ================= -->
        <section id="profil-sekolah" class="py-16 md:py-24 border-t border-white/5 bg-slate-900/40">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">
                    
                    <!-- Left: Card Identitas Resmi Sekolah -->
                    <div class="lg:col-span-5 relative">
                        <div class="absolute -inset-1 rounded-3xl bg-gradient-to-r from-blue-600 to-indigo-600 opacity-20 blur-xl"></div>
                        
                        <div class="relative rounded-3xl border border-white/10 bg-slate-950 p-6 sm:p-8 shadow-2xl backdrop-blur">
                            
                            <div class="flex items-center gap-4 pb-6 border-b border-white/10">
                                <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 text-white text-3xl shadow-lg shadow-blue-500/30">
                                    <i class="fa-solid fa-school"></i>
                                </div>
                                <div>
                                    <span class="inline-block rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-[11px] font-bold text-emerald-400">
                                        Akreditasi A (Unggul)
                                    </span>
                                    <h3 class="text-lg sm:text-xl font-bold text-white mt-1">
                                        <?= htmlspecialchars($school_info['school_name'] ?? 'SMA Bina Bangsa Nusantara') ?>
                                    </h3>
                                    <p class="text-xs text-slate-400 font-mono">NPSN: 20108921 | Kurikulum Merdeka</p>
                                </div>
                            </div>

                            <div class="py-6 space-y-4 text-xs sm:text-sm">
                                <div class="flex items-start gap-3">
                                    <div class="h-7 w-7 rounded-lg bg-blue-500/10 text-blue-400 flex items-center justify-center shrink-0">
                                        <i class="fa-solid fa-location-dot"></i>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block text-xs">Alamat Lembaga:</span>
                                        <span class="font-medium text-slate-200"><?= htmlspecialchars($school_info['school_address'] ?? 'Jl. Pendidikan Nasional No. 45, Kebayoran Baru, Jakarta') ?></span>
                                    </div>
                                </div>

                                <div class="flex items-start gap-3">
                                    <div class="h-7 w-7 rounded-lg bg-indigo-500/10 text-indigo-400 flex items-center justify-center shrink-0">
                                        <i class="fa-solid fa-envelope"></i>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block text-xs">Email Resmi:</span>
                                        <span class="font-medium text-slate-200"><?= htmlspecialchars($school_info['school_email'] ?? 'info@binabangsa.sch.id') ?></span>
                                    </div>
                                </div>

                                <div class="flex items-start gap-3">
                                    <div class="h-7 w-7 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center shrink-0">
                                        <i class="fa-solid fa-phone"></i>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block text-xs">Telepon / Fax:</span>
                                        <span class="font-medium text-slate-200"><?= htmlspecialchars($school_info['school_phone'] ?? '(021) 789-0123') ?></span>
                                    </div>
                                </div>

                                <div class="flex items-start gap-3">
                                    <div class="h-7 w-7 rounded-lg bg-purple-500/10 text-purple-400 flex items-center justify-center shrink-0">
                                        <i class="fa-solid fa-globe"></i>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 block text-xs">Website Portal:</span>
                                        <a href="<?= htmlspecialchars($school_info['school_website'] ?? '#') ?>" target="_blank" class="font-medium text-blue-400 hover:underline">
                                            <?= htmlspecialchars($school_info['school_website'] ?? 'https://binabangsa.sch.id') ?>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <div class="pt-5 border-t border-white/10 flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 border border-white/10 text-blue-400">
                                    <i class="fa-solid fa-user-tie"></i>
                                </div>
                                <div>
                                    <p class="text-xs text-slate-400">Kepala Sekolah</p>
                                    <p class="text-sm font-bold text-white"><?= htmlspecialchars($school_info['headmaster_name'] ?? 'Dr. H. Bambang Sudirman, M.Pd') ?></p>
                                    <p class="text-[11px] text-slate-400 font-mono">NIP: <?= htmlspecialchars($school_info['headmaster_nip'] ?? '19750812 199903 1 002') ?></p>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- Right: Narasi & Visi Misi -->
                    <div class="lg:col-span-7 space-y-6">
                        
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider text-blue-400">Mengenal Lebih Dekat</span>
                            <h2 class="text-2xl sm:text-4xl font-extrabold text-white mt-1 tracking-tight">
                                Dedikasi Membangun Generasi Emas Berintegritas
                            </h2>
                            <p class="mt-4 text-sm sm:text-base text-slate-300 leading-relaxed">
                                Didirikan dengan komitmen tinggi terhadap kualitas pembelajaran, kami memadukan kurikulum nasional berbasis kompetensi dengan pembelajaran digital modern. Sistem ini dirancang untuk memfasilitasi setiap aspek akademik, administratif, dan komunikasi antar stakeholder sekolah secara instan dan tanpa sekat birokrasi konvensional.
                            </p>
                        </div>

                        <!-- Visi & Misi Tab / Cards -->
                        <div class="space-y-4">
                            
                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-5 backdrop-blur">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-500/20 text-blue-400 font-bold text-xs">
                                        <i class="fa-solid fa-eye"></i>
                                    </span>
                                    <h4 class="text-base font-bold text-white">Visi Sekolah</h4>
                                </div>
                                <p class="text-sm text-slate-300 italic pl-11">
                                    "Menjadi lembaga pendidikan terdepan yang menghasilkan insan berkarakter mulia, cerdas berteknologi, berwawasan lingkungan, serta unggul dalam persaingan global pada tahun 2030."
                                </p>
                            </div>

                            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-5 backdrop-blur">
                                <div class="flex items-center gap-3 mb-3">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/20 text-emerald-400 font-bold text-xs">
                                        <i class="fa-solid fa-bullseye"></i>
                                    </span>
                                    <h4 class="text-base font-bold text-white">Misi Utama Sekolah</h4>
                                </div>
                                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-3 pl-1 text-xs sm:text-sm text-slate-300">
                                    <li class="flex items-start gap-2">
                                        <i class="fa-solid fa-check-circle text-emerald-400 mt-1 shrink-0"></i>
                                        <span>Menyelenggarakan proses pembelajaran aktif, kreatif, dan berbasis teknologi mutakhir.</span>
                                    </li>
                                    <li class="flex items-start gap-2">
                                        <i class="fa-solid fa-check-circle text-emerald-400 mt-1 shrink-0"></i>
                                        <span>Menanamkan nilai-nilai religiusitas, kejujuran, dan toleransi dalam kehidupan bersosial.</span>
                                    </li>
                                    <li class="flex items-start gap-2">
                                        <i class="fa-solid fa-check-circle text-emerald-400 mt-1 shrink-0"></i>
                                        <span>Menerapkan tata kelola digital terpadu yang transparan, akuntabel, dan bebas kertas (paperless).</span>
                                    </li>
                                    <li class="flex items-start gap-2">
                                        <i class="fa-solid fa-check-circle text-emerald-400 mt-1 shrink-0"></i>
                                        <span>Mempererat sinergi aktif antara guru, orang tua, dan masyarakat demi perkembangan siswa.</span>
                                    </li>
                                </ul>
                            </div>

                        </div>

                    </div>

                </div>

            </div>
        </section>

        <!-- ================= 5 ROLE SISTEM SECTION ================= -->
        <section class="py-16 md:py-24 border-t border-white/5 relative">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                
                <div class="text-center max-w-2xl mx-auto mb-14 space-y-3">
                    <span class="text-xs font-bold uppercase tracking-wider text-blue-400">Arsitektur Terintegrasi</span>
                    <h2 class="text-2xl sm:text-4xl font-extrabold text-white tracking-tight">
                        5 Role Pengguna Terproteksi
                    </h2>
                    <p class="text-sm text-slate-400">
                        Setiap pemangku kepentingan memiliki dashboard kerja khusus yang disesuaikan dengan otoritas tugas masing-masing.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
                    
                    <!-- 1. Administrator -->
                    <div class="rounded-2xl border border-red-500/20 bg-slate-900/60 p-5 backdrop-blur transition hover:border-red-500/50 hover:bg-slate-900 group">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-red-500/10 text-red-400 text-xl mb-4 group-hover:scale-110 transition">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-red-500/20 text-red-300">Level Utama</span>
                        <h3 class="text-base font-bold text-white mt-2">Administrator</h3>
                        <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                            Manajemen pengguna, pengaturan data pokok sekolah, audit trails aktivitas, backup database, dan kontrol modul.
                        </p>
                    </div>

                    <!-- 2. Staf TU -->
                    <div class="rounded-2xl border border-blue-500/20 bg-slate-900/60 p-5 backdrop-blur transition hover:border-blue-500/50 hover:bg-slate-900 group">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400 text-xl mb-4 group-hover:scale-110 transition">
                            <i class="fa-solid fa-id-card-clip"></i>
                        </div>
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-blue-500/20 text-blue-300">Tata Usaha</span>
                        <h3 class="text-base font-bold text-white mt-2">Staf Sekolah</h3>
                        <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                            Verifikasi berkas PPDB, penagihan SPP & kwitansi, dispensasi surat, dan pengelolaan inventaris buku perpustakaan.
                        </p>
                    </div>

                    <!-- 3. Guru -->
                    <div class="rounded-2xl border border-emerald-500/20 bg-slate-900/60 p-5 backdrop-blur transition hover:border-emerald-500/50 hover:bg-slate-900 group">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-400 text-xl mb-4 group-hover:scale-110 transition">
                            <i class="fa-solid fa-chalkboard-user"></i>
                        </div>
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300">Akademik</span>
                        <h3 class="text-base font-bold text-white mt-2">Guru Pendidik</h3>
                        <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                            Pengelolaan jadwal mengajar, pengunggahan materi & modul, pembuatan tugas online, serta rekapitulasi nilai rapor.
                        </p>
                    </div>

                    <!-- 4. Siswa -->
                    <div class="rounded-2xl border border-purple-500/20 bg-slate-900/60 p-5 backdrop-blur transition hover:border-purple-500/50 hover:bg-slate-900 group">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-purple-500/10 text-purple-400 text-xl mb-4 group-hover:scale-110 transition">
                            <i class="fa-solid fa-user-graduate"></i>
                        </div>
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-purple-500/20 text-purple-300">Pelajar</span>
                        <h3 class="text-base font-bold text-white mt-2">Siswa</h3>
                        <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                            Portal e-learning mandiri, presensi QR harian, peminjaman e-book, unggah tugas, dan pemantauan nilai hasil belajar.
                        </p>
                    </div>

                    <!-- 5. Orang Tua -->
                    <div class="rounded-2xl border border-amber-500/20 bg-slate-900/60 p-5 backdrop-blur transition hover:border-amber-500/50 hover:bg-slate-900 group">
                        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-500/10 text-amber-400 text-xl mb-4 group-hover:scale-110 transition">
                            <i class="fa-solid fa-person-breastfeeding"></i>
                        </div>
                        <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded bg-amber-500/20 text-amber-300">Pendamping</span>
                        <h3 class="text-base font-bold text-white mt-2">Orang Tua / Wali</h3>
                        <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                            Pemantauan presensi dan kehadiran putra-putri, cek tagihan SPP berkala, konsultasi dengan guru via perpesanan.
                        </p>
                    </div>

                </div>

            </div>
        </section>

        <!-- ================= FITUR UNGGULAN APLIKASI ================= -->
        <section class="py-16 md:py-24 border-t border-white/5 bg-slate-900/30">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                
                <div class="text-center max-w-2xl mx-auto mb-14 space-y-3">
                    <span class="text-xs font-bold uppercase tracking-wider text-emerald-400">Ekosistem Lengkap</span>
                    <h2 class="text-2xl sm:text-4xl font-extrabold text-white tracking-tight">
                        Fitur Unggulan Sistem Informasi
                    </h2>
                    <p class="text-sm text-slate-400">
                        Solusi end-to-end tanpa memerlukan aplikasi pihak ketiga tambahan.
                    </p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    
                    <div class="rounded-2xl border border-white/10 bg-slate-950 p-6 shadow-md transition hover:-translate-y-1 hover:border-emerald-500/40">
                        <div class="h-10 w-10 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center text-lg mb-4">
                            <i class="fa-solid fa-laptop-file"></i>
                        </div>
                        <h3 class="text-base font-bold text-white">PPDB Online Terintegrasi</h3>
                        <p class="text-xs sm:text-sm text-slate-400 mt-2 leading-relaxed">
                            Pendaftaran 4 jalur (Reguler, Zonasi, Prestasi, Afirmasi), kalkulasi skor rapor otomatis, kartu ujian ber-QR Code, dan verifikasi berkas cepat.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-slate-950 p-6 shadow-md transition hover:-translate-y-1 hover:border-blue-500/40">
                        <div class="h-10 w-10 rounded-xl bg-blue-500/10 text-blue-400 flex items-center justify-center text-lg mb-4">
                            <i class="fa-solid fa-qrcode"></i>
                        </div>
                        <h3 class="text-base font-bold text-white">Presensi QR & Geolocation</h3>
                        <p class="text-xs sm:text-sm text-slate-400 mt-2 leading-relaxed">
                            Absensi cepat menggunakan kamera smartphone dengan validasi kode dinamis untuk meminimalisir kecurangan presensi siswa maupun staf.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-slate-950 p-6 shadow-md transition hover:-translate-y-1 hover:border-indigo-500/40">
                        <div class="h-10 w-10 rounded-xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center text-lg mb-4">
                            <i class="fa-solid fa-book-open-reader"></i>
                        </div>
                        <h3 class="text-base font-bold text-white">Perpustakaan & E-Book</h3>
                        <p class="text-xs sm:text-sm text-slate-400 mt-2 leading-relaxed">
                            Pencatatan sirkulasi peminjaman buku, katalog modul materi digital, scanner barcode ISBN, dan penghitungan denda otomatis.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-slate-950 p-6 shadow-md transition hover:-translate-y-1 hover:border-amber-500/40">
                        <div class="h-10 w-10 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-lg mb-4">
                            <i class="fa-solid fa-file-invoice-dollar"></i>
                        </div>
                        <h3 class="text-base font-bold text-white">Keuangan & Pembayaran SPP</h3>
                        <p class="text-xs sm:text-sm text-slate-400 mt-2 leading-relaxed">
                            Penerbitan tagihan bulanan otomatis, rekonsiliasi pembayaran kas, serta cetak bukti kwitansi resmi berstempel digital.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-slate-950 p-6 shadow-md transition hover:-translate-y-1 hover:border-purple-500/40">
                        <div class="h-10 w-10 rounded-xl bg-purple-500/10 text-purple-400 flex items-center justify-center text-lg mb-4">
                            <i class="fa-solid fa-envelope-open-text"></i>
                        </div>
                        <h3 class="text-base font-bold text-white">Layanan Persuratan Digital</h3>
                        <p class="text-xs sm:text-sm text-slate-400 mt-2 leading-relaxed">
                            Pengajuan surat izin siswa, surat keterangan aktif belajar, dan disposisi izin operasional secara online tanpa antrean fisik.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-slate-950 p-6 shadow-md transition hover:-translate-y-1 hover:border-rose-500/40">
                        <div class="h-10 w-10 rounded-xl bg-rose-500/10 text-rose-400 flex items-center justify-center text-lg mb-4">
                            <i class="fa-solid fa-lock"></i>
                        </div>
                        <h3 class="text-base font-bold text-white">Standar Keamanan Siber Berlapis</h3>
                        <p class="text-xs sm:text-sm text-slate-400 mt-2 leading-relaxed">
                            CSRF Token 64-karakter, SQL Injection prevention dengan PDO Prepared Statements, hashing password Bcrypt, dan rekaman Audit Log.
                        </p>
                    </div>

                </div>

            </div>
        </section>

        <!-- ================= FAQ SECTION ================= -->
        <section class="py-16 md:py-24 border-t border-white/5">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                
                <div class="text-center mb-12 space-y-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-blue-400">Pusat Bantuan</span>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                        Pertanyaan yang Sering Diajukan (FAQ)
                    </h2>
                </div>

                <div class="space-y-3" id="faqAccordion">
                    
                    <!-- FAQ 1 -->
                    <div class="rounded-2xl border border-white/10 bg-slate-900/70 overflow-hidden">
                        <button type="button" class="faq-toggle w-full flex items-center justify-between p-5 text-left text-sm sm:text-base font-bold text-white hover:text-blue-400 transition" onclick="toggleFaq(this)">
                            <span>Bagaimana cara mendaftar sebagai calon siswa baru (PPDB)?</span>
                            <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-300"></i>
                        </button>
                        <div class="faq-content hidden px-5 pb-5 text-xs sm:text-sm text-slate-300 leading-relaxed border-t border-white/5 pt-3">
                            Calon peserta didik cukup mengunjungi menu <a href="ppdb/ppdb.php" class="text-emerald-400 underline font-semibold">PPDB Online</a>, memilih jalur seleksi yang sesuai (Reguler, Zonasi, Prestasi, Afirmasi), mengisi data diri, mengunggah pindaian berkas persyaratan, dan mencetak kartu peserta yang dilengkapi nomor registrasi unik serta QR Code verifikasi.
                        </div>
                    </div>

                    <!-- FAQ 2 -->
                    <div class="rounded-2xl border border-white/10 bg-slate-900/70 overflow-hidden">
                        <button type="button" class="faq-toggle w-full flex items-center justify-between p-5 text-left text-sm sm:text-base font-bold text-white hover:text-blue-400 transition" onclick="toggleFaq(this)">
                            <span>Bagaimana orang tua dapat memantau kehadiran anak?</span>
                            <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-300"></i>
                        </button>
                        <div class="faq-content hidden px-5 pb-5 text-xs sm:text-sm text-slate-300 leading-relaxed border-t border-white/5 pt-3">
                            Orang tua yang telah memiliki akun dapat masuk ke portal dashboard orang tua. Di dalamnya tersedia menu pemantauan presensi harian, rekapitulasi kehadiran (hadir, izin, sakit, alpa), jadwal belajar, serta status tagihan SPP siswa secara real-time.
                        </div>
                    </div>

                    <!-- FAQ 3 -->
                    <div class="rounded-2xl border border-white/10 bg-slate-900/70 overflow-hidden">
                        <button type="button" class="faq-toggle w-full flex items-center justify-between p-5 text-left text-sm sm:text-base font-bold text-white hover:text-blue-400 transition" onclick="toggleFaq(this)">
                            <span>Apakah data dan berkas yang diunggah terjamin keamanannya?</span>
                            <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-300"></i>
                        </button>
                        <div class="faq-content hidden px-5 pb-5 text-xs sm:text-sm text-slate-300 leading-relaxed border-t border-white/5 pt-3">
                            Sistem Manajemen-PHP menerapkan sanitasi file upload, validasi MIME type, proteksi CSRF di seluruh form interaktif, parameterized queries untuk mencegah serangan SQL Injection, dan pembatasan izin direktori agar dokumen tidak dapat diakses tanpa hak otorisasi.
                        </div>
                    </div>

                    <!-- FAQ 4 -->
                    <div class="rounded-2xl border border-white/10 bg-slate-900/70 overflow-hidden">
                        <button type="button" class="faq-toggle w-full flex items-center justify-between p-5 text-left text-sm sm:text-base font-bold text-white hover:text-blue-400 transition" onclick="toggleFaq(this)">
                            <span>Siapa yang harus dihubungi jika mengalami kendala login atau lupa kata sandi?</span>
                            <i class="fa-solid fa-chevron-down text-xs text-slate-400 transition-transform duration-300"></i>
                        </button>
                        <div class="faq-content hidden px-5 pb-5 text-xs sm:text-sm text-slate-300 leading-relaxed border-t border-white/5 pt-3">
                            Anda dapat menghubungi tim Tata Usaha atau Administrator Sekolah melalui nomor kontak <strong class="text-white"><?= htmlspecialchars($school_info['school_phone'] ?? '(021) 789-0123') ?></strong> atau email resmi <strong class="text-white"><?= htmlspecialchars($school_info['school_email'] ?? 'info@binabangsa.sch.id') ?></strong> pada jam operasional sekolah (Senin - Jumat, 07.30 - 15.30 WIB).
                        </div>
                    </div>

                </div>

            </div>
        </section>

        <!-- ================= CTA BANNER ================= -->
        <section class="py-16 border-t border-white/5 glow-radial-emerald">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-3xl border border-emerald-500/30 bg-gradient-to-r from-emerald-950/60 via-slate-900 to-slate-900 p-8 sm:p-12 text-center backdrop-blur shadow-2xl relative overflow-hidden">
                    <div class="relative z-10 max-w-2xl mx-auto space-y-4">
                        <span class="inline-block rounded-full bg-emerald-500/20 px-3.5 py-1 text-xs font-bold text-emerald-300">
                            Penerimaan Siswa Baru TA <?= htmlspecialchars($school_info['academic_year'] ?? '2026/2027 Ganjil') ?>
                        </span>
                        <h2 class="text-2xl sm:text-4xl font-extrabold text-white tracking-tight">
                            Siap Menjadi Bagian dari <?= htmlspecialchars($school_info['school_name'] ?? 'Sekolah Kami') ?>?
                        </h2>
                        <p class="text-sm sm:text-base text-slate-300 leading-relaxed">
                            Pendaftaran online dibuka dengan kuota terbatas. Daftarkan diri Anda sekarang atau masuk ke portal sistem untuk mengelola aktivitas akademik.
                        </p>
                        <div class="flex flex-wrap items-center justify-center gap-3 pt-3">
                            <a href="ppdb/ppdb.php" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-emerald-600/30 transition">
                                <i class="fa-solid fa-graduation-cap"></i>
                                <span>Daftar PPDB Sekarang</span>
                            </a>
                            <a href="auth/login.php" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-6 py-3 text-sm font-semibold text-slate-200 transition">
                                <i class="fa-solid fa-arrow-right-to-bracket"></i>
                                <span>Masuk ke Dashboard</span>
                            </a>
                        </div>
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

            // Tutup semua yang lain
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
