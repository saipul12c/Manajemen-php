<?php
/**
 * Cetak Lembar Daftar Hadir Peserta Ujian (DHPU) & Berita Acara Seleksi PPDB
 * Dokumen resmi fisik untuk pengawas ruang dan panitia ujian mandiri/offline.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../../config/database.php";

requireLogin();
requireRole(['administrator', 'staf', 'guru']);

$school_info = getSchoolSettings($pdo);

// Ambil daftar filter yang tersedia
$dates_stmt = $pdo->query("SELECT DISTINCT exam_date FROM ppdb_registrations WHERE exam_date IS NOT NULL AND exam_date != '' ORDER BY exam_date ASC");
$distinct_dates = $dates_stmt->fetchAll(PDO::FETCH_COLUMN);

$rooms_stmt = $pdo->query("SELECT DISTINCT exam_room FROM ppdb_registrations WHERE exam_room IS NOT NULL AND exam_room != '' ORDER BY exam_room ASC");
$distinct_rooms = $rooms_stmt->fetchAll(PDO::FETCH_COLUMN);

$sessions_stmt = $pdo->query("SELECT DISTINCT exam_session FROM ppdb_registrations WHERE exam_session IS NOT NULL AND exam_session != '' ORDER BY exam_session ASC");
$distinct_sessions = $sessions_stmt->fetchAll(PDO::FETCH_COLUMN);

// Filter terpilih
$filter_date    = trim($_GET['exam_date'] ?? ($distinct_dates[0] ?? ''));
$filter_room    = trim($_GET['exam_room'] ?? ($distinct_rooms[0] ?? ''));
$filter_session = trim($_GET['exam_session'] ?? ($distinct_sessions[0] ?? ''));

$where = ["status IN ('menunggu_verifikasi', 'diverifikasi', 'lulus_seleksi', 'diterima')"];
$params = [];

if ($filter_date !== '' && $filter_date !== 'all') {
    $where[] = "exam_date = ?";
    $params[] = $filter_date;
}
if ($filter_room !== '' && $filter_room !== 'all') {
    $where[] = "exam_room = ?";
    $params[] = $filter_room;
}
if ($filter_session !== '' && $filter_session !== 'all') {
    $where[] = "exam_session = ?";
    $params[] = $filter_session;
}

$where_clause = implode(" AND ", $where);
$stmt = $pdo->prepare("SELECT * FROM ppdb_registrations WHERE $where_clause ORDER BY exam_room ASC, exam_session ASC, registration_no ASC");
$stmt->execute($params);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contoh waktu ujian yang tertera di salah satu peserta
$exam_time_display = '-';
foreach ($candidates as $c) {
    if (!empty($c['exam_time'])) {
        $exam_time_display = $c['exam_time'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Hadir & Berita Acara Ujian PPDB - <?= htmlspecialchars($filter_room ?: 'Semua Ruang') ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; padding: 0 !important; }
            .print-sheet { box-shadow: none !important; border: none !important; border-radius: 0 !important; width: 100% !important; max-width: 100% !important; padding: 0 !important; }
            .page-break { page-break-after: always; }
        }
    </style>
</head>
<body class="bg-slate-900 min-h-screen py-8 px-4 text-slate-800 antialiased flex flex-col items-center">

    <!-- Filter & Action Toolbar (Hidden during print) -->
    <div class="no-print mb-6 w-full max-w-5xl bg-slate-800/90 backdrop-blur-md p-4 rounded-2xl border border-white/10 shadow-xl">
        <form method="GET" class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-300 mb-1">Tanggal Ujian:</label>
                    <select name="exam_date" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                        <option value="all">-- Semua Tanggal --</option>
                        <?php foreach ($distinct_dates as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>" <?= $filter_date === $d ? 'selected' : '' ?>>
                                <?= date('d M Y', strtotime($d)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-slate-300 mb-1">Ruangan / Lab:</label>
                    <select name="exam_room" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                        <option value="all">-- Semua Ruangan --</option>
                        <?php foreach ($distinct_rooms as $r): ?>
                            <option value="<?= htmlspecialchars($r) ?>" <?= $filter_room === $r ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-slate-300 mb-1">Sesi Ujian:</label>
                    <select name="exam_session" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                        <option value="all">-- Semua Sesi --</option>
                        <?php foreach ($distinct_sessions as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>" <?= $filter_session === $s ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-2.5">
                <a href="ppdb.php" class="inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/5 px-3.5 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
                    <i class="fa-solid fa-arrow-left"></i> Kembali
                </a>
                <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-teal-600 hover:bg-teal-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-teal-600/30 transition cursor-pointer">
                    <i class="fa-solid fa-print"></i> Cetak Dokumen (Print)
                </button>
            </div>
        </form>
    </div>

    <!-- Printable Sheet (A4 Sheet Container) -->
    <div class="print-sheet w-full max-w-5xl rounded-3xl bg-white p-8 sm:p-12 shadow-2xl border border-slate-200">

        <!-- KOP Surat Resmi -->
        <div class="flex items-center justify-between border-b-2 border-slate-900 pb-4 mb-5">
            <div class="flex items-center gap-4">
                <?php 
                    $clean_dhpu_logo = !empty($school_info['school_logo']) ? resolveAssetUrl($school_info['school_logo'], '../../') : '';
                    $dhpu_logo_exists = !empty($clean_dhpu_logo) && (strpos($clean_dhpu_logo, 'http') === 0 || file_exists(dirname(__DIR__, 2) . '/' . resolveAssetUrl($school_info['school_logo'])));
                ?>
                <?php if ($dhpu_logo_exists): ?>
                    <img src="<?= htmlspecialchars($clean_dhpu_logo) ?>" alt="Logo" class="h-16 w-16 object-contain shrink-0">
                <?php else: ?>
                    <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-teal-700 text-white font-black text-2xl shadow-md shrink-0">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <h2 class="text-lg font-black text-slate-900 uppercase tracking-tight">PANITIA PENERIMAAN PESERTA DIDIK BARU (PPDB)</h2>
                    <h3 class="text-base font-bold text-teal-800 uppercase"><?= htmlspecialchars($school_info['school_name'] ?? 'SMA / SMK TERPADU INDONESIA') ?></h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        <?= htmlspecialchars($school_info['school_address'] ?? 'Jl. Pendidikan No. 45') ?> | 
                        Telp: <?= htmlspecialchars($school_info['school_phone'] ?? '(021) 7890-1234') ?> | 
                        Email: <?= htmlspecialchars($school_info['school_email'] ?? 'ppdb@sekolah.sch.id') ?>
                    </p>
                </div>
            </div>
            <div class="text-right hidden sm:block">
                <span class="inline-block rounded-lg bg-teal-50 border border-teal-200 px-3 py-1 text-xs font-bold text-teal-900 uppercase">
                    FORM DHPU - 01
                </span>
            </div>
        </div>

        <!-- Judul Form -->
        <div class="text-center mb-6">
            <h1 class="text-lg sm:text-xl font-black text-slate-900 uppercase tracking-wider underline">DAFTAR HADIR PESERTA UJIAN (DHPU)</h1>
            <p class="text-xs text-slate-600 mt-1">Ujian Seleksi Masuk Berbasis Komputer (CBT / Mandiri Offline) Tahun Ajaran 2026/2027</p>
        </div>

        <!-- Info Kotak Ruang & Sesi -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-slate-50 p-4 rounded-2xl border border-slate-200 mb-6 text-xs">
            <div>
                <span class="text-slate-500 block text-[10px] uppercase font-bold tracking-wider">Tanggal Ujian:</span>
                <strong class="text-slate-900 text-sm">
                    <?= !empty($filter_date) && $filter_date !== 'all' ? date('d F Y', strtotime($filter_date)) : 'Semua Tanggal' ?>
                </strong>
            </div>
            <div>
                <span class="text-slate-500 block text-[10px] uppercase font-bold tracking-wider">Ruangan / Lab:</span>
                <strong class="text-teal-700 text-sm font-bold">
                    <?= !empty($filter_room) && $filter_room !== 'all' ? htmlspecialchars($filter_room) : 'Semua Ruangan' ?>
                </strong>
            </div>
            <div>
                <span class="text-slate-500 block text-[10px] uppercase font-bold tracking-wider">Sesi & Jam:</span>
                <strong class="text-slate-900 text-sm">
                    <?= !empty($filter_session) && $filter_session !== 'all' ? htmlspecialchars($filter_session) : 'Semua Sesi' ?>
                    <span class="text-slate-500 font-normal block text-[11px]"><?= htmlspecialchars($exam_time_display) ?></span>
                </strong>
            </div>
            <div>
                <span class="text-slate-500 block text-[10px] uppercase font-bold tracking-wider">Total Peserta:</span>
                <strong class="text-slate-900 text-sm font-mono"><?= count($candidates) ?> Orang</strong>
            </div>
        </div>

        <!-- Tabel Peserta Ujian & Tanda Tangan -->
        <div class="overflow-x-auto mb-8">
            <table class="w-full text-left text-xs border-collapse border border-slate-300">
                <thead>
                    <tr class="bg-slate-100 text-slate-800 font-bold uppercase tracking-wider text-[11px] border-b border-slate-300">
                        <th class="py-2.5 px-2 border border-slate-300 text-center w-10">No</th>
                        <th class="py-2.5 px-3 border border-slate-300 w-32">No. Registrasi</th>
                        <th class="py-2.5 px-3 border border-slate-300 w-28">NISN</th>
                        <th class="py-2.5 px-3 border border-slate-300">Nama Lengkap Peserta</th>
                        <th class="py-2.5 px-2 border border-slate-300 text-center w-12">L/P</th>
                        <th class="py-2.5 px-3 border border-slate-300 w-36">Peminatan / Jurusan</th>
                        <th class="py-2.5 px-3 border border-slate-300 text-center w-16">Skor CBT</th>
                        <th class="py-2.5 px-4 border border-slate-300 text-center w-40">Tanda Tangan Peserta</th>
                        <th class="py-2.5 px-2 border border-slate-300 text-center w-20">Ket.</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php if (empty($candidates)): ?>
                        <tr>
                            <td colspan="9" class="py-8 text-center text-slate-500 italic border border-slate-300">
                                Tidak ada peserta ujian yang dialokasikan pada tanggal / ruangan / sesi yang dipilih.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($candidates as $idx => $cand): 
                            $no = $idx + 1;
                            $is_odd = ($no % 2 !== 0);
                        ?>
                            <tr class="hover:bg-slate-50">
                                <td class="py-2.5 px-2 text-center border border-slate-300 font-mono"><?= $no ?></td>
                                <td class="py-2.5 px-3 font-mono font-bold text-slate-900 border border-slate-300 whitespace-nowrap">
                                    <?= htmlspecialchars($cand['registration_no']) ?>
                                </td>
                                <td class="py-2.5 px-3 font-mono text-slate-600 border border-slate-300 whitespace-nowrap">
                                    <?= htmlspecialchars($cand['nisn'] ?: '-') ?>
                                </td>
                                <td class="py-2.5 px-3 font-semibold text-slate-900 border border-slate-300 uppercase">
                                    <?= htmlspecialchars($cand['full_name']) ?>
                                </td>
                                <td class="py-2.5 px-2 text-center border border-slate-300 uppercase font-mono">
                                    <?= $cand['gender'] === 'perempuan' ? 'P' : 'L' ?>
                                </td>
                                <td class="py-2.5 px-3 text-slate-700 border border-slate-300">
                                    <?= htmlspecialchars($cand['chosen_major'] ?: 'Reguler') ?>
                                </td>
                                <td class="py-2.5 px-3 text-center font-mono font-bold border border-slate-300">
                                    <?= $cand['selection_score'] !== null ? number_format((float)$cand['selection_score'], 1) : '-' ?>
                                </td>
                                <td class="py-2.5 px-2 border border-slate-300 h-11 align-bottom text-slate-400">
                                    <div class="flex items-end justify-between w-full h-full">
                                        <?php if ($is_odd): ?>
                                            <span class="text-[10px] font-mono font-bold text-slate-500"><?= $no ?>. ....................</span>
                                            <span></span>
                                        <?php else: ?>
                                            <span></span>
                                            <span class="text-[10px] font-mono font-bold text-slate-500 text-right"><?= $no ?>. ....................</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-2.5 px-2 text-center border border-slate-300 text-[10px]">
                                    [ &nbsp; ] H<br>[ &nbsp; ] A
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- BERITA ACARA PELAKSANAAN UJIAN (OFFICIAL PROCTOR REPORT) -->
        <div class="border border-slate-300 rounded-2xl p-5 bg-slate-50/50 mb-8 page-break-inside-avoid">
            <h3 class="text-sm font-bold text-slate-900 uppercase tracking-wider border-b border-slate-200 pb-2 mb-3">
                BERITA ACARA PELAKSANAAN UJIAN SELEKSI
            </h3>
            <p class="text-xs text-slate-600 leading-relaxed mb-4">
                Pada hari ini, tanggal <strong><?= !empty($filter_date) && $filter_date !== 'all' ? date('d F Y', strtotime($filter_date)) : '..................................' ?></strong>, 
                telah dilaksanakan Ujian Seleksi Masuk Calon Peserta Didik Baru pada ruangan <strong><?= htmlspecialchars($filter_room ?: '..................') ?></strong> 
                dengan rincian laporan pelaksanaan sebagai berikut:
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs mb-4">
                <div class="p-3 bg-white border border-slate-200 rounded-xl">
                    <span class="text-slate-500 block text-[10px] uppercase font-bold">1. Jumlah Terdaftar</span>
                    <strong class="text-sm font-mono text-slate-900"><?= count($candidates) ?> Orang</strong>
                </div>
                <div class="p-3 bg-white border border-slate-200 rounded-xl">
                    <span class="text-slate-500 block text-[10px] uppercase font-bold">2. Jumlah Hadir</span>
                    <span class="text-sm font-mono text-slate-900">.......... Orang</span>
                </div>
                <div class="p-3 bg-white border border-slate-200 rounded-xl">
                    <span class="text-slate-500 block text-[10px] uppercase font-bold">3. Jumlah Tidak Hadir</span>
                    <span class="text-sm font-mono text-slate-900">.......... Orang</span>
                </div>
            </div>

            <div class="text-xs mb-3">
                <span class="font-bold text-slate-700 block mb-1">Catatan / Kejadian Khusus Selama Ujian:</span>
                <div class="h-16 border border-dashed border-slate-300 rounded-xl bg-white p-2.5 text-slate-400 italic">
                    (Ujian berjalan tertib, aman, dan lancar tanpa kendala teknis / catatan insiden)
                </div>
            </div>
        </div>

        <!-- Kolom Tanda Tangan Panitia & Pengawas Ruang -->
        <div class="grid grid-cols-3 gap-6 text-center text-xs pt-4">
            <div>
                <p class="text-slate-500 mb-1">Pengawas Ruang 1</p>
                <div class="h-20"></div>
                <p class="font-bold text-slate-900 underline uppercase">( ........................................ )</p>
                <p class="text-[10px] text-slate-500 mt-0.5">NIP/ID: ..................................</p>
            </div>
            <div>
                <p class="text-slate-500 mb-1">Pengawas Ruang 2 / Proktor</p>
                <div class="h-20"></div>
                <p class="font-bold text-slate-900 underline uppercase">( ........................................ )</p>
                <p class="text-[10px] text-slate-500 mt-0.5">NIP/ID: ..................................</p>
            </div>
            <div>
                <p class="text-slate-500 mb-1">Ketua Panitia / Kepala Sekolah</p>
                <div class="h-20"></div>
                <p class="font-bold text-slate-900 underline uppercase">
                    <?= htmlspecialchars($school_info['headmaster_name'] ?? '( ........................................ )') ?>
                </p>
                <p class="text-[10px] text-slate-500 mt-0.5">
                    NIP: <?= htmlspecialchars($school_info['headmaster_nip'] ?? '..................................') ?>
                </p>
            </div>
        </div>

    </div>

</body>
</html>
