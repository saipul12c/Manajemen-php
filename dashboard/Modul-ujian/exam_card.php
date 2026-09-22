<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';

$school_info = getSchoolSettings($pdo);

// Tentukan siswa yang dicetak kartu ujiannya
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

// Hitung presensi
$stmt_att = $pdo->prepare("SELECT COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir, COUNT(*) as total FROM student_attendance WHERE student_id = ?");
$stmt_att->execute([$target_student_id]);
$att = $stmt_att->fetch();
$att_pct = ($att && $att['total'] > 0) ? round(($att['hadir'] / $att['total']) * 100, 1) : 100.0;
$is_eligible = ($att_pct >= 75.0);

// Ambil jadwal ujian aktif
$active_exams = $pdo->query("SELECT * FROM exams WHERE status = 'aktif' ORDER BY start_time ASC, due_date ASC")->fetchAll();

$page_title = "Kartu Peserta Ujian - " . htmlspecialchars($student['name']);
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
            body { background: white !important; color: black !important; }
            .no-print { display: none !important; }
            .print-card { border: 2px solid #000 !important; box-shadow: none !important; margin: 0 !important; max-width: 100% !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center justify-center p-4 sm:p-6 font-sans antialiased">

    <!-- Action Bar -->
    <div class="no-print w-full max-w-2xl mb-6 flex items-center justify-between">
        <a href="../index.php" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
            ← Kembali ke Dashboard
        </a>
        <button onclick="window.print()" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition flex items-center gap-2">
            <span>🖨️</span> Cetak Kartu Ujian
        </button>
    </div>

    <!-- KARTU UJIAN DIGITAL -->
    <div class="print-card w-full max-w-2xl rounded-3xl border-2 border-white/15 bg-slate-900/95 p-6 sm:p-8 shadow-2xl backdrop-blur relative overflow-hidden">
        
        <!-- Header Kop Kartu -->
        <div class="flex items-center justify-between border-b-2 border-white/10 pb-6 mb-6">
            <div class="flex items-center gap-4">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-600 text-white text-3xl font-extrabold shadow-lg shadow-blue-500/30">
                    <?= htmlspecialchars($school_info['school_logo'] ?? '⚡') ?>
                </span>
                <div>
                    <h2 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-white">
                        <?= htmlspecialchars($school_info['school_name']) ?>
                    </h2>
                    <p class="text-xs text-slate-400"><?= htmlspecialchars($school_info['school_address']) ?></p>
                    <p class="text-[11px] text-slate-500 font-mono">Telp: <?= htmlspecialchars($school_info['school_phone']) ?> • Email: <?= htmlspecialchars($school_info['school_email']) ?></p>
                </div>
            </div>
            <div class="hidden sm:block text-right">
                <span class="inline-block rounded-xl border border-purple-500/30 bg-purple-500/10 px-3 py-1 text-xs font-bold uppercase tracking-wider text-purple-300">
                    Kartu Peserta Ujian
                </span>
                <p class="text-[10px] text-slate-400 font-mono mt-1">TA: <?= htmlspecialchars($school_info['academic_year']) ?></p>
            </div>
        </div>

        <!-- Identitas Siswa -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-6">
            <div class="sm:col-span-2 space-y-2 text-xs">
                <div class="grid grid-cols-3">
                    <span class="text-slate-400 font-medium">Nomor Peserta</span>
                    <span class="col-span-2 font-mono font-bold text-white">: 2026-<?= sprintf('%04d', $student['id']) ?>-<?= htmlspecialchars($student['nisn'] ?: 'REG') ?></span>
                </div>
                <div class="grid grid-cols-3">
                    <span class="text-slate-400 font-medium">Nama Lengkap</span>
                    <span class="col-span-2 font-bold text-white uppercase">: <?= htmlspecialchars($student['name']) ?></span>
                </div>
                <div class="grid grid-cols-3">
                    <span class="text-slate-400 font-medium">NISN / Identitas</span>
                    <span class="col-span-2 font-mono text-slate-200">: <?= htmlspecialchars($student['nisn'] ?: '0081234567') ?></span>
                </div>
                <div class="grid grid-cols-3">
                    <span class="text-slate-400 font-medium">Kelas / Rombel</span>
                    <span class="col-span-2 font-semibold text-slate-200">: <?= htmlspecialchars($student['class_name'] ?? 'X MIPA 1') ?></span>
                </div>
                <div class="grid grid-cols-3">
                    <span class="text-slate-400 font-medium">Syarat Kehadiran</span>
                    <span class="col-span-2 font-semibold <?= $is_eligible ? 'text-emerald-400' : 'text-rose-400' ?>">
                        : <?= $att_pct ?>% (<?= $is_eligible ? 'Lolos Verifikasi' : 'Kurang dari 75%' ?>)
                    </span>
                </div>
            </div>

            <!-- Pas Foto / QR Code Box -->
            <div class="flex flex-col items-center justify-center p-3 rounded-2xl border border-white/10 bg-slate-950/80">
                <div class="h-24 w-20 rounded-xl bg-gradient-to-b from-blue-600/30 to-slate-800 border border-blue-500/30 flex flex-col items-center justify-center text-center p-2 mb-2">
                    <span class="text-2xl">👨‍🎓</span>
                    <span class="text-[9px] text-slate-400 mt-1 uppercase font-bold">Foto Siswa</span>
                </div>
                <span class="font-mono text-[9px] text-slate-500">VERIFIED-ID-<?= $student['id'] ?></span>
            </div>
        </div>

        <!-- Jadwal Ujian Aktif -->
        <div class="mb-6">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2.5">
                Jadwal Mata Pelajaran yang Diikuti:
            </h3>
            <div class="overflow-x-auto rounded-2xl border border-white/10 bg-slate-950/60">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="border-b border-white/10 bg-white/5 text-[10px] uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-4 py-2.5">Mata Pelajaran</th>
                            <th class="px-4 py-2.5">Kategori</th>
                            <th class="px-4 py-2.5 text-center">Durasi</th>
                            <th class="px-4 py-2.5">Batas / Waktu</th>
                            <th class="px-4 py-2.5 text-center">Paraf Pengawas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5 font-mono text-[11px]">
                        <?php if (empty($active_exams)): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Tidak ada jadwal ujian aktif.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($active_exams as $ex): ?>
                                <tr>
                                    <td class="px-4 py-2 font-sans font-semibold text-white"><?= htmlspecialchars($ex['subject']) ?></td>
                                    <td class="px-4 py-2 uppercase text-[10px] text-purple-300"><?= htmlspecialchars($ex['category']) ?></td>
                                    <td class="px-4 py-2 text-center"><?= $ex['duration_minutes'] > 0 ? $ex['duration_minutes'] . " mnt" : "Fleksibel" ?></td>
                                    <td class="px-4 py-2 text-slate-400"><?= date('d/m/y H:i', strtotime($ex['due_date'])) ?></td>
                                    <td class="px-4 py-2 text-center border-l border-white/5 text-slate-600">__________</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tanda Tangan & Validasi -->
        <div class="grid grid-cols-2 gap-6 pt-4 border-t border-white/10 text-xs text-slate-400">
            <div>
                <p class="text-[11px] leading-relaxed">
                    * Harap kartu ini dibawa saat pelaksanaan ujian daring/luring.<br>
                    * Token ujian hanya akan diberikan kepada siswa yang terdaftar sah.
                </p>
            </div>
            <div class="text-right">
                <p>Jakarta, <?= date('d F Y') ?></p>
                <p class="mt-1 font-semibold text-white">Kepala Sekolah,</p>
                <div class="h-10"></div>
                <p class="font-bold text-white underline"><?= htmlspecialchars($school_info['headmaster_name']) ?></p>
                <p class="text-[10px] font-mono text-slate-500">NIP. <?= htmlspecialchars($school_info['headmaster_nip']) ?></p>
            </div>
        </div>

    </div>

</body>
</html>
