<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff = in_array($user_role, ['staf', 'administrator', 'guru'], true);
$school = getSchoolSettings($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die("Parameter ID permohonan legalisir tidak valid.");
}

$stmt = $pdo->prepare("
    SELECT r.*, u.name as submitter_name, p.name as officer_name, p.nip as officer_nip
    FROM legalisir_requests r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN users p ON r.processed_by = p.id
    WHERE r.id = ?
");
$stmt->execute([$id]);
$leg = $stmt->fetch();

if (!$leg) {
    die("Data legalisir digital tidak ditemukan.");
}

// Cek hak akses: hanya pemilik atau staf/admin yang boleh membuka
if (!$is_staff && (int)$leg['user_id'] !== $user_id) {
    die("Anda tidak memiliki otorisasi untuk mengakses dokumen legalisir ini.");
}

// Cek apakah sudah disahkan
$is_approved = ($leg['status'] === 'disetujui');

// Nama jenis dokumen
$doc_names = [
    'ijazah' => 'Ijazah Asli',
    'skl' => 'Surat Keterangan Lulus (SKL)',
    'transkrip' => 'Transkrip Nilai Akademik',
    'rapor' => 'Buku Rapor Lengkap'
];
$doc_label = $doc_names[$leg['document_type']] ?? strtoupper($leg['document_type']);

// Path berkas dokumen asli
$file_path = $leg['file_original'];
$file_ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'webp'], true);
$is_pdf = ($file_ext === 'pdf');

// Metadata TTE Pengesahan
$verified_time = !empty($leg['verified_at']) ? strtotime($leg['verified_at']) : time();
$date_legalized = date('d F Y', $verified_time);

$ks_signers = getSchoolSigners($pdo)['headmaster'] ?? [];
$city = $school['doc_city'] ?? 'Jakarta';

// Base Web URL untuk validasi QR
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$base_dir = rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/\\');
$root_path = preg_replace('/\/dashboard.*$/', '', $base_dir);

