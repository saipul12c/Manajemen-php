<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff = in_array($user_role, ['staf', 'administrator'], true);

$message = "";
$message_type = "";

// Buat direktori upload lampiran surat jika belum ada
$upload_letter_dir = __DIR__ . "/../../uploads/letters";
if (!is_dir($upload_letter_dir)) {
    mkdir($upload_letter_dir, 0755, true);
}

// -------------------------------------------------------------
// 1. SISWA / ORANG TUA: AJUKAN PERMOHONAN SURAT BARU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_request') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan coba lagi.";
        $message_type = "error";
    } else {
        $request_type = trim($_POST['request_type'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $target_date = !empty($_POST['target_date']) ? $_POST['target_date'] : date('Y-m-d');
        $attachment_url = null;

        // BUG-09 fix: Handle Upload Berkas / Surat Dokter dengan validasi MIME-type
        if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp  = $_FILES['attachment_file']['tmp_name'];
            $file_name = $_FILES['attachment_file']['name'];
            $file_size = $_FILES['attachment_file']['size'];
            $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext   = ['pdf', 'jpg', 'jpeg', 'png'];
            $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png'];

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected_mime = finfo_file($finfo, $file_tmp);
            finfo_close($finfo);

            if (!in_array($file_ext, $allowed_ext, true) || !in_array($detected_mime, $allowed_mimes, true)) {
                $message = "Format file tidak didukung atau isi file tidak valid. Gunakan berkas asli JPG, PNG, atau PDF.";
                $message_type = "error";
            } elseif ($file_size > 10 * 1024 * 1024) {
                $message = "Ukuran file lampiran melebihi batas maksimal 10MB.";
                $message_type = "error";
            } else {
                $new_filename = 'surat_' . $user_id . '_' . time() . '.' . $file_ext;
                $destination  = $upload_letter_dir . '/' . $new_filename;
                if (move_uploaded_file($file_tmp, $destination)) {
                    $attachment_url = '../../uploads/letters/' . $new_filename;
                }
            }
        }

        if ($message_type !== 'error') {
            if ($request_type === '') {
                $message = "Jenis surat permohonan wajib dipilih.";
                $message_type = "error";
            } else {
                $stmt_in = $pdo->prepare("
                    INSERT INTO service_requests (user_id, request_type, target_date, notes, attachment_url, status) 
                    VALUES (?, ?, ?, ?, ?, 'menunggu')
                ");
                $stmt_in->execute([$user_id, $request_type, $target_date, $notes, $attachment_url]);
                logActivity($pdo, 'SUBMIT_REQUEST', "Mengajukan permohonan: $request_type");
                $message = "Permohonan surat berhasil dikirim ke bagian Tata Usaha.";
                $message_type = "success";
            }
        }
    }
}

// -------------------------------------------------------------
// 2. STAF / ADMIN: TERBITKAN SURAT RESMI LANGSUNG (SISWA / GURU / STAF)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'staff_create_letter' && $is_staff) {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $recipient_id = (int) ($_POST['recipient_id'] ?? $_POST['student_id'] ?? 0);
        $request_type = trim($_POST['request_type'] ?? '');
        $target_date = !empty($_POST['target_date']) ? $_POST['target_date'] : date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');

        // Validasi data penerima (siswa, guru, atau staf)
        $stmt_chk = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ? AND role IN ('siswa', 'guru', 'staf')");
        $stmt_chk->execute([$recipient_id]);
        $target_user = $stmt_chk->fetch();

        if (!$target_user) {
            $message = "Pilih penerima surat yang valid (siswa, guru, atau tenaga kependidikan).";
            $message_type = "error";
        } elseif ($request_type === '') {
            $message = "Jenis surat resmi wajib dipilih.";
            $message_type = "error";
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO service_requests (user_id, request_type, target_date, notes, status, processed_by)
                VALUES (?, ?, ?, ?, 'selesai', ?)
            ");
            $stmt_ins->execute([$recipient_id, $request_type, $target_date, $notes, $user_id]);
            $new_letter_id = $pdo->lastInsertId();

            // Jika surat dispensasi atau izin sakit untuk siswa, sinkronisasi otomatis ke presensi
            if ($target_user['role'] === 'siswa') {
                $is_leave = (stripos($request_type, 'Izin') !== false || stripos($request_type, 'Sakit') !== false || stripos($request_type, 'Dispensasi') !== false);
                if ($is_leave) {
                    $att_status = (stripos($request_type, 'Sakit') !== false) ? 'sakit' : 'izin';
                    $att_notes  = "Diterbitkan TU via Surat #" . $new_letter_id . " (" . $request_type . ")";
                    $stmt_att_sync = $pdo->prepare("
                        INSERT INTO student_attendance (student_id, date, status, notes, recorded_by)
                        VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes), recorded_by = VALUES(recorded_by)
                    ");
                    $stmt_att_sync->execute([$recipient_id, $target_date, $att_status, $att_notes, $user_id]);
                }
            }

            logActivity($pdo, 'CREATE_OFFICIAL_LETTER', "Menerbitkan $request_type untuk {$target_user['role']} {$target_user['name']} (#$new_letter_id)");
            $message = "Surat resmi (" . htmlspecialchars($request_type) . ") untuk " . htmlspecialchars($target_user['name']) . " (" . ucfirst($target_user['role']) . ") berhasil diterbitkan dan siap dicetak!";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 3. STAF / ADMIN: PERBARUI STATUS PERMOHONAN & INTEGRASI PRESENSI
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_request_status' && $is_staff) {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $update_id = (int) ($_POST['update_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';

        $valid_statuses = ['menunggu', 'diproses', 'selesai', 'ditolak'];
        if (in_array($new_status, $valid_statuses, true)) {
            $stmt_get = $pdo->prepare("
                SELECT r.*, u.role as applicant_role 
                FROM service_requests r 
                JOIN users u ON r.user_id = u.id 
                WHERE r.id = ?
            ");
            $stmt_get->execute([$update_id]);
            $req_data = $stmt_get->fetch();

            if ($req_data) {
                $stmt_up = $pdo->prepare("UPDATE service_requests SET status = ?, processed_by = ? WHERE id = ?");
                $stmt_up->execute([$new_status, $user_id, $update_id]);

                // Jika disetujui ('selesai') dan berupa surat izin/sakit, sinkron otomatis ke tabel student_attendance!
                if ($new_status === 'selesai') {
                    $rtype = $req_data['request_type'];
                    $is_leave = (stripos($rtype, 'Izin') !== false || stripos($rtype, 'Sakit') !== false || stripos($rtype, 'Dispensasi') !== false);

                    if ($is_leave) {
                        $stu_id = null;
                        if ($req_data['applicant_role'] === 'siswa') {
                            $stu_id = (int) $req_data['user_id'];
                        } elseif ($req_data['applicant_role'] === 'orang_tua') {
                            $c_id = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
                            $c_id->execute([$req_data['user_id']]);
                            $stu_id = $c_id->fetchColumn();
                        }

                        if ($stu_id) {
                            $att_date = $req_data['target_date'] ?: date('Y-m-d');
                            $att_status = (stripos($rtype, 'Sakit') !== false) ? 'sakit' : 'izin';
                            $att_notes  = "Disetujui TU via Permohonan #" . $req_data['id'] . " (" . $rtype . ")";

                            $stmt_att_sync = $pdo->prepare("
                                INSERT INTO student_attendance (student_id, date, status, notes, recorded_by)
                                VALUES (?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    status = VALUES(status),
                                    notes = VALUES(notes),
                                    recorded_by = VALUES(recorded_by)
                            ");
                            $stmt_att_sync->execute([$stu_id, $att_date, $att_status, $att_notes, $user_id]);
                        }
                    }
                }

                logActivity($pdo, 'UPDATE_REQUEST', "Mengubah status permohonan #$update_id menjadi: $new_status");
                $message = "Status permohonan surat berhasil diubah menjadi: " . strtoupper($new_status);
                $message_type = "success";
            }
        }
    }
}

// -------------------------------------------------------------
// 4. DAFTAR SISWA & GURU/PEGAWAI UNTUK DROPDOWN STAF
// -------------------------------------------------------------
$students_list = [];
$staff_teachers_list = [];
if ($is_staff) {
    $students_list = $pdo->query("
        SELECT u.id, u.name, u.nisn, c.name as class_name 
        FROM users u 
        LEFT JOIN classes c ON u.class_id = c.id 
        WHERE u.role = 'siswa' 
        ORDER BY c.grade_level ASC, c.name ASC, u.name ASC
    ")->fetchAll();

    $staff_teachers_list = $pdo->query("
        SELECT u.id, u.name, u.role, u.email
        FROM users u
        WHERE u.role IN ('guru', 'staf')
        ORDER BY u.role DESC, u.name ASC
    ")->fetchAll();
}

// -------------------------------------------------------------
// 5. QUERY DAN FILTER PERMOHONAN SURAT
// -------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_type = trim($_GET['type'] ?? '');

$sql = "
    SELECT r.*, u.name as applicant_name, u.role as applicant_role, u.email as applicant_email, 
           c.name as class_name, p.name as processor_name
    FROM service_requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN users p ON r.processed_by = p.id
    WHERE 1=1
";
$params = [];

if (!$is_staff) {
    if ($user_role === 'orang_tua') {
        $sql .= " AND (r.user_id = ? OR r.user_id IN (SELECT student_id FROM parent_students WHERE parent_id = ?))";
        $params[] = $user_id;
        $params[] = $user_id;
    } else {
        $sql .= " AND r.user_id = ?";
        $params[] = $user_id;
    }
}

if ($search !== '') {
    $sql .= " AND (u.name LIKE ? OR r.request_type LIKE ? OR r.notes LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter_status !== '' && in_array($filter_status, ['menunggu', 'diproses', 'selesai', 'ditolak'], true)) {
    $sql .= " AND r.status = ?";
    $params[] = $filter_status;
}

if ($filter_type !== '') {
    $sql .= " AND r.request_type = ?";
    $params[] = $filter_type;
}

$sql .= " ORDER BY r.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests_list = $stmt->fetchAll();

// Hitung Ringkasan Statistik
if ($is_staff) {
    $stats_total = $pdo->query("SELECT COUNT(*) FROM service_requests")->fetchColumn();
    $stats_pending = $pdo->query("SELECT COUNT(*) FROM service_requests WHERE status = 'menunggu'")->fetchColumn();
    $stats_process = $pdo->query("SELECT COUNT(*) FROM service_requests WHERE status = 'diproses'")->fetchColumn();
    $stats_done = $pdo->query("SELECT COUNT(*) FROM service_requests WHERE status = 'selesai'")->fetchColumn();
} else {
    if ($user_role === 'orang_tua') {
        $stmt_st = $pdo->prepare("
            SELECT status, COUNT(*) as cnt 
            FROM service_requests 
            WHERE (user_id = ? OR user_id IN (SELECT student_id FROM parent_students WHERE parent_id = ?)) 
            GROUP BY status
        ");
        $stmt_st->execute([$user_id, $user_id]);
    } else {
        $stmt_st = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM service_requests WHERE user_id = ? GROUP BY status");
        $stmt_st->execute([$user_id]);
    }
    $user_stats = $stmt_st->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats_total = array_sum($user_stats);
    $stats_pending = $user_stats['menunggu'] ?? 0;
    $stats_process = $user_stats['diproses'] ?? 0;
    $stats_done = $user_stats['selesai'] ?? 0;
}

// Master Jenis Surat Terstandarisasi
$official_letter_types = [
    'Surat Keterangan Aktif Siswa' => 'Keterangan bahwa siswa terdaftar aktif mengikuti KBM pada tahun ajaran berjalan.',
    'Surat Keterangan Berkelakuan Baik (SKBB)' => 'Keterangan tidak pernah terlibat tindak kriminal, narkoba, atau tawuran.',
    'Surat Keterangan Lulus (SKL) Sementara' => 'Keterangan kelulusan sementara sebelum ijazah asli fisik diterbitkan.',
    'Surat Undangan / Panggilan Orang Tua Murid' => 'Surat panggilan resmi kepada orang tua/wali murid untuk hadir ke sekolah.',
    'Surat Keterangan Pindah / Mutasi Siswa' => 'Pelepasan siswa mutasi serta keterangan bebas tanggungan SPP & perpustakaan.',
    'Surat Rekomendasi Beasiswa / Prestasi' => 'Rekomendasi resmi untuk pengajuan beasiswa, perlombaan, atau event kedinasan.',
    'Surat Izin Dispensasi Acara / Kegiatan' => 'Dispensasi izin tidak mengikuti KBM karena kegiatan resmi / lomba sekolah.',
    'Surat Izin Sakit Siswa' => 'Pengesahan izin sakit siswa berdasarkan keterangan dokter atau surat orang tua.',
    'Surat Perintah Tugas (SPT) Guru / Pegawai' => 'Surat tugas kedinasan, pelatihan/MGMP, atau kepengawasan untuk pendidik dan tenaga kependidikan.'
];

$page_title = "Layanan Surat Tata Usaha";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
            <span class="text-blue-400"><i class="fa-solid fa-envelope-open-text"></i></span> Layanan Surat Tata Usaha
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            <?= $is_staff ? 'Kelola permohonan surat siswa, terbitkan surat resmi, dan cetak dokumen berkop resmi sekolah.' : 'Ajukan surat keterangan aktif, SKBB, permohonan dispensasi, dan izin sakit resmi sekolah.' ?>
        </p>
    </div>

    <div class="flex items-center gap-2 flex-wrap">
        <?php if ($is_staff): ?>
            <button onclick="document.getElementById('modalStaffLetter').classList.remove('hidden')" 
                    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition cursor-pointer">
                <i class="fa-solid fa-file-circle-plus"></i> Terbitkan Surat Resmi
            </button>
            <a href="../presensi/attendance_report.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-slate-300 transition flex items-center gap-2">
                <i class="fa-solid fa-file-invoice"></i> Laporan Presensi TU
            </a>
        <?php else: ?>
            <button onclick="document.getElementById('modalCreateRequest').classList.remove('hidden')" 
                    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition cursor-pointer">
                <i class="fa-solid fa-plus"></i> Ajukan Permohonan Surat
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== ''): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <span><i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-amber-400' ?>"></i></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<!-- Kartu Ringkasan Status Surat -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-slate-400">Total Berkas</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400"><i class="fa-solid fa-folder-open text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stats_total) ?></p>
        <span class="text-[11px] text-slate-500">Semua permohonan & penerbitan</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-amber-400">Menunggu Review</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400"><i class="fa-solid fa-clock text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stats_pending) ?></p>
        <span class="text-[11px] text-slate-500">Menanti verifikasi Staf TU</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-cyan-400">Sedang Diproses</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-cyan-500/20 text-cyan-400"><i class="fa-solid fa-spinner text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stats_process) ?></p>
        <span class="text-[11px] text-slate-500">Dalam penyusunan berkas</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-emerald-400">Selesai / Terbit</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400"><i class="fa-solid fa-circle-check text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stats_done) ?></p>
        <span class="text-[11px] text-slate-500">Siap dicetak / disimpan PDF</span>
    </div>
</div>

<!-- Filter dan Pencarian -->
<div class="mb-6 rounded-2xl border border-white/10 bg-slate-900/60 p-4">
    <form method="GET" class="flex flex-col md:flex-row gap-3">
        <div class="flex-1">
            <input 
                type="text" 
                name="search" 
                value="<?= htmlspecialchars($search) ?>" 
                placeholder="Cari nama siswa, jenis surat, atau keterangan..." 
                class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white placeholder-slate-500 outline-none focus:border-blue-500"
            >
        </div>

        <div class="md:w-44">
            <select name="status" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-blue-500">
                <option value="">Semua Status</option>
                <option value="menunggu" <?= $filter_status === 'menunggu' ? 'selected' : '' ?>>Menunggu</option>
                <option value="diproses" <?= $filter_status === 'diproses' ? 'selected' : '' ?>>Diproses</option>
                <option value="selesai" <?= $filter_status === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                <option value="ditolak" <?= $filter_status === 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
            </select>
        </div>

        <div class="md:w-56">
            <select name="type" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-blue-500 truncate">
                <option value="">Semua Jenis Surat</option>
                <?php foreach ($official_letter_types as $t => $desc): ?>
                    <option value="<?= $t ?>" <?= $filter_type === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="rounded-xl bg-slate-800 hover:bg-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 transition flex items-center gap-2 cursor-pointer">
                <i class="fa-solid fa-magnifying-glass text-xs"></i> Filter
            </button>
            <?php if ($search !== '' || $filter_status !== '' || $filter_type !== ''): ?>
                <a href="requests.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-sm font-semibold text-slate-400 transition">
                    Reset
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Tabel Permohonan & Penerbitan Surat -->
<div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-2xl backdrop-blur">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
            <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-6 py-4 font-semibold">Jenis Surat</th>
                    <th class="px-6 py-4 font-semibold">Siswa / Pemohon</th>
                    <th class="px-4 py-4 font-semibold">Tgl Keperluan</th>
                    <th class="px-6 py-4 font-semibold text-center">Status</th>
                    <th class="px-6 py-4 font-semibold">Waktu Buat</th>
                    <th class="px-6 py-4 font-semibold text-right">Aksi & Dokumen</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php if (empty($requests_list)): ?>
                    <tr>
                        <td colspan="6" class="py-12 text-center text-slate-400 text-sm">
                            Tidak ada arsip atau permohonan surat yang sesuai kriteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests_list as $r): ?>
                        <tr class="hover:bg-white/[0.02] transition">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-white flex items-center gap-2">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-500/10 text-blue-400 text-xs shrink-0">
                                        <i class="fa-solid fa-file-lines"></i>
                                    </span>
                                    <span><?= htmlspecialchars($r['request_type']) ?></span>
                                </div>
                                <?php if (!empty($r['notes'])): ?>
                                    <div class="text-xs text-slate-400 mt-1 italic pl-9">
                                        "<?= htmlspecialchars($r['notes']) ?>"
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($r['attachment_url'])): ?>
                                    <div class="pl-9 mt-1">
                                        <a href="<?= htmlspecialchars($r['attachment_url']) ?>" target="_blank" class="inline-flex items-center gap-1 text-[11px] text-blue-400 hover:underline">
                                            <i class="fa-solid fa-paperclip"></i> Lampiran Berkas <i class="fa-solid fa-arrow-up-right-from-square text-[9px] ml-0.5"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-100"><?= htmlspecialchars($r['applicant_name']) ?></div>
                                <div class="text-xs text-slate-400 flex items-center gap-2 mt-0.5">
                                    <?php if (!empty($r['class_name'])): ?>
                                        <span class="text-cyan-300 font-medium"><?= htmlspecialchars($r['class_name']) ?></span>
                                    <?php endif; ?>
                                    <span class="inline-flex items-center rounded border px-1.5 py-0.2 text-[10px] <?= getRoleBadge($r['applicant_role']) ?>">
                                        <?= htmlspecialchars(getRoleLabel($r['applicant_role'])) ?>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-4 text-xs font-mono text-slate-300">
                                <?= $r['target_date'] ? date('d M Y', strtotime($r['target_date'])) : '-' ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="inline-flex items-center rounded-lg border px-2.5 py-1 text-xs font-semibold uppercase tracking-wider <?= getStatusBadge($r['status']) ?>">
                                    <?= htmlspecialchars($r['status']) ?>
                                </span>
                                <?php if (!empty($r['processor_name'])): ?>
                                    <div class="text-[10px] text-slate-500 mt-1 font-mono">Petugas: <?= htmlspecialchars($r['processor_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-400 font-mono">
                                <?= date('d M Y, H:i', strtotime($r['created_at'])) ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                    <?php if ($r['status'] === 'selesai'): ?>
                                        <a href="request_print.php?id=<?= $r['id'] ?>" target="_blank" 
                                           class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3 py-1.5 text-xs font-bold text-emerald-300 transition inline-flex items-center gap-1.5 cursor-pointer shadow-sm">
                                            <i class="fa-solid fa-print"></i> Cetak Surat
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($is_staff): ?>
                                        <?php if ($r['status'] !== 'diproses' && $r['status'] !== 'selesai'): ?>
                                            <form method="POST" class="inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="update_request_status">
                                                <input type="hidden" name="update_id" value="<?= $r['id'] ?>">
                                                <input type="hidden" name="new_status" value="diproses">
                                                <button type="submit" class="rounded-xl border border-blue-500/30 bg-blue-500/10 px-2.5 py-1.5 text-xs font-medium text-blue-300 hover:bg-blue-500/20 transition cursor-pointer" title="Ubah status ke Diproses">
                                                    Proses
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($r['status'] !== 'selesai'): ?>
                                            <form method="POST" class="inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="update_request_status">
                                                <input type="hidden" name="update_id" value="<?= $r['id'] ?>">
                                                <input type="hidden" name="new_status" value="selesai">
                                                <button type="submit" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1.5 text-xs font-medium text-emerald-300 hover:bg-emerald-500/20 transition cursor-pointer" title="Setujui & Terbitkan">
                                                    Setujui
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($r['status'] !== 'ditolak' && $r['status'] !== 'selesai'): ?>
                                            <form method="POST" class="inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="update_request_status">
                                                <input type="hidden" name="update_id" value="<?= $r['id'] ?>">
                                                <input type="hidden" name="new_status" value="ditolak">
                                                <button type="submit" class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-2.5 py-1.5 text-xs font-medium text-rose-300 hover:bg-rose-500/20 transition cursor-pointer" title="Tolak Permohonan">
                                                    Tolak
                                                </button>
                                            </form>
                                        <?php endif; ?>
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

<!-- ========================================================= -->
<!-- MODAL: TERBITKAN SURAT RESMI LANGSUNG (KHUSUS STAF / TU) -->
<!-- ========================================================= -->
<?php if ($is_staff): ?>
<div id="modalStaffLetter" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-blue-400"><i class="fa-solid fa-file-circle-plus"></i></span> Terbitkan Surat Resmi Sekolah
            </h3>
            <button onclick="document.getElementById('modalStaffLetter').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="staff_create_letter">

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Pilih Penerima Surat (Siswa / Guru / Staf) *</label>
                <select name="recipient_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    <option value="">-- Pilih Penerima Surat Resmi --</option>
                    <optgroup label="Peserta Didik (Siswa Aktif)">
                        <?php foreach ($students_list as $st): ?>
                            <option value="<?= $st['id'] ?>">
                                <?= htmlspecialchars($st['name']) ?> (Kelas: <?= htmlspecialchars($st['class_name'] ?? 'Belum ada kelas') ?> | NISN: <?= htmlspecialchars($st['nisn'] ?: '-') ?>)
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Pendidik & Tenaga Kependidikan (Guru / Staf)">
                        <?php foreach ($staff_teachers_list as $tc): ?>
                            <option value="<?= $tc['id'] ?>">
                                [<?= strtoupper($tc['role']) ?>] <?= htmlspecialchars($tc['name']) ?> (<?= htmlspecialchars($tc['email'] ?? '-') ?>)
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Jenis Surat Resmi yang Diterbitkan *</label>
                <select name="request_type" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    <?php foreach ($official_letter_types as $type_name => $type_desc): ?>
                        <option value="<?= $type_name ?>"><?= $type_name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Tanggal Surat / Tanggal Keperluan *</label>
                <input type="date" name="target_date" required value="<?= date('Y-m-d') ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Keterangan / Keperluan / Catatan Tambahan</label>
                <textarea name="notes" rows="3" placeholder="Contoh: Pengurusan Beasiswa KIP-K / Panggilan Orang Tua terkait kehadiran / Mutasi ke SMAN 1 Jakarta..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
                <p class="text-[11px] text-slate-500 mt-1">Isian ini akan otomatis dicetak ke dalam paragraf isi surat dinas resmi.</p>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalStaffLetter').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-blue-500/25 cursor-pointer flex items-center gap-2">
                    <i class="fa-solid fa-stamp"></i> Terbitkan & Siapkan Cetak
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ========================================================= -->
<!-- MODAL: AJUKAN SURAT (SISWA & ORANG TUA) -->
<!-- ========================================================= -->
<?php if (!$is_staff): ?>
<div id="modalCreateRequest" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-blue-400"><i class="fa-solid fa-envelope-open-text"></i></span> Permohonan Surat Tata Usaha
            </h3>
            <button onclick="document.getElementById('modalCreateRequest').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="submit_request">

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Jenis Surat yang Dibutuhkan *</label>
                <select name="request_type" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    <?php foreach ($official_letter_types as $type_name => $type_desc): ?>
                        <option value="<?= $type_name ?>"><?= $type_name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Tanggal Keperluan / Izin *</label>
                <input type="date" name="target_date" required value="<?= date('Y-m-d') ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Lampiran Bukti / Surat Dokter (Opsional)</label>
                <input type="file" name="attachment_file" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500">
                <p class="text-[11px] text-slate-500 mt-1">Format: JPG, PNG, atau PDF. Maksimal 10 MB.</p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Catatan / Keterangan Keperluan</label>
                <textarea name="notes" rows="3" placeholder="Tuliskan tujuan surat atau alasan izin secara lengkap..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalCreateRequest').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-blue-500/25 cursor-pointer">
                    Kirim Permohonan
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
