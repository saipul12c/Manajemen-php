<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$req_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($req_id <= 0) {
    header("Location: requests.php?error=invalid_id");
    exit;
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
    header("Location: requests.php?error=not_found");
    exit;
}

// Cek hak akses: staf/admin, pemilik surat, atau orang tua dari siswa yang bersangkutan
$is_owner = ($req['user_id'] == $user_id);
$is_parent_of_student = false;
if ($user_role === 'orang_tua') {
    $chk_ps = $pdo->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ?");
    $chk_ps->execute([$user_id, $req['user_id']]);
    $is_parent_of_student = (bool) $chk_ps->fetch();
}

if (!in_array($user_role, ['staf', 'administrator'], true) && !$is_owner && !$is_parent_of_student) {
    header("Location: requests.php?error=unauthorized");
    exit;
}

// Format Penomoran Surat Resmi Berdasarkan Kode Klasifikasi Arsip
$type_codes = [
    'Surat Keterangan Aktif Siswa'               => '421.3/KET-AKTIF',
    'Surat Keterangan Berkelakuan Baik (SKBB)'   => '421.3/SKBB',
    'Surat Keterangan Lulus (SKL) Sementara'     => '421.3/SKL',
    'Surat Undangan / Panggilan Orang Tua Murid' => '005/UND-ORTU',
    'Surat Keterangan Pindah / Mutasi Siswa'     => '422.1/MUTASI',
    'Surat Rekomendasi Beasiswa / Prestasi'      => '421.4/REK',
    'Surat Izin Dispensasi Acara / Kegiatan'     => '421.3/DISP',
    'Surat Izin Sakit Siswa'                     => '421.3/IZIN-SAKIT',
    'Surat Perintah Tugas (SPT) Guru / Pegawai' => '800/SPT-GTK',
];
$prefix = $type_codes[$req['request_type']] ?? '421.3/SRT';

$romans = [1=>'I', 2=>'II', 3=>'III', 4=>'IV', 5=>'V', 6=>'VI', 7=>'VII', 8=>'VIII', 9=>'IX', 10=>'X', 11=>'XI', 12=>'XII'];
$created_time = strtotime($req['created_at']);
$month_num = (int) date('n', $created_time);
$roman_month = $romans[$month_num] ?? date('m', $created_time);
$year = date('Y', $created_time);
$letter_number = $prefix . "/" . sprintf('%03d', $req['id']) . "/SMA-BBN/" . $roman_month . "/" . $year;

$page_title = "Surat Resmi - " . htmlspecialchars($req['request_type']);

// Nama hari dalam Bahasa Indonesia
$hari_indo = [
    'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
];
$target_time = !empty($req['target_date']) ? strtotime($req['target_date']) : time();
$nama_hari = $hari_indo[date('l', $target_time)] ?? date('l', $target_time);

