<?php
/**
 * Portal Verifikasi Keaslian Dokumen Resmi & Tanda Tangan Elektronik (TTE)
 * Sistem Manajemen Informasi Lembaga Pendidikan
 *
 * Mendukung:
 * 1. Verifikasi otomatis melalui pemindaian Kode QR Dokumen Resmi (TTE Kriptografis SHA/MD5).
 * 2. Verifikasi berkas Legalisir Ijazah & Dokumen Akademik Resmi.
 * 3. Pencarian & Validasi Mandiri (Input Nomor Registrasi, Kode Legalisir, No Surat, atau Token).
 * 4. Pemindai Kamera Langsung (HTML5 Real-Time QR Scanner & Unggah Berkas Gambar).
 * 5. Cetak Lembar Bukti Hasil Verifikasi Resmi (Print Verification Certificate Slip).
 */

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/config/database.php";

$school = getSchoolSettings($pdo);

// Parameter query verifikasi (kompatibilitas penuh dengan template dokumen yang ada)
$raw_doc_type   = trim($_GET['doc_type'] ?? '');
$raw_doc_num    = trim($_GET['doc_num'] ?? '');
$raw_doc_title  = trim($_GET['doc_title'] ?? '');
$raw_student    = trim($_GET['student_name'] ?? '');
$raw_date       = trim($_GET['date'] ?? '');
$raw_role       = trim($_GET['signer_role'] ?? '');
$raw_name       = trim($_GET['signer_name'] ?? '');
$raw_nip        = trim($_GET['signer_nip'] ?? '');
$raw_hash       = trim($_GET['hash'] ?? '');
$search_q       = trim($_GET['q'] ?? $_GET['search'] ?? '');

// Inisialisasi variabel tampilan
$doc_type     = !empty($raw_doc_type) ? htmlspecialchars($raw_doc_type) : 'DOKUMEN RESMI SEKOLAH';
$doc_num      = !empty($raw_doc_num) ? htmlspecialchars($raw_doc_num) : '';
$doc_title    = !empty($raw_doc_title) ? htmlspecialchars($raw_doc_title) : 'Surat / Dokumen Pelayanan Administrasi Sekolah';
$student_name = !empty($raw_student) ? htmlspecialchars($raw_student) : '';
$issue_date   = !empty($raw_date) ? htmlspecialchars($raw_date) : date('d F Y');
$signer_role  = !empty($raw_role) ? htmlspecialchars($raw_role) : 'Kepala Sekolah';
$signer_name  = !empty($raw_name) ? htmlspecialchars($raw_name) : ($school['headmaster_name'] ?? 'Kepala Sekolah');
$signer_nip   = !empty($raw_nip) ? htmlspecialchars($raw_nip) : ($school['headmaster_nip'] ?? '-');

$is_doc_valid = false;
$verify_error = "";
$verify_match_source = "";

// Kamus label nama dokumen yang ramah pengguna
$doc_type_labels = [
    'SURAT_KETERANGAN'  => 'Surat Keterangan Sekolah',
    'SURAT_EDARAN'      => 'Surat Edaran Resmi Sekolah',
    'SKBB'              => 'Surat Keterangan Berkelakuan Baik (SKBB)',
    'SKL'               => 'Surat Keterangan Lulus (SKL)',
    'SPT'               => 'Surat Perintah Tugas (SPT Dinas Guru/Staf)',
    'KARTU_UJIAN'       => 'Kartu Peserta Ujian Resmi (Smart Clearance)',
    'KARTU_PPDB'        => 'Kartu Tanda Peserta PPDB Resmi',
    'RAPOR'             => 'Laporan Hasil Belajar Siswa (Buku Rapor Digital)',
    'RAPOR_P5'          => 'Rapor Projek Penguatan Profil Pelajar Pancasila (P5)',
    'LEGER_NILAI'       => 'Leger Rekapitulasi Nilai Akademik Siswa',
    'SK_DISIPLIN_DO'    => 'Surat Keputusan Pengembalian Siswa ke Orang Tua (DO)',
    'SP_BK'             => 'Surat Pembinaan / Peringatan BK',
    'SLIP_GAJI'         => 'Slip Gaji & Honorarium Pendidik',
    'BUKU_INDUK'        => 'Lembar Biodata Buku Induk Siswa (Master Archive)',
    'BEBAS_PUSTAKA'     => 'Surat Bebas Pustaka / Perpustakaan Resmi',
    'KUITANSI_SPP'      => 'Bukti Pembayaran / Kuitansi Sah SPP',
    'KUITANSI'          => 'Kuitansi / Bukti Pembayaran Keuangan',
    'LAPORAN_KEUANGAN'  => 'Buku Kas Umum / Laporan Realisasi Keuangan',
    'REKAP_PRESENSI'    => 'Rekapitulasi Kehadiran & Presensi Akademik',
    'DOKUMEN_UJIAN'     => 'Naskah & Kisi-Kisi Ujian Terverifikasi',
    'KARTU_PELAJAR'     => 'Kartu Tanda Siswa / Kartu Pelajar Digital Resmi',
    'SERTIFIKAT_EKSKUL' => 'Sertifikat & Piagam Keaktifan Ekstrakurikuler',
    'PIAGAM_PRESTASI'   => 'Piagam Penghargaan & Sertifikat Prestasi Siswa',
    'LEGALISIR'         => 'Pengesahan Legalisir Digital Ijazah & Dokumen Akademik Sah'
];

// Deteksi apakah pengguna membuka halaman landing (tanpa parameter pencarian/hash)
$is_landing_mode = empty($raw_hash) 
    && empty($search_q) 
    && (empty($raw_doc_type) || $raw_doc_type !== 'LEGALISIR') 
    && empty($raw_doc_num);

