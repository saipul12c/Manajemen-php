<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['administrator', 'staf'], true);

$announcement_id = (int) ($_GET['id'] ?? 0);
if ($announcement_id < 1) {
    header("Location: announcements.php");
    exit;
}

// Ambil data pengumuman
$stmt = $pdo->prepare("
    SELECT a.*, u.name as author_name, u.role as author_role, c.name as class_name
    FROM announcements a
    JOIN users u ON a.author_id = u.id
    LEFT JOIN classes c ON a.class_id = c.id
    WHERE a.id = ?
");
$stmt->execute([$announcement_id]);
$a = $stmt->fetch();

if (!$a) {
    die("Pengumuman tidak ditemukan.");
}

// Cek hak akses jika bukan admin/staf
if (!$can_manage) {
    if ($a['status'] !== 'published') {
        die("Akses ditolak. Pengumuman masih dalam status draft.");
    }
    if ($a['expires_at'] && strtotime($a['expires_at']) <= time()) {
        die("Pengumuman telah kedaluwarsa.");
    }
    if ($a['target_role'] !== 'semua' && $a['target_role'] !== $user_role) {
        die("Akses ditolak. Pengumuman tidak ditujukan untuk peran Anda.");
    }
}

// Otomatis tandai sudah dibaca jika belum
if (!isAnnouncementRead($pdo, $a['id'], $user_id)) {
    try {
        $stmt_r = $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)");
        $stmt_r->execute([$a['id'], $user_id]);
    } catch (Exception $e) {}
}

$school = getSchoolSettings($pdo);
$bulan_romawi = [
    1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
    7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'
];
$created_time = strtotime($a['created_at']);
$nomor_bulan = (int) date('n', $created_time);
$romawi = $bulan_romawi[$nomor_bulan] ?? 'IX';
$tahun = date('Y', $created_time);
$nomor_surat = sprintf("421/%03d/SMABBN/EDR/%s/%s", $a['id'], $romawi, $tahun);

// Format target pembaca
$target_text = "Seluruh Civitas Akademika";
if ($a['target_role'] === 'guru') $target_text = "Bapak/Ibu Dewan Guru";
elseif ($a['target_role'] === 'siswa') $target_text = "Seluruh Siswa/Siswi";
elseif ($a['target_role'] === 'orang_tua') $target_text = "Bapak/Ibu Orang Tua / Wali Murid";
elseif ($a['target_role'] === 'staf') $target_text = "Bapak/Ibu Tenaga Kependidikan & Staf";

