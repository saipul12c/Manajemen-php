<?php
/**
 * Halaman Publik Berita & Liputan Sekolah Terpadu
 * Manajemen-PHP (Open Access)
 * Mendukung Tampilan Berita Utama, Filter Kategori, Pencarian, serta Detail Baca Artikel Lengkap.
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
$can_write    = in_array($user_role, ['administrator', 'staf', 'guru'], true);

// Deteksi Mode: Detail Artikel atau Daftar Berita
$view_id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$view_slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$is_detail = ($view_id > 0 || $view_slug !== '');

$article = null;
$related_news = [];

if ($is_detail) {
    // -------------------------------------------------------------
    // MODE DETAIL ARTIKEL BERITA
    // -------------------------------------------------------------
    try {
        if ($view_id > 0) {
            $stmt_art = $pdo->prepare("
                SELECT n.*, 
                       u.name as author_name, u.role as author_role,
                       app.name as approver_name, app.role as approver_role,
                       lb.title as book_title, lb.author as book_author, lb.publisher as book_publisher, lb.year as book_year, lb.category as book_category, lb.shelf_location as book_shelf, lb.stock_available as book_stock, lb.cover_image as book_cover, lb.ebook_file as book_ebook, lb.description as book_desc,
                       ce.title as event_title, ce.event_date as event_date, ce.category as event_category
                FROM news n
                JOIN users u ON n.author_id = u.id
                LEFT JOIN users app ON n.approved_by = app.id
                LEFT JOIN library_books lb ON n.book_id = lb.id
                LEFT JOIN calendar_events ce ON n.event_id = ce.id
                WHERE n.id = ?
            ");
            $stmt_art->execute([$view_id]);
        } else {
            $stmt_art = $pdo->prepare("
                SELECT n.*, 
                       u.name as author_name, u.role as author_role,
                       app.name as approver_name, app.role as approver_role,
                       lb.title as book_title, lb.author as book_author, lb.publisher as book_publisher, lb.year as book_year, lb.category as book_category, lb.shelf_location as book_shelf, lb.stock_available as book_stock, lb.cover_image as book_cover, lb.ebook_file as book_ebook, lb.description as book_desc,
                       ce.title as event_title, ce.event_date as event_date, ce.category as event_category
                FROM news n
                JOIN users u ON n.author_id = u.id
                LEFT JOIN users app ON n.approved_by = app.id
                LEFT JOIN library_books lb ON n.book_id = lb.id
                LEFT JOIN calendar_events ce ON n.event_id = ce.id
                WHERE n.slug = ?
            ");
            $stmt_art->execute([$view_slug]);
        }
        $article = $stmt_art->fetch();

        if ($article) {
            // Jika berita belum published, hanya author atau staf/admin yang boleh melihat preview
            $can_view_draft = ($is_logged_in && ((int)$article['author_id'] === $user_id || in_array($user_role, ['administrator', 'staf'], true)));
            if ($article['status'] !== 'published' && !$can_view_draft) {
                $article = null; // Akses ditolak
            } else {
                // Update hit view counter untuk berita published
                if ($article['status'] === 'published') {
                    $pdo->prepare("UPDATE news SET views = views + 1 WHERE id = ?")->execute([$article['id']]);
                    $article['views'] = (int)$article['views'] + 1;
                }

                // Ambil Berita Terkait Lainnya
                $stmt_rel = $pdo->prepare("
                    SELECT n.*, u.name as author_name, u.role as author_role
                    FROM news n
                    JOIN users u ON n.author_id = u.id
                    WHERE n.status = 'published' AND n.id != ? AND n.category = ?
                    ORDER BY n.published_at DESC LIMIT 3
                ");
                $stmt_rel->execute([$article['id'], $article['category']]);
                $related_news = $stmt_rel->fetchAll();

                // Jika tidak cukup dari kategori sama, ambil berita published terbaru
                if (count($related_news) < 3) {
                    $needed = 3 - count($related_news);
                    $existing_ids = array_merge([$article['id']], array_column($related_news, 'id'));
                    $placeholders = implode(',', array_fill(0, count($existing_ids), '?'));
                    $stmt_extra = $pdo->prepare("
                        SELECT n.*, u.name as author_name, u.role as author_role
                        FROM news n
                        JOIN users u ON n.author_id = u.id
                        WHERE n.status = 'published' AND n.id NOT IN ($placeholders)
                        ORDER BY n.published_at DESC LIMIT $needed
                    ");
                    $stmt_extra->execute($existing_ids);
                    $related_news = array_merge($related_news, $stmt_extra->fetchAll());
                }
            }
        }
    } catch (Exception $e) {
        $article = null;
    }
} else {
    // -------------------------------------------------------------
    // MODE DAFTAR / GRID BERITA
    // -------------------------------------------------------------
    $filter_category = trim($_GET['category'] ?? '');
    $search_query = trim($_GET['q'] ?? '');

    $where_clauses = ["n.status = 'published'"];
    $params = [];

    if ($filter_category !== '' && array_key_exists($filter_category, NEWS_CATEGORIES)) {
        $where_clauses[] = "n.category = ?";
        $params[] = $filter_category;
    }

    if ($search_query !== '') {
        $where_clauses[] = "(n.title LIKE ? OR n.excerpt LIKE ? OR u.name LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }

    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

    $news_items = [];
    $featured_news = null;

    try {
        // Ambil Headline / Featured News jika tanpa filter pencarian spesifik
        if (empty($search_query) && empty($filter_category)) {
            $stmt_feat = $pdo->query("
                SELECT n.*, u.name as author_name, u.role as author_role,
                       lb.title as book_title,
                       ce.title as event_title,
                       cr.title as counseling_title
                FROM news n
                JOIN users u ON n.author_id = u.id
                LEFT JOIN library_books lb ON n.book_id = lb.id
                LEFT JOIN calendar_events ce ON n.event_id = ce.id
                LEFT JOIN counseling_records cr ON n.counseling_id = cr.id
                WHERE n.status = 'published' AND n.is_featured = 1
                ORDER BY n.published_at DESC LIMIT 1
            ");
            $featured_news = $stmt_feat->fetch();
        }

        $feat_id = $featured_news ? (int)$featured_news['id'] : 0;
        if ($feat_id > 0) {
            $where_clauses[] = "n.id != $feat_id";
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        $stmt_list = $pdo->prepare("
            SELECT n.*, u.name as author_name, u.role as author_role,
                   lb.title as book_title,
                   ce.title as event_title,
                   cr.title as counseling_title
            FROM news n
            JOIN users u ON n.author_id = u.id
            LEFT JOIN library_books lb ON n.book_id = lb.id
            LEFT JOIN calendar_events ce ON n.event_id = ce.id
            LEFT JOIN counseling_records cr ON n.counseling_id = cr.id
            $where_sql
            ORDER BY n.published_at DESC
        ");
        $stmt_list->execute($params);
        $news_items = $stmt_list->fetchAll();
    } catch (Exception $e) {
        $news_items = [];
    }
}

// Hitung total berita published untuk statistik header
$total_published_news = 0;
try {
    $total_published_news = (int)$pdo->query("SELECT COUNT(*) FROM news WHERE status = 'published'")->fetchColumn();
} catch (Exception $e) {}

$page_title_text = $is_detail && $article 
    ? htmlspecialchars($article['title']) . ' - Berita ' . htmlspecialchars($school_info['school_name'] ?? 'Sekolah')
    : 'Portal Berita & Kabar Sekolah - ' . htmlspecialchars($school_info['school_name'] ?? 'Sekolah');

$page_desc_text = $is_detail && $article
    ? htmlspecialchars($article['excerpt'] ?: mb_substr(strip_tags($article['content']), 0, 160))
    : 'Liputan kegiatan resmi, prestasi siswa, inovasi akademik, dan artikel edukasi dari civitas akademika sekolah.';
?>
<!DOCTYPE html>
<html lang="id" class="dark scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= $page_title_text ?></title>
    <meta name="description" content="<?= $page_desc_text ?>">

    <!-- Tailwind CSS v4 Browser CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-card {
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .article-body {
            color: #e2e8f0;
            line-height: 1.85;
            font-size: 1rem;
        }
        .article-body p { margin-bottom: 1.25rem; }
        .article-body h2 { color: #ffffff; font-size: 1.4rem; font-weight: 700; margin-top: 2rem; margin-bottom: 0.75rem; }
        .article-body h3 { color: #f1f5f9; font-size: 1.2rem; font-weight: 600; margin-top: 1.5rem; margin-bottom: 0.5rem; }
        .article-body strong { color: #ffffff; font-weight: 700; }
        .article-body ul { list-style-type: disc; margin-left: 1.5rem; margin-bottom: 1.25rem; }
        .article-body ol { list-style-type: decimal; margin-left: 1.5rem; margin-bottom: 1.25rem; }
        .article-body li { margin-bottom: 0.35rem; }
        .article-body blockquote {
            border-left: 4px solid #38bdf8;
            padding-left: 1rem;
            margin: 1.5rem 0;
            color: #94a3b8;
            font-style: italic;
            background: rgba(56, 189, 248, 0.05);
            padding: 0.75rem 1rem;
            border-radius: 0 0.75rem 0.75rem 0;
        }
        .article-body img {
            max-width: 100%;
            height: auto;
            border-radius: 1rem;
            margin: 1.5rem 0;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
    </style>
</head>

<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-sky-500 selection:text-white flex flex-col justify-between">

    <?php
    $nav_active = 'berita';
    require_once __DIR__ . '/includes/navbar.php';
    ?>

    <main class="flex-1">

        <?php if ($is_detail): ?>
            <!-- ============================================================== -->
            <!-- TAMPILAN DETAIL ARTIKEL LENGKAP -->
            <!-- ============================================================== -->
            <?php if (!$article): ?>
                <div class="max-w-3xl mx-auto px-4 py-24 text-center space-y-4">
                    <div class="h-20 w-20 rounded-3xl bg-slate-900 border border-white/10 text-slate-500 flex items-center justify-center text-3xl mx-auto shadow-2xl">
                        <i class="fa-solid fa-file-circle-xmark"></i>
                    </div>
                    <h1 class="text-2xl font-extrabold text-white">Berita Tidak Ditemukan</h1>
                    <p class="text-sm text-slate-400 max-w-md mx-auto">
                        Artikel yang Anda tuju mungkin belum dipublikasikan, sedang dalam peninjauan moderasi, atau telah dihapus oleh redaksi.
                    </p>
                    <div class="pt-4">
                        <a href="berita.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-lg shadow-blue-500/25 transition">
                            <i class="fa-solid fa-arrow-left"></i>
                            <span>Kembali ke Portal Berita</span>
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php
                    $cat_info = NEWS_CATEGORIES[$article['category']] ?? ['label' => ucfirst($article['category']), 'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300'];
                    $cover_url = !empty($article['thumbnail']) ? resolveAssetUrl($article['thumbnail']) : null;
                    $reading_time = max(1, (int)ceil(str_word_count(strip_tags($article['content'])) / 200));
                ?>

                <!-- Article Breadcrumbs -->
                <section class="border-b border-white/5 bg-slate-950/80 py-3.5 px-4 sm:px-6 lg:px-8">
                    <div class="max-w-4xl mx-auto flex items-center gap-2 text-xs text-slate-400 overflow-x-auto whitespace-nowrap">
                        <a href="index.php" class="hover:text-white transition flex items-center gap-1">
                            <i class="fa-solid fa-house text-[10px]"></i> Beranda
                        </a>
                        <span class="text-slate-600">/</span>
                        <a href="informasi.php" class="hover:text-white transition">Informasi</a>
                        <span class="text-slate-600">/</span>
                        <a href="berita.php" class="hover:text-white transition">Berita Sekolah</a>
                        <span class="text-slate-600">/</span>
                        <span class="text-slate-300 truncate max-w-[200px] sm:max-w-xs font-medium"><?= htmlspecialchars($article['title']) ?></span>
                    </div>
                </section>

                <!-- Article Header & Content -->
                <article class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12 space-y-8">
                    
                    <!-- Header Meta -->
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <a href="berita.php?category=<?= urlencode($article['category']) ?>" 
                               class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider border <?= $cat_info['badge'] ?> hover:opacity-80 transition">
                                <?= htmlspecialchars($cat_info['label']) ?>
                            </a>

                            <?php if ($article['is_featured']): ?>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500 text-slate-950 flex items-center gap-1 shadow-sm">
                                    <i class="fa-solid fa-star text-[10px]"></i> Berita Utama
                                </span>
                            <?php endif; ?>

                            <?php if ($article['status'] !== 'published'): ?>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40">
                                    <i class="fa-solid fa-eye text-[10px] mr-1"></i> Preview (Status: <?= htmlspecialchars(ucfirst($article['status'])) ?>)
                                </span>
                            <?php endif; ?>
                        </div>

                        <h1 class="text-2xl sm:text-4xl font-extrabold text-white tracking-tight leading-tight">
                            <?= htmlspecialchars($article['title']) ?>
                        </h1>

                        <div class="flex flex-wrap items-center justify-between gap-4 py-3 border-y border-white/10 text-xs text-slate-400">
                            <!-- Author & Role info -->
                            <div class="flex items-center gap-3">
                                <div class="h-10 w-10 rounded-full bg-gradient-to-tr from-blue-600 to-indigo-600 text-white flex items-center justify-center font-bold text-sm shadow-md">
                                    <?= strtoupper(mb_substr($article['author_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <p class="font-bold text-slate-200"><?= htmlspecialchars($article['author_name']) ?></p>
                                        <span class="px-2 py-0.2 rounded-full text-[10px] font-semibold border <?= getRoleBadge($article['author_role']) ?>">
                                            <?= htmlspecialchars(getRoleLabel($article['author_role'])) ?>
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-slate-400">
                                        Diterbitkan: <?= date('d F Y', strtotime($article['published_at'] ?? $article['created_at'])) ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Reading time & Views -->
                            <div class="flex items-center gap-4 text-slate-400 text-xs">
                                <span class="flex items-center gap-1.5" title="Estimasi waktu baca">
                                    <i class="fa-regular fa-clock text-slate-500"></i> <?= $reading_time ?> menit baca
                                </span>
                                <span class="flex items-center gap-1.5" title="Jumlah pembaca">
                                    <i class="fa-regular fa-eye text-slate-500"></i> <?= number_format($article['views']) ?> kali dibaca
                                </span>
                            </div>
                        </div>

                        <!-- Moderation Approval Note (khusus artikel guru yang disetujui staf/admin) -->
                        <?php if (!empty($article['approver_name'])): ?>
                            <div class="p-3 rounded-2xl bg-emerald-500/5 border border-emerald-500/20 text-emerald-300 text-xs flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid fa-circle-check text-emerald-400"></i>
                                    <span>Artikel resmi terverifikasi dan disetujui oleh <strong><?= htmlspecialchars($article['approver_name']) ?></strong> (<?= htmlspecialchars(getRoleLabel($article['approver_role'] ?? 'staf')) ?>)</span>
                                </div>
                                <span class="text-[10px] text-emerald-400/80 font-mono hidden sm:inline">
                                    <?= date('d/m/Y H:i', strtotime($article['approved_at'])) ?> WIB
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Cover Photo -->
                    <?php if ($cover_url): ?>
                        <div class="rounded-3xl overflow-hidden shadow-2xl border border-white/10 bg-slate-900 max-h-[500px]">
                            <img src="<?= $cover_url ?>" alt="<?= htmlspecialchars($article['title']) ?>" class="w-full h-full object-cover">
                        </div>
                    <?php endif; ?>

                    <!-- Konten Lengkap Artikel -->
                    <div class="article-body">
                        <?= sanitizeNewsHtml($article['content']) ?>
                    </div>

                    <!-- 1. INTEGRASI BK & KESISWAAN: Banner Prestasi Siswa Terverifikasi -->
                    <?php if (!empty($article['counseling_title'])): ?>
                        <div class="rounded-3xl border border-amber-500/30 bg-gradient-to-r from-amber-950/30 via-slate-900 to-amber-950/20 p-6 shadow-xl space-y-4">
                            <div class="flex items-start justify-between gap-4 flex-wrap">
                                <div class="flex items-center gap-3">
                                    <div class="h-12 w-12 rounded-2xl bg-amber-500/20 border border-amber-500/30 text-amber-400 flex items-center justify-center text-xl shrink-0">
                                        <i class="fa-solid fa-trophy"></i>
                                    </div>
                                    <div>
                                        <span class="text-[10px] font-extrabold uppercase tracking-wider text-amber-400">Verifikasi Catatan Prestasi Siswa Terpadu</span>
                                        <h4 class="text-base sm:text-lg font-bold text-white"><?= htmlspecialchars($article['counseling_title']) ?></h4>
                                    </div>
                                </div>
                                <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 font-mono font-bold text-xs">
                                    <i class="fa-solid fa-award"></i> +<?= (int)$article['counseling_points'] ?> Poin Apresiasi
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-3 border-t border-white/10 text-xs">
                                <div>
                                    <span class="text-slate-400 block text-[11px]">Siswa Berprestasi:</span>
                                    <span class="font-bold text-slate-200"><?= htmlspecialchars($article['counseling_student_name'] ?: 'Siswa Terkait') ?></span>
                                    <?php if (!empty($article['counseling_student_class'])): ?>
                                        <span class="text-slate-400 text-[11px]">(<?= htmlspecialchars($article['counseling_student_class']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <span class="text-slate-400 block text-[11px]">Kategori Capaian:</span>
                                    <span class="font-semibold text-slate-300"><?= htmlspecialchars($article['counseling_category'] ?: 'Akademik / Non-Akademik') ?></span>
                                </div>
                                <div>
                                    <span class="text-slate-400 block text-[11px]">Tanggal Verifikasi:</span>
                                    <span class="font-mono text-slate-300"><?= date('d F Y', strtotime($article['counseling_date'] ?? $article['created_at'])) ?></span>
                                </div>
                            </div>
                            <?php if (!empty($article['counseling_notes'])): ?>
                                <p class="text-xs text-slate-400 italic bg-black/20 rounded-xl p-3 border border-white/5">
                                    <i class="fa-solid fa-quote-left text-amber-400/50 mr-1.5"></i>
                                    <?= htmlspecialchars($article['counseling_notes']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- 2. INTEGRASI KALENDER AKADEMIK: Liputan Agenda Kegiatan Resmi -->
                    <?php if (!empty($article['event_title'])): ?>
                        <div class="rounded-3xl border border-blue-500/30 bg-gradient-to-r from-blue-950/30 via-slate-900 to-indigo-950/20 p-5 shadow-xl flex items-center justify-between gap-4 flex-wrap">
                            <div class="flex items-center gap-3">
                                <div class="h-11 w-11 rounded-2xl bg-blue-500/20 border border-blue-500/30 text-blue-400 flex items-center justify-center text-lg shrink-0">
                                    <i class="fa-solid fa-calendar-check"></i>
                                </div>
                                <div>
                                    <span class="text-[10px] font-bold uppercase tracking-wider text-blue-400">Liputan Resmi Agenda Kalender Akademik</span>
                                    <h4 class="text-sm sm:text-base font-bold text-white"><?= htmlspecialchars($article['event_title']) ?></h4>
                                    <p class="text-[11px] text-slate-400">
                                        Jadwal Pelaksanaan: <?= date('d F Y', strtotime($article['event_date'])) ?> • Kategori: <?= htmlspecialchars(ucfirst($article['event_category'] ?? 'Kegiatan')) ?>
                                    </p>
                                </div>
                            </div>
                            <a href="informasi.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/40 text-xs font-semibold text-blue-300 transition">
                                <span>Lihat Kalender</span>
                                <i class="fa-solid fa-arrow-right text-[10px]"></i>
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- 3. INTEGRASI PERPUSTAKAAN: Koleksi Buku Terkait & Pojok Literasi -->
                    <?php if (!empty($article['book_title'])): ?>
                        <div class="rounded-3xl border border-teal-500/30 bg-gradient-to-r from-teal-950/25 via-slate-900 to-slate-900 p-6 shadow-xl space-y-4">
                            <div class="flex items-center gap-2 text-teal-400 text-xs font-extrabold uppercase tracking-wider">
                                <i class="fa-solid fa-book-bookmark text-sm"></i>
                                <span>Pojok Literasi: Buku Terkait di Perpustakaan Sekolah</span>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-5 items-start">
                                <div class="w-24 sm:w-28 shrink-0 rounded-2xl overflow-hidden bg-slate-950 border border-white/10 shadow-lg aspect-[3/4]">
                                    <?php if (!empty($article['book_cover'])): ?>
                                        <img src="<?= htmlspecialchars($article['book_cover']) ?>" alt="<?= htmlspecialchars($article['book_title']) ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <div class="w-full h-full flex flex-col items-center justify-center text-teal-400/40 p-2 text-center">
                                            <i class="fa-solid fa-book text-3xl mb-1"></i>
                                            <span class="text-[9px]">Koleksi Cetak</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="flex-1 space-y-2">
                                    <h4 class="text-base sm:text-lg font-bold text-white leading-snug">
                                        <?= htmlspecialchars($article['book_title']) ?>
                                    </h4>
                                    <p class="text-xs text-slate-300">
                                        Penulis: <span class="font-semibold text-white"><?= htmlspecialchars($article['book_author'] ?? '-') ?></span> 
                                        <?php if (!empty($article['book_publisher'])): ?>
                                            • Penerbit: <?= htmlspecialchars($article['book_publisher']) ?> (<?= htmlspecialchars((string)($article['book_year'] ?? '')) ?>)
                                        <?php endif; ?>
                                    </p>

                                    <div class="flex flex-wrap items-center gap-2 pt-1 text-[11px]">
                                        <span class="px-2.5 py-1 rounded-lg bg-teal-500/10 border border-teal-500/30 text-teal-300 font-semibold">
                                            <?= htmlspecialchars($article['book_category'] ?? 'Umum') ?>
                                        </span>
                                        <span class="px-2.5 py-1 rounded-lg bg-slate-800 border border-white/10 text-slate-300">
                                            <i class="fa-solid fa-location-dot text-teal-400 mr-1"></i> <?= htmlspecialchars($article['book_shelf'] ?: 'Rak Perpustakaan') ?>
                                        </span>
                                        <span class="px-2.5 py-1 rounded-lg <?= (int)($article['book_stock'] ?? 0) > 0 ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20' ?>">
                                            Stok Tersedia: <?= (int)($article['book_stock'] ?? 0) ?> Eks
                                        </span>
                                    </div>

                                    <?php if (!empty($article['book_desc'])): ?>
                                        <p class="text-xs text-slate-400 line-clamp-2 pt-1">
                                            <?= htmlspecialchars($article['book_desc']) ?>
                                        </p>
                                    <?php endif; ?>

                                    <div class="pt-2 flex flex-wrap items-center gap-3">
                                        <a href="perpustakaan.php?search=<?= urlencode($article['book_title']) ?>" 
                                           class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-teal-600 hover:bg-teal-500 text-white text-xs font-semibold shadow-md shadow-teal-600/20 transition">
                                            <i class="fa-solid fa-magnifying-glass text-[10px]"></i>
                                            <span>Cari di Katalog Perpustakaan</span>
                                        </a>
                                        <?php if (!empty($article['book_ebook'])): ?>
                                            <a href="<?= htmlspecialchars($article['book_ebook']) ?>" target="_blank"
                                               class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-teal-500/30 bg-teal-500/10 hover:bg-teal-500/20 text-teal-300 text-xs font-semibold transition">
                                                <i class="fa-solid fa-file-pdf"></i>
                                                <span>Akses E-Book Digital</span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 4. INTEGRASI PPDB: Smart Conversion CTA Card -->
                    <div class="rounded-3xl border border-emerald-500/30 bg-gradient-to-r from-emerald-950/40 via-slate-900 to-teal-950/30 p-6 sm:p-8 shadow-2xl relative overflow-hidden">
                        <div class="absolute -right-10 -bottom-10 h-40 w-40 rounded-full bg-emerald-500/10 blur-2xl pointer-events-none"></div>
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-6 relative z-10">
                            <div class="space-y-2 max-w-xl">
                                <div class="inline-flex items-center gap-2 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-emerald-300">
                                    <i class="fa-solid fa-graduation-cap"></i>
                                    <span>Penerimaan Peserta Didik Baru (PPDB)</span>
                                </div>
                                <h3 class="text-xl sm:text-2xl font-extrabold text-white leading-snug">
                                    Tertarik Bergabung dengan Komunitas Berprestasi Kami?
                                </h3>
                                <p class="text-xs sm:text-sm text-slate-300 leading-relaxed">
                                    Pendaftaran siswa baru Tahun Ajaran <?= htmlspecialchars($school_info['academic_year'] ?? date('Y') . '/' . (date('Y') + 1)) ?> telah dibuka. Dapatkan lingkungan belajar modern, fasilitas unggul, dan bimbingan prestasi terpadu.
                                </p>
                            </div>
                            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 shrink-0">
                                <a href="ppdb/ppdb.php" class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs sm:text-sm font-bold shadow-xl shadow-emerald-600/30 transition">
                                    <span>Daftar PPDB Online</span>
                                    <i class="fa-solid fa-arrow-right text-xs"></i>
                                </a>
                                <a href="kontak.php" class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white text-xs sm:text-sm font-semibold transition">
                                    <span>Konsultasi</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Share Section & Tags -->
                    <div class="pt-8 border-t border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                        <div class="flex items-center gap-2 text-xs text-slate-400">
                            <span class="font-bold text-white">Kategori:</span>
                            <span class="px-2.5 py-1 rounded-xl bg-white/5 border border-white/10 text-slate-300">
                                <?= htmlspecialchars($cat_info['label']) ?>
                            </span>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold text-slate-300 mr-1">Bagikan:</span>
                            <?php 
                                $share_url = urlencode((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");
                                $share_title = urlencode($article['title']);
                            ?>
                            <a href="https://api.whatsapp.com/send?text=<?= $share_title ?>%20<?= $share_url ?>" target="_blank" rel="noopener noreferrer" 
                               class="h-9 w-9 rounded-xl bg-emerald-600/20 text-emerald-400 hover:bg-emerald-600 hover:text-white transition flex items-center justify-center text-sm" title="Bagikan ke WhatsApp">
                                <i class="fa-brands fa-whatsapp"></i>
                            </a>
                            <a href="https://twitter.com/intent/tweet?text=<?= $share_title ?>&url=<?= $share_url ?>" target="_blank" rel="noopener noreferrer"
                               class="h-9 w-9 rounded-xl bg-sky-500/20 text-sky-400 hover:bg-sky-500 hover:text-white transition flex items-center justify-center text-sm" title="Bagikan ke X / Twitter">
                                <i class="fa-brands fa-x-twitter"></i>
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= $share_url ?>" target="_blank" rel="noopener noreferrer"
                               class="h-9 w-9 rounded-xl bg-blue-600/20 text-blue-400 hover:bg-blue-600 hover:text-white transition flex items-center justify-center text-sm" title="Bagikan ke Facebook">
                                <i class="fa-brands fa-facebook"></i>
                            </a>
                            <button type="button" onclick="navigator.clipboard.writeText(window.location.href); alert('Tautan berita berhasil disalin ke clipboard!');" 
                                    class="h-9 w-9 rounded-xl bg-white/10 text-slate-300 hover:bg-white/20 hover:text-white transition flex items-center justify-center text-sm" title="Salin Tautan">
                                <i class="fa-solid fa-link"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Berita Terkait Lainnya -->
                    <?php if (!empty($related_news)): ?>
                        <div class="pt-8 border-t border-white/10 space-y-4">
                            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-newspaper text-sky-400"></i>
                                <span>Berita Terkait Lainnya</span>
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <?php foreach ($related_news as $rel): ?>
                                    <?php 
                                        $rel_cat = NEWS_CATEGORIES[$rel['category']] ?? ['label' => ucfirst($rel['category']), 'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300'];
                                        $rel_thumb = !empty($rel['thumbnail']) ? resolveAssetUrl($rel['thumbnail']) : null;
                                    ?>
                                    <a href="berita.php?id=<?= $rel['id'] ?>" class="glass-card rounded-2xl p-3 hover:border-sky-500/40 transition group flex flex-col justify-between">
                                        <div>
                                            <div class="h-28 rounded-xl bg-slate-950 overflow-hidden relative mb-2.5">
                                                <?php if ($rel_thumb): ?>
                                                    <img src="<?= $rel_thumb ?>" alt="<?= htmlspecialchars($rel['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                                                <?php else: ?>
                                                    <div class="w-full h-full bg-slate-900 flex items-center justify-center text-slate-700">
                                                        <i class="fa-solid fa-newspaper text-2xl"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <span class="absolute top-2 left-2 px-2 py-0.5 rounded-full text-[9px] font-bold uppercase backdrop-blur-md border <?= $rel_cat['badge'] ?>">
                                                    <?= htmlspecialchars($rel_cat['label']) ?>
                                                </span>
                                            </div>
                                            <h4 class="text-xs sm:text-sm font-bold text-white line-clamp-2 group-hover:text-sky-400 transition leading-snug">
                                                <?= htmlspecialchars($rel['title']) ?>
                                            </h4>
                                        </div>
                                        <div class="pt-2 text-[10px] text-slate-400 flex items-center justify-between mt-2 border-t border-white/5">
                                            <span><?= date('d M Y', strtotime($rel['published_at'] ?? $rel['created_at'])) ?></span>
                                            <span class="text-sky-400 font-semibold">Baca →</span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Tombol Kembali -->
                    <div class="pt-4 text-center">
                        <a href="berita.php" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white text-xs font-semibold transition">
                            <i class="fa-solid fa-arrow-left"></i>
                            <span>Kembali ke Indeks Berita</span>
                        </a>
                    </div>
                </article>
            <?php endif; ?>

        <?php else: ?>
            <!-- ============================================================== -->
            <!-- TAMPILAN DAFTAR / GRID BERITA LENGKAP -->
            <!-- ============================================================== -->
            
            <!-- Hero Header Section -->
            <section class="relative overflow-hidden border-b border-white/10 bg-gradient-to-b from-blue-950/40 via-slate-950 to-slate-950 py-12 md:py-16">
                <!-- Background Ambient Glows -->
                <div class="absolute -top-32 -left-32 h-96 w-96 rounded-full bg-blue-600/15 blur-3xl pointer-events-none"></div>
                <div class="absolute top-0 right-0 h-96 w-96 rounded-full bg-indigo-600/10 blur-3xl pointer-events-none"></div>

                <div class="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
                        <div class="max-w-2xl space-y-3">
                            <div class="inline-flex items-center gap-2 rounded-full border border-sky-500/30 bg-sky-500/10 px-3.5 py-1 text-xs font-bold uppercase tracking-wider text-sky-400">
                                <i class="fa-solid fa-newspaper text-xs"></i>
                                <span>Portal Kabar & Liputan Sekolah Terpadu</span>
                            </div>
                            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white tracking-tight leading-tight">
                                Berita, Prestasi & Artikel Sekolah
                            </h1>
                            <p class="text-sm sm:text-base text-slate-300 leading-relaxed">
                                Publikasi resmi liputan kegiatan edukasi, prestasi membanggakan siswa, agenda penting, serta tulisan karya pemikiran dewan guru.
                            </p>
                        </div>

                        <!-- Right Stats & Action Shortcut -->
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="glass-card rounded-2xl px-4 py-2.5 text-center">
                                <span class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Total Publikasi</span>
                                <p class="text-xl font-extrabold text-sky-400"><?= number_format($total_published_news) ?></p>
                            </div>

                            <a href="informasi.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-xs font-semibold text-slate-300 hover:text-white transition">
                                <i class="fa-solid fa-bullhorn text-amber-400"></i>
                                <span>Papan Pengumuman</span>
                            </a>

                            <?php if ($can_write): ?>
                                <a href="dashboard/informasi/news.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-semibold text-white shadow-lg shadow-blue-600/30 transition">
                                    <i class="fa-solid fa-feather-pointed"></i>
                                    <span>Tulis Berita</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Main Listing Container -->
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-12 space-y-8">
                
                <!-- Category Filters & Search Bar -->
                <div class="glass-card rounded-3xl p-4 sm:p-5 shadow-xl space-y-4">
                    <!-- Category Pills -->
                    <div class="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-thin">
                        <a href="berita.php" 
                           class="px-3.5 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition <?= empty($filter_category) ? 'bg-blue-600 text-white shadow-md shadow-blue-600/30' : 'bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white' ?>">
                            Semua Kategori
                        </a>
                        <?php foreach (NEWS_CATEGORIES as $ckey => $cdata): ?>
                            <a href="berita.php?category=<?= urlencode($ckey) ?><?= !empty($search_query) ? '&q=' . urlencode($search_query) : '' ?>" 
                               class="px-3.5 py-1.5 rounded-xl text-xs font-semibold whitespace-nowrap transition flex items-center gap-1.5 <?= $filter_category === $ckey ? 'bg-sky-500 text-slate-950 font-bold shadow-md shadow-sky-500/30' : 'bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white' ?>">
                                <?= $cdata['icon'] ?>
                                <span><?= htmlspecialchars($cdata['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Search Input -->
                    <form method="GET" action="berita.php" class="flex gap-2">
                        <?php if (!empty($filter_category)): ?>
                            <input type="hidden" name="category" value="<?= htmlspecialchars($filter_category) ?>">
                        <?php endif; ?>
                        <div class="relative flex-1">
                            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>" 
                                   placeholder="Cari berita berdasarkan judul, topik kegiatan, atau nama penulis..." 
                                   class="w-full pl-9 pr-4 py-2.5 rounded-2xl bg-slate-950/80 border border-white/10 text-xs sm:text-sm text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 transition">
                        </div>
                        <button type="submit" class="px-5 py-2.5 rounded-2xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-md transition">
                            Cari
                        </button>
                        <?php if (!empty($search_query) || !empty($filter_category)): ?>
                            <a href="berita.php" class="px-3.5 py-2.5 rounded-2xl bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white text-xs font-semibold transition flex items-center" title="Reset">
                                <i class="fa-solid fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Featured / Headline News Hero Banner (Jika Ada) -->
                <?php if ($featured_news && empty($search_query) && empty($filter_category)): ?>
                    <?php 
                        $feat_cat = NEWS_CATEGORIES[$featured_news['category']] ?? ['label' => ucfirst($featured_news['category']), 'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300'];
                        $feat_thumb = !empty($featured_news['thumbnail']) ? resolveAssetUrl($featured_news['thumbnail']) : null;
                    ?>
                    <div class="relative overflow-hidden rounded-3xl border border-indigo-500/30 bg-gradient-to-r from-slate-900 via-indigo-950/40 to-slate-900 shadow-2xl p-6 sm:p-8 group">
                        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-center">
                            
                            <div class="lg:col-span-5 order-2 lg:order-1 space-y-4">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase bg-amber-500 text-slate-950 flex items-center gap-1 shadow-sm">
                                        <i class="fa-solid fa-star"></i> Berita Utama
                                    </span>
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border <?= $feat_cat['badge'] ?>">
                                        <?= htmlspecialchars($feat_cat['label']) ?>
                                    </span>
                                    <?php if (!empty($featured_news['counseling_title'])): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/40 flex items-center gap-1">
                                            <i class="fa-solid fa-trophy text-[9px]"></i> Prestasi
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($featured_news['event_title'])): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-500/20 text-blue-300 border border-blue-500/40 flex items-center gap-1">
                                            <i class="fa-solid fa-calendar-check text-[9px]"></i> Agenda
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($featured_news['book_title'])): ?>
                                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-teal-500/20 text-teal-300 border border-teal-500/40 flex items-center gap-1">
                                            <i class="fa-solid fa-book text-[9px]"></i> Resensi
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h2 class="text-xl sm:text-2xl lg:text-3xl font-extrabold text-white leading-tight group-hover:text-blue-400 transition">
                                    <a href="berita.php?id=<?= $featured_news['id'] ?>">
                                        <?= htmlspecialchars($featured_news['title']) ?>
                                    </a>
                                </h2>

                                <p class="text-xs sm:text-sm text-slate-300 line-clamp-3 leading-relaxed">
                                    <?= htmlspecialchars($featured_news['excerpt'] ?: strip_tags($featured_news['content'])) ?>
                                </p>

                                <div class="pt-2 flex items-center justify-between border-t border-white/10 text-xs text-slate-400">
                                    <div class="flex items-center gap-2">
                                        <div class="h-6 w-6 rounded-full bg-blue-600 text-white font-bold flex items-center justify-center text-[10px]">
                                            <?= strtoupper(mb_substr($featured_news['author_name'], 0, 1)) ?>
                                        </div>
                                        <span><?= htmlspecialchars($featured_news['author_name']) ?></span>
                                    </div>
                                    <span class="text-[11px]"><?= date('d F Y', strtotime($featured_news['published_at'] ?? $featured_news['created_at'])) ?></span>
                                </div>

                                <div class="pt-1">
                                    <a href="berita.php?id=<?= $featured_news['id'] ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold shadow-lg shadow-blue-600/30 transition">
                                        <span>Baca Liputan Lengkap</span>
                                        <i class="fa-solid fa-arrow-right text-[11px]"></i>
                                    </a>
                                </div>
                            </div>

                            <div class="lg:col-span-7 order-1 lg:order-2">
                                <a href="berita.php?id=<?= $featured_news['id'] ?>" class="block rounded-2xl overflow-hidden h-64 sm:h-80 w-full bg-slate-950 relative shadow-xl">
                                    <?php if ($feat_thumb): ?>
                                        <img src="<?= $feat_thumb ?>" alt="<?= htmlspecialchars($featured_news['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                                    <?php else: ?>
                                        <div class="w-full h-full bg-gradient-to-tr from-slate-950 via-slate-900 to-indigo-950 flex items-center justify-center text-slate-700">
                                            <i class="fa-solid fa-newspaper text-6xl"></i>
                                        </div>
                                    <?php endif; ?>
                                </a>
                            </div>

                        </div>
                    </div>
                <?php endif; ?>

                <!-- Grid Berita -->
                <?php if (empty($news_items) && !$featured_news): ?>
                    <div class="glass-card rounded-3xl p-12 text-center space-y-3">
                        <div class="h-16 w-16 rounded-full bg-slate-800 text-slate-400 mx-auto flex items-center justify-center text-2xl">
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </div>
                        <h3 class="text-base font-bold text-white">Tidak Ada Berita yang Cocok</h3>
                        <p class="text-xs sm:text-sm text-slate-400 max-w-md mx-auto">
                            Tidak ditemukan berita untuk kata kunci atau kategori yang Anda cari. Silakan coba kata kunci lain.
                        </p>
                        <div class="pt-2">
                            <a href="berita.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold transition">
                                Reset Pencarian
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php foreach ($news_items as $item): ?>
                            <?php 
                                $cat_info = NEWS_CATEGORIES[$item['category']] ?? ['label' => ucfirst($item['category']), 'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300'];
                                $thumb_src = !empty($item['thumbnail']) ? resolveAssetUrl($item['thumbnail']) : null;
                            ?>
                            <article class="glass-card rounded-3xl overflow-hidden flex flex-col justify-between hover:border-sky-500/40 hover:bg-slate-900/90 transition duration-300 group shadow-lg">
                                <div>
                                    <!-- Thumbnail Cover -->
                                    <a href="berita.php?id=<?= $item['id'] ?>" class="block relative h-48 w-full bg-slate-950 overflow-hidden">
                                        <?php if ($thumb_src): ?>
                                            <img src="<?= $thumb_src ?>" alt="<?= htmlspecialchars($item['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                                        <?php else: ?>
                                            <div class="w-full h-full bg-gradient-to-tr from-slate-950 via-slate-900 to-indigo-950/70 flex items-center justify-center text-slate-700">
                                                <i class="fa-solid fa-newspaper text-5xl opacity-40"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-transparent to-black/30"></div>
                                        
                                        <!-- Category badge -->
                                        <div class="absolute top-3 left-3">
                                            <span class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider backdrop-blur-md border <?= $cat_info['badge'] ?>">
                                                <?= htmlspecialchars($cat_info['label']) ?>
                                            </span>
                                        </div>

                                        <!-- Integration Badges -->
                                        <div class="absolute top-3 right-3 flex items-center gap-1">
                                            <?php if (!empty($item['counseling_title'])): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-amber-500 text-slate-950 flex items-center gap-1 shadow" title="Terkait Catatan Prestasi Siswa">
                                                    <i class="fa-solid fa-trophy"></i>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['event_title'])): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-blue-600 text-white flex items-center gap-1 shadow" title="Liputan Agenda Sekolah">
                                                    <i class="fa-solid fa-calendar-check"></i>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($item['book_title'])): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[9px] font-bold bg-teal-500 text-slate-950 flex items-center gap-1 shadow" title="Resensi Koleksi Buku Perpustakaan">
                                                    <i class="fa-solid fa-book"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Date badge -->
                                        <div class="absolute bottom-2.5 left-3 text-[11px] text-slate-300 flex items-center gap-1.5">
                                            <i class="fa-regular fa-calendar-days text-slate-400"></i>
                                            <?= date('d M Y', strtotime($item['published_at'] ?? $item['created_at'])) ?>
                                        </div>
                                    </a>

                                    <!-- Content -->
                                    <div class="p-5 space-y-2.5">
                                        <h3 class="text-base font-bold text-white line-clamp-2 group-hover:text-sky-400 transition leading-snug">
                                            <a href="berita.php?id=<?= $item['id'] ?>">
                                                <?= htmlspecialchars($item['title']) ?>
                                            </a>
                                        </h3>
                                        <p class="text-xs text-slate-400 line-clamp-3 leading-relaxed">
                                            <?= htmlspecialchars($item['excerpt'] ?: strip_tags($item['content'])) ?>
                                        </p>
                                    </div>
                                </div>

                                <!-- Footer meta -->
                                <div class="p-5 pt-0 border-t border-white/5 flex items-center justify-between text-[11px] text-slate-400 mt-2">
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-medium text-slate-300"><?= htmlspecialchars($item['author_name']) ?></span>
                                        <span class="text-slate-600">•</span>
                                        <span class="text-slate-500 capitalize"><?= htmlspecialchars(getRoleLabel($item['author_role'])) ?></span>
                                    </div>
                                    <a href="berita.php?id=<?= $item['id'] ?>" class="text-sky-400 font-bold group-hover:underline flex items-center gap-1">
                                        <span>Baca</span>
                                        <i class="fa-solid fa-chevron-right text-[9px]"></i>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        <?php endif; ?>

    </main>

    <?php require_once __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
