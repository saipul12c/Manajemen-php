<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';

$bill_id = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;
if ($bill_id <= 0) {
    die("ID Tagihan tidak valid.");
}

// Ambil data tagihan & pembayaran
$stmt = $pdo->prepare("
    SELECT b.*, p.amount_paid, p.payment_method, p.payment_date, p.verified_at,
           u.name as student_name, u.nisn, u.gender, u.phone as student_phone, c.name as class_name,
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

if (!$bill || $bill['status'] !== 'lunas') {
    die("Bukti kuitansi tidak ditemukan atau tagihan belum dinyatakan lunas.");
}

// Cek hak akses jika bukan admin/staf
if (!in_array($user_role, ['administrator', 'staf', 'guru'], true)) {
    if ($user_role === 'siswa' && $bill['student_id'] != $user_id) {
        die("Akses ditolak.");
    }
    if ($user_role === 'orang_tua') {
        $stmt_check = $pdo->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ?");
        $stmt_check->execute([$user_id, $bill['student_id']]);
        if (!$stmt_check->fetch()) {
            die("Akses ditolak.");
        }
    }
}

$school = getSchoolSettings($pdo);

// Token digital anti-pemalsuan untuk validasi publik QR Code
$receipt_token = substr(hash('sha256', "RECEIPT_{$bill['id']}_{$bill['student_id']}_{$bill['amount']}_BINABANGSA"), 0, 32);
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$proto = $is_https ? "https" : "http";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$current_dir = dirname($_SERVER['PHP_SELF'] ?? '/dashboard/keuangan');
$verify_url = "{$proto}://{$host}{$current_dir}/receipt_verify.php?bill_id={$bill['id']}&token={$receipt_token}";

// Fitur Kirim WhatsApp Slip Kuitansi ke Wali Siswa (Staf & Admin only)
$wa_message = "";
$wa_status = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_wa_receipt') {
    if (in_array($user_role, ['administrator', 'staf', 'guru'], true) && validateCsrfToken()) {
        // Ambil kontak orang tua
        $stmt_p = $pdo->prepare("
            SELECT u_p.name, u_p.phone 
            FROM parent_students ps
            JOIN users u_p ON ps.parent_id = u_p.id
            WHERE ps.student_id = ?
            LIMIT 1
        ");
        $stmt_p->execute([$bill['student_id']]);
        $parent = $stmt_p->fetch();

        $dest_phone = $parent['phone'] ?? $bill['student_phone'] ?? '';
        $dest_name  = $parent['name'] ?? ('Wali Murid ' . $bill['student_name']);

        if (empty($dest_phone)) {
            $wa_message = "Nomor WhatsApp wali murid maupun siswa belum terdaftar di sistem profil.";
            $wa_status = "error";
        } else {
            $receipt_no = "KWT/" . date('Y/m', strtotime($bill['payment_date'] ?? 'now')) . "/" . str_pad((string)$bill['id'], 5, '0', STR_PAD_LEFT);
            $msg_body = "🧾 *BUKTI PEMBAYARAN PENDIDIKAN RESMI*\n"
                      . "*{$school['school_name']}*\n\n"
                      . "Yth. Bpk/Ibu *{$dest_name}*,\n"
                      . "Pembayaran tagihan pendidikan telah terverifikasi sah dengan rincian sbb:\n\n"
                      . "• *No. Kuitansi*: {$receipt_no}\n"
                      . "• *Nama Siswa*: {$bill['student_name']} (NISN: {$bill['nisn']})\n"
                      . "• *Keperluan*: {$bill['title']}\n"
                      . "• *Periode*: " . ($bill['month_period'] ?: 'Tahun Berjalan') . "\n"
                      . "• *Nominal Lunas*: " . formatRupiah($bill['amount']) . "\n"
                      . "• *Metode*: " . strtoupper(str_replace('_', ' ', $bill['payment_method'] ?? 'Tunai')) . "\n"
                      . "• *Tanggal*: " . date('d F Y', strtotime($bill['payment_date'] ?? 'now')) . "\n\n"
                      . "🔗 *Verifikasi Keaslian Kuitansi Digital*:\n"
                      . "{$verify_url}\n\n"
                      . "Simpan pesan ini sebagai bukti pembayaran yang sah. Terima kasih atas kerja samanya.\n\n"
                      . "- *Bendahara Sekolah*";

            $res_wa = sendWhatsAppNotification($pdo, $dest_phone, $dest_name, $msg_body, 'keuangan');
            logActivity($pdo, 'SEND_WA_RECEIPT', "Kirim kuitansi #{$bill['id']} ke $dest_phone ($dest_name)");
            $wa_message = "Kuitansi digital berhasil dikirim ke WhatsApp {$dest_name} ({$dest_phone})!";
            $wa_status = "success";
        }
    }
}

// Helper fungsi terbilang sederhana
function terbilang($angka) {
    $angka = abs((int)$angka);
    $baca = ["", "Satu", "Dua", "Tiga", "Empat", "Lima", "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas"];
    $terbilang = "";
    if ($angka < 12) {
        $terbilang = " " . $baca[$angka];
    } else if ($angka < 20) {
        $terbilang = terbilang($angka - 10) . " Belas";
    } else if ($angka < 100) {
        $terbilang = terbilang(intval($angka / 10)) . " Puluh" . terbilang($angka % 10);
    } else if ($angka < 200) {
        $terbilang = " Seratus" . terbilang($angka - 100);
    } else if ($angka < 1000) {
        $terbilang = terbilang(intval($angka / 100)) . " Ratus" . terbilang($angka % 100);
    } else if ($angka < 2000) {
        $terbilang = " Seribu" . terbilang($angka - 1000);
    } else if ($angka < 1000000) {
        $terbilang = terbilang(intval($angka / 1000)) . " Ribu" . terbilang($angka % 1000);
    } else if ($angka < 1000000000) {
        $terbilang = terbilang(intval($angka / 1000000)) . " Juta" . terbilang($angka % 1000000);
    }
    return trim($terbilang);
}

$receipt_no = "KWT/" . date('Y/m', strtotime($bill['payment_date'] ?? 'now')) . "/" . str_pad((string)$bill['id'], 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kuitansi Pembayaran #<?= $receipt_no ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; padding: 0 !important; }
            .receipt-box { border: 2px solid #000 !important; box-shadow: none !important; }
        }
    </style>
</head>
<body class="bg-slate-100 min-h-screen p-4 sm:p-8 flex flex-col items-center font-sans antialiased text-slate-800">

    <!-- Notifikasi Alert WhatsApp -->
    <?php if ($wa_message !== ''): ?>
        <div class="w-full max-w-3xl mb-4 no-print flex items-center gap-3 rounded-2xl p-4 border <?= $wa_status === 'success' ? 'bg-emerald-50 border-emerald-300 text-emerald-800' : 'bg-rose-50 border-rose-300 text-rose-800' ?>">
            <i class="fa-solid <?= $wa_status === 'success' ? 'fa-circle-check text-emerald-600' : 'fa-circle-exclamation text-rose-600' ?> text-lg"></i>
            <div class="flex-1 text-sm font-semibold"><?= htmlspecialchars($wa_message) ?></div>
            <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-slate-700 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <!-- Action Bar -->
    <div class="w-full max-w-3xl mb-4 flex items-center justify-between no-print gap-2 flex-wrap">
        <a href="payments.php" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 shadow-sm transition">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Keuangan
        </a>

        <div class="flex items-center gap-2">
            <?php if (in_array($user_role, ['administrator', 'staf', 'guru'], true)): ?>
                <form method="POST" onsubmit="return confirm('Kirimkan salinan kuitansi digital ini langsung ke WhatsApp nomor wali murid?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="send_wa_receipt">
                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-sm font-bold text-white shadow-md transition cursor-pointer">
                        <i class="fa-brands fa-whatsapp text-base"></i> Kirim ke WA Wali
                    </button>
                </form>
            <?php endif; ?>

            <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-md hover:bg-blue-700 transition cursor-pointer">
                <i class="fa-solid fa-print"></i> Cetak Kuitansi
            </button>
        </div>
    </div>

    <!-- Official Receipt Sheet -->
    <div class="receipt-box w-full max-w-3xl rounded-2xl border-2 border-slate-300 bg-white p-6 sm:p-10 shadow-xl space-y-6">
        
        <!-- Header Kop Sekolah -->
        <div class="flex items-center gap-4 border-b-2 border-slate-800 pb-4">
            <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-600 text-white text-2xl font-extrabold shadow-md shrink-0">
                <i class="fa-solid fa-graduation-cap"></i>
            </div>
            <div class="flex-1">
                <h1 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-slate-900 leading-tight">
                    <?= htmlspecialchars($school['school_name']) ?>
                </h1>
                <p class="text-xs text-slate-600 mt-0.5">
                    <?= htmlspecialchars($school['school_address']) ?>
                </p>
                <p class="text-xs text-slate-500">
                    Telepon: <?= htmlspecialchars($school['school_phone']) ?> | Email: <?= htmlspecialchars($school['school_email']) ?>
                </p>
            </div>
            <div class="text-right shrink-0">
                <span class="inline-block rounded-lg border-2 border-emerald-600 px-3 py-1 text-xs font-black uppercase tracking-widest text-emerald-700">
                    LUNAS
                </span>
            </div>
        </div>

        <!-- Receipt Title & Number -->
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 border-b border-slate-200 pb-3">
            <div>
                <h2 class="text-lg font-bold text-slate-900 uppercase tracking-wide">
                    BUKTI PEMBAYARAN PENDIDIKAN (KUITANSI)
                </h2>
                <p class="text-xs text-slate-500">No. Bukti: <strong class="text-slate-800 font-mono"><?= $receipt_no ?></strong></p>
            </div>
            <div class="text-xs text-slate-600 sm:text-right">
                <div>Tanggal Pembayaran: <strong><?= date('d F Y', strtotime($bill['payment_date'] ?? 'now')) ?></strong></div>
                <div>Metode: <strong class="capitalize"><?= str_replace('_', ' ', $bill['payment_method'] ?? 'Tunai') ?></strong></div>
            </div>
        </div>

        <!-- Details Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm bg-slate-50 rounded-xl p-4 border border-slate-200">
            <div class="space-y-1.5">
                <div class="text-xs text-slate-500 uppercase tracking-wider font-semibold">Telah Diterima Dari:</div>
                <div class="text-base font-bold text-slate-900"><?= htmlspecialchars($bill['student_name']) ?></div>
                <div class="text-xs text-slate-600">Nomor Induk Siswa (NISN): <strong><?= htmlspecialchars($bill['nisn'] ?: '-') ?></strong></div>
                <div class="text-xs text-slate-600">Rombongan Belajar: <strong>Kelas <?= htmlspecialchars($bill['class_name'] ?: 'Umum') ?></strong></div>
            </div>

            <div class="space-y-1.5 sm:text-right">
                <div class="text-xs text-slate-500 uppercase tracking-wider font-semibold">Untuk Keperluan:</div>
                <div class="text-base font-bold text-slate-900"><?= htmlspecialchars($bill['title']) ?></div>
                <div class="text-xs text-slate-600">Periode: <strong><?= htmlspecialchars($bill['month_period'] ?: 'Tahun Ajaran 2026/2027') ?></strong></div>
                <div class="text-xs text-slate-600">Status Transaksi: <strong class="text-emerald-700">Terverifikasi Sah</strong></div>
            </div>
        </div>

        <!-- Total Box -->
        <div class="rounded-xl border-2 border-slate-900 bg-slate-900 text-white p-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div>
                <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Jumlah Pembayaran:</span>
                <div class="text-2xl sm:text-3xl font-black text-emerald-400">
                    <?= formatRupiah($bill['amount']) ?>
                </div>
            </div>
            <div class="text-xs sm:text-right text-slate-300 italic">
                Terbilang: <strong>"<?= terbilang($bill['amount']) ?> Rupiah"</strong>
            </div>
        </div>

        <!-- Signatures & Verification -->
        <div class="flex flex-col sm:flex-row justify-between items-end gap-6 pt-4">
            <!-- Penyetor -->
            <div class="text-center sm:text-left">
                <p class="text-xs text-slate-500 mb-1">Tanda Tangan Penyetor / Wali:</p>
                <div class="h-16 flex items-end">
                    <p class="text-xs font-bold text-slate-900 underline">( <?= htmlspecialchars($bill['student_name']) ?> )</p>
                </div>
                <p class="text-[10px] text-slate-400 mt-1">NISN: <?= htmlspecialchars($bill['nisn'] ?? '-') ?> • Kelas: <?= htmlspecialchars($bill['class_name'] ?? '-') ?></p>
            </div>

            <!-- Bendahara Sekolah Resmi -->
            <div>
                <?= renderOfficialSignatureHtml($pdo, 'treasurer', [
                    'doc_type' => 'KUITANSI_SPP',
                    'doc_num' => $receipt_no,
                    'doc_title' => 'Kuitansi Pembayaran Tagihan Sekolah',
                    'student_name' => $bill['student_name']
                ]) ?>
            </div>
        </div>

        <!-- Receipt Footer Note -->
        <div class="border-t border-slate-200 pt-3 text-center text-[10px] text-slate-400">
            * Kuitansi ini dicetak secara sah melalui Sistem Manajemen-PHP Sekolah dan merupakan bukti pembayaran resmi yang tidak dapat disanggah. Token Verifikasi: <span class="font-mono text-slate-600"><?= $receipt_token ?></span>
        </div>

    </div>

    <!-- Script Generate QR Code -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            new QRCode(document.getElementById("receiptQrCode"), {
                text: "<?= addslashes($verify_url) ?>",
                width: 90,
                height: 90,
                colorDark : "#0f172a",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
            });
        });
    </script>

</body>
</html>
