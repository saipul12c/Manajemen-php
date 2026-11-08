<?php
/**
 * PPDB Online - Portal Pendaftaran Siswa Baru TA 2026/2027
 * Portal pendaftaran mandiri calon siswa, upload berkas terproteksi, kalkulasi skor otomatis, dan pelacakan status seleksi berkeamanan tinggi.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/config/database.php';

// Ambil Konfigurasi Sekolah & PPDB
$school_info = getSchoolSettings($pdo);
$ppdb_status = $school_info['ppdb_status'] ?? 'buka';
$ppdb_wave_name = $school_info['ppdb_wave_name'] ?? 'Gelombang 1';
$ppdb_quota = (int)($school_info['ppdb_quota'] ?? 150);
$ppdb_start_date = $school_info['ppdb_start_date'] ?? '';
$ppdb_end_date = $school_info['ppdb_end_date'] ?? '';
$ppdb_closed_message = $school_info['ppdb_closed_message'] ?? 'Pendaftaran Peserta Didik Baru (PPDB) saat ini sedang ditutup atau batas kuota telah terpenuhi.';
$ppdb_next_wave_name = $school_info['ppdb_next_wave_name'] ?? 'Gelombang 2 (Jalur Reguler & Zonasi)';
$ppdb_next_open_date = $school_info['ppdb_next_open_date'] ?? '2026-07-01';
$ppdb_announcement_date = $school_info['ppdb_announcement_date'] ?? '18 Juli 2026';
$ppdb_re_registration_date = $school_info['ppdb_re_registration_date'] ?? '20 - 25 Juli 2026';
$ppdb_contact_phone = $school_info['ppdb_contact_phone'] ?? ($school_info['school_phone'] ?? '081234567890');

// Clean WhatsApp hotline
$wa_ppdb_hotline = preg_replace('/[^0-9]/', '', $ppdb_contact_phone);
if (!empty($wa_ppdb_hotline) && substr($wa_ppdb_hotline, 0, 1) === '0') {
    $wa_ppdb_hotline = '62' . substr($wa_ppdb_hotline, 1);
}
if (empty($wa_ppdb_hotline)) {
    $wa_ppdb_hotline = '6281234567890';
}

// Hitung Jumlah Pendaftar Saat Ini
$total_registrants = (int)$pdo->query("SELECT COUNT(*) FROM ppdb_registrations")->fetchColumn();

// Validasi Status Buka/Tutup, Jadwal, dan Kuota
$is_registration_allowed = true;
$closed_reason = "";

$today = date('Y-m-d');
if ($ppdb_status !== 'buka') {
    $is_registration_allowed = false;
    $closed_reason = $ppdb_closed_message;
} elseif (!empty($ppdb_start_date) && $today < $ppdb_start_date) {
    $is_registration_allowed = false;
    $closed_reason = "Pendaftaran PPDB {$ppdb_wave_name} belum dibuka. Pendaftaran akan resmi dimulai pada " . date('d F Y', strtotime($ppdb_start_date)) . ".";
} elseif (!empty($ppdb_end_date) && $today > $ppdb_end_date) {
    $is_registration_allowed = false;
    $closed_reason = "Pendaftaran PPDB {$ppdb_wave_name} telah resmi ditutup pada " . date('d F Y', strtotime($ppdb_end_date)) . ".";
} elseif ($ppdb_quota > 0 && $total_registrants >= $ppdb_quota) {
    $is_registration_allowed = false;
    $closed_reason = "Batas kuota pendaftaran {$ppdb_wave_name} telah terpenuhi ({$total_registrants}/{$ppdb_quota} kursi). Pendaftaran online telah ditutup.";
}

$active_tab = $_GET['tab'] ?? ($is_registration_allowed ? 'daftar' : 'cek');
$success_reg = null;
$success_msg = null;
$error_msg = null;
$search_result = null;

/**
 * Helper Validasi & Upload Berkas PPDB Terproteksi Terpusat (Strip EXIF & Decompression Bomb Protection)
 */
function handlePpdbUpload(array $file, string $field_key, string $upload_dir): ?string {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    $allowed_exts = ['pdf', 'jpg', 'jpeg', 'png'];
    $res = saveSecureUpload(
        $file,
        'ppdb',
        $allowed_exts,
        [],
        5 * 1024 * 1024,
        $field_key
    );
    
    if ($res['success']) {
        return $res['path'];
    }
    return null;
}

