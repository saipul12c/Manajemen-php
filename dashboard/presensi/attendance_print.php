<?php
/**
 * Cetak Laporan Resmi Rekapitulasi Presensi Siswa (PDF / Print View)
 * Manajemen-PHP
 */
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();
requireRole(['staf', 'administrator', 'guru']);

$school_info = getSchoolSettings($pdo);

$selected_month = $_GET['month'] ?? date('m');
$selected_year  = $_GET['year'] ?? date('Y');
$selected_class = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

$months = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];
$month_name = $months[$selected_month] ?? $selected_month;

// Ambil info nama kelas jika dipilih
$class_name_filter = "Seluruh Kelas";
if ($selected_class > 0) {
    $stmt_c = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt_c->execute([$selected_class]);
    $c_row = $stmt_c->fetch();
    if ($c_row) {
        $class_name_filter = "Kelas " . $c_row['name'];
    }
}

// Query data rekap presensi per siswa
$sql = "
    SELECT 
        u.id as student_id,
        u.name as student_name,
        u.nisn,
        u.gender,
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
";
$params = [$selected_month, $selected_year];

if ($selected_class > 0) {
    $sql .= " AND u.class_id = ?";
    $params[] = $selected_class;
}

$sql .= " GROUP BY u.id, u.name, u.nisn, u.gender, c.name ORDER BY c.name ASC, u.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$report_rows = $stmt->fetchAll();