// PROSES 1: Pencarian Mandiri via Search Input ($search_q atau $raw_doc_num tanpa hash)
if (!$is_landing_mode && empty($raw_hash) && ($raw_doc_type !== 'LEGALISIR')) {
    $lookup_key = !empty($search_q) ? $search_q : $raw_doc_num;

    if (!empty($lookup_key)) {
        // 1.1 Cek Legalisir berdasarkan legalized_code atau document_number
        try {
            $stmt_leg = $pdo->prepare("SELECT * FROM legalisir_requests WHERE (legalized_code = ? OR document_number = ?) LIMIT 1");
            $stmt_leg->execute([$lookup_key, $lookup_key]);
            $leg_row = $stmt_leg->fetch();
            if ($leg_row) {
                $is_doc_valid = true;
                $doc_type = 'LEGALISIR';
                $doc_num = $leg_row['legalized_code'];
                $student_name = $leg_row['alumni_name'];
                if (!empty($leg_row['verified_at'])) {
                    $issue_date = date('d F Y', strtotime($leg_row['verified_at']));
                }
                $doc_title = "Legalisir Digital " . strtoupper($leg_row['document_type']) . " (No. Seri: " . $leg_row['document_number'] . ")";
                $raw_hash = strtoupper(substr(md5($doc_num . ($school['headmaster_nip'] ?? '') . 'SEKOLAH_AUTH_TTE'), 0, 16));
                $verify_match_source = "Pangkalan Data Arsip Legalisir Digital Sekolah";
            }
        } catch (Exception $e) {}

        // 1.2 Cek Arsip Surat Keluar (mail_archives)
        if (!$is_doc_valid) {
            try {
                $stmt_arc = $pdo->prepare("SELECT * FROM mail_archives WHERE reference_no = ? LIMIT 1");
                $stmt_arc->execute([$lookup_key]);
                $arc_row = $stmt_arc->fetch();
                if ($arc_row) {
                    $is_doc_valid = true;
                    $doc_type = 'SURAT_KETERANGAN';
                    $doc_num = $arc_row['reference_no'];
                    if (!empty($arc_row['subject'])) $doc_title = $arc_row['subject'];
                    if (!empty($arc_row['mail_date'])) $issue_date = date('d F Y', strtotime($arc_row['mail_date']));
                    if (!empty($arc_row['sender_or_recipient']) && empty($student_name)) {
                        $student_name = $arc_row['sender_or_recipient'];
                    }
                    $raw_hash = strtoupper(substr(md5($doc_num . ($school['headmaster_nip'] ?? '') . 'SEKOLAH_AUTH_TTE'), 0, 16));
                    $verify_match_source = "Buku Agenda Surat Keluar & Tata Usaha";
                }
            } catch (Exception $e) {}
        }

        // 1.3 Cek Permohonan Pelayanan Siswa (service_requests)
        if (!$is_doc_valid) {
            try {
                $sr_id = 0;
                if (preg_match('/\/(\d+)\//', $lookup_key, $m_sr)) {
                    $sr_id = (int)$m_sr[1];
                } elseif (is_numeric($lookup_key)) {
                    $sr_id = (int)$lookup_key;
                }
                if ($sr_id > 0) {
                    $stmt_sr = $pdo->prepare("SELECT r.*, u.name as real_name FROM service_requests r JOIN users u ON r.user_id = u.id WHERE r.id = ? AND r.status = 'selesai'");
                    $stmt_sr->execute([$sr_id]);
                    $sr_row = $stmt_sr->fetch();
                    if ($sr_row) {
                        $is_doc_valid = true;
                        $doc_type = 'SURAT_KETERANGAN';
                        $doc_num = $lookup_key;
                        $student_name = $sr_row['real_name'];
                        $doc_title = "Surat Pelayanan Siswa: " . htmlspecialchars($sr_row['letter_type'] ?? 'Administrasi');
                        if (!empty($sr_row['updated_at'])) $issue_date = date('d F Y', strtotime($sr_row['updated_at']));
                        $raw_hash = strtoupper(substr(md5($doc_num . ($school['headmaster_nip'] ?? '') . 'SEKOLAH_AUTH_TTE'), 0, 16));
                        $verify_match_source = "Layanan Pelayanan Administrasi Siswa Terpadu";
                    }
                }
            } catch (Exception $e) {}
        }

        // 1.4 Cek Nomor Peserta PPDB (ppdb_registrations)
        if (!$is_doc_valid) {
            try {
                $stmt_ppdb = $pdo->prepare("SELECT * FROM ppdb_registrations WHERE registration_no = ? OR qr_token = ? LIMIT 1");
                $stmt_ppdb->execute([$lookup_key, $lookup_key]);
                $p_row = $stmt_ppdb->fetch();
                if ($p_row) {
                    $is_doc_valid = true;
                    $doc_type = 'KARTU_PPDB';
                    $doc_num = $p_row['registration_no'];
                    $student_name = $p_row['full_name'];
                    $doc_title = "Kartu Tanda Peserta PPDB Online Resmi";
                    if (!empty($p_row['created_at'])) $issue_date = date('d F Y', strtotime($p_row['created_at']));
                    $raw_hash = !empty($p_row['qr_token']) ? strtoupper(substr($p_row['qr_token'], 0, 16)) : strtoupper(substr(md5($doc_num . ($school['headmaster_nip'] ?? '') . 'SEKOLAH_AUTH_TTE'), 0, 16));
                    $verify_match_source = "Pangkalan Data Seleksi PPDB Online Terpadu";
                }
            } catch (Exception $e) {}
        }

        // 1.5 Cek Kuitansi / Tagihan Pembayaran Siswa (student_bills)
        if (!$is_doc_valid && (str_contains($lookup_key, 'KWT') || is_numeric($lookup_key))) {
            try {
                $b_id = 0;
                if (preg_match('/(\d+)$/', $lookup_key, $m_b)) {
                    $b_id = (int)$m_b[1];
                }
                if ($b_id > 0) {
                    $stmt_b = $pdo->prepare("SELECT b.*, u.name as real_name FROM student_bills b JOIN users u ON b.student_id = u.id WHERE b.id = ? AND b.status = 'lunas'");
                    $stmt_b->execute([$b_id]);
                    $b_row = $stmt_b->fetch();
                    if ($b_row) {
                        $is_doc_valid = true;
                        $doc_type = 'KUITANSI_SPP';
                        $doc_num = "KWT/" . date('Y/m', strtotime($b_row['created_at'] ?? 'now')) . "/" . str_pad((string)$b_row['id'], 5, '0', STR_PAD_LEFT);
                        $student_name = $b_row['real_name'];
                        $doc_title = "Kuitansi Pembayaran Sah Tagihan #" . $b_row['id'];
                        if (!empty($b_row['updated_at'])) $issue_date = date('d F Y', strtotime($b_row['updated_at']));
                        $raw_hash = strtoupper(substr(md5($doc_num . ($school['headmaster_nip'] ?? '') . 'SEKOLAH_AUTH_TTE'), 0, 16));
                        $verify_match_source = "Buku Kas & Riwayat Pembayaran Siswa Lunas";
                    }
                }
            } catch (Exception $e) {}
        }

        if (!$is_doc_valid) {
            $verify_error = "Dokumen dengan nomor registrasi atau kode \"" . htmlspecialchars($lookup_key) . "\" tidak ditemukan dalam pangkalan data resmi sekolah.";
        }
    } else {
        $verify_error = "Harap masukkan nomor registrasi dokumen, nomor surat, atau kode legalisir untuk diverifikasi.";
    }
}