$doc_num = $leg['legalized_code'];
$doc_hash = strtoupper(substr(md5($doc_num . ($ks_signers['nip'] ?? '') . 'SEKOLAH_LEGALISIR_TTE'), 0, 16));
$verify_url = $base_url . $root_path . "/verify_document.php?doc_type=LEGALISIR"
    . "&doc_num=" . urlencode($doc_num)
    . "&signer_role=" . urlencode($ks_signers['title'] ?? 'Kepala Sekolah')
    . "&signer_name=" . urlencode($school['headmaster_name'])
    . "&signer_nip=" . urlencode($school['headmaster_nip'])
    . "&student_name=" . urlencode($leg['alumni_name'])
    . "&date=" . urlencode($date_legalized)
    . "&hash=" . $doc_hash;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Legalisir Digital - <?= htmlspecialchars($leg['legalized_code']) ?> - <?= htmlspecialchars($leg['alumni_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white !important;
                color: black !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .legalisir-sheet {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
            }
            .watermark-overlay {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
        /* Watermark diagonal repeated */
        .watermark-overlay {
            background-image: repeating-linear-gradient(
                -45deg,
                rgba(16, 185, 129, 0.05) 0px,
                rgba(16, 185, 129, 0.05) 40px,
                transparent 40px,
                transparent 90px
            );
        }
    </style>
</head>
<body class="min-h-screen bg-slate-900 text-slate-100 font-sans p-4 sm:p-8 flex flex-col items-center">

    <!-- Action Bar (No-Print) -->
    <div class="no-print max-w-4xl w-full mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 rounded-2xl bg-slate-950 border border-white/10 shadow-2xl backdrop-blur">
        <div class="flex items-center gap-3">
            <a href="legalisir.php" class="h-9 w-9 rounded-xl bg-white/5 hover:bg-white/10 flex items-center justify-center text-slate-300 hover:text-white transition">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div>
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    Lembar Legalisir Digital Sah
                    <span class="rounded-full px-2 py-0.5 text-[10px] font-mono <?= $is_approved ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-amber-500/20 text-amber-300 border border-amber-500/30' ?>">
                        <?= htmlspecialchars($leg['legalized_code']) ?>
                    </span>
                </h3>
                <p class="text-xs text-slate-400">Status: <strong class="<?= $is_approved ? 'text-emerald-400' : 'text-amber-400' ?>"><?= strtoupper($leg['status']) ?></strong></p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="<?= htmlspecialchars($verify_url) ?>" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs font-semibold text-slate-200 hover:bg-slate-700 transition">
                <i class="fa-solid fa-shield-halved text-cyan-400"></i> Cek Verifikasi
            </a>

            <?php if (!empty($leg['contact_whatsapp'])): ?>
                <?php
                $wa_share_text = urlencode("Halo Kak " . $leg['alumni_name'] . ", berikut adalah tautan berkas resmi Legalisir Digital Dokumen " . strtoupper($leg['document_type']) . " (" . $leg['legalized_code']) . ") dari " . $school['school_name'] . ":\n\n" . urlencode($verify_url);
                ?>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $leg['contact_whatsapp']) ?>?text=<?= $wa_share_text ?>" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white hover:bg-emerald-500 transition shadow-lg shadow-emerald-600/20">
                    <i class="fa-brands fa-whatsapp text-sm"></i> Kirim ke WA
                </a>
            <?php endif; ?>

            <?php if ($is_approved): ?>
                <button onclick="window.print()" class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 px-4 py-2 text-xs font-bold text-white hover:bg-blue-500 transition shadow-lg shadow-blue-600/30 cursor-pointer">
                    <i class="fa-solid fa-print"></i> Cetak / Simpan PDF
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$is_approved): ?>
        <!-- Peringatan jika belum disetujui -->
        <div class="max-w-4xl w-full p-6 rounded-3xl border border-amber-500/30 bg-amber-950/30 text-amber-200 text-center mb-6">
            <i class="fa-solid fa-clock-rotate-left text-3xl text-amber-400 mb-2"></i>
            <h3 class="text-base font-bold text-white">Dokumen Masih Dalam Proses Verifikasi</h3>
            <p class="text-xs text-amber-300/80 mt-1 max-w-md mx-auto">
                Permohonan legalisir ini berstatus <strong><?= strtoupper($leg['status']) ?></strong> dan belum dibubuhi Tanda Tangan Elektronik resmi Kepala Sekolah. Silakan tunggu review dari staf Tata Usaha.
            </p>
        </div>
    <?php endif; ?>

    <!-- ========================================================= -->
    <!-- LEMBAR RESMI LEGALISIR ELEKTRONIK (A4 PRINTABLE SHEET)   -->
    <!-- ========================================================= -->
    <div class="legalisir-sheet relative max-w-4xl w-full bg-white text-slate-900 rounded-3xl p-8 sm:p-10 shadow-2xl border border-slate-200 overflow-hidden font-sans">
        
        <!-- Watermark Diagonal Cap Legalisir -->
        <div class="watermark-overlay absolute inset-0 pointer-events-none z-10"></div>
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none z-10 opacity-[0.06] select-none">
            <p class="text-5xl sm:text-6xl font-black uppercase text-emerald-800 rotate-[-30deg] tracking-widest text-center leading-relaxed">
                LEGALISIR SAH SECARA ELEKTRONIK<br>
                <?= htmlspecialchars($school['school_name']) ?>
            </p>
        </div>

        <!-- 1. KOP SURAT RESMI SEKOLAH -->
        <div class="relative z-20 border-b-2 border-slate-900 pb-4 mb-6">
            <div class="flex items-center gap-4">
                <?php if (!empty($school['school_logo'])): ?>
                    <img src="../../<?= htmlspecialchars($school['school_logo']) ?>" alt="Logo" class="h-20 w-20 object-contain shrink-0">
                <?php else: ?>
                    <div class="h-20 w-20 rounded-2xl bg-blue-700 text-white flex items-center justify-center text-3xl font-black shrink-0 shadow-md">
                        <i class="fa-solid fa-school"></i>
                    </div>
                <?php endif; ?>
                <div class="flex-1 text-center pr-6">
                    <p class="text-xs font-bold tracking-widest uppercase text-slate-600">PEMERINTAH DAERAH PROVINSI / KEMENTERIAN PENDIDIKAN</p>
                    <h1 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-slate-900">
                        <?= htmlspecialchars($school['school_name']) ?>
                    </h1>
                    <p class="text-xs text-slate-700 leading-tight mt-0.5">
                        <?= htmlspecialchars($school['school_address']) ?>
                    </p>
                    <p class="text-[11px] text-slate-600 font-mono mt-0.5">
                        Telepon: <?= htmlspecialchars($school['school_phone']) ?> | Pos-el: <?= htmlspecialchars($school['school_email']) ?> | Laman: <?= htmlspecialchars($school['school_website']) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- 2. JUDUL LEMBAR PENGESAHAN -->
        <div class="relative z-20 text-center mb-6">
            <span class="inline-block rounded-full bg-emerald-100 border border-emerald-300 px-3 py-0.5 text-[10px] font-extrabold uppercase tracking-widest text-emerald-800 mb-1">
                PENGESAHAN DOKUMEN ELEKTRONIK RESMI
            </span>
            <h2 class="text-lg font-black uppercase tracking-wide text-slate-900 underline decoration-slate-400 underline-offset-4">
                SURAT PENGESAHAN / LEGALISIR DIGITAL
            </h2>
            <p class="font-mono text-xs text-slate-600 mt-1">
                Nomor Registrasi: <strong><?= htmlspecialchars($leg['legalized_code']) ?></strong>
            </p>
        </div>

        <!-- 3. PERNYATAAN KEABSAHAN HUKUM -->
        <div class="relative z-20 bg-slate-50 border border-slate-200 rounded-2xl p-4 mb-6 text-xs text-slate-800 leading-relaxed space-y-2">
            <p>
                Yang bertanda tangan di bawah ini, Kepala Sekolah <strong><?= htmlspecialchars($school['school_name']) ?></strong>, menerangkan dengan sesungguhnya bahwa salinan dokumen di bawah ini:
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5 bg-white p-3 rounded-xl border border-slate-200 font-sans">
                <div>
                    <span class="text-slate-500">Nama Pemilik Dokumen:</span>
                    <strong class="text-slate-900 block"><?= htmlspecialchars($leg['alumni_name']) ?></strong>
                </div>
                <div>
                    <span class="text-slate-500">NISN / Nomor Induk:</span>
                    <strong class="text-slate-900 font-mono block"><?= htmlspecialchars($leg['nisn'] ?: '-') ?></strong>
                </div>
                <div>
                    <span class="text-slate-500">Jenis Dokumen:</span>
                    <strong class="text-slate-900 block"><?= htmlspecialchars($doc_label) ?></strong>
                </div>
                <div>
                    <span class="text-slate-500">Nomor Seri Dokumen Asli:</span>
                    <strong class="text-blue-700 font-mono block"><?= htmlspecialchars($leg['document_number']) ?></strong>
                </div>
                <div class="sm:col-span-2">
                    <span class="text-slate-500">Keperluan Pengesahan:</span>
                    <strong class="text-slate-900 block"><?= htmlspecialchars($leg['purpose']) ?></strong>
                </div>
            </div>
            <p class="text-[11px] text-slate-600 italic">
                Telah dicocokkan dengan dokumen aslinya dan pangkalan data buku induk sekolah, serta dinyatakan <strong>SESUAI DENGAN ASLINYA DAN SAH SECARA ELEKTRONIK</strong>.
            </p>
        </div>

        <!-- 4. TAMPILAN BERKAS ASLI (PREVIEW FRAME) -->
        <div class="relative z-20 mb-6 border-2 border-dashed border-slate-300 rounded-2xl p-4 bg-slate-50">
            <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-200 text-xs">
                <span class="font-bold text-slate-700 flex items-center gap-1.5">
                    <i class="fa-solid fa-file-contract text-emerald-600"></i> Lampiran Salinan Berkas Asli
                </span>
                <span class="font-mono text-[10px] text-slate-500">
                    File: <?= htmlspecialchars(basename($file_path)) ?>
                </span>
            </div>

            <div class="flex justify-center items-center overflow-hidden rounded-xl bg-white border border-slate-200 min-h-[350px] p-2">
                <?php if ($is_image): ?>
                    <img src="../../<?= htmlspecialchars($file_path) ?>" alt="Berkas Ijazah" class="max-h-[580px] max-w-full object-contain shadow-sm rounded-lg">
                <?php elseif ($is_pdf): ?>
                    <div class="w-full text-center py-10 space-y-3">
                        <i class="fa-solid fa-file-pdf text-5xl text-rose-500"></i>
                        <p class="text-xs font-semibold text-slate-800">Lampiran Dokumen Format PDF Terverifikasi</p>
                        <a href="../../<?= htmlspecialchars($file_path) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-bold hover:bg-blue-500 transition">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Berkas PDF Asli
                        </a>
                    </div>
                <?php else: ?>
                    <p class="text-xs text-slate-500">Format berkas tidak dapat dipratinjau langsung.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 5. KOTAK PENGESAHAN & TTE KEPALA SEKOLAH -->
        <div class="relative z-20 flex flex-col sm:flex-row items-end justify-between gap-6 pt-4 border-t-2 border-slate-800">
            
            <!-- Box Validasi Keaslian Dokumen (QR Validasi Publik) -->
            <div class="w-full sm:w-auto p-4 rounded-2xl bg-emerald-50 border border-emerald-200 flex items-center gap-3">
                <a href="<?= htmlspecialchars($verify_url) ?>" target="_blank" title="Scan / Klik untuk Verifikasi Keaslian TTE">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=84x84&margin=0&data=<?= urlencode($verify_url) ?>" 
                         alt="QR Legalisir" class="w-20 h-20 border border-emerald-400 p-1 rounded-xl bg-white shadow-sm shrink-0">
                </a>
                <div class="text-[11px] leading-snug">
                    <span class="inline-block px-1.5 py-0.5 rounded bg-emerald-600 text-white font-mono font-bold text-[9px] mb-1">
                        LEGALISIR RESMI TTE
                    </span>
                    <p class="font-bold text-slate-900">Pangkalan Arsip Sekolah</p>
                    <p class="font-mono text-[10px] text-slate-600">Hash: <?= $doc_hash ?></p>
                    <p class="text-[9px] text-slate-500 mt-0.5">Pindai QR untuk verifikasi keabsahan secara langsung.</p>
                </div>
            </div>

            <!-- Tanda Tangan & Stempel Resmi Kepala Sekolah -->
            <div class="w-full sm:w-auto text-right">
                <?= renderOfficialSignatureHtml($pdo, 'headmaster', [
                    'doc_type' => 'LEGALISIR',
                    'doc_num' => $leg['legalized_code'],
                    'student_name' => $leg['alumni_name'],
                    'city' => $city,
                    'date' => $date_legalized,
                    'align' => 'right'
                ]) ?>
            </div>

        </div>

        <!-- Footer Catatan Hukum -->
        <div class="relative z-20 border-t border-slate-200 mt-6 pt-3 flex flex-col sm:flex-row items-center justify-between text-[10px] text-slate-500">
            <p>Dokumen ini diterbitkan secara sah dan memenuhi ketentuan UU No. 11/2008 & PP No. 71/2019 tentang Informasi dan Transaksi Elektronik.</p>
            <span class="font-mono">ID: <?= htmlspecialchars($leg['legalized_code']) ?></span>
        </div>

    </div>

</body>
</html>
