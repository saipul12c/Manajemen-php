<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';

// Hak akses: hanya Staf TU dan Administrator
if (!in_array($user_role, ['staf', 'administrator'], true)) {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

$message = "";
$message_type = "";

// Buat direktori upload nota pengeluaran jika belum ada
$upload_expense_dir = __DIR__ . "/../../uploads/expenses";
if (!is_dir($upload_expense_dir)) {
    mkdir($upload_expense_dir, 0755, true);
}

// -------------------------------------------------------------
// 1. TAMBAH CATATAN PENGELUARAN KAS OPERASIONAL
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $category     = trim($_POST['category'] ?? 'ATK & Operasional Kantor');
        $title        = trim($_POST['title'] ?? '');
        $expense_date = !empty($_POST['expense_date']) ? $_POST['expense_date'] : date('Y-m-d');
        $amount       = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? 0);
        $recipient    = trim($_POST['recipient'] ?? '');
        $notes        = trim($_POST['notes'] ?? '');
        $receipt_doc  = null;

        // Generate No Bukti Pengeluaran
        $custom_no = trim($_POST['expense_no'] ?? '');
        if ($custom_no === '') {
            $next_id = (int)$pdo->query("SELECT MAX(id) FROM financial_expenses")->fetchColumn() + 1;
            $expense_no = "BKK-" . date('Y/m', strtotime($expense_date)) . "/" . sprintf('%04d', $next_id);
        } else {
            $expense_no = $custom_no;
        }

        // Upload Berkas / Nota Kwitansi
        if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp  = $_FILES['receipt_file']['tmp_name'];
            $file_name = $_FILES['receipt_file']['name'];
            $file_size = $_FILES['receipt_file']['size'];
            $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext   = ['jpg', 'jpeg', 'png', 'pdf'];
            $allowed_mimes = ['image/jpeg', 'image/png', 'application/pdf'];

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected_mime = finfo_file($finfo, $file_tmp);
            finfo_close($finfo);

            if (in_array($file_ext, $allowed_ext, true) && in_array($detected_mime, $allowed_mimes, true) && $file_size <= 8 * 1024 * 1024) {
                $new_file = "nota_" . time() . "_" . uniqid() . "." . $file_ext;
                if (move_uploaded_file($file_tmp, $upload_expense_dir . "/" . $new_file)) {
                    $receipt_doc = "../../uploads/expenses/" . $new_file;
                }
            }
        }

        if ($title === '' || $amount <= 0 || $recipient === '') {
            $message = "Uraian pengeluaran, nominal biaya, dan nama penerima/toko wajib diisi.";
            $message_type = "error";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO financial_expenses 
                (expense_no, category, title, expense_date, amount, recipient, receipt_doc, notes, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$expense_no, $category, $title, $expense_date, $amount, $recipient, $receipt_doc, $notes, $user_id]);

            logActivity($pdo, 'ADD_EXPENSE', "Mencatat pengeluaran kas $expense_no: $title (Rp $amount)");
            $message = "Pengeluaran kas operasional ($expense_no) berhasil dicatat ke dalam Buku Kas Umum!";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 2. HAPUS PENGELUARAN KAS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_expense') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $del_id = (int)($_POST['delete_id'] ?? 0);
        if ($del_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM financial_expenses WHERE id = ?");
            $stmt->execute([$del_id]);

            logActivity($pdo, 'DELETE_EXPENSE', "Menghapus pengeluaran kas #$del_id");
            $message = "Catatan pengeluaran kas berhasil dihapus.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// FILTER & QUERY PENGELUARAN
// -------------------------------------------------------------
$search     = trim($_GET['search'] ?? '');
$filter_cat = trim($_GET['category'] ?? '');
$filter_m   = trim($_GET['month'] ?? date('m'));
$filter_y   = trim($_GET['year'] ?? date('Y'));

$categories = [
    'ATK & Operasional Kantor',
    'Listrik & Internet',
    'Konsumsi & Rapat',
    'Pemeliharaan & Kebersihan',
    'Kegiatan Kesiswaan & Lomba',
    'Honor & Transport Tugas',
    'Pengadaan Buku & Bahan Ajar',
    'Lainnya'
];

$sql = "
    SELECT e.*, u.name as staff_name 
    FROM financial_expenses e
    LEFT JOIN users u ON e.recorded_by = u.id
    WHERE 1=1
";
$params = [];

if ($filter_m !== '') {
    $sql .= " AND MONTH(e.expense_date) = ?";
    $params[] = $filter_m;
}
if ($filter_y !== '') {
    $sql .= " AND YEAR(e.expense_date) = ?";
    $params[] = $filter_y;
}
if ($filter_cat !== '') {
    $sql .= " AND e.category = ?";
    $params[] = $filter_cat;
}
if ($search !== '') {
    $sql .= " AND (e.expense_no LIKE ? OR e.title LIKE ? OR e.recipient LIKE ? OR e.notes LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY e.expense_date DESC, e.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses_list = $stmt->fetchAll();

// -------------------------------------------------------------
// KALKULASI ARUS KAS UMUM (PENERIMAAN VS PENGELUARAN)
// -------------------------------------------------------------
// 1. Total Pemasukan SPP / Tagihan Terverifikasi
$total_income_all = (float)$pdo->query("SELECT COALESCE(SUM(amount_paid), 0) FROM bill_payments WHERE status = 'diterima'")->fetchColumn();
// 2. Total Pengeluaran Kas
$total_expense_all = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM financial_expenses")->fetchColumn();
// 3. Saldo Kas Riil
$balance_cash_all = $total_income_all - $total_expense_all;

// Filtered Period Stats
$period_expense = array_sum(array_column($expenses_list, 'amount'));

$school_info = getSchoolSettings($pdo);
$page_title = "Buku Kas Umum & Pengeluaran Operasional";
require_once __DIR__ . "/../includes/header.php";
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
            <span class="text-rose-400"><i class="fa-solid fa-receipt"></i></span> Buku Kas Umum (BKU)
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            Pencatatan pengeluaran kas operasional, belanja ATK/sarana, dan monitoring saldo kas sekolah terintegrasi.
        </p>
    </div>

    <div class="flex items-center gap-2 flex-wrap">
        <button onclick="document.getElementById('modalAddExpense').classList.remove('hidden')" 
                class="inline-flex items-center gap-2 rounded-xl bg-rose-600 hover:bg-rose-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-rose-500/20 transition cursor-pointer">
            <i class="fa-solid fa-plus"></i> Catat Pengeluaran Baru
        </button>
        <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-slate-300 transition cursor-pointer">
            <i class="fa-solid fa-print"></i> Cetak BKU
        </button>
    </div>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== ''): ?>
    <div class="mb-6 flex items-center gap-3 rounded-2xl border p-4 <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-rose-400' ?> text-lg"></i>
        <div class="flex-1 text-sm font-medium"><?= htmlspecialchars($message) ?></div>
        <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<!-- Kartu Ringkasan Saldo Buku Kas Umum (BKU) -->
<div class="mb-8 grid grid-cols-1 md:grid-cols-4 gap-4">
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wider text-emerald-400">Total Kas Masuk (SPP)</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400"><i class="fa-solid fa-arrow-down-left text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2">Rp <?= number_format($total_income_all, 0, ',', '.') ?></p>
        <span class="text-[11px] text-slate-500">Iuran siswa terverifikasi sah</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wider text-rose-400">Total Pengeluaran Kas</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-rose-500/20 text-rose-400"><i class="fa-solid fa-arrow-up-right text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2">Rp <?= number_format($total_expense_all, 0, ',', '.') ?></p>
        <span class="text-[11px] text-slate-500">Akumulasi biaya operasional</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wider text-blue-400">Sisa Saldo Kas Riil</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400"><i class="fa-solid fa-wallet text-xs"></i></span>
        </div>
        <p class="text-2xl font-black <?= $balance_cash_all >= 0 ? 'text-emerald-400' : 'text-rose-400' ?> mt-2">
            Rp <?= number_format($balance_cash_all, 0, ',', '.') ?>
        </p>
        <span class="text-[11px] text-slate-500">Saldo kas siap digunakan</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold uppercase tracking-wider text-amber-400">Beban Periode Terpilih</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400"><i class="fa-solid fa-calendar-check text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2">Rp <?= number_format($period_expense, 0, ',', '.') ?></p>
        <span class="text-[11px] text-slate-500"><?= count($expenses_list) ?> bukti kas keluar</span>
    </div>
</div>

<!-- Filter dan Pencarian -->
<div class="mb-6 rounded-2xl border border-white/10 bg-slate-900/60 p-4">
    <form method="GET" class="flex flex-col md:flex-row gap-3">
        <div class="flex-1">
            <input 
                type="text" 
                name="search" 
                value="<?= htmlspecialchars($search) ?>" 
                placeholder="Cari nomor bukti BKK, uraian keperluan, atau penerima/toko..." 
                class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white placeholder-slate-500 outline-none focus:border-rose-500"
            >
        </div>

        <div class="md:w-36">
            <select name="month" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-rose-500">
                <option value="">Semua Bulan</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= sprintf('%02d', $m) ?>" <?= $filter_m === sprintf('%02d', $m) ? 'selected' : '' ?>>
                        <?= date('F', mktime(0, 0, 0, $m, 10)) ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="md:w-28">
            <select name="year" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-rose-500">
                <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                    <option value="<?= $y ?>" <?= $filter_y == $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="md:w-56">
            <select name="category" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-rose-500 truncate">
                <option value="">Semua Kategori Biaya</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat ?>" <?= $filter_cat === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="rounded-xl bg-slate-800 hover:bg-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 transition flex items-center gap-2 cursor-pointer">
                <i class="fa-solid fa-magnifying-glass text-xs"></i> Filter
            </button>
            <?php if ($search !== '' || $filter_cat !== '' || $filter_m !== date('m') || $filter_y !== date('Y')): ?>
                <a href="expenses.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-sm font-semibold text-slate-400 transition">
                    Reset
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Tabel Buku Kas Pengeluaran -->
<div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-2xl backdrop-blur">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
            <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-6 py-4 font-semibold">No. Bukti & Tanggal</th>
                    <th class="px-6 py-4 font-semibold">Uraian / Keperluan</th>
                    <th class="px-6 py-4 font-semibold">Kategori Belanja</th>
                    <th class="px-6 py-4 font-semibold">Penerima / Vendor</th>
                    <th class="px-6 py-4 font-semibold text-right">Jumlah Keluar</th>
                    <th class="px-6 py-4 font-semibold text-right">Dokumen / Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php if (empty($expenses_list)): ?>
                    <tr>
                        <td colspan="6" class="py-12 text-center text-slate-400 text-sm">
                            Belum ada catatan pengeluaran kas pada periode yang dipilih.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($expenses_list as $exp): ?>
                        <tr class="hover:bg-white/5 transition">
                            <td class="px-6 py-4">
                                <span class="font-mono text-xs font-bold text-rose-400 block"><?= htmlspecialchars($exp['expense_no']) ?></span>
                                <span class="text-xs text-slate-400 mt-0.5 block"><?= date('d F Y', strtotime($exp['expense_date'])) ?></span>
                            </td>

                            <td class="px-6 py-4">
                                <p class="font-bold text-white"><?= htmlspecialchars($exp['title']) ?></p>
                                <?php if (!empty($exp['notes'])): ?>
                                    <p class="text-xs text-slate-400 mt-0.5 italic"><?= htmlspecialchars($exp['notes']) ?></p>
                                <?php endif; ?>
                                <span class="text-[11px] text-slate-500 block mt-1">Dicatat oleh: <?= htmlspecialchars($exp['staff_name'] ?? 'Staf TU') ?></span>
                            </td>

                            <td class="px-6 py-4">
                                <span class="inline-block px-2.5 py-1 rounded-lg bg-slate-800 text-slate-300 text-xs font-semibold">
                                    <?= htmlspecialchars($exp['category']) ?>
                                </span>
                            </td>

                            <td class="px-6 py-4 text-slate-200 font-medium">
                                <i class="fa-solid fa-store text-slate-500 text-xs mr-1"></i><?= htmlspecialchars($exp['recipient']) ?>
                            </td>

                            <td class="px-6 py-4 text-right font-mono font-bold text-rose-400 text-base">
                                - Rp <?= number_format($exp['amount'], 0, ',', '.') ?>
                            </td>

                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <?php if (!empty($exp['receipt_doc'])): ?>
                                        <a href="<?= htmlspecialchars($exp['receipt_doc']) ?>" target="_blank" 
                                           class="rounded-xl border border-blue-500/30 bg-blue-500/10 hover:bg-blue-500/20 px-3 py-1.5 text-xs font-semibold text-blue-400 transition" title="Lihat Kwitansi / Nota">
                                            <i class="fa-solid fa-file-invoice"></i> Nota
                                        </a>
                                    <?php endif; ?>

                                    <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan & menghapus transaksi kas <?= htmlspecialchars($exp['expense_no']) ?>?');" class="inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_expense">
                                        <input type="hidden" name="delete_id" value="<?= $exp['id'] ?>">
                                        <button type="submit" class="rounded-xl border border-rose-500/20 bg-rose-500/10 hover:bg-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-400 transition cursor-pointer" title="Hapus Bukti Kas">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL: CATAT PENGELUARAN KAS OPERASIONAL BARU -->
<!-- ========================================================= -->
<div id="modalAddExpense" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-rose-400"><i class="fa-solid fa-receipt"></i></span> Catat Bukti Kas Keluar (BKK)
            </h3>
            <button onclick="document.getElementById('modalAddExpense').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_expense">

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Uraian / Keperluan Pengeluaran *</label>
                <input type="text" name="title" required placeholder="Contoh: Belanja Kertas HVS F4/A4, Tinta Printer & Map Arsip TU" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-rose-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Kategori Belanja *</label>
                    <select name="category" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-rose-500">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>"><?= $cat ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Nominal Biaya (Rp) *</label>
                    <input type="number" name="amount" required min="1000" step="500" placeholder="Contoh: 450000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white font-mono outline-none focus:border-rose-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Tanggal Pengeluaran *</label>
                    <input type="date" name="expense_date" required value="<?= date('Y-m-d') ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-rose-500">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Penerima Uang / Toko / Vendor *</label>
                    <input type="text" name="recipient" required placeholder="Contoh: Toko Buku Grama Mandiri / PT PLN" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-rose-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Unggah Nota / Kwitansi / Bukti Fisik</label>
                <input type="file" name="receipt_file" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-rose-600 file:text-white hover:file:bg-rose-500">
                <p class="text-[11px] text-slate-500 mt-1">Format: JPG, PNG, atau PDF. Maksimal 8 MB.</p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Catatan Tambahan (Opsional)</label>
                <textarea name="notes" rows="2" placeholder="Keterangan alokasi anggaran atau rincian item barang..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-rose-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalAddExpense').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-rose-600 hover:bg-rose-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-rose-500/25 cursor-pointer">
                    Simpan Kas Keluar
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