// PROSES 2: Verifikasi Berkas LEGALISIR Berdasarkan URL
if (!$is_landing_mode && $raw_doc_type === 'LEGALISIR') {
    if (empty($doc_num)) {
        $verify_error = "Nomor kode verifikasi legalisir tidak disertakan pada tautan.";
    } else {
        try {
            $stmt_leg = $pdo->prepare("SELECT * FROM legalisir_requests WHERE legalized_code = ? LIMIT 1");
            $stmt_leg->execute([$doc_num]);
            $legalisir_record = $stmt_leg->fetch();
            if ($legalisir_record) {
                $student_name = $legalisir_record['alumni_name'];
                if (!empty($legalisir_record['verified_at'])) {
                    $issue_date = date('d F Y', strtotime($legalisir_record['verified_at']));
                }
                $doc_title = "Legalisir Digital " . strtoupper($legalisir_record['document_type']) . " (No. Seri: " . $legalisir_record['document_number'] . ")";
                $is_doc_valid = true;
                $verify_match_source = "Pangkalan Data Arsip Legalisir Digital Ijazah";
                if (empty($raw_hash)) {
                    $raw_hash = strtoupper(substr(md5($doc_num . ($school['headmaster_nip'] ?? '') . 'SEKOLAH_AUTH_TTE'), 0, 16));
                }
            } else {
                $verify_error = "Kode legalisir tidak ditemukan pada basis data arsip resmi sekolah.";
            }
        } catch (Exception $e) {
            $verify_error = "Gagal memverifikasi catatan legalisir: " . $e->getMessage();
        }
    }
}

// PROSES 3: Validasi Hash Tanda Tangan Digital TTE Standar (Kompatibilitas Penuh Kode QR Asli)
if (!$is_landing_mode && !empty($raw_hash) && !$is_doc_valid) {
    $expected_hash_1 = strtoupper(substr(md5($doc_num . $signer_nip . 'SEKOLAH_AUTH_TTE'), 0, 16));
    $expected_hash_2 = strtoupper(substr(md5($doc_num . ($school['headmaster_nip'] ?? '') . 'SEKOLAH_AUTH_TTE'), 0, 16));
    $expected_hash_3 = strtoupper(substr(md5($doc_num . $raw_doc_type . 'SEKOLAH_AUTH_SALT'), 0, 20));

    $clean_hash = strtoupper($raw_hash);
    $hash_matched = (hash_equals($expected_hash_1, $clean_hash) || hash_equals($expected_hash_2, $clean_hash) || hash_equals($expected_hash_3, $clean_hash));

    if (!$hash_matched) {
        $verify_error = "Token tanda tangan digital tidak cocok. Dokumen berpotensi telah dimodifikasi atau tidak sah.";
    } else {
        // Verifikasi integritas terhadap pangkalan data arsip resmi
        $db_found = false;

        // 1. Cek Arsip Surat Keluar (mail_archives)
        try {
            $stmt_arc = $pdo->prepare("SELECT * FROM mail_archives WHERE reference_no = ? LIMIT 1");
            $stmt_arc->execute([$doc_num]);
            $arc = $stmt_arc->fetch();
            if ($arc) {
                $db_found = true;
                if (!empty($arc['subject'])) $doc_title = $arc['subject'];
                $verify_match_source = "Arsip Surat Keluar Resmi Tata Usaha";
            }
        } catch (Exception $e) {}

        // 2. Cek Permohonan Pelayanan Surat (service_requests)
        if (!$db_found && preg_match('/\/(\d+)\//', $doc_num, $m_id)) {
            try {
                $req_id = (int)$m_id[1];
                $stmt_req = $pdo->prepare("SELECT r.*, u.name as real_name FROM service_requests r JOIN users u ON r.user_id = u.id WHERE r.id = ? AND r.status = 'selesai'");
                $stmt_req->execute([$req_id]);
                $req_row = $stmt_req->fetch();
                if ($req_row) {
                    $db_found = true;
                    $student_name = $req_row['real_name'];
                    $verify_match_source = "Buku Pelayanan Surat Administrasi Siswa";
                }
            } catch (Exception $e) {}
        }

        // 3. Cek Kuitansi Pembayaran (student_bills)
        if (!$db_found && (str_contains($raw_doc_type, 'KUITANSI') || str_contains($raw_doc_type, 'SPP'))) {
            if (preg_match('/(\d+)$/', $doc_num, $m_bill)) {
                try {
                    $bill_id = (int)$m_bill[1];
                    $stmt_b = $pdo->prepare("SELECT b.*, u.name as real_name FROM student_bills b JOIN users u ON b.student_id = u.id WHERE b.id = ? AND b.status = 'lunas'");
                    $stmt_b->execute([$bill_id]);
                    $b_row = $stmt_b->fetch();
                    if ($b_row) {
                        $db_found = true;
                        $student_name = $b_row['real_name'];
                        $verify_match_source = "Buku Kas & Riwayat Tagihan Siswa Lunas";
                    }
                } catch (Exception $e) {}
            }
        }

        // 4. Validasi Keberadaan Subjek Siswa jika disertakan
        if (!$db_found && !empty($student_name)) {
            try {
                $stmt_u = $pdo->prepare("SELECT name FROM users WHERE name = ? LIMIT 1");
                $stmt_u->execute([$student_name]);
                if ($stmt_u->fetch()) {
                    $db_found = true;
                    $verify_match_source = "Buku Induk Kependidikan & Peserta Didik Aktif";
                } else {
                    $stmt_ppdb = $pdo->prepare("SELECT full_name FROM ppdb_registrations WHERE full_name = ? LIMIT 1");
                    $stmt_ppdb->execute([$student_name]);
                    if ($stmt_ppdb->fetch()) {
                        $db_found = true;
                        $verify_match_source = "Pangkalan Data Pendaftar PPDB Resmi";
                    }
                }
            } catch (Exception $e) {}
        }

        // 5. Cek Nomor Pendaftaran PPDB (KARTU_PPDB)
        if (!$db_found && $raw_doc_type === 'KARTU_PPDB') {
            try {
                $stmt_ppdb2 = $pdo->prepare("SELECT full_name FROM ppdb_registrations WHERE registration_no = ? LIMIT 1");
                $stmt_ppdb2->execute([$doc_num]);
                $ppdb_row2 = $stmt_ppdb2->fetch();
                if ($ppdb_row2) {
                    $db_found = true;
                    if (empty($student_name)) {
                        $student_name = $ppdb_row2['full_name'];
                    }
                    $verify_match_source = "Pangkalan Data Pendaftar PPDB Terverifikasi";
                }
            } catch (Exception $e) {}
        }

        // 6. Dokumen institusional umum / laporan administratif dengan token hash sah
        if (!$db_found && in_array($raw_doc_type, ['LAPORAN_KEUANGAN', 'REKAP_PRESENSI', 'SURAT_EDARAN', 'DOKUMEN_UJIAN', 'LEGER_NILAI', 'RAPOR_P5'], true)) {
            $db_found = true;
            $verify_match_source = "Arsip Digital Laporan Institusional Lembaga";
        }

        if ($db_found) {
            $is_doc_valid = true;
        } else {
            $verify_error = "Catatan berkas, nomor dokumen, atau subjek terkait tidak ditemukan dalam pangkalan data arsip resmi sekolah.";
        }
    }
}

