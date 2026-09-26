<?php
/**
 * Sirkulasi Peminjaman & Pengembalian Buku Perpustakaan
 * Pencatatan peminjaman, pelacakan jatuh tempo, pengembalian buku, dan kalkulasi denda otomatis.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$page_title = "Sirkulasi Peminjaman Buku";

$is_librarian = in_array($user_role, ['administrator', 'staf'], true);

$message = "";
$message_type = "";

// 1. PROSES PINJAM BUKU BARU (Librarian only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_loan') {
    if (!$is_librarian) {
        $message = "Hanya petugas perpustakaan yang dapat mencatat peminjaman.";
        $message_type = "error";
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $book_id     = (int)($_POST['book_id'] ?? 0);
        $borrower_id = (int)($_POST['user_id'] ?? 0);
        $borrow_date = trim($_POST['borrow_date'] ?? date('Y-m-d'));
        $due_date    = trim($_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days')));
        $notes       = trim($_POST['notes'] ?? '');

        // Cek ketersediaan stok buku
        $stmt_b = $pdo->prepare("SELECT title, stock_available FROM library_books WHERE id = ?");
        $stmt_b->execute([$book_id]);
        $book = $stmt_b->fetch();

        if (!$book) {
            $message = "Buku yang dipilih tidak ditemukan.";
            $message_type = "error";
        } elseif ((int)$book['stock_available'] <= 0) {
            $message = "Maaf, stok buku '{$book['title']}' sedang habis dipinjam.";
            $message_type = "error";
        } else {
            try {
                $pdo->beginTransaction();

                // Insert peminjaman
                $stmt_ins = $pdo->prepare("
                    INSERT INTO library_loans 
                    (book_id, user_id, borrow_date, due_date, status, notes)
                    VALUES
                    (?, ?, ?, ?, 'dipinjam', ?)
                ");
                $stmt_ins->execute([$book_id, $borrower_id, $borrow_date, $due_date, $notes]);

                // Kurangi stok buku tersedia
                $stmt_dec = $pdo->prepare("UPDATE library_books SET stock_available = stock_available - 1 WHERE id = ?");
                $stmt_dec->execute([$book_id]);

                $pdo->commit();

                logActivity($pdo, 'CREATE_LOAN', "Meminjamkan buku ID $book_id ke User ID $borrower_id");
                $message = "Peminjaman buku '{$book['title']}' berhasil dicatat! Jatuh tempo: " . date('d/m/Y', strtotime($due_date));
                $message_type = "success";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $message = "Gagal memproses peminjaman: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// 2. PROSES PENGEMBALIAN BUKU (Librarian only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_loan') {
    if (!$is_librarian) {
        $message = "Hanya petugas perpustakaan yang dapat memproses pengembalian buku.";
        $message_type = "error";
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $loan_id = (int)($_POST['loan_id'] ?? 0);

        try {
            $stmt_l = $pdo->prepare("SELECT * FROM library_loans WHERE id = ? AND status = 'dipinjam'");
            $stmt_l->execute([$loan_id]);
            $loan = $stmt_l->fetch();

            if (!$loan) {
                $message = "Data peminjaman aktif tidak ditemukan.";
                $message_type = "error";
            } else {
                $pdo->beginTransaction();

                $today = date('Y-m-d');
                $due_date = $loan['due_date'];
                $fine_amount = 0.00;

                // Hitung denda keterlambatan (Rp 1.000 / hari keterlambatan)
                if ($today > $due_date) {
                    $days_late = (int)(strtotime($today) - strtotime($due_date)) / (60 * 60 * 24);
                    $fine_amount = max(0, $days_late) * 1000.00;
                }

                // Update status peminjaman menjadi kembali
                $stmt_up_l = $pdo->prepare("
                    UPDATE library_loans 
                    SET return_date = ?, fine_amount = ?, status = 'kembali' 
                    WHERE id = ?
                ");
                $stmt_up_l->execute([$today, $fine_amount, $loan_id]);

                // Tambah kembali stok buku tersedia
                $stmt_inc = $pdo->prepare("UPDATE library_books SET stock_available = stock_available + 1 WHERE id = ?");
                $stmt_inc->execute([$loan['book_id']]);

                $pdo->commit();

                logActivity($pdo, 'RETURN_LOAN', "Pengembalian buku ID {$loan['book_id']} oleh pinjaman ID $loan_id");
                $fine_str = $fine_amount > 0 ? " (Denda keterlambatan: " . formatRupiah($fine_amount) . ")" : " (Tepat waktu tanpa denda)";
                $message = "Buku berhasil dikembalikan ke rak perpustakaan! $fine_str";
                $message_type = "success";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $message = "Gagal memproses pengembalian: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// 3. TANDAI HILANG (Librarian only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_lost') {
    if (!$is_librarian) {
        $message = "Akses ditolak.";
        $message_type = "error";
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE library_loans SET status = 'hilang' WHERE id = ?");
            $stmt->execute([$loan_id]);
            logActivity($pdo, 'MARK_LOST', "Menandai buku hilang pada peminjaman ID $loan_id");
            $message = "Status buku berhasil ditandai sebagai hilang.";
            $message_type = "info";
        } catch (Exception $e) {
            $message = "Gagal memperbarui status: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// 4. PROSES PERPANJANGAN MANDIRI / OLEH PETUGAS (Action: renew_loan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'renew_loan') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        try {
            $stmt_l = $pdo->prepare("
                SELECT l.*, b.title as book_title 
                FROM library_loans l 
                JOIN library_books b ON l.book_id = b.id 
                WHERE l.id = ? AND l.status = 'dipinjam'
            ");
            $stmt_l->execute([$loan_id]);
            $loan = $stmt_l->fetch();

            if (!$loan) {
                $message = "Data peminjaman aktif tidak ditemukan.";
                $message_type = "error";
            } elseif (!$is_librarian && (int)$loan['user_id'] !== $user_id) {
                $message = "Anda hanya dapat memperpanjang peminjaman buku milik Anda sendiri.";
                $message_type = "error";
            } elseif (date('Y-m-d') > $loan['due_date']) {
                $message = "Peminjaman yang sudah melewati jatuh tempo tidak dapat diperpanjang secara mandiri. Harap kembalikan buku ke perpustakaan.";
                $message_type = "error";
            } elseif ((int)($loan['renewal_count'] ?? 0) >= 1) {
                $message = "Buku ini sudah pernah diperpanjang sebelumnya (Maksimal perpanjangan mandiri adalah 1 kali).";
                $message_type = "error";
            } else {
                $new_due_date = date('Y-m-d', strtotime($loan['due_date'] . ' +7 days'));
                $stmt_up = $pdo->prepare("UPDATE library_loans SET due_date = ?, renewal_count = renewal_count + 1 WHERE id = ?");
                $stmt_up->execute([$new_due_date, $loan_id]);

                logActivity($pdo, 'RENEW_LOAN', "Perpanjangan peminjaman buku #$loan_id ({$loan['book_title']}) hingga $new_due_date");
                $message = "Peminjaman buku '{$loan['book_title']}' berhasil diperpanjang 7 hari hingga " . date('d/m/Y', strtotime($new_due_date)) . "!";
                $message_type = "success";
            }
        } catch (Exception $e) {
            $message = "Gagal memperpanjang peminjaman: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Identifikasi Target Siswa jika Orang Tua
$linked_child_id = null;
if ($user_role === 'orang_tua') {
    $stmt_c = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
    $stmt_c->execute([$user_id]);
    $linked_child_id = $stmt_c->fetchColumn();
}

// Filter Status & Search
$filter_status = trim($_GET['status'] ?? '');
$search_q      = trim($_GET['q'] ?? '');

$sql_where = [];
$sql_params = [];

// Jika bukan staf/admin, batasi hanya melihat buku miliknya
if (!$is_librarian) {
    if ($user_role === 'orang_tua') {
        if ($linked_child_id) {
            $sql_where[] = "l.user_id = ?";
            $sql_params[] = (int)$linked_child_id;
        } else {
            $sql_where[] = "1 = 0"; // Tidak ada anak terhubung
        }
    } else {
        $sql_where[] = "l.user_id = ?";
        $sql_params[] = $user_id;
    }
}

if (!empty($filter_status)) {
    $sql_where[] = "l.status = ?";
    $sql_params[] = $filter_status;
}

if (!empty($search_q)) {
    $sql_where[] = "(b.title LIKE ? OR b.code LIKE ? OR u.name LIKE ? OR u.nisn LIKE ?)";
    $term = "%$search_q%";
    $sql_params[] = $term;
    $sql_params[] = $term;
    $sql_params[] = $term;
    $sql_params[] = $term;
}

$where_clause = !empty($sql_where) ? "WHERE " . implode(" AND ", $sql_where) : "";

// Query Data Peminjaman
$stmt_loans = $pdo->prepare("
    SELECT l.*, 
           b.code as book_code, b.title as book_title, b.shelf_location,
           u.name as borrower_name, u.role as borrower_role, u.nisn as borrower_nisn,
           c.name as class_name,
           DATEDIFF(CURRENT_DATE(), l.due_date) as days_overdue,
           DATEDIFF(l.due_date, CURRENT_DATE()) as days_remaining,
           CASE 
               WHEN l.status = 'dipinjam' AND CURRENT_DATE() > l.due_date 
               THEN DATEDIFF(CURRENT_DATE(), l.due_date) * 1000 
               ELSE l.fine_amount 
           END as calculated_fine
    FROM library_loans l
    JOIN library_books b ON l.book_id = b.id
    JOIN users u ON l.user_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    $where_clause
    ORDER BY (l.status = 'dipinjam') DESC, l.due_date ASC, l.id DESC
");
$stmt_loans->execute($sql_params);
$loans = $stmt_loans->fetchAll();

// Statistik Ringkas
$loan_stats_user_where = "";
if (!$is_librarian) {
    $stat_uid = ($user_role === 'orang_tua') ? (int)($linked_child_id ?: 0) : $user_id;
    $loan_stats_user_where = " AND user_id = $stat_uid";
}

$stat_active = (int)$pdo->query("SELECT COUNT(*) FROM library_loans WHERE status = 'dipinjam' $loan_stats_user_where")->fetchColumn();
$stat_late   = (int)$pdo->query("SELECT COUNT(*) FROM library_loans WHERE status = 'dipinjam' AND CURRENT_DATE() > due_date $loan_stats_user_where")->fetchColumn();
$stat_done   = (int)$pdo->query("SELECT COUNT(*) FROM library_loans WHERE status = 'kembali' $loan_stats_user_where")->fetchColumn();

// Ambil list buku yang stoknya tersedia & user untuk modal pinjam baru
$available_books = [];
$borrowers_list  = [];
if ($is_librarian) {
    $available_books = $pdo->query("SELECT id, code, title, stock_available FROM library_books WHERE stock_available > 0 ORDER BY title ASC")->fetchAll();
    $borrowers_list  = $pdo->query("SELECT id, name, role, nisn FROM users WHERE role IN ('siswa', 'guru') ORDER BY role ASC, name ASC")->fetchAll();
}

$preselected_book_id = isset($_GET['book_id']) ? (int)$_GET['book_id'] : 0;

include __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Sub Navigasi Modul Perpustakaan -->
    <?php include __DIR__ . "/_nav.php"; ?>

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-2 rounded-full border border-blue-500/20 bg-blue-500/10 px-3 py-1 text-xs font-semibold text-blue-400 mb-2">
                <i class="fa-solid fa-arrows-rotate"></i> Sirkulasi Koleksi Buku
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white">Peminjaman & Pengembalian Buku</h1>
            <p class="text-sm text-slate-400 mt-1">
                <?= $is_librarian ? "Kelola transaksi peminjaman, rekam pengembalian tepat waktu, dan hitung denda keterlambatan." : "Pantau buku pinjaman aktif Anda, tanggal jatuh tempo pengembalian, dan riwayat peminjaman." ?>
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <?php if ($is_librarian): ?>
                <a href="scan.php" 
                   class="rounded-xl border border-teal-500/40 bg-teal-500/10 hover:bg-teal-500/20 px-3.5 py-2 text-xs sm:text-sm font-semibold text-teal-300 transition flex items-center gap-1.5">
                    <i class="fa-solid fa-barcode"></i> Scan Cepat
                </a>
            <?php endif; ?>
            <a href="books.php" 
               class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs sm:text-sm font-semibold text-slate-200 transition flex items-center gap-1.5">
                <i class="fa-solid fa-book-bookmark"></i> Katalog Buku
            </a>
            <?php if ($is_librarian): ?>
                <button onclick="openLoanModal()" 
                        class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-lg shadow-blue-600/20 transition flex items-center gap-1.5 cursor-pointer">
                    <i class="fa-solid fa-plus"></i> Catat Pinjam
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($message)): ?>
        <div class="rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : ($message_type === 'info' ? 'border-blue-500/30 bg-blue-500/10 text-blue-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300') ?>">
            <div class="flex items-center gap-3">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : ($message_type === 'info' ? 'fa-circle-info text-blue-400' : 'fa-triangle-exclamation text-rose-400') ?> text-lg"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-xs font-bold opacity-70 hover:opacity-100 p-1">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- Statistik Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="rounded-2xl border border-blue-500/20 bg-blue-500/5 p-4 flex items-center justify-between">
            <div>
                <span class="text-xs font-semibold text-blue-400">Sedang Dipinjam</span>
                <p class="text-2xl font-black text-white mt-1"><?= $stat_active ?></p>
                <span class="text-[11px] text-slate-400">Buku Belum Kembali</span>
            </div>
            <i class="fa-solid fa-book-open-reader text-blue-400 text-3xl"></i>
        </div>

        <div class="rounded-2xl border <?= $stat_late > 0 ? 'border-rose-500/30 bg-rose-500/10' : 'border-white/10 bg-slate-900/50' ?> p-4 flex items-center justify-between">
            <div>
                <span class="text-xs font-semibold <?= $stat_late > 0 ? 'text-rose-400' : 'text-slate-400' ?>">Terlambat (Overdue)</span>
                <p class="text-2xl font-black <?= $stat_late > 0 ? 'text-rose-300' : 'text-white' ?> mt-1"><?= $stat_late ?></p>
                <span class="text-[11px] <?= $stat_late > 0 ? 'text-rose-400/80 font-bold' : 'text-slate-500' ?>">
                    <?= $stat_late > 0 ? 'Dikenakan denda Rp 1.000/hari' : 'Tidak ada keterlambatan' ?>
                </span>
            </div>
            <i class="fa-solid <?= $stat_late > 0 ? 'fa-triangle-exclamation text-rose-400' : 'fa-clock text-slate-400' ?> text-3xl"></i>
        </div>

        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4 flex items-center justify-between">
            <div>
                <span class="text-xs font-semibold text-emerald-400">Total Pengembalian</span>
                <p class="text-2xl font-black text-emerald-300 mt-1"><?= $stat_done ?></p>
                <span class="text-[11px] text-emerald-400/70">Selesai Dibaca</span>
            </div>
            <i class="fa-solid fa-circle-check text-emerald-400 text-3xl"></i>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
            <div class="sm:col-span-6">
                <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="Cari judul buku, kode, nama peminjam, atau NISN..."
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-xs sm:text-sm text-white focus:border-blue-500 focus:outline-none transition">
            </div>

            <div class="sm:col-span-4">
                <select name="status" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs sm:text-sm text-white focus:border-blue-500 focus:outline-none transition">
                    <option value="">-- Semua Status Sirkulasi --</option>
                    <option value="dipinjam" <?= $filter_status === 'dipinjam' ? 'selected' : '' ?>>Sedang Dipinjam (Aktif)</option>
                    <option value="kembali" <?= $filter_status === 'kembali' ? 'selected' : '' ?>>Sudah Dikembalikan</option>
                    <option value="hilang" <?= $filter_status === 'hilang' ? 'selected' : '' ?>>Hilang</option>
                </select>
            </div>

            <div class="sm:col-span-2 flex items-center gap-2">
                <button type="submit" class="w-full rounded-xl bg-blue-600 hover:bg-blue-500 py-2 text-xs sm:text-sm font-semibold text-white transition inline-flex items-center justify-center gap-1.5">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
                <?php if (!empty($filter_status) || !empty($search_q)): ?>
                    <a href="loans.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-xs text-slate-300 transition" title="Reset">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Tabel Data Sirkulasi -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/50 backdrop-blur overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs sm:text-sm">
                <thead class="border-b border-white/10 bg-white/5 text-slate-300 font-bold uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="px-4 py-3.5">Buku Dipinjam</th>
                        <th class="px-4 py-3.5">Peminjam</th>
                        <th class="px-4 py-3.5">Tgl Pinjam</th>
                        <th class="px-4 py-3.5">Jatuh Tempo</th>
                        <th class="px-4 py-3.5">Status & Denda</th>
                        <th class="px-4 py-3.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-slate-200">
                    <?php if (empty($loans)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-400">
                                <i class="fa-solid fa-book-open-reader text-slate-500 text-3xl block mb-2"></i>
                                Tidak ada data sirkulasi peminjaman yang ditemukan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($loans as $l): 
                            $is_active = ($l['status'] === 'dipinjam');
                            $is_late   = ($is_active && $l['days_overdue'] > 0);
                        ?>
                            <tr class="hover:bg-white/5 transition <?= $is_late ? 'bg-rose-950/10' : '' ?>">
                                <td class="px-4 py-3.5">
                                    <div class="font-bold text-white"><?= htmlspecialchars($l['book_title']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-mono mt-0.5 flex items-center gap-2">
                                        <span class="flex items-center gap-1"><i class="fa-solid fa-tag text-teal-400"></i> <?= htmlspecialchars($l['book_code']) ?></span>
                                        <span class="flex items-center gap-1"><i class="fa-solid fa-location-dot text-blue-400"></i> <?= htmlspecialchars($l['shelf_location'] ?: 'Rak A-1') ?></span>
                                    </div>
                                    <?php if (!empty($l['notes'])): ?>
                                        <div class="text-[11px] text-slate-500 italic mt-0.5">
                                            "<?= htmlspecialchars($l['notes']) ?>"
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="px-4 py-3.5">
                                    <div class="font-semibold text-white"><?= htmlspecialchars($l['borrower_name']) ?></div>
                                    <div class="text-[11px] text-slate-400 flex items-center gap-1.5 mt-0.5">
                                        <span class="inline-block rounded px-1.5 py-0.2 text-[10px] uppercase font-bold <?= getRoleBadge($l['borrower_role']) ?>">
                                            <?= htmlspecialchars(getRoleLabel($l['borrower_role'])) ?>
                                        </span>
                                        <?php if (!empty($l['class_name'])): ?>
                                            <span><?= htmlspecialchars($l['class_name']) ?></span>
                                        <?php elseif (!empty($l['borrower_nisn'])): ?>
                                            <span>NISN: <?= htmlspecialchars($l['borrower_nisn']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="px-4 py-3.5 whitespace-nowrap text-slate-300">
                                    <?= date('d/m/Y', strtotime($l['borrow_date'])) ?>
                                </td>

                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <span class="font-semibold <?= $is_late ? 'text-rose-400 font-bold' : 'text-slate-200' ?>">
                                        <?= date('d/m/Y', strtotime($l['due_date'])) ?>
                                    </span>
                                    <?php if ($is_active): ?>
                                        <?php if ($is_late): ?>
                                            <span class="block text-[10px] font-bold text-rose-400">
                                                <i class="fa-solid fa-circle-exclamation mr-1"></i> Terlambat <?= $l['days_overdue'] ?> hari
                                            </span>
                                        <?php else: ?>
                                            <span class="block text-[10px] text-emerald-400">
                                                <i class="fa-regular fa-clock mr-1"></i> Sisa <?= max(0, (int)$l['days_remaining']) ?> hari
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="block text-[10px] text-slate-500">
                                            Kembali: <?= date('d/m/Y', strtotime($l['return_date'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <div class="flex flex-col gap-1 items-start">
                                        <span class="inline-flex items-center rounded-xl border px-2.5 py-0.5 text-[11px] font-semibold <?= getLoanStatusBadge($l['status']) ?>">
                                            <?= htmlspecialchars(getLoanStatusLabel($l['status'])) ?>
                                        </span>

                                        <?php if ((float)$l['calculated_fine'] > 0): ?>
                                            <span class="text-xs font-bold text-rose-400">
                                                Denda: <?= formatRupiah((float)$l['calculated_fine']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="px-4 py-3.5 whitespace-nowrap text-right">
                                    <?php if ($is_active): ?>
                                        <div class="inline-flex items-center gap-1.5">
                                            <!-- Tombol Perpanjangan Mandiri / Staf (Maksimal 1x perpanjang dan belum telat) -->
                                            <?php if (!$is_late && (int)($l['renewal_count'] ?? 0) < 1): ?>
                                                <form method="POST" class="inline" onsubmit="return confirm('Perpanjang masa peminjaman buku ini 7 hari? (Perpanjangan hanya dapat dilakukan 1 kali)');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="renew_loan">
                                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                    <button type="submit" 
                                                            class="rounded-lg border border-teal-500/30 bg-teal-500/10 hover:bg-teal-500/20 px-2.5 py-1 text-xs font-semibold text-teal-300 transition cursor-pointer inline-flex items-center gap-1"
                                                            title="Perpanjang 7 Hari">
                                                        <i class="fa-solid fa-clock-rotate-left"></i> Perpanjang
                                                    </button>
                                                </form>
                                            <?php elseif ((int)($l['renewal_count'] ?? 0) >= 1): ?>
                                                <span class="rounded px-2 py-0.5 text-[10px] font-semibold bg-slate-800 text-slate-400 border border-white/5" title="Buku sudah pernah diperpanjang 1 kali">
                                                    1x Diperpanjang
                                                </span>
                                            <?php endif; ?>

                                            <?php if ($is_librarian): ?>
                                                <!-- Tombol Proses Pengembalian -->
                                                <form method="POST" class="inline" onsubmit="return confirm('Konfirmasi pengembalian buku ini? Stok akan otomatis bertambah.');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="return_loan">
                                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                    <button type="submit" 
                                                            class="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-3 py-1 text-xs font-bold text-white shadow-sm transition cursor-pointer inline-flex items-center gap-1"
                                                            title="Kembalikan Buku">
                                                        <i class="fa-solid fa-arrow-down-to-bracket"></i> Kembalikan
                                                    </button>
                                                </form>

                                                <!-- Tombol Tandai Hilang -->
                                                <form method="POST" class="inline" onsubmit="return confirm('Tandai buku ini sebagai hilang?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="mark_lost">
                                                    <input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
                                                    <button type="submit" 
                                                            class="rounded-lg border border-rose-500/20 bg-rose-500/10 hover:bg-rose-500/20 px-2 py-1 text-xs text-rose-400 transition"
                                                            title="Tandai Hilang">
                                                        Hilang
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-500 inline-flex items-center gap-1">Selesai <i class="fa-solid fa-check text-emerald-400"></i></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Informasi Tata Tertib Perpustakaan -->
    <div class="rounded-2xl border border-white/5 bg-slate-900/40 p-4 text-xs text-slate-400 space-y-1">
        <p class="font-bold text-white flex items-center gap-1.5">
            <i class="fa-solid fa-circle-info text-blue-400"></i> Aturan Sirkulasi & Denda Perpustakaan:
        </p>
        <p>1. Maksimal peminjaman mandiri per siswa adalah 2 buku selama 7 hari kalender.</p>
        <p>2. Keterlambatan pengembalian buku dikenakan denda sebesar <strong>Rp 1.000 / hari keterlambatan</strong> per buku.</p>
        <p>3. Jagalah keutuhan fisik buku dan dilarang mencoret atau merusak halaman koleksi perpustakaan.</p>
    </div>

</div>

<?php if ($is_librarian): ?>
<!-- MODAL CATAT PEMINJAMAN BARU -->
<div id="loanModal" class="fixed inset-0 z-50 hidden bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="relative w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl text-slate-100 my-8">
        <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-6">
            <div>
                <h3 class="text-xl font-bold text-white">Catat Peminjaman Buku</h3>
                <p class="text-xs text-slate-400">Petugas Sirkulasi Perpustakaan</p>
            </div>
            <button onclick="closeLoanModal()" class="rounded-lg p-1 text-slate-400 hover:text-white hover:bg-white/10 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="loans.php" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_loan">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Pilih Buku Tersedia *</label>
                <select name="book_id" required
                        class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs sm:text-sm text-white focus:border-blue-500 focus:outline-none transition">
                    <option value="">-- Pilih Judul Buku --</option>
                    <?php foreach ($available_books as $ab): ?>
                        <option value="<?= $ab['id'] ?>" <?= ($preselected_book_id === (int)$ab['id']) ? 'selected' : '' ?>>
                            [<?= htmlspecialchars($ab['code']) ?>] <?= htmlspecialchars($ab['title']) ?> (Sisa: <?= $ab['stock_available'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Peminjam (Siswa / Guru) *</label>
                <select name="user_id" required
                        class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs sm:text-sm text-white focus:border-blue-500 focus:outline-none transition">
                    <option value="">-- Pilih Nama Peminjam --</option>
                    <?php foreach ($borrowers_list as $bl): ?>
                        <option value="<?= $bl['id'] ?>">
                            <?= htmlspecialchars($bl['name']) ?> (<?= ucfirst($bl['role']) ?><?= $bl['nisn'] ? ' - NISN: '.$bl['nisn'] : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tanggal Pinjam *</label>
                    <input type="date" name="borrow_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs sm:text-sm text-white focus:border-blue-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Jatuh Tempo (7 Hari) *</label>
                    <input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs sm:text-sm text-white focus:border-blue-500 focus:outline-none transition">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Catatan / Keperluan (Opsional)</label>
                <input type="text" name="notes" placeholder="Contoh: Tugas mandiri fisika kelompok 3"
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-blue-500 focus:outline-none transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="closeLoanModal()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-600/20 transition cursor-pointer inline-flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Peminjaman
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openLoanModal() {
    document.getElementById('loanModal').classList.remove('hidden');
}
function closeLoanModal() {
    document.getElementById('loanModal').classList.add('hidden');
}

// Auto open loan modal if book_id in URL
<?php if ($preselected_book_id > 0): ?>
    document.addEventListener('DOMContentLoaded', function() {
        openLoanModal();
    });
<?php endif; ?>
</script>
<?php endif; ?>

<?php include __DIR__ . "/../includes/footer.php"; ?>
