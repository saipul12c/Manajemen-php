<?php
/**
 * Halaman Publik Papan Informasi & Mading Digital Sekolah
 * Halaman terpisah mandiri sebelum login (Public Open Access)
 * Terhubung langsung ke modul `announcements` dan `calendar_events`.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

$school_info  = getSchoolSettings($pdo);
$is_logged_in = isset($_SESSION['user_id']);
$user_id      = $is_logged_in ? (int)$_SESSION['user_id'] : 0;
$user_role    = $_SESSION['user_role'] ?? '';
$user_name    = $_SESSION['user_name'] ?? '';

// -------------------------------------------------------------
// 1. STATISTIK SISTEM INFORMASI
// -------------------------------------------------------------
$stat_total_announcements = 0;
$stat_urgent_count        = 0;
$stat_events_count        = 0;
$stat_attachment_count    = 0;

try {
    $stat_total_announcements = (int)$pdo->query("
        SELECT COUNT(*) FROM announcements 
        WHERE status = 'published' AND (expires_at IS NULL OR expires_at > NOW())
    ")->fetchColumn();

    $stat_urgent_count = (int)$pdo->query("
        SELECT COUNT(*) FROM announcements 
        WHERE status = 'published' AND (expires_at IS NULL OR expires_at > NOW())
          AND (category IN ('darurat', 'penting') OR is_pinned = 1)
    ")->fetchColumn();

    $stat_events_count = (int)$pdo->query("
        SELECT COUNT(*) FROM calendar_events 
        WHERE event_date >= CURRENT_DATE()
    ")->fetchColumn();

    $stat_attachment_count = (int)$pdo->query("
        SELECT COUNT(*) FROM announcements 
        WHERE status = 'published' AND attachment_url IS NOT NULL AND attachment_url != ''
    ")->fetchColumn();
} catch (Exception $e) {}

// -------------------------------------------------------------
// 2. QUERY DAFTAR PENGUMUMAN PUBLIK AKTIF
// -------------------------------------------------------------
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
    ");
    $stmt_ann->execute();
    $announcements = $stmt_ann->fetchAll(PDO::FETCH_ASSOC);

    foreach ($announcements as $a) {
        if ($a['is_pinned'] || in_array($a['category'], ['darurat', 'penting'], true)) {
            $urgent_announcements[] = $a;
        }
    }
} catch (Exception $e) {}

// -------------------------------------------------------------
// 3. QUERY AGENDA KEGIATAN MENDATANG
// -------------------------------------------------------------
$upcoming_events = [];
try {
    $stmt_ev = $pdo->prepare("
        SELECT * FROM calendar_events 
        WHERE event_date >= CURRENT_DATE() 
        ORDER BY event_date ASC 
        LIMIT 6
    ");
    $stmt_ev->execute();
    $upcoming_events = $stmt_ev->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id" class="dark scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Papan Informasi & Pengumuman Resmi - <?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?></title>
    <meta name="description" content="Papan informasi digital dan mading pengumuman resmi <?= htmlspecialchars($school_info['school_name'] ?? 'Sekolah') ?>. Menampilkan edaran penting, jadwal ujian, dan kalender kegiatan sekolah.">

    <!-- Tailwind CSS v4 Browser CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
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

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-sky-500 selection:text-white flex flex-col justify-between">

    <?php
    $nav_active = 'informasi';
    require_once __DIR__ . '/includes/navbar.php';
    ?>

    <!-- ================= MAIN CONTENT ================= -->
    <main class="flex-1">

        <!-- HERO HEADER WITH STATS -->
        <section class="relative overflow-hidden border-b border-white/10 bg-gradient-to-b from-sky-950/30 via-slate-950 to-slate-950 py-12 md:py-16">
            <!-- Background Glows -->
            <div class="absolute -top-32 -left-32 h-96 w-96 rounded-full bg-sky-600/15 blur-3xl pointer-events-none"></div>
            <div class="absolute top-1/2 -right-32 h-96 w-96 rounded-full bg-indigo-600/15 blur-3xl pointer-events-none"></div>

            <div class="mx-auto max-w-7xl px-4 sm:px-6 relative z-10">
                <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                    <div>
                        <div class="inline-flex items-center gap-2 rounded-full border border-sky-400/30 bg-sky-400/10 px-3.5 py-1 text-xs text-sky-300 font-semibold mb-3">
                            <i class="fa-solid fa-newspaper text-sky-400"></i>
                            <span>Pusat Publikasi & Mading Digital Sekolah</span>
                        </div>
                        <h1 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight leading-tight">
                            Papan Informasi & Pengumuman
                        </h1>
                        <p class="mt-3 text-sm sm:text-base text-slate-400 max-w-2xl">
                            Saluran komunikasi resmi civitas akademika <?= htmlspecialchars($school_info['school_name'] ?? 'Sekolah') ?>. Menampilkan pengumuman kedinasan, surat edaran penting, jadwal kegiatan, dan informasi akademik terbaru.
                        </p>
                    </div>

                    <!-- Ringkasan Statistik -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 shrink-0">
                        <div class="glass-card rounded-2xl p-3.5 text-center min-w-[100px]">
                            <p class="text-xs text-slate-400 font-medium">Pengumuman</p>
                            <p class="text-xl font-extrabold text-sky-400 mt-0.5"><?= $stat_total_announcements ?></p>
                        </div>
                        <div class="glass-card rounded-2xl p-3.5 text-center min-w-[100px]">
                            <p class="text-xs text-slate-400 font-medium">Penting / Pin</p>
                            <p class="text-xl font-extrabold text-amber-400 mt-0.5"><?= $stat_urgent_count ?></p>
                        </div>
                        <div class="glass-card rounded-2xl p-3.5 text-center min-w-[100px]">
                            <p class="text-xs text-slate-400 font-medium">Agenda</p>
                            <p class="text-xl font-extrabold text-emerald-400 mt-0.5"><?= $stat_events_count ?></p>
                        </div>
                        <div class="glass-card rounded-2xl p-3.5 text-center min-w-[100px]">
                            <p class="text-xs text-slate-400 font-medium">Lampiran File</p>
                            <p class="text-xl font-extrabold text-indigo-400 mt-0.5"><?= $stat_attachment_count ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CONTAINER KONTEN UTAMA -->
        <div class="mx-auto max-w-7xl px-4 sm:px-6 py-10">

            <!-- BANNER HIGHLIGHT PENGUMUMAN PENTING / DARURAT JIKA ADA -->
            <?php if (!empty($urgent_announcements)): ?>
                <?php $top_urgent = $urgent_announcements[0]; ?>
                <div class="mb-10 rounded-3xl border border-rose-500/40 bg-gradient-to-r from-rose-950/60 via-slate-900/90 to-amber-950/40 p-5 sm:p-6 shadow-2xl relative overflow-hidden">
                    <div class="absolute -right-12 -top-12 h-40 w-40 rounded-full bg-rose-500/10 blur-2xl pointer-events-none"></div>

                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 relative z-10">
                        <div class="flex items-start gap-3.5">
                            <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-rose-500/20 text-rose-400 border border-rose-500/30 text-xl">
                                <i class="fa-solid fa-triangle-exclamation animate-bounce"></i>
                            </span>
                            <div>
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="rounded-full bg-rose-500 px-2.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-white">
                                        PENGUMUMAN PENTING & DISIARKAN
                                    </span>
                                    <span class="text-[11px] text-slate-400">
                                        <i class="fa-regular fa-clock mr-1"></i><?= date('d M Y, H:i', strtotime($top_urgent['created_at'])) ?> WIB
                                    </span>
                                </div>
                                <h2 class="text-base sm:text-lg font-bold text-white hover:text-rose-200 transition cursor-pointer" onclick="openAnnouncementModal(<?= htmlspecialchars(json_encode($top_urgent)) ?>)">
                                    <?= htmlspecialchars($top_urgent['title']) ?>
                                </h2>
                                <p class="text-xs sm:text-sm text-slate-300 mt-1 line-clamp-2">
                                    <?= htmlspecialchars(mb_strimwidth(strip_tags($top_urgent['content']), 0, 180, '...')) ?>
                                </p>
                            </div>
                        </div>

                        <button type="button" onclick="openAnnouncementModal(<?= htmlspecialchars(json_encode($top_urgent)) ?>)"
                                class="shrink-0 rounded-xl bg-rose-600 hover:bg-rose-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-rose-600/30 transition flex items-center gap-1.5 cursor-pointer">
                            <span>Baca Lengkap</span>
                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TOOLBAR FILTER & PENCARIAN -->
            <div class="glass-card rounded-2xl p-4 sm:p-5 mb-8">
                <div class="flex flex-col lg:flex-row gap-4 items-center justify-between">
                    
                    <!-- Search Input -->
                    <div class="relative w-full lg:w-96">
                        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
                        <input type="text" id="annSearchInput" onkeyup="filterAnnouncements()" placeholder="Cari judul, kata kunci, atau nomor edaran..."
                               class="w-full rounded-xl border border-white/10 bg-slate-900/90 pl-9 pr-3.5 py-2.5 text-xs sm:text-sm text-white placeholder:text-slate-500 focus:border-sky-500 focus:outline-none transition">
                    </div>

                    <!-- Category Pills -->
                    <div class="flex items-center gap-1.5 overflow-x-auto w-full lg:w-auto pb-1 lg:pb-0 scrollbar-none" id="catFilterButtons">
                        <button type="button" onclick="setCategoryFilter('all', this)"
                                class="cat-pill active-cat px-3 py-1.5 rounded-xl text-xs font-bold transition whitespace-nowrap bg-sky-600 text-white shadow-md shadow-sky-600/20 cursor-pointer">
                            Semua Kategori
                        </button>
                        <button type="button" onclick="setCategoryFilter('darurat', this)"
                                class="cat-pill px-3 py-1.5 rounded-xl text-xs font-semibold transition whitespace-nowrap bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white cursor-pointer">
                            <i class="fa-solid fa-triangle-exclamation text-rose-400 mr-1"></i> Darurat
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

                    <!-- Target Audiens Filter Dropdown -->
                    <div class="w-full lg:w-48 shrink-0">
                        <select id="targetFilterSelect" onchange="filterAnnouncements()"
                                class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs font-medium text-slate-200 focus:border-sky-500 focus:outline-none transition">
                            <option value="all">Semua Sasaran</option>
                            <option value="semua">Semua Civitas</option>
                            <option value="siswa">Khusus Siswa</option>
                            <option value="orang_tua">Wali Murid</option>
                            <option value="guru">Dewan Guru</option>
                            <option value="staf">Staf & Pegawai</option>
                        </select>
                    </div>

                </div>
            </div>

            <!-- GRID UTAMA: LIST PENGUMUMAN & SIDEBAR AGENDA -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

                <!-- LIST KARTU PENGUMUMAN (8 Kolom) -->
                <div class="lg:col-span-8">

                    <?php if (empty($announcements)): ?>
                        <div class="glass-card rounded-3xl p-16 text-center">
                            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-white/5 text-slate-400 text-3xl mb-4">
                                <i class="fa-solid fa-inbox"></i>
                            </div>
                            <h3 class="text-lg font-bold text-white">Belum Ada Pengumuman Terbit</h3>
                            <p class="text-xs text-slate-400 mt-1 max-w-sm mx-auto">
                                Pengumuman resmi sekolah akan ditampilkan di sini saat telah dipublikasikan oleh administrator.
                            </p>
                        </div>
                    <?php else: ?>

                        <div id="announcementsList" class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <?php foreach ($announcements as $ann): 
                                $cat = $ann['category'] ?? 'umum';
                                $target = $ann['target_role'] ?? 'semua';
                                
                                $cat_colors = [
                                    'darurat'  => 'bg-rose-500/15 text-rose-300 border-rose-500/30',
                                    'penting'  => 'bg-amber-500/15 text-amber-300 border-amber-500/30',
                                    'akademik' => 'bg-sky-500/15 text-sky-300 border-sky-500/30',
                                    'kegiatan' => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
                                    'umum'     => 'bg-purple-500/15 text-purple-300 border-purple-500/30',
                                ];
                                $cat_badge_color = $cat_colors[$cat] ?? $cat_colors['umum'];

                                $target_labels = [
                                    'semua'     => 'Semua Civitas',
                                    'siswa'     => 'Khusus Siswa',
                                    'orang_tua' => 'Wali Murid',
                                    'guru'      => 'Dewan Guru',
                                    'staf'      => 'Staf / Pegawai',
                                ];
                                $target_label = $target_labels[$target] ?? 'Umum';
                            ?>
                                <article class="announcement-item glass-card rounded-3xl p-6 hover:border-sky-500/40 hover:bg-slate-900/95 transition duration-300 flex flex-col justify-between group relative"
                                         data-category="<?= htmlspecialchars($cat) ?>"
                                         data-target="<?= htmlspecialchars($target) ?>"
                                         data-title="<?= htmlspecialchars(strtolower($ann['title'])) ?>"
                                         data-content="<?= htmlspecialchars(strtolower(strip_tags($ann['content']))) ?>">

                                    <div>
                                        <!-- Header Badges -->
                                        <div class="flex items-center justify-between gap-2 mb-3.5">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <span class="rounded-lg border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider <?= $cat_badge_color ?>">
                                                    <?= htmlspecialchars(ucfirst($cat)) ?>
                                                </span>
                                                <span class="rounded-lg bg-white/5 border border-white/10 px-2 py-0.5 text-[10px] text-slate-300">
                                                    <?= htmlspecialchars($target_label) ?>
                                                </span>
                                            </div>

                                            <?php if (!empty($ann['is_pinned'])): ?>
                                                <span class="inline-flex items-center gap-1 text-[11px] font-bold text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded-lg border border-amber-500/20" title="Disematkan oleh Pengelola">
                                                    <i class="fa-solid fa-thumbtack text-amber-400 text-xs"></i>
                                                    <span>Pin</span>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Title -->
                                        <h3 class="text-base font-bold text-white group-hover:text-sky-300 transition line-clamp-2 leading-snug cursor-pointer"
                                            onclick="openAnnouncementModal(<?= htmlspecialchars(json_encode($ann)) ?>)">
                                            <?= htmlspecialchars($ann['title']) ?>
                                        </h3>

                                        <!-- Body Snippet -->
                                        <p class="mt-2.5 text-xs text-slate-400 line-clamp-3 leading-relaxed">
                                            <?= htmlspecialchars(mb_strimwidth(strip_tags($ann['content']), 0, 160, '...')) ?>
                                        </p>
                                    </div>

                                    <!-- Footer Meta & Read Action -->
                                    <div class="mt-5 pt-3.5 border-t border-white/5 flex items-center justify-between gap-2">
                                        <div class="text-[11px] text-slate-400 flex items-center gap-2.5">
                                            <span><i class="fa-regular fa-calendar mr-1"></i><?= date('d M Y', strtotime($ann['created_at'])) ?></span>
                                            <?php if (!empty($ann['attachment_url'])): ?>
                                                <span class="text-sky-400 font-semibold inline-flex items-center gap-1 text-[11px]" title="Tersedia berkas lampiran">
                                                    <i class="fa-solid fa-paperclip"></i> Lampiran
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <button type="button" onclick="openAnnouncementModal(<?= htmlspecialchars(json_encode($ann)) ?>)"
                                                class="rounded-xl bg-white/5 group-hover:bg-sky-600/20 border border-white/10 group-hover:border-sky-500/30 px-3 py-1.5 text-xs font-bold text-sky-400 transition inline-flex items-center gap-1 cursor-pointer">
                                            <span>Baca</span>
                                            <i class="fa-solid fa-arrow-right text-[10px] group-hover:translate-x-0.5 transition"></i>
                                        </button>
                                    </div>

                                </article>
                            <?php endforeach; ?>
                        </div>

                        <!-- State Jika Filter Tidak Menemukan Hasil -->
                        <div id="noAnnouncementsMatch" class="hidden glass-card rounded-3xl p-12 text-center">
                            <i class="fa-solid fa-magnifying-glass text-3xl text-slate-500 mb-3"></i>
                            <h4 class="text-sm font-bold text-white">Tidak Ada Pengumuman Ditemukan</h4>
                            <p class="text-xs text-slate-400 mt-1">Coba sesuaikan kata kunci pencarian atau ganti filter kategori/sasaran.</p>
                            <button type="button" onclick="resetFilters()" class="mt-4 rounded-xl bg-sky-600 hover:bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition">
                                Reset Filter
                            </button>
                        </div>

                    <?php endif; ?>

                </div>

                <!-- SIDEBAR AGENDA & PUSAT INFORMASI (4 Kolom) -->
                <div class="lg:col-span-4 space-y-6">

                    <!-- WIDGET AGENDA MENDATANG (KALENDER AKADEMIK) -->
                    <div class="glass-card rounded-3xl p-6 shadow-xl border border-white/10">
                        <div class="flex items-center justify-between border-b border-white/10 pb-3.5 mb-4">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-sm">
                                    <i class="fa-solid fa-calendar-days"></i>
                                </span>
                                <div>
                                    <h3 class="text-sm font-bold text-white">Agenda Mendatang</h3>
                                    <p class="text-[11px] text-slate-400">Kalender Kegiatan Sekolah</p>
                                </div>
                            </div>
                            <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-400">
                                Real-Time
                            </span>
                        </div>

                        <?php if (empty($upcoming_events)): ?>
                            <p class="text-xs text-slate-400 italic text-center py-4">Belum ada agenda terdekat dalam kalender.</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($upcoming_events as $ev): ?>
                                    <div class="flex items-start gap-3 rounded-2xl bg-slate-900/60 border border-white/5 p-3.5 hover:border-emerald-500/30 transition">
                                        <div class="flex flex-col items-center justify-center rounded-xl bg-emerald-600/15 border border-emerald-500/30 px-3 py-1.5 text-center shrink-0">
                                            <span class="text-[10px] font-bold uppercase text-emerald-400"><?= date('M', strtotime($ev['event_date'])) ?></span>
                                            <span class="text-base font-extrabold text-white leading-none"><?= date('d', strtotime($ev['event_date'])) ?></span>
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

                    <!-- WIDGET LAYANAN TATA USAHA & HOTLINE -->
                    <div class="glass-card rounded-3xl p-6 shadow-xl border border-white/10">
                        <div class="flex items-center gap-2.5 border-b border-white/10 pb-3.5 mb-4">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-500/20 text-sky-400 text-sm">
                                <i class="fa-solid fa-headset"></i>
                            </span>
                            <div>
                                <h3 class="text-sm font-bold text-white">Hotline & Tata Usaha</h3>
                                <p class="text-[11px] text-slate-400">Pusat Layanan Informasi</p>
                            </div>
                        </div>

                        <div class="space-y-3.5 text-xs text-slate-300">
                            <div class="flex items-start gap-2.5">
                                <i class="fa-regular fa-clock text-slate-500 mt-0.5"></i>
                                <div>
                                    <p class="font-semibold text-white">Jam Layanan Sekolah:</p>
                                    <p class="text-[11px] text-slate-400">Senin - Jumat: 07:00 - 15:30 WIB</p>
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
                                    <p class="font-semibold text-white">Email Lembaga:</p>
                                    <p class="text-[11px] text-slate-400"><?= htmlspecialchars($school_info['school_email']) ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Tombol Masuk Portal -->
                        <div class="mt-5 pt-4 border-t border-white/10">
                            <p class="text-[11px] text-slate-400 mb-3">Siswa & orang tua ingin mengakses nilai, tugas, dan materi?</p>
                            <a href="auth/login.php" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-sky-600 hover:bg-sky-500 py-2.5 text-xs font-bold text-white shadow-lg shadow-sky-600/20 transition">
                                <i class="fa-solid fa-right-to-bracket text-xs"></i>
                                <span>Login ke Portal Sekolah</span>
                            </a>
                        </div>
                    </div>

                    <!-- WIDGET SHORTCUT PERPUSTAKAAN & PPDB -->
                    <div class="glass-card rounded-3xl p-6 shadow-xl border border-white/10 space-y-3">
                        <a href="perpustakaan.php" class="block rounded-2xl bg-slate-900/80 border border-indigo-500/20 p-3.5 hover:border-indigo-500/40 hover:bg-slate-900 transition group">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600/20 text-indigo-400 group-hover:scale-105 transition">
                                        <i class="fa-solid fa-book-open-reader"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-white group-hover:text-indigo-300 transition">Perpustakaan Digital</p>
                                        <p class="text-[11px] text-slate-400">Katalog Buku & E-Book</p>
                                    </div>
                                </div>
                                <span class="text-xs text-indigo-400 font-bold group-hover:translate-x-1 transition">Buka →</span>
                            </div>
                        </a>

                        <a href="ppdb/ppdb.php" class="block rounded-2xl bg-slate-900/80 border border-emerald-500/20 p-3.5 hover:border-emerald-500/40 hover:bg-slate-900 transition group">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-600/20 text-emerald-400 group-hover:scale-105 transition">
                                        <i class="fa-solid fa-graduation-cap"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold text-white group-hover:text-emerald-300 transition">PPDB Online</p>
                                        <p class="text-[11px] text-slate-400">Pendaftaran Peserta Didik Baru</p>
                                    </div>
                                </div>
                                <span class="text-xs text-emerald-400 font-bold group-hover:translate-x-1 transition">Daftar →</span>
                            </div>
                        </a>
                    </div>

                </div>

            </div>

        </div>

    </main>

    <!-- ================= MODAL DETAIL PENGUMUMAN ================= -->
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
                    <i class="fa-solid fa-print"></i> Cetak Dokumen
                </button>
                <button type="button" onclick="closeAnnouncementModal()" class="rounded-xl bg-sky-600 hover:bg-sky-500 px-5 py-2 text-xs font-bold text-white transition cursor-pointer">
                    Tutup
                </button>
            </div>

        </div>
    </div>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    ?>

    <!-- JAVASCRIPT FILTER & MODAL -->
    <script>
        let currentCategory = 'all';

        function setCategoryFilter(category, button) {
            currentCategory = category;

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
            const targetFilter = document.getElementById('targetFilterSelect')?.value || 'all';
            const items = document.querySelectorAll('.announcement-item');
            let visibleCount = 0;

            items.forEach(item => {
                const itemCat = item.getAttribute('data-category') || 'umum';
                const itemTarget = item.getAttribute('data-target') || 'semua';
                const itemTitle = item.getAttribute('data-title') || '';
                const itemContent = item.getAttribute('data-content') || '';

                const matchesCat = (currentCategory === 'all') || (itemCat === currentCategory);
                const matchesTarget = (targetFilter === 'all') || (itemTarget === targetFilter);
                const matchesSearch = !query || itemTitle.includes(query) || itemContent.includes(query);

                if (matchesCat && matchesTarget && matchesSearch) {
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

        function resetFilters() {
            const searchInput = document.getElementById('annSearchInput');
            if (searchInput) searchInput.value = '';

            const targetSelect = document.getElementById('targetFilterSelect');
            if (targetSelect) targetSelect.value = 'all';

            const firstPill = document.querySelector('.cat-pill');
            if (firstPill) setCategoryFilter('all', firstPill);
        }

        function openAnnouncementModal(data) {
            if (!data) return;

            document.getElementById('modalTitle').textContent = data.title || 'Pengumuman';

            const cat = data.category || 'umum';
            const catBadge = document.getElementById('modalCategoryBadge');
            catBadge.textContent = cat.toUpperCase();
            
            catBadge.className = 'rounded-lg border px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ';
            if (cat === 'darurat') catBadge.className += 'bg-rose-500/20 text-rose-300 border-rose-500/40';
            else if (cat === 'penting') catBadge.className += 'bg-amber-500/20 text-amber-300 border-amber-500/40';
            else if (cat === 'akademik') catBadge.className += 'bg-sky-500/20 text-sky-300 border-sky-500/40';
            else if (cat === 'kegiatan') catBadge.className += 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40';
            else catBadge.className += 'bg-purple-500/20 text-purple-300 border-purple-500/40';

            const targetLabels = {
                'semua': 'Semua Civitas',
                'siswa': 'Khusus Siswa',
                'orang_tua': 'Wali Murid',
                'guru': 'Dewan Guru',
                'staf': 'Staf & Pegawai'
            };
            document.getElementById('modalTargetBadge').textContent = targetLabels[data.target_role] || 'Umum';

            const pinnedBadge = document.getElementById('modalPinnedBadge');
            if (data.is_pinned && data.is_pinned != '0') {
                pinnedBadge.classList.remove('hidden');
            } else {
                pinnedBadge.classList.add('hidden');
            }

            if (data.created_at) {
                const d = new Date(data.created_at);
                document.getElementById('modalDate').textContent = d.toLocaleDateString('id-ID', {
                    day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit'
                }) + ' WIB';
            } else {
                document.getElementById('modalDate').textContent = '-';
            }
            document.getElementById('modalAuthor').textContent = data.author_name || 'Admin Sekolah';

            document.getElementById('modalContent').innerHTML = data.content || '';

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

            document.getElementById('announcementModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeAnnouncementModal() {
            document.getElementById('announcementModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        document.getElementById('announcementModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeAnnouncementModal();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAnnouncementModal();
            }
        });
    </script>

</body>
</html>
