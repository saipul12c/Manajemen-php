<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['guru', 'staf', 'administrator'], true);

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. RESOLUSI SISWA TERKAIT
// -------------------------------------------------------------
$target_student_id = null;
$parent_children = [];
if ($user_role === 'siswa') {
    $target_student_id = $user_id;
} elseif ($user_role === 'orang_tua') {
    $stmt_all_c = $pdo->prepare("
        SELECT u.id, u.name, u.nisn, c.name as class_name 
        FROM parent_students ps
        JOIN users u ON ps.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE ps.parent_id = ?
        ORDER BY u.name ASC
    ");
    $stmt_all_c->execute([$user_id]);
    $parent_children = $stmt_all_c->fetchAll();
    $p_kids_ids = array_map('intval', array_column($parent_children, 'id'));

    $req_kid = isset($_GET['student_id']) && is_numeric($_GET['student_id']) ? (int)$_GET['student_id'] : (isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0);
    if ($req_kid > 0 && in_array($req_kid, $p_kids_ids, true)) {
        $target_student_id = $req_kid;
    } else {
        $target_student_id = !empty($p_kids_ids) ? (int)$p_kids_ids[0] : 0;
    }
} elseif (isset($_GET['student_id']) && is_numeric($_GET['student_id'])) {
    $target_student_id = (int)$_GET['student_id'];
}

$target_student_info = null;
if ($target_student_id) {
    $stmt_sti = $pdo->prepare("SELECT id, name, status, status_reason FROM users WHERE id = ? AND role = 'siswa'");
    $stmt_sti->execute([$target_student_id]);
    $target_student_info = $stmt_sti->fetch();
}

// -------------------------------------------------------------
// 2. TAMBAH CATATAN BK (Guru, Staf, Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_counseling' && $can_manage) {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $type = $_POST['type'] ?? 'pelanggaran';
        $category = trim($_POST['category'] ?? 'Kedisiplinan');
        $title = trim($_POST['title'] ?? '');
        $points = abs((int)($_POST['points'] ?? 0));
        $action_taken = trim($_POST['action_taken'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $record_date = $_POST['date'] ?? date('Y-m-d');

        if ($student_id <= 0 || empty($title)) {
            $message = "Siswa dan judul catatan wajib diisi.";
            $message_type = "error";
        } else {
            $stmt_in = $pdo->prepare("
                INSERT INTO counseling_records (student_id, type, category, title, points, action_taken, notes, recorded_by, date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_in->execute([$student_id, $type, $category, $title, $points, $action_taken ?: null, $notes ?: null, $user_id, $record_date]);
            $new_counseling_id = (int)$pdo->lastInsertId();

            logActivity($pdo, 'CREATE_COUNSELING', "Catat $type: $title untuk siswa ID $student_id ($points poin)");

            $wa_note = "";
            // Integrasi WhatsApp Alert & Peringatan Ambang Batas Poin BK ke Orang Tua
            if ($type === 'pelanggaran') {
                $stmt_tot = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM counseling_records WHERE student_id = ? AND type = 'pelanggaran'");
                $stmt_tot->execute([$student_id]);
                $cum_points = (int)$stmt_tot->fetchColumn();

                $wa_sent = notifyParentCounselingViolation($pdo, $student_id, $title, $points, $cum_points, $action_taken);
                if ($wa_sent) {
                    $wa_note = " Notifikasi WhatsApp peringatan poin telah terkirim ke Orang Tua.";
                }
            }

            // Integrasi: Jika dicentang langsung buat draf berita sekolah
            if (!empty($_POST['auto_create_news']) && $type === 'prestasi') {
                $stmt_st_name = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $stmt_st_name->execute([$student_id]);
                $st_name = $stmt_st_name->fetchColumn() ?: 'Siswa';
                $news_title = "Prestasi Siswa: " . $title . " (" . $st_name . ")";
                $news_excerpt = "Selamat dan apresiasi setinggi-tingginya kepada " . $st_name . " atas capaian membanggakan " . $title . " dalam bidang " . $category . ".";
                header("Location: ../informasi/news.php?action=create&source=prestasi&counseling_id=" . $new_counseling_id . "&title=" . urlencode($news_title) . "&excerpt=" . urlencode($news_excerpt) . "&category=prestasi");
                exit;
            }

            $message = "Catatan bimbingan konseling berhasil disimpan." . $wa_note;
            $message_type = "success";
            $target_student_id = $student_id;
        }
    }
}

// -------------------------------------------------------------
// 3. HAPUS CATATAN BK (Admin & Guru Pencatat)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_counseling' && $can_manage) {
    if (validateCsrfToken()) {
        $del_id = (int)($_POST['delete_id'] ?? 0);
        $query_del = "DELETE FROM counseling_records WHERE id = ?";
        $params_del = [$del_id];
        if ($user_role !== 'administrator') {
            $query_del .= " AND recorded_by = ?";
            $params_del[] = $user_id;
        }
        $stmt_del = $pdo->prepare($query_del);
        $stmt_del->execute($params_del);

        logActivity($pdo, 'DELETE_COUNSELING', "Menghapus catatan BK ID $del_id");
        $message = "Catatan bimbingan konseling berhasil dihapus.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 4. QUERY CATATAN BK
// -------------------------------------------------------------
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();
$all_students = $pdo->query("
    SELECT u.id, u.name, u.nisn, c.name as class_name 
    FROM users u 
    LEFT JOIN classes c ON u.class_id = c.id 
    WHERE u.role = 'siswa' 
    ORDER BY u.name ASC
")->fetchAll();

$filter_type = $_GET['type'] ?? '';
$filter_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;

$sql = "
    SELECT cr.*, u.name as student_name, u.nisn, c.name as class_name, rec.name as recorder_name
    FROM counseling_records cr
    JOIN users u ON cr.student_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    JOIN users rec ON cr.recorded_by = rec.id
    WHERE 1=1
";
$params = [];

if (!$can_manage) {
    $sql .= " AND cr.student_id = ?";
    $params[] = $target_student_id ?: 0;
} else {
    if ($target_student_id) {
        $sql .= " AND cr.student_id = ?";
        $params[] = $target_student_id;
    }
    if ($filter_class !== null) {
        $sql .= " AND u.class_id = ?";
        $params[] = $filter_class;
    }
}

if ($filter_type !== '') {
    $sql .= " AND cr.type = ?";
    $params[] = $filter_type;
}

$sql .= " ORDER BY cr.date DESC, cr.id DESC";
$stmt_cr = $pdo->prepare($sql);
$stmt_cr->execute($params);
$records = $stmt_cr->fetchAll();

// Hitung Poin Akumulasi
$total_penalty_points = 0;
$total_reward_points = 0;
foreach ($records as $r) {
    if ($r['type'] === 'pelanggaran') {
        $total_penalty_points += (int)$r['points'];
    } else {
        $total_reward_points += (int)$r['points'];
    }
}

// Tentukan Tingkat Sanksi
function getSanctionStatus(int $points, string $user_status = 'aktif'): array {
    if ($user_status === 'dikeluarkan' || $points >= 100) {
        return [
            'label' => 'Dikeluarkan dari Sekolah (DO)', 
            'badge' => 'border-rose-600/60 bg-rose-600/30 text-rose-200 font-bold', 
            'icon' => '<i class="fa-solid fa-ban"></i>'
        ];
    } elseif ($user_status === 'skorsing' || $points >= 75) {
        return [
            'label' => 'Sidang Pleno / Skorsing', 
            'badge' => 'border-rose-500/40 bg-rose-500/20 text-rose-300', 
            'icon' => '<i class="fa-solid fa-triangle-exclamation"></i>'
        ];
    } elseif ($points >= 50) {
        return [
            'label' => 'Pemanggilan Orang Tua (SP 2)', 
            'badge' => 'border-amber-500/40 bg-amber-500/20 text-amber-300', 
            'icon' => '<i class="fa-solid fa-triangle-exclamation"></i>'
        ];
    } elseif ($points >= 25) {
        return [
            'label' => 'Peringatan Tertulis (SP 1)', 
            'badge' => 'border-yellow-500/40 bg-yellow-500/20 text-yellow-300', 
            'icon' => '<i class="fa-solid fa-file-pen"></i>'
        ];
    } elseif ($points > 0) {
        return [
            'label' => 'Peringatan Lisan / Pembinaan', 
            'badge' => 'border-blue-500/40 bg-blue-500/20 text-blue-300', 
            'icon' => '<i class="fa-solid fa-circle-info"></i>'
        ];
    }
    return [
        'label' => 'Tertib & Disiplin Baik', 
        'badge' => 'border-emerald-500/40 bg-emerald-500/20 text-emerald-300', 
        'icon' => '<i class="fa-solid fa-circle-check"></i>'
    ];
}

$current_user_status = $target_student_info['status'] ?? 'aktif';
$is_critical_do = ($total_penalty_points >= 100 || $current_user_status === 'dikeluarkan');
$sanction_info = getSanctionStatus($total_penalty_points, $current_user_status);

$page_title = "Bimbingan Konseling & Prestasi Siswa";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Top Action Bar -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2.5">
                <span class="text-blue-400"><i class="fa-solid fa-scale-balanced"></i></span> Bimbingan Konseling (BK) & Prestasi
            </h1>
            <p class="text-sm text-slate-400 mt-1">
                Pencatatan rekam jejak kedisiplinan, sistem poin pelanggaran tata tertib, dan penanganan sanksi kesiswaan.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <?php if ($target_student_id): ?>
                <?php if ($is_critical_do): ?>
                    <a href="counseling_letter.php?student_id=<?= $target_student_id ?>&type=do" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-rose-500/50 bg-rose-600/30 hover:bg-rose-600/40 px-3.5 py-2 text-xs font-bold text-rose-200 transition shadow-lg shadow-rose-600/20">
                        <i class="fa-solid fa-file-contract"></i> Cetak SK Pengembalian / DO
                    </a>
                <?php else: ?>
                    <a href="counseling_letter.php?student_id=<?= $target_student_id ?>" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 px-3.5 py-2 text-xs font-bold text-rose-300 transition">
                        <i class="fa-solid fa-file-lines"></i> Cetak Surat Peringatan / SP
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($can_manage): ?>
                <button onclick="document.getElementById('modalAddCounseling').classList.remove('hidden')" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-lg shadow-blue-500/25 transition hover:bg-blue-500 cursor-pointer">
                    <i class="fa-solid fa-plus"></i> Catat Kasus / Prestasi
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($user_role === 'orang_tua' && count($parent_children) > 1): ?>
        <div class="rounded-2xl border border-blue-500/30 bg-blue-950/40 p-4 backdrop-blur flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-blue-300"><i class="fa-solid fa-children mr-1"></i> Pilih Data Anak:</span>
                <form method="GET" class="inline-flex items-center gap-2">
                    <select name="student_id" onchange="this.form.submit()" class="rounded-xl border border-blue-500/40 bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white focus:outline-none">
                        <?php foreach ($parent_children as $pk): ?>
                            <option value="<?= $pk['id'] ?>" <?= ($target_student_id == $pk['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pk['name']) ?> (<?= htmlspecialchars($pk['class_name'] ?: 'Umum') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <span class="text-xs text-slate-400">Menampilkan rekam jejak BK & kedisiplinan untuk anak terpilih</span>
        </div>
    <?php endif; ?>

    <!-- Alert Notifikasi Sanksi Berat / Drop Out (>= 100 Poin) -->
    <?php if ($target_student_id && $is_critical_do): ?>
        <div class="rounded-2xl border border-rose-500/40 bg-rose-950/40 p-4 sm:p-5 text-rose-200 shadow-xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div class="flex items-start gap-3.5">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-rose-600/30 text-rose-400 text-xl border border-rose-500/40">
                    <i class="fa-solid fa-ban"></i>
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm sm:text-base font-bold text-white">Ambang Batas Sanksi Maksimal / DO Terpenuhi</span>
                        <?= getUserStatusBadge($target_student_info['status'] ?? 'aktif') ?>
                    </div>
                    <p class="text-xs text-rose-300/80 mt-1 max-w-2xl leading-relaxed">
                        Akumulasi poin pelanggaran siswa ini telah mencapai <strong><?= $total_penalty_points ?> Poin</strong> (Batas Maksimal Dikeluarkan: 100 Poin). Tindak lanjut resmi: Terbitkan Surat Keputusan Pengembalian ke Orang Tua / Wali dan mutakhirkan status siswa menjadi Dikeluarkan di Master Pengguna.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0 w-full sm:w-auto">
                <a href="counseling_letter.php?student_id=<?= $target_student_id ?>&type=do" target="_blank" class="w-full sm:w-auto text-center rounded-xl bg-rose-600 hover:bg-rose-500 px-4 py-2 text-xs font-bold text-white transition shadow-lg shadow-rose-600/30 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-file-contract"></i> Cetak SK Dikeluarkan
                </a>
                <?php if ($can_manage && in_array($user_role, ['administrator', 'staf'], true)): ?>
                    <a href="../admin/users.php?action=edit&id=<?= $target_student_id ?>" target="_blank" class="w-full sm:w-auto text-center rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs font-semibold text-slate-200 transition flex items-center justify-center gap-2">
                        <i class="fa-solid fa-user-gear"></i> Set Status Master
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Alert Message -->
    <?php if (!empty($message)): ?>
        <div class="rounded-xl border p-4 <?= $message_type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/20 bg-rose-500/10 text-rose-300' ?> text-sm">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-950/20 p-5 shadow-lg backdrop-blur">
            <span class="text-xs font-bold uppercase tracking-wider text-emerald-400">Total Poin Prestasi</span>
            <div class="text-3xl font-black text-emerald-300 mt-1.5 flex items-center gap-2">
                <span class="text-amber-400"><i class="fa-solid fa-trophy"></i></span> +<?= $total_reward_points ?>
            </div>
            <p class="text-xs text-emerald-500/70 mt-1">Apresiasi kejuaraan & capaian positif</p>
        </div>

        <div class="rounded-2xl border border-rose-500/20 bg-rose-950/20 p-5 shadow-lg backdrop-blur">
            <span class="text-xs font-bold uppercase tracking-wider text-rose-400">Poin Pelanggaran Disiplin</span>
            <div class="text-3xl font-black text-rose-300 mt-1.5 flex items-center gap-2">
                <span class="text-rose-400"><i class="fa-solid fa-triangle-exclamation"></i></span> <?= $total_penalty_points ?>
            </div>
            <p class="text-xs text-rose-500/70 mt-1">Batas akumulasi pemanggilan: 50 poin</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 shadow-lg backdrop-blur">
            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Status Kedisiplinan</span>
            <div class="text-lg font-bold text-white mt-2">
                <span class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1 text-xs font-semibold uppercase tracking-wider <?= $sanction_info['badge'] ?>">
                    <span><?= $sanction_info['icon'] ?></span> <?= $sanction_info['label'] ?>
                </span>
            </div>
            <p class="text-xs text-slate-500 mt-2">Hasil evaluasi guru BK & wali kelas</p>
        </div>
    </div>

    <!-- Filter & Search Bar (Admin & Guru) -->
    <?php if ($can_manage): ?>
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur">
            <form method="GET" class="flex flex-wrap items-center gap-3">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Filter Data:</span>
                
                <select name="type" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                    <option value="">Semua Kategori</option>
                    <option value="prestasi" <?= $filter_type === 'prestasi' ? 'selected' : '' ?>>Prestasi Saja</option>
                    <option value="pelanggaran" <?= $filter_type === 'pelanggaran' ? 'selected' : '' ?>>Pelanggaran Saja</option>
                </select>

                <select name="student_id" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none max-w-xs">
                    <option value="">Semua Siswa</option>
                    <?php foreach ($all_students as $st): ?>
                        <option value="<?= $st['id'] ?>" <?= ($target_student_id == $st['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($st['name']) ?> (<?= htmlspecialchars($st['class_name'] ?: 'Umum') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="class_id" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                    <option value="">Semua Kelas</option>
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?= $cl['id'] ?>" <?= ($filter_class !== null && $filter_class == $cl['id']) ? 'selected' : '' ?>>
                            Kelas <?= htmlspecialchars($cl['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-1.5 text-xs font-medium text-slate-200 hover:bg-slate-700 transition cursor-pointer">
                    Filter
                </button>

                <?php if ($filter_type !== '' || $target_student_id || $filter_class !== null): ?>
                    <a href="counseling.php" class="rounded-xl border border-white/10 px-3 py-1.5 text-xs text-slate-400 hover:text-white transition">
                        Reset
                    </a>
                <?php endif; ?>
            </form>
        </div>
    <?php endif; ?>

    <!-- Counseling Records List -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/50 shadow-lg backdrop-blur overflow-hidden">
        <div class="border-b border-white/10 px-5 py-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-white">Riwayat Catatan Perilaku & Capaian</h3>
            <span class="text-xs text-slate-400">Total <?= count($records) ?> catatan</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-slate-950/60 text-xs uppercase font-semibold text-slate-400">
                    <tr>
                        <th class="px-5 py-3.5">Tanggal</th>
                        <?php if ($can_manage): ?>
                            <th class="px-5 py-3.5">Nama Siswa</th>
                        <?php endif; ?>
                        <th class="px-5 py-3.5">Jenis</th>
                        <th class="px-5 py-3.5">Kasus / Prestasi</th>
                        <th class="px-5 py-3.5">Bobot Poin</th>
                        <th class="px-5 py-3.5">Tindak Lanjut / Apresiasi</th>
                        <th class="px-5 py-3.5">Pencatat</th>
                        <?php if ($can_manage): ?>
                            <th class="px-5 py-3.5 text-right">Aksi</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="8" class="px-5 py-8 text-center text-slate-500">
                                Belum ada catatan bimbingan konseling yang terdaftar.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $item): ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="px-5 py-4 text-xs font-mono text-slate-400 whitespace-nowrap">
                                    <?= date('d M Y', strtotime($item['date'])) ?>
                                </td>

                                <?php if ($can_manage): ?>
                                    <td class="px-5 py-4">
                                        <div class="font-bold text-white"><?= htmlspecialchars($item['student_name']) ?></div>
                                        <div class="text-xs text-slate-500">NISN: <?= htmlspecialchars($item['nisn']) ?> (<?= htmlspecialchars($item['class_name'] ?: 'Umum') ?>)</div>
                                    </td>
                                <?php endif; ?>

                                <td class="px-5 py-4">
                                    <span class="rounded-lg border px-2.5 py-1 text-xs font-semibold inline-flex items-center gap-1.5 <?= getCounselingBadge($item['type']) ?>">
                                        <?= $item['type'] === 'prestasi' ? '<i class="fa-solid fa-trophy text-amber-400"></i> Prestasi' : '<i class="fa-solid fa-triangle-exclamation text-rose-400"></i> Pelanggaran' ?>
                                    </span>
                                </td>

                                <td class="px-5 py-4">
                                    <div class="font-bold text-white"><?= htmlspecialchars($item['title']) ?></div>
                                    <div class="text-xs text-slate-400 mt-0.5">Kategori: <?= htmlspecialchars($item['category']) ?></div>
                                    <?php if (!empty($item['notes'])): ?>
                                        <div class="text-xs text-slate-500 italic mt-0.5"><?= htmlspecialchars($item['notes']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <td class="px-5 py-4 font-mono font-bold <?= $item['type'] === 'prestasi' ? 'text-emerald-400' : 'text-rose-400' ?>">
                                    <?= $item['type'] === 'prestasi' ? '+' : '-' ?><?= $item['points'] ?> Poin
                                </td>

                                <td class="px-5 py-4 text-xs text-slate-300">
                                    <?= htmlspecialchars($item['action_taken'] ?: '-') ?>
                                </td>

                                <td class="px-5 py-4 text-xs text-slate-400">
                                    <i class="fa-solid fa-chalkboard-user mr-1 text-slate-400"></i><?= htmlspecialchars($item['recorder_name']) ?>
                                </td>

                                <?php if ($can_manage): ?>
                                    <td class="px-5 py-4 text-right whitespace-nowrap">
                                        <?php if ($item['type'] === 'prestasi'): ?>
                                            <a href="../informasi/news.php?action=create&source=prestasi&counseling_id=<?= $item['id'] ?>&title=<?= urlencode('Prestasi Siswa: ' . $item['title'] . ' (' . $item['student_name'] . ')') ?>&excerpt=<?= urlencode('Selamat dan apresiasi setinggi-tingginya kepada ' . $item['student_name'] . ' atas capaian ' . $item['title'] . ' (' . $item['category'] . ').') ?>&category=prestasi" 
                                               class="rounded-lg px-2.5 py-1 text-amber-300 hover:text-amber-200 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30 transition inline-flex items-center gap-1.5 text-xs font-semibold mr-1" 
                                               title="Publikasikan Capaian Ini ke Berita Sekolah">
                                                <i class="fa-solid fa-bullhorn text-[10px]"></i>
                                                <span class="hidden sm:inline">Buat Berita</span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($user_role === 'administrator' || $item['recorded_by'] == $user_id): ?>
                                            <form method="POST" onsubmit="return confirm('Hapus catatan ini?');" class="inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_counseling">
                                                <input type="hidden" name="delete_id" value="<?= $item['id'] ?>">
                                                <button type="submit" title="Hapus" class="rounded-lg p-1.5 text-slate-500 hover:text-rose-400 hover:bg-rose-500/10 transition cursor-pointer">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal Tambah Catatan BK (Guru & Admin) -->
<?php if ($can_manage): ?>
<div id="modalAddCounseling" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm hidden">
    <div class="w-full max-w-lg rounded-2xl border border-white/10 bg-slate-900 p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-blue-400"><i class="fa-solid fa-scale-balanced"></i></span> Catat Bimbingan Konseling / Prestasi
            </h3>
            <button onclick="document.getElementById('modalAddCounseling').classList.add('hidden')" class="text-slate-400 hover:text-white cursor-pointer text-lg font-bold">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_counseling">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Pilih Siswa</label>
                <select name="student_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
                    <?php foreach ($all_students as $st): ?>
                        <option value="<?= $st['id'] ?>" <?= ($target_student_id == $st['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($st['name']) ?> (NISN: <?= htmlspecialchars($st['nisn']) ?>) - <?= htmlspecialchars($st['class_name'] ?: 'Umum') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Master Template Preset -->
            <div class="rounded-xl border border-blue-500/30 bg-blue-500/5 p-3">
                <label class="block text-xs font-bold text-blue-300 uppercase tracking-wider mb-1"><i class="fa-solid fa-bolt text-amber-400 mr-1"></i> Pilih Template Tata Tertib / Prestasi Cepat:</label>
                <select id="presetRuleSelect" onchange="applyRulePreset(this)" class="w-full rounded-xl border border-white/20 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                    <option value="">-- Pilih Template Kasus / Prestasi Cepat --</option>
                    <optgroup label="Pelanggaran Ringan (5 Poin)">
                        <option data-type="pelanggaran" data-category="Kedisiplinan" data-points="5" data-action="Peringatan lisan & pencatatan poin tata tertib" value="Terlambat Masuk Jam Pertama Sekolah (15 Menit)">Terlambat Masuk Sekolah - 5 Poin</option>
                        <option data-type="pelanggaran" data-category="Kerapian" data-points="5" data-action="Peringatan dan kewajiban merapikan seragam" value="Atribut Seragam Tidak Lengkap (Badge/Dasi/Sepatu)">Atribut Seragam Tidak Lengkap - 5 Poin</option>
                        <option data-type="pelanggaran" data-category="Ketertiban" data-points="5" data-action="Teguran dan pembinaan di kelas oleh wali kelas" value="Membuat Gaduh / Mengganggu Proses KBM">Mengganggu KBM di Kelas - 5 Poin</option>
                    </optgroup>
                    <optgroup label="Pelanggaran Sedang (15 - 25 Poin)">
                        <option data-type="pelanggaran" data-category="Kedisiplinan" data-points="15" data-action="Pembinaan guru piket & tugas resume perpustakaan" value="Meninggalkan Lingkungan Sekolah Tanpa Izin (Membolos)">Membolos / Keluar Sekolah - 15 Poin</option>
                        <option data-type="pelanggaran" data-category="Ketertiban" data-points="20" data-action="Penyitaan sementara HP & pembinaan wali kelas" value="Menggunakan Smartphone Saat Jam Belajar Tanpa Izin">Main HP Saat Jam Pelajaran - 20 Poin</option>
                        <option data-type="pelanggaran" data-category="Perilaku" data-points="25" data-action="Surat Peringatan 1 & pembinaan wali kelas" value="Perilaku Tidak Sopan terhadap Tenaga Pendidik / Staf">Tidak Sopan kepada Guru/Staf - 25 Poin</option>
                    </optgroup>
                    <optgroup label="Pelanggaran Berat (50 - 75 Poin)">
                        <option data-type="pelanggaran" data-category="Kedisiplinan Berat" data-points="50" data-action="Surat Peringatan 2 & Pemanggilan Orang Tua ke Sekolah" value="Membawa / Menghisap Rokok atau Vape di Lingkungan Sekolah">Merokok / Vape di Sekolah - 50 Poin</option>
                        <option data-type="pelanggaran" data-category="Perilaku Berat" data-points="75" data-action="Surat Peringatan 3 & Sidang Pleno Skorsing" value="Terlibat Perkelahian Fisik / Tawuran Pelajar">Perkelahian / Tawuran - 75 Poin</option>
                    </optgroup>
                    <optgroup label="Prestasi & Apresiasi">
                        <option data-type="prestasi" data-category="Akademik" data-points="15" data-action="Piagam apresiasi & poin penghargaan akademik" value="Peringkat 1 s.d 3 Paralel Kelas Semester Ini">Juara Kelas Paralel - +15 Poin</option>
                        <option data-type="prestasi" data-category="Olahraga & Seni" data-points="25" data-action="Pemberian piagam & apresiasi di upacara bendera" value="Juara Lomba Tingkat Kota / Kabupaten">Juara Tingkat Kota/Kabupaten - +25 Poin</option>
                        <option data-type="prestasi" data-category="Nasional" data-points="50" data-action="Pemberian beasiswa apresiasi & piagam sekolah" value="Juara Kompetisi Sains / Olahraga Tingkat Nasional">Juara Tingkat Nasional - +50 Poin</option>
                    </optgroup>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Jenis Catatan</label>
                    <select name="type" id="counselingType" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:outline-none">
                        <option value="pelanggaran">Pelanggaran Tata Tertib</option>
                        <option value="prestasi">Prestasi & Apresiasi</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kategori</label>
                    <input type="text" name="category" id="counselingCategory" required placeholder="Contoh: Kedisiplinan / Akademik" value="Kedisiplinan" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Judul Kasus / Capaian Prestasi</label>
                <input type="text" name="title" id="counselingTitle" required placeholder="Contoh: Terlambat upacara bendera 15 menit" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Bobot Poin</label>
                    <input type="number" name="points" id="counselingPoints" required min="1" max="100" value="5" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tanggal Kejadian</label>
                    <input type="date" name="date" required value="<?= date('Y-m-d') ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Tindak Lanjut / Bentuk Apresiasi</label>
                <input type="text" name="action_taken" id="counselingAction" placeholder="Contoh: Peringatan lisan & pembinaan tata tertib" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Catatan Keterangan Tambahan (Opsional)</label>
                <textarea name="notes" rows="2" placeholder="Catatan kronologis atau detail penghargaan..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none"></textarea>
            </div>

            <!-- Integrasi Portal Berita Sekolah -->
            <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 flex items-start gap-3">
                <input type="checkbox" name="auto_create_news" id="autoCreateNews" value="1" class="mt-0.5 rounded border-white/20 bg-slate-950 text-amber-500 focus:ring-0 cursor-pointer">
                <label for="autoCreateNews" class="text-xs text-slate-300 cursor-pointer select-none">
                    <span class="font-bold text-amber-300 flex items-center gap-1.5">
                        <i class="fa-solid fa-bullhorn text-[11px]"></i>
                        Langsung Buat Draf Berita Sekolah (Khusus Prestasi)
                    </span>
                    <p class="text-[11px] text-slate-400 mt-0.5">Jika dicentang dan jenis catatan adalah Prestasi, sistem akan langsung mengarahkan Anda ke formulir publikasi berita dengan judul, siswa, dan kategori yang sudah terisi otomatis.</p>
                </label>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalAddCounseling').classList.add('hidden')" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/5 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 cursor-pointer">
                    Simpan Catatan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function applyRulePreset(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;

    const type = opt.getAttribute('data-type');
    const cat = opt.getAttribute('data-category');
    const pts = opt.getAttribute('data-points');
    const action = opt.getAttribute('data-action');

    if (type) document.getElementById('counselingType').value = type;
    if (cat) document.getElementById('counselingCategory').value = cat;
    if (pts) document.getElementById('counselingPoints').value = pts;
    if (action) document.getElementById('counselingAction').value = action;
    document.getElementById('counselingTitle').value = opt.value;
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
