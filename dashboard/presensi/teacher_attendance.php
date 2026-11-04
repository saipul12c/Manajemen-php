<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'guru';
$can_view_all = in_array($user_role, ['administrator', 'staf'], true);
$can_attend = in_array($user_role, ['guru', 'administrator', 'staf'], true);

if (!$can_attend) {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

$page_title = "Presensi Mandiri Guru";
$message = "";
$message_type = "";
$today = date('Y-m-d');
$now_time = date('H:i:s');

// Ambil konfigurasi titik koordinat sekolah (Geofencing)
$school_settings = getSchoolSettings($pdo);
$school_lat = (float)($school_settings['school_latitude'] ?? -6.208800);
$school_lng = (float)($school_settings['school_longitude'] ?? 106.845600);
$school_radius = (int)($school_settings['school_radius_meters'] ?? 150);

// -------------------------------------------------------------
// 1. CLOCK-IN MANDIRI HARI INI DENGAN GEOFENCING GPS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clock_in') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $notes = trim($_POST['notes'] ?? '');
        $status = ($now_time > '07:15:00') ? 'terlambat' : 'hadir';

        $client_lat = (!empty($_POST['latitude']) && is_numeric($_POST['latitude'])) ? (float)$_POST['latitude'] : null;
        $client_lng = (!empty($_POST['longitude']) && is_numeric($_POST['longitude'])) ? (float)$_POST['longitude'] : null;
        $distance_meters = null;

        if ($client_lat !== null && $client_lng !== null) {
            $distance_meters = calculateDistanceMeters($school_lat, $school_lng, $client_lat, $client_lng);
        }

        // Peringatan Geofencing jika di luar radius (tetap izinkan dengan catatan log untuk fleksibilitas)
        $is_out_of_radius = ($distance_meters !== null && $distance_meters > $school_radius);
        if ($is_out_of_radius) {
            $notes = ($notes ? "$notes | " : "") . "[Luar Radius: {$distance_meters}m dari gerbang]";
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO teacher_attendance (teacher_id, date, check_in_time, status, notes, latitude, longitude, distance_meters)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    check_in_time = COALESCE(check_in_time, VALUES(check_in_time)),
                    notes = COALESCE(VALUES(notes), notes),
                    latitude = VALUES(latitude),
                    longitude = VALUES(longitude),
                    distance_meters = VALUES(distance_meters)
            ");
            $stmt->execute([$user_id, $today, $now_time, $status, $notes ?: null, $client_lat, $client_lng, $distance_meters]);

            logActivity($pdo, 'TEACHER_CLOCK_IN', "Presensi masuk guru ID $user_id status: $status jam $now_time (Jarak: " . ($distance_meters ?? 'N/A') . "m)");
            $message = "Presensi masuk berhasil dicatat ($now_time)! Status: " . ucfirst($status) . ($distance_meters !== null ? " (Jarak GPS: {$distance_meters} meter)" : "");
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Gagal mencatat presensi masuk: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 2. CLOCK-OUT MANDIRI
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clock_out') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE teacher_attendance 
                SET check_out_time = ? 
                WHERE teacher_id = ? AND date = ?
            ");
            $stmt->execute([$now_time, $user_id, $today]);

            logActivity($pdo, 'TEACHER_CLOCK_OUT', "Presensi pulang guru ID $user_id jam $now_time");
            $message = "Presensi kepulangan berhasil dicatat ($now_time)! Selamat beristirahat.";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Gagal mencatat kepulangan: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 3. PENGAJUAN IZIN / SAKIT / CUTI GURU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_leave') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $leave_date = $_POST['leave_date'] ?? $today;
        $leave_status = $_POST['status'] ?? 'izin';
        $leave_notes = trim($_POST['notes'] ?? '');
        $proof_doc = null;

        // Support file upload surat keterangan
        if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            $file_name = $_FILES['proof_file']['name'];
            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true)) {
                $upload_dir = __DIR__ . "/../../uploads/leave_docs/";
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0755, true);
                }
                $new_filename = "leave_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
                if (move_uploaded_file($_FILES['proof_file']['tmp_name'], $upload_dir . $new_filename)) {
                    $proof_doc = "uploads/leave_docs/" . $new_filename;
                }
            }
        }

        if (empty($leave_notes)) {
            $message = "Alasan izin/sakit/cuti wajib diisi.";
            $message_type = "error";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO teacher_attendance (teacher_id, date, status, notes, proof_doc)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    notes = VALUES(notes),
                    proof_doc = COALESCE(VALUES(proof_doc), proof_doc)
            ");
            $stmt->execute([$user_id, $leave_date, $leave_status, $leave_notes, $proof_doc]);

            logActivity($pdo, 'TEACHER_LEAVE_REQUEST', "Pengajuan $leave_status guru ID $user_id tgl $leave_date");
            $message = "Pengajuan " . strtoupper($leave_status) . " berhasil disimpan dan dilaporkan.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 4. DATA PRESENSI HARI INI
// -------------------------------------------------------------
$stmt_today = $pdo->prepare("SELECT * FROM teacher_attendance WHERE teacher_id = ? AND date = ?");
$stmt_today->execute([$user_id, $today]);
$my_today = $stmt_today->fetch();

// -------------------------------------------------------------
// 5. RIWAYAT & REKAP BULANAN
// -------------------------------------------------------------
$filter_month = $_GET['month'] ?? date('Y-m');
$filter_teacher = isset($_GET['teacher_id']) && is_numeric($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : ($can_view_all ? 0 : $user_id);
$teachers = $pdo->query("SELECT id, name FROM users WHERE role = 'guru' ORDER BY name ASC")->fetchAll();

$query_sql = "
    SELECT ta.*, u.name as teacher_name, u.email as teacher_email
    FROM teacher_attendance ta
    JOIN users u ON ta.teacher_id = u.id
    WHERE 1=1
";
$params = [];

if (!$can_view_all) {
    $query_sql .= " AND ta.teacher_id = ?";
    $params[] = $user_id;
} elseif ($filter_teacher > 0) {
    $query_sql .= " AND ta.teacher_id = ?";
    $params[] = $filter_teacher;
}

if (!empty($filter_month)) {
    $query_sql .= " AND DATE_FORMAT(ta.date, '%Y-%m') = ?";
    $params[] = $filter_month;
}

$query_sql .= " ORDER BY ta.date DESC, ta.check_in_time DESC";
$stmt_list = $pdo->prepare($query_sql);
$stmt_list->execute($params);
$attendance_history = $stmt_list->fetchAll();

// Hitung statistik bulanan
$stat_hadir = 0;
$stat_terlambat = 0;
$stat_izin = 0;
$stat_sakit = 0;
$stat_cuti = 0;

foreach ($attendance_history as $rec) {
    if ($rec['status'] === 'hadir') $stat_hadir++;
    elseif ($rec['status'] === 'terlambat') $stat_terlambat++;
    elseif ($rec['status'] === 'izin') $stat_izin++;
    elseif ($rec['status'] === 'sakit') $stat_sakit++;
    elseif ($rec['status'] === 'cuti') $stat_cuti++;
}

require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Header Page -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-slate-900/60 p-6 rounded-3xl border border-white/10 backdrop-blur-xl relative overflow-hidden">
        <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-gradient-to-br from-emerald-600/20 to-teal-600/20 rounded-full blur-2xl pointer-events-none"></div>
        <div class="relative z-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold uppercase tracking-wider mb-2">
                <i class="fa-solid fa-business-time"></i> Presensi Kehadiran Guru
            </div>
            <h1 class="text-2xl font-black text-white tracking-tight">Presensi Mandiri Guru</h1>
            <p class="text-slate-400 text-sm mt-1">Pencatatan jam kedatangan, jam kepulangan, serta pengajuan izin atau sakit kedinasan guru.</p>
        </div>
        <div class="flex items-center gap-2.5 relative z-10">
            <button onclick="window.print()" class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-white/10 text-xs font-semibold flex items-center gap-2 transition shadow-sm">
                <i class="fa-solid fa-print"></i> Cetak Rekap
            </button>
            <button onclick="openModal('modalLeaveRequest')" class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-amber-300 border border-amber-500/20 text-xs font-semibold flex items-center gap-2 transition shadow-sm">
                <i class="fa-solid fa-envelope-open-text"></i> Ajukan Izin / Sakit
            </button>
        </div>
    </div>

    <!-- Feedback Message Alert -->
    <?php if (!empty($message)): ?>
        <div class="p-4 rounded-2xl flex items-center gap-3 border <?= $message_type === 'success' ? 'bg-emerald-950/40 border-emerald-500/40 text-emerald-300' : 'bg-rose-950/40 border-rose-500/40 text-rose-300' ?>">
            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-rose-400' ?> text-lg shrink-0"></i>
            <span class="text-sm font-medium"><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <!-- CLOCK-IN / CLOCK-OUT WIDGET HARI INI (Khusus Guru / Pengguna Aktif) -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Live Clock & Status Card -->
        <div class="lg:col-span-1 p-6 rounded-3xl bg-gradient-to-br from-slate-900 to-slate-950 border border-white/10 shadow-xl flex flex-col justify-between">
            <div>
                <p class="text-xs uppercase tracking-wider font-bold text-slate-400 mb-1">Waktu Server Saat Ini</p>
                <div id="liveClock" class="text-3xl font-black text-white font-mono tracking-tight">
                    <?= date('H:i:s') ?> <span class="text-xs text-emerald-400 font-sans font-medium">WIB</span>
                </div>
                <p class="text-xs text-slate-400 mt-1 flex items-center gap-1.5">
                    <i class="fa-regular fa-calendar-check text-emerald-400"></i>
                    <?= $tgl_ini ?? date('d M Y') ?>
                </p>

                <div class="mt-5 pt-4 border-t border-white/5 space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-400">Batas Masuk Tepat Waktu:</span>
                        <span class="font-bold text-emerald-400">07:15 WIB</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-slate-400">Status Kehadiran Hari Ini:</span>
                        <?php if ($my_today): ?>
                            <span class="font-bold uppercase <?= $my_today['status'] === 'hadir' ? 'text-emerald-400' : ($my_today['status'] === 'terlambat' ? 'text-amber-400' : 'text-purple-400') ?>">
                                <?= htmlspecialchars($my_today['status']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-rose-400 font-semibold italic">Belum Presensi</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Buttons Action -->
            <div class="mt-6 pt-4 border-t border-white/10 space-y-2.5">
                <?php if (!$my_today): ?>
                    <!-- Live Geofencing Radar Widget -->
                    <div class="p-3.5 rounded-2xl border border-white/10 bg-slate-950/80 text-xs space-y-2 mb-3">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-400 font-semibold flex items-center gap-1.5 text-[11px]">
                                <i class="fa-solid fa-satellite-dish text-cyan-400"></i> Geofencing GPS
                            </span>
                            <span id="gpsPill" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold bg-slate-800 text-slate-400 border border-white/10">
                                <i class="fa-solid fa-spinner fa-spin"></i> Mendeteksi GPS...
                            </span>
                        </div>
                        <div id="gpsDetailText" class="text-[11px] text-slate-400 font-mono">
                            Mencari posisi perangkat Anda...
                        </div>
                    </div>

                    <!-- Form Clock In -->
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="action" value="clock_in">
                        <input type="hidden" name="latitude" id="inputLat">
                        <input type="hidden" name="longitude" id="inputLng">
                        <input type="hidden" name="distance_meters" id="inputDist">
                        <button type="submit" id="btnClockIn" class="w-full py-3 rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold text-sm flex items-center justify-center gap-2 shadow-lg shadow-emerald-500/25 transition cursor-pointer">
                            <i class="fa-solid fa-fingerprint text-lg"></i>
                            Presensi Masuk (Clock-In)
                        </button>
                    </form>
                <?php else: ?>
                    <div class="p-3 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 text-xs flex items-center gap-3">
                        <i class="fa-solid fa-circle-check text-base"></i>
                        <div>
                            <p class="font-bold">Masuk: <?= substr($my_today['check_in_time'] ?? '00:00', 0, 5) ?> WIB</p>
                            <p class="text-[10px] text-slate-400"><?= htmlspecialchars($my_today['notes'] ?: 'Kehadiran KBM harian') ?></p>
                        </div>
                    </div>

                    <?php if (empty($my_today['check_out_time'])): ?>
                        <!-- Form Clock Out -->
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                            <input type="hidden" name="action" value="clock_out">
                            <button type="submit" class="w-full py-3 rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-bold text-sm flex items-center justify-center gap-2 shadow-lg shadow-indigo-500/25 transition">
                                <i class="fa-solid fa-right-from-bracket text-base"></i>
                                Presensi Pulang (Clock-Out)
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="p-3 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-300 text-xs flex items-center gap-3">
                            <i class="fa-solid fa-house-chimney text-base"></i>
                            <div>
                                <p class="font-bold">Pulang: <?= substr($my_today['check_out_time'], 0, 5) ?> WIB</p>
                                <p class="text-[10px] text-slate-400">Presensi hari ini tuntas!</p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Summary Cards Bulanan -->
        <div class="lg:col-span-2 grid grid-cols-2 sm:grid-cols-3 gap-4 content-start">
            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
                <div class="h-11 w-11 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0 text-base">
                    <i class="fa-solid fa-user-check"></i>
                </div>
                <div>
                    <p class="text-xs text-slate-400 font-medium">Hadir Tepat Waktu</p>
                    <p class="text-xl font-bold text-emerald-400"><?= $stat_hadir ?> hari</p>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
                <div class="h-11 w-11 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center shrink-0 text-base">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <p class="text-xs text-slate-400 font-medium">Terlambat</p>
                    <p class="text-xl font-bold text-amber-400"><?= $stat_terlambat ?> hari</p>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
                <div class="h-11 w-11 rounded-xl bg-blue-500/10 border border-blue-500/20 text-blue-400 flex items-center justify-center shrink-0 text-base">
                    <i class="fa-solid fa-file-lines"></i>
                </div>
                <div>
                    <p class="text-xs text-slate-400 font-medium">Izin Kedinasan</p>
                    <p class="text-xl font-bold text-blue-400"><?= $stat_izin ?> hari</p>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
                <div class="h-11 w-11 rounded-xl bg-purple-500/10 border border-purple-500/20 text-purple-400 flex items-center justify-center shrink-0 text-base">
                    <i class="fa-solid fa-notes-medical"></i>
                </div>
                <div>
                    <p class="text-xs text-slate-400 font-medium">Sakit</p>
                    <p class="text-xl font-bold text-purple-400"><?= $stat_sakit ?> hari</p>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
                <div class="h-11 w-11 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 flex items-center justify-center shrink-0 text-base">
                    <i class="fa-solid fa-umbrella-beach"></i>
                </div>
                <div>
                    <p class="text-xs text-slate-400 font-medium">Cuti Tahunan</p>
                    <p class="text-xl font-bold text-cyan-400"><?= $stat_cuti ?> hari</p>
                </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
                <div class="h-11 w-11 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center shrink-0 text-base">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
                <div>
                    <p class="text-xs text-slate-400 font-medium">Total Terdata</p>
                    <p class="text-xl font-bold text-white"><?= count($attendance_history) ?> rekaman</p>
                </div>
            </div>

            <div class="col-span-full p-4 rounded-2xl bg-slate-900/40 border border-white/5 no-print mt-1">
                <form method="GET" class="flex flex-wrap items-center gap-3">
                    <div class="flex-1 min-w-[180px]">
                        <input type="month" name="month" value="<?= htmlspecialchars($filter_month) ?>"
                               class="w-full bg-slate-950/60 border border-white/10 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-emerald-500">
                    </div>
                    <?php if ($can_view_all): ?>
                    <div class="flex-1 min-w-[200px]">
                        <select name="teacher_id" class="w-full bg-slate-950/60 border border-white/10 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-emerald-500">
                            <option value="0">-- Semua Dewan Guru --</option>
                            <?php foreach ($teachers as $tc): ?>
                                <option value="<?= $tc['id'] ?>" <?= $filter_teacher === (int)$tc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold transition">
                        <i class="fa-solid fa-filter mr-1"></i> Filter Rekap
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Riwayat Rekap Presensi Tabel -->
    <div class="bg-slate-900/60 border border-white/10 rounded-3xl overflow-hidden backdrop-blur-xl shadow-xl">
        <div class="p-5 border-b border-white/10 flex items-center justify-between">
            <h2 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-list-check text-emerald-400"></i>
                Logbook Presensi Dewan Guru
            </h2>
        </div>

        <?php if (empty($attendance_history)): ?>
            <div class="py-16 text-center text-slate-400">
                <div class="h-16 w-16 mx-auto mb-3 rounded-2xl bg-white/5 flex items-center justify-center text-2xl text-slate-500">
                    <i class="fa-solid fa-calendar-xmark"></i>
                </div>
                <p class="text-sm font-medium text-slate-300">Belum ada catatan presensi pada periode bulan ini.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-950/70 uppercase text-[10px] text-slate-400 tracking-wider font-semibold border-b border-white/10">
                        <tr>
                            <th class="py-3.5 px-4">Tanggal</th>
                            <?php if ($can_view_all): ?>
                                <th class="py-3.5 px-4">Nama Guru</th>
                            <?php endif; ?>
                            <th class="py-3.5 px-4">Jam Masuk</th>
                            <th class="py-3.5 px-4">Jam Pulang</th>
                            <th class="py-3.5 px-4">Status</th>
                            <th class="py-3.5 px-4">Radius GPS</th>
                            <th class="py-3.5 px-4">Keterangan / Berkas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($attendance_history as $row): 
                            $badge_color = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
                            if ($row['status'] === 'terlambat') $badge_color = 'bg-amber-500/10 text-amber-400 border-amber-500/20';
                            elseif ($row['status'] === 'izin') $badge_color = 'bg-blue-500/10 text-blue-400 border-blue-500/20';
                            elseif ($row['status'] === 'sakit') $badge_color = 'bg-purple-500/10 text-purple-400 border-purple-500/20';
                            elseif ($row['status'] === 'cuti') $badge_color = 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20';
                        ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="py-3.5 px-4 whitespace-nowrap font-medium text-white">
                                    <?= date('d M Y', strtotime($row['date'])) ?>
                                </td>
                                <?php if ($can_view_all): ?>
                                    <td class="py-3.5 px-4">
                                        <p class="font-bold text-slate-200"><?= htmlspecialchars($row['teacher_name']) ?></p>
                                    </td>
                                <?php endif; ?>
                                <td class="py-3.5 px-4 font-mono">
                                    <?= !empty($row['check_in_time']) ? substr($row['check_in_time'], 0, 5) . ' WIB' : '-' ?>
                                </td>
                                <td class="py-3.5 px-4 font-mono">
                                    <?= !empty($row['check_out_time']) ? substr($row['check_out_time'], 0, 5) . ' WIB' : '-' ?>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-semibold border <?= $badge_color ?>">
                                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                        <?= ucfirst(htmlspecialchars($row['status'])) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <?php if ($row['distance_meters'] !== null): ?>
                                        <div class="flex items-center gap-1.5">
                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold <?= $row['distance_meters'] <= $school_radius ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20' ?>">
                                                <i class="fa-solid fa-location-dot"></i> <?= $row['distance_meters'] ?> m
                                            </span>
                                            <?php if (!empty($row['latitude']) && !empty($row['longitude'])): ?>
                                                <a href="https://www.google.com/maps?q=<?= $row['latitude'] ?>,<?= $row['longitude'] ?>" target="_blank" class="text-cyan-400 hover:text-cyan-300 text-xs" title="Buka Koordinat Google Maps">
                                                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-500 text-[11px]">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4">
                                    <p class="text-slate-300"><?= htmlspecialchars($row['notes'] ?: '-') ?></p>
                                    <?php if (!empty($row['proof_doc'])): ?>
                                        <a href="../../<?= htmlspecialchars($row['proof_doc']) ?>" target="_blank"
                                           class="inline-flex items-center gap-1 text-[10px] text-indigo-400 hover:text-indigo-300 mt-0.5">
                                            <i class="fa-solid fa-paperclip"></i> Lihat Berkas Surat
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: PENGAJUAN IZIN / SAKIT / CUTI GURU -->
<!-- ============================================================== -->
<div id="modalLeaveRequest" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-lg w-full p-6 text-slate-100 shadow-2xl relative my-8">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-4">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-envelope-open-text text-amber-400"></i>
                Pengajuan Izin / Sakit / Cuti Guru
            </h3>
            <button onclick="closeModal('modalLeaveRequest')" class="text-slate-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="request_leave">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Tanggal Tidak Hadir <span class="text-rose-400">*</span></label>
                    <input type="date" name="leave_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Jenis Ketidakhadiran <span class="text-rose-400">*</span></label>
                    <select name="status" required class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-amber-500">
                        <option value="izin">Izin Dinas / Keperluan Mendesak</option>
                        <option value="sakit">Sakit</option>
                        <option value="cuti">Cuti Tahunan</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Alasan / Keterangan Lengkap <span class="text-rose-400">*</span></label>
                <textarea name="notes" rows="3" required placeholder="Jelaskan alasan izin atau keperluan tidak dapat hadir KBM..."
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-white focus:outline-none focus:border-amber-500"></textarea>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Unggah Surat Keterangan / Bukti (PDF, JPG, PNG)</label>
                <input type="file" name="proof_file" accept=".pdf,.jpg,.jpeg,.png"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white file:mr-3 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-amber-600 file:text-white hover:file:bg-amber-500 cursor-pointer">
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-white/10">
                <button type="button" onclick="closeModal('modalLeaveRequest')" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition">
                    Batal
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white font-bold transition shadow-lg shadow-amber-500/25">
                    Kirim Permohonan Izin
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}

// Update jam setiap detik
setInterval(() => {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    const s = String(now.getSeconds()).padStart(2, '0');
    const clockEl = document.getElementById('liveClock');
    if (clockEl) {
        clockEl.innerHTML = `${h}:${m}:${s} <span class="text-xs text-emerald-400 font-sans font-medium">WIB</span>`;
    }
}, 1000);

// GEOFENCING GPS LIVE RADAR (FASE 3)
const schoolLat = <?= json_encode($school_lat) ?>;
const schoolLng = <?= json_encode($school_lng) ?>;
const schoolRadius = <?= json_encode($school_radius) ?>;

function calcDistMeters(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return Math.round(R * c);
}

if (navigator.geolocation) {
    navigator.geolocation.watchPosition(
        (pos) => {
            const uLat = pos.coords.latitude;
            const uLng = pos.coords.longitude;
            const dist = calcDistMeters(schoolLat, schoolLng, uLat, uLng);

            const latInput = document.getElementById('inputLat');
            const lngInput = document.getElementById('inputLng');
            const distInput = document.getElementById('inputDist');
            if (latInput) latInput.value = uLat.toFixed(6);
            if (lngInput) lngInput.value = uLng.toFixed(6);
            if (distInput) distInput.value = dist;

            const pill = document.getElementById('gpsPill');
            const detail = document.getElementById('gpsDetailText');

            if (pill && detail) {
                if (dist <= schoolRadius) {
                    pill.className = "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30";
                    pill.innerHTML = `<i class="fa-solid fa-circle-check"></i> Dalam Radius (${dist}m)`;
                    detail.innerHTML = `Posisi terdeteksi aman: <strong class="text-white">${dist} meter</strong> dari gerbang sekolah (Batas: ${schoolRadius}m).`;
                } else {
                    pill.className = "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30";
                    pill.innerHTML = `<i class="fa-solid fa-circle-xmark"></i> Luar Radius (${dist}m)`;
                    detail.innerHTML = `Anda berada <strong class="text-rose-400">${dist} meter</strong> dari sekolah (Maksimal: ${schoolRadius}m). Dekati gerbang sekolah.`;
                }
            }
        },
        (err) => {
            const pill = document.getElementById('gpsPill');
            const detail = document.getElementById('gpsDetailText');
            if (pill) {
                pill.className = "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30";
                pill.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> GPS Nonaktif`;
            }
            if (detail) {
                detail.innerText = "Izin lokasi browser belum diaktifkan. Anda tetap dapat mencatat presensi.";
            }
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
