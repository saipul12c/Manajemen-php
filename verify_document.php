<?php
session_start();
require_once __DIR__ . "/config/database.php";

$school = getSchoolSettings($pdo);

$doc_type = htmlspecialchars($_GET['doc_type'] ?? 'DOKUMEN RESMI SEKOLAH');
$doc_num = htmlspecialchars($_GET['doc_num'] ?? '421.3 / REG / ' . date('Y'));
$doc_title = htmlspecialchars($_GET['doc_title'] ?? 'Surat / Dokumen Pelayanan Administrasi Sekolah');
$student_name = htmlspecialchars($_GET['student_name'] ?? '');
$issue_date = htmlspecialchars($_GET['date'] ?? date('d F Y'));
$signer_role = htmlspecialchars($_GET['signer_role'] ?? 'Kepala Sekolah');
$signer_name = htmlspecialchars($_GET['signer_name'] ?? ($school['headmaster_name'] ?? 'Kepala Sekolah'));
$signer_nip = htmlspecialchars($_GET['signer_nip'] ?? ($school['headmaster_nip'] ?? '-'));
$raw_hash = trim($_GET['hash'] ?? '');
$is_doc_valid = false;
$verify_error = "";

// Clean readable label
$doc_type_labels = [
    'SURAT_KETERANGAN' => 'Surat Keterangan Sekolah',
    'SKBB' => 'Surat Keterangan Berkelakuan Baik (SKBB)',
    'SKL' => 'Surat Keterangan Lulus (SKL)',
    'SPT' => 'Surat Perintah Tugas (SPT Dinas Guru/Staf)',
    'KARTU_UJIAN' => 'Kartu Peserta Ujian Resmi (Smart Clearance)',
    'RAPOR' => 'Laporan Hasil Belajar Siswa (Buku Rapor Digital)',
    'SK_DISIPLIN_DO' => 'Surat Keputusan Pengembalian Siswa ke Orang Tua (DO)',
    'SP_BK' => 'Surat Pembinaan / Peringatan BK',
    'SLIP_GAJI' => 'Slip Gaji & Honorarium Pendidik',
    'BUKU_INDUK' => 'Lembar Biodata Buku Induk Siswa (Master Archive)',
    'BEBAS_PUSTAKA' => 'Surat Bebas Pustaka / Perpustakaan Resmi',
    'KUITANSI_SPP' => 'Bukti Pembayaran / Kuitansi Sah SPP',
    'LAPORAN_KEUANGAN' => 'Buku Kas Umum / Laporan Realisasi Keuangan',
    'REKAP_PRESENSI' => 'Rekapitulasi Kehadiran & Presensi Akademik',
    'DOKUMEN_UJIAN' => 'Naskah & Kisi-Kisi Ujian Terverifikasi',
    'KARTU_PELAJAR' => 'Kartu Tanda Siswa / Kartu Pelajar Digital Resmi',
    'PIAGAM_PRESTASI' => 'Piagam Penghargaan & Sertifikat Prestasi Siswa',
    'LEGALISIR' => 'Pengesahan Legalisir Digital Ijazah & Dokumen Akademik Sah'
];

$display_type = $doc_type_labels[$doc_type] ?? $doc_type;

