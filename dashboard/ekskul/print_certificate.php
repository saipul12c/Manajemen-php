<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';

$school = getSchoolSettings($pdo);
$academic_year = $school['academic_year'] ?? '2026/2027';

$member_id      = (int)($_GET['member_id'] ?? 0);
$achievement_id = (int)($_GET['achievement_id'] ?? 0);

$cert_type = '';
$data = null;

if ($member_id > 0) {
    $cert_type = 'member';
    $stmt = $pdo->prepare("
        SELECT em.*, u.name as student_name, u.nisn, u.gender, c.name as class_name,
               e.name as ekskul_name, e.code as ekskul_code, e.category as ekskul_category,
               e.schedule_day, e.schedule_time, e.location,
               u_lead.name as leader_name, u_lead.nip as leader_nip
        FROM extracurricular_members em
        JOIN users u ON em.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        JOIN extracurriculars e ON em.extracurricular_id = e.id
        LEFT JOIN users u_lead ON e.leader_instructor_id = u_lead.id
        WHERE em.id = ?
    ");
    $stmt->execute([$member_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($achievement_id > 0) {
    $cert_type = 'achievement';
    $stmt = $pdo->prepare("
        SELECT ach.*, u.name as student_name, u.nisn, u.gender, c.name as class_name,
               e.name as ekskul_name, e.code as ekskul_code,
               u_lead.name as leader_name, u_lead.nip as leader_nip
        FROM extracurricular_achievements ach
        JOIN users u ON ach.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        LEFT JOIN extracurriculars e ON ach.extracurricular_id = e.id
        LEFT JOIN users u_lead ON e.leader_instructor_id = u_lead.id
        WHERE ach.id = ?
    ");
    $stmt->execute([$achievement_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$data) {
    die("Dokumen sertifikat tidak ditemukan atau parameter tidak valid.");
}

// Cek hak akses jika role siswa
if ($user_role === 'siswa' && (int)$data['student_id'] !== $user_id) {
    die("Akses ditolak. Anda hanya dapat mencetak sertifikat milik Anda sendiri.");
}

$ref_no = ($cert_type === 'member')
    ? "421.3/EKS-" . htmlspecialchars($data['ekskul_code']) . "/" . str_pad((string)$data['id'], 3, '0', STR_PAD_LEFT) . "/" . date('Y')
    : "421.3/PIAGAM-PRESTASI/" . str_pad((string)$data['id'], 3, '0', STR_PAD_LEFT) . "/" . date('Y');

$verify_hash = hash('sha256', $ref_no . '|' . $data['student_name'] . '|' . ($data['ekskul_name'] ?? 'Sekolah'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $cert_type === 'member' ? 'Sertifikat_Keaktifan_' : 'Piagam_Prestasi_' ?><?= preg_replace('/[^a-zA-Z0-9]/', '_', $data['student_name']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; padding: 0 !important; }
            .cert-container { border: 4px double #1e293b !important; box-shadow: none !important; margin: 0 auto !important; }
            @page { size: A4 portrait; margin: 1.5cm; }
        }
        .cert-container {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            padding: 20mm;
            position: relative;
            box-sizing: border-box;
            border: 4px double #0f172a;
        }
        .cert-watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 80px;
            font-weight: 900;
            color: rgba(15, 23, 42, 0.03);
            pointer-events: none;
            white-space: nowrap;
            text-transform: uppercase;
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-800 antialiased py-6">

    <!-- Action Bar Floating (No Print) -->
    <div class="no-print fixed top-4 right-4 z-50 flex items-center gap-2 bg-slate-950/90 backdrop-blur-md border border-white/20 p-2.5 rounded-2xl shadow-2xl">
        <a href="index.php" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-white transition flex items-center gap-1.5">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Kembali</span>
        </a>
        <button type="button" onclick="window.print()" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white shadow-lg shadow-blue-500/30 transition flex items-center gap-1.5 cursor-pointer">
            <i class="fa-solid fa-print"></i>
            <span>Cetak / Simpan PDF (Ctrl+P)</span>
        </button>
    </div>

    <div class="cert-container text-slate-900">
        <div class="cert-watermark"><?= htmlspecialchars($school['school_name'] ?? 'BINA BANGSA') ?></div>

        <!-- KOP SURAT RESMI -->
        <div class="flex items-center justify-between pb-4 border-b-2 border-slate-900 gap-4">
            <?php 
                $clean_cert_logo = !empty($school['school_logo']) ? resolveAssetUrl($school['school_logo'], '../../') : '';
                $cert_logo_exists = !empty($clean_cert_logo) && (strpos($clean_cert_logo, 'http') === 0 || file_exists(dirname(__DIR__, 2) . '/' . resolveAssetUrl($school['school_logo'])));
            ?>
            <div class="w-16 h-16 flex items-center justify-center shrink-0">
                <?php if ($cert_logo_exists): ?>
                    <img src="<?= htmlspecialchars($clean_cert_logo) ?>" alt="Logo" class="max-h-16 max-w-16 object-contain">
                <?php else: ?>
                    <div class="w-16 h-16 flex items-center justify-center rounded-2xl bg-slate-100 border border-slate-300 text-slate-800 text-2xl font-black">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="text-center flex-1">
                <p class="text-xs uppercase font-bold tracking-widest text-slate-600">Pemerintah Provinsi Dinas Pendidikan</p>
                <h1 class="text-xl font-black uppercase tracking-wide text-slate-950 mt-0.5">
                    <?= htmlspecialchars($school['school_name'] ?? 'SMA BINA BANGSA NUSANTARA') ?>
                </h1>
                <p class="text-[11px] text-slate-600 mt-0.5">
                    <?= htmlspecialchars($school['school_address'] ?? 'Jl. Pendidikan Nasional No. 45, Kebayoran Baru, Jakarta') ?>
                </p>
                <p class="text-[10px] text-slate-500">
                    Telp: <?= htmlspecialchars($school['school_phone'] ?? '(021) 789-0123') ?> • Website: <?= htmlspecialchars($school['school_website'] ?? 'https://binabangsa.sch.id') ?>
                </p>
            </div>
            <div class="w-16 shrink-0 text-center">
                <!-- Barcode / QR Preview -->
                <div class="w-14 h-14 bg-slate-100 border border-slate-300 p-1 flex items-center justify-center rounded-lg mx-auto">
                    <i class="fa-solid fa-qrcode text-2xl text-slate-700"></i>
                </div>
                <span class="text-[8px] font-mono text-slate-500 block mt-0.5">VERIFIED</span>
            </div>
        </div>

        <!-- JUDUL SURAT RESMI -->
        <div class="text-center my-6">
            <h2 class="text-base font-black uppercase tracking-wider text-slate-950 underline underline-offset-4 decoration-2">
                <?= $cert_type === 'member' ? 'SURAT KETERANGAN KEAKTIFAN EKSTRAKURIKULER' : 'PIAGAM PENGHARGAAN PRESTASI SISWA' ?>
            </h2>
            <p class="text-xs font-mono text-slate-600 mt-1">Nomor: <?= $ref_no ?></p>
        </div>

        <!-- ISI SURAT KETERANGAN -->
        <div class="space-y-4 text-xs leading-relaxed text-slate-800">
            <p>
                Kepala Sekolah Menengah Atas <?= htmlspecialchars($school['school_name'] ?? 'Bina Bangsa') ?>, dengan ini menerangkan secara resmi bahwa peserta didik di bawah ini:
            </p>

            <div class="my-4 mx-6 p-4 rounded-xl border border-slate-300 bg-slate-50/50 space-y-2">
                <div class="grid grid-cols-3 gap-2">
                    <span class="font-bold text-slate-600">Nama Lengkap</span>
                    <span class="col-span-2 font-black text-slate-950 uppercase">: <?= htmlspecialchars($data['student_name']) ?></span>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <span class="font-bold text-slate-600">NISN / Nomor Induk</span>
                    <span class="col-span-2 font-mono font-bold text-slate-900">: <?= htmlspecialchars($data['nisn'] ?: '-') ?></span>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <span class="font-bold text-slate-600">Rombongan Belajar (Kelas)</span>
                    <span class="col-span-2 font-semibold text-slate-900">: <?= htmlspecialchars($data['class_name'] ?? 'Siswa Aktif') ?></span>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <span class="font-bold text-slate-600">Tahun Ajaran</span>
                    <span class="col-span-2 font-semibold text-slate-900">: <?= htmlspecialchars($academic_year) ?></span>
                </div>
            </div>

            <?php if ($cert_type === 'member'): ?>
                <p>
                    Benar-benar terdaftar aktif dan telah menyelesaikan program pengembangan bakat, kepemimpinan karakter, dan keterampilan pada cabang ekstrakurikuler resmi:
                </p>

                <div class="my-3 mx-6 p-4 rounded-xl border-2 border-slate-800 bg-white space-y-2">
                    <div class="grid grid-cols-3 gap-2">
                        <span class="font-bold text-slate-700">Nama Ekstrakurikuler</span>
                        <span class="col-span-2 font-black text-slate-950 text-sm">: <?= htmlspecialchars($data['ekskul_name']) ?></span>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <span class="font-bold text-slate-700">Jabatan Organisasi</span>
                        <span class="col-span-2 font-bold uppercase text-slate-900">: <?= htmlspecialchars($data['role']) ?></span>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <span class="font-bold text-slate-700">Predikat Nilai Rapor</span>
                        <span class="col-span-2 font-black text-blue-900 text-sm">: <?= htmlspecialchars($data['grade_predicate']) ?> (Sangat Baik)</span>
                    </div>
                    <div class="grid grid-cols-3 gap-2">
                        <span class="font-bold text-slate-700">Evaluasi & Catatan Pembina</span>
                        <span class="col-span-2 italic text-slate-800">: "<?= htmlspecialchars($data['grade_notes'] ?: 'Menunjukkan kedisiplinan, komitmen tinggi, dan dedikasi luar biasa.') ?>"</span>
                    </div>
                </div>
            <?php else: ?>
                <p>
                    Telah menorehkan prestasi gemilang dan mengharumkan nama almamater satuan pendidikan dengan perolehan capaian kejuaraan resmi:
                </p>

                <div class="my-4 mx-6 p-5 rounded-xl border-2 border-amber-500 bg-amber-50/30 text-center space-y-2">
                    <p class="text-xs uppercase font-extrabold tracking-widest text-amber-700">Tingkat <?= strtoupper($data['level']) ?></p>
                    <h3 class="text-lg font-black text-slate-950 uppercase"><?= htmlspecialchars($data['rank_achieved']) ?></h3>
                    <p class="text-sm font-bold text-slate-800"><?= htmlspecialchars($data['competition_name']) ?></p>
                    <p class="text-xs text-slate-600">Diselenggarakan pada: <?= date('d F Y', strtotime($data['achievement_date'])) ?></p>
                    <?php if (!empty($data['description'])): ?>
                        <p class="text-xs italic text-slate-700 pt-1">"<?= htmlspecialchars($data['description']) ?>"</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p class="mt-4">
                Demikian surat keterangan ini diberikan sebagai bukti otentik portofolio akademik dan non-akademik peserta didik untuk dapat dipergunakan sebagaimana mestinya.
            </p>
        </div>

        <!-- TANDA TANGAN RESMI KEPALA SEKOLAH & PEMBINA -->
        <div class="mt-14 pt-4 grid grid-cols-2 gap-8 text-xs text-center items-end">
            <div>
                <p class="text-slate-600 mb-1">Mengetahui,</p>
                <?= renderOfficialSignatureHtml($pdo, 'teacher', [
                    'custom_name' => $data['leader_name'] ?? 'Guru Pembina',
                    'custom_nip' => $data['leader_nip'] ?: '19850101 201001 1 005',
                    'custom_title' => 'Guru Pembina ' . ($data['ekskul_name'] ?? 'Ekstrakurikuler'),
                    'doc_type' => 'SERTIFIKAT_EKSKUL',
                    'doc_num' => $ref_no,
                    'student_name' => $data['student_name'],
                    'show_city_date' => false,
                    'align' => 'center'
                ]) ?>
            </div>

            <div>
                <?= renderOfficialSignatureHtml($pdo, 'headmaster', [
                    'doc_type' => 'SERTIFIKAT_EKSKUL',
                    'doc_num' => $ref_no,
                    'student_name' => $data['student_name'],
                    'show_city_date' => true,
                    'align' => 'center'
                ]) ?>
            </div>
        </div>

        <!-- FOOTER DOKUMEN & KEAMANAN QR VERIFIKASI -->
        <div class="absolute bottom-6 left-20 right-20 pt-3 border-t border-slate-300 flex items-center justify-between text-[9px] text-slate-500 font-mono">
            <span>Security Hash: <?= substr($verify_hash, 0, 24) ?>...</span>
            <span>Dokumen Sah SIMS Manajemen-PHP • Standar Akreditasi Kemendikbud</span>
        </div>
    </div>

</body>
</html>
