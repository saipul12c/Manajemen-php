<?php
/**
 * Katalog Perpustakaan & E-Book Sekolah
 * Menampilkan katalog koleksi buku cetak, modul digital (e-book), lokasi rak, dan ketersediaan stok.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$page_title = "Katalog Perpustakaan & E-Book";

$is_librarian = in_array($user_role, ['administrator', 'staf'], true);

$message = "";
$message_type = "";

// 1. TAMBAH BUKU BARU (Admin & Staf)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_book') {
    if (!$is_librarian) {
        $message = "Anda tidak memiliki izin mengelola data buku.";
        $message_type = "error";
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $code           = trim($_POST['code'] ?? '');
        $isbn           = trim($_POST['isbn'] ?? '');
        $title          = trim($_POST['title'] ?? '');
        $author         = trim($_POST['author'] ?? '');
        $publisher      = trim($_POST['publisher'] ?? '');
        $year           = !empty($_POST['year']) ? (int)$_POST['year'] : null;
        $category       = trim($_POST['category'] ?? 'Umum');
        $stock_total    = max(1, (int)($_POST['stock_total'] ?? 1));
        $shelf_location = trim($_POST['shelf_location'] ?? 'Rak A-1');
        $description    = trim($_POST['description'] ?? '');
        $ebook_file     = trim($_POST['ebook_file'] ?? '');

        if (empty($code) || empty($title) || empty($author)) {
            $message = "Kode buku, judul, dan nama penulis wajib diisi.";
            $message_type = "error";
        } else {
            try {
                // Upload Sampul Buku jika ada
                $cover_image = null;
                if (isset($_FILES['cover_file']) && $_FILES['cover_file']['error'] === UPLOAD_ERR_OK) {
                    $up_dir = __DIR__ . '/../../uploads/books';
                    if (!is_dir($up_dir)) { mkdir($up_dir, 0777, true); }
                    $ext = strtolower(pathinfo($_FILES['cover_file']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && $_FILES['cover_file']['size'] <= 2 * 1024 * 1024) {
                        $cover_name = 'book_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        if (move_uploaded_file($_FILES['cover_file']['tmp_name'], $up_dir . '/' . $cover_name)) {
                            $cover_image = 'uploads/books/' . $cover_name;
                        }
                    }
                }

                $stmt = $pdo->prepare("
                    INSERT INTO library_books 
                    (code, isbn, title, author, publisher, year, category, stock_total, stock_available, shelf_location, cover_image, ebook_file, description)
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $code, $isbn, $title, $author, $publisher, $year, $category,
                    $stock_total, $stock_total, $shelf_location, $cover_image, $ebook_file, $description
                ]);

                logActivity($pdo, 'CREATE_BOOK', "Menambahkan buku baru: $title ($code)");
                $message = "Buku '$title' berhasil ditambahkan ke katalog perpustakaan!";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Gagal menyimpan buku (Kode buku mungkin sudah digunakan): " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// 2. EDIT BUKU (Admin & Staf)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_book') {
    if (!$is_librarian) {
        $message = "Anda tidak memiliki izin mengedit data buku.";
        $message_type = "error";
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $book_id        = (int)($_POST['book_id'] ?? 0);
        $code           = trim($_POST['code'] ?? '');
        $isbn           = trim($_POST['isbn'] ?? '');
        $title          = trim($_POST['title'] ?? '');
        $author         = trim($_POST['author'] ?? '');
        $publisher      = trim($_POST['publisher'] ?? '');
        $year           = !empty($_POST['year']) ? (int)$_POST['year'] : null;
        $category       = trim($_POST['category'] ?? 'Umum');
        $stock_total    = max(1, (int)($_POST['stock_total'] ?? 1));
        $shelf_location = trim($_POST['shelf_location'] ?? 'Rak A-1');
        $description    = trim($_POST['description'] ?? '');
        $ebook_file     = trim($_POST['ebook_file'] ?? '');

        try {
            // Ambil data buku saat ini untuk perhitungan stok
            $stmt_old = $pdo->prepare("SELECT stock_total, stock_available FROM library_books WHERE id = ?");
            $stmt_old->execute([$book_id]);
            $old_book = $stmt_old->fetch();

            if ($old_book) {
                $borrowed = (int)$old_book['stock_total'] - (int)$old_book['stock_available'];
                $new_available = max(0, $stock_total - $borrowed);

                $stmt_up = $pdo->prepare("
                    UPDATE library_books 
                    SET code = ?, isbn = ?, title = ?, author = ?, publisher = ?, year = ?, category = ?, 
                        stock_total = ?, stock_available = ?, shelf_location = ?, ebook_file = ?, description = ?
                    WHERE id = ?
                ");
                $stmt_up->execute([
                    $code, $isbn, $title, $author, $publisher, $year, $category,
                    $stock_total, $new_available, $shelf_location, $ebook_file, $description, $book_id
                ]);

                logActivity($pdo, 'UPDATE_BOOK', "Memperbarui buku ID $book_id: $title");
                $message = "Informasi buku '$title' berhasil diperbarui!";
                $message_type = "success";
            }
        } catch (Exception $e) {
            $message = "Gagal memperbarui buku: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// 3. HAPUS BUKU (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_book') {
    if ($user_role !== 'administrator') {
        $message = "Hanya administrator yang diizinkan menghapus koleksi buku.";
        $message_type = "error";
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $del_id = (int)($_POST['book_id'] ?? 0);
        try {
            // Cek apakah sedang ada yang meminjam buku ini
            $stmt_chk_l = $pdo->prepare("SELECT COUNT(*) FROM library_loans WHERE book_id = ? AND status = 'dipinjam'");
            $stmt_chk_l->execute([$del_id]);
            if ($stmt_chk_l->fetchColumn() > 0) {
                $message = "Buku tidak dapat dihapus karena saat ini masih dalam masa peminjaman aktif oleh siswa/guru.";
                $message_type = "error";
            } else {
                $stmt_del = $pdo->prepare("DELETE FROM library_books WHERE id = ?");
                $stmt_del->execute([$del_id]);
                logActivity($pdo, 'DELETE_BOOK', "Menghapus buku ID $del_id");
                $message = "Buku berhasil dihapus dari katalog.";
                $message_type = "success";
            }
        } catch (Exception $e) {
            $message = "Gagal menghapus buku: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Filter & Pencarian
$filter_cat = trim($_GET['cat'] ?? '');
$search_q   = trim($_GET['q'] ?? '');

$sql_where = [];
$sql_params = [];

if (!empty($filter_cat)) {
    $sql_where[] = "category = ?";
    $sql_params[] = $filter_cat;
}

if (!empty($search_q)) {
    $sql_where[] = "(title LIKE ? OR author LIKE ? OR code LIKE ? OR isbn LIKE ?)";
    $term = "%$search_q%";
    $sql_params[] = $term;
    $sql_params[] = $term;
    $sql_params[] = $term;
    $sql_params[] = $term;
}

$where_clause = !empty($sql_where) ? "WHERE " . implode(" AND ", $sql_where) : "";

// Data Buku
$stmt_b = $pdo->prepare("SELECT * FROM library_books $where_clause ORDER BY id DESC");
$stmt_b->execute($sql_params);
$books = $stmt_b->fetchAll();

// Daftar Kategori Unik
$categories = $pdo->query("SELECT DISTINCT category FROM library_books ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

// Statistik Singkat
$stat_total_books  = (int)$pdo->query("SELECT COUNT(*) FROM library_books")->fetchColumn();
$stat_total_copies = (int)$pdo->query("SELECT COALESCE(SUM(stock_total), 0) FROM library_books")->fetchColumn();
$stat_available    = (int)$pdo->query("SELECT COALESCE(SUM(stock_available), 0) FROM library_books")->fetchColumn();
$stat_borrowed     = (int)$pdo->query("SELECT COUNT(*) FROM library_loans WHERE status = 'dipinjam'")->fetchColumn();

include __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Sub Navigasi Modul Perpustakaan -->
    <?php include __DIR__ . "/_nav.php"; ?>

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-2 rounded-full border border-teal-500/20 bg-teal-500/10 px-3 py-1 text-xs font-semibold text-teal-400 mb-2">
                <i class="fa-solid fa-book-bookmark"></i> Fasilitas Akademik & Literasi Sekolah
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white">Katalog Perpustakaan & E-Book</h1>
            <p class="text-sm text-slate-400 mt-1">Eksplorasi ribuan koleksi buku teks pelajaran, karya sastra fiksi, ensiklopedia referensi, dan materi bacaan digital.</p>
        </div>

        <div class="flex items-center gap-3">
            <a href="loans.php" 
               class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs sm:text-sm font-semibold text-slate-200 transition flex items-center gap-1.5">
                <i class="fa-solid fa-arrows-rotate"></i> Sirkulasi & Peminjaman <?= $stat_borrowed > 0 ? "($stat_borrowed)" : "" ?>
            </a>
            <?php if ($is_librarian): ?>
                <button onclick="openCreateModal()" 
                        class="rounded-xl bg-teal-600 hover:bg-teal-500 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-lg shadow-teal-600/20 transition flex items-center gap-1.5 cursor-pointer">
                    <i class="fa-solid fa-plus"></i> Tambah Buku Baru
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($message)): ?>
        <div class="rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
            <div class="flex items-center gap-3">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-rose-400' ?> text-lg"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-xs font-bold opacity-70 hover:opacity-100 p-1">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- Statistik Ringkas -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3.5">
        <div class="rounded-2xl border border-white/10 bg-slate-900/50 p-4">
            <span class="text-xs font-semibold text-slate-400">Judul Buku</span>
            <p class="text-2xl font-black text-white mt-1"><?= $stat_total_books ?></p>
            <span class="text-[11px] text-teal-400">Koleksi Terdaftar</span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/50 p-4">
            <span class="text-xs font-semibold text-slate-400">Total Eksemplar</span>
            <p class="text-2xl font-black text-white mt-1"><?= $stat_total_copies ?></p>
            <span class="text-[11px] text-slate-500">Fisik di Perpustakaan</span>
        </div>

        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4">
            <span class="text-xs font-semibold text-emerald-400">Stok Tersedia</span>
            <p class="text-2xl font-black text-emerald-300 mt-1"><?= $stat_available ?></p>
            <span class="text-[11px] text-emerald-400/70">Siap Dipinjam</span>
        </div>

        <div class="rounded-2xl border border-blue-500/20 bg-blue-500/5 p-4">
            <span class="text-xs font-semibold text-blue-400">Sedang Dipinjam</span>
            <p class="text-2xl font-black text-blue-300 mt-1"><?= $stat_borrowed ?></p>
            <span class="text-[11px] text-blue-400/70">Sirkulasi Berjalan</span>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
            <div class="sm:col-span-6">
                <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="Cari judul buku, penulis, kode (BK-001), atau ISBN..."
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
            </div>

            <div class="sm:col-span-4">
                <select name="cat" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                    <option value="">-- Semua Kategori Koleksi --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $filter_cat === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-2 flex items-center gap-2">
                <button type="submit" class="w-full rounded-xl bg-teal-600 hover:bg-teal-500 py-2 text-xs sm:text-sm font-semibold text-white transition inline-flex items-center justify-center gap-1.5">
                    <i class="fa-solid fa-magnifying-glass"></i> Cari Buku
                </button>
                <?php if (!empty($filter_cat) || !empty($search_q)): ?>
                    <a href="books.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-xs text-slate-300 transition" title="Reset">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Grid Buku Modern Card View -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php if (empty($books)): ?>
            <div class="col-span-full rounded-3xl border border-white/10 bg-slate-900/40 p-12 text-center text-slate-400">
                <i class="fa-solid fa-book-open text-slate-500 text-4xl block mb-2"></i>
                <p class="text-base font-semibold text-white">Tidak ada buku yang cocok dengan kriteria pencarian.</p>
                <p class="text-xs text-slate-500 mt-1">Coba gunakan kata kunci pencarian yang lebih umum atau reset filter.</p>
            </div>
        <?php else: ?>
            <?php foreach ($books as $b): 
                $is_available = (int)$b['stock_available'] > 0;
            ?>
                <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-5 backdrop-blur hover:border-teal-500/40 hover:bg-slate-900/80 transition flex flex-col justify-between group shadow-xl">
                    <div>
                        <!-- Header Card: Category & Status Stock -->
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <span class="inline-flex rounded-lg border border-teal-500/30 bg-teal-500/10 px-2.5 py-0.5 text-[11px] font-semibold text-teal-300">
                                <?= htmlspecialchars($b['category']) ?>
                            </span>

                            <span class="inline-flex items-center gap-1.5 rounded-lg border px-2 py-0.5 text-[11px] font-bold <?= $is_available ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
                                <span class="h-1.5 w-1.5 rounded-full <?= $is_available ? 'bg-emerald-400' : 'bg-rose-400' ?>"></span>
                                <?= $b['stock_available'] ?> / <?= $b['stock_total'] ?> Tersedia
                            </span>
                        </div>

                        <!-- Book Title & Author -->
                        <h3 class="text-base font-bold text-white group-hover:text-teal-300 transition line-clamp-2">
                            <?= htmlspecialchars($b['title']) ?>
                        </h3>
                        <p class="text-xs text-slate-400 mt-1">
                            Penulis: <strong class="text-slate-200"><?= htmlspecialchars($b['author']) ?></strong>
                            <?php if (!empty($b['publisher'])): ?>
                                • <?= htmlspecialchars($b['publisher']) ?> (<?= $b['year'] ?: '-' ?>)
                            <?php endif; ?>
                        </p>

                        <!-- Description snippet -->
                        <?php if (!empty($b['description'])): ?>
                            <p class="text-xs text-slate-400 mt-2.5 line-clamp-2 leading-relaxed">
                                <?= htmlspecialchars($b['description']) ?>
                            </p>
                        <?php endif; ?>

                        <!-- Metadata Badges: Shelf & Code -->
                        <div class="mt-4 flex flex-wrap items-center gap-2 text-[11px]">
                            <span class="rounded-lg bg-slate-950 px-2.5 py-1 text-slate-300 border border-white/5 flex items-center gap-1.5 font-mono">
                                <i class="fa-solid fa-tag text-teal-400"></i> <?= htmlspecialchars($b['code']) ?>
                            </span>
                            <span class="rounded-lg bg-slate-950 px-2.5 py-1 text-slate-300 border border-white/5 flex items-center gap-1.5">
                                <i class="fa-solid fa-location-dot text-blue-400"></i> <?= htmlspecialchars($b['shelf_location'] ?: 'Rak A-1') ?>
                            </span>
                            <?php if (!empty($b['isbn'])): ?>
                                <span class="rounded-lg bg-slate-950 px-2.5 py-1 text-slate-400 border border-white/5 font-mono text-[10px]">
                                    ISBN: <?= htmlspecialchars($b['isbn']) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Footer Action Buttons -->
                    <div class="mt-5 pt-4 border-t border-white/10 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1.5">
                            <?php if (!empty($b['ebook_file'])): ?>
                                <button type="button" onclick="openEbookReader(<?= htmlspecialchars(json_encode($b['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($b['ebook_file']), ENT_QUOTES) ?>)"
                                   class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3 py-1.5 text-xs font-semibold text-emerald-300 transition flex items-center gap-1.5 cursor-pointer">
                                    <i class="fa-solid fa-book-open"></i> Baca E-Book
                                </button>
                            <?php endif; ?>

                            <a href="loans.php?book_id=<?= $b['id'] ?>" 
                               class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/15 px-3 py-1.5 text-xs font-semibold text-slate-200 transition inline-flex items-center gap-1">
                                <?= $is_librarian ? 'Pinjamkan' : 'Pinjam' ?> <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>

                        <?php if ($is_librarian): ?>
                            <div class="flex items-center gap-1">
                                <a href="print_labels.php?book_id=<?= $b['id'] ?>"
                                   class="rounded-lg border border-white/10 bg-white/5 hover:bg-white/15 p-2 text-xs text-teal-400 transition"
                                   title="Cetak Label & Barcode Buku">
                                    <i class="fa-solid fa-tags"></i>
                                </a>
                                <button onclick="openEditModal(<?= htmlspecialchars(json_encode($b)) ?>)"
                                        class="rounded-lg border border-white/10 bg-white/5 hover:bg-white/15 p-2 text-xs text-slate-300 transition"
                                        title="Edit Buku">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <?php if ($user_role === 'administrator'): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Hapus buku ini dari katalog?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_book">
                                        <input type="hidden" name="book_id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="rounded-lg border border-rose-500/20 bg-rose-500/10 hover:bg-rose-500/20 p-2 text-xs text-rose-400 transition" title="Hapus">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<?php if ($is_librarian): ?>
<!-- MODAL TAMBAH BUKU -->
<div id="createModal" class="fixed inset-0 z-50 hidden bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="relative w-full max-w-2xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl text-slate-100 my-8">
        <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-6">
            <h3 class="text-xl font-bold text-white">Tambah Koleksi Buku Baru</h3>
            <button onclick="closeCreateModal()" class="rounded-lg p-1 text-slate-400 hover:text-white hover:bg-white/10 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="books.php" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_book">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kode Buku / Barcode *</label>
                    <input type="text" id="createCode" name="code" required placeholder="Contoh: BK-006"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-semibold text-slate-300">ISBN (Opsional)</label>
                        <span id="isbnStatusCreate" class="text-[10px] text-teal-400 font-semibold hidden"></span>
                    </div>
                    <div class="flex gap-1.5">
                        <input type="text" id="createIsbn" name="isbn" placeholder="Contoh: 9786020123451"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                        <button type="button" onclick="autoFillIsbn('create')" id="btnFetchIsbnCreate"
                                class="rounded-xl bg-indigo-600/30 hover:bg-indigo-600/50 border border-indigo-500/40 px-3 py-2 text-xs font-bold text-indigo-300 transition flex items-center gap-1.5 shrink-0 cursor-pointer"
                                title="Ambil otomatis judul, penulis, penerbit, tahun, dan sinopsis dari Google Books API">
                            <i class="fa-solid fa-wand-magic-sparkles text-xs"></i>
                            <span class="hidden sm:inline">Auto-Fill</span>
                        </button>
                    </div>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Judul Buku *</label>
                    <input type="text" id="createTitle" name="title" required placeholder="Judul lengkap buku..."
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Penulis / Pengarang *</label>
                    <input type="text" id="createAuthor" name="author" required placeholder="Nama penulis..."
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Penerbit & Tahun</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" id="createPublisher" name="publisher" placeholder="Penerbit"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-teal-500 focus:outline-none transition">
                        <input type="number" id="createYear" name="year" placeholder="Tahun" value="<?= date('Y') ?>"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-teal-500 focus:outline-none transition">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kategori Buku *</label>
                    <select id="createCategory" name="category" required
                            class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                        <option value="Sains & Teknologi">Sains & Teknologi</option>
                        <option value="Novel & Sastra">Novel & Sastra</option>
                        <option value="Referensi & Bahasa">Referensi & Bahasa</option>
                        <option value="IPS & Sejarah">IPS & Sejarah</option>
                        <option value="Teknologi & Komputer">Teknologi & Komputer</option>
                        <option value="Agama & Moral">Agama & Moral</option>
                        <option value="Umum">Umum</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Jumlah Eksemplar (Stok) *</label>
                    <input type="number" name="stock_total" min="1" value="5" required
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Lokasi Rak Buku *</label>
                    <input type="text" name="shelf_location" value="Rak A-1" required placeholder="Contoh: Rak Sains B-2"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tautan E-Book / PDF (Opsional)</label>
                    <input type="url" name="ebook_file" placeholder="https://..."
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Deskripsi Singkat / Sinopsis</label>
                    <textarea id="createDescription" name="description" rows="2.5" placeholder="Rangkuman ringkas isi buku..."
                              class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="closeCreateModal()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-teal-600 hover:bg-teal-500 px-6 py-2 text-xs font-bold text-white shadow-lg shadow-teal-600/20 transition cursor-pointer inline-flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Buku
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDIT BUKU -->
<div id="editModal" class="fixed inset-0 z-50 hidden bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="relative w-full max-w-2xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl text-slate-100 my-8">
        <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-6">
            <h3 class="text-xl font-bold text-white">Edit Informasi Buku</h3>
            <button onclick="closeEditModal()" class="rounded-lg p-1 text-slate-400 hover:text-white hover:bg-white/10 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="books.php" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_book">
            <input type="hidden" name="book_id" id="editBookId">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kode Buku / Barcode *</label>
                    <input type="text" name="code" id="editCode" required
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-semibold text-slate-300">ISBN</label>
                        <span id="isbnStatusEdit" class="text-[10px] text-teal-400 font-semibold hidden"></span>
                    </div>
                    <div class="flex gap-1.5">
                        <input type="text" name="isbn" id="editIsbn"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                        <button type="button" onclick="autoFillIsbn('edit')" id="btnFetchIsbnEdit"
                                class="rounded-xl bg-indigo-600/30 hover:bg-indigo-600/50 border border-indigo-500/40 px-3 py-2 text-xs font-bold text-indigo-300 transition flex items-center gap-1.5 shrink-0 cursor-pointer"
                                title="Auto-Fill dari Google Books">
                            <i class="fa-solid fa-wand-magic-sparkles text-xs"></i>
                            <span class="hidden sm:inline">Auto-Fill</span>
                        </button>
                    </div>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Judul Buku *</label>
                    <input type="text" name="title" id="editTitle" required
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Penulis / Pengarang *</label>
                    <input type="text" name="author" id="editAuthor" required
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Penerbit & Tahun</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="text" name="publisher" id="editPublisher" placeholder="Penerbit"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-teal-500 focus:outline-none transition">
                        <input type="number" name="year" id="editYear" placeholder="Tahun"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-teal-500 focus:outline-none transition">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kategori Buku *</label>
                    <input type="text" name="category" id="editCategory" required
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Total Stok *</label>
                    <input type="number" name="stock_total" id="editStockTotal" min="1" required
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Lokasi Rak *</label>
                    <input type="text" name="shelf_location" id="editShelf" required
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tautan E-Book (Opsional)</label>
                    <input type="url" name="ebook_file" id="editEbook" placeholder="https://..."
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Deskripsi Singkat</label>
                    <textarea name="description" id="editDescription" rows="2.5"
                              class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="closeEditModal()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-6 py-2 text-xs font-bold text-white shadow-lg shadow-blue-600/20 transition cursor-pointer inline-flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
}
function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
}
function openEditModal(data) {
    document.getElementById('editBookId').value = data.id;
    document.getElementById('editCode').value = data.code;
    document.getElementById('editIsbn').value = data.isbn || '';
    document.getElementById('editTitle').value = data.title;
    document.getElementById('editAuthor').value = data.author;
    document.getElementById('editPublisher').value = data.publisher || '';
    document.getElementById('editYear').value = data.year || '';
    document.getElementById('editCategory').value = data.category;
    document.getElementById('editStockTotal').value = data.stock_total;
    document.getElementById('editShelf').value = data.shelf_location || '';
    document.getElementById('editEbook').value = data.ebook_file || '';
    document.getElementById('editDescription').value = data.description || '';
    document.getElementById('editModal').classList.remove('hidden');
}
function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

// -------------------------------------------------------------
// FITUR: AUTO-FILL ISBN VIA GOOGLE BOOKS & OPENLIBRARY API
// -------------------------------------------------------------
async function autoFillIsbn(mode) {
    const isCreate = mode === 'create';
    const isbnInput = document.getElementById(isCreate ? 'createIsbn' : 'editIsbn');
    const btn = document.getElementById(isCreate ? 'btnFetchIsbnCreate' : 'btnFetchIsbnEdit');
    const statusText = document.getElementById(isCreate ? 'isbnStatusCreate' : 'isbnStatusEdit');

    const rawIsbn = (isbnInput.value || '').trim();
    const cleanIsbn = rawIsbn.replace(/[^0-9X]/gi, '');

    if (!cleanIsbn || cleanIsbn.length < 10) {
        alert('Harap masukkan minimal 10 atau 13 digit angka ISBN terlebih dahulu.');
        isbnInput.focus();
        return;
    }

    const origBtnHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-xs"></i> <span>Mencari...</span>';
    if (statusText) {
        statusText.classList.remove('hidden', 'text-rose-400', 'text-teal-400');
        statusText.classList.add('text-indigo-400');
        statusText.textContent = 'Mencari ke Google Books API...';
    }

    try {
        let bookData = null;

        // 1. Coba Google Books API
        try {
            const resp = await fetch(`https://www.googleapis.com/books/v1/volumes?q=isbn:${cleanIsbn}`);
            const data = await resp.json();
            if (data && data.totalItems > 0 && data.items && data.items[0].volumeInfo) {
                const vi = data.items[0].volumeInfo;
                bookData = {
                    title: vi.title || '',
                    author: (vi.authors || []).join(', '),
                    publisher: vi.publisher || '',
                    year: vi.publishedDate ? vi.publishedDate.substring(0, 4) : '',
                    description: vi.description || '',
                    categories: (vi.categories || []).join(', ')
                };
            }
        } catch (e1) {
            console.warn('Google Books API failed, trying OpenLibrary...', e1);
        }

        // 2. Fallback OpenLibrary API jika Google Books tidak menemukan
        if (!bookData) {
            try {
                const olResp = await fetch(`https://openlibrary.org/api/books?bibkeys=ISBN:${cleanIsbn}&format=json&jscmd=data`);
                const olData = await olResp.json();
                const key = `ISBN:${cleanIsbn}`;
                if (olData && olData[key]) {
                    const olItem = olData[key];
                    bookData = {
                        title: olItem.title || '',
                        author: (olItem.authors || []).map(a => a.name).join(', '),
                        publisher: (olItem.publishers || []).map(p => p.name).join(', '),
                        year: olItem.publish_date ? (olItem.publish_date.match(/\d{4}/) ? olItem.publish_date.match(/\d{4}/)[0] : '') : '',
                        description: typeof olItem.notes === 'string' ? olItem.notes : '',
                        categories: (olItem.subjects || []).map(s => s.name).join(', ')
                    };
                }
            } catch (e2) {
                console.warn('OpenLibrary fallback failed', e2);
            }
        }

        if (bookData) {
            const prefix = isCreate ? 'create' : 'edit';
            if (bookData.title) document.getElementById(`${prefix}Title`).value = bookData.title;
            if (bookData.author) document.getElementById(`${prefix}Author`).value = bookData.author;
            if (bookData.publisher) document.getElementById(`${prefix}Publisher`).value = bookData.publisher;
            if (bookData.year) document.getElementById(`${prefix}Year`).value = bookData.year;
            if (bookData.description) document.getElementById(`${prefix}Description`).value = bookData.description;

            // Map category jika cocok
            const catSelect = document.getElementById(`${prefix}Category`);
            if (catSelect) {
                const cLower = (bookData.categories + ' ' + bookData.title).toLowerCase();
                let matchedCat = '';
                if (cLower.includes('science') || cLower.includes('physics') || cLower.includes('chemistry') || cLower.includes('biology') || cLower.includes('math') || cLower.includes('fisika') || cLower.includes('matematika')) {
                    matchedCat = 'Sains & Teknologi';
                } else if (cLower.includes('fiction') || cLower.includes('novel') || cLower.includes('literature') || cLower.includes('poetry') || cLower.includes('sastra')) {
                    matchedCat = 'Novel & Sastra';
                } else if (cLower.includes('computer') || cLower.includes('programming') || cLower.includes('software') || cLower.includes('web') || cLower.includes('technology') || cLower.includes('komputer')) {
                    matchedCat = 'Teknologi & Komputer';
                } else if (cLower.includes('history') || cLower.includes('social') || cLower.includes('geography') || cLower.includes('sejarah') || cLower.includes('ips')) {
                    matchedCat = 'IPS & Sejarah';
                } else if (cLower.includes('language') || cLower.includes('dictionary') || cLower.includes('kamus') || cLower.includes('grammar') || cLower.includes('bahasa')) {
                    matchedCat = 'Referensi & Bahasa';
                } else if (cLower.includes('religion') || cLower.includes('islam') || cLower.includes('moral') || cLower.includes('agama')) {
                    matchedCat = 'Agama & Moral';
                }
                if (matchedCat) {
                    catSelect.value = matchedCat;
                }
            }

            if (statusText) {
                statusText.classList.remove('text-indigo-400', 'text-rose-400');
                statusText.classList.add('text-teal-400');
                statusText.innerHTML = '<i class="fa-solid fa-check"></i> Data ditemukan & otomatis terisi!';
            }
        } else {
            if (statusText) {
                statusText.classList.remove('text-indigo-400', 'text-teal-400');
                statusText.classList.add('text-rose-400');
                statusText.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Tidak ditemukan di Google Books.';
            }
            alert(`Tidak dapat menemukan data untuk ISBN: ${rawIsbn}. Silakan lengkapi informasi buku secara manual.`);
        }
    } catch (err) {
        console.error(err);
        alert('Gagal menghubungi API ISBN. Pastikan perangkat Anda terhubung ke internet.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origBtnHtml;
    }
}
</script>
<?php endif; ?>

<!-- MODAL IN-BROWSER E-BOOK READER -->
<div id="ebookReaderModal" class="fixed inset-0 z-50 hidden bg-black/85 backdrop-blur-md flex flex-col p-2 sm:p-4">
    <div class="flex items-center justify-between bg-slate-900 border border-white/10 rounded-2xl px-4 py-3 mb-2 text-white shadow-xl">
        <div class="flex items-center gap-2.5">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/20 text-emerald-400">
                <i class="fa-solid fa-book-open-reader"></i>
            </div>
            <div>
                <h4 id="ebookModalTitle" class="text-sm font-bold text-white line-clamp-1">Judul E-Book</h4>
                <p class="text-[11px] text-slate-400">In-Browser E-Book & Modul Reader Perpustakaan</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <a id="ebookExternalLink" href="#" target="_blank" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-semibold text-slate-300 transition flex items-center gap-1.5" title="Buka Tab Baru">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> <span class="hidden sm:inline">Tab Baru</span>
            </a>
            <button onclick="toggleEbookFullscreen()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-semibold text-slate-300 transition flex items-center gap-1.5 cursor-pointer" title="Layar Penuh">
                <i class="fa-solid fa-expand"></i>
            </button>
            <button onclick="closeEbookReader()" class="rounded-xl bg-rose-600/80 hover:bg-rose-500 px-3.5 py-1.5 text-xs font-bold text-white transition flex items-center gap-1.5 cursor-pointer">
                <i class="fa-solid fa-xmark"></i> Tutup
            </button>
        </div>
    </div>
    <div id="ebookFrameContainer" class="flex-1 rounded-2xl overflow-hidden border border-white/10 bg-slate-950 shadow-2xl relative">
        <iframe id="ebookIframe" src="" class="w-full h-full border-none" allow="fullscreen"></iframe>
    </div>
</div>

<script>
function openEbookReader(title, fileUrl) {
    document.getElementById('ebookModalTitle').textContent = title;
    document.getElementById('ebookExternalLink').href = fileUrl;
    document.getElementById('ebookIframe').src = fileUrl;
    document.getElementById('ebookReaderModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeEbookReader() {
    document.getElementById('ebookIframe').src = '';
    document.getElementById('ebookReaderModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

function toggleEbookFullscreen() {
    const elem = document.getElementById('ebookReaderModal');
    if (!document.fullscreenElement) {
        elem.requestFullscreen().catch(err => console.error(err));
    } else {
        document.exitFullscreen().catch(err => console.error(err));
    }
}
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
