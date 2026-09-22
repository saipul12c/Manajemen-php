<?php
session_start();
require_once __DIR__ . "/../config/database.php";

requireRole(['guru', 'administrator']);

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'guru';

$school_info = getSchoolSettings($pdo);

// Ambil daftar kelas untuk filter
$classes = $pdo->query("SELECT * FROM classes ORDER BY grade_level ASC, name ASC")->fetchAll();
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

// Query siswa
$sql_students = "SELECT u.id, u.name, u.email, u.nisn, u.gender, c.name as class_name 
                 FROM users u 
                 LEFT JOIN classes c ON u.class_id = c.id
                 WHERE u.role = 'siswa'";
if ($selected_class_id > 0) {
    $sql_students .= " AND u.class_id = $selected_class_id";
}
$sql_students .= " ORDER BY u.name ASC";
$students = $pdo->query($sql_students)->fetchAll();

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

    // 2. Rata-rata Tugas
    $stmt_as = $pdo->prepare("
        SELECT AVG(COALESCE(score, 80)) as avg_score, COUNT(*) as count_done 
        FROM assignment_submissions 
        WHERE student_id = ? AND status = 'selesai'
    ");
    $stmt_as->execute([$s_id]);
    $as_row = $stmt_as->fetch();
    $as_score = $as_row['avg_score'] !== null ? round((float)$as_row['avg_score'], 1) : 0.0;

    // 3. Rata-rata Ulangan Harian
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

    // Nilai Akhir Terhitung
    // Bobot: Presensi 10%, Tugas 20%, UH 20%, UTS 25%, UKK 25%
    $final_grade = ($att_score * 0.10) + ($as_score * 0.20) + ($uh_score * 0.20) + ($uts_score * 0.25) + ($ukk_score * 0.25);
    $final_grade = round($final_grade, 1);

    // Predikat
    if ($final_grade >= 85) {
        $predikat = 'A';
        $status_pass = 'Lulus Sangat Baik';
    } elseif ($final_grade >= 75) {
        $predikat = 'B';
        $status_pass = 'Lulus Baik';
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

$page_title = "Buku Rekap Nilai Akademik";
require_once __DIR__ . "/includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-purple-500/20 text-purple-400 text-xl border border-purple-500/30 shadow-lg shadow-purple-500/10">
                📊
            </span>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Buku Rekap Nilai Gabungan</h1>
                <p class="text-sm text-slate-400">
                    Akumulasi Nilai: Presensi (10%) + Tugas (20%) + Ulangan Harian (20%) + UTS (25%) + UKK (25%)
                </p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-2">
        <button onclick="window.print()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition">
            🖨️ Cetak Buku Nilai
        </button>
        <a href="assignments.php" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2 text-xs font-semibold text-white transition">
            📚 Kelola Tugas
        </a>
    </div>
</div>

<!-- Filter Kelas -->
<div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur shadow-xl mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <form method="GET" action="gradebook.php" class="flex items-center gap-3">
            <label class="text-xs font-semibold uppercase text-slate-400">Filter Rombel / Kelas:</label>
            <select name="class_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                <option value="0">-- Semua Siswa Terdaftar --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selected_class_id === (int)$c['id'] ? 'selected' : '' ?>>
                        Kelas <?= htmlspecialchars($c['name']) ?> (Tingkat <?= $c['grade_level'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="text-xs text-slate-400">
            Tahun Ajaran: <strong class="text-white"><?= htmlspecialchars($school_info['academic_year']) ?></strong>
        </div>
    </div>
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
                    <th class="px-4 py-4 text-center">UH (20%)</th>
                    <th class="px-4 py-4 text-center">UTS (25%)</th>
                    <th class="px-4 py-4 text-center">UKK (25%)</th>
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
                            <td class="px-6 py-4 font-mono text-xs text-slate-500"><?= $idx + 1 ?></td>
                            <td class="px-6 py-4">
                                <p class="font-bold text-white"><?= htmlspecialchars($stu['name']) ?></p>
                                <p class="text-xs text-slate-400 font-mono">NISN: <?= htmlspecialchars($stu['nisn'] ?: '-') ?></p>
                            </td>
                            <td class="px-4 py-4 text-center text-xs">
                                <span class="px-2.5 py-1 rounded-lg border border-white/10 bg-white/5 text-slate-300">
                                    <?= htmlspecialchars($stu['class_name'] ?? 'Reguler') ?>
                                </span>
                            </td>
                            <td class="px-4 py-4 text-center font-mono text-xs">
                                <?= $row['attendance'] ?>%
                            </td>
                            <td class="px-4 py-4 text-center font-mono text-xs">
                                <?= $row['assignments'] ?>
                            </td>
                            <td class="px-4 py-4 text-center font-mono text-xs">
                                <?= $row['daily_exams'] ?>
                            </td>
                            <td class="px-4 py-4 text-center font-mono text-xs">
                                <?= $row['uts'] ?>
                            </td>
                            <td class="px-4 py-4 text-center font-mono text-xs">
                                <?= $row['ukk'] ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="text-base font-extrabold <?= $final >= 75 ? 'text-emerald-300' : 'text-rose-400' ?>">
                                    <?= number_format($final, 1) ?>
                                </span>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span class="inline-block px-2.5 py-1 rounded-lg text-xs font-black <?= $row['predikat'] === 'A' ? 'border border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : ($row['predikat'] === 'B' ? 'border border-blue-500/30 bg-blue-500/10 text-blue-300' : 'border border-amber-500/30 bg-amber-500/10 text-amber-300') ?>">
                                    <?= $row['predikat'] ?>
                                </span>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <a href="report_card.php?student_id=<?= $stu['id'] ?>" 
                                   target="_blank"
                                   class="inline-flex items-center gap-1 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-2.5 py-1 text-xs text-blue-400 font-semibold transition">
                                    <span>📄</span> Rapor
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
