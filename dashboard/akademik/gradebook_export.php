<?php
/**
 * Export Buku Rekap Nilai Akademik (Leger Nilai) ke Format Excel / CSV
 * Manajemen-PHP
 */
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();
requireRole(['guru', 'administrator']);

$school_info = getSchoolSettings($pdo);
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

// Ambil info nama kelas jika ada filter
$class_name_str = "Semua_Kelas";
$class_title_str = "Semua Siswa Terdaftar";
if ($selected_class_id > 0) {
    $stmt_c = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt_c->execute([$selected_class_id]);
    $c_row = $stmt_c->fetch();
    if ($c_row) {
        $class_name_str = "Kelas_" . preg_replace('/[^A-Za-z0-9_\-]/', '_', $c_row['name']);
        $class_title_str = "Kelas " . $c_row['name'];
    }
}

// Query siswa
$sql_students = "SELECT u.id, u.name, u.email, u.nisn, u.gender, c.name as class_name 
                 FROM users u 
                 LEFT JOIN classes c ON u.class_id = c.id
                 WHERE u.role = 'siswa'";
$params_students = [];
if ($selected_class_id > 0) {
    $sql_students .= " AND u.class_id = ?";
    $params_students[] = $selected_class_id;
}
$sql_students .= " ORDER BY u.name ASC";
$stmt_students = $pdo->prepare($sql_students);
$stmt_students->execute($params_students);
$students = $stmt_students->fetchAll();

// Siapkan nama file download
$filename = "Leger_Nilai_{$class_name_str}_" . date('Ymd_His') . ".csv";

// Header download CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM untuk kompatibilitas penuh Microsoft Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Metadata Dokumen
fputcsv($output, ['LEGER BUKU REKAPITULASI NILAI HASIL BELAJAR SISWA']);
fputcsv($output, ['Nama Lembaga', $school_info['school_name'] ?? 'SMA Bina Bangsa Nusantara']);
fputcsv($output, ['Tahun Ajaran', $school_info['academic_year'] ?? '2026/2027 Ganjil']);
fputcsv($output, ['Rombel / Kelas', $class_title_str]);
fputcsv($output, ['Rumus Bobot', 'Presensi (10%) + Tugas (20%) + Ulangan Harian (20%) + UTS (25%) + UKK (25%)']);
fputcsv($output, ['Tanggal Unduh', date('d/m/Y H:i:s') . ' WIB']);
fputcsv($output, ['Wali Kelas / Pengunduh', $_SESSION['user_name'] ?? 'Guru']);
fputcsv($output, []); // Baris Kosong

// Baris Judul Kolom
fputcsv($output, [
    'No',
    'Nama Lengkap Siswa',
    'NISN',
    'L/P',
    'Kelas / Rombel',
    'Presensi (10%)',
    'Rata-rata Tugas (20%)',
    'Ulangan Harian (20%)',
    'Nilai UTS (25%)',
    'Nilai UKK (25%)',
    'Nilai Akhir (Skala 100)',
    'Predikat',
    'Status Kelulusan'
]);

$no = 1;
$sum_final = 0;
$count_pass = 0;

foreach ($students as $stu) {
    $s_id = $stu['id'];

    // 1. Presensi
    $stmt_att = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir,
            COUNT(*) as total
        FROM student_attendance
        WHERE student_id = ?
    ");
    $stmt_att->execute([$s_id]);
    $att_row = $stmt_att->fetch();
    $att_total = (int)($att_row['total'] ?? 0);
    $att_hadir = (int)($att_row['hadir'] ?? 0);
    $att_score = ($att_total > 0) ? round(($att_hadir / $att_total) * 100, 1) : 100.0;

    // 2. Tugas
    $stmt_as = $pdo->prepare("
        SELECT AVG(score) as avg_score 
        FROM assignment_submissions 
        WHERE student_id = ? AND status = 'selesai' AND score IS NOT NULL
    ");
    $stmt_as->execute([$s_id]);
    $as_row = $stmt_as->fetch();
    $as_score = $as_row['avg_score'] !== null ? round((float)$as_row['avg_score'], 1) : 0.0;

    // 3. UH
    $stmt_uh = $pdo->prepare("
        SELECT AVG(s.score) as avg_score 
        FROM exam_submissions s
        JOIN exams e ON s.exam_id = e.id
        WHERE s.student_id = ? AND e.category = 'ujian_harian'
    ");
    $stmt_uh->execute([$s_id]);
    $uh_score = round((float)($stmt_uh->fetchColumn() ?? 0), 1);

    // 4. UTS
    $stmt_uts = $pdo->prepare("
        SELECT s.score 
        FROM exam_submissions s
        JOIN exams e ON s.exam_id = e.id
        WHERE s.student_id = ? AND e.category = 'uts'
        ORDER BY s.id DESC LIMIT 1
    ");
    $stmt_uts->execute([$s_id]);
    $uts_score = round((float)($stmt_uts->fetchColumn() ?? 0), 1);

    // 5. UKK
    $stmt_ukk = $pdo->prepare("
        SELECT s.score 
        FROM exam_submissions s
        JOIN exams e ON s.exam_id = e.id
        WHERE s.student_id = ? AND e.category = 'ukk'
        ORDER BY s.id DESC LIMIT 1
    ");
    $stmt_ukk->execute([$s_id]);
    $ukk_score = round((float)($stmt_ukk->fetchColumn() ?? 0), 1);

    // Nilai Akhir
    $final_grade = ($att_score * 0.10) + ($as_score * 0.20) + ($uh_score * 0.20) + ($uts_score * 0.25) + ($ukk_score * 0.25);
    $final_grade = round($final_grade, 1);
    $sum_final += $final_grade;

    if ($final_grade >= 85) {
        $predikat = 'A';
        $status_pass = 'Lulus Sangat Baik';
        $count_pass++;
    } elseif ($final_grade >= 75) {
        $predikat = 'B';
        $status_pass = 'Lulus Baik';
        $count_pass++;
    } elseif ($final_grade >= 65) {
        $predikat = 'C';
        $status_pass = 'Cukup / Remedial';
    } else {
        $predikat = 'D';
        $status_pass = 'Perlu Pendampingan';
    }

    fputcsv($output, [
        $no++,
        $stu['name'],
        "'" . ($stu['nisn'] ?: '-'),
        $stu['gender'] ?? 'L',
        $stu['class_name'] ?? 'Reguler',
        number_format($att_score, 1),
        number_format($as_score, 1),
        number_format($uh_score, 1),
        number_format($uts_score, 1),
        number_format($ukk_score, 1),
        number_format($final_grade, 1),
        $predikat,
        $status_pass
    ]);
}

// Baris Rangkuman
fputcsv($output, []);
$total_count = count($students);
$avg_class_grade = $total_count > 0 ? round($sum_final / $total_count, 1) : 0.0;
$pass_rate = $total_count > 0 ? round(($count_pass / $total_count) * 100, 1) : 0.0;

fputcsv($output, [
    'RATA-RATA KELAS',
    $total_count . ' Siswa Terdaftar',
    '-',
    '-',
    '-',
    '-',
    '-',
    '-',
    '-',
    '-',
    number_format($avg_class_grade, 1),
    '-',
    "Kelulusan KKM: {$pass_rate}%"
]);

fclose($output);
exit;
