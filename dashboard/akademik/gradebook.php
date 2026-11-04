<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['guru', 'administrator']);

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'guru';

$school_info = getSchoolSettings($pdo);

// Ambil daftar kelas untuk filter
$classes = $pdo->query("SELECT * FROM classes ORDER BY grade_level ASC, name ASC")->fetchAll();
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

// Ambil daftar mata pelajaran terdaftar
$subjects_list = $pdo->query("SELECT DISTINCT name FROM subjects ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($subjects_list)) {
    $subjects_list = ['Matematika', 'Bahasa Indonesia', 'Bahasa Inggris', 'Fisika', 'Biologi', 'Kimia', 'Sejarah Indonesia', 'PJOK / Penjas', 'Pendidikan Agama Islam', 'Seni Budaya'];
}
$selected_subject = trim($_GET['subject'] ?? '');

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

// Ambil data ujian online CBT yang terkait untuk referensi guru
$cbt_query = "SELECT e.*, COUNT(s.id) as total_takers, AVG(s.score) as avg_cbt_score 
              FROM exams e 
              LEFT JOIN exam_submissions s ON s.exam_id = e.id 
              WHERE 1=1";
$cbt_params = [];
if (!empty($selected_subject)) {
    $cbt_query .= " AND e.subject = ?";
    $cbt_params[] = $selected_subject;
}
$cbt_query .= " GROUP BY e.id ORDER BY e.id DESC LIMIT 5";
$stmt_cbt = $pdo->prepare($cbt_query);
$stmt_cbt->execute($cbt_params);
$cbt_exams = $stmt_cbt->fetchAll();

