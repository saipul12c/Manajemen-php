<?php
/**
 * Kasir Sirkulasi Cepat Perpustakaan (QR & Barcode Scanner)
 * Memproses peminjaman dan pengembalian buku seketika menggunakan kamera scanner atau barcode reader.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();
requireRole(['staf', 'administrator']);

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$page_title = "Kasir Sirkulasi Cepat (Scan Barcode & QR)";

// -------------------------------------------------------------
// AJAX HANDLERS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Token keamanan CSRF tidak valid.']);
        exit;
    }

    $action = $_POST['action'];

    // 1. CARI DATA PEMINJAM (SISWA / GURU) DARI SCAN KARTU QR / NISN
    if ($action === 'find_borrower') {
        $raw_code = trim($_POST['code'] ?? '');
        if (empty($raw_code)) {
            echo json_encode(['success' => false, 'message' => 'Kode identitas tidak boleh kosong.']);
            exit;
        }

        // Pembersihan format kode: misal "ID-5", "STUDENT:5", atau NISN murni
        $cleaned = $raw_code;
        if (strpos($raw_code, ':') !== false) {
            $parts = explode(':', $raw_code);
            $cleaned = end($parts);
        }
        $cleaned = str_replace(['ID-', 'USER-'], '', $cleaned);

        $stmt = $pdo->prepare("
            SELECT u.id, u.name, u.role, u.nisn, u.gender, c.name as class_name
            FROM users u
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE (u.nisn = ? OR u.id = ? OR u.username = ?) AND u.role IN ('siswa', 'guru', 'staf')
            LIMIT 1
        ");
        $stmt->execute([$cleaned, is_numeric($cleaned) ? (int)$cleaned : 0, $cleaned]);
        $borrower = $stmt->fetch();

        if (!$borrower) {
            echo json_encode(['success' => false, 'message' => "Anggota tidak ditemukan untuk kode '$raw_code'."]);
            exit;
        }

        // Ambil riwayat pinjaman aktif & denda belum selesai
        $stmt_loans = $pdo->prepare("
            SELECT l.id, b.title, b.code, l.borrow_date, l.due_date,
                   DATEDIFF(CURRENT_DATE(), l.due_date) as days_overdue,
                   CASE WHEN CURRENT_DATE() > l.due_date THEN DATEDIFF(CURRENT_DATE(), l.due_date) * 1000 ELSE 0 END as fine
            FROM library_loans l
            JOIN library_books b ON l.book_id = b.id
            WHERE l.user_id = ? AND l.status = 'dipinjam'
            ORDER BY l.due_date ASC
        ");
        $stmt_loans->execute([$borrower['id']]);
        $active_loans = $stmt_loans->fetchAll();

        // Hitung batas kuota peminjaman
        $max_quota = ($borrower['role'] === 'guru') ? 7 : 3;
        $active_count = count($active_loans);
        $quota_remaining = max(0, $max_quota - $active_count);

        echo json_encode([
            'success' => true,
            'borrower' => [
                'id' => (int)$borrower['id'],
                'name' => $borrower['name'],
                'role' => $borrower['role'],
                'nisn' => $borrower['nisn'] ?: '-',
                'class_name' => $borrower['class_name'] ?: ($borrower['role'] === 'guru' ? 'Dewan Guru' : 'Staf Sekolah'),
                'active_count' => $active_count,
                'max_quota' => $max_quota,
                'quota_remaining' => $quota_remaining,
                'active_loans' => $active_loans
            ]
        ]);
        exit;
    }

    // 2. CARI DATA BUKU DARI SCAN BARCODE / KODE BUKU
    if ($action === 'find_book') {
        $book_code = trim($_POST['book_code'] ?? '');
        if (empty($book_code)) {
            echo json_encode(['success' => false, 'message' => 'Kode atau Barcode buku tidak boleh kosong.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id, code, isbn, title, author, publisher, category, shelf_location, 
                   stock_total, stock_available, cover_image
            FROM library_books
            WHERE code = ? OR isbn = ? OR id = ?
            LIMIT 1
        ");
        $stmt->execute([$book_code, $book_code, is_numeric($book_code) ? (int)$book_code : 0]);
        $book = $stmt->fetch();

        if (!$book) {
            echo json_encode(['success' => false, 'message' => "Buku dengan kode/barcode '$book_code' tidak ditemukan di katalog."]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'book' => [
                'id' => (int)$book['id'],
                'code' => $book['code'],
                'isbn' => $book['isbn'] ?: '-',
                'title' => $book['title'],
                'author' => $book['author'],
                'category' => $book['category'],
                'shelf_location' => $book['shelf_location'] ?: 'Rak Umum',
                'stock_available' => (int)$book['stock_available'],
                'stock_total' => (int)$book['stock_total'],
                'cover_image' => $book['cover_image'] ? (baseUrl() . '/' . $book['cover_image']) : null
            ]
        ]);
        exit;
    }

    // 3. PROSES PEMINJAMAN CEPAT
    if ($action === 'process_quick_borrow') {
        $target_user_id = (int)($_POST['borrower_id'] ?? 0);
        $book_ids       = json_decode($_POST['book_ids'] ?? '[]', true);
        $days_duration  = (int)($_POST['duration_days'] ?? 7);

        if ($target_user_id <= 0 || empty($book_ids)) {
            echo json_encode(['success' => false, 'message' => 'Peminjam atau daftar buku belum dipilih.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $today = date('Y-m-d');
            $due_date = date('Y-m-d', strtotime("+{$days_duration} days"));

            $borrowed_titles = [];

            foreach ($book_ids as $b_id) {
                $b_id = (int)$b_id;
                // Kunci dan cek stok buku
                $stmt_chk = $pdo->prepare("SELECT title, stock_available FROM library_books WHERE id = ? FOR UPDATE");
                $stmt_chk->execute([$b_id]);
                $b_info = $stmt_chk->fetch();

                if (!$b_info || (int)$b_info['stock_available'] <= 0) {
                    throw new Exception("Stok untuk buku '{$b_info['title']}' sudah habis atau tidak valid.");
                }

                // Catat transaksi peminjaman
                $stmt_ins = $pdo->prepare("
                    INSERT INTO library_loans (book_id, user_id, borrow_date, due_date, status, notes)
                    VALUES (?, ?, ?, ?, 'dipinjam', 'Peminjaman instan via Kasir Barcode')
                ");
                $stmt_ins->execute([$b_id, $target_user_id, $today, $due_date]);

                // Potong stok
                $stmt_dec = $pdo->prepare("UPDATE library_books SET stock_available = stock_available - 1 WHERE id = ?");
                $stmt_dec->execute([$b_id]);

                $borrowed_titles[] = $b_info['title'];
            }

            $pdo->commit();

            logActivity($pdo, 'QUICK_BORROW', "Peminjaman sirkulasi cepat untuk User ID $target_user_id (" . implode(', ', $borrowed_titles) . ")");

            echo json_encode([
                'success' => true,
                'message' => "Berhasil meminjamkan " . count($borrowed_titles) . " buku. Jatuh tempo: " . date('d/m/Y', strtotime($due_date)),
                'due_date_formatted' => date('d/m/Y', strtotime($due_date))
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            echo json_encode(['success' => false, 'message' => 'Gagal memproses peminjaman: ' . $e->getMessage()]);
            exit;
        }
    }

    // 4. CARI TRANSAKSI AKTIF BUKU UNTUK PENGEMBALIAN CEPAT
    if ($action === 'lookup_active_loan') {
        $book_code = trim($_POST['book_code'] ?? '');
        if (empty($book_code)) {
            echo json_encode(['success' => false, 'message' => 'Scan atau masukkan barcode buku.']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT l.id as loan_id, l.borrow_date, l.due_date, l.renewal_count,
                   b.id as book_id, b.code as book_code, b.title as book_title, b.shelf_location,
                   u.id as user_id, u.name as borrower_name, u.role as borrower_role, u.nisn,
                   c.name as class_name,
                   DATEDIFF(CURRENT_DATE(), l.due_date) as days_overdue
            FROM library_loans l
            JOIN library_books b ON l.book_id = b.id
            JOIN users u ON l.user_id = u.id
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE (b.code = ? OR b.isbn = ? OR b.id = ?) AND l.status = 'dipinjam'
            ORDER BY l.borrow_date ASC
            LIMIT 1
        ");
        $stmt->execute([$book_code, $book_code, is_numeric($book_code) ? (int)$book_code : 0]);
        $loan = $stmt->fetch();

        if (!$loan) {
            echo json_encode(['success' => false, 'message' => "Tidak ada data pinjaman aktif untuk buku dengan barcode '$book_code'."]);
            exit;
        }

        $today = date('Y-m-d');
        $days_late = max(0, (int)$loan['days_overdue']);
        $fine_amount = $days_late * 1000.00;

        echo json_encode([
            'success' => true,
            'loan' => [
                'loan_id' => (int)$loan['loan_id'],
                'book_id' => (int)$loan['book_id'],
                'book_code' => $loan['book_code'],
                'book_title' => $loan['book_title'],
                'shelf_location' => $loan['shelf_location'] ?: 'Rak A-1',
                'borrower_name' => $loan['borrower_name'],
                'borrower_role' => $loan['borrower_role'],
                'class_name' => $loan['class_name'] ?: ($loan['borrower_role'] === 'guru' ? 'Dewan Guru' : 'Staf'),
                'borrow_date' => date('d/m/Y', strtotime($loan['borrow_date'])),
                'due_date' => date('d/m/Y', strtotime($loan['due_date'])),
                'days_late' => $days_late,
                'fine_amount' => $fine_amount,
                'fine_formatted' => formatRupiah($fine_amount)
            ]
        ]);
        exit;
    }

    // 5. PROSES PENGEMBALIAN CEPAT
    if ($action === 'process_quick_return') {
        $loan_id = (int)($_POST['loan_id'] ?? 0);
        if ($loan_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID peminjaman tidak valid.']);
            exit;
        }

        try {
            $stmt_l = $pdo->prepare("SELECT * FROM library_loans WHERE id = ? AND status = 'dipinjam'");
            $stmt_l->execute([$loan_id]);
            $loan = $stmt_l->fetch();

            if (!$loan) {
                echo json_encode(['success' => false, 'message' => 'Peminjaman tidak ditemukan atau sudah dikembalikan.']);
                exit;
            }

            $pdo->beginTransaction();

            $today = date('Y-m-d');
            $due_date = $loan['due_date'];
            $days_late = max(0, (int)(strtotime($today) - strtotime($due_date)) / (60 * 60 * 24));
            $fine_amount = $days_late * 1000.00;

            // Update peminjaman
            $stmt_up = $pdo->prepare("
                UPDATE library_loans 
                SET return_date = ?, fine_amount = ?, status = 'kembali' 
                WHERE id = ?
            ");
            $stmt_up->execute([$today, $fine_amount, $loan_id]);

            // Kembalikan stok buku
            $stmt_inc = $pdo->prepare("UPDATE library_books SET stock_available = stock_available + 1 WHERE id = ?");
            $stmt_inc->execute([$loan['book_id']]);

            $pdo->commit();

            logActivity($pdo, 'QUICK_RETURN', "Pengembalian cepat buku ID {$loan['book_id']} (Pinjaman #$loan_id). Denda: " . formatRupiah($fine_amount));

            echo json_encode([
                'success' => true,
                'message' => "Buku berhasil dikembalikan ke rak!" . ($fine_amount > 0 ? " Total denda: " . formatRupiah($fine_amount) : " (Tepat waktu tanpa denda)."),
                'fine_amount' => $fine_amount,
                'fine_formatted' => formatRupiah($fine_amount)
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            echo json_encode(['success' => false, 'message' => 'Gagal memproses pengembalian: ' . $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
    exit;
}

// Data ringkas hari ini
$today_str = date('Y-m-d');
$today_borrows = (int)$pdo->query("SELECT COUNT(*) FROM library_loans WHERE borrow_date = '$today_str'")->fetchColumn();
$today_returns = (int)$pdo->query("SELECT COUNT(*) FROM library_loans WHERE return_date = '$today_str'")->fetchColumn();

// Ambil riwayat aktivitas sirkulasi terbaru (10 transaksi)
$recent_transactions = $pdo->query("
    SELECT l.id, l.borrow_date, l.due_date, l.return_date, l.status, l.fine_amount,
           b.code as book_code, b.title as book_title,
           u.name as borrower_name, u.role as borrower_role, c.name as class_name
    FROM library_loans l
    JOIN library_books b ON l.book_id = b.id
    JOIN users u ON l.user_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    ORDER BY l.id DESC
    LIMIT 8
")->fetchAll();

include __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Sub Navigasi Modul Perpustakaan -->
    <?php include __DIR__ . "/_nav.php"; ?>

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-2 rounded-full border border-blue-500/20 bg-blue-500/10 px-3 py-1 text-xs font-semibold text-blue-400 mb-2">
                <i class="fa-solid fa-barcode"></i> Fast Circulation Desk
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white">Kasir Sirkulasi Cepat</h1>
            <p class="text-sm text-slate-400 mt-1">Pindai barcode buku dan kartu QR siswa untuk transaksi peminjaman & pengembalian buku dalam 3 detik.</p>
        </div>

        <div class="flex items-center gap-3">
            <div class="rounded-xl border border-white/10 bg-slate-900/60 px-3.5 py-2 text-right backdrop-blur">
                <span class="text-[11px] text-slate-400 block">Sirkulasi Hari Ini</span>
                <span class="text-sm font-bold text-white">
                    <span class="text-emerald-400">+<?= $today_borrows ?> Pinjam</span> • 
                    <span class="text-blue-400"><?= $today_returns ?> Kembali</span>
                </span>
            </div>
        </div>
    </div>

    <!-- Mode Selector Tabs -->
    <div class="grid grid-cols-2 gap-3 max-w-md">
        <button type="button" id="tabBorrowBtn" onclick="switchMode('borrow')"
                class="rounded-2xl border p-3.5 text-center font-bold text-xs sm:text-sm transition-all flex items-center justify-center gap-2 cursor-pointer border-teal-500/50 bg-teal-500/20 text-white shadow-lg shadow-teal-500/10">
            <i class="fa-solid fa-book-medical text-base text-teal-400"></i> Peminjaman Cepat
        </button>
        <button type="button" id="tabReturnBtn" onclick="switchMode('return')"
                class="rounded-2xl border p-3.5 text-center font-bold text-xs sm:text-sm transition-all flex items-center justify-center gap-2 cursor-pointer border-white/10 bg-slate-900/40 text-slate-400 hover:text-white">
            <i class="fa-solid fa-box-archive text-base text-blue-400"></i> Pengembalian Cepat
        </button>
    </div>

    <!-- Grid Scanner & Circulation Panels -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        <!-- SISI KIRI: SCANNER INTERAKTIF (Kamera & Input Barcode) -->
        <div class="lg:col-span-5 space-y-4">
            <div class="rounded-3xl border border-white/10 bg-slate-900/70 p-5 backdrop-blur-xl shadow-2xl">
                <div class="flex items-center justify-between pb-3 mb-4 border-b border-white/10">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-teal-500/20 text-teal-400 border border-teal-500/30">
                            <i class="fa-solid fa-camera"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-white">Scanner Optik / Kamera</h3>
                            <p class="text-[11px] text-slate-400">Arahkan QR atau Barcode ke kamera</p>
                        </div>
                    </div>
                    <button type="button" id="toggleCameraBtn" onclick="toggleCamera()"
                            class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-semibold text-slate-300 transition flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-power-off text-emerald-400"></i> <span id="cameraStatusText">Buka Kamera</span>
                    </button>
                </div>

                <!-- Video Viewport Scanner -->
                <div class="relative overflow-hidden rounded-2xl border border-white/10 bg-slate-950 aspect-[4/3] flex items-center justify-center shadow-inner">
                    <div id="reader" class="w-full h-full"></div>
                    
                    <div id="cameraPlaceholder" class="absolute inset-0 flex flex-col items-center justify-center p-6 text-center text-slate-500 bg-slate-950">
                        <i class="fa-solid fa-qrcode text-5xl mb-3 text-slate-700 animate-pulse"></i>
                        <p class="text-xs font-medium text-slate-400">Kamera scanner sedang nonaktif</p>
                        <p class="text-[11px] text-slate-600 mt-1 max-w-xs">Klik tombol di atas untuk menyalakan kamera, atau gunakan input barcode keyboard/laser di bawah ini.</p>
                    </div>

                    <!-- Target Scan Box Overlay -->
                    <div id="scannerOverlay" class="absolute inset-8 border-2 border-teal-400/50 rounded-2xl pointer-events-none hidden flex flex-col justify-between p-2">
                        <div class="flex justify-between text-teal-400 text-xs">
                            <i class="fa-solid fa-angle-left"></i>
                            <i class="fa-solid fa-angle-right"></i>
                        </div>
                        <div class="text-center text-[10px] bg-black/60 backdrop-blur rounded px-2 py-0.5 text-teal-300 mx-auto">
                            Sejajarkan barcode / QR di dalam area ini
                        </div>
                        <div class="flex justify-between text-teal-400 text-xs">
                            <i class="fa-solid fa-angle-left rotate-180"></i>
                            <i class="fa-solid fa-angle-right rotate-180"></i>
                        </div>
                    </div>
                </div>

                <!-- Barcode Gun / Manual Input -->
                <div class="mt-4 pt-4 border-t border-white/10">
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5 flex items-center justify-between">
                        <span><i class="fa-solid fa-keyboard mr-1 text-slate-400"></i> Input Barcode / Laser Scanner USB</span>
                        <span class="text-[10px] text-teal-400 font-mono">Auto-detect Enter</span>
                    </label>
                    <div class="relative">
                        <input type="text" id="manualCodeInput" placeholder="Arahkan scanner laser atau ketik kode..."
                               autofocus
                               class="w-full rounded-xl border border-white/15 bg-slate-950 px-4 py-2.5 pl-10 text-xs sm:text-sm text-white placeholder-slate-500 focus:border-teal-500 focus:ring-1 focus:ring-teal-500 focus:outline-none transition font-mono">
                        <i class="fa-solid fa-barcode absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-sm"></i>
                        <button type="button" onclick="submitManualCode()"
                                class="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-lg bg-teal-600 hover:bg-teal-500 px-3 py-1.5 text-xs font-bold text-white transition cursor-pointer">
                            Cari
                        </button>
                    </div>
                    <p class="text-[11px] text-slate-500 mt-1.5">
                        Tip: Jika menggunakan barcode scanner USB, letakkan kursor pada kolom di atas dan tembak barcode.
                    </p>
                </div>
            </div>

            <!-- Panduan Cepat -->
            <div class="rounded-2xl border border-white/10 bg-slate-900/40 p-4 text-xs text-slate-400 space-y-2">
                <div class="font-bold text-slate-200 flex items-center gap-1.5">
                    <i class="fa-solid fa-circle-question text-teal-400"></i> Petunjuk Penggunaan Cepat:
                </div>
                <div id="guideBorrow" class="space-y-1.5 text-[11px]">
                    <p>1. Pindai <strong>Kartu Siswa (QR / NISN)</strong> terlebih dahulu untuk memuat profil peminjam.</p>
                    <p>2. Pindai <strong>Barcode Buku (BK-xxx)</strong> untuk menambahkan buku ke daftar pinjaman.</p>
                    <p>3. Tinjau batas waktu jatuh tempo lalu klik <strong>Selesaikan Peminjaman</strong>.</p>
                </div>
                <div id="guideReturn" class="space-y-1.5 text-[11px] hidden">
                    <p>1. Langsung pindai <strong>Barcode Buku (BK-xxx)</strong> yang dikembalikan siswa.</p>
                    <p>2. Sistem langsung menampilkan nama peminjam dan menghitung denda otomatis jika telat.</p>
                    <p>3. Klik <strong>Konfirmasi Pengembalian</strong> untuk mengembalikan stok buku ke rak.</p>
                </div>
            </div>
        </div>

        <!-- SISI KANAN: WORKSPACE TRANSAKSI -->
        <div class="lg:col-span-7 space-y-5">

            <!-- ============================================== -->
            <!-- 1. WORKSPACE MODE PEMINJAMAN CEPAT             -->
            <!-- ============================================== -->
            <div id="borrowWorkspace" class="space-y-5">
                
                <!-- KARTU IDENTITAS PEMINJAM -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/70 p-5 backdrop-blur-xl shadow-2xl relative overflow-hidden">
                    <div class="flex items-center justify-between pb-3 mb-4 border-b border-white/10">
                        <span class="text-xs font-bold uppercase tracking-wider text-teal-400 flex items-center gap-1.5">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-teal-500/20 text-[10px]">1</span>
                            Identitas Peminjam
                        </span>
                        <button type="button" id="resetBorrowerBtn" onclick="resetBorrower()" class="text-xs text-rose-400 hover:text-rose-300 hidden">
                            <i class="fa-solid fa-user-xmark mr-1"></i> Ganti Peminjam
                        </button>
                    </div>

                    <!-- Placeholder Peminjam Kosong -->
                    <div id="borrowerEmptyState" class="py-6 text-center text-slate-500">
                        <i class="fa-solid fa-id-card-clip text-4xl mb-2 text-slate-700"></i>
                        <p class="text-xs text-slate-300 font-semibold">Belum Ada Peminjam Terpilih</p>
                        <p class="text-[11px] text-slate-500 mt-0.5">Scan kartu QR siswa atau ketik NISN pada kolom scanner untuk memulai.</p>
                    </div>

                    <!-- Card Detail Peminjam (Muncul setelah scan) -->
                    <div id="borrowerCard" class="hidden">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div class="flex items-center gap-3.5">
                                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-tr from-teal-500 to-blue-600 text-white font-black text-lg shadow-lg shadow-teal-500/20">
                                    <span id="bInitials">A</span>
                                </div>
                                <div>
                                    <h4 id="bName" class="text-base font-bold text-white leading-tight">Nama Anggota</h4>
                                    <div class="flex flex-wrap items-center gap-2 mt-1 text-xs text-slate-400">
                                        <span id="bRoleBadge" class="rounded-md bg-teal-500/10 border border-teal-500/30 px-2 py-0.5 text-[10px] font-bold text-teal-300 uppercase">Siswa</span>
                                        <span>•</span>
                                        <span id="bClass" class="text-slate-300">Kelas</span>
                                        <span>•</span>
                                        <span class="font-mono text-[11px] text-slate-400">NISN: <span id="bNisn">-</span></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Kuota Pinjam Box -->
                            <div class="rounded-2xl border border-white/10 bg-slate-950/80 p-3 text-center sm:text-right">
                                <span class="text-[10px] text-slate-400 block uppercase font-semibold">Sisa Kuota Pinjam</span>
                                <span class="text-lg font-black text-teal-400">
                                    <span id="bQuotaRemaining">0</span> / <span id="bQuotaMax">3</span> Buku
                                </span>
                            </div>
                        </div>

                        <!-- Info Pinjaman Aktif -->
                        <div id="activeLoansWarning" class="mt-3.5 pt-3 border-t border-white/5 text-xs hidden">
                            <p class="text-amber-400 font-semibold flex items-center gap-1.5 mb-1.5">
                                <i class="fa-solid fa-triangle-exclamation"></i> Buku Yang Sedang Dipinjam Sebelumnya:
                            </p>
                            <div id="activeLoansList" class="space-y-1 text-[11px] text-slate-400"></div>
                        </div>
                    </div>
                </div>

                <!-- KERANJANG BUKU YANG AKAN DIPINJAM -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/70 p-5 backdrop-blur-xl shadow-2xl">
                    <div class="flex items-center justify-between pb-3 mb-4 border-b border-white/10">
                        <span class="text-xs font-bold uppercase tracking-wider text-teal-400 flex items-center gap-1.5">
                            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-teal-500/20 text-[10px]">2</span>
                            Daftar Buku Yang Dipinjam
                        </span>
                        <span id="bookCountBadge" class="text-xs font-mono rounded-lg bg-slate-800 px-2.5 py-1 text-slate-300">0 Buku</span>
                    </div>

                    <!-- List Buku Terpilih -->
                    <div id="borrowCartList" class="space-y-2.5 min-h-[120px]">
                        <div id="emptyCartMessage" class="py-8 text-center text-slate-500">
                            <i class="fa-solid fa-book text-3xl mb-2 text-slate-700"></i>
                            <p class="text-xs text-slate-400">Keranjang Buku Masih Kosong</p>
                            <p class="text-[11px] text-slate-600">Scan barcode buku fisik satu per satu untuk dimasukkan ke daftar ini.</p>
                        </div>
                    </div>

                    <!-- Parameter Durasi & Jatuh Tempo -->
                    <div class="mt-5 pt-4 border-t border-white/10 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Durasi Peminjaman</label>
                            <select id="borrowDuration" onchange="updateDueDate()"
                                    class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                                <option value="7" selected>7 Hari (Standar Siswa)</option>
                                <option value="14">14 Hari (Standar Guru / Tugas Panjang)</option>
                                <option value="3">3 Hari (Buku Referensi Kilat)</option>
                                <option value="30">30 Hari (Modul Khusus)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Tanggal Jatuh Tempo</label>
                            <div id="previewDueDate" class="rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-teal-400 font-bold font-mono">
                                <?= date('d/m/Y', strtotime('+7 days')) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tombol Eksekusi Peminjaman -->
                    <div class="mt-5 pt-4 border-t border-white/10 flex items-center justify-end gap-3">
                        <button type="button" onclick="clearBorrowCart()"
                                class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-xs font-semibold text-slate-300 transition cursor-pointer">
                            Reset Form
                        </button>
                        <button type="button" id="submitBorrowBtn" onclick="submitQuickBorrow()" disabled
                                class="rounded-xl bg-teal-600 hover:bg-teal-500 disabled:opacity-40 disabled:cursor-not-allowed px-6 py-2.5 text-xs sm:text-sm font-bold text-white shadow-lg shadow-teal-600/20 transition cursor-pointer inline-flex items-center gap-2">
                            <i class="fa-solid fa-floppy-disk"></i> Selesaikan Peminjaman (1 Klik)
                        </button>
                    </div>
                </div>

            </div>

            <!-- ============================================== -->
            <!-- 2. WORKSPACE MODE PENGEMBALIAN CEPAT           -->
            <!-- ============================================== -->
            <div id="returnWorkspace" class="space-y-5 hidden">
                <div class="rounded-3xl border border-white/10 bg-slate-900/70 p-6 backdrop-blur-xl shadow-2xl">
                    <div class="flex items-center justify-between pb-3 mb-5 border-b border-white/10">
                        <h3 class="text-sm font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-rotate-left text-blue-400"></i> Pengembalian Koleksi Buku
                        </h3>
                        <span class="text-xs text-slate-400">Status Instan</span>
                    </div>

                    <!-- Placeholder Pengembalian Kosong -->
                    <div id="returnEmptyState" class="py-12 text-center text-slate-500">
                        <i class="fa-solid fa-barcode text-5xl mb-3 text-slate-700 animate-bounce"></i>
                        <p class="text-sm text-slate-300 font-bold">Siap Menerima Pengembalian Buku</p>
                        <p class="text-xs text-slate-500 mt-1 max-w-sm mx-auto">Cukup arahkan barcode buku ke kamera scanner atau tembakkan scanner USB ke kolom barcode.</p>
                    </div>

                    <!-- Hasil Scan Peminjaman Ditemukan -->
                    <div id="returnResultCard" class="hidden space-y-4">
                        <div class="rounded-2xl border border-white/10 bg-slate-950 p-4 sm:p-5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <span class="rounded-md bg-blue-500/10 border border-blue-500/30 px-2 py-0.5 text-[10px] font-bold text-blue-400 font-mono" id="retBookCode">BK-001</span>
                                    <h3 id="retBookTitle" class="text-base sm:text-lg font-bold text-white mt-1.5">Judul Buku</h3>
                                    <p class="text-xs text-slate-400 mt-0.5">Lokasi Rak: <strong id="retShelfLocation" class="text-slate-200">Rak A-1</strong></p>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] text-slate-500 block uppercase">Peminjam</span>
                                    <span id="retBorrowerName" class="text-sm font-bold text-white">Nama Peminjam</span>
                                    <span id="retBorrowerClass" class="text-xs text-slate-400 block">Kelas</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 mt-4 pt-4 border-t border-white/10 text-xs">
                                <div>
                                    <span class="text-slate-500 block text-[10px]">Tgl Pinjam</span>
                                    <span id="retBorrowDate" class="font-mono text-slate-300">-</span>
                                </div>
                                <div>
                                    <span class="text-slate-500 block text-[10px]">Jatuh Tempo</span>
                                    <span id="retDueDate" class="font-mono text-slate-300">-</span>
                                </div>
                                <div>
                                    <span class="text-slate-500 block text-[10px]">Keterlambatan</span>
                                    <span id="retLateDays" class="font-bold text-emerald-400">0 Hari</span>
                                </div>
                                <div>
                                    <span class="text-slate-500 block text-[10px]">Denda Dihitung</span>
                                    <span id="retFineAmount" class="font-bold text-slate-300">Rp 0</span>
                                </div>
                            </div>
                        </div>

                        <!-- Alert Box Denda Jika Terlambat -->
                        <div id="lateFineAlert" class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs text-amber-300 flex items-center justify-between hidden">
                            <div class="flex items-center gap-3">
                                <i class="fa-solid fa-triangle-exclamation text-amber-400 text-lg"></i>
                                <div>
                                    <span class="font-bold block">Peminjaman Melebihi Batas Jatuh Tempo!</span>
                                    <span>Siswa dikenakan denda keterlambatan sebesar <strong id="alertFineText">Rp 0</strong>. Mohon terima pembayaran denda di kasir.</span>
                                </div>
                            </div>
                        </div>

                        <!-- Tombol Konfirmasi Selesai Pengembalian -->
                        <div class="flex items-center justify-end gap-3 pt-3">
                            <button type="button" onclick="cancelReturn()"
                                    class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-xs font-semibold text-slate-300 transition cursor-pointer">
                                Batal
                            </button>
                            <button type="button" id="confirmReturnBtn" onclick="submitQuickReturn()"
                                    class="rounded-xl bg-blue-600 hover:bg-blue-500 px-6 py-2.5 text-xs sm:text-sm font-bold text-white shadow-lg shadow-blue-600/20 transition cursor-pointer inline-flex items-center gap-2">
                                <i class="fa-solid fa-circle-check"></i> Konfirmasi Pengembalian Buku
                            </button>
                        </div>
                    </div>

                </div>
            </div>

            <!-- RIWAYAT TRANSAKSI TERAKHIR -->
            <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-5 backdrop-blur-xl">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-white/10">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center gap-2">
                        <i class="fa-solid fa-clock-rotate-left text-slate-400"></i> Transaksi Sirkulasi Terbaru
                    </h3>
                    <a href="loans.php" class="text-xs text-teal-400 hover:underline">Lihat Semua Sirkulasi &rarr;</a>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-white/5 text-[11px] text-slate-400">
                                <th class="py-2">Peminjam</th>
                                <th class="py-2">Buku</th>
                                <th class="py-2">Jatuh Tempo</th>
                                <th class="py-2 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5 text-slate-300" id="recentCirculationTable">
                            <?php foreach ($recent_transactions as $rt): ?>
                                <tr class="hover:bg-white/5 transition">
                                    <td class="py-2.5">
                                        <div class="font-bold text-white"><?= htmlspecialchars($rt['borrower_name']) ?></div>
                                        <div class="text-[10px] text-slate-400"><?= htmlspecialchars($rt['class_name'] ?: ucfirst($rt['borrower_role'])) ?></div>
                                    </td>
                                    <td class="py-2.5">
                                        <div class="font-medium text-slate-200"><?= htmlspecialchars($rt['book_title']) ?></div>
                                        <div class="font-mono text-[10px] text-teal-400"><?= htmlspecialchars($rt['book_code']) ?></div>
                                    </td>
                                    <td class="py-2.5 font-mono text-[11px]">
                                        <?= date('d/m/Y', strtotime($rt['due_date'])) ?>
                                    </td>
                                    <td class="py-2.5 text-right">
                                        <?php if ($rt['status'] === 'dipinjam'): ?>
                                            <span class="rounded-full bg-amber-500/10 border border-amber-500/30 px-2 py-0.5 text-[10px] font-bold text-amber-300">Dipinjam</span>
                                        <?php elseif ($rt['status'] === 'kembali'): ?>
                                            <span class="rounded-full bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 text-[10px] font-bold text-emerald-300">Kembali</span>
                                        <?php else: ?>
                                            <span class="rounded-full bg-rose-500/10 border border-rose-500/30 px-2 py-0.5 text-[10px] font-bold text-rose-300">Hilang</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- SCRIPT SCANNER & SIRKULASI -->
<script>
const CSRF_TOKEN = '<?= generateCsrfToken() ?>';
let currentMode = 'borrow'; // 'borrow' atau 'return'

// Audio Synthesis Beep
function playAudioBeep(type = 'success') {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);

        if (type === 'success') {
            osc.frequency.setValueAtTime(880, audioCtx.currentTime); // A5
            gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.12);
        } else if (type === 'double') {
            osc.frequency.setValueAtTime(1046.5, audioCtx.currentTime); // C6
            gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.08);
            setTimeout(() => {
                const osc2 = audioCtx.createOscillator();
                const gain2 = audioCtx.createGain();
                osc2.connect(gain2);
                gain2.connect(audioCtx.destination);
                osc2.frequency.setValueAtTime(1318.5, audioCtx.currentTime); // E6
                gain2.gain.setValueAtTime(0.2, audioCtx.currentTime);
                osc2.start();
                osc2.stop(audioCtx.currentTime + 0.12);
            }, 90);
        } else {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(220, audioCtx.currentTime); // Low warning
            gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.25);
        }
    } catch (e) {
        // Fallback jika audio context terblokir
    }
}

// ----------------------------------------------------
// CAMERA SCANNER MANAGEMENT (html5-qrcode)
// ----------------------------------------------------
let html5QrCode = null;
let isCameraRunning = false;

function toggleCamera() {
    if (isCameraRunning) {
        stopCamera();
    } else {
        startCamera();
    }
}

function startCamera() {
    const readerElem = document.getElementById('reader');
    if (!readerElem) return;

    if (!html5QrCode) {
        html5QrCode = new Html5Qrcode("reader");
    }

    const config = {
        fps: 12,
        qrbox: { width: 250, height: 250 },
        aspectRatio: 1.333334
    };

    html5QrCode.start(
        { facingMode: "environment" },
        config,
        onScanSuccess,
        onScanFailure
    ).then(() => {
        isCameraRunning = true;
        document.getElementById('cameraPlaceholder').classList.add('hidden');
        document.getElementById('scannerOverlay').classList.remove('hidden');
        document.getElementById('cameraStatusText').textContent = 'Matikan Kamera';
    }).catch(err => {
        alert("Tidak dapat mengakses kamera: " + err);
    });
}

function stopCamera() {
    if (html5QrCode && isCameraRunning) {
        html5QrCode.stop().then(() => {
            isCameraRunning = false;
            document.getElementById('cameraPlaceholder').classList.remove('hidden');
            document.getElementById('scannerOverlay').classList.add('hidden');
            document.getElementById('cameraStatusText').textContent = 'Buka Kamera';
        }).catch(err => console.error(err));
    }
}

let lastScanTime = 0;
function onScanSuccess(decodedText, decodedResult) {
    const now = Date.now();
    if (now - lastScanTime < 1800) {
        return; // debounce 1.8 detik
    }
    lastScanTime = now;
    processScannedCode(decodedText);
}

function onScanFailure(error) {
    // Normal ignore frame failures
}

// ----------------------------------------------------
// MODE SWITCHING & KEYBOARD LISTENERS
// ----------------------------------------------------
function switchMode(mode) {
    currentMode = mode;
    const tabBorrow = document.getElementById('tabBorrowBtn');
    const tabReturn = document.getElementById('tabReturnBtn');
    const borrowWs  = document.getElementById('borrowWorkspace');
    const returnWs  = document.getElementById('returnWorkspace');
    const guideB    = document.getElementById('guideBorrow');
    const guideR    = document.getElementById('guideReturn');

    if (mode === 'borrow') {
        tabBorrow.className = "rounded-2xl border p-3.5 text-center font-bold text-xs sm:text-sm transition-all flex items-center justify-center gap-2 cursor-pointer border-teal-500/50 bg-teal-500/20 text-white shadow-lg shadow-teal-500/10";
        tabReturn.className = "rounded-2xl border p-3.5 text-center font-bold text-xs sm:text-sm transition-all flex items-center justify-center gap-2 cursor-pointer border-white/10 bg-slate-900/40 text-slate-400 hover:text-white";
        borrowWs.classList.remove('hidden');
        returnWs.classList.add('hidden');
        guideB.classList.remove('hidden');
        guideR.classList.add('hidden');
    } else {
        tabReturn.className = "rounded-2xl border p-3.5 text-center font-bold text-xs sm:text-sm transition-all flex items-center justify-center gap-2 cursor-pointer border-blue-500/50 bg-blue-500/20 text-white shadow-lg shadow-blue-500/10";
        tabBorrow.className = "rounded-2xl border p-3.5 text-center font-bold text-xs sm:text-sm transition-all flex items-center justify-center gap-2 cursor-pointer border-white/10 bg-slate-900/40 text-slate-400 hover:text-white";
        returnWs.classList.remove('hidden');
        borrowWs.classList.add('hidden');
        guideR.classList.remove('hidden');
        guideB.classList.add('hidden');
    }

    document.getElementById('manualCodeInput').focus();
}

// Input keyboard barcode gun enter key
document.getElementById('manualCodeInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        submitManualCode();
    }
});

function submitManualCode() {
    const input = document.getElementById('manualCodeInput');
    const code = input.value.trim();
    if (!code) return;
    processScannedCode(code);
    input.value = '';
    input.focus();
}

// ----------------------------------------------------
// DISPATCHER PROSES SCAN KODE
// ----------------------------------------------------
function processScannedCode(code) {
    if (currentMode === 'borrow') {
        // Jika belum ada peminjam terpilih, anggap kode ini identitas peminjam
        if (!selectedBorrower) {
            lookupBorrower(code);
        } else {
            // Jika peminjam sudah ada, anggap scan buku
            lookupBookForBorrow(code);
        }
    } else {
        // Mode pengembalian: cari pinjaman aktif dari barcode buku
        lookupLoanForReturn(code);
    }
}

// ----------------------------------------------------
// LOGIKA PEMINJAMAN CEPAT
// ----------------------------------------------------
let selectedBorrower = null;
let borrowCart = []; // Array of book objects

function lookupBorrower(code) {
    const fd = new FormData();
    fd.append('action', 'find_borrower');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('code', code);

    fetch('scan.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                selectedBorrower = res.borrower;
                playAudioBeep('double');
                renderBorrowerCard();
            } else {
                playAudioBeep('error');
                // Cek apakah mungkin yang discan adalah buku saat peminjam belum dipilih
                alert(res.message + "\n\nTip: Pindai Kartu Siswa / NISN terlebih dahulu.");
            }
        })
        .catch(err => alert("Terjadi kesalahan jaringan: " + err));
}

function renderBorrowerCard() {
    if (!selectedBorrower) return;

    document.getElementById('borrowerEmptyState').classList.add('hidden');
    document.getElementById('borrowerCard').classList.remove('hidden');
    document.getElementById('resetBorrowerBtn').classList.remove('hidden');

    document.getElementById('bName').textContent = selectedBorrower.name;
    document.getElementById('bInitials').textContent = selectedBorrower.name.charAt(0).toUpperCase();
    document.getElementById('bRoleBadge').textContent = selectedBorrower.role.toUpperCase();
    document.getElementById('bClass').textContent = selectedBorrower.class_name;
    document.getElementById('bNisn').textContent = selectedBorrower.nisn;
    document.getElementById('bQuotaRemaining').textContent = selectedBorrower.quota_remaining;
    document.getElementById('bQuotaMax').textContent = selectedBorrower.max_quota;

    // Warning jika ada pinjaman aktif
    const activeWarn = document.getElementById('activeLoansWarning');
    const activeList = document.getElementById('activeLoansList');
    if (selectedBorrower.active_loans && selectedBorrower.active_loans.length > 0) {
        activeWarn.classList.remove('hidden');
        activeList.innerHTML = selectedBorrower.active_loans.map(al => `
            <div class="flex items-center justify-between py-0.5">
                <span>• ${al.title} (${al.code})</span>
                <span class="${al.days_overdue > 0 ? 'text-rose-400 font-bold' : 'text-slate-400'}">
                    Jatuh tempo: ${al.due_date} ${al.days_overdue > 0 ? `(Telat ${al.days_overdue} hari)` : ''}
                </span>
            </div>
        `).join('');
    } else {
        activeWarn.classList.add('hidden');
    }

    validateBorrowButton();
}

function resetBorrower() {
    selectedBorrower = null;
    document.getElementById('borrowerCard').classList.add('hidden');
    document.getElementById('resetBorrowerBtn').classList.add('hidden');
    document.getElementById('borrowerEmptyState').classList.remove('hidden');
    validateBorrowButton();
}

function lookupBookForBorrow(bookCode) {
    if (!selectedBorrower) {
        alert("Pindai kartu identitas peminjam terlebih dahulu.");
        return;
    }

    // Cek batas kuota
    if (borrowCart.length >= selectedBorrower.quota_remaining) {
        playAudioBeep('error');
        alert(`Batas kuota peminjaman tercapai! ${selectedBorrower.name} hanya dapat meminjam maksimal ${selectedBorrower.quota_remaining} buku lagi.`);
        return;
    }

    // Cek duplikasi di keranjang
    if (borrowCart.some(b => b.code.toLowerCase() === bookCode.toLowerCase() || b.isbn.toLowerCase() === bookCode.toLowerCase())) {
        playAudioBeep('error');
        alert("Buku ini sudah ada di dalam keranjang peminjaman.");
        return;
    }

    const fd = new FormData();
    fd.append('action', 'find_book');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('book_code', bookCode);

    fetch('scan.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                if (res.book.stock_available <= 0) {
                    playAudioBeep('error');
                    alert(`Maaf, stok buku '${res.book.title}' sedang habis dipinjam.`);
                    return;
                }
                playAudioBeep('success');
                borrowCart.push(res.book);
                renderBorrowCart();
            } else {
                playAudioBeep('error');
                alert(res.message);
            }
        })
        .catch(err => alert("Terjadi kesalahan jaringan: " + err));
}

function renderBorrowCart() {
    const list = document.getElementById('borrowCartList');
    const badge = document.getElementById('bookCountBadge');
    badge.textContent = `${borrowCart.length} Buku`;

    if (borrowCart.length === 0) {
        list.innerHTML = `
            <div id="emptyCartMessage" class="py-8 text-center text-slate-500">
                <i class="fa-solid fa-book text-3xl mb-2 text-slate-700"></i>
                <p class="text-xs text-slate-400">Keranjang Buku Masih Kosong</p>
                <p class="text-[11px] text-slate-600">Scan barcode buku fisik satu per satu untuk dimasukkan ke daftar ini.</p>
            </div>
        `;
    } else {
        list.innerHTML = borrowCart.map((b, idx) => `
            <div class="flex items-center justify-between gap-3 rounded-2xl border border-white/10 bg-slate-950 p-3 text-xs transition hover:border-teal-500/30">
                <div class="flex items-center gap-3">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-teal-500/20 text-teal-400 font-mono text-[10px] font-bold">
                        #${idx + 1}
                    </div>
                    <div>
                        <h5 class="font-bold text-white leading-tight line-clamp-1">${b.title}</h5>
                        <div class="flex items-center gap-2 text-[10px] text-slate-400 mt-0.5">
                            <span class="font-mono text-teal-400">${b.code}</span>
                            <span>•</span>
                            <span>${b.shelf_location}</span>
                            <span>•</span>
                            <span>Stok: ${b.stock_available}</span>
                        </div>
                    </div>
                </div>
                <button type="button" onclick="removeBookFromCart(${idx})" class="p-1.5 text-slate-500 hover:text-rose-400 transition" title="Hapus">
                    <i class="fa-solid fa-trash-can"></i>
                </button>
            </div>
        `).join('');
    }

    validateBorrowButton();
}

function removeBookFromCart(index) {
    borrowCart.splice(index, 1);
    renderBorrowCart();
}

function clearBorrowCart() {
    borrowCart = [];
    resetBorrower();
    renderBorrowCart();
}

function validateBorrowButton() {
    const btn = document.getElementById('submitBorrowBtn');
    if (selectedBorrower && borrowCart.length > 0) {
        btn.removeAttribute('disabled');
    } else {
        btn.setAttribute('disabled', 'disabled');
    }
}

function updateDueDate() {
    const days = parseInt(document.getElementById('borrowDuration').value);
    const targetDate = new Date();
    targetDate.setDate(targetDate.getDate() + days);
    
    const d = String(targetDate.getDate()).padStart(2, '0');
    const m = String(targetDate.getMonth() + 1).padStart(2, '0');
    const y = targetDate.getFullYear();
    document.getElementById('previewDueDate').textContent = `${d}/${m}/${y}`;
}

function submitQuickBorrow() {
    if (!selectedBorrower || borrowCart.length === 0) return;

    const fd = new FormData();
    fd.append('action', 'process_quick_borrow');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('borrower_id', selectedBorrower.id);
    fd.append('duration_days', document.getElementById('borrowDuration').value);
    fd.append('book_ids', JSON.stringify(borrowCart.map(b => b.id)));

    const btn = document.getElementById('submitBorrowBtn');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';
    btn.disabled = true;

    fetch('scan.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Selesaikan Peminjaman (1 Klik)';
            if (res.success) {
                playAudioBeep('double');
                alert(res.message);
                window.location.reload();
            } else {
                playAudioBeep('error');
                btn.disabled = false;
                alert(res.message);
            }
        })
        .catch(err => {
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Selesaikan Peminjaman (1 Klik)';
            btn.disabled = false;
            alert("Terjadi kesalahan jaringan: " + err);
        });
}

// ----------------------------------------------------
// LOGIKA PENGEMBALIAN CEPAT
// ----------------------------------------------------
let activeReturnLoan = null;

function lookupLoanForReturn(bookCode) {
    const fd = new FormData();
    fd.append('action', 'lookup_active_loan');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('book_code', bookCode);

    fetch('scan.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                activeReturnLoan = res.loan;
                playAudioBeep('double');
                renderReturnCard();
            } else {
                playAudioBeep('error');
                alert(res.message);
            }
        })
        .catch(err => alert("Terjadi kesalahan: " + err));
}

function renderReturnCard() {
    if (!activeReturnLoan) return;

    document.getElementById('returnEmptyState').classList.add('hidden');
    document.getElementById('returnResultCard').classList.remove('hidden');

    document.getElementById('retBookCode').textContent = activeReturnLoan.book_code;
    document.getElementById('retBookTitle').textContent = activeReturnLoan.book_title;
    document.getElementById('retShelfLocation').textContent = activeReturnLoan.shelf_location;
    document.getElementById('retBorrowerName').textContent = activeReturnLoan.borrower_name;
    document.getElementById('retBorrowerClass').textContent = activeReturnLoan.class_name;
    document.getElementById('retBorrowDate').textContent = activeReturnLoan.borrow_date;
    document.getElementById('retDueDate').textContent = activeReturnLoan.due_date;
    
    const lateDaysElem = document.getElementById('retLateDays');
    const fineElem = document.getElementById('retFineAmount');
    const lateAlert = document.getElementById('lateFineAlert');

    if (activeReturnLoan.days_late > 0) {
        lateDaysElem.textContent = `${activeReturnLoan.days_late} Hari Terlambat`;
        lateDaysElem.className = 'font-bold text-rose-400';
        fineElem.textContent = activeReturnLoan.fine_formatted;
        fineElem.className = 'font-bold text-rose-400 font-mono';
        
        document.getElementById('alertFineText').textContent = activeReturnLoan.fine_formatted;
        lateAlert.classList.remove('hidden');
    } else {
        lateDaysElem.textContent = 'Tepat Waktu';
        lateDaysElem.className = 'font-bold text-emerald-400';
        fineElem.textContent = 'Rp 0 (Bebas Denda)';
        fineElem.className = 'font-bold text-emerald-400';
        lateAlert.classList.add('hidden');
    }
}

function cancelReturn() {
    activeReturnLoan = null;
    document.getElementById('returnResultCard').classList.add('hidden');
    document.getElementById('returnEmptyState').classList.remove('hidden');
    document.getElementById('manualCodeInput').focus();
}

function submitQuickReturn() {
    if (!activeReturnLoan) return;

    const fd = new FormData();
    fd.append('action', 'process_quick_return');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('loan_id', activeReturnLoan.loan_id);

    const btn = document.getElementById('confirmReturnBtn');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memproses...';
    btn.disabled = true;

    fetch('scan.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Konfirmasi Pengembalian Buku';
            if (res.success) {
                playAudioBeep('double');
                alert(res.message);
                window.location.reload();
            } else {
                playAudioBeep('error');
                btn.disabled = false;
                alert(res.message);
            }
        })
        .catch(err => {
            btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Konfirmasi Pengembalian Buku';
            btn.disabled = false;
            alert("Terjadi kesalahan jaringan: " + err);
        });
}
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
