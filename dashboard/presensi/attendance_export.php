<?php
/**
 * Export Rekapitulasi Presensi Siswa ke Format Excel / CSV
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

// Query rekap presensi per siswa
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
        COUNT(sa.id) as total_records
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
$rows = $stmt->fetchAll();

// Siapkan nama berkas
$class_label = $selected_class > 0 ? "Kelas_" . $selected_class : "Semua_Kelas";
$filename = "Rekap_Presensi_{$month_name}_{$selected_year}_{$class_label}_" . date('Ymd_His') . ".csv";

// Header download CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM untuk kompatibilitas penuh Microsoft Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Metadata Laporan
fputcsv($output, ['LAPORAN REKAPITULASI PRESENSI SISWA']);
fputcsv($output, ['Nama Lembaga', $school_info['school_name'] ?? 'Sekolah Menengah Terpadu']);
fputcsv($output, ['Alamat', $school_info['school_address'] ?? '-']);
fputcsv($output, ['Periode', "Bulan {$month_name} {$selected_year}"]);
fputcsv($output, ['Filter Rombel', $selected_class > 0 ? "ID Kelas {$selected_class}" : "Semua Kelas"]);
fputcsv($output, ['Tanggal Unduh', date('d/m/Y H:i:s') . ' WIB']);
fputcsv($output, ['Petugas Ekspor', $_SESSION['user_name'] ?? 'Staf']);
fputcsv($output, []); // Baris kosong

// Header Kolom Tabel
fputcsv($output, [
    'No',
    'Nama Lengkap Siswa',
    'NISN',
    'L/P',
    'Kelas / Rombel',
    'Total Hadir (H)',
    'Total Sakit (S)',
    'Total Izin (I)',
    'Total Alpa (A)',
    'Total Catatan Hari',
    'Persentase Kehadiran (%)',
    'Status Keterangan'
]);

// Baris Data Siswa
$no = 1;
$sum_hadir = 0;
$sum_sakit = 0;
$sum_izin  = 0;
$sum_alpa  = 0;

foreach ($rows as $r) {
    $hadir = (int)$r['total_hadir'];
    $sakit = (int)$r['total_sakit'];
    $izin  = (int)$r['total_izin'];
    $alpa  = (int)$r['total_alpa'];
    $tot   = (int)$r['total_records'];

    $sum_hadir += $hadir;
    $sum_sakit += $sakit;
    $sum_izin  += $izin;
    $sum_alpa  += $alpa;

    $pct = $tot > 0 ? round(($hadir / $tot) * 100, 1) : 100.0;
    $status_ket = ($pct >= 85) ? 'Sangat Tertib' : (($pct >= 75) ? 'Cukup Tertib' : 'Perlu Pembinaan');

    fputcsv($output, [
        $no++,
        $r['student_name'],
        "'" . ($r['nisn'] ?: '-'), // Tambahkan petik agar NISN tidak dianggap format scientific oleh Excel
        $r['gender'] ?? 'L',
        $r['class_name'] ?? 'Reguler',
        $hadir,
        $sakit,
        $izin,
        $alpa,
        $tot,
        number_format($pct, 1) . '%',
        $status_ket
    ]);
}

// Baris Total Akumulasi
fputcsv($output, []);
$total_all = $sum_hadir + $sum_sakit + $sum_izin + $sum_alpa;
$avg_pct = $total_all > 0 ? round(($sum_hadir / $total_all) * 100, 1) : 100.0;

fputcsv($output, [
    'TOTAL AKUMULASI',
    count($rows) . ' Siswa',
    '-',
    '-',
    '-',
    $sum_hadir,
    $sum_sakit,
    $sum_izin,
    $sum_alpa,
    $total_all,
    number_format($avg_pct, 1) . '%',
    '-'
]);

fclose($output);
exit;
