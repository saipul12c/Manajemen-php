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

// -------------------------------------------------------------
// 1. TAMBAH BARANG INVENTARIS BARU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan coba lagi.";
        $message_type = "error";
    } else {
        $item_name       = trim($_POST['item_name'] ?? '');
        $category        = trim($_POST['category'] ?? 'Elektronik');
        $room_location   = trim($_POST['room_location'] ?? 'Ruang Tata Usaha');
        $condition_status= $_POST['condition_status'] ?? 'baik';
        $quantity        = max(1, (int)($_POST['quantity'] ?? 1));
        $unit            = trim($_POST['unit'] ?? 'Unit');
        $funding_source  = trim($_POST['funding_source'] ?? 'BOS');
        $purchase_year   = (int)($_POST['purchase_year'] ?? date('Y'));
        $purchase_cost   = (float)str_replace(['.', ','], ['', '.'], $_POST['purchase_cost'] ?? 0);
        $notes           = trim($_POST['notes'] ?? '');

        // Generate Item Code unik jika tidak diisi
        $custom_code = trim($_POST['item_code'] ?? '');
        if ($custom_code === '') {
            $prefix_map = [
                'Elektronik' => 'ELK',
                'Mebel & Perabot' => 'MEB',
                'Alat Peraga & Media' => 'APM',
                'Perlengkapan Kantor' => 'ATK',
                'Kendaraan & Mesin' => 'KND'
            ];
            $pfx = $prefix_map[$category] ?? 'INV';
            $next_id = (int)$pdo->query("SELECT MAX(id) FROM inventory_items")->fetchColumn() + 1;
            $item_code = "INV-" . $pfx . "-" . date('Y') . "-" . sprintf('%04d', $next_id);
        } else {
            $item_code = $custom_code;
        }

        if ($item_name === '') {
            $message = "Nama barang inventaris wajib diisi.";
            $message_type = "error";
        } else {
            // Cek duplikasi kode
            $chk = $pdo->prepare("SELECT id FROM inventory_items WHERE item_code = ?");
            $chk->execute([$item_code]);
            if ($chk->fetch()) {
                $message = "Kode barang '$item_code' sudah terdaftar dalam sistem inventaris.";
                $message_type = "error";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO inventory_items 
                    (item_code, item_name, category, room_location, condition_status, quantity, unit, funding_source, purchase_year, purchase_cost, notes, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$item_code, $item_name, $category, $room_location, $condition_status, $quantity, $unit, $funding_source, $purchase_year, $purchase_cost, $notes, $user_id]);
                
                logActivity($pdo, 'ADD_INVENTORY', "Menambahkan aset baru: $item_name ($item_code) di $room_location");
                $message = "Barang inventaris baru ($item_code) berhasil ditambahkan ke buku aset sekolah.";
                $message_type = "success";
            }
        }
    }
}