// Nama bulan dalam Bahasa Indonesia
$bulan_indo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
function tglIndo($date_str, $bulan_indo) {
    if (!$date_str) return '-';
    $t = strtotime($date_str);
    $d = date('j', $t);
    $m = $bulan_indo[(int)date('n', $t)] ?? date('F', $t);
    $y = date('Y', $t);
    return "$d $m $y";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        @media print {
            body { background: white !important; color: black !important; padding: 0 !important; }
            .no-print { display: none !important; }
            .print-paper { border: none !important; box-shadow: none !important; padding: 0 !important; max-width: 100% !important; margin: 0 !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center p-4 sm:p-8 font-serif antialiased">

    <!-- Action Bar -->
    <div class="no-print w-full max-w-3xl mb-6 flex items-center justify-between font-sans">
        <a href="requests.php" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Layanan Surat
        </a>
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition inline-flex items-center gap-2 cursor-pointer">
                <i class="fa-solid fa-print"></i> Cetak / Simpan PDF
            </button>
        </div>
    </div>

    <!-- LEMBAR SURAT RESMI DINAS SEKOLAH -->
    <div class="print-paper w-full max-w-3xl rounded-3xl border border-white/15 bg-white text-slate-900 p-8 sm:p-14 shadow-2xl">
        
        <!-- KOP SURAT DINAS PEMERINTAH / LEMBAGA PENDIDIKAN -->
        <div class="relative pb-4 mb-6 border-b-4 border-slate-900" style="border-bottom-style: double;">
            <div class="flex items-center justify-center gap-5">
                <div class="h-20 w-20 flex items-center justify-center rounded-2xl border-2 border-slate-800 bg-slate-50 text-slate-800 shrink-0 shadow-sm">
                    <i class="fa-solid fa-graduation-cap text-3xl"></i>
                </div>
                <div class="text-center font-sans">
                    <h3 class="text-xs font-bold uppercase tracking-widest text-slate-600">PEMERINTAH DAERAH PROVINSI / KABUPATEN</h3>
                    <h4 class="text-xs font-bold uppercase tracking-widest text-slate-700">DINAS PENDIDIKAN DAN KEBUDAYAAN</h4>
                    <h1 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-slate-950 mt-0.5">
                        <?= htmlspecialchars($school_info['school_name']) ?>
                    </h1>
                    <p class="text-xs text-slate-600 mt-0.5">
                        <?= htmlspecialchars($school_info['school_address']) ?>
                    </p>
                    <p class="text-[11px] text-slate-500 font-mono mt-0.5">
                        Telp: <?= htmlspecialchars($school_info['school_phone']) ?> | Email: <?= htmlspecialchars($school_info['school_email']) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- FORMAT 1: SURAT UNDANGAN / PANGGILAN ORANG TUA / WALI -->
        <!-- ========================================================= -->
        <?php if (stripos($req['request_type'], 'Undangan') !== false || stripos($req['request_type'], 'Panggilan') !== false): ?>

            <div class="font-sans text-xs space-y-1 mb-6 text-slate-800">
                <div class="flex justify-between">
                    <div class="space-y-1">
                        <div class="grid grid-cols-3 w-72">
                            <span class="text-slate-600">Nomor</span>
                            <span class="col-span-2 font-mono font-bold">: <?= $letter_number ?></span>
                        </div>
                        <div class="grid grid-cols-3 w-72">
                            <span class="text-slate-600">Lampiran</span>
                            <span class="col-span-2">: - (Nihil)</span>
                        </div>
                        <div class="grid grid-cols-3 w-72">
                            <span class="text-slate-600">Perihal</span>
                            <span class="col-span-2 font-semibold text-slate-900">: <u>Undangan Pertemuan Orang Tua/Wali</u></span>
                        </div>
                    </div>
                    <div class="text-right">
                        <p>Jakarta, <?= tglIndo($req['created_at'], $bulan_indo) ?></p>
                    </div>
                </div>

                <div class="pt-4">
                    <p>Kepada Yth.</p>
                    <p class="font-bold text-slate-900">Bapak / Ibu Orang Tua / Wali Murid dari:</p>
                    <p class="font-semibold text-slate-900 pl-4"><?= htmlspecialchars($req['applicant_name']) ?> (Kelas: <?= htmlspecialchars($req['class_name'] ?? 'Siswa') ?>)</p>
                    <p class="text-slate-600 pl-4"><?= htmlspecialchars($req['applicant_address'] ?: 'Di Tempat') ?></p>
                </div>
            </div>

            <div class="space-y-3.5 text-sm leading-relaxed text-slate-800 text-justify">
                <p>
                    Dengan hormat,
                </p>
                <p>
                    Sehubungan dengan program pembinaan peserta didik serta koordinasi perkembangan akademik dan tata tertib di <strong><?= htmlspecialchars($school_info['school_name']) ?></strong>, bersama ini kami mengharapkan kehadiran Bapak/Ibu Orang Tua/Wali Murid pada:
                </p>

                <div class="my-4 ml-6 space-y-1.5 font-sans text-xs bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600 font-medium">Hari / Tanggal</span>
                        <span class="col-span-3 font-bold text-slate-900">: <?= $nama_hari ?>, <?= tglIndo($req['target_date'], $bulan_indo) ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600 font-medium">Waktu</span>
                        <span class="col-span-3 font-semibold text-slate-900">: 09.00 WIB s.d Selesai</span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600 font-medium">Tempat</span>
                        <span class="col-span-3 text-slate-900">: Ruang Bimbingan Konseling (BK) / Tata Usaha</span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600 font-medium">Agenda Pertemuan</span>
                        <span class="col-span-3 text-slate-900 font-semibold">: <?= htmlspecialchars($req['notes'] ?: 'Koordinasi Perkembangan Belajar dan Pembinaan Siswa') ?></span>
                    </div>
                </div>

                <p>
                    Mengingat pentingnya agenda tersebut demi kemajuan pendidikan putra/putri Bapak/Ibu, kami sangat mengharapkan kehadiran Bapak/Ibu tepat pada waktu yang telah ditentukan. Atas perhatian dan kerja sama yang baik, kami ucapkan terima kasih.
                </p>
            </div>

        <!-- ========================================================= -->
        <!-- FORMAT 2: SURAT KETERANGAN KELULUSAN (SKL) SEMENTARA -->
        <!-- ========================================================= -->
        <?php elseif (stripos($req['request_type'], 'Lulus') !== false || stripos($req['request_type'], 'SKL') !== false): ?>

            <div class="text-center my-6">
                <h2 class="text-lg font-bold uppercase tracking-wider underline decoration-2 text-slate-900 font-sans">
                    SURAT KETERANGAN LULUS SEMENTARA
                </h2>
                <p class="text-xs font-mono text-slate-600 mt-1">Nomor: <?= $letter_number ?></p>
            </div>

            <div class="space-y-4 text-sm leading-relaxed text-slate-800 text-justify">
                <p>
                    Yang bertanda tangan di bawah ini, Kepala Sekolah <strong><?= htmlspecialchars($school_info['school_name']) ?></strong>, berdasarkan kriteria kelulusan peserta didik dan hasil Rapat Pleno Dewan Guru tentang Kelulusan Tahun Ajaran <strong><?= htmlspecialchars($school_info['academic_year']) ?></strong>, dengan ini menerangkan bahwa:
                </p>

                <div class="my-4 ml-6 space-y-1.5 font-sans text-xs">
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Nama Lengkap</span>
                        <span class="col-span-3 font-bold text-slate-900">: <?= htmlspecialchars($req['applicant_name']) ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">NISN / Nomor Induk</span>
                        <span class="col-span-3 font-mono text-slate-900">: <?= htmlspecialchars($req['nisn'] ?: '3171092837') ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Kelas / Rombel Terakhir</span>
                        <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['class_name'] ?? 'Kelas XII') ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Alamat Tempat Tinggal</span>
                        <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['applicant_address'] ?: 'Jakarta, Indonesia') ?></span>
                    </div>
                </div>

                <div class="my-6 py-4 px-6 text-center border-2 border-slate-900 bg-slate-50 rounded-2xl shadow-sm">
                    <span class="text-xs uppercase font-sans font-bold tracking-widest text-slate-600 block">Dinyatakan:</span>
                    <span class="text-3xl font-black font-sans tracking-widest text-emerald-700 uppercase mt-1 block">L U L U S</span>
                    <span class="text-[11px] font-sans text-slate-500 mt-1 block">Dari Satuan Pendidikan <?= htmlspecialchars($school_info['school_name']) ?></span>
                </div>

                <p>
                    Surat Keterangan Lulus (SKL) ini bersifat <strong>sementara</strong> dan berlaku sah sampai dengan diterbitkannya Ijazah asli fisik, serta dapat dipergunakan untuk keperluan: <em>"<?= htmlspecialchars($req['notes'] ?: 'Melanjutkan Pendidikan ke Perguruan Tinggi / Melamar Pekerjaan') ?>"</em>.
                </p>

                <p>
                    Demikian surat keterangan ini dibuat dengan sebenarnya untuk dapat dipergunakan sebagaimana mestinya bagi pihak-pihak yang berkepentingan.
                </p>
            </div>

        <!-- ========================================================= -->
        <!-- FORMAT 3: SURAT KETERANGAN BERKELAKUAN BAIK (SKBB) -->
        <!-- ========================================================= -->
        <?php elseif (stripos($req['request_type'], 'Berkelakuan Baik') !== false || stripos($req['request_type'], 'SKBB') !== false): ?>

            <div class="text-center my-6">
                <h2 class="text-lg font-bold uppercase tracking-wider underline decoration-2 text-slate-900 font-sans">
                    SURAT KETERANGAN BERKELAKUAN BAIK
                </h2>
                <p class="text-xs font-mono text-slate-600 mt-1">Nomor: <?= $letter_number ?></p>
            </div>

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
                        <span class="text-slate-600">NISN / Nomor Induk</span>
                        <span class="col-span-3 font-mono text-slate-900">: <?= htmlspecialchars($req['nisn'] ?: '-') ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Kelas / Rombel</span>
                        <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['class_name'] ?? 'Peserta Didik') ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Alamat Tempat Tinggal</span>
                        <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['applicant_address'] ?: 'Jakarta, Indonesia') ?></span>
                    </div>
                </div>

                <p>
                    Berdasarkan buku catatan kedisiplinan sekolah dan data unit Bimbingan Konseling (BK), peserta didik tersebut di atas selama mengikuti proses pendidikan di <strong><?= htmlspecialchars($school_info['school_name']) ?></strong> memiliki dedikasi, integritas, dan budi pekerti yang <strong>BAIK</strong>, serta:
                </p>

                <ol class="list-decimal ml-8 space-y-1 font-sans text-xs text-slate-700">
                    <li>Tidak pernah terlibat dalam tindak pidana, kriminalitas, perkelahian, ataupun tawuran antarpelajar.</li>
                    <li>Bebas dan tidak pernah terlibat dalam penyalahgunaan Narkotika, Psikotropika, dan Zat Adiktif (NAPZA).</li>
                    <li>Tidak sedang menjalani pembinaan disiplin atau sanksi pelanggaran tata tertib sekolah tingkat berat.</li>
                </ol>

                <p>
                    Surat keterangan ini diterbitkan secara sah dan objektif untuk memenuhi kelengkapan persyaratan: <em>"<?= htmlspecialchars($req['notes'] ?: 'Melanjutkan Pendidikan ke Jenjang Berikutnya / Keperluan Kedinasan') ?>"</em>.
                </p>

                <p>
                    Demikian surat keterangan ini dibuat dengan sebenarnya agar dapat dipergunakan sebagaimana mestinya.
                </p>
            </div>

        <!-- ========================================================= -->
        <!-- FORMAT 4: SURAT KETERANGAN PINDAH / MUTASI SISWA -->
        <!-- ========================================================= -->
        <?php elseif (stripos($req['request_type'], 'Pindah') !== false || stripos($req['request_type'], 'Mutasi') !== false): ?>

            <div class="text-center my-6">
                <h2 class="text-lg font-bold uppercase tracking-wider underline decoration-2 text-slate-900 font-sans">
                    SURAT KETERANGAN PINDAH SEKOLAH (MUTASI SISWA)
                </h2>
                <p class="text-xs font-mono text-slate-600 mt-1">Nomor: <?= $letter_number ?></p>
            </div>

            <div class="space-y-4 text-sm leading-relaxed text-slate-800 text-justify">
                <p>
                    Yang bertanda tangan di bawah ini, Kepala Sekolah <strong><?= htmlspecialchars($school_info['school_name']) ?></strong>, atas dasar permohonan tertulis dari Orang Tua / Wali Murid, dengan ini menerangkan bahwa:
                </p>

                <div class="my-4 ml-6 space-y-1.5 font-sans text-xs">
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Nama Lengkap</span>
                        <span class="col-span-3 font-bold text-slate-900">: <?= htmlspecialchars($req['applicant_name']) ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">NISN / Nomor Induk</span>
                        <span class="col-span-3 font-mono text-slate-900">: <?= htmlspecialchars($req['nisn'] ?: '-') ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Kelas Terakhir</span>
                        <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['class_name'] ?? 'Siswa') ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Alamat Orang Tua/Wali</span>
                        <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['applicant_address'] ?: 'Jakarta, Indonesia') ?></span>
                    </div>
                </div>

                <p>
                    Terhitung mulai tanggal <strong><?= tglIndo($req['target_date'], $bulan_indo) ?></strong>, telah resmi disetujui pindah/mutasi keluar dari <strong><?= htmlspecialchars($school_info['school_name']) ?></strong> ke sekolah tujuan:
                </p>

                <div class="my-3 p-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-sans">
                    <span class="text-slate-500 font-medium">Sekolah Tujuan Mutasi:</span>
                    <p class="text-sm font-bold text-slate-900 mt-0.5"><?= htmlspecialchars($req['notes'] ?: 'Sesuai permohonan kepindahan orang tua/wali murid') ?></p>
                </div>

                <p>
                    Siswa yang bersangkutan dinyatakan telah menyelesaikan seluruh kewajiban administrasi, tidak memiliki tanggungan peminjaman buku perpustakaan, dan telah melunasi kewajiban iuran/SPP sekolah.
                </p>

                <p>
                    Demikian surat keterangan pindah sekolah ini diberikan untuk dapat dipergunakan sebagaimana mestinya.
                </p>
            </div>

        <!-- ========================================================= -->
        <!-- FORMAT 5: SURAT REKOMENDASI BEASISWA / PRESTASI -->
        <!-- ========================================================= -->
        <?php elseif (stripos($req['request_type'], 'Rekomendasi') !== false): ?>

            <div class="text-center my-6">
                <h2 class="text-lg font-bold uppercase tracking-wider underline decoration-2 text-slate-900 font-sans">
                    SURAT REKOMENDASI SEKOLAH
                </h2>
                <p class="text-xs font-mono text-slate-600 mt-1">Nomor: <?= $letter_number ?></p>
            </div>

            <div class="space-y-4 text-sm leading-relaxed text-slate-800 text-justify">
                <p>
                    Yang bertanda tangan di bawah ini, Kepala Sekolah <strong><?= htmlspecialchars($school_info['school_name']) ?></strong> dengan ini memberikan rekomendasi penuh kepada peserta didik:
                </p>

                <div class="my-4 ml-6 space-y-1.5 font-sans text-xs">
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Nama Lengkap</span>
                        <span class="col-span-3 font-bold text-slate-900">: <?= htmlspecialchars($req['applicant_name']) ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">NISN / Nomor Induk</span>
                        <span class="col-span-3 font-mono text-slate-900">: <?= htmlspecialchars($req['nisn'] ?: '-') ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Kelas / Rombel</span>
                        <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['class_name'] ?? 'Peserta Didik') ?></span>
                    </div>
                </div>

                <p>
                    Berdasarkan evaluasi capaian akademik, kedisiplinan, serta potensi pengembangan bakat dan minat yang bersangkutan selama belajar di sekolah kami, pihak sekolah memberikan rekomendasi untuk mengikuti / menerima:
                </p>

                <div class="my-3 p-4 bg-slate-50 border border-slate-200 rounded-xl text-xs font-sans">
                    <span class="text-slate-500 font-medium">Tujuan / Program Rekomendasi:</span>
                    <p class="text-sm font-bold text-slate-900 mt-0.5"><?= htmlspecialchars($req['notes'] ?: 'Program Beasiswa Pendidikan / Partisipasi Kompetisi & Perlombaan') ?></p>
                </div>

                <p>
                    Pihak sekolah senantiasa mendukung sepenuhnya keikutsertaan siswa tersebut demi tercapainya prestasi yang optimal dan pengembangan diri berkelanjutan.
                </p>

                <p>
                    Demikian surat rekomendasi ini dibuat dengan penuh tanggung jawab untuk dapat dipergunakan sebagaimana mestinya.
                </p>
            </div>

        <!-- ========================================================= -->
        <!-- FORMAT KHUSUS: SURAT PERINTAH TUGAS (SPT) GURU / PEGAWAI  -->
        <!-- ========================================================= -->
        <?php elseif (stripos($req['request_type'], 'SPT') !== false || stripos($req['request_type'], 'Tugas') !== false): ?>

            <div class="text-center my-6">
                <h2 class="text-lg font-bold uppercase tracking-wider underline decoration-2 text-slate-900 font-sans">
                    SURAT PERINTAH TUGAS (SPT)
                </h2>
                <p class="text-xs font-mono text-slate-600 mt-1">Nomor: <?= $letter_number ?></p>
            </div>

            <div class="space-y-4 text-sm leading-relaxed text-slate-800 text-justify">
                <div class="font-sans text-xs space-y-1 mb-2">
                    <p class="font-bold text-slate-700 uppercase">DASAR PENUGASAN:</p>
                    <ol class="list-decimal pl-5 space-y-0.5 text-slate-600">
                        <li>Undang-Undang Republik Indonesia Nomor 14 Tahun 2005 tentang Guru dan Dosen.</li>
                        <li>Peraturan Pemerintah Nomor 19 Tahun 2017 tentang Perubahan atas PP No. 74 Tahun 2008 tentang Guru.</li>
                        <li>Program Kerja dan Rencana Kegiatan Anggaran Sekolah (RKAS) <?= htmlspecialchars($school_info['school_name']) ?> Tahun Ajaran <?= htmlspecialchars($school_info['academic_year']) ?>.</li>
                    </ol>
                </div>

                <div class="text-center font-bold text-slate-900 py-1 font-sans tracking-wider border-y border-dashed border-slate-300">
                    MEMERINTAHKAN:
                </div>

                <div class="space-y-2">
                    <p class="font-sans text-xs font-bold text-slate-700">Kepada Pegawai / Pendidik:</p>
                    <div class="my-2 ml-4 space-y-1.5 font-sans text-xs bg-slate-50 p-3.5 rounded-xl border border-slate-200">
                        <div class="grid grid-cols-4">
                            <span class="text-slate-600">Nama Lengkap</span>
                            <span class="col-span-3 font-bold text-slate-900">: <?= htmlspecialchars($req['applicant_name']) ?></span>
                        </div>
                        <div class="grid grid-cols-4">
                            <span class="text-slate-600">NIP / NUPTK / ID</span>
                            <span class="col-span-3 font-mono text-slate-900">: <?= htmlspecialchars($req['nisn'] ?: 'GTK-'.sprintf('%04d', $req['user_id'])) ?></span>
                        </div>
                        <div class="grid grid-cols-4">
                            <span class="text-slate-600">Jabatan / Peran</span>
                            <span class="col-span-3 text-slate-900">: <?= $req['applicant_role'] === 'guru' ? 'Guru / Tenaga Pendidik' : ($req['applicant_role'] === 'staf' ? 'Tenaga Kependidikan / Tata Usaha' : 'Peserta Didik Terpilih') ?></span>
                        </div>
                        <div class="grid grid-cols-4">
                            <span class="text-slate-600">Unit Kerja</span>
                            <span class="col-span-3 font-semibold text-slate-900">: <?= htmlspecialchars($school_info['school_name']) ?></span>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <p class="font-sans text-xs font-bold text-slate-700">Untuk Melaksanakan Tugas:</p>
                    <div class="my-2 ml-4 space-y-2 font-sans text-xs">
                        <div class="p-3 bg-blue-50/60 rounded-xl border border-blue-200/80">
                            <span class="text-slate-500 font-semibold">Rincian / Uraian Tugas:</span>
                            <p class="text-sm font-bold text-slate-900 mt-1"><?= htmlspecialchars($req['notes'] ?: 'Melaksanakan tugas kedinasan / pembinaan kompetensi / koordinasi kurikulum / kepengawasan kegiatan sekolah.') ?></p>
                        </div>
                        <div class="grid grid-cols-4 pt-1">
                            <span class="text-slate-600">Hari / Tanggal</span>
                            <span class="col-span-3 font-semibold text-slate-900">: <?= $nama_hari ?>, <?= tglIndo($req['target_date'] ?: $req['created_at'], $bulan_indo) ?></span>
                        </div>
                        <div class="grid grid-cols-4">
                            <span class="text-slate-600">Waktu / Jadwal</span>
                            <span class="col-span-3 text-slate-900">: 08.00 WIB s/d Selesai</span>
                        </div>
                        <div class="grid grid-cols-4">
                            <span class="text-slate-600">Tempat Pelaksanaan</span>
                            <span class="col-span-3 text-slate-900">: Lokasi / Lembaga Penyelenggara Terkait</span>
                        </div>
                    </div>
                </div>

                <p>
                    Demikian Surat Perintah Tugas ini diterbitkan untuk dilaksanakan dengan sebaik-baiknya dan penuh rasa tanggung jawab. Setelah melaksanakan tugas, yang bersangkutan diwajibkan menyerahkan laporan hasil pelaksanaan tugas tertulis kepada Kepala Sekolah.
                </p>
            </div>

        <!-- ========================================================= -->
        <!-- FORMAT STANDAR: KETERANGAN AKTIF SISWA & DISPENSASI/LAINNYA -->
        <!-- ========================================================= -->
        <?php else: ?>

            <div class="text-center my-6">
                <h2 class="text-lg font-bold uppercase tracking-wider underline decoration-2 text-slate-900 font-sans">
                    <?= htmlspecialchars($req['request_type']) ?>
                </h2>
                <p class="text-xs font-mono text-slate-600 mt-1">Nomor: <?= $letter_number ?></p>
            </div>

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
                        <span class="text-slate-600">NISN / Nomor Induk</span>
                        <span class="col-span-3 font-mono text-slate-900">: <?= htmlspecialchars($req['nisn'] ?: 'Belum diisi') ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Kelas / Rombel</span>
                        <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['class_name'] ?? 'Peserta Didik') ?></span>
                    </div>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Alamat Tempat Tinggal</span>
                        <span class="col-span-3 text-slate-900">: <?= htmlspecialchars($req['applicant_address'] ?: 'Jakarta, Indonesia') ?></span>
                    </div>
                    <?php if (!empty($req['target_date'])): ?>
                    <div class="grid grid-cols-4">
                        <span class="text-slate-600">Tanggal Keperluan</span>
                        <span class="col-span-3 font-semibold text-slate-900">: <?= tglIndo($req['target_date'], $bulan_indo) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <p>
                    <?php if (stripos($req['request_type'], 'Aktif') !== false): ?>
                        Adalah benar peserta didik yang bersangkutan tercatat aktif mengikuti kegiatan pembelajaran di <strong><?= htmlspecialchars($school_info['school_name']) ?></strong> pada Tahun Ajaran <strong><?= htmlspecialchars($school_info['academic_year']) ?></strong> dan berkelakuan baik dalam mematuhi seluruh peraturan tata tertib sekolah.
                    <?php elseif (stripos($req['request_type'], 'Sakit') !== false || stripos($req['request_type'], 'Dispensasi') !== false): ?>
                        Diberikan izin / dispensasi tidak mengikuti kegiatan pembelajaran pada tanggal <strong><?= tglIndo($req['target_date'] ?: $req['created_at'], $bulan_indo) ?></strong> sehubungan dengan keperluan: <em>"<?= htmlspecialchars($req['notes'] ?: 'Izin / Sakit') ?>"</em>.
                    <?php else: ?>
                        Surat keterangan ini diterbitkan secara sah sesuai data pokok kesiswaan sekolah untuk keperluan: <em>"<?= htmlspecialchars($req['notes'] ?: 'Administrasi Pendidikan') ?>"</em>.
                    <?php endif; ?>
                </p>

                <?php if (stripos($req['request_type'], 'Aktif') !== false && !empty($req['notes'])): ?>
                    <p class="font-sans text-xs bg-slate-50 p-3 rounded-xl border border-slate-200">
                        <span class="text-slate-500 font-semibold">Tujuan Penerbitan:</span> <?= htmlspecialchars($req['notes']) ?>
                    </p>
                <?php endif; ?>

                <p>
                    Demikian surat keterangan ini dibuat dengan sebenarnya untuk dapat dipergunakan sebagaimana mestinya bagi pihak-pihak yang berkepentingan.
                </p>
            </div>

        <?php endif; ?>

        <!-- ========================================================= -->
        <!-- TANDA TANGAN KEPALA SEKOLAH, STEMPEL & QR VALIDASI RESMI -->
        <!-- ========================================================= -->
        <div class="grid grid-cols-2 gap-8 pt-10 mt-8 border-t border-slate-300 font-sans text-xs">
            <div class="flex flex-col items-center justify-center p-4 rounded-2xl border border-dashed border-slate-300 bg-slate-50 text-center">
                <span class="text-3xl mb-1 text-slate-700"><i class="fa-solid fa-qrcode"></i></span>
                <span class="font-mono text-[10px] text-slate-700 font-bold uppercase tracking-wider">VERIFIED-ELECTRONIC-DOCUMENT</span>
                <span class="text-[9px] text-slate-500 mt-0.5 font-mono">DOKUMEN RESMI TATA USAHA</span>
                <span class="text-[8px] text-slate-400 mt-0.5 font-mono">HASH: <?= strtoupper(substr(md5($req['id'] . $req['created_at'] . 'SEKOLAH_AUTH'), 0, 16)) ?></span>
            </div>

            <div class="text-right relative">
                <p class="text-slate-600">Dikeluarkan di: Jakarta</p>
                <p class="text-slate-600">Pada tanggal: <?= tglIndo($req['updated_at'] ?: $req['created_at'], $bulan_indo) ?></p>
                <p class="mt-2 font-bold text-slate-900">Kepala Sekolah,</p>
                
                <!-- Area Tanda Tangan & Cap Stempel -->
                <div class="h-20 flex items-center justify-end relative my-1">
                    <!-- Simulasi Cap Stempel Basah Digital -->
                    <div class="absolute right-24 w-20 h-20 rounded-full border-2 border-dashed border-blue-600/40 text-blue-700/50 flex flex-col items-center justify-center pointer-events-none rotate-[-12deg] select-none text-[8px] font-bold uppercase tracking-tighter">
                        <span class="text-[6px]">KEMENDIKBUD</span>
                        <i class="fa-solid fa-stamp text-xs my-0.5"></i>
                        <span>TERAKREDITASI</span>
                    </div>

                    <span class="text-xs italic text-blue-800 font-bold border border-blue-600/60 px-3 py-1.5 rounded-lg bg-blue-50/80 shadow-sm relative z-10">
                        <i class="fa-solid fa-signature text-xs mr-1"></i> Ditandatangani Elektronik
                    </span>
                </div>

                <p class="font-bold underline text-slate-900 text-sm"><?= htmlspecialchars($school_info['headmaster_name']) ?></p>
                <p class="text-xs text-slate-600 font-mono mt-0.5">NIP. <?= htmlspecialchars($school_info['headmaster_nip']) ?></p>
            </div>
        </div>

    </div>

</body>
</html>
