<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff  = in_array($user_role, ['staf', 'administrator', 'guru'], true);
$school    = getSchoolSettings($pdo);

$message      = "";
$message_type = "";

// -------------------------------------------------------------
// 1. EKSPOR DATA KE CSV (Untuk Bukti Portofolio Akreditasi BAN-SM)
// -------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!$is_staff) {
        die("Akses ditolak. Hanya staf dan pimpinan yang dapat mengunduh berkas akreditasi.");
    }

    $filter_year = trim($_GET['year'] ?? '');
    $filter_act  = trim($_GET['activity'] ?? '');

    $sql = "SELECT * FROM `alumni_tracer_studies` WHERE 1=1";
    $params = [];
    if (!empty($filter_year)) {
        $sql .= " AND graduation_year = ?";
        $params[] = $filter_year;
    }
    if (!empty($filter_act)) {
        $sql .= " AND current_activity = ?";
        $params[] = $filter_act;
    }
    $sql .= " ORDER BY graduation_year DESC, submitted_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data_csv = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_tracer_study_akreditasi_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');

    // BOM UTF-8 for Excel support
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['INSTRUMEN TRACER STUDY & JEJAK KARIR ALUMNI (STANDAR AKREDITASI BAN-SM)']);
    fputcsv($output, ['Nama Satuan Pendidikan', $school['school_name'] ?? 'Sekolah Terpadu']);
    fputcsv($output, ['NPSN', $school['npsn'] ?? '-']);
    fputcsv($output, ['Tanggal Ekspor', date('d/m/Y H:i:s') . ' WIB']);
    fputcsv($output, ['Petugas Pengekspor', $_SESSION['user_name'] ?? 'Admin']);
    fputcsv($output, ['Total Responden Terdata', count($data_csv)]);
    fputcsv($output, []); // Baris Kosong

    fputcsv($output, [
        'No',
        'Nama Lengkap Alumni',
        'NISN',
        'Tahun Kelulusan',
        'Nomor WhatsApp/HP',
        'Email',
        'Aktivitas Saat Ini',
        'Nama Kampus / Universitas',
        'Program Studi / Jurusan',
        'Jenjang Studi',
        'Jalur Penerimaan',
        'Nama Perusahaan / Instansi',
        'Jabatan / Posisi',
        'Sektor Industri',
        'Estimasi Rentang Gaji',
        'Nama Usaha (Wirausaha)',
        'Bidang Usaha',
        'Estimasi Omset Usaha',
        'Tingkat Keselarasan Ilmu',
        'Testimoni & Saran',
        'Waktu Pengisian'
    ]);

    $no = 1;
    foreach ($data_csv as $row) {
        $activity_label = match($row['current_activity']) {
            'kuliah'        => 'Kuliah / Studi Lanjut',
            'bekerja'       => 'Bekerja / Karyawan',
            'wirausaha'     => 'Wirausaha / Bisnis Mandiri',
            'mencari_kerja' => 'Masa Tunggu / Mencari Kerja',
            default         => ucfirst($row['current_activity'])
        };

        $alignment_label = match($row['alignment']) {
            'sangat_selaras' => 'Sangat Selaras',
            'selaras'        => 'Selaras',
            'kurang_selaras' => 'Kurang Selaras',
            'tidak_selaras'  => 'Tidak Selaras',
            default          => '-'
        };

        fputcsv($output, [
            $no++,
            $row['full_name'],
            $row['nisn'] ?: '-',
            $row['graduation_year'],
            $row['phone'] ?: '-',
            $row['email'] ?: '-',
            $activity_label,
            $row['campus_name'] ?: '-',
            $row['campus_major'] ?: '-',
            $row['degree_level'] ?: '-',
            $row['admission_track'] ?: '-',
            $row['company_name'] ?: '-',
            $row['job_title'] ?: '-',
            $row['job_sector'] ?: '-',
            $row['income_range'] ?: '-',
            $row['business_name'] ?: '-',
            $row['business_field'] ?: '-',
            $row['business_revenue'] ?: '-',
            $alignment_label,
            $row['testimonial'] ?: '-',
            $row['submitted_at']
        ]);
    }

    fclose($output);
    exit;
}

