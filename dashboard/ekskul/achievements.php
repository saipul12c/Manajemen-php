<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Pengguna';
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_admin_or_staff = in_array($user_role, ['administrator', 'staf'], true);
$is_teacher        = ($user_role === 'guru');
$can_manage        = ($is_admin_or_staff || $is_teacher);

$message = "";
$message_type = "";

// -------------------------------------------------------------
// POST HANDLER: TAMBAH PRESTASI BARU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $message = "Token CSRF tidak valid. Silakan coba kembali.";
        $message_type = "error";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_achievement') {
            $student_id = ($user_role === 'siswa') ? $user_id : (int)($_POST['student_id'] ?? 0);
            $ekskul_id  = !empty($_POST['extracurricular_id']) ? (int)$_POST['extracurricular_id'] : null;
            $comp_name  = trim($_POST['competition_name'] ?? '');
            $level      = trim($_POST['level'] ?? 'kabupaten');
            $rank       = trim($_POST['rank_achieved'] ?? 'Juara 1');
            $ach_date   = trim($_POST['achievement_date'] ?? date('Y-m-d'));
            $desc       = trim($_POST['description'] ?? '');
            $verifier   = $can_manage ? $user_id : null;

            if ($student_id <= 0 || empty($comp_name) || empty($rank)) {
                $message = "Nama siswa, nama kompetisi, dan peringkat juara wajib diisi.";
                $message_type = "error";
            } else {
                $stmt_in = $pdo->prepare("
                    INSERT INTO extracurricular_achievements 
                    (extracurricular_id, student_id, competition_name, level, rank_achieved, achievement_date, description, verified_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_in->execute([$ekskul_id, $student_id, $comp_name, $level, $rank, $ach_date, $desc, $verifier]);
                logActivity($pdo, 'ADD_ACHIEVEMENT', "Menambahkan prestasi: '$comp_name' ($rank) untuk siswa ID #$student_id");
                $message = "Catatan prestasi dan piagam penghargaan berhasil didokumentasikan!";
                $message_type = "success";
            }
        }
    }
}

// -------------------------------------------------------------
// FILTER & EKSPOR CSV (STANDAR AKREDITASI BAN-SM)
// -------------------------------------------------------------
$filter_level  = trim($_GET['level'] ?? '');
$filter_ekskul = (int)($_GET['ekskul_id'] ?? 0);
$search_q      = trim($_GET['q'] ?? '');

$sql = "
    SELECT ach.*, u.name as student_name, u.nisn, u.gender, c.name as class_name,
           e.name as ekskul_name, e.code as ekskul_code, e.logo_icon,
           uv.name as verifier_name
    FROM extracurricular_achievements ach
    JOIN users u ON ach.student_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN extracurriculars e ON ach.extracurricular_id = e.id
    LEFT JOIN users uv ON ach.verified_by = uv.id
    WHERE 1=1
";
$params = [];

if (!empty($filter_level) && in_array($filter_level, ['sekolah', 'kecamatan', 'kabupaten', 'provinsi', 'nasional', 'internasional'], true)) {
    $sql .= " AND ach.level = ?";
    $params[] = $filter_level;
}
if ($filter_ekskul > 0) {
    $sql .= " AND ach.extracurricular_id = ?";
    $params[] = $filter_ekskul;
}
if (!empty($search_q)) {
    $sql .= " AND (u.name LIKE ? OR ach.competition_name LIKE ? OR ach.rank_achieved LIKE ?)";
    $params[] = "%$search_q%";
    $params[] = "%$search_q%";
    $params[] = "%$search_q%";
}

$sql .= " ORDER BY ach.achievement_date DESC, ach.id DESC";

