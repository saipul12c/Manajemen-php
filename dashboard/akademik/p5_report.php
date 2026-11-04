<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff_or_teacher = in_array($user_role, ['guru', 'staf', 'administrator'], true);

$school_info = getSchoolSettings($pdo);
$academic_year = isset($_GET['academic_year']) && trim($_GET['academic_year']) !== '' 
    ? trim($_GET['academic_year']) 
    : ($school_info['academic_year'] ?? '2026/2027');
$current_semester = isset($_GET['semester']) && trim($_GET['semester']) !== '' 
    ? trim($_GET['semester']) 
    : ($school_info['academic_semester'] ?? 'Ganjil');

// -------------------------------------------------------------
// 1. RESOLUSI SISWA TARGET
// -------------------------------------------------------------
$target_student_id = null;
if (isset($_GET['student_id']) && is_numeric($_GET['student_id'])) {
    $target_student_id = (int)$_GET['student_id'];
    // Validasi otorisasi siswa/ortu agar tidak mengintip data siswa lain
    if ($user_role === 'siswa' && $target_student_id !== $user_id) {
        $target_student_id = $user_id;
    } elseif ($user_role === 'orang_tua') {
        $stmt_c = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? AND student_id = ? LIMIT 1");
        $stmt_c->execute([$user_id, $target_student_id]);
        if (!$stmt_c->fetchColumn()) {
            $stmt_c2 = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
            $stmt_c2->execute([$user_id]);
            $target_student_id = (int)($stmt_c2->fetchColumn() ?: 0);
        }
    }
} elseif ($user_role === 'siswa') {
    $target_student_id = $user_id;
} elseif ($user_role === 'orang_tua') {
    $stmt_c = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
    $stmt_c->execute([$user_id]);
    $target_student_id = (int)($stmt_c->fetchColumn() ?: 0);
} elseif ($is_staff_or_teacher) {
    // Default ambil siswa pertama
    $first_id = $pdo->query("SELECT id FROM users WHERE role = 'siswa' ORDER BY class_id ASC, name ASC LIMIT 1")->fetchColumn();
    $target_student_id = (int)($first_id ?: 0);
}