// Kalkulasi nilai per siswa
$gradebook_data = [];

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

    // 2. Rata-rata Tugas (Terintegrasi Mapel jika dipilih)
    if (!empty($selected_subject)) {
        $stmt_as = $pdo->prepare("
            SELECT AVG(sub.score) as avg_score, COUNT(*) as count_done 
            FROM assignment_submissions sub
            JOIN assignments a ON sub.assignment_id = a.id
            WHERE sub.student_id = ? AND sub.status = 'selesai' AND sub.score IS NOT NULL AND a.subject = ?
        ");
        $stmt_as->execute([$s_id, $selected_subject]);
    } else {
        $stmt_as = $pdo->prepare("
            SELECT AVG(score) as avg_score, COUNT(*) as count_done 
            FROM assignment_submissions 
            WHERE student_id = ? AND status = 'selesai' AND score IS NOT NULL
        ");
        $stmt_as->execute([$s_id]);
    }
    $as_row = $stmt_as->fetch();
    $as_score = $as_row['avg_score'] !== null ? round((float)$as_row['avg_score'], 1) : 0.0;

    // 3. Rata-rata Ulangan Harian / Sumatif Lingkup Materi (CBT category = 'slm', 'ujian_harian', 'harian', 'kuis')
    if (!empty($selected_subject)) {
        $stmt_uh = $pdo->prepare("
            SELECT AVG(s.score) as avg_score 
            FROM exam_submissions s
            JOIN exams e ON s.exam_id = e.id
            WHERE s.student_id = ? 
              AND e.category IN ('slm', 'ujian_harian', 'harian', 'kuis') 
              AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
              AND e.subject = ?
        ");
        $stmt_uh->execute([$s_id, $selected_subject]);
    } else {
        $stmt_uh = $pdo->prepare("
            SELECT AVG(s.score) as avg_score 
            FROM exam_submissions s
            JOIN exams e ON s.exam_id = e.id
            WHERE s.student_id = ? 
              AND e.category IN ('slm', 'ujian_harian', 'harian', 'kuis')
              AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
        ");
        $stmt_uh->execute([$s_id]);
    }
    $uh_score = round((float)($stmt_uh->fetchColumn() ?? 0), 1);

    // 4. UTS / Sumatif Tengah Semester (CBT category = 'sts', 'uts')
    if (!empty($selected_subject)) {
        $stmt_uts = $pdo->prepare("
            SELECT s.score 
            FROM exam_submissions s
            JOIN exams e ON s.exam_id = e.id
            WHERE s.student_id = ? 
              AND e.category IN ('sts', 'uts') 
              AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
              AND e.subject = ?
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt_uts->execute([$s_id, $selected_subject]);
    } else {
        $stmt_uts = $pdo->prepare("
            SELECT s.score 
            FROM exam_submissions s
            JOIN exams e ON s.exam_id = e.id
            WHERE s.student_id = ? 
              AND e.category IN ('sts', 'uts')
              AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt_uts->execute([$s_id]);
    }
    $uts_score = round((float)($stmt_uts->fetchColumn() ?? 0), 1);

    // 5. UKK / Sumatif Akhir Semester/Tahun (CBT category = 'sas', 'sat', 'ukk', 'uas')
    if (!empty($selected_subject)) {
        $stmt_ukk = $pdo->prepare("
            SELECT s.score 
            FROM exam_submissions s
            JOIN exams e ON s.exam_id = e.id
            WHERE s.student_id = ? 
              AND e.category IN ('sas', 'sat', 'ukk', 'uas') 
              AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
              AND e.subject = ?
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt_ukk->execute([$s_id, $selected_subject]);
    } else {
        $stmt_ukk = $pdo->prepare("
            SELECT s.score 
            FROM exam_submissions s
            JOIN exams e ON s.exam_id = e.id
            WHERE s.student_id = ? 
              AND e.category IN ('sas', 'sat', 'ukk', 'uas')
              AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt_ukk->execute([$s_id]);
    }
    $ukk_score = round((float)($stmt_ukk->fetchColumn() ?? 0), 1);

    // Nilai Akhir Terhitung
    // Bobot: Presensi 10%, Tugas 20%, UH 20%, UTS 25%, UKK 25%
    $final_grade = ($att_score * 0.10) + ($as_score * 0.20) + ($uh_score * 0.20) + ($uts_score * 0.25) + ($ukk_score * 0.25);
    $final_grade = round($final_grade, 1);

    // Predikat
    if ($final_grade >= 85) {
        $predikat = 'A';
        $status_pass = 'Sangat Baik';
    } elseif ($final_grade >= 75) {
        $predikat = 'B';
        $status_pass = 'Baik / Tuntas';
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

$page_title = "Buku Rekap Nilai Akademik Terpadu";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-purple-500/20 text-purple-400 text-lg border border-purple-500/30 shadow-lg shadow-purple-500/10">
                <i class="fa-solid fa-chart-column"></i>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Buku Rekap Nilai Gabungan</h1>
                <p class="text-sm text-slate-400">
                    Akumulasi Nilai: Presensi (10%) + Tugas (20%) + UH/SLM CBT (20%) + UTS/STS CBT (25%) + UKK/SAS/SAT CBT (25%)
                </p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-2 flex-wrap">
        <button onclick="document.getElementById('modalCbtSync').classList.remove('hidden')" 
                class="rounded-xl border border-cyan-500/30 bg-cyan-500/10 hover:bg-cyan-500/20 px-3.5 py-2 text-xs font-semibold text-cyan-300 transition flex items-center gap-1.5 shadow-sm cursor-pointer">
            <i class="fa-solid fa-laptop-code text-cyan-400"></i> Status Ujian Online (CBT)
        </button>

        <a href="gradebook_export.php?class_id=<?= $selected_class_id ?>&subject=<?= urlencode($selected_subject) ?>" 
           class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3.5 py-2 text-xs font-semibold text-emerald-300 transition flex items-center gap-1.5 shadow-sm">
            <i class="fa-solid fa-file-excel text-emerald-400"></i> Export Excel
        </a>
        <a href="gradebook_print.php?class_id=<?= $selected_class_id ?>&subject=<?= urlencode($selected_subject) ?>" 
           target="_blank"
           class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs font-semibold text-slate-300 transition flex items-center gap-1.5">
            <i class="fa-solid fa-print"></i> Cetak Leger
        </a>
        <?php if ($selected_class_id > 0): ?>
            <a href="report_card_bulk.php?class_id=<?= $selected_class_id ?>" 
               target="_blank"
               class="rounded-xl border border-purple-500/30 bg-purple-500/10 hover:bg-purple-500/20 px-3.5 py-2 text-xs font-semibold text-purple-300 transition flex items-center gap-1.5 shadow-sm">
                <i class="fa-solid fa-graduation-cap text-purple-400"></i> Cetak E-Rapor Kelas
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filter Kelas & Mata Pelajaran -->
<div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur shadow-xl mb-6">
    <form method="GET" action="gradebook.php" class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 flex-wrap">
        <div class="flex flex-wrap items-center gap-3">
            <div>
                <label class="block text-[11px] font-semibold uppercase text-slate-400 mb-1">Rombel / Kelas:</label>
                <select name="class_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                    <option value="0">-- Semua Rombel --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $selected_class_id === (int)$c['id'] ? 'selected' : '' ?>>
                            Kelas <?= htmlspecialchars($c['name']) ?> (Tingkat <?= $c['grade_level'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-semibold uppercase text-slate-400 mb-1">Mata Pelajaran:</label>
                <select name="subject" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-purple-500 focus:outline-none">
                    <option value="">-- Semua Mata Pelajaran (Rata-Rata Umum) --</option>
                    <?php foreach ($subjects_list as $sbj): ?>
                        <option value="<?= htmlspecialchars($sbj) ?>" <?= $selected_subject === $sbj ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sbj) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="text-xs text-slate-400 text-left sm:text-right">
            <div>Tahun Ajaran: <strong class="text-white"><?= htmlspecialchars($school_info['academic_year']) ?></strong></div>
            <div class="text-[11px] text-purple-400 font-mono mt-0.5">
                Mode: <?= !empty($selected_subject) ? "Spesifik Mapel " . htmlspecialchars($selected_subject) : "Akumulasi Rerata Seluruh Mapel" ?>
            </div>
        </div>
    </form>
</div>

<!-- Tabel Master Gradebook -->
<div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-xl backdrop-blur">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
            <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-6 py-4">No</th>
                    <th class="px-6 py-4">Nama Siswa</th>
                    <th class="px-4 py-4 text-center">Kelas</th>
                    <th class="px-4 py-4 text-center">Presensi (10%)</th>
                    <th class="px-4 py-4 text-center">Tugas (20%)</th>
                    <th class="px-4 py-4 text-center">UH / SLM (20%)</th>
                    <th class="px-4 py-4 text-center">UTS / STS (25%)</th>
                    <th class="px-4 py-4 text-center">UKK / SAS (25%)</th>
                    <th class="px-6 py-4 text-center font-bold text-white">Nilai Akhir</th>
                    <th class="px-4 py-4 text-center">Predikat</th>
                    <th class="px-4 py-4 text-center">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php if (empty($gradebook_data)): ?>
                    <tr>
                        <td colspan="11" class="px-6 py-12 text-center text-slate-400">
                            Tidak ada data siswa pada filter ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($gradebook_data as $idx => $row): 
                        $stu = $row['student'];
                        $final = $row['final_grade'];
                    ?>
                        <tr class="hover:bg-white/[0.02] transition">
                            <td class="px-6 py-4 text-xs font-mono text-slate-500"><?= $idx + 1 ?></td>
                            <td class="px-6 py-4">
                                <div class="font-semibold text-white"><?= htmlspecialchars($stu['name']) ?></div>
                                <div class="text-[11px] font-mono text-slate-400">NISN: <?= htmlspecialchars($stu['nisn'] ?: '-') ?></div>
                            </td>
                            <td class="px-4 py-4 text-center text-xs font-medium text-slate-300">
                                <?= htmlspecialchars($stu['class_name'] ?: 'Umum') ?>
                            </td>
                            <td class="px-4 py-4 text-center text-xs font-mono">
                                <span class="<?= $row['attendance'] >= 75 ? 'text-emerald-400' : 'text-rose-400 font-bold' ?>">
                                    <?= $row['attendance'] ?>%
                                </span>
                            </td>
                            <td class="px-4 py-4 text-center text-xs font-mono"><?= $row['assignments'] ?></td>
                            <td class="px-4 py-4 text-center text-xs font-mono text-cyan-300"><?= $row['daily_exams'] ?></td>
                            <td class="px-4 py-4 text-center text-xs font-mono text-purple-300"><?= $row['uts'] ?></td>
                            <td class="px-4 py-4 text-center text-xs font-mono text-indigo-300"><?= $row['ukk'] ?></td>
                            <td class="px-6 py-4 text-center font-bold font-mono text-base <?= $final >= 75 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                <?= $final ?>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span class="inline-flex items-center justify-center rounded-lg border px-2.5 py-0.5 text-xs font-bold 
                                    <?= $row['predikat'] === 'A' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-400' : 
                                       ($row['predikat'] === 'B' ? 'border-blue-500/30 bg-blue-500/10 text-blue-400' : 
                                       ($row['predikat'] === 'C' ? 'border-amber-500/30 bg-amber-500/10 text-amber-400' : 
                                       'border-rose-500/30 bg-rose-500/10 text-rose-400')) ?>">
                                    <?= $row['predikat'] ?>
                                </span>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <a href="report_card.php?student_id=<?= $stu['id'] ?>" class="inline-flex items-center gap-1 rounded-lg border border-purple-500/30 bg-purple-500/10 px-2.5 py-1 text-xs font-semibold text-purple-300 hover:bg-purple-500/20 transition">
                                    <i class="fa-solid fa-file-invoice"></i> E-Rapor
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Informasi Sinkronisasi CBT Online -->
<div id="modalCbtSync" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm hidden">
    <div class="w-full max-w-2xl rounded-3xl border border-white/10 bg-slate-900 p-6 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-laptop-code text-cyan-400"></i> Sinkronisasi Hasil Ujian CBT ke Buku Nilai
            </h3>
            <button onclick="document.getElementById('modalCbtSync').classList.add('hidden')" class="text-slate-400 hover:text-white cursor-pointer">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <div class="mt-4 space-y-3">
            <p class="text-xs text-slate-300 leading-relaxed">
                Seluruh pengerjaan siswa pada <strong>Modul Ujian Online (CBT)</strong> otomatis terhubung langsung ke kolom <em>UH CBT</em>, <em>UTS CBT</em>, dan <em>UKK CBT</em> tanpa perlu input nilai manual ganda.
            </p>

            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 pt-2">Daftar Paket Ujian Terhubung:</h4>
            <div class="space-y-2">
                <?php if (empty($cbt_exams)): ?>
                    <p class="text-xs text-slate-500 italic py-2">Belum ada paket ujian online yang dibuat pada mata pelajaran ini.</p>
                <?php else: ?>
                    <?php foreach ($cbt_exams as $ce): ?>
                        <div class="p-3 rounded-xl border border-white/5 bg-slate-950 flex items-center justify-between text-xs">
                            <div>
                                <span class="font-bold text-white block"><?= htmlspecialchars($ce['title']) ?> (<?= htmlspecialchars($ce['subject']) ?>)</span>
                                <span class="text-[11px] text-slate-400 uppercase">Kategori: <?= htmlspecialchars($ce['category']) ?> &bull; Durasi: <?= $ce['duration_minutes'] ?> mnt</span>
                            </div>
                            <div class="text-right">
                                <span class="text-emerald-400 font-bold block"><?= $ce['total_takers'] ?> Siswa Submit</span>
                                <span class="text-[11px] text-slate-500 font-mono">Rerata: <?= round((float)$ce['avg_cbt_score'], 1) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-6 pt-4 border-t border-white/10 flex items-center justify-between">
            <a href="../Modul-ujian/exams.php" class="text-xs font-bold text-cyan-400 hover:text-cyan-300">
                Buka Manajemen Ujian Online (CBT) &rarr;
            </a>
            <button onclick="document.getElementById('modalCbtSync').classList.add('hidden')" class="rounded-xl bg-slate-800 hover:bg-slate-700 px-4 py-2 text-xs font-bold text-white transition cursor-pointer">
                Tutup
            </button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
