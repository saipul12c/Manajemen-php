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
    $req_child = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
    if ($req_child > 0) {
        $stmt_verify = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1");
        $stmt_verify->execute([$user_id, $req_child]);
        if ($stmt_verify->fetchColumn()) {
            $target_student_id = $req_child;
        }
    }
    if ($target_student_id === $user_id) {
        $stmt_c = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
        $stmt_c->execute([$user_id]);
        $child_id = $stmt_c->fetchColumn();
        if ($child_id) {
            $target_student_id = (int)$child_id;
        }
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
    $page_title = "Data Tidak Ditemukan";
    require_once __DIR__ . "/../includes/header.php";
    ?>
    <div class="max-w-md mx-auto my-12 rounded-3xl border border-rose-500/30 bg-slate-900/90 p-8 text-center shadow-2xl">
        <i class="fa-solid fa-triangle-exclamation text-rose-400 text-4xl"></i>
        <h2 class="text-lg font-bold text-white mt-3">Data Siswa Tidak Ditemukan</h2>
        <p class="text-sm text-slate-400 mt-2">ID siswa yang dicari tidak tersedia atau bukan berstatus siswa.</p>
        <a href="../index.php" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 px-6 py-2 text-xs font-bold text-white transition">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard
        </a>
    </div>
    <?php
    require_once __DIR__ . "/../includes/footer.php";
    exit;
}

// 1. Hitung Presensi
$stmt_att = $pdo->prepare("SELECT COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir, COUNT(*) as total FROM student_attendance WHERE student_id = ?");
$stmt_att->execute([$target_student_id]);
$att = $stmt_att->fetch();
$att_pct = ($att && $att['total'] > 0) ? round(($att['hadir'] / $att['total']) * 100, 1) : 100.0;
$is_att_eligible = ($att_pct >= 75.0);

// 2. Evaluasi Bebas Tanggungan Terpadu (Clearance Gatekeeper 3-in-1)
$clearance = getStudentClearanceStatus($pdo, $target_student_id);
$is_fin_cleared = $clearance['finance']['status'];
$is_lib_cleared = $clearance['library']['status'];
$is_bk_cleared  = $clearance['counseling']['status'];

$is_fully_cleared = $is_att_eligible && $clearance['is_cleared'];
$can_bypass = in_array($user_role, ['administrator', 'guru', 'staf'], true);

