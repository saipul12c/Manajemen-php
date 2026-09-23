<?php
/**
 * Cetak Laporan Resmi Keuangan & Rekap Kas SPP (PDF / Print View)
 * Manajemen-PHP
 */
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();
requireRole(['staf', 'administrator']);

$school_info = getSchoolSettings($pdo);

$filter_month = trim($_GET['month'] ?? '');
$filter_year  = trim($_GET['year'] ?? date('Y'));
$filter_class = (int)($_GET['class_id'] ?? 0);
$filter_status = trim($_GET['status'] ?? '');

$months = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

$month_name_str = !empty($filter_month) ? ($months[$filter_month] ?? $filter_month) : "Seluruh Bulan";

$class_name_filter = "Seluruh Kelas";
if ($filter_class > 0) {
    $stmt_c = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt_c->execute([$filter_class]);
    $c_row = $stmt_c->fetch();
    if ($c_row) {
        $class_name_filter = "Kelas " . $c_row['name'];
    }
}

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

$tot_billed = 0;
$tot_collected = 0;
$tot_pending = 0;

foreach ($bills as $b) {
    $amt = (float)$b['amount'];
    $tot_billed += $amt;
    if ($b['status'] === 'lunas') {
        $tot_collected += $amt;
    } else {
        $tot_pending += $amt;
    }
}
$rate = $tot_billed > 0 ? round(($tot_collected / $tot_billed) * 100, 1) : 0.0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Keuangan <?= htmlspecialchars($month_name_str . ' ' . $filter_year) ?> - <?= htmlspecialchars($school_info['school_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm 20mm;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Times New Roman', Times, serif;
            color: #111827;
            background-color: #f3f4f6;
            line-height: 1.4;
            font-size: 10.5pt;
            padding: 20px;
        }
        .page-container {
            max-width: 840px;
            margin: 0 auto;
            background: #ffffff;
            padding: 40px 45px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border-radius: 8px;
        }
        .toolbar {
            max-width: 840px;
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
        .btn-back { background: rgba(255, 255, 255, 0.1); color: #e2e8f0; }
        .btn-back:hover { background: rgba(255, 255, 255, 0.2); color: #ffffff; }
        .btn-print { background: #2563eb; color: #ffffff; }
        .btn-print:hover { background: #1d4ed8; }
        .btn-excel { background: #059669; color: #ffffff; }
        .btn-excel:hover { background: #047857; }

        /* Kop Surat */
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
        .kop-text { flex-grow: 1; }
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
        }
        .kop-text p { font-size: 9pt; color: #333; }

        .doc-title { text-align: center; margin-bottom: 20px; }
        .doc-title h3 {
            font-size: 13pt;
            font-weight: bold;
            text-decoration: underline;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-title p { font-size: 10pt; margin-top: 4px; font-style: italic; }

        .meta-table { width: 100%; margin-bottom: 16px; font-size: 9.5pt; }
        .meta-table td { padding: 3px 0; vertical-align: top; }

        .finance-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            margin-bottom: 20px;
        }
        .finance-table th, .finance-table td {
            border: 1px solid #000;
            padding: 6px 8px;
        }
        .finance-table th {
            background-color: #f1f5f9;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            font-size: 8.5pt;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }

        .summary-box {
            border: 1px dashed #000;
            padding: 12px 15px;
            margin-bottom: 25px;
            font-size: 9.5pt;
            background: #fafafa;
        }

        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            page-break-inside: avoid;
        }
        .sig-block { width: 250px; text-align: center; font-size: 10pt; }
        .sig-space { height: 70px; }

        @media print {
            body { background: #ffffff; padding: 0; }
            .page-container { box-shadow: none; padding: 0; max-width: 100%; }
            .toolbar { display: none !important; }
            .finance-table th {
                background-color: #f1f5f9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <!-- Floating Action Bar -->
    <div class="toolbar">
        <div style="display: flex; align-items: center; gap: 8px;">
            <a href="financial_report.php" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Laporan
            </a>
            <span style="opacity: 0.6;">|</span>
            <span>Dokumen Rekapitulasi Keuangan Kas Sekolah</span>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="financial_export.php?month=<?= urlencode($filter_month) ?>&year=<?= urlencode($filter_year) ?>&class_id=<?= $filter_class ?>&status=<?= urlencode($filter_status) ?>" class="btn-excel">
                <i class="fa-solid fa-file-arrow-down"></i> Unduh Excel (.csv)
            </a>
            <button onclick="window.print()" class="btn-print">
                <i class="fa-solid fa-print"></i> Cetak / Simpan PDF
            </button>
        </div>
    </div>

    <div class="page-container">
        <!-- Kop Surat -->
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

        <!-- Judul -->
        <div class="doc-title">
            <h3>LAPORAN REKAPITULASI PENERIMAAN KAS & TAGIHAN SISWA</h3>
            <p>Periode: <?= htmlspecialchars($month_name_str . ' ' . $filter_year) ?></p>
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
                <td>Tanggal Cetak</td>
                <td>: <?= date('d F Y') ?></td>
                <td>Filter Status</td>
                <td>: <strong><?= !empty($filter_status) ? ucfirst(str_replace('_', ' ', $filter_status)) : 'Semua Transaksi' ?></strong></td>
            </tr>
        </table>

        <!-- Tabel Transaksi -->
        <table class="finance-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 25%;">Nama Siswa</th>
                    <th style="width: 12%;">Kelas</th>
                    <th style="width: 26%;">Uraian Tagihan</th>
                    <th style="width: 16%;">Nominal (Rp)</th>
                    <th style="width: 16%;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bills)): ?>
                    <tr>
                        <td colspan="6" class="text-center" style="padding: 20px;">
                            Tidak ada data transaksi keuangan pada periode ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($bills as $idx => $b): ?>
                        <tr>
                            <td class="text-center"><?= $idx + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars($b['student_name']) ?></strong>
                                <span style="font-size: 8pt; color: #555; display: block; font-family: monospace;">NISN: <?= htmlspecialchars($b['nisn'] ?: '-') ?></span>
                            </td>
                            <td class="text-center"><?= htmlspecialchars($b['class_name'] ?? 'Reguler') ?></td>
                            <td>
                                <?= htmlspecialchars($b['title']) ?>
                                <?php if (!empty($b['payment_date'])): ?>
                                    <span style="font-size: 7.5pt; color: #444; display: block;">Bayar: <?= date('d/m/Y', strtotime($b['payment_date'])) ?> (<?= strtoupper(str_replace('_', ' ', $b['payment_method'])) ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right font-bold" style="font-family: monospace;">
                                <?= number_format((float)$b['amount'], 0, ',', '.') ?>
                            </td>
                            <td class="text-center">
                                <?php if ($b['status'] === 'lunas'): ?>
                                    <strong style="color: #15803d;">LUNAS</strong>
                                <?php elseif ($b['status'] === 'menunggu_verifikasi'): ?>
                                    <strong style="color: #b45309;">VERIFIKASI</strong>
                                <?php else: ?>
                                    <strong style="color: #b91c1c;">TERTUNGGAK</strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8fafc; font-weight: bold;">
                    <td colspan="4" class="text-center">TOTAL KESELURUHAN TAGIHAN</td>
                    <td class="text-right" style="font-family: monospace;">Rp <?= number_format($tot_billed, 0, ',', '.') ?></td>
                    <td class="text-center"><?= count($bills) ?> Data</td>
                </tr>
            </tfoot>
        </table>

        <!-- Ringkasan Keuangan Box -->
        <div class="summary-box">
            <strong>Rekapitulasi Arus Kas Penerimaan:</strong>
            <table style="width: 100%; margin-top: 6px; font-size: 9.5pt;">
                <tr>
                    <td style="width: 33%;">1. Total Kas Masuk (Lunas):</td>
                    <td style="width: 67%;"><strong>Rp <?= number_format($tot_collected, 0, ',', '.') ?></strong></td>
                </tr>
                <tr>
                    <td>2. Total Piutang (Tertunggak):</td>
                    <td><strong style="color: #b91c1c;">Rp <?= number_format($tot_pending, 0, ',', '.') ?></strong></td>
                </tr>
                <tr>
                    <td>3. Tingkat Kolektibilitas Kas:</td>
                    <td><strong><?= number_format($rate, 1) ?>%</strong></td>
                </tr>
            </table>
        </div>

        <!-- Tanda Tangan -->
        <div class="signature-section">
            <div class="sig-block">
                <p>Mengetahui / Memeriksa,<br>Bendahara Sekolah / Staf Keuangan,</p>
                <div class="sig-space"></div>
                <p><strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Budi Santoso, S.Kom') ?></strong></p>
                <p style="font-size: 8.5pt; color: #444;">NIP: 19880620 201201 1 003</p>
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
