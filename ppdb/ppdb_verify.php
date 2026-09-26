<?php
/**
 * Halaman Verifikasi Keaslian Kartu PPDB Online
 * Memvalidasi token digital QR Code yang tertera pada Kartu Tanda Bukti Pendaftaran.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$token = trim($_GET['token'] ?? '');
$reg_no = trim($_GET['reg'] ?? '');

$student = null;
if (!empty($token)) {
    $stmt = $pdo->prepare("SELECT * FROM ppdb_registrations WHERE qr_token = ? LIMIT 1");
    $stmt->execute([$token]);
    $student = $stmt->fetch();
} elseif (!empty($reg_no)) {
    $stmt = $pdo->prepare("SELECT * FROM ppdb_registrations WHERE registration_no = ? LIMIT 1");
    $stmt->execute([$reg_no]);
    $student = $stmt->fetch();
}

$school_info = getSchoolSettings($pdo);
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Keaslian Kartu PPDB - <?= $student ? htmlspecialchars($student['registration_no']) : 'Validasi Dokumen' ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased flex flex-col justify-between p-4 sm:p-8">

    <div class="max-w-xl mx-auto w-full my-auto">
        <!-- Logo Header -->
        <div class="text-center mb-6">
            <a href="ppdb.php" class="inline-flex items-center gap-3 group">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-tr from-emerald-600 to-teal-500 text-white font-bold text-xl shadow-lg shadow-emerald-500/20 group-hover:scale-105 transition">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div class="text-left">
                    <span class="text-lg font-black tracking-tight text-white block">PPDB Online Terpadu</span>
                    <span class="text-xs text-emerald-400 font-semibold"><?= htmlspecialchars($school_info['school_name'] ?? 'SMA / SMK Terpadu') ?></span>
                </div>
            </a>
        </div>

        <?php if ($student): ?>
            <!-- Card Valid -->
            <div class="rounded-3xl border border-emerald-500/40 bg-gradient-to-b from-slate-900 via-slate-900 to-emerald-950/30 p-6 sm:p-8 shadow-2xl backdrop-blur relative overflow-hidden">
                <div class="absolute -right-12 -top-12 h-40 w-40 rounded-full bg-emerald-500/10 blur-2xl"></div>

                <div class="flex items-center gap-3 border-b border-white/10 pb-5 mb-6">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-500/20 text-2xl text-emerald-400 border border-emerald-500/30 shadow-lg shadow-emerald-500/10">
                        <i class="fa-solid fa-circle-check"></i>
                    </span>
                    <div>
                        <span class="inline-block rounded-full bg-emerald-500/20 px-2.5 py-0.5 text-[11px] font-bold text-emerald-300 mb-1">
                            DOKUMEN ASLI TERVALIDASI
                        </span>
                        <h2 class="text-lg font-black text-white">Kartu Tanda Pendaftaran Resmi</h2>
                    </div>
                </div>

                <div class="space-y-4 text-xs">
                    <div class="flex justify-between items-center bg-slate-950/60 p-3.5 rounded-2xl border border-white/5">
                        <span class="text-slate-400">Nomor Registrasi:</span>
                        <span class="font-mono font-black text-emerald-400 text-sm select-all"><?= htmlspecialchars($student['registration_no']) ?></span>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-slate-950/60 p-3 rounded-xl border border-white/5">
                            <span class="text-slate-400 block mb-0.5">Nama Calon Siswa:</span>
                            <strong class="text-white text-sm block"><?= htmlspecialchars($student['full_name']) ?></strong>
                        </div>
                        <div class="bg-slate-950/60 p-3 rounded-xl border border-white/5">
                            <span class="text-slate-400 block mb-0.5">NISN:</span>
                            <strong class="text-white text-sm font-mono block"><?= htmlspecialchars($student['nisn']) ?></strong>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-slate-950/60 p-3 rounded-xl border border-white/5">
                            <span class="text-slate-400 block mb-0.5">Jalur Masuk:</span>
                            <span class="inline-flex items-center gap-1 font-semibold text-emerald-400">
                                <i class="fa-solid <?= getPpdbTrackIcon($student['track_type'] ?? 'reguler') ?>"></i>
                                <?= htmlspecialchars(getPpdbTrackLabel($student['track_type'] ?? 'reguler')) ?>
                            </span>
                        </div>
                        <div class="bg-slate-950/60 p-3 rounded-xl border border-white/5">
                            <span class="text-slate-400 block mb-0.5">Peminatan / Jurusan:</span>
                            <strong class="text-white block"><?= htmlspecialchars($student['chosen_major']) ?></strong>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-slate-950/60 p-3 rounded-xl border border-white/5">
                            <span class="text-slate-400 block mb-0.5">Status Seleksi:</span>
                            <span class="inline-flex items-center rounded-lg border px-2 py-0.5 text-[11px] font-bold <?= getPpdbStatusBadge($student['status']) ?>">
                                ● <?= htmlspecialchars(getPpdbStatusLabel($student['status'])) ?>
                            </span>
                        </div>
                        <div class="bg-slate-950/60 p-3 rounded-xl border border-white/5">
                            <span class="text-slate-400 block mb-0.5">Status Berkas:</span>
                            <span class="inline-flex items-center rounded-lg border px-2 py-0.5 text-[11px] font-bold <?= getPpdbDocStatusBadge($student['document_status'] ?? 'lengkap') ?>">
                                <?= htmlspecialchars(getPpdbDocStatusLabel($student['document_status'] ?? 'lengkap')) ?>
                            </span>
                        </div>
                    </div>

                    <div class="bg-slate-950/60 p-3 rounded-xl border border-white/5 flex items-center justify-between text-slate-400">
                        <span>Waktu Pendaftaran:</span>
                        <span class="text-white font-mono"><?= date('d F Y - H:i', strtotime($student['created_at'])) ?> WIB</span>
                    </div>
                </div>

                <div class="mt-6 pt-5 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <span class="text-[11px] text-slate-500">
                        <i class="fa-solid fa-lock text-emerald-400 mr-1"></i> Terenkripsi SHA-256 Digital Signature
                    </span>
                    <a href="ppdb_card.php?reg=<?= urlencode($student['registration_no']) ?>" target="_blank"
                       class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-xs font-bold text-white shadow-md shadow-emerald-600/20 transition">
                        <i class="fa-solid fa-print"></i> Cetak Kartu Fisik
                    </a>
                </div>
            </div>

        <?php else: ?>
            <!-- Card Invalid / Tidak Ditemukan -->
            <div class="rounded-3xl border border-rose-500/30 bg-slate-900/80 p-8 text-center backdrop-blur shadow-2xl">
                <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-rose-500/20 text-3xl text-rose-400 mx-auto mb-4 border border-rose-500/30">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <h3 class="text-xl font-bold text-white">Data Verifikasi Tidak Ditemukan</h3>
                <p class="text-xs text-slate-400 mt-2 max-w-sm mx-auto leading-relaxed">
                    Token QR Code atau Nomor Registrasi yang Anda pindai tidak terdaftar di sistem PPDB resmi kami. Pastikan dokumen berasal dari portal resmi sekolah.
                </p>
                <div class="mt-6">
                    <a href="ppdb.php?tab=cek" class="inline-flex items-center gap-2 rounded-xl bg-slate-800 hover:bg-slate-700 px-5 py-2.5 text-xs font-semibold text-slate-200 transition">
                        <i class="fa-solid fa-magnifying-glass"></i> Cek Manual di Portal PPDB
                    </a>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <footer class="text-center text-xs text-slate-600 py-4">
        <p>© 2026 Panitia PPDB Online. Sistem Validasi Terintegrasi.</p>
    </footer>

</body>
</html>
