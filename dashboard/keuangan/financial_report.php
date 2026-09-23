<?php
/**
 * Laporan Keuangan & Rekapitulasi Pembayaran SPP / Tagihan Siswa
 * Manajemen-PHP
 */
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();
requireRole(['staf', 'administrator']);

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'staf';

$school_info = getSchoolSettings($pdo);

// Ambil daftar kelas untuk filter
$classes = $pdo->query("SELECT * FROM classes ORDER BY grade_level ASC, name ASC")->fetchAll();

// Filter
$filter_month = trim($_GET['month'] ?? '');
$filter_year  = trim($_GET['year'] ?? date('Y'));
$filter_class = (int)($_GET['class_id'] ?? 0);
$filter_status = trim($_GET['status'] ?? '');

$months = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

$sql = "
    SELECT b.*, 
           u.name as student_name, u.nisn, u.gender, c.name as class_name,
           p.amount_paid, p.payment_method, p.payment_date, p.verified_at,
           vf.name as verifier_name
    FROM student_bills b
    JOIN users u ON b.student_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN bill_payments p ON p.bill_id = b.id AND p.status = 'diterima'
    LEFT JOIN users vf ON p.verified_by = vf.id
    WHERE 1=1
";
$params = [];

if ($filter_class > 0) {
    $sql .= " AND u.class_id = ?";
    $params[] = $filter_class;
}

if (!empty($filter_status)) {
    $sql .= " AND b.status = ?";
    $params[] = $filter_status;
}

if (!empty($filter_month)) {
    $sql .= " AND (b.month_period LIKE ? OR MONTH(b.due_date) = ?)";
    $month_str = "%" . ($months[$filter_month] ?? '') . "%";
    $params[] = $month_str;
    $params[] = (int)$filter_month;
}

if (!empty($filter_year)) {
    $sql .= " AND YEAR(b.due_date) = ?";
    $params[] = (int)$filter_year;
}

$sql .= " ORDER BY b.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bills = $stmt->fetchAll();

// Metrik Keuangan
$tot_bills_count = count($bills);
$tot_billed_amount = 0;
$tot_collected_amount = 0;
$tot_pending_amount = 0;
$count_lunas = 0;

foreach ($bills as $b) {
    $amt = (float)$b['amount'];
    $tot_billed_amount += $amt;
    if ($b['status'] === 'lunas') {
        $tot_collected_amount += $amt;
        $count_lunas++;
    } else {
        $tot_pending_amount += $amt;
    }
}
$collection_rate = $tot_billed_amount > 0 ? round(($tot_collected_amount / $tot_billed_amount) * 100, 1) : 0.0;

$page_title = "Laporan Keuangan Sekolah";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-500/20 text-emerald-400 text-xl border border-emerald-500/30 shadow-lg shadow-emerald-500/10">
                <i class="fa-solid fa-coins"></i>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Laporan Rekapitulasi Keuangan</h1>
                <p class="text-sm text-slate-400">Arsip mutasi kas, rekapitulasi pembayaran SPP bulanan, dan evaluasi piutang tagihan siswa</p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-2">
        <a href="financial_export.php?month=<?= urlencode($filter_month) ?>&year=<?= urlencode($filter_year) ?>&class_id=<?= $filter_class ?>&status=<?= urlencode($filter_status) ?>" 
           class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-emerald-300 transition flex items-center gap-1.5 shadow-sm">
            <i class="fa-solid fa-file-arrow-down"></i> Export Excel (.csv)
        </a>
        <a href="financial_print.php?month=<?= urlencode($filter_month) ?>&year=<?= urlencode($filter_year) ?>&class_id=<?= $filter_class ?>&status=<?= urlencode($filter_status) ?>" 
           target="_blank"
           class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition flex items-center gap-1.5">
            <i class="fa-solid fa-print"></i> Cetak PDF Laporan
        </a>
        <a href="payments.php" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2 text-xs font-semibold text-white transition inline-flex items-center gap-1.5">
            <i class="fa-solid fa-credit-card"></i> Loket Pembayaran
        </a>
    </div>
</div>

<!-- Metrik Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur shadow-xl">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-slate-400">Total Tagihan</span>
            <i class="fa-solid fa-file-invoice-dollar text-slate-400 text-lg"></i>
        </div>
        <p class="mt-3 text-2xl font-black text-white">Rp <?= number_format($tot_billed_amount, 0, ',', '.') ?></p>
        <p class="mt-1 text-xs text-slate-500"><?= $tot_bills_count ?> tagihan diterbitkan</p>
    </div>

    <div class="rounded-3xl border border-emerald-500/30 bg-emerald-500/10 p-5 backdrop-blur shadow-xl">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-emerald-300">Total Kas Masuk (Lunas)</span>
            <i class="fa-solid fa-circle-check text-emerald-400 text-lg"></i>
        </div>
        <p class="mt-3 text-2xl font-black text-emerald-300">Rp <?= number_format($tot_collected_amount, 0, ',', '.') ?></p>
        <p class="mt-1 text-xs text-emerald-400/80"><?= $count_lunas ?> transaksi terverifikasi</p>
    </div>

    <div class="rounded-3xl border border-rose-500/30 bg-rose-500/10 p-5 backdrop-blur shadow-xl">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-rose-300">Piutang / Belum Lunas</span>
            <i class="fa-solid fa-clock text-rose-400 text-lg"></i>
        </div>
        <p class="mt-3 text-2xl font-black text-rose-300">Rp <?= number_format($tot_pending_amount, 0, ',', '.') ?></p>
        <p class="mt-1 text-xs text-rose-400/80"><?= $tot_bills_count - $count_lunas ?> tagihan tertunggak</p>
    </div>

    <div class="rounded-3xl border border-blue-500/30 bg-blue-500/10 p-5 backdrop-blur shadow-xl">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-blue-300">Rasio Pelunasan</span>
            <i class="fa-solid fa-chart-line text-blue-400 text-lg"></i>
        </div>
        <p class="mt-3 text-2xl font-black text-blue-300"><?= number_format($collection_rate, 1) ?>%</p>
        <p class="mt-1 text-xs text-blue-400/80">Tingkat kolektibilitas kas</p>
    </div>