// Total statistik
$tot_hadir = 0; $tot_sakit = 0; $tot_izin = 0; $tot_alpa = 0; $tot_records = 0;
foreach ($report_rows as $r) {
    $tot_hadir += (int)$r['total_hadir'];
    $tot_sakit += (int)$r['total_sakit'];
    $tot_izin  += (int)$r['total_izin'];
    $tot_alpa  += (int)$r['total_alpa'];
    $tot_records += (int)$r['total_days'];
}
$school_att_rate = $tot_records > 0 ? round(($tot_hadir / $tot_records) * 100, 1) : 100.0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Presensi <?= htmlspecialchars($month_name . ' ' . $selected_year) ?> - <?= htmlspecialchars($school_info['school_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm 20mm;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Times New Roman', Times, serif;
            color: #111827;
            background-color: #f3f4f6;
            line-height: 1.4;
            font-size: 11pt;
            padding: 20px;
        }
        .page-container {
            max-width: 820px;
            margin: 0 auto;
            background: #ffffff;
            padding: 40px 45px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border-radius: 8px;
        }
        .toolbar {
            max-width: 820px;
            margin: 0 auto 16px auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #0f172a;
            padding: 12px 20px;
            border-radius: 12px;
            color: #ffffff;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 13px;
        }
        .toolbar a, .toolbar button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.15s ease;
        }
        .btn-back {
            background: rgba(255, 255, 255, 0.1);
            color: #e2e8f0;
        }
        .btn-back:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }
        .btn-print {
            background: #2563eb;
            color: #ffffff;
        }
        .btn-print:hover {
            background: #1d4ed8;
        }
        .btn-excel {
            background: #059669;
            color: #ffffff;
        }
        .btn-excel:hover {
            background: #047857;
        }
        
        /* Kop Surat Resmi */
        .kop-surat {
            display: flex;
            align-items: center;
            border-bottom: 3px double #000;
            padding-bottom: 12px;
            margin-bottom: 20px;
            text-align: center;
        }
        .kop-logo {
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            border-radius: 12px;
            background: #f1f5f9;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .kop-text {
            flex-grow: 1;
        }
        .kop-text h2 {
            font-size: 11pt;
            font-weight: normal;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .kop-text h1 {
            font-size: 16pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 2px 0;
            letter-spacing: 0.5px;
        }
        .kop-text p {
            font-size: 9pt;
            color: #333;
            margin-top: 1px;
        }

        .doc-title {
            text-align: center;
            margin-bottom: 20px;
        }
        .doc-title h3 {
            font-size: 13pt;
            font-weight: bold;
            text-decoration: underline;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-title p {
            font-size: 10pt;
            margin-top: 4px;
            font-style: italic;
        }

        .meta-table {
            width: 100%;
            margin-bottom: 18px;
            font-size: 10pt;
        }
        .meta-table td {
            padding: 3px 0;
            vertical-align: top;
        }

        .rekap-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5pt;
            margin-bottom: 20px;
        }
        .rekap-table th, .rekap-table td {
            border: 1px solid #000;
            padding: 6px 8px;
        }
        .rekap-table th {
            background-color: #f1f5f9;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            font-size: 8.5pt;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }

        /* Kotak Rekapitulasi */
        .summary-box {
            border: 1px dashed #000;
            padding: 10px 15px;
            margin-bottom: 25px;
            font-size: 9.5pt;
            background: #fafafa;
        }

        /* Tanda Tangan */
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            page-break-inside: avoid;
        }
        .sig-block {
            width: 250px;
            text-align: center;
            font-size: 10pt;
        }
        .sig-space {
            height: 70px;
        }

        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .page-container {
                box-shadow: none;
                padding: 0;
                max-width: 100%;
            }
            .toolbar {
                display: none !important;
            }
            .rekap-table th {
                background-color: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <!-- Floating Action Bar (Hanya tampil di layar) -->
    <div class="toolbar">
        <div style="display: flex; align-items: center; gap: 8px;">
            <a href="attendance_report.php?month=<?= urlencode($selected_month) ?>&year=<?= urlencode($selected_year) ?>" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Laporan
            </a>
            <span style="opacity: 0.6;">|</span>
            <span>Laporan Rekapitulasi Presensi</span>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="attendance_export.php?month=<?= urlencode($selected_month) ?>&year=<?= urlencode($selected_year) ?>&class_id=<?= $selected_class ?>" class="btn-excel">
                <i class="fa-solid fa-file-excel"></i> Unduh Excel (.csv)
            </a>
            <button onclick="window.print()" class="btn-print">
                <i class="fa-solid fa-print"></i> Cetak / Simpan PDF
            </button>
        </div>
    </div>

    <div class="page-container">
        <!-- Kop Surat Lembaga -->
        <div class="kop-surat">
            <div class="kop-logo">
                <i class="fa-solid fa-graduation-cap"></i>
            </div>
            <div class="kop-text">
                <h2>PEMERINTAH DAERAH PROVINSI / DINAS PENDIDIKAN</h2>
                <h1><?= htmlspecialchars($school_info['school_name'] ?? 'SMA BINA BANGSA NUSANTARA') ?></h1>
                <p><?= htmlspecialchars($school_info['school_address'] ?? 'Jl. Pendidikan Nasional No. 45') ?> | Telp: <?= htmlspecialchars($school_info['school_phone'] ?? '-') ?></p>
                <p>Website: <?= htmlspecialchars($school_info['school_website'] ?? 'https://sekolah.sch.id') ?> | Email: <?= htmlspecialchars($school_info['school_email'] ?? 'info@sekolah.sch.id') ?></p>
            </div>
        </div>

        <!-- Judul Dokumen -->
        <div class="doc-title">
            <h3>LAPORAN REKAPITULASI PRESENSI SISWA</h3>
            <p>Periode Bulan: <?= htmlspecialchars($month_name . ' ' . $selected_year) ?></p>
        </div>

        <!-- Metadata -->
        <table class="meta-table">
            <tr>
                <td style="width: 15%;">Tahun Ajaran</td>
                <td style="width: 35%;">: <strong><?= htmlspecialchars($school_info['academic_year'] ?? '2026/2027 Ganjil') ?></strong></td>
                <td style="width: 15%;">Rombel / Kelas</td>
                <td style="width: 35%;">: <strong><?= htmlspecialchars($class_name_filter) ?></strong></td>
            </tr>
            <tr>
                <td>Tanggal Unduh</td>
                <td>: <?= date('d F Y') ?></td>
                <td>Total Siswa</td>
                <td>: <strong><?= count($report_rows) ?> Siswa</strong></td>
            </tr>
        </table>

        <!-- Tabel Rekapitulasi -->
        <table class="rekap-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 32%;">Nama Siswa</th>
                    <th style="width: 15%;">NISN</th>
                    <th style="width: 12%;">Kelas</th>
                    <th style="width: 7%;">Hadir</th>
                    <th style="width: 7%;">Sakit</th>
                    <th style="width: 7%;">Izin</th>
                    <th style="width: 7%;">Alpa</th>
                    <th style="width: 8%;">% Hadir</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report_rows)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 20px;">
                            Tidak ada data presensi pada periode bulan <?= htmlspecialchars($month_name . ' ' . $selected_year) ?>.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = 1;
                    foreach ($report_rows as $row): 
                        $hadir = (int)$row['total_hadir'];
                        $sakit = (int)$row['total_sakit'];
                        $izin  = (int)$row['total_izin'];
                        $alpa  = (int)$row['total_alpa'];
                        $tot   = (int)$row['total_days'];
                        $pct   = $tot > 0 ? round(($hadir / $tot) * 100, 1) : 100.0;
                    ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td><strong><?= htmlspecialchars($row['student_name']) ?></strong></td>
                            <td class="text-center" style="font-family: monospace; font-size: 8.5pt;"><?= htmlspecialchars($row['nisn'] ?: '-') ?></td>
                            <td class="text-center"><?= htmlspecialchars($row['class_name'] ?? 'Reguler') ?></td>
                            <td class="text-center"><?= $hadir ?></td>
                            <td class="text-center"><?= $sakit ?></td>
                            <td class="text-center"><?= $izin ?></td>
                            <td class="text-center" style="<?= $alpa > 0 ? 'font-weight:bold; color:#b91c1c;' : '' ?>"><?= $alpa ?></td>
                            <td class="text-center font-bold"><?= number_format($pct, 1) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8fafc; font-weight: bold;">
                    <td colspan="4" class="text-center">TOTAL KESELURUHAN</td>
                    <td class="text-center"><?= $tot_hadir ?></td>
                    <td class="text-center"><?= $tot_sakit ?></td>
                    <td class="text-center"><?= $tot_izin ?></td>
                    <td class="text-center"><?= $tot_alpa ?></td>
                    <td class="text-center"><?= number_format($school_att_rate, 1) ?>%</td>
                </tr>
            </tfoot>
        </table>

        <!-- Ringkasan Statistik -->
        <div class="summary-box">
            <strong>Catatan Ringkasan Kehadiran:</strong>
            <ul style="margin-left: 18px; margin-top: 4px;">
                <li>Rata-rata Tingkat Kehadiran Sekolah: <strong><?= number_format($school_att_rate, 1) ?>%</strong></li>
                <li>Akumulasi Hari Sakit: <strong><?= $tot_sakit ?></strong> kali | Izin: <strong><?= $tot_izin ?></strong> kali | Alpa: <strong><?= $tot_alpa ?></strong> kali</li>
                <li>Laporan ini sah digunakan sebagai arsip evaluasi ketertiban siswa oleh Bagian Bimbingan Konseling dan Dinas Pendidikan.</li>
            </ul>
        </div>

        <!-- Tanda Tangan -->
        <div class="signature-section">
            <div class="sig-block">
                <p>Mengetahui,<br>Petugas Tata Usaha / Wali Kelas,</p>
                <div class="sig-space"></div>
                <p><strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Budi Santoso, S.Kom') ?></strong></p>
                <p style="font-size: 8.5pt; color: #444;">NIP/NUPTK: 19850412 201001 1 005</p>
            </div>

            <div class="sig-block">
                <p>Ditetapkan di Jakarta,<br><?= date('d F Y') ?><br>Kepala Sekolah,</p>
                <div class="sig-space"></div>
                <p><strong><?= htmlspecialchars($school_info['headmaster_name'] ?? 'Dr. H. Bambang Sudirman, M.Pd') ?></strong></p>
                <p style="font-size: 8.5pt; color: #444;">NIP: <?= htmlspecialchars($school_info['headmaster_nip'] ?? '19750812 199903 1 002') ?></p>
            </div>
        </div>
    </div>

</body>
</html>
