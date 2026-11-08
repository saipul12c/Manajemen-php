<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'guru';

$payroll_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($payroll_id <= 0) {
    die("ID Penggajian tidak valid.");
}

// Fetch payroll with teacher info
$stmt = $pdo->prepare("
    SELECT p.*, u.name as teacher_name, u.email as teacher_email, u.phone as teacher_phone,
           c.name as homeroom_class,
           cb.name as creator_name
    FROM teacher_payrolls p
    JOIN users u ON p.teacher_id = u.id
    LEFT JOIN classes c ON c.homeroom_teacher_id = u.id
    LEFT JOIN users cb ON p.created_by = cb.id
    WHERE p.id = ?
");
$stmt->execute([$payroll_id]);
$payroll = $stmt->fetch();

if (!$payroll) {
    die("Data penggajian tidak ditemukan.");
}

// Hak akses: admin & staf dapat melihat semua; guru hanya dapat melihat miliknya sendiri
if (!in_array($user_role, ['administrator', 'staf'], true)) {
    if ($payroll['teacher_id'] != $user_id) {
        die("Akses ditolak: Anda hanya dapat melihat slip gaji Anda sendiri.");
    }
}

$school = getSchoolSettings($pdo);

// Helper terbilang
function terbilangRupiah($angka) {
    $angka = abs((int)$angka);
    $baca = ["", "Satu", "Dua", "Tiga", "Empat", "Lima", "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas"];
    $terbilang = "";
    if ($angka < 12) {
        $terbilang = " " . $baca[$angka];
    } else if ($angka < 20) {
        $terbilang = terbilangRupiah($angka - 10) . " Belas";
    } else if ($angka < 100) {
        $terbilang = terbilangRupiah(intval($angka / 10)) . " Puluh" . terbilangRupiah($angka % 10);
    } else if ($angka < 200) {
        $terbilang = " Seratus" . terbilangRupiah($angka - 100);
    } else if ($angka < 1000) {
        $terbilang = terbilangRupiah(intval($angka / 100)) . " Ratus" . terbilangRupiah($angka % 100);
    } else if ($angka < 2000) {
        $terbilang = " Seribu" . terbilangRupiah($angka - 1000);
    } else if ($angka < 1000000) {
        $terbilang = terbilangRupiah(intval($angka / 1000)) . " Ribu" . terbilangRupiah($angka % 1000);
    } else if ($angka < 1000000000) {
        $terbilang = terbilangRupiah(intval($angka / 1000000)) . " Juta" . terbilangRupiah($angka % 1000000);
    } else if ($angka < 1000000000000) {
        $terbilang = terbilangRupiah(intval($angka / 1000000000)) . " Miliar" . terbilangRupiah(fmod($angka, 1000000000));
    }
    return trim($terbilang);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Slip Gaji - <?= htmlspecialchars($payroll['payroll_code']) ?> - <?= htmlspecialchars($payroll['teacher_name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; padding: 0 !important; }
            .print-card { border: none !important; box-shadow: none !important; width: 100% !important; max-width: 100% !important; }
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen py-8 px-4 flex flex-col items-center">

    <!-- Action Bar (No Print) -->
    <div class="no-print w-full max-w-3xl mb-6 flex items-center justify-between">
        <a href="payroll.php?month=<?= urlencode($payroll['month_period']) ?>" class="inline-flex items-center gap-2 text-sm text-slate-400 hover:text-white transition">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Payroll
        </a>
        <div class="flex items-center gap-3">
            <span class="rounded-lg border px-3 py-1 text-xs font-semibold <?= getPayrollStatusBadge($payroll['status']) ?>">
                Status: <?= getPayrollStatusLabel($payroll['status']) ?>
            </span>
            <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-lg shadow-blue-500/20 hover:bg-blue-500 transition cursor-pointer">
                <i class="fa-solid fa-print"></i> Cetak / Simpan PDF
            </button>
        </div>
    </div>

    <!-- Printable Payslip Document -->
    <div class="print-card w-full max-w-3xl bg-white text-slate-900 rounded-3xl p-8 shadow-2xl border border-slate-200">
        
        <!-- Header / School Kop -->
        <div class="border-b-2 border-slate-800 pb-5 mb-6 flex items-start justify-between">
            <div class="flex items-center gap-4">
                <?php 
                    $clean_pay_logo = !empty($school['school_logo']) ? resolveAssetUrl($school['school_logo'], '../../') : '';
                    $pay_logo_exists = !empty($clean_pay_logo) && (strpos($clean_pay_logo, 'http') === 0 || file_exists(dirname(__DIR__, 2) . '/' . resolveAssetUrl($school['school_logo'])));
                ?>
                <?php if ($pay_logo_exists): ?>
                    <img src="<?= htmlspecialchars($clean_pay_logo) ?>" alt="Logo" class="h-16 w-16 object-contain shrink-0">
                <?php else: ?>
                    <div class="h-16 w-16 rounded-2xl bg-slate-900 text-white flex items-center justify-center text-2xl font-black shrink-0">
                        <i class="fa-solid fa-graduation-cap text-blue-400"></i>
                    </div>
                <?php endif; ?>
                <div>
                    <h1 class="text-xl font-black uppercase tracking-tight text-slate-900"><?= htmlspecialchars($school['school_name'] ?? 'Bina Bangsa Education') ?></h1>
                    <p class="text-xs text-slate-600"><?= htmlspecialchars($school['school_address'] ?? 'Jl. Pendidikan No. 45, Jakarta') ?></p>
                    <p class="text-[11px] text-slate-500">Telp: <?= htmlspecialchars($school['school_phone'] ?? '021-12345678') ?> • Email: <?= htmlspecialchars($school['school_email'] ?? 'info@sekolah.sch.id') ?></p>
                </div>
            </div>
            <div class="text-right">
                <div class="inline-block px-3 py-1 rounded-lg bg-blue-50 text-blue-800 font-black text-xs uppercase tracking-wider border border-blue-200">
                    SLIP GAJI DIGITAL
                </div>
                <div class="mt-2 text-xs text-slate-500">Nomor Dokumen:</div>
                <div class="text-sm font-mono font-bold text-slate-800"><?= htmlspecialchars($payroll['payroll_code']) ?></div>
            </div>
        </div>

        <!-- Teacher & Period Info -->
        <div class="grid grid-cols-2 gap-4 mb-6 bg-slate-50 p-4 rounded-2xl border border-slate-200 text-xs">
            <div class="space-y-1.5">
                <div class="flex"><span class="w-32 text-slate-500">Nama Penerima</span>: <strong class="text-slate-900 ml-1"><?= htmlspecialchars($payroll['teacher_name']) ?></strong></div>
                <div class="flex"><span class="w-32 text-slate-500">Jabatan / Role</span>: <span class="text-slate-800 ml-1">Guru Pendidik <?= $payroll['homeroom_class'] ? '(Wali Kelas ' . htmlspecialchars($payroll['homeroom_class']) . ')' : '' ?></span></div>
                <div class="flex"><span class="w-32 text-slate-500">No. Kontak / WA</span>: <span class="text-slate-800 ml-1"><?= htmlspecialchars($payroll['teacher_phone'] ?: '-') ?></span></div>
            </div>
            <div class="space-y-1.5">
                <div class="flex"><span class="w-32 text-slate-500">Periode Gaji</span>: <strong class="text-slate-900 ml-1"><?= date('F Y', strtotime($payroll['month_period'] . '-01')) ?></strong></div>
                <div class="flex"><span class="w-32 text-slate-500">Metode Bayar</span>: <span class="text-slate-800 ml-1"><?= htmlspecialchars($payroll['payment_method'] ?: 'Transfer Bank') ?></span></div>
                <div class="flex"><span class="w-32 text-slate-500">Tanggal Pencairan</span>: <span class="text-slate-800 ml-1"><?= $payroll['payment_date'] ? date('d F Y', strtotime($payroll['payment_date'])) : '-' ?></span></div>
            </div>
        </div>

        <!-- Breakdown Table -->
        <div class="border border-slate-200 rounded-2xl overflow-hidden mb-6">
            <table class="w-full text-xs text-left">
                <thead class="bg-slate-100 text-slate-700 font-bold border-b border-slate-200">
                    <tr>
                        <th class="py-2.5 px-4">Deskripsi Komponen</th>
                        <th class="py-2.5 px-4 text-center">Volume / Keterangan</th>
                        <th class="py-2.5 px-4 text-right">Penghasilan (Rp)</th>
                        <th class="py-2.5 px-4 text-right">Potongan (Rp)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-slate-700">
                    <tr>
                        <td class="py-2.5 px-4 font-semibold text-slate-800">Gaji Pokok Pendidik</td>
                        <td class="py-2.5 px-4 text-center text-slate-500">Standar Bulanan</td>
                        <td class="py-2.5 px-4 text-right font-mono font-medium"><?= number_format($payroll['base_salary'], 0, ',', '.') ?></td>
                        <td class="py-2.5 px-4 text-right font-mono text-slate-400">-</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 px-4 font-semibold text-slate-800">Tunjangan Tugas Tambahan (Wali Kelas)</td>
                        <td class="py-2.5 px-4 text-center text-slate-500"><?= $payroll['homeroom_class'] ? 'Kelas ' . htmlspecialchars($payroll['homeroom_class']) : 'Tidak Ada' ?></td>
                        <td class="py-2.5 px-4 text-right font-mono font-medium"><?= number_format($payroll['position_allowance'], 0, ',', '.') ?></td>
                        <td class="py-2.5 px-4 text-right font-mono text-slate-400">-</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 px-4 font-semibold text-slate-800">Honor Jam Mengajar (Jurnal Mengajar)</td>
                        <td class="py-2.5 px-4 text-center text-slate-500"><?= (int)$payroll['teaching_hours'] ?> Jam @ Rp <?= number_format($payroll['teaching_rate_per_hour'], 0, ',', '.') ?></td>
                        <td class="py-2.5 px-4 text-right font-mono font-medium"><?= number_format($payroll['total_teaching_honor'], 0, ',', '.') ?></td>
                        <td class="py-2.5 px-4 text-right font-mono text-slate-400">-</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 px-4 font-semibold text-slate-800">Potongan Presensi & Keterlambatan</td>
                        <td class="py-2.5 px-4 text-center text-slate-500">Evaluasi Presensi GPS</td>
                        <td class="py-2.5 px-4 text-right font-mono text-slate-400">-</td>
                        <td class="py-2.5 px-4 text-right font-mono text-rose-600 font-medium"><?= number_format($payroll['attendance_deductions'], 0, ',', '.') ?></td>
                    </tr>
                </tbody>
                <tfoot class="bg-slate-50 border-t-2 border-slate-300 font-bold text-slate-900">
                    <tr>
                        <td colspan="2" class="py-3 px-4 text-sm uppercase">Total Penghasilan Bersih (Take Home Pay)</td>
                        <td colspan="2" class="py-3 px-4 text-right text-base text-blue-700 font-mono font-extrabold">
                            Rp <?= number_format($payroll['net_salary'], 0, ',', '.') ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Terbilang Box -->
        <div class="mb-6 p-3 bg-blue-50/60 border border-blue-200 rounded-xl text-xs text-blue-900 italic flex items-center gap-2">
            <span class="font-bold not-italic text-blue-950">Terbilang:</span>
            <span># <?= terbilangRupiah($payroll['net_salary']) ?> Rupiah #</span>
        </div>

        <?php if (!empty($payroll['notes'])): ?>
            <div class="mb-6 text-xs text-slate-600 bg-slate-50 p-3 rounded-xl border border-slate-200">
                <span class="font-bold text-slate-700">Catatan Keuangan:</span> <?= htmlspecialchars($payroll['notes']) ?>
            </div>
        <?php endif; ?>

        <!-- Signatures & Verification Stamp -->
        <div class="pt-4 grid grid-cols-3 gap-6 text-center text-xs text-slate-600 border-t border-slate-200">
            <div>
                <div>Penerima / Guru</div>
                <div class="h-16 flex items-end justify-center font-bold text-slate-900 underline">
                    <?= htmlspecialchars($payroll['teacher_name']) ?>
                </div>
                <div class="text-[10px] text-slate-500 mt-1">NIP: <?= htmlspecialchars($payroll['teacher_email']) ?></div>
            </div>

            <div class="flex flex-col items-center justify-center">
                <!-- QR Code Verification Badge -->
                <div class="p-2 border border-slate-300 rounded-xl bg-slate-50 flex flex-col items-center">
                    <i class="fa-solid fa-qrcode text-3xl text-slate-800 mb-1"></i>
                    <span class="text-[9px] font-mono text-slate-500 uppercase tracking-tighter">Verified Digital Payroll</span>
                </div>
            </div>

            <div>
                <?= renderOfficialSignatureHtml($pdo, 'treasurer', [
                    'doc_type' => 'SLIP_GAJI',
                    'doc_num' => 'PAY/' . date('Ym') . '/' . $payroll['id'],
                    'student_name' => $payroll['teacher_name'],
                    'date' => $payroll['payment_date'] ? date('d F Y', strtotime($payroll['payment_date'])) : date('d F Y'),
                    'align' => 'center'
                ]) ?>
            </div>
        </div>

        <!-- Footer Notice -->
        <div class="mt-8 pt-3 border-t border-dashed border-slate-200 text-[10px] text-center text-slate-400">
            Dokumen ini dicetak secara otomatis melalui Sistem Informasi Manajemen Sekolah (SIM Sekolah) dan sah tanpa tanda tangan basah jika kode verifikasi sesuai.
        </div>

    </div>

</body>
</html>