// -------------------------------------------------------------
// 2. PERBARUI / EDIT BARANG INVENTARIS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_item') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $edit_id         = (int)($_POST['edit_id'] ?? 0);
        $item_name       = trim($_POST['item_name'] ?? '');
        $category        = trim($_POST['category'] ?? 'Elektronik');
        $room_location   = trim($_POST['room_location'] ?? 'Ruang Tata Usaha');
        $condition_status= $_POST['condition_status'] ?? 'baik';
        $quantity        = max(1, (int)($_POST['quantity'] ?? 1));
        $unit            = trim($_POST['unit'] ?? 'Unit');
        $funding_source  = trim($_POST['funding_source'] ?? 'BOS');
        $purchase_year   = (int)($_POST['purchase_year'] ?? date('Y'));
        $purchase_cost   = (float)str_replace(['.', ','], ['', '.'], $_POST['purchase_cost'] ?? 0);
        $notes           = trim($_POST['notes'] ?? '');

        if ($edit_id > 0 && $item_name !== '') {
            $stmt = $pdo->prepare("
                UPDATE inventory_items 
                SET item_name = ?, category = ?, room_location = ?, condition_status = ?, quantity = ?, unit = ?, 
                    funding_source = ?, purchase_year = ?, purchase_cost = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$item_name, $category, $room_location, $condition_status, $quantity, $unit, $funding_source, $purchase_year, $purchase_cost, $notes, $edit_id]);
            
            logActivity($pdo, 'UPDATE_INVENTORY', "Memperbarui data aset #$edit_id ($item_name)");
            $message = "Data inventaris barang berhasil diperbarui.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 3. CATAT PEMINJAMAN SARANA & PRASARANA
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'loan_item') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $item_id              = (int)($_POST['item_id'] ?? 0);
        $borrower_name        = trim($_POST['borrower_name'] ?? '');
        $borrower_role        = trim($_POST['borrower_role'] ?? 'guru');
        $borrower_phone       = trim($_POST['borrower_phone'] ?? '');
        $borrow_date          = !empty($_POST['borrow_date']) ? $_POST['borrow_date'] : date('Y-m-d');
        $expected_return_date = !empty($_POST['expected_return_date']) ? $_POST['expected_return_date'] : date('Y-m-d', strtotime('+3 days'));
        $purpose              = trim($_POST['purpose'] ?? '');

        if ($item_id <= 0 || $borrower_name === '' || $purpose === '') {
            $message = "Lengkapi barang, nama peminjam, dan tujuan peminjaman sarana.";
            $message_type = "error";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO inventory_loans 
                (item_id, borrower_name, borrower_role, borrower_phone, borrow_date, expected_return_date, status, purpose, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, 'dipinjam', ?, ?)
            ");
            $stmt->execute([$item_id, $borrower_name, $borrower_role, $borrower_phone, $borrow_date, $expected_return_date, $purpose, $user_id]);

            logActivity($pdo, 'LOAN_INVENTORY', "Mencatat peminjaman aset item #$item_id oleh $borrower_name");
            $message = "Peminjaman sarana berhasil dicatat!";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 4. KEMBALIKAN BARANG PINJAMAN
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_loan') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $loan_id      = (int)($_POST['loan_id'] ?? 0);
        $return_notes = trim($_POST['return_notes'] ?? 'Dikembalikan dalam kondisi baik dan lengkap.');

        if ($loan_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE inventory_loans 
                SET status = 'kembali', actual_return_date = CURRENT_DATE(), notes = ? 
                WHERE id = ?
            ");
            $stmt->execute([$return_notes, $loan_id]);

            logActivity($pdo, 'RETURN_INVENTORY', "Pengembalian peminjaman aset loan #$loan_id");
            $message = "Pengembalian sarana/alat berhasil dikonfirmasi.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 5. HAPUS BARANG DARI BUKU INVENTARIS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $delete_id = (int)($_POST['delete_id'] ?? 0);
        if ($delete_id > 0) {
            $stmt = $pdo->prepare("DELETE FROM inventory_items WHERE id = ?");
            $stmt->execute([$delete_id]);
            logActivity($pdo, 'DELETE_INVENTORY', "Menghapus aset inventaris #$delete_id");
            $message = "Barang telah dihapus dari buku inventaris.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// FILTER & QUERY KATALOG INVENTARIS
// -------------------------------------------------------------
$search     = trim($_GET['search'] ?? '');
$filter_room= trim($_GET['room'] ?? '');
$filter_cond= trim($_GET['condition'] ?? '');
$filter_cat = trim($_GET['cat'] ?? '');
$active_tab = trim($_GET['tab'] ?? 'inventory'); // 'inventory' or 'loans'

// Master Ruangan Sekolah
$rooms_list = [
    'Ruang Tata Usaha',
    'Ruang Kepala Sekolah',
    'Ruang Dewan Guru',
    'Ruang Bimbingan Konseling (BK)',
    'Ruang Lab Komputer 1',
    'Ruang Lab Komputer 2',
    'Ruang Lab IPA / Biologi',
    'Ruang Lab Kimia & Fisika',
    'Perpustakaan Utama',
    'Ruang UKS',
    'Aula Graha Utama',
    'Ruang OSIS & Ekstrakurikuler',
    'Ruang Kelas X-A',
    'Ruang Kelas X-B',
    'Ruang Kelas XI-IPA',
    'Ruang Kelas XI-IPS',
    'Ruang Kelas XII-IPA',
    'Gudang Sarpras & Olahraga'
];

$categories_list = [
    'Elektronik',
    'Mebel & Perabot',
    'Alat Peraga & Media',
    'Perlengkapan Kantor',
    'Kendaraan & Mesin',
    'Peralatan Olahraga',
    'Buku & Referensi'
];

// Query Barang
$sql_inv = "SELECT i.*, u.name as recorder_name FROM inventory_items i LEFT JOIN users u ON i.recorded_by = u.id WHERE 1=1";
$params_inv = [];

if ($search !== '') {
    $sql_inv .= " AND (i.item_code LIKE ? OR i.item_name LIKE ? OR i.room_location LIKE ? OR i.notes LIKE ?)";
    $params_inv[] = "%$search%";
    $params_inv[] = "%$search%";
    $params_inv[] = "%$search%";
    $params_inv[] = "%$search%";
}
if ($filter_room !== '') {
    $sql_inv .= " AND i.room_location = ?";
    $params_inv[] = $filter_room;
}
if ($filter_cond !== '') {
    $sql_inv .= " AND i.condition_status = ?";
    $params_inv[] = $filter_cond;
}
if ($filter_cat !== '') {
    $sql_inv .= " AND i.category = ?";
    $params_inv[] = $filter_cat;
}
$sql_inv .= " ORDER BY i.id DESC";

$stmt_inv = $pdo->prepare($sql_inv);
$stmt_inv->execute($params_inv);
$inventory_items = $stmt_inv->fetchAll();

// Query Riwayat Peminjaman
$sql_loans = "
    SELECT l.*, i.item_code, i.item_name, i.room_location, u.name as staff_name
    FROM inventory_loans l
    JOIN inventory_items i ON l.item_id = i.id
    LEFT JOIN users u ON l.recorded_by = u.id
    ORDER BY l.id DESC
";
$loans_list = $pdo->query($sql_loans)->fetchAll();

// Ringkasan Statistik Aset
$stat_total_items = (int)$pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM inventory_items")->fetchColumn();
$stat_good_items  = (int)$pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM inventory_items WHERE condition_status = 'baik'")->fetchColumn();
$stat_damaged_lt  = (int)$pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM inventory_items WHERE condition_status = 'rusak_ringan'")->fetchColumn();
$stat_damaged_hv  = (int)$pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM inventory_items WHERE condition_status = 'rusak_berat'")->fetchColumn();
$stat_total_asset = (float)$pdo->query("SELECT COALESCE(SUM(purchase_cost * quantity), 0) FROM inventory_items")->fetchColumn();
$stat_active_loans= (int)$pdo->query("SELECT COUNT(*) FROM inventory_loans WHERE status = 'dipinjam'")->fetchColumn();

$school_info = getSchoolSettings($pdo);
$page_title = "Inventaris Sarana & Prasarana (Sarpras)";
require_once __DIR__ . "/../includes/header.php";
?>

<!-- Header Halaman -->
<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
            <span class="text-amber-400"><i class="fa-solid fa-boxes-stacked"></i></span> Inventaris Sarana & Prasarana
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            Pencatatan aset sekolah, pemetaan per ruangan, monitoring kondisi barang, dan sirkulasi peminjaman alat penunjang KBM.
        </p>
    </div>

    <div class="flex items-center gap-2 flex-wrap">
        <button onclick="document.getElementById('modalAddItem').classList.remove('hidden')" 
                class="inline-flex items-center gap-2 rounded-xl bg-amber-600 hover:bg-amber-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-amber-500/20 transition cursor-pointer">
            <i class="fa-solid fa-plus"></i> Tambah Aset Barang
        </button>
        <button onclick="document.getElementById('modalLoanItem').classList.remove('hidden')" 
                class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition cursor-pointer">
            <i class="fa-solid fa-hand-holding-hand"></i> Catat Peminjaman
        </button>
        <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-slate-300 transition cursor-pointer">
            <i class="fa-solid fa-print"></i> Cetak Laporan
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

<!-- Kartu Metrik Ringkasan Aset -->
<div class="mb-8 grid grid-cols-2 lg:grid-cols-5 gap-4">
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-slate-400">Total Unit Aset</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400"><i class="fa-solid fa-cubes text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stat_total_items) ?></p>
        <span class="text-[11px] text-slate-500">Unit terdata resmi</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-emerald-400">Kondisi Baik</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400"><i class="fa-solid fa-circle-check text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stat_good_items) ?></p>
        <span class="text-[11px] text-slate-500">Siap & layak guna</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-amber-400">Rusak Ringan</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400"><i class="fa-solid fa-wrench text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stat_damaged_lt) ?></p>
        <span class="text-[11px] text-slate-500">Perlu perbaikan/servis</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-rose-400">Rusak Berat</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-rose-500/20 text-rose-400"><i class="fa-solid fa-ban text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stat_damaged_hv) ?></p>
        <span class="text-[11px] text-slate-500">Usulan penghapusan</span>
    </div>

    <div class="col-span-2 lg:col-span-1 rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-cyan-400">Estimasi Nilai Aset</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-cyan-500/20 text-cyan-400"><i class="fa-solid fa-coins text-xs"></i></span>
        </div>
        <p class="text-xl font-black text-white mt-2">Rp <?= number_format($stat_total_asset, 0, ',', '.') ?></p>
        <span class="text-[11px] text-slate-500"><?= $stat_active_loans ?> unit sedang dipinjam</span>
    </div>
</div>

<!-- Tab Navigasi -->
<div class="mb-6 flex border-b border-white/10">
    <a href="?tab=inventory" class="px-6 py-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 <?= $active_tab !== 'loans' ? 'border-amber-500 text-amber-400' : 'border-transparent text-slate-400 hover:text-white' ?>">
        <i class="fa-solid fa-table-list"></i> Buku Inventaris Barang (<?= count($inventory_items) ?>)
    </a>
    <a href="?tab=loans" class="px-6 py-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 <?= $active_tab === 'loans' ? 'border-blue-500 text-blue-400' : 'border-transparent text-slate-400 hover:text-white' ?>">
        <i class="fa-solid fa-hand-holding-hand"></i> Sirkulasi Peminjaman Sarpras (<?= count($loans_list) ?>)
        <?php if ($stat_active_loans > 0): ?>
            <span class="px-2 py-0.5 rounded-full bg-blue-500/20 text-blue-400 text-xs font-bold"><?= $stat_active_loans ?></span>
        <?php endif; ?>
    </a>
</div>

<?php if ($active_tab !== 'loans'): ?>

    <!-- Filter & Pencarian Inventaris -->
    <div class="mb-6 rounded-2xl border border-white/10 bg-slate-900/60 p-4">
        <form method="GET" class="flex flex-col md:flex-row gap-3">
            <input type="hidden" name="tab" value="inventory">
            
            <div class="flex-1">
                <input 
                    type="text" 
                    name="search" 
                    value="<?= htmlspecialchars($search) ?>" 
                    placeholder="Cari kode inventaris, nama barang, merk, atau ruangan..." 
                    class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white placeholder-slate-500 outline-none focus:border-amber-500"
                >
            </div>

            <div class="md:w-52">
                <select name="room" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-500">
                    <option value="">Semua Lokasi Ruangan</option>
                    <?php foreach ($rooms_list as $rm): ?>
                        <option value="<?= $rm ?>" <?= $filter_room === $rm ? 'selected' : '' ?>><?= $rm ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="md:w-44">
                <select name="condition" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-500">
                    <option value="">Semua Kondisi</option>
                    <option value="baik" <?= $filter_cond === 'baik' ? 'selected' : '' ?>>Baik (Layak)</option>
                    <option value="rusak_ringan" <?= $filter_cond === 'rusak_ringan' ? 'selected' : '' ?>>Rusak Ringan</option>
                    <option value="rusak_berat" <?= $filter_cond === 'rusak_berat' ? 'selected' : '' ?>>Rusak Berat</option>
                </select>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="rounded-xl bg-slate-800 hover:bg-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 transition flex items-center gap-2 cursor-pointer">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i> Filter
                </button>
                <?php if ($search !== '' || $filter_room !== '' || $filter_cond !== '' || $filter_cat !== ''): ?>
                    <a href="inventory.php?tab=inventory" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-sm font-semibold text-slate-400 transition">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Tabel Daftar Barang Inventaris -->
    <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-2xl backdrop-blur">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Kode & Nama Barang</th>
                        <th class="px-6 py-4 font-semibold">Kategori & Sumber</th>
                        <th class="px-6 py-4 font-semibold">Ruangan Penempatan</th>
                        <th class="px-4 py-4 font-semibold text-center">Jumlah</th>
                        <th class="px-4 py-4 font-semibold text-center">Kondisi</th>
                        <th class="px-6 py-4 font-semibold text-right">Nilai / Biaya</th>
                        <th class="px-6 py-4 font-semibold text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($inventory_items)): ?>
                        <tr>
                            <td colspan="7" class="py-12 text-center text-slate-400 text-sm">
                                Belum ada aset barang yang sesuai dengan filter atau pencarian Anda.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($inventory_items as $item): ?>
                            <tr class="hover:bg-white/5 transition">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20 shrink-0 font-bold">
                                            <i class="fa-solid fa-box text-sm"></i>
                                        </div>
                                        <div>
                                            <p class="font-bold text-white"><?= htmlspecialchars($item['item_name']) ?></p>
                                            <span class="font-mono text-xs text-amber-400/90 font-semibold"><?= htmlspecialchars($item['item_code']) ?></span>
                                            <?php if (!empty($item['notes'])): ?>
                                                <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-1 italic"><?= htmlspecialchars($item['notes']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <span class="inline-block px-2.5 py-0.5 rounded-lg bg-slate-800 text-slate-300 text-xs font-semibold mb-1">
                                        <?= htmlspecialchars($item['category']) ?>
                                    </span>
                                    <div class="text-[11px] text-slate-400">
                                        Sumber: <span class="text-slate-200"><?= htmlspecialchars($item['funding_source'] ?: 'BOS') ?></span> (<?= $item['purchase_year'] ?: '-' ?>)
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-1.5 text-slate-200 font-medium">
                                        <i class="fa-solid fa-location-dot text-slate-400 text-xs"></i>
                                        <span><?= htmlspecialchars($item['room_location']) ?></span>
                                    </div>
                                </td>

                                <td class="px-4 py-4 text-center font-bold text-white">
                                    <?= number_format($item['quantity']) ?> <span class="text-xs text-slate-400 font-normal"><?= htmlspecialchars($item['unit']) ?></span>
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <?php if ($item['condition_status'] === 'baik'): ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-400">
                                            <i class="fa-solid fa-circle-check text-[10px]"></i> Baik
                                        </span>
                                    <?php elseif ($item['condition_status'] === 'rusak_ringan'): ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-semibold text-amber-400">
                                            <i class="fa-solid fa-triangle-exclamation text-[10px]"></i> Rusak Ringan
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border border-rose-500/30 bg-rose-500/10 px-3 py-1 text-xs font-semibold text-rose-400">
                                            <i class="fa-solid fa-circle-xmark text-[10px]"></i> Rusak Berat
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4 text-right font-mono text-slate-200 font-semibold">
                                    Rp <?= number_format($item['purchase_cost'], 0, ',', '.') ?>
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button 
                                            onclick='openEditItemModal(<?= json_encode($item) ?>)'
                                            class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-semibold text-amber-400 transition cursor-pointer"
                                            title="Edit Aset">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>

                                        <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus barang aset <?= htmlspecialchars($item['item_name']) ?> dari buku inventaris?');" class="inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="delete_id" value="<?= $item['id'] ?>">
                                            <button type="submit" class="rounded-xl border border-rose-500/20 bg-rose-500/10 hover:bg-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-400 transition cursor-pointer" title="Hapus Aset">
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

<?php else: ?>

    <!-- Sirkulasi Peminjaman Sarpras -->
    <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-2xl backdrop-blur">
        <div class="p-6 border-b border-white/10 flex items-center justify-between">
            <h3 class="font-bold text-white text-lg flex items-center gap-2">
                <i class="fa-solid fa-hand-holding-hand text-blue-400"></i> Riwayat Sirkulasi Peminjaman Peralatan / Fasilitas
            </h3>
            <span class="text-xs text-slate-400">Total <?= count($loans_list) ?> catatan transaksi</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-6 py-4 font-semibold">Barang yang Dipinjam</th>
                        <th class="px-6 py-4 font-semibold">Peminjam & Kontak</th>
                        <th class="px-6 py-4 font-semibold">Tgl Pinjam / Batas</th>
                        <th class="px-6 py-4 font-semibold">Keperluan & Kegiatan</th>
                        <th class="px-4 py-4 font-semibold text-center">Status</th>
                        <th class="px-6 py-4 font-semibold text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($loans_list)): ?>
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-400 text-sm">
                                Belum ada transaksi peminjaman sarana dan fasilitas.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($loans_list as $ln): ?>
                            <tr class="hover:bg-white/5 transition">
                                <td class="px-6 py-4">
                                    <p class="font-bold text-white"><?= htmlspecialchars($ln['item_name']) ?></p>
                                    <span class="font-mono text-xs text-amber-400"><?= htmlspecialchars($ln['item_code']) ?></span>
                                    <p class="text-[11px] text-slate-400 mt-0.5"><i class="fa-solid fa-location-dot text-slate-500 mr-1"></i><?= htmlspecialchars($ln['room_location']) ?></p>
                                </td>

                                <td class="px-6 py-4">
                                    <p class="font-semibold text-white"><?= htmlspecialchars($ln['borrower_name']) ?></p>
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold bg-blue-500/10 text-blue-400 uppercase">
                                        <?= htmlspecialchars($ln['borrower_role']) ?>
                                    </span>
                                    <?php if (!empty($ln['borrower_phone'])): ?>
                                        <span class="text-xs text-slate-400 block mt-0.5"><i class="fa-brands fa-whatsapp text-emerald-400 mr-1"></i><?= htmlspecialchars($ln['borrower_phone']) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4">
                                    <div class="text-xs">
                                        <p class="text-slate-300">Pinjam: <span class="font-semibold text-white"><?= date('d/m/Y', strtotime($ln['borrow_date'])) ?></span></p>
                                        <p class="text-slate-400">Batas: <span class="font-semibold <?= (strtotime($ln['expected_return_date']) < time() && $ln['status'] === 'dipinjam') ? 'text-rose-400' : 'text-slate-300' ?>"><?= date('d/m/Y', strtotime($ln['expected_return_date'])) ?></span></p>
                                        <?php if ($ln['actual_return_date']): ?>
                                            <p class="text-emerald-400 text-[11px] mt-0.5 font-semibold">Kembali: <?= date('d/m/Y', strtotime($ln['actual_return_date'])) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="px-6 py-4">
                                    <p class="text-xs text-slate-200"><?= htmlspecialchars($ln['purpose']) ?></p>
                                    <?php if (!empty($ln['notes'])): ?>
                                        <p class="text-[11px] text-slate-400 italic mt-1">Catatan: <?= htmlspecialchars($ln['notes']) ?></p>
                                    <?php endif; ?>
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <?php if ($ln['status'] === 'dipinjam'): ?>
                                        <?php $is_overdue = (strtotime($ln['expected_return_date']) < strtotime(date('Y-m-d'))); ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border <?= $is_overdue ? 'border-rose-500/30 bg-rose-500/10 text-rose-400' : 'border-blue-500/30 bg-blue-500/10 text-blue-400' ?> px-3 py-1 text-xs font-semibold">
                                            <i class="fa-solid <?= $is_overdue ? 'fa-clock text-rose-400' : 'fa-hourglass-half' ?> text-[10px]"></i>
                                            <?= $is_overdue ? 'Jatuh Tempo' : 'Dipinjam' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-400">
                                            <i class="fa-solid fa-circle-check text-[10px]"></i> Dikembalikan
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-6 py-4 text-right">
                                    <?php if ($ln['status'] === 'dipinjam'): ?>
                                        <form method="POST" onsubmit="return confirm('Konfirmasi pengembalian barang <?= htmlspecialchars($ln['item_name']) ?> oleh <?= htmlspecialchars($ln['borrower_name']) ?>?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="return_loan">
                                            <input type="hidden" name="loan_id" value="<?= $ln['id'] ?>">
                                            <button type="submit" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-white transition shadow-sm cursor-pointer inline-flex items-center gap-1">
                                                <i class="fa-solid fa-check"></i> Kembalikan
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-500 font-semibold"><i class="fa-solid fa-lock text-slate-600 mr-1"></i>Selesai</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<!-- ========================================================= -->
<!-- MODAL: TAMBAH BARANG INVENTARIS BARU -->
<!-- ========================================================= -->
<div id="modalAddItem" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-amber-400"><i class="fa-solid fa-boxes-stacked"></i></span> Tambah Aset Inventaris Baru
            </h3>
            <button onclick="document.getElementById('modalAddItem').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_item">

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Nama Barang & Merk *</label>
                    <input type="text" name="item_name" required placeholder="Contoh: Laptop Asus ExpertBook Core i5" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Kode Aset (Opsional)</label>
                    <input type="text" name="item_code" placeholder="Otomatis" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white font-mono outline-none focus:border-amber-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Kategori Barang *</label>
                    <select name="category" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-500">
                        <?php foreach ($categories_list as $cat): ?>
                            <option value="<?= $cat ?>"><?= $cat ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Penempatan Ruangan *</label>
                    <select name="room_location" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-500">
                        <?php foreach ($rooms_list as $rm): ?>
                            <option value="<?= $rm ?>"><?= $rm ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Jumlah Unit *</label>
                    <input type="number" name="quantity" min="1" value="1" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Satuan</label>
                    <input type="text" name="unit" value="Unit" placeholder="Unit, Buah, Set, Paket" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Kondisi Aset *</label>
                    <select name="condition_status" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-500">
                        <option value="baik">Baik (Layak Pakai)</option>
                        <option value="rusak_ringan">Rusak Ringan (Perlu Servis)</option>
                        <option value="rusak_berat">Rusak Berat (Tidak Layak)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Sumber Dana</label>
                    <input type="text" name="funding_source" value="BOS Reguler" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Tahun Perolehan</label>
                    <input type="number" name="purchase_year" value="<?= date('Y') ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Harga Beli / Unit (Rp)</label>
                    <input type="number" name="purchase_cost" value="0" step="1000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white font-mono outline-none focus:border-amber-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Catatan / Spesifikasi / Nomor Seri</label>
                <textarea name="notes" rows="2" placeholder="Nomor seri pabrikan, spesifikasi teknis, atau keterangan hibah..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalAddItem').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-amber-600 hover:bg-amber-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-amber-500/25 cursor-pointer">
                    Simpan Barang Inventaris
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL: EDIT BARANG INVENTARIS -->
<!-- ========================================================= -->
<div id="modalEditItem" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-amber-400"><i class="fa-solid fa-pen-to-square"></i></span> Perbarui Data Barang Inventaris
            </h3>
            <button onclick="document.getElementById('modalEditItem').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="edit_id" id="edit_id">

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Nama Barang & Merk *</label>
                <input type="text" name="item_name" id="edit_item_name" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Kategori Barang *</label>
                    <select name="category" id="edit_category" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-500">
                        <?php foreach ($categories_list as $cat): ?>
                            <option value="<?= $cat ?>"><?= $cat ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Penempatan Ruangan *</label>
                    <select name="room_location" id="edit_room_location" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-500">
                        <?php foreach ($rooms_list as $rm): ?>
                            <option value="<?= $rm ?>"><?= $rm ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Jumlah Unit *</label>
                    <input type="number" name="quantity" id="edit_quantity" min="1" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Satuan</label>
                    <input type="text" name="unit" id="edit_unit" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Kondisi Aset *</label>
                    <select name="condition_status" id="edit_condition_status" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-amber-500">
                        <option value="baik">Baik (Layak Pakai)</option>
                        <option value="rusak_ringan">Rusak Ringan (Perlu Servis)</option>
                        <option value="rusak_berat">Rusak Berat (Tidak Layak)</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Sumber Dana</label>
                    <input type="text" name="funding_source" id="edit_funding_source" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Tahun Perolehan</label>
                    <input type="number" name="purchase_year" id="edit_purchase_year" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Harga Beli / Unit (Rp)</label>
                    <input type="number" name="purchase_cost" id="edit_purchase_cost" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white font-mono outline-none focus:border-amber-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Catatan / Spesifikasi Tambahan</label>
                <textarea name="notes" id="edit_notes" rows="2" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalEditItem').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-amber-600 hover:bg-amber-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-amber-500/25 cursor-pointer">
                    Perbarui Data
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL: CATAT PEMINJAMAN SARANA & PRASARANA -->
<!-- ========================================================= -->
<div id="modalLoanItem" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-blue-400"><i class="fa-solid fa-hand-holding-hand"></i></span> Formulir Peminjaman Fasilitas / Alat KBM
            </h3>
            <button onclick="document.getElementById('modalLoanItem').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="loan_item">

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Pilih Sarana / Barang yang Dipinjam *</label>
                <select name="item_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    <option value="">-- Pilih Barang dari Gudang/Ruangan --</option>
                    <?php foreach ($inventory_items as $it): ?>
                        <option value="<?= $it['id'] ?>">
                            <?= htmlspecialchars($it['item_name']) ?> (<?= htmlspecialchars($it['item_code']) ?>) | Lokasi: <?= htmlspecialchars($it['room_location']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Nama Peminjam *</label>
                    <input type="text" name="borrower_name" required placeholder="Nama guru, pembina, atau siswa" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Peran Peminjam *</label>
                    <select name="borrower_role" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-blue-500">
                        <option value="guru">Guru / Pendidik</option>
                        <option value="staf">Staf Tata Usaha</option>
                        <option value="siswa">Siswa / Pengurus OSIS</option>
                        <option value="ekstrakurikuler">Pembina Ekstrakurikuler</option>
                        <option value="tamu">Pihak Luar / Tamu</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">No. WhatsApp / HP</label>
                    <input type="text" name="borrower_phone" placeholder="08xxxxxxxxxx" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white font-mono outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Tanggal Pinjam *</label>
                    <input type="date" name="borrow_date" value="<?= date('Y-m-d') ?>" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Batas Pengembalian *</label>
                    <input type="date" name="expected_return_date" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Keperluan & Kegiatan Penggunaan *</label>
                <textarea name="purpose" required rows="2" placeholder="Contoh: Digunakan untuk presentasi Asesmen Nasional di Aula / KBM Praktik Fisika..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalLoanItem').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-blue-500/25 cursor-pointer">
                    Simpan Catatan Peminjaman
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditItemModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_item_name').value = data.item_name;
    document.getElementById('edit_category').value = data.category;
    document.getElementById('edit_room_location').value = data.room_location;
    document.getElementById('edit_quantity').value = data.quantity;
    document.getElementById('edit_unit').value = data.unit;
    document.getElementById('edit_condition_status').value = data.condition_status;
    document.getElementById('edit_funding_source').value = data.funding_source || '';
    document.getElementById('edit_purchase_year').value = data.purchase_year || '';
    document.getElementById('edit_purchase_cost').value = data.purchase_cost || 0;
    document.getElementById('edit_notes').value = data.notes || '';
    document.getElementById('modalEditItem').classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
