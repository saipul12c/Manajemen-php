<?php
/**
 * Cetak Laporan Resmi Leger Nilai Siswa (PDF / Print View)
 * Format Dokumen Resmi Kurikulum & Kearsipan
 * Manajemen-PHP
 */
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();
requireRole(['guru', 'administrator']);

$school_info = getSchoolSettings($pdo);
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

$class_title_str = "Semua Siswa Terdaftar";
if ($selected_class_id > 0) {
    $stmt_c = $pdo->prepare("SELECT name, grade_level FROM classes WHERE id = ?");
    $stmt_c->execute([$selected_class_id]);
    $c_row = $stmt_c->fetch();
    if ($c_row) {
        $class_title_str = "Kelas " . $c_row['name'] . " (Tingkat " . $c_row['grade_level'] . ")";
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

$gradebook_data = [];
$sum_final = 0;
$count_pass = 0;
$max_grade = 0;
$min_grade = 100;

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

    if ($final_grade > $max_grade) $max_grade = $final_grade;
    if ($final_grade < $min_grade) $min_grade = $final_grade;

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

    $gradebook_data[] = [
        'student'      => $stu,
        'attendance'   => $att_score,
        'assignments'  => $as_score,
        'daily_exams'  => $uh_score,
        'uts'          => $uts_score,
        'ukk'          => $ukk_score,
        'final_grade'  => $final_grade,
        'predikat'     => $predikat,
        'status_pass'  => $status_pass
    ];
}

$total_stu = count($students);
$avg_grade = $total_stu > 0 ? round($sum_final / $total_stu, 1) : 0.0;
$pass_pct  = $total_stu > 0 ? round(($count_pass / $total_stu) * 100, 1) : 0.0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leger Nilai - <?= htmlspecialchars($class_title_str) ?> - <?= htmlspecialchars($school_info['school_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <style>
        @page {
            size: A4 landscape;
            margin: 12mm 15mm;
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
            line-height: 1.35;
            font-size: 10pt;
            padding: 20px;
        }
        .page-container {
            max-width: 1050px;
            margin: 0 auto;
            background: #ffffff;
            padding: 35px 45px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            border-radius: 8px;
        }
        .toolbar {
            max-width: 1050px;
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

        /* Kop Surat */
        .kop-surat {
            display: flex;
            align-items: center;
            border-bottom: 3px double #000;
            padding-bottom: 12px;
            margin-bottom: 18px;
            text-align: center;
        }
        .kop-logo {
            width: 65px;
            height: 65px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            border-radius: 10px;
            background: #f1f5f9;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .kop-text { flex-grow: 1; }
        .kop-text h2 {
            font-size: 10.5pt;
            font-weight: normal;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .kop-text h1 {
            font-size: 15pt;
            font-weight: bold;
            text-transform: uppercase;
            margin: 2px 0;
        }
        .kop-text p {
            font-size: 8.5pt;
            color: #333;
        }

        .doc-title {
            text-align: center;
            margin-bottom: 18px;
        }
        .doc-title h3 {
            font-size: 13pt;
            font-weight: bold;
            text-decoration: underline;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .doc-title p {
            font-size: 9.5pt;
            margin-top: 3px;
            font-style: italic;
        }

        .meta-table {
            width: 100%;
            margin-bottom: 15px;
            font-size: 9.5pt;
        }
        .meta-table td { padding: 2px 0; vertical-align: top; }

        .leger-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
            margin-bottom: 18px;
        }
        .leger-table th, .leger-table td {
            border: 1px solid #000;
            padding: 5px 6px;
        }
        .leger-table th {
            background-color: #f1f5f9;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            font-size: 8pt;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }

        .summary-box {
            border: 1px dashed #000;
            padding: 8px 12px;
            margin-bottom: 20px;
            font-size: 9pt;
            background: #fafafa;
        }

        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 25px;
            page-break-inside: avoid;
        }
        .sig-block {
            width: 250px;
            text-align: center;
            font-size: 9.5pt;
        }
        .sig-space { height: 60px; }

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
            .toolbar { display: none !important; }
            .leger-table th {
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
            <a href="gradebook.php?class_id=<?= $selected_class_id ?>" class="btn-back">
                ← Kembali ke Buku Nilai
            </a>
            <span style="opacity: 0.6;">|</span>
            <span>Dokumen Leger Nilai Hasil Belajar</span>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="gradebook_export.php?class_id=<?= $selected_class_id ?>" class="btn-excel">
                <i class="fa-solid fa-file-excel"></i> Unduh Excel (.csv)
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
            <h3>LEGER REKAPITULASI NILAI HASIL BELAJAR SISWA</h3>
            <p>Bobot: Presensi (10%) + Tugas (20%) + UH (20%) + UTS (25%) + UKK (25%) | Standar KKM: 75</p>
        </div>

        <!-- Metadata -->
        <table class="meta-table">
            <tr>
                <td style="width: 15%;">Tahun Ajaran</td>
                <td style="width: 35%;">: <strong><?= htmlspecialchars($school_info['academic_year'] ?? '2026/2027 Ganjil') ?></strong></td>
                <td style="width: 15%;">Rombel / Kelas</td>
                <td style="width: 35%;">: <strong><?= htmlspecialchars($class_title_str) ?></strong></td>
            </tr>
            <tr>
                <td>Tanggal Cetak</td>
                <td>: <?= date('d F Y') ?></td>
                <td>Total Peserta Didik</td>
                <td>: <strong><?= $total_stu ?> Siswa</strong></td>
            </tr>
        </table>

        <!-- Tabel Leger -->
        <table class="leger-table">
            <thead>
                <tr>
                    <th style="width: 4%;">No</th>
                    <th style="width: 25%;">Nama Siswa</th>
                    <th style="width: 12%;">NISN</th>
                    <th style="width: 10%;">Kelas</th>
                    <th style="width: 7%;">Presensi</th>
                    <th style="width: 7%;">Tugas</th>
                    <th style="width: 7%;">UH</th>
                    <th style="width: 7%;">UTS</th>
                    <th style="width: 7%;">UKK</th>
                    <th style="width: 8%;">Nilai Akhir</th>
                    <th style="width: 6%;">Predikat</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($gradebook_data)): ?>
                    <tr>
                        <td colspan="11" class="text-center" style="padding: 20px;">
                            Tidak ada data siswa pada kelas ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($gradebook_data as $idx => $row): 
                        $stu = $row['student'];
                        $final = $row['final_grade'];
                    ?>
                        <tr>
                            <td class="text-center"><?= $idx + 1 ?></td>
                            <td><strong><?= htmlspecialchars($stu['name']) ?></strong></td>
                            <td class="text-center" style="font-family: monospace; font-size: 8pt;"><?= htmlspecialchars($stu['nisn'] ?: '-') ?></td>
                            <td class="text-center"><?= htmlspecialchars($stu['class_name'] ?? 'Reguler') ?></td>
                            <td class="text-center"><?= number_format($row['attendance'], 1) ?></td>
                            <td class="text-center"><?= number_format($row['assignments'], 1) ?></td>
                            <td class="text-center"><?= number_format($row['daily_exams'], 1) ?></td>
                            <td class="text-center"><?= number_format($row['uts'], 1) ?></td>
                            <td class="text-center"><?= number_format($row['ukk'], 1) ?></td>
                            <td class="text-center font-bold" style="<?= $final >= 75 ? 'color:#15803d;' : 'color:#b91c1c;' ?>">
                                <?= number_format($final, 1) ?>
                            </td>
                            <td class="text-center font-bold"><?= $row['predikat'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Ringkasan Statistik Kelas -->
        <div class="summary-box">
            <strong>Analisis Ketuntasan Belajar:</strong>
            <span style="margin-left: 15px;">Rata-rata Nilai: <strong><?= number_format($avg_grade, 1) ?></strong></span>
            <span style="margin-left: 15px;">Nilai Tertinggi: <strong><?= number_format($max_grade, 1) ?></strong></span>
            <span style="margin-left: 15px;">Nilai Terendah: <strong><?= number_format($min_grade == 100 ? 0 : $min_grade, 1) ?></strong></span>
            <span style="margin-left: 15px;">Ketuntasan KKM: <strong><?= $count_pass ?> dari <?= $total_stu ?> Siswa (<?= $pass_pct ?>%)</strong></span>
        </div>

        <!-- Tanda Tangan -->
        <div class="signature-section">
            <div class="sig-block">
                <p>Wali Kelas / Guru Pengampu,</p>
                <div class="sig-space"></div>
                <p><strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Dewi Lestari, M.Pd') ?></strong></p>
                <p style="font-size: 8.5pt; color: #444;">NIP: 19820315 200801 2 014</p>
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
