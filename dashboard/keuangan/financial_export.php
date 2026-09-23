<?php
/**
 * Export Laporan Keuangan Sekolah ke Format Excel / CSV
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

$month_name_str = !empty($filter_month) ? ($months[$filter_month] ?? $filter_month) : "Semua_Bulan";

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

$filename = "Laporan_Keuangan_{$month_name_str}_{$filter_year}_" . date('Ymd_His') . ".csv";

// Header download CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM untuk kompatibilitas penuh Microsoft Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Metadata Dokumen
fputcsv($output, ['LAPORAN KEUANGAN & REKAPITULASI TAGIHAN SPP SISWA']);
fputcsv($output, ['Nama Lembaga', $school_info['school_name'] ?? 'SMA Bina Bangsa Nusantara']);
fputcsv($output, ['Periode Filter', ($months[$filter_month] ?? 'Semua Bulan') . ' ' . $filter_year]);
fputcsv($output, ['Filter Status', !empty($filter_status) ? ucfirst(str_replace('_', ' ', $filter_status)) : 'Semua Status']);
fputcsv($output, ['Filter Kelas', $filter_class > 0 ? "ID Kelas {$filter_class}" : 'Semua Kelas']);
fputcsv($output, ['Tanggal Unduh', date('d/m/Y H:i:s') . ' WIB']);
fputcsv($output, ['Petugas / Bendahara', $_SESSION['user_name'] ?? 'Staf']);
fputcsv($output, []); // Baris Kosong

// Baris Judul Kolom
fputcsv($output, [
    'No',
    'Nama Lengkap Siswa',
    'NISN',
    'Kelas / Rombel',
    'Judul Tagihan',
    'Periode Tagihan',
    'Nominal Tagihan (Rp)',
    'Jatuh Tempo',
    'Status Pembayaran',
    'Metode Pembayaran',
    'Tanggal Bayar',
    'Petugas Verifikasi'
]);

$no = 1;
$tot_billed = 0;
$tot_collected = 0;
$tot_pending = 0;

foreach ($bills as $b) {
    $amt = (float)$b['amount'];
    $tot_billed += $amt;

    if ($b['status'] === 'lunas') {
        $tot_collected += $amt;
        $status_label = 'Lunas';
    } elseif ($b['status'] === 'menunggu_verifikasi') {
        $tot_pending += $amt;
        $status_label = 'Menunggu Verifikasi';
    } else {
        $tot_pending += $amt;
        $status_label = 'Belum Lunas';
    }

    fputcsv($output, [
        $no++,
        $b['student_name'],
        "'" . ($b['nisn'] ?: '-'),
        $b['class_name'] ?? 'Reguler',
        $b['title'],
        $b['month_period'] ?: '-',
        $amt,
        date('d/m/Y', strtotime($b['due_date'])),
        $status_label,
        !empty($b['payment_method']) ? strtoupper(str_replace('_', ' ', $b['payment_method'])) : '-',
        !empty($b['payment_date']) ? date('d/m/Y', strtotime($b['payment_date'])) : '-',
        $b['verifier_name'] ?? '-'
    ]);
}

// Baris Ringkasan Akumulasi
fputcsv($output, []);
$rate = $tot_billed > 0 ? round(($tot_collected / $tot_billed) * 100, 1) : 0.0;

fputcsv($output, ['RINGKASAN REKAPITULASI KEUANGAN']);
fputcsv($output, ['Total Tagihan Diterbitkan', 'Rp ' . number_format($tot_billed, 0, ',', '.')]);
fputcsv($output, ['Total Kas Masuk Terverifikasi', 'Rp ' . number_format($tot_collected, 0, ',', '.')]);
fputcsv($output, ['Total Piutang / Belum Lunas', 'Rp ' . number_format($tot_pending, 0, ',', '.')]);
fputcsv($output, ['Rasio Kolektibilitas Kas', number_format($rate, 1) . '%']);

fclose($output);
exit;
