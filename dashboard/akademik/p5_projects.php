<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['guru', 'administrator', 'staf'], true);

$school_info = getSchoolSettings($pdo);
$academic_year = $school_info['academic_year'] ?? '2026/2027';

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. TAMBAH PROJEK P5 BARU (Guru & Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_project' && $can_manage) {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $title = trim($_POST['title'] ?? '');
        $theme = trim($_POST['theme'] ?? 'Gaya Hidup Berkelanjutan');
        $description = trim($_POST['description'] ?? '');
        $p_year = trim($_POST['academic_year'] ?? $academic_year);
        $phase = trim($_POST['phase'] ?? 'Fase E');
        $target_classes = isset($_POST['target_class_ids']) ? implode(',', array_map('intval', $_POST['target_class_ids'])) : '';
        $coordinator_id = (int)($_POST['coordinator_teacher_id'] ?? $user_id);
        $status = in_array($_POST['status'] ?? 'aktif', ['aktif', 'selesai', 'draf'], true) ? $_POST['status'] : 'aktif';

        if ($title === '' || $theme === '' || $description === '') {
            $message = "Judul projek, tema, dan deskripsi projek wajib diisi.";
            $message_type = "error";
        } else {
            try {
                $stmt_add = $pdo->prepare("
                    INSERT INTO p5_projects (title, theme, description, academic_year, phase, target_class_ids, coordinator_teacher_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_add->execute([$title, $theme, $description, $p_year, $phase, $target_classes, $coordinator_id, $status]);
                $new_proj_id = (int)$pdo->lastInsertId();

                // Tambahkan dimensi default sesuai pilihan
                $sel_dims = $_POST['default_dimensions'] ?? [];
                if (!empty($sel_dims) && is_array($sel_dims)) {
                    $stmt_dim = $pdo->prepare("
                        INSERT INTO p5_project_dimensions (project_id, dimension, element, sub_element, target_capaian)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    foreach ($sel_dims as $dim_name) {
                        if (isset(P5_DIMENSIONS[$dim_name])) {
                            $el_first = P5_DIMENSIONS[$dim_name]['elements'][0] ?? 'Elemen Utama';
                            $stmt_dim->execute([
                                $new_proj_id, 
                                $dim_name, 
                                $el_first, 
                                'Kemandirian & Kolaborasi dalam Projek', 
                                'Mencapai fase perkembangan profil pelajar pancasila sesuai target pembelajaran tingkat sekolah.'
                            ]);
                        }
                    }
                }

                logActivity($pdo, 'CREATE_P5_PROJECT', "Membuat Projek P5 '$title' (Tema: $theme)");
                $message = "Projek P5 baru berhasil ditambahkan! Silakan lengkapi rubrik dimensi dan mulai input penilaian.";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Gagal menyimpan projek: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// 2. TAMBAH DIMENSI & SUB-ELEMEN KE PROJEK
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_dimension' && $can_manage) {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $proj_id = (int)($_POST['project_id'] ?? 0);
        $dimension = trim($_POST['dimension'] ?? '');
        $element = trim($_POST['element'] ?? '');
        $sub_element = trim($_POST['sub_element'] ?? '');
        $target_capaian = trim($_POST['target_capaian'] ?? '');

        if ($proj_id > 0 && $dimension !== '' && $element !== '' && $sub_element !== '') {
            try {
                $stmt_ad = $pdo->prepare("
                    INSERT INTO p5_project_dimensions (project_id, dimension, element, sub_element, target_capaian)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt_ad->execute([$proj_id, $dimension, $element, $sub_element, $target_capaian]);
                logActivity($pdo, 'ADD_P5_DIMENSION', "Menambah rubrik dimensi pada Projek P5 ID $proj_id");
                $message = "Dimensi & sub-elemen rubrik berhasil ditambahkan ke projek!";
                $message_type = "success";
            } catch (Exception $e) {
                $message = "Gagal menambah dimensi: " . $e->getMessage();
                $message_type = "error";
            }
        } else {
            $message = "Dimensi, elemen, dan sub-elemen wajib diisi.";
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 3. HAPUS DIMENSI RUBRIK
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_dimension' && $can_manage) {
    if (validateCsrfToken()) {
        $dim_id = (int)($_POST['dimension_id'] ?? 0);
        if ($dim_id > 0) {
            $stmt_dd = $pdo->prepare("DELETE FROM p5_project_dimensions WHERE id = ?");
            $stmt_dd->execute([$dim_id]);
            $message = "Elemen rubrik projek berhasil dihapus.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 4. HAPUS PROJEK P5
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_project' && $can_manage) {
    if (validateCsrfToken()) {
        $del_proj_id = (int)($_POST['project_id'] ?? 0);
        if ($del_proj_id > 0) {
            $stmt_del = $pdo->prepare("DELETE FROM p5_projects WHERE id = ?");
            $stmt_del->execute([$del_proj_id]);
            logActivity($pdo, 'DELETE_P5_PROJECT', "Menghapus Projek P5 ID $del_proj_id");
            $message = "Projek P5 beserta seluruh rubrik dan penilaian terkait berhasil dihapus.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 5. QUERY DAFTAR PROJEK P5
// -------------------------------------------------------------
$filter_theme = trim($_GET['theme'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT p.*, u.name as coordinator_name, u.nip as coordinator_nip,
           (SELECT COUNT(*) FROM p5_project_dimensions WHERE project_id = p.id) as dimensions_count,
           (SELECT COUNT(DISTINCT student_id) FROM p5_student_assessments WHERE project_id = p.id) as assessed_students_count
    FROM p5_projects p
    LEFT JOIN users u ON p.coordinator_teacher_id = u.id
    WHERE 1=1
";
$params = [];

if ($filter_theme !== '') {
    $sql .= " AND p.theme = ?";
    $params[] = $filter_theme;
}
if ($filter_status !== '') {
    $sql .= " AND p.status = ?";
    $params[] = $filter_status;
}
if ($search !== '') {
    $sql .= " AND (p.title LIKE ? OR p.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$projects = $stmt->fetchAll();

// Daftar semua kelas untuk selector rombel
$all_classes = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();

// Daftar guru untuk selector koordinator
$all_teachers = $pdo->query("SELECT id, name, nip FROM users WHERE role IN ('guru', 'administrator') ORDER BY name ASC")->fetchAll();

$page_title = "Projek Penguatan Profil Pelajar Pancasila (P5)";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-8">

    <!-- Header & Action Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-white/10 pb-6">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold text-emerald-400 mb-1">
                <i class="fa-solid fa-seedling"></i>
                <span>Kurikulum Merdeka BSKAP Kemendikbudristek</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight flex items-center gap-2.5">
                <span>Rapor Projek Profil (P5)</span>
                <span class="rounded-full bg-emerald-500/20 border border-emerald-500/30 px-3 py-0.5 text-xs font-bold text-emerald-300">
                    Fase E & F
                </span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-400 mt-1 max-w-2xl">
                Pengelolaan tema projek, penyusunan rubrik dimensi Profil Pelajar Pancasila, penilaian proses, dan penerbitan Lembar Rapor P5 resmi.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <?php if ($can_manage): ?>
                <button type="button" onclick="document.getElementById('createProjectModal').classList.remove('hidden')"
                        class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-emerald-600/25 transition cursor-pointer flex items-center gap-2 active:scale-95">
                    <i class="fa-solid fa-plus"></i>
                    <span>Buat Projek P5 Baru</span>
                </button>
            <?php endif; ?>

            <a href="report_card.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-xs font-semibold text-slate-300 hover:text-white transition flex items-center gap-2">
                <i class="fa-solid fa-award text-yellow-400"></i>
                <span>E-Rapor Akademik</span>
            </a>
        </div>
    </div>

    <!-- Alert Notifikasi -->
    <?php if ($message !== ""): ?>
        <div class="rounded-2xl border p-4 text-xs flex items-center gap-3 
            <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-950/40 text-emerald-200' : 'border-rose-500/30 bg-rose-950/40 text-rose-200' ?>">
            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-circle-exclamation text-rose-400' ?> text-lg"></i>
            <div><?= htmlspecialchars($message) ?></div>
        </div>
    <?php endif; ?>

    <!-- STATISTIK RINGKAS P5 -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-2xl border border-white/10 bg-slate-900/80 p-4 shadow-lg backdrop-blur">
            <div class="flex items-center justify-between text-slate-400 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider">Total Projek P5</span>
                <span class="h-8 w-8 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-shapes"></i>
                </span>
            </div>
            <div class="text-2xl font-black text-white"><?= count($projects) ?></div>
            <span class="text-[11px] text-slate-400">Tahun <?= htmlspecialchars($academic_year) ?></span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/80 p-4 shadow-lg backdrop-blur">
            <div class="flex items-center justify-between text-slate-400 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider">Tema Nasional</span>
                <span class="h-8 w-8 rounded-xl bg-blue-500/10 text-blue-400 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-earth-asia"></i>
                </span>
            </div>
            <div class="text-2xl font-black text-white"><?= count(P5_THEMES) ?></div>
            <span class="text-[11px] text-slate-400">Tema Standar BSKAP</span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/80 p-4 shadow-lg backdrop-blur">
            <div class="flex items-center justify-between text-slate-400 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider">Dimensi Profil</span>
                <span class="h-8 w-8 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-star"></i>
                </span>
            </div>
            <div class="text-2xl font-black text-white">6</div>
            <span class="text-[11px] text-slate-400">Karakter Pelajar Pancasila</span>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/80 p-4 shadow-lg backdrop-blur">
            <div class="flex items-center justify-between text-slate-400 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider">Rubrik Capaian</span>
                <span class="h-8 w-8 rounded-xl bg-purple-500/10 text-purple-400 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-chart-simple"></i>
                </span>
            </div>
            <div class="text-xs font-bold text-white flex items-center gap-1.5 mt-1">
                <span class="px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300">BB</span>
                <span class="px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300">MB</span>
                <span class="px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300">BSH</span>
                <span class="px-1.5 py-0.5 rounded bg-cyan-500/20 text-cyan-300">SB</span>
            </div>
            <span class="text-[11px] text-slate-400 block mt-1.5">Skala Kualitatif Nasional</span>
        </div>
    </div>

    <!-- FILTER & PENCARIAN -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/70 p-4">
        <form method="GET" class="flex flex-col sm:flex-row items-center gap-3">
            <div class="relative flex-1 w-full">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-3 text-xs text-slate-500"></i>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Cari judul projek atau tema..."
                       class="w-full rounded-xl border border-white/10 bg-slate-950 pl-10 pr-4 py-2 text-xs text-white placeholder-slate-500 outline-none focus:border-emerald-500">
            </div>

            <select name="theme" onchange="this.form.submit()" class="w-full sm:w-64 rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-slate-300 outline-none focus:border-emerald-500">
                <option value="">Semua Tema P5</option>
                <?php foreach (P5_THEMES as $th_key => $th_data): ?>
                    <option value="<?= htmlspecialchars($th_key) ?>" <?= $filter_theme === $th_key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($th_key) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status" onchange="this.form.submit()" class="w-full sm:w-36 rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-slate-300 outline-none focus:border-emerald-500">
                <option value="">Semua Status</option>
                <option value="aktif" <?= $filter_status === 'aktif' ? 'selected' : '' ?>>Aktif</option>
                <option value="selesai" <?= $filter_status === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                <option value="draf" <?= $filter_status === 'draf' ? 'selected' : '' ?>>Draf</option>
            </select>

            <button type="submit" class="w-full sm:w-auto rounded-xl bg-slate-800 hover:bg-slate-700 px-4 py-2 text-xs font-bold text-white transition">
                Filter
            </button>
            <?php if ($filter_theme !== '' || $filter_status !== '' || $search !== ''): ?>
                <a href="p5_projects.php" class="text-xs text-slate-400 hover:text-white px-2 py-1">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- DAFTAR KARTU PROJEK P5 -->
    <?php if (empty($projects)): ?>
        <div class="rounded-3xl border border-white/10 bg-slate-900/50 p-12 text-center">
            <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-500/10 text-emerald-400 text-2xl mb-4">
                <i class="fa-solid fa-seedling"></i>
            </div>
            <h3 class="text-lg font-bold text-white">Belum Ada Projek P5</h3>
            <p class="text-xs text-slate-400 mt-1 max-w-md mx-auto">
                Silakan buat paket Projek Penguatan Profil Pelajar Pancasila baru untuk memulai perancangan tema dan penilaian asesmen.
            </p>
            <?php if ($can_manage): ?>
                <div class="mt-4">
                    <button type="button" onclick="document.getElementById('createProjectModal').classList.remove('hidden')" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-xs font-bold text-white transition">
                        <i class="fa-solid fa-plus mr-1"></i> Buat Projek Sekarang
                    </button>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php foreach ($projects as $p): ?>
                <?php
                $theme_info = P5_THEMES[$p['theme']] ?? [
                    'label' => $p['theme'],
                    'icon' => 'fa-solid fa-seedling',
                    'color' => 'emerald',
                    'desc' => ''
                ];

                // Parse target classes
                $class_badges = [];
                if (!empty($p['target_class_ids'])) {
                    $cids = explode(',', $p['target_class_ids']);
                    foreach ($all_classes as $cls) {
                        if (in_array((string)$cls['id'], $cids, true)) {
                            $class_badges[] = $cls['name'];
                        }
                    }
                }
                ?>
                <div class="rounded-3xl border border-white/10 bg-slate-900/80 p-6 shadow-xl hover:border-emerald-500/40 transition flex flex-col justify-between group">
                    <div class="space-y-4">
                        <!-- Top Bar: Tema & Status -->
                        <div class="flex items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-300">
                                <i class="<?= $theme_info['icon'] ?>"></i>
                                <span><?= htmlspecialchars($p['theme']) ?></span>
                            </span>
                            <span class="rounded-md border border-white/10 bg-white/5 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-300">
                                <?= htmlspecialchars($p['phase']) ?> • <?= htmlspecialchars($p['academic_year']) ?>
                            </span>
                        </div>

                        <!-- Judul Projek -->
                        <div>
                            <h3 class="text-lg font-bold text-white group-hover:text-emerald-300 transition">
                                <?= htmlspecialchars($p['title']) ?>
                            </h3>
                            <p class="text-xs text-slate-400 mt-1.5 line-clamp-3 leading-relaxed">
                                <?= htmlspecialchars($p['description']) ?>
                            </p>
                        </div>

                        <!-- Metadata Grid -->
                        <div class="grid grid-cols-2 gap-3 p-3.5 rounded-2xl bg-slate-950/60 border border-white/5 text-xs text-slate-300">
                            <div>
                                <span class="block text-[10px] text-slate-500 uppercase font-bold">Koordinator:</span>
                                <span class="font-medium text-white truncate block" title="<?= htmlspecialchars($p['coordinator_name']) ?>">
                                    <?= htmlspecialchars($p['coordinator_name'] ?: 'Belum Ditugaskan') ?>
                                </span>
                            </div>
                            <div>
                                <span class="block text-[10px] text-slate-500 uppercase font-bold">Rubrik Sasaran:</span>
                                <span class="font-bold text-emerald-400"><?= (int)$p['dimensions_count'] ?> Sub-elemen Dimensi</span>
                            </div>
                            <div class="col-span-2">
                                <span class="block text-[10px] text-slate-500 uppercase font-bold mb-1">Rombel Sasaran:</span>
                                <div class="flex flex-wrap gap-1">
                                    <?php if (!empty($class_badges)): ?>
                                        <?php foreach ($class_badges as $cb): ?>
                                            <span class="px-2 py-0.5 rounded bg-white/5 border border-white/10 text-[10px] font-medium text-slate-300">
                                                <?= htmlspecialchars($cb) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-[10px] text-slate-500">Semua Rombel Tingkat Terkait</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons Footer -->
                    <div class="mt-6 pt-4 border-t border-white/10 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <?php if ($can_manage): ?>
                                <a href="p5_assessment.php?project_id=<?= $p['id'] ?>"
                                   class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-3.5 py-2 text-xs font-bold text-white shadow-md shadow-emerald-600/20 transition flex items-center gap-1.5">
                                    <i class="fa-solid fa-clipboard-check"></i>
                                    <span>Input Penilaian</span>
                                </a>
                            <?php endif; ?>

                            <a href="p5_report.php?project_id=<?= $p['id'] ?>"
                               class="rounded-xl border border-blue-500/30 bg-blue-500/10 hover:bg-blue-500/20 px-3.5 py-2 text-xs font-bold text-blue-300 transition flex items-center gap-1.5">
                                <i class="fa-solid fa-file-invoice"></i>
                                <span>Cetak Rapor P5</span>
                            </a>
                        </div>

                        <?php if ($can_manage): ?>
                            <div class="flex items-center gap-1.5">
                                <button type="button" onclick="openDimensionsModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['title'])) ?>')"
                                        class="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition text-xs" title="Kelola Dimensi & Sub-Elemen Rubrik">
                                    <i class="fa-solid fa-list-check"></i>
                                </button>

                                <form method="POST" class="inline" onsubmit="return confirm('Hapus Projek P5 ini beserta seluruh data rubrik dan penilaian yang sudah masuk?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_project">
                                    <input type="hidden" name="project_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="p-2 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 border border-rose-500/20 text-rose-400 transition text-xs cursor-pointer" title="Hapus Projek">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ============================================================== -->
<!-- MODAL: BUAT PROJEK P5 BARU                                      -->
<!-- ============================================================== -->
<div id="createProjectModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm overflow-y-auto">
    <div class="relative w-full max-w-2xl rounded-3xl border border-white/15 bg-slate-900 p-6 sm:p-8 shadow-2xl my-8">
        <div class="flex items-center justify-between mb-5 border-b border-white/10 pb-4">
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-seedling text-emerald-400"></i> Buat Paket Projek P5 Baru
            </h2>
            <button onclick="document.getElementById('createProjectModal').classList.add('hidden')" 
                    class="rounded-lg p-1.5 text-slate-400 hover:text-white hover:bg-white/10 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="p5_projects.php" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_project">

            <!-- Tema Projek P5 -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Tema Projek Nasional (Kurikulum Merdeka) <span class="text-rose-400">*</span>
                </label>
                <select name="theme" required class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2.5 text-xs text-white focus:border-emerald-500 focus:outline-none">
                    <?php foreach (P5_THEMES as $th_key => $th_data): ?>
                        <option value="<?= htmlspecialchars($th_key) ?>">
                            <?= htmlspecialchars($th_key) ?> — <?= htmlspecialchars($th_data['desc']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Judul Projek -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Judul Projek P5 <span class="text-rose-400">*</span>
                </label>
                <input type="text" name="title" required placeholder="Contoh: Pengolahan Sampah Organik Menjadi Kompos Berkualitas" 
                       class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2.5 text-xs text-white focus:border-emerald-500 focus:outline-none">
            </div>

            <!-- Grid: Fase, Tahun Ajaran, Koordinator -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        Fase Pembelajaran
                    </label>
                    <select name="phase" class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none">
                        <option value="Fase E">Fase E (Kelas 10)</option>
                        <option value="Fase F">Fase F (Kelas 11 - 12)</option>
                        <option value="Fase D">Fase D (SMP/Mts)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        Tahun Ajaran
                    </label>
                    <input type="text" name="academic_year" value="<?= htmlspecialchars($academic_year) ?>" required
                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        Koordinator Guru
                    </label>
                    <select name="coordinator_teacher_id" class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none">
                        <?php foreach ($all_teachers as $tch): ?>
                            <option value="<?= $tch['id'] ?>" <?= $tch['id'] == $user_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tch['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Rombel Sasaran (Checkboxes) -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Pilih Rombongan Belajar Sasaran Projek:
                </label>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 p-3 rounded-2xl bg-slate-950/60 border border-white/10 max-h-36 overflow-y-auto">
                    <?php foreach ($all_classes as $cls): ?>
                        <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer p-1 rounded hover:bg-white/5">
                            <input type="checkbox" name="target_class_ids[]" value="<?= $cls['id'] ?>" checked class="rounded border-white/20 bg-slate-900 text-emerald-500 focus:ring-emerald-500">
                            <span><?= htmlspecialchars($cls['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Deskripsi Singkat Pelaksanaan Projek -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Deskripsi Ringkas & Alur Projek <span class="text-rose-400">*</span>
                </label>
                <textarea name="description" rows="3" required placeholder="Tuliskan latar belakang masalah, tujuan pembelajaran, dan ringkasan aktivitas nyata yang dikerjakan peserta didik dalam projek ini..."
                          class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none"></textarea>
                <span class="text-[10px] text-slate-400 mt-1 block">Deskripsi ini akan tercetak pada Lembar Rapor P5 resmi Kemendikbudristek.</span>
            </div>

            <!-- Pilih Dimensi Awal (Opsional) -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Dimensi Profil Sasaran Awal:
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-slate-300">
                    <?php foreach (P5_DIMENSIONS as $d_key => $d_val): ?>
                        <label class="flex items-center gap-2 p-2 rounded-xl bg-white/5 border border-white/5 hover:border-emerald-500/30 cursor-pointer">
                            <input type="checkbox" name="default_dimensions[]" value="<?= htmlspecialchars($d_key) ?>" checked class="rounded border-white/20 bg-slate-900 text-emerald-500 focus:ring-emerald-500">
                            <span class="truncate"><?= htmlspecialchars($d_key) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('createProjectModal').classList.add('hidden')"
                        class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2.5 text-xs font-bold text-white shadow-lg shadow-emerald-600/25 transition">
                    Simpan Projek P5
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: KELOLA DIMENSI & SUB-ELEMEN RUBRIK                       -->
<!-- ============================================================== -->
<div id="dimensionsModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm overflow-y-auto">
    <div class="relative w-full max-w-2xl rounded-3xl border border-white/15 bg-slate-900 p-6 sm:p-8 shadow-2xl my-8">
        <div class="flex items-center justify-between mb-5 border-b border-white/10 pb-4">
            <div>
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-list-check text-blue-400"></i> Tambah Rubrik Dimensi Projek
                </h2>
                <p id="modalProjectTitle" class="text-xs text-slate-400 mt-0.5 font-medium"></p>
            </div>
            <button onclick="document.getElementById('dimensionsModal').classList.add('hidden')" 
                    class="rounded-lg p-1.5 text-slate-400 hover:text-white hover:bg-white/10 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="p5_projects.php" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_dimension">
            <input type="hidden" name="project_id" id="dimModalProjectId" value="">

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Dimensi Profil Pelajar Pancasila <span class="text-rose-400">*</span>
                </label>
                <select name="dimension" id="selectDimension" required onchange="populateDimensionElements(this.value)"
                        class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                    <?php foreach (P5_DIMENSIONS as $d_key => $d_val): ?>
                        <option value="<?= htmlspecialchars($d_key) ?>"><?= htmlspecialchars($d_key) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        Elemen Dimensi <span class="text-rose-400">*</span>
                    </label>
                    <input type="text" name="element" id="elementInput" required placeholder="Contoh: Akhlak kepada Alam"
                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        Sub-Elemen Sasaran <span class="text-rose-400">*</span>
                    </label>
                    <input type="text" name="sub_element" required placeholder="Contoh: Menjaga Lingkungan Alam Sekitar"
                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Capaian Akhir Fase (Target Rubrik)
                </label>
                <textarea name="target_capaian" rows="2" placeholder="Tuliskan indikator capaian fase yang diharapkan dicapai peserta didik..."
                          class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2 text-xs text-white focus:border-blue-500 focus:outline-none"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('dimensionsModal').classList.add('hidden')"
                        class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-600/25 transition">
                    Tambahkan Rubrik
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const P5_DIMENSIONS_DATA = <?= json_encode(P5_DIMENSIONS) ?>;

function openDimensionsModal(projectId, projectTitle) {
    document.getElementById('dimModalProjectId').value = projectId;
    document.getElementById('modalProjectTitle').innerText = projectTitle;
    document.getElementById('dimensionsModal').classList.remove('hidden');
    const sel = document.getElementById('selectDimension');
    if (sel) populateDimensionElements(sel.value);
}

function populateDimensionElements(dimKey) {
    const elInput = document.getElementById('elementInput');
    if (elInput && P5_DIMENSIONS_DATA[dimKey] && P5_DIMENSIONS_DATA[dimKey].elements.length > 0) {
        elInput.value = P5_DIMENSIONS_DATA[dimKey].elements[0];
    }
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