</div>

<!-- Filter Toolbar -->
<div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur shadow-xl mb-6">
    <form method="GET" action="financial_report.php" class="flex flex-wrap items-center gap-3">
        <div>
            <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Bulan</label>
            <select name="month" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                <option value="">-- Semua Bulan --</option>
                <?php foreach ($months as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filter_month === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Tahun</label>
            <select name="year" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= (int)$filter_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div>
            <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Rombel / Kelas</label>
            <select name="class_id" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                <option value="0">-- Semua Kelas --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filter_class === (int)$c['id'] ? 'selected' : '' ?>>
                        Kelas <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Status</label>
            <select name="status" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                <option value="">-- Semua Status --</option>
                <option value="lunas" <?= $filter_status === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                <option value="belum_lunas" <?= $filter_status === 'belum_lunas' ? 'selected' : '' ?>>Belum Lunas</option>
                <option value="menunggu_verifikasi" <?= $filter_status === 'menunggu_verifikasi' ? 'selected' : '' ?>>Menunggu Verifikasi</option>
            </select>
        </div>

        <div class="pt-4">
            <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-1.5 text-xs font-semibold text-white transition">
                Filter Data
            </button>
            <a href="financial_report.php" class="ml-1 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-semibold text-slate-400 hover:text-white transition">
                Reset
            </a>
        </div>
    </form>
</div>

<!-- Tabel Laporan Transaksi Keuangan -->
<div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-xl backdrop-blur">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
            <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-5 py-4">No</th>
                    <th class="px-5 py-4">Siswa</th>
                    <th class="px-4 py-4">Kelas</th>
                    <th class="px-5 py-4">Nama Tagihan</th>
                    <th class="px-4 py-4 text-right">Nominal</th>
                    <th class="px-4 py-4 text-center">Jatuh Tempo</th>
                    <th class="px-4 py-4 text-center">Status</th>
                    <th class="px-4 py-4">Metode Bayar</th>
                    <th class="px-4 py-4 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php if (empty($bills)): ?>
                    <tr>
                        <td colspan="9" class="px-6 py-12 text-center text-slate-400">
                            Tidak ada data tagihan atau pembayaran yang sesuai dengan kriteria filter.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($bills as $idx => $b): ?>
                        <tr class="hover:bg-white/[0.02] transition">
                            <td class="px-5 py-4 font-mono text-xs text-slate-500"><?= $idx + 1 ?></td>
                            <td class="px-5 py-4">
                                <p class="font-bold text-white"><?= htmlspecialchars($b['student_name']) ?></p>
                                <p class="text-xs text-slate-400 font-mono">NISN: <?= htmlspecialchars($b['nisn'] ?: '-') ?></p>
                            </td>
                            <td class="px-4 py-4 text-xs">
                                <span class="px-2.5 py-1 rounded-lg border border-white/10 bg-white/5 text-slate-300">
                                    <?= htmlspecialchars($b['class_name'] ?? 'Reguler') ?>
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <p class="font-semibold text-white"><?= htmlspecialchars($b['title']) ?></p>
                                <p class="text-[11px] text-slate-500"><?= htmlspecialchars($b['month_period'] ?: 'Reguler') ?></p>
                            </td>
                            <td class="px-4 py-4 text-right font-mono font-bold text-white">
                                Rp <?= number_format((float)$b['amount'], 0, ',', '.') ?>
                            </td>
                            <td class="px-4 py-4 text-center text-xs text-slate-400">
                                <?= date('d M Y', strtotime($b['due_date'])) ?>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <?php if ($b['status'] === 'lunas'): ?>
                                    <span class="inline-flex items-center rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-xs font-bold text-emerald-300">
                                        ● Lunas
                                    </span>
                                <?php elseif ($b['status'] === 'menunggu_verifikasi'): ?>
                                    <span class="inline-flex items-center gap-1 rounded-lg border border-amber-500/30 bg-amber-500/10 px-2.5 py-1 text-xs font-bold text-amber-300">
                                        <i class="fa-solid fa-clock"></i> Menunggu Verifikasi
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center rounded-lg border border-rose-500/30 bg-rose-500/10 px-2.5 py-1 text-xs font-bold text-rose-300">
                                        Belum Lunas
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 text-xs text-slate-300">
                                <?php if (!empty($b['payment_method'])): ?>
                                    <span class="uppercase font-semibold text-slate-200"><?= htmlspecialchars(str_replace('_', ' ', $b['payment_method'])) ?></span>
                                    <?php if (!empty($b['payment_date'])): ?>
                                        <p class="text-[10px] text-slate-500"><?= date('d/m/Y', strtotime($b['payment_date'])) ?></p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-slate-600">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <?php if ($b['status'] === 'lunas'): ?>
                                    <a href="receipt.php?bill_id=<?= $b['id'] ?>" target="_blank"
                                       class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1 text-xs font-semibold text-blue-400 transition">
                                        <i class="fa-solid fa-receipt"></i> Kuitansi
                                    </a>
                                <?php else: ?>
                                    <a href="payments.php" 
                                       class="text-xs text-slate-500 hover:text-slate-300 inline-flex items-center gap-1">
                                        Kelola <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