// Ekspor CSV jika diminta
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt_csv = $pdo->prepare($sql);
    $stmt_csv->execute($params);
    $data_csv = $stmt_csv->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rekap_prestasi_sekolah_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

    fputcsv($output, ['REKAPITULASI PRESTASI & PENGHARGAAN SISWA (STANDAR AKREDITASI BAN-SM)']);
    fputcsv($output, ['Tanggal Unduh', date('d/m/Y H:i:s') . ' WIB']);
    fputcsv($output, ['Total Prestasi Terdata', count($data_csv)]);
    fputcsv($output, []);
    fputcsv($output, ['No', 'Nama Siswa', 'NISN', 'Kelas', 'Nama Kompetisi / Turnamen', 'Peringkat / Juara', 'Tingkat', 'Tanggal', 'Organisasi / Ekskul', 'Status Verifikasi']);

    $no = 1;
    foreach ($data_csv as $r) {
        fputcsv($output, [
            $no++,
            $r['student_name'],
            $r['nisn'] ?: '-',
            $r['class_name'] ?: '-',
            $r['competition_name'],
            $r['rank_achieved'],
            strtoupper($r['level']),
            date('d/m/Y', strtotime($r['achievement_date'])),
            $r['ekskul_name'] ?: 'Umum / Akademik',
            !empty($r['verifier_name']) ? 'Terverifikasi (' . $r['verifier_name'] . ')' : 'Menunggu'
        ]);
    }
    fclose($output);
    exit;
}

$stmt_ach = $pdo->prepare($sql);
$stmt_ach->execute($params);
$achievements = $stmt_ach->fetchAll(PDO::FETCH_ASSOC);

// Metrik Tingkatan Prestasi
$stat_intl  = (int)$pdo->query("SELECT COUNT(*) FROM extracurricular_achievements WHERE level = 'internasional'")->fetchColumn();
$stat_nas   = (int)$pdo->query("SELECT COUNT(*) FROM extracurricular_achievements WHERE level = 'nasional'")->fetchColumn();
$stat_prov  = (int)$pdo->query("SELECT COUNT(*) FROM extracurricular_achievements WHERE level = 'provinsi'")->fetchColumn();
$stat_kab   = (int)$pdo->query("SELECT COUNT(*) FROM extracurricular_achievements WHERE level = 'kabupaten'")->fetchColumn();

