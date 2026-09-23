<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff_or_teacher = in_array($user_role, ['guru', 'staf', 'administrator'], true);

$school_info = getSchoolSettings($pdo);
$academic_year = $school_info['academic_year'] ?? '2026/2027';
$current_semester = 'Ganjil';

// -------------------------------------------------------------
// 1. RESOLUSI SISWA TARGET
// -------------------------------------------------------------
$target_student_id = null;
if (isset($_GET['student_id']) && is_numeric($_GET['student_id'])) {
    $target_student_id = (int)$_GET['student_id'];
} elseif ($user_role === 'siswa') {
    $target_student_id = $user_id;
} elseif ($user_role === 'orang_tua') {
    $stmt_c = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
    $stmt_c->execute([$user_id]);
    $child_id = $stmt_c->fetchColumn();
    $target_student_id = $child_id ? (int)$child_id : 0;
} elseif ($is_staff_or_teacher) {
    // Default ambil siswa pertama jika guru/admin membuka langsung
    $stmt_first = $pdo->query("SELECT id FROM users WHERE role = 'siswa' ORDER BY class_id ASC, name ASC LIMIT 1");
    $target_student_id = (int)($stmt_first->fetchColumn() ?: 0);
}

// -------------------------------------------------------------
// 2. SIMPAN CATATAN WALI KELAS & EKSKUL (Guru & Admin)
// -------------------------------------------------------------
$feedback_msg = "";
$feedback_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_report_notes' && $is_staff_or_teacher) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $feedback_msg = "Token keamanan tidak valid.";
        $feedback_type = "error";
    } else {
        $p_student_id = (int)($_POST['student_id'] ?? $target_student_id);
        $p_year = trim($_POST['academic_year'] ?? $academic_year);
        $p_sem = trim($_POST['semester'] ?? $current_semester);
        $p_notes = trim($_POST['homeroom_notes'] ?? '');
        $p_ekskul = trim($_POST['extracurricular'] ?? '');

        try {
            $stmt_sn = $pdo->prepare("
                INSERT INTO student_report_notes (student_id, academic_year, semester, homeroom_notes, extracurricular, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    homeroom_notes = VALUES(homeroom_notes),
                    extracurricular = VALUES(extracurricular),
                    created_by = VALUES(created_by),
                    updated_at = NOW()
            ");
            $stmt_sn->execute([$p_student_id, $p_year, $p_sem, $p_notes, $p_ekskul, $user_id]);
            logActivity($pdo, 'SAVE_REPORT_NOTES', "Menyimpan catatan rapor siswa ID $p_student_id");
            $feedback_msg = "Catatan wali kelas & ekstrakurikuler berhasil disimpan ke rapor siswa.";
            $feedback_type = "success";
            $target_student_id = $p_student_id;
        } catch (Exception $e) {
            $feedback_msg = "Gagal menyimpan catatan: " . $e->getMessage();
            $feedback_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 3. AMBIL DATA SISWA
// -------------------------------------------------------------
$stmt_stu = $pdo->prepare("
    SELECT u.*, c.name as class_name 
    FROM users u 
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE u.id = ? AND u.role = 'siswa'
");
$stmt_stu->execute([$target_student_id]);
$student = $stmt_stu->fetch();

// Daftar siswa dan kelas untuk selector guru/admin
$classes_list = [];
$all_students_report = [];
$prev_student_id = null;
$next_student_id = null;
$selected_class_id = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : ($student['class_id'] ?? null);

if ($is_staff_or_teacher) {
    $classes_list = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();

    $stu_sql = "
        SELECT u.id, u.name, u.nisn, u.class_id, c.name as class_name 
        FROM users u 
        LEFT JOIN classes c ON u.class_id = c.id 
        WHERE u.role = 'siswa'
    ";
    $stu_params = [];
    if ($selected_class_id) {
        $stu_sql .= " AND u.class_id = ?";
        $stu_params[] = $selected_class_id;
    }
    $stu_sql .= " ORDER BY c.name ASC, u.name ASC";
    $stmt_stus = $pdo->prepare($stu_sql);
    $stmt_stus->execute($stu_params);
    $all_students_report = $stmt_stus->fetchAll();

    if (!empty($all_students_report)) {
        $ids = array_column($all_students_report, 'id');
        $curr_idx = array_search($target_student_id, $ids);
        if ($curr_idx !== false) {
            if ($curr_idx > 0) $prev_student_id = $ids[$curr_idx - 1];
            if ($curr_idx < count($ids) - 1) $next_student_id = $ids[$curr_idx + 1];
        }
    }
}

if (!$student) {
    $page_title = "Data Rapor Siswa";
    require_once __DIR__ . "/../includes/header.php";
    ?>
    <div class="max-w-md mx-auto my-12 rounded-3xl border border-rose-500/30 bg-slate-900/90 p-8 text-center shadow-2xl">
        <span class="text-4xl block mb-2 text-rose-400"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <h2 class="text-lg font-bold text-white">Data Siswa Tidak Ditemukan</h2>
        <p class="text-xs text-slate-400 mt-2">Tidak ditemukan data siswa aktif untuk ditampilkan pada halaman rapor ini.</p>
        <a href="../index.php" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 px-6 py-2 text-xs font-bold text-white transition"><i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard</a>
    </div>
    <?php
    require_once __DIR__ . "/../includes/footer.php";
    exit;
}

// -------------------------------------------------------------
// 4. REKAP KEHADIRAN & CATATAN RAPOR
// -------------------------------------------------------------
$stmt_att = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir,
        COUNT(CASE WHEN status = 'sakit' THEN 1 END) as sakit,
        COUNT(CASE WHEN status = 'izin' THEN 1 END) as izin,
        COUNT(CASE WHEN status = 'alpa' THEN 1 END) as alpa,
        COUNT(*) as total
    FROM student_attendance
    WHERE student_id = ?
");
$stmt_att->execute([$target_student_id]);
$att = $stmt_att->fetch() ?: ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alpa' => 0, 'total' => 0];

// Ambil Catatan Wali Kelas & Ekskul dari tabel student_report_notes
$stmt_rn = $pdo->prepare("
    SELECT * FROM student_report_notes 
    WHERE student_id = ? AND academic_year = ? AND semester = ?
    LIMIT 1
");
$stmt_rn->execute([$target_student_id, $academic_year, $current_semester]);
$report_notes = $stmt_rn->fetch() ?: [
    'homeroom_notes' => 'Ahmad menunjukkan ketekunan dan kedisiplinan yang sangat baik selama semester ini. Tingkatkan keaktifan dalam diskusi dan pertahankan prestasi belajar.',
    'extracurricular' => "1. Pramuka Wajib (Predikat: A - Sangat Baik & Berjiwa Pemimpin)\n2. Kelompok Ilmiah Remaja / KIR (Predikat: A - Aktif Berinovasi)"
];

// -------------------------------------------------------------
// 5. NILAI MAPEL & CAPAIAN KOMPETENSI
// -------------------------------------------------------------
$stmt_subjects = $pdo->prepare("
    SELECT DISTINCT subject FROM (
        SELECT subject FROM assignments
        UNION
        SELECT subject FROM exams
        UNION
        SELECT name as subject FROM subjects
    ) as all_subjects
    ORDER BY subject ASC
");
$stmt_subjects->execute();
$dynamic_subjects = $stmt_subjects->fetchAll(PDO::FETCH_COLUMN);

if (empty($dynamic_subjects)) {
    $dynamic_subjects = ['Matematika', 'Bahasa Indonesia', 'Bahasa Inggris', 'Fisika', 'Biologi', 'Kimia', 'Sejarah Indonesia', 'Pendidikan Agama Islam'];
}

$report_grades = [];
$total_final_sum = 0;

foreach ($dynamic_subjects as $subj) {
    // Nilai Tugas
    $stmt_as = $pdo->prepare("
        SELECT AVG(s.score) as avg_score 
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.id
        WHERE s.student_id = ? AND a.subject = ? AND s.status = 'selesai' AND s.score IS NOT NULL
    ");
    $stmt_as->execute([$target_student_id, $subj]);
    $tugas_val = round((float)($stmt_as->fetchColumn() ?: 0), 1);

    // Nilai Ujian
    $stmt_ex = $pdo->prepare("
        SELECT e.category, s.score 
        FROM exam_submissions s
        JOIN exams e ON s.exam_id = e.id
        WHERE s.student_id = ? AND e.subject = ?
    ");
    $stmt_ex->execute([$target_student_id, $subj]);
    $ex_rows = $stmt_ex->fetchAll();

    $uh_val = 0; $uts_val = 0; $ukk_val = 0;
    foreach ($ex_rows as $er) {
        if ($er['category'] === 'ujian_harian' || str_starts_with($er['category'], 'latihan')) $uh_val = max($uh_val, (float)$er['score']);
        elseif ($er['category'] === 'uts') $uts_val = (float)$er['score'];
        elseif ($er['category'] === 'ukk') $ukk_val = (float)$er['score'];
    }

    // Hitung Nilai Akhir: Tugas (20%), UH (20%), UTS (25%), UKK (25%), Presensi (10%)
    $att_pct = ((int)($att['total'] ?? 0) > 0) ? round(((int)$att['hadir'] / (int)$att['total']) * 100, 1) : 100.0;
    $na = ($tugas_val * 0.20) + ($uh_val * 0.20) + ($uts_val * 0.25) + ($ukk_val * 0.25) + ($att_pct * 0.10);
    $na = round($na, 1);
    $total_final_sum += $na;

    $pred = ($na >= 85) ? 'A' : (($na >= 75) ? 'B' : (($na >= 65) ? 'C' : 'D'));
    $deskripsi = ($na >= 85) 
        ? 'Sangat menguasai capaian pembelajaran dengan ketuntasan istimewa.' 
        : (($na >= 75) 
            ? 'Menguasai kompetensi dasar dengan baik, pertahankan capaian ini.' 
            : (($na >= 65) ? 'Cukup memahami materi, perlu peningkatan pada latihan soal mandiri.' : 'Perlu bimbingan dan remidiasi intensif pada materi esensial.'));

    $report_grades[] = [
        'subject'    => $subj,
        'kkm'        => 75,
        'tugas'      => $tugas_val,
        'uh'         => $uh_val,
        'uts'        => $uts_val,
        'ukk'        => $ukk_val,
        'na'         => $na,
        'predikat'   => $pred,
        'deskripsi'  => $deskripsi
    ];
}

$average_score = count($report_grades) > 0 ? round($total_final_sum / count($report_grades), 1) : 0;
$page_title = "E-Rapor Digital - " . htmlspecialchars($student['name']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 15mm 15mm 15mm;
        }
        @media print {
            body { 
                background: white !important; 
                color: #0f172a !important; 
                padding: 0 !important; 
                font-size: 11pt !important;
            }
            .no-print { display: none !important; }
            .print-paper { 
                border: none !important; 
                box-shadow: none !important; 
                max-width: 100% !important; 
                padding: 0 !important;
                background: transparent !important;
                color: #000 !important;
            }
            .print-paper * {
                color: #000 !important;
                border-color: #334155 !important;
            }
            .print-paper .text-white { color: #000 !important; }
            .print-paper .text-slate-300, .print-paper .text-slate-400 { color: #333 !important; }
            .print-paper .bg-slate-950, .print-paper .bg-slate-900, .print-paper .bg-white\/5 {
                background: transparent !important;
            }
            table { border-collapse: collapse !important; width: 100% !important; }
            th, td { border: 1px solid #333 !important; }
            thead th { background-color: #f1f5f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center p-3 sm:p-6 font-sans antialiased">

    <!-- Top Action & Navigation Bar (No-Print) -->
    <div class="no-print w-full max-w-4xl mb-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <a href="../index.php" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 px-3.5 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
                    <i class="fa-solid fa-arrow-left"></i> Dashboard
                </a>
                <a href="gradebook.php" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 px-3.5 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
                    <i class="fa-solid fa-chart-column"></i> Leger Nilai
                </a>
            </div>

            <div class="flex items-center gap-2">
                <?php if ($is_staff_or_teacher): ?>
                    <button onclick="document.getElementById('modalEditNotes').classList.remove('hidden')" class="inline-flex items-center gap-1.5 rounded-xl border border-purple-500/30 bg-purple-500/10 hover:bg-purple-500/20 px-3.5 py-2 text-xs font-bold text-purple-300 transition cursor-pointer">
                        <i class="fa-solid fa-pen-to-square"></i> Edit Catatan Wali Kelas
                    </button>
                <?php endif; ?>

                <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition cursor-pointer">
                    <i class="fa-solid fa-print"></i> Cetak / Simpan PDF
                </button>
            </div>
        </div>

        <?php if (!empty($feedback_msg)): ?>
            <div class="rounded-xl border p-3 text-xs <?= $feedback_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
                <?= htmlspecialchars($feedback_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Selector Bar for Teachers/Admin -->
        <?php if ($is_staff_or_teacher): ?>
            <div class="rounded-2xl border border-white/10 bg-slate-900/80 p-3.5 backdrop-blur flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2.5">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Pilih Siswa:</span>
                    
                    <form method="GET" class="inline-flex gap-2">
                        <select name="class_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                            <option value="">Semua Rombel</option>
                            <?php foreach ($classes_list as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= ($selected_class_id == $cl['id']) ? 'selected' : '' ?>>
                                    Kelas <?= htmlspecialchars($cl['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="student_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none max-w-[240px]">
                            <?php foreach ($all_students_report as $st): ?>
                                <option value="<?= $st['id'] ?>" <?= ($target_student_id == $st['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($st['name']) ?> (<?= htmlspecialchars($st['class_name'] ?: 'Umum') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <!-- Next/Prev Buttons -->
                <div class="flex items-center gap-1.5">
                    <?php if ($prev_student_id): ?>
                        <a href="report_card.php?student_id=<?= $prev_student_id ?>&class_id=<?= $selected_class_id ?>" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-2.5 py-1.5 text-xs text-slate-300">
                            ◀ Sebelumnya
                        </a>
                    <?php endif; ?>

                    <?php if ($next_student_id): ?>
                        <a href="report_card.php?student_id=<?= $next_student_id ?>&class_id=<?= $selected_class_id ?>" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-2.5 py-1.5 text-xs text-slate-300">
                            Berikutnya ▶
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- LEMBAR RAPOR RESMI (A4) -->
    <div class="print-paper w-full max-w-4xl rounded-3xl border border-white/15 bg-slate-900/95 p-6 sm:p-10 shadow-2xl backdrop-blur">
        
        <!-- Kop Surat Resmi -->
        <div class="text-center border-b-2 border-white/20 pb-5 mb-5">
            <h1 class="text-2xl sm:text-3xl font-black uppercase tracking-tight text-white">
                <?= htmlspecialchars($school_info['school_name']) ?>
            </h1>
            <p class="text-xs text-slate-300 mt-1"><?= htmlspecialchars($school_info['school_address']) ?></p>
            <p class="text-xs text-slate-400 font-mono">Telepon: <?= htmlspecialchars($school_info['school_phone']) ?> | Email: <?= htmlspecialchars($school_info['school_email']) ?></p>
            <div class="inline-block mt-3 px-4 py-1 rounded-full border border-white/20 bg-white/5 text-xs font-bold uppercase tracking-widest text-blue-400">
                Laporan Hasil Belajar Peserta Didik (E-Rapor)
            </div>
        </div>

        <!-- Identitas Siswa -->
        <div class="grid grid-cols-2 gap-4 text-xs mb-5 bg-slate-950/60 p-4 rounded-2xl border border-white/10">
            <div class="space-y-1.5">
                <div class="flex"><span class="w-32 text-slate-400">Nama Peserta Didik</span><span class="font-bold text-white">: <?= htmlspecialchars($student['name']) ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Nomor Induk / NISN</span><span class="font-mono text-slate-200">: <?= htmlspecialchars($student['nisn'] ?: '-') ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Rombongan Belajar</span><span class="font-semibold text-slate-200">: <?= htmlspecialchars($student['class_name'] ?? 'Belum ditentukan') ?></span></div>
            </div>
            <div class="space-y-1.5">
                <div class="flex"><span class="w-32 text-slate-400">Semester</span><span class="font-semibold text-white">: <?= htmlspecialchars($current_semester) ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Tahun Pelajaran</span><span class="text-slate-200">: <?= htmlspecialchars($academic_year) ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Rata-rata Nilai</span><span class="font-bold text-emerald-400">: <?= number_format($average_score, 1) ?> (Predikat <?= $average_score >= 85 ? 'A' : ($average_score >= 75 ? 'B' : 'C') ?>)</span></div>
            </div>
        </div>

        <!-- A. Tabel Capaian Akademik -->
        <div class="mb-5">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">A. Nilai Pengetahuan & Keterampilan</h3>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="border-b border-white/10 bg-white/5 text-[10px] uppercase font-bold text-slate-400">
                        <tr>
                            <th class="px-3 py-2.5 text-center w-8">No</th>
                            <th class="px-4 py-2.5">Mata Pelajaran</th>
                            <th class="px-2.5 py-2.5 text-center">KKM</th>
                            <th class="px-2.5 py-2.5 text-center">Tugas</th>
                            <th class="px-2.5 py-2.5 text-center">UH</th>
                            <th class="px-2.5 py-2.5 text-center">UTS</th>
                            <th class="px-2.5 py-2.5 text-center">UKK</th>
                            <th class="px-3 py-2.5 text-center font-bold text-white">Nilai Akhir</th>
                            <th class="px-2.5 py-2.5 text-center">Pred</th>
                            <th class="px-4 py-2.5">Capaian Kompetensi Pembelajaran</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($report_grades as $i => $rg): ?>
                            <tr>
                                <td class="px-3 py-2 text-center font-mono text-slate-500"><?= $i + 1 ?></td>
                                <td class="px-4 py-2 font-semibold text-white"><?= htmlspecialchars($rg['subject']) ?></td>
                                <td class="px-2.5 py-2 text-center font-mono text-slate-400"><?= $rg['kkm'] ?></td>
                                <td class="px-2.5 py-2 text-center font-mono"><?= $rg['tugas'] ?></td>
                                <td class="px-2.5 py-2 text-center font-mono"><?= $rg['uh'] ?></td>
                                <td class="px-2.5 py-2 text-center font-mono"><?= $rg['uts'] ?></td>
                                <td class="px-2.5 py-2 text-center font-mono"><?= $rg['ukk'] ?></td>
                                <td class="px-3 py-2 text-center font-mono font-bold <?= $rg['na'] >= $rg['kkm'] ? 'text-emerald-300' : 'text-rose-400' ?>">
                                    <?= number_format($rg['na'], 1) ?>
                                </td>
                                <td class="px-2.5 py-2 text-center font-bold"><?= $rg['predikat'] ?></td>
                                <td class="px-4 py-2 text-[11px] text-slate-300 leading-snug"><?= htmlspecialchars($rg['deskripsi']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- B & C. Ketidakhadiran & Ekstrakurikuler -->
        <div class="mb-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- B. Presensi -->
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">B. Rekapitulasi Ketidakhadiran</h3>
                <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-3.5 text-xs space-y-1.5">
                    <div class="flex justify-between border-b border-white/5 pb-1"><span>1. Sakit (S)</span><strong class="font-mono"><?= $att['sakit'] ?> hari</strong></div>
                    <div class="flex justify-between border-b border-white/5 pb-1"><span>2. Izin (I)</span><strong class="font-mono"><?= $att['izin'] ?> hari</strong></div>
                    <div class="flex justify-between border-b border-white/5 pb-1"><span>3. Tanpa Keterangan / Alpa (A)</span><strong class="font-mono <?= $att['alpa'] > 0 ? 'text-rose-400' : '' ?>"><?= $att['alpa'] ?> hari</strong></div>
                    <div class="flex justify-between pt-1"><span>Total Kehadiran Efektif</span><strong class="font-mono text-emerald-400"><?= $att['hadir'] ?> hari (<?= $att_pct ?>%)</strong></div>
                </div>
            </div>

            <!-- C. Ekstrakurikuler -->
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">C. Kegiatan Ekstrakurikuler</h3>
                <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-3.5 text-xs text-slate-200 min-h-[110px] whitespace-pre-line leading-relaxed">
                    <?= htmlspecialchars($report_notes['extracurricular'] ?? "1. Pramuka Wajib (Predikat: A)\n2. Kegiatan Olahraga & Seni (Predikat: B)") ?>
                </div>
            </div>
        </div>

        <!-- D. Catatan Perkembangan Karakter & Rekomendasi Wali Kelas -->
        <div class="mb-6">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">D. Catatan Wali Kelas & Karakter</h3>
            <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4 text-xs text-slate-200 leading-relaxed italic">
                "<?= htmlspecialchars($report_notes['homeroom_notes'] ?? 'Ananda menunjukkan perkembangan belajar yang positif dan berdisiplin tinggi.') ?>"
            </div>
        </div>

        <!-- Tanda Tangan Tiga Pihak -->
        <div class="grid grid-cols-3 gap-4 text-center text-xs text-slate-300 pt-5 border-t border-white/10">
            <div>
                <p class="text-slate-400">Mengetahui,</p>
                <p class="font-semibold">Orang Tua / Wali Murid,</p>
                <div class="h-16"></div>
                <p class="font-bold underline text-white">..........................................</p>
            </div>
            <div>
                <p class="text-slate-400">Jakarta, <?= date('d F Y') ?></p>
                <p class="font-semibold">Wali Kelas,</p>
                <div class="h-16"></div>
                <p class="font-bold underline text-white">Dewi Lestari, M.Pd</p>
                <p class="text-[10px] text-slate-400 font-mono">NIP. 19820415 200801 2 015</p>
            </div>
            <div>
                <p class="text-slate-400">Mengetahui,</p>
                <p class="font-semibold">Kepala Sekolah,</p>
                <div class="h-16"></div>
                <p class="font-bold underline text-white"><?= htmlspecialchars($school_info['headmaster_name']) ?></p>
                <p class="text-[10px] text-slate-400 font-mono">NIP. <?= htmlspecialchars($school_info['headmaster_nip']) ?></p>
            </div>
        </div>

    </div>

    <!-- MODAL EDIT CATATAN WALI KELAS (GURU & ADMIN) -->
    <?php if ($is_staff_or_teacher): ?>
        <div id="modalEditNotes" class="fixed inset-0 z-50 hidden bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="relative w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl text-slate-100">
                <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-5">
                    <div>
                        <h3 class="text-lg font-bold text-white">Edit Catatan Rapor Siswa</h3>
                        <p class="text-xs text-purple-400 font-semibold"><?= htmlspecialchars($student['name']) ?> (<?= htmlspecialchars($student['class_name'] ?? 'Kelas') ?>)</p>
                    </div>
                    <button type="button" onclick="document.getElementById('modalEditNotes').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
                </div>

                <form method="POST" action="report_card.php?student_id=<?= $target_student_id ?>&class_id=<?= $selected_class_id ?>" class="space-y-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_report_notes">
                    <input type="hidden" name="student_id" value="<?= $target_student_id ?>">
                    <input type="hidden" name="academic_year" value="<?= htmlspecialchars($academic_year) ?>">
                    <input type="hidden" name="semester" value="<?= htmlspecialchars($current_semester) ?>">

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Kegiatan Ekstrakurikuler & Predikat:</label>
                        <textarea name="extracurricular" rows="3" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs text-white focus:border-purple-500 focus:outline-none transition leading-relaxed"><?= htmlspecialchars($report_notes['extracurricular'] ?? '') ?></textarea>
                        <span class="text-[10px] text-slate-400 block mt-1">Contoh: 1. Pramuka (A - Sangat Aktif) \n 2. Paskibra (B - Baik)</span>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Catatan Perkembangan Karakter & Motivasi Wali Kelas:</label>
                        <textarea name="homeroom_notes" rows="4" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs text-white focus:border-purple-500 focus:outline-none transition leading-relaxed"><?= htmlspecialchars($report_notes['homeroom_notes'] ?? '') ?></textarea>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                        <button type="button" onclick="document.getElementById('modalEditNotes').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition">
                            Batal
                        </button>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-purple-600 hover:bg-purple-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-purple-600/25 transition cursor-pointer">
                            <i class="fa-solid fa-floppy-disk"></i> Simpan Catatan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

</body>
</html>