// -------------------------------------------------------------
// 2. AMBIL DATA SISWA
// -------------------------------------------------------------
$stmt_stu = $pdo->prepare("
    SELECT u.*, c.name as class_name, c.homeroom_teacher_id, 
           hr.name as homeroom_teacher_name, hr.nip as homeroom_teacher_nip, 
           hr.title_degree as homeroom_teacher_degree, hr.signature_image as homeroom_teacher_sig
    FROM users u 
    LEFT JOIN classes c ON u.class_id = c.id 
    LEFT JOIN users hr ON c.homeroom_teacher_id = hr.id 
    WHERE u.id = ? AND u.role = 'siswa'
");
$stmt_stu->execute([$target_student_id]);
$student = $stmt_stu->fetch();

// Daftar siswa dan rombel untuk selector guru/admin
$classes_list = [];
$all_students = [];
$prev_student_id = null;
$next_student_id = null;
$selected_class_id = isset($_GET['class_id']) && is_numeric($_GET['class_id']) ? (int)$_GET['class_id'] : ($student['class_id'] ?? null);

if ($is_staff_or_teacher) {
    $classes_list = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();
    
    $stu_sql = "
        SELECT u.id, u.name, u.nisn, u.class_id, c.name as class_name 
        FROM users u 
        LEFT JOIN classes c ON u.class_id = c.id 
        WHERE u.role = 'siswa'
    ";
    $stu_params = [];
    if ($selected_class_id) {
        $stu_sql .= " AND u.class_id = ?";
        $stu_params[] = $selected_class_id;
    }
    $stu_sql .= " ORDER BY c.name ASC, u.name ASC";
    $stmt_stus = $pdo->prepare($stu_sql);
    $stmt_stus->execute($stu_params);
    $all_students = $stmt_stus->fetchAll();

    if (!empty($all_students)) {
        $ids = array_column($all_students, 'id');
        $curr_idx = array_search($target_student_id, $ids);
        if ($curr_idx !== false) {
            if ($curr_idx > 0) $prev_student_id = $ids[$curr_idx - 1];
            if ($curr_idx < count($ids) - 1) $next_student_id = $ids[$curr_idx + 1];
        }
    }
}

if (!$student) {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Data Siswa Tidak Ditemukan - Rapor P5</title>
        <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center p-4">
        <div class="max-w-md w-full rounded-3xl border border-rose-500/30 bg-slate-900/90 p-8 text-center shadow-2xl">
            <span class="text-4xl block mb-2 text-rose-400"><i class="fa-solid fa-triangle-exclamation"></i></span>
            <h2 class="text-lg font-bold text-white">Data Siswa Tidak Ditemukan</h2>
            <p class="text-xs text-slate-400 mt-2">Tidak ditemukan data siswa aktif untuk ditampilkan pada lembar Rapor P5 ini.</p>
            <a href="p5_projects.php" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 px-6 py-2 text-xs font-bold text-white transition"><i class="fa-solid fa-arrow-left"></i> Kembali ke Modul Projek P5</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// -------------------------------------------------------------
// 3. DAFTAR PROJEK P5 & FILTER PROJEK TERPILIH
// -------------------------------------------------------------
$all_projects = $pdo->query("SELECT id, title, theme, phase, academic_year FROM p5_projects ORDER BY id ASC")->fetchAll();

$selected_project_id = isset($_GET['project_id']) && is_numeric($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

// Query projek yang akan dimuat ke lembar rapor
$proj_query = "
    SELECT p.*, coord.name as coordinator_name, coord.nip as coordinator_nip, 
           coord.title_degree as coordinator_degree, coord.signature_image as coordinator_sig
    FROM p5_projects p 
    LEFT JOIN users coord ON p.coordinator_teacher_id = coord.id
";
$proj_params = [];
if ($selected_project_id > 0) {
    $proj_query .= " WHERE p.id = ?";
    $proj_params[] = $selected_project_id;
} else {
    $proj_query .= " WHERE p.status = 'aktif' ORDER BY p.id ASC";
}
$stmt_proj = $pdo->prepare($proj_query);
$stmt_proj->execute($proj_params);
$projects_to_report = $stmt_proj->fetchAll();

// Jika belum ada projek sama sekali
if (empty($projects_to_report)) {
    // Coba ambil projek apapun yang ada
    $projects_to_report = $pdo->query("
        SELECT p.*, coord.name as coordinator_name, coord.nip as coordinator_nip, 
               coord.title_degree as coordinator_degree, coord.signature_image as coordinator_sig
        FROM p5_projects p 
        LEFT JOIN users coord ON p.coordinator_teacher_id = coord.id 
        ORDER BY p.id ASC LIMIT 1
    ")->fetchAll();
}

// -------------------------------------------------------------
// 4. SUSUN DATA ASESMEN & CATATAN PER PROJEK
// -------------------------------------------------------------
$p5_data = [];
foreach ($projects_to_report as $pr) {
    $pid = (int)$pr['id'];

    // Ambil butir dimensi dan sub-elemen
    $stmt_dim = $pdo->prepare("SELECT * FROM p5_project_dimensions WHERE project_id = ? ORDER BY id ASC");
    $stmt_dim->execute([$pid]);
    $dims = $stmt_dim->fetchAll();

    // Ambil nilai asesmen siswa untuk projek ini
    $stmt_as = $pdo->prepare("SELECT dimension_id, predicate FROM p5_student_assessments WHERE project_id = ? AND student_id = ?");
    $stmt_as->execute([$pid, $target_student_id]);
    $grades = [];
    while ($r = $stmt_as->fetch()) {
        $grades[(int)$r['dimension_id']] = $r['predicate'];
    }

    // Ambil catatan fasilitator projek untuk siswa ini
    $stmt_nt = $pdo->prepare("SELECT facilitator_notes FROM p5_student_notes WHERE project_id = ? AND student_id = ? LIMIT 1");
    $stmt_nt->execute([$pid, $target_student_id]);
    $note = $stmt_nt->fetchColumn() ?: '';

    $p5_data[] = [
        'project'    => $pr,
        'dimensions' => $dims,
        'grades'     => $grades,
        'note'       => $note
    ];
}

$page_title = "Rapor Projek P5 - " . htmlspecialchars($student['name']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 15mm 15mm 15mm;
        }
        @media print {
            body {
                background: #ffffff !important;
                color: #0f172a !important;
                padding: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .print-paper {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
                background: #ffffff !important;
                color: #0f172a !important;
            }
            .print-border {
                border-color: #334155 !important;
            }
            .print-text-dark {
                color: #0f172a !important;
            }
            .print-text-muted {
                color: #475569 !important;
            }
            .print-bg-header {
                background-color: #f1f5f9 !important;
                color: #0f172a !important;
            }
            .print-badge {
                border: 1px solid #94a3b8 !important;
                color: #0f172a !important;
                background: transparent !important;
            }
            .page-break {
                page-break-after: always;
                break-after: page;
            }
            .avoid-break {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col items-center py-6 px-4 font-sans antialiased">

    <!-- TOP TOOLBAR (NO PRINT) -->
    <div class="no-print w-full max-w-4xl mb-6 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <a href="p5_projects.php" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 px-3.5 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
                    <i class="fa-solid fa-arrow-left"></i> Kembali ke Projek P5
                </a>
                <a href="report_card.php?student_id=<?= $target_student_id ?>" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 px-3.5 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition">
                    <i class="fa-solid fa-graduation-cap text-blue-400"></i> Rapor Akademik
                </a>
                <?php if ($is_staff_or_teacher): ?>
                    <a href="p5_assessment.php?project_id=<?= $selected_project_id ?: ($projects_to_report[0]['id'] ?? 1) ?>&class_id=<?= $student['class_id'] ?>" class="inline-flex items-center gap-1.5 rounded-xl border border-purple-500/30 bg-purple-500/10 px-3.5 py-2 text-xs font-semibold text-purple-300 hover:bg-purple-500/20 transition">
                        <i class="fa-solid fa-pen-to-square"></i> Input Asesmen
                    </a>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-2">
                <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition cursor-pointer">
                    <i class="fa-solid fa-print"></i> Cetak Lembar Rapor P5 (PDF)
                </button>
            </div>
        </div>

        <!-- Filter & Selector Bar untuk Guru/Admin -->
        <?php if ($is_staff_or_teacher): ?>
            <div class="rounded-2xl border border-white/10 bg-slate-900/80 p-3.5 backdrop-blur flex flex-wrap items-center justify-between gap-3">
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Pilih Siswa:</span>
                    
                    <select name="class_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                        <option value="">Semua Rombel</option>
                        <?php foreach ($classes_list as $cl): ?>
                            <option value="<?= $cl['id'] ?>" <?= ($selected_class_id == $cl['id']) ? 'selected' : '' ?>>
                                Kelas <?= htmlspecialchars($cl['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="student_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none max-w-[220px]">
                        <?php foreach ($all_students as $st): ?>
                            <option value="<?= $st['id'] ?>" <?= ($target_student_id == $st['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['name']) ?> (<?= htmlspecialchars($st['class_name'] ?: 'Umum') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="project_id" onchange="this.form.submit()" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none max-w-[180px]">
                        <option value="0">Semua Projek Aktif</option>
                        <?php foreach ($all_projects as $prj): ?>
                            <option value="<?= $prj['id'] ?>" <?= ($selected_project_id == $prj['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($prj['title']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Pilihan Semester (Preset + Manual) -->
                    <select name="semester" onchange="this.form.submit()" class="rounded-xl border border-purple-500/30 bg-purple-500/10 px-3 py-1.5 text-xs font-semibold text-purple-300 focus:outline-none">
                        <option value="Ganjil" <?= ($current_semester === 'Ganjil') ? 'selected' : '' ?>>Sem. Ganjil</option>
                        <option value="Genap" <?= ($current_semester === 'Genap') ? 'selected' : '' ?>>Sem. Genap</option>
                        <?php if (!in_array($current_semester, ['Ganjil', 'Genap'])): ?>
                            <option value="<?= htmlspecialchars($current_semester) ?>" selected><?= htmlspecialchars($current_semester) ?> (Manual)</option>
                        <?php endif; ?>
                    </select>

                    <!-- Pilihan / Input Tahun Ajaran -->
                    <div class="inline-flex items-center gap-1 rounded-xl border border-white/10 bg-slate-950 px-2 py-1">
                        <span class="text-[10px] text-slate-400 font-mono">T.A:</span>
                        <input type="text" name="academic_year" value="<?= htmlspecialchars($academic_year) ?>" 
                               title="Tekan Enter untuk menerapkan Tahun Ajaran kustom"
                               placeholder="2026/2027" 
                               class="w-20 bg-transparent text-xs font-mono text-white focus:outline-none text-center">
                        <button type="submit" class="text-[10px] text-blue-400 hover:text-white px-1" title="Terapkan"><i class="fa-solid fa-check"></i></button>
                    </div>
                </form>

                <!-- Tombol Navigasi Antar Siswa -->
                <div class="flex items-center gap-1.5">
                    <?php if ($prev_student_id): ?>
                        <a href="p5_report.php?student_id=<?= $prev_student_id ?>&class_id=<?= $selected_class_id ?>&project_id=<?= $selected_project_id ?>&semester=<?= urlencode($current_semester) ?>&academic_year=<?= urlencode($academic_year) ?>" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-2.5 py-1.5 text-xs text-slate-300">
                            ◀ Siswa Sebelumnya
                        </a>
                    <?php endif; ?>

                    <?php if ($next_student_id): ?>
                        <a href="p5_report.php?student_id=<?= $next_student_id ?>&class_id=<?= $selected_class_id ?>&project_id=<?= $selected_project_id ?>&semester=<?= urlencode($current_semester) ?>&academic_year=<?= urlencode($academic_year) ?>" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-2.5 py-1.5 text-xs text-slate-300">
                            Siswa Berikutnya ▶
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- LEMBAR RAPOR RESMI BSKAP (A4) -->
    <div class="print-paper w-full max-w-4xl rounded-3xl border border-white/15 bg-slate-900/95 p-6 sm:p-10 shadow-2xl backdrop-blur text-slate-100">
        
        <!-- KOP SURAT RESMI LEMBAGA -->
        <div class="text-center border-b-2 border-white/20 print-border pb-4 mb-5">
            <h1 class="text-2xl sm:text-3xl font-black uppercase tracking-tight text-white print-text-dark">
                <?= htmlspecialchars($school_info['school_name']) ?>
            </h1>
            <p class="text-xs text-slate-300 print-text-muted mt-0.5"><?= htmlspecialchars($school_info['school_address']) ?></p>
            <p class="text-[11px] text-slate-400 print-text-muted font-mono mt-0.5">
                NPSN: <?= htmlspecialchars($school_info['school_npsn'] ?? '10801234') ?> | Akreditasi: A | Telepon: <?= htmlspecialchars($school_info['school_phone']) ?> | Email: <?= htmlspecialchars($school_info['school_email']) ?>
            </p>
            
            <div class="mt-4 pt-3 border-t border-dashed border-white/15 print-border">
                <h2 class="text-base sm:text-lg font-black uppercase tracking-wider text-blue-400 print-text-dark">
                    RAPOR PROJEK PENGUATAN PROFIL PELAJAR PANCASILA (P5)
                </h2>
                <p class="text-[11px] font-semibold tracking-wide text-slate-400 print-text-muted">
                    Standar BSKAP Kemendikbudristek No. 009/H/KR/2022 - Kurikulum Merdeka
                </p>
            </div>
        </div>

        <!-- IDENTITAS PESERTA DIDIK -->
        <div class="rounded-2xl border border-white/10 print-border bg-slate-950/50 p-4 mb-6 text-xs text-slate-200 print-text-dark">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-2">
                <div class="flex items-center justify-between border-b border-white/5 print-border pb-1">
                    <span class="text-slate-400 print-text-muted">Nama Peserta Didik:</span>
                    <strong class="text-white print-text-dark text-sm"><?= htmlspecialchars($student['name']) ?></strong>
                </div>
                <div class="flex items-center justify-between border-b border-white/5 print-border pb-1">
                    <span class="text-slate-400 print-text-muted">Kelas / Rombel:</span>
                    <strong class="text-white print-text-dark"><?= htmlspecialchars($student['class_name'] ?: 'Umum') ?></strong>
                </div>
                <div class="flex items-center justify-between border-b border-white/5 print-border pb-1">
                    <span class="text-slate-400 print-text-muted">NISN / NIS:</span>
                    <strong class="text-white print-text-dark font-mono"><?= htmlspecialchars($student['nisn'] ?: '-') ?></strong>
                </div>
                <div class="flex items-center justify-between border-b border-white/5 print-border pb-1">
                    <span class="text-slate-400 print-text-muted">Fase Perkembangan:</span>
                    <strong class="text-white print-text-dark"><?= htmlspecialchars($projects_to_report[0]['phase'] ?? 'Fase E') ?></strong>
                </div>
                <div class="flex items-center justify-between border-b border-white/5 print-border pb-1">
                    <span class="text-slate-400 print-text-muted">Tahun Ajaran:</span>
                    <strong class="text-white print-text-dark"><?= htmlspecialchars($academic_year) ?></strong>
                </div>
                <div class="flex items-center justify-between border-b border-white/5 print-border pb-1">
                    <span class="text-slate-400 print-text-muted">Semester:</span>
                    <strong class="text-white print-text-dark"><?= htmlspecialchars($current_semester) ?></strong>
                </div>
            </div>
        </div>

        <?php if (empty($p5_data)): ?>
            <div class="rounded-2xl border border-dashed border-white/20 p-8 text-center my-6">
                <i class="fa-solid fa-folder-open text-3xl text-slate-500 mb-2"></i>
                <h3 class="text-sm font-bold text-white print-text-dark">Belum Ada Data Projek P5 untuk Siswa Ini</h3>
                <p class="text-xs text-slate-400 print-text-muted mt-1">Pastikan projek P5 telah dibuat dan penilaian telah dimasukkan oleh fasilitator/guru.</p>
            </div>
        <?php else: ?>

            <?php foreach ($p5_data as $p_idx => $item): 
                $proj = $item['project'];
                $dims = $item['dimensions'];
                $grades = $item['grades'];
                $note = $item['note'];
            ?>
                <div class="avoid-break mb-8 <?= ($p_idx > 0) ? 'pt-6 border-t-2 border-dashed border-white/15 print-border' : '' ?>">
                    
                    <!-- INFORMASI PROJEK -->
                    <div class="mb-4">
                        <div class="flex flex-wrap items-center gap-2 mb-1.5">
                            <span class="px-2.5 py-0.5 rounded-lg border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 print-badge text-[11px] font-bold">
                                Projek <?= $p_idx + 1 ?>: Tema <?= htmlspecialchars($proj['theme']) ?>
                            </span>
                            <span class="px-2 py-0.5 rounded-lg border border-purple-500/30 bg-purple-500/10 text-purple-300 print-badge text-[10px] font-bold">
                                <?= htmlspecialchars($proj['phase']) ?>
                            </span>
                        </div>
                        <h3 class="text-base sm:text-lg font-bold text-white print-text-dark">
                            <?= htmlspecialchars($proj['title']) ?>
                        </h3>
                        <div class="mt-2 rounded-xl border border-white/10 print-border bg-slate-950/40 p-3 text-xs text-slate-300 print-text-dark leading-relaxed">
                            <strong class="text-slate-400 print-text-muted block text-[11px] uppercase tracking-wider mb-1">Deskripsi Projek:</strong>
                            <?= htmlspecialchars($proj['description']) ?>
                        </div>
                    </div>

                    <!-- TABEL PENILAIAN RUBRIK DIMENSI BSKAP -->
                    <div class="overflow-x-auto rounded-xl border border-white/15 print-border mb-4">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-white/5 print-bg-header border-b border-white/15 print-border text-slate-200 print-text-dark">
                                    <th class="p-2.5 font-bold text-center w-10 border-r border-white/10 print-border">No</th>
                                    <th class="p-2.5 font-bold w-1/3 border-r border-white/10 print-border">Dimensi & Elemen</th>
                                    <th class="p-2.5 font-bold border-r border-white/10 print-border">Sub-Elemen & Capaian Akhir Fase</th>
                                    <th class="p-2 font-bold text-center w-12 border-r border-white/10 print-border" title="Belum Berkembang">BB</th>
                                    <th class="p-2 font-bold text-center w-12 border-r border-white/10 print-border" title="Mulai Berkembang">MB</th>
                                    <th class="p-2 font-bold text-center w-12 border-r border-white/10 print-border" title="Berkembang Sesuai Harapan">BSH</th>
                                    <th class="p-2 font-bold text-center w-12" title="Sangat Berkembang">SB</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/10 print-border">
                                <?php if (empty($dims)): ?>
                                    <tr>
                                        <td colspan="7" class="p-4 text-center text-slate-400 italic">
                                            Belum ada butir rubrik dimensi yang dikonfigurasi untuk projek ini.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $cur_dim = null;
                                    foreach ($dims as $d_idx => $dm): 
                                        $did = (int)$dm['id'];
                                        $p_grade = $grades[$did] ?? 'BSH';
                                    ?>
                                        <tr class="hover:bg-white/[0.02]">
                                            <td class="p-2.5 text-center font-mono text-slate-400 print-text-dark border-r border-white/10 print-border">
                                                <?= $d_idx + 1 ?>
                                            </td>
                                            <td class="p-2.5 border-r border-white/10 print-border">
                                                <strong class="text-white print-text-dark block"><?= htmlspecialchars($dm['dimension']) ?></strong>
                                                <span class="text-[11px] text-slate-400 print-text-muted">Elemen: <?= htmlspecialchars($dm['element']) ?></span>
                                            </td>
                                            <td class="p-2.5 border-r border-white/10 print-border">
                                                <strong class="text-slate-200 print-text-dark block"><?= htmlspecialchars($dm['sub_element']) ?></strong>
                                                <?php if (!empty($dm['target_capaian'])): ?>
                                                    <p class="text-[11px] text-slate-400 print-text-muted mt-0.5 leading-snug">
                                                        <?= htmlspecialchars($dm['target_capaian']) ?>
                                                    </p>
                                                <?php endif; ?>
                                            </td>
                                            <!-- Kolom BB -->
                                            <td class="p-2 text-center border-r border-white/10 print-border">
                                                <?php if ($p_grade === 'BB'): ?>
                                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-rose-500/20 text-rose-400 font-black text-sm print-text-dark">✓</span>
                                                <?php endif; ?>
                                            </td>
                                            <!-- Kolom MB -->
                                            <td class="p-2 text-center border-r border-white/10 print-border">
                                                <?php if ($p_grade === 'MB'): ?>
                                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-amber-500/20 text-amber-400 font-black text-sm print-text-dark">✓</span>
                                                <?php endif; ?>
                                            </td>
                                            <!-- Kolom BSH -->
                                            <td class="p-2 text-center border-r border-white/10 print-border">
                                                <?php if ($p_grade === 'BSH'): ?>
                                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-emerald-500/20 text-emerald-400 font-black text-sm print-text-dark">✓</span>
                                                <?php endif; ?>
                                            </td>
                                            <!-- Kolom SB -->
                                            <td class="p-2 text-center">
                                                <?php if ($p_grade === 'SB'): ?>
                                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-cyan-500/20 text-cyan-400 font-black text-sm print-text-dark">✓</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- KETERANGAN KRITERIA PREDIKAT BSKAP -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-4 text-[10px] rounded-xl border border-white/10 print-border bg-slate-950/40 p-2.5">
                        <div>
                            <strong class="text-rose-400 print-text-dark">BB (Belum Berkembang):</strong>
                            <p class="text-slate-400 print-text-muted leading-tight mt-0.5">Membutuhkan bimbingan intensif dalam mencapai fase.</p>
                        </div>
                        <div>
                            <strong class="text-amber-400 print-text-dark">MB (Mulai Berkembang):</strong>
                            <p class="text-slate-400 print-text-muted leading-tight mt-0.5">Mulai mengembangkan kemampuan namun belum ajeg.</p>
                        </div>
                        <div>
                            <strong class="text-emerald-400 print-text-dark">BSH (Berkembang Sesuai Harapan):</strong>
                            <p class="text-slate-400 print-text-muted leading-tight mt-0.5">Mencapai target capaian fase secara konsisten.</p>
                        </div>
                        <div>
                            <strong class="text-cyan-400 print-text-dark">SB (Sangat Berkembang):</strong>
                            <p class="text-slate-400 print-text-muted leading-tight mt-0.5">Melampaui capaian fase & menjadi teladan kelompok.</p>
                        </div>
                    </div>

                    <!-- CATATAN PROSES FASILITATOR PROJEK -->
                    <div class="rounded-xl border border-white/10 print-border bg-slate-950/60 p-3.5 mb-6 text-xs text-slate-200 print-text-dark">
                        <strong class="text-[11px] font-bold uppercase tracking-wider text-slate-400 print-text-muted block mb-1">
                            Catatan Proses Fasilitator Projek:
                        </strong>
                        <p class="leading-relaxed italic">
                            <?= !empty($note) ? '"' . htmlspecialchars($note) . '"' : '"Peserta didik menunjukkan partisipasi aktif dan kolaboratif dalam seluruh tahapan alur Projek Penguatan Profil Pelajar Pancasila ini."' ?>
                        </p>
                    </div>

                </div>
            <?php endforeach; ?>

        <?php endif; ?>

        <!-- BLOK TANDA TANGAN RESMI TIGA PIHAK -->
        <div class="avoid-break mt-6 pt-5 border-t-2 border-white/20 print-border text-xs text-slate-300 print-text-dark">
            <div class="text-right text-[11px] text-slate-400 print-text-muted mb-4">
                <?= htmlspecialchars($school_info['doc_city'] ?? 'Kota') ?>, <?= date('d F Y') ?>
            </div>

            <div class="grid grid-cols-3 gap-4 text-center">
                <!-- Pihak 1: Orang Tua / Wali -->
                <div>
                    <p class="text-slate-400 print-text-muted text-[11px]">Mengetahui,</p>
                    <p class="font-bold text-white print-text-dark">Orang Tua / Wali Murid</p>
                    <div class="h-20 flex items-center justify-center">
                        <span class="text-[10px] text-slate-600 print-text-muted italic">(Tanda Tangan Asli)</span>
                    </div>
                    <p class="font-bold underline text-white print-text-dark">..........................................</p>
                </div>

                <!-- Pihak 2: Koordinator Projek / Wali Kelas -->
                <div>
                    <p class="text-slate-400 print-text-muted text-[11px]">Fasilitator Projek,</p>
                    <p class="font-bold text-white print-text-dark">Koordinator P5</p>
                    <div class="h-20 flex flex-col items-center justify-center">
                        <?php 
                        $coord_name = $projects_to_report[0]['coordinator_name'] ?? ($student['homeroom_teacher_name'] ?? 'Fasilitator Projek');
                        $coord_nip = $projects_to_report[0]['coordinator_nip'] ?? ($student['homeroom_teacher_nip'] ?? '-');
                        $coord_deg = $projects_to_report[0]['coordinator_degree'] ?? ($student['homeroom_teacher_degree'] ?? '');
                        $coord_full = $coord_name . (!empty($coord_deg) ? ', ' . $coord_deg : '');
                        ?>
                        <span class="text-[10px] font-mono text-emerald-400 print-text-muted">[TERVERIFIKASI DIGITAL]</span>
                    </div>
                    <p class="font-bold underline text-white print-text-dark"><?= htmlspecialchars($coord_full) ?></p>
                    <p class="text-[11px] text-slate-400 print-text-muted">NIP. <?= htmlspecialchars($coord_nip ?: '-') ?></p>
                </div>

                <!-- Pihak 3: Kepala Sekolah -->
                <div>
                    <p class="text-slate-400 print-text-muted text-[11px]">Mengetahui,</p>
                    <p class="font-bold text-white print-text-dark">Kepala Sekolah</p>
                    <div class="h-20 flex flex-col items-center justify-center">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=64x64&data=<?= urlencode('VERIFIED_P5_REPORT:'.$student['id'].':'.$student['nisn'].':'.date('Ymd')) ?>" 
                             alt="QR Validasi" 
                             class="h-16 w-16 rounded border border-white/20 print-border">
                    </div>
                    <?php 
                    $headmaster_name = $school_info['headmaster_name'] ?? 'Drs. H. Mulyadi, M.Pd.';
                    $headmaster_nip = $school_info['headmaster_nip'] ?? '197508122000031002';
                    ?>
                    <p class="font-bold underline text-white print-text-dark"><?= htmlspecialchars($headmaster_name) ?></p>
                    <p class="text-[11px] text-slate-400 print-text-muted">NIP. <?= htmlspecialchars($headmaster_nip) ?></p>
                </div>
            </div>
        </div>

    </div>

</body>
</html>
