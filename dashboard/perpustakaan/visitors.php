<?php
/**
 * Buku Tamu & Presensi Pengunjung Perpustakaan Digital
 * Mencatat kunjungan siswa, dewan guru, staf, dan tamu umum baik secara mandiri (Kiosk Scan) maupun manual.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$page_title = "Buku Tamu Perpustakaan";
$is_librarian = in_array($user_role, ['administrator', 'staf'], true);

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. AJAX SCANNER KIOSK: PRESENSI KUNJUNGAN INSTAN
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scan_visitor') {
    header('Content-Type: application/json');

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Token keamanan CSRF tidak valid.']);
        exit;
    }

    $raw_code = trim($_POST['code'] ?? '');
    $purpose  = trim($_POST['purpose'] ?? 'Membaca Buku / Majalah');

    if (empty($raw_code)) {
        echo json_encode(['success' => false, 'message' => 'Kode identitas tidak boleh kosong.']);
        exit;
    }

    // Ekstrak kode (bersihkan ID-, STUDENT:, dll)
    $cleaned = $raw_code;
    if (strpos($raw_code, ':') !== false) {
        $parts = explode(':', $raw_code);
        $cleaned = end($parts);
    }
    $cleaned = str_replace(['ID-', 'USER-'], '', $cleaned);

    // Cari di tabel users
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.role, u.nisn, u.gender, c.name as class_name
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE (u.nisn = ? OR u.id = ? OR u.username = ?)
        LIMIT 1
    ");
    $stmt->execute([$cleaned, is_numeric($cleaned) ? (int)$cleaned : 0, $cleaned]);
    $user_data = $stmt->fetch();

    $today = date('Y-m-d');
    $time_now = date('H:i:s');

    if ($user_data) {
        $visitor_name  = $user_data['name'];
        $visitor_role  = in_array($user_data['role'], ['siswa', 'guru', 'staf'], true) ? $user_data['role'] : 'umum';
        $identifier    = $user_data['nisn'] ?: "ID-" . $user_data['id'];
        $class_name    = $user_data['class_name'] ?: ($user_data['role'] === 'guru' ? 'Dewan Guru' : 'Staf');
        $gender        = $user_data['gender'] ?? 'L';
        $v_uid         = (int)$user_data['id'];
    } else {
        // Jika tidak ditemukan di database, anggap tamu terdaftar dengan nama kode
        $visitor_name  = $raw_code;
        $visitor_role  = 'umum';
        $identifier    = '-';
        $class_name    = 'Umum';
        $gender        = 'L';
        $v_uid         = null;
    }

    // Cek apakah sudah pernah presensi dalam 15 menit terakhir agar tidak dobel scan tidak sengaja
    $stmt_recent = $pdo->prepare("
        SELECT id FROM library_visitors 
        WHERE (user_id = ? OR name = ?) AND visit_date = ? AND TIMESTAMPDIFF(MINUTE, CONCAT(visit_date, ' ', visit_time), NOW()) < 15
        LIMIT 1
    ");
    $stmt_recent->execute([$v_uid, $visitor_name, $today]);
    if ($stmt_recent->fetch()) {
        echo json_encode([
            'success' => true,
            'is_duplicate' => true,
            'message' => "Halo $visitor_name, kunjungan Anda sudah tercatat beberapa saat yang lalu!",
            'visitor' => [
                'name' => $visitor_name,
                'class_name' => $class_name,
                'role' => ucfirst($visitor_role),
                'time' => date('H:i')
            ]
        ]);
        exit;
    }

    // Insert ke tabel library_visitors
    $stmt_ins = $pdo->prepare("
        INSERT INTO library_visitors 
        (user_id, name, role, identifier, class_name, gender, purpose, visit_date, visit_time, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Presensi mandiri via Kiosk')
    ");
    $stmt_ins->execute([$v_uid, $visitor_name, $visitor_role, $identifier, $class_name, $gender, $purpose, $today, $time_now]);

    logActivity($pdo, 'VISIT_LIBRARY', "Presensi kunjungan perpustakaan: $visitor_name ($visitor_role)");

    echo json_encode([
        'success' => true,
        'is_duplicate' => false,
        'message' => "Selamat Datang di Perpustakaan, $visitor_name! Selamat menikmati literasi.",
        'visitor' => [
            'name' => $visitor_name,
            'class_name' => $class_name,
            'role' => ucfirst($visitor_role),
            'purpose' => $purpose,
            'time' => date('H:i')
        ]
    ]);
    exit;
}

// -------------------------------------------------------------
// 2. INPUT MANUAL BUKU TAMU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_visitor') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = "Token keamanan CSRF tidak valid.";
        $message_type = "error";
    } else {
        $name        = trim($_POST['name'] ?? '');
        $role        = trim($_POST['role'] ?? 'siswa');
        $identifier  = trim($_POST['identifier'] ?? '');
        $class_name  = trim($_POST['class_name'] ?? '');
        $gender      = trim($_POST['gender'] ?? 'L');
        $purpose     = trim($_POST['purpose'] ?? 'Membaca Buku / Majalah');
        $notes       = trim($_POST['notes'] ?? '');

        if (empty($name)) {
            $message = "Nama pengunjung wajib diisi.";
            $message_type = "error";
        } else {
            $today = date('Y-m-d');
            $time_now = date('H:i:s');

            $stmt_ins = $pdo->prepare("
                INSERT INTO library_visitors 
                (user_id, name, role, identifier, class_name, gender, purpose, visit_date, visit_time, notes)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_ins->execute([$name, $role, $identifier, $class_name, $gender, $purpose, $today, $time_now, $notes]);

            logActivity($pdo, 'MANUAL_VISITOR', "Pencatatan buku tamu manual: $name ($role)");
            $message = "Kunjungan '$name' berhasil dicatat ke buku tamu perpustakaan!";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 3. EXPORT DATA PENGUNJUNG KE CSV (Admin/Staf)
// -------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $is_librarian) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pengunjung_perpustakaan_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM untuk Excel UTF-8
    fputcsv($output, ['No', 'Tanggal', 'Pukul', 'Nama Pengunjung', 'Peran', 'NISN/Identitas', 'Kelas/Instansi', 'Jenis Kelamin', 'Keperluan', 'Catatan']);

    $stmt_exp = $pdo->query("SELECT * FROM library_visitors ORDER BY visit_date DESC, visit_time DESC");
    $no = 1;
    while ($row = $stmt_exp->fetch()) {
        fputcsv($output, [
            $no++,
            $row['visit_date'],
            $row['visit_time'],
            $row['name'],
            ucfirst($row['role']),
            $row['identifier'],
            $row['class_name'],
            $row['gender'] === 'L' ? 'Laki-laki' : 'Perempuan',
            $row['purpose'],
            $row['notes']
        ]);
    }
    fclose($output);
    exit;
}

// -------------------------------------------------------------
// 4. STATISTIK PENGUNJUNG
// -------------------------------------------------------------
$today_str = date('Y-m-d');
$month_str = date('Y-m');

$stat_today_total = (int)$pdo->query("SELECT COUNT(*) FROM library_visitors WHERE visit_date = '$today_str'")->fetchColumn();
$stat_today_siswa = (int)$pdo->query("SELECT COUNT(*) FROM library_visitors WHERE visit_date = '$today_str' AND role = 'siswa'")->fetchColumn();
$stat_today_guru  = (int)$pdo->query("SELECT COUNT(*) FROM library_visitors WHERE visit_date = '$today_str' AND role IN ('guru', 'staf')")->fetchColumn();
$stat_month_total = (int)$pdo->query("SELECT COUNT(*) FROM library_visitors WHERE visit_date LIKE '$month_str%'")->fetchColumn();

// Kelas paling aktif bulan ini
$top_classes = $pdo->query("
    SELECT class_name, COUNT(*) as total 
    FROM library_visitors 
    WHERE visit_date LIKE '$month_str%' AND class_name IS NOT NULL AND class_name != '' AND role = 'siswa'
    GROUP BY class_name 
    ORDER BY total DESC 
    LIMIT 3
")->fetchAll();

// Filter & Pencarian Daftar Pengunjung
$filter_date = trim($_GET['date'] ?? '');
$search_q    = trim($_GET['q'] ?? '');

$sql_where = [];
$sql_params = [];

if (!empty($filter_date)) {
    $sql_where[] = "visit_date = ?";
    $sql_params[] = $filter_date;
} else {
    // Default tampilkan 7 hari terakhir jika tidak memilih tanggal
    $sql_where[] = "visit_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)";
}

if (!empty($search_q)) {
    $sql_where[] = "(name LIKE ? OR identifier LIKE ? OR class_name LIKE ? OR purpose LIKE ?)";
    $q_like = "%$search_q%";
    $sql_params = array_merge($sql_params, [$q_like, $q_like, $q_like, $q_like]);
}

$where_clause = !empty($sql_where) ? "WHERE " . implode(" AND ", $sql_where) : "";

$stmt_list = $pdo->prepare("
    SELECT * FROM library_visitors
    $where_clause
    ORDER BY visit_date DESC, visit_time DESC
    LIMIT 100
");
$stmt_list->execute($sql_params);
$visitors = $stmt_list->fetchAll();

include __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Sub Navigasi Modul Perpustakaan -->
    <?php include __DIR__ . "/_nav.php"; ?>

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="inline-flex items-center gap-2 rounded-full border border-teal-500/20 bg-teal-500/10 px-3 py-1 text-xs font-semibold text-teal-400 mb-2">
                <i class="fa-solid fa-clipboard-user"></i> Visitor Counter & Presensi Literasi
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white">Buku Tamu Perpustakaan</h1>
            <p class="text-sm text-slate-400 mt-1">Pencatatan kehadiran pengunjung ruang baca, pelacakan minat literasi, dan statistik pengunjung harian.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <?php if ($is_librarian): ?>
                <a href="visitors.php?export=csv" 
                   class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs sm:text-sm font-semibold text-slate-200 transition flex items-center gap-2">
                    <i class="fa-solid fa-file-excel text-emerald-400"></i> Ekspor CSV
                </a>
            <?php endif; ?>
            <button onclick="openManualModal()" 
                    class="rounded-xl bg-teal-600 hover:bg-teal-500 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-lg shadow-teal-600/20 transition flex items-center gap-2 cursor-pointer">
                <i class="fa-solid fa-user-plus"></i> Isi Buku Tamu Manual
            </button>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($message)): ?>
        <div class="rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
            <div class="flex items-center gap-3">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-rose-400' ?> text-lg"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-xs font-bold opacity-70 hover:opacity-100 p-1">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- Statistik Ringkas -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3.5">
        <div class="rounded-2xl border border-white/10 bg-slate-900/50 p-4 backdrop-blur">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-400">Pengunjung Hari Ini</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-teal-500/20 text-teal-400">
                    <i class="fa-solid fa-users text-sm"></i>
                </span>
            </div>
            <div class="text-2xl font-black text-white mt-2"><?= number_format($stat_today_total) ?></div>
            <span class="text-[11px] text-teal-400 font-medium"><?= date('d F Y') ?></span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/50 p-4 backdrop-blur">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-400">Peserta Didik (Siswa)</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400">
                    <i class="fa-solid fa-graduation-cap text-sm"></i>
                </span>
            </div>
            <div class="text-2xl font-black text-white mt-2"><?= number_format($stat_today_siswa) ?></div>
            <span class="text-[11px] text-slate-500">Kunjungan siswa hari ini</span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/50 p-4 backdrop-blur">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-400">Guru & Tenaga Kependidikan</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-purple-500/20 text-purple-400">
                    <i class="fa-solid fa-chalkboard-user text-sm"></i>
                </span>
            </div>
            <div class="text-2xl font-black text-white mt-2"><?= number_format($stat_today_guru) ?></div>
            <span class="text-[11px] text-slate-500">Pendidik & staf sekolah</span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/50 p-4 backdrop-blur">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-400">Total Bulan Ini</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                    <i class="fa-solid fa-chart-line text-sm"></i>
                </span>
            </div>
            <div class="text-2xl font-black text-white mt-2"><?= number_format($stat_month_total) ?></div>
            <span class="text-[11px] text-amber-400 font-medium">Bulan <?= date('F Y') ?></span>
        </div>
    </div>

    <!-- AREA KIOSK SCANNER DIGITAL PERPUSTAKAAN -->
    <div class="rounded-3xl border border-teal-500/30 bg-gradient-to-r from-teal-950/40 via-slate-900/60 to-blue-950/40 p-6 backdrop-blur-xl shadow-2xl relative overflow-hidden">
        <div class="absolute -right-10 -bottom-10 opacity-10 pointer-events-none">
            <i class="fa-solid fa-qrcode text-[220px] text-teal-400"></i>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-center">
            
            <div class="lg:col-span-7 space-y-3">
                <div class="inline-flex items-center gap-2 rounded-lg bg-teal-500/20 border border-teal-500/40 px-2.5 py-1 text-[11px] font-bold text-teal-300">
                    <i class="fa-solid fa-bolt"></i> KIOSK PRESENSI MANDIRI PENGUNJUNG
                </div>
                <h3 class="text-xl sm:text-2xl font-extrabold text-white">Tempelkan Kartu QR Siswa Anda</h3>
                <p class="text-xs sm:text-sm text-slate-300 leading-relaxed max-w-xl">
                    Siswa dan guru cukup mengarahkan kartu QR perpustakaan ke scanner di meja pintu masuk. Kehadiran Anda langsung tercatat tanpa perlu tanda tangan di buku kertas.
                </p>

                <!-- Pilihan Keperluan Kunjungan Saat Scan -->
                <div class="pt-2">
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tujuan Kunjungan Anda:</label>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <?php 
                        $purposes = [
                            'Membaca Buku / Majalah',
                            'Mengerjakan Tugas / Belajar',
                            'Peminjaman / Pengembalian',
                            'Belajar Kelompok / Diskusi',
                            'Akses Komputer & Internet'
                        ];
                        foreach ($purposes as $idx => $p):
                        ?>
                            <label class="flex items-center gap-1.5 rounded-xl border border-white/10 bg-slate-950/80 px-3 py-1.5 cursor-pointer hover:border-teal-500/50 hover:bg-slate-900 transition text-slate-300">
                                <input type="radio" name="kiosk_purpose" value="<?= $p ?>" <?= $idx === 0 ? 'checked' : '' ?> class="accent-teal-500">
                                <span><?= $p ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Input Laser Gun Barcode Manual untuk Kiosk -->
                <div class="pt-3 flex items-center gap-3 max-w-md">
                    <div class="relative flex-1">
                        <input type="text" id="kioskCodeInput" placeholder="Scan kartu siswa atau ketik NISN..."
                               class="w-full rounded-xl border border-white/15 bg-slate-950 px-3.5 py-2 pl-9 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition font-mono">
                        <i class="fa-solid fa-barcode absolute left-3 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
                    </div>
                    <button type="button" onclick="submitKioskManual()" 
                            class="rounded-xl bg-teal-600 hover:bg-teal-500 px-4 py-2 text-xs font-bold text-white transition cursor-pointer">
                        Catat Masuk
                    </button>
                </div>
            </div>

            <!-- Preview Kamera Kiosk -->
            <div class="lg:col-span-5 flex flex-col items-center">
                <div class="w-full max-w-xs rounded-2xl border border-white/15 bg-slate-950 p-2 shadow-2xl overflow-hidden relative">
                    <div class="relative aspect-square rounded-xl overflow-hidden bg-slate-900 flex items-center justify-center">
                        <div id="kioskReader" class="w-full h-full"></div>
                        <div id="kioskCameraOverlay" class="absolute inset-0 flex flex-col items-center justify-center p-4 text-center text-slate-500 bg-slate-950">
                            <i class="fa-solid fa-camera text-4xl mb-2 text-teal-500/50"></i>
                            <p class="text-xs font-bold text-slate-300">Kamera Kiosk Standby</p>
                            <button type="button" onclick="startKioskCamera()" 
                                    class="mt-2.5 rounded-lg bg-teal-600/30 border border-teal-500/40 hover:bg-teal-600/50 px-3 py-1 text-[11px] font-bold text-teal-300 transition">
                                Nyalakan Kamera Kiosk
                            </button>
                        </div>
                    </div>
                    <div class="p-2 text-center text-[10px] text-slate-400">
                        Arahkan QR Code Kartu Pelajar tepat di depan kamera
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- TABEL RIWAYAT KUNJUNGAN & FILTER -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 backdrop-blur-xl shadow-xl space-y-4">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-white/10">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-list-check text-teal-400"></i> Riwayat Pengunjung Perpustakaan
            </h3>

            <!-- Filter Tanggal & Cari -->
            <form method="GET" action="visitors.php" class="flex flex-wrap items-center gap-2">
                <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>"
                       class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-teal-500 focus:outline-none transition">
                <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="Cari nama / NISN / kelas..."
                       class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-teal-500 focus:outline-none transition w-44">
                <button type="submit" class="rounded-xl bg-teal-600 hover:bg-teal-500 px-3 py-1.5 text-xs font-bold text-white transition">
                    <i class="fa-solid fa-filter"></i> Filter
                </button>
                <?php if (!empty($filter_date) || !empty($search_q)): ?>
                    <a href="visitors.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs text-slate-300 transition">
                        Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Tabel Pengunjung -->
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs sm:text-sm">
                <thead>
                    <tr class="border-b border-white/10 text-xs font-bold text-slate-400 uppercase tracking-wider">
                        <th class="py-3 px-3">Waktu</th>
                        <th class="py-3 px-3">Nama Pengunjung</th>
                        <th class="py-3 px-3">Identitas / Peran</th>
                        <th class="py-3 px-3">Kelas / Bagian</th>
                        <th class="py-3 px-3">Keperluan Kunjungan</th>
                        <th class="py-3 px-3">Catatan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 text-slate-300">
                    <?php if (empty($visitors)): ?>
                        <tr>
                            <td colspan="6" class="py-10 text-center text-slate-500">
                                <i class="fa-solid fa-clipboard-user text-3xl mb-2 text-slate-700"></i>
                                <p class="text-xs">Belum ada catatan pengunjung pada kriteria filter ini.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($visitors as $v): ?>
                            <tr class="hover:bg-white/5 transition">
                                <td class="py-3 px-3 font-mono text-xs text-slate-400 whitespace-nowrap">
                                    <div><?= date('d/m/Y', strtotime($v['visit_date'])) ?></div>
                                    <div class="text-[11px] text-teal-400 font-bold"><?= date('H:i', strtotime($v['visit_time'])) ?> WIB</div>
                                </td>
                                <td class="py-3 px-3 font-bold text-white whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <div class="flex h-7 w-7 items-center justify-center rounded-lg <?= $v['gender'] === 'P' ? 'bg-rose-500/20 text-rose-300' : 'bg-blue-500/20 text-blue-300' ?> text-xs font-bold">
                                            <?= htmlspecialchars(substr($v['name'], 0, 1)) ?>
                                        </div>
                                        <span><?= htmlspecialchars($v['name']) ?></span>
                                    </div>
                                </td>
                                <td class="py-3 px-3 whitespace-nowrap">
                                    <span class="rounded-md px-2 py-0.5 text-[10px] font-bold uppercase border <?= $v['role'] === 'siswa' ? 'bg-blue-500/10 text-blue-400 border-blue-500/20' : ($v['role'] === 'guru' ? 'bg-purple-500/10 text-purple-400 border-purple-500/20' : 'bg-slate-500/10 text-slate-400 border-slate-500/20') ?>">
                                        <?= htmlspecialchars(ucfirst($v['role'])) ?>
                                    </span>
                                    <?php if (!empty($v['identifier']) && $v['identifier'] !== '-'): ?>
                                        <div class="text-[10px] text-slate-500 font-mono mt-0.5">ID: <?= htmlspecialchars($v['identifier']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-3 text-slate-300 whitespace-nowrap">
                                    <?= htmlspecialchars($v['class_name'] ?: '-') ?>
                                </td>
                                <td class="py-3 px-3">
                                    <span class="rounded-lg bg-slate-950 px-2.5 py-1 text-xs border border-white/5 text-teal-300 inline-block">
                                        <?= htmlspecialchars($v['purpose']) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-xs text-slate-400">
                                    <?= htmlspecialchars($v['notes'] ?: '-') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

</div>

<!-- MODAL POPUP SAMBUTAN KIOSK -->
<div id="welcomeKioskModal" class="fixed inset-0 z-50 hidden bg-black/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="relative w-full max-w-md rounded-3xl border border-teal-500/40 bg-slate-900 p-6 sm:p-8 shadow-2xl text-center text-white space-y-4 animate-scaleUp">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-teal-500/20 text-teal-400 border border-teal-500/40 text-3xl">
            <i class="fa-solid fa-circle-check"></i>
        </div>
        <div>
            <h3 class="text-xl font-black text-white">Selamat Datang!</h3>
            <p id="kioskWelcomeName" class="text-lg font-bold text-teal-400 mt-1">Nama Siswa</p>
            <p id="kioskWelcomeDesc" class="text-xs text-slate-400 mt-1">Kelas X MIPA 1 • Waktu: 08:30 WIB</p>
        </div>
        <div class="rounded-2xl border border-white/10 bg-slate-950 p-3 text-xs text-slate-300">
            Kunjungan Anda telah berhasil dicatat ke sistem perpustakaan sekolah. Selamat membaca dan belajar!
        </div>
        <button type="button" onclick="closeKioskModal()" 
                class="w-full rounded-xl bg-teal-600 hover:bg-teal-500 py-2.5 text-xs font-bold text-white shadow-lg shadow-teal-600/20 transition cursor-pointer">
            Tutup
        </button>
    </div>
</div>

<!-- MODAL FORM INPUT MANUAL PENGUNJUNG -->
<div id="manualModal" class="fixed inset-0 z-50 hidden bg-black/75 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="relative w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-7 shadow-2xl text-slate-100 my-8">
        <div class="flex items-center justify-between border-b border-white/10 pb-3 mb-5">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-teal-400"></i> Catat Pengunjung Manual
            </h3>
            <button onclick="closeManualModal()" class="rounded-lg p-1 text-slate-400 hover:text-white hover:bg-white/10 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="visitors.php" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="manual_visitor">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Lengkap Pengunjung *</label>
                <input type="text" name="name" required placeholder="Contoh: Rian Pratama atau Ibu Suryani"
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Peran / Kategori *</label>
                    <select name="role" required
                            class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                        <option value="siswa">Siswa</option>
                        <option value="guru">Guru</option>
                        <option value="staf">Staf</option>
                        <option value="umum">Umum / Tamu Luar</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Jenis Kelamin</label>
                    <select name="gender"
                            class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                        <option value="L">Laki-laki</option>
                        <option value="P">Perempuan</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">NISN / No. Identitas</label>
                    <input type="text" name="identifier" placeholder="NISN atau NIP..."
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kelas / Instansi</label>
                    <input type="text" name="class_name" placeholder="Contoh: X MIPA 1 atau Wali Murid"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Keperluan Kunjungan *</label>
                <select name="purpose" required
                        class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
                    <option value="Membaca Buku / Majalah">Membaca Buku / Majalah</option>
                    <option value="Mengerjakan Tugas / Belajar Mandiri">Mengerjakan Tugas / Belajar Mandiri</option>
                    <option value="Peminjaman / Pengembalian Buku">Peminjaman / Pengembalian Buku</option>
                    <option value="Belajar Kelompok / Diskusi">Belajar Kelompok / Diskusi</option>
                    <option value="Akses Komputer & Internet">Akses Komputer & Internet</option>
                    <option value="Kunjungan Fasilitas / Lainnya">Kunjungan Fasilitas / Lainnya</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Catatan Tambahan (Opsional)</label>
                <input type="text" name="notes" placeholder="Keterangan keperluan..."
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none transition">
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                <button type="button" onclick="closeManualModal()" 
                        class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                    Batal
                </button>
                <button type="submit" 
                        class="rounded-xl bg-teal-600 hover:bg-teal-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-teal-600/20 transition cursor-pointer flex items-center gap-1.5">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Buku Tamu
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= generateCsrfToken() ?>';
let kioskScanner = null;
let kioskScanActive = false;

function playKioskChime() {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.frequency.setValueAtTime(523.25, audioCtx.currentTime); // C5
        gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
        osc.start();
        osc.stop(audioCtx.currentTime + 0.1);
        setTimeout(() => {
            const osc2 = audioCtx.createOscillator();
            const gain2 = audioCtx.createGain();
            osc2.connect(gain2);
            gain2.connect(audioCtx.destination);
            osc2.frequency.setValueAtTime(659.25, audioCtx.currentTime); // E5
            gain2.gain.setValueAtTime(0.2, audioCtx.currentTime);
            osc2.start();
            osc2.stop(audioCtx.currentTime + 0.15);
        }, 110);
    } catch(e) {}
}

function startKioskCamera() {
    if (!kioskScanner) {
        kioskScanner = new Html5Qrcode("kioskReader");
    }

    const config = { fps: 10, qrbox: { width: 200, height: 200 }, aspectRatio: 1.0 };
    kioskScanner.start(
        { facingMode: "user" },
        config,
        (decodedText) => {
            processVisitorScan(decodedText);
        },
        (error) => {}
    ).then(() => {
        kioskScanActive = true;
        document.getElementById('kioskCameraOverlay').classList.add('hidden');
    }).catch(err => alert("Gagal menyalakan kamera: " + err));
}

let lastVisitorScanTime = 0;
function processVisitorScan(code) {
    const now = Date.now();
    if (now - lastVisitorScanTime < 2500) return;
    lastVisitorScanTime = now;

    const selectedPurpose = document.querySelector('input[name="kiosk_purpose"]:checked')?.value || 'Membaca Buku / Majalah';

    const fd = new FormData();
    fd.append('action', 'scan_visitor');
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('code', code);
    fd.append('purpose', selectedPurpose);

    fetch('visitors.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                playKioskChime();
                showWelcomeModal(res.visitor);
            } else {
                alert(res.message);
            }
        })
        .catch(err => console.error(err));
}

function showWelcomeModal(v) {
    document.getElementById('kioskWelcomeName').textContent = v.name;
    document.getElementById('kioskWelcomeDesc').textContent = `${v.class_name || v.role} • Pukul: ${v.time} WIB`;
    document.getElementById('welcomeKioskModal').classList.remove('hidden');

    // Auto dismiss after 3.5 seconds
    setTimeout(() => {
        closeKioskModal();
    }, 3500);
}

function closeKioskModal() {
    document.getElementById('welcomeKioskModal').classList.add('hidden');
}

// Input manual Kiosk enter key
document.getElementById('kioskCodeInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        submitKioskManual();
    }
});

function submitKioskManual() {
    const input = document.getElementById('kioskCodeInput');
    const val = input.value.trim();
    if (!val) return;
    processVisitorScan(val);
    input.value = '';
}

function openManualModal() {
    document.getElementById('manualModal').classList.remove('hidden');
}
function closeManualModal() {
    document.getElementById('manualModal').classList.add('hidden');
}
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
