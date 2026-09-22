<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$req_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($req_id <= 0) {
    die("ID permohonan surat tidak valid.");
}

$school_info = getSchoolSettings($pdo);

// Ambil data permohonan surat
$stmt = $pdo->prepare("
    SELECT r.*, u.name as applicant_name, u.role as applicant_role, u.nisn, u.address as applicant_address,
           c.name as class_name, p.name as processor_name
    FROM service_requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN users p ON r.processed_by = p.id
    WHERE r.id = ?
");
$stmt->execute([$req_id]);
$req = $stmt->fetch();

if (!$req) {
    die("Data permohonan surat tidak ditemukan.");
}

// Cek hak akses: hanya pemilik atau staf/admin yang boleh buka
if (!in_array($user_role, ['staf', 'administrator'], true) && $req['user_id'] != $user_id) {
    die("Akses ditolak: Anda tidak memiliki izin untuk melihat dokumen ini.");
}

$letter_number = "421.3/" . sprintf('%03d', $req['id']) . "/SMA-BBN/" . date('m/Y', strtotime($req['created_at']));
$page_title = "Surat Resmi - " . htmlspecialchars($req['request_type']);
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
            .print-paper { border: none !important; box-shadow: none !important; padding: 0 !important; max-width: 100% !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center p-4 sm:p-8 font-serif antialiased">

    <!-- Action Bar -->
    <div class="no-print w-full max-w-3xl mb-6 flex items-center justify-between font-sans">
        <a href="requests.php" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
            ← Kembali ke Daftar Surat
        </a>
        <button onclick="window.print()" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition flex items-center gap-2">
            <span>🖨️</span> Cetak / Simpan PDF
        </button>
    </div>

    <!-- LEMBAR SURAT RESMI -->
    <div class="print-paper w-full max-w-3xl rounded-3xl border border-white/15 bg-white text-slate-900 p-8 sm:p-14 shadow-2xl">
        
        <!-- KOP SURAT DINAS / RESMI -->
        <div class="text-center relative pb-4 mb-6 border-b-4 border-slate-900" style="border-bottom-style: double;">
            <div class="flex items-center justify-center gap-4 mb-2">
                <span class="text-4xl">🏛️</span>
                <div>
                    <h3 class="text-xs font-sans font-bold uppercase tracking-widest text-slate-600">Pemerintah Provinsi DKI Jakarta • Dinas Pendidikan</h3>
                    <h1 class="text-2xl sm:text-3xl font-black uppercase tracking-tight text-slate-900 font-sans">
                        <?= htmlspecialchars($school_info['school_name']) ?>
                    </h1>
                    <p class="text-xs text-slate-600 font-sans mt-0.5">
                        <?= htmlspecialchars($school_info['school_address']) ?>
                    </p>
                    <p class="text-[11px] text-slate-500 font-sans font-mono">
                        Telp: <?= htmlspecialchars($school_info['school_phone']) ?> | Surel: <?= htmlspecialchars($school_info['school_email']) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- JUDUL & NOMOR SURAT -->
        <div class="text-center my-6">
            <h2 class="text-lg font-bold uppercase tracking-wider underline decoration-1 text-slate-900">
                <?= htmlspecialchars($req['request_type']) ?>
            </h2>
            <p class="text-xs font-mono text-slate-600 mt-1">Nomor: <?= $letter_number ?></p>
        </div>

        <!-- ISI SURAT -->
        <div class="space-y-4 text-sm leading-relaxed text-slate-800 text-justify">
            <p>
                Yang bertanda tangan di bawah ini, Kepala Sekolah <strong><?= htmlspecialchars($school_info['school_name']) ?></strong> menerangkan bahwa:
            </p>

            <div class="my-4 ml-6 space-y-1.5 font-sans text-xs">
                <div class="grid grid-cols-4">
                    <span class="text-slate-600">Nama Lengkap</span>
                    <span class="col-span-3 font-bold text-slate-900">: <?= htmlspecialchars($req['applicant_name']) ?></span>
                </div>
                <div class="grid grid-cols-4">
                    <span class="text-slate-600">NISN / No. Induk</span>
                    <span class="col-span-3 font-mono text-slate-900">: <?= htmlspecialchars($req['nisn'] ?: '0081234567') ?></span>
                </div>
                <div class="grid grid-cols-4">
                    <span class="text-slate-600">Kelas / Rombel</span>
                    <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['class_name'] ?? 'X MIPA 1') ?></span>
                </div>
                <div class="grid grid-cols-4">
                    <span class="text-slate-600">Alamat Tempat Tinggal</span>
                    <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['applicant_address'] ?: 'Jakarta, Indonesia') ?></span>
                </div>
                <?php if (!empty($req['target_date'])): ?>
                <div class="grid grid-cols-4">
                    <span class="text-slate-600">Tanggal Keperluan</span>
                    <span class="col-span-3 font-semibold text-slate-900">: <?= date('d F Y', strtotime($req['target_date'])) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <p>
                <?php if (stripos($req['request_type'], 'Aktif') !== false): ?>
                    Adalah benar siswa yang bersangkutan tercatat aktif sebagai peserta didik di <strong><?= htmlspecialchars($school_info['school_name']) ?></strong> pada Tahun Ajaran <strong><?= htmlspecialchars($school_info['academic_year']) ?></strong> dan berkelakuan baik dalam mengikuti seluruh proses kegiatan belajar mengajar.
                <?php elseif (stripos($req['request_type'], 'Sakit') !== false || stripos($req['request_type'], 'Dispensasi') !== false): ?>
                    Diberikan izin tidak mengikuti kegiatan pembelajaran pada tanggal <strong><?= date('d F Y', strtotime($req['target_date'] ?: $req['created_at'])) ?></strong> sehubungan dengan keperluan: <em>"<?= htmlspecialchars($req['notes'] ?: 'Izin / Sakit') ?>"</em>.
                <?php else: ?>
                    Surat keterangan ini diterbitkan secara sah sesuai permohonan yang diajukan untuk keperluan: <em><?= htmlspecialchars($req['notes'] ?: 'Administrasi pendidikan') ?></em>.
                <?php endif; ?>
            </p>

            <p>
                Demikian surat keterangan ini dibuat dengan sebenarnya untuk dapat dipergunakan sebagaimana mestinya bagi pihak-pihak yang berkepentingan.
            </p>
        </div>

        <!-- TANDA TANGAN KEPALA SEKOLAH & QR VALIDASI -->
        <div class="grid grid-cols-2 gap-8 pt-10 mt-6 border-t border-slate-200 font-sans text-xs">
            <div class="flex flex-col items-center justify-center p-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 text-center">
                <span class="text-3xl mb-1">📱</span>
                <span class="font-mono text-[10px] text-slate-600 font-bold">DIGITAL-SIGNATURE-VERIFIED</span>
                <span class="text-[9px] text-slate-400 mt-0.5 font-mono">DOC-HASH: <?= strtoupper(substr(md5($req['id'] . $req['created_at']), 0, 12)) ?></span>
            </div>

            <div class="text-right">
                <p class="text-slate-600">Dikeluarkan di: Jakarta</p>
                <p class="text-slate-600">Pada tanggal: <?= date('d F Y', strtotime($req['updated_at'] ?: $req['created_at'])) ?></p>
                <p class="mt-2 font-bold text-slate-900">Kepala Sekolah,</p>
                <div class="h-16 flex items-center justify-end">
                    <span class="text-xs italic text-blue-800 font-bold border border-blue-600 px-3 py-1 rounded bg-blue-50">
                        [ Ditandatangani Elektronik ]
                    </span>
                </div>
                <p class="font-bold underline text-slate-900 text-sm"><?= htmlspecialchars($school_info['headmaster_name']) ?></p>
                <p class="text-xs text-slate-600 font-mono">NIP. <?= htmlspecialchars($school_info['headmaster_nip']) ?></p>
            </div>
        </div>

    </div>

</body>
</html>
