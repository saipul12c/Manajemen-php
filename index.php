<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

$school_info = getSchoolSettings($pdo);

// Ambil pengumuman yang dipublikasikan dan masih aktif
$announcements = [];
$urgent_announcements = [];
try {
    $stmt_ann = $pdo->prepare("
        SELECT a.*, u.name as author_name, u.role as author_role
        FROM announcements a
        LEFT JOIN users u ON a.author_id = u.id
        WHERE a.status = 'published'
          AND (a.expires_at IS NULL OR a.expires_at > NOW())
        ORDER BY a.is_pinned DESC, a.created_at DESC
        LIMIT 12
    ");
    $stmt_ann->execute();
    $announcements = $stmt_ann->fetchAll(PDO::FETCH_ASSOC);

    foreach ($announcements as $a) {
        if ($a['is_pinned'] || in_array($a['category'], ['darurat', 'penting'])) {
            $urgent_announcements[] = $a;
        }
    }
} catch (Exception $e) {
    // Fallback silent
}

// Ambil agenda kegiatan sekolah mendatang
$upcoming_events = [];
try {
    $stmt_events = $pdo->prepare("
        SELECT * FROM calendar_events
        WHERE event_date >= CURRENT_DATE()
        ORDER BY event_date ASC
        LIMIT 5
    ");
    $stmt_events->execute();
    $upcoming_events = $stmt_events->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Ambil berita terbaru yang dipublikasikan untuk landing page
$index_news = [];
try {
    $stmt_idx_news = $pdo->prepare("
        SELECT n.*, u.name as author_name, u.role as author_role
        FROM news n
        JOIN users u ON n.author_id = u.id
        WHERE n.status = 'published'
        ORDER BY n.is_featured DESC, n.published_at DESC
        LIMIT 3
    ");
    $stmt_idx_news->execute();
    $index_news = $stmt_idx_news->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?> - Portal Informasi & Sistem Manajemen Sekolah</title>
    <meta name="description" content="Papan informasi resmi, portal PPDB online, katalog perpustakaan digital, dan sistem informasi akademik sekolah terpadu.">

    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-card {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .announcement-body img {
            max-width: 100%;
            height: auto;
            border-radius: 0.75rem;
            margin: 0.5rem 0;
        }
    </style>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 selection:bg-blue-600 selection:text-white">

    <?php
    $nav_active = 'beranda';
    require_once __DIR__ . '/includes/navbar.php';
    ?>

    <!-- HERO SECTION -->
    <header class="relative overflow-hidden border-b border-white/5 pt-12 pb-16 md:py-24">
        <!-- Ambient Glow -->
        <div class="absolute -top-32 -left-32 w-96 h-96 rounded-full bg-blue-600/15 blur-3xl pointer-events-none"></div>
        <div class="absolute top-1/2 -right-32 w-96 h-96 rounded-full bg-indigo-600/15 blur-3xl pointer-events-none"></div>

        <div class="mx-auto grid max-w-7xl items-center gap-12 px-4 sm:px-6 md:grid-cols-12 relative z-10">

            <div class="md:col-span-7">

                <div class="mb-5 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-blue-400/20 bg-blue-400/10 px-3.5 py-1 text-xs text-blue-300 font-medium">
                        <i class="fa-solid fa-school-flag"></i>
                        <span>Sistem Manajemen Terpadu</span>
                    </span>

                    <a href="ppdb/ppdb.php" class="inline-flex items-center gap-1.5 rounded-full border border-emerald-400/30 bg-emerald-400/10 px-3.5 py-1 text-xs text-emerald-300 font-semibold hover:bg-emerald-400/20 transition">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                        </span>
                        PPDB TA 2026/2027 Dibuka
                    </a>
                </div>

                <h1 class="text-3xl sm:text-5xl lg:text-6xl font-extrabold leading-[1.15] text-white">
                    <?= htmlspecialchars($school_info['hero_title'] ?? 'Pusat Informasi & Administrasi Digital') ?>
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-blue-400 via-sky-300 to-indigo-400">
                        <?= htmlspecialchars($school_info['school_name'] ?? 'Sekolah Unggulan') ?>
                    </span>
                </h1>

                <p class="mt-5 max-w-xl text-sm sm:text-base leading-relaxed text-slate-400">
                    <?= nl2br(htmlspecialchars($school_info['hero_description'] ?? 'Platform cerdas operasional sekolah yang mengintegrasikan Papan Pengumuman Resmi, Katalog Perpustakaan & E-Book, PPDB Online, hingga portal akademik terpadu untuk 5 role pengguna.')) ?>
                </p>

                <!-- Tombol Aksi Cepat -->
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="informasi.php"
                       class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-sky-600 to-blue-600 hover:from-sky-500 hover:to-blue-500 px-5 py-3 text-xs sm:text-sm font-bold text-white shadow-xl shadow-sky-600/20 transition">
                        <i class="fa-solid fa-bullhorn"></i>
                        <span>Papan Informasi Resmi</span>
                    </a>

                    <a href="perpustakaan.php"
                       class="inline-flex items-center gap-2 rounded-xl border border-indigo-500/30 bg-indigo-600/15 hover:bg-indigo-600/25 px-5 py-3 text-xs sm:text-sm font-bold text-indigo-300 transition">
                        <i class="fa-solid fa-book-open-reader"></i>
                        <span>Katalog Perpustakaan</span>
                    </a>

                    <a href="ppdb/ppdb.php"
                       class="inline-flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-600/15 hover:bg-emerald-600/25 px-5 py-3 text-xs sm:text-sm font-bold text-emerald-300 transition">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <span>Daftar PPDB</span>
                    </a>

                    <a href="auth/login.php"
                       class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-3 text-xs sm:text-sm font-semibold text-slate-300 transition">
                        <span>Portal Login</span>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </div>

                <!-- Info Ringkas Kontak & Alamat -->
                <div class="mt-8 pt-6 border-t border-white/5 flex flex-wrap gap-4 text-xs text-slate-400">
                    <?php if (!empty($school_info['school_address'])): ?>
                    <div class="flex items-center gap-1.5">
                        <i class="fa-solid fa-location-dot text-slate-500"></i>
                        <span><?= htmlspecialchars($school_info['school_address']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($school_info['school_phone'])): ?>
                    <div class="flex items-center gap-1.5">
                        <i class="fa-solid fa-phone text-slate-500"></i>
                        <span><?= htmlspecialchars($school_info['school_phone']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Kartu Interaktif Kanan -->
            <div class="md:col-span-5">
                <div class="glass-card rounded-3xl p-6 sm:p-7 shadow-2xl space-y-4">
                    <div class="flex items-center justify-between border-b border-white/10 pb-4">
                        <div>
                            <span class="text-[11px] font-bold uppercase tracking-wider text-blue-400">Portal Layanan Terpadu</span>
                            <h2 class="text-lg font-bold text-white">Akses Cepat Pengunjung</h2>
                        </div>
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600/20 text-blue-400">
                            <i class="fa-solid fa-compass"></i>
                        </span>
                    </div>

                    <!-- Shortcut 1: Papan Informasi -->
                    <a href="informasi.php" class="block rounded-2xl bg-slate-900/80 border border-sky-500/20 p-3.5 hover:border-sky-500/40 hover:bg-slate-900 transition group">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-600/20 text-sky-400 group-hover:scale-105 transition">
                                    <i class="fa-solid fa-bullhorn"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-white group-hover:text-sky-300 transition">Papan Pengumuman Sekolah</p>
                                    <p class="text-[11px] text-slate-400"><?= count($announcements) ?> Pengumuman Resmi Aktif</p>
                                </div>
                            </div>
                            <span class="text-xs text-sky-400 font-bold group-hover:translate-x-1 transition">Buka →</span>
                        </div>
                    </a>

                    <!-- Shortcut 2: Katalog Perpustakaan -->
                    <a href="perpustakaan.php" class="block rounded-2xl bg-slate-900/80 border border-indigo-500/20 p-3.5 hover:border-indigo-500/40 hover:bg-slate-900 transition group">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600/20 text-indigo-400 group-hover:scale-105 transition">
                                    <i class="fa-solid fa-book-open-reader"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-white group-hover:text-indigo-300 transition">Perpustakaan & E-Book</p>
                                    <p class="text-[11px] text-slate-400">Booking Buku & In-Browser PDF Reader</p>
                                </div>
                            </div>
                            <span class="text-xs text-indigo-400 font-bold group-hover:translate-x-1 transition">Buka →</span>
                        </div>
                    </a>

                    <!-- Shortcut 3: PPDB Online -->
                    <a href="ppdb/ppdb.php" class="block rounded-2xl bg-slate-900/80 border border-emerald-500/20 p-3.5 hover:border-emerald-500/40 hover:bg-slate-900 transition group">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-600/20 text-emerald-400 group-hover:scale-105 transition">
                                    <i class="fa-solid fa-id-card"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-white group-hover:text-emerald-300 transition">Pendaftaran PPDB 2026/2027</p>
                                    <p class="text-[11px] text-slate-400">Formulir Mandiri & Kartu Ujian Berbarcode</p>
                                </div>
                            </div>
                            <span class="text-xs text-emerald-400 font-bold group-hover:translate-x-1 transition">Daftar →</span>
                        </div>
                    </a>

                    <!-- Status Operasional -->
                    <div class="rounded-2xl bg-slate-900/60 border border-white/5 p-3.5 flex items-center justify-between text-xs">
                        <span class="text-slate-400">Server & Database Sistem:</span>
                        <span class="font-semibold text-emerald-400 inline-flex items-center gap-1.5">
                            <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            Online & Siap Layanan
                        </span>
                    </div>

                </div>
            </div>

        </div>
    </header>

    <!-- ======================================================== -->
    <!-- BAGIAN: PAPAN INFORMASI & PENGUMUMAN SEKOLAH (MADING DIGITAL) -->
    <!-- ======================================================== -->
    <section id="papan-informasi" class="py-16 md:py-24 relative bg-slate-950/70 border-b border-white/5">

        <div class="mx-auto max-w-7xl px-4 sm:px-6">

            <!-- Section Header -->
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 mb-10">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-sky-400/30 bg-sky-400/10 px-3.5 py-1 text-xs text-sky-300 font-semibold mb-3">
                        <i class="fa-solid fa-bullhorn text-sky-400"></i>
                        <span>Mading Digital Terbuka</span>
                    </div>
                    <h2 class="text-2xl sm:text-4xl font-extrabold text-white tracking-tight">
                        Papan Informasi & Pengumuman Sekolah
                    </h2>
                    <p class="mt-2 text-sm sm:text-base text-slate-400 max-w-2xl">
                        Pusat publikasi resmi edaran kepala sekolah, jadwal akademik, agenda kegiatan, dan berita penting yang terhubung langsung dengan sistem informasi sekolah.
                    </p>
                </div>

                <!-- Info Jumlah Pengumuman & Link Halaman Penuh -->
                <div class="flex items-center gap-3">
                    <a href="informasi.php" class="hidden sm:inline-flex items-center gap-2 rounded-2xl border border-sky-500/30 bg-sky-600/10 hover:bg-sky-600/20 px-4 py-2.5 text-xs font-bold text-sky-300 transition shadow-sm">
                        <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
                        <span>Buka Halaman Mandiri</span>
                    </a>
                    <div class="rounded-2xl border border-white/10 bg-slate-900/80 px-4 py-2.5 text-right">
                        <p class="text-[11px] text-slate-400 font-medium">Total Pengumuman Aktif</p>
                        <p class="text-xl font-extrabold text-sky-400"><?= count($announcements) ?> <span class="text-xs font-normal text-slate-400">edaran</span></p>
                    </div>
                </div>
            </div>

            <!-- URGENT / DARURAT ALERT BANNER JIKA ADA -->
            <?php if (!empty($urgent_announcements)): ?>
                <?php $top_urgent = $urgent_announcements[0]; ?>
                <div class="mb-10 rounded-3xl border border-rose-500/40 bg-gradient-to-r from-rose-950/60 via-slate-900/90 to-amber-950/40 p-5 sm:p-6 shadow-2xl relative overflow-hidden">
                    <div class="absolute -right-12 -top-12 h-40 w-40 rounded-full bg-rose-500/10 blur-2xl pointer-events-none"></div>

                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 relative z-10">
                        <div class="flex items-start gap-3.5">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-rose-500/20 text-rose-400 border border-rose-500/30 text-lg">
                                <i class="fa-solid fa-triangle-exclamation animate-bounce"></i>
                            </span>
                            <div>
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="rounded-full bg-rose-500 px-2.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-white">
                                        PENGUMUMAN PENTING
                                    </span>
                                    <span class="text-[11px] text-slate-400">
                                        <i class="fa-regular fa-clock mr-1"></i><?= date('d M Y', strtotime($top_urgent['created_at'])) ?>
                                    </span>
                                </div>
                                <h3 class="text-base sm:text-lg font-bold text-white hover:text-rose-200 transition cursor-pointer" onclick="openAnnouncementModal(<?= htmlspecialchars(json_encode($top_urgent)) ?>)">
                                    <?= htmlspecialchars($top_urgent['title']) ?>
                                </h3>
                                <p class="text-xs sm:text-sm text-slate-300 mt-1 line-clamp-2">
                                    <?= htmlspecialchars(mb_strimwidth(strip_tags($top_urgent['content']), 0, 180, '...')) ?>
                                </p>
                            </div>
                        </div>

                        <button type="button" onclick="openAnnouncementModal(<?= htmlspecialchars(json_encode($top_urgent)) ?>)"
                                class="shrink-0 rounded-xl bg-rose-600 hover:bg-rose-500 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-rose-600/30 transition flex items-center gap-1.5 cursor-pointer">
                            <span>Baca Lengkap</span>
                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- PENCARIAN & FILTER KATEGORI -->
            <div class="glass-card rounded-2xl p-4 sm:p-5 mb-8">
                <div class="flex flex-col md:flex-row gap-4 items-center justify-between">
                    
                    <!-- Search Input -->
                    <div class="relative w-full md:w-80">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
                        <input type="text" id="annSearchInput" onkeyup="filterAnnouncements()" placeholder="Cari judul atau isi pengumuman..."
                               class="w-full rounded-xl border border-white/10 bg-slate-900/90 pl-9 pr-3.5 py-2 text-xs sm:text-sm text-white placeholder:text-slate-500 focus:border-sky-500 focus:outline-none transition">
                    </div>

                    <!-- Filter Kategori Tabs -->
                    <div class="flex items-center gap-1.5 overflow-x-auto w-full md:w-auto pb-1 md:pb-0 scrollbar-none" id="catFilterButtons">
                        <button type="button" onclick="setCategoryFilter('all', this)"
                                class="cat-pill active-cat px-3 py-1.5 rounded-xl text-xs font-bold transition whitespace-nowrap bg-sky-600 text-white shadow-md shadow-sky-600/20 cursor-pointer">
                            Semua
                        </button>
                        <button type="button" onclick="setCategoryFilter('penting', this)"
                                class="cat-pill px-3 py-1.5 rounded-xl text-xs font-semibold transition whitespace-nowrap bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white cursor-pointer">
                            <i class="fa-solid fa-circle-exclamation text-amber-400 mr-1"></i> Penting
                        </button>
                        <button type="button" onclick="setCategoryFilter('akademik', this)"
                                class="cat-pill px-3 py-1.5 rounded-xl text-xs font-semibold transition whitespace-nowrap bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white cursor-pointer">
                            <i class="fa-solid fa-book-open text-sky-400 mr-1"></i> Akademik
                        </button>
                        <button type="button" onclick="setCategoryFilter('kegiatan', this)"
                                class="cat-pill px-3 py-1.5 rounded-xl text-xs font-semibold transition whitespace-nowrap bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white cursor-pointer">
                            <i class="fa-solid fa-calendar-day text-emerald-400 mr-1"></i> Kegiatan
                        </button>
                        <button type="button" onclick="setCategoryFilter('umum', this)"
                                class="cat-pill px-3 py-1.5 rounded-xl text-xs font-semibold transition whitespace-nowrap bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white cursor-pointer">
                            <i class="fa-solid fa-info-circle text-purple-400 mr-1"></i> Umum
                        </button>
                    </div>

                </div>
            </div>

            <!-- GRID UTAMA: DAFTAR PENGUMUMAN & SIDEBAR AGENDA -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

                <!-- KOLOM KIRI (DAFTAR KARTU PENGUMUMAN): 8 Kolom -->
                <div class="lg:col-span-8">
                    
                    <?php if (empty($announcements)): ?>
                        <div class="glass-card rounded-3xl p-12 text-center">
                            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-white/5 text-slate-400 text-2xl mb-4">
                                <i class="fa-solid fa-inbox"></i>
                            </div>
                            <h3 class="text-lg font-bold text-white">Belum Ada Pengumuman</h3>
                            <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
                                Belum ada pengumuman publik yang dipublikasikan saat ini. Silakan periksa kembali nanti.
                            </p>
                        </div>
                    <?php else: ?>
                        
                        <div id="announcementsContainer" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <?php foreach ($announcements as $ann): 
                                $cat = $ann['category'] ?? 'umum';
                                $target = $ann['target_role'] ?? 'semua';
                                
                                // Mapping visual kategori
                                $cat_colors = [
                                    'darurat' => 'bg-rose-500/15 text-rose-300 border-rose-500/30',
                                    'penting' => 'bg-amber-500/15 text-amber-300 border-amber-500/30',
                                    'akademik' => 'bg-sky-500/15 text-sky-300 border-sky-500/30',
                                    'kegiatan' => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
                                    'umum' => 'bg-purple-500/15 text-purple-300 border-purple-500/30',
                                ];
                                $cat_badge_color = $cat_colors[$cat] ?? $cat_colors['umum'];

                                // Mapping label audiens
                                $target_labels = [
                                    'semua' => 'Semua Civitas',
                                    'siswa' => 'Khusus Siswa',
                                    'orang_tua' => 'Wali Murid',
                                    'guru' => 'Dewan Guru',
                                    'staf' => 'Staf / Pegawai',
                                ];
                                $target_label = $target_labels[$target] ?? 'Umum';
                            ?>
                                <article class="announcement-item glass-card rounded-2xl p-5 hover:border-sky-500/40 hover:bg-slate-900/90 transition flex flex-col justify-between group relative"
                                         data-category="<?= htmlspecialchars($cat) ?>"
                                         data-title="<?= htmlspecialchars(strtolower($ann['title'])) ?>"
                                         data-content="<?= htmlspecialchars(strtolower(strip_tags($ann['content']))) ?>">

                                    <!-- Top Meta Badges -->
                                    <div>
                                        <div class="flex items-center justify-between gap-2 mb-3">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <span class="rounded-lg border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider <?= $cat_badge_color ?>">
                                                    <?= htmlspecialchars(ucfirst($cat)) ?>
                                                </span>
                                                <span class="rounded-lg bg-white/5 border border-white/10 px-2 py-0.5 text-[10px] text-slate-300">
                                                    <?= htmlspecialchars($target_label) ?>
                                                </span>
                                            </div>

                                            <?php if (!empty($ann['is_pinned'])): ?>
                                                <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-400" title="Disematkan oleh Pengelola">
                                                    <i class="fa-solid fa-thumbtack text-amber-400"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Title -->
                                        <h3 class="text-sm sm:text-base font-bold text-white group-hover:text-sky-300 transition line-clamp-2 leading-snug cursor-pointer"
                                            onclick="openAnnouncementModal(<?= htmlspecialchars(json_encode($ann)) ?>)">
                                            <?= htmlspecialchars($ann['title']) ?>
                                        </h3>

                                        <!-- Snippet Body -->
                                        <p class="mt-2 text-xs text-slate-400 line-clamp-3 leading-relaxed">
                                            <?= htmlspecialchars(mb_strimwidth(strip_tags($ann['content']), 0, 160, '...')) ?>
                                        </p>
                                    </div>

                                    <!-- Bottom Meta & Action -->
                                    <div class="mt-4 pt-3 border-t border-white/5 flex items-center justify-between gap-2">
                                        <div class="text-[11px] text-slate-400 flex items-center gap-2">
                                            <span><i class="fa-regular fa-calendar mr-1"></i><?= date('d M Y', strtotime($ann['created_at'])) ?></span>
                                            <?php if (!empty($ann['attachment_url'])): ?>
                                                <span class="text-sky-400 font-semibold inline-flex items-center gap-1" title="Terdapat lampiran dokumen">
                                                    <i class="fa-solid fa-paperclip"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <button type="button" onclick="openAnnouncementModal(<?= htmlspecialchars(json_encode($ann)) ?>)"
                                                class="text-xs font-bold text-sky-400 hover:text-sky-300 group-hover:translate-x-0.5 transition inline-flex items-center gap-1 cursor-pointer">
                                            <span>Baca</span>
                                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                                        </button>
                                    </div>

                                </article>
                            <?php endforeach; ?>
                        </div>

                        <!-- State Jika Pencarian Nihil -->
                        <div id="noAnnouncementsMatch" class="hidden glass-card rounded-3xl p-10 text-center">
                            <i class="fa-solid fa-magnifying-glass text-3xl text-slate-500 mb-3"></i>
                            <h4 class="text-sm font-bold text-white">Tidak Ada Pengumuman Ditemukan</h4>
                            <p class="text-xs text-slate-400 mt-1">Coba gunakan kata kunci pencarian yang lain atau ubah filter kategori.</p>
                        </div>

                    <?php endif; ?>

                </div>

                <!-- KOLOM KANAN (SIDEBAR: AGENDA & HOTLINE LAYANAN): 4 Kolom -->
                <div class="lg:col-span-4 space-y-6">

                    <!-- WIDGET 1: AGENDA & KALENDER SEKOLAH -->
                    <div class="glass-card rounded-3xl p-5 sm:p-6 shadow-xl border border-white/10">
                        <div class="flex items-center justify-between border-b border-white/10 pb-3 mb-4">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-xs">
                                    <i class="fa-solid fa-calendar-days"></i>
                                </span>
                                <div>
                                    <h3 class="text-sm font-bold text-white">Agenda Mendatang</h3>
                                    <p class="text-[10px] text-slate-400">Kalender Kegiatan & Akademik</p>
                                </div>
                            </div>
                            <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-400">
                                2026/2027
                            </span>
                        </div>

                        <?php if (empty($upcoming_events)): ?>
                            <p class="text-xs text-slate-400 italic text-center py-4">Belum ada agenda terdekat yang dijadwalkan.</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($upcoming_events as $ev): ?>
                                    <div class="flex items-start gap-3 rounded-xl bg-slate-900/60 border border-white/5 p-3 hover:border-emerald-500/30 transition">
                                        <div class="flex flex-col items-center justify-center rounded-lg bg-emerald-600/15 border border-emerald-500/30 px-2.5 py-1 text-center shrink-0">
                                            <span class="text-[10px] font-bold uppercase text-emerald-400"><?= date('M', strtotime($ev['event_date'])) ?></span>
                                            <span class="text-sm font-extrabold text-white leading-none"><?= date('d', strtotime($ev['event_date'])) ?></span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <h4 class="text-xs font-bold text-slate-100 truncate"><?= htmlspecialchars($ev['title']) ?></h4>
                                            <?php if (!empty($ev['description'])): ?>
                                                <p class="text-[11px] text-slate-400 line-clamp-1 mt-0.5"><?= htmlspecialchars($ev['description']) ?></p>
                                            <?php endif; ?>
                                            <span class="inline-block mt-1 text-[10px] text-emerald-400 font-semibold">
                                                <i class="fa-regular fa-clock mr-1 text-[9px]"></i><?= date('d M Y', strtotime($ev['event_date'])) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- WIDGET 2: PUSAT INFORMASI & KONTAK LAYANAN -->
                    <div class="glass-card rounded-3xl p-5 sm:p-6 shadow-xl border border-white/10">
                        <div class="flex items-center gap-2.5 border-b border-white/10 pb-3 mb-4">
                            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-sky-500/20 text-sky-400 text-xs">
                                <i class="fa-solid fa-headset"></i>
                            </span>
                            <div>
                                <h3 class="text-sm font-bold text-white">Layanan & Tata Usaha</h3>
                                <p class="text-[10px] text-slate-400">Jam Operasional Layanan</p>
                            </div>
                        </div>

                        <div class="space-y-3 text-xs text-slate-300">
                            <div class="flex items-start gap-2.5">
                                <i class="fa-regular fa-clock text-slate-500 mt-0.5"></i>
                                <div>
                                    <p class="font-semibold text-white">Senin - Jumat:</p>
                                    <p class="text-[11px] text-slate-400">07:00 - 15:30 WIB (Hari Kerja)</p>
                                </div>
                            </div>

                            <?php if (!empty($school_info['school_phone'])): ?>
                            <div class="flex items-start gap-2.5">
                                <i class="fa-solid fa-phone text-slate-500 mt-0.5"></i>
                                <div>
                                    <p class="font-semibold text-white">Telepon / WhatsApp:</p>
                                    <p class="text-[11px] text-slate-400"><?= htmlspecialchars($school_info['school_phone']) ?></p>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($school_info['school_email'])): ?>
                            <div class="flex items-start gap-2.5">
                                <i class="fa-solid fa-envelope text-slate-500 mt-0.5"></i>
                                <div>
                                    <p class="font-semibold text-white">Email Resmi:</p>
                                    <p class="text-[11px] text-slate-400"><?= htmlspecialchars($school_info['school_email']) ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- CTA Login Portal -->
                        <div class="mt-5 pt-4 border-t border-white/10">
                            <p class="text-[11px] text-slate-400 mb-2.5">Warga sekolah ingin melihat pengumuman spesifik kelas & tugas?</p>
                            <a href="auth/login.php" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/30 py-2 text-xs font-bold text-blue-300 transition">
                                <i class="fa-solid fa-right-to-bracket text-xs"></i>
                                <span>Masuk ke Akun Portal</span>
                            </a>
                        </div>
                    </div>

                </div>

            </div>

        </div>

    </section>

    <!-- ======================================================== -->
    <!-- SEKSI BERITA & LIPUTAN SEKOLAH TERKINI -->
    <!-- ======================================================== -->
    <?php if (!empty($index_news)): ?>
    <section class="border-t border-white/10 bg-slate-950 py-16 sm:py-20 relative overflow-hidden">
        <div class="absolute -bottom-32 -right-32 h-96 w-96 rounded-full bg-blue-600/10 blur-3xl pointer-events-none"></div>

        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 relative z-10 space-y-10">
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-sky-400/30 bg-sky-400/10 px-3.5 py-1 text-xs text-sky-300 font-semibold mb-3">
                        <i class="fa-solid fa-newspaper text-sky-400"></i>
                        <span>Kabar & Publikasi Sekolah</span>
                    </div>
                    <h2 class="text-2xl sm:text-4xl font-extrabold text-white tracking-tight">
                        Liputan Berita & Prestasi Terbaru
                    </h2>
                    <p class="mt-2 text-sm sm:text-base text-slate-400 max-w-2xl">
                        Dokumentasi kegiatan unggulan, prestasi siswa di kancah daerah maupun nasional, dan artikel inspiratif dari dewan guru.
                    </p>
                </div>

                <a href="berita.php" class="inline-flex items-center gap-2 rounded-2xl bg-blue-600 hover:bg-blue-500 px-5 py-3 text-xs font-bold text-white shadow-lg shadow-blue-600/30 transition self-start md:self-auto">
                    <span>Lihat Semua Berita</span>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php foreach ($index_news as $in_item): ?>
                    <?php 
                        $in_cat = NEWS_CATEGORIES[$in_item['category']] ?? ['label' => ucfirst($in_item['category']), 'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300'];
                        $in_thumb = !empty($in_item['thumbnail']) ? 'uploads/news/' . htmlspecialchars($in_item['thumbnail']) : null;
                    ?>
                    <article class="glass-card rounded-3xl overflow-hidden flex flex-col justify-between hover:border-sky-500/40 hover:bg-slate-900/90 transition duration-300 group shadow-lg">
                        <div>
                            <a href="berita.php?id=<?= $in_item['id'] ?>" class="block relative h-48 w-full bg-slate-950 overflow-hidden">
                                <?php if ($in_thumb): ?>
                                    <img src="<?= $in_thumb ?>" alt="<?= htmlspecialchars($in_item['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                                <?php else: ?>
                                    <div class="w-full h-full bg-gradient-to-tr from-slate-950 via-slate-900 to-indigo-950/70 flex items-center justify-center text-slate-700">
                                        <i class="fa-solid fa-newspaper text-5xl opacity-40"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-black/30"></div>
                                <div class="absolute top-3 left-3">
                                    <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider backdrop-blur-md border <?= $in_cat['badge'] ?>">
                                        <?= htmlspecialchars($in_cat['label']) ?>
                                    </span>
                                </div>
                                <div class="absolute bottom-2.5 left-3 text-[11px] text-slate-300 flex items-center gap-1.5">
                                    <i class="fa-regular fa-calendar-days text-slate-400"></i>
                                    <?= date('d M Y', strtotime($in_item['published_at'] ?? $in_item['created_at'])) ?>
                                </div>
                            </a>

                            <div class="p-5 space-y-2.5">
                                <h3 class="text-base font-bold text-white line-clamp-2 group-hover:text-sky-400 transition leading-snug">
                                    <a href="berita.php?id=<?= $in_item['id'] ?>">
                                        <?= htmlspecialchars($in_item['title']) ?>
                                    </a>
                                </h3>
                                <p class="text-xs text-slate-400 line-clamp-3 leading-relaxed">
                                    <?= htmlspecialchars($in_item['excerpt'] ?: strip_tags($in_item['content'])) ?>
                                </p>
                            </div>
                        </div>

                        <div class="p-5 pt-0 border-t border-white/5 flex items-center justify-between text-[11px] text-slate-400 mt-2">
                            <span class="font-medium text-slate-300 truncate max-w-[150px]"><?= htmlspecialchars($in_item['author_name']) ?></span>
                            <a href="berita.php?id=<?= $in_item['id'] ?>" class="text-sky-400 font-bold group-hover:underline flex items-center gap-1">
                                <span>Baca Selengkapnya</span>
                                <i class="fa-solid fa-chevron-right text-[9px]"></i>
                            </a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ======================================================== -->
    <!-- MODAL DETAIL PENGUMUMAN LENGKAP -->
    <!-- ======================================================== -->
    <div id="announcementModal" class="fixed inset-0 z-50 hidden bg-black/85 backdrop-blur-md flex items-center justify-center p-4 overflow-y-auto">
        <div class="relative w-full max-w-2xl rounded-3xl border border-white/15 bg-slate-900 p-6 sm:p-8 shadow-2xl text-slate-100 my-8">
            
            <!-- Modal Header -->
            <div class="flex items-start justify-between gap-4 border-b border-white/10 pb-4 mb-5">
                <div>
                    <div class="flex items-center gap-2 flex-wrap mb-2">
                        <span id="modalCategoryBadge" class="rounded-lg border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                            KATEGORI
                        </span>
                        <span id="modalTargetBadge" class="rounded-lg bg-white/5 border border-white/10 px-2.5 py-0.5 text-[10px] text-slate-300">
                            Semua
                        </span>
                        <span id="modalPinnedBadge" class="hidden rounded-lg bg-amber-500/15 border border-amber-500/30 px-2 py-0.5 text-[10px] font-bold text-amber-300">
                            <i class="fa-solid fa-thumbtack mr-1"></i> Disematkan
                        </span>
                    </div>
                    <h3 id="modalTitle" class="text-lg sm:text-xl font-extrabold text-white leading-snug">
                        Judul Pengumuman
                    </h3>
                </div>

                <button type="button" onclick="closeAnnouncementModal()" class="rounded-xl p-1.5 text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <!-- Meta Author & Date -->
            <div class="flex items-center gap-4 text-xs text-slate-400 mb-5 pb-3 border-b border-white/5">
                <div class="flex items-center gap-1.5">
                    <i class="fa-regular fa-calendar text-slate-500"></i>
                    <span id="modalDate">Tanggal</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <i class="fa-regular fa-user text-slate-500"></i>
                    <span id="modalAuthor">Pengelola Sekolah</span>
                </div>
            </div>

            <!-- Content Body -->
            <div id="modalContent" class="announcement-body text-xs sm:text-sm text-slate-200 leading-relaxed whitespace-pre-line space-y-3 max-h-[50vh] overflow-y-auto pr-2 scrollbar-thin">
                Isi pengumuman lengkap...
            </div>

            <!-- Lampiran File (Jika Ada) -->
            <div id="modalAttachmentSection" class="hidden mt-6 pt-4 border-t border-white/10">
                <p class="text-xs font-semibold text-slate-300 mb-2 flex items-center gap-1.5">
                    <i class="fa-solid fa-paperclip text-sky-400"></i>
                    <span>Dokumen Lampiran Resmi:</span>
                </p>
                <a id="modalAttachmentLink" href="#" target="_blank" download
                   class="inline-flex items-center gap-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 px-4 py-2 text-xs font-semibold text-sky-300 transition">
                    <i class="fa-solid fa-file-arrow-down"></i>
                    <span id="modalAttachmentName">Download Lampiran</span>
                </a>
            </div>

            <!-- Modal Footer -->
            <div class="mt-6 pt-4 border-t border-white/10 flex items-center justify-between gap-3">
                <button type="button" onclick="window.print()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition flex items-center gap-1.5 cursor-pointer">
                    <i class="fa-solid fa-print"></i> Cetak
                </button>
                <button type="button" onclick="closeAnnouncementModal()" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white transition cursor-pointer">
                    Tutup
                </button>
            </div>

        </div>
    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    ?>

    <!-- JAVASCRIPT LOGIC UNTUK PAPAN INFORMASI -->
    <script>
        let currentCategory = 'all';

        function setCategoryFilter(category, button) {
            currentCategory = category;

            // Update status button class
            const pills = document.querySelectorAll('.cat-pill');
            pills.forEach(p => {
                p.classList.remove('bg-sky-600', 'text-white', 'shadow-md', 'shadow-sky-600/20');
                p.classList.add('bg-white/5', 'text-slate-300');
            });

            button.classList.remove('bg-white/5', 'text-slate-300');
            button.classList.add('bg-sky-600', 'text-white', 'shadow-md', 'shadow-sky-600/20');

            filterAnnouncements();
        }

        function filterAnnouncements() {
            const query = (document.getElementById('annSearchInput')?.value || '').toLowerCase().trim();
            const items = document.querySelectorAll('.announcement-item');
            let visibleCount = 0;

            items.forEach(item => {
                const itemCat = item.getAttribute('data-category') || 'umum';
                const itemTitle = item.getAttribute('data-title') || '';
                const itemContent = item.getAttribute('data-content') || '';

                const matchesCat = (currentCategory === 'all') || (itemCat === currentCategory);
                const matchesSearch = !query || itemTitle.includes(query) || itemContent.includes(query);

                if (matchesCat && matchesSearch) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            const noMatchElem = document.getElementById('noAnnouncementsMatch');
            if (noMatchElem) {
                noMatchElem.style.display = (visibleCount === 0) ? 'block' : 'none';
            }
        }

        function openAnnouncementModal(data) {
            if (!data) return;

            document.getElementById('modalTitle').textContent = data.title || 'Pengumuman';

            // Category badge
            const cat = data.category || 'umum';
            const catBadge = document.getElementById('modalCategoryBadge');
            catBadge.textContent = cat.toUpperCase();
            
            catBadge.className = 'rounded-lg border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ';
            if (cat === 'darurat') catBadge.className += 'bg-rose-500/20 text-rose-300 border-rose-500/40';
            else if (cat === 'penting') catBadge.className += 'bg-amber-500/20 text-amber-300 border-amber-500/40';
            else if (cat === 'akademik') catBadge.className += 'bg-sky-500/20 text-sky-300 border-sky-500/40';
            else if (cat === 'kegiatan') catBadge.className += 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40';
            else catBadge.className += 'bg-purple-500/20 text-purple-300 border-purple-500/40';

            // Target badge
            const targetLabels = {
                'semua': 'Semua Civitas',
                'siswa': 'Khusus Siswa',
                'orang_tua': 'Wali Murid',
                'guru': 'Dewan Guru',
                'staf': 'Staf & Pegawai'
            };
            document.getElementById('modalTargetBadge').textContent = targetLabels[data.target_role] || 'Umum';

            // Pinned badge
            const pinnedBadge = document.getElementById('modalPinnedBadge');
            if (data.is_pinned && data.is_pinned != '0') {
                pinnedBadge.classList.remove('hidden');
            } else {
                pinnedBadge.classList.add('hidden');
            }

            // Date & author
            if (data.created_at) {
                const d = new Date(data.created_at);
                document.getElementById('modalDate').textContent = d.toLocaleDateString('id-ID', {
                    day: 'numeric', month: 'long', year: 'numeric'
                });
            } else {
                document.getElementById('modalDate').textContent = '-';
            }
            document.getElementById('modalAuthor').textContent = data.author_name || 'Admin Sekolah';

            // Content
            document.getElementById('modalContent').innerHTML = data.content || '';

            // Attachment
            const attSection = document.getElementById('modalAttachmentSection');
            const attLink = document.getElementById('modalAttachmentLink');
            const attName = document.getElementById('modalAttachmentName');

            if (data.attachment_url) {
                attSection.classList.remove('hidden');
                attLink.href = 'uploads/announcements/' + data.attachment_url;
                attName.textContent = 'Unduh ' + data.attachment_url;
            } else {
                attSection.classList.add('hidden');
            }

            // Open modal
            document.getElementById('announcementModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeAnnouncementModal() {
            document.getElementById('announcementModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Close on backdrop click
        document.getElementById('announcementModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAnnouncementModal();
            }
        });

        // Close on Esc key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAnnouncementModal();
            }
        });
    </script>

</body>
</html>