// -------------------------------------------------------------
// 1. HANDLE FORM SUBMIT PENDAFTARAN BARU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_ppdb') {
    if (!$is_registration_allowed) {
        $error_msg = $closed_reason ?: 'Mohon maaf, pendaftaran PPDB saat ini sedang ditutup.';
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        // Biodata Siswa
        $full_name       = trim($_POST['full_name'] ?? '');
        $nisn            = trim($_POST['nisn'] ?? '');
        $nik             = trim($_POST['nik'] ?? '');
        $gender          = in_array($_POST['gender'] ?? '', ['L', 'P'], true) ? $_POST['gender'] : 'L';
        $birth_place     = trim($_POST['birth_place'] ?? '');
        $birth_date      = trim($_POST['birth_date'] ?? '');
        $religion        = trim($_POST['religion'] ?? 'Islam');
        $phone           = trim($_POST['phone'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $address         = trim($_POST['address'] ?? '');

        // Peminatan & Jalur
        $previous_school   = trim($_POST['previous_school'] ?? '');
        $chosen_major      = trim($_POST['chosen_major'] ?? 'MIPA (Matematika & IPA)');
        $track_type        = in_array($_POST['track_type'] ?? '', array_keys(PPDB_TRACKS), true) ? $_POST['track_type'] : 'reguler';
        $distance_km       = ($_POST['distance_km'] ?? '') !== '' ? (float)$_POST['distance_km'] : null;
        $achievement_desc  = trim($_POST['achievement_desc'] ?? '') ?: null;
        $achievement_level = in_array($_POST['achievement_level'] ?? '', ['sekolah', 'kecamatan', 'kabupaten', 'provinsi', 'nasional', 'internasional'], true) ? $_POST['achievement_level'] : null;
        $affirmation_no    = trim($_POST['affirmation_no'] ?? '') ?: null;

        // Nilai Rapor
        $score_math        = ($_POST['score_math'] ?? '') !== '' ? (float)$_POST['score_math'] : null;
        $score_science     = ($_POST['score_science'] ?? '') !== '' ? (float)$_POST['score_science'] : null;
        $score_indonesian  = ($_POST['score_indonesian'] ?? '') !== '' ? (float)$_POST['score_indonesian'] : null;
        $score_english     = ($_POST['score_english'] ?? '') !== '' ? (float)$_POST['score_english'] : null;

        // Data Sertifikat Prestasi (Maksimal 10)
        $level_ranks = [
            'internasional' => 6,
            'nasional'      => 5,
            'provinsi'      => 4,
            'kabupaten'     => 3,
            'kecamatan'     => 2,
            'sekolah'       => 1
        ];

        $achievements_data = [];
        $achievement_desc = null;
        $achievement_level = null;
        $highest_level_rank = 0;

        if (isset($_POST['ach_desc']) && is_array($_POST['ach_desc'])) {
            $total_certs = min(10, count($_POST['ach_desc']));
            for ($ci = 0; $ci < $total_certs; $ci++) {
                $cd = trim($_POST['ach_desc'][$ci] ?? '');
                $cl = trim($_POST['ach_level'][$ci] ?? 'sekolah');
                if (!array_key_exists($cl, $level_ranks)) {
                    $cl = 'sekolah';
                }
                if ($cd !== '') {
                    $rank = $level_ranks[$cl] ?? 1;
                    if ($rank > $highest_level_rank) {
                        $highest_level_rank = $rank;
                        $achievement_level = $cl;
                        $achievement_desc = $cd;
                    }
                    $achievements_data[] = [
                        'index' => $ci,
                        'title' => $cd,
                        'level' => $cl,
                        'file'  => null
                    ];
                }
            }
        } elseif (!empty($_POST['achievement_desc'])) {
            $achievement_desc = trim($_POST['achievement_desc']);
            $achievement_level = in_array($_POST['achievement_level'] ?? '', array_keys($level_ranks), true) ? $_POST['achievement_level'] : 'sekolah';
            $achievements_data[] = [
                'index' => 0,
                'title' => $achievement_desc,
                'level' => $achievement_level,
                'file'  => null
            ];
        }

        // Hitung Otomatis Skor Seleksi (berdasarkan tingkat prestasi tertinggi)
        $calculated_score = null;
        if ($score_math !== null && $score_science !== null && $score_indonesian !== null && $score_english !== null) {
            $calculated_score = calculatePpdbScore($score_math, $score_science, $score_indonesian, $score_english, $track_type, $achievement_level, $distance_km);
        }

        // Data Orang Tua / Wali
        $parent_name     = trim($_POST['parent_name'] ?? '');
        $parent_phone    = trim($_POST['parent_phone'] ?? '');
        $parent_job      = trim($_POST['parent_job'] ?? '');

        if (empty($full_name) || empty($nisn) || empty($birth_place) || empty($birth_date) || empty($phone) || empty($email) || empty($address) || empty($previous_school) || empty($parent_name) || empty($parent_phone)) {
            $error_msg = 'Harap lengkapi semua kolom biodata wajib bertanda bintang (*).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = 'Format alamat email tidak valid.';
        } elseif (strlen($nisn) !== 10 || !ctype_digit($nisn)) {
            $error_msg = 'NISN harus terdiri dari tepat 10 digit angka.';
        } else {
            try {
                // Cek apakah NISN sudah pernah mendaftar
                $stmt_check = $pdo->prepare("SELECT id, registration_no FROM ppdb_registrations WHERE nisn = ?");
                $stmt_check->execute([$nisn]);
                $existing = $stmt_check->fetch();

                if ($existing) {
                    $error_msg = "NISN {$nisn} sudah terdaftar dengan Nomor Registrasi: {$existing['registration_no']}. Silakan gunakan menu Cek Status & Cetak Kartu.";
                    $active_tab = 'cek';
                } else {
                    $upload_dir = dirname(__DIR__) . '/uploads/ppdb';

                    // Upload Berkas Dokumen dengan verifikasi MIME
                    $uploaded_docs = [
                        'report_card_doc'      => isset($_FILES['report_card_doc']) ? handlePpdbUpload($_FILES['report_card_doc'], 'report_card_doc', $upload_dir) : null,
                        'birth_cert_doc'       => isset($_FILES['birth_cert_doc']) ? handlePpdbUpload($_FILES['birth_cert_doc'], 'birth_cert_doc', $upload_dir) : null,
                        'family_card_doc'      => isset($_FILES['family_card_doc']) ? handlePpdbUpload($_FILES['family_card_doc'], 'family_card_doc', $upload_dir) : null,
                        'photo_doc'            => isset($_FILES['photo_doc']) ? handlePpdbUpload($_FILES['photo_doc'], 'photo_doc', $upload_dir) : null,
                        'achievement_cert_doc' => isset($_FILES['achievement_cert_doc']) ? handlePpdbUpload($_FILES['achievement_cert_doc'], 'achievement_cert_doc', $upload_dir) : null,
                    ];

                    // Upload multiple file sertifikat (maksimal 10)
                    if (!empty($achievements_data)) {
                        foreach ($achievements_data as &$ach_item) {
                            $idx = $ach_item['index'];
                            if (isset($_FILES['ach_file']['name'][$idx]) && $_FILES['ach_file']['error'][$idx] === UPLOAD_ERR_OK) {
                                $single_f = [
                                    'name'     => $_FILES['ach_file']['name'][$idx],
                                    'type'     => $_FILES['ach_file']['type'][$idx],
                                    'tmp_name' => $_FILES['ach_file']['tmp_name'][$idx],
                                    'error'    => $_FILES['ach_file']['error'][$idx],
                                    'size'     => $_FILES['ach_file']['size'][$idx]
                                ];
                                $ach_item['file'] = handlePpdbUpload($single_f, 'cert_' . ($idx + 1), $upload_dir);
                            }
                            unset($ach_item['index']);
                        }
                        unset($ach_item);

                        // Ambil berkas sertifikat utama jika achievement_cert_doc masih kosong
                        if (empty($uploaded_docs['achievement_cert_doc'])) {
                            foreach ($achievements_data as $ach_item) {
                                if (!empty($ach_item['file'])) {
                                    $uploaded_docs['achievement_cert_doc'] = $ach_item['file'];
                                    break;
                                }
                            }
                        }
                    }

                    $achievements_json = !empty($achievements_data) ? json_encode($achievements_data, JSON_UNESCAPED_UNICODE) : null;

                    // Generate Nomor Registrasi Unik Bebas Race-Condition (via Transaction + LAST_INSERT_ID)
                    $pdo->beginTransaction();
                    try {
                        // Insert dulu dengan placeholder registration_no unik untuk menghindari duplicate key race condition
                        $temp_reg_no = 'PPDB-' . date('Y') . '-TMP-' . bin2hex(random_bytes(6));

                        // Insert ke Database
                        $stmt_ins = $pdo->prepare("
                            INSERT INTO ppdb_registrations 
                            (registration_no, full_name, nisn, nik, gender, birth_place, birth_date, religion, phone, email, address, 
                             previous_school, chosen_major, track_type, distance_km, achievement_desc, achievement_level, affirmation_no,
                             score_math, score_science, score_indonesian, score_english, calculated_score,
                             parent_name, parent_phone, parent_job, 
                             report_card_doc, birth_cert_doc, family_card_doc, photo_doc, achievement_cert_doc, achievements_json,
                             document_status, status, selection_score, notes, qr_token)
                            VALUES
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                             ?, ?, ?, ?, ?, ?, ?,
                             ?, ?, ?, ?, ?,
                             ?, ?, ?, 
                             ?, ?, ?, ?, ?, ?,
                             'lengkap', 'menunggu_verifikasi', ?, 'Pendaftaran baru masuk secara online via portal PPDB.', ?)
                        ");

                        $stmt_ins->execute([
                            $temp_reg_no, $full_name, $nisn, $nik, $gender, $birth_place, $birth_date, $religion, $phone, $email, $address,
                            $previous_school, $chosen_major, $track_type, $distance_km, $achievement_desc, $achievement_level, $affirmation_no,
                            $score_math, $score_science, $score_indonesian, $score_english, $calculated_score,
                            $parent_name, $parent_phone, $parent_job,
                            $uploaded_docs['report_card_doc'], $uploaded_docs['birth_cert_doc'], $uploaded_docs['family_card_doc'], $uploaded_docs['photo_doc'], $uploaded_docs['achievement_cert_doc'], $achievements_json,
                            $calculated_score, ''
                        ]);

                        // Gunakan LAST_INSERT_ID() yang dijamin unik per-connection
                        $new_id = (int)$pdo->lastInsertId();
                        $reg_no = 'PPDB-' . date('Y') . '-' . str_pad((string)$new_id, 4, '0', STR_PAD_LEFT);

                        // Generate Token QR Code Resmi untuk Validasi Kartu
                        $qr_token = generatePpdbQrToken($reg_no);

                        // Update registration_no dan qr_token dengan nilai final
                        $stmt_upd_reg = $pdo->prepare("UPDATE ppdb_registrations SET registration_no = ?, qr_token = ? WHERE id = ?");
                        $stmt_upd_reg->execute([$reg_no, $qr_token, $new_id]);

                        $pdo->commit();
                    } catch (Exception $e_trx) {
                        $pdo->rollBack();
                        throw $e_trx;
                    }

                    logActivity($pdo, 'PPDB_REGISTER', "Calon siswa baru mendaftar: $full_name ($reg_no) via Jalur " . getPpdbTrackLabel($track_type));

                    $_SESSION['ppdb_verified_reg'] = $reg_no;

                    $success_reg = [
                        'reg_no'       => $reg_no,
                        'qr_token'     => $qr_token,
                        'name'         => $full_name,
                        'nisn'         => $nisn,
                        'chosen_major' => $chosen_major,
                        'track_type'   => $track_type,
                        'birth_date'   => $birth_date,
                        'score'        => $calculated_score
                    ];
                }
            } catch (Exception $e) {
                error_log("PPDB register error: " . $e->getMessage());
                $error_msg = 'Gagal menyimpan data pendaftaran. Pastikan data yang dimasukkan valid atau hubungi panitia PPDB.';
            }
        }
    }
}

// -------------------------------------------------------------
// 2. HANDLE UNGGAH ULANG / PERBAIKAN BERKAS MANDIRI OLEH SISWA
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reupload_ppdb_doc') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_msg = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        $reupload_reg_no = trim($_POST['registration_no'] ?? '');
        $reupload_birth_date = trim($_POST['birth_date'] ?? '');
        $upload_field = trim($_POST['upload_field'] ?? '');

        $valid_fields = ['report_card_doc', 'birth_cert_doc', 'family_card_doc', 'photo_doc', 'achievement_cert_doc'];
        if (!in_array($upload_field, $valid_fields, true)) {
            $error_msg = 'Jenis berkas yang diunggah tidak valid.';
        } else {
            try {
                $stmt_chk = $pdo->prepare("SELECT * FROM ppdb_registrations WHERE registration_no = ? AND birth_date = ? LIMIT 1");
                $stmt_chk->execute([$reupload_reg_no, $reupload_birth_date]);
                $target_reg = $stmt_chk->fetch();

                if (!$target_reg) {
                    $error_msg = 'Data pendaftaran tidak ditemukan atau tanggal lahir tidak cocok untuk otentikasi.';
                } else {
                    $upload_dir = dirname(__DIR__) . '/uploads/ppdb';
                    $new_file_path = isset($_FILES['replacement_file']) ? handlePpdbUpload($_FILES['replacement_file'], $upload_field, $upload_dir) : null;

                    if (!$new_file_path) {
                        $error_msg = 'Gagal mengunggah berkas. Pastikan format file PDF/JPG/PNG dan ukuran maksimal 5MB.';
                    } else {
                        $field_names_id = [
                            'report_card_doc'      => 'Rapor',
                            'birth_cert_doc'       => 'Akta Kelahiran',
                            'family_card_doc'      => 'Kartu Keluarga',
                            'photo_doc'            => 'Pas Foto 3x4',
                            'achievement_cert_doc' => 'Sertifikat Prestasi'
                        ];
                        $f_label = $field_names_id[$upload_field] ?? 'Dokumen';

                        // Update database: kembalikan status berkas ke lengkap & status verifikasi ke menunggu
                        $stmt_up_doc = $pdo->prepare("
                            UPDATE ppdb_registrations 
                            SET {$upload_field} = ?, document_status = 'lengkap', rejection_reason = NULL, status = 'menunggu_verifikasi',
                                notes = CONCAT(COALESCE(notes, ''), ' [Berkas $f_label diperbaiki pendaftar pada ', NOW(), ']')
                            WHERE id = ?
                        ");
                        $stmt_up_doc->execute([$new_file_path, $target_reg['id']]);

                        logActivity($pdo, 'PPDB_REUPLOAD', "Calon siswa {$target_reg['full_name']} mengunggah ulang berkas $f_label");

                        $success_msg = "Berkas $f_label pengganti berhasil diunggah! Panitia PPDB akan segera memverifikasi kembali dokumen Anda.";

                        // Muat ulang data hasil pencarian
                        $stmt_re = $pdo->prepare("
                            SELECT r.*, u.email as student_email, c.name as class_name 
                            FROM ppdb_registrations r
                            LEFT JOIN users u ON r.user_id = u.id
                            LEFT JOIN classes c ON u.class_id = c.id
                            WHERE r.id = ? LIMIT 1
                        ");
                        $stmt_re->execute([$target_reg['id']]);
                        $search_result = $stmt_re->fetch();
                        $active_tab = 'cek';
                    }
                }
            } catch (Exception $e) {
                error_log("PPDB upload error: " . $e->getMessage());
                $error_msg = 'Terjadi kesalahan pada server saat mengunggah berkas. Silakan coba beberapa saat lagi.';
            }
        }
    }
}

// -------------------------------------------------------------
// 3. HANDLE CEK STATUS PENDAFTARAN DENGAN PERLINDUNGAN PRIVASI
// -------------------------------------------------------------
if ($active_tab === 'cek' && isset($_GET['search'])) {
    $search_query = trim($_GET['search'] ?? '');
    $search_birth_date = trim($_GET['birth_date'] ?? '');

    if (empty($search_query) || empty($search_birth_date)) {
        $error_msg = "Demi privasi data calon siswa, harap masukkan Nomor Registrasi/NISN serta Tanggal Lahir yang sesuai.";
    } else {
        try {
            $stmt_search = $pdo->prepare("
                SELECT r.*, u.email as student_email, c.name as class_name 
                FROM ppdb_registrations r
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN classes c ON u.class_id = c.id
                WHERE (r.registration_no = ? OR r.nisn = ?) AND r.birth_date = ?
                LIMIT 1
            ");
            $stmt_search->execute([$search_query, $search_query, $search_birth_date]);
            $search_result = $stmt_search->fetch();

            if (!$search_result) {
                $error_msg = "Data pendaftaran dengan Nomor Registrasi / NISN '{$search_query}' dan tanggal lahir yang dimasukkan tidak ditemukan. Mohon periksa kembali input Anda.";
            } else {
                $_SESSION['ppdb_verified_reg'] = $search_result['registration_no'];
            }
        } catch (Exception $e) {
            $error_msg = 'Terjadi kesalahan saat mencari data.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PPDB Online 2026/2027 - Portal Penerimaan Siswa Baru</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-emerald-500 selection:text-white">

    <?php
    $base_path = '../';
    $nav_active = 'ppdb';
    require_once dirname(__DIR__) . '/includes/navbar.php';
    ?>

    <main class="mx-auto max-w-6xl px-4 sm:px-6 py-8 sm:py-12">

        <?php
        $percent_quota = $ppdb_quota > 0 ? min(100, round(($total_registrants / $ppdb_quota) * 100, 1)) : 0;
        $remaining_quota = max(0, $ppdb_quota - $total_registrants);
        ?>
        <!-- Hero Banner -->
        <div class="relative overflow-hidden rounded-3xl border border-emerald-500/20 bg-gradient-to-r from-emerald-950/40 via-slate-900 to-slate-900 p-6 sm:p-10 mb-8 backdrop-blur shadow-2xl">
            <div class="relative z-10 max-w-3xl">
                <div class="flex flex-wrap items-center gap-2 mb-4">
                    <div class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1 text-xs font-semibold <?= $is_registration_allowed ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-300' : 'border-rose-400/30 bg-rose-400/10 text-rose-300' ?>">
                        <span class="relative flex h-2 w-2">
                            <span class="<?= $is_registration_allowed ? 'animate-ping bg-emerald-400' : 'bg-rose-400' ?> absolute inline-flex h-full w-full rounded-full opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 <?= $is_registration_allowed ? 'bg-emerald-500' : 'bg-rose-500' ?>"></span>
                        </span>
                        <span><?= htmlspecialchars($ppdb_wave_name) ?> : <?= $is_registration_allowed ? 'Pendaftaran Dibuka' : 'Pendaftaran Ditutup' ?></span>
                    </div>

                    <div class="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-mono text-slate-300">
                        <i class="fa-solid fa-users text-slate-400 text-[11px]"></i>
                        <span>Kuota: <strong class="text-white"><?= $total_registrants ?></strong> / <?= $ppdb_quota ?> Kursi</span>
                    </div>
                </div>

                <h1 class="text-3xl sm:text-4xl md:text-5xl font-extrabold text-white tracking-tight leading-tight">
                    Penerimaan Peserta Didik Baru (PPDB) 2026/2027
                </h1>
                <p class="mt-3 text-sm sm:text-base text-slate-300 leading-relaxed">
                    Sistem pendaftaran calon siswa terpadu. Pilih jalur masuk (Reguler, Zonasi, Prestasi, Afirmasi), input nilai rapor, unggah berkas administrasi secara digital, dan pantau status seleksi Anda langsung dari halaman ini.
                </p>

                <!-- Quota Capacity Live Progress Bar -->
                <div class="mt-6 pt-5 border-t border-white/10 max-w-xl">
                    <div class="flex items-center justify-between text-xs mb-2">
                        <span class="text-slate-300 flex items-center gap-1.5 font-medium">
                            <i class="fa-solid fa-chart-pie text-emerald-400"></i> Kapasitas Kuota Penerimaan:
                        </span>
                        <span class="font-mono font-bold text-white">
                            <?= $percent_quota ?>% Terisi <span class="text-slate-400 font-normal">(Sisa <?= $remaining_quota ?> Kursi)</span>
                        </span>
                    </div>
                    <div class="w-full bg-slate-950/80 rounded-full h-2.5 overflow-hidden border border-white/5 p-0.5">
                        <div class="h-full rounded-full bg-gradient-to-r <?= $percent_quota >= 90 ? 'from-amber-500 to-rose-500' : 'from-emerald-500 to-teal-400' ?> transition-all duration-1000 shadow-sm" style="width: <?= $percent_quota ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Jalur Pendaftaran Information Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
            <?php foreach (PPDB_TRACKS as $t_key => $t_info): ?>
                <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4">
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold <?= $t_info['badge'] ?> px-2.5 py-0.5 rounded-lg mb-2">
                        <i class="fa-solid <?= $t_info['icon'] ?>"></i> <?= htmlspecialchars($t_info['label']) ?>
                    </span>
                    <p class="text-[11px] text-slate-400">
                        <?php 
                        switch ($t_key) {
                            case 'reguler': echo 'Berdasarkan nilai rapor 4 mata pelajaran pokok semester 1-5.'; break;
                            case 'zonasi':  echo 'Prioritas jarak tempat tinggal terdekat dari lokasi sekolah (KM).'; break;
                            case 'prestasi': echo 'Tambahan bobot skor untuk sertifikat akademik/non-akademik resmi.'; break;
                            case 'afirmasi': echo 'Khusus siswa pemegang kartu KIP/PKH atau bantuan sosial resmi.'; break;
                        }
                        ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Success Registration Banner -->
        <?php if ($success_reg): ?>
            <div class="mb-8 rounded-3xl border border-emerald-500/30 bg-emerald-500/10 p-6 sm:p-8 backdrop-blur">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-6">
                    <div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-300 mb-2">
                            <i class="fa-solid fa-circle-check"></i> Pendaftaran Berhasil Dikirim!
                        </span>
                        <h2 class="text-2xl font-bold text-white">Nomor Registrasi Anda:</h2>
                        <div class="mt-2 inline-block rounded-2xl border border-emerald-400/30 bg-slate-950/80 px-5 py-2.5 font-mono text-2xl sm:text-3xl font-black text-emerald-400 tracking-wider">
                            <?= htmlspecialchars($success_reg['reg_no']) ?>
                        </div>
                        <p class="mt-3 text-sm text-slate-300">
                            Nama: <strong class="text-white"><?= htmlspecialchars($success_reg['name']) ?></strong> | 
                            NISN: <strong class="text-white"><?= htmlspecialchars($success_reg['nisn']) ?></strong> | 
                            Jalur: <strong class="text-emerald-400"><?= htmlspecialchars(getPpdbTrackLabel($success_reg['track_type'])) ?></strong> | 
                            Peminatan: <strong class="text-white"><?= htmlspecialchars($success_reg['chosen_major']) ?></strong>
                        </p>
                        <?php if ($success_reg['score'] !== null): ?>
                            <p class="mt-1 text-xs text-emerald-300 font-semibold">
                                <i class="fa-solid fa-calculator mr-1"></i> Estimasi Skor Awal Rapor: <?= number_format((float)$success_reg['score'], 2) ?> / 100
                            </p>
                        <?php endif; ?>
                        <p class="mt-2 text-xs text-slate-400">
                            Simpan nomor registrasi di atas beserta tanggal lahir Anda untuk mencetak kartu pendaftaran dan melacak hasil pengumuman panitia seleksi.
                        </p>
                    </div>
                    <div class="flex flex-col gap-2 shrink-0 w-full sm:w-auto">
                        <a href="ppdb_card.php?reg=<?= urlencode($success_reg['reg_no']) ?>&token=<?= urlencode($success_reg['qr_token'] ?? '') ?>" target="_blank"
                           class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-6 py-3 font-semibold text-white shadow-lg shadow-emerald-500/20 transition">
                            <i class="fa-solid fa-print"></i> Cetak Kartu Pendaftaran
                        </a>
                        <a href="ppdb.php?tab=cek&search=<?= urlencode($success_reg['reg_no']) ?>&birth_date=<?= urlencode($success_reg['birth_date']) ?>"
                           class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-6 py-2.5 text-sm font-semibold text-slate-200 transition">
                            <i class="fa-solid fa-magnifying-glass"></i> Cek Status Seleksi
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Message Alert -->
        <?php if ($success_msg): ?>
            <div class="mb-8 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-300 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="text-xl"><i class="fa-solid fa-circle-check text-emerald-400"></i></span>
                    <span><?= htmlspecialchars($success_msg) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Message Alert -->
        <?php if ($error_msg): ?>
            <div class="mb-8 rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-300 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="text-xl"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <span><?= htmlspecialchars($error_msg) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Navigation Tabs -->
        <div class="flex items-center border-b border-white/10 mb-8">
            <a href="ppdb.php?tab=daftar" 
               class="flex items-center gap-2 border-b-2 px-6 py-3.5 text-sm font-bold transition <?= $active_tab === 'daftar' ? 'border-emerald-500 text-emerald-400 bg-white/5 rounded-t-xl' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-file-signature"></i> Formulir Pendaftaran <?= $is_registration_allowed ? 'Baru' : '(Tutup)' ?>
            </a>
            <a href="ppdb.php?tab=cek" 
               class="flex items-center gap-2 border-b-2 px-6 py-3.5 text-sm font-bold transition <?= $active_tab === 'cek' ? 'border-emerald-500 text-emerald-400 bg-white/5 rounded-t-xl' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-magnifying-glass"></i> Cek Status & Cetak Kartu
            </a>
        </div>

        <?php if ($active_tab === 'daftar'): ?>
            <?php if (!$is_registration_allowed): ?>
                <!-- TAMPILAN RESMI PROFESIONAL: PENDAFTARAN DITUTUP / JADWAL GELOMBANG BERIKUTNYA -->
                <div class="max-w-4xl mx-auto space-y-6">

                    <!-- 1. Hero Notice Card -->
                    <div class="relative overflow-hidden rounded-3xl border border-amber-500/30 bg-gradient-to-br from-amber-500/10 via-slate-900/90 to-slate-950 p-7 sm:p-10 shadow-2xl backdrop-blur">
                        <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-amber-500/10 blur-3xl pointer-events-none"></div>

                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5 pb-6 border-b border-white/10">
                            <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-amber-500/20 text-amber-400 text-3xl border border-amber-500/30 shadow-lg shadow-amber-500/10">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <div>
                                <div class="flex flex-wrap items-center gap-2 mb-1.5">
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-500/20 text-rose-300 border border-rose-500/30 px-3 py-0.5 text-xs font-bold">
                                        <span class="h-1.5 w-1.5 rounded-full bg-rose-400"></span>
                                        Pendaftaran Ditutup
                                    </span>
                                    <span class="text-xs text-slate-400 font-medium">
                                        <?= htmlspecialchars($ppdb_wave_name) ?>
                                    </span>
                                </div>
                                <h2 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
                                    Penerimaan Berkas Periode Ini Telah Ditutup
                                </h2>
                                <p class="mt-2 text-xs sm:text-sm text-slate-300 leading-relaxed">
                                    <?= htmlspecialchars($closed_reason) ?>
                                </p>
                            </div>
                        </div>

                        <!-- Bar Status Transparan -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-6 text-xs">
                            <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                                <span class="text-slate-400 block mb-0.5">Total Pendaftar Masuk:</span>
                                <span class="text-base font-bold text-white"><?= number_format($total_registrants, 0, ',', '.') ?> Berkas Calon Siswa</span>
                            </div>
                            <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                                <span class="text-slate-400 block mb-0.5">Kapasitas Daya Tampung:</span>
                                <span class="text-base font-bold text-amber-300"><?= number_format($ppdb_quota, 0, ',', '.') ?> Kursi (Penuh)</span>
                            </div>
                            <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                                <span class="text-slate-400 block mb-0.5">Status Pendaftar Lama:</span>
                                <span class="text-base font-bold text-emerald-400">Tahap Ujian & Seleksi</span>
                            </div>
                        </div>
                    </div>

                    <!-- 2. Kapan Dibuka Lagi? Kartu Jadwal Gelombang Berikutnya -->
                    <div class="rounded-3xl border border-blue-500/30 bg-gradient-to-br from-blue-950/40 via-slate-900/80 to-slate-900 p-6 sm:p-8 shadow-xl">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-white/10">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400 text-lg">
                                    <i class="fa-solid fa-calendar-check"></i>
                                </span>
                                <div>
                                    <h3 class="text-base font-bold text-white">Kapan Pendaftaran Dibuka Kembali?</h3>
                                    <p class="text-xs text-slate-400">Jadwal resmi pembukaan pendaftaran periode selanjutnya</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-blue-500/15 border border-blue-500/30 text-blue-300 text-xs font-semibold">
                                <i class="fa-solid fa-bell"></i> Persiapkan Berkas Anda
                            </span>
                        </div>

                        <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="p-4 rounded-2xl bg-white/5 border border-white/5">
                                <span class="text-xs text-slate-400 block mb-1">Periode Pembukaan Selanjutnya:</span>
                                <span class="text-base font-bold text-white block"><?= htmlspecialchars($ppdb_next_wave_name) ?></span>
                                <span class="text-xs text-slate-400 mt-1 block">Jalur Reguler Umum, Prestasi & Zonasi</span>
                            </div>
                            <div class="p-4 rounded-2xl bg-white/5 border border-blue-500/20 bg-blue-500/[0.03]">
                                <span class="text-xs text-slate-400 block mb-1">Tanggal Resmi Dibuka Kembali:</span>
                                <span class="text-xl font-extrabold text-blue-400 block">
                                    <?= !empty($ppdb_next_open_date) ? date('d F Y', strtotime($ppdb_next_open_date)) : 'Segera Diumumkan' ?>
                                </span>
                                <span class="text-[11px] text-slate-400 mt-1 block">Pukul 08.00 WIB melalui portal ini.</span>
                            </div>
                        </div>

                        <div class="mt-4 p-3.5 rounded-xl bg-slate-950/60 border border-white/5 flex items-start gap-2.5 text-xs text-slate-300">
                            <i class="fa-solid fa-circle-info text-blue-400 mt-0.5"></i>
                            <span>
                                <strong>Tips Calon Pendaftar:</strong> Siapkan dokumen digital sejak sekarang (Scan Kartu Keluarga, Akta Kelahiran, NISN aktif, dan Surat Keterangan Nilai Rapor Semester 1–5) agar saat pendaftaran dibuka dapat langsung mengunggah tanpa antre.
                            </span>
                        </div>
                    </div>

                    <!-- 3. Roadmap / Alur Tahapan Seleksi Berjalan -->
                    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-6 sm:p-8 shadow-xl">
                        <h3 class="text-base font-bold text-white flex items-center gap-2 mb-1">
                            <span class="text-emerald-400"><i class="fa-solid fa-timeline"></i></span>
                            Timeline & Jadwal Tahapan Seleksi PPDB 2026
                        </h3>
                        <p class="text-xs text-slate-400 mb-6">Agenda alur proses penerimaan dari pendaftaran hingga registrasi ulang</p>

                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 text-xs">
                            <!-- Step 1 -->
                            <div class="p-3.5 rounded-2xl bg-white/5 border border-emerald-500/30 relative">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-bold text-emerald-400">Tahap 1</span>
                                    <span class="h-5 w-5 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center text-[10px]">
                                        <i class="fa-solid fa-check"></i>
                                    </span>
                                </div>
                                <div class="font-bold text-white">Unggah Berkas</div>
                                <p class="text-[11px] text-slate-400 mt-1">Pendaftaran & verifikasi berkas online.</p>
                                <span class="text-[10px] text-emerald-300/80 mt-2 block font-semibold">Telah Selesai</span>
                            </div>

                            <!-- Step 2 -->
                            <div class="p-3.5 rounded-2xl bg-blue-500/10 border border-blue-500/30 relative">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-bold text-blue-400">Tahap 2</span>
                                    <span class="h-2 w-2 rounded-full bg-blue-400 animate-ping"></span>
                                </div>
                                <div class="font-bold text-white">Ujian CBT Online</div>
                                <p class="text-[11px] text-slate-400 mt-1">Tes potensi akademik & peminatan jurusan.</p>
                                <span class="text-[10px] text-blue-300 mt-2 block font-bold">Sedang Berlangsung</span>
                            </div>

                            <!-- Step 3 -->
                            <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-bold text-slate-400">Tahap 3</span>
                                    <i class="fa-regular fa-clock text-slate-500"></i>
                                </div>
                                <div class="font-bold text-white">Pengumuman Hasil</div>
                                <p class="text-[11px] text-slate-400 mt-1">Hasil seleksi kelulusan akhir.</p>
                                <span class="text-[10px] text-amber-300 mt-2 block font-semibold"><?= htmlspecialchars($ppdb_announcement_date) ?></span>
                            </div>

                            <!-- Step 4 -->
                            <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-bold text-slate-400">Tahap 4</span>
                                    <i class="fa-regular fa-calendar text-slate-500"></i>
                                </div>
                                <div class="font-bold text-white">Daftar Ulang</div>
                                <p class="text-[11px] text-slate-400 mt-1">Verifikasi fisik & seragam.</p>
                                <span class="text-[10px] text-slate-400 mt-2 block"><?= htmlspecialchars($ppdb_re_registration_date) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- 4. Hotline WhatsApp & Aksi Utama -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Kartu Hotline WhatsApp Panitia -->
                        <div class="p-6 rounded-3xl border border-emerald-500/30 bg-gradient-to-br from-emerald-950/30 to-slate-900 flex flex-col justify-between gap-4">
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-400 flex items-center gap-1.5 mb-1.5">
                                    <i class="fa-brands fa-whatsapp text-sm"></i> Layanan Konsultasi & Bantuan
                                </span>
                                <h4 class="text-base font-bold text-white">Punya Pertanyaan Seputar Gelombang Berikutnya?</h4>
                                <p class="text-xs text-slate-300 mt-1 leading-relaxed">
                                    Hubungi langsung Sekretariat Panitia PPDB sekolah untuk informasi kuota susulan atau verifikasi berkas khusus.
                                </p>
                            </div>
                            <a href="https://wa.me/<?= $wa_ppdb_hotline ?>?text=<?= urlencode("Halo Panitia PPDB {$school_name}, saya ingin menanyakan informasi terkait pembukaan pendaftaran gelombang berikutnya dan persyaratan siswa baru.") ?>"
                               target="_blank"
                               class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2.5 text-xs font-bold text-white shadow-lg shadow-emerald-600/25 transition">
                                <i class="fa-brands fa-whatsapp text-sm"></i> Chat Panitia PPDB WhatsApp
                            </a>
                        </div>

                        <!-- Kartu Aksi Lacak Status & Beranda -->
                        <div class="p-6 rounded-3xl border border-white/10 bg-slate-900/60 flex flex-col justify-between gap-4">
                            <div>
                                <span class="text-[10px] font-bold uppercase tracking-wider text-blue-400 flex items-center gap-1.5 mb-1.5">
                                    <i class="fa-solid fa-user-check"></i> Sudah Terdaftar Sebelumnya?
                                </span>
                                <h4 class="text-base font-bold text-white">Lacak Status Berkas & Ikuti Ujian CBT</h4>
                                <p class="text-xs text-slate-300 mt-1 leading-relaxed">
                                    Bagi calon siswa yang sudah mengirim formulir, silakan cek hasil validasi, skor seleksi, atau selesaikan tes online.
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="ppdb.php?tab=cek" 
                                   class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-blue-600/25 transition">
                                    <i class="fa-solid fa-magnifying-glass"></i> Cek Status
                                </a>
                                <a href="../index.php" 
                                   class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-xs font-semibold text-slate-300 transition">
                                    <i class="fa-solid fa-house"></i> Beranda
                                </a>
                            </div>
                        </div>
                    </div>

                </div>
            <?php else: ?>
                <!-- FORM PENDAFTARAN WIZARD MULTI-STEP -->
                <form action="ppdb.php?tab=daftar" method="POST" enctype="multipart/form-data" id="ppdbRegistrationForm" class="space-y-6">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="register_ppdb">

                    <!-- Stepper Progress Header -->
                    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-4 sm:p-6 backdrop-blur shadow-xl">
                        <div class="flex items-center justify-between mb-4 pb-3 border-b border-white/5">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-400 text-sm">
                                    <i class="fa-solid fa-list-check"></i>
                                </span>
                                <div>
                                    <h4 class="text-xs sm:text-sm font-bold text-white">Alur Pendaftaran Calon Siswa Baru</h4>
                                    <p class="text-[11px] text-slate-400">Lengkapi 5 tahapan pengisian data di bawah ini secara bertahap</p>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-xs font-mono font-bold text-emerald-400" id="stepPillBadge">
                                Tahap 1 dari 5
                            </span>
                        </div>

                        <!-- Stepper Grid Buttons -->
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 sm:gap-3" id="wizardStepper">
                            <!-- Step 1 -->
                            <button type="button" class="step-nav-btn active flex items-center gap-2.5 rounded-2xl p-2.5 sm:p-3 text-left transition border border-emerald-500/40 bg-emerald-500/10 text-emerald-300" data-step="1">
                                <span class="step-badge flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-emerald-500 text-slate-950 font-black text-xs shadow">1</span>
                                <div class="truncate">
                                    <span class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Tahap 1</span>
                                    <strong class="block text-xs truncate">Biodata Diri</strong>
                                </div>
                            </button>
                            <!-- Step 2 -->
                            <button type="button" class="step-nav-btn flex items-center gap-2.5 rounded-2xl p-2.5 sm:p-3 text-left transition border border-white/5 bg-slate-950/40 text-slate-400 hover:text-slate-200" data-step="2">
                                <span class="step-badge flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-white/10 text-slate-300 font-bold text-xs">2</span>
                                <div class="truncate">
                                    <span class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Tahap 2</span>
                                    <strong class="block text-xs truncate">Sekolah & Jalur</strong>
                                </div>
                            </button>
                            <!-- Step 3 -->
                            <button type="button" class="step-nav-btn flex items-center gap-2.5 rounded-2xl p-2.5 sm:p-3 text-left transition border border-white/5 bg-slate-950/40 text-slate-400 hover:text-slate-200" data-step="3">
                                <span class="step-badge flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-white/10 text-slate-300 font-bold text-xs">3</span>
                                <div class="truncate">
                                    <span class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Tahap 3</span>
                                    <strong class="block text-xs truncate">Orang Tua</strong>
                                </div>
                            </button>
                            <!-- Step 4 -->
                            <button type="button" class="step-nav-btn flex items-center gap-2.5 rounded-2xl p-2.5 sm:p-3 text-left transition border border-white/5 bg-slate-950/40 text-slate-400 hover:text-slate-200" data-step="4">
                                <span class="step-badge flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-white/10 text-slate-300 font-bold text-xs">4</span>
                                <div class="truncate">
                                    <span class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Tahap 4</span>
                                    <strong class="block text-xs truncate">Upload Berkas</strong>
                                </div>
                            </button>
                            <!-- Step 5 -->
                            <button type="button" class="step-nav-btn col-span-2 sm:col-span-1 flex items-center gap-2.5 rounded-2xl p-2.5 sm:p-3 text-left transition border border-white/5 bg-slate-950/40 text-slate-400 hover:text-slate-200" data-step="5">
                                <span class="step-badge flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-white/10 text-slate-300 font-bold text-xs">5</span>
                                <div class="truncate">
                                    <span class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Tahap 5</span>
                                    <strong class="block text-xs truncate">Konfirmasi Data</strong>
                                </div>
                            </button>
                        </div>

                        <!-- Stepper Percentage Bar -->
                        <div class="mt-4 w-full bg-slate-950/80 rounded-full h-1.5 overflow-hidden border border-white/5">
                            <div id="stepProgressBar" class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-400 transition-all duration-300" style="width: 20%;"></div>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- TAHAP 1: BIODATA CALON SISWA                    -->
                    <!-- ============================================== -->
                    <div class="step-card" id="stepSection1">
                        <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl">
                            <div class="flex items-center gap-3 border-b border-white/10 pb-4 mb-6">
                                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-500/10 text-lg text-blue-400 shadow-inner">
                                    <i class="fa-solid fa-user"></i>
                                </span>
                                <div>
                                    <h3 class="text-lg font-bold text-white">1. Biodata Calon Siswa Baru</h3>
                                    <p class="text-xs text-slate-400">Pastikan seluruh data identitas diisi sesuai dokumen kependudukan resmi (KK/Akta)</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-5">
                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Nama Lengkap Siswa *</label>
                                    <input type="text" name="full_name" id="inp_full_name" required placeholder="Contoh: Rian Pratama"
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">NISN (10 Digit Angka) *</label>
                                    <input type="text" name="nisn" id="inp_nisn" required maxlength="10" pattern="[0-9]{10}" placeholder="Contoh: 0081234567"
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition font-mono">
                                    <span class="text-[10px] text-slate-500 mt-1 block">Wajib 10 digit angka valid</span>
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">NIK Siswa (Opsional / Sesuai KK)</label>
                                    <input type="text" name="nik" id="inp_nik" maxlength="16" placeholder="Contoh: 3201011203080001"
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition font-mono">
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Jenis Kelamin *</label>
                                    <select name="gender" id="inp_gender" required
                                            class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                        <option value="L">Laki-Laki (L)</option>
                                        <option value="P">Perempuan (P)</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Agama</label>
                                    <select name="religion" id="inp_religion"
                                            class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                        <option value="Islam">Islam</option>
                                        <option value="Kristen">Kristen Protestan</option>
                                        <option value="Katolik">Katolik</option>
                                        <option value="Hindu">Hindu</option>
                                        <option value="Buddha">Buddha</option>
                                        <option value="Konghucu">Konghucu</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Tempat Lahir *</label>
                                    <input type="text" name="birth_place" id="inp_birth_place" required placeholder="Contoh: Jakarta"
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Tanggal Lahir *</label>
                                    <input type="date" name="birth_date" id="regBirthDate" required
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">No. HP / WhatsApp Siswa *</label>
                                    <input type="tel" name="phone" id="inp_phone" required placeholder="Contoh: 081234567890"
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition font-mono">
                                </div>

                                <div class="sm:col-span-2">
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Alamat Email Aktif *</label>
                                    <input type="email" name="email" id="inp_email" required placeholder="Contoh: siswa@gmail.com"
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                    <span class="text-[11px] text-slate-500 mt-1 block">Email ini akan digunakan sebagai akun login portal akademik setelah kelulusan.</span>
                                </div>

                                <div class="sm:col-span-3">
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Alamat Lengkap Domisili Siswa *</label>
                                    <textarea name="address" id="inp_address" required rows="2" placeholder="Nama Jalan, RT/RW, Kelurahan, Kecamatan, Kota/Kabupaten..."
                                              class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- TAHAP 2: ASAL SEKOLAH, JURUSAN & JALUR MASUK    -->
                    <!-- ============================================== -->
                    <div class="step-card hidden" id="stepSection2">
                        <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl">
                            <div class="flex items-center gap-3 border-b border-white/10 pb-4 mb-6">
                                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-500/10 text-lg text-emerald-400 shadow-inner">
                                    <i class="fa-solid fa-school"></i>
                                </span>
                                <div>
                                    <h3 class="text-lg font-bold text-white">2. Asal Sekolah, Peminatan Jurusan & Jalur Masuk</h3>
                                    <p class="text-xs text-slate-400">Tentukan jurusan pilihan serta jalur seleksi yang akan Anda tempuh</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Asal Sekolah (SMP / MTs) *</label>
                                    <input type="text" name="previous_school" id="inp_previous_school" required placeholder="Contoh: SMP Negeri 1 Jakarta"
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Pilihan Jurusan / Peminatan *</label>
                                    <select name="chosen_major" id="inp_chosen_major" required
                                            class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                        <?php 
                                        $ppdb_majors = function_exists('getSchoolMajors') ? getSchoolMajors($pdo) : ['MIPA (Matematika & IPA)', 'IPS (Ilmu Pengetahuan Sosial)', 'Bahasa & Budaya', 'Teknologi Informasi & Jaringan'];
                                        foreach ($ppdb_majors as $m_item):
                                        ?>
                                            <option value="<?= htmlspecialchars($m_item) ?>" <?= (isset($draft['chosen_major']) && $draft['chosen_major'] === $m_item) ? 'selected' : '' ?>><?= htmlspecialchars($m_item) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Jalur Seleksi PPDB *</label>
                                    <select name="track_type" id="trackSelect" required onchange="toggleTrackInputs()"
                                            class="w-full rounded-xl border border-emerald-500/30 bg-slate-950 px-4 py-2.5 text-sm text-emerald-400 font-bold focus:border-emerald-500 focus:outline-none transition">
                                        <option value="reguler">Reguler / Tes Akademik</option>
                                        <option value="zonasi">Zonasi Domisili (Radius KM)</option>
                                        <option value="prestasi">Jalur Prestasi (Akademik / Lomba)</option>
                                        <option value="afirmasi">Afirmasi / KIP / Bantuan Sosial</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Sub-Form Dinamis Berdasarkan Jalur -->
                            <div id="zonasiBox" class="hidden mt-5 p-4 rounded-2xl border border-emerald-500/20 bg-emerald-500/5">
                                <label class="block text-xs font-bold text-emerald-400 mb-1.5"><i class="fa-solid fa-location-dot mr-1"></i> Jarak Domisili ke Sekolah (KM) *</label>
                                <input type="number" step="0.01" min="0" max="50" name="distance_km" id="distanceKmInput" placeholder="Contoh: 1.5 (dalam Kilometer)"
                                       class="w-full sm:w-80 rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none">
                                <span class="text-[11px] text-slate-400 mt-1 block">Jarak di bawah 1 KM akan memperoleh prioritas poin zonasi maksimal.</span>
                            </div>

                            <!-- Dynamic Multi-Certificate Prestasi Builder (Maks. 10) -->
                            <div id="prestasiBox" class="hidden mt-5 p-5 rounded-2xl border border-amber-500/20 bg-amber-500/5 space-y-4">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-amber-500/20 pb-3">
                                    <div>
                                        <h4 class="text-xs font-bold text-amber-300 uppercase tracking-wider flex items-center gap-2">
                                            <i class="fa-solid fa-trophy text-amber-400"></i>
                                            <span>Daftar Sertifikat & Piagam Prestasi</span>
                                        </h4>
                                        <p class="text-[11px] text-slate-400 mt-0.5">
                                            Tambahkan prestasi lomba (maksimal 10). Skor akhir dihitung dari kejuaraan tertinggi.
                                        </p>
                                    </div>
                                    <div class="shrink-0">
                                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-amber-500/20 border border-amber-500/30 text-xs font-mono font-bold text-amber-300">
                                            <span id="certCounter">1</span> / 10 Sertifikat
                                        </span>
                                    </div>
                                </div>

                                <div id="certItemsContainer" class="space-y-3">
                                    <!-- Item 1 (Default) -->
                                    <div class="cert-item rounded-xl border border-white/10 bg-slate-950/80 p-4 space-y-3" data-index="0">
                                        <div class="flex items-center justify-between text-xs font-semibold text-slate-300">
                                            <span class="cert-badge flex items-center gap-1.5 text-amber-400">
                                                <i class="fa-solid fa-medal"></i> Sertifikat #1
                                            </span>
                                            <button type="button" onclick="removeCertItem(this)" class="btn-remove-cert hidden text-rose-400 hover:text-rose-300 text-xs font-semibold transition">
                                                <i class="fa-solid fa-trash-can mr-1"></i> Hapus
                                            </button>
                                        </div>

                                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                            <div>
                                                <label class="block text-[11px] font-semibold text-slate-400 mb-1">Tingkat Kejuaraan *</label>
                                                <select name="ach_level[]" class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-amber-400 focus:outline-none">
                                                    <option value="sekolah">Tingkat Sekolah (+1.5 Poin)</option>
                                                    <option value="kecamatan">Tingkat Kecamatan (+3.0 Poin)</option>
                                                    <option value="kabupaten">Tingkat Kota / Kabupaten (+5.0 Poin)</option>
                                                    <option value="provinsi">Tingkat Provinsi (+7.0 Poin)</option>
                                                    <option value="nasional">Tingkat Nasional (+10.0 Poin)</option>
                                                    <option value="internasional">Tingkat Internasional (+15.0 Poin)</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold text-slate-400 mb-1">Nama Lomba / Prestasi *</label>
                                                <input type="text" name="ach_desc[]" placeholder="Contoh: Juara 1 OSN Matematika..." 
                                                       class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-amber-400 focus:outline-none">
                                            </div>
                                            <div>
                                                <label class="block text-[11px] font-semibold text-slate-400 mb-1">File Bukti Sertifikat (PDF/JPG, Maks 5MB)</label>
                                                <input type="file" name="ach_file[]" accept=".pdf,.jpg,.jpeg,.png"
                                                       class="w-full text-[11px] text-slate-400 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-white/10 file:text-white hover:file:bg-white/20">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="pt-2 flex items-center justify-between">
                                    <button type="button" id="btnAddCert" onclick="addCertItem()" 
                                            class="inline-flex items-center gap-1.5 rounded-xl border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 px-4 py-2 text-xs font-bold text-amber-300 transition cursor-pointer">
                                        <i class="fa-solid fa-plus"></i>
                                        <span>Tambah Sertifikat Lagi (Maks. 10)</span>
                                    </button>
                                    <span class="text-[11px] text-slate-400 italic">Maksimal 10 sertifikat</span>
                                </div>
                            </div>

                            <div id="afirmasiBox" class="hidden mt-5 p-4 rounded-2xl border border-purple-500/20 bg-purple-500/5">
                                <label class="block text-xs font-bold text-purple-400 mb-1.5"><i class="fa-solid fa-id-card-clip mr-1"></i> Nomor Kartu Indonesia Pintar (KIP) / PKH / SKTM *</label>
                                <input type="text" name="affirmation_no" id="inp_affirmation_no" placeholder="Contoh: KIP-2025-99881122"
                                       class="w-full sm:w-80 rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none font-mono">
                            </div>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- TAHAP 3: DATA ORANG TUA / WALI                  -->
                    <!-- ============================================== -->
                    <div class="step-card hidden" id="stepSection3">
                        <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl">
                            <div class="flex items-center gap-3 border-b border-white/10 pb-4 mb-6">
                                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-purple-500/10 text-lg text-purple-400 shadow-inner">
                                    <i class="fa-solid fa-people-roof"></i>
                                </span>
                                <div>
                                    <h3 class="text-lg font-bold text-white">3. Data Orang Tua / Wali Calon Siswa</h3>
                                    <p class="text-xs text-slate-400">Kontak penanggung jawab keluarga calon siswa untuk konfirmasi pendaftaran</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Nama Orang Tua / Wali *</label>
                                    <input type="text" name="parent_name" id="inp_parent_name" required placeholder="Contoh: Budi Santoso"
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">No. HP / WhatsApp Orang Tua *</label>
                                    <input type="tel" name="parent_phone" id="inp_parent_phone" required placeholder="Contoh: 081298765432"
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition font-mono">
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 mb-2">Pekerjaan Orang Tua</label>
                                    <input type="text" name="parent_job" id="inp_parent_job" placeholder="Contoh: Wiraswasta / PNS / Karyawan"
                                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- TAHAP 4: UPLOAD BERKAS & LIVE PREVIEW           -->
                    <!-- ============================================== -->
                    <div class="step-card hidden" id="stepSection4">
                        <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl">
                            <div class="flex items-center gap-3 border-b border-white/10 pb-4 mb-6">
                                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-500/10 text-lg text-amber-400 shadow-inner">
                                    <i class="fa-solid fa-cloud-arrow-up"></i>
                                </span>
                                <div>
                                    <h3 class="text-lg font-bold text-white">4. Upload Dokumen Administrasi Siswa</h3>
                                    <p class="text-xs text-slate-400">Seret berkas atau klik kotak untuk mengunggah dokumen (Format PDF, JPG, PNG - Maks 5MB per berkas)</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                                
                                <!-- 1. Scan Rapor -->
                                <div class="drop-zone-card rounded-2xl border border-white/10 bg-slate-950/60 p-4 transition-all hover:border-blue-500/50" data-field="report_card_doc">
                                    <label class="block text-xs font-bold text-slate-200 mb-1 flex items-center gap-1.5">
                                        <i class="fa-solid fa-file-lines text-blue-400"></i> Scan Rapor SMP
                                    </label>
                                    <span class="text-[10px] text-slate-400 block mb-3">Semester 1 - 5 SMP</span>
                                    
                                    <div class="drop-area border-2 border-dashed border-white/15 hover:border-blue-500/60 rounded-xl p-3 text-center cursor-pointer transition bg-slate-900/40 min-h-[140px] flex flex-col justify-center items-center">
                                        <div class="default-view space-y-1.5">
                                            <div class="w-9 h-9 mx-auto rounded-xl bg-blue-500/10 text-blue-400 flex items-center justify-center">
                                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                            </div>
                                            <p class="text-[11px] font-semibold text-slate-300">Pilih / Seret File</p>
                                            <span class="text-[9px] text-slate-500 block">PDF / JPG (Maks 5MB)</span>
                                        </div>
                                        <div class="preview-view hidden space-y-1.5 text-center w-full">
                                            <div class="preview-thumbnail w-14 h-14 mx-auto rounded-xl bg-slate-800 border border-white/10 flex items-center justify-center overflow-hidden"></div>
                                            <p class="preview-filename text-[11px] font-semibold text-blue-400 truncate max-w-[130px] mx-auto"></p>
                                            <span class="preview-filesize text-[9px] text-slate-400 font-mono block"></span>
                                            <button type="button" class="btn-clear-file text-[10px] text-rose-400 hover:text-rose-300 underline font-semibold">Ganti File</button>
                                        </div>
                                    </div>
                                    <input type="file" name="report_card_doc" id="file_report_card_doc" accept=".pdf,.jpg,.jpeg,.png" class="hidden file-input-target">
                                </div>

                                <!-- 2. Kartu Keluarga -->
                                <div class="drop-zone-card rounded-2xl border border-white/10 bg-slate-950/60 p-4 transition-all hover:border-emerald-500/50" data-field="family_card_doc">
                                    <label class="block text-xs font-bold text-slate-200 mb-1 flex items-center gap-1.5">
                                        <i class="fa-solid fa-id-card text-emerald-400"></i> Kartu Keluarga (KK)
                                    </label>
                                    <span class="text-[10px] text-slate-400 block mb-3">Foto / Scan Asli KK</span>
                                    
                                    <div class="drop-area border-2 border-dashed border-white/15 hover:border-emerald-500/60 rounded-xl p-3 text-center cursor-pointer transition bg-slate-900/40 min-h-[140px] flex flex-col justify-center items-center">
                                        <div class="default-view space-y-1.5">
                                            <div class="w-9 h-9 mx-auto rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                            </div>
                                            <p class="text-[11px] font-semibold text-slate-300">Pilih / Seret File</p>
                                            <span class="text-[9px] text-slate-500 block">PDF / JPG (Maks 5MB)</span>
                                        </div>
                                        <div class="preview-view hidden space-y-1.5 text-center w-full">
                                            <div class="preview-thumbnail w-14 h-14 mx-auto rounded-xl bg-slate-800 border border-white/10 flex items-center justify-center overflow-hidden"></div>
                                            <p class="preview-filename text-[11px] font-semibold text-emerald-400 truncate max-w-[130px] mx-auto"></p>
                                            <span class="preview-filesize text-[9px] text-slate-400 font-mono block"></span>
                                            <button type="button" class="btn-clear-file text-[10px] text-rose-400 hover:text-rose-300 underline font-semibold">Ganti File</button>
                                        </div>
                                    </div>
                                    <input type="file" name="family_card_doc" id="file_family_card_doc" accept=".pdf,.jpg,.jpeg,.png" class="hidden file-input-target">
                                </div>

                                <!-- 3. Akta Kelahiran -->
                                <div class="drop-zone-card rounded-2xl border border-white/10 bg-slate-950/60 p-4 transition-all hover:border-emerald-500/50" data-field="birth_cert_doc">
                                    <label class="block text-xs font-bold text-slate-200 mb-1 flex items-center gap-1.5">
                                        <i class="fa-solid fa-certificate text-emerald-400"></i> Akta Kelahiran
                                    </label>
                                    <span class="text-[10px] text-slate-400 block mb-3">Foto / Scan Asli Akta</span>
                                    
                                    <div class="drop-area border-2 border-dashed border-white/15 hover:border-emerald-500/60 rounded-xl p-3 text-center cursor-pointer transition bg-slate-900/40 min-h-[140px] flex flex-col justify-center items-center">
                                        <div class="default-view space-y-1.5">
                                            <div class="w-9 h-9 mx-auto rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                            </div>
                                            <p class="text-[11px] font-semibold text-slate-300">Pilih / Seret File</p>
                                            <span class="text-[9px] text-slate-500 block">PDF / JPG (Maks 5MB)</span>
                                        </div>
                                        <div class="preview-view hidden space-y-1.5 text-center w-full">
                                            <div class="preview-thumbnail w-14 h-14 mx-auto rounded-xl bg-slate-800 border border-white/10 flex items-center justify-center overflow-hidden"></div>
                                            <p class="preview-filename text-[11px] font-semibold text-emerald-400 truncate max-w-[130px] mx-auto"></p>
                                            <span class="preview-filesize text-[9px] text-slate-400 font-mono block"></span>
                                            <button type="button" class="btn-clear-file text-[10px] text-rose-400 hover:text-rose-300 underline font-semibold">Ganti File</button>
                                        </div>
                                    </div>
                                    <input type="file" name="birth_cert_doc" id="file_birth_cert_doc" accept=".pdf,.jpg,.jpeg,.png" class="hidden file-input-target">
                                </div>

                                <!-- 4. Pas Foto 3x4 -->
                                <div class="drop-zone-card rounded-2xl border border-white/10 bg-slate-950/60 p-4 transition-all hover:border-purple-500/50" data-field="photo_doc">
                                    <label class="block text-xs font-bold text-slate-200 mb-1 flex items-center gap-1.5">
                                        <i class="fa-solid fa-image text-purple-400"></i> Pas Foto 3x4
                                    </label>
                                    <span class="text-[10px] text-slate-400 block mb-3">Latar Belakang Merah/Biru</span>
                                    
                                    <div class="drop-area border-2 border-dashed border-white/15 hover:border-purple-500/60 rounded-xl p-3 text-center cursor-pointer transition bg-slate-900/40 min-h-[140px] flex flex-col justify-center items-center">
                                        <div class="default-view space-y-1.5">
                                            <div class="w-9 h-9 mx-auto rounded-xl bg-purple-500/10 text-purple-400 flex items-center justify-center">
                                                <i class="fa-solid fa-camera"></i>
                                            </div>
                                            <p class="text-[11px] font-semibold text-slate-300">Pilih Foto 3x4</p>
                                            <span class="text-[9px] text-slate-500 block">JPG / PNG (Maks 5MB)</span>
                                        </div>
                                        <div class="preview-view hidden space-y-1.5 text-center w-full">
                                            <div class="preview-thumbnail w-14 h-14 mx-auto rounded-xl bg-slate-800 border border-white/10 flex items-center justify-center overflow-hidden"></div>
                                            <p class="preview-filename text-[11px] font-semibold text-purple-400 truncate max-w-[130px] mx-auto"></p>
                                            <span class="preview-filesize text-[9px] text-slate-400 font-mono block"></span>
                                            <button type="button" class="btn-clear-file text-[10px] text-rose-400 hover:text-rose-300 underline font-semibold">Ganti Foto</button>
                                        </div>
                                    </div>
                                    <input type="file" name="photo_doc" id="file_photo_doc" accept=".jpg,.jpeg,.png" class="hidden file-input-target">
                                </div>

                                <!-- 5. Sertifikat Prestasi -->
                                <div id="uploadCertBox" class="drop-zone-card rounded-2xl border border-amber-500/30 bg-amber-500/5 p-4 transition-all hover:border-amber-500/60" data-field="achievement_cert_doc">
                                    <label class="block text-xs font-bold text-amber-300 mb-1 flex items-center gap-1.5">
                                        <i class="fa-solid fa-trophy text-amber-400"></i> Sertifikat Prestasi
                                    </label>
                                    <span class="text-[10px] text-amber-300/70 block mb-3">Wajib jika Jalur Prestasi</span>
                                    
                                    <div class="drop-area border-2 border-dashed border-amber-500/30 hover:border-amber-500/70 rounded-xl p-3 text-center cursor-pointer transition bg-slate-900/40 min-h-[140px] flex flex-col justify-center items-center">
                                        <div class="default-view space-y-1.5">
                                            <div class="w-9 h-9 mx-auto rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center">
                                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                            </div>
                                            <p class="text-[11px] font-semibold text-slate-300">Pilih Sertifikat</p>
                                            <span class="text-[9px] text-slate-500 block">PDF / JPG (Maks 5MB)</span>
                                        </div>
                                        <div class="preview-view hidden space-y-1.5 text-center w-full">
                                            <div class="preview-thumbnail w-14 h-14 mx-auto rounded-xl bg-slate-800 border border-white/10 flex items-center justify-center overflow-hidden"></div>
                                            <p class="preview-filename text-[11px] font-semibold text-amber-400 truncate max-w-[130px] mx-auto"></p>
                                            <span class="preview-filesize text-[9px] text-slate-400 font-mono block"></span>
                                            <button type="button" class="btn-clear-file text-[10px] text-rose-400 hover:text-rose-300 underline font-semibold">Ganti File</button>
                                        </div>
                                    </div>
                                    <input type="file" name="achievement_cert_doc" id="achievementCertDocInput" accept=".pdf,.jpg,.jpeg,.png" class="hidden file-input-target">
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- TAHAP 5: RINGKASAN & KONFIRMASI DATA           -->
                    <!-- ============================================== -->
                    <div class="step-card hidden" id="stepSection5">
                        <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl space-y-6">
                            
                            <div class="flex items-center gap-3 border-b border-white/10 pb-4">
                                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-teal-500/10 text-lg text-teal-400 shadow-inner">
                                    <i class="fa-solid fa-clipboard-check"></i>
                                </span>
                                <div>
                                    <h3 class="text-lg font-bold text-white">5. Ringkasan & Konfirmasi Pendaftaran</h3>
                                    <p class="text-xs text-slate-400">Silakan periksa kembali seluruh isian formulir Anda sebelum dikirimkan ke panitia</p>
                                </div>
                            </div>

                            <!-- Review Sections Grid -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                                
                                <!-- Card A: Biodata -->
                                <div class="rounded-2xl border border-white/5 bg-slate-950/70 p-4 space-y-2">
                                    <div class="flex items-center justify-between border-b border-white/5 pb-2">
                                        <strong class="text-white flex items-center gap-1.5 text-xs">
                                            <i class="fa-solid fa-user text-blue-400"></i> Biodata Calon Siswa
                                        </strong>
                                        <button type="button" onclick="goToStep(1)" class="text-blue-400 hover:text-blue-300 font-semibold cursor-pointer">
                                            <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                                        </button>
                                    </div>
                                    <div class="space-y-1 text-slate-300" id="summaryBiodata">
                                        <p><span class="text-slate-500">Nama:</span> <strong class="text-white" id="sum_name">-</strong></p>
                                        <p><span class="text-slate-500">NISN / NIK:</span> <span class="font-mono text-white" id="sum_nisn">-</span> / <span class="font-mono" id="sum_nik">-</span></p>
                                        <p><span class="text-slate-500">Jenis Kelamin / Agama:</span> <span id="sum_gender">-</span> / <span id="sum_religion">-</span></p>
                                        <p><span class="text-slate-500">TTL:</span> <span id="sum_ttl">-</span></p>
                                        <p><span class="text-slate-500">WhatsApp / Email:</span> <span class="font-mono" id="sum_contact">-</span></p>
                                        <p><span class="text-slate-500">Alamat:</span> <span id="sum_address">-</span></p>
                                    </div>
                                </div>

                                <!-- Card B: Pilihan Sekolah & Jalur -->
                                <div class="rounded-2xl border border-white/5 bg-slate-950/70 p-4 space-y-2">
                                    <div class="flex items-center justify-between border-b border-white/5 pb-2">
                                        <strong class="text-white flex items-center gap-1.5 text-xs">
                                            <i class="fa-solid fa-school text-emerald-400"></i> Peminatan & Jalur Seleksi
                                        </strong>
                                        <button type="button" onclick="goToStep(2)" class="text-emerald-400 hover:text-emerald-300 font-semibold cursor-pointer">
                                            <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                                        </button>
                                    </div>
                                    <div class="space-y-1 text-slate-300" id="summaryPeminatan">
                                        <p><span class="text-slate-500">Asal SMP:</span> <strong class="text-white" id="sum_school">-</strong></p>
                                        <p><span class="text-slate-500">Peminatan Jurusan:</span> <strong class="text-emerald-400" id="sum_major">-</strong></p>
                                        <p><span class="text-slate-500">Jalur Masuk:</span> <span class="font-bold text-white uppercase" id="sum_track">-</span></p>
                                        <p id="sum_track_detail" class="text-[11px] text-slate-400"></p>
                                    </div>
                                </div>

                                <!-- Card C: Data Orang Tua -->
                                <div class="rounded-2xl border border-white/5 bg-slate-950/70 p-4 space-y-2">
                                    <div class="flex items-center justify-between border-b border-white/5 pb-2">
                                        <strong class="text-white flex items-center gap-1.5 text-xs">
                                            <i class="fa-solid fa-people-roof text-purple-400"></i> Orang Tua / Wali
                                        </strong>
                                        <button type="button" onclick="goToStep(3)" class="text-purple-400 hover:text-purple-300 font-semibold cursor-pointer">
                                            <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                                        </button>
                                    </div>
                                    <div class="space-y-1 text-slate-300" id="summaryOrtu">
                                        <p><span class="text-slate-500">Nama Wali:</span> <strong class="text-white" id="sum_parent_name">-</strong></p>
                                        <p><span class="text-slate-500">WhatsApp Wali:</span> <span class="font-mono text-white" id="sum_parent_phone">-</span></p>
                                        <p><span class="text-slate-500">Pekerjaan:</span> <span id="sum_parent_job">-</span></p>
                                    </div>
                                </div>

                                <!-- Card D: Dokumen Terunggah -->
                                <div class="rounded-2xl border border-white/5 bg-slate-950/70 p-4 space-y-2">
                                    <div class="flex items-center justify-between border-b border-white/5 pb-2">
                                        <strong class="text-white flex items-center gap-1.5 text-xs">
                                            <i class="fa-solid fa-paperclip text-amber-400"></i> Status Berkas Terunggah
                                        </strong>
                                        <button type="button" onclick="goToStep(4)" class="text-amber-400 hover:text-amber-300 font-semibold cursor-pointer">
                                            <i class="fa-solid fa-pen-to-square mr-1"></i> Edit
                                        </button>
                                    </div>
                                    <ul class="space-y-1 text-slate-300 text-[11px]" id="summaryBerkas">
                                        <!-- Injected by JS -->
                                    </ul>
                                </div>

                            </div>

                            <!-- Pernyataan Keabsahan Data Checkbox -->
                            <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4">
                                <label class="flex items-start gap-3 cursor-pointer select-none">
                                    <input type="checkbox" id="agreementCheckbox" required
                                           class="mt-1 h-4 w-4 rounded border-white/20 bg-slate-950 text-emerald-500 focus:ring-emerald-500 focus:ring-offset-slate-900 cursor-pointer">
                                    <span class="text-xs text-slate-300 leading-relaxed">
                                        Dengan mencentang pernyataan ini, saya menyatakan dengan sesungguhnya bahwa seluruh data dan dokumen yang saya kirimkan adalah sah, benar, dan dapat dipertanggungjawabkan secara hukum. Apabila di kemudian hari ditemukan pemalsuan data, saya bersedia menerima sanksi pembatalan kelulusan seleksi PPDB.
                                    </span>
                                </label>
                            </div>

                        </div>
                    </div>

                    <!-- ============================================== -->
                    <!-- WIZARD STEPPER NAVIGATION CONTROLS             -->
                    <!-- ============================================== -->
                    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-4 sm:p-6 backdrop-blur shadow-xl flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div>
                            <button type="button" id="btnPrevStep" onclick="navigateStep(-1)"
                                    class="hidden inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3 text-xs sm:text-sm font-bold text-slate-200 transition cursor-pointer">
                                <i class="fa-solid fa-arrow-left"></i>
                                <span>Langkah Sebelumnya</span>
                            </button>
                        </div>

                        <div class="flex items-center gap-3 w-full sm:w-auto justify-end">
                            <span class="text-xs text-slate-400 hidden sm:inline" id="stepIndicatorLabel">
                                Langkah 1: Biodata Diri
                            </span>

                            <button type="button" id="btnNextStep" onclick="navigateStep(1)"
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-7 py-3 text-xs sm:text-sm font-bold text-white shadow-lg shadow-emerald-600/25 transition cursor-pointer">
                                <span>Lanjutkan ke Tahap 2</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </button>

                            <button type="submit" id="btnFinalSubmit"
                                    class="hidden w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 px-8 py-3 text-xs sm:text-sm font-bold text-white shadow-xl shadow-emerald-600/25 transition cursor-pointer">
                                <i class="fa-solid fa-paper-plane"></i>
                                <span>Kirim Pendaftaran Siswa Baru</span>
                            </button>
                        </div>
                    </div>

                </form>
            <?php endif; ?>

        <?php else: ?>
            <!-- TAB CEK STATUS & PERBAIKAN BERKAS -->
            <div class="space-y-8">
                <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-6 sm:p-8 backdrop-blur shadow-xl max-w-2xl mx-auto">
                    <div class="flex items-center gap-3 mb-3">
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/10 text-lg text-emerald-400">
                            <i class="fa-solid fa-shield-halved"></i>
                        </span>
                        <div>
                            <h3 class="text-lg font-bold text-white">Pelacakan Status & Cetak Kartu</h3>
                            <p class="text-xs text-slate-400">Masukkan No. Registrasi/NISN beserta Tanggal Lahir untuk otentikasi data pribadi</p>
                        </div>
                    </div>

                    <form action="ppdb.php" method="GET" class="space-y-4 pt-2">
                        <input type="hidden" name="tab" value="cek">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1.5">No. Registrasi / NISN *</label>
                                <input type="text" name="search" required 
                                       value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                       placeholder="Contoh: PPDB-2026-0001 atau 0081234567"
                                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tanggal Lahir Calon Siswa *</label>
                                <input type="date" name="birth_date" required
                                       value="<?= htmlspecialchars($_GET['birth_date'] ?? '') ?>"
                                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                            </div>
                        </div>

                        <div class="flex justify-end pt-2">
                            <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-6 py-2.5 font-bold text-white shadow-lg shadow-emerald-600/20 transition cursor-pointer text-sm">
                                <i class="fa-solid fa-magnifying-glass"></i>
                                <span>Verifikasi & Lacak Status</span>
                            </button>
                        </div>
                    </form>
                </div>

                <?php if ($search_result): ?>
                    <!-- Hasil Pencarian Status -->
                    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-6 sm:p-8 backdrop-blur shadow-xl max-w-3xl mx-auto">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-white/10 pb-6 mb-6">
                            <div>
                                <span class="text-xs font-mono text-slate-400 block mb-1">Nomor Registrasi:</span>
                                <h3 class="text-2xl font-black text-white font-mono"><?= htmlspecialchars($search_result['registration_no']) ?></h3>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-xl border px-3 py-1 text-xs font-bold <?= getPpdbTrackBadge($search_result['track_type'] ?? 'reguler') ?>">
                                    <i class="fa-solid <?= getPpdbTrackIcon($search_result['track_type'] ?? 'reguler') ?> mr-1.5"></i>
                                    Jalur <?= htmlspecialchars(getPpdbTrackLabel($search_result['track_type'] ?? 'reguler')) ?>
                                </span>

                                <span class="inline-flex items-center rounded-xl border px-3 py-1 text-xs font-bold <?= getPpdbStatusBadge($search_result['status']) ?>">
                                    ● <?= htmlspecialchars(getPpdbStatusLabel($search_result['status'])) ?>
                                </span>

                                <a href="ppdb_card.php?reg=<?= urlencode($search_result['registration_no']) ?>&token=<?= urlencode($search_result['qr_token'] ?? '') ?>" target="_blank"
                                   class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-1 text-xs font-semibold text-white transition flex items-center gap-1.5">
                                    <i class="fa-solid fa-print"></i> Cetak Kartu
                                </a>
                            </div>
                        </div>

                        <!-- Status Berkas & Peringatan Revisi -->
                        <div class="mb-6 rounded-2xl border p-4 text-xs <?= ($search_result['document_status'] ?? 'lengkap') === 'perlu_revisi' ? 'border-amber-500/40 bg-amber-500/10 text-amber-300' : (($search_result['document_status'] ?? 'lengkap') === 'ditolak' ? 'border-rose-500/40 bg-rose-500/10 text-rose-300' : 'border-emerald-500/20 bg-emerald-500/5 text-emerald-300') ?>">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3">
                                    <span class="text-lg mt-0.5">
                                        <?= ($search_result['document_status'] ?? 'lengkap') === 'perlu_revisi' ? '<i class="fa-solid fa-triangle-exclamation text-amber-400"></i>' : (($search_result['document_status'] ?? 'lengkap') === 'ditolak' ? '<i class="fa-solid fa-circle-xmark text-rose-400"></i>' : '<i class="fa-solid fa-circle-check text-emerald-400"></i>') ?>
                                    </span>
                                    <div>
                                        <span class="font-bold uppercase tracking-wider block">
                                            Status Berkas Dokumen: <?= htmlspecialchars(getPpdbDocStatusLabel($search_result['document_status'] ?? 'lengkap')) ?>
                                        </span>
                                        <?php if (!empty($search_result['rejection_reason'])): ?>
                                            <p class="mt-1 text-sm text-slate-200">
                                                <strong>Catatan Panitia:</strong> <?= htmlspecialchars($search_result['rejection_reason']) ?>
                                            </p>
                                        <?php else: ?>
                                            <p class="mt-1 text-slate-300">Berkas administrasi digital Anda telah diterima oleh panitia penerimaan siswa baru.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm mb-6">
                            <div class="rounded-2xl bg-slate-950/60 p-4">
                                <span class="text-xs text-slate-400 block">Nama Calon Siswa</span>
                                <strong class="text-white text-base mt-0.5 block"><?= htmlspecialchars($search_result['full_name']) ?></strong>
                            </div>
                            <div class="rounded-2xl bg-slate-950/60 p-4">
                                <span class="text-xs text-slate-400 block">NISN / Asal Sekolah</span>
                                <strong class="text-white text-base mt-0.5 block font-mono"><?= htmlspecialchars($search_result['nisn']) ?></strong>
                                <span class="text-xs text-slate-400"><?= htmlspecialchars($search_result['previous_school']) ?></span>
                            </div>
                            <div class="rounded-2xl bg-slate-950/60 p-4">
                                <span class="text-xs text-slate-400 block">Peminatan / Jurusan Dituju</span>
                                <strong class="text-white text-base mt-0.5 block"><?= htmlspecialchars($search_result['chosen_major']) ?></strong>
                            </div>
                            <div class="rounded-2xl bg-slate-950/60 p-4">
                                <span class="text-xs text-slate-400 block">Kontak Siswa / Orang Tua</span>
                                <strong class="text-white text-base mt-0.5 block font-mono"><?= htmlspecialchars($search_result['phone']) ?></strong>
                                <span class="text-xs text-slate-400"><?= htmlspecialchars($search_result['parent_name']) ?></span>
                            </div>
                        </div>

                        <!-- Jadwal & Lokasi Ujian Masuk / Seleksi Offline -->
                        <?php if (!empty($search_result['exam_room']) || !empty($search_result['exam_date']) || $search_result['status'] === 'diverifikasi'): ?>
                            <div class="rounded-3xl border border-emerald-500/30 bg-gradient-to-r from-emerald-950/40 via-slate-900/90 to-teal-950/30 p-5 mb-6 shadow-xl">
                                <div class="flex items-center justify-between border-b border-emerald-500/20 pb-2.5 mb-3.5">
                                    <div class="flex items-center gap-2">
                                        <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-sm">
                                            <i class="fa-solid fa-calendar-check"></i>
                                        </span>
                                        <h4 class="text-sm font-bold text-white">Jadwal & Ruang Pelaksanaan Ujian Seleksi Masuk</h4>
                                    </div>
                                    <span class="px-2.5 py-0.5 rounded text-[10px] font-bold uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                        <?= htmlspecialchars($search_result['exam_session'] ?: 'Sesi 1 (Reguler)') ?>
                                    </span>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center sm:text-left">
                                    <div class="rounded-2xl bg-slate-950/60 p-3 border border-white/5">
                                        <span class="text-[10px] text-slate-400 block uppercase font-bold">Tanggal Ujian</span>
                                        <strong class="text-white text-xs mt-0.5 block font-mono">
                                            <?= !empty($search_result['exam_date']) ? date('d F Y', strtotime($search_result['exam_date'])) : 'Sesuai Jadwal Gelombang' ?>
                                        </strong>
                                    </div>
                                    <div class="rounded-2xl bg-slate-950/60 p-3 border border-white/5">
                                        <span class="text-[10px] text-slate-400 block uppercase font-bold">Waktu / Jam</span>
                                        <strong class="text-white text-xs mt-0.5 block font-mono">
                                            <?= htmlspecialchars($search_result['exam_time'] ?: '08.00 - 10.00 WIB') ?>
                                        </strong>
                                    </div>
                                    <div class="rounded-2xl bg-slate-950/60 p-3 border border-white/5">
                                        <span class="text-[10px] text-slate-400 block uppercase font-bold">Ruangan / Lab</span>
                                        <strong class="text-emerald-400 text-xs mt-0.5 block font-bold">
                                            <?= htmlspecialchars($search_result['exam_room'] ?: 'Lab Komputer / Ruang Seleksi') ?>
                                        </strong>
                                    </div>
                                    <div class="rounded-2xl bg-slate-950/60 p-3 border border-white/5">
                                        <span class="text-[10px] text-slate-400 block uppercase font-bold">Tempat / Lokasi</span>
                                        <strong class="text-slate-200 text-xs mt-0.5 block truncate" title="<?= htmlspecialchars($school_info['school_name'] ?? 'Gedung Sekolah') ?>">
                                            <?= htmlspecialchars($school_info['school_name'] ?? 'Gedung Sekolah') ?>
                                        </strong>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Integrasi Ujian CBT Seleksi Mandiri PPDB -->
                        <?php 
                        $stmt_cbt_ppdb = $pdo->prepare("SELECT * FROM exams WHERE is_ppdb = 1 AND status = 'aktif' ORDER BY id DESC LIMIT 1");
                        $stmt_cbt_ppdb->execute();
                        $ppdb_cbt_exam = $stmt_cbt_ppdb->fetch();
                        ?>
                        <?php if ($ppdb_cbt_exam && $search_result['status'] !== 'diterima' && $search_result['status'] !== 'tidak_lulus'): ?>
                            <div class="rounded-3xl border border-indigo-500/30 bg-gradient-to-r from-indigo-950/50 via-slate-900/90 to-purple-950/40 p-5 mb-6 shadow-xl relative overflow-hidden">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                    <div class="flex items-start gap-3.5">
                                        <div class="h-12 w-12 rounded-2xl bg-indigo-500/20 text-indigo-400 flex items-center justify-center shrink-0 text-xl border border-indigo-500/30">
                                            <i class="fa-solid fa-laptop-code"></i>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                                <span class="px-2.5 py-0.5 rounded text-[10px] font-bold uppercase bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                                                    Tahap Seleksi CBT Online
                                                </span>
                                                <?php if ($search_result['cbt_score'] !== null): ?>
                                                    <span class="px-2.5 py-0.5 rounded text-[10px] font-bold uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                                        <i class="fa-solid fa-check mr-1"></i>Selesai Dikerjakan
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2.5 py-0.5 rounded text-[10px] font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/30 animate-pulse">
                                                        <i class="fa-solid fa-bell mr-1"></i>Wajib Dikerjakan
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <h4 class="text-base font-bold text-white"><?= htmlspecialchars($ppdb_cbt_exam['title']) ?></h4>
                                            <p class="text-xs text-slate-300 mt-0.5"><?= htmlspecialchars($ppdb_cbt_exam['description'] ?: 'Ujian online seleksi penerimaan peserta didik baru.') ?></p>
                                            <div class="flex items-center gap-4 text-[11px] text-slate-400 mt-2 font-mono">
                                                <span><i class="fa-regular fa-clock mr-1 text-indigo-400"></i><?= $ppdb_cbt_exam['duration_minutes'] ?> Menit</span>
                                                <span>&bull;</span>
                                                <span><i class="fa-solid fa-bullseye mr-1 text-emerald-400"></i>Passing Grade: <?= $ppdb_cbt_exam['passing_grade'] ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="shrink-0 flex items-center gap-3">
                                        <?php if ($search_result['cbt_score'] === null): ?>
                                            <a href="ppdb_cbt.php?reg=<?= urlencode($search_result['registration_no']) ?>&token=<?= urlencode($search_result['qr_token'] ?? '') ?>" 
                                               class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white text-xs font-bold transition shadow-lg shadow-indigo-600/30 flex items-center gap-2">
                                                <i class="fa-solid fa-play"></i> Mulai Ujian CBT
                                            </a>
                                        <?php else: ?>
                                            <div class="text-right bg-slate-950/60 p-3 rounded-2xl border border-white/5">
                                                <span class="text-[10px] text-slate-400 block uppercase tracking-wider font-bold">Skor CBT Anda</span>
                                                <span class="text-2xl font-black text-emerald-400 font-mono"><?= number_format((float)$search_result['cbt_score'], 1) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Rincian Nilai & Skor -->
                        <div class="rounded-2xl border border-white/5 bg-slate-950/80 p-5 space-y-4 mb-6">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Skor Seleksi Panitia:</span>
                                <span class="text-xl font-black text-emerald-400 font-mono">
                                    <?= $search_result['selection_score'] !== null ? number_format((float)$search_result['selection_score'], 2) . ' / 100' : 'Sedang Dinilai' ?>
                                </span>
                            </div>

                            <!-- Nilai 4 Mapel Pokok -->
                            <div class="grid grid-cols-4 gap-2 pt-2 border-t border-white/5 text-center">
                                <div class="rounded-xl bg-slate-900/60 p-2 border border-white/5">
                                    <span class="text-[10px] text-slate-400 block">Matematika</span>
                                    <span class="text-xs font-mono font-bold text-white"><?= $search_result['score_math'] !== null ? number_format((float)$search_result['score_math'], 1) : '-' ?></span>
                                </div>
                                <div class="rounded-xl bg-slate-900/60 p-2 border border-white/5">
                                    <span class="text-[10px] text-slate-400 block">IPA / Sains</span>
                                    <span class="text-xs font-mono font-bold text-white"><?= $search_result['score_science'] !== null ? number_format((float)$search_result['score_science'], 1) : '-' ?></span>
                                </div>
                                <div class="rounded-xl bg-slate-900/60 p-2 border border-white/5">
                                    <span class="text-[10px] text-slate-400 block">B. Indonesia</span>
                                    <span class="text-xs font-mono font-bold text-white"><?= $search_result['score_indonesian'] !== null ? number_format((float)$search_result['score_indonesian'], 1) : '-' ?></span>
                                </div>
                                <div class="rounded-xl bg-slate-900/60 p-2 border border-white/5">
                                    <span class="text-[10px] text-slate-400 block">B. Inggris</span>
                                    <span class="text-xs font-mono font-bold text-white"><?= $search_result['score_english'] !== null ? number_format((float)$search_result['score_english'], 1) : '-' ?></span>
                                </div>
                            </div>

                            <div class="border-t border-white/5 pt-3">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-1">Catatan Panitia PPDB:</span>
                                <p class="text-sm text-slate-300">
                                    <?= htmlspecialchars($search_result['notes'] ?? 'Belum ada catatan tambahan.') ?>
                                </p>
                            </div>

                            <?php 
                            $saved_achievements = [];
                            if (!empty($search_result['achievements_json'])) {
                                $saved_achievements = json_decode($search_result['achievements_json'], true) ?: [];
                            }
                            ?>
                            <?php if (!empty($saved_achievements)): ?>
                                <div class="border-t border-white/5 pt-3 space-y-2">
                                    <span class="text-xs font-bold uppercase tracking-wider text-amber-400 block flex items-center gap-1.5">
                                        <i class="fa-solid fa-trophy"></i>
                                        <span>Daftar Sertifikat Prestasi (<?= count($saved_achievements) ?> Sertifikat Terlampir):</span>
                                    </span>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <?php foreach ($saved_achievements as $s_idx => $s_ach): ?>
                                            <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-900 border border-white/5 text-xs">
                                                <div class="min-w-0 pr-2">
                                                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-amber-500/20 text-amber-300 mb-0.5">
                                                        Tingkat <?= htmlspecialchars($s_ach['level'] ?? 'Prestasi') ?>
                                                    </span>
                                                    <span class="block font-semibold text-white truncate"><?= htmlspecialchars($s_ach['title'] ?? '-') ?></span>
                                                </div>
                                                <?php if (!empty($s_ach['file'])): ?>
                                                    <a href="../<?= htmlspecialchars($s_ach['file']) ?>" target="_blank" class="shrink-0 text-blue-400 hover:text-blue-300 font-semibold inline-flex items-center gap-1">
                                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Berkas
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Banner Akun Portal Jika Diterima -->
                            <?php if ($search_result['status'] === 'diterima'): ?>
                                <div class="mt-4 rounded-2xl border border-emerald-500/40 bg-gradient-to-br from-emerald-950/60 to-slate-900/90 p-5 space-y-4">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-xl">
                                            <i class="fa-solid fa-award"></i>
                                        </span>
                                        <div>
                                            <h4 class="text-sm font-bold text-white">Selamat! Anda Resmi Diterima sebagai Siswa Baru</h4>
                                            <p class="text-xs text-emerald-300">Akun portal pembelajaran dan administrasi Anda telah aktif.</p>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs bg-slate-950/70 p-4 rounded-xl border border-white/5">
                                        <div>
                                            <span class="text-slate-400 block">Rombongan Belajar / Kelas:</span>
                                            <strong class="text-emerald-400 font-semibold text-sm mt-0.5 block">
                                                <?= htmlspecialchars($search_result['class_name'] ?? 'Kelas X (Menunggu Penempatan)') ?>
                                            </strong>
                                        </div>
                                        <div>
                                            <span class="text-slate-400 block">Email Login Portal:</span>
                                            <strong class="text-white font-mono text-xs mt-0.5 block select-all">
                                                <?= htmlspecialchars($search_result['student_email'] ?? $search_result['email']) ?>
                                            </strong>
                                        </div>
                                        <div>
                                            <span class="text-slate-400 block">Kata Sandi Bawaan:</span>
                                            <span class="font-mono text-amber-300 font-bold select-all">password</span>
                                            <span class="text-[10px] text-slate-500 block">(Ganti sandi saat login pertama)</span>
                                        </div>
                                        <div>
                                            <span class="text-slate-400 block">Akun Portal Wali Murid:</span>
                                            <span class="font-mono text-blue-300 text-xs block select-all">wali.<?= htmlspecialchars($search_result['nisn']) ?>@sekolah.id</span>
                                            <span class="text-[10px] text-slate-500 block">Password: password</span>
                                        </div>
                                    </div>

                                    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2">
                                        <div class="text-[11px] text-slate-400 flex items-center gap-1.5">
                                            <i class="fa-solid fa-circle-check text-emerald-400"></i>
                                            <span>Kredensial aktif. Silakan simpan dan gunakan untuk masuk.</span>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                                            <a href="ppdb_card.php?reg=<?= urlencode($search_result['registration_no']) ?>&token=<?= urlencode($search_result['qr_token'] ?? '') ?>" target="_blank"
                                               class="w-full sm:w-auto inline-flex items-center justify-center gap-1.5 rounded-xl border border-emerald-500/40 bg-emerald-500/10 hover:bg-emerald-500/20 px-4 py-2 text-xs font-bold text-emerald-300 transition">
                                                <i class="fa-solid fa-print"></i> Cetak Surat Penerimaan & Kartu
                                            </a>
                                            <a href="../auth/login.php" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-emerald-600/25 transition">
                                                <span>Masuk ke Portal Siswa</span>
                                                <i class="fa-solid fa-arrow-right"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- MODUL UNGGAH ULANG / PERBAIKAN BERKAS MANDIRI -->
                        <?php if (in_array($search_result['document_status'] ?? '', ['perlu_revisi', 'ditolak'], true) || $search_result['status'] === 'menunggu_verifikasi'): ?>
                            <div class="rounded-2xl border border-amber-500/30 bg-slate-950 p-5 mt-6">
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="text-amber-400"><i class="fa-solid fa-upload"></i></span>
                                    <h4 class="text-sm font-bold text-white">Unggah Ulang / Perbaikan Berkas Dokumen</h4>
                                </div>
                                <p class="text-xs text-slate-400 mb-4">
                                    Jika ada berkas yang buram, tidak terbaca, atau diminta revisi oleh panitia seleksi, Anda dapat mengunggah berkas pengganti langsung melalui formulir di bawah ini.
                                </p>

                                <form action="ppdb.php?tab=cek" method="POST" enctype="multipart/form-data" class="space-y-4">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="reupload_ppdb_doc">
                                    <input type="hidden" name="registration_no" value="<?= htmlspecialchars($search_result['registration_no']) ?>">
                                    <input type="hidden" name="birth_date" value="<?= htmlspecialchars($search_result['birth_date']) ?>">

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Pilih Dokumen yang Ingin Diperbaiki *</label>
                                            <select name="upload_field" required class="w-full rounded-xl border border-white/10 bg-slate-900 px-3.5 py-2 text-xs text-white focus:border-amber-400 focus:outline-none">
                                                <option value="report_card_doc">Scan Rapor (Semester 1-5)</option>
                                                <option value="family_card_doc">Kartu Keluarga (KK)</option>
                                                <option value="birth_cert_doc">Akta Kelahiran</option>
                                                <option value="photo_doc">Pas Foto 3x4</option>
                                                <option value="achievement_cert_doc">Sertifikat / Piagam Prestasi</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-semibold text-slate-300 mb-1.5">Pilih Berkas File Baru (PDF/JPG/PNG, Maks 5MB) *</label>
                                            <input type="file" name="replacement_file" required accept=".pdf,.jpg,.jpeg,.png"
                                                   class="w-full text-xs text-slate-400 file:mr-2 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-white/10 file:text-white hover:file:bg-white/20">
                                        </div>
                                    </div>

                                    <div class="flex justify-end pt-2">
                                        <button type="submit" class="rounded-xl bg-amber-600 hover:bg-amber-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-amber-600/20 transition cursor-pointer flex items-center gap-1.5">
                                            <i class="fa-solid fa-cloud-arrow-up"></i>
                                            <span>Kirim Berkas Pengganti</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </main>

    <?php
    $base_path = '../';
    require_once dirname(__DIR__) . '/includes/footer.php';
    ?>

    <script>
    function toggleTrackInputs() {
        const track = document.getElementById('trackSelect')?.value;
        const zonasiBox = document.getElementById('zonasiBox');
        const prestasiBox = document.getElementById('prestasiBox');
        const afirmasiBox = document.getElementById('afirmasiBox');
        const uploadCertBox = document.getElementById('uploadCertBox');

        if (zonasiBox) zonasiBox.classList.add('hidden');
        if (prestasiBox) prestasiBox.classList.add('hidden');
        if (afirmasiBox) afirmasiBox.classList.add('hidden');

        if (track === 'zonasi' && zonasiBox) {
            zonasiBox.classList.remove('hidden');
        } else if (track === 'prestasi') {
            if (prestasiBox) prestasiBox.classList.remove('hidden');
            if (uploadCertBox) {
                uploadCertBox.classList.remove('border-white/10', 'bg-slate-950/60');
                uploadCertBox.classList.add('border-amber-500/50', 'bg-amber-500/10', 'ring-1', 'ring-amber-500/30');
            }
        } else if (track === 'afirmasi' && afirmasiBox) {
            afirmasiBox.classList.remove('hidden');
        }

        if (track !== 'prestasi' && uploadCertBox) {
            uploadCertBox.classList.remove('border-amber-500/50', 'bg-amber-500/10', 'ring-1', 'ring-amber-500/30');
            uploadCertBox.classList.add('border-white/10', 'bg-slate-950/60');
        }
    }

    // Dynamic Multi-Certificate Handlers (Maksimal 10)
    let certCount = 1;
    const MAX_CERTS = 10;

    function updateCertUI() {
        const container = document.getElementById('certItemsContainer');
        if (!container) return;
        const items = container.querySelectorAll('.cert-item');
        certCount = items.length;
        
        const counter = document.getElementById('certCounter');
        if (counter) counter.innerText = certCount;
        
        const btnAdd = document.getElementById('btnAddCert');
        if (btnAdd) {
            if (certCount >= MAX_CERTS) {
                btnAdd.disabled = true;
                btnAdd.classList.add('opacity-50', 'cursor-not-allowed');
                btnAdd.innerHTML = '<i class="fa-solid fa-ban"></i> Batas Maksimal 10 Sertifikat Tercapai';
            } else {
                btnAdd.disabled = false;
                btnAdd.classList.remove('opacity-50', 'cursor-not-allowed');
                btnAdd.innerHTML = '<i class="fa-solid fa-plus"></i> Tambah Sertifikat Lagi (Maks. 10)';
            }
        }

        items.forEach((item, idx) => {
            const badge = item.querySelector('.cert-badge');
            if (badge) badge.innerHTML = `<i class="fa-solid fa-medal"></i> Sertifikat #${idx + 1}`;
            const btnRemove = item.querySelector('.btn-remove-cert');
            if (btnRemove) {
                if (certCount > 1) {
                    btnRemove.classList.remove('hidden');
                } else {
                    btnRemove.classList.add('hidden');
                }
            }
        });
    }

    function addCertItem() {
        const container = document.getElementById('certItemsContainer');
        if (!container) return;
        const items = container.querySelectorAll('.cert-item');
        if (items.length >= MAX_CERTS) {
            alert('Maksimal penginputan sertifikat adalah 10.');
            return;
        }

        const newIndex = items.length;
        const template = document.createElement('div');
        template.className = 'cert-item rounded-xl border border-white/10 bg-slate-950/80 p-4 space-y-3';
        template.setAttribute('data-index', newIndex);
        template.innerHTML = `
            <div class="flex items-center justify-between text-xs font-semibold text-slate-300">
                <span class="cert-badge flex items-center gap-1.5 text-amber-400">
                    <i class="fa-solid fa-medal"></i> Sertifikat #${newIndex + 1}
                </span>
                <button type="button" onclick="removeCertItem(this)" class="btn-remove-cert text-rose-400 hover:text-rose-300 text-xs font-semibold transition cursor-pointer">
                    <i class="fa-solid fa-trash-can mr-1"></i> Hapus
                </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-semibold text-slate-400 mb-1">Tingkat Kejuaraan *</label>
                    <select name="ach_level[]" class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-amber-400 focus:outline-none">
                        <option value="sekolah">Tingkat Sekolah (+1.5 Poin)</option>
                        <option value="kecamatan">Tingkat Kecamatan (+3.0 Poin)</option>
                        <option value="kabupaten">Tingkat Kota / Kabupaten (+5.0 Poin)</option>
                        <option value="provinsi">Tingkat Provinsi (+7.0 Poin)</option>
                        <option value="nasional">Tingkat Nasional (+10.0 Poin)</option>
                        <option value="internasional">Tingkat Internasional (+15.0 Poin)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-400 mb-1">Nama Lomba / Prestasi *</label>
                    <input type="text" name="ach_desc[]" placeholder="Contoh: Juara 2 Lomba..." 
                           class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-white focus:border-amber-400 focus:outline-none">
                </div>
                <div>
                    <label class="block text-[11px] font-semibold text-slate-400 mb-1">File Bukti Sertifikat (PDF/JPG, Maks 5MB)</label>
                    <input type="file" name="ach_file[]" accept=".pdf,.jpg,.jpeg,.png"
                           class="w-full text-[11px] text-slate-400 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-white/10 file:text-white hover:file:bg-white/20">
                </div>
            </div>
        `;
        container.appendChild(template);
        updateCertUI();
    }

    function removeCertItem(btn) {
        const item = btn.closest('.cert-item');
        if (item) {
            item.remove();
            updateCertUI();
        }
    }

    // ==============================================================
    // MULTI-STEP WIZARD CONTROLLER
    // ==============================================================
    let currentStep = 1;
    const totalSteps = 5;
    const stepTitles = [
        "Biodata Diri",
        "Sekolah & Jalur",
        "Orang Tua / Wali",
        "Upload Berkas",
        "Konfirmasi Data"
    ];

    function goToStep(step) {
        if (step < 1 || step > totalSteps) return;

        // Jika melangkah maju, validasi langkah-langkah sebelumnya
        if (step > currentStep) {
            for (let s = currentStep; s < step; s++) {
                if (!validateCurrentStep(s)) return;
            }
        }

        currentStep = step;

        // 1. Tampilkan section langkah aktif dan sembunyikan yang lain
        for (let i = 1; i <= totalSteps; i++) {
            const section = document.getElementById('stepSection' + i);
            if (section) {
                if (i === currentStep) {
                    section.classList.remove('hidden');
                } else {
                    section.classList.add('hidden');
                }
            }
        }

        // 2. Perbarui tampilan tombol stepper header
        const stepperBtns = document.querySelectorAll('#wizardStepper .step-nav-btn');
        stepperBtns.forEach(btn => {
            const s = parseInt(btn.getAttribute('data-step'));
            const badge = btn.querySelector('.step-badge');
            
            if (s < currentStep) {
                // Selesai (Completed)
                btn.className = 'step-nav-btn flex items-center gap-2.5 rounded-2xl p-2.5 sm:p-3 text-left transition border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 cursor-pointer';
                if (badge) {
                    badge.className = 'step-badge flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 font-bold text-xs border border-emerald-500/30';
                    badge.innerHTML = '<i class="fa-solid fa-check"></i>';
                }
            } else if (s === currentStep) {
                // Aktif Saat Ini (Active)
                btn.className = 'step-nav-btn active flex items-center gap-2.5 rounded-2xl p-2.5 sm:p-3 text-left transition border border-emerald-500/60 bg-emerald-500/20 text-emerald-200 shadow-lg shadow-emerald-500/10 cursor-pointer';
                if (badge) {
                    badge.className = 'step-badge flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-emerald-500 text-slate-950 font-black text-xs shadow-md';
                    badge.innerHTML = s;
                }
            } else {
                // Belum (Pending)
                btn.className = 'step-nav-btn flex items-center gap-2.5 rounded-2xl p-2.5 sm:p-3 text-left transition border border-white/5 bg-slate-950/40 text-slate-400 hover:text-slate-200 cursor-pointer';
                if (badge) {
                    badge.className = 'step-badge flex h-7 w-7 shrink-0 items-center justify-center rounded-xl bg-white/10 text-slate-400 font-bold text-xs';
                    badge.innerHTML = s;
                }
            }
        });

        // 3. Perbarui ProgressBar & Label
        const progressBar = document.getElementById('stepProgressBar');
        if (progressBar) {
            const pct = (currentStep / totalSteps) * 100;
            progressBar.style.width = pct + '%';
        }

        const pillBadge = document.getElementById('stepPillBadge');
        if (pillBadge) {
            pillBadge.textContent = `Tahap ${currentStep} dari ${totalSteps}`;
        }

        const stepIndicatorLabel = document.getElementById('stepIndicatorLabel');
        if (stepIndicatorLabel) {
            stepIndicatorLabel.textContent = `Langkah ${currentStep}: ${stepTitles[currentStep - 1]}`;
        }

        // 4. Perbarui Tombol Navigasi Bawah
        const btnPrev = document.getElementById('btnPrevStep');
        const btnNext = document.getElementById('btnNextStep');
        const btnSubmit = document.getElementById('btnFinalSubmit');

        if (btnPrev) {
            if (currentStep === 1) {
                btnPrev.classList.add('hidden');
            } else {
                btnPrev.classList.remove('hidden');
            }
        }

        if (btnNext && btnSubmit) {
            if (currentStep < totalSteps) {
                btnNext.classList.remove('hidden');
                btnNext.innerHTML = `<span>Lanjutkan ke Tahap ${currentStep + 1}</span> <i class="fa-solid fa-arrow-right"></i>`;
                btnSubmit.classList.add('hidden');
            } else {
                btnNext.classList.add('hidden');
                btnSubmit.classList.remove('hidden');
                populateConfirmationSummary();
            }
        }

        // Scroll halus ke puncak form
        const form = document.getElementById('ppdbRegistrationForm');
        if (form && step > 1) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function navigateStep(direction) {
        if (direction > 0) {
            if (!validateCurrentStep(currentStep)) return;
            goToStep(currentStep + 1);
        } else {
            goToStep(currentStep - 1);
        }
    }

    function validateCurrentStep(stepNum) {
        const section = document.getElementById('stepSection' + stepNum);
        if (!section) return true;

        const inputs = section.querySelectorAll('input, select, textarea');
        for (let input of inputs) {
            // Hanya periksa elemen yang terlihat (tidak di-hidden oleh style/sub-form)
            if (input.offsetParent !== null && !input.checkValidity()) {
                input.reportValidity();
                input.focus();
                return false;
            }
        }

        // Validasi kustom tambahan untuk NISN 10 digit di Langkah 1
        if (stepNum === 1) {
            const nisnInput = document.getElementById('inp_nisn');
            if (nisnInput && !/^[0-9]{10}$/.test(nisnInput.value.trim())) {
                nisnInput.setCustomValidity('NISN wajib terdiri dari 10 digit angka numerik.');
                nisnInput.reportValidity();
                nisnInput.focus();
                return false;
            } else if (nisnInput) {
                nisnInput.setCustomValidity('');
            }
        }

        return true;
    }

    // Pasang listener pada tombol stepper di header
    document.addEventListener('DOMContentLoaded', function() {
        const stepperBtns = document.querySelectorAll('#wizardStepper .step-nav-btn');
        stepperBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const targetStep = parseInt(this.getAttribute('data-step'));
                goToStep(targetStep);
            });
        });

        setupDragAndDropZones();
        toggleTrackInputs();
    });

    // ==============================================================
    // POPULATE RINGKASAN DATA (TAHAP 5)
    // ==============================================================
    function populateConfirmationSummary() {
        // 1. Biodata Siswa
        const nameVal = document.getElementById('inp_full_name')?.value || '-';
        const nisnVal = document.getElementById('inp_nisn')?.value || '-';
        const nikVal = document.getElementById('inp_nik')?.value || '(Tidak diisi)';
        const genderVal = document.getElementById('inp_gender')?.value === 'L' ? 'Laki-Laki (L)' : 'Perempuan (P)';
        const religionVal = document.getElementById('inp_religion')?.value || '-';
        const placeVal = document.getElementById('inp_birth_place')?.value || '-';
        const dateVal = document.getElementById('regBirthDate')?.value || '-';
        const phoneVal = document.getElementById('inp_phone')?.value || '-';
        const emailVal = document.getElementById('inp_email')?.value || '-';
        const addressVal = document.getElementById('inp_address')?.value || '-';

        setText('sum_name', nameVal);
        setText('sum_nisn', nisnVal);
        setText('sum_nik', nikVal);
        setText('sum_gender', genderVal);
        setText('sum_religion', religionVal);
        setText('sum_ttl', `${placeVal}, ${dateVal}`);
        setText('sum_contact', `${phoneVal} / ${emailVal}`);
        setText('sum_address', addressVal);

        // 2. Sekolah & Jurusan
        const schoolVal = document.getElementById('inp_previous_school')?.value || '-';
        const majorVal = document.getElementById('inp_chosen_major')?.value || '-';
        const trackSelect = document.getElementById('trackSelect');
        const trackVal = trackSelect ? trackSelect.options[trackSelect.selectedIndex].text : '-';
        const trackKey = trackSelect?.value || 'reguler';

        setText('sum_school', schoolVal);
        setText('sum_major', majorVal);
        setText('sum_track', trackVal);

        const trackDetailEl = document.getElementById('sum_track_detail');
        if (trackDetailEl) {
            if (trackKey === 'zonasi') {
                const dist = document.getElementById('distanceKmInput')?.value || '0';
                trackDetailEl.innerHTML = `<i class="fa-solid fa-location-dot text-emerald-400 mr-1"></i> Jarak Domisili: <strong>${dist} KM</strong>`;
            } else if (trackKey === 'afirmasi') {
                const kip = document.getElementById('inp_affirmation_no')?.value || '-';
                trackDetailEl.innerHTML = `<i class="fa-solid fa-id-card-clip text-purple-400 mr-1"></i> No KIP/Bansos: <strong>${kip}</strong>`;
            } else if (trackKey === 'prestasi') {
                trackDetailEl.innerHTML = `<i class="fa-solid fa-trophy text-amber-400 mr-1"></i> Sertifikat prestasi telah dilampirkan`;
            } else {
                trackDetailEl.textContent = 'Seleksi nilai rapor 4 mapel semester 1-5';
            }
        }

        // 3. Orang Tua
        const parentName = document.getElementById('inp_parent_name')?.value || '-';
        const parentPhone = document.getElementById('inp_parent_phone')?.value || '-';
        const parentJob = document.getElementById('inp_parent_job')?.value || '(Tidak diisi)';

        setText('sum_parent_name', parentName);
        setText('sum_parent_phone', parentPhone);
        setText('sum_parent_job', parentJob);

        // 4. Status Berkas Dokumen
        const berkasContainer = document.getElementById('summaryBerkas');
        if (berkasContainer) {
            berkasContainer.innerHTML = '';
            const docList = [
                { id: 'file_report_card_doc', label: 'Scan Rapor SMP' },
                { id: 'file_family_card_doc', label: 'Kartu Keluarga (KK)' },
                { id: 'file_birth_cert_doc', label: 'Akta Kelahiran' },
                { id: 'file_photo_doc', label: 'Pas Foto 3x4' },
                { id: 'achievementCertDocInput', label: 'Sertifikat Prestasi' }
            ];

            docList.forEach(item => {
                const fileInput = document.getElementById(item.id);
                const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
                const li = document.createElement('li');
                li.className = 'flex items-center justify-between py-1 border-b border-white/5 last:border-0';
                
                if (hasFile) {
                    const f = fileInput.files[0];
                    const sizeKB = (f.size / 1024).toFixed(1);
                    li.innerHTML = `
                        <span class="text-emerald-300 font-medium flex items-center gap-1.5">
                            <i class="fa-solid fa-circle-check text-emerald-400"></i> ${item.label}
                        </span>
                        <span class="font-mono text-slate-300 truncate max-w-[160px]" title="${f.name}">
                            ${f.name} (${sizeKB} KB)
                        </span>
                    `;
                } else {
                    li.innerHTML = `
                        <span class="text-slate-400 flex items-center gap-1.5">
                            <i class="fa-regular fa-circle text-slate-500"></i> ${item.label}
                        </span>
                        <span class="text-slate-500 italic text-[10px]">Belum diunggah</span>
                    `;
                }
                berkasContainer.appendChild(li);
            });
        }
    }

    function setText(id, text) {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    // ==============================================================
    // DRAG & DROP DAN LIVE PREVIEW UNTUK UPLOAD BERKAS
    // ==============================================================
    function setupDragAndDropZones() {
        const dropZones = document.querySelectorAll('.drop-zone-card');

        dropZones.forEach(card => {
            const fileInput = card.querySelector('.file-input-target');
            const dropArea = card.querySelector('.drop-area');
            const defaultView = card.querySelector('.default-view');
            const previewView = card.querySelector('.preview-view');
            const previewThumb = card.querySelector('.preview-thumbnail');
            const previewName = card.querySelector('.preview-filename');
            const previewSize = card.querySelector('.preview-filesize');
            const btnClear = card.querySelector('.btn-clear-file');

            if (!fileInput || !dropArea) return;

            // Klik area drop memicu input file
            dropArea.addEventListener('click', function(e) {
                if (e.target.closest('.btn-clear-file')) return;
                fileInput.click();
            });

            // Tombol Ganti / Hapus File
            if (btnClear) {
                btnClear.addEventListener('click', function(e) {
                    e.stopPropagation();
                    fileInput.value = '';
                    if (previewView) previewView.classList.add('hidden');
                    if (defaultView) defaultView.classList.remove('hidden');
                });
            }

            // Drag Over
            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropArea.classList.add('border-emerald-400', 'bg-emerald-500/10');
                });
            });

            // Drag Leave
            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropArea.classList.remove('border-emerald-400', 'bg-emerald-500/10');
                });
            });

            // Drop File Handler
            dropArea.addEventListener('drop', function(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files && files.length > 0) {
                    fileInput.files = files;
                    renderFilePreview(files[0], defaultView, previewView, previewThumb, previewName, previewSize);
                }
            });

            // File Input Change Handler
            fileInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    renderFilePreview(this.files[0], defaultView, previewView, previewThumb, previewName, previewSize);
                }
            });
        });
    }

    function renderFilePreview(file, defaultView, previewView, previewThumb, previewName, previewSize) {
        if (!file) return;

        // Validasi ukuran maksimal 5MB
        if (file.size > 5 * 1024 * 1024) {
            alert('Ukuran berkas ' + file.name + ' melebihi batas maksimal 5MB.');
            return;
        }

        const sizeInKB = (file.size / 1024).toFixed(1);
        const sizeFormatted = file.size > 1024 * 1024 
            ? (file.size / (1024 * 1024)).toFixed(2) + ' MB' 
            : sizeInKB + ' KB';

        if (previewName) previewName.textContent = file.name;
        if (previewSize) previewSize.textContent = sizeFormatted;

        if (previewThumb) {
            previewThumb.innerHTML = '';
            if (file.type.startsWith('image/')) {
                const img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.className = 'w-full h-full object-cover rounded-xl';
                img.onload = function() {
                    URL.revokeObjectURL(this.src); // Bersihkan memori blob
                };
                previewThumb.appendChild(img);
            } else if (file.type === 'application/pdf' || file.name.endsWith('.pdf')) {
                previewThumb.innerHTML = '<i class="fa-solid fa-file-pdf text-rose-500 text-2xl"></i>';
            } else {
                previewThumb.innerHTML = '<i class="fa-solid fa-file text-blue-400 text-2xl"></i>';
            }
        }

        if (defaultView) defaultView.classList.add('hidden');
        if (previewView) previewView.classList.remove('hidden');
    }
    </script>

</body>
</html>
