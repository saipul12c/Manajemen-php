<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['guru', 'administrator', 'staf'], true);

// Jika siswa atau orang tua, arahkan langsung ke halaman cetak/lihat rapor P5
if (!$can_manage) {
    header("Location: p5_report.php");
    exit;
}

$school_info = getSchoolSettings($pdo);
$academic_year = $school_info['academic_year'] ?? '2026/2027';

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. PROSES SIMPAN MASSAL ASESMEN P5
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_batch_assessment') {
    if (!validateCsrfToken()) {
        $message = "Sesi token keamanan kedaluwarsa. Silakan muat ulang halaman.";
        $message_type = "error";
    } else {
        $post_proj_id = (int)($_POST['project_id'] ?? 0);
        $post_class_id = (int)($_POST['class_id'] ?? 0);
        $assessments = $_POST['assessments'] ?? []; // [student_id => [dimension_id => 'BSH']]
        $notes = $_POST['notes'] ?? []; // [student_id => 'teks catatan']

        if ($post_proj_id <= 0 || $post_class_id <= 0) {
            $message = "Projek dan rombongan belajar (kelas) wajib dipilih.";
            $message_type = "error";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_save_as = $pdo->prepare("
                    INSERT INTO p5_student_assessments (project_id, dimension_id, student_id, predicate, evaluated_by)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        predicate = VALUES(predicate),
                        evaluated_by = VALUES(evaluated_by),
                        updated_at = NOW()
                ");

                $stmt_save_note = $pdo->prepare("
                    INSERT INTO p5_student_notes (project_id, student_id, facilitator_notes, evaluated_by)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        facilitator_notes = VALUES(facilitator_notes),
                        evaluated_by = VALUES(evaluated_by),
                        updated_at = NOW()
                ");

                $valid_preds = ['BB', 'MB', 'BSH', 'SB'];
                $saved_count = 0;

                // Simpan nilai rubrik dimensi
                if (is_array($assessments)) {
                    foreach ($assessments as $stu_id => $dim_grades) {
                        $stu_id = (int)$stu_id;
                        if ($stu_id <= 0 || !is_array($dim_grades)) continue;

                        foreach ($dim_grades as $dim_id => $pred) {
                            $dim_id = (int)$dim_id;
                            if ($dim_id <= 0) continue;
                            $pred = in_array($pred, $valid_preds, true) ? $pred : 'BSH';
                            $stmt_save_as->execute([$post_proj_id, $dim_id, $stu_id, $pred, $user_id]);
                            $saved_count++;
                        }
                    }
                }

                // Simpan catatan fasilitator projek
                if (is_array($notes)) {
                    foreach ($notes as $stu_id => $note_text) {
                        $stu_id = (int)$stu_id;
                        if ($stu_id <= 0) continue;
                        $note_text = trim($note_text);
                        $stmt_save_note->execute([$post_proj_id, $stu_id, $note_text, $user_id]);
                    }
                }

                $pdo->commit();
                logActivity($pdo, 'P5_ASSESSMENT_SAVED', "Menyimpan $saved_count butir asesmen Projek ID $post_proj_id untuk Kelas ID $post_class_id");
                $message = "Berhasil menyimpan penilaian rubrik dimensi dan catatan fasilitator Projek P5!";
                $message_type = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Gagal menyimpan asesmen: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// 2. AMBIL DAFTAR PROJEK & KELAS TERSEDIA
// -------------------------------------------------------------
$projects = $pdo->query("
    SELECT p.*, u.name as coordinator_name 
    FROM p5_projects p 
    LEFT JOIN users u ON p.coordinator_teacher_id = u.id 
    ORDER BY p.id DESC
")->fetchAll();

$selected_project_id = isset($_GET['project_id']) && is_numeric($_GET['project_id']) 
    ? (int)$_GET['project_id'] 
    : (isset($projects[0]['id']) ? (int)$projects[0]['id'] : 0);

// Ambil detail projek terpilih
$active_project = null;
foreach ($projects as $pr) {
    if ((int)$pr['id'] === $selected_project_id) {
        $active_project = $pr;
        break;
    }
}

// Ambil dimensi rubrik untuk projek terpilih
$project_dimensions = [];
if ($selected_project_id > 0) {
    $stmt_dim = $pdo->prepare("SELECT * FROM p5_project_dimensions WHERE project_id = ? ORDER BY id ASC");
    $stmt_dim->execute([$selected_project_id]);
    $project_dimensions = $stmt_dim->fetchAll();
}

// Ambil daftar kelas yang relevan dengan target projek
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();
$target_class_ids = [];
if (!empty($active_project['target_class_ids'])) {
    $target_class_ids = array_filter(array_map('intval', explode(',', $active_project['target_class_ids'])));
}

// Resolusi kelas terpilih
$selected_class_id = isset($_GET['class_id']) && is_numeric($_GET['class_id']) 
    ? (int)$_GET['class_id'] 
    : (!empty($target_class_ids) ? $target_class_ids[0] : (isset($classes[0]['id']) ? (int)$classes[0]['id'] : 0));

// -------------------------------------------------------------
// 3. AMBIL SISWA & ASESMEN YANG SUDAH TEREKAM
// -------------------------------------------------------------
$students = [];
$existing_assessments = []; // [student_id => [dimension_id => predicate]]
$existing_notes = [];       // [student_id => facilitator_notes]

if ($selected_class_id > 0) {
    $stmt_stu = $pdo->prepare("
        SELECT id, name, nisn, username 
        FROM users 
        WHERE role = 'siswa' AND class_id = ? 
        ORDER BY name ASC
    ");
    $stmt_stu->execute([$selected_class_id]);
    $students = $stmt_stu->fetchAll();

    if ($selected_project_id > 0 && !empty($students)) {
        // Ambil asesmen dimensi
        $stmt_ea = $pdo->prepare("
            SELECT student_id, dimension_id, predicate 
            FROM p5_student_assessments 
            WHERE project_id = ?
        ");
        $stmt_ea->execute([$selected_project_id]);
        while ($row = $stmt_ea->fetch()) {
            $existing_assessments[$row['student_id']][$row['dimension_id']] = $row['predicate'];
        }

        // Ambil catatan fasilitator
        $stmt_en = $pdo->prepare("
            SELECT student_id, facilitator_notes 
            FROM p5_student_notes 
            WHERE project_id = ?
        ");
        $stmt_en->execute([$selected_project_id]);
        while ($row = $stmt_en->fetch()) {
            $existing_notes[$row['student_id']] = $row['facilitator_notes'];
        }
    }
}

// Statistik kelengkapan asesmen
$total_students = count($students);
$assessed_students = 0;
if ($total_students > 0 && !empty($project_dimensions)) {
    foreach ($students as $stu) {
        $sid = $stu['id'];
        $dim_count_assessed = isset($existing_assessments[$sid]) ? count($existing_assessments[$sid]) : 0;
        if ($dim_count_assessed >= count($project_dimensions)) {
            $assessed_students++;
        }
    }
}
$completion_pct = $total_students > 0 ? round(($assessed_students / $total_students) * 100) : 0;

$page_title = "Penilaian Projek P5 (Kurikulum Merdeka)";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">
    <!-- Header Page & Breadcrumb -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-slate-400 mb-1">
                <a href="../index.php" class="hover:text-blue-400">Dashboard</a>
                <span>/</span>
                <a href="p5_projects.php" class="hover:text-blue-400">Projek P5</a>
                <span>/</span>
                <span class="text-slate-200">Input Penilaian Rubrik</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-purple-500/20 text-purple-400 ring-1 ring-purple-500/30">
                    <i class="fa-solid fa-clipboard-check text-lg"></i>
                </span>
                Penilaian Rubrik Dimensi Projek P5
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Penilaian otentik capaian Profil Pelajar Pancasila berdasarkan rubrik standar BSKAP Kemendikbudristek.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="p5_projects.php" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-slate-900/80 px-3.5 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 hover:text-white transition">
                <i class="fa-solid fa-list-check"></i> Kelola Projek
            </a>
            <?php if ($selected_class_id > 0 && $selected_project_id > 0): ?>
                <a href="p5_report.php?project_id=<?= $selected_project_id ?>&class_id=<?= $selected_class_id ?>" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl border border-blue-500/30 bg-blue-500/10 hover:bg-blue-500/20 px-3.5 py-2 text-xs font-bold text-blue-400 transition">
                    <i class="fa-solid fa-print"></i> Cetak Rapor Kelas Ini
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Feedback Message -->
    <?php if (!empty($message)): ?>
        <div class="rounded-2xl border p-4 text-xs font-medium flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
            <div class="flex items-center gap-2.5">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-circle-exclamation text-rose-400' ?> text-base"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <!-- Filter Bar: Pilih Projek & Pilih Rombel -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/90 p-4 backdrop-blur shadow-xl">
        <form method="GET" action="p5_assessment.php" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            <!-- Pilih Projek -->
            <div class="md:col-span-6 space-y-1">
                <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Pilih Projek P5:</label>
                <select name="project_id" onchange="this.form.submit()" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:outline-none focus:border-blue-500">
                    <?php if (empty($projects)): ?>
                        <option value="">Belum ada projek dibuat</option>
                    <?php else: ?>
                        <?php foreach ($projects as $pr): ?>
                            <option value="<?= $pr['id'] ?>" <?= ($selected_project_id === (int)$pr['id']) ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($pr['phase']) ?>] <?= htmlspecialchars($pr['title']) ?> (<?= htmlspecialchars($pr['theme']) ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Pilih Kelas / Rombel -->
            <div class="md:col-span-4 space-y-1">
                <label class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Rombongan Belajar (Kelas):</label>
                <select name="class_id" onchange="this.form.submit()" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:outline-none focus:border-blue-500">
                    <?php if (empty($classes)): ?>
                        <option value="">Belum ada rombel</option>
                    <?php else: ?>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?= $cl['id'] ?>" <?= ($selected_class_id === (int)$cl['id']) ? 'selected' : '' ?>>
                                Kelas <?= htmlspecialchars($cl['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Tombol Muat -->
            <div class="md:col-span-2">
                <button type="submit" class="w-full inline-flex items-center justify-center gap-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/20 transition cursor-pointer">
                    <i class="fa-solid fa-arrows-rotate"></i> Muat Data
                </button>
            </div>
        </form>
    </div>

    <?php if (!$active_project): ?>
        <div class="rounded-3xl border border-amber-500/30 bg-amber-500/10 p-8 text-center">
            <i class="fa-solid fa-triangle-exclamation text-3xl text-amber-400 mb-2"></i>
            <h3 class="text-base font-bold text-white">Belum Ada Projek P5 yang Aktif</h3>
            <p class="text-xs text-slate-400 mt-1">Silakan tambahkan projek penguatan profil pelajar pancasila terlebih dahulu di modul kelola projek.</p>
            <a href="p5_projects.php" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-blue-600 px-4 py-2 text-xs font-bold text-white hover:bg-blue-500">
                <i class="fa-solid fa-plus"></i> Buat Projek P5 Sekarang
            </a>
        </div>
    <?php elseif (empty($project_dimensions)): ?>
        <div class="rounded-3xl border border-rose-500/30 bg-rose-500/10 p-8 text-center">
            <i class="fa-solid fa-shapes text-3xl text-rose-400 mb-2"></i>
            <h3 class="text-base font-bold text-white">Dimensi Rubrik Belum Dikonfigurasi</h3>
            <p class="text-xs text-slate-400 mt-1 max-w-lg mx-auto">
                Projek "<strong><?= htmlspecialchars($active_project['title']) ?></strong>" belum memiliki daftar dimensi rubrik capaian profil pelajar pancasila. Tambahkan dimensi terlebih dahulu agar guru dapat memberikan penilaian.
            </p>
            <a href="p5_projects.php" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-rose-600 px-4 py-2 text-xs font-bold text-white hover:bg-rose-500">
                <i class="fa-solid fa-sliders"></i> Atur Dimensi di Projek P5
            </a>
        </div>
    <?php else: ?>

        <!-- Kartu Ringkasan Projek & Progress Asesmen -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
            <!-- Info Projek -->
            <div class="lg:col-span-8 rounded-2xl border border-white/10 bg-slate-900/80 p-5 backdrop-blur">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="rounded-lg bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-2.5 py-0.5 text-[11px] font-bold">
                        <i class="fa-solid fa-certificate"></i> <?= htmlspecialchars($active_project['theme']) ?>
                    </span>
                    <span class="rounded-lg bg-purple-500/20 text-purple-400 border border-purple-500/30 px-2 py-0.5 text-[11px] font-bold">
                        <?= htmlspecialchars($active_project['phase']) ?>
                    </span>
                    <span class="rounded-lg bg-blue-500/20 text-blue-400 border border-blue-500/30 px-2 py-0.5 text-[11px] font-bold">
                        T.A. <?= htmlspecialchars($active_project['academic_year']) ?>
                    </span>
                    <span class="text-xs text-slate-400 ml-auto">
                        Koordinator: <strong class="text-white"><?= htmlspecialchars($active_project['coordinator_name'] ?: 'Fasilitator') ?></strong>
                    </span>
                </div>
                <h2 class="text-base font-bold text-white mb-1.5">
                    <?= htmlspecialchars($active_project['title']) ?>
                </h2>
                <p class="text-xs text-slate-300 leading-relaxed">
                    <?= htmlspecialchars($active_project['description']) ?>
                </p>

                <!-- Dimensi yang dinilai -->
                <div class="mt-4 pt-3 border-t border-white/10">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-2">
                        <?= count($project_dimensions) ?> Butir Dimensi & Sub-Elemen Rubrik:
                    </span>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($project_dimensions as $idx => $dm): ?>
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/5 px-2.5 py-1 text-[11px] text-slate-300">
                                <span class="h-1.5 w-1.5 rounded-full bg-cyan-400"></span>
                                <strong>D<?= $idx + 1 ?>:</strong> <?= htmlspecialchars($dm['sub_element']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Kartu Progress -->
            <div class="lg:col-span-4 rounded-2xl border border-white/10 bg-slate-900/80 p-5 backdrop-blur flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Status Penilaian Rombel</span>
                        <span class="text-xs font-bold text-blue-400"><?= $completion_pct ?>% Selesai</span>
                    </div>
                    <div class="h-3 w-full rounded-full bg-slate-800 overflow-hidden p-0.5 ring-1 ring-white/10">
                        <div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-emerald-400 transition-all duration-500" style="width: <?= $completion_pct ?>%"></div>
                    </div>
                    <div class="mt-3 flex items-center justify-between text-xs text-slate-300">
                        <span>Siswa Dinilai Lengkap:</span>
                        <strong class="text-emerald-400"><?= $assessed_students ?> / <?= $total_students ?> Siswa</strong>
                    </div>
                </div>

                <!-- Panduan Rubrik Predikat BSKAP -->
                <div class="mt-4 pt-3 border-t border-white/10">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-1.5">Standar Predikat Rubrik BSKAP:</span>
                    <div class="grid grid-cols-2 gap-1.5 text-[10px]">
                        <div class="flex items-center gap-1.5 rounded-md bg-rose-500/10 border border-rose-500/20 px-2 py-1 text-rose-300">
                            <strong>BB</strong> : Belum Berkembang
                        </div>
                        <div class="flex items-center gap-1.5 rounded-md bg-amber-500/10 border border-amber-500/20 px-2 py-1 text-amber-300">
                            <strong>MB</strong> : Mulai Berkembang
                        </div>
                        <div class="flex items-center gap-1.5 rounded-md bg-emerald-500/10 border border-emerald-500/20 px-2 py-1 text-emerald-300">
                            <strong>BSH</strong> : Berkembang Sesuai Harapan
                        </div>
                        <div class="flex items-center gap-1.5 rounded-md bg-cyan-500/10 border border-cyan-500/20 px-2 py-1 text-cyan-300">
                            <strong>SB</strong> : Sangat Berkembang
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FORM ASESMEN MASSAL -->
        <form method="POST" action="p5_assessment.php?project_id=<?= $selected_project_id ?>&class_id=<?= $selected_class_id ?>" id="formAssessment">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_batch_assessment">
            <input type="hidden" name="project_id" value="<?= $selected_project_id ?>">
            <input type="hidden" name="class_id" value="<?= $selected_class_id ?>">

            <!-- Quick Action Toolbar -->
            <div class="sticky top-2 z-20 rounded-2xl border border-white/15 bg-slate-900/95 p-3.5 shadow-2xl backdrop-blur flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-bold text-white flex items-center gap-1.5">
                        <i class="fa-solid fa-wand-magic-sparkles text-amber-400"></i> Aksi Cepat:
                    </span>
                    <button type="button" onclick="setAllPredicates('BSH')" class="inline-flex items-center gap-1 rounded-lg border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-2.5 py-1 text-[11px] font-bold text-emerald-300 transition cursor-pointer">
                        <i class="fa-solid fa-check-double"></i> Set Semua "BSH" (Standar Fase)
                    </button>
                    <button type="button" onclick="setAllPredicates('SB')" class="inline-flex items-center gap-1 rounded-lg border border-cyan-500/30 bg-cyan-500/10 hover:bg-cyan-500/20 px-2.5 py-1 text-[11px] font-bold text-cyan-300 transition cursor-pointer">
                        <i class="fa-solid fa-star"></i> Set Semua "SB"
                    </button>
                    <button type="button" onclick="autoGenerateNotes()" class="inline-flex items-center gap-1 rounded-lg border border-purple-500/30 bg-purple-500/10 hover:bg-purple-500/20 px-2.5 py-1 text-[11px] font-bold text-purple-300 transition cursor-pointer">
                        <i class="fa-solid fa-comment-dots"></i> Rekomendasi Catatan Otomatis
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <button type="reset" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs text-slate-300 transition cursor-pointer">
                        Reset
                    </button>
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-emerald-500/25 transition cursor-pointer">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan Penilaian P5
                    </button>
                </div>
            </div>

            <!-- Daftar Siswa untuk Dinilai -->
            <?php if (empty($students)): ?>
                <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-8 text-center">
                    <i class="fa-solid fa-users-slash text-3xl text-slate-500 mb-2"></i>
                    <h3 class="text-sm font-bold text-white">Tidak Ada Siswa di Kelas Ini</h3>
                    <p class="text-xs text-slate-400 mt-1">Silakan pilih rombongan belajar lain atau tambahkan siswa ke kelas ini.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($students as $stu_idx => $stu): 
                        $sid = (int)$stu['id'];
                        $cur_note = $existing_notes[$sid] ?? '';
                    ?>
                        <div class="rounded-2xl border border-white/10 bg-slate-900/80 p-5 backdrop-blur transition hover:border-white/20 student-card" id="student-card-<?= $sid ?>">
                            <!-- Baris Info Siswa -->
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 border-b border-white/10">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400 font-bold text-xs ring-1 ring-blue-500/30">
                                        <?= $stu_idx + 1 ?>
                                    </span>
                                    <div>
                                        <h3 class="text-sm font-bold text-white flex items-center gap-2">
                                            <?= htmlspecialchars($stu['name']) ?>
                                            <span class="text-[10px] font-mono font-normal text-slate-400 px-1.5 py-0.5 rounded bg-white/5">
                                                NISN: <?= htmlspecialchars($stu['nisn'] ?: '-') ?>
                                            </span>
                                        </h3>
                                        <span class="text-[11px] text-slate-400">Username: @<?= htmlspecialchars($stu['username']) ?></span>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <a href="p5_report.php?student_id=<?= $sid ?>&project_id=<?= $selected_project_id ?>" target="_blank" class="inline-flex items-center gap-1 rounded-xl border border-blue-500/30 bg-blue-500/10 hover:bg-blue-500/20 px-3 py-1.5 text-[11px] font-bold text-blue-400 transition">
                                        <i class="fa-solid fa-file-invoice"></i> Lihat / Cetak Rapor P5
                                    </a>
                                </div>
                            </div>

                            <!-- Matriks Penilaian Butir Dimensi Rubrik -->
                            <div class="mt-4 space-y-3">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block">
                                    Capaian Rubrik per Dimensi:
                                </span>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <?php foreach ($project_dimensions as $dim_idx => $dm): 
                                        $did = (int)$dm['id'];
                                        $active_pred = $existing_assessments[$sid][$did] ?? 'BSH';
                                    ?>
                                        <div class="rounded-xl border border-white/5 bg-slate-950/60 p-3.5 space-y-2.5">
                                            <div>
                                                <div class="flex items-center justify-between text-[11px] font-bold text-slate-300">
                                                    <span class="text-cyan-400"><?= htmlspecialchars($dm['dimension']) ?></span>
                                                    <span class="text-[10px] text-slate-400">Elemen: <?= htmlspecialchars($dm['element']) ?></span>
                                                </div>
                                                <p class="text-xs font-semibold text-white mt-0.5">
                                                    <?= htmlspecialchars($dm['sub_element']) ?>
                                                </p>
                                                <?php if (!empty($dm['target_capaian'])): ?>
                                                    <p class="text-[10px] text-slate-400 mt-1 italic leading-tight">
                                                        "<?= htmlspecialchars($dm['target_capaian']) ?>"
                                                    </p>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Opsi Predikat BB, MB, BSH, SB -->
                                            <div class="grid grid-cols-4 gap-1.5 pt-1">
                                                <?php 
                                                $p_options = [
                                                    'BB'  => ['label' => 'BB', 'name' => 'Belum Berkembang', 'active_cls' => 'bg-rose-500 text-white shadow-rose-500/30'],
                                                    'MB'  => ['label' => 'MB', 'name' => 'Mulai Berkembang', 'active_cls' => 'bg-amber-500 text-slate-950 font-black shadow-amber-500/30'],
                                                    'BSH' => ['label' => 'BSH', 'name' => 'Sesuai Harapan', 'active_cls' => 'bg-emerald-500 text-white shadow-emerald-500/30'],
                                                    'SB'  => ['label' => 'SB', 'name' => 'Sangat Berkembang', 'active_cls' => 'bg-cyan-500 text-slate-950 font-black shadow-cyan-500/30']
                                                ];
                                                foreach ($p_options as $p_code => $p_cfg): 
                                                    $is_checked = ($active_pred === $p_code);
                                                ?>
                                                    <label class="cursor-pointer">
                                                        <input type="radio" 
                                                               name="assessments[<?= $sid ?>][<?= $did ?>]" 
                                                               value="<?= $p_code ?>" 
                                                               <?= $is_checked ? 'checked' : '' ?> 
                                                               class="sr-only peer assessment-radio"
                                                               data-student-id="<?= $sid ?>"
                                                               data-dimension-id="<?= $did ?>"
                                                               data-code="<?= $p_code ?>">
                                                        <div class="rounded-lg border border-white/10 bg-white/5 py-1.5 text-center text-xs font-bold text-slate-400 transition hover:bg-white/10 peer-checked:<?= $p_cfg['active_cls'] ?> peer-checked:border-transparent peer-checked:shadow-md" title="<?= $p_cfg['name'] ?>">
                                                            <?= $p_code ?>
                                                        </div>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Catatan Naratif Proses Fasilitator -->
                            <div class="mt-4 pt-3 border-t border-white/10 space-y-2">
                                <div class="flex items-center justify-between">
                                    <label for="note_<?= $sid ?>" class="text-[11px] font-bold uppercase tracking-wider text-slate-400 flex items-center gap-1.5">
                                        <i class="fa-solid fa-comment-pen text-blue-400"></i> Catatan Proses Fasilitator Projek:
                                    </label>
                                    <span class="text-[10px] text-slate-400 italic">Muncul di lembar Rapor P5 resmi</span>
                                </div>
                                <textarea id="note_<?= $sid ?>" 
                                          name="notes[<?= $sid ?>]" 
                                          rows="2" 
                                          placeholder="Tuliskan perkembangan karakter, keterlibatan, kemandirian kerja, dan kolaborasi ananda selama mengikuti projek ini..." 
                                          class="w-full rounded-xl border border-white/10 bg-slate-950/80 p-3 text-xs text-slate-200 placeholder-slate-500 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500/50 student-note"><?= htmlspecialchars($cur_note) ?></textarea>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Bottom Sticky Submit Bar -->
                <div class="sticky bottom-4 z-20 rounded-2xl border border-white/20 bg-slate-900/95 p-4 shadow-2xl backdrop-blur flex items-center justify-between">
                    <span class="text-xs text-slate-300">
                        Total <strong><?= $total_students ?></strong> siswa pada rombel ini siap dinilai.
                    </span>
                    <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-6 py-2.5 text-xs font-bold text-white shadow-xl shadow-emerald-500/30 transition cursor-pointer">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan Semua Penilaian Rombel
                    </button>
                </div>
            <?php endif; ?>
        </form>

    <?php endif; ?>
</div>

<script>
// Helper: Set semua predikat radio ke kode tertentu (misal BSH atau SB)
function setAllPredicates(code) {
    const radios = document.querySelectorAll(`input.assessment-radio[value="${code}"]`);
    radios.forEach(radio => {
        radio.checked = true;
    });
}

// Helper: Auto generate rekomendasi catatan naratif positif fasilitator
function autoGenerateNotes() {
    const studentCards = document.querySelectorAll('.student-card');
    const positiveTemplates = [
        "Menunjukkan antusiasme tinggi selama tahapan projek, sangat aktif bergotong royong, dan mampu memberikan solusi inovatif dalam penyelesaian tugas kelompok.",
        "Sangat mandiri dan konsisten dalam merencanakan serta mengeksekusi ide projek. Berani mengemukakan penalaran kritis dan menghargai pandangan rekan sekelompok.",
        "Menunjukkan perkembangan karakter yang sangat positif, memiliki empati tinggi terhadap lingkungan sosial, serta terampil mengomunikasikan hasil projek secara percaya diri.",
        "Konsisten menjaga etos kerja dan kedisiplinan tim. Mampu mengintegrasikan nilai-nilai Profil Pelajar Pancasila dengan sangat baik sepanjang rangkaian projek."
    ];

    studentCards.forEach((card, idx) => {
        const textarea = card.querySelector('.student-note');
        if (textarea && textarea.value.trim() === '') {
            textarea.value = positiveTemplates[idx % positiveTemplates.length];
        }
    });
}
</script>

<?php
require_once __DIR__ . "/../includes/footer.php";
?>
