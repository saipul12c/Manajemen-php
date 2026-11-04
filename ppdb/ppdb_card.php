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

// Cek Hak Akses demi Perlindungan Data Pribadi (UU PDP)
$token = trim($_GET['token'] ?? '');
$is_admin_or_staff = isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['administrator', 'staf', 'guru'], true);
$is_session_verified = isset($_SESSION['ppdb_verified_reg']) && $_SESSION['ppdb_verified_reg'] === $student['registration_no'];
$is_token_valid = !empty($token) && !empty($student['qr_token']) && hash_equals($student['qr_token'], $token);

if (!$is_admin_or_staff && !$is_session_verified && !$is_token_valid) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <title>Akses Dibatasi - PPDB Online</title>
        <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4">
        <div class="max-w-md w-full bg-slate-900 border border-amber-500/30 rounded-3xl p-6 sm:p-8 text-center shadow-2xl">
            <div class="h-14 w-14 rounded-2xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-2xl mx-auto mb-4">
                <i class="fa-solid fa-user-shield"></i>
            </div>
            <h1 class="text-xl font-bold text-white mb-2">Verifikasi Identitas Diperlukan</h1>
            <p class="text-xs text-slate-400 mb-6 leading-relaxed">
                Demi melindungi kerahasiaan data pribadi calon siswa (NIK, NISN, Alamat & Kontak Wali), kartu pendaftaran fisik hanya dapat diakses melalui portal <strong>Cek Status Pendaftaran</strong> dengan mengonfirmasi tanggal lahir yang valid atau melalui tautan dengan token keamanan resmi.
            </p>
            <a href="ppdb.php?tab=cek&search=<?= urlencode($student['registration_no']) ?>" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2.5 text-xs font-bold text-white transition">
                Masuk via Cek Status PPDB
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
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
            <?php if (($student['status'] ?? '') === 'diterima'): ?>
                <div class="mb-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 text-emerald-800 border border-emerald-300 px-3.5 py-1 text-xs font-black uppercase tracking-wider shadow-sm">
                        <i class="fa-solid fa-circle-check text-emerald-600"></i> SURAT KETERANGAN RESMI: LULUS SELEKSI PPDB
                    </span>
                </div>
                <h1 class="text-lg sm:text-xl font-black text-slate-900 uppercase tracking-wider underline">SURAT TANDA DITERIMA & KARTU SISWA BARU</h1>
                <p class="text-xs text-slate-600 mt-1">Dokumen resmi bukti kelulusan seleksi dan penerimaan peserta didik baru tahun pelajaran 2026/2027.</p>
            <?php else: ?>
                <h1 class="text-lg font-black text-slate-900 uppercase tracking-wider underline">KARTU TANDA BUKTI PENDAFTARAN</h1>
                <p class="text-xs text-slate-600 mt-1">Harap kartu ini dicetak dan dibawa saat verifikasi berkas fisik serta tes wawancara.</p>
            <?php endif; ?>
        </div>

        <!-- Header No Registrasi & QR Code Digital -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 rounded-2xl bg-slate-50 p-5 border border-slate-200 mb-6">
            <div>
                <span class="text-xs font-bold text-slate-500 uppercase tracking-wider block">Nomor Pendaftaran Resmi:</span>
                <span class="text-2xl font-black text-slate-900 font-mono"><?= htmlspecialchars($student['registration_no']) ?></span>
                <div class="flex flex-wrap items-center gap-2 mt-1.5">
                    <span class="inline-block rounded bg-emerald-100 text-emerald-800 font-bold px-2 py-0.5 text-[11px]">
                        Jalur <?= htmlspecialchars(getPpdbTrackLabel($student['track_type'] ?? 'reguler')) ?>
                    </span>
                    <?php if (($student['status'] ?? '') === 'diterima'): ?>
                        <span class="inline-flex items-center gap-1 rounded bg-emerald-700 text-white font-extrabold px-2.5 py-0.5 text-[11px] shadow-sm">
                            <i class="fa-solid fa-check-double"></i> DITERIMA RESMI
                        </span>
                    <?php endif; ?>
                    <span class="text-xs text-slate-500">Tanggal: <?= date('d F Y', strtotime($student['created_at'])) ?></span>
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
                                <?php elseif (($student['track_type'] ?? '') === 'prestasi'): ?>
                                    <?php 
                                    $cardAchievements = !empty($student['achievements_json']) ? json_decode($student['achievements_json'], true) : [];
                                    if (!empty($cardAchievements) && is_array($cardAchievements)):
                                    ?>
                                        <div class="mt-1 text-xs text-slate-700 font-normal">
                                            <span class="font-bold text-amber-700"><i class="fa-solid fa-trophy mr-1 text-amber-500"></i> Daftar Sertifikat Prestasi Terlampir (<?= count($cardAchievements) ?>):</span>
                                            <ol class="list-decimal list-inside mt-0.5 space-y-0.5 text-[11px] text-slate-600">
                                                <?php foreach ($cardAchievements as $cIdx => $cItem): ?>
                                                    <li><?= htmlspecialchars($cItem['title'] ?? '') ?> <span class="text-slate-500 font-semibold">[Tingkat <?= ucfirst($cItem['level'] ?? 'sekolah') ?>]</span></li>
                                                <?php endforeach; ?>
                                            </ol>
                                        </div>
                                    <?php elseif (!empty($student['achievement_desc'])): ?>
                                        (<?= htmlspecialchars($student['achievement_desc']) ?><?= !empty($student['achievement_cert_doc']) ? ' • Sertifikat Terlampir' : '' ?>)
                                    <?php endif; ?>
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

        <!-- JADWAL & LOKASI PELAKSANAAN UJIAN SELEKSI MASUK / OFFLINE -->
        <div class="rounded-2xl border-2 border-emerald-600/30 bg-emerald-50/60 p-5 mb-6 text-xs text-slate-800 shadow-sm">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-emerald-200 pb-2.5 mb-3">
                <span class="font-extrabold text-emerald-950 uppercase tracking-wider text-xs flex items-center gap-2">
                    <i class="fa-solid fa-calendar-check text-emerald-700 text-sm"></i>
                    <span>Jadwal & Ruang Pelaksanaan Ujian Seleksi Masuk (Offline / Mandiri)</span>
                </span>
                <span class="px-2.5 py-0.5 rounded bg-emerald-700 text-white font-bold text-[10px] uppercase tracking-wide self-start sm:self-auto">
                    <?= htmlspecialchars($student['exam_session'] ?: 'Sesi 1 (Reguler)') ?>
                </span>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center sm:text-left">
                <div class="p-2.5 rounded-xl bg-white border border-emerald-200 shadow-2xs">
                    <span class="text-[10px] font-bold text-slate-500 uppercase block">Tanggal Ujian</span>
                    <strong class="text-slate-900 text-xs mt-0.5 block font-bold">
                        <?= !empty($student['exam_date']) ? date('d F Y', strtotime($student['exam_date'])) : 'Sesuai Jadwal Gelombang' ?>
                    </strong>
                </div>
                <div class="p-2.5 rounded-xl bg-white border border-emerald-200 shadow-2xs">
                    <span class="text-[10px] font-bold text-slate-500 uppercase block">Waktu / Jam Ujian</span>
                    <strong class="text-slate-900 text-xs mt-0.5 block font-bold">
                        <?= htmlspecialchars($student['exam_time'] ?: '08.00 - 10.00 WIB') ?>
                    </strong>
                </div>
                <div class="p-2.5 rounded-xl bg-white border border-emerald-200 shadow-2xs">
                    <span class="text-[10px] font-bold text-slate-500 uppercase block">Ruangan / Lab Ujian</span>
                    <strong class="text-emerald-800 text-xs mt-0.5 block font-black">
                        <?= htmlspecialchars($student['exam_room'] ?: 'Lab Komputer / Ruang Seleksi') ?>
                    </strong>
                </div>
                <div class="p-2.5 rounded-xl bg-white border border-emerald-200 shadow-2xs">
                    <span class="text-[10px] font-bold text-slate-500 uppercase block">Tempat / Lokasi</span>
                    <strong class="text-slate-900 text-xs mt-0.5 block font-bold truncate" title="<?= htmlspecialchars($school_info['school_name'] ?? 'Gedung Utama Sekolah') ?>">
                        <?= htmlspecialchars($school_info['school_name'] ?? 'Gedung Sekolah') ?>
                    </strong>
                </div>
            </div>

            <?php if (!empty($student['notes'])): ?>
                <div class="mt-3 pt-2.5 border-t border-emerald-200/80 text-[11px] text-slate-700 flex items-start gap-1.5">
                    <i class="fa-solid fa-circle-info text-emerald-600 mt-0.5 shrink-0"></i>
                    <span><strong>Catatan Khusus Panitia:</strong> <?= htmlspecialchars($student['notes']) ?></span>
                </div>
            <?php endif; ?>
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