// -------------------------------------------------------------
// 2. PROSES SUBMIT / PERBARUI DATA TRACER STUDY ALUMNI
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_tracer') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan coba kembali.";
        $message_type = "error";
    } else {
        $full_name        = trim($_POST['full_name'] ?? '');
        $nisn             = trim($_POST['nisn'] ?? '');
        $graduation_year  = trim($_POST['graduation_year'] ?? '');
        $phone            = trim($_POST['phone'] ?? '');
        $email            = trim($_POST['email'] ?? '');
        $current_activity = trim($_POST['current_activity'] ?? 'kuliah');
        $alignment        = trim($_POST['alignment'] ?? 'selaras');
        $testimonial      = trim($_POST['testimonial'] ?? '');

        // Spesifik Kuliah
        $campus_name     = trim($_POST['campus_name'] ?? '');
        $campus_major    = trim($_POST['campus_major'] ?? '');
        $degree_level    = trim($_POST['degree_level'] ?? '');
        $admission_track = trim($_POST['admission_track'] ?? '');

        // Spesifik Bekerja
        $company_name = trim($_POST['company_name'] ?? '');
        $job_title    = trim($_POST['job_title'] ?? '');
        $job_sector   = trim($_POST['job_sector'] ?? '');
        $income_range = trim($_POST['income_range'] ?? '');

        // Spesifik Wirausaha
        $business_name    = trim($_POST['business_name'] ?? '');
        $business_field   = trim($_POST['business_field'] ?? '');
        $business_revenue = trim($_POST['business_revenue'] ?? '');

        if (empty($full_name) || empty($graduation_year) || empty($current_activity)) {
            $message = "Nama lengkap, tahun kelulusan, dan status kegiatan saat ini wajib diisi.";
            $message_type = "error";
        } else {
            try {
                // Cek apakah data alumni ini sudah pernah mengisi sebelumnya (berdasarkan user_id atau NISN)
                $existing_id = null;
                if ($user_role === 'siswa' && $user_id > 0) {
                    $stmt_chk = $pdo->prepare("SELECT id FROM `alumni_tracer_studies` WHERE user_id = ? LIMIT 1");
                    $stmt_chk->execute([$user_id]);
                    $existing_id = $stmt_chk->fetchColumn();
                }

                if (!$existing_id && !empty($nisn)) {
                    $stmt_chk_nisn = $pdo->prepare("SELECT id FROM `alumni_tracer_studies` WHERE nisn = ? LIMIT 1");
                    $stmt_chk_nisn->execute([$nisn]);
                    $existing_id = $stmt_chk_nisn->fetchColumn();
                }

                // Cek apakah tercatat di alumni_records
                $alumni_rec_id = null;
                if (!empty($nisn)) {
                    $stmt_al = $pdo->prepare("SELECT id FROM `alumni_records` WHERE nisn = ? LIMIT 1");
                    $stmt_al->execute([$nisn]);
                    $alumni_rec_id = $stmt_al->fetchColumn() ?: null;
                }

                if ($existing_id) {
                    // Update data yang sudah ada
                    $stmt_up = $pdo->prepare("
                        UPDATE `alumni_tracer_studies` SET
                            `full_name` = ?,
                            `nisn` = ?,
                            `graduation_year` = ?,
                            `phone` = ?,
                            `email` = ?,
                            `current_activity` = ?,
                            `campus_name` = ?,
                            `campus_major` = ?,
                            `degree_level` = ?,
                            `admission_track` = ?,
                            `company_name` = ?,
                            `job_title` = ?,
                            `job_sector` = ?,
                            `income_range` = ?,
                            `business_name` = ?,
                            `business_field` = ?,
                            `business_revenue` = ?,
                            `alignment` = ?,
                            `testimonial` = ?,
                            `submitted_at` = NOW()
                        WHERE `id` = ?
                    ");
                    $stmt_up->execute([
                        $full_name, $nisn ?: null, $graduation_year, $phone ?: null, $email ?: null,
                        $current_activity,
                        $campus_name ?: null, $campus_major ?: null, $degree_level ?: null, $admission_track ?: null,
                        $company_name ?: null, $job_title ?: null, $job_sector ?: null, $income_range ?: null,
                        $business_name ?: null, $business_field ?: null, $business_revenue ?: null,
                        $alignment, $testimonial ?: null,
                        $existing_id
                    ]);

                    logActivity($pdo, 'UPDATE_TRACER_STUDY', "Memperbarui tracer study alumni: $full_name ($graduation_year)");
                    $message = "Terima kasih! Pembaruan data tracer study dan jejak karir Anda berhasil disimpan.";
                    $message_type = "success";
                } else {
                    // Simpan data baru
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO `alumni_tracer_studies`
                        (`alumni_id`, `user_id`, `full_name`, `nisn`, `graduation_year`, `phone`, `email`, `current_activity`,
                         `campus_name`, `campus_major`, `degree_level`, `admission_track`,
                         `company_name`, `job_title`, `job_sector`, `income_range`,
                         `business_name`, `business_field`, `business_revenue`, `alignment`, `testimonial`)
                        VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt_ins->execute([
                        $alumni_rec_id, ($user_role === 'siswa' ? $user_id : null),
                        $full_name, $nisn ?: null, $graduation_year, $phone ?: null, $email ?: null,
                        $current_activity,
                        $campus_name ?: null, $campus_major ?: null, $degree_level ?: null, $admission_track ?: null,
                        $company_name ?: null, $job_title ?: null, $job_sector ?: null, $income_range ?: null,
                        $business_name ?: null, $business_field ?: null, $business_revenue ?: null,
                        $alignment, $testimonial ?: null
                    ]);

                    logActivity($pdo, 'SUBMIT_TRACER_STUDY', "Mengisi tracer study alumni baru: $full_name ($graduation_year - $current_activity)");
                    $message = "Terima kasih! Data jejak karir Anda telah berhasil direkam ke dalam basis data akreditasi sekolah.";
                    $message_type = "success";
                }
            } catch (Exception $e) {
                $message = "Terjadi kesalahan saat menyimpan data: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// 3. PROSES HAPUS DATA TRACER (Khusus Administrator)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_tracer') {
    if (!$is_staff) {
        $message = "Akses ditolak.";
        $message_type = "error";
    } elseif (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $del_id = (int)($_POST['tracer_id'] ?? 0);
        if ($del_id > 0) {
            $stmt_del = $pdo->prepare("DELETE FROM `alumni_tracer_studies` WHERE id = ?");
            $stmt_del->execute([$del_id]);
            logActivity($pdo, 'DELETE_TRACER_STUDY', "Menghapus entri tracer study ID: $del_id");
            $message = "Entri tracer study berhasil dihapus dari arsip.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 4. QUERY AGREGASI & INDIKATOR AKREDITASI BAN-SM
// -------------------------------------------------------------
$total_alumni_pool = (int)$pdo->query("SELECT COUNT(*) FROM `alumni_records`")->fetchColumn();
if ($total_alumni_pool === 0) {
    // Fallback: hitung siswa berstatus lulus dari users
    $total_alumni_pool = (int)$pdo->query("SELECT COUNT(*) FROM `users` WHERE role = 'siswa' AND status = 'lulus'")->fetchColumn();
}
if ($total_alumni_pool === 0) {
    $total_alumni_pool = 12; // Basis sampel minimum agar rasio tetap representatif jika baru inisialisasi
}

$total_responden = (int)$pdo->query("SELECT COUNT(*) FROM `alumni_tracer_studies`")->fetchColumn();
$response_rate   = ($total_alumni_pool > 0) ? round(($total_responden / max($total_alumni_pool, $total_responden)) * 100, 1) : 0;

// Breakdown Aktivitas
$stats_activity = [
    'kuliah'        => 0,
    'bekerja'       => 0,
    'wirausaha'     => 0,
    'mencari_kerja' => 0,
    'lainnya'       => 0
];
$stmt_act = $pdo->query("SELECT current_activity, COUNT(*) as cnt FROM `alumni_tracer_studies` GROUP BY current_activity");
while ($row = $stmt_act->fetch(PDO::FETCH_ASSOC)) {
    $act_key = $row['current_activity'];
    if (isset($stats_activity[$act_key])) {
        $stats_activity[$act_key] = (int)$row['cnt'];
    }
}

$pct_kuliah        = $total_responden > 0 ? round(($stats_activity['kuliah'] / $total_responden) * 100, 1) : 0;
$pct_bekerja       = $total_responden > 0 ? round(($stats_activity['bekerja'] / $total_responden) * 100, 1) : 0;
$pct_wirausaha     = $total_responden > 0 ? round(($stats_activity['wirausaha'] / $total_responden) * 100, 1) : 0;
$pct_mencari_kerja = $total_responden > 0 ? round(($stats_activity['mencari_kerja'] / $total_responden) * 100, 1) : 0;

// Indeks Keselarasan (Alignment)
$stats_alignment = [
    'sangat_selaras' => 0,
    'selaras'        => 0,
    'kurang_selaras' => 0,
    'tidak_selaras'  => 0
];
$stmt_align = $pdo->query("SELECT alignment, COUNT(*) as cnt FROM `alumni_tracer_studies` GROUP BY alignment");
while ($row = $stmt_align->fetch(PDO::FETCH_ASSOC)) {
    $al_key = $row['alignment'];
    if (isset($stats_alignment[$al_key])) {
        $stats_alignment[$al_key] = (int)$row['cnt'];
    }
}
$selaras_count = $stats_alignment['sangat_selaras'] + $stats_alignment['selaras'];
$pct_selaras   = $total_responden > 0 ? round(($selaras_count / $total_responden) * 100, 1) : 0;

// Filter Data Tabel
$filter_year = trim($_GET['year'] ?? '');
$filter_act  = trim($_GET['activity'] ?? '');
$search_q    = trim($_GET['q'] ?? '');

$sql_list = "SELECT * FROM `alumni_tracer_studies` WHERE 1=1";
$params_list = [];

if (!empty($filter_year)) {
    $sql_list .= " AND graduation_year = ?";
    $params_list[] = $filter_year;
}
if (!empty($filter_act)) {
    $sql_list .= " AND current_activity = ?";
    $params_list[] = $filter_act;
}
if (!empty($search_q)) {
    $sql_list .= " AND (full_name LIKE ? OR campus_name LIKE ? OR company_name LIKE ? OR nisn LIKE ?)";
    $like = "%$search_q%";
    $params_list[] = $like;
    $params_list[] = $like;
    $params_list[] = $like;
    $params_list[] = $like;
}

$sql_list .= " ORDER BY graduation_year DESC, submitted_at DESC LIMIT 100";
$stmt_list = $pdo->prepare($sql_list);
$stmt_list->execute($params_list);
$tracer_list = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

// Data profil user saat ini (untuk autofill form jika login sebagai alumni/siswa)
$user_info = null;
$my_submission = null;
if ($user_role === 'siswa') {
    $stmt_u = $pdo->prepare("SELECT * FROM `users` WHERE id = ?");
    $stmt_u->execute([$user_id]);
    $user_info = $stmt_u->fetch(PDO::FETCH_ASSOC);

    $stmt_my = $pdo->prepare("SELECT * FROM `alumni_tracer_studies` WHERE user_id = ? OR (nisn = ? AND nisn IS NOT NULL AND nisn != '') LIMIT 1");
    $stmt_my->execute([$user_id, $user_info['nisn'] ?? '']);
    $my_submission = $stmt_my->fetch(PDO::FETCH_ASSOC);
}

// Opsi Tahun Kelulusan yang ada di database
$years_available = $pdo->query("SELECT DISTINCT graduation_year FROM `alumni_tracer_studies` WHERE graduation_year IS NOT NULL AND graduation_year != '' ORDER BY graduation_year DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($years_available)) {
    $years_available = [date('Y'), date('Y') - 1, date('Y') - 2];
}

$page_title = "Tracer Study & Jejak Karir Alumni";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">
    <!-- Header Banner -->
    <div class="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-r from-blue-900/40 via-indigo-900/40 to-slate-900/60 p-6 md:p-8 backdrop-blur-xl shadow-2xl">
        <div class="absolute -right-10 -top-10 h-64 w-64 rounded-full bg-blue-500/10 blur-3xl pointer-events-none"></div>
        <div class="absolute right-40 -bottom-10 h-48 w-48 rounded-full bg-indigo-500/10 blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
            <div>
                <div class="flex items-center gap-3">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-500 text-white shadow-lg shadow-blue-500/25 border border-white/20">
                        <i class="fa-solid fa-user-graduate text-xl"></i>
                    </span>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-2xl md:text-3xl font-bold tracking-tight text-white">Tracer Study & Jejak Karir Alumni</h1>
                            <span class="inline-flex items-center gap-1 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-semibold text-emerald-400">
                                <i class="fa-solid fa-award text-[10px]"></i> Standar BAN-SM
                            </span>
                        </div>
                        <p class="text-sm text-slate-400 mt-1">
                            Sistem pemantauan lulusan, keterserapan kerja (BMW: Bekerja, Melanjutkan, Wirausaha), dan keselarasan kurikulum sekolah.
                        </p>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <?php if ($is_staff): ?>
                    <a href="?export=csv<?= !empty($filter_year) ? '&year='.urlencode($filter_year) : '' ?><?= !empty($filter_act) ? '&activity='.urlencode($filter_act) : '' ?>" 
                       class="inline-flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-4 py-2.5 text-sm font-semibold text-emerald-300 shadow-sm transition">
                        <i class="fa-solid fa-file-excel text-emerald-400"></i> Ekspor Akreditasi BAN-SM (CSV)
                    </a>
                <?php endif; ?>

                <button onclick="document.getElementById('modalTracerForm').classList.remove('hidden')"
                        class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/25 transition cursor-pointer">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <?= ($user_role === 'siswa' && $my_submission) ? 'Perbarui Data Saya' : 'Isi Form Tracer Study' ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Alert Notifikasi -->
    <?php if ($message): ?>
        <div class="rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
            <div class="flex items-center gap-3">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-circle-exclamation text-rose-400' ?> text-lg"></i>
                <span><?= $message ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <!-- Status Pengisian untuk Siswa / Alumni Login -->
    <?php if ($user_role === 'siswa' && $my_submission): ?>
        <div class="rounded-2xl border border-blue-500/30 bg-blue-500/10 p-5 backdrop-blur-md">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3.5">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white shadow-md shadow-blue-600/30">
                        <i class="fa-solid fa-id-card-clip"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h4 class="font-bold text-white text-base">Status Tracer Anda: <span class="text-blue-300">Terdaftar</span></h4>
                            <span class="rounded-md bg-emerald-500/20 px-2 py-0.5 text-[11px] font-bold text-emerald-400 border border-emerald-500/30">Aktif</span>
                        </div>
                        <p class="text-xs text-slate-300 mt-0.5">
                            Aktivitas: <strong class="text-white capitalize"><?= htmlspecialchars($my_submission['current_activity']) ?></strong> 
                            <?php if ($my_submission['current_activity'] === 'kuliah' && $my_submission['campus_name']): ?>
                                di <?= htmlspecialchars($my_submission['campus_name']) ?> (<?= htmlspecialchars($my_submission['campus_major']) ?>)
                            <?php elseif ($my_submission['current_activity'] === 'bekerja' && $my_submission['company_name']): ?>
                                di <?= htmlspecialchars($my_submission['company_name']) ?> (<?= htmlspecialchars($my_submission['job_title']) ?>)
                            <?php elseif ($my_submission['current_activity'] === 'wirausaha' && $my_submission['business_name']): ?>
                                usaha <?= htmlspecialchars($my_submission['business_name']) ?>
                            <?php endif; ?>
                            • Terakhir diperbarui: <?= date('d M Y, H:i', strtotime($my_submission['submitted_at'])) ?>
                        </p>
                    </div>
                </div>
                <button onclick="document.getElementById('modalTracerForm').classList.remove('hidden')" 
                        class="inline-flex items-center gap-1.5 rounded-xl border border-blue-400/40 bg-blue-600/20 hover:bg-blue-600/30 px-3.5 py-1.5 text-xs font-semibold text-blue-200 transition">
                    <i class="fa-solid fa-pencil text-[11px]"></i> Edit Data Karir
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI & METRIK UTAMA (STANDAR AKREDITASI BAN-SM) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <!-- Card 1: Total Alumni & Respon -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur-xl shadow-xl hover:border-blue-500/30 transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Total Responden</span>
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400 border border-blue-500/20">
                    <i class="fa-solid fa-users-viewfinder text-sm"></i>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black text-white"><?= number_format($total_responden) ?></span>
                <span class="text-xs text-slate-400">dari <?= number_format($total_alumni_pool) ?> alumni</span>
            </div>
            <div class="mt-3">
                <div class="flex items-center justify-between text-xs text-slate-400 mb-1">
                    <span>Response Rate</span>
                    <span class="font-bold text-blue-400"><?= $response_rate ?>%</span>
                </div>
                <div class="h-2 w-full rounded-full bg-slate-800 overflow-hidden">
                    <div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-500 transition-all duration-500" style="width: <?= min(100, $response_rate) ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Card 2: Lanjut Kuliah (PTN / PTS) -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur-xl shadow-xl hover:border-cyan-500/30 transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Lanjut Kuliah (Studi)</span>
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-cyan-500/10 text-cyan-400 border border-cyan-500/20">
                    <i class="fa-solid fa-graduation-cap text-sm"></i>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black text-white"><?= $stats_activity['kuliah'] ?></span>
                <span class="text-xs font-bold text-cyan-400">(<?= $pct_kuliah ?>%)</span>
            </div>
            <p class="mt-2 text-xs text-slate-400 flex items-center gap-1.5">
                <i class="fa-solid fa-building-columns text-[11px] text-cyan-400"></i> PTN/PTS/Kedinasan/Luar Negeri
            </p>
            <div class="mt-2.5 h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
                <div class="h-full rounded-full bg-cyan-500" style="width: <?= $pct_kuliah ?>%"></div>
            </div>
        </div>

        <!-- Card 3: Bekerja & Wirausaha -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur-xl shadow-xl hover:border-emerald-500/30 transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Karier (Kerja & Bisnis)</span>
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    <i class="fa-solid fa-briefcase text-sm"></i>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black text-white"><?= $stats_activity['bekerja'] + $stats_activity['wirausaha'] ?></span>
                <span class="text-xs font-bold text-emerald-400">(<?= round($pct_bekerja + $pct_wirausaha, 1) ?>%)</span>
            </div>
            <p class="mt-2 text-xs text-slate-400 flex items-center gap-1.5">
                <i class="fa-solid fa-check-circle text-[11px] text-emerald-400"></i> Bekerja: <?= $stats_activity['bekerja'] ?> • Usaha: <?= $stats_activity['wirausaha'] ?>
            </p>
            <div class="mt-2.5 h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
                <div class="h-full rounded-full bg-emerald-500" style="width: <?= round($pct_bekerja + $pct_wirausaha, 1) ?>%"></div>
            </div>
        </div>

        <!-- Card 4: Indeks Keselarasan BAN-SM -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur-xl shadow-xl hover:border-purple-500/30 transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Keselarasan Kurikulum</span>
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-purple-500/10 text-purple-400 border border-purple-500/20">
                    <i class="fa-solid fa-bullseye text-sm"></i>
                </span>
            </div>
            <div class="mt-3 flex items-baseline gap-2">
                <span class="text-3xl font-black text-white"><?= $pct_selaras ?>%</span>
                <span class="text-xs font-semibold text-purple-300">Selaras</span>
            </div>
            <p class="mt-2 text-xs text-slate-400 flex items-center gap-1.5">
                <i class="fa-solid fa-shield-halved text-[11px] text-purple-400"></i> Standar Akreditasi Butir Lulusan
            </p>
            <div class="mt-2.5 h-1.5 w-full rounded-full bg-slate-800 overflow-hidden">
                <div class="h-full rounded-full bg-purple-500" style="width: <?= $pct_selaras ?>%"></div>
            </div>
        </div>
    </div>

    <!-- DISTRIBUSI BMW & KESELARASAN VISUAL BAR -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- BMW Distribution -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-6 backdrop-blur-xl shadow-xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-chart-pie text-blue-400"></i> Distribusi Keterserapan Lulusan (BMW)
                </h3>
                <span class="text-xs font-semibold text-slate-400">Basis Responden: <?= $total_responden ?></span>
            </div>

            <!-- Segmented Multi-Color Progress Bar -->
            <div class="h-4 w-full rounded-full bg-slate-800 overflow-hidden flex shadow-inner">
                <div class="bg-cyan-500 transition-all hover:opacity-80" style="width: <?= $pct_kuliah ?>%" title="Kuliah: <?= $pct_kuliah ?>%"></div>
                <div class="bg-blue-600 transition-all hover:opacity-80" style="width: <?= $pct_bekerja ?>%" title="Bekerja: <?= $pct_bekerja ?>%"></div>
                <div class="bg-emerald-500 transition-all hover:opacity-80" style="width: <?= $pct_wirausaha ?>%" title="Wirausaha: <?= $pct_wirausaha ?>%"></div>
                <div class="bg-amber-500 transition-all hover:opacity-80" style="width: <?= $pct_mencari_kerja ?>%" title="Mencari Kerja: <?= $pct_mencari_kerja ?>%"></div>
            </div>

            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                <div class="flex items-center gap-2">
                    <span class="h-3 w-3 rounded-full bg-cyan-500 shrink-0"></span>
                    <div>
                        <div class="font-bold text-white"><?= $pct_kuliah ?>%</div>
                        <div class="text-[11px] text-slate-400">Kuliah (<?= $stats_activity['kuliah'] ?>)</div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="h-3 w-3 rounded-full bg-blue-600 shrink-0"></span>
                    <div>
                        <div class="font-bold text-white"><?= $pct_bekerja ?>%</div>
                        <div class="text-[11px] text-slate-400">Bekerja (<?= $stats_activity['bekerja'] ?>)</div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="h-3 w-3 rounded-full bg-emerald-500 shrink-0"></span>
                    <div>
                        <div class="font-bold text-white"><?= $pct_wirausaha ?>%</div>
                        <div class="text-[11px] text-slate-400">Usaha (<?= $stats_activity['wirausaha'] ?>)</div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="h-3 w-3 rounded-full bg-amber-500 shrink-0"></span>
                    <div>
                        <div class="font-bold text-white"><?= $pct_mencari_kerja ?>%</div>
                        <div class="text-[11px] text-slate-400">Masa Tunggu (<?= $stats_activity['mencari_kerja'] ?>)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Keselarasan Bidang -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-6 backdrop-blur-xl shadow-xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-crosshairs text-purple-400"></i> Tingkat Relevansi Pembelajaran Sekolah
                </h3>
                <span class="text-xs font-bold text-emerald-400">Target BAN-SM ≥ 75%</span>
            </div>

            <div class="space-y-3 text-xs">
                <div>
                    <div class="flex justify-between text-slate-300 mb-1">
                        <span>Sangat Selaras (Relevansi Sangat Tinggi)</span>
                        <span class="font-bold text-white"><?= $stats_alignment['sangat_selaras'] ?> lulusan</span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-slate-800 overflow-hidden">
                        <div class="h-full rounded-full bg-emerald-500" style="width: <?= $total_responden > 0 ? round(($stats_alignment['sangat_selaras']/$total_responden)*100, 1) : 0 ?>%"></div>
                    </div>
                </div>

                <div>
                    <div class="flex justify-between text-slate-300 mb-1">
                        <span>Selaras (Sesuai Kompetensi / Minat)</span>
                        <span class="font-bold text-white"><?= $stats_alignment['selaras'] ?> lulusan</span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-slate-800 overflow-hidden">
                        <div class="h-full rounded-full bg-blue-500" style="width: <?= $total_responden > 0 ? round(($stats_alignment['selaras']/$total_responden)*100, 1) : 0 ?>%"></div>
                    </div>
                </div>

                <div>
                    <div class="flex justify-between text-slate-300 mb-1">
                        <span>Kurang / Tidak Selaras</span>
                        <span class="font-bold text-white"><?= $stats_alignment['kurang_selaras'] + $stats_alignment['tidak_selaras'] ?> lulusan</span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-slate-800 overflow-hidden">
                        <div class="h-full rounded-full bg-amber-500" style="width: <?= $total_responden > 0 ? round((($stats_alignment['kurang_selaras'] + $stats_alignment['tidak_selaras'])/$total_responden)*100, 1) : 0 ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TABEL DATA RESPONDEN TRACER STUDY -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-6 backdrop-blur-xl shadow-xl">
        <!-- Filter Bar -->
        <div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h3 class="text-lg font-bold text-white">Daftar Rekam Jejak Karir Alumni</h3>
                <p class="text-xs text-slate-400">Menampilkan <?= count($tracer_list) ?> data responden alumni terdaftar</p>
            </div>

            <form method="GET" class="flex flex-wrap items-center gap-2.5">
                <!-- Filter Tahun -->
                <select name="year" class="rounded-xl border border-white/10 bg-slate-800/80 px-3 py-2 text-xs font-semibold text-slate-200 focus:outline-none focus:border-blue-500">
                    <option value="">Semua Angkatan</option>
                    <?php foreach ($years_available as $yr): ?>
                        <option value="<?= htmlspecialchars($yr) ?>" <?= $filter_year === (string)$yr ? 'selected' : '' ?>>Lulusan <?= htmlspecialchars($yr) ?></option>
                    <?php endforeach; ?>
                </select>

                <!-- Filter Aktivitas -->
                <select name="activity" class="rounded-xl border border-white/10 bg-slate-800/80 px-3 py-2 text-xs font-semibold text-slate-200 focus:outline-none focus:border-blue-500">
                    <option value="">Semua Aktivitas</option>
                    <option value="kuliah" <?= $filter_act === 'kuliah' ? 'selected' : '' ?>>Kuliah</option>
                    <option value="bekerja" <?= $filter_act === 'bekerja' ? 'selected' : '' ?>>Bekerja</option>
                    <option value="wirausaha" <?= $filter_act === 'wirausaha' ? 'selected' : '' ?>>Wirausaha</option>
                    <option value="mencari_kerja" <?= $filter_act === 'mencari_kerja' ? 'selected' : '' ?>>Masa Tunggu</option>
                </select>

                <!-- Cari Nama/Kampus/Perusahaan -->
                <div class="relative">
                    <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="Cari nama / instansi..." 
                           class="rounded-xl border border-white/10 bg-slate-800/80 pl-8 pr-3 py-2 text-xs text-white placeholder-slate-400 focus:outline-none focus:border-blue-500 w-44 sm:w-56">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-400"></i>
                </div>

                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-3.5 py-2 text-xs font-semibold text-white transition">
                    Filter
                </button>

                <?php if (!empty($filter_year) || !empty($filter_act) || !empty($search_q)): ?>
                    <a href="tracer_study.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-xs font-semibold text-slate-300 transition">
                        Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tabel Responsive -->
        <div class="overflow-x-auto rounded-xl border border-white/10">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-white/5 text-xs font-semibold uppercase tracking-wider text-slate-400 border-b border-white/10">
                    <tr>
                        <th class="py-3.5 px-4">Alumni</th>
                        <th class="py-3.5 px-3">Angkatan</th>
                        <th class="py-3.5 px-4">Status & Keterangan</th>
                        <th class="py-3.5 px-4">Keselarasan</th>
                        <th class="py-3.5 px-4">Kontak</th>
                        <th class="py-3.5 px-4 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 font-normal">
                    <?php if (empty($tracer_list)): ?>
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-800 text-slate-400 mb-3">
                                        <i class="fa-solid fa-inbox text-xl"></i>
                                    </span>
                                    <p class="font-medium text-white">Belum ada data tracer study ditemukan</p>
                                    <p class="text-xs text-slate-400 mt-1">Ubah kata kunci filter atau tambahkan data responden pertama.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tracer_list as $row): ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="py-3.5 px-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-tr from-slate-700 to-slate-800 text-slate-200 font-bold text-xs border border-white/10">
                                            <?= strtoupper(substr($row['full_name'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-white"><?= htmlspecialchars($row['full_name']) ?></div>
                                            <div class="text-xs text-slate-400">NISN: <?= htmlspecialchars($row['nisn'] ?: '-') ?></div>
                                        </div>
                                    </div>
                                </td>

                                <td class="py-3.5 px-3 whitespace-nowrap">
                                    <span class="inline-flex items-center rounded-md bg-slate-800 px-2 py-0.5 text-xs font-semibold text-slate-300 border border-white/10">
                                        <?= htmlspecialchars($row['graduation_year']) ?>
                                    </span>
                                </td>

                                <td class="py-3.5 px-4">
                                    <?php if ($row['current_activity'] === 'kuliah'): ?>
                                        <div>
                                            <span class="inline-flex items-center gap-1 rounded-md bg-cyan-500/10 px-2 py-0.5 text-[11px] font-bold text-cyan-400 border border-cyan-500/20 mb-1">
                                                <i class="fa-solid fa-graduation-cap text-[10px]"></i> Kuliah
                                            </span>
                                            <div class="font-medium text-white text-xs"><?= htmlspecialchars($row['campus_name'] ?: 'Perguruan Tinggi') ?></div>
                                            <div class="text-[11px] text-slate-400"><?= htmlspecialchars($row['campus_major'] ?: '') ?> <?= $row['degree_level'] ? "({$row['degree_level']})" : '' ?> <?= $row['admission_track'] ? "• {$row['admission_track']}" : '' ?></div>
                                        </div>
                                    <?php elseif ($row['current_activity'] === 'bekerja'): ?>
                                        <div>
                                            <span class="inline-flex items-center gap-1 rounded-md bg-blue-500/10 px-2 py-0.5 text-[11px] font-bold text-blue-400 border border-blue-500/20 mb-1">
                                                <i class="fa-solid fa-briefcase text-[10px]"></i> Bekerja
                                            </span>
                                            <div class="font-medium text-white text-xs"><?= htmlspecialchars($row['company_name'] ?: 'Instansi / Perusahaan') ?></div>
                                            <div class="text-[11px] text-slate-400"><?= htmlspecialchars($row['job_title'] ?: '') ?> <?= $row['job_sector'] ? "• {$row['job_sector']}" : '' ?></div>
                                        </div>
                                    <?php elseif ($row['current_activity'] === 'wirausaha'): ?>
                                        <div>
                                            <span class="inline-flex items-center gap-1 rounded-md bg-emerald-500/10 px-2 py-0.5 text-[11px] font-bold text-emerald-400 border border-emerald-500/20 mb-1">
                                                <i class="fa-solid fa-store text-[10px]"></i> Wirausaha
                                            </span>
                                            <div class="font-medium text-white text-xs"><?= htmlspecialchars($row['business_name'] ?: 'Usaha Mandiri') ?></div>
                                            <div class="text-[11px] text-slate-400"><?= htmlspecialchars($row['business_field'] ?: '') ?></div>
                                        </div>
                                    <?php else: ?>
                                        <div>
                                            <span class="inline-flex items-center gap-1 rounded-md bg-amber-500/10 px-2 py-0.5 text-[11px] font-bold text-amber-400 border border-amber-500/20">
                                                <i class="fa-solid fa-hourglass-half text-[10px]"></i> Masa Tunggu
                                            </span>
                                            <div class="text-xs text-slate-400 mt-1">Mencari Kerja / Pelatihan</div>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <?php if ($row['alignment'] === 'sangat_selaras'): ?>
                                        <span class="inline-flex items-center gap-1 rounded-md bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-400 border border-emerald-500/20">
                                            <i class="fa-solid fa-circle-check text-[10px]"></i> Sangat Selaras
                                        </span>
                                    <?php elseif ($row['alignment'] === 'selaras'): ?>
                                        <span class="inline-flex items-center gap-1 rounded-md bg-blue-500/10 px-2 py-0.5 text-xs font-semibold text-blue-400 border border-blue-500/20">
                                            <i class="fa-solid fa-check text-[10px]"></i> Selaras
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 rounded-md bg-slate-800 px-2 py-0.5 text-xs font-semibold text-slate-400 border border-white/10">
                                            Kurang/Tidak
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3.5 px-4 text-xs whitespace-nowrap">
                                    <div class="flex flex-col gap-1">
                                        <?php if ($row['phone']): ?>
                                            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $row['phone']) ?>" target="_blank" class="inline-flex items-center gap-1.5 text-emerald-400 hover:underline">
                                                <i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars($row['phone']) ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($row['email']): ?>
                                            <span class="text-slate-400 flex items-center gap-1.5">
                                                <i class="fa-regular fa-envelope text-[11px]"></i> <?= htmlspecialchars($row['email']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                    <div class="flex items-center justify-center gap-2">
                                        <!-- Tombol Detail / Testimoni Modal -->
                                        <button onclick="showDetailModal(<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>)" 
                                                title="Lihat Detail & Testimoni"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600/10 text-blue-400 hover:bg-blue-600/20 border border-blue-500/20 transition cursor-pointer">
                                            <i class="fa-solid fa-eye text-xs"></i>
                                        </button>

                                        <?php if ($is_staff): ?>
                                            <!-- Tombol Hapus -->
                                            <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus data responden ini?');" class="inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_tracer">
                                                <input type="hidden" name="tracer_id" value="<?= $row['id'] ?>">
                                                <button type="submit" title="Hapus Data"
                                                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-600/10 text-rose-400 hover:bg-rose-600/20 border border-rose-500/20 transition cursor-pointer">
                                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL 1: FORM PENGISIAN TRACER STUDY ALUMNI                -->
<!-- ========================================================= -->
<div id="modalTracerForm" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm hidden overflow-y-auto">
    <div class="relative w-full max-w-2xl rounded-3xl border border-white/10 bg-slate-900 p-6 md:p-8 shadow-2xl my-8">
        <button onclick="document.getElementById('modalTracerForm').classList.add('hidden')" 
                class="absolute right-6 top-6 text-slate-400 hover:text-white transition cursor-pointer">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div class="flex items-center gap-3 mb-6">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-600/20 text-blue-400 border border-blue-500/30">
                <i class="fa-solid fa-clipboard-question text-base"></i>
            </span>
            <div>
                <h3 class="text-xl font-bold text-white">Form Kuesioner Tracer Study Alumni</h3>
                <p class="text-xs text-slate-400">Isi data terkini Anda untuk pembaharuan data akreditasi dan silaturahmi sekolah</p>
            </div>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="submit_tracer">

            <!-- Data Pribadi Alumni -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Nama Lengkap Alumni <span class="text-rose-400">*</span></label>
                    <input type="text" name="full_name" required 
                           value="<?= htmlspecialchars($my_submission['full_name'] ?? ($user_info['name'] ?? '')) ?>" 
                           placeholder="Nama lengkap beserta gelar jika ada" 
                           class="w-full rounded-xl border border-white/10 bg-slate-800/80 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">NISN (Nomor Induk Siswa Nasional)</label>
                    <input type="text" name="nisn" 
                           value="<?= htmlspecialchars($my_submission['nisn'] ?? ($user_info['nisn'] ?? '')) ?>" 
                           placeholder="Contoh: 0041829301" 
                           class="w-full rounded-xl border border-white/10 bg-slate-800/80 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tahun Kelulusan <span class="text-rose-400">*</span></label>
                    <input type="text" name="graduation_year" required 
                           value="<?= htmlspecialchars($my_submission['graduation_year'] ?? date('Y')) ?>" 
                           placeholder="Contoh: 2025" 
                           class="w-full rounded-xl border border-white/10 bg-slate-800/80 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Nomor WhatsApp / HP</label>
                    <input type="text" name="phone" 
                           value="<?= htmlspecialchars($my_submission['phone'] ?? ($user_info['phone'] ?? '')) ?>" 
                           placeholder="081234567890" 
                           class="w-full rounded-xl border border-white/10 bg-slate-800/80 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Email Aktif</label>
                    <input type="email" name="email" 
                           value="<?= htmlspecialchars($my_submission['email'] ?? ($user_info['email'] ?? '')) ?>" 
                           placeholder="email.alumni@gmail.com" 
                           class="w-full rounded-xl border border-white/10 bg-slate-800/80 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <!-- Pilihan Aktivitas Utama (BMW) -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-2">Aktivitas Utama Saat Ini <span class="text-rose-400">*</span></label>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                    <?php 
                    $curr_act = $my_submission['current_activity'] ?? 'kuliah';
                    ?>
                    <label class="flex flex-col items-center justify-center p-3 rounded-2xl border border-white/10 bg-slate-800/60 hover:bg-slate-800 transition cursor-pointer text-center group">
                        <input type="radio" name="current_activity" value="kuliah" class="sr-only" onchange="toggleActivityFields('kuliah')" <?= $curr_act === 'kuliah' ? 'checked' : '' ?>>
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-cyan-500/10 text-cyan-400 mb-2 group-hover:scale-110 transition">
                            <i class="fa-solid fa-graduation-cap"></i>
                        </span>
                        <span class="text-xs font-bold text-white">Kuliah</span>
                        <span class="text-[10px] text-slate-400">PTN / PTS</span>
                    </label>

                    <label class="flex flex-col items-center justify-center p-3 rounded-2xl border border-white/10 bg-slate-800/60 hover:bg-slate-800 transition cursor-pointer text-center group">
                        <input type="radio" name="current_activity" value="bekerja" class="sr-only" onchange="toggleActivityFields('bekerja')" <?= $curr_act === 'bekerja' ? 'checked' : '' ?>>
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400 mb-2 group-hover:scale-110 transition">
                            <i class="fa-solid fa-briefcase"></i>
                        </span>
                        <span class="text-xs font-bold text-white">Bekerja</span>
                        <span class="text-[10px] text-slate-400">Industri / Swasta</span>
                    </label>

                    <label class="flex flex-col items-center justify-center p-3 rounded-2xl border border-white/10 bg-slate-800/60 hover:bg-slate-800 transition cursor-pointer text-center group">
                        <input type="radio" name="current_activity" value="wirausaha" class="sr-only" onchange="toggleActivityFields('wirausaha')" <?= $curr_act === 'wirausaha' ? 'checked' : '' ?>>
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-400 mb-2 group-hover:scale-110 transition">
                            <i class="fa-solid fa-store"></i>
                        </span>
                        <span class="text-xs font-bold text-white">Wirausaha</span>
                        <span class="text-[10px] text-slate-400">Bisnis Mandiri</span>
                    </label>

                    <label class="flex flex-col items-center justify-center p-3 rounded-2xl border border-white/10 bg-slate-800/60 hover:bg-slate-800 transition cursor-pointer text-center group">
                        <input type="radio" name="current_activity" value="mencari_kerja" class="sr-only" onchange="toggleActivityFields('mencari_kerja')" <?= $curr_act === 'mencari_kerja' ? 'checked' : '' ?>>
                        <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-500/10 text-amber-400 mb-2 group-hover:scale-110 transition">
                            <i class="fa-solid fa-hourglass-half"></i>
                        </span>
                        <span class="text-xs font-bold text-white">Masa Tunggu</span>
                        <span class="text-[10px] text-slate-400">Cari Kerja / Kursus</span>
                    </label>
                </div>
            </div>

            <!-- SECTION DINAMIS 1: KULIAH -->
            <div id="section_kuliah" class="rounded-2xl border border-cyan-500/30 bg-cyan-500/5 p-4 space-y-3">
                <h4 class="text-xs font-bold uppercase tracking-wider text-cyan-400 flex items-center gap-2">
                    <i class="fa-solid fa-university"></i> Informasi Perguruan Tinggi
                </h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Nama Universitas / Kampus</label>
                        <input type="text" name="campus_name" 
                               value="<?= htmlspecialchars($my_submission['campus_name'] ?? '') ?>" 
                               placeholder="Contoh: Universitas Indonesia (UI)" 
                               class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Program Studi / Jurusan</label>
                        <input type="text" name="campus_major" 
                               value="<?= htmlspecialchars($my_submission['campus_major'] ?? '') ?>" 
                               placeholder="Contoh: Teknik Informatika" 
                               class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Jenjang Pendidikan</label>
                        <select name="degree_level" class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                            <option value="S1" <?= ($my_submission['degree_level'] ?? '') === 'S1' ? 'selected' : '' ?>>S1 (Sarjana)</option>
                            <option value="D4" <?= ($my_submission['degree_level'] ?? '') === 'D4' ? 'selected' : '' ?>>D4 (Sarjana Terapan)</option>
                            <option value="D3" <?= ($my_submission['degree_level'] ?? '') === 'D3' ? 'selected' : '' ?>>D3 (Diploma Tiga)</option>
                            <option value="Kedinasan" <?= ($my_submission['degree_level'] ?? '') === 'Kedinasan' ? 'selected' : '' ?>>Ikatan Dinas</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Jalur Penerimaan</label>
                        <select name="admission_track" class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-cyan-500">
                            <option value="SNBP (Prestasi)" <?= ($my_submission['admission_track'] ?? '') === 'SNBP (Prestasi)' ? 'selected' : '' ?>>SNBP (Prestasi Rapor)</option>
                            <option value="SNBT (UTBK)" <?= ($my_submission['admission_track'] ?? '') === 'SNBT (UTBK)' ? 'selected' : '' ?>>SNBT (Tes UTBK)</option>
                            <option value="Mandiri" <?= ($my_submission['admission_track'] ?? '') === 'Mandiri' ? 'selected' : '' ?>>Seleksi Mandiri Kampus</option>
                            <option value="Beasiswa Penuh" <?= ($my_submission['admission_track'] ?? '') === 'Beasiswa Penuh' ? 'selected' : '' ?>>Beasiswa KIP-K / Swasta</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- SECTION DINAMIS 2: BEKERJA -->
            <div id="section_bekerja" class="rounded-2xl border border-blue-500/30 bg-blue-500/5 p-4 space-y-3 hidden">
                <h4 class="text-xs font-bold uppercase tracking-wider text-blue-400 flex items-center gap-2">
                    <i class="fa-solid fa-briefcase"></i> Informasi Pekerjaan & Perusahaan
                </h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Nama Perusahaan / Instansi</label>
                        <input type="text" name="company_name" 
                               value="<?= htmlspecialchars($my_submission['company_name'] ?? '') ?>" 
                               placeholder="Contoh: PT Telkom Indonesia" 
                               class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Posisi / Jabatan</label>
                        <input type="text" name="job_title" 
                               value="<?= htmlspecialchars($my_submission['job_title'] ?? '') ?>" 
                               placeholder="Contoh: Junior Web Developer" 
                               class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Sektor Industri</label>
                        <input type="text" name="job_sector" 
                               value="<?= htmlspecialchars($my_submission['job_sector'] ?? '') ?>" 
                               placeholder="Contoh: Teknologi Informasi / Perbankan" 
                               class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Rentang Pendapatan / Gaji</label>
                        <select name="income_range" class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-blue-500">
                            <option value="Di Bawah UMR" <?= ($my_submission['income_range'] ?? '') === 'Di Bawah UMR' ? 'selected' : '' ?>>Di Bawah UMR</option>
                            <option value="Sesuai UMR (1x UMR)" <?= ($my_submission['income_range'] ?? '') === 'Sesuai UMR (1x UMR)' ? 'selected' : '' ?>>Sesuai UMR Daerah</option>
                            <option value="1x - 2x UMR" <?= ($my_submission['income_range'] ?? '') === '1x - 2x UMR' ? 'selected' : '' ?>>1x - 2x UMR</option>
                            <option value="Di Atas 2x UMR" <?= ($my_submission['income_range'] ?? '') === 'Di Atas 2x UMR' ? 'selected' : '' ?>>Di Atas 2x UMR</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- SECTION DINAMIS 3: WIRAUSAHA -->
            <div id="section_wirausaha" class="rounded-2xl border border-emerald-500/30 bg-emerald-500/5 p-4 space-y-3 hidden">
                <h4 class="text-xs font-bold uppercase tracking-wider text-emerald-400 flex items-center gap-2">
                    <i class="fa-solid fa-store"></i> Informasi Usaha / Bisnis Mandiri
                </h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Nama Usaha / Brand</label>
                        <input type="text" name="business_name" 
                               value="<?= htmlspecialchars($my_submission['business_name'] ?? '') ?>" 
                               placeholder="Contoh: Kopi Nusantara / Fajar Creative" 
                               class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-300 mb-1">Bidang Usaha</label>
                        <input type="text" name="business_field" 
                               value="<?= htmlspecialchars($my_submission['business_field'] ?? '') ?>" 
                               placeholder="Contoh: Kuliner / Digital Agency / Fashion" 
                               class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-emerald-500">
                    </div>
                </div>
            </div>

            <!-- Keselarasan Pembelajaran -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Keselarasan Ilmu & Kompetensi yang Diperoleh di Sekolah <span class="text-rose-400">*</span></label>
                <select name="alignment" class="w-full rounded-xl border border-white/10 bg-slate-800/80 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500">
                    <option value="sangat_selaras" <?= ($my_submission['alignment'] ?? 'selaras') === 'sangat_selaras' ? 'selected' : '' ?>>Sangat Selaras (Sangat membantu karir & kuliah saya)</option>
                    <option value="selaras" <?= ($my_submission['alignment'] ?? 'selaras') === 'selaras' ? 'selected' : '' ?>>Selaras (Cukup menunjang kegiatan saat ini)</option>
                    <option value="kurang_selaras" <?= ($my_submission['alignment'] ?? '') === 'kurang_selaras' ? 'selected' : '' ?>>Kurang Selaras (Hanya sedikit berkaitan)</option>
                    <option value="tidak_selaras" <?= ($my_submission['alignment'] ?? '') === 'tidak_selaras' ? 'selected' : '' ?>>Tidak Selaras (Bidang yang dijalani berbeda total)</option>
                </select>
            </div>

            <!-- Testimoni / Pesan untuk Sekolah -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Testimoni & Pesan/Saran untuk Almamater</label>
                <textarea name="testimonial" rows="3" placeholder="Tuliskan pengalaman berharga selama bersekolah atau masukan untuk adik-adik kelas..." 
                          class="w-full rounded-xl border border-white/10 bg-slate-800/80 px-3.5 py-2.5 text-sm text-white focus:outline-none focus:border-blue-500"><?= htmlspecialchars($my_submission['testimonial'] ?? '') ?></textarea>
            </div>

            <div class="pt-4 flex items-center justify-end gap-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalTracerForm').classList.add('hidden')" 
                        class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-slate-300 transition cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/25 transition cursor-pointer">
                    <i class="fa-solid fa-paper-plane mr-1.5"></i> Simpan Data Tracer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL 2: DETAIL RESPONDEN TRACER STUDY                     -->
<!-- ========================================================= -->
<div id="modalDetail" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm hidden overflow-y-auto">
    <div class="relative w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 md:p-8 shadow-2xl">
        <button onclick="document.getElementById('modalDetail').classList.add('hidden')" 
                class="absolute right-6 top-6 text-slate-400 hover:text-white transition cursor-pointer">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div class="flex items-center gap-3 mb-5">
            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-600/20 text-indigo-400 border border-indigo-500/30">
                <i class="fa-solid fa-user-check text-lg"></i>
            </span>
            <div>
                <h3 id="dt_name" class="text-xl font-bold text-white">Nama Alumni</h3>
                <p id="dt_meta" class="text-xs text-slate-400">NISN: - • Lulusan 2025</p>
            </div>
        </div>

        <div class="space-y-4 text-xs">
            <div class="rounded-2xl border border-white/10 bg-slate-800/60 p-4 space-y-2">
                <div class="flex justify-between">
                    <span class="text-slate-400">Aktivitas Utama:</span>
                    <span id="dt_activity" class="font-bold text-white capitalize">-</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Instansi / Kampus:</span>
                    <span id="dt_institution" class="font-semibold text-cyan-300">-</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Program / Posisi:</span>
                    <span id="dt_detail_pos" class="font-semibold text-white">-</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Keselarasan Kurikulum:</span>
                    <span id="dt_alignment" class="font-bold text-emerald-400">-</span>
                </div>
            </div>

            <div class="rounded-2xl border border-white/10 bg-slate-800/60 p-4 space-y-2">
                <h5 class="font-bold text-slate-300 uppercase tracking-wider text-[10px]">Kontak Alumni</h5>
                <div class="flex justify-between">
                    <span class="text-slate-400">WhatsApp/HP:</span>
                    <span id="dt_phone" class="font-semibold text-slate-200">-</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Email:</span>
                    <span id="dt_email" class="font-semibold text-slate-200">-</span>
                </div>
            </div>

            <div>
                <h5 class="font-bold text-slate-300 uppercase tracking-wider text-[10px] mb-1.5">Testimoni & Saran untuk Sekolah</h5>
                <div id="dt_testimonial" class="rounded-xl border border-white/10 bg-slate-800/40 p-3.5 text-slate-300 italic text-xs leading-relaxed">
                    -
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <button onclick="document.getElementById('modalDetail').classList.add('hidden')"
                    class="rounded-xl bg-slate-800 hover:bg-slate-700 px-4 py-2 text-xs font-semibold text-white transition cursor-pointer">
                Tutup
            </button>
        </div>
    </div>
</div>

<script>
function toggleActivityFields(act) {
    document.getElementById('section_kuliah').classList.add('hidden');
    document.getElementById('section_bekerja').classList.add('hidden');
    document.getElementById('section_wirausaha').classList.add('hidden');

    if (act === 'kuliah') {
        document.getElementById('section_kuliah').classList.remove('hidden');
    } else if (act === 'bekerja') {
        document.getElementById('section_bekerja').classList.remove('hidden');
    } else if (act === 'wirausaha') {
        document.getElementById('section_wirausaha').classList.remove('hidden');
    }
}

// Inisialisasi tampilan awal sesuai radio yang aktif
document.addEventListener('DOMContentLoaded', function() {
    const checkedRadio = document.querySelector('input[name="current_activity"]:checked');
    if (checkedRadio) {
        toggleActivityFields(checkedRadio.value);
    }
});

function showDetailModal(data) {
    document.getElementById('dt_name').textContent = data.full_name || 'Alumni';
    document.getElementById('dt_meta').textContent = 'NISN: ' + (data.nisn || '-') + ' • Lulusan ' + (data.graduation_year || '-');
    
    let actLabel = data.current_activity;
    let instLabel = '-';
    let posLabel = '-';

    if (data.current_activity === 'kuliah') {
        actLabel = 'Kuliah / Perguruan Tinggi';
        instLabel = data.campus_name || '-';
        posLabel = (data.campus_major || '-') + (data.degree_level ? ' (' + data.degree_level + ')' : '');
    } else if (data.current_activity === 'bekerja') {
        actLabel = 'Bekerja di Perusahaan';
        instLabel = data.company_name || '-';
        posLabel = (data.job_title || '-') + (data.job_sector ? ' • ' + data.job_sector : '');
    } else if (data.current_activity === 'wirausaha') {
        actLabel = 'Wirausaha / Bisnis Mandiri';
        instLabel = data.business_name || '-';
        posLabel = data.business_field || '-';
    } else {
        actLabel = 'Masa Tunggu / Mencari Kerja';
    }

    document.getElementById('dt_activity').textContent = actLabel;
    document.getElementById('dt_institution').textContent = instLabel;
    document.getElementById('dt_detail_pos').textContent = posLabel;
    
    let alignText = 'Selaras';
    if (data.alignment === 'sangat_selaras') alignText = 'Sangat Selaras';
    else if (data.alignment === 'kurang_selaras') alignText = 'Kurang Selaras';
    else if (data.alignment === 'tidak_selaras') alignText = 'Tidak Selaras';
    document.getElementById('dt_alignment').textContent = alignText;

    document.getElementById('dt_phone').textContent = data.phone || '-';
    document.getElementById('dt_email').textContent = data.email || '-';
    document.getElementById('dt_testimonial').textContent = data.testimonial ? '"' + data.testimonial + '"' : 'Belum ada testimoni yang ditulis.';

    document.getElementById('modalDetail').classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
