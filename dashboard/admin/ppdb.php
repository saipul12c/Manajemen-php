<?php
/**
 * Dashboard Panitia PPDB Online
 * Kelola verifikasi berkas, penilaian seleksi, dan 1-klik pembuatan akun resmi siswa baru.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['administrator', 'staf']);

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$page_title = "Manajemen PPDB Online";

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. UPDATE STATUS, SKOR SELEKSI & STATUS BERKAS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_ppdb_status') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $reg_id           = (int)($_POST['registration_id'] ?? 0);
        $status           = trim($_POST['status'] ?? 'menunggu_verifikasi');
        $score            = $_POST['selection_score'] !== '' ? (float)$_POST['selection_score'] : null;
        $notes            = trim($_POST['notes'] ?? '');
        $document_status  = in_array($_POST['document_status'] ?? '', array_keys(PPDB_DOC_STATUSES), true) ? $_POST['document_status'] : 'lengkap';
        $rejection_reason = trim($_POST['rejection_reason'] ?? '');

        $valid_statuses = ['menunggu_verifikasi', 'diverifikasi', 'lulus_seleksi', 'tidak_lulus', 'diterima'];
        if (!in_array($status, $valid_statuses, true)) {
            $message = "Status seleksi yang dipilih tidak valid.";
            $message_type = "error";
        } else {
            try {
                $stmt = $pdo->prepare("
                    UPDATE ppdb_registrations 
                    SET status = ?, selection_score = ?, notes = ?, document_status = ?, rejection_reason = ?
                    WHERE id = ?
                ");
                $stmt->execute([$status, $score, $notes, $document_status, $rejection_reason ?: null, $reg_id]);

                logActivity($pdo, 'UPDATE_PPDB', "Memperbarui status pendaftar ID $reg_id menjadi: $status (Berkas: $document_status)");
                $message = "Status seleksi, berkas dokumen, dan penilaian pendaftar berhasil diperbarui!";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Gagal memperbarui status: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// Helper fungsi otomatisasi pembuatan akun siswa, orang tua, dan tagihan awal
// -------------------------------------------------------------
function processAcceptStudent($pdo, $applicant, $class_id) {
    $default_password_hash = password_hash('password', PASSWORD_DEFAULT);

    // 1. Akun Siswa di tabel users (dengan perlindungan akun administratif)
    $stmt_chk_user = $pdo->prepare("SELECT id, role FROM users WHERE email = ? OR (nisn IS NOT NULL AND nisn = ?)");
    $stmt_chk_user->execute([$applicant['email'], $applicant['nisn']]);
    $existing_user = $stmt_chk_user->fetch();

    $target_user_id = null;
    if ($existing_user) {
        if (in_array($existing_user['role'], ['administrator', 'staf', 'guru'], true)) {
            throw new Exception("Email {$applicant['email']} sudah digunakan oleh akun {$existing_user['role']}. Tidak dapat mengubah akun staf/guru menjadi siswa.");
        }
        $target_user_id = (int)$existing_user['id'];
        $stmt_up_u = $pdo->prepare("UPDATE users SET role = 'siswa', class_id = ?, nisn = ?, gender = ?, address = ?, phone = ? WHERE id = ?");
        $stmt_up_u->execute([$class_id, $applicant['nisn'], $applicant['gender'], $applicant['address'], $applicant['phone'], $target_user_id]);
    } else {
        $stmt_new_u = $pdo->prepare("
            INSERT INTO users 
            (name, email, password, role, class_id, nisn, gender, address, phone)
            VALUES
            (?, ?, ?, 'siswa', ?, ?, ?, ?, ?)
        ");
        $stmt_new_u->execute([
            $applicant['full_name'],
            $applicant['email'],
            $default_password_hash,
            $class_id,
            $applicant['nisn'],
            $applicant['gender'],
            $applicant['address'],
            $applicant['phone']
        ]);
        $target_user_id = (int)$pdo->lastInsertId();
    }

    // 2. Akun Orang Tua (Wali Murid) di tabel users
    $clean_nisn = preg_replace('/[^a-zA-Z0-9]/', '', $applicant['nisn'] ?: (string)$applicant['id']);
    $parent_email = 'wali.' . $clean_nisn . '@sekolah.id';
    $parent_phone = $applicant['parent_phone'] ?: '-';
    $parent_name = ($applicant['parent_name'] ?: 'Wali dari ' . $applicant['full_name']);

    $stmt_chk_p = $pdo->prepare("SELECT id, role FROM users WHERE email = ? OR (phone != '-' AND phone = ? AND role = 'orang_tua')");
    $stmt_chk_p->execute([$parent_email, $parent_phone]);
    $existing_parent = $stmt_chk_p->fetch();

    if ($existing_parent) {
        $parent_id = (int)$existing_parent['id'];
    } else {
        $stmt_new_p = $pdo->prepare("
            INSERT INTO users (name, email, password, role, phone, address)
            VALUES (?, ?, ?, 'orang_tua', ?, ?)
        ");
        $stmt_new_p->execute([
            $parent_name . ' (Wali)',
            $parent_email,
            $default_password_hash,
            $parent_phone,
            $applicant['address']
        ]);
        $parent_id = (int)$pdo->lastInsertId();
    }

    // Hubungkan relasi orang tua dan siswa di parent_students
    $stmt_ps = $pdo->prepare("
        INSERT IGNORE INTO parent_students (parent_id, student_id, relation_type)
        VALUES (?, ?, 'Wali Murid')
    ");
    $stmt_ps->execute([$parent_id, $target_user_id]);

    // 3. Terbitkan Tagihan Administrasi Awal (student_bills)
    $stmt_chk_bill = $pdo->prepare("SELECT id FROM student_bills WHERE student_id = ?");
    $stmt_chk_bill->execute([$target_user_id]);
    if (!$stmt_chk_bill->fetch()) {
        $stmt_in_bill = $pdo->prepare("
            INSERT INTO student_bills (student_id, payment_type_id, title, amount, due_date, month_period, academic_year, status, notes)
            VALUES (?, 1, 'SPP Bulan Pertama & Daftar Ulang', 350000.00, DATE_ADD(CURRENT_DATE(), INTERVAL 14 DAY), 'Bulan Pertama', '2026/2027', 'belum_lunas', 'Biaya administrasi awal & SPP daftar ulang siswa baru.')
        ");
        $stmt_in_bill->execute([$target_user_id]);
    }

    // 4. Update status di ppdb_registrations
    $stmt_acc = $pdo->prepare("
        UPDATE ppdb_registrations 
        SET status = 'diterima', document_status = 'lengkap', user_id = ?, notes = CONCAT(COALESCE(notes, ''), ' [Diterima resmi & akun aktif pada ', NOW(), ']')
        WHERE id = ?
    ");
    $stmt_acc->execute([$target_user_id, $applicant['id']]);

    return [
        'student_id'    => $target_user_id,
        'student_name'  => $applicant['full_name'],
        'student_email' => $applicant['email'],
        'student_pass'  => 'password',
        'nisn'          => $applicant['nisn'],
        'parent_name'   => $parent_name,
        'parent_email'  => $parent_email,
        'parent_pass'   => 'password',
        'class_id'      => $class_id
    ];
}

// -------------------------------------------------------------
// 2. TERIMA RESMI & 1-KLIK BUAT AKUN SISWA TUNGGAL
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_and_create_account') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $reg_id   = (int)($_POST['registration_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 1);

        try {
            $stmt_app = $pdo->prepare("SELECT * FROM ppdb_registrations WHERE id = ?");
            $stmt_app->execute([$reg_id]);
            $applicant = $stmt_app->fetch();

            if (!$applicant) {
                $message = "Data pendaftar tidak ditemukan.";
                $message_type = "error";
            } elseif ($applicant['status'] === 'diterima' && !empty($applicant['user_id'])) {
                $message = "Pendaftar ini sudah pernah diterima dan akun siswa telah aktif.";
                $message_type = "info";
            } else {
                $created_info = processAcceptStudent($pdo, $applicant, $class_id);
                $_SESSION['newly_activated'] = $created_info;

                logActivity($pdo, 'ACCEPT_PPDB_STUDENT', "Menerima pendaftar {$applicant['full_name']} dan membuat akun siswa ID {$created_info['student_id']}");
                $message = "Sukses! Calon siswa '{$applicant['full_name']}' resmi diterima. Akun Siswa & Akun Orang Tua berhasil dibuat serta tagihan awal diterbitkan.";
                $message_type = "success";
            }
        } catch (Exception $e) {
            $message = "Terjadi kesalahan saat memproses akun siswa: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 2B. TERIMA MASSAL (BATCH ACCEPT & CREATE ACCOUNTS)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_accept_students') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $selected_ids = $_POST['selected_ids'] ?? [];
        $class_id = (int)($_POST['batch_class_id'] ?? 1);

        if (empty($selected_ids) || !is_array($selected_ids)) {
            $message = "Pilih minimal satu calon siswa pada checkbox tabel untuk diterima secara massal.";
            $message_type = "error";
        } else {
            $success_count = 0;
            $fail_messages = [];
            foreach ($selected_ids as $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) continue;
                try {
                    $stmt_app = $pdo->prepare("SELECT * FROM ppdb_registrations WHERE id = ?");
                    $stmt_app->execute([$sid]);
                    $app = $stmt_app->fetch();
                    if ($app && $app['status'] !== 'diterima') {
                        processAcceptStudent($pdo, $app, $class_id);
                        $success_count++;
                    }
                } catch (Exception $e) {
                    $fail_messages[] = $e->getMessage();
                }
            }

            if ($success_count > 0) {
                logActivity($pdo, 'BATCH_ACCEPT_PPDB', "Menerima massal $success_count calon siswa ke kelas ID $class_id");
                $message = "Sukses! Sebanyak $success_count calon siswa berhasil diterima dan akun siswa + orang tua telah diaktifkan serentak.";
                $message_type = "success";
            } else {
                $message = "Gagal memproses siswa: " . implode(', ', $fail_messages);
                $message_type = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// 3. HAPUS PENDAFTAR (Admin only)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_registration') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } elseif ($user_role !== 'administrator') {
        $message = "Hanya administrator yang diizinkan menghapus data registrasi PPDB.";
        $message_type = "error";
    } else {
        $del_id = (int)($_POST['registration_id'] ?? 0);
        try {
            $stmt_del = $pdo->prepare("DELETE FROM ppdb_registrations WHERE id = ?");
            $stmt_del->execute([$del_id]);
            logActivity($pdo, 'DELETE_PPDB', "Menghapus pendaftar PPDB ID $del_id");
            $message = "Data pendaftar berhasil dihapus.";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Gagal menghapus data: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 4. EXPORT DATA PENDAFTAR KE CSV / EXCEL
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $exp_status = trim($_GET['status'] ?? '');
    $exp_major  = trim($_GET['major'] ?? '');
    $exp_track  = trim($_GET['track'] ?? '');
    $exp_doc    = trim($_GET['doc_status'] ?? '');
    $exp_search = trim($_GET['q'] ?? '');

    $exp_where = [];
    $exp_params = [];

    if (!empty($exp_status)) {
        $exp_where[] = "status = ?";
        $exp_params[] = $exp_status;
    }
    if (!empty($exp_major)) {
        $exp_where[] = "chosen_major LIKE ?";
        $exp_params[] = "%$exp_major%";
    }
    if (!empty($exp_track)) {
        $exp_where[] = "track_type = ?";
        $exp_params[] = $exp_track;
    }
    if (!empty($exp_doc)) {
        $exp_where[] = "document_status = ?";
        $exp_params[] = $exp_doc;
    }
    if (!empty($exp_search)) {
        $exp_where[] = "(registration_no LIKE ? OR full_name LIKE ? OR nisn LIKE ? OR previous_school LIKE ?)";
        $term = "%$exp_search%";
        $exp_params[] = $term;
        $exp_params[] = $term;
        $exp_params[] = $term;
        $exp_params[] = $term;
    }

    $exp_clause = !empty($exp_where) ? "WHERE " . implode(" AND ", $exp_where) : "";
    $stmt_exp = $pdo->prepare("
        SELECT r.*, u.id as user_account_id, c.name as assigned_class 
        FROM ppdb_registrations r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        $exp_clause 
        ORDER BY r.id DESC
    ");
    $stmt_exp->execute($exp_params);
    $export_rows = $stmt_exp->fetchAll();

    logActivity($pdo, 'EXPORT_PPDB_CSV', "Mengekspor " . count($export_rows) . " data pendaftar PPDB ke format CSV/Excel");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rekap_ppdb_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // Tambahkan BOM UTF-8 agar Microsoft Excel membaca format karakter Indonesia secara sempurna
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    // Header CSV
    fputcsv($output, [
        'No. Registrasi',
        'Tanggal Daftar',
        'Nama Lengkap Siswa',
        'NISN',
        'NIK',
        'Jenis Kelamin',
        'Tempat Lahir',
        'Tanggal Lahir',
        'Agama',
        'No. HP / WA Siswa',
        'Email Siswa',
        'Alamat Tinggal',
        'Asal Sekolah',
        'Pilihan Peminatan/Jurusan',
        'Jalur Masuk PPDB',
        'Jarak Domisili (KM)',
        'Tingkat Prestasi',
        'Keterangan Prestasi',
        'No. KIP/Afirmasi',
        'Nilai MTK',
        'Nilai IPA',
        'Nilai B.Indo',
        'Nilai B.Inggris',
        'Skor Kalkulasi Rapor',
        'Status Berkas Dokumen',
        'Catatan / Alasan Penolakan Berkas',
        'Nama Orang Tua / Wali',
        'No. HP Orang Tua',
        'Pekerjaan Orang Tua',
        'Status Seleksi',
        'Skor Final Panitia',
        'Catatan Panitia',
        'Status Akun Portal',
        'Kelas Terdaftar'
    ]);

    foreach ($export_rows as $row) {
        fputcsv($output, [
            $row['registration_no'],
            $row['created_at'],
            $row['full_name'],
            "'" . $row['nisn'],
            $row['nik'] ? "'" . $row['nik'] : '-',
            $row['gender'] === 'L' ? 'Laki-Laki (L)' : 'Perempuan (P)',
            $row['birth_place'],
            $row['birth_date'],
            $row['religion'] ?: 'Islam',
            $row['phone'],
            $row['email'],
            $row['address'],
            $row['previous_school'],
            $row['chosen_major'],
            getPpdbTrackLabel($row['track_type'] ?? 'reguler'),
            $row['distance_km'] !== null ? $row['distance_km'] . ' KM' : '-',
            $row['achievement_level'] ? ucfirst($row['achievement_level']) : '-',
            $row['achievement_desc'] ?: '-',
            $row['affirmation_no'] ?: '-',
            $row['score_math'] !== null ? $row['score_math'] : '-',
            $row['score_science'] !== null ? $row['score_science'] : '-',
            $row['score_indonesian'] !== null ? $row['score_indonesian'] : '-',
            $row['score_english'] !== null ? $row['score_english'] : '-',
            $row['calculated_score'] !== null ? $row['calculated_score'] : '-',
            getPpdbDocStatusLabel($row['document_status'] ?? 'lengkap'),
            $row['rejection_reason'] ?: '-',
            $row['parent_name'],
            $row['parent_phone'],
            $row['parent_job'] ?: '-',
            getPpdbStatusLabel($row['status']),
            $row['selection_score'] !== null ? $row['selection_score'] : '-',
            $row['notes'] ?: '-',
            !empty($row['user_account_id']) ? 'Aktif (ID: ' . $row['user_account_id'] . ')' : 'Belum Dibuat',
            $row['assigned_class'] ?: '-'
        ]);
    }
    fclose($output);
    exit;
}

// -------------------------------------------------------------
// 5. FILTER & QUERY DATA PENDAFTAR
// -------------------------------------------------------------
$filter_status = trim($_GET['status'] ?? '');
$filter_major  = trim($_GET['major'] ?? '');
$filter_track  = trim($_GET['track'] ?? '');
$filter_doc    = trim($_GET['doc_status'] ?? '');
$search_query  = trim($_GET['q'] ?? '');

$sql_where = [];
$sql_params = [];

if (!empty($filter_status)) {
    $sql_where[] = "status = ?";
    $sql_params[] = $filter_status;
}

if (!empty($filter_major)) {
    $sql_where[] = "chosen_major LIKE ?";
    $sql_params[] = "%$filter_major%";
}

if (!empty($filter_track)) {
    $sql_where[] = "track_type = ?";
    $sql_params[] = $filter_track;
}

if (!empty($filter_doc)) {
    $sql_where[] = "document_status = ?";
    $sql_params[] = $filter_doc;
}

if (!empty($search_query)) {
    $sql_where[] = "(registration_no LIKE ? OR full_name LIKE ? OR nisn LIKE ? OR previous_school LIKE ?)";
    $term = "%$search_query%";
    $sql_params[] = $term;
    $sql_params[] = $term;
    $sql_params[] = $term;
    $sql_params[] = $term;
}

$where_clause = !empty($sql_where) ? "WHERE " . implode(" AND ", $sql_where) : "";

// Hitung Statistik Lengkap
$stats = [
    'total'        => (int)$pdo->query("SELECT COUNT(*) FROM ppdb_registrations")->fetchColumn(),
    'menunggu'     => (int)$pdo->query("SELECT COUNT(*) FROM ppdb_registrations WHERE status = 'menunggu_verifikasi'")->fetchColumn(),
    'verif'        => (int)$pdo->query("SELECT COUNT(*) FROM ppdb_registrations WHERE status = 'diverifikasi'")->fetchColumn(),
    'lulus'        => (int)$pdo->query("SELECT COUNT(*) FROM ppdb_registrations WHERE status = 'lulus_seleksi'")->fetchColumn(),
    'diterima'     => (int)$pdo->query("SELECT COUNT(*) FROM ppdb_registrations WHERE status = 'diterima'")->fetchColumn(),
    'perlu_revisi' => (int)$pdo->query("SELECT COUNT(*) FROM ppdb_registrations WHERE document_status = 'perlu_revisi'")->fetchColumn(),
];

// Ambil Konfigurasi Sekolah & PPDB
$school_info = getSchoolSettings($pdo);
$ppdb_quota = (int)($school_info['ppdb_quota'] ?? 150);
$ppdb_is_open = ($school_info['ppdb_status'] ?? 'buka') === 'buka';
$ppdb_wave_name = $school_info['ppdb_wave_name'] ?? 'Gelombang 1';
$quota_percentage = $ppdb_quota > 0 ? min(100, round(($stats['total'] / $ppdb_quota) * 100, 1)) : 0;

// Ambil Data Pendaftar
$stmt_list = $pdo->prepare("SELECT * FROM ppdb_registrations $where_clause ORDER BY id DESC");
$stmt_list->execute($sql_params);
$registrations = $stmt_list->fetchAll();

// Ambil Daftar Kelas untuk Dropdown
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY grade_level ASC, name ASC")->fetchAll();

include __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Header Page -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-2 rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-400 mb-2">
                <i class="fa-solid fa-graduation-cap"></i> Panitia Penerimaan Siswa Baru
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white">Manajemen PPDB Online</h1>
            <p class="text-sm text-slate-400 mt-1">Verifikasi berkas, pembobotan skor seleksi otomatis/manual, status revisi, dan 1-klik terbitkan akun siswa baru.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <!-- Tombol Export CSV / Excel -->
            <a href="ppdb.php?action=export_csv&status=<?= urlencode($filter_status) ?>&major=<?= urlencode($filter_major) ?>&track=<?= urlencode($filter_track) ?>&doc_status=<?= urlencode($filter_doc) ?>&q=<?= urlencode($search_query) ?>"
               class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3.5 py-2 text-xs sm:text-sm font-semibold text-emerald-300 transition flex items-center gap-2"
               title="Unduh seluruh data pendaftar atau sesuai filter saat ini ke Excel/CSV">
                <i class="fa-solid fa-file-excel text-emerald-400"></i> Export Excel/CSV
            </a>

            <!-- Tombol Pengaturan Kuota & Buka/Tutup -->
            <a href="settings.php"
               class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs sm:text-sm font-semibold text-slate-200 transition flex items-center gap-2"
               title="Atur status buka/tutup pendaftaran dan batas kuota di Pengaturan Sekolah">
                <i class="fa-solid fa-sliders text-amber-400"></i> Atur Kuota & Status
            </a>

            <a href="../../ppdb/ppdb.php" target="_blank"
               class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs sm:text-sm font-semibold text-slate-200 transition flex items-center gap-2">
                <i class="fa-solid fa-globe text-xs"></i> Portal Publik
            </a>

            <a href="../../ppdb/ppdb.php?tab=daftar" target="_blank"
               class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-lg shadow-emerald-600/20 transition flex items-center gap-2">
                <i class="fa-solid fa-plus text-xs"></i> Input Siswa Baru
            </a>
        </div>
    </div>

    <!-- Banner Status PPDB & Batas Kuota -->
    <div class="rounded-3xl border border-white/10 bg-gradient-to-r from-slate-900 via-slate-900 to-slate-950 p-5 sm:p-6 shadow-xl backdrop-blur">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="flex items-start sm:items-center gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl <?= $ppdb_is_open ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' ?> text-2xl">
                    <i class="fa-solid <?= $ppdb_is_open ? 'fa-door-open' : 'fa-door-closed' ?>"></i>
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-0.5 text-xs font-bold <?= $ppdb_is_open ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-rose-500/20 text-rose-400 border border-rose-500/30' ?>">
                            <span class="h-2 w-2 rounded-full <?= $ppdb_is_open ? 'bg-emerald-400 animate-pulse' : 'bg-rose-400' ?>"></span>
                            <?= $ppdb_is_open ? 'PENDAFTARAN DIBUKA' : 'PENDAFTARAN DITUTUP' ?>
                        </span>
                        <span class="rounded-lg bg-white/5 border border-white/10 px-2.5 py-0.5 text-xs font-semibold text-slate-300">
                            <?= htmlspecialchars($ppdb_wave_name) ?>
                        </span>
                    </div>
                    <p class="text-xs text-slate-400 mt-1.5">
                        <?php if ($ppdb_is_open): ?>
                            Formulir pendaftaran online di portal publik sedang menerima berkas calon siswa baru.
                        <?php else: ?>
                            Formulir publik dinonaktifkan. Pengunjung hanya dapat melacak status registrasi sebelumnya.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="w-full md:w-80 bg-slate-950/80 border border-white/5 rounded-2xl p-4 shrink-0">
                <div class="flex items-center justify-between text-xs mb-2">
                    <span class="text-slate-400 font-semibold">Kapasitas Kuota Pendaftar</span>
                    <span class="font-mono font-bold text-white"><?= $stats['total'] ?> / <?= $ppdb_quota ?> (<?= $quota_percentage ?>%)</span>
                </div>
                <div class="w-full bg-slate-800 rounded-full h-2.5 overflow-hidden">
                    <div class="h-2.5 rounded-full transition-all duration-500 <?= $quota_percentage >= 100 ? 'bg-rose-500' : ($quota_percentage >= 80 ? 'bg-amber-500' : 'bg-emerald-500') ?>" style="width: <?= $quota_percentage ?>%"></div>
                </div>
                <div class="flex items-center justify-between text-[11px] text-slate-500 mt-2">
                    <span>Sisa Slot: <strong class="text-slate-300"><?= max(0, $ppdb_quota - $stats['total']) ?> kursi</strong></span>
                    <a href="settings.php" class="text-blue-400 hover:underline">Ubah Kuota →</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($message)): ?>
        <div class="rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : ($message_type === 'info' ? 'border-blue-500/30 bg-blue-500/10 text-blue-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300') ?>">
            <div class="flex items-center gap-3">
                <span class="text-lg"><?= $message_type === 'success' ? '<i class="fa-solid fa-circle-check text-emerald-400"></i>' : ($message_type === 'info' ? '<i class="fa-solid fa-circle-info text-blue-400"></i>' : '<i class="fa-solid fa-triangle-exclamation text-rose-400"></i>') ?></span>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-xs font-bold opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['newly_activated'])): 
        $activated = $_SESSION['newly_activated'];
        unset($_SESSION['newly_activated']);
    ?>
        <!-- Popup Kredensial Siswa Baru -->
        <div class="rounded-3xl border border-emerald-500/40 bg-gradient-to-r from-emerald-950/80 via-slate-900 to-slate-900 p-6 shadow-2xl relative">
            <button onclick="this.parentElement.remove()" class="absolute top-4 right-4 text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
            <div class="flex items-start gap-4">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-500/20 text-2xl text-emerald-400">
                    <i class="fa-solid fa-graduation-cap"></i>
                </span>
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <span class="rounded-lg bg-emerald-500/20 px-2 py-0.5 text-[11px] font-bold text-emerald-400">AKTIVASI BERHASIL</span>
                        <span class="text-xs text-slate-400">Akun Siswa, Akun Orang Tua & Tagihan SPP Telah Diterbitkan Otomatis</span>
                    </div>
                    <h3 class="text-lg font-bold text-white mt-1">Calon Siswa Diterima: <?= htmlspecialchars($activated['student_name']) ?></h3>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                        <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                            <div class="flex items-center gap-2 text-xs font-bold text-emerald-400 mb-2">
                                <i class="fa-solid fa-user"></i> AKUN SISWA
                            </div>
                            <div class="space-y-1 text-xs">
                                <div class="flex justify-between"><span class="text-slate-400">Email:</span> <span class="font-mono text-white font-semibold select-all"><?= htmlspecialchars($activated['student_email']) ?></span></div>
                                <div class="flex justify-between"><span class="text-slate-400">Password Default:</span> <span class="font-mono text-amber-300 font-bold select-all"><?= htmlspecialchars($activated['student_pass']) ?></span></div>
                                <div class="flex justify-between"><span class="text-slate-400">Status Portal:</span> <span class="text-emerald-400 font-semibold">Aktif & Siap Login</span></div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4">
                            <div class="flex items-center gap-2 text-xs font-bold text-blue-400 mb-2">
                                <i class="fa-solid fa-users"></i> AKUN ORANG TUA / WALI
                            </div>
                            <div class="space-y-1 text-xs">
                                <div class="flex justify-between"><span class="text-slate-400">Nama Wali:</span> <span class="text-white font-semibold"><?= htmlspecialchars($activated['parent_name']) ?></span></div>
                                <div class="flex justify-between"><span class="text-slate-400">Email:</span> <span class="font-mono text-white font-semibold select-all"><?= htmlspecialchars($activated['parent_email']) ?></span></div>
                                <div class="flex justify-between"><span class="text-slate-400">Password Default:</span> <span class="font-mono text-amber-300 font-bold select-all"><?= htmlspecialchars($activated['parent_pass']) ?></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between text-xs text-slate-400">
                        <span><i class="fa-solid fa-lightbulb text-amber-400 mr-1"></i> Tagihan SPP bulan berjalan telah otomatis diterbitkan pada modul Keuangan.</span>
                        <button onclick="navigator.clipboard.writeText('Login Siswa: <?= htmlspecialchars($activated['student_email']) ?> / <?= htmlspecialchars($activated['student_pass']) ?>\nLogin Wali: <?= htmlspecialchars($activated['parent_email']) ?> / <?= htmlspecialchars($activated['parent_pass']) ?>'); alert('Kredensial berhasil disalin ke clipboard!');"
                                class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-semibold text-slate-200 transition flex items-center gap-1.5 cursor-pointer">
                            <i class="fa-solid fa-copy"></i> Salin Kredensial
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Statistik Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-6 gap-3">
        <div class="rounded-2xl border border-white/10 bg-slate-900/50 p-4">
            <span class="text-xs font-semibold text-slate-400">Total Pendaftar</span>
            <p class="text-2xl font-black text-white mt-1"><?= $stats['total'] ?></p>
            <span class="text-[11px] text-slate-500">Semua Jalur</span>
        </div>

        <div class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4">
            <span class="text-xs font-semibold text-amber-400">Menunggu Verifikasi</span>
            <p class="text-2xl font-black text-amber-300 mt-1"><?= $stats['menunggu'] ?></p>
            <span class="text-[11px] text-amber-400/70">Perlu ditinjau</span>
        </div>

        <div class="rounded-2xl border border-orange-500/20 bg-orange-500/5 p-4">
            <span class="text-xs font-semibold text-orange-400">Perlu Revisi</span>
            <p class="text-2xl font-black text-orange-300 mt-1"><?= $stats['perlu_revisi'] ?></p>
            <span class="text-[11px] text-orange-400/70">Berkas buram/kurang</span>
        </div>

        <div class="rounded-2xl border border-blue-500/20 bg-blue-500/5 p-4">
            <span class="text-xs font-semibold text-blue-400">Terverifikasi</span>
            <p class="text-2xl font-black text-blue-300 mt-1"><?= $stats['verif'] ?></p>
            <span class="text-[11px] text-blue-400/70">Siap seleksi/tes</span>
        </div>

        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4">
            <span class="text-xs font-semibold text-emerald-400">Lulus Seleksi</span>
            <p class="text-2xl font-black text-emerald-300 mt-1"><?= $stats['lulus'] ?></p>
            <span class="text-[11px] text-emerald-400/70">Telah dinilai</span>
        </div>

        <div class="col-span-2 sm:col-span-1 rounded-2xl border border-purple-500/20 bg-purple-500/5 p-4">
            <span class="text-xs font-semibold text-purple-400">Resmi Siswa</span>
            <p class="text-2xl font-black text-purple-300 mt-1"><?= $stats['diterima'] ?></p>
            <span class="text-[11px] text-purple-400/70">Akun portal aktif</span>
        </div>
    </div>

    <!-- Filter & Pencarian Bar -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
            <div class="sm:col-span-4">
                <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>" placeholder="Cari nama siswa, NISN, atau no registrasi..."
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-emerald-500 focus:outline-none transition">
            </div>

            <div class="sm:col-span-2">
                <select name="status" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none transition">
                    <option value="">-- Semua Status --</option>
                    <option value="menunggu_verifikasi" <?= $filter_status === 'menunggu_verifikasi' ? 'selected' : '' ?>>Menunggu Verifikasi</option>
                    <option value="diverifikasi" <?= $filter_status === 'diverifikasi' ? 'selected' : '' ?>>Terverifikasi</option>
                    <option value="lulus_seleksi" <?= $filter_status === 'lulus_seleksi' ? 'selected' : '' ?>>Lulus Seleksi</option>
                    <option value="tidak_lulus" <?= $filter_status === 'tidak_lulus' ? 'selected' : '' ?>>Tidak Lulus</option>
                    <option value="diterima" <?= $filter_status === 'diterima' ? 'selected' : '' ?>>Diterima (Resmi Siswa)</option>
                </select>
            </div>

            <div class="sm:col-span-2">
                <select name="track" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none transition">
                    <option value="">-- Semua Jalur --</option>
                    <?php foreach (PPDB_TRACKS as $tk => $ti): ?>
                        <option value="<?= $tk ?>" <?= $filter_track === $tk ? 'selected' : '' ?>><?= htmlspecialchars($ti['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-2">
                <select name="doc_status" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none transition">
                    <option value="">-- Status Berkas --</option>
                    <option value="lengkap" <?= $filter_doc === 'lengkap' ? 'selected' : '' ?>>Berkas Lengkap</option>
                    <option value="perlu_revisi" <?= $filter_doc === 'perlu_revisi' ? 'selected' : '' ?>>Perlu Revisi</option>
                    <option value="ditolak" <?= $filter_doc === 'ditolak' ? 'selected' : '' ?>>Berkas Ditolak</option>
                </select>
            </div>

            <div class="sm:col-span-2 flex items-center gap-2">
                <button type="submit" class="w-full rounded-xl bg-blue-600 hover:bg-blue-500 py-2 text-xs font-semibold text-white transition flex items-center justify-center gap-1.5 cursor-pointer">
                    <i class="fa-solid fa-magnifying-glass text-xs"></i> Filter
                </button>
                <?php if (!empty($filter_status) || !empty($filter_major) || !empty($filter_track) || !empty($filter_doc) || !empty($search_query)): ?>
                    <a href="ppdb.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-xs text-slate-300 transition" title="Reset Filter">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Batch Action Bar -->
    <div id="batchActionBar" class="hidden rounded-2xl border border-emerald-500/30 bg-emerald-950/60 backdrop-blur p-4 shadow-xl transition-all">
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 font-bold text-sm">
                    <i class="fa-solid fa-check"></i>
                </span>
                <div>
                    <div class="font-bold text-white text-sm">
                        <span id="selectedCount" class="text-emerald-400 font-mono text-base">0</span> Calon Siswa Dipilih
                    </div>
                    <div class="text-[11px] text-slate-400">
                        Aktivasi massal akan otomatis membuat akun Siswa, akun Orang Tua, dan menerbitkan tagihan SPP awal.
                    </div>
                </div>
            </div>

            <form id="formBatchPPDB" method="POST" action="ppdb.php" class="flex flex-wrap items-center gap-2.5 w-full sm:w-auto" onsubmit="return confirm('Apakah Anda yakin ingin menerima dan mengaktifkan seluruh calon siswa terpilih secara massal?');">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="batch_accept_students">
                
                <div class="flex items-center gap-2">
                    <label class="text-xs text-slate-300 font-medium whitespace-nowrap">Tempatkan di Kelas:</label>
                    <select name="batch_class_id" required class="rounded-xl border border-white/20 bg-slate-900 px-3 py-1.5 text-xs text-white focus:border-emerald-500 focus:outline-none">
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-emerald-600/25 transition cursor-pointer flex items-center gap-1.5">
                    <i class="fa-solid fa-rocket"></i> Terima & Aktivasi Massal
                </button>
            </form>
        </div>
    </div>

    <!-- Data Table Pendaftar -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/50 backdrop-blur overflow-hidden shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs sm:text-sm">
                <thead class="border-b border-white/10 bg-white/5 text-slate-300 font-bold uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="px-3 py-3.5 text-center w-10">
                            <input type="checkbox" id="selectAllPPDB" onchange="toggleSelectAll(this)" title="Pilih Semua" class="rounded border-white/20 bg-slate-950 text-emerald-500 focus:ring-0 cursor-pointer">
                        </th>
                        <th class="px-4 py-3.5">No. Registrasi</th>
                        <th class="px-4 py-3.5">Nama & NISN</th>
                        <th class="px-4 py-3.5">Asal Sekolah</th>
                        <th class="px-4 py-3.5">Peminatan & Jalur</th>
                        <th class="px-4 py-3.5 text-center">Status Berkas</th>
                        <th class="px-4 py-3.5 text-center">Skor Seleksi</th>
                        <th class="px-4 py-3.5">Status</th>
                        <th class="px-4 py-3.5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-slate-200">
                    <?php if (empty($registrations)): ?>
                        <tr>
                            <td colspan="9" class="px-6 py-12 text-center text-slate-400">
                                <i class="fa-solid fa-file-lines text-3xl block mb-2 text-slate-500"></i>
                                Tidak ada data pendaftar yang sesuai kriteria pencarian.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($registrations as $r): ?>
                            <tr class="hover:bg-white/5 transition">
                                <td class="px-3 py-3.5 text-center">
                                    <?php if ($r['status'] !== 'diterima'): ?>
                                        <input type="checkbox" form="formBatchPPDB" name="selected_ids[]" value="<?= $r['id'] ?>" onchange="updateBatchUI()" class="ppdb-checkbox rounded border-white/20 bg-slate-950 text-emerald-500 focus:ring-0 cursor-pointer">
                                    <?php else: ?>
                                        <span class="text-slate-600 font-mono text-xs">-</span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-4 py-3.5 whitespace-nowrap font-mono font-bold text-white">
                                    <?= htmlspecialchars($r['registration_no']) ?>
                                    <span class="block text-[10px] font-sans font-normal text-slate-400">
                                        <?= date('d/m/Y', strtotime($r['created_at'])) ?>
                                    </span>
                                </td>

                                <td class="px-4 py-3.5">
                                    <div class="font-bold text-white"><?= htmlspecialchars($r['full_name']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-mono">
                                        NISN: <?= htmlspecialchars($r['nisn']) ?> (<?= $r['gender'] ?>)
                                    </div>
                                    <div class="text-[11px] text-slate-500 flex items-center gap-1">
                                        <i class="fa-solid fa-phone text-[10px] text-slate-500"></i> <?= htmlspecialchars($r['phone']) ?>
                                    </div>
                                </td>

                                <td class="px-4 py-3.5 text-slate-300">
                                    <?= htmlspecialchars($r['previous_school']) ?>
                                </td>

                                <td class="px-4 py-3.5">
                                    <div class="font-medium text-emerald-400"><?= htmlspecialchars($r['chosen_major']) ?></div>
                                    <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold <?= getPpdbTrackBadge($r['track_type'] ?? 'reguler') ?> mt-0.5">
                                        <i class="fa-solid <?= getPpdbTrackIcon($r['track_type'] ?? 'reguler') ?>"></i>
                                        <?= htmlspecialchars(getPpdbTrackLabel($r['track_type'] ?? 'reguler')) ?>
                                    </span>
                                </td>

                                <td class="px-4 py-3.5 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center rounded-xl border px-2 py-0.5 text-[10px] font-semibold <?= getPpdbDocStatusBadge($r['document_status'] ?? 'lengkap') ?>">
                                        <?= htmlspecialchars(getPpdbDocStatusLabel($r['document_status'] ?? 'lengkap')) ?>
                                    </span>
                                </td>

                                <td class="px-4 py-3.5 text-center font-bold font-mono">
                                    <?= $r['selection_score'] !== null ? number_format((float)$r['selection_score'], 1) : '<span class="text-slate-500 font-normal">-</span>' ?>
                                    <?php if ($r['calculated_score'] !== null): ?>
                                        <span class="block text-[9px] font-sans font-normal text-slate-400">Rapor: <?= number_format((float)$r['calculated_score'], 1) ?></span>
                                    <?php endif; ?>
                                </td>

                                <td class="px-4 py-3.5 whitespace-nowrap">
                                    <span class="inline-flex items-center rounded-xl border px-2.5 py-1 text-[11px] font-semibold <?= getPpdbStatusBadge($r['status']) ?>">
                                        <?= htmlspecialchars(getPpdbStatusLabel($r['status'])) ?>
                                    </span>
                                </td>

                                <td class="px-4 py-3.5 whitespace-nowrap text-right">
                                    <div class="inline-flex items-center gap-1.5">
                                        <!-- Tombol Review & Berkas Modal -->
                                        <button onclick="openReviewModal(<?= htmlspecialchars(json_encode($r)) ?>)"
                                                class="rounded-lg border border-white/10 bg-white/5 hover:bg-white/15 px-2.5 py-1 text-xs font-semibold text-slate-200 transition inline-flex items-center gap-1 cursor-pointer"
                                                title="Lihat Detail & Dokumen">
                                            <i class="fa-solid fa-magnifying-glass text-xs"></i> Review
                                        </button>

                                        <!-- Tombol Cetak Kartu -->
                                        <a href="../../ppdb/ppdb_card.php?reg=<?= urlencode($r['registration_no']) ?>" target="_blank"
                                           class="rounded-lg border border-white/10 bg-white/5 hover:bg-white/15 px-2.5 py-1 text-xs font-semibold text-slate-300 transition"
                                           title="Cetak Kartu Registrasi">
                                            <i class="fa-solid fa-print text-xs"></i>
                                        </a>

                                        <!-- Tombol 1-Klik Terima & Buat Akun Siswa -->
                                        <?php if ($r['status'] !== 'diterima'): ?>
                                            <button onclick="openAcceptModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['full_name'])) ?>', '<?= htmlspecialchars(addslashes($r['chosen_major'])) ?>')"
                                                    class="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-2.5 py-1 text-xs font-bold text-white shadow-md shadow-emerald-600/20 transition cursor-pointer inline-flex items-center gap-1"
                                                    title="Terima & Otomatis Buat Akun Siswa">
                                                <i class="fa-solid fa-check text-xs"></i> Terima
                                            </button>
                                        <?php else: ?>
                                            <span class="rounded-lg border border-purple-500/30 bg-purple-500/10 px-2 py-0.5 text-[10px] font-bold text-purple-300 inline-flex items-center gap-1">
                                                Resmi Siswa <i class="fa-solid fa-graduation-cap"></i>
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($user_role === 'administrator'): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Hapus data pendaftar ini secara permanen?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_registration">
                                                <input type="hidden" name="registration_id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="rounded-lg border border-rose-500/20 bg-rose-500/10 hover:bg-rose-500/20 px-2 py-1 text-xs text-rose-400 transition cursor-pointer" title="Hapus Data">
                                                    <i class="fa-solid fa-trash text-xs"></i>
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

<!-- MODAL REVIEW & VERIFIKASI BERKAS -->
<div id="reviewModal" class="fixed inset-0 z-50 hidden bg-black/70 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="relative w-full max-w-3xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl text-slate-100 my-8 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-6">
            <div>
                <h3 class="text-xl font-bold text-white">Review Berkas & Penilaian Calon Siswa</h3>
                <p id="modalRegNo" class="text-xs font-mono text-emerald-400 mt-0.5"></p>
            </div>
            <button onclick="closeReviewModal()" class="text-slate-400 hover:text-white text-lg cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="ppdb.php" class="space-y-6">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_ppdb_status">
            <input type="hidden" name="registration_id" id="modalRegId">

            <!-- Data Singkat & Dokumen -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs bg-slate-950/60 p-4 rounded-2xl border border-white/5">
                <div>
                    <span class="text-slate-400">Nama Lengkap:</span>
                    <p id="modalName" class="font-bold text-white text-sm mt-0.5"></p>
                </div>
                <div>
                    <span class="text-slate-400">NISN / Asal Sekolah:</span>
                    <p id="modalNisnSchool" class="font-bold text-white text-sm mt-0.5"></p>
                </div>
                <div>
                    <span class="text-slate-400">Jalur & Peminatan:</span>
                    <p id="modalTrackMajor" class="font-semibold text-emerald-400 mt-0.5"></p>
                </div>
                <div>
                    <span class="text-slate-400">Orang Tua / Kontak:</span>
                    <p id="modalParent" class="font-semibold text-slate-300 mt-0.5"></p>
                </div>
                <div class="sm:col-span-2 border-t border-white/5 pt-2">
                    <span class="text-slate-400">Parameter Jalur Khusus:</span>
                    <p id="modalTrackParams" class="text-xs text-amber-300 mt-0.5"></p>
                </div>
            </div>

            <!-- Rincian Nilai Rapor -->
            <div class="rounded-2xl border border-white/5 bg-slate-950/80 p-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Nilai Rapor 4 Mapel Utama:</span>
                    <span id="modalCalcScoreBadge" class="text-xs font-mono font-bold text-teal-400"></span>
                </div>
                <div class="grid grid-cols-4 gap-2 text-center text-xs">
                    <div class="rounded-xl bg-slate-900/80 p-2 border border-white/5">
                        <span class="text-[10px] text-slate-400 block">Matematika</span>
                        <strong id="modalScoreMath" class="font-mono text-white text-sm"></strong>
                    </div>
                    <div class="rounded-xl bg-slate-900/80 p-2 border border-white/5">
                        <span class="text-[10px] text-slate-400 block">IPA</span>
                        <strong id="modalScoreSci" class="font-mono text-white text-sm"></strong>
                    </div>
                    <div class="rounded-xl bg-slate-900/80 p-2 border border-white/5">
                        <span class="text-[10px] text-slate-400 block">B. Indonesia</span>
                        <strong id="modalScoreIndo" class="font-mono text-white text-sm"></strong>
                    </div>
                    <div class="rounded-xl bg-slate-900/80 p-2 border border-white/5">
                        <span class="text-[10px] text-slate-400 block">B. Inggris</span>
                        <strong id="modalScoreEng" class="font-mono text-white text-sm"></strong>
                    </div>
                </div>
            </div>

            <!-- Download / Preview Berkas Dokumen -->
            <div>
                <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-2">Dokumen Berkas Terunggah:</label>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div id="docRapor" class="p-3 rounded-xl border border-white/5 bg-slate-950 text-center">
                        <i class="fa-solid fa-file-lines text-xl block mb-1 text-blue-400"></i>
                        <span class="text-[11px] font-semibold text-slate-300 block mt-1">Rapor Siswa</span>
                        <a id="linkRapor" href="#" target="_blank" class="text-xs text-blue-400 hover:underline mt-1 inline-block">Lihat Berkas ↗</a>
                    </div>
                    <div id="docKK" class="p-3 rounded-xl border border-white/5 bg-slate-950 text-center">
                        <i class="fa-solid fa-id-card text-xl block mb-1 text-emerald-400"></i>
                        <span class="text-[11px] font-semibold text-slate-300 block mt-1">Kartu Keluarga</span>
                        <a id="linkKK" href="#" target="_blank" class="text-xs text-blue-400 hover:underline mt-1 inline-block">Lihat Berkas ↗</a>
                    </div>
                    <div id="docAkta" class="p-3 rounded-xl border border-white/5 bg-slate-950 text-center">
                        <i class="fa-solid fa-certificate text-xl block mb-1 text-amber-400"></i>
                        <span class="text-[11px] font-semibold text-slate-300 block mt-1">Akta Kelahiran</span>
                        <a id="linkAkta" href="#" target="_blank" class="text-xs text-blue-400 hover:underline mt-1 inline-block">Lihat Berkas ↗</a>
                    </div>
                    <div id="docFoto" class="p-3 rounded-xl border border-white/5 bg-slate-950 text-center">
                        <i class="fa-solid fa-image text-xl block mb-1 text-purple-400"></i>
                        <span class="text-[11px] font-semibold text-slate-300 block mt-1">Pas Foto 3x4</span>
                        <a id="linkFoto" href="#" target="_blank" class="text-xs text-blue-400 hover:underline mt-1 inline-block">Lihat Berkas ↗</a>
                    </div>
                </div>
            </div>

            <!-- Status Berkas & Revisi -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 rounded-2xl border border-white/5 bg-slate-950 p-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Status Validitas Berkas Dokumen *</label>
                    <select name="document_status" id="modalDocStatus" required
                            class="w-full rounded-xl border border-white/10 bg-slate-900 px-3.5 py-2.5 text-xs text-white focus:border-amber-400 focus:outline-none">
                        <option value="lengkap">Lengkap & Valid</option>
                        <option value="perlu_revisi">Perlu Revisi (Minta Upload Ulang)</option>
                        <option value="ditolak">Berkas Ditolak / Tidak Sah</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Instruksi Revisi Berkas (Tampil ke Pendaftar)</label>
                    <input type="text" name="rejection_reason" id="modalDocNotes" placeholder="Contoh: Foto KK terpotong / Rapor semester 5 belum diunggah"
                           class="w-full rounded-xl border border-white/10 bg-slate-900 px-3.5 py-2.5 text-xs text-white focus:border-amber-400 focus:outline-none">
                </div>
            </div>

            <!-- Input Nilai & Update Status -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Status Seleksi Panitia *</label>
                    <select name="status" id="modalStatusSelect" required
                            class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                        <option value="menunggu_verifikasi">Menunggu Verifikasi</option>
                        <option value="diverifikasi">Diverifikasi (Berkas Lengkap)</option>
                        <option value="lulus_seleksi">Lulus Seleksi</option>
                        <option value="tidak_lulus">Tidak Lulus</option>
                        <option value="diterima">Diterima Resmi Siswa</option>
                    </select>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-semibold text-slate-300">Skor Seleksi (0 - 100)</label>
                        <button type="button" onclick="useCalculatedScore()" id="btnUseCalc" class="text-[10px] text-teal-400 hover:underline cursor-pointer">
                            Gunakan Skor Kalkulasi Rapor
                        </button>
                    </div>
                    <input type="number" step="0.01" min="0" max="100" name="selection_score" id="modalScore" placeholder="Contoh: 85.50"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition font-mono">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Catatan Tambahan Panitia Seleksi</label>
                    <textarea name="notes" id="modalNotes" rows="2" placeholder="Catatan hasil verifikasi atau arahan daftar ulang..."
                              class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-emerald-500 focus:outline-none transition"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="closeReviewModal()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-6 py-2 text-xs font-bold text-white shadow-lg shadow-blue-600/20 transition cursor-pointer flex items-center gap-1.5">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Penilaian & Berkas
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 1-KLIK TERIMA & BUAT AKUN SISWA -->
<div id="acceptModal" class="fixed inset-0 z-50 hidden bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="relative w-full max-w-md rounded-3xl border border-emerald-500/30 bg-slate-900 p-6 sm:p-8 shadow-2xl text-slate-100">
        <div class="text-center mb-6">
            <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-500/10 text-2xl text-emerald-400 mx-auto mb-3">
                <i class="fa-solid fa-graduation-cap"></i>
            </span>
            <h3 class="text-xl font-bold text-white">Terima & Buat Akun Siswa</h3>
            <p class="text-xs text-slate-400 mt-1">Siswa akan otomatis dimasukkan ke kelas dan diberikan akun login portal.</p>
        </div>

        <form method="POST" action="ppdb.php" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="accept_and_create_account">
            <input type="hidden" name="registration_id" id="acceptRegId">

            <div class="rounded-xl bg-slate-950 p-4 border border-white/5">
                <span class="text-xs text-slate-400 block">Nama Calon Siswa:</span>
                <strong id="acceptStudentName" class="text-sm text-white block mt-0.5"></strong>
                <span id="acceptStudentMajor" class="text-xs text-emerald-400 font-semibold block mt-1"></span>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tempatkan di Rombel / Kelas Baru *</label>
                <select name="class_id" required
                        class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none transition">
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-3 text-xs text-emerald-300 flex items-start gap-2">
                <i class="fa-solid fa-lightbulb text-amber-400 mt-0.5"></i>
                <span>Password login awal siswa akan diset ke <strong>password</strong> secara default. Siswa dapat langsung login menggunakan email pendaftarannya.</span>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="closeAcceptModal()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-emerald-600/25 transition cursor-pointer flex items-center gap-1.5">
                    <i class="fa-solid fa-rocket"></i> Terima & Terbitkan Akun
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentCalculatedScore = null;

function openReviewModal(data) {
    document.getElementById('modalRegId').value = data.id;
    document.getElementById('modalRegNo').textContent = 'No. Registrasi: ' + data.registration_no;
    document.getElementById('modalName').textContent = data.full_name;
    document.getElementById('modalNisnSchool').textContent = data.nisn + ' (' + data.previous_school + ')';
    document.getElementById('modalTrackMajor').textContent = (data.track_type ? data.track_type.toUpperCase() : 'REGULER') + ' — ' + data.chosen_major;
    document.getElementById('modalParent').textContent = data.parent_name + ' / ' + data.parent_phone;
    
    // Parameter Jalur
    let trackDetails = 'Jalur Reguler (Tes/Rapor)';
    if (data.track_type === 'zonasi') {
        trackDetails = 'Jarak Domisili: ' + (data.distance_km ? data.distance_km + ' KM' : 'Belum diisi');
    } else if (data.track_type === 'prestasi') {
        trackDetails = 'Prestasi: ' + (data.achievement_level ? data.achievement_level.toUpperCase() : '-') + (data.achievement_desc ? ' (' + data.achievement_desc + ')' : '');
    } else if (data.track_type === 'afirmasi') {
        trackDetails = 'No. Kartu KIP/Afirmasi: ' + (data.affirmation_no || '-');
    }
    document.getElementById('modalTrackParams').textContent = trackDetails;

    // Nilai Rapor
    document.getElementById('modalScoreMath').textContent = data.score_math !== null ? data.score_math : '-';
    document.getElementById('modalScoreSci').textContent = data.score_science !== null ? data.score_science : '-';
    document.getElementById('modalScoreIndo').textContent = data.score_indonesian !== null ? data.score_indonesian : '-';
    document.getElementById('modalScoreEng').textContent = data.score_english !== null ? data.score_english : '-';
    
    currentCalculatedScore = data.calculated_score;
    document.getElementById('modalCalcScoreBadge').textContent = data.calculated_score !== null ? 'Skor Kalkulasi Rapor: ' + data.calculated_score : '';

    // Document Status & Notes
    document.getElementById('modalDocStatus').value = data.document_status || 'lengkap';
    document.getElementById('modalDocNotes').value = data.rejection_reason || '';

    // Status Seleksi & Nilai
    document.getElementById('modalStatusSelect').value = data.status;
    document.getElementById('modalScore').value = data.selection_score !== null ? data.selection_score : '';
    document.getElementById('modalNotes').value = data.notes || '';

    // Handle documents
    setupDocLink('linkRapor', 'docRapor', data.report_card_doc);
    setupDocLink('linkKK', 'docKK', data.family_card_doc);
    setupDocLink('linkAkta', 'docAkta', data.birth_cert_doc);
    setupDocLink('linkFoto', 'docFoto', data.photo_doc);

    document.getElementById('reviewModal').classList.remove('hidden');
}

function useCalculatedScore() {
    if (currentCalculatedScore !== null) {
        document.getElementById('modalScore').value = currentCalculatedScore;
    } else {
        alert('Data nilai rapor tidak lengkap untuk melakukan kalkulasi otomatis.');
    }
}

function setupDocLink(linkId, boxId, path) {
    const link = document.getElementById(linkId);
    const box = document.getElementById(boxId);
    if (path) {
        link.href = '../../' + path;
        link.textContent = 'Lihat Berkas ↗';
        link.classList.remove('text-slate-600', 'pointer-events-none');
        link.classList.add('text-blue-400');
        box.classList.remove('opacity-40');
    } else {
        link.removeAttribute('href');
        link.textContent = 'Belum Diunggah';
        link.classList.remove('text-blue-400');
        link.classList.add('text-slate-600', 'pointer-events-none');
        box.classList.add('opacity-40');
    }
}

function closeReviewModal() {
    document.getElementById('reviewModal').classList.add('hidden');
}

function openAcceptModal(id, name, major) {
    document.getElementById('acceptRegId').value = id;
    document.getElementById('acceptStudentName').textContent = name;
    document.getElementById('acceptStudentMajor').textContent = 'Pilihan: ' + major;
    document.getElementById('acceptModal').classList.remove('hidden');
}

function closeAcceptModal() {
    document.getElementById('acceptModal').classList.add('hidden');
}

function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.ppdb-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = master.checked;
    });
    updateBatchUI();
}

function updateBatchUI() {
    const checkboxes = document.querySelectorAll('.ppdb-checkbox:checked');
    const count = checkboxes.length;
    const bar = document.getElementById('batchActionBar');
    const countDisplay = document.getElementById('selectedCount');
    
    if (count > 0) {
        bar.classList.remove('hidden');
        countDisplay.textContent = count;
    } else {
        bar.classList.add('hidden');
        countDisplay.textContent = '0';
        const master = document.getElementById('selectAllPPDB');
        if (master) master.checked = false;
    }
}
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
