<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff = in_array($user_role, ['administrator', 'guru', 'staf'], true);

$school = getSchoolSettings($pdo);
$target_student_id = (int)($_GET['student_id'] ?? 0);

if ($user_role === 'siswa') {
    // Siswa mutlak hanya dapat melihat surat pembinaan disiplin miliknya sendiri (anti-IDOR)
    $target_student_id = $user_id;
} elseif ($user_role === 'orang_tua') {
    // Orang tua hanya dapat melihat surat ananda yang terhubung
    $stmt_p_kids = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ?");
    $stmt_p_kids->execute([$user_id]);
    $p_kids_ids = array_map('intval', $stmt_p_kids->fetchAll(PDO::FETCH_COLUMN));

    if ($target_student_id > 0 && in_array($target_student_id, $p_kids_ids, true)) {
        // ID anak sah terhubung
    } else {
        $target_student_id = !empty($p_kids_ids) ? (int)$p_kids_ids[0] : 0;
    }
} elseif (!$is_staff) {
    die("Akses ditolak: Anda tidak memiliki izin untuk melihat dokumen ini.");
}

// Ambil profil siswa
$stmt_stu = $pdo->prepare("
    SELECT u.*, c.name as class_name,
           p.name as parent_name, p.phone as parent_phone
    FROM users u
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN parent_students ps ON u.id = ps.student_id
    LEFT JOIN users p ON ps.parent_id = p.id
    WHERE u.id = ? AND u.role = 'siswa'
");
$stmt_stu->execute([$target_student_id]);
$student = $stmt_stu->fetch();

if (!$student) {
    die("Data siswa tidak ditemukan.");
}

// Ambil seluruh rekam jejak pelanggaran siswa
$stmt_rec = $pdo->prepare("
    SELECT cr.*, rec.name as recorder_name
    FROM counseling_records cr
    JOIN users rec ON cr.recorded_by = rec.id
    WHERE cr.student_id = ? AND cr.type = 'pelanggaran'
    ORDER BY cr.date ASC, cr.id ASC
");
$stmt_rec->execute([$target_student_id]);
$violations = $stmt_rec->fetchAll();

$total_points = 0;
foreach ($violations as $v) {
    $total_points += (int)$v['points'];
}

// Tentukan Tingkat Surat & Sanksi
$force_do = (isset($_GET['type']) && $_GET['type'] === 'do');
$is_expelled = $force_do || $total_points >= 100 || (($student['status'] ?? '') === 'dikeluarkan');
$is_suspended = !$is_expelled && ($total_points >= 75 || (($student['status'] ?? '') === 'skorsing'));

$sp_level = "Surat Pembinaan Disiplin";
$sp_code = "SP-0";
$urgency_color = "text-blue-600";
if ($is_expelled) {
    $sp_level = "SURAT KEPUTUSAN PENGEMBALIAN PESERTA DIDIK KEPADA ORANG TUA / WALI (DIKELUARKAN DARI SEKOLAH)";
    $sp_code = "SK-DO / Pengembalian";
    $urgency_color = "text-rose-700";
} elseif ($is_suspended) {
    $sp_level = "SURAT PERINGATAN III (SP 3) & SKORSING SEMENTARA";
    $sp_code = "SP-3";
    $urgency_color = "text-rose-600";
} elseif ($total_points >= 50) {
    $sp_level = "SURAT PERINGATAN II (SP 2) & PEMANGGILAN ORANG TUA";
    $sp_code = "SP-2 / Panggilan";
    $urgency_color = "text-amber-600";
} elseif ($total_points >= 25) {
    $sp_level = "SURAT PERINGATAN I (SP 1)";
    $sp_code = "SP-1";
    $urgency_color = "text-yellow-600";
}

$letter_number = $is_expelled 
    ? "421.3 / SK-DO." . (100 + $student['id']) . " / " . date('Y') 
    : "421.3 / " . (100 + $student['id']) . " / BK / " . date('Y');
$letter_date = date('d F Y');
$invite_date = date('l, d F Y', strtotime('+3 days'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_expelled ? 'Surat Keputusan Dikeluarkan' : 'Surat Panggilan Orang Tua / SP' ?> - <?= htmlspecialchars($student['name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm 20mm 15mm 20mm;
        }
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body { 
                background: white !important; 
                color: #000 !important; 
                padding: 0 !important;
                font-size: 11pt !important;
            }
            .no-print { display: none !important; }
            .print-card { 
                border: none !important; 
                box-shadow: none !important; 
                width: 100% !important; 
                max-width: 100% !important;
                padding: 0 !important;
            }
            table { border-collapse: collapse !important; width: 100% !important; }
            th, td { border: 1px solid #333 !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center p-4 sm:p-8 font-sans antialiased">

    <!-- Top Action Bar -->
    <div class="no-print w-full max-w-4xl mb-6 flex flex-wrap items-center justify-between gap-3">
        <a href="counseling.php?student_id=<?= $target_student_id ?>" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Modul BK
        </a>
        <div class="flex items-center gap-2">
            <?php if ($is_staff && !$is_expelled): ?>
                <a href="counseling_letter.php?student_id=<?= $target_student_id ?>&type=do" class="rounded-xl border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 px-3.5 py-2 text-xs font-bold text-rose-300 transition inline-flex items-center gap-2">
                    <i class="fa-solid fa-ban"></i> Mode SK Dikeluarkan (DO)
                </a>
            <?php endif; ?>
            <?php if ($is_staff && $is_expelled): ?>
                <a href="../admin/users.php?action=edit&id=<?= $target_student_id ?>" target="_blank" class="rounded-xl border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 px-3.5 py-2 text-xs font-bold text-amber-300 transition inline-flex items-center gap-2">
                    <i class="fa-solid fa-user-gear"></i> Set Status di Master Pengguna
                </a>
            <?php endif; ?>
            <button onclick="window.print()" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition inline-flex items-center gap-2 cursor-pointer">
                <i class="fa-solid fa-print"></i> Cetak Surat Resmi (A4)
            </button>
        </div>
    </div>

    <!-- LEMBAR SURAT RESMI BK -->
    <div class="print-card w-full max-w-4xl rounded-3xl border border-white/15 bg-white text-slate-900 p-8 sm:p-14 shadow-2xl">
        
        <!-- Kop Surat Resmi -->
        <div class="flex items-center gap-4 border-b-2 border-slate-900 pb-4 mb-6">
            <?php 
                $clean_bk_logo = !empty($school['school_logo']) ? resolveAssetUrl($school['school_logo'], '../../') : '';
                $bk_logo_exists = !empty($clean_bk_logo) && (strpos($clean_bk_logo, 'http') === 0 || file_exists(dirname(__DIR__, 2) . '/' . resolveAssetUrl($school['school_logo'])));
            ?>
            <?php if ($bk_logo_exists): ?>
                <img src="<?= htmlspecialchars($clean_bk_logo) ?>" alt="Logo" class="h-20 w-20 object-contain shrink-0">
            <?php else: ?>
                <div class="h-16 w-16 rounded-2xl bg-slate-900 text-white flex items-center justify-center text-3xl font-black shrink-0">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
            <?php endif; ?>
            <div class="flex-1 text-center pr-6">
                <h2 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-slate-900">
                    PEMERINTAH PROVINSI DAERAH KHUSUS IBUKOTA
                </h2>
                <h1 class="text-2xl sm:text-3xl font-black uppercase tracking-tight text-slate-950 mt-0.5">
                    <?= htmlspecialchars($school['school_name']) ?>
                </h1>
                <p class="text-xs text-slate-700 mt-1"><?= htmlspecialchars($school['school_address']) ?></p>
                <p class="text-xs text-slate-600 font-mono">Telepon: <?= htmlspecialchars($school['school_phone']) ?> | Pos-el: <?= htmlspecialchars($school['school_email']) ?></p>
            </div>
        </div>

        <!-- Nomor & Perihal Surat -->
        <div class="flex justify-between items-start text-xs sm:text-sm mb-6 leading-relaxed">
            <div class="space-y-1">
                <div class="flex"><span class="w-24 text-slate-600">Nomor</span><span class="font-mono">: <?= $letter_number ?></span></div>
                <div class="flex"><span class="w-24 text-slate-600">Lampiran</span><span>: 1 (satu) Berkas Lembar Rekam Disiplin</span></div>
                <div class="flex"><span class="w-24 text-slate-600 font-bold">Perihal</span><span class="font-bold underline text-slate-950">: <?= $sp_level ?></span></div>
            </div>
            <div class="text-right">
                <p>Jakarta, <?= $letter_date ?></p>
            </div>
        </div>

        <!-- Tujuan Surat -->
        <div class="text-xs sm:text-sm mb-6 leading-relaxed">
            <p>Kepada Yth.</p>
            <p class="font-bold">Bapak / Ibu Orang Tua / Wali dari Ananda <?= htmlspecialchars($student['name']) ?></p>
            <p>di Tempat</p>
        </div>

        <!-- Isi Surat -->
        <div class="text-xs sm:text-sm space-y-3.5 leading-relaxed text-slate-800 text-justify mb-6">
            <p>Dengan hormat,</p>
            <p>
                Sehubungan dengan evaluasi ketertiban, kedisiplinan, dan etika peserta didik di lingkungan <?= htmlspecialchars($school['school_name']) ?>, melalui surat ini kami sampaikan catatan disiplin putra/putri Bapak/Ibu:
            </p>

            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5 text-xs space-y-1 my-2">
                <div class="flex"><span class="w-40 text-slate-600">Nama Siswa</span><strong class="text-slate-900">: <?= htmlspecialchars($student['name']) ?></strong></div>
                <div class="flex"><span class="w-40 text-slate-600">NISN / Rombel</span><span>: <?= htmlspecialchars($student['nisn'] ?: '-') ?> / Kelas <?= htmlspecialchars($student['class_name'] ?: 'Reguler') ?></span></div>
                <div class="flex"><span class="w-40 text-slate-600 font-bold">Akumulasi Poin Pelanggaran</span><strong class="text-rose-700 font-bold">: <?= $total_points ?> Poin (Batas Maksimal Sanksi Dikeluarkan: 100 Poin)</strong></div>
                <?php if (!empty($student['status']) && $student['status'] !== 'aktif'): ?>
                    <div class="flex"><span class="w-40 text-slate-600">Status Kesiswaan</span><strong class="text-slate-900 uppercase font-mono">: <?= htmlspecialchars($student['status']) ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if ($is_expelled): ?>
                <div class="border-l-4 border-rose-600 pl-4 py-1 space-y-2 bg-rose-50/50 rounded-r-xl">
                    <p class="font-semibold text-rose-950">
                        Berdasarkan:
                    </p>
                    <ol class="list-decimal list-inside space-y-1 text-xs text-rose-900">
                        <li>Buku Pedoman Tata Tertib Peserta Didik <?= htmlspecialchars($school['school_name']) ?> Bab Pelanggaran Berat.</li>
                        <li>Akumulasi poin pelanggaran yang telah mencapai / melampaui batas toleransi maksimal 100 poin (Total: <?= $total_points ?> poin) atau tindakan indispliner berat yang mencemarkan integritas dan nama baik sekolah.</li>
                        <li>Keputusan Musyawarah Rapat Dewan Kehormatan Guru, Tim Bimbingan Konseling (BK), Wali Kelas, dan Kepala Sekolah.</li>
                    </ol>
                    <p class="pt-2 font-bold text-slate-950 text-xs sm:text-sm">
                        Maka pimpinan sekolah secara resmi memutuskan untuk <u>MENGEMBALIKAN PESERTA DIDIK TERSEBUT KEPADA ORANG TUA / WALI (DIKELUARKAN DARI SEKOLAH)</u> terhitung sejak tanggal surat keputusan ini ditetapkan.
                    </p>
                </div>

                <p>
                    Pihak sekolah melalui Bagian Tata Usaha dan Bimbingan Konseling siap membantu memfasilitasi administrasi penerbitan <strong>Surat Keterangan Pindah / Mutasi Keluar</strong> serta <strong>Transkrip Nilai Akademik</strong> yang telah ditempuh selama ini, agar ananda dapat melanjutkan proses pendidikan di satuan pendidikan baru. Seluruh inventaris, buku perpustakaan, atau sarana milik sekolah wajib diselesaikan dan diserahterimakan kembali.
                </p>
            <?php else: ?>
                <p>
                    Telah melampaui batas toleransi poin tata tertib sekolah, sehingga pihak Bimbingan Konseling (BK) dan Tim Ketertiban Sekolah menerbitkan <strong><?= $sp_level ?></strong> sebagai upaya pembinaan komprehensif bagi masa depan ananda.
                </p>

                <?php if ($total_points >= 50 || $is_suspended): ?>
                    <p>
                        Oleh karena itu, kami mengharap kehadiran Bapak/Ibu Orang Tua/Wali ke sekolah untuk berdiskusi dan bermusyawarah mencari solusi pembinaan terbaik, pada:
                    </p>

                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5 text-xs space-y-1 ml-4 my-2">
                        <div class="flex"><span class="w-32 text-slate-600">Hari / Tanggal</span><strong>: <?= $invite_date ?></strong></div>
                        <div class="flex"><span class="w-32 text-slate-600">Waktu</span><strong>: Pukul 09.00 WIB s.d Selesai</strong></div>
                        <div class="flex"><span class="w-32 text-slate-600">Tempat</span><span>: Ruang Bimbingan Konseling (BK)</span></div>
                        <div class="flex"><span class="w-32 text-slate-600">Menemui</span><span>: Koordinator Guru BK & Wali Kelas</span></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <p>
                Demikian surat pemberitahuan ini kami sampaikan. Atas perhatian, kerja sama, dan komitmen Bapak/Ibu demi kemajuan pembentukan karakter putra/putri kita, kami ucapkan terima kasih.
            </p>
        </div>

        <!-- Rincian Catatan Kasus Pelanggaran -->
        <div class="mb-8">
            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-700 mb-2">Lampiran: Riwayat Pelanggaran Tata Tertib</h4>
            <table class="w-full text-left text-xs border border-slate-300">
                <thead class="bg-slate-100 text-slate-800 font-bold">
                    <tr>
                        <th class="p-2 text-center w-8 border border-slate-300">No</th>
                        <th class="p-2 border border-slate-300">Tanggal</th>
                        <th class="p-2 border border-slate-300">Bentuk Pelanggaran</th>
                        <th class="p-2 border border-slate-300">Kategori</th>
                        <th class="p-2 text-center border border-slate-300">Poin</th>
                        <th class="p-2 border border-slate-300">Tindak Lanjut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($violations)): ?>
                        <tr><td colspan="6" class="p-3 text-center text-slate-500">Tidak ada rincian pelanggaran.</td></tr>
                    <?php else: ?>
                        <?php foreach ($violations as $idx => $v): ?>
                            <tr>
                                <td class="p-2 text-center font-mono border border-slate-300"><?= $idx + 1 ?></td>
                                <td class="p-2 font-mono whitespace-nowrap border border-slate-300"><?= date('d/m/Y', strtotime($v['date'])) ?></td>
                                <td class="p-2 font-semibold border border-slate-300"><?= htmlspecialchars($v['title']) ?></td>
                                <td class="p-2 text-slate-600 border border-slate-300"><?= htmlspecialchars($v['category']) ?></td>
                                <td class="p-2 text-center font-bold text-rose-600 border border-slate-300"><?= $v['points'] ?></td>
                                <td class="p-2 text-slate-700 border border-slate-300"><?= htmlspecialchars($v['action_taken'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="bg-slate-50 font-bold">
                            <td colspan="4" class="p-2 text-right border border-slate-300">TOTAL AKUMULASI POIN PELANGGARAN:</td>
                            <td class="p-2 text-center text-rose-700 border border-slate-300"><?= $total_points ?> Poin</td>
                            <td class="p-2 border border-slate-300"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Kolom Tanda Tangan Tripartit -->
        <div class="grid grid-cols-3 gap-4 text-center text-xs pt-4 border-t border-slate-200">
            <div>
                <p class="text-slate-600">Mengetahui,</p>
                <p class="font-bold text-slate-900">Orang Tua / Wali Siswa,</p>
                <div class="h-20 flex items-center justify-center">
                    <span class="text-slate-400 italic text-[11px]">(Tanda Tangan Basah)</span>
                </div>
                <p class="font-bold underline text-slate-900">..........................................</p>
                <p class="text-[10px] text-slate-500">Tanda Tangan & Nama Terang</p>
            </div>

            <div>
                <p class="text-slate-600">Guru Bimbingan Konseling,</p>
                <?= renderOfficialSignatureHtml($pdo, 'bk_coordinator', [
                    'doc_type' => $is_expelled ? 'SK_DISIPLIN_DO' : 'SP_BK',
                    'doc_num' => $letter_number,
                    'student_name' => $student['name'],
                    'show_city_date' => false,
                    'align' => 'center'
                ]) ?>
            </div>

            <div>
                <p class="text-slate-600">Mengetahui,</p>
                <?= renderOfficialSignatureHtml($pdo, 'headmaster', [
                    'doc_type' => $is_expelled ? 'SK_DISIPLIN_DO' : 'SP_BK',
                    'doc_num' => $letter_number,
                    'student_name' => $student['name'],
                    'show_city_date' => false,
                    'align' => 'center'
                ]) ?>
            </div>
        </div>

    </div>

</body>
</html>
