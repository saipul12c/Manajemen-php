<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['staf', 'administrator', 'guru']);

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'staf';

$school_info = getSchoolSettings($pdo);

$selected_month = $_GET['month'] ?? date('m');
$selected_year  = $_GET['year'] ?? date('Y');

$months = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

// Rekapitulasi per siswa
$stmt = $pdo->prepare("
    SELECT 
        u.id as student_id,
        u.name as student_name,
        u.nisn,
        c.name as class_name,
        COUNT(CASE WHEN sa.status = 'hadir' THEN 1 END) as total_hadir,
        COUNT(CASE WHEN sa.status = 'sakit' THEN 1 END) as total_sakit,
        COUNT(CASE WHEN sa.status = 'izin' THEN 1 END) as total_izin,
        COUNT(CASE WHEN sa.status = 'alpa' THEN 1 END) as total_alpa,
        COUNT(sa.id) as total_days
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN student_attendance sa ON u.id = sa.student_id 
        AND MONTH(sa.date) = ? AND YEAR(sa.date) = ?
    WHERE u.role = 'siswa'
    GROUP BY u.id, u.name, u.nisn, c.name
    ORDER BY c.name ASC, u.name ASC
");
$stmt->execute([$selected_month, $selected_year]);
$report_rows = $stmt->fetchAll();

// Total statistik sekolah
$tot_hadir = 0; $tot_sakit = 0; $tot_izin = 0; $tot_alpa = 0; $tot_records = 0;
foreach ($report_rows as $r) {
    $tot_hadir += (int)$r['total_hadir'];
    $tot_sakit += (int)$r['total_sakit'];
    $tot_izin  += (int)$r['total_izin'];
    $tot_alpa  += (int)$r['total_alpa'];
    $tot_records += (int)$r['total_days'];
}
$school_att_rate = $tot_records > 0 ? round(($tot_hadir / $tot_records) * 100, 1) : 100.0;

$page_title = "Laporan Rekapitulasi Presensi Bulanan";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-500/20 text-amber-400 text-lg border border-amber-500/30 shadow-lg shadow-amber-500/10">
                <i class="fa-solid fa-file-invoice"></i>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Laporan Rekapitulasi Presensi Sekolah</h1>
                <p class="text-sm text-slate-400">Arsip presensi resmi untuk verifikasi Tata Usaha, Kepala Sekolah, dan Dinas Pendidikan</p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-2">
        <a href="attendance_export.php?month=<?= urlencode($selected_month) ?>&year=<?= urlencode($selected_year) ?>" 
           class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-4 py-2 text-xs font-semibold text-emerald-300 transition flex items-center gap-1.5 shadow-sm">
            <i class="fa-solid fa-file-excel"></i> Export Excel (.csv)
        </a>
        <a href="attendance_print.php?month=<?= urlencode($selected_month) ?>&year=<?= urlencode($selected_year) ?>" 
           target="_blank"
           class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition flex items-center gap-1.5">
            <i class="fa-solid fa-print"></i> Cetak PDF Laporan
        </a>
        <a href="../surat/requests.php" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2 text-xs font-semibold text-white transition flex items-center gap-1.5">
            <i class="fa-solid fa-envelope-open-text"></i> Layanan Surat
        </a>
    </div>
</div>

<!-- Filter Bulan -->
<div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur shadow-xl mb-6">
    <form method="GET" action="attendance_report.php" class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <label class="text-xs font-semibold uppercase text-slate-400">Pilih Periode:</label>
            <select name="month" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                <?php foreach ($months as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $selected_month === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <select name="year" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= (int)$selected_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="text-xs text-slate-400">
            Lembaga: <strong class="text-white"><?= htmlspecialchars($school_info['school_name']) ?></strong>
        </div>
    </form>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
        <span class="text-[10px] uppercase font-bold text-slate-400">Kehadiran Rata-rata</span>
        <p class="text-2xl font-black text-emerald-400 mt-1"><?= $school_att_rate ?>%</p>
    </div>
    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
        <span class="text-[10px] uppercase font-bold text-slate-400">Total Hadir</span>
        <p class="text-2xl font-bold text-white mt-1"><?= $tot_hadir ?></p>
    </div>
    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
        <span class="text-[10px] uppercase font-bold text-slate-400">Total Sakit</span>
        <p class="text-2xl font-bold text-amber-300 mt-1"><?= $tot_sakit ?></p>
    </div>
    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
        <span class="text-[10px] uppercase font-bold text-slate-400">Total Izin</span>
        <p class="text-2xl font-bold text-blue-300 mt-1"><?= $tot_izin ?></p>
    </div>
    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
        <span class="text-[10px] uppercase font-bold text-slate-400">Total Alpa</span>
        <p class="text-2xl font-bold text-rose-400 mt-1"><?= $tot_alpa ?></p>
    </div>
</div>

<!-- Tabel Laporan -->
<div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-xl backdrop-blur">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-300">
            <thead class="border-b border-white/10 bg-white/5 text-[10px] uppercase font-bold tracking-wider text-slate-400">
                <tr>
                    <th class="px-6 py-3.5">No</th>
                    <th class="px-6 py-3.5">Nama Siswa</th>
                    <th class="px-4 py-3.5">NISN</th>
                    <th class="px-4 py-3.5">Kelas</th>
                    <th class="px-4 py-3.5 text-center">Hadir (H)</th>
                    <th class="px-4 py-3.5 text-center">Sakit (S)</th>
                    <th class="px-4 py-3.5 text-center">Izin (I)</th>
                    <th class="px-4 py-3.5 text-center">Alpa (A)</th>
                    <th class="px-6 py-3.5 text-center font-bold text-white">% Kehadiran</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php if (empty($report_rows)): ?>
                    <tr>
                        <td colspan="9" class="px-6 py-12 text-center text-slate-400">
                            Tidak ada data siswa pada periode ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($report_rows as $idx => $r): 
                        $td = (int)$r['total_days'];
                        $th = (int)$r['total_hadir'];
                        $pct = $td > 0 ? round(($th / $td) * 100, 1) : 100.0;
                    ?>
                        <tr class="hover:bg-white/[0.02] transition">
                            <td class="px-6 py-3.5 font-mono text-slate-500"><?= $idx + 1 ?></td>
                            <td class="px-6 py-3.5 font-bold text-white"><?= htmlspecialchars($r['student_name']) ?></td>
                            <td class="px-4 py-3.5 font-mono text-slate-400"><?= htmlspecialchars($r['nisn'] ?: '-') ?></td>
                            <td class="px-4 py-3.5 text-slate-300"><?= htmlspecialchars($r['class_name'] ?? 'Reguler') ?></td>
                            <td class="px-4 py-3.5 text-center font-bold text-emerald-400"><?= $r['total_hadir'] ?></td>
                            <td class="px-4 py-3.5 text-center font-bold text-amber-300"><?= $r['total_sakit'] ?></td>
                            <td class="px-4 py-3.5 text-center font-bold text-blue-300"><?= $r['total_izin'] ?></td>
                            <td class="px-4 py-3.5 text-center font-bold text-rose-400"><?= $r['total_alpa'] ?></td>
                            <td class="px-6 py-3.5 text-center font-mono font-bold <?= $pct >= 85 ? 'text-emerald-400' : 'text-amber-400' ?>">
                                <?= $pct ?>%
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
