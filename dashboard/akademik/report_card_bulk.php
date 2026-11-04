<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_print = in_array($user_role, ['guru', 'staf', 'administrator'], true);

if (!$can_print) {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

$school_info = getSchoolSettings($pdo);
$academic_year = isset($_GET['academic_year']) && trim($_GET['academic_year']) !== '' 
    ? trim($_GET['academic_year']) 
    : ($school_info['academic_year'] ?? '2026/2027');
$current_semester = isset($_GET['semester']) && trim($_GET['semester']) !== '' 
    ? trim($_GET['semester']) 
    : ($school_info['academic_semester'] ?? 'Ganjil');

$all_classes = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();

$class_id = 0;
if (isset($_GET['class_id']) && is_numeric($_GET['class_id'])) {
    $class_id = (int)$_GET['class_id'];
} elseif ($user_role === 'guru') {
    // Cari kelas binaan guru jika ada
    $stmt_my_c = $pdo->prepare("SELECT id FROM classes WHERE homeroom_teacher_id = ? LIMIT 1");
    $stmt_my_c->execute([$user_id]);
    $class_id = (int)($stmt_my_c->fetchColumn() ?: 0);
}

if ($class_id <= 0 && !empty($all_classes)) {
    $class_id = (int)$all_classes[0]['id'];
}

// Ambil data kelas
$stmt_class = $pdo->prepare("
    SELECT c.*, u.name as teacher_name, u.nip as teacher_nip, u.title_degree as teacher_degree, u.signature_image as teacher_signature_image
    FROM classes c 
    LEFT JOIN users u ON c.homeroom_teacher_id = u.id 
    WHERE c.id = ?
");
$stmt_class->execute([$class_id]);
$class = $stmt_class->fetch();

if (!$class) {
    die("Kelas tidak ditemukan.");
}

// Ambil semua siswa di kelas ini
$stmt_students = $pdo->prepare("
    SELECT id, name, nisn, gender, email 
    FROM users 
    WHERE class_id = ? AND role = 'siswa' 
    ORDER BY name ASC
");
$stmt_students->execute([$class_id]);
$students = $stmt_students->fetchAll();

// Map KKM per mata pelajaran
$subject_kkm_map = [];
try {
    $stmt_kkm = $pdo->query("SELECT name, kkm FROM subjects");
    while ($row_kkm = $stmt_kkm->fetch()) {
        $subject_kkm_map[$row_kkm['name']] = (int)($row_kkm['kkm'] ?: 75);
    }
} catch (Exception $e_kkm) {}

// Ambil daftar mata pelajaran dinamis
$stmt_subjects = $pdo->query("
    SELECT DISTINCT subject FROM (
        SELECT subject FROM assignments
        UNION
        SELECT subject FROM exams
        UNION
        SELECT name as subject FROM subjects
    ) as all_subjects
    ORDER BY subject ASC
");
$dynamic_subjects = $stmt_subjects->fetchAll(PDO::FETCH_COLUMN);
if (empty($dynamic_subjects)) {
    $dynamic_subjects = ['Matematika', 'Bahasa Indonesia', 'Bahasa Inggris', 'Fisika', 'Biologi', 'Kimia'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Rapor Massal - Kelas <?= htmlspecialchars($class['name']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            background-color: #0f172a;
            color: #1e293b;
        }
        @media print {
            body {
                background: white !important;
                color: black !important;
                font-size: 10.5pt !important;
            }
            .no-print {
                display: none !important;
            }
            .report-card-sheet {
                page-break-after: always;
                break-after: page;
                margin: 0 !important;
                padding: 15mm 15mm !important;
                border: none !important;
                box-shadow: none !important;
                background: white !important;
                color: black !important;
            }
            .report-card-sheet:last-child {
                page-break-after: avoid;
                break-after: avoid;
            }
            table th, table td {
                border-color: #cbd5e1 !important;
                color: black !important;
            }
        }
    </style>
</head>
<body class="p-4 sm:p-8">

    <!-- Top floating print action bar (hidden on print) -->
    <div class="max-w-4xl mx-auto mb-6 p-4 rounded-2xl bg-slate-900 border border-white/10 text-white flex flex-wrap items-center justify-between gap-4 shadow-2xl no-print">
        <div>
            <h1 class="text-sm font-bold flex items-center gap-2">
                <i class="fa-solid fa-print text-blue-400"></i>
                Cetak Rapor Massal - Kelas <?= htmlspecialchars($class['name']) ?>
            </h1>
            <p class="text-xs text-slate-400 mt-0.5">Total <?= count($students) ?> siswa &bull; Standar Kurikulum Merdeka e-Rapor A4</p>
        </div>
        
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <select name="class_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                <?php foreach ($all_classes as $cl): ?>
                    <option value="<?= $cl['id'] ?>" <?= ($class_id == $cl['id']) ? 'selected' : '' ?>>
                        Kelas <?= htmlspecialchars($cl['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="semester" onchange="this.form.submit()" class="rounded-xl border border-purple-500/30 bg-purple-500/10 px-3 py-1.5 text-xs font-semibold text-purple-300 focus:outline-none">
                <option value="Ganjil" <?= ($current_semester === 'Ganjil') ? 'selected' : '' ?>>Sem. Ganjil</option>
                <option value="Genap" <?= ($current_semester === 'Genap') ? 'selected' : '' ?>>Sem. Genap</option>
            </select>
            <input type="text" name="academic_year" value="<?= htmlspecialchars($academic_year) ?>" title="Tekan Enter untuk menerapkan"
                   class="w-24 bg-slate-950 border border-white/10 rounded-xl px-2 py-1.5 text-xs text-white text-center font-mono">
            <button type="submit" class="px-2.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-300" title="Terapkan Filter">
                <i class="fa-solid fa-check"></i>
            </button>
            <button type="button" onclick="window.history.back()" class="px-3.5 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs text-slate-300">
                <i class="fa-solid fa-arrow-left mr-1"></i> Kembali
            </button>
            <button type="button" onclick="window.print()" class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs flex items-center gap-1.5 shadow-lg shadow-blue-500/30 cursor-pointer">
                <i class="fa-solid fa-print"></i> Cetak Semua (<?= count($students) ?> Rapor)
            </button>
        </form>
    </div>

    <?php if (empty($students)): ?>
        <div class="max-w-md mx-auto my-12 p-8 text-center bg-slate-900 rounded-3xl border border-white/10 text-slate-300">
            <p class="text-sm">Tidak ada siswa yang terdaftar di kelas <?= htmlspecialchars($class['name']) ?>.</p>
        </div>
    <?php else: ?>
        <div class="max-w-4xl mx-auto space-y-8">
            <?php foreach ($students as $stu): 
                $s_id = (int)$stu['id'];

                // Presensi
                $stmt_att = $pdo->prepare("
                    SELECT 
                        COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir,
                        COUNT(CASE WHEN status = 'sakit' THEN 1 END) as sakit,
                        COUNT(CASE WHEN status = 'izin' THEN 1 END) as izin,
                        COUNT(CASE WHEN status = 'alpa' THEN 1 END) as alpa,
                        COUNT(*) as total
                    FROM student_attendance WHERE student_id = ?
                ");
                $stmt_att->execute([$s_id]);
                $att = $stmt_att->fetch() ?: ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alpa' => 0, 'total' => 0];

                // Catatan Rapor
                $stmt_rn = $pdo->prepare("SELECT * FROM student_report_notes WHERE student_id = ? AND academic_year = ? AND semester = ? LIMIT 1");
                $stmt_rn->execute([$s_id, $academic_year, $current_semester]);
                $rn = $stmt_rn->fetch() ?: [
                    'homeroom_notes' => 'Menunjukkan sikap disiplin dan keaktifan belajar yang sangat baik.',
                    'extracurricular' => 'Pramuka (A - Sangat Aktif)',
                    'decision_status' => 'belum_ditetapkan',
                    'target_next_class' => '',
                    'subject_descriptions' => null,
                    'pkl_internship' => null
                ];

                $custom_subj_descs = [];
                if (!empty($rn['subject_descriptions'])) {
                    $decoded_sd = json_decode($rn['subject_descriptions'], true);
                    if (is_array($decoded_sd)) {
                        $custom_subj_descs = $decoded_sd;
                    }
                }

                $pkl_entries = [];
                if (!empty($rn['pkl_internship'])) {
                    $decoded_pkl = json_decode($rn['pkl_internship'], true);
                    if (is_array($decoded_pkl)) {
                        $pkl_entries = $decoded_pkl;
                    }
                }

                // Nilai mapel
                $academic_records = [];
                $total_score_sum = 0;
                $scored_count = 0;

                foreach ($dynamic_subjects as $sub) {
                    // Nilai tugas
                    $stmt_as = $pdo->prepare("
                        SELECT AVG(s.score) as avg_score 
                        FROM assignment_submissions s 
                        JOIN assignments a ON s.assignment_id = a.id 
                        WHERE s.student_id = ? AND a.subject = ? AND s.score IS NOT NULL
                    ");
                    $stmt_as->execute([$s_id, $sub]);
                    $avg_as = $stmt_as->fetchColumn();

                    // Nilai ujian
                    $stmt_ex = $pdo->prepare("
                        SELECT AVG(es.score) as avg_exam 
                        FROM exam_submissions es 
                        JOIN exams e ON es.exam_id = e.id 
                        WHERE es.student_id = ? AND e.subject = ? AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
                    ");
                    $stmt_ex->execute([$s_id, $sub]);
                    $avg_ex = $stmt_ex->fetchColumn();

                    $kkm = $subject_kkm_map[$sub] ?? 75;
                    $final_val = null;

                    if ($avg_as !== null && $avg_ex !== null) {
                        $final_val = round(($avg_as * 0.4) + ($avg_ex * 0.6), 1);
                    } elseif ($avg_ex !== null) {
                        $final_val = round((float)$avg_ex, 1);
                    } elseif ($avg_as !== null) {
                        $final_val = round((float)$avg_as, 1);
                    } else {
                        $final_val = null;
                    }

                    if ($final_val !== null) {
                        $total_score_sum += $final_val;
                        $scored_count++;
                        $predikat = ($final_val >= 88) ? 'A' : (($final_val >= $kkm) ? 'B' : (($final_val >= 65) ? 'C' : 'D'));

                        // Dual-sided narrative description (Kurikulum Merdeka)
                        $has_custom = isset($custom_subj_descs[$sub]) && trim($custom_subj_descs[$sub]) !== '';
                        if ($has_custom) {
                            $desc = trim($custom_subj_descs[$sub]);
                        } else {
                            if ($final_val >= 88) {
                                $cap_tinggi = "Menunjukkan penguasaan sangat baik dalam capaian pembelajaran pokok {$sub}.";
                                $cap_bimb = "Pertahankan prestasi dan perdalam penalaran konteks mandiri.";
                            } elseif ($final_val >= $kkm) {
                                $cap_tinggi = "Tuntas mencapai tujuan pembelajaran pokok {$sub} dengan baik.";
                                $cap_bimb = "Perlu sedikit peningkatan ketelitian pada asesmen sumatif.";
                            } else {
                                $cap_tinggi = "Cukup mengenal materi pengantar pembelajaran {$sub}.";
                                $cap_bimb = "Perlu pendampingan dan remedial terarah pada ketuntasan materi.";
                            }
                            $desc = "<strong>Capaian Tertinggi:</strong> {$cap_tinggi}<br><span style='color:#64748b'><strong>Perlu Peningkatan:</strong> {$cap_bimb}</span>";
                        }
                    } else {
                        $predikat = '-';
                        $desc = "Belum ada penilaian terekam untuk mata pelajaran ini.";
                    }

                    $academic_records[] = [
                        'subject' => $sub,
                        'kkm' => $kkm,
                        'final_score' => $final_val !== null ? $final_val : '-',
                        'predikat' => $predikat,
                        'desc' => $desc
                    ];
                }

                $avg_total = $scored_count > 0 ? round($total_score_sum / $scored_count, 1) : 0;
                $decision_status = $rn['decision_status'] ?? 'belum_ditetapkan';
                $target_next_class = $rn['target_next_class'] ?? '';
                $is_genap = (strtolower($current_semester) === 'genap');
            ?>
                <!-- LEMBAR RAPOR PER SISWA -->
                <div class="report-card-sheet bg-white p-8 sm:p-10 rounded-2xl shadow-xl text-slate-800 border border-slate-200">
                    
                    <!-- KOP RAPOR -->
                    <div class="text-center border-b-2 border-slate-800 pb-4 mb-5">
                        <h2 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-slate-900">
                            <?= htmlspecialchars($school_info['school_name'] ?? 'SMA Bina Bangsa Nusantara') ?>
                        </h2>
                        <p class="text-xs text-slate-600 mt-0.5"><?= htmlspecialchars($school_info['school_address'] ?? 'Jakarta') ?></p>
                        <p class="text-[11px] text-slate-500 font-mono">Telepon: <?= htmlspecialchars($school_info['school_phone'] ?? '-') ?> &bull; Email: <?= htmlspecialchars($school_info['school_email'] ?? '-') ?></p>
                        <div class="inline-block mt-2 px-3 py-0.5 border border-slate-700 font-bold uppercase tracking-wider text-xs">
                            Laporan Hasil Belajar Peserta Didik (E-Rapor)
                        </div>
                    </div>

                    <!-- IDENTITAS SISWA -->
                    <div class="grid grid-cols-2 gap-3 text-xs mb-5 bg-slate-50 p-3.5 rounded-lg border border-slate-200">
                        <div class="space-y-1">
                            <div class="flex"><span class="w-32 text-slate-500">Nama Siswa</span><span class="font-bold text-slate-900">: <?= htmlspecialchars($stu['name']) ?></span></div>
                            <div class="flex"><span class="w-32 text-slate-500">NISN</span><span class="font-mono text-slate-800">: <?= htmlspecialchars($stu['nisn'] ?: '-') ?></span></div>
                            <div class="flex"><span class="w-32 text-slate-500">Kelas / Rombel</span><span class="font-bold text-slate-800">: <?= htmlspecialchars($class['name']) ?></span></div>
                        </div>
                        <div class="space-y-1">
                            <div class="flex"><span class="w-32 text-slate-500">Semester</span><span class="font-semibold text-slate-900">: <?= htmlspecialchars($current_semester) ?></span></div>
                            <div class="flex"><span class="w-32 text-slate-500">Tahun Pelajaran</span><span class="text-slate-800">: <?= htmlspecialchars($academic_year) ?></span></div>
                            <div class="flex"><span class="w-32 text-slate-500">Rata-rata Nilai</span><span class="font-bold text-blue-700">: <?= number_format($avg_total, 1) ?> (Predikat <?= $avg_total >= 85 ? 'A' : 'B' ?>)</span></div>
                        </div>
                    </div>

                    <!-- TABEL NILAI AKADEMIK -->
                    <div class="mb-5">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700 mb-1.5">A. Capaian Kompetensi Akademik</h3>
                        <table class="w-full text-left text-xs border-collapse border border-slate-300">
                            <thead class="bg-slate-100 font-bold text-slate-700 text-[11px]">
                                <tr>
                                    <th class="border border-slate-300 p-2 text-center w-8">No</th>
                                    <th class="border border-slate-300 p-2">Mata Pelajaran</th>
                                    <th class="border border-slate-300 p-2 text-center w-14">KKTP</th>
                                    <th class="border border-slate-300 p-2 text-center w-14">Nilai</th>
                                    <th class="border border-slate-300 p-2 text-center w-14">Predikat</th>
                                    <th class="border border-slate-300 p-2">Deskripsi Capaian Pembelajaran</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; foreach ($academic_records as $rec): ?>
                                    <tr>
                                        <td class="border border-slate-300 p-2 text-center"><?= $no++ ?></td>
                                        <td class="border border-slate-300 p-2 font-semibold text-slate-900"><?= htmlspecialchars($rec['subject']) ?></td>
                                        <td class="border border-slate-300 p-2 text-center font-mono"><?= $rec['kkm'] ?></td>
                                        <td class="border border-slate-300 p-2 text-center font-bold text-slate-900"><?= $rec['final_score'] ?></td>
                                        <td class="border border-slate-300 p-2 text-center font-bold"><?= $rec['predikat'] ?></td>
                                        <td class="border border-slate-300 p-2 text-[11px] text-slate-700 leading-snug"><?= $rec['desc'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- EKSTRAKURIKULER & KETIDAKHADIRAN -->
                    <div class="grid grid-cols-2 gap-4 mb-5 text-xs">
                        <div class="border border-slate-300 p-3 rounded-lg">
                            <h4 class="font-bold text-slate-800 uppercase text-[11px] mb-1">B. Ekstrakurikuler</h4>
                            <p class="text-slate-600 whitespace-pre-line"><?= htmlspecialchars($rn['extracurricular'] ?: 'Pramuka (A - Sangat Aktif)') ?></p>
                        </div>
                        <div class="border border-slate-300 p-3 rounded-lg">
                            <h4 class="font-bold text-slate-800 uppercase text-[11px] mb-1">C. Ketidakhadiran</h4>
                            <div class="space-y-0.5 text-slate-600">
                                <div class="flex justify-between"><span>Sakit:</span> <span class="font-bold"><?= $att['sakit'] ?> hari</span></div>
                                <div class="flex justify-between"><span>Izin:</span> <span class="font-bold"><?= $att['izin'] ?> hari</span></div>
                                <div class="flex justify-between"><span>Tanpa Keterangan:</span> <span class="font-bold"><?= $att['alpa'] ?> hari</span></div>
                            </div>
                        </div>
                    </div>

                    <!-- CATATAN WALI KELAS -->
                    <div class="border border-slate-300 p-3.5 rounded-lg mb-5 text-xs">
                        <h4 class="font-bold text-slate-800 uppercase text-[11px] mb-1">D. Catatan Wali Kelas</h4>
                        <p class="text-slate-700 italic">"<?= htmlspecialchars($rn['homeroom_notes'] ?: 'Tingkatkan terus prestasi dan pertahankan kedisiplinan belajar.') ?>"</p>
                    </div>

                    <!-- PKL / MAGANG (Opsional SMK) -->
                    <?php if (!empty($pkl_entries)): ?>
                        <div class="border border-slate-300 p-3 rounded-lg mb-5 text-xs">
                            <h4 class="font-bold text-slate-800 uppercase text-[11px] mb-1.5">E. Praktik Kerja Lapangan (PKL)</h4>
                            <table class="w-full text-left border-collapse border border-slate-200 text-[11px]">
                                <thead class="bg-slate-50 font-bold">
                                    <tr>
                                        <th class="border border-slate-200 p-1.5">Mitra DUDI</th>
                                        <th class="border border-slate-200 p-1.5">Lokasi</th>
                                        <th class="border border-slate-200 p-1.5 text-center">Durasi</th>
                                        <th class="border border-slate-200 p-1.5 text-center">Nilai</th>
                                        <th class="border border-slate-200 p-1.5">Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pkl_entries as $pke): ?>
                                        <tr>
                                            <td class="border border-slate-200 p-1.5 font-semibold"><?= htmlspecialchars($pke['mitra'] ?? '-') ?></td>
                                            <td class="border border-slate-200 p-1.5"><?= htmlspecialchars($pke['lokasi'] ?? '-') ?></td>
                                            <td class="border border-slate-200 p-1.5 text-center"><?= htmlspecialchars($pke['durasi'] ?? '-') ?></td>
                                            <td class="border border-slate-200 p-1.5 text-center font-bold text-blue-700"><?= htmlspecialchars($pke['nilai'] ?? '-') ?></td>
                                            <td class="border border-slate-200 p-1.5"><?= htmlspecialchars($pke['keterangan'] ?? '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <!-- KOTAK KEPUTUSAN KENAIKAN KELAS / KELULUSAN -->
                    <div class="border-2 <?= $decision_status === 'tinggal_kelas' || $decision_status === 'tidak_lulus' ? 'border-rose-400 bg-rose-50/50' : 'border-slate-800 bg-slate-50' ?> p-3.5 rounded-lg mb-6 text-xs">
                        <div class="font-bold uppercase tracking-wider text-slate-900 mb-1 flex items-center justify-between">
                            <span>Keputusan Hasil Akhir Pembelajaran</span>
                            <span class="font-mono text-[10px] text-slate-600">Sem. <?= htmlspecialchars($current_semester) ?> - T.A <?= htmlspecialchars($academic_year) ?></span>
                        </div>
                        <?php if ($is_genap || in_array($decision_status, ['naik_kelas', 'tinggal_kelas', 'lulus', 'tidak_lulus'])): ?>
                            <p class="text-slate-700 mb-1.5">Berdasarkan hasil pencapaian kompetensi seluruh mata pelajaran, peserta didik ditetapkan:</p>
                            <div class="p-2 border border-slate-300 rounded bg-white font-bold text-xs flex items-center gap-2">
                                <?php if ($decision_status === 'naik_kelas'): ?>
                                    <span class="text-emerald-700">&#10003; NAIK KE KELAS:</span>
                                    <span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded border border-emerald-300"><?= htmlspecialchars(!empty($target_next_class) ? $target_next_class : 'Tingkat Selanjutnya') ?></span>
                                <?php elseif ($decision_status === 'tinggal_kelas'): ?>
                                    <span class="text-rose-700">&#10007; TINGGAL DI KELAS:</span>
                                    <span class="bg-rose-100 text-rose-800 px-2 py-0.5 rounded border border-rose-300"><?= htmlspecialchars(!empty($target_next_class) ? $target_next_class : $class['name']) ?></span>
                                <?php elseif ($decision_status === 'lulus'): ?>
                                    <span class="text-emerald-700">&#10003; LULUS</span>
                                    <span class="text-slate-600 font-normal text-[11px]">(Telah menyelesaikan seluruh program pembelajaran)</span>
                                <?php elseif ($decision_status === 'tidak_lulus'): ?>
                                    <span class="text-rose-700">&#10007; TIDAK LULUS</span>
                                <?php else: ?>
                                    <span class="text-amber-700 italic font-normal">Status kenaikan kelas belum ditetapkan wali kelas.</span>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-slate-700"><strong>Semester Ganjil:</strong> Peserta didik melanjutkan kegiatan pembelajaran ke Semester Genap.</p>
                        <?php endif; ?>
                    </div>

                    <!-- TANDA TANGAN -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs text-center pt-2 items-end">
                        <div>
                            <p class="text-slate-600">Mengetahui,<br>Orang Tua / Wali Murid</p>
                            <div class="h-16"></div>
                            <p class="border-t border-slate-400 font-bold text-slate-800 mx-4 pt-1">( .................................... )</p>
                        </div>
                        <div>
                            <?= renderOfficialSignatureHtml($pdo, 'wali_kelas', [
                                'teacher_id' => $class['homeroom_teacher_id'] ?? 0,
                                'custom_name' => !empty($class['teacher_name']) ? ($class['teacher_name'] . (!empty($class['teacher_degree']) ? ', ' . $class['teacher_degree'] : '')) : null,
                                'custom_nip' => $class['teacher_nip'] ?? null,
                                'custom_signature_image' => $class['teacher_signature_image'] ?? null,
                                'custom_title' => 'Wali Kelas ' . ($class['name'] ?? ''),
                                'doc_type' => 'RAPOR',
                                'doc_num' => 'RAPOR-' . $stu['id'] . '-' . ($stu['nisn'] ?: '2026'),
                                'student_name' => $stu['name'],
                                'show_city_date' => true,
                                'align' => 'center'
                            ]) ?>
                        </div>
                        <div>
                            <p class="text-slate-600">Mengetahui,</p>
                            <?= renderOfficialSignatureHtml($pdo, 'headmaster', [
                                'doc_type' => 'RAPOR',
                                'doc_num' => 'RAPOR-' . $stu['id'] . '-' . ($stu['nisn'] ?: '2026'),
                                'student_name' => $stu['name'],
                                'show_city_date' => false,
                                'align' => 'center'
                            ]) ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</body>
</html>
