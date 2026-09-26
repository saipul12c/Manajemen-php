<?php
/**
 * Katalog Perpustakaan Digital Terbuka (Public Open Access)
 * Menampilkan koleksi buku fisik dan e-book yang terikat ke sistem perpustakaan sekolah.
 * Dilengkapi:
 * 1. Fitur Reservasi / Booking Buku Mandiri bagi siswa/guru yang login.
 * 2. Review & Rating Buku (1-5 Bintang) komunitas sekolah.
 * 3. In-Browser PDF/E-Book Reader interaktif.
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

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. HANDLE POST ACTIONS (Reservasi, Batalkan, Submit Ulasan)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // ACTION: RESERVASI / BOOKING BUKU MANDIRI
    if ($_POST['action'] === 'reserve_book') {
        if (!$is_logged_in) {
            $message = "Harap masuk ke akun siswa/guru Anda terlebih dahulu untuk melakukan reservasi buku.";
            $message_type = "error";
        } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $message = "Token keamanan tidak valid atau telah kedaluwarsa.";
            $message_type = "error";
        } else {
            $book_id = (int)($_POST['book_id'] ?? 0);
            $notes   = trim($_POST['notes'] ?? '');

            // Cek apakah buku ada
            $stmt_b = $pdo->prepare("SELECT id, title, stock_available FROM library_books WHERE id = ? LIMIT 1");
            $stmt_b->execute([$book_id]);
            $target_book = $stmt_b->fetch();

            if (!$target_book) {
                $message = "Buku yang ingin di-booking tidak ditemukan.";
                $message_type = "error";
            } else {
                // Cek apakah siswa sudah punya booking aktif untuk buku ini
                $stmt_chk = $pdo->prepare("SELECT id FROM library_reservations WHERE book_id = ? AND user_id = ? AND status IN ('menunggu', 'disiapkan') LIMIT 1");
                $stmt_chk->execute([$book_id, $user_id]);
                if ($stmt_chk->fetch()) {
                    $message = "Anda sudah memiliki antrean booking aktif untuk buku ini. Silakan cek di menu Reservasi Saya.";
                    $message_type = "error";
                } else {
                    // Batas kedaluwarsa: 2 hari ke depan
                    $expiry_date = date('Y-m-d', strtotime('+2 days'));
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO library_reservations (book_id, user_id, expiry_date, status, notes)
                        VALUES (?, ?, ?, 'menunggu', ?)
                    ");
                    $stmt_ins->execute([$book_id, $user_id, $expiry_date, $notes]);
                    logActivity($pdo, 'SELF_RESERVE_BOOK', "Siswa ID #$user_id melakukan booking mandiri buku '{$target_book['title']}'");
                    
                    $message = "Buku '{$target_book['title']}' berhasil di-booking! Buku akan disiapkan di meja sirkulasi hingga " . date('d M Y', strtotime($expiry_date)) . ". Harap bawa kartu pelajar saat mengambil.";
                    $message_type = "success";
                }
            }
        }
    }

    // ACTION: BATALKAN RESERVASI SENDIRI
    elseif ($_POST['action'] === 'cancel_my_reservation') {
        if (!$is_logged_in) {
            $message = "Akses ditolak.";
            $message_type = "error";
        } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $message = "Token keamanan tidak valid.";
            $message_type = "error";
        } else {
            $res_id = (int)($_POST['reservation_id'] ?? 0);
            $stmt_can = $pdo->prepare("UPDATE library_reservations SET status = 'dibatalkan' WHERE id = ? AND user_id = ? AND status IN ('menunggu', 'disiapkan')");
            $stmt_can->execute([$res_id, $user_id]);
            if ($stmt_can->rowCount() > 0) {
                logActivity($pdo, 'CANCEL_SELF_RESERVATION', "Siswa ID #$user_id membatalkan booking buku ID #$res_id");
                $message = "Booking buku Anda berhasil dibatalkan.";
                $message_type = "success";
            } else {
                $message = "Pemesanan tidak dapat dibatalkan atau status sudah berubah.";
                $message_type = "error";
            }
        }
    }

    // ACTION: SUBMIT REVIEW & RATING
    elseif ($_POST['action'] === 'submit_review') {
        if (!$is_logged_in) {
            $message = "Harap masuk ke akun Anda terlebih dahulu untuk memberikan ulasan.";
            $message_type = "error";
        } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $message = "Token keamanan tidak valid.";
            $message_type = "error";
        } else {
            $book_id = (int)($_POST['book_id'] ?? 0);
            $rating  = max(1, min(5, (int)($_POST['rating'] ?? 5)));
            $review  = trim($_POST['review'] ?? '');

            if (empty($review)) {
                $message = "Harap tuliskan ulasan atau tanggapan Anda mengenai buku ini.";
                $message_type = "error";
            } else {
                $stmt_rev = $pdo->prepare("
                    INSERT INTO library_reviews (book_id, user_id, rating, review, created_at)
                    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE rating = VALUES(rating), review = VALUES(review), created_at = CURRENT_TIMESTAMP
                ");
                $stmt_rev->execute([$book_id, $user_id, $rating, $review]);
                logActivity($pdo, 'SUBMIT_BOOK_REVIEW', "User ID #$user_id memberikan rating $rating bintang untuk buku ID #$book_id");

                $message = "Terima kasih! Ulasan dan rating ($rating ★) Anda telah berhasil dipublikasikan.";
                $message_type = "success";
            }
        }
    }
}

// -------------------------------------------------------------
// 2. STATISTIK PERPUSTAKAAN DARI DATABASE
// -------------------------------------------------------------
$stat_total_titles     = 0;
$stat_total_copies     = 0;
$stat_available_copies = 0;
$stat_ebooks           = 0;
$categories_with_count = [];

try {
    $stat_total_titles     = (int)$pdo->query("SELECT COUNT(*) FROM library_books")->fetchColumn();
    $stat_total_copies     = (int)$pdo->query("SELECT COALESCE(SUM(stock_total), 0) FROM library_books")->fetchColumn();
    $stat_available_copies = (int)$pdo->query("SELECT COALESCE(SUM(stock_available), 0) FROM library_books")->fetchColumn();
    $stat_ebooks           = (int)$pdo->query("SELECT COUNT(*) FROM library_books WHERE ebook_file IS NOT NULL AND ebook_file != ''")->fetchColumn();

    $stmt_cat = $pdo->query("SELECT category, COUNT(*) as cnt FROM library_books GROUP BY category ORDER BY category ASC");
    $categories_with_count = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// -------------------------------------------------------------
// 3. TANGANI FILTER, PENCARIAN & SORTING
// -------------------------------------------------------------
$search_query    = trim($_GET['q'] ?? '');
$filter_category = trim($_GET['category'] ?? '');
$filter_avail    = trim($_GET['avail'] ?? 'all');
$sort_by         = trim($_GET['sort'] ?? 'latest');

$where_clauses = [];
$params = [];

if (!empty($search_query)) {
    $where_clauses[] = "(title LIKE ? OR author LIKE ? OR publisher LIKE ? OR code LIKE ? OR isbn LIKE ? OR description LIKE ?)";
    $term = "%{$search_query}%";
    $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
}

if (!empty($filter_category)) {
    $where_clauses[] = "category = ?";
    $params[] = $filter_category;
}

if ($filter_avail === 'available') {
    $where_clauses[] = "stock_available > 0";
} elseif ($filter_avail === 'ebook') {
    $where_clauses[] = "ebook_file IS NOT NULL AND ebook_file != ''";
}

$order_by = "id DESC";
if ($sort_by === 'title_asc') {
    $order_by = "title ASC";
} elseif ($sort_by === 'title_desc') {
    $order_by = "title DESC";
} elseif ($sort_by === 'year_desc') {
    $order_by = "year DESC, id DESC";
} elseif ($sort_by === 'stock_desc') {
    $order_by = "stock_available DESC, id DESC";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";
$sql = "SELECT * FROM library_books {$where_sql} ORDER BY {$order_by}";
$stmt_books = $pdo->prepare($sql);
$stmt_books->execute($params);
$books = $stmt_books->fetchAll(PDO::FETCH_ASSOC);
$total_filtered = count($books);

// -------------------------------------------------------------
// 4. FETCH RATINGS SUMMARY & REVIEWS PER BUKU
// -------------------------------------------------------------
$ratings_summary = [];
try {
    $stmt_rs = $pdo->query("
        SELECT book_id, 
               ROUND(AVG(rating), 1) as avg_rating, 
               COUNT(*) as total_reviews 
        FROM library_reviews 
        GROUP BY book_id
    ");
    while ($row = $stmt_rs->fetch(PDO::FETCH_ASSOC)) {
        $ratings_summary[(int)$row['book_id']] = [
            'avg'   => (float)$row['avg_rating'],
            'count' => (int)$row['total_reviews']
        ];
    }
} catch (Exception $e) {}

$all_reviews = [];
try {
    $stmt_rv = $pdo->query("
        SELECT r.*, u.name as reviewer_name, u.role as reviewer_role 
        FROM library_reviews r 
        JOIN users u ON r.user_id = u.id 
        ORDER BY r.id DESC
    ");
    while ($row = $stmt_rv->fetch(PDO::FETCH_ASSOC)) {
        $all_reviews[(int)$row['book_id']][] = $row;
    }
} catch (Exception $e) {}

// Ambil booking aktif milik user saat ini
$user_active_reservations = [];
if ($is_logged_in) {
    try {
        $stmt_ures = $pdo->prepare("
            SELECT id, book_id, status, expiry_date 
            FROM library_reservations 
            WHERE user_id = ? AND status IN ('menunggu', 'disiapkan')
        ");
        $stmt_ures->execute([$user_id]);
        while ($row = $stmt_ures->fetch(PDO::FETCH_ASSOC)) {
            $user_active_reservations[(int)$row['book_id']] = $row;
        }
    } catch (Exception $e) {}
}

// Helper warna tema kategori
function getCategoryColor(string $cat): array {
    $c = strtolower($cat);
    if (str_contains($c, 'sains') || str_contains($c, 'ipa') || str_contains($c, 'matematika')) {
        return ['border' => 'border-cyan-500/30', 'bg' => 'bg-cyan-500/10', 'text' => 'text-cyan-300', 'gradient' => 'from-cyan-900/60 to-slate-900', 'icon' => 'fa-atom'];
    } elseif (str_contains($c, 'novel') || str_contains($c, 'sastra') || str_contains($c, 'fiksi')) {
        return ['border' => 'border-rose-500/30', 'bg' => 'bg-rose-500/10', 'text' => 'text-rose-300', 'gradient' => 'from-rose-900/60 to-slate-900', 'icon' => 'fa-feather'];
    } elseif (str_contains($c, 'teknologi') || str_contains($c, 'komputer') || str_contains($c, 'it')) {
        return ['border' => 'border-indigo-500/30', 'bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-300', 'gradient' => 'from-indigo-900/60 to-slate-900', 'icon' => 'fa-laptop-code'];
    } elseif (str_contains($c, 'bahasa') || str_contains($c, 'kamus') || str_contains($c, 'referensi')) {
        return ['border' => 'border-amber-500/30', 'bg' => 'bg-amber-500/10', 'text' => 'text-amber-300', 'gradient' => 'from-amber-900/60 to-slate-900', 'icon' => 'fa-book-atlas'];
    } elseif (str_contains($c, 'sejarah') || str_contains($c, 'ips') || str_contains($c, 'sosial')) {
        return ['border' => 'border-emerald-500/30', 'bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-300', 'gradient' => 'from-emerald-900/60 to-slate-900', 'icon' => 'fa-landmark'];
    } elseif (str_contains($c, 'agama') || str_contains($c, 'islam')) {
        return ['border' => 'border-teal-500/30', 'bg' => 'bg-teal-500/10', 'text' => 'text-teal-300', 'gradient' => 'from-teal-900/60 to-slate-900', 'icon' => 'fa-moon'];
    }
    return ['border' => 'border-blue-500/30', 'bg' => 'bg-blue-500/10', 'text' => 'text-blue-300', 'gradient' => 'from-blue-900/60 to-slate-900', 'icon' => 'fa-book'];
}
?>
<!DOCTYPE html>
<html lang="id" class="dark scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perpustakaan Digital - <?= htmlspecialchars($school_info['school_name'] ?? 'Sistem Manajemen Sekolah') ?></title>
    
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .glass { background: rgba(15, 23, 42, 0.75); backdrop-filter: blur(16px); }
        .star-active { color: #f59e0b; }
        .star-inactive { color: #475569; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-indigo-500 selection:text-white flex flex-col justify-between">

    <?php
    $nav_active = 'perpustakaan';
    require_once __DIR__ . '/includes/navbar.php';
    ?>

    <main class="flex-grow">

        <!-- Notification Message Bar -->
        <?php if (!empty($message)): ?>
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-6">
                <div class="rounded-2xl border p-4 text-xs sm:text-sm font-medium flex items-center justify-between gap-3 shadow-xl <?= $message_type === 'success' ? 'border-emerald-500/40 bg-emerald-950/60 text-emerald-300' : 'border-rose-500/40 bg-rose-950/60 text-rose-300' ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-lg text-emerald-400' : 'fa-circle-exclamation text-lg text-rose-400' ?>"></i>
                        <span><?= htmlspecialchars($message) ?></span>
                    </div>
                    <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white text-xs">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- ================= HERO SECTION ================= -->
        <section class="relative overflow-hidden pt-10 pb-14 lg:pt-14 lg:pb-18 border-b border-white/10">
            <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-indigo-600/15 rounded-full blur-3xl -z-10 pointer-events-none"></div>
            <div class="absolute top-1/3 right-10 w-80 h-80 bg-teal-500/10 rounded-full blur-3xl -z-10 pointer-events-none"></div>

            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 text-center">
                
                <div class="inline-flex items-center gap-2 rounded-full border border-indigo-400/30 bg-indigo-500/10 px-4 py-1.5 text-xs font-semibold text-indigo-300 mb-6">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-indigo-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-indigo-500"></span>
                    </span>
                    <span>OPAC • Online Public Access Catalog & E-Library</span>
                </div>

                <h1 class="text-3xl sm:text-5xl lg:text-6xl font-extrabold text-white tracking-tight leading-tight max-w-4xl mx-auto">
                    Katalog Buku & Pustaka Terbuka <br class="hidden sm:inline">
                    <span class="bg-gradient-to-r from-indigo-400 via-sky-400 to-teal-300 bg-clip-text text-transparent">
                        <?= htmlspecialchars($school_info['school_name'] ?? 'Sekolah Unggulan') ?>
                    </span>
                </h1>

                <p class="mt-4 text-sm sm:text-base text-slate-400 max-w-2xl mx-auto leading-relaxed">
                    Eksplorasi koleksi literasi, baca e-book secara online, beri rating & ulasan komunitas, serta lakukan reservasi/booking buku secara mandiri sebelum ke perpustakaan.
                </p>

                <!-- Search Input Bar Hero -->
                <form action="perpustakaan.php" method="GET" class="mt-8 max-w-2xl mx-auto">
                    <?php if (!empty($filter_category)): ?>
                        <input type="hidden" name="category" value="<?= htmlspecialchars($filter_category) ?>">
                    <?php endif; ?>
                    <?php if ($filter_avail !== 'all'): ?>
                        <input type="hidden" name="avail" value="<?= htmlspecialchars($filter_avail) ?>">
                    <?php endif; ?>

                    <div class="relative flex items-center rounded-2xl border border-white/15 bg-slate-900/90 shadow-2xl p-2 focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-500/30 transition backdrop-blur">
                        <div class="pl-3.5 pr-2 text-slate-400">
                            <i class="fa-solid fa-magnifying-glass text-lg"></i>
                        </div>
                        <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>"
                               placeholder="Cari judul buku, penulis, penerbit, nomor ISBN, atau kode rak..."
                               class="w-full bg-transparent px-2 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none">
                        
                        <?php if (!empty($search_query)): ?>
                            <a href="perpustakaan.php<?= !empty($filter_category) ? '?category=' . urlencode($filter_category) : '' ?>" 
                               class="text-slate-400 hover:text-white px-2 text-xs" title="Hapus pencarian">
                                <i class="fa-solid fa-xmark"></i>
                            </a>
                        <?php endif; ?>

                        <button type="submit" 
                                class="rounded-xl bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-500 hover:to-blue-500 px-5 py-2.5 text-xs sm:text-sm font-bold text-white shadow-lg shadow-indigo-600/30 transition cursor-pointer flex items-center gap-2 shrink-0">
                            <span>Cari Buku</span>
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </button>
                    </div>
                </form>

                <!-- Quick Stats Bar -->
                <div class="mt-10 grid grid-cols-2 sm:grid-cols-4 gap-4 max-w-4xl mx-auto">
                    <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 text-center backdrop-blur">
                        <span class="text-2xl font-black text-white font-mono"><?= number_format($stat_total_titles) ?></span>
                        <span class="block text-xs text-slate-400 mt-0.5">Judul Buku Terdaftar</span>
                    </div>
                    <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 text-center backdrop-blur">
                        <span class="text-2xl font-black text-emerald-400 font-mono"><?= number_format($stat_available_copies) ?></span>
                        <span class="block text-xs text-slate-400 mt-0.5">Eksemplar Tersedia</span>
                    </div>
                    <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 text-center backdrop-blur">
                        <span class="text-2xl font-black text-indigo-400 font-mono"><?= count($categories_with_count) ?></span>
                        <span class="block text-xs text-slate-400 mt-0.5">Kategori Ilmu</span>
                    </div>
                    <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 text-center backdrop-blur">
                        <span class="text-2xl font-black text-amber-400 font-mono"><?= number_format($stat_ebooks) ?></span>
                        <span class="block text-xs text-slate-400 mt-0.5">Buku Digital (E-Book)</span>
                    </div>
                </div>

            </div>
        </section>

        <!-- ================= CATALOG CONTENT SECTION ================= -->
        <section class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

                <!-- Control Bar: Kategori Pills & Filter -->
                <div class="space-y-4 mb-8">
                    
                    <!-- Kategori Pills Scrollable -->
                    <div class="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none">
                        <a href="perpustakaan.php?<?= http_build_query(array_merge($_GET, ['category' => ''])) ?>"
                           class="rounded-xl px-4 py-2 text-xs font-bold whitespace-nowrap transition <?= empty($filter_category) ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-slate-900 text-slate-300 border border-white/10 hover:bg-slate-800' ?>">
                            Semua Kategori (<?= $stat_total_titles ?>)
                        </a>

                        <?php foreach ($categories_with_count as $cat): ?>
                            <a href="perpustakaan.php?<?= http_build_query(array_merge($_GET, ['category' => $cat['category']])) ?>"
                               class="rounded-xl px-4 py-2 text-xs font-semibold whitespace-nowrap transition <?= $filter_category === $cat['category'] ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'bg-slate-900 text-slate-300 border border-white/10 hover:bg-slate-800' ?>">
                                <?= htmlspecialchars($cat['category']) ?>
                                <span class="ml-1 opacity-70">(<?= $cat['cnt'] ?>)</span>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <!-- Secondary Filter & Sort Toolbar -->
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 rounded-2xl border border-white/10 bg-slate-900/50 p-3 sm:px-4 backdrop-blur">
                        
                        <!-- Status Ketersediaan Pills -->
                        <div class="flex items-center gap-1.5 overflow-x-auto text-xs">
                            <span class="text-slate-400 text-xs hidden sm:inline mr-1">Status:</span>
                            <a href="perpustakaan.php?<?= http_build_query(array_merge($_GET, ['avail' => 'all'])) ?>"
                               class="rounded-lg px-3 py-1.5 font-medium transition <?= $filter_avail === 'all' ? 'bg-white/15 text-white font-semibold' : 'text-slate-400 hover:text-white' ?>">
                                Semua Buku
                            </a>
                            <a href="perpustakaan.php?<?= http_build_query(array_merge($_GET, ['avail' => 'available'])) ?>"
                               class="rounded-lg px-3 py-1.5 font-medium transition flex items-center gap-1.5 <?= $filter_avail === 'available' ? 'bg-emerald-500/20 text-emerald-300 font-semibold border border-emerald-500/30' : 'text-slate-400 hover:text-emerald-300' ?>">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                                Tersedia Dipinjam
                            </a>
                            <a href="perpustakaan.php?<?= http_build_query(array_merge($_GET, ['avail' => 'ebook'])) ?>"
                               class="rounded-lg px-3 py-1.5 font-medium transition flex items-center gap-1.5 <?= $filter_avail === 'ebook' ? 'bg-amber-500/20 text-amber-300 font-semibold border border-amber-500/30' : 'text-slate-400 hover:text-amber-300' ?>">
                                <i class="fa-solid fa-file-pdf text-xs"></i>
                                E-Book Reader
                            </a>
                        </div>

                        <!-- Dropdown Sorting & Reset -->
                        <div class="flex items-center gap-2 justify-end">
                            <form id="sortForm" action="perpustakaan.php" method="GET" class="flex items-center gap-2">
                                <?php if (!empty($search_query)): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search_query) ?>"><?php endif; ?>
                                <?php if (!empty($filter_category)): ?><input type="hidden" name="category" value="<?= htmlspecialchars($filter_category) ?>"><?php endif; ?>
                                <?php if ($filter_avail !== 'all'): ?><input type="hidden" name="avail" value="<?= htmlspecialchars($filter_avail) ?>"><?php endif; ?>
                                
                                <label for="sortSelect" class="text-xs text-slate-400 shrink-0 hidden sm:inline">Urutkan:</label>
                                <select id="sortSelect" name="sort" onchange="this.form.submit()"
                                        class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-slate-300 focus:border-indigo-500 focus:outline-none">
                                    <option value="latest" <?= $sort_by === 'latest' ? 'selected' : '' ?>>Katalog Terbaru</option>
                                    <option value="title_asc" <?= $sort_by === 'title_asc' ? 'selected' : '' ?>>Judul (A - Z)</option>
                                    <option value="title_desc" <?= $sort_by === 'title_desc' ? 'selected' : '' ?>>Judul (Z - A)</option>
                                    <option value="year_desc" <?= $sort_by === 'year_desc' ? 'selected' : '' ?>>Tahun Terbit (Terbaru)</option>
                                    <option value="stock_desc" <?= $sort_by === 'stock_desc' ? 'selected' : '' ?>>Stok Terbanyak</option>
                                </select>
                            </form>

                            <?php if (!empty($search_query) || !empty($filter_category) || $filter_avail !== 'all' || $sort_by !== 'latest'): ?>
                                <a href="perpustakaan.php" class="rounded-xl border border-rose-500/20 bg-rose-500/10 px-3 py-1.5 text-xs font-semibold text-rose-300 hover:bg-rose-500/20 transition flex items-center gap-1.5" title="Reset Semua Filter">
                                    <i class="fa-solid fa-rotate-left"></i>
                                    <span class="hidden sm:inline">Reset</span>
                                </a>
                            <?php endif; ?>
                        </div>

                    </div>

                    <!-- Indikator Jumlah Hasil -->
                    <div class="flex items-center justify-between text-xs text-slate-400 px-1">
                        <div>
                            Menampilkan <strong class="text-white"><?= number_format($total_filtered) ?></strong> buku
                            <?php if (!empty($search_query)): ?>
                                untuk kata kunci <span class="text-indigo-400">"<?= htmlspecialchars($search_query) ?>"</span>
                            <?php endif; ?>
                        </div>
                        <span class="text-[11px] text-slate-500">Pembaruan data otomatis realtime</span>
                    </div>

                </div>

                <!-- Buku Grid -->
                <?php if (empty($books)): ?>
                    <div class="rounded-3xl border border-white/10 bg-slate-900/40 p-12 text-center backdrop-blur max-w-lg mx-auto my-8">
                        <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-500/10 text-3xl text-indigo-400 mx-auto mb-4 border border-indigo-500/20">
                            <i class="fa-solid fa-book-bookmark"></i>
                        </div>
                        <h3 class="text-lg font-bold text-white">Tidak Ada Buku yang Cocok</h3>
                        <p class="text-xs text-slate-400 mt-2 leading-relaxed">
                            Koleksi buku dengan kriteria pencarian atau filter yang Anda pilih belum tersedia. Silakan gunakan kata kunci lain atau reset filter.
                        </p>
                        <div class="mt-6">
                            <a href="perpustakaan.php" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 px-5 py-2.5 text-xs font-semibold text-white shadow-lg shadow-indigo-600/25 transition">
                                <i class="fa-solid fa-rotate-left"></i> Tampilkan Semua Koleksi
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                        <?php foreach ($books as $b): 
                            $b_id = (int)$b['id'];
                            $is_avail = (int)$b['stock_available'] > 0;
                            $cat_theme = getCategoryColor($b['category'] ?? 'Umum');
                            $has_cover = !empty($b['cover_image']) && file_exists(__DIR__ . '/' . $b['cover_image']);
                            $has_ebook = !empty($b['ebook_file']);
                            
                            $b_rating = $ratings_summary[$b_id] ?? null;
                            $user_booked = $user_active_reservations[$b_id] ?? null;
                            $book_reviews = $all_reviews[$b_id] ?? [];
                        ?>
                            <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur hover:border-indigo-500/50 hover:bg-slate-900/90 transition duration-300 flex flex-col justify-between group shadow-xl hover:-translate-y-1">
                                <div>
                                    <!-- Book Cover Artwork -->
                                    <div class="relative w-full aspect-[16/10] rounded-2xl overflow-hidden mb-4 border border-white/5 bg-gradient-to-br <?= $cat_theme['gradient'] ?> flex items-center justify-center group-hover:shadow-lg transition">
                                        <?php if ($has_cover): ?>
                                            <img src="<?= htmlspecialchars($b['cover_image']) ?>" alt="<?= htmlspecialchars($b['title']) ?>" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <div class="text-center p-4">
                                                <i class="fa-solid <?= $cat_theme['icon'] ?> text-4xl text-white/30 group-hover:scale-110 transition duration-300"></i>
                                                <span class="block text-[11px] font-mono font-bold text-white/60 mt-2"><?= htmlspecialchars($b['code']) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Badge Status Stok Floating -->
                                        <div class="absolute top-2.5 right-2.5 flex flex-col gap-1 items-end">
                                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold border backdrop-blur-md shadow-md <?= $is_avail ? 'border-emerald-500/40 bg-emerald-950/80 text-emerald-300' : 'border-rose-500/40 bg-rose-950/80 text-rose-300' ?>">
                                                <span class="h-1.5 w-1.5 rounded-full <?= $is_avail ? 'bg-emerald-400' : 'bg-rose-400' ?>"></span>
                                                <?= $is_avail ? "Tersedia ({$b['stock_available']})" : "Dipinjam Habis" ?>
                                            </span>

                                            <?php if ($user_booked): ?>
                                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[9px] font-bold border border-amber-500/40 bg-amber-950/90 text-amber-300 backdrop-blur-md shadow-md">
                                                    <i class="fa-solid fa-bookmark"></i> Anda Pesan
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Badge E-Book Floating -->
                                        <?php if ($has_ebook): ?>
                                            <div class="absolute bottom-2.5 left-2.5">
                                                <button type="button" 
                                                        onclick="openPdfReader('<?= htmlspecialchars(addslashes($b['title'])) ?>', '<?= htmlspecialchars(addslashes($b['ebook_file'])) ?>')"
                                                        class="inline-flex items-center gap-1.5 rounded-xl px-2.5 py-1 text-[10px] font-bold border border-amber-500/40 bg-amber-600/90 hover:bg-amber-500 text-white backdrop-blur-md shadow-md transition cursor-pointer">
                                                    <i class="fa-solid fa-book-open-reader"></i> Baca E-Book
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Kategori & Rating Review Star -->
                                    <div class="flex items-center justify-between gap-2 mb-2">
                                        <span class="inline-flex items-center gap-1 rounded-lg border px-2 py-0.5 text-[11px] font-bold <?= $cat_theme['border'] ?> <?= $cat_theme['bg'] ?> <?= $cat_theme['text'] ?>">
                                            <i class="fa-solid <?= $cat_theme['icon'] ?> text-[10px]"></i>
                                            <?= htmlspecialchars($b['category']) ?>
                                        </span>

                                        <!-- Star Rating Badge -->
                                        <div class="flex items-center gap-1 text-[11px] text-amber-400 font-semibold" title="<?= $b_rating ? $b_rating['avg'] . ' dari 5 bintang (' . $b_rating['count'] . ' ulasan)' : 'Belum ada ulasan' ?>">
                                            <i class="fa-solid fa-star text-xs"></i>
                                            <span><?= $b_rating ? number_format($b_rating['avg'], 1) : '-' ?></span>
                                            <span class="text-slate-500 text-[10px]">(<?= $b_rating ? $b_rating['count'] : '0' ?>)</span>
                                        </div>
                                    </div>

                                    <!-- Judul Buku -->
                                    <h3 class="text-base font-bold text-white group-hover:text-indigo-300 transition line-clamp-2 leading-snug">
                                        <?= htmlspecialchars($b['title']) ?>
                                    </h3>

                                    <!-- Pengarang & Tahun -->
                                    <p class="text-xs text-slate-400 mt-1.5 line-clamp-1">
                                        Oleh: <strong class="text-slate-200"><?= htmlspecialchars($b['author']) ?></strong>
                                    </p>
                                    <div class="flex items-center justify-between text-[11px] text-slate-400 mt-1">
                                        <span><?= htmlspecialchars($b['publisher'] ?: '-') ?> <?= $b['year'] ? "({$b['year']})" : '' ?></span>
                                        <span class="font-mono text-emerald-400 flex items-center gap-1">
                                            <i class="fa-solid fa-location-dot text-slate-500"></i> <?= htmlspecialchars($b['shelf_location'] ?: 'Rak A-1') ?>
                                        </span>
                                    </div>

                                    <!-- Deskripsi Singkat -->
                                    <?php if (!empty($b['description'])): ?>
                                        <p class="text-xs text-slate-400 mt-3 line-clamp-2 leading-relaxed">
                                            <?= htmlspecialchars($b['description']) ?>
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <!-- Action Buttons -->
                                <div class="mt-5 pt-4 border-t border-white/5 flex items-center gap-2">
                                    <button type="button" 
                                            onclick='openBookDetailModal(<?= json_encode($b, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($b_rating) ?>, <?= json_encode($book_reviews, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= json_encode($user_booked) ?>)'
                                            class="w-full rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-xs font-semibold text-slate-200 transition flex items-center justify-center gap-1.5 cursor-pointer">
                                        <i class="fa-solid fa-circle-info text-indigo-400"></i>
                                        <span>Detail & Ulasan</span>
                                    </button>

                                    <!-- Tombol Reservasi / Booking Mandiri -->
                                    <?php if ($is_logged_in): ?>
                                        <?php if ($user_booked): ?>
                                            <button type="button" 
                                                    onclick="openCancelReservationModal(<?= $user_booked['id'] ?>, '<?= htmlspecialchars(addslashes($b['title'])) ?>')"
                                                    class="rounded-xl border border-amber-500/40 bg-amber-500/20 hover:bg-rose-500/20 hover:border-rose-500/40 px-3 py-2 text-xs font-bold text-amber-300 hover:text-rose-300 transition flex items-center gap-1 shrink-0 cursor-pointer"
                                                    title="Pemesanan aktif. Klik untuk batalkan.">
                                                <i class="fa-solid fa-bookmark"></i>
                                                <span class="hidden sm:inline">Dipesan</span>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" 
                                                    onclick="openReservationModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['title'])) ?>', '<?= htmlspecialchars(addslashes($b['code'])) ?>', <?= (int)$b['stock_available'] ?>)"
                                                    class="rounded-xl bg-indigo-600 hover:bg-indigo-500 px-3 py-2 text-xs font-bold text-white shadow-md shadow-indigo-600/25 transition flex items-center gap-1.5 shrink-0 cursor-pointer"
                                                    title="Booking / Pesan buku ini sekarang">
                                                <i class="fa-solid fa-bookmark"></i>
                                                <span>Booking</span>
                                            </button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <a href="auth/login.php" 
                                           title="Masuk untuk meminjam atau booking buku ini"
                                           class="rounded-xl bg-indigo-600/20 hover:bg-indigo-600/30 border border-indigo-500/30 px-3 py-2 text-xs font-bold text-indigo-300 transition flex items-center justify-center gap-1 shrink-0">
                                            <i class="fa-solid fa-arrow-right-to-bracket"></i>
                                            <span>Pinjam</span>
                                        </a>
                                    <?php endif; ?>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </section>

        <!-- ================= PANDUAN SIRKULASI & LAYANAN ================= -->
        <section class="py-16 border-t border-white/10 bg-slate-900/30">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                
                <div class="text-center max-w-2xl mx-auto mb-12">
                    <span class="inline-block rounded-full bg-indigo-500/10 px-3.5 py-1 text-xs font-bold text-indigo-300 border border-indigo-500/20 mb-3">
                        Layanan Terpadu
                    </span>
                    <h2 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                        Cara Mudah Booking & Meminjam Buku
                    </h2>
                    <p class="text-xs sm:text-sm text-slate-400 mt-2">
                        Perpustakaan <?= htmlspecialchars($school_info['school_name'] ?? 'Sekolah') ?> mendukung sirkulasi cepat berbasis barcode, reservasi online, dan koleksi e-book digital.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="rounded-3xl border border-white/10 bg-slate-950 p-6 backdrop-blur space-y-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-600/20 text-indigo-400 text-xl border border-indigo-500/30">
                            <i class="fa-solid fa-bookmark"></i>
                        </div>
                        <h3 class="text-base font-bold text-white">1. Booking Online Mandiri</h3>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Cari judul buku di katalog ini, klik tombol <strong>"Booking"</strong>. Buku pilihan Anda otomatis ditahan dan disimpan di meja sirkulasi selama 2 hari kerja.
                        </p>
                    </div>

                    <div class="rounded-3xl border border-white/10 bg-slate-950 p-6 backdrop-blur space-y-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-600/20 text-teal-400 text-xl border border-teal-500/30">
                            <i class="fa-solid fa-id-card"></i>
                        </div>
                        <h3 class="text-base font-bold text-white">2. Ambil & Scan Kartu</h3>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Kunjungi perpustakaan dan tunjukkan kartu pelajar atau sebutkan nama Anda ke petugas. Buku langsung diserahkan tanpa perlu antre mencari di rak.
                        </p>
                    </div>

                    <div class="rounded-3xl border border-white/10 bg-slate-950 p-6 backdrop-blur space-y-3">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-600/20 text-amber-400 text-xl border border-amber-500/30">
                            <i class="fa-solid fa-star"></i>
                        </div>
                        <h3 class="text-base font-bold text-white">3. Baca & Beri Ulasan</h3>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Nikmati membaca buku fisik atau e-book langsung di peramban web. Berikan rating bintang dan ulasan untuk menginspirasi teman-teman lainnya!
                        </p>
                    </div>
                </div>

                <div class="mt-8 rounded-2xl border border-indigo-500/20 bg-gradient-to-r from-indigo-950/40 via-slate-900 to-slate-900 p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-500/20 text-indigo-400 text-lg">
                            <i class="fa-solid fa-clock"></i>
                        </span>
                        <div>
                            <h4 class="text-sm font-bold text-white">Jam Operasional Layanan Perpustakaan</h4>
                            <p class="text-xs text-slate-400">Senin – Jumat: 07.30 – 16.00 WIB • Istirahat: 12.00 – 13.00 WIB (Sabtu, Minggu & Libur Tutup)</p>
                        </div>
                    </div>
                    <?php if ($is_logged_in): ?>
                        <a href="dashboard/perpustakaan/reservations.php" class="rounded-xl bg-indigo-600 hover:bg-indigo-500 px-5 py-2.5 text-xs font-bold text-white shadow-lg shadow-indigo-600/25 transition shrink-0">
                            Kelola Reservasi Saya →
                        </a>
                    <?php else: ?>
                        <a href="auth/login.php" class="rounded-xl bg-indigo-600 hover:bg-indigo-500 px-5 py-2.5 text-xs font-bold text-white shadow-lg shadow-indigo-600/25 transition shrink-0">
                            Masuk ke Akun Literasi →
                        </a>
                    <?php endif; ?>
                </div>

            </div>
        </section>

    </main>

    <?php
    require_once __DIR__ . '/includes/footer.php';
    ?>

    <!-- ================= MODAL DETAIL BUKU & REVIEWS ================= -->
    <div id="bookModal" class="fixed inset-0 z-50 hidden bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-4 overflow-y-auto">
        <div class="relative w-full max-w-3xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl my-8 text-slate-100 max-h-[90vh] overflow-y-auto">
            
            <!-- Close Button -->
            <button onclick="closeBookDetailModal()" class="absolute top-5 right-5 text-slate-400 hover:text-white transition h-8 w-8 rounded-full bg-white/5 flex items-center justify-center cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <!-- Book Header Info -->
            <div class="flex flex-col sm:flex-row gap-6 border-b border-white/10 pb-6 mb-6">
                <!-- Cover / Placeholder -->
                <div id="modalCoverContainer" class="w-full sm:w-44 aspect-[3/4] shrink-0 rounded-2xl border border-white/10 bg-slate-950 flex flex-col items-center justify-center p-2 overflow-hidden shadow-inner">
                    <img id="modalCoverImg" src="" alt="Sampul Buku" class="w-full h-full object-cover rounded-xl hidden">
                    <div id="modalCoverPlaceholder" class="text-center p-4">
                        <i class="fa-solid fa-book-open text-4xl text-indigo-400 mb-2"></i>
                        <span id="modalCodeBadge" class="block text-xs font-mono font-bold text-slate-400"></span>
                    </div>
                </div>

                <!-- Info -->
                <div class="flex-grow space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span id="modalCategory" class="inline-block rounded-lg px-2.5 py-0.5 text-[11px] font-bold border border-indigo-500/30 bg-indigo-500/10 text-indigo-300"></span>
                        <span id="modalStockBadge" class="inline-block rounded-lg px-2.5 py-0.5 text-[11px] font-bold"></span>
                        <span id="modalRatingBadge" class="inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-[11px] font-bold border border-amber-500/30 bg-amber-500/10 text-amber-300"></span>
                    </div>

                    <h2 id="modalTitle" class="text-xl sm:text-2xl font-black text-white leading-snug"></h2>
                    
                    <p class="text-xs text-slate-300">
                        Penulis: <strong id="modalAuthor" class="text-white"></strong>
                    </p>

                    <!-- Meta Specs Table -->
                    <div class="rounded-2xl bg-slate-950/70 border border-white/5 p-3.5 space-y-2 text-xs">
                        <div class="flex justify-between border-b border-white/5 pb-1.5">
                            <span class="text-slate-400">Nomor Panggil / Barcode:</span>
                            <span id="modalCode" class="font-mono font-bold text-indigo-300"></span>
                        </div>
                        <div class="flex justify-between border-b border-white/5 pb-1.5">
                            <span class="text-slate-400">Nomor ISBN:</span>
                            <span id="modalIsbn" class="font-mono text-slate-200"></span>
                        </div>
                        <div class="flex justify-between border-b border-white/5 pb-1.5">
                            <span class="text-slate-400">Penerbit & Tahun:</span>
                            <span id="modalPublisherYear" class="text-slate-200"></span>
                        </div>
                        <div class="flex justify-between border-b border-white/5 pb-1.5">
                            <span class="text-slate-400">Lokasi Penempatan Rak:</span>
                            <span id="modalShelf" class="font-semibold text-emerald-400"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Ketersediaan Stok Fisik:</span>
                            <span id="modalStockText" class="font-bold text-slate-200"></span>
                        </div>
                    </div>

                    <!-- Quick Action in Modal Header -->
                    <div id="modalActionContainer" class="pt-2 flex flex-wrap items-center gap-2"></div>
                </div>
            </div>

            <!-- Sinopsis / Ringkasan -->
            <div class="mb-6">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Sinopsis & Ringkasan Buku:</h4>
                <p id="modalDesc" class="text-xs sm:text-sm text-slate-300 leading-relaxed max-h-36 overflow-y-auto pr-1 bg-slate-950/40 p-3.5 rounded-2xl border border-white/5"></p>
            </div>

            <!-- Section Review & Rating -->
            <div class="border-t border-white/10 pt-6">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-comments text-indigo-400"></i>
                        <h4 class="text-sm font-bold text-white">Ulasan & Rating Pembaca</h4>
                    </div>
                    <span id="modalReviewCount" class="text-xs text-slate-400"></span>
                </div>

                <!-- Form Beri Ulasan (Jika Login) -->
                <?php if ($is_logged_in): ?>
                    <form action="perpustakaan.php" method="POST" class="rounded-2xl border border-indigo-500/20 bg-slate-950 p-4 mb-6 space-y-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="submit_review">
                        <input type="hidden" name="book_id" id="reviewFormBookId" value="">
                        
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-slate-300">Bagikan Pengalaman Membaca Anda:</span>
                            
                            <!-- Star Picker Interaktif -->
                            <div class="flex items-center gap-1" id="starPickerContainer">
                                <input type="hidden" name="rating" id="reviewRatingInput" value="5">
                                <span class="text-xs text-slate-400 mr-1.5">Rating:</span>
                                <div class="flex items-center gap-1 cursor-pointer text-base text-amber-400" id="starPickerStars">
                                    <i class="fa-solid fa-star star-opt" data-val="1"></i>
                                    <i class="fa-solid fa-star star-opt" data-val="2"></i>
                                    <i class="fa-solid fa-star star-opt" data-val="3"></i>
                                    <i class="fa-solid fa-star star-opt" data-val="4"></i>
                                    <i class="fa-solid fa-star star-opt" data-val="5"></i>
                                </div>
                                <span id="starRatingLabel" class="text-xs font-bold text-amber-400 ml-1">5.0</span>
                            </div>
                        </div>

                        <textarea name="review" required rows="2.5" placeholder="Tuliskan ulasan Anda mengenai isi buku, alur cerita, atau wawasan yang Anda dapatkan..."
                                  class="w-full rounded-xl border border-white/10 bg-slate-900 px-3.5 py-2 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"></textarea>

                        <div class="flex justify-end">
                            <button type="submit" class="rounded-xl bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-xs font-bold text-white shadow-md shadow-indigo-600/30 transition flex items-center gap-1.5 cursor-pointer">
                                <i class="fa-solid fa-paper-plane text-xs"></i>
                                <span>Kirim Ulasan</span>
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="rounded-2xl border border-white/5 bg-slate-950 p-4 mb-6 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <span class="text-indigo-400"><i class="fa-solid fa-circle-question"></i></span>
                            <span class="text-xs text-slate-400">Sudah membaca buku ini? Masuk ke akun Anda untuk memberikan ulasan dan rating bintang.</span>
                        </div>
                        <a href="auth/login.php" class="rounded-xl bg-indigo-600 hover:bg-indigo-500 px-4 py-1.5 text-xs font-bold text-white transition shrink-0">
                            Login
                        </a>
                    </div>
                <?php endif; ?>

                <!-- Daftar Komentar Ulasan -->
                <div id="modalReviewsList" class="space-y-3 max-h-56 overflow-y-auto pr-1"></div>
            </div>

            <!-- Footer Close -->
            <div class="mt-6 pt-4 border-t border-white/10 flex justify-end">
                <button type="button" onclick="closeBookDetailModal()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                    Tutup
                </button>
            </div>

        </div>
    </div>

    <!-- ================= MODAL BOOKING / RESERVASI MANDIRI ================= -->
    <div id="bookingModal" class="fixed inset-0 z-50 hidden bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-4">
        <div class="relative w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl text-slate-100">
            
            <button onclick="closeReservationModal()" class="absolute top-5 right-5 text-slate-400 hover:text-white transition h-8 w-8 rounded-full bg-white/5 flex items-center justify-center cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <div class="flex items-center gap-3 mb-4">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-600/20 text-indigo-400 text-xl border border-indigo-500/30">
                    <i class="fa-solid fa-bookmark"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white">Konfirmasi Booking Buku</h3>
                    <p class="text-xs text-slate-400">Reservasi mandiri sebelum berkunjung ke perpustakaan</p>
                </div>
            </div>

            <div class="rounded-2xl border border-white/5 bg-slate-950 p-4 mb-4 space-y-1.5 text-xs">
                <span class="text-slate-400 block">Buku yang akan di-booking:</span>
                <strong id="bookingBookTitle" class="text-white text-sm block"></strong>
                <div class="flex items-center justify-between text-[11px] pt-1 text-slate-400">
                    <span>Kode: <strong id="bookingBookCode" class="text-indigo-400 font-mono"></strong></span>
                    <span id="bookingStockAvailable"></span>
                </div>
            </div>

            <form action="perpustakaan.php" method="POST" class="space-y-4">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reserve_book">
                <input type="hidden" name="book_id" id="bookingBookIdInput" value="">

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Catatan Peminjam (Opsional)</label>
                    <input type="text" name="notes" placeholder="Contoh: Diambil jam istirahat pertama untuk tugas kelompok..."
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none">
                </div>

                <div class="rounded-xl border border-indigo-500/20 bg-indigo-500/10 p-3 text-[11px] text-indigo-300 leading-relaxed">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    Buku akan disiapkan dan ditahan di meja sirkulasi selama <strong>2 hari kerja</strong>. Harap bawa kartu pelajar Anda saat mengambil buku.
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <button type="button" onclick="closeReservationModal()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" class="rounded-xl bg-indigo-600 hover:bg-indigo-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-indigo-600/30 transition flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-check"></i>
                        <span>Konfirmasi Booking</span>
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- ================= MODAL IN-BROWSER PDF / E-BOOK READER ================= -->
    <div id="pdfReaderModal" class="fixed inset-0 z-50 hidden bg-slate-950/95 backdrop-blur-md flex flex-col p-2 sm:p-4" oncontextmenu="return false;">
        <!-- Top Toolbar -->
        <div class="flex items-center justify-between bg-slate-900 border border-white/10 rounded-2xl px-4 py-3 mb-2 text-white shadow-xl shrink-0">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                    <i class="fa-solid fa-book-open-reader text-base"></i>
                </div>
                <div>
                    <h4 id="pdfReaderTitle" class="text-xs sm:text-sm font-bold text-white line-clamp-1 max-w-xs sm:max-w-md">Membaca E-Book</h4>
                    <p class="text-[10px] text-slate-400">In-Browser Protected Digital Library Reader</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <a id="pdfReaderExternalBtn" href="#" target="_blank" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-semibold text-slate-300 transition flex items-center gap-1.5" title="Buka di Tab Baru">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> <span class="hidden sm:inline">Tab Baru</span>
                </a>
                <button onclick="togglePdfFullscreen()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-semibold text-slate-300 transition cursor-pointer" title="Layar Penuh">
                    <i class="fa-solid fa-expand"></i>
                </button>
                <button onclick="closePdfReader()" class="rounded-xl bg-rose-600 hover:bg-rose-500 px-3.5 py-1.5 text-xs font-bold text-white transition flex items-center gap-1.5 cursor-pointer">
                    <i class="fa-solid fa-xmark"></i> Tutup
                </button>
            </div>
        </div>

        <!-- Frame Container -->
        <div class="flex-1 rounded-2xl overflow-hidden border border-white/10 bg-slate-950 shadow-2xl relative">
            <iframe id="pdfReaderIframe" src="" class="w-full h-full border-none" allow="fullscreen"></iframe>
        </div>
    </div>

    <!-- ================= FOOTER ================= -->
    <footer class="border-t border-white/10 bg-slate-950 py-10 text-xs text-slate-400">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-center sm:text-left">
            <div>
                <p class="font-semibold text-slate-300">
                    &copy; <?= date('Y') ?> Perpustakaan Digital <?= htmlspecialchars($school_info['school_name'] ?? 'Manajemen-PHP') ?>
                </p>
                <p class="text-[11px] text-slate-500 mt-0.5">Sistem Informasi Manajemen Sekolah Terintegrasi Berbasis Web.</p>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-6 text-xs">
                <a href="index.php" class="hover:text-indigo-400 transition">Beranda</a>
                <a href="informasi.php" class="text-sky-400 font-semibold hover:underline">Informasi</a>
                <a href="about.php" class="hover:text-indigo-400 transition">Tentang Kami</a>
                <a href="perpustakaan.php" class="text-indigo-400 font-bold hover:underline">Katalog Buku</a>
                <a href="ppdb/ppdb.php" class="hover:text-emerald-400 transition">PPDB Online</a>
                <a href="auth/login.php" class="hover:text-white transition">Login Portal</a>
            </div>
        </div>
    </footer>

    <!-- ================= JAVASCRIPT LOGIC ================= -->
    <script>
    // 1. Mobile Menu
    const btnMobileMenu = document.getElementById('btnMobileMenu');
    const mobileMenu = document.getElementById('mobileMenu');
    if (btnMobileMenu && mobileMenu) {
        btnMobileMenu.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });
    }

    // 2. Interactive Star Rating Picker
    const starPickerStars = document.getElementById('starPickerStars');
    const reviewRatingInput = document.getElementById('reviewRatingInput');
    const starRatingLabel = document.getElementById('starRatingLabel');

    if (starPickerStars) {
        const stars = starPickerStars.querySelectorAll('.star-opt');
        stars.forEach(star => {
            star.addEventListener('click', () => {
                const val = parseInt(star.getAttribute('data-val'));
                reviewRatingInput.value = val;
                starRatingLabel.textContent = val.toFixed(1);
                updateStarVisuals(val);
            });
            star.addEventListener('mouseenter', () => {
                const val = parseInt(star.getAttribute('data-val'));
                updateStarVisuals(val);
            });
        });
        starPickerStars.addEventListener('mouseleave', () => {
            const currentVal = parseInt(reviewRatingInput.value) || 5;
            updateStarVisuals(currentVal);
        });
    }

    function updateStarVisuals(ratingVal) {
        if (!starPickerStars) return;
        const stars = starPickerStars.querySelectorAll('.star-opt');
        stars.forEach(s => {
            const val = parseInt(s.getAttribute('data-val'));
            if (val <= ratingVal) {
                s.className = 'fa-solid fa-star star-opt text-amber-400';
            } else {
                s.className = 'fa-regular fa-star star-opt text-slate-600';
            }
        });
    }

    // 3. Modal Detail Buku & Reviews
    function openBookDetailModal(book, ratingData, reviews, userBooked) {
        document.getElementById('modalTitle').textContent = book.title || '-';
        document.getElementById('modalAuthor').textContent = book.author || '-';
        document.getElementById('modalCategory').textContent = book.category || 'Umum';
        document.getElementById('modalCode').textContent = book.code || '-';
        document.getElementById('modalCodeBadge').textContent = book.code || '';
        document.getElementById('modalIsbn').textContent = book.isbn || 'Tidak tercatat';
        document.getElementById('modalPublisherYear').textContent = (book.publisher || '-') + (book.year ? ' (' + book.year + ')' : '');
        document.getElementById('modalShelf').textContent = book.shelf_location || 'Rak Utama';
        document.getElementById('modalStockText').textContent = book.stock_available + ' eks tersedia (dari total ' + book.stock_total + ' eks)';
        document.getElementById('modalDesc').textContent = book.description || 'Tidak ada sinopsis atau deskripsi tambahan untuk buku ini.';

        // Stock Badge
        const stockBadge = document.getElementById('modalStockBadge');
        if (parseInt(book.stock_available) > 0) {
            stockBadge.className = 'inline-block rounded-lg px-2.5 py-0.5 text-[11px] font-bold border border-emerald-500/30 bg-emerald-500/10 text-emerald-300';
            stockBadge.textContent = '● Tersedia untuk Dipinjam';
        } else {
            stockBadge.className = 'inline-block rounded-lg px-2.5 py-0.5 text-[11px] font-bold border border-rose-500/30 bg-rose-500/10 text-rose-300';
            stockBadge.textContent = '● Sedang Dipinjam Habis';
        }

        // Rating Badge
        const ratingBadge = document.getElementById('modalRatingBadge');
        if (ratingData && ratingData.count > 0) {
            ratingBadge.innerHTML = `<i class="fa-solid fa-star text-amber-400"></i> ${ratingData.avg} / 5.0 (${ratingData.count} ulasan)`;
            ratingBadge.classList.remove('hidden');
        } else {
            ratingBadge.innerHTML = `<i class="fa-regular fa-star text-slate-400"></i> Belum ada ulasan`;
        }

        // Cover
        const coverImg = document.getElementById('modalCoverImg');
        const coverPlaceholder = document.getElementById('modalCoverPlaceholder');
        if (book.cover_image) {
            coverImg.src = book.cover_image;
            coverImg.classList.remove('hidden');
            coverPlaceholder.classList.add('hidden');
        } else {
            coverImg.classList.add('hidden');
            coverPlaceholder.classList.remove('hidden');
        }

        // Review Form ID
        const revBookIdInput = document.getElementById('reviewFormBookId');
        if (revBookIdInput) revBookIdInput.value = book.id;

        // Action Container
        const actionCont = document.getElementById('modalActionContainer');
        actionCont.innerHTML = '';

        // Tombol Baca E-Book jika ada
        if (book.ebook_file) {
            const btnEbook = document.createElement('button');
            btnEbook.type = 'button';
            btnEbook.className = 'rounded-xl bg-amber-600 hover:bg-amber-500 px-4 py-2 text-xs font-bold text-white shadow-md shadow-amber-600/20 transition flex items-center gap-1.5 cursor-pointer';
            btnEbook.innerHTML = '<i class="fa-solid fa-book-open-reader"></i> Baca E-Book Digital';
            btnEbook.onclick = () => openPdfReader(book.title, book.ebook_file);
            actionCont.appendChild(btnEbook);
        }

        // Tombol Reservasi / Booking
        <?php if ($is_logged_in): ?>
            if (userBooked) {
                const btnCancel = document.createElement('button');
                btnCancel.type = 'button';
                btnCancel.className = 'rounded-xl border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 px-4 py-2 text-xs font-bold text-rose-300 transition flex items-center gap-1.5 cursor-pointer';
                btnCancel.innerHTML = '<i class="fa-solid fa-xmark"></i> Batalkan Booking Aktif';
                btnCancel.onclick = () => openCancelReservationModal(userBooked.id, book.title);
                actionCont.appendChild(btnCancel);
            } else {
                const btnReserve = document.createElement('button');
                btnReserve.type = 'button';
                btnReserve.className = 'rounded-xl bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-xs font-bold text-white shadow-md shadow-indigo-600/30 transition flex items-center gap-1.5 cursor-pointer';
                btnReserve.innerHTML = '<i class="fa-solid fa-bookmark"></i> Booking / Reservasi Buku Ini';
                btnReserve.onclick = () => {
                    closeBookDetailModal();
                    openReservationModal(book.id, book.title, book.code, parseInt(book.stock_available));
                };
                actionCont.appendChild(btnReserve);
            }
        <?php else: ?>
            const btnLogin = document.createElement('a');
            btnLogin.href = 'auth/login.php';
            btnLogin.className = 'rounded-xl bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-xs font-bold text-white shadow-md shadow-indigo-600/30 transition flex items-center gap-1.5';
            btnLogin.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> Login untuk Booking / Pinjam';
            actionCont.appendChild(btnLogin);
        <?php endif; ?>

        // Render Reviews List
        const listCont = document.getElementById('modalReviewsList');
        const countSpan = document.getElementById('modalReviewCount');
        listCont.innerHTML = '';

        if (reviews && reviews.length > 0) {
            countSpan.textContent = reviews.length + ' ulasan terverifikasi';
            reviews.forEach(rv => {
                const item = document.createElement('div');
                item.className = 'p-3.5 rounded-2xl bg-slate-950/70 border border-white/5 space-y-1.5';

                let starsHtml = '';
                for (let i = 1; i <= 5; i++) {
                    starsHtml += (i <= rv.rating) ? '<i class="fa-solid fa-star text-amber-400 text-xs"></i> ' : '<i class="fa-regular fa-star text-slate-600 text-xs"></i> ';
                }

                item.innerHTML = `
                    <div class="flex items-center justify-between text-xs">
                        <div class="flex items-center gap-2">
                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-600/30 text-indigo-300 font-bold text-xs uppercase">
                                ${rv.reviewer_name ? rv.reviewer_name.charAt(0) : 'U'}
                            </div>
                            <div>
                                <strong class="text-white">${escapeHtml(rv.reviewer_name || 'Pembaca')}</strong>
                                <span class="text-[10px] text-slate-500 uppercase ml-1">(${escapeHtml(rv.reviewer_role || 'siswa')})</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-1">${starsHtml}</div>
                    </div>
                    <p class="text-xs text-slate-300 leading-relaxed pl-9">${escapeHtml(rv.review)}</p>
                `;
                listCont.appendChild(item);
            });
        } else {
            countSpan.textContent = '0 ulasan';
            listCont.innerHTML = `
                <div class="text-center py-6 text-slate-500 text-xs">
                    <i class="fa-regular fa-comment-dots text-2xl mb-1.5 block text-slate-600"></i>
                    Belum ada ulasan untuk buku ini. Jadilah yang pertama memberikan ulasan!
                </div>
            `;
        }

        document.getElementById('bookModal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closeBookDetailModal() {
        document.getElementById('bookModal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    // 4. Modal Booking / Reservasi
    function openReservationModal(bookId, bookTitle, bookCode, stockAvailable) {
        document.getElementById('bookingBookIdInput').value = bookId;
        document.getElementById('bookingBookTitle').textContent = bookTitle;
        document.getElementById('bookingBookCode').textContent = bookCode;
        document.getElementById('bookingStockAvailable').textContent = (stockAvailable > 0) ? `${stockAvailable} eks tersedia` : 'Sedang dipinjam (masuk antrean reservasi)';
        document.getElementById('bookingModal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closeReservationModal() {
        document.getElementById('bookingModal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    function openCancelReservationModal(resId, bookTitle) {
        if (confirm(`Apakah Anda yakin ingin membatalkan pemesanan/booking untuk buku "${bookTitle}"?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'perpustakaan.php';
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?= csrfToken() ?>';
            form.appendChild(csrfInput);

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'cancel_my_reservation';
            form.appendChild(actionInput);

            const resIdInput = document.createElement('input');
            resIdInput.type = 'hidden';
            resIdInput.name = 'reservation_id';
            resIdInput.value = resId;
            form.appendChild(resIdInput);

            document.body.appendChild(form);
            form.submit();
        }
    }

    // 5. In-Browser PDF / E-Book Reader
    function openPdfReader(title, fileUrl) {
        document.getElementById('pdfReaderTitle').textContent = title;
        document.getElementById('pdfReaderExternalBtn').href = fileUrl;
        
        // Cek URL file
        const iframe = document.getElementById('pdfReaderIframe');
        iframe.src = fileUrl;

        document.getElementById('pdfReaderModal').classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
    }

    function closePdfReader() {
        document.getElementById('pdfReaderIframe').src = '';
        document.getElementById('pdfReaderModal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

    function togglePdfFullscreen() {
        const elem = document.getElementById('pdfReaderModal');
        if (!document.fullscreenElement) {
            elem.requestFullscreen().catch(err => console.error(err));
        } else {
            document.exitFullscreen().catch(err => console.error(err));
        }
    }

    // Helper escape html
    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Close on Escape or Backdrop
    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeBookDetailModal();
            closeReservationModal();
            closePdfReader();
        }
    });
    document.getElementById('bookModal')?.addEventListener('click', (e) => {
        if (e.target === document.getElementById('bookModal')) closeBookDetailModal();
    });
    document.getElementById('bookingModal')?.addEventListener('click', (e) => {
        if (e.target === document.getElementById('bookingModal')) closeReservationModal();
    });
    </script>

</body>
</html>
