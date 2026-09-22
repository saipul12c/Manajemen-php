<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';

$school_info = getSchoolSettings($pdo);

// Tentukan siswa
$target_student_id = $user_id;
if (in_array($user_role, ['guru', 'staf', 'administrator'], true) && isset($_GET['student_id'])) {
    $target_student_id = (int)$_GET['student_id'];
} elseif ($user_role === 'orang_tua') {
    $stmt_c = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
    $stmt_c->execute([$user_id]);
    $child_id = $stmt_c->fetchColumn();
    if ($child_id) {
        $target_student_id = (int)$child_id;
    }
}

// Ambil data siswa
$stmt_stu = $pdo->prepare("
    SELECT u.*, c.name as class_name 
    FROM users u 
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE u.id = ? AND u.role = 'siswa'
");
$stmt_stu->execute([$target_student_id]);
$student = $stmt_stu->fetch();

if (!$student) {
    die("Data siswa tidak ditemukan.");
}

// Rekap Kehadiran
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

// Ambil semua mata pelajaran dari assignments & exams
$subjects_list = [
    'Matematika'        => ['kkm' => 75, 'guru' => 'Dewi Lestari, M.Pd'],
    'Bahasa Indonesia'  => ['kkm' => 75, 'guru' => 'Dewi Lestari, M.Pd'],
    'Biologi'           => ['kkm' => 70, 'guru' => 'Dewi Lestari, M.Pd'],
    'Bahasa Inggris'    => ['kkm' => 65, 'guru' => 'Dewi Lestari, M.Pd'],
    'Fisika'            => ['kkm' => 70, 'guru' => 'Dewi Lestari, M.Pd'],
    'Sejarah'           => ['kkm' => 75, 'guru' => 'Dewi Lestari, M.Pd'],
];

$report_grades = [];
$total_final_sum = 0;

foreach ($subjects_list as $subj => $meta) {
    // Nilai Tugas
    $stmt_as = $pdo->prepare("
        SELECT AVG(COALESCE(s.score, 80)) as avg_score 
        FROM assignment_submissions s
        JOIN assignments a ON s.assignment_id = a.id
        WHERE s.student_id = ? AND a.subject = ? AND s.status = 'selesai'
    ");
    $stmt_as->execute([$target_student_id, $subj]);
    $tugas_val = round((float)($stmt_as->fetchColumn() ?: 80.0), 1);

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

    // Default jika belum ujian
    if ($uh_val == 0) $uh_val = $tugas_val;
    if ($uts_val == 0) $uts_val = max(75.0, $uh_val);
    if ($ukk_val == 0) $ukk_val = max(75.0, $uh_val);

    // Hitung Nilai Akhir
    $na = ($tugas_val * 0.3) + ($uh_val * 0.2) + ($uts_val * 0.25) + ($ukk_val * 0.25);
    $na = round($na, 1);
    $total_final_sum += $na;

    $pred = ($na >= 85) ? 'A' : (($na >= 75) ? 'B' : (($na >= 65) ? 'C' : 'D'));
    $deskripsi = ($na >= 85) ? 'Sangat baik dalam memahami materi dan aktif berdiskusi.' : (($na >= 75) ? 'Menguasai kompetensi dasar dengan baik, pertahankan capaian.' : 'Cukup memahami materi, perlu peningkatan latihan soal.');

    $report_grades[] = [
        'subject'    => $subj,
        'kkm'        => $meta['kkm'],
        'guru'       => $meta['guru'],
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

$page_title = "Rapor Hasil Belajar - " . htmlspecialchars($student['name']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        @media print {
            body { background: white !important; color: black !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .print-paper { border: none !important; box-shadow: none !important; max-width: 100% !important; padding: 0 !important; }
            table { border-color: #333 !important; }
            th, td { border-color: #666 !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center p-4 sm:p-8 font-sans antialiased">

    <!-- Action Bar -->
    <div class="no-print w-full max-w-4xl mb-6 flex items-center justify-between">
        <a href="../index.php" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
            ← Kembali ke Dashboard
        </a>
        <button onclick="window.print()" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition flex items-center gap-2">
            <span>🖨️</span> Cetak / Simpan PDF
        </button>
    </div>

    <!-- LEMBAR RAPOR RESMI -->
    <div class="print-paper w-full max-w-4xl rounded-3xl border border-white/15 bg-slate-900/95 p-8 sm:p-12 shadow-2xl backdrop-blur">
        
        <!-- Kop Rapor -->
        <div class="text-center border-b-2 border-white/20 pb-6 mb-6">
            <h1 class="text-2xl sm:text-3xl font-black uppercase tracking-tight text-white">
                <?= htmlspecialchars($school_info['school_name']) ?>
            </h1>
            <p class="text-xs text-slate-300 mt-1"><?= htmlspecialchars($school_info['school_address']) ?></p>
            <p class="text-xs text-slate-400 font-mono">Telepon: <?= htmlspecialchars($school_info['school_phone']) ?> | Email: <?= htmlspecialchars($school_info['school_email']) ?></p>
            <div class="inline-block mt-3 px-4 py-1 rounded-full border border-white/20 bg-white/5 text-xs font-bold uppercase tracking-widest text-blue-400">
                Laporan Hasil Belajar Peserta Didik (Rapor)
            </div>
        </div>

        <!-- Identitas Siswa -->
        <div class="grid grid-cols-2 gap-4 text-xs mb-6 bg-slate-950/60 p-4 rounded-2xl border border-white/10">
            <div class="space-y-1.5">
                <div class="flex"><span class="w-32 text-slate-400">Nama Siswa</span><span class="font-bold text-white">: <?= htmlspecialchars($student['name']) ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Nomor Induk / NISN</span><span class="font-mono text-slate-200">: <?= htmlspecialchars($student['nisn'] ?: '0081234567') ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Kelas / Rombel</span><span class="font-semibold text-slate-200">: <?= htmlspecialchars($student['class_name'] ?? 'X MIPA 1') ?></span></div>
            </div>
            <div class="space-y-1.5">
                <div class="flex"><span class="w-32 text-slate-400">Semester</span><span class="font-semibold text-white">: <?= htmlspecialchars($school_info['academic_year']) ?></span></div>
                <div class="flex"><span class="w-32 text-slate-400">Tahun Pelajaran</span><span class="text-slate-200">: 2026/2027</span></div>
                <div class="flex"><span class="w-32 text-slate-400">Rata-rata Nilai</span><span class="font-bold text-emerald-400">: <?= $average_score ?> (Predikat <?= $average_score >= 85 ? 'A' : 'B' ?>)</span></div>
            </div>
        </div>

        <!-- Tabel Capaian Akademik -->
        <div class="mb-6">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">A. Nilai Pengetahuan & Keterampilan</h3>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="border-b border-white/10 bg-white/5 text-[10px] uppercase font-bold text-slate-400">
                        <tr>
                            <th class="px-4 py-3">No</th>
                            <th class="px-4 py-3">Mata Pelajaran</th>
                            <th class="px-3 py-3 text-center">KKM</th>
                            <th class="px-3 py-3 text-center">Tugas</th>
                            <th class="px-3 py-3 text-center">UH</th>
                            <th class="px-3 py-3 text-center">UTS</th>
                            <th class="px-3 py-3 text-center">UKK</th>
                            <th class="px-3 py-3 text-center font-bold text-white">Akhir</th>
                            <th class="px-3 py-3 text-center">Pred</th>
                            <th class="px-4 py-3">Capaian Kompetensi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($report_grades as $i => $rg): ?>
                            <tr>
                                <td class="px-4 py-3 font-mono text-slate-500"><?= $i + 1 ?></td>
                                <td class="px-4 py-3 font-semibold text-white"><?= htmlspecialchars($rg['subject']) ?></td>
                                <td class="px-3 py-3 text-center font-mono text-slate-400"><?= $rg['kkm'] ?></td>
                                <td class="px-3 py-3 text-center font-mono"><?= $rg['tugas'] ?></td>
                                <td class="px-3 py-3 text-center font-mono"><?= $rg['uh'] ?></td>
                                <td class="px-3 py-3 text-center font-mono"><?= $rg['uts'] ?></td>
                                <td class="px-3 py-3 text-center font-mono"><?= $rg['ukk'] ?></td>
                                <td class="px-3 py-3 text-center font-mono font-bold <?= $rg['na'] >= $rg['kkm'] ? 'text-emerald-300' : 'text-rose-400' ?>">
                                    <?= number_format($rg['na'], 1) ?>
                                </td>
                                <td class="px-3 py-3 text-center font-bold"><?= $rg['predikat'] ?></td>
                                <td class="px-4 py-3 text-[11px] text-slate-400 leading-snug"><?= htmlspecialchars($rg['deskripsi']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Rekapitulasi Presensi / Ketidakhadiran -->
        <div class="mb-8 grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">B. Ketidakhadiran (Presensi)</h3>
                <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4 text-xs space-y-2">
                    <div class="flex justify-between border-b border-white/5 pb-1"><span>1. Sakit (S)</span><strong class="font-mono"><?= $att['sakit'] ?> hari</strong></div>
                    <div class="flex justify-between border-b border-white/5 pb-1"><span>2. Izin (I)</span><strong class="font-mono"><?= $att['izin'] ?> hari</strong></div>
                    <div class="flex justify-between border-b border-white/5 pb-1"><span>3. Tanpa Keterangan (A)</span><strong class="font-mono text-rose-400"><?= $att['alpa'] ?> hari</strong></div>
                    <div class="flex justify-between pt-1"><span>Total Kehadiran Efektif</span><strong class="font-mono text-emerald-400"><?= $att['hadir'] ?> hari</strong></div>
                </div>
            </div>

            <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">C. Catatan Perkembangan Karakter</h3>
                <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4 text-xs text-slate-400 leading-relaxed italic">
                    "Ananda menunjukkan sikap religius, disiplin yang tinggi, dan senantiasa bersemangat dalam menyelesaikan penugasan belajar daring maupun luring. Pertahankan prestasi dan ketekunan belajar di semester berikutnya."
                </div>
            </div>
        </div>

        <!-- Tanda Tangan Tiga Pihak -->
        <div class="grid grid-cols-3 gap-4 text-center text-xs text-slate-300 pt-6 border-t border-white/10">
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
                <p class="text-[10px] text-slate-500 font-mono">NIP. 19820415 200801 2 015</p>
            </div>
            <div>
                <p class="text-slate-400">Mengetahui,</p>
                <p class="font-semibold">Kepala Sekolah,</p>
                <div class="h-16"></div>
                <p class="font-bold underline text-white"><?= htmlspecialchars($school_info['headmaster_name']) ?></p>
                <p class="text-[10px] text-slate-500 font-mono">NIP. <?= htmlspecialchars($school_info['headmaster_nip']) ?></p>
            </div>
        </div>

    </div>

</body>
</html>