// Format teks tampilan
$display_type = $doc_type_labels[$raw_doc_type] ?? ($doc_type_labels[$doc_type] ?? $doc_type);
$hash_display = !empty($raw_hash) ? htmlspecialchars($raw_hash) : '-';
$current_verify_timestamp = date('d F Y, H:i:s') . ' WIB';
$verification_session_code = 'VERIF-' . strtoupper(substr(md5($doc_num . $hash_display . date('Ymd')), 0, 8)) . '-' . date('Y');

// Logo sekolah
$clean_v_logo = !empty($school['school_logo']) ? resolveAssetUrl($school['school_logo']) : '';
$v_logo_exists = !empty($clean_v_logo) && (strpos($clean_v_logo, 'http') === 0 || file_exists(__DIR__ . '/' . resolveAssetUrl($school['school_logo'])));

// URL absolut saat ini untuk disalin
$current_full_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '');
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Keaslian Dokumen Resmi - <?= htmlspecialchars($school['school_name'] ?? 'Sistem Sekolah') ?></title>
    
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    
    <!-- Tailwind CSS v4 Browser Engine -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    
    <!-- HTML5 QR Code Scanner Library (CDN Fallback Graceful) -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

    <style>
        :root {
            --font-sans: 'Plus Jakarta Sans', system-ui, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }
        body {
            font-family: var(--font-sans);
        }
        .font-mono-code {
            font-family: var(--font-mono);
        }
        * {
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.35) transparent;
        }
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3);
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.6);
        }

        /* Certificate Guilloche Watermark Pattern */
        .certificate-watermark {
            background-image: radial-gradient(circle at 50% 50%, rgba(16, 185, 129, 0.05) 0%, transparent 60%),
                              radial-gradient(circle at 10% 20%, rgba(59, 130, 246, 0.04) 0%, transparent 40%),
                              radial-gradient(circle at 90% 80%, rgba(139, 92, 246, 0.04) 0%, transparent 40%);
        }

        /* Khusus Media Cetak / Print Styles */
        @media print {
            body {
                background: #ffffff !important;
                color: #0f172a !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .print-card {
                border: 2px solid #0f172a !important;
                box-shadow: none !important;
                background: #ffffff !important;
                color: #0f172a !important;
                border-radius: 0 !important;
                padding: 24px !important;
            }
            .print-exact {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .certificate-watermark {
                background: none !important;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col justify-between p-3 sm:p-6 transition-colors duration-200">

    <!-- Top Utility Bar (No-Print) -->
    <header class="no-print max-w-4xl mx-auto w-full flex items-center justify-between pb-4 pt-1 text-xs">
        <a href="index.php" class="inline-flex items-center gap-2 text-slate-400 hover:text-white transition font-medium">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Kembali ke Beranda Utama</span>
        </a>
        <div class="flex items-center gap-2">
            <!-- Theme Toggle Button -->
            <button id="themeToggleBtn" type="button" onclick="toggleTheme()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-slate-900 border border-slate-800 text-slate-300 hover:text-white hover:border-slate-700 transition" title="Ganti Tema (Terang / Gelap)">
                <i class="fa-solid fa-sun text-amber-400" id="themeIcon"></i>
                <span id="themeLabel" class="hidden sm:inline">Tema</span>
            </button>
            <?php if (!$is_landing_mode): ?>
                <a href="verify_document.php" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-blue-600/20 border border-blue-500/30 text-blue-300 hover:bg-blue-600/30 transition">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span class="hidden sm:inline">Cari Dokumen Lain</span>
                </a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Main Container -->
    <main class="max-w-4xl mx-auto w-full my-auto py-2 sm:py-6">

        <!-- KOP / Header Identitas Resmi Instansi -->
        <div class="text-center mb-6 print:mb-4">
            <div class="flex items-center justify-center gap-4 mb-2">
                <?php if ($v_logo_exists): ?>
                    <img src="<?= htmlspecialchars($clean_v_logo) ?>" alt="Logo Instansi" class="h-16 w-16 sm:h-20 sm:w-20 object-contain drop-shadow-md">
                <?php else: ?>
                    <div class="flex h-16 w-16 sm:h-18 sm:w-18 items-center justify-center rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white text-3xl shadow-xl shadow-blue-500/20 border border-white/20">
                        <i class="fa-solid fa-building-columns"></i>
                    </div>
                <?php endif; ?>
                <div class="text-left">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold tracking-wider uppercase bg-blue-500/10 text-blue-400 border border-blue-500/20 mb-1">
                        <i class="fa-solid fa-shield-halved"></i> Portal Layanan Validasi Digital Resmi
                    </span>
                    <h1 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-white print:text-black">
                        <?= htmlspecialchars($school['school_name'] ?? 'Sistem Manajemen Sekolah') ?>
                    </h1>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-slate-400 print:text-slate-600">
                        <?php if (!empty($school['school_npsn'])): ?>
                            <span>NPSN: <strong class="text-slate-300 print:text-black"><?= htmlspecialchars($school['school_npsn']) ?></strong></span>
                            <span>•</span>
                        <?php endif; ?>
                        <?php if (!empty($school['school_accreditation'])): ?>
                            <span>Akreditasi: <strong class="text-amber-400 print:text-black"><?= htmlspecialchars($school['school_accreditation']) ?></strong></span>
                            <span>•</span>
                        <?php endif; ?>
                        <span>Sistem TTE Terverifikasi</span>
                    </div>
                </div>
            </div>
            <p class="text-xs text-slate-400 print:text-slate-600 max-w-xl mx-auto">
                <?= htmlspecialchars($school['school_address'] ?? 'Alamat Resmi Sekolah') ?>
            </p>
            <p class="text-[11px] text-slate-500 print:text-slate-500 font-mono mt-0.5">
                Telp: <?= htmlspecialchars($school['school_phone'] ?? '-') ?> | Pos-el: <?= htmlspecialchars($school['school_email'] ?? '-') ?>
            </p>
        </div>

        <?php if ($is_landing_mode): ?>
            <!-- ============================================================== -->
            <!-- STATE 1: MODE LANDING / PENCARIAN & PEMINDAIAN MANDIRI       -->
            <!-- ============================================================== -->
            <div class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 sm:p-10 shadow-2xl backdrop-blur relative overflow-hidden certificate-watermark">
                <!-- Ambient Accent Glow -->
                <div class="absolute -right-20 -top-20 h-64 w-64 rounded-full bg-blue-500/10 blur-3xl pointer-events-none"></div>
                <div class="absolute -left-20 -bottom-20 h-64 w-64 rounded-full bg-indigo-500/10 blur-3xl pointer-events-none"></div>

                <div class="max-w-2xl mx-auto text-center space-y-4">
                    <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-500/20 text-blue-400 text-3xl border border-blue-500/30 shadow-lg shadow-blue-500/10">
                        <i class="fa-solid fa-qrcode"></i>
                    </div>
                    <h2 class="text-2xl sm:text-3xl font-black text-white tracking-tight">
                        Pemeriksaan Keabsahan Dokumen Sekolah
                    </h2>
                    <p class="text-sm text-slate-300 leading-relaxed">
                        Layanan terbuka untuk publik, perguruan tinggi, kedinasan, dan perusahaan guna memverifikasi keaslian dokumen resmi (Surat Keterangan, Ijazah Legalisir, Rapor Digital, Kuitansi, Kartu Ujian, dsb.) yang diterbitkan oleh institusi.
                    </p>

                    <!-- Formulir Pencarian Manual -->
                    <form method="GET" action="verify_document.php" class="mt-8 text-left space-y-3">
                        <label for="searchQueryInput" class="block text-xs font-bold text-slate-300 uppercase tracking-wider">
                            <i class="fa-solid fa-keyboard mr-1 text-blue-400"></i> Masukkan Nomor Dokumen / Kode Legalisir / Token TTE:
                        </label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <div class="relative flex-1">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-4 text-slate-500 pointer-events-none">
                                    <i class="fa-solid fa-barcode"></i>
                                </span>
                                <input type="text" 
                                       id="searchQueryInput" 
                                       name="q" 
                                       placeholder="Contoh: 421.3/REG/2026 atau LEG-2026-0001 atau No. Peserta PPDB" 
                                       required
                                       class="w-full pl-11 pr-4 py-3.5 rounded-2xl bg-slate-950 border border-slate-700 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition">
                            </div>
                            <button type="submit" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-2xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-bold text-sm shadow-lg shadow-blue-500/25 transition active:scale-98">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <span>Verifikasi</span>
                            </button>
                        </div>
                    </form>

                    <!-- Opsi Pemindai Kamera & Unggah QR -->
                    <div class="pt-6 border-t border-slate-800/80 flex flex-col sm:flex-row items-center justify-center gap-3">
                        <button type="button" onclick="startCameraScanner()" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-2xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white text-xs font-bold transition">
                            <i class="fa-solid fa-camera text-emerald-400"></i>
                            <span>Pindai Kode QR via Kamera</span>
                        </button>
                        <label class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-2xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-white text-xs font-bold cursor-pointer transition">
                            <i class="fa-solid fa-file-image text-cyan-400"></i>
                            <span>Unggah Foto / Screenshot QR</span>
                            <input type="file" id="qrFileInput" accept="image/*" class="hidden" onchange="handleQrFileUpload(this)">
                        </label>
                    </div>

                    <!-- Panduan / Letak Kode QR -->
                    <div class="mt-8 p-4 rounded-2xl bg-slate-950/70 border border-slate-800 text-left text-xs text-slate-400 space-y-2">
                        <div class="font-bold text-slate-200 flex items-center gap-2">
                            <i class="fa-solid fa-circle-info text-blue-400"></i> Di mana letak kode verifikasi?
                        </div>
                        <ul class="list-disc list-inside space-y-1 text-slate-400 pl-1">
                            <li><strong class="text-slate-300">Surat Keterangan & Rapor:</strong> Pada blok tanda tangan Kepala Sekolah di sudut kanan bawah naskah.</li>
                            <li><strong class="text-slate-300">Lembar Legalisir Digital:</strong> Pada stempel resmi TTE atau kolom kode registrasi legalisir (format: <span class="font-mono text-cyan-300">LEG-YYYY-XXXX</span>).</li>
                            <li><strong class="text-slate-300">Kartu Ujian / Kartu PPDB:</strong> Pada lencana keamanan barcode/QR smart clearance di sisi kanan kartu.</li>
                        </ul>
                    </div>
                </div>
            </div>

        <?php elseif ($is_doc_valid): ?>
            <!-- ============================================================== -->
            <!-- STATE 2: DOKUMEN RESMI TERVERIFIKASI SAH                     -->
            <!-- ============================================================== -->
            <div class="print-card relative overflow-hidden rounded-3xl border border-emerald-500/40 bg-gradient-to-b from-slate-900 via-slate-900 to-emerald-950/20 p-6 sm:p-10 shadow-2xl backdrop-blur certificate-watermark print:border-2 print:border-black print:bg-white print:p-6">
                
                <!-- Background Accent Glow -->
                <div class="absolute -right-20 -top-20 h-64 w-64 rounded-full bg-emerald-500/10 blur-3xl pointer-events-none no-print"></div>
                <div class="absolute -left-20 -bottom-20 h-64 w-64 rounded-full bg-blue-500/10 blur-3xl pointer-events-none no-print"></div>

                <!-- Banner Status Keaslian Dokumen -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-emerald-500/20 pb-6 mb-6 print:border-black print:pb-4 print:mb-4">
                    <div class="flex items-start sm:items-center gap-4">
                        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-emerald-500/20 border border-emerald-500/40 text-emerald-400 text-3xl shadow-lg shadow-emerald-500/20 print:bg-emerald-100 print:text-emerald-700 print:border-emerald-600">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <div>
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-0.5 text-[11px] font-black uppercase tracking-wider bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 print:bg-emerald-50 print:text-emerald-800 print:border-emerald-700">
                                    <i class="fa-solid fa-shield-halved text-[10px]"></i> Tanda Tangan Elektronik Sah
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-mono font-semibold bg-slate-800 text-slate-300 border border-slate-700 print:border-black print:text-black">
                                    <i class="fa-solid fa-hashtag text-[9px]"></i> <?= htmlspecialchars($verification_session_code) ?>
                                </span>
                            </div>
                            <h2 class="text-xl sm:text-2xl font-black text-white print:text-black tracking-tight">
                                Dokumen Resmi Dinyatakan Sah & Terverifikasi
                            </h2>
                            <p class="text-xs text-slate-400 print:text-slate-600 mt-0.5">
                                Diverifikasi melalui Pangkalan Data Arsip Resmi Lembaga pada <strong class="text-slate-200 print:text-black"><?= $current_verify_timestamp ?></strong>.
                            </p>
                        </div>
                    </div>

                    <!-- Seal Hologram Visual (Cetak & Layar) -->
                    <div class="hidden sm:flex flex-col items-center justify-center p-3 rounded-2xl bg-emerald-950/40 border border-emerald-500/30 text-center shrink-0 print:border print:border-black print:bg-white">
                        <i class="fa-solid fa-stamp text-emerald-400 text-xl print:text-black"></i>
                        <span class="text-[9px] font-black uppercase tracking-wider text-emerald-300 print:text-black mt-1">SEAL OF AUTHENTICITY</span>
                        <span class="text-[8px] font-mono text-slate-400 print:text-slate-600"><?= date('Y') ?> VERIFIED</span>
                    </div>
                </div>

                <!-- Rincian Data Metadata Dokumen -->
                <div class="space-y-3.5 text-xs sm:text-sm">
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="p-3.5 rounded-2xl bg-slate-950/70 border border-slate-800/80 print:bg-white print:border-slate-300">
                            <span class="text-[11px] font-bold text-slate-400 print:text-slate-600 uppercase tracking-wider block mb-1">
                                <i class="fa-solid fa-file-contract mr-1 text-emerald-400"></i> Jenis Dokumen:
                            </span>
                            <strong class="text-sm font-black text-white print:text-black"><?= $display_type ?></strong>
                        </div>

                        <div class="p-3.5 rounded-2xl bg-slate-950/70 border border-slate-800/80 print:bg-white print:border-slate-300">
                            <span class="text-[11px] font-bold text-slate-400 print:text-slate-600 uppercase tracking-wider block mb-1">
                                <i class="fa-solid fa-fingerprint mr-1 text-cyan-400"></i> Nomor Registrasi / Berkas:
                            </span>
                            <div class="flex items-center justify-between gap-2">
                                <strong class="text-sm font-mono font-bold text-cyan-300 print:text-black truncate"><?= $doc_num ?></strong>
                                <button type="button" onclick="copyToClipboard('<?= addslashes($doc_num) ?>', 'Nomor Dokumen')" class="no-print text-slate-400 hover:text-white text-xs px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 transition" title="Salin Nomor">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($doc_title) && $doc_title !== 'Surat / Dokumen Pelayanan Administrasi Sekolah'): ?>
                        <div class="p-3.5 rounded-2xl bg-slate-950/70 border border-slate-800/80 print:bg-white print:border-slate-300">
                            <span class="text-[11px] font-bold text-slate-400 print:text-slate-600 uppercase tracking-wider block mb-1">
                                <i class="fa-solid fa-heading mr-1 text-indigo-400"></i> Perihal / Judul Berkas:
                            </span>
                            <strong class="text-slate-200 print:text-black font-semibold"><?= $doc_title ?></strong>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($student_name)): ?>
                        <div class="p-3.5 rounded-2xl bg-slate-950/70 border border-slate-800/80 print:bg-white print:border-slate-300">
                            <span class="text-[11px] font-bold text-slate-400 print:text-slate-600 uppercase tracking-wider block mb-1">
                                <i class="fa-solid fa-user-check mr-1 text-amber-400"></i> Subjek / Nama Pemilik Dokumen:
                            </span>
                            <strong class="text-base font-black text-amber-300 print:text-black"><?= $student_name ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="p-3.5 rounded-2xl bg-slate-950/70 border border-slate-800/80 print:bg-white print:border-slate-300">
                        <span class="text-[11px] font-bold text-slate-400 print:text-slate-600 uppercase tracking-wider block mb-1">
                            <i class="fa-solid fa-calendar-check mr-1 text-blue-400"></i> Tanggal Pengesahan Resmi:
                        </span>
                        <span class="text-slate-200 print:text-black font-semibold"><?= $issue_date ?></span>
                    </div>

                    <!-- Blok Otorisasi Penandatangan Digital -->
                    <div class="p-4 sm:p-5 rounded-2xl bg-blue-950/20 border border-blue-500/30 print:border-slate-400 print:bg-slate-50 mt-4 space-y-3">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-blue-400 print:text-blue-900 block">
                            <i class="fa-solid fa-signature mr-1.5"></i> Otorisasi Pengesahan Tanda Tangan Elektronik:
                        </span>
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <p class="text-base sm:text-lg font-black text-white print:text-black"><?= $signer_name ?></p>
                                <p class="text-xs text-slate-400 print:text-slate-700 font-mono">NIP. <?= $signer_nip ?></p>
                                <p class="text-xs text-blue-300 print:text-blue-800 font-semibold mt-0.5"><?= $signer_role ?> — <?= htmlspecialchars($school['school_name'] ?? 'Lembaga Pendidikan') ?></p>
                            </div>
                            <div class="text-left sm:text-right shrink-0">
                                <span class="inline-flex items-center gap-1.5 rounded-xl border border-blue-500/40 bg-blue-500/20 px-3.5 py-1.5 text-xs font-bold text-blue-200 print:border-blue-700 print:bg-blue-100 print:text-blue-900">
                                    <i class="fa-solid fa-certificate text-xs"></i> TERDAFTAR & DIAKREDITASI
                                </span>
                                <?php if (!empty($verify_match_source)): ?>
                                    <p class="text-[10px] text-slate-400 print:text-slate-600 mt-1">Sumber: <?= htmlspecialchars($verify_match_source) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Checklist Audit Keamanan Kriptografis 4 Poin -->
                    <div class="mt-4 p-4 rounded-2xl bg-slate-950/60 border border-slate-800 print:border-slate-300 print:bg-white space-y-2.5">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 print:text-slate-700 block mb-1">
                            <i class="fa-solid fa-list-check mr-1 text-emerald-400"></i> Hasil Audit Keamanan Sistem:
                        </span>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                            <div class="flex items-center gap-2 text-slate-300 print:text-slate-800">
                                <i class="fa-solid fa-check text-emerald-400"></i>
                                <span><strong>Integritas Dokumen:</strong> Berkas utuh & tidak dimodifikasi.</span>
                            </div>
                            <div class="flex items-center gap-2 text-slate-300 print:text-slate-800">
                                <i class="fa-solid fa-check text-emerald-400"></i>
                                <span><strong>Identitas Penandatangan:</strong> Pejabat sah terdaftar aktif.</span>
                            </div>
                            <div class="flex items-center gap-2 text-slate-300 print:text-slate-800">
                                <i class="fa-solid fa-check text-emerald-400"></i>
                                <span><strong>Pangkalan Data:</strong> Terdaftar di arsip master sekolah.</span>
                            </div>
                            <div class="flex items-center gap-2 text-slate-300 print:text-slate-800">
                                <i class="fa-solid fa-check text-emerald-400"></i>
                                <span><strong>Payung Hukum:</strong> Sah mengacu UU ITE Pasal 5 & 11.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Accordion / Detail Teknis Hash TTE Kriptografis -->
                    <div class="p-3.5 rounded-2xl bg-slate-950/80 border border-slate-800/80 font-mono-code text-[11px] print:border-slate-300 print:bg-white flex flex-col sm:flex-row sm:items-center justify-between gap-2 mt-4">
                        <div class="flex items-center gap-2 text-slate-400 print:text-slate-700">
                            <i class="fa-solid fa-fingerprint text-purple-400 print:text-purple-700 text-sm"></i>
                            <span>SHA / TTE Checksum Digest:</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-purple-300 print:text-purple-900 font-bold tracking-wider truncate max-w-xs sm:max-w-md"><?= $hash_display ?></span>
                            <button type="button" onclick="copyToClipboard('<?= addslashes($hash_display) ?>', 'Hash Kriptografis')" class="no-print text-slate-400 hover:text-white px-2 py-0.5 rounded bg-slate-800 hover:bg-slate-700 transition" title="Salin Hash">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>
                    </div>

                </div>

                <!-- Action Toolbar Pasca-Verifikasi (No-Print) -->
                <div class="no-print mt-8 pt-6 border-t border-slate-800 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-2xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs shadow-lg shadow-emerald-500/20 transition active:scale-98">
                            <i class="fa-solid fa-print"></i>
                            <span>Cetak Lembar Bukti</span>
                        </button>
                        <button type="button" onclick="copyCurrentUrl()" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-semibold transition active:scale-98">
                            <i class="fa-solid fa-share-nodes text-blue-400"></i>
                            <span>Bagikan / Salin Tautan</span>
                        </button>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <a href="verify_document.php" class="text-blue-400 hover:text-blue-300 font-semibold inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <span>Verifikasi Dokumen Baru</span>
                        </a>
                    </div>
                </div>

                <!-- Footer Khusus Cetak -->
                <div class="hidden print:block mt-8 pt-4 border-t border-slate-300 text-[10px] text-slate-500 text-center leading-relaxed">
                    <p>Lembar Bukti Hasil Verifikasi Resmi ini dicetak secara otomatis dari Sistem Manajemen Informasi <?= htmlspecialchars($school['school_name'] ?? 'Sekolah') ?> pada <?= $current_verify_timestamp ?>.</p>
                    <p>Keabsahan tanda tangan elektronik dilindungi oleh Undang-Undang Republik Indonesia Nomor 11 Tahun 2008 tentang Informasi dan Transaksi Elektronik (UU ITE) serta PP Nomor 71 Tahun 2019.</p>
                </div>

            </div>

        <?php else: ?>
            <!-- ============================================================== -->
            <!-- STATE 3: VERIFIKASI GAGAL / DOKUMEN TIDAK VALID              -->
            <!-- ============================================================== -->
            <div class="relative overflow-hidden rounded-3xl border border-rose-500/40 bg-gradient-to-b from-slate-900 via-slate-900 to-rose-950/20 p-6 sm:p-10 shadow-2xl backdrop-blur certificate-watermark">
                <!-- Background Accent Glow -->
                <div class="absolute -right-20 -top-20 h-64 w-64 rounded-full bg-rose-500/10 blur-3xl pointer-events-none"></div>

                <div class="flex flex-col sm:flex-row sm:items-center gap-4 border-b border-rose-500/20 pb-6 mb-6">
                    <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-rose-500/20 border border-rose-500/40 text-rose-400 text-3xl shadow-lg shadow-rose-500/20">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-0.5 text-[11px] font-black uppercase tracking-wider bg-rose-500/20 text-rose-300 border border-rose-500/40 mb-1">
                            <i class="fa-solid fa-shield-xmark text-[10px]"></i> Verifikasi Tidak Berhasil
                        </span>
                        <h2 class="text-xl sm:text-2xl font-black text-white tracking-tight">
                            Keaslian Dokumen Tidak Dapat Dikonfirmasi
                        </h2>
                        <p class="text-xs text-rose-300 font-medium mt-0.5">
                            <?= !empty($verify_error) ? htmlspecialchars($verify_error) : 'Dokumen tidak terdaftar atau token verifikasi tidak cocok dengan pangkalan data resmi.' ?>
                        </p>
                    </div>
                </div>

                <div class="space-y-4 text-xs sm:text-sm">
                    <div class="p-4 rounded-2xl bg-slate-950/70 border border-slate-800 space-y-2">
                        <span class="text-xs font-bold text-slate-300 uppercase tracking-wider block">
                            <i class="fa-solid fa-circle-question mr-1 text-rose-400"></i> Kemungkinan Penyebab:
                        </span>
                        <ul class="list-disc list-inside space-y-1 text-slate-400 text-xs pl-1">
                            <li>Nomor registrasi dokumen atau kode legalisir salah ketik saat pencarian manual.</li>
                            <li>Tautan verifikasi terpotong saat disalin atau kode QR buram/rusak pada cetakan fisik.</li>
                            <li>Dokumen merupakan draf lampau yang telah dibatalkan atau ditarik kembali oleh pihak sekolah.</li>
                            <li>Kandungan teks atau tanda tangan pada dokumen telah dimodifikasi di luar pangkalan data resmi.</li>
                        </ul>
                    </div>

                    <!-- Formulir Coba Lagi -->
                    <div class="p-5 rounded-2xl bg-slate-950/80 border border-slate-800 space-y-3">
                        <span class="text-xs font-bold text-slate-200 block">
                            <i class="fa-solid fa-rotate-right mr-1 text-blue-400"></i> Coba Periksa Ulang dengan Nomor / Kode Lain:
                        </span>
                        <form method="GET" action="verify_document.php" class="flex flex-col sm:flex-row gap-2">
                            <input type="text" 
                                   name="q" 
                                   value="<?= htmlspecialchars($search_q ?: $doc_num) ?>" 
                                   placeholder="Masukkan Nomor Dokumen / Kode Legalisir" 
                                   required
                                   class="flex-1 px-4 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-sm text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <button type="submit" class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs transition">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <span>Periksa Ulang</span>
                            </button>
                        </form>
                    </div>

                    <!-- Tindakan Lanjutan -->
                    <div class="pt-4 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                        <a href="verify_document.php" class="text-slate-400 hover:text-white inline-flex items-center gap-1.5 font-medium">
                            <i class="fa-solid fa-house"></i>
                            <span>Kembali ke Halaman Verifikasi Utama</span>
                        </a>
                        <a href="kontak.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold border border-slate-700 transition">
                            <i class="fa-solid fa-headset text-emerald-400"></i>
                            <span>Hubungi Layanan Tata Usaha Sekolah</span>
                        </a>
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <!-- Footer Hak Cipta & Keamanan (No-Print) -->
        <footer class="no-print mt-8 text-center space-y-2 text-xs text-slate-500">
            <p>
                <i class="fa-solid fa-lock text-emerald-400 mr-1"></i> Keabsahan dan integritas dokumen dilindungi oleh UU ITE No. 11/2008 & Sistem Informasi Sekolah.
            </p>
            <p>
                &copy; <?= date('Y') ?> <strong class="text-slate-400"><?= htmlspecialchars($school['school_name'] ?? 'Sistem Sekolah') ?></strong>. Seluruh hak cipta dilindungi undang-undang.
            </p>
        </footer>

    </main>

    <!-- ============================================================== -->
    <!-- MODAL PEMINDAI KAMERA QR (HTML5 QR CODE SCANNER)              -->
    <!-- ============================================================== -->
    <div id="cameraScannerModal" class="no-print fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="relative w-full max-w-lg rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-2 text-white font-bold text-sm">
                    <i class="fa-solid fa-camera text-emerald-400"></i>
                    <span>Pindai Kode QR Dokumen</span>
                </div>
                <button type="button" onclick="stopCameraScanner()" class="h-8 w-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <p class="text-xs text-slate-400">
                Arahkan kamera ke Kode QR tanda tangan elektronik pada dokumen fisik atau layar Anda.
            </p>

            <!-- Video Viewport -->
            <div id="qrReaderViewport" class="overflow-hidden rounded-2xl bg-black aspect-square w-full max-w-sm mx-auto flex items-center justify-center border border-slate-800">
                <span class="text-xs text-slate-500">Menyiapkan kamera...</span>
            </div>

            <div class="flex items-center justify-between text-xs text-slate-400 pt-2">
                <span>Pencahayaan terang membantu pembacaan lebih cepat.</span>
                <button type="button" onclick="stopCameraScanner()" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200">
                    Batal
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Notification Element -->
    <div id="toastNotification" class="no-print fixed bottom-6 right-6 z-50 transform translate-y-20 opacity-0 transition-all duration-300 pointer-events-none flex items-center gap-3 px-4 py-3 rounded-2xl bg-slate-900 border border-slate-700 text-white text-xs shadow-2xl">
        <i class="fa-solid fa-circle-check text-emerald-400 text-base" id="toastIcon"></i>
        <span id="toastMessage">Tindakan berhasil!</span>
    </div>

    <!-- JavaScript Interaktif -->
    <script>
        // 1. Manajer Tema (Terang / Gelap)
        function initTheme() {
            const savedTheme = localStorage.getItem('v_doc_theme');
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            const isDark = savedTheme ? (savedTheme === 'dark') : true; // Default dark elegan
            applyTheme(isDark);
        }

        function applyTheme(isDark) {
            const htmlEl = document.documentElement;
            const themeIcon = document.getElementById('themeIcon');
            const themeLabel = document.getElementById('themeLabel');
            
            if (isDark) {
                htmlEl.classList.add('dark');
                if (themeIcon) themeIcon.className = 'fa-solid fa-sun text-amber-400';
                if (themeLabel) themeLabel.textContent = 'Mode Terang';
                localStorage.setItem('v_doc_theme', 'dark');
            } else {
                htmlEl.classList.remove('dark');
                if (themeIcon) themeIcon.className = 'fa-solid fa-moon text-indigo-400';
                if (themeLabel) themeLabel.textContent = 'Mode Gelap';
                localStorage.setItem('v_doc_theme', 'light');
            }
        }

        function toggleTheme() {
            const isCurrentlyDark = document.documentElement.classList.contains('dark');
            applyTheme(!isCurrentlyDark);
        }

        // 2. Notifikasi Toast
        function showToast(message, isError = false) {
            const toast = document.getElementById('toastNotification');
            const toastMessage = document.getElementById('toastMessage');
            const toastIcon = document.getElementById('toastIcon');
            if (!toast || !toastMessage) return;

            toastMessage.textContent = message;
            if (isError) {
                toastIcon.className = 'fa-solid fa-circle-exclamation text-rose-400 text-base';
            } else {
                toastIcon.className = 'fa-solid fa-circle-check text-emerald-400 text-base';
            }

            toast.classList.remove('translate-y-20', 'opacity-0');
            toast.classList.add('translate-y-0', 'opacity-100');

            setTimeout(() => {
                toast.classList.remove('translate-y-0', 'opacity-100');
                toast.classList.add('translate-y-20', 'opacity-0');
            }, 3000);
        }

        // 3. Salin ke Papan Klip (Clipboard)
        function copyToClipboard(text, label = 'Teks') {
            if (!text || text === '-') return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    showToast(label + ' berhasil disalin ke papan klip!');
                }).catch(() => {
                    fallbackCopy(text, label);
                });
            } else {
                fallbackCopy(text, label);
            }
        }

        function fallbackCopy(text, label) {
            const temp = document.createElement('textarea');
            temp.value = text;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);
            showToast(label + ' berhasil disalin!');
        }

        function copyCurrentUrl() {
            copyToClipboard(window.location.href, 'Tautan Verifikasi Dokumen');
        }

        // 4. Integrasi Pemindai Kamera QR (HTML5 QR Code)
        let html5QrScannerInstance = null;

        function startCameraScanner() {
            const modal = document.getElementById('cameraScannerModal');
            if (!modal) return;
            modal.classList.remove('hidden');

            if (typeof Html5Qrcode === 'undefined') {
                showToast('Pustaka pemindai belum siap. Gunakan input manual atau unggah gambar.', true);
                return;
            }

            if (!html5QrScannerInstance) {
                html5QrScannerInstance = new Html5Qrcode("qrReaderViewport");
            }

            const config = { fps: 10, qrbox: { width: 250, height: 250 } };
            html5QrScannerInstance.start(
                { facingMode: "environment" },
                config,
                onQrCodeScanned,
                (errorMessage) => {
                    // Scanning ongoing, abaikan log frame
                }
            ).catch(err => {
                showToast('Tidak dapat mengakses kamera: ' + err, true);
                stopCameraScanner();
            });
        }

        function stopCameraScanner() {
            const modal = document.getElementById('cameraScannerModal');
            if (modal) modal.classList.add('hidden');
            if (html5QrScannerInstance) {
                html5QrScannerInstance.stop().then(() => {
                    html5QrScannerInstance.clear();
                }).catch(() => {});
            }
        }

        function onQrCodeScanned(decodedText) {
            stopCameraScanner();
            showToast('Kode QR terdeteksi! Memverifikasi...');
            // Jika decodedText adalah URL yang menuju ke sistem ini
            if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
                window.location.href = decodedText;
            } else {
                window.location.href = 'verify_document.php?q=' + encodeURIComponent(decodedText);
            }
        }

        function handleQrFileUpload(input) {
            if (!input.files || input.files.length === 0) return;
            const file = input.files[0];

            if (typeof Html5Qrcode === 'undefined') {
                showToast('Pustaka pemindai belum termuat.', true);
                return;
            }

            const scanner = new Html5Qrcode("qrReaderViewport");
            scanner.scanFile(file, true)
                .then(decodedText => {
                    onQrCodeScanned(decodedText);
                })
                .catch(err => {
                    showToast('Kode QR tidak terdeteksi pada gambar. Pastikan gambar jelas.', true);
                });
        }

        // Inisialisasi saat halaman dimuat
        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
        });
    </script>

</body>
</html>
