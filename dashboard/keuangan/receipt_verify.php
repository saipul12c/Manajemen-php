<?php
/**
 * Halaman Verifikasi Keaslian Kuitansi Pembayaran Digital
 * Memvalidasi token digital QR Code yang tertera pada Kuitansi Resmi Sekolah.
 */
declare(strict_types=1);

require_once __DIR__ . "/../../config/database.php";

$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;
$token   = trim($_GET['token'] ?? '');

$bill = null;
$is_verified = false;
$error_reason = "";

if ($bill_id > 0) {
    $stmt = $pdo->prepare("
        SELECT b.*, p.amount_paid, p.payment_method, p.payment_date, p.verified_at,
               u.name as student_name, u.nisn, u.gender, c.name as class_name,
               vf.name as verifier_name
        FROM student_bills b
        JOIN users u ON b.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        LEFT JOIN bill_payments p ON p.bill_id = b.id AND p.status = 'diterima'
        LEFT JOIN users vf ON p.verified_by = vf.id
        WHERE b.id = ?
    ");
    $stmt->execute([$bill_id]);
    $bill = $stmt->fetch();

    if (!$bill) {
        $error_reason = "Nomor tagihan/kuitansi tidak terdaftar dalam pangkalan data resmi sekolah.";
    } elseif ($bill['status'] !== 'lunas') {
        $error_reason = "Tagihan terkait belum dinyatakan LUNAS atau masih dalam proses verifikasi loket/gateway.";
    } else {
        $expected_token = substr(hash('sha256', "RECEIPT_{$bill['id']}_{$bill['student_id']}_{$bill['amount']}_BINABANGSA"), 0, 32);
        if (empty($token) || !hash_equals($expected_token, $token)) {
            $error_reason = "Token tanda tangan digital kuitansi tidak valid atau tidak disertakan. Dokumen berpotensi telah dimodifikasi.";
        } else {
            $is_verified = true;
        }
    }
} else {
    $error_reason = "Parameter verifikasi kuitansi tidak lengkap atau tidak valid.";
}

$school = getSchoolSettings($pdo);
$receipt_no = $bill ? ("KWT/" . date('Y/m', strtotime($bill['payment_date'] ?? 'now')) . "/" . str_pad((string)$bill['id'], 5, '0', STR_PAD_LEFT)) : '-';
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Keaslian Kuitansi - <?= htmlspecialchars($receipt_no) ?></title>
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
            <a href="../../index.php" class="inline-flex items-center gap-3 group">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-500 text-white font-bold text-xl shadow-lg shadow-blue-500/20 group-hover:scale-105 transition">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div class="text-left">
                    <span class="text-lg font-black tracking-tight text-white block">Portal Verifikasi Dokumen</span>
                    <span class="text-xs text-blue-400 font-semibold"><?= htmlspecialchars($school['school_name'] ?? 'Sistem Manajemen Sekolah') ?></span>
                </div>
            </a>
        </div>

        <?php if ($is_verified && $bill): ?>
            <!-- Card Valid -->
            <div class="rounded-3xl border border-emerald-500/40 bg-gradient-to-b from-slate-900 via-slate-900 to-emerald-950/30 p-6 sm:p-8 shadow-2xl backdrop-blur relative overflow-hidden">
                <div class="absolute -right-12 -top-12 h-40 w-40 rounded-full bg-emerald-500/10 blur-2xl"></div>

                <div class="text-center pb-6 border-b border-white/10">
                    <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-500/20 text-emerald-400 text-3xl mb-3 shadow-inner">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <span class="inline-block rounded-full bg-emerald-500/20 border border-emerald-500/30 px-3 py-1 text-xs font-bold text-emerald-300 uppercase tracking-widest mb-2">
                        Dokumen Sah & Terverifikasi
                    </span>
                    <h1 class="text-2xl font-black text-white">Kuitansi Pembayaran Asli</h1>
                    <p class="text-xs text-slate-400 mt-1">
                        Nomor Bukti: <span class="font-mono text-emerald-400 font-bold"><?= htmlspecialchars($receipt_no) ?></span>
                    </p>
                </div>

                <div class="mt-6 space-y-4 text-xs sm:text-sm">
                    <div class="grid grid-cols-3 gap-2 py-2 border-b border-white/5">
                        <span class="text-slate-400">Nama Siswa</span>
                        <span class="col-span-2 font-bold text-white"><?= htmlspecialchars($bill['student_name']) ?></span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 py-2 border-b border-white/5">
                        <span class="text-slate-400">NISN / Kelas</span>
                        <span class="col-span-2 font-mono text-slate-200"><?= htmlspecialchars($bill['nisn'] ?: '-') ?> (Kelas <?= htmlspecialchars($bill['class_name'] ?: 'Umum') ?>)</span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 py-2 border-b border-white/5">
                        <span class="text-slate-400">Untuk Pembayaran</span>
                        <span class="col-span-2 font-semibold text-slate-200"><?= htmlspecialchars($bill['title']) ?> (<?= htmlspecialchars($bill['month_period'] ?: '-') ?>)</span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 py-2 border-b border-white/5">
                        <span class="text-slate-400">Nominal Lunas</span>
                        <span class="col-span-2 font-black text-emerald-400 text-base">
                            <?= formatRupiah($bill['amount']) ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 py-2 border-b border-white/5">
                        <span class="text-slate-400">Metode & Tanggal</span>
                        <span class="col-span-2 text-slate-300 capitalize">
                            <?= str_replace('_', ' ', $bill['payment_method'] ?? 'Tunai') ?> &bull; <?= date('d F Y', strtotime($bill['payment_date'] ?? $bill['paid_at'] ?? 'now')) ?>
                        </span>
                    </div>

                    <div class="grid grid-cols-3 gap-2 py-2">
                        <span class="text-slate-400">Petugas / Verifikator</span>
                        <span class="col-span-2 text-slate-300">
                            <?= htmlspecialchars($bill['verifier_name'] ?: 'Sistem Loket Otomatis TU') ?>
                        </span>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-white/10 text-center">
                    <p class="text-[11px] text-slate-400 flex items-center justify-center gap-1.5">
                        <i class="fa-solid fa-shield-halved text-emerald-400"></i>
                        Data ini tercatat secara kriptografis dalam Sistem Basis Data Resmi Sekolah.
                    </p>
                </div>
            </div>

        <?php else: ?>
            <!-- Card Invalid -->
            <div class="rounded-3xl border border-rose-500/40 bg-gradient-to-b from-slate-900 via-slate-900 to-rose-950/30 p-6 sm:p-8 shadow-2xl backdrop-blur text-center">
                <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-rose-500/20 text-rose-400 text-3xl mb-3 shadow-inner">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <span class="inline-block rounded-full bg-rose-500/20 border border-rose-500/30 px-3 py-1 text-xs font-bold text-rose-300 uppercase tracking-widest mb-2">
                    Verifikasi Gagal
                </span>
                <h1 class="text-2xl font-black text-white">Kuitansi Tidak Sah</h1>
                <p class="text-xs text-rose-300/80 mt-2 max-w-md mx-auto leading-relaxed">
                    <?= htmlspecialchars($error_reason) ?>
                </p>
                <div class="mt-6 pt-4 border-t border-white/10 text-[11px] text-slate-500">
                    Jika Anda merasa ini adalah kesalahan, harap hubungi Bendahara Tata Usaha sekolah dengan membawa bukti transfer fisik.
                </div>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="mt-8 text-center text-xs text-slate-400 flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="../../index.php" class="hover:text-white transition inline-flex items-center gap-1">
                <i class="fa-solid fa-house"></i> Halaman Utama
            </a>
            <span class="hidden sm:inline">&bull;</span>
            <a href="../keuangan/payments.php" class="hover:text-white transition inline-flex items-center gap-1">
                <i class="fa-solid fa-wallet"></i> Portal Pembayaran
            </a>
        </div>
    </div>

</body>
</html>
