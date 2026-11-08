<?php
/**
 * Papan Informasi & Mading Digital Sekolah (Smart TV & Digital Signage Mode)
 * Manajemen-PHP — Sistem Terpadu Manajemen Sekolah
 * 
 * Dirancang khusus untuk layar TV / Monitor Lobi Sekolah:
 * - Tampilan 100% Layar Penuh (16:9 / 1080p / 4K) tanpa scrollbar terpotong
 * - Rotasi Pengumuman Otomatis (Auto-Slideshow) dengan Progress Timer
 * - Auto-Scroll Halus untuk Pengumuman Berisi Teks Panjang (Anti-Terpotong)
 * - Jam Digital Real-Time & Kalender Bahasa Indonesia
 * - Running Text Ticker (Teks Berjalan Berita Kilat & Darurat)
 * - Panel Agenda Kegiatan & Liputan Terkini
 * - Wake Lock API (Mencegah Layar TV Masuk Mode Tidur / Sleep)
 * - Auto Data Refresh di Latar Belakang (Sinkronisasi Konten Terbaru)
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';

$school_info = getSchoolSettings($pdo);

// -------------------------------------------------------------
// 1. QUERY PENGUMUMAN AKTIF (Diprioritaskan Pin & Tanggal Rilis)
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
        LIMIT 15
    ");
    $stmt_ann->execute();
    $announcements = $stmt_ann->fetchAll(PDO::FETCH_ASSOC);

    foreach ($announcements as $a) {
        if ($a['is_pinned'] || in_array($a['category'], ['darurat', 'penting'], true)) {
            $urgent_announcements[] = $a;
        }
    }
} catch (Exception $e) {
    error_log("Signage query error: " . $e->getMessage());
}

// -------------------------------------------------------------
// 2. QUERY AGENDA KEGIATAN MENDATANG (Kalender Sekolah)
// -------------------------------------------------------------
$upcoming_events = [];
try {
    $stmt_ev = $pdo->prepare("
        SELECT * FROM calendar_events 
        WHERE event_date >= CURRENT_DATE() 
        ORDER BY event_date ASC 
        LIMIT 4
    ");
    $stmt_ev->execute();
    $upcoming_events = $stmt_ev->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// -------------------------------------------------------------
// 3. QUERY KABAR & BERITA TERKINI
// -------------------------------------------------------------
$latest_news = [];
try {
    $stmt_news = $pdo->prepare("
        SELECT n.*, u.name as author_name
        FROM news n
        JOIN users u ON n.author_id = u.id
        WHERE n.status = 'published'
        ORDER BY n.is_featured DESC, n.published_at DESC
        LIMIT 3
    ");
    $stmt_news->execute();
    $latest_news = $stmt_news->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Siapkan pesan teks berjalan untuk Running Text Ticker di bawah
$ticker_items = [];
if (!empty($urgent_announcements)) {
    foreach ($urgent_announcements as $ua) {
        $ticker_items[] = '🚨 PENTING: ' . htmlspecialchars($ua['title']) . ' — ' . htmlspecialchars(mb_strimwidth(strip_tags($ua['content']), 0, 140, '...'));
    }
}
if (!empty($upcoming_events)) {
    foreach ($upcoming_events as $ev) {
        $ticker_items[] = '📅 AGENDA: ' . htmlspecialchars($ev['title']) . ' (' . date('d M Y', strtotime($ev['event_date'])) . ($ev['location'] ? ' di ' . htmlspecialchars($ev['location']) : '') . ')';
    }
}
$ticker_items[] = '🏛️ ' . htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') . ' — Sistem Informasi & Manajemen Akademik Terpadu';
if (!empty($school_info['school_address'])) {
    $ticker_items[] = '📍 ' . htmlspecialchars($school_info['school_address']);
}
?>
<!DOCTYPE html>
<html lang="id" class="dark select-none">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mading Digital & TV Signage Sekolah - <?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?></title>
    
    <!-- Google Fonts: Plus Jakarta Sans & JetBrains Mono -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    
    <!-- Tailwind CSS v4 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            overflow: hidden;
            user-select: none;
            -webkit-user-select: none;
        }
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
        /* Background Cyber Mesh */
        .signage-bg {
            background-color: #020617;
            background-image: 
                radial-gradient(circle at 15% 20%, rgba(56, 189, 248, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 85% 75%, rgba(99, 102, 241, 0.08) 0%, transparent 40%),
                radial-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 28px 28px;
        }
        /* Smooth Slide Transitions */
        .slide-pane {
            transition: opacity 0.6s ease-in-out, transform 0.6s ease-in-out;
        }
        .slide-pane.hidden-slide {
            opacity: 0;
            transform: scale(0.98);
            pointer-events: none;
            position: absolute;
            inset: 0;
        }
        .slide-pane.active-slide {
            opacity: 1;
            transform: scale(1);
            position: relative;
        }
        /* Auto-scroll container custom scrollbar hidden */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        /* Marquee Ticker Keyframes */
        @keyframes tickerMove {
            0% { transform: translateX(100vw); }
            100% { transform: translateX(-100%); }
        }
        .animate-ticker {
            display: inline-block;
            white-space: nowrap;
            animation: tickerMove 40s linear infinite;
        }
        .animate-ticker:hover {
            animation-play-state: paused;
        }
    </style>
</head>
<body class="h-screen w-screen signage-bg text-slate-100 flex flex-col justify-between overflow-hidden relative selection:bg-sky-500 selection:text-white">

    <!-- Ambient Glowing Orbs -->
    <div class="pointer-events-none fixed inset-0 overflow-hidden z-0">
        <div class="absolute -top-32 -left-32 h-[450px] w-[450px] rounded-full bg-sky-600/10 blur-[130px]"></div>
        <div class="absolute -bottom-32 -right-32 h-[450px] w-[450px] rounded-full bg-indigo-600/10 blur-[130px]"></div>
    </div>

    <!-- ============================================================== -->
    <!-- 1. HEADER BAR: IDENTITAS SEKOLAH, JAM REAL-TIME & LIVE BADGE  -->
    <!-- ============================================================== -->
    <header class="relative z-20 border-b border-white/10 bg-slate-950/80 backdrop-blur-2xl px-6 py-3.5 shrink-0 flex items-center justify-between shadow-2xl">
        <!-- Logo & Nama Sekolah -->
        <div class="flex items-center gap-3.5">
            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-tr from-sky-600 to-indigo-600 text-white shadow-lg shadow-sky-500/25 border border-white/10">
                <i class="fa-solid fa-graduation-cap text-lg"></i>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-base sm:text-lg font-black tracking-tight text-white leading-tight">
                        <?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?>
                    </h1>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-[10px] font-bold text-emerald-400 uppercase tracking-wider">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-ping"></span>
                        LIVE SIGNAGE
                    </span>
                </div>
                <p class="text-xs text-slate-400 flex items-center gap-2 mt-0.5">
                    <span>Papan Informasi & Pengumuman Resmi Sekolah</span>
                    <span class="text-slate-600">•</span>
                    <span class="text-slate-400 font-medium truncate max-w-sm"><?= htmlspecialchars($school_info['school_address'] ?? 'Pusat Informasi Digital') ?></span>
                </p>
            </div>
        </div>

        <!-- Jam Digital Real-time & Kontrol Signage -->
        <div class="flex items-center gap-4">
            <!-- Tanggal & Hari Bahasa Indonesia -->
            <div class="hidden md:flex flex-col text-right">
                <span class="text-xs font-bold text-slate-200" id="liveDateText">Memuat tanggal...</span>
                <span class="text-[10px] text-slate-400 uppercase font-mono tracking-wider">Zona Waktu Indonesia</span>
            </div>

            <!-- Jam Digital dengan Detik -->
            <div class="flex items-center gap-2 rounded-2xl border border-emerald-500/30 bg-slate-900/90 px-4 py-2 font-mono shadow-inner">
                <i class="fa-regular fa-clock text-emerald-400 text-sm"></i>
                <div class="text-lg sm:text-2xl font-black text-emerald-400 tracking-wider" id="liveClockText">
                    00:00:00
                </div>
                <span class="text-[10px] font-bold text-slate-400">WIB</span>
            </div>

            <!-- Toolbar Kontrol Layar (Fullscreen, Pause, Switch to Web) -->
            <div class="flex items-center gap-1.5 bg-white/5 border border-white/10 rounded-2xl p-1">
                <!-- Tombol Play / Pause Slideshow -->
                <button type="button" id="togglePlayBtn" title="Jeda / Lanjutkan Slideshow (Spasi)"
                        class="h-8 w-8 rounded-xl flex items-center justify-center text-slate-300 hover:text-white hover:bg-white/10 transition cursor-pointer">
                    <i class="fa-solid fa-pause text-xs" id="playIcon"></i>
                </button>

                <!-- Tombol Fullscreen Layar Penuh -->
                <button type="button" id="toggleFullscreenBtn" title="Layar Penuh TV (F11 / F)"
                        class="h-8 w-8 rounded-xl flex items-center justify-center text-slate-300 hover:text-white hover:bg-white/10 transition cursor-pointer">
                    <i class="fa-solid fa-expand text-xs" id="fsIcon"></i>
                </button>

                <!-- Tombol Buka Mode Web Biasa -->
                <a href="informasi.php" title="Buka Mode Halaman Web Publik"
                   class="h-8 px-2.5 rounded-xl flex items-center gap-1.5 text-xs text-slate-400 hover:text-white hover:bg-white/10 transition">
                    <i class="fa-solid fa-globe text-xs"></i>
                    <span class="hidden lg:inline text-[11px] font-semibold">Mode Web</span>
                </a>
            </div>
        </div>
    </header>

    <!-- ============================================================== -->
    <!-- 2. MAIN SIGNAGE CANVAS (PANGGUNG SLIDE PENGUMUMAN + SIDEBAR)  -->
    <!-- ============================================================== -->
    <main class="relative z-10 flex-1 px-6 py-4 overflow-hidden flex gap-5">

        <!-- ======================================================== -->
        <!-- KOLOM KIRI (70%): PANGGUNG SLIDE PENGUMUMAN UTAMA       -->
        <!-- ======================================================== -->
        <section class="flex-1 h-full relative rounded-3xl border border-white/10 bg-slate-900/70 backdrop-blur-2xl p-6 sm:p-8 flex flex-col justify-between shadow-2xl overflow-hidden">
            
            <!-- Slide Container Stage -->
            <div class="relative flex-1 overflow-hidden" id="slideStage">
                <?php if (empty($announcements)): ?>
                    <!-- State Kosong Jika Belum Ada Pengumuman -->
                    <div class="h-full flex flex-col items-center justify-center text-center p-8">
                        <div class="h-20 w-20 rounded-3xl bg-white/5 border border-white/10 flex items-center justify-center text-4xl text-slate-500 mb-4">
                            <i class="fa-solid fa-bullhorn"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-white">Belum Ada Pengumuman Aktif</h2>
                        <p class="text-sm text-slate-400 mt-2 max-w-md">
                            Seluruh pengumuman kedinasan dan edaran penting sekolah akan disiarkan secara otomatis pada papan digital ini.
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($announcements as $idx => $ann): 
                        // Kategori Badge Styling
                        $cat = $ann['category'] ?? 'umum';
                        $cat_badge = 'bg-sky-500/20 text-sky-300 border-sky-500/30';
                        $cat_label = 'PENGUMUMAN UMUM';
                        if ($cat === 'darurat') {
                            $cat_badge = 'bg-rose-500/25 text-rose-300 border-rose-500/40 animate-pulse';
                            $cat_label = '🚨 PENGUMUMAN DARURAT';
                        } elseif ($cat === 'penting' || $ann['is_pinned']) {
                            $cat_badge = 'bg-amber-500/25 text-amber-300 border-amber-500/40';
                            $cat_label = '⭐ PENGUMUMAN PENTING';
                        } elseif ($cat === 'akademik') {
                            $cat_badge = 'bg-blue-500/20 text-blue-300 border-blue-500/30';
                            $cat_label = '📚 AKADEMIK & UJIAN';
                        } elseif ($cat === 'kegiatan') {
                            $cat_badge = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
                            $cat_label = '🎪 KEGIATAN SISWA';
                        }
                    ?>
                        <div class="slide-pane <?= $idx === 0 ? 'active-slide' : 'hidden-slide' ?> h-full flex flex-col justify-between"
                             data-index="<?= $idx ?>">
                            
                            <!-- Slide Top Meta -->
                            <div class="flex items-center justify-between pb-3 border-b border-white/10 mb-4">
                                <div class="flex items-center gap-2.5 flex-wrap">
                                    <span class="rounded-full border px-3 py-1 text-xs font-extrabold uppercase tracking-wide <?= $cat_badge ?>">
                                        <?= $cat_label ?>
                                    </span>
                                    <span class="text-xs text-slate-400">
                                        <i class="fa-solid fa-users mr-1 opacity-70"></i>
                                        Untuk: <strong class="text-slate-200 uppercase"><?= htmlspecialchars($ann['target_role'] ?? 'Semua') ?></strong>
                                    </span>
                                    <span class="text-slate-600">•</span>
                                    <span class="text-xs text-slate-400">
                                        <i class="fa-regular fa-calendar mr-1 opacity-70"></i>
                                        <?= date('d F Y, H:i', strtotime($ann['created_at'])) ?> WIB
                                    </span>
                                </div>

                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-xs font-bold text-sky-400 bg-sky-500/10 border border-sky-500/20 px-3 py-1 rounded-full">
                                        Slide <?= $idx + 1 ?> / <?= count($announcements) ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Slide Content (Didesain Besar & Jelas Terbaca dari Kejauhan) -->
                            <div class="flex-1 overflow-y-auto no-scrollbar space-y-4 pr-2 scroll-content-box" id="scrollBox_<?= $idx ?>">
                                <h2 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white tracking-tight leading-tight">
                                    <?= htmlspecialchars($ann['title']) ?>
                                </h2>

                                <!-- Body Text & Formatting -->
                                <div class="text-base sm:text-lg text-slate-300 leading-relaxed space-y-3 font-normal whitespace-pre-line">
                                    <?= nl2br(htmlspecialchars($ann['content'])) ?>
                                </div>

                                <!-- Lampiran / Attachment Preview jika ada -->
                                <?php if (!empty($ann['attachment_url'])): ?>
                                    <div class="mt-4 p-4 rounded-2xl border border-white/10 bg-slate-950/60 flex items-center justify-between max-w-lg">
                                        <div class="flex items-center gap-3">
                                            <div class="h-10 w-10 rounded-xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center text-lg">
                                                <i class="fa-solid fa-paperclip"></i>
                                            </div>
                                            <div>
                                                <strong class="text-xs sm:text-sm text-white block truncate max-w-xs">Berkas Lampiran Resmi</strong>
                                                <span class="text-[11px] text-slate-400 font-mono"><?= htmlspecialchars(basename($ann['attachment_url'])) ?></span>
                                            </div>
                                        </div>
                                        <span class="text-xs text-emerald-400 font-semibold flex items-center gap-1">
                                            <i class="fa-solid fa-circle-check"></i> Tersedia di Portal
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Slide Footer Meta (Author & Verification) -->
                            <div class="pt-4 border-t border-white/5 flex items-center justify-between text-xs text-slate-400 mt-2">
                                <div class="flex items-center gap-2">
                                    <span class="h-7 w-7 rounded-lg bg-white/10 flex items-center justify-center text-slate-300 font-bold">
                                        <i class="fa-solid fa-signature"></i>
                                    </span>
                                    <span>Diterbitkan oleh: <strong class="text-slate-200"><?= htmlspecialchars($ann['author_name'] ?? 'Sekretariat Sekolah') ?></strong> (<?= htmlspecialchars(ucfirst($ann['author_role'] ?? 'Administrator')) ?>)</span>
                                </div>
                                <span class="text-[11px] text-slate-500 font-mono">ID: ANN-<?= str_pad((string)$ann['id'], 4, '0', STR_PAD_LEFT) ?></span>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Slide Progress Timer Bar & Navigasi Cepat -->
            <div class="pt-4 mt-2 border-t border-white/10 flex items-center justify-between gap-4">
                <!-- Tombol Prev -->
                <button type="button" id="btnPrevSlide" title="Pengumuman Sebelumnya"
                        class="h-9 w-9 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 flex items-center justify-center text-slate-300 hover:text-white transition cursor-pointer">
                    <i class="fa-solid fa-chevron-left text-xs"></i>
                </button>

                <!-- Progress Bar Timer -->
                <div class="flex-1 bg-slate-950 rounded-full h-2 overflow-hidden border border-white/10 relative">
                    <div id="slideTimerBar" class="h-full bg-gradient-to-r from-sky-500 via-indigo-500 to-emerald-400 rounded-full transition-all ease-linear" style="width: 0%;"></div>
                </div>

                <!-- Tombol Next -->
                <button type="button" id="btnNextSlide" title="Pengumuman Berikutnya"
                        class="h-9 w-9 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 flex items-center justify-center text-slate-300 hover:text-white transition cursor-pointer">
                    <i class="fa-solid fa-chevron-right text-xs"></i>
                </button>
            </div>
        </section>

        <!-- ======================================================== -->
        <!-- KOLOM KANAN (30%): KALENDER AGENDA & BERITA UTAMA        -->
        <!-- ======================================================== -->
        <aside class="w-80 lg:w-96 h-full flex flex-col gap-4 shrink-0">
            
            <!-- Panel 1: Agenda Kegiatan Mendatang (Calendar Events) -->
            <div class="flex-1 rounded-3xl border border-white/10 bg-slate-900/70 backdrop-blur-2xl p-5 flex flex-col justify-between shadow-2xl overflow-hidden">
                <div>
                    <div class="flex items-center justify-between pb-3 border-b border-white/10 mb-3">
                        <div class="flex items-center gap-2">
                            <span class="flex h-7 w-7 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-400 text-xs">
                                <i class="fa-solid fa-calendar-days"></i>
                            </span>
                            <h3 class="text-xs sm:text-sm font-bold text-white uppercase tracking-wider">
                                Agenda Kegiatan Terdekat
                            </h3>
                        </div>
                        <span class="text-[10px] text-slate-400 font-mono">SEKOLAH</span>
                    </div>

                    <?php if (empty($upcoming_events)): ?>
                        <div class="py-6 text-center text-xs text-slate-500">
                            <i class="fa-regular fa-calendar-xmark text-xl mb-1.5 block"></i>
                            Belum ada jadwal kegiatan mendatang yang diagendakan.
                        </div>
                    <?php else: ?>
                        <div class="space-y-2.5">
                            <?php foreach ($upcoming_events as $ev): 
                                $evDate = strtotime($ev['event_date']);
                                $dayNum = date('d', $evDate);
                                $monthName = date('M', $evDate);
                            ?>
                                <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-2.5 flex items-center gap-3">
                                    <!-- Date Badge Box -->
                                    <div class="h-11 w-11 shrink-0 rounded-xl bg-gradient-to-b from-emerald-500/20 to-teal-500/10 border border-emerald-500/30 flex flex-col items-center justify-center text-center">
                                        <span class="text-xs font-black text-emerald-400 font-mono leading-none"><?= $dayNum ?></span>
                                        <span class="text-[9px] font-bold text-slate-400 uppercase leading-none mt-0.5"><?= $monthName ?></span>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <strong class="text-xs text-white block truncate leading-tight"><?= htmlspecialchars($ev['title']) ?></strong>
                                        <div class="flex items-center gap-2 text-[10px] text-slate-400 mt-1">
                                            <?php if (!empty($ev['event_time'])): ?>
                                                <span><i class="fa-regular fa-clock mr-1"></i><?= htmlspecialchars($ev['event_time']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($ev['location'])): ?>
                                                <span class="truncate"><i class="fa-solid fa-location-dot mr-1"></i><?= htmlspecialchars($ev['location']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pt-2 border-t border-white/5 text-[10px] text-slate-500 flex items-center justify-between">
                    <span>Diperbarui otomatis dari kalender akademik</span>
                    <i class="fa-solid fa-circle-check text-emerald-400"></i>
                </div>
            </div>

            <!-- Panel 2: Sekilas Berita Sekolah & Layanan Siswa -->
            <div class="h-48 rounded-3xl border border-white/10 bg-slate-900/70 backdrop-blur-2xl p-5 flex flex-col justify-between shadow-2xl overflow-hidden">
                <div class="flex items-center justify-between pb-2.5 border-b border-white/10">
                    <div class="flex items-center gap-2">
                        <span class="flex h-7 w-7 items-center justify-center rounded-xl bg-sky-500/10 text-sky-400 text-xs">
                            <i class="fa-solid fa-newspaper"></i>
                        </span>
                        <h3 class="text-xs font-bold text-white uppercase tracking-wider">
                            Kabar Terkini Sekolah
                        </h3>
                    </div>
                    <span class="text-[10px] text-sky-400 font-mono">BERITA</span>
                </div>

                <?php if (!empty($latest_news)): ?>
                    <?php $top_news = $latest_news[0]; ?>
                    <div class="my-auto py-1">
                        <strong class="text-xs font-bold text-white block line-clamp-2 leading-snug">
                            <?= htmlspecialchars($top_news['title']) ?>
                        </strong>
                        <p class="text-[11px] text-slate-400 line-clamp-2 mt-1">
                            <?= htmlspecialchars(mb_strimwidth(strip_tags($top_news['content']), 0, 110, '...')) ?>
                        </p>
                    </div>
                <?php else: ?>
                    <p class="text-xs text-slate-500 my-auto text-center">Belum ada liputan berita terbaru.</p>
                <?php endif; ?>

                <div class="pt-2 border-t border-white/5 flex items-center justify-between text-[10px] text-slate-400">
                    <span>Akses Berita Lengkap: <strong class="text-white">berita.php</strong></span>
                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px] opacity-70"></i>
                </div>
            </div>

        </aside>

    </main>

    <!-- ============================================================== -->
    <!-- 3. FOOTER: RUNNING TEXT TICKER (SIARAN KILAT & DARURAT)        -->
    <!-- ============================================================== -->
    <footer class="relative z-20 border-t border-white/10 bg-slate-950/95 backdrop-blur-2xl h-12 shrink-0 flex items-center overflow-hidden shadow-2xl">
        <!-- Ticker Badge Label -->
        <div class="flex items-center gap-2 bg-gradient-to-r from-rose-600 to-amber-600 px-5 h-full text-xs font-extrabold text-white uppercase tracking-wider shrink-0 z-10 shadow-lg">
            <i class="fa-solid fa-bolt text-yellow-300 animate-bounce"></i>
            <span>WARTA KILAT</span>
        </div>

        <!-- Marquee Track Running Text -->
        <div class="flex-1 overflow-hidden relative flex items-center">
            <div class="animate-ticker text-xs font-semibold text-slate-200 space-x-12">
                <?php foreach ($ticker_items as $item): ?>
                    <span class="inline-flex items-center gap-2">
                        <span><?= $item ?></span>
                        <span class="text-slate-600">✦</span>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Network / Sync Status Indicator -->
        <div class="hidden sm:flex items-center gap-2 px-4 h-full bg-slate-900/80 border-l border-white/10 shrink-0 text-[11px] text-slate-400 font-mono">
            <span class="h-2 w-2 rounded-full bg-emerald-400 animate-ping"></span>
            <span id="syncText">SYNC OK</span>
        </div>
    </footer>

    <!-- ============================================================== -->
    <!-- 4. SIGNAGE JAVASCRIPT ENGINE (AUTO-ROTATE, SCROLL, WAKELOCK)   -->
    <!-- ============================================================== -->
    <script>
    (function() {
        // -------------------------------------------------------------
        // A. DIGITAL CLOCK & INDONESIAN DATE
        // -------------------------------------------------------------
        const liveClockText = document.getElementById('liveClockText');
        const liveDateText = document.getElementById('liveDateText');
        const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        const months = [
            'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
        ];

        function updateClock() {
            const now = new Date();
            const h = String(now.getHours()).padStart(2, '0');
            const m = String(now.getMinutes()).padStart(2, '0');
            const s = String(now.getSeconds()).padStart(2, '0');
            if (liveClockText) liveClockText.textContent = `${h}:${m}:${s}`;

            if (liveDateText) {
                const dayName = days[now.getDay()];
                const dateNum = now.getDate();
                const monthName = months[now.getMonth()];
                const yearNum = now.getFullYear();
                liveDateText.textContent = `${dayName}, ${dateNum} ${monthName} ${yearNum}`;
            }
        }
        setInterval(updateClock, 1000);
        updateClock();

        // -------------------------------------------------------------
        // B. SCREEN WAKE LOCK API (Cegah TV Sleep / Layar Mati)
        // -------------------------------------------------------------
        let wakeLock = null;
        async function requestWakeLock() {
            try {
                if ('wakeLock' in navigator) {
                    wakeLock = await navigator.wakeLock.request('screen');
                    wakeLock.addEventListener('release', () => {
                        console.log('Screen WakeLock dilepas');
                    });
                }
            } catch (err) {
                console.log('WakeLock tidak didukung atau dicegah:', err);
            }
        }
        requestWakeLock();
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                requestWakeLock();
            }
        });

        // -------------------------------------------------------------
        // C. SLIDESHOW ROTATION ENGINE DENGAN AUTO-SCROLL ANTI-TERPOTONG
        // -------------------------------------------------------------
        const slides = document.querySelectorAll('#slideStage .slide-pane');
        const totalSlides = slides.length;
        let currentSlideIdx = 0;
        let isPaused = false;
        const SLIDE_DURATION = 14000; // 14 detik per slide pengumuman
        let timerInterval = null;
        let elapsed = 0;
        const timerStep = 100;
        const slideTimerBar = document.getElementById('slideTimerBar');

        function showSlide(idx) {
            if (totalSlides === 0) return;
            if (idx < 0) idx = totalSlides - 1;
            if (idx >= totalSlides) idx = 0;
            currentSlideIdx = idx;

            slides.forEach((slide, i) => {
                const scrollBox = slide.querySelector('.scroll-content-box');
                if (i === currentSlideIdx) {
                    slide.className = 'slide-pane active-slide h-full flex flex-col justify-between';
                    // Reset scroll position ke atas
                    if (scrollBox) scrollBox.scrollTop = 0;
                } else {
                    slide.className = 'slide-pane hidden-slide h-full flex flex-col justify-between';
                }
            });

            // Reset Timer Bar
            elapsed = 0;
            if (slideTimerBar) slideTimerBar.style.width = '0%';

            // Cek jika teks sangat panjang, jalankan auto-scroll perlahan
            startAutoScrollForCurrentSlide();
        }

        // Auto-scroll lembut untuk konten pengumuman panjang
        let autoScrollInterval = null;
        function startAutoScrollForCurrentSlide() {
            if (autoScrollInterval) clearInterval(autoScrollInterval);
            const activeSlide = slides[currentSlideIdx];
            if (!activeSlide) return;
            const scrollBox = activeSlide.querySelector('.scroll-content-box');
            if (!scrollBox) return;

            setTimeout(() => {
                const scrollable = scrollBox.scrollHeight - scrollBox.clientHeight;
                if (scrollable > 20) {
                    // Beri jeda 3 detik membaca bagian atas sebelum mulai scroll
                    let scrollPos = 0;
                    autoScrollInterval = setInterval(() => {
                        if (isPaused) return;
                        if (scrollPos < scrollable) {
                            scrollPos += 1;
                            scrollBox.scrollTop = scrollPos;
                        } else {
                            clearInterval(autoScrollInterval);
                        }
                    }, 50); // Kecepatan scroll perlahan yang nyaman dibaca di layar TV
                }
            }, 3000);
        }

        function startTimer() {
            if (totalSlides <= 1) return;
            clearInterval(timerInterval);
            timerInterval = setInterval(() => {
                if (!isPaused) {
                    elapsed += timerStep;
                    const pct = Math.min(100, (elapsed / SLIDE_DURATION) * 100);
                    if (slideTimerBar) slideTimerBar.style.width = pct + '%';

                    if (elapsed >= SLIDE_DURATION) {
                        showSlide(currentSlideIdx + 1);
                    }
                }
            }, timerStep);
        }

        // Tombol Navigasi Manual
        const btnPrev = document.getElementById('btnPrevSlide');
        const btnNext = document.getElementById('btnNextSlide');
        if (btnPrev) {
            btnPrev.addEventListener('click', () => {
                showSlide(currentSlideIdx - 1);
            });
        }
        if (btnNext) {
            btnNext.addEventListener('click', () => {
                showSlide(currentSlideIdx + 1);
            });
        }

        // Tombol Play / Pause
        const togglePlayBtn = document.getElementById('togglePlayBtn');
        const playIcon = document.getElementById('playIcon');
        if (togglePlayBtn && playIcon) {
            togglePlayBtn.addEventListener('click', () => {
                isPaused = !isPaused;
                if (isPaused) {
                    playIcon.className = 'fa-solid fa-play text-xs text-amber-400';
                } else {
                    playIcon.className = 'fa-solid fa-pause text-xs text-slate-300';
                }
            });
        }

        // -------------------------------------------------------------
        // D. TOGGLE FULLSCREEN TV MODE (F11 / F / Klik Tombol)
        // -------------------------------------------------------------
        const toggleFullscreenBtn = document.getElementById('toggleFullscreenBtn');
        const fsIcon = document.getElementById('fsIcon');

        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(err => {
                    console.log('Fullscreen error:', err);
                });
                if (fsIcon) fsIcon.className = 'fa-solid fa-compress text-xs';
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
                if (fsIcon) fsIcon.className = 'fa-solid fa-expand text-xs';
            }
        }

        if (toggleFullscreenBtn) {
            toggleFullscreenBtn.addEventListener('click', toggleFullscreen);
        }

        // Keyboard Shortcuts: F = Fullscreen, Spasi = Pause/Play, Panah Kiri/Kanan = Navigasi
        window.addEventListener('keydown', (e) => {
            if (e.key === 'f' || e.key === 'F') {
                toggleFullscreen();
            } else if (e.key === ' ' || e.code === 'Space') {
                e.preventDefault();
                if (togglePlayBtn) togglePlayBtn.click();
            } else if (e.key === 'ArrowRight') {
                showSlide(currentSlideIdx + 1);
            } else if (e.key === 'ArrowLeft') {
                showSlide(currentSlideIdx - 1);
            }
        });

        // -------------------------------------------------------------
        // E. AUTO BACKGROUND SYNC (Reload Berkala Tiap 5 Menit)
        // -------------------------------------------------------------
        // Setiap 5 menit, halaman akan menyegarkan data agar pengumuman
        // baru yang diinput staf sekolah langsung muncul di layar TV.
        const SYNC_INTERVAL = 5 * 60 * 1000; // 5 menit
        setTimeout(() => {
            window.location.reload();
        }, SYNC_INTERVAL);

        // Mulai Slideshow Pertama
        showSlide(0);
        startTimer();
    })();
    </script>
</body>
</html>
