<?php
/**
 * Modul Reservasi & Booking Buku Perpustakaan
 * Memungkinkan siswa/guru memesan buku mandiri dan petugas memproses antrean sirkulasi.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$page_title = "Booking & Reservasi Buku";

$is_librarian = in_array($user_role, ['administrator', 'staf'], true);

$message = "";
$message_type = "";

// Pastikan tabel library_reservations tersedia
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `library_reservations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `book_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `reservation_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `expiry_date` DATE NOT NULL,
            `status` ENUM('menunggu', 'disiapkan', 'selesai', 'dibatalkan', 'kedaluwarsa') NOT NULL DEFAULT 'menunggu',
            `notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {}

// 0. AUTO-UPDATE KEDALUWARSA RESERVASI (Batas waktu lewat)
try {
    $pdo->query("
        UPDATE library_reservations 
        SET status = 'kedaluwarsa' 
        WHERE expiry_date < CURRENT_DATE() AND status IN ('menunggu', 'disiapkan')
    ");
} catch (Exception $e) {}

// 1. ACTION: TANDAI SIAP DIAMBIL (Librarian only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ready_reservation') {
    if (!$is_librarian) {
        $message = "Hanya petugas perpustakaan yang dapat mengubah status.";
        $message_type = "error";
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $res_id = (int)($_POST['reservation_id'] ?? 0);
        $stmt_up = $pdo->prepare("UPDATE library_reservations SET status = 'disiapkan' WHERE id = ?");
        $stmt_up->execute([$res_id]);
        logActivity($pdo, 'READY_RESERVATION', "Menyiapkan buku reservasi ID #$res_id di meja sirkulasi");
        $message = "Buku telah ditandai SIAP DIAMBIL di meja sirkulasi!";
        $message_type = "success";
    }
}

// 2. ACTION: PROSES JADI PINJAMAN RESMI (Librarian only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'convert_to_loan') {
    if (!$is_librarian) {
        $message = "Hanya petugas perpustakaan yang dapat memproses peminjaman.";
        $message_type = "error";
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $res_id = (int)($_POST['reservation_id'] ?? 0);
        $stmt_r = $pdo->prepare("SELECT * FROM library_reservations WHERE id = ? LIMIT 1");
        $stmt_r->execute([$res_id]);
        $res = $stmt_r->fetch();

        if (!$res) {
            $message = "Data reservasi tidak ditemukan.";
            $message_type = "error";
        } elseif ($res['status'] === 'selesai') {
            $message = "Reservasi ini sudah diproses sebelumnya.";
            $message_type = "error";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Kurangi stok buku jika belum 0
                $stmt_dec = $pdo->prepare("UPDATE library_books SET stock_available = GREATEST(0, stock_available - 1) WHERE id = ?");
                $stmt_dec->execute([$res['book_id']]);

                // 2. Tambah record library_loans
                $borrow_date = date('Y-m-d');
                $due_date = date('Y-m-d', strtotime('+7 days'));
                $stmt_loan = $pdo->prepare("
                    INSERT INTO library_loans (book_id, user_id, borrow_date, due_date, status, notes)
                    VALUES (?, ?, ?, ?, 'dipinjam', ?)
                ");
                $stmt_loan->execute([
                    $res['book_id'],
                    $res['user_id'],
                    $borrow_date,
                    $due_date,
                    "Dikonversi dari booking online #{$res['id']}: " . ($res['notes'] ?? '')
                ]);

                // 3. Update status reservasi jadi selesai
                $stmt_end = $pdo->prepare("UPDATE library_reservations SET status = 'selesai' WHERE id = ?");
                $stmt_end->execute([$res_id]);

                $pdo->commit();
                logActivity($pdo, 'CONVERT_RESERVATION', "Mengonversi reservasi #$res_id menjadi pinjaman aktif siswa ID #{$res['user_id']}");
                $message = "Reservasi berhasil dikonversi menjadi pinjaman resmi (Jatuh tempo: " . date('d M Y', strtotime($due_date)) . ")!";
                $message_type = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Gagal memproses peminjaman: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// 3. ACTION: BATALKAN RESERVASI (Siswa sendiri atau Petugas)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_reservation') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $res_id = (int)($_POST['reservation_id'] ?? 0);
        
        if ($is_librarian) {
            $stmt_can = $pdo->prepare("UPDATE library_reservations SET status = 'dibatalkan' WHERE id = ?");
            $stmt_can->execute([$res_id]);
            logActivity($pdo, 'CANCEL_RESERVATION', "Petugas membatalkan reservasi ID #$res_id");
            $message = "Reservasi berhasil dibatalkan.";
            $message_type = "success";
        } else {
            // Siswa hanya boleh batalkan miliknya sendiri yang masih menunggu/disiapkan
            $stmt_can = $pdo->prepare("UPDATE library_reservations SET status = 'dibatalkan' WHERE id = ? AND user_id = ? AND status IN ('menunggu', 'disiapkan')");
            $stmt_can->execute([$res_id, $user_id]);
            if ($stmt_can->rowCount() > 0) {
                logActivity($pdo, 'CANCEL_MY_RESERVATION', "Siswa membatalkan booking buku ID #$res_id");
                $message = "Booking buku Anda berhasil dibatalkan.";
                $message_type = "success";
            } else {
                $message = "Reservasi tidak dapat dibatalkan atau bukan milik Anda.";
                $message_type = "error";
            }
        }
    }
}

// 4. STATISTIK RESERVASI
$where_stat = $is_librarian ? "" : "WHERE user_id = $user_id";
$total_active = (int)$pdo->query("SELECT COUNT(*) FROM library_reservations " . ($is_librarian ? "WHERE status IN ('menunggu', 'disiapkan')" : "WHERE user_id = $user_id AND status IN ('menunggu', 'disiapkan')"))->fetchColumn();
$total_ready  = (int)$pdo->query("SELECT COUNT(*) FROM library_reservations " . ($is_librarian ? "WHERE status = 'disiapkan'" : "WHERE user_id = $user_id AND status = 'disiapkan')"))->fetchColumn();
$total_done   = (int)$pdo->query("SELECT COUNT(*) FROM library_reservations " . ($is_librarian ? "WHERE status = 'selesai'" : "WHERE user_id = $user_id AND status = 'selesai')"))->fetchColumn();
$total_all    = (int)$pdo->query("SELECT COUNT(*) FROM library_reservations $where_stat")->fetchColumn();

// 5. QUERY DAFTAR RESERVASI
$filter_status = trim($_GET['status'] ?? '');
$search_q = trim($_GET['q'] ?? '');

$query_where = [];
$query_params = [];

if (!$is_librarian) {
    $query_where[] = "r.user_id = ?";
    $query_params[] = $user_id;
}

if (!empty($filter_status)) {
    $query_where[] = "r.status = ?";
    $query_params[] = $filter_status;
}

if (!empty($search_q)) {
    $query_where[] = "(b.title LIKE ? OR b.code LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
    $term = "%{$search_q}%";
    $query_params = array_merge($query_params, [$term, $term, $term, $term]);
}

$where_clause = !empty($query_where) ? "WHERE " . implode(" AND ", $query_where) : "";

$sql_reservations = "
    SELECT r.*, 
           b.title as book_title, b.code as book_code, b.shelf_location, b.stock_available, b.stock_total,
           u.name as student_name, u.role as student_role, u.email as student_email
    FROM library_reservations r
    JOIN library_books b ON r.book_id = b.id
    JOIN users u ON r.user_id = u.id
    $where_clause
    ORDER BY r.id DESC
";
$stmt_list = $pdo->prepare($sql_reservations);
$stmt_list->execute($query_params);
$reservations = $stmt_list->fetchAll();

include __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Sub-Navigasi Modul Perpustakaan -->
    <?php include __DIR__ . '/_nav.php'; ?>

    <!-- Header Page -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                <?= $is_librarian ? 'Manajemen Booking & Reservasi Buku' : 'Reservasi Buku Saya' ?>
            </h1>
            <p class="text-xs sm:text-sm text-slate-400 mt-1">
                <?= $is_librarian ? 'Kelola antrean pemesanan buku online siswa dan persiapkan koleksi di meja sirkulasi.' : 'Pantau status pemesanan buku yang Anda booking sebelum mengambilnya di perpustakaan.' ?>
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="../../perpustakaan.php" target="_blank"
               class="rounded-xl bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-xs sm:text-sm font-bold text-white shadow-lg shadow-indigo-600/20 transition flex items-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i>
                <span>Cari & Booking Buku Baru</span>
            </a>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($message)): ?>
        <div class="rounded-2xl border p-4 text-xs sm:text-sm font-medium flex items-center gap-3 <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-lg text-emerald-400' : 'fa-circle-exclamation text-lg text-rose-400' ?>"></i>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <!-- Stats Bar -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur shadow-lg">
            <span class="text-xs text-slate-400 block">Antrean Booking Aktif</span>
            <p class="text-2xl font-black text-amber-400 font-mono mt-1"><?= $total_active ?></p>
            <span class="text-[10px] text-slate-500">Menunggu / Disiapkan</span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur shadow-lg">
            <span class="text-xs text-slate-400 block">Siap Di Meja Sirkulasi</span>
            <p class="text-2xl font-black text-teal-400 font-mono mt-1"><?= $total_ready ?></p>
            <span class="text-[10px] text-slate-500">Telah ditahan untuk siswa</span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur shadow-lg">
            <span class="text-xs text-slate-400 block">Selesai Jadi Pinjaman</span>
            <p class="text-2xl font-black text-emerald-400 font-mono mt-1"><?= $total_done ?></p>
            <span class="text-[10px] text-slate-500">Telah diambil oleh peminjam</span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur shadow-lg">
            <span class="text-xs text-slate-400 block">Total Rekapitulasi</span>
            <p class="text-2xl font-black text-slate-200 font-mono mt-1"><?= $total_all ?></p>
            <span class="text-[10px] text-slate-500">Semua riwayat pemesanan</span>
        </div>
    </div>

    <!-- Filter & Toolbar -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/50 p-4 backdrop-blur flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
        
        <!-- Filter Tabs -->
        <div class="flex items-center gap-1.5 overflow-x-auto text-xs">
            <a href="reservations.php" 
               class="rounded-xl px-3 py-1.5 font-semibold transition <?= empty($filter_status) ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-white bg-slate-950/50' ?>">
                Semua
            </a>
            <a href="reservations.php?status=menunggu" 
               class="rounded-xl px-3 py-1.5 font-semibold transition <?= $filter_status === 'menunggu' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40' : 'text-slate-400 hover:text-white bg-slate-950/50' ?>">
                ● Menunggu
            </a>
            <a href="reservations.php?status=disiapkan" 
               class="rounded-xl px-3 py-1.5 font-semibold transition <?= $filter_status === 'disiapkan' ? 'bg-teal-500/20 text-teal-300 border border-teal-500/40' : 'text-slate-400 hover:text-white bg-slate-950/50' ?>">
                ● Siap Diambil
            </a>
            <a href="reservations.php?status=selesai" 
               class="rounded-xl px-3 py-1.5 font-semibold transition <?= $filter_status === 'selesai' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40' : 'text-slate-400 hover:text-white bg-slate-950/50' ?>">
                ● Selesai
            </a>
            <a href="reservations.php?status=dibatalkan" 
               class="rounded-xl px-3 py-1.5 font-semibold transition <?= $filter_status === 'dibatalkan' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'text-slate-400 hover:text-white bg-slate-950/50' ?>">
                ● Dibatalkan
            </a>
        </div>

        <!-- Search Form -->
        <form action="reservations.php" method="GET" class="flex items-center gap-2">
            <?php if (!empty($filter_status)): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
            <?php endif; ?>
            <div class="relative w-full sm:w-64">
                <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>"
                       placeholder="Cari buku atau nama siswa..."
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-1.5 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none">
            </div>
            <button type="submit" class="rounded-xl bg-white/10 hover:bg-white/15 px-3 py-1.5 text-xs text-slate-200 transition">
                <i class="fa-solid fa-magnifying-glass"></i>
            </button>
        </form>

    </div>

    <!-- Table List Reservasi -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/60 overflow-hidden backdrop-blur shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-950/80 text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-white/10">
                    <tr>
                        <th class="py-3.5 px-4">No</th>
                        <th class="py-3.5 px-4">Judul & Kode Buku</th>
                        <th class="py-3.5 px-4">Pemesan</th>
                        <th class="py-3.5 px-4">Tanggal Booking</th>
                        <th class="py-3.5 px-4">Batas Ambil</th>
                        <th class="py-3.5 px-4">Status</th>
                        <th class="py-3.5 px-4 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 font-medium">
                    <?php if (empty($reservations)): ?>
                        <tr>
                            <td colspan="7" class="py-12 text-center text-slate-500">
                                <i class="fa-solid fa-bookmark text-3xl mb-2 text-slate-600 block"></i>
                                Belum ada antrean reservasi atau booking buku yang cocok.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($reservations as $row): 
                            $is_expired = ($row['status'] === 'kedaluwarsa' || ($row['expiry_date'] < date('Y-m-d') && in_array($row['status'], ['menunggu', 'disiapkan'])));
                        ?>
                            <tr class="hover:bg-white/5 transition">
                                <td class="py-3.5 px-4 font-mono text-slate-500"><?= $no++ ?></td>
                                
                                <td class="py-3.5 px-4 max-w-xs">
                                    <strong class="text-white block truncate text-xs sm:text-sm font-bold"><?= htmlspecialchars($row['book_title']) ?></strong>
                                    <div class="flex items-center gap-2 mt-0.5 text-[11px] text-slate-400">
                                        <span class="font-mono text-indigo-400 font-bold"><?= htmlspecialchars($row['book_code']) ?></span>
                                        <span>•</span>
                                        <span><i class="fa-solid fa-location-dot text-slate-500"></i> <?= htmlspecialchars($row['shelf_location']) ?></span>
                                    </div>
                                </td>

                                <td class="py-3.5 px-4">
                                    <strong class="text-slate-200 block"><?= htmlspecialchars($row['student_name']) ?></strong>
                                    <span class="text-[10px] text-slate-400 uppercase"><?= htmlspecialchars($row['student_role']) ?></span>
                                </td>

                                <td class="py-3.5 px-4 font-mono text-slate-400">
                                    <?= date('d M Y - H:i', strtotime($row['reservation_date'])) ?>
                                </td>

                                <td class="py-3.5 px-4 font-mono">
                                    <span class="<?= $is_expired ? 'text-rose-400 font-bold' : 'text-amber-300' ?>">
                                        <?= date('d M Y', strtotime($row['expiry_date'])) ?>
                                    </span>
                                </td>

                                <td class="py-3.5 px-4">
                                    <?php if ($row['status'] === 'menunggu'): ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-2.5 py-0.5 text-[11px] font-bold text-amber-300">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                                            Menunggu Disiapkan
                                        </span>
                                    <?php elseif ($row['status'] === 'disiapkan'): ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-teal-500/30 bg-teal-500/10 px-2.5 py-0.5 text-[11px] font-bold text-teal-300">
                                            <span class="h-1.5 w-1.5 rounded-full bg-teal-400"></span>
                                            Siap Di Meja Sirkulasi
                                        </span>
                                    <?php elseif ($row['status'] === 'selesai'): ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-[11px] font-bold text-emerald-300">
                                            <i class="fa-solid fa-check text-[10px]"></i> Selesai Dipinjam
                                        </span>
                                    <?php elseif ($row['status'] === 'dibatalkan'): ?>
                                        <span class="inline-flex items-center rounded-full border border-slate-500/30 bg-slate-500/10 px-2.5 py-0.5 text-[11px] font-semibold text-slate-400">
                                            Dibatalkan
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center rounded-full border border-rose-500/30 bg-rose-500/10 px-2.5 py-0.5 text-[11px] font-semibold text-rose-400">
                                            Kedaluwarsa
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3.5 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <?php if ($is_librarian): ?>
                                            <?php if ($row['status'] === 'menunggu'): ?>
                                                <!-- Tandai Siap Di Meja -->
                                                <form method="POST" action="reservations.php" onsubmit="return confirm('Tandai buku ini sudah disiapkan di meja sirkulasi?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="ready_reservation">
                                                    <input type="hidden" name="reservation_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" class="rounded-lg bg-teal-600 hover:bg-teal-500 px-2.5 py-1 text-[11px] font-bold text-white transition flex items-center gap-1 cursor-pointer" title="Tandai buku siap diambil">
                                                        <i class="fa-solid fa-box-archive"></i> Siapkan
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (in_array($row['status'], ['menunggu', 'disiapkan'], true)): ?>
                                                <!-- Proses Jadi Pinjaman Resmi -->
                                                <form method="POST" action="reservations.php" onsubmit="return confirm('Proses booking ini menjadi pinjaman aktif resmi siswa selama 7 hari?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="convert_to_loan">
                                                    <input type="hidden" name="reservation_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" class="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-2.5 py-1 text-[11px] font-bold text-white transition flex items-center gap-1 cursor-pointer" title="Siswa datang mengambil: Proses jadi Pinjaman Aktif">
                                                        <i class="fa-solid fa-arrows-rotate"></i> Pinjamkan
                                                    </button>
                                                </form>

                                                <!-- Batalkan -->
                                                <form method="POST" action="reservations.php" onsubmit="return confirm('Batalkan pemesanan ini?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="cancel_reservation">
                                                    <input type="hidden" name="reservation_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" class="rounded-lg border border-rose-500/20 bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 px-2.5 py-1 text-[11px] font-semibold transition cursor-pointer" title="Batalkan">
                                                        <i class="fa-solid fa-xmark"></i>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-slate-600 text-xs">-</span>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <!-- Tombol Siswa: Batalkan jika masih pending/disiapkan -->
                                            <?php if (in_array($row['status'], ['menunggu', 'disiapkan'], true)): ?>
                                                <form method="POST" action="reservations.php" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan booking buku ini?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="cancel_reservation">
                                                    <input type="hidden" name="reservation_id" value="<?= $row['id'] ?>">
                                                    <button type="submit" class="rounded-lg border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 px-3 py-1 text-xs font-semibold transition cursor-pointer">
                                                        Batalkan Booking
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-slate-600 text-xs">Arsip</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
