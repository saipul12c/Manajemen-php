<?php
/**
 * Bukti Cetak Kartu Pendaftaran PPDB 2026/2027
 * Kartu resmi tanda bukti pendaftaran calon peserta didik baru dilengkapi barcode & QR Code validasi digital.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/database.php';

$reg_no = trim($_GET['reg'] ?? '');
if (empty($reg_no)) {
    die("Nomor registrasi pendaftaran tidak disertakan.");
}

$stmt = $pdo->prepare("SELECT * FROM ppdb_registrations WHERE registration_no = ? LIMIT 1");
$stmt->execute([$reg_no]);
$student = $stmt->fetch();

if (!$student) {
    die("Data pendaftaran dengan nomor {$reg_no} tidak ditemukan.");
}

// Generate QR token jika belum ada
if (empty($student['qr_token'])) {
    $student['qr_token'] = generatePpdbQrToken($student['registration_no']);
    $stmt_up = $pdo->prepare("UPDATE ppdb_registrations SET qr_token = ? WHERE id = ?");
    $stmt_up->execute([$student['qr_token'], $student['id']]);
}

$school_info = getSchoolSettings($pdo);

// URL verifikasi resmi
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$dir_path = dirname($_SERVER['PHP_SELF'] ?? '/');
$verify_url = "{$proto}://{$host}" . rtrim($dir_path, '/\\') . "/ppdb_verify.php?token=" . urlencode($student['qr_token']);
$qr_image_url = "https://api.qrserver.com/v1/create-qr-code/?size=130x130&margin=6&data=" . urlencode($verify_url);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kartu PPDB - <?= htmlspecialchars($student['registration_no']) ?> - <?= htmlspecialchars($student['full_name']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Libre+Barcode+39&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .barcode { font-family: 'Libre Barcode 39', cursive; font-size: 40px; letter-spacing: 2px; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; padding: 0 !important; }
            .print-card { box-shadow: none !important; border: 1px solid #333 !important; border-radius: 0 !important; width: 100% !important; max-width: 100% !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-900 py-8 px-4 text-slate-800 antialiased flex flex-col items-center">

    <!-- Action Toolbar (Hidden during print) -->
    <div class="no-print mb-6 flex flex-wrap items-center justify-between gap-4 w-full max-w-3xl">
        <a href="ppdb.php?tab=cek&search=<?= urlencode($student['registration_no']) ?>&birth_date=<?= urlencode($student['birth_date']) ?>" 
           class="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/20 transition">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Portal PPDB
        </a>

        <div class="flex items-center gap-3">
            <a href="<?= htmlspecialchars($verify_url) ?>" target="_blank"
               class="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/20 transition">
                <i class="fa-solid fa-shield-halved text-emerald-400"></i> Cek Verifikasi Digital
            </a>
            <button onclick="window.print()" 
                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2 text-sm font-bold text-white shadow-lg shadow-emerald-600/30 transition cursor-pointer">
                <i class="fa-solid fa-print"></i> Cetak Kartu (Print)
            </button>
        </div>
    </div>

    <!-- Official Printable Card -->
    <div class="print-card w-full max-w-3xl rounded-3xl bg-white p-8 sm:p-10 shadow-2xl border border-slate-200">

        <!-- KOP Surat Resmi -->
        <div class="flex items-center justify-between border-b-2 border-slate-900 pb-5 mb-6">
            <div class="flex items-center gap-4">
                <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-700 text-white font-black text-2xl shadow-md">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div>
                    <h2 class="text-xl font-black text-slate-900 uppercase tracking-tight">PANITIA PENERIMAAN PESERTA DIDIK BARU (PPDB)</h2>
                    <h3 class="text-base font-bold text-emerald-700 uppercase"><?= htmlspecialchars($school_info['school_name'] ?? 'SMA / SMK TERPADU INDONESIA') ?></h3>
                    <p class="text-xs text-slate-500 mt-0.5">
                        <?= htmlspecialchars($school_info['school_address'] ?? 'Jl. Pendidikan No. 45') ?> | 
                        Telp: <?= htmlspecialchars($school_info['school_phone'] ?? '(021) 7890-1234') ?> | 
                        Email: <?= htmlspecialchars($school_info['school_email'] ?? 'ppdb@sekolah.sch.id') ?>
                    </p>
                </div>
            </div>
            <div class="text-right hidden sm:block">
                <span class="inline-block rounded-lg bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800">
                    TAHUN 2026/2027
                </span>
            </div>
        </div>

        <!-- Judul Dokumen -->
        <div class="text-center mb-6">
            <h1 class="text-lg font-black text-slate-900 uppercase tracking-wider underline">KARTU TANDA BUKTI PENDAFTARAN</h1>
            <p class="text-xs text-slate-600 mt-1">Harap kartu ini dicetak dan dibawa saat verifikasi berkas fisik serta tes wawancara.</p>
        </div>

        <!-- Header No Registrasi & QR Code Digital -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 rounded-2xl bg-slate-50 p-5 border border-slate-200 mb-6">
            <div>
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block">Nomor Pendaftaran Resmi:</span>
                <span class="text-2xl font-black text-slate-900 font-mono"><?= htmlspecialchars($student['registration_no']) ?></span>
                <div class="flex items-center gap-2 mt-1.5">
                    <span class="inline-block rounded bg-emerald-100 text-emerald-800 font-bold px-2 py-0.5 text-[11px]">
                        Jalur <?= htmlspecialchars(getPpdbTrackLabel($student['track_type'] ?? 'reguler')) ?>
                    </span>
                    <span class="text-xs text-slate-500">Tanggal Daftar: <?= date('d F Y', strtotime($student['created_at'])) ?></span>
                </div>
            </div>

            <!-- QR Code Validasi Digital -->
            <div class="flex items-center gap-3">
                <div class="text-right hidden sm:block text-[11px] text-slate-500">
                    <span class="font-bold text-slate-700 block">Scan Validasi:</span>
                    <span>Pindai untuk cek</span>
                    <span class="block">keaslian berkas</span>
                </div>
                <div class="p-1.5 rounded-xl border border-slate-300 bg-white shadow-sm flex flex-col items-center">
                    <img src="<?= htmlspecialchars($qr_image_url) ?>" alt="QR Code Verifikasi" class="w-24 h-24 object-contain">
                    <span class="text-[9px] font-mono text-slate-400 mt-0.5">VALIDATED</span>
                </div>
            </div>
        </div>

        <!-- Body Biodata & Foto -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <!-- Foto 3x4 Calon Siswa -->
            <div class="md:col-span-1 flex flex-col items-center">
                <div class="w-32 h-44 rounded-xl border-2 border-dashed border-slate-300 bg-slate-100 flex flex-col items-center justify-center p-1.5 overflow-hidden shadow-inner">
                    <?php if (!empty($student['photo_doc']) && file_exists(dirname(__DIR__) . '/' . $student['photo_doc'])): ?>
                        <img src="../<?= htmlspecialchars($student['photo_doc']) ?>" alt="Pas Foto" class="w-full h-full object-cover rounded-lg">
                    <?php else: ?>
                        <span class="text-3xl text-slate-400"><i class="fa-solid fa-user"></i></span>
                        <span class="text-[10px] text-slate-400 font-medium text-center mt-2 leading-tight">Pas Foto 3x4 Calon Siswa</span>
                    <?php endif; ?>
                </div>
                <span class="text-[11px] font-bold text-slate-600 mt-2">
                    <?= $student['gender'] === 'L' ? 'Laki-Laki (L)' : 'Perempuan (P)' ?>
                </span>
            </div>

            <!-- Tabel Data Calon Siswa -->
            <div class="md:col-span-3">
                <table class="w-full text-xs text-slate-700">
                    <tbody class="divide-y divide-slate-100">
                        <tr>
                            <td class="py-1.5 font-semibold text-slate-500 w-36">Nama Lengkap</td>
                            <td class="py-1.5 font-bold text-slate-900">: <?= htmlspecialchars($student['full_name']) ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 font-semibold text-slate-500">NISN / NIK</td>
                            <td class="py-1.5 font-mono font-bold text-slate-900">: <?= htmlspecialchars($student['nisn']) ?> / <?= htmlspecialchars($student['nik'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 font-semibold text-slate-500">Tempat, Tgl Lahir</td>
                            <td class="py-1.5 text-slate-800">: <?= htmlspecialchars($student['birth_place']) ?>, <?= date('d-m-Y', strtotime($student['birth_date'])) ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 font-semibold text-slate-500">Agama</td>
                            <td class="py-1.5 text-slate-800">: <?= htmlspecialchars($student['religion']) ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 font-semibold text-slate-500">Asal Sekolah</td>
                            <td class="py-1.5 font-bold text-slate-800">: <?= htmlspecialchars($student['previous_school']) ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 font-semibold text-slate-500">Pilihan Peminatan</td>
                            <td class="py-1.5 font-bold text-emerald-700">: <?= htmlspecialchars($student['chosen_major']) ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 font-semibold text-slate-500">Jalur Penerimaan</td>
                            <td class="py-1.5 font-bold text-slate-800">
                                : <?= htmlspecialchars(getPpdbTrackLabel($student['track_type'] ?? 'reguler')) ?>
                                <?php if (($student['track_type'] ?? '') === 'zonasi' && $student['distance_km']): ?>
                                    (Radius: <?= htmlspecialchars((string)$student['distance_km']) ?> KM)
                                <?php elseif (($student['track_type'] ?? '') === 'prestasi' && $student['achievement_desc']): ?>
                                    (<?= htmlspecialchars($student['achievement_desc']) ?>)
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="py-1.5 font-semibold text-slate-500">Nama Orang Tua/Wali</td>
                            <td class="py-1.5 text-slate-800">: <?= htmlspecialchars($student['parent_name']) ?> (<?= htmlspecialchars($student['parent_job'] ?: '-') ?>)</td>
                        </tr>
                        <tr>
                            <td class="py-1.5 font-semibold text-slate-500">No. Kontak / WhatsApp</td>
                            <td class="py-1.5 font-mono text-slate-800">: <?= htmlspecialchars($student['phone']) ?> / <?= htmlspecialchars($student['parent_phone']) ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 font-semibold text-slate-500">Alamat Tinggal</td>
                            <td class="py-1.5 text-slate-800">: <?= htmlspecialchars($student['address']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Ketentuan Jadwal Seleksi -->
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-700 mb-6">
            <h4 class="font-bold text-slate-900 mb-1.5 flex items-center gap-2"><i class="fa-solid fa-thumbtack text-amber-500"></i> Informasi Penting Jadwal & Syarat Pelaksanaan Seleksi:</h4>
            <ul class="list-disc list-inside space-y-1 text-slate-600">
                <li>Verifikasi berkas fisik dan wawancara dilaksanakan pada hari kerja pukul 08.00 - 14.00 WIB.</li>
                <li>Wajib membawa: <strong>Kartu Pendaftaran ini</strong>, fotokopi Akta Kelahiran, fotokopi KK, dan Surat Keterangan Lulus / Rapor Asli.</li>
                <li>Pakaian saat wawancara: Seragam sekolah asal atau kemeja rapi bersepatu.</li>
                <li>Pantau kelulusan seleksi secara berkala melalui laman PPDB Online dengan Nomor Registrasi Anda.</li>
            </ul>
        </div>

        <!-- Tanda Tangan Validasi -->
        <div class="grid grid-cols-2 gap-8 text-center text-xs pt-4">
            <div>
                <p class="text-slate-500 mb-16">Calon Peserta Didik Baru,</p>
                <p class="font-bold text-slate-900 underline"><?= htmlspecialchars($student['full_name']) ?></p>
                <p class="text-[10px] text-slate-400">NISN. <?= htmlspecialchars($student['nisn']) ?></p>
            </div>
            <div>
                <p class="text-slate-500 mb-16">Panitia PPDB 2026/2027,</p>
                <p class="font-bold text-slate-900 underline"><?= htmlspecialchars($school_info['headmaster_name'] ?? 'Dr. H. Bambang Sudirman, M.Pd') ?></p>
                <p class="text-[10px] text-slate-400">NIP. <?= htmlspecialchars($school_info['headmaster_nip'] ?? '19750812 199903 1 002') ?></p>
            </div>
        </div>

    </div>

</body>
</html>