if (!empty($a['class_name'])) {
    $target_text .= " (Khusus " . htmlspecialchars($a['class_name']) . ")";
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Edaran - <?= htmlspecialchars($a['title']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .page-sheet {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
            }
            @page {
                size: A4 portrait;
                margin: 20mm 20mm 20mm 20mm;
            }
        }
        /* Double line for kop surat */
        .kop-line {
            border-top: 3px solid #000;
            border-bottom: 1px solid #000;
            height: 4px;
            margin-top: 8px;
            margin-bottom: 20px;
        }
        .content-body p {
            margin-bottom: 0.75rem;
            line-height: 1.6;
        }
        .content-body ul {
            list-style-type: disc;
            margin-left: 1.5rem;
            margin-bottom: 0.75rem;
        }
        .content-body ol {
            list-style-type: decimal;
            margin-left: 1.5rem;
            margin-bottom: 0.75rem;
        }
    </style>
</head>
<body class="bg-slate-900 text-slate-900 font-serif antialiased min-h-screen py-6 px-3 sm:px-6">

    <!-- Top Action Bar (Non-print) -->
    <div class="no-print max-w-4xl mx-auto mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-950/80 backdrop-blur border border-white/10 p-4 rounded-2xl text-white">
        <div class="flex items-center gap-3">
            <a href="announcements.php" class="inline-flex items-center gap-2 rounded-xl bg-white/10 hover:bg-white/15 px-3 py-2 text-xs font-semibold text-slate-200 transition">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Pengumuman
            </a>
            <div>
                <p class="text-xs font-bold text-slate-200">Pratinjau Surat Edaran Resmi</p>
                <p class="text-[11px] text-slate-400">Siap dicetak ke printer atau disimpan sebagai PDF (Kertas A4)</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/30 transition cursor-pointer">
                <i class="fa-solid fa-print"></i> Cetak / Unduh PDF
            </button>
        </div>
    </div>

    <!-- Official Document Sheet -->
    <div class="page-sheet max-w-4xl mx-auto bg-white p-8 sm:p-14 shadow-2xl rounded-2xl border border-slate-200 text-slate-900">
        
        <!-- KOP SURAT RESMI DINAS -->
        <div class="flex items-center justify-between gap-4 pb-2 text-center">
            <div class="w-20 h-20 flex-shrink-0 flex items-center justify-center text-3xl">
                <?php if (!empty($school['school_logo']) && (str_contains($school['school_logo'], '.') || str_contains($school['school_logo'], '/'))): ?>
                    <img src="<?= htmlspecialchars($school['school_logo']) ?>" class="max-h-16 max-w-16 object-contain" alt="Logo">
                <?php else: ?>
                    <i class="fa-solid fa-graduation-cap text-slate-800"></i>
                <?php endif; ?>
            </div>
            <div class="flex-1">
                <h3 class="text-xs sm:text-sm font-bold uppercase tracking-widest text-slate-700">PEMERINTAH DAERAH PROVINSI DKI JAKARTA</h3>
                <h3 class="text-xs sm:text-sm font-bold uppercase tracking-widest text-slate-700">DINAS PENDIDIKAN DAN KEBUDAYAAN</h3>
                <h1 class="text-lg sm:text-2xl font-black uppercase tracking-wide text-slate-950 mt-0.5">
                    <?= htmlspecialchars($school['school_name']) ?>
                </h1>
                <p class="text-[11px] sm:text-xs text-slate-600 mt-1 leading-tight">
                    <?= htmlspecialchars($school['school_address']) ?><br>
                    Telp: <?= htmlspecialchars($school['school_phone']) ?> | Email: <?= htmlspecialchars($school['school_email']) ?> | Website: <?= htmlspecialchars($school['school_website']) ?>
                </p>
            </div>
            <div class="w-20 h-20 flex-shrink-0 hidden sm:flex items-center justify-center text-xs font-bold border border-slate-300 rounded-xl text-slate-400">
                KODE POS<br>12110
            </div>
        </div>

        <!-- Garis Ganda Kop Surat -->
        <div class="kop-line"></div>

        <!-- JUDUL & NOMOR SURAT -->
        <div class="text-center my-6">
            <h2 class="text-base sm:text-lg font-bold underline uppercase tracking-wider text-slate-950">
                SURAT EDARAN
            </h2>
            <p class="text-xs font-semibold text-slate-700 mt-1">
                Nomor: <?= $nomor_surat ?>
            </p>
            <p class="text-xs font-medium text-slate-600 mt-1">
                Tentang: <strong><?= htmlspecialchars($a['title']) ?></strong>
            </p>
        </div>

        <!-- METADATA SURAT -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs text-slate-800 mb-6">
            <table class="w-full">
                <tr>
                    <td class="w-24 py-1 font-semibold text-slate-600">Sifat</td>
                    <td class="w-3">:</td>
                    <td class="font-bold text-slate-900 uppercase"><?= htmlspecialchars($a['category']) ?></td>
                </tr>
                <tr>
                    <td class="py-1 font-semibold text-slate-600">Lampiran</td>
                    <td>:</td>
                    <td><?= !empty($a['attachment_url']) ? '1 (Satu) Berkas Terlampir' : '-' ?></td>
                </tr>
                <tr>
                    <td class="py-1 font-semibold text-slate-600">Hal</td>
                    <td>:</td>
                    <td class="font-bold text-slate-900"><?= htmlspecialchars($a['title']) ?></td>
                </tr>
            </table>

            <div class="sm:text-right">
                <p class="font-semibold text-slate-600">Kepada Yth.:</p>
                <p class="font-bold text-slate-950 text-sm mt-0.5"><?= $target_text ?></p>
                <p class="text-slate-600 text-xs">di Tempat</p>
            </div>
        </div>

        <!-- PEMBUKA SURAT -->
        <div class="text-xs sm:text-sm text-slate-800 leading-relaxed mb-4">
            <p>Dengan hormat,</p>
            <p class="mt-2 text-justify indent-8">
                Sehubungan dengan penyelenggaraan kegiatan akademik dan operasional di lingkungan <?= htmlspecialchars($school['school_name']) ?> Tahun Ajaran <?= htmlspecialchars($school['academic_year']) ?>, dengan ini kami sampaikan pemberitahuan sebagai berikut:
            </p>
        </div>

        <!-- ISI SURAT EDARAN (Rich Text yang Disanitasi) -->
        <div class="content-body text-xs sm:text-sm text-slate-900 text-justify mb-8">
            <?= sanitizeAnnouncementHtml($a['content']) ?>
        </div>

        <!-- PENUTUP SURAT -->
        <div class="text-xs sm:text-sm text-slate-800 leading-relaxed mb-10 text-justify">
            <p class="indent-8">
                Demikian surat edaran ini disampaikan untuk menjadi perhatian dan dapat dilaksanakan sebagaimana mestinya. Atas perhatian, dukungan, dan kerja sama Bapak/Ibu serta seluruh civitas akademika, kami ucapkan terima kasih.
            </p>
        </div>

        <!-- TITIMANGSA & TANDA TANGAN -->
        <div class="flex justify-end pt-4 break-inside-avoid">
            <div class="w-72 text-center text-xs sm:text-sm text-slate-900">
                <p>Jakarta, <?= date('d F Y', $created_time) ?></p>
                <p class="font-semibold mt-1">Kepala Sekolah,</p>

                <!-- Stempel & Tanda Tangan Space / QR Verifikasi -->
                <div class="my-3 flex items-center justify-center">
                    <div class="p-2 border border-slate-300 rounded-lg bg-slate-50 text-[10px] text-slate-500 flex flex-col items-center">
                        <span class="text-xl mb-1 text-slate-600"><i class="fa-solid fa-shield-halved"></i></span>
                        <span class="font-mono font-bold text-slate-700">DOKUMEN TERVERIFIKASI</span>
                        <span><?= $nomor_surat ?></span>
                    </div>
                </div>

                <p class="font-bold underline text-sm sm:text-base text-slate-950">
                    <?= htmlspecialchars($school['headmaster_name']) ?>
                </p>
                <p class="text-xs text-slate-700 mt-0.5">
                    NIP. <?= htmlspecialchars($school['headmaster_nip']) ?>
                </p>
            </div>
        </div>

        <!-- FOOTER CATATAN RESMI -->
        <div class="mt-12 pt-4 border-t border-slate-200 text-[10px] text-slate-500 flex flex-col sm:flex-row justify-between items-center gap-2">
            <span>Surat resmi ini dicetak secara otomatis melalui Sistem Informasi Manajemen Sekolah.</span>
            <span>ID Dokumen: #ANN-<?= $a['id'] ?>-<?= strtoupper(substr(md5($a['id'] . $a['created_at']), 0, 6)) ?></span>
        </div>

    </div>

</body>
</html>