// Dropdown data untuk form
$all_ekskul   = $pdo->query("SELECT id, name, code FROM extracurriculars WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_students = [];
if ($can_manage) {
    $all_students = $pdo->query("SELECT u.id, u.name, u.nisn, c.name as class_name FROM users u LEFT JOIN classes c ON u.class_id = c.id WHERE u.role = 'siswa' ORDER BY u.name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = "Hall of Fame - Galeri Prestasi Siswa";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Header Title & Action Buttons -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-slate-400 mb-1">
                <a href="../index.php" class="hover:text-blue-400 transition">Dashboard</a>
                <span><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
                <a href="index.php" class="hover:text-blue-400 transition">Ekstrakurikuler</a>
                <span><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
                <span class="text-amber-400 font-medium">Galeri Prestasi</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-tr from-amber-500 via-orange-500 to-yellow-400 text-white shadow-lg shadow-amber-500/30 text-xl">
                    <i class="fa-solid fa-award"></i>
                </span>
                <span>Hall of Fame & Galeri Prestasi Siswa</span>
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Dokumentasi piagam penghargaan, piala kejuaraan lomba akademik dan non-akademik civitas sekolah.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Ekspor CSV Akreditasi -->
            <a href="achievements.php?export=csv<?= !empty($filter_level) ? '&level='.$filter_level : '' ?><?= $filter_ekskul > 0 ? '&ekskul_id='.$filter_ekskul : '' ?>" 
               class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-white/10 text-xs font-semibold text-emerald-300 transition flex items-center gap-1.5">
                <i class="fa-solid fa-file-csv text-emerald-400"></i>
                <span>Unduh CSV Akreditasi</span>
            </a>

            <!-- Tombol Tambah Prestasi -->
            <button type="button" onclick="openModal('addAchModal')"
                    class="px-4 py-2 rounded-xl bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-xs font-bold text-white shadow-lg shadow-amber-500/20 transition cursor-pointer flex items-center gap-1.5">
                <i class="fa-solid fa-plus"></i>
                <span>Catat Prestasi Baru</span>
            </button>
        </div>
    </div>

    <!-- Alert Notifikasi -->
    <?php if (!empty($message)): ?>
        <div class="p-4 rounded-2xl border text-xs font-medium flex items-center justify-between gap-3 <?= $message_type === 'success' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' : 'bg-rose-500/10 border-rose-500/30 text-rose-300' ?>">
            <div class="flex items-center gap-2.5">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-rose-400' ?> text-base"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <!-- 4 Metrik Prestasi Berjenjang -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 rounded-2xl bg-slate-900/80 border border-purple-500/20 shadow-sm relative overflow-hidden group">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-bold text-purple-400 uppercase tracking-wider block">Internasional</span>
                    <span class="text-2xl font-black text-white mt-1 block"><?= $stat_intl ?></span>
                    <span class="text-[10px] text-slate-400">Kejuaraan dunia</span>
                </div>
                <div class="h-10 w-10 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400 text-lg">
                    <i class="fa-solid fa-earth-americas"></i>
                </div>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-slate-900/80 border border-rose-500/20 shadow-sm relative overflow-hidden group">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-bold text-rose-400 uppercase tracking-wider block">Tingkat Nasional</span>
                    <span class="text-2xl font-black text-white mt-1 block"><?= $stat_nas ?></span>
                    <span class="text-[10px] text-slate-400">OSN, O2SN, FLS2N</span>
                </div>
                <div class="h-10 w-10 rounded-xl bg-rose-500/10 border border-rose-500/20 flex items-center justify-center text-rose-400 text-lg">
                    <i class="fa-solid fa-flag"></i>
                </div>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-slate-900/80 border border-amber-500/20 shadow-sm relative overflow-hidden group">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-bold text-amber-400 uppercase tracking-wider block">Tingkat Provinsi</span>
                    <span class="text-2xl font-black text-white mt-1 block"><?= $stat_prov ?></span>
                    <span class="text-[10px] text-slate-400">Kejurda & Jambore</span>
                </div>
                <div class="h-10 w-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 text-lg">
                    <i class="fa-solid fa-medal"></i>
                </div>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-slate-900/80 border border-blue-500/20 shadow-sm relative overflow-hidden group">
            <div class="flex items-center justify-between">
                <div>
                    <span class="text-[10px] font-bold text-blue-400 uppercase tracking-wider block">Kabupaten / Kota</span>
                    <span class="text-2xl font-black text-white mt-1 block"><?= $stat_kab ?></span>
                    <span class="text-[10px] text-slate-400">Porda & Antar-Pelajar</span>
                </div>
                <div class="h-10 w-10 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400 text-lg">
                    <i class="fa-solid fa-trophy"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Controls -->
    <div class="p-4 rounded-2xl bg-slate-900/90 border border-white/10 flex flex-col md:flex-row md:items-center justify-between gap-3 shadow-md">
        <form method="GET" class="flex flex-wrap items-center gap-2.5 w-full md:w-auto">
            <!-- Filter Tingkat -->
            <select name="level" onchange="this.form.submit()" class="bg-slate-950 border border-white/10 rounded-xl px-3 py-1.5 text-xs text-slate-200">
                <option value="">-- Semua Tingkat --</option>
                <option value="internasional" <?= $filter_level === 'internasional' ? 'selected' : '' ?>>Internasional</option>
                <option value="nasional" <?= $filter_level === 'nasional' ? 'selected' : '' ?>>Nasional</option>
                <option value="provinsi" <?= $filter_level === 'provinsi' ? 'selected' : '' ?>>Provinsi</option>
                <option value="kabupaten" <?= $filter_level === 'kabupaten' ? 'selected' : '' ?>>Kabupaten / Kota</option>
                <option value="sekolah" <?= $filter_level === 'sekolah' ? 'selected' : '' ?>>Sekolah</option>
            </select>

            <!-- Filter Ekskul -->
            <select name="ekskul_id" onchange="this.form.submit()" class="bg-slate-950 border border-white/10 rounded-xl px-3 py-1.5 text-xs text-slate-200">
                <option value="">-- Semua Cabang Ekskul --</option>
                <?php foreach ($all_ekskul as $ae): ?>
                    <option value="<?= $ae['id'] ?>" <?= $filter_ekskul === (int)$ae['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ae['name']) ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Search input -->
            <div class="relative flex-1 sm:w-64">
                <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="Cari nama siswa / kejuaraan..."
                       class="w-full bg-slate-950 border border-white/10 rounded-xl pl-8 pr-3 py-1.5 text-xs text-slate-200 placeholder:text-slate-500">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-500"></i>
            </div>

            <button type="submit" class="px-3.5 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white transition">
                Filter
            </button>
            <?php if (!empty($filter_level) || $filter_ekskul > 0 || !empty($search_q)): ?>
                <a href="achievements.php" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-400 transition">
                    Reset
                </a>
            <?php endif; ?>
        </form>

        <span class="text-xs text-slate-400 shrink-0">
            Ditemukan: <strong class="text-white"><?= count($achievements) ?></strong> Prestasi
        </span>
    </div>

    <!-- Grid Kartu Prestasi Visual -->
    <?php if (empty($achievements)): ?>
        <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-12 text-center">
            <i class="fa-solid fa-award text-4xl text-slate-500 mb-2"></i>
            <h3 class="text-sm font-bold text-white">Tidak Ada Data Prestasi Ditemukan</h3>
            <p class="text-xs text-slate-400 mt-1">Belum ada catatan kejuaraan yang sesuai dengan filter atau kata kunci Anda.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($achievements as $ach): 
                $level_badge = match($ach['level']) {
                    'internasional' => 'bg-purple-500/20 text-purple-300 border-purple-500/30',
                    'nasional'      => 'bg-rose-500/20 text-rose-300 border-rose-500/30',
                    'provinsi'      => 'bg-amber-500/20 text-amber-300 border-amber-500/30',
                    'kabupaten'     => 'bg-blue-500/20 text-blue-300 border-blue-500/30',
                    default         => 'bg-slate-800 text-slate-400 border-slate-700'
                };
            ?>
                <div class="rounded-3xl border border-amber-500/20 bg-slate-900/90 p-5 flex flex-col justify-between hover:border-amber-500/50 transition-all duration-200 shadow-xl relative group">
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <div class="h-11 w-11 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 text-xl shadow group-hover:scale-105 transition-transform">
                                <i class="fa-solid <?= htmlspecialchars($ach['logo_icon'] ?: 'fa-medal') ?>"></i>
                            </div>
                            <span class="text-[9px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-full border <?= $level_badge ?>">
                                Tingkat <?= ucfirst($ach['level']) ?>
                            </span>
                        </div>

                        <div class="mt-3.5">
                            <span class="text-xs font-extrabold text-amber-300 flex items-center gap-1.5">
                                <i class="fa-solid fa-trophy text-[11px]"></i>
                                <?= htmlspecialchars($ach['rank_achieved']) ?>
                            </span>
                            <h3 class="text-sm font-bold text-white mt-1 leading-snug">
                                <?= htmlspecialchars($ach['competition_name']) ?>
                            </h3>
                            <p class="text-[10px] text-slate-400 mt-1">
                                <i class="fa-regular fa-calendar mr-1"></i><?= date('d F Y', strtotime($ach['achievement_date'])) ?>
                            </p>
                        </div>

                        <!-- Info Siswa & Ekskul -->
                        <div class="mt-3.5 p-3 rounded-2xl bg-slate-950/60 border border-white/5 space-y-1.5 text-[11px]">
                            <div class="flex items-center justify-between">
                                <span class="text-slate-400">Pemenang:</span>
                                <span class="font-bold text-white"><?= htmlspecialchars($ach['student_name']) ?></span>
                            </div>
                            <div class="flex items-center justify-between text-[10px]">
                                <span class="text-slate-400">Kelas / NISN:</span>
                                <span class="text-slate-300 font-mono"><?= htmlspecialchars($ach['class_name'] ?? 'Siswa') ?> (<?= htmlspecialchars($ach['nisn'] ?: '-') ?>)</span>
                            </div>
                            <div class="flex items-center justify-between text-[10px]">
                                <span class="text-slate-400">Cabang Ekskul:</span>
                                <span class="text-amber-400 font-medium"><?= htmlspecialchars($ach['ekskul_name'] ?? 'Umum / Mandiri') ?></span>
                            </div>
                        </div>

                        <?php if (!empty($ach['description'])): ?>
                            <p class="text-[11px] text-slate-300 mt-2.5 italic leading-relaxed">
                                "<?= htmlspecialchars($ach['description']) ?>"
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Footer Action -->
                    <div class="mt-5 pt-3 border-t border-white/5 flex items-center justify-between text-[11px]">
                        <span class="text-[10px] text-slate-400">
                            Valid: <?= htmlspecialchars($ach['verifier_name'] ?? 'Sistem') ?>
                        </span>
                        
                        <a href="print_certificate.php?achievement_id=<?= $ach['id'] ?>" target="_blank"
                           class="px-3 py-1.5 rounded-xl bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/30 text-amber-300 font-bold transition flex items-center gap-1.5">
                            <i class="fa-solid fa-certificate text-xs"></i>
                            <span>Cetak Piagam</span>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ============================================================== -->
<!-- MODAL: CATAT PRESTASI BARU -->
<!-- ============================================================== -->
<div id="addAchModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-lg w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto custom-scrollbar">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-trophy text-amber-400"></i>
                <span>Catat Prestasi Kejuaraan Siswa</span>
            </h3>
            <button type="button" onclick="closeModal('addAchModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_achievement">

            <?php if ($can_manage): ?>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Peserta Didik Peraih Prestasi <span class="text-rose-400">*</span></label>
                    <select name="student_id" required class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-amber-500">
                        <option value="">-- Pilih Siswa --</option>
                        <?php foreach ($all_students as $st): ?>
                            <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['name']) ?> (<?= htmlspecialchars($st['class_name'] ?? 'Siswa') ?> - NISN: <?= htmlspecialchars($st['nisn'] ?: '-') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="student_id" value="<?= $user_id ?>">
                <div class="p-3 rounded-xl bg-slate-950/60 border border-white/5 text-xs">
                    <span class="text-slate-400">Pengaju:</span>
                    <strong class="text-white ml-1"><?= htmlspecialchars($user_name) ?> (Siswa)</strong>
                </div>
            <?php endif; ?>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Cabang Ekstrakurikuler / Bidang</label>
                <select name="extracurricular_id" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-amber-500">
                    <option value="">-- Umum / Mandiri --</option>
                    <?php foreach ($all_ekskul as $ae): ?>
                        <option value="<?= $ae['id'] ?>"><?= htmlspecialchars($ae['name']) ?> (<?= htmlspecialchars($ae['code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Kompetisi / Perlombaan <span class="text-rose-400">*</span></label>
                <input type="text" name="competition_name" required placeholder="Contoh: Lomba Debat Bahasa Inggris Tingkat Nasional 2026"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-amber-500">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Peringkat / Juara <span class="text-rose-400">*</span></label>
                    <input type="text" name="rank_achieved" required placeholder="Contoh: Juara 1 / Medali Emas"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tingkat Penyelenggaraan</label>
                    <select name="level" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-amber-500">
                        <option value="sekolah">Sekolah</option>
                        <option value="kecamatan">Kecamatan</option>
                        <option value="kabupaten" selected>Kabupaten / Kota</option>
                        <option value="provinsi">Provinsi</option>
                        <option value="nasional">Nasional</option>
                        <option value="internasional">Internasional</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Tanggal Kejuaraan</label>
                <input type="date" name="achievement_date" value="<?= date('Y-m-d') ?>" required
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Deskripsi Tambahan / Penyelenggara</label>
                <textarea name="description" rows="2" placeholder="Nama instansi penyelenggara atau nomor sertifikat..."
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-xs text-slate-200 focus:outline-none focus:border-amber-500"></textarea>
            </div>

            <div class="pt-3 border-t border-white/10 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('addAchModal')" class="px-4 py-2 rounded-xl bg-slate-800 text-xs font-semibold text-white">Batal</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-xs font-bold text-white shadow-lg shadow-amber-500/20">Simpan Piagam</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('hidden');
}
</script>

<?php
require_once __DIR__ . "/../includes/footer.php";
?>
