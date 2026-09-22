<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();
requireRole(['guru', 'administrator', 'staf']);

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';

$exam_id = (int) ($_GET['id'] ?? 0);
if ($exam_id <= 0) {
    header("Location: exams.php");
    exit;
}

// Ambil info ujian
$stmt_e = $pdo->prepare("
    SELECT e.*, u.name as teacher_name 
    FROM exams e 
    JOIN users u ON e.teacher_id = u.id 
    WHERE e.id = ?
");
$stmt_e->execute([$exam_id]);
$exam = $stmt_e->fetch();

if (!$exam) {
    die("Ujian tidak ditemukan.");
}

// Jika guru, pastikan hanya mengekspor ujian buatannya (kecuali admin/staf)
if ($user_role === 'guru' && (int)$exam['teacher_id'] !== $user_id) {
    die("Akses ditolak. Anda hanya dapat mengekspor nilai ujian yang Anda ampu.");
}

// Ambil seluruh data submission siswa
$stmt_all = $pdo->prepare("
    SELECT es.*, u.name as student_name, u.email as student_email 
    FROM exam_submissions es 
    JOIN users u ON es.student_id = u.id 
    WHERE es.exam_id = ? 
    ORDER BY es.score DESC, es.submitted_at ASC
");
$stmt_all->execute([$exam_id]);
$submissions = $stmt_all->fetchAll();

// Siapkan nama file CSV
$clean_title = preg_replace('/[^A-Za-z0-9_\-]/', '_', $exam['title']);
$filename = "Rekap_Nilai_" . substr($clean_title, 0, 30) . "_" . date('Ymd_His') . ".csv";

// Kirim header file download CSV
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Tulis UTF-8 BOM agar terbaca sempurna di Microsoft Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Baris Metadata Header
fputcsv($output, ['REKAPITULASI NILAI UJIAN / LATIHAN SEKOLAH']);
fputcsv($output, ['Judul Paket', $exam['title']]);
fputcsv($output, ['Mata Pelajaran', $exam['subject']]);
fputcsv($output, ['Kategori', getExamCategoryLabel($exam['category'])]);
fputcsv($output, ['Guru Pengampu', $exam['teacher_name']]);
fputcsv($output, ['Standar KKM', $exam['passing_grade']]);
fputcsv($output, ['Tanggal Ekspor', date('d/m/Y H:i:s') . ' WIB']);
fputcsv($output, []); // Baris Kosong

// Ambil info apakah ada soal esai
$stmt_q_cnt = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE exam_id = ? AND question_type = 'essay'");
$stmt_q_cnt->execute([$exam_id]);
$has_essay = ((int)$stmt_q_cnt->fetchColumn()) > 0;

// Baris Judul Kolom Tabel
$csv_headers = [
    'No',
    'Nama Siswa',
    'Email Siswa',
    'Jawaban Benar (PG)',
    'Total Soal',
    'Nilai Akhir',
    'Standar KKM',
    'Status KKM',
    'Nilai Awal (Sebelum Remedial)',
    'Status Sesi',
];
if ($has_essay) {
    $csv_headers[] = 'Status Koreksi Esai';
}
$csv_headers[] = 'Waktu Pengumpulan';

fputcsv($output, $csv_headers);

// Baris Data Siswa
$no = 1;
foreach ($submissions as $sub) {
    $score_val = (float) $sub['score'];
    $is_pass = ($score_val >= $exam['passing_grade']);
    $is_remedial = (!empty($sub['is_remedial']) && (int)$sub['is_remedial'] === 1);
    
    $prev_score_str = '-';
    if ($is_remedial && $sub['previous_score'] !== null) {
        $prev_score_str = number_format((float)$sub['previous_score'], 1);
    }

    $row = [
        $no++,
        $sub['student_name'],
        $sub['student_email'],
        $sub['total_correct'],
        $sub['total_questions'],
        number_format($score_val, 1),
        $exam['passing_grade'],
        $is_pass ? 'Lulus KKM' : 'Belum Tuntas (Perlu Remedial)',
        $prev_score_str,
        $is_remedial ? 'Hasil Remedial' : 'Ujian Pertama',
    ];

    if ($has_essay) {
        $row[] = (!empty($sub['essay_graded']) && (int)$sub['essay_graded'] === 1) ? 'Sudah Dikoreksi' : 'Menunggu Koreksi Guru';
    }

    $row[] = date('d/m/Y H:i', strtotime($sub['submitted_at']));

    fputcsv($output, $row);
}

fclose($output);
exit;