// Jika siswa atau orang tua mengakses dan belum memenuhi syarat clearance, tampilkan penangguhan kartu ujian
if (!$is_fully_cleared && !$can_bypass) {
    $page_title = "Penerbitan Kartu Ujian Ditangguhkan";
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= $page_title ?></title>
        <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4 font-sans antialiased">
        <div class="max-w-xl w-full rounded-3xl border border-amber-500/30 bg-slate-900/95 p-6 sm:p-8 shadow-2xl backdrop-blur">
            <div class="text-center pb-5 border-b border-white/10">
                <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-500/20 text-amber-400 text-3xl mb-3">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <h1 class="text-2xl font-black text-white">Kartu Ujian Belum Dapat Dicetak</h1>
                <p class="text-xs text-slate-400 mt-1">
                    Sistem mendeteksi adanya tanggungan administrasi atau presensi yang belum terpenuhi untuk ananda <strong class="text-white"><?= htmlspecialchars($student['name']) ?></strong>.
                </p>
            </div>

            <!-- Rincian Tanggungan -->
            <div class="my-6 space-y-3">
                <!-- Keuangan -->
                <div class="flex items-center justify-between p-3.5 rounded-2xl border <?= $is_fin_cleared ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-rose-500/30 bg-rose-500/10' ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?= $is_fin_cleared ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-rose-400' ?> text-lg"></i>
                        <div>
                            <p class="text-xs font-bold text-white">Administrasi Keuangan & SPP</p>
                            <p class="text-[11px] text-slate-400">
                                <?= $is_fin_cleared ? 'Lunas tanpa tunggakan' : 'Terdapat ' . $clearance['finance']['unpaid_bills'] . ' tagihan belum lunas (' . formatRupiah($clearance['finance']['total_unpaid']) . ')' ?>
                            </p>
                        </div>
                    </div>
                    <?php if (!$is_fin_cleared): ?>
                        <a href="../keuangan/payments.php" class="text-[11px] font-bold text-rose-400 hover:text-rose-300 underline">Bayar &rarr;</a>
                    <?php endif; ?>
                </div>

                <!-- Perpustakaan -->
                <div class="flex items-center justify-between p-3.5 rounded-2xl border <?= $is_lib_cleared ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-rose-500/30 bg-rose-500/10' ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?= $is_lib_cleared ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-rose-400' ?> text-lg"></i>
                        <div>
                            <p class="text-xs font-bold text-white">Sirkulasi Perpustakaan</p>
                            <p class="text-[11px] text-slate-400">
                                <?= $is_lib_cleared ? 'Bebas pinjaman & denda' : 'Meminjam ' . $clearance['library']['active_loans'] . ' buku / denda ' . formatRupiah($clearance['library']['fines']) ?>
                            </p>
                        </div>
                    </div>
                    <?php if (!$is_lib_cleared): ?>
                        <a href="../perpustakaan/clearance.php" class="text-[11px] font-bold text-rose-400 hover:text-rose-300 underline">Cek &rarr;</a>
                    <?php endif; ?>
                </div>

                <!-- Presensi -->
                <div class="flex items-center justify-between p-3.5 rounded-2xl border <?= $is_att_eligible ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-rose-500/30 bg-rose-500/10' ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?= $is_att_eligible ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-rose-400' ?> text-lg"></i>
                        <div>
                            <p class="text-xs font-bold text-white">Presensi Kehadiran KBM</p>
                            <p class="text-[11px] text-slate-400">
                                <?= $att_pct ?>% kehadiran (Batas minimal standar: 75.0%)
                            </p>
                        </div>
                    </div>
                </div>

                <!-- BK -->
                <div class="flex items-center justify-between p-3.5 rounded-2xl border <?= $is_bk_cleared ? 'border-emerald-500/30 bg-emerald-500/10' : 'border-rose-500/30 bg-rose-500/10' ?>">
                    <div class="flex items-center gap-3">
                        <i class="fa-solid <?= $is_bk_cleared ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-rose-400' ?> text-lg"></i>
                        <div>
                            <p class="text-xs font-bold text-white">Kedisiplinan & Bimbingan Konseling</p>
                            <p class="text-[11px] text-slate-400">
                                <?= $is_bk_cleared ? 'Catatan disiplin memenuhi syarat' : 'Poin pelanggaran: ' . $clearance['counseling']['points'] . ' (Perlu pembinaan BK)' ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-white/10 flex items-center justify-between">
                <a href="../index.php" class="text-xs text-slate-400 hover:text-white transition">
                    &larr; Kembali ke Dashboard
                </a>
                <span class="text-[11px] text-slate-500">Hubungi Tata Usaha untuk informasi dispensasi</span>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Ambil jadwal ujian aktif (disesuaikan dengan kelas siswa jika ada & kecualikan ujian PPDB)
$student_class_id = !empty($student['class_id']) ? (int)$student['class_id'] : 0;
if ($student_class_id > 0) {
    $stmt_active_ex = $pdo->prepare("
        SELECT * FROM exams 
        WHERE status = 'aktif' 
          AND (is_ppdb IS NULL OR is_ppdb = 0) 
          AND (class_id IS NULL OR class_id = 0 OR class_id = ?) 
        ORDER BY start_time ASC, due_date ASC
    ");
    $stmt_active_ex->execute([$student_class_id]);
    $active_exams = $stmt_active_ex->fetchAll();
} else {
    $stmt_active_ex = $pdo->prepare("
        SELECT * FROM exams 
        WHERE status = 'aktif' 
          AND (is_ppdb IS NULL OR is_ppdb = 0) 
          AND (class_id IS NULL OR class_id = 0) 
        ORDER BY start_time ASC, due_date ASC
    ");
    $stmt_active_ex->execute();
    $active_exams = $stmt_active_ex->fetchAll();
}

$page_title = "Kartu Peserta Ujian - " . htmlspecialchars($student['name']);
$exam_token = "EXAM-" . date('Y') . "-" . str_pad((string)$student['id'], 4, '0', STR_PAD_LEFT) . "-" . ($student['nisn'] ?: 'REG');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body { background: white !important; color: black !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .print-card { border: 2px solid #000 !important; box-shadow: none !important; margin: 0 !important; max-width: 100% !important; background: white !important; color: black !important; }
            .print-card * { color: black !important; border-color: #cbd5e1 !important; }
            .print-badge { border: 1px solid #000 !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center justify-center p-4 sm:p-6 font-sans antialiased">

    <!-- Action Bar -->
    <div class="no-print w-full max-w-2xl mb-4 flex items-center justify-between gap-2 flex-wrap">
        <a href="../index.php" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition inline-flex items-center gap-1.5">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard
        </a>

        <?php if (!$is_fully_cleared && $can_bypass): ?>
            <span class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-xs font-bold text-amber-300 flex items-center gap-1.5">
                <i class="fa-solid fa-circle-exclamation"></i> Dicetak dengan Izin / Dispensasi Staf
            </span>
        <?php endif; ?>

        <button onclick="window.print()" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition flex items-center gap-2 cursor-pointer">
            <i class="fa-solid fa-print"></i> Cetak Kartu Ujian
        </button>
    </div>

    <!-- KARTU UJIAN DIGITAL -->
    <div class="print-card w-full max-w-2xl rounded-3xl border-2 border-white/15 bg-slate-900/95 p-6 sm:p-8 shadow-2xl backdrop-blur relative overflow-hidden">
        
        <!-- Header Kop Kartu -->
        <div class="flex items-center justify-between border-b-2 border-white/10 pb-6 mb-6">
            <div class="flex items-center gap-4">
                <?php 
                    $clean_exm_logo = !empty($school_info['school_logo']) ? resolveAssetUrl($school_info['school_logo'], '../../') : '';
                    $exm_logo_exists = !empty($clean_exm_logo) && (strpos($clean_exm_logo, 'http') === 0 || file_exists(dirname(__DIR__, 2) . '/' . resolveAssetUrl($school_info['school_logo'])));
                ?>
                <?php if ($exm_logo_exists): ?>
                    <img src="<?= htmlspecialchars($clean_exm_logo) ?>" alt="Logo" class="h-14 w-14 object-contain shrink-0">
                <?php else: ?>
                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-600 text-white text-2xl font-extrabold shadow-lg shadow-blue-500/30 shrink-0">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </span>
                <?php endif; ?>
                <div>
                    <h2 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-white leading-tight">
                        <?= htmlspecialchars($school_info['school_name']) ?>
                    </h2>
                    <p class="text-xs text-slate-400"><?= htmlspecialchars($school_info['school_address']) ?></p>
                    <p class="text-[11px] text-slate-500 font-mono">Telp: <?= htmlspecialchars($school_info['school_phone']) ?> • Email: <?= htmlspecialchars($school_info['school_email']) ?></p>
                </div>
            </div>
            <div class="hidden sm:block text-right shrink-0">
                <span class="print-badge inline-block rounded-xl border border-purple-500/30 bg-purple-500/10 px-3 py-1 text-xs font-bold uppercase tracking-wider text-purple-300">
                    Kartu Peserta Ujian
                </span>
                <p class="text-[10px] text-slate-400 font-mono mt-1">TA: <?= htmlspecialchars($school_info['academic_year']) ?></p>
            </div>
        </div>

        <!-- Identitas Siswa -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-6 items-center">
            <div class="sm:col-span-2 space-y-2 text-xs">
                <div class="grid grid-cols-3">
                    <span class="text-slate-400 font-medium">Nomor Peserta</span>
                    <span class="col-span-2 font-mono font-bold text-white">: <?= $exam_token ?></span>
                </div>
                <div class="grid grid-cols-3">
                    <span class="text-slate-400 font-medium">Nama Lengkap</span>
                    <span class="col-span-2 font-bold text-white uppercase">: <?= htmlspecialchars($student['name']) ?></span>
                </div>
                <div class="grid grid-cols-3">
                    <span class="text-slate-400 font-medium">NISN / Identitas</span>
                    <span class="col-span-2 font-mono text-slate-200">: <?= htmlspecialchars($student['nisn'] ?: '-') ?></span>
                </div>
                <div class="grid grid-cols-3">
                    <span class="text-slate-400 font-medium">Kelas / Rombel</span>
                    <span class="col-span-2 font-semibold text-slate-200">: <?= htmlspecialchars($student['class_name'] ?? 'Umum') ?></span>
                </div>
                <div class="grid grid-cols-3">
                    <span class="text-slate-400 font-medium">Status Administrasi</span>
                    <span class="col-span-2 font-semibold <?= $is_fully_cleared ? 'text-emerald-400' : 'text-amber-400' ?>">
                        : <?= $is_fully_cleared ? 'Terverifikasi Lengkap (Bebas Tanggungan)' : 'Dispensasi Izin Khusus' ?>
                    </span>
                </div>
            </div>

            <!-- QR Code Digital Verification -->
            <div class="flex flex-col items-center justify-center p-3 rounded-2xl border border-white/10 bg-slate-950/80">
                <div class="p-1.5 bg-white rounded-xl mb-1.5 shadow-sm">
                    <div id="examQrCode"></div>
                </div>
                <span class="font-mono text-[9px] text-slate-400">QR-AUTH-<?= $student['id'] ?></span>
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
                                <td colspan="5" class="px-4 py-6 text-center text-slate-500">Tidak ada jadwal ujian aktif dalam sistem.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($active_exams as $ex): ?>
                                <tr>
                                    <td class="px-4 py-2 font-sans font-semibold text-white"><?= htmlspecialchars($ex['subject']) ?></td>
                                    <td class="px-4 py-2 uppercase text-[10px] text-purple-300"><?= htmlspecialchars(EXAM_CATEGORIES[$ex['category']]['label'] ?? strtoupper($ex['category'])) ?></td>
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
                    * Token ujian hanya akan diberikan kepada siswa yang terdaftar sah.<br>
                    * Bebas tanggungan keuangan, perpustakaan, & disiplin KBM.
                </p>
            </div>
            <div class="text-right">
                <?= renderOfficialSignatureHtml($pdo, 'headmaster', [
                    'doc_type' => 'KARTU_UJIAN',
                    'doc_num' => 'KPU-' . ($exam['exam_code'] ?? 'CBT') . '-' . $student['id'],
                    'student_name' => $student['name'],
                    'date' => date('d F Y')
                ]) ?>
            </div>
        </div>

    </div>

    <!-- Script Generate QR Code -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            new QRCode(document.getElementById("examQrCode"), {
                text: "<?= addslashes($exam_token) ?>",
                width: 75,
                height: 75,
                colorDark : "#0f172a",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
            });
        });
    </script>

</body>
</html>