// Cek database jika tipe dokumen adalah LEGALISIR
$legalisir_record = null;
if ($doc_type === 'LEGALISIR') {
    if (empty($doc_num)) {
        $verify_error = "Nomor kode verifikasi legalisir tidak disertakan.";
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
            } else {
                $verify_error = "Kode legalisir tidak ditemukan pada basis data arsip resmi sekolah.";
            }
        } catch (Exception $e) {
            $verify_error = "Gagal memverifikasi catatan legalisir.";
        }
    }
} else {
    // Validasi hash tanda tangan digital TTE
    if (empty($raw_hash)) {
        $verify_error = "Token tanda tangan digital (hash TTE) tidak disertakan pada tautan verifikasi.";
    } else {
        $expected_hash_1 = strtoupper(substr(md5($doc_num . $signer_nip . 'SEKOLAH_AUTH_TTE'), 0, 16));
        $expected_hash_2 = strtoupper(substr(md5($doc_num . ($school['headmaster_nip'] ?? '') . 'SEKOLAH_AUTH_TTE'), 0, 16));
        $expected_hash_3 = strtoupper(substr(md5($doc_num . $doc_type . 'SEKOLAH_AUTH_SALT'), 0, 20));

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
                        $student_name = $req_row['real_name']; // Gunakan nama valid dari basis data
                    }
                } catch (Exception $e) {}
            }

            // 3. Cek Kuitansi Pembayaran (student_bills)
            if (!$db_found && (str_contains($doc_type, 'KUITANSI') || str_contains($doc_type, 'SPP'))) {
                if (preg_match('/(\d+)$/', $doc_num, $m_bill)) {
                    try {
                        $bill_id = (int)$m_bill[1];
                        $stmt_b = $pdo->prepare("SELECT b.*, u.name as real_name FROM student_bills b JOIN users u ON b.student_id = u.id WHERE b.id = ? AND b.status = 'lunas'");
                        $stmt_b->execute([$bill_id]);
                        $b_row = $stmt_b->fetch();
                        if ($b_row) {
                            $db_found = true;
                            $student_name = $b_row['real_name'];
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
                    } else {
                        // Cek data pendaftar PPDB
                        $stmt_ppdb = $pdo->prepare("SELECT full_name FROM ppdb_registrations WHERE full_name = ? LIMIT 1");
                        $stmt_ppdb->execute([$student_name]);
                        if ($stmt_ppdb->fetch()) {
                            $db_found = true;
                        }
                    }
                } catch (Exception $e) {}
            }

            if ($db_found) {
                $is_doc_valid = true;
            } else {
                $verify_error = "Catatan berkas, nomor dokumen, atau subjek terkait tidak ditemukan dalam pangkalan data arsip resmi sekolah.";
            }
        }
    }
}
$hash = htmlspecialchars(!empty($raw_hash) ? $raw_hash : '-');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Dokumen Resmi TTE - <?= htmlspecialchars($school['school_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <style>
        * {
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.35) transparent;
        }
        ::-webkit-scrollbar, *::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track, *::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb, *::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3);
            border-radius: 9999px;
            transition: background-color 0.2s ease;
        }
        ::-webkit-scrollbar-thumb:hover, *::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.6);
        }
        ::-webkit-scrollbar-corner, *::-webkit-scrollbar-corner {
            background: transparent;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col justify-between p-4 sm:p-8 font-sans antialiased selection:bg-blue-500 selection:text-white">

    <div class="max-w-2xl mx-auto w-full my-auto py-6">
        
        <!-- Header Identitas Sekolah -->
        <div class="text-center mb-6">
            <div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white text-2xl shadow-xl shadow-blue-500/20 mb-3 border border-white/20">
                <i class="fa-solid fa-school"></i>
            </div>
            <h2 class="text-xl sm:text-2xl font-black uppercase tracking-tight text-white">
                <?= htmlspecialchars($school['school_name']) ?>
            </h2>
            <p class="text-xs text-slate-400 mt-1 max-w-lg mx-auto">
                <?= htmlspecialchars($school['school_address']) ?>
            </p>
            <p class="text-[11px] text-slate-500 font-mono mt-0.5">
                Telp: <?= htmlspecialchars($school['school_phone']) ?> | Pos-el: <?= htmlspecialchars($school['school_email']) ?>
            </p>
        </div>

        <!-- Kartu Sertifikat Keaslian Dokumen (TTE) -->
        <div class="relative overflow-hidden rounded-3xl border <?= $is_doc_valid ? 'border-emerald-500/30' : 'border-rose-500/30' ?> bg-slate-900/90 p-6 sm:p-8 shadow-2xl backdrop-blur">
            <!-- Background Watermark Glow -->
            <div class="absolute -right-16 -top-16 h-56 w-56 rounded-full <?= $is_doc_valid ? 'bg-emerald-500/10' : 'bg-rose-500/10' ?> blur-3xl pointer-events-none"></div>
            <div class="absolute -left-16 -bottom-16 h-56 w-56 rounded-full bg-blue-500/10 blur-3xl pointer-events-none"></div>

            <!-- Banner Terverifikasi / Tidak Sah -->
            <div class="flex items-center gap-4 border-b border-white/10 pb-5 mb-6">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl <?= $is_doc_valid ? 'bg-emerald-500/20 border border-emerald-500/40 text-emerald-400 shadow-emerald-500/20' : 'bg-rose-500/20 border border-rose-500/40 text-rose-400 shadow-rose-500/20' ?> text-2xl shadow-lg">
                    <i class="fa-solid <?= $is_doc_valid ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                </div>
                <div>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider <?= $is_doc_valid ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-rose-500/20 text-rose-300 border border-rose-500/30' ?>">
                        <i class="fa-solid <?= $is_doc_valid ? 'fa-shield-halved' : 'fa-shield-xmark' ?> text-[9px]"></i> <?= $is_doc_valid ? 'Tanda Tangan Elektronik Sah' : 'Verifikasi Gagal' ?>
                    </span>
                    <h1 class="text-lg sm:text-xl font-bold text-white mt-1">
                        <?= $is_doc_valid ? 'Dokumen Resmi Terverifikasi Sah' : 'Keaslian Dokumen Tidak Terverifikasi' ?>
                    </h1>
                    <p class="text-xs <?= $is_doc_valid ? 'text-slate-400' : 'text-rose-400 font-medium' ?>">
                        <?= $is_doc_valid ? 'Dokumen digital ini terdaftar dalam Pangkalan Data Arsip Resmi Lembaga.' : htmlspecialchars($verify_error) ?>
                    </p>
                </div>
            </div>

            <!-- Rincian Metadata Dokumen -->
            <div class="space-y-3.5 text-xs sm:text-sm">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3 rounded-xl bg-slate-950/60 border border-white/5 gap-1">
                    <span class="text-slate-400">Jenis Dokumen:</span>
                    <strong class="text-white font-semibold text-right"><?= $display_type ?></strong>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3 rounded-xl bg-slate-950/60 border border-white/5 gap-1">
                    <span class="text-slate-400">Nomor Registrasi / Berkas:</span>
                    <strong class="text-cyan-300 font-mono text-right"><?= $doc_num ?></strong>
                </div>

                <?php if (!empty($student_name)): ?>
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3 rounded-xl bg-slate-950/60 border border-white/5 gap-1">
                        <span class="text-slate-400">Nama Terkait / Subjek:</span>
                        <strong class="text-white font-bold text-right"><?= $student_name ?></strong>
                    </div>
                <?php endif; ?>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between p-3 rounded-xl bg-slate-950/60 border border-white/5 gap-1">
                    <span class="text-slate-400">Tanggal Pengesahan:</span>
                    <span class="text-slate-200 font-medium text-right"><?= $issue_date ?></span>
                </div>

                <!-- Blok Penandatangan -->
                <div class="p-4 rounded-2xl bg-blue-950/20 border border-blue-500/20 mt-4 space-y-2">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-blue-400 block mb-1">
                        <i class="fa-solid fa-signature mr-1"></i> Ditandatangani Secara Elektronik Oleh:
                    </span>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-base font-bold text-white"><?= $signer_name ?></p>
                            <p class="text-xs text-slate-400 font-mono">NIP. <?= $signer_nip ?></p>
                            <p class="text-[11px] text-blue-300 font-semibold mt-0.5"><?= $signer_role ?> - <?= htmlspecialchars($school['school_name']) ?></p>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-blue-500/40 bg-blue-500/20 px-3 py-1.5 text-xs font-bold text-blue-200">
                                <i class="fa-solid fa-stamp text-xs"></i> TERAKREDITASI
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Integritas Kriptografis / Hash -->
                <div class="p-3 rounded-xl bg-slate-950/80 border border-white/10 font-mono text-[11px] flex flex-col sm:flex-row sm:items-center justify-between gap-2 mt-4">
                    <span class="text-slate-400 flex items-center gap-1.5">
                        <i class="fa-solid fa-fingerprint text-purple-400"></i> SHA / Digital Hash:
                    </span>
                    <span class="text-purple-300 font-bold tracking-wider truncate max-w-xs"><?= $hash ?></span>
                </div>
            </div>

            <!-- Footer Card -->
            <div class="mt-6 pt-4 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-3 text-[11px] text-slate-400">
                <p>
                    <i class="fa-solid fa-lock text-emerald-400 mr-1"></i> Keabsahan dokumen dilindungi UU ITE & Sistem Informasi Sekolah.
                </p>
                <a href="index.php" class="text-blue-400 hover:text-blue-300 font-semibold flex items-center gap-1">
                    Portal Sekolah <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </a>
            </div>
        </div>

        <p class="text-center text-xs text-slate-500 mt-6">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($school['school_name']) ?>. Seluruh hak cipta dilindungi.
        </p>

    </div>

</body>
</html>
