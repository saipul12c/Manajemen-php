<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$student_id= isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($student_id <= 0) {
    header("Location: users.php?error=invalid_id");
    exit;
}

// Cek hak akses: staf/admin/guru, siswa bersangkutan, atau orang tua siswa
$is_staff_or_admin = in_array($user_role, ['staf', 'administrator', 'guru'], true);
$is_self           = ($user_role === 'siswa' && $student_id === $user_id);
$is_parent         = false;

if ($user_role === 'orang_tua') {
    $chk_p = $pdo->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ?");
    $chk_p->execute([$user_id, $student_id]);
    $is_parent = (bool)$chk_p->fetch();
}

if (!$is_staff_or_admin && !$is_self && !$is_parent) {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

$school_info = getSchoolSettings($pdo);

// Query Data Pokok Siswa & Buku Induk
$stmt = $pdo->prepare("
    SELECT u.*, c.name as class_name, c.grade_level,
           p.name as parent_name, p.phone as parent_phone, p.address as parent_address,
           ppdb.birth_place, ppdb.birth_date, ppdb.gender, ppdb.religion, ppdb.previous_school,
           ppdb.parent_job, ppdb.nik, ppdb.registration_no
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN parent_students ps ON u.id = ps.student_id
    LEFT JOIN users p ON ps.parent_id = p.id
    LEFT JOIN ppdb_registrations ppdb ON (u.email = ppdb.email OR (u.nisn IS NOT NULL AND u.nisn != '' AND u.nisn = ppdb.nisn))
    WHERE u.id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    header("Location: users.php?error=not_found");
    exit;
}

// Format Tanggal Indonesia
$bulan_indo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
function tglIndoFormat($date_str, $bulan_indo) {
    if (!$date_str) return '-';
    $t = strtotime($date_str);
    $d = date('j', $t);
    $m = $bulan_indo[(int)date('n', $t)] ?? date('F', $t);
    $y = date('Y', $t);
    return "$d $m $y";
}

$page_title = "Lembar Buku Induk Siswa - " . htmlspecialchars($student['name']);
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
            @page { margin: 1.5cm; size: A4 portrait; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center p-4 sm:p-8 font-serif antialiased">

    <!-- Action Bar -->
    <div class="no-print w-full max-w-4xl mb-6 flex items-center justify-between font-sans">
        <a href="users.php" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Daftar Pengguna
        </a>
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition inline-flex items-center gap-2 cursor-pointer">
                <i class="fa-solid fa-print"></i> Cetak Lembar Buku Induk
            </button>
        </div>
    </div>

    <!-- Lembar Kertas Dokumen Resmi Standar A4 -->
    <div class="print-paper w-full max-w-4xl bg-white text-slate-900 rounded-3xl p-8 sm:p-12 shadow-2xl border border-slate-200">
        
        <!-- KOP SURAT RESMI -->
        <div class="border-b-4 border-double border-slate-900 pb-4 mb-6">
            <div class="flex items-center justify-center gap-6">
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
                        NPSN: <?= htmlspecialchars($school_info['school_npsn'] ?? '20108392') ?> | Telp: <?= htmlspecialchars($school_info['school_phone']) ?> | Email: <?= htmlspecialchars($school_info['school_email']) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- JUDUL BUKU INDUK -->
        <div class="text-center my-4">
            <h2 class="text-lg font-bold uppercase tracking-wider underline decoration-2 text-slate-950 font-sans">
                LEMBAR BUKU INDUK PESERTA DIDIK
            </h2>
            <p class="text-xs font-mono text-slate-700 mt-1">
                NOMOR INDUK: <?= sprintf('%04d', $student['id']) ?> / NISN: <?= htmlspecialchars($student['nisn'] ?: 'Belum Tercatat') ?>
            </p>
        </div>

        <div class="space-y-6 text-sm text-slate-800">

            <!-- BAGIAN I: IDENTITAS DIRI -->
            <div>
                <h3 class="font-sans text-xs font-bold uppercase bg-slate-100 px-3 py-1.5 rounded-lg border border-slate-200 text-slate-900 mb-2">
                    A. KETERANGAN TENTANG DIRI PESERTA DIDIK
                </h3>
                <div class="ml-4 space-y-1.5 font-sans text-xs">
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">1.</span>
                        <span class="col-span-4 text-slate-600">Nama Lengkap Siswa</span>
                        <span class="col-span-7 font-bold text-slate-900">: <?= strtoupper(htmlspecialchars($student['name'])) ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">2.</span>
                        <span class="col-span-4 text-slate-600">Nomor Induk Siswa Nasional (NISN)</span>
                        <span class="col-span-7 font-mono font-semibold text-slate-900">: <?= htmlspecialchars($student['nisn'] ?: '-') ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">3.</span>
                        <span class="col-span-4 text-slate-600">Nomor Induk Kependudukan (NIK)</span>
                        <span class="col-span-7 font-mono text-slate-900">: <?= htmlspecialchars($student['nik'] ?: '-') ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">4.</span>
                        <span class="col-span-4 text-slate-600">Jenis Kelamin</span>
                        <span class="col-span-7 text-slate-900">: <?= ($student['gender'] === 'L') ? 'Laki-Laki' : (($student['gender'] === 'P') ? 'Perempuan' : 'Laki-Laki') ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">5.</span>
                        <span class="col-span-4 text-slate-600">Tempat, Tanggal Lahir</span>
                        <span class="col-span-7 text-slate-900">: <?= htmlspecialchars($student['birth_place'] ?: 'Jakarta') ?>, <?= tglIndoFormat($student['birth_date'] ?: '2009-05-15', $bulan_indo) ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">6.</span>
                        <span class="col-span-4 text-slate-600">Agama & Kepercayaan</span>
                        <span class="col-span-7 text-slate-900">: <?= htmlspecialchars($student['religion'] ?: 'Islam') ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">7.</span>
                        <span class="col-span-4 text-slate-600">Kewarganegaraan</span>
                        <span class="col-span-7 text-slate-900">: Indonesia (WNI)</span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">8.</span>
                        <span class="col-span-4 text-slate-600">Rombongan Belajar (Rombel / Kelas)</span>
                        <span class="col-span-7 font-bold text-slate-900">: <?= htmlspecialchars($student['class_name'] ?? 'Kelas X-A') ?> (Tingkat: <?= htmlspecialchars($student['grade_level'] ?? '10') ?>)</span>
                    </div>
                </div>
            </div>

            <!-- BAGIAN II: TEMPAT TINGGAL -->
            <div>
                <h3 class="font-sans text-xs font-bold uppercase bg-slate-100 px-3 py-1.5 rounded-lg border border-slate-200 text-slate-900 mb-2">
                    B. KETERANGAN TEMPAT TINGGAL
                </h3>
                <div class="ml-4 space-y-1.5 font-sans text-xs">
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">9.</span>
                        <span class="col-span-4 text-slate-600">Alamat Tempat Tinggal Lengkap</span>
                        <span class="col-span-7 text-slate-900">: <?= htmlspecialchars($student['address'] ?: 'DKI Jakarta, Indonesia') ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">10.</span>
                        <span class="col-span-4 text-slate-600">Nomor Telepon / WhatsApp Siswa</span>
                        <span class="col-span-7 font-mono text-slate-900">: <?= htmlspecialchars($student['phone'] ?: '-') ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">11.</span>
                        <span class="col-span-4 text-slate-600">Alamat Surat Elektronik (Email)</span>
                        <span class="col-span-7 font-mono text-slate-900">: <?= htmlspecialchars($student['email']) ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">12.</span>
                        <span class="col-span-4 text-slate-600">Tinggal Bersama</span>
                        <span class="col-span-7 text-slate-900">: Orang Tua Kandung</span>
                    </div>
                </div>
            </div>

            <!-- BAGIAN III: PENDIDIKAN SEBELUMNYA -->
            <div>
                <h3 class="font-sans text-xs font-bold uppercase bg-slate-100 px-3 py-1.5 rounded-lg border border-slate-200 text-slate-900 mb-2">
                    C. KETERANGAN PENDIDIKAN SEBELUMNYA
                </h3>
                <div class="ml-4 space-y-1.5 font-sans text-xs">
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">13.</span>
                        <span class="col-span-4 text-slate-600">Sekolah Asal (SMP / MTs)</span>
                        <span class="col-span-7 font-semibold text-slate-900">: <?= htmlspecialchars($student['previous_school'] ?: 'SMP Negeri Sederajat') ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">14.</span>
                        <span class="col-span-4 text-slate-600">Jalur Penerimaan Masuk</span>
                        <span class="col-span-7 text-slate-900">: Seleksi Reguler / PPDB Daring</span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">15.</span>
                        <span class="col-span-4 text-slate-600">Tanggal Terdaftar Masuk Sekolah</span>
                        <span class="col-span-7 text-slate-900">: <?= tglIndoFormat($student['created_at'], $bulan_indo) ?></span>
                    </div>
                </div>
            </div>

            <!-- BAGIAN IV: ORANG TUA / WALI -->
            <div>
                <h3 class="font-sans text-xs font-bold uppercase bg-slate-100 px-3 py-1.5 rounded-lg border border-slate-200 text-slate-900 mb-2">
                    D. KETERANGAN TENTANG ORANG TUA / WALI MURID
                </h3>
                <div class="ml-4 space-y-1.5 font-sans text-xs">
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">16.</span>
                        <span class="col-span-4 text-slate-600">Nama Orang Tua / Wali</span>
                        <span class="col-span-7 font-bold text-slate-900">: <?= htmlspecialchars($student['parent_name'] ?: 'Wali Murid Siswa') ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">17.</span>
                        <span class="col-span-4 text-slate-600">Pekerjaan Orang Tua / Wali</span>
                        <span class="col-span-7 text-slate-900">: <?= htmlspecialchars($student['parent_job'] ?: 'Wiraswasta / Karyawan') ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">18.</span>
                        <span class="col-span-4 text-slate-600">Nomor Telepon Orang Tua / Wali</span>
                        <span class="col-span-7 font-mono text-slate-900">: <?= htmlspecialchars($student['parent_phone'] ?: '-') ?></span>
                    </div>
                    <div class="grid grid-cols-12">
                        <span class="col-span-1 text-slate-500">19.</span>
                        <span class="col-span-4 text-slate-600">Alamat Rumah Orang Tua / Wali</span>
                        <span class="col-span-7 text-slate-900">: <?= htmlspecialchars($student['parent_address'] ?: ($student['address'] ?: 'Sesuai domisili peserta didik')) ?></span>
                    </div>
                </div>
            </div>

        </div>

        <!-- TANDA TANGAN, PAS FOTO & PENGESAHAN BUKU INDUK -->
        <div class="grid grid-cols-3 gap-6 pt-8 mt-6 border-t border-slate-300 font-sans text-xs">
            
            <!-- Pas Foto 3x4 Box -->
            <div class="flex flex-col items-center justify-center">
                <div class="w-24 h-32 border-2 border-dashed border-slate-400 bg-slate-50 flex flex-col items-center justify-center text-slate-400 rounded-lg shadow-sm">
                    <i class="fa-solid fa-user text-2xl mb-1 text-slate-300"></i>
                    <span class="text-[10px] font-bold">PAS FOTO</span>
                    <span class="text-[9px]">3 x 4 cm</span>
                </div>
                <span class="text-[10px] text-slate-500 mt-2 font-mono">NISN: <?= htmlspecialchars($student['nisn'] ?: '-') ?></span>
            </div>

            <!-- Tanda Tangan Peserta Didik -->
            <div class="text-center flex flex-col justify-between h-40">
                <div>
                    <p class="text-slate-600">Peserta Didik yang Bersangkutan,</p>
                </div>
                <div>
                    <p class="font-bold underline text-slate-900"><?= htmlspecialchars($student['name']) ?></p>
                    <p class="text-[10px] text-slate-500 font-mono">Tanda Tangan Asli</p>
                </div>
            </div>

            <!-- Tanda Tangan Pengesahan Kepala Sekolah & Staf TU -->
            <div class="text-right flex flex-col justify-between h-40">
                <div>
                    <p class="text-slate-600">Jakarta, <?= tglIndoFormat(date('Y-m-d'), $bulan_indo) ?></p>
                    <p class="font-bold text-slate-900 mt-0.5">Kepala Sekolah,</p>
                </div>
                <div>
                    <p class="font-bold underline text-slate-900"><?= htmlspecialchars($school_info['headmaster_name']) ?></p>
                    <p class="text-xs text-slate-600 font-mono">NIP. <?= htmlspecialchars($school_info['headmaster_nip']) ?></p>
                </div>
            </div>

        </div>

    </div>

</body>
</html>
