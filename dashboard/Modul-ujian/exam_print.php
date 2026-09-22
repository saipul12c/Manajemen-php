<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';

// Hanya guru, admin, dan staf yang diizinkan mencetak Berita Acara & Daftar Nilai resmi
if (!in_array($user_role, ['guru', 'administrator', 'staf'], true)) {
    header("Location: exams.php?error=unauthorized");
    exit;
}

$exam_id = (int) ($_GET['id'] ?? 0);
if ($exam_id <= 0) {
    header("Location: exams.php");
    exit;
}

// Ambil info ujian
$stmt_e = $pdo->prepare("
    SELECT e.*, u.name as teacher_name, u.email as teacher_email 
    FROM exams e 
    JOIN users u ON e.teacher_id = u.id 
    WHERE e.id = ?
");
$stmt_e->execute([$exam_id]);
$exam = $stmt_e->fetch();

if (!$exam) {
    header("Location: exams.php?error=notfound");
    exit;
}

// Ambil butir soal
$stmt_q = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY id ASC");
$stmt_q->execute([$exam_id]);
$questions = $stmt_q->fetchAll();

$total_questions = count($questions);
$essay_count = count(array_filter($questions, fn($q) => ($q['question_type'] ?? 'multiple_choice') === 'essay'));
$pg_count = $total_questions - $essay_count;

// Ambil data seluruh siswa yang mengerjakan
$stmt_sub = $pdo->prepare("
    SELECT es.*, u.name as student_name, u.email as student_email 
    FROM exam_submissions es 
    JOIN users u ON es.student_id = u.id 
    WHERE es.exam_id = ? 
    ORDER BY u.name ASC
");
$stmt_sub->execute([$exam_id]);
$submissions = $stmt_sub->fetchAll();

$total_participants = count($submissions);
$pass_count = 0;
$fail_count = 0;
$total_score_sum = 0;
$highest_score = 0;
$lowest_score = 100;

if ($total_participants > 0) {
    foreach ($submissions as $s) {
        $sc = (float) $s['score'];
        $total_score_sum += $sc;
        if ($sc > $highest_score) $highest_score = $sc;
        if ($sc < $lowest_score) $lowest_score = $sc;
        if ($sc >= $exam['passing_grade']) {
            $pass_count++;
        } else {
            $fail_count++;
        }
    }
    $avg_score = $total_score_sum / $total_participants;
} else {
    $lowest_score = 0;
    $avg_score = 0;
}

$pass_percentage = ($total_participants > 0) ? round(($pass_count / $total_participants) * 100, 1) : 0;

// Format Tanggal Indonesia
function tgl_indo($timestamp) {
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    $w_day = $hari[date('w', $timestamp)];
    $day = date('j', $timestamp);
    $month = $bulan[(int)date('n', $timestamp)];
    $year = date('Y', $timestamp);

    return [
        'hari' => $w_day,
        'tanggal' => "$day $month $year",
        'lengkap' => "$w_day, $day $month $year"
    ];
}

$exam_date_ts = !empty($exam['start_time']) ? strtotime($exam['start_time']) : (!empty($exam['created_at']) ? strtotime($exam['created_at']) : time());
$info_tgl_ujian = tgl_indo($exam_date_ts);
$info_tgl_cetak = tgl_indo(time());

$cat_label = EXAM_CATEGORIES[$exam['category']]['label'] ?? strtoupper($exam['category']);
$doc_number = sprintf("421.5 / %03d / BA-CBT / %s / %s", $exam['id'], date('m'), date('Y'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Berita Acara & Nilai - <?= htmlspecialchars($exam['title']) ?></title>
    <style>
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
        
        /* Floating Toolbar (Screen only) */
        .toolbar {
            max-width: 820px;
            margin: 0 auto 16px auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #1e293b;
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

        /* Kop Surat Resmi */
        .kop-surat {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            padding-bottom: 12px;
            border-bottom: 3px double #000000;
            margin-bottom: 20px;
            text-align: center;
        }
        .kop-logo {
            width: 75px;
            height: 75px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #0f172a;
            border-radius: 14px;
            font-size: 32px;
            font-weight: bold;
            color: #1e3a8a;
            background: #f8fafc;
        }
        .kop-header h4 {
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .kop-header h2 {
            font-size: 15pt;
            font-weight: 900;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin: 2px 0;
            color: #0f172a;
        }
        .kop-header p {
            font-size: 9pt;
            color: #374151;
        }

        /* Judul Dokumen */
        .doc-title {
            text-align: center;
            margin-bottom: 22px;
        }
        .doc-title h3 {
            font-size: 13pt;
            font-weight: bold;
            text-transform: uppercase;
            text-decoration: underline;
            letter-spacing: 0.5px;
        }
        .doc-title .doc-num {
            font-size: 10pt;
            margin-top: 2px;
            font-weight: bold;
            color: #334155;
        }

        /* Informasi Metadata Ujian */
        .meta-table {
            width: 100%;
            margin-bottom: 16px;
            border-collapse: collapse;
            font-size: 10.5pt;
        }
        .meta-table td {
            padding: 3px 0;
            vertical-align: top;
        }
        .meta-table td.label {
            width: 28%;
            color: #1f2937;
        }
        .meta-table td.colon {
            width: 3%;
            text-align: center;
        }
        .meta-table td.value {
            width: 69%;
            font-weight: bold;
            color: #000000;
        }

        /* Teks Berita Acara */
        .ba-narrative {
            text-align: justify;
            margin-bottom: 18px;
            font-size: 10.5pt;
            line-height: 1.5;
            text-indent: 28px;
        }

        /* Tabel Rekap Nilai */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
            margin-bottom: 16px;
            font-size: 10pt;
        }
        .data-table th, .data-table td {
            border: 1px solid #000000;
            padding: 6px 8px;
        }
        .data-table th {
            background-color: #f1f5f9;
            text-align: center;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9pt;
        }
        .data-table td.center {
            text-align: center;
        }
        .data-table td.bold {
            font-weight: bold;
        }
        .status-lulus {
            font-weight: bold;
            color: #047857;
        }
        .status-remedial {
            font-weight: bold;
            color: #b91c1c;
        }

        /* Ringkasan Statistik */
        .summary-box {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 24px;
            padding: 10px 14px;
            border: 1px dashed #475569;
            background: #fafafa;
            font-size: 9.5pt;
        }
        .summary-box .item-title {
            color: #64748b;
            font-size: 8.5pt;
            text-transform: uppercase;
            font-weight: bold;
        }
        .summary-box .item-val {
            font-size: 12pt;
            font-weight: bold;
            color: #0f172a;
            margin-top: 2px;
        }

        /* Tanda Tangan */
        .signatures {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            page-break-inside: avoid;
            font-size: 10.5pt;
        }
        .sign-box {
            width: 44%;
            text-align: center;
        }
        .sign-space {
            height: 70px;
        }
        .sign-name {
            font-weight: bold;
            text-decoration: underline;
        }
        .sign-nip {
            font-size: 9.5pt;
            color: #374151;
        }

        /* Print Media Styles */
        @media print {
            body {
                background: #ffffff;
                padding: 0;
            }
            .page-container {
                box-shadow: none;
                padding: 0;
                max-width: 100%;
                border-radius: 0;
            }
            .toolbar {
                display: none !important;
            }
            @page {
                size: A4 portrait;
                margin: 20mm 15mm 20mm 15mm;
            }
            .data-table th {
                background-color: #f3f4f6 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

    <!-- Toolbar Navigasi Aksi (Hanya tampil di layar monitor) -->
    <div class="toolbar">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span>📄</span>
            <strong>Mode Cetak Berita Acara & Daftar Nilai</strong>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <a href="exam_results.php?id=<?= $exam['id'] ?>" class="btn-back">
                ← Kembali ke Rekap
            </a>
            <button onclick="window.print()" class="btn-print">
                🖨️ Cetak Dokumen / Simpan PDF
            </button>
        </div>
    </div>

    <div class="page-container">

        <!-- KOP SURAT RESMI -->
        <div class="kop-surat">
            <div class="kop-logo">
                🎓
            </div>
            <div class="kop-header">
                <h4>PEMERINTAH DAERAH PROVINSI PENDIDIKAN & KEBUDAYAAN</h4>
                <h2>SISTEM MANAJEMEN AKADEMIK & CBT TERPADU</h2>
                <p>Jl. Pendidikan Raya No. 45, Kompleks Pusat Pendidikan Modern | Telp. (021) 7890-1234</p>
                <p>Website: https://manajemen-php.local | Email: cbt-evaluasi@sekolah.sch.id</p>
            </div>
        </div>

        <!-- JUDUL DOKUMEN -->
        <div class="doc-title">
            <h3>BERITA ACARA & DAFTAR NILAI EVALUASI PESERTA DIDIK</h3>
            <div class="doc-num">Nomor: <?= htmlspecialchars($doc_number) ?></div>
        </div>

        <!-- NARASI BERITA ACARA -->
        <p class="ba-narrative">
            Pada hari ini, <strong><?= $info_tgl_ujian['hari'] ?></strong> tanggal <strong><?= $info_tgl_ujian['tanggal'] ?></strong>, telah diselenggarakan pelaksanaan evaluasi akademik berbasis Computer-Based Test (CBT) dengan data identitas modul dan rekapitulasi kehadiran sebagai berikut:
        </p>

        <!-- IDENTITAS PELAKSANAAN UJIAN -->
        <table class="meta-table">
            <tr>
                <td class="label">Mata Pelajaran</td>
                <td class="colon">:</td>
                <td class="value"><?= htmlspecialchars($exam['subject']) ?></td>
            </tr>
            <tr>
                <td class="label">Nama Modul Evaluasi</td>
                <td class="colon">:</td>
                <td class="value"><?= htmlspecialchars($exam['title']) ?> (<?= htmlspecialchars($cat_label) ?>)</td>
            </tr>
            <tr>
                <td class="label">Guru Pengampu / Pengawas</td>
                <td class="colon">:</td>
                <td class="value"><?= htmlspecialchars($exam['teacher_name']) ?></td>
            </tr>
            <tr>
                <td class="label">Alokasi Waktu Pengerjaan</td>
                <td class="colon">:</td>
                <td class="value"><?= $exam['duration_minutes'] > 0 ? (int)$exam['duration_minutes'] . ' Menit' : 'Fleksibel (Tanpa Batas Menit)' ?></td>
            </tr>
            <tr>
                <td class="label">Komposisi Butir Soal</td>
                <td class="colon">:</td>
                <td class="value">
                    Total <strong><?= $total_questions ?> Butir</strong> 
                    (<?= $pg_count ?> Pilihan Ganda<?= $essay_count > 0 ? ", $essay_count Esai/Uraian" : '' ?>)
                </td>
            </tr>
            <tr>
                <td class="label">Standar Ketuntasan (KKM)</td>
                <td class="colon">:</td>
                <td class="value"><?= $exam['passing_grade'] ?> / 100</td>
            </tr>
            <tr>
                <td class="label">Kehadiran Peserta Ujian</td>
                <td class="colon">:</td>
                <td class="value">
                    Hadir & Menyelesaikan: <strong><?= $total_participants ?> Siswa</strong> | 
                    Lulus KKM: <strong><?= $pass_count ?> Siswa (<?= $pass_percentage ?>%)</strong>
                </td>
            </tr>
            <tr>
                <td class="label">Catatan Pelaksanaan Ujian</td>
                <td class="colon">:</td>
                <td class="value" style="font-weight: normal; font-style: italic;">
                    Ujian berlangsung secara tertib, aman, dan terkendali dengan pengawasan integritas sistem CBT. Seluruh jawaban tersimpan otomatis di database sekolah.
                </td>
            </tr>
        </table>

        <!-- RINGKASAN STATISTIK KELAS -->
        <div class="summary-box">
            <div>
                <div class="item-title">Total Peserta</div>
                <div class="item-val"><?= $total_participants ?> Siswa</div>
            </div>
            <div>
                <div class="item-title">Rata-Rata Kelas</div>
                <div class="item-val"><?= number_format($avg_score, 1) ?></div>
            </div>
            <div>
                <div class="item-title">Nilai Tertinggi</div>
                <div class="item-val"><?= number_format($highest_score, 0) ?></div>
            </div>
            <div>
                <div class="item-title">Persentase Ketuntasan</div>
                <div class="item-val"><?= $pass_percentage ?>%</div>
            </div>
        </div>

        <!-- TABEL REKAPITULASI DAFTAR NILAI -->
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 32%;">Nama Lengkap Peserta Didik</th>
                    <th style="width: 25%;">Akun / Email</th>
                    <th style="width: 12%;">Benar (PG)</th>
                    <th style="width: 10%;">Skor Akhir</th>
                    <th style="width: 16%;">Status KKM</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                    <tr>
                        <td colspan="6" class="center" style="padding: 20px; font-style: italic; color: #64748b;">
                            Belum ada lembar jawaban yang terkumpul untuk sesi ujian ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $idx => $sub): ?>
                        <?php 
                            $sc = (float) $sub['score'];
                            $is_pass = ($sc >= $exam['passing_grade']);
                            $is_remed = !empty($sub['is_remedial']) && (int)$sub['is_remedial'] === 1;
                        ?>
                        <tr>
                            <td class="center"><?= $idx + 1 ?></td>
                            <td class="bold">
                                <?= htmlspecialchars($sub['student_name']) ?>
                                <?php if ($is_remed): ?>
                                    <span style="font-size: 8pt; font-weight: normal; font-style: italic;">(Remedial)</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 9pt; color: #374151;"><?= htmlspecialchars($sub['student_email']) ?></td>
                            <td class="center"><?= $sub['total_correct'] ?> / <?= $sub['total_questions'] ?></td>
                            <td class="center bold" style="font-size: 11pt;"><?= number_format($sc, 0) ?></td>
                            <td class="center <?= $is_pass ? 'status-lulus' : 'status-remedial' ?>">
                                <?= $is_pass ? 'TUNTAS / LULUS' : 'REMEDIAL' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- LEMBAR TANDA TANGAN & PENGESAHAN -->
        <div class="signatures">
            <div class="sign-box">
                <p>Mengetahui,</p>
                <p>Wakil Kepala Sekolah Bidang Kurikulum</p>
                <div class="sign-space"></div>
                <p class="sign-name">Dr. H. Muhammad Arifin, M.Pd.</p>
                <p class="sign-nip">NIP. 19780512 200312 1 004</p>
            </div>

            <div class="sign-box">
                <p>Ditetapkan di: Jakarta</p>
                <p>Pada Tanggal: <?= $info_tgl_cetak['tanggal'] ?></p>
                <p style="margin-top: 2px;">Guru Pengampu / Pengawas Ujian,</p>
                <div class="sign-space"></div>
                <p class="sign-name"><?= htmlspecialchars($exam['teacher_name']) ?></p>
                <p class="sign-nip"><?= !empty($exam['teacher_email']) ? htmlspecialchars($exam['teacher_email']) : 'NIP. - / Akun Resmi' ?></p>
            </div>
        </div>

    </div>

</body>
</html>
