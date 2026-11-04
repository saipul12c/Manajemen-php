<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'guru';
$can_view_all = in_array($user_role, ['administrator', 'staf'], true);

// Cari kelas di mana user adalah wali kelas
$my_classes = [];
if ($can_view_all) {
    $my_classes = $pdo->query("SELECT * FROM classes ORDER BY grade_level ASC, name ASC")->fetchAll();
} else {
    $stmt_hc = $pdo->prepare("SELECT * FROM classes WHERE homeroom_teacher_id = ? ORDER BY grade_level ASC, name ASC");
    $stmt_hc->execute([$user_id]);
    $my_classes = $stmt_hc->fetchAll();
}

// Jika guru bukan wali kelas dan bukan admin, tampilkan info
$is_homeroom_teacher = !empty($my_classes);

$selected_class_id = isset($_GET['class_id']) && is_numeric($_GET['class_id']) ? (int)$_GET['class_id'] : ($my_classes[0]['id'] ?? 0);

// Ambil detail kelas yang dipilih
$current_class = null;
if ($selected_class_id > 0) {
    $stmt_cl = $pdo->prepare("
        SELECT c.*, u.name as teacher_name 
        FROM classes c 
        LEFT JOIN users u ON c.homeroom_teacher_id = u.id 
        WHERE c.id = ?
    ");
    $stmt_cl->execute([$selected_class_id]);
    $current_class = $stmt_cl->fetch();
}

$page_title = "Dashboard Wali Kelas";
$message = "";
$message_type = "";

// -------------------------------------------------------------
// SIMPAN CATATAN PERKEMBANGAN SISWA OLEH WALI KELAS
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_homeroom_note') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $academic_year = trim($_POST['academic_year'] ?? ($current_class['academic_year'] ?? '2026/2027'));
        $semester = trim($_POST['semester'] ?? 'Ganjil');
        $notes = trim($_POST['homeroom_notes'] ?? '');
        $ekskul = trim($_POST['extracurricular'] ?? '');
        $decision_status = trim($_POST['decision_status'] ?? 'belum_ditetapkan');
        if (!in_array($decision_status, ['naik_kelas', 'tinggal_kelas', 'lulus', 'tidak_lulus', 'belum_ditetapkan'], true)) {
            $decision_status = 'belum_ditetapkan';
        }
        $target_next_class = trim($_POST['target_next_class'] ?? '');

        if ($student_id > 0) {
            $stmt_note = $pdo->prepare("
                INSERT INTO student_report_notes 
                (student_id, academic_year, semester, homeroom_notes, extracurricular, decision_status, target_next_class, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    homeroom_notes = VALUES(homeroom_notes),
                    extracurricular = VALUES(extracurricular),
                    decision_status = VALUES(decision_status),
                    target_next_class = VALUES(target_next_class),
                    created_by = VALUES(created_by),
                    updated_at = NOW()
            ");
            $stmt_note->execute([$student_id, $academic_year, $semester, $notes ?: null, $ekskul ?: null, $decision_status, $target_next_class ?: null, $user_id]);
            logActivity($pdo, 'SAVE_HOMEROOM_NOTE', "Wali kelas menyimpan catatan perkembangan & status kenaikan siswa ID $student_id");
            $message = "Catatan perkembangan & status kenaikan siswa berhasil disimpan ke e-rapor!";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// QUERY SISWA & REKAP KELAS BINAAN
// -------------------------------------------------------------
$students = [];
$class_attendance_rate = 100;
$total_students = 0;
$total_pending_tasks = 0;
$total_bk_violations = 0;

if ($current_class) {
    // Ambil semua siswa di kelas ini
    $stmt_stu = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.nisn, u.gender, u.phone,
               srn.homeroom_notes, srn.extracurricular, srn.decision_status, srn.target_next_class
        FROM users u
        LEFT JOIN student_report_notes srn ON srn.student_id = u.id AND srn.academic_year = ? AND srn.semester = 'Ganjil'
        WHERE u.class_id = ? AND u.role = 'siswa'
        ORDER BY u.name ASC
    ");
    $stmt_stu->execute([$current_class['academic_year'], $current_class['id']]);
    $raw_students = $stmt_stu->fetchAll();
    $total_students = count($raw_students);

    $sum_attendance_pct = 0;

    foreach ($raw_students as $st) {
        $sid = (int)$st['id'];

        // 1. Rekap presensi siswa
        $stmt_att = $pdo->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir,
                COUNT(CASE WHEN status = 'sakit' THEN 1 END) as sakit,
                COUNT(CASE WHEN status = 'izin' THEN 1 END) as izin,
                COUNT(CASE WHEN status = 'alpa' THEN 1 END) as alpa,
                COUNT(*) as total
            FROM student_attendance WHERE student_id = ?
        ");
        $stmt_att->execute([$sid]);
        $att = $stmt_att->fetch();
        $total_att = (int)($att['total'] ?? 0);
        $hadir_att = (int)($att['hadir'] ?? 0);
        $att_pct = $total_att > 0 ? round(($hadir_att / $total_att) * 100, 1) : 100.0;
        $sum_attendance_pct += $att_pct;

        // 2. Tugas yang belum dikumpulkan
        $stmt_pen = $pdo->prepare("
            SELECT COUNT(*) FROM assignments a
            LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
            WHERE s.id IS NULL OR s.status = 'belum'
        ");
        $stmt_pen->execute([$sid]);
        $pending_tasks = (int)$stmt_pen->fetchColumn();
        $total_pending_tasks += $pending_tasks;

        // 3. Poin BK
        $stmt_bk = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN type = 'pelanggaran' THEN points ELSE 0 END), 0) as pelanggaran,
                COALESCE(SUM(CASE WHEN type = 'prestasi' THEN points ELSE 0 END), 0) as prestasi
            FROM counseling_records WHERE student_id = ?
        ");
        $stmt_bk->execute([$sid]);
        $bk = $stmt_bk->fetch();
        $total_bk_violations += (int)$bk['pelanggaran'];

        // 4. Orang tua siswa
        $stmt_parent = $pdo->prepare("
            SELECT p.id, p.name, p.phone FROM parent_students ps
            JOIN users p ON ps.parent_id = p.id
            WHERE ps.student_id = ? LIMIT 1
        ");
        $stmt_parent->execute([$sid]);
        $parent = $stmt_parent->fetch();

        $students[] = array_merge($st, [
            'attendance' => $att,
            'attendance_pct' => $att_pct,
            'pending_tasks' => $pending_tasks,
            'bk_pelanggaran' => (int)$bk['pelanggaran'],
            'bk_prestasi' => (int)$bk['prestasi'],
            'parent' => $parent
        ]);
    }

    if ($total_students > 0) {
        $class_attendance_rate = round($sum_attendance_pct / $total_students, 1);
    }
}

require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Header Page -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-slate-900/60 p-6 rounded-3xl border border-white/10 backdrop-blur-xl relative overflow-hidden">
        <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-gradient-to-br from-blue-600/20 to-cyan-600/20 rounded-full blur-2xl pointer-events-none"></div>
        <div class="relative z-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-400 text-xs font-semibold uppercase tracking-wider mb-2">
                <i class="fa-solid fa-chalkboard-user"></i> Portal Wali Kelas Terpadu
            </div>
            <h1 class="text-2xl font-black text-white tracking-tight">Dashboard Kelas Binaan</h1>
            <p class="text-slate-400 text-sm mt-1">Pemantauan menyeluruh presensi, penuntasan tugas, poin kedisiplinan BK, dan pencetakan rapor massal rombel.</p>
        </div>

        <?php if ($current_class): ?>
        <div class="flex flex-wrap items-center gap-2.5 relative z-10">
            <a href="report_card_bulk.php?class_id=<?= $current_class['id'] ?>" target="_blank"
               class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white text-xs font-bold flex items-center gap-2 transition shadow-lg shadow-blue-500/25">
                <i class="fa-solid fa-print"></i> Cetak Rapor Satu Kelas
            </a>
            <a href="gradebook_print.php?class_id=<?= $current_class['id'] ?>" target="_blank"
               class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-white/10 text-xs font-semibold flex items-center gap-2 transition shadow-sm">
                <i class="fa-solid fa-file-invoice"></i> Ledger Nilai
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($message)): ?>
        <div class="p-4 rounded-2xl flex items-center gap-3 border <?= $message_type === 'success' ? 'bg-emerald-950/40 border-emerald-500/40 text-emerald-300' : 'bg-rose-950/40 border-rose-500/40 text-rose-300' ?>">
            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-rose-400' ?> text-lg shrink-0"></i>
            <span class="text-sm font-medium"><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$is_homeroom_teacher && !$can_view_all): ?>
        <div class="py-16 text-center text-slate-400 bg-slate-900/40 rounded-3xl border border-white/5">
            <div class="h-16 w-16 mx-auto mb-3 rounded-2xl bg-white/5 flex items-center justify-center text-2xl text-amber-400">
                <i class="fa-solid fa-user-lock"></i>
            </div>
            <h3 class="text-base font-bold text-white">Anda Belum Ditugaskan sebagai Wali Kelas</h3>
            <p class="text-xs text-slate-400 max-w-md mx-auto mt-1">
                Silakan hubungi Administrator atau Staf Kurikulum sekolah untuk menugaskan akun Anda sebagai wali kelas pada menu Manajemen Kelas & Rombel.
            </p>
        </div>
    <?php else: ?>

        <!-- Selector Kelas Binaan jika mengampu lebih dari 1 kelas atau Admin -->
        <?php if (count($my_classes) > 1 || $can_view_all): ?>
            <div class="p-4 rounded-2xl bg-slate-900/40 border border-white/5 no-print">
                <form method="GET" class="flex flex-wrap items-center gap-3">
                    <label class="text-xs font-semibold text-slate-300 flex items-center gap-1.5">
                        <i class="fa-solid fa-school text-blue-400"></i> Pilih Kelas Binaan:
                    </label>
                    <select name="class_id" onchange="this.form.submit()"
                            class="rounded-xl border border-white/10 bg-slate-950 px-3.5 py-1.5 text-xs text-white focus:outline-none focus:border-blue-500">
                        <?php foreach ($my_classes as $mc): ?>
                            <option value="<?= $mc['id'] ?>" <?= $selected_class_id === (int)$mc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($mc['name']) ?> (Tingkat <?= htmlspecialchars($mc['grade_level']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($current_class): ?>
            <!-- STATISTIK KELAS BINAAN -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
                    <div class="h-11 w-11 rounded-xl bg-blue-500/10 border border-blue-500/20 text-blue-400 flex items-center justify-center shrink-0 text-base">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400 font-medium">Siswa Terdaftar</p>
                        <p class="text-xl font-bold text-white"><?= $total_students ?> anak</p>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
                    <div class="h-11 w-11 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0 text-base">
                        <i class="fa-solid fa-clipboard-user"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400 font-medium">Tingkat Hadir Kelas</p>
                        <p class="text-xl font-bold text-emerald-400"><?= $class_attendance_rate ?>%</p>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
                    <div class="h-11 w-11 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center shrink-0 text-base">
                        <i class="fa-solid fa-list-check"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400 font-medium">Tugas Belum Selesai</p>
                        <p class="text-xl font-bold text-amber-400"><?= $total_pending_tasks ?> tugas</p>
                    </div>
                </div>

                <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
                    <div class="h-11 w-11 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center justify-center shrink-0 text-base">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400 font-medium">Poin Pelanggaran BK</p>
                        <p class="text-xl font-bold text-rose-400"><?= $total_bk_violations ?> poin</p>
                    </div>
                </div>
            </div>

            <?php 
            $at_risk_students = [];
            foreach ($students as $stu_item) {
                $alpa_count = (int)($stu_item['attendance']['alpa'] ?? 0);
                $bk_pts = (int)($stu_item['bk_pelanggaran'] ?? 0);
                $pending_cnt = (int)($stu_item['pending_tasks'] ?? 0);
                if ($alpa_count >= 3 || $bk_pts >= 15 || $pending_cnt >= 3) {
                    $at_risk_students[] = array_merge($stu_item, [
                        'alpa_count' => $alpa_count,
                        'bk_pts' => $bk_pts,
                        'pending_cnt' => $pending_cnt
                    ]);
                }
            }
            ?>

            <?php if (!empty($at_risk_students)): ?>
                <!-- Early Warning System (EWS) Alert Widget -->
                <div class="rounded-3xl border border-rose-500/30 bg-gradient-to-r from-rose-950/40 via-slate-900/80 to-amber-950/20 p-5 shadow-2xl backdrop-blur">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-rose-500/20">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-rose-500/20 text-rose-400 text-sm font-bold">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </span>
                            <div>
                                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                                    Early Warning System (EWS) Kesiswaan
                                    <span class="rounded-full bg-rose-500/20 border border-rose-500/30 px-2 py-0.5 text-[10px] text-rose-300 font-bold"><?= count($at_risk_students) ?> Siswa Memerlukan Perhatian</span>
                                </h3>
                                <p class="text-[11px] text-slate-400">Deteksi otomatis ketidakhadiran berulang, akumulasi poin BK, atau tunggakan tugas.</p>
                            </div>
                        </div>
                        <a href="../bk/counseling.php" class="text-xs font-semibold text-rose-400 hover:text-rose-300 flex items-center gap-1.5 transition">
                            <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i> Buka Modul BK
                        </a>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mt-3">
                        <?php foreach ($at_risk_students as $risk): ?>
                            <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-3.5 flex flex-col justify-between">
                                <div>
                                    <div class="flex items-start justify-between gap-2">
                                        <h4 class="text-xs font-bold text-white"><?= htmlspecialchars($risk['name']) ?></h4>
                                        <span class="text-[10px] font-mono text-slate-400">NISN: <?= htmlspecialchars($risk['nisn'] ?: '-') ?></span>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5 mt-2">
                                        <?php if ($risk['alpa_count'] >= 3): ?>
                                            <span class="inline-flex items-center gap-1 rounded-lg border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 text-[10px] font-bold text-rose-400">
                                                <i class="fa-solid fa-clock"></i> <?= $risk['alpa_count'] ?>x Alpa
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($risk['bk_pts'] >= 15): ?>
                                            <span class="inline-flex items-center gap-1 rounded-lg border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-400">
                                                <i class="fa-solid fa-shield-halved"></i> <?= $risk['bk_pts'] ?> Poin BK
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($risk['pending_cnt'] >= 3): ?>
                                            <span class="inline-flex items-center gap-1 rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-2 py-0.5 text-[10px] font-bold text-cyan-400">
                                                <i class="fa-solid fa-book"></i> <?= $risk['pending_cnt'] ?> Tugas Tertunda
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mt-3 pt-2 border-t border-white/5 flex items-center justify-between text-[11px]">
                                    <span class="text-slate-400 truncate"><i class="fa-solid fa-user text-[10px] mr-1"></i><?= htmlspecialchars($risk['parent']['name'] ?? 'Wali Murid') ?></span>
                                    <?php if (!empty($risk['parent']['phone'])): ?>
                                        <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $risk['parent']['phone']) ?>" target="_blank" class="inline-flex items-center gap-1 text-emerald-400 hover:text-emerald-300 font-semibold">
                                            <i class="fa-brands fa-whatsapp"></i> Hubungi
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- TABEL SISWA BINAAN LENGKAP -->
            <div class="bg-slate-900/60 border border-white/10 rounded-3xl overflow-hidden backdrop-blur-xl shadow-xl">
                <div class="p-5 border-b border-white/10 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-graduation-cap text-blue-400"></i>
                            Daftar Siswa Kelas <?= htmlspecialchars($current_class['name']) ?>
                        </h2>
                        <p class="text-[11px] text-slate-400 mt-0.5">Wali Kelas: <?= htmlspecialchars($current_class['teacher_name'] ?? 'Belum Ditentukan') ?> &bull; Tahun Ajaran: <?= htmlspecialchars($current_class['academic_year']) ?></p>
                    </div>
                </div>

                <?php if (empty($students)): ?>
                    <div class="py-16 text-center text-slate-400">
                        <p class="text-sm">Belum ada siswa yang ditempatkan di kelas ini.</p>
                        <p class="text-xs text-slate-500 mt-1">Gunakan modul Manajemen Kelas untuk menambahkan siswa ke rombel ini.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-300">
                            <thead class="bg-slate-950/70 uppercase text-[10px] text-slate-400 tracking-wider font-semibold border-b border-white/10">
                                <tr>
                                    <th class="py-3.5 px-4">Nama Siswa / NISN</th>
                                    <th class="py-3.5 px-4 text-center">Kehadiran</th>
                                    <th class="py-3.5 px-4 text-center">Tugas Pending</th>
                                    <th class="py-3.5 px-4 text-center">Catatan BK</th>
                                    <th class="py-3.5 px-4">Catatan Wali Kelas</th>
                                    <th class="py-3.5 px-4 text-center">Aksi Cepat</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                <?php foreach ($students as $stu): ?>
                                    <tr class="hover:bg-white/[0.02] transition">
                                        <td class="py-3.5 px-4">
                                            <div class="font-bold text-white flex items-center gap-1.5">
                                                <?= htmlspecialchars($stu['name']) ?>
                                                <span class="text-[10px] font-normal text-slate-400 font-mono">(<?= $stu['gender'] ?? '-' ?>)</span>
                                            </div>
                                            <div class="text-[11px] text-slate-400 font-mono mt-0.5">
                                                NISN: <?= htmlspecialchars($stu['nisn'] ?: '-') ?>
                                            </div>
                                            <?php if (!empty($stu['parent'])): ?>
                                                <div class="text-[10px] text-indigo-400 mt-0.5">
                                                    <i class="fa-solid fa-user-group text-[9px] mr-1"></i> Ortu: <?= htmlspecialchars($stu['parent']['name']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-center">
                                            <span class="inline-block font-bold text-xs <?= $stu['attendance_pct'] >= 85 ? 'text-emerald-400' : 'text-rose-400' ?>">
                                                <?= $stu['attendance_pct'] ?>%
                                            </span>
                                            <div class="text-[10px] text-slate-500 mt-0.5">
                                                H:<?= $stu['attendance']['hadir'] ?> &bull; S:<?= $stu['attendance']['sakit'] ?> &bull; I:<?= $stu['attendance']['izin'] ?> &bull; A:<?= $stu['attendance']['alpa'] ?>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-4 text-center">
                                            <?php if ($stu['pending_tasks'] > 0): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-300 border border-amber-500/25">
                                                    <i class="fa-solid fa-clock text-[9px]"></i> <?= $stu['pending_tasks'] ?> Tugas
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                                    <i class="fa-solid fa-check text-[9px]"></i> Tuntas
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-center">
                                            <div class="flex items-center justify-center gap-2">
                                                <span class="text-[11px] font-semibold text-rose-400" title="Poin Pelanggaran">
                                                    <i class="fa-solid fa-circle-exclamation text-[10px]"></i> <?= $stu['bk_pelanggaran'] ?>
                                                </span>
                                                <span class="text-slate-600">/</span>
                                                <span class="text-[11px] font-semibold text-emerald-400" title="Poin Prestasi">
                                                    <i class="fa-solid fa-star text-[10px]"></i> <?= $stu['bk_prestasi'] ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="py-3.5 px-4 max-w-xs">
                                            <?php if (!empty($stu['decision_status']) && $stu['decision_status'] !== 'belum_ditetapkan'): ?>
                                                <div class="mb-1">
                                                    <?php if ($stu['decision_status'] === 'naik_kelas'): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-400">
                                                            <i class="fa-solid fa-circle-check text-[9px]"></i> Naik ke <?= htmlspecialchars($stu['target_next_class'] ?: 'Tingkat Lanjut') ?>
                                                        </span>
                                                    <?php elseif ($stu['decision_status'] === 'tinggal_kelas'): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-500/20 text-rose-400">
                                                            <i class="fa-solid fa-triangle-exclamation text-[9px]"></i> Tinggal di <?= htmlspecialchars($stu['target_next_class'] ?: 'Kelas Ini') ?>
                                                        </span>
                                                    <?php elseif ($stu['decision_status'] === 'lulus'): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-500/20 text-blue-400">
                                                            <i class="fa-solid fa-graduation-cap text-[9px]"></i> Lulus Satuan Pendidikan
                                                        </span>
                                                    <?php elseif ($stu['decision_status'] === 'tidak_lulus'): ?>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-400">
                                                            <i class="fa-solid fa-circle-xmark text-[9px]"></i> Tidak Lulus
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($stu['homeroom_notes'])): ?>
                                                <p class="text-[11px] text-slate-300 line-clamp-2"><?= htmlspecialchars($stu['homeroom_notes']) ?></p>
                                            <?php else: ?>
                                                <span class="text-[11px] text-slate-500 italic block">Belum ada catatan rapor</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3.5 px-4 text-center whitespace-nowrap">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <!-- Tombol Edit Catatan Rapor -->
                                                <button type="button" onclick='openNoteModal(<?= json_encode($stu) ?>)'
                                                        class="p-2 rounded-lg bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 transition" title="Input Catatan Rapor & Kenaikan">
                                                    <i class="fa-solid fa-comment-dots text-xs"></i>
                                                </button>
                                                <!-- Tombol Lihat Rapor Siswa -->
                                                <a href="report_card.php?student_id=<?= $stu['id'] ?>" target="_blank"
                                                   class="p-2 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 transition" title="Lihat E-Rapor">
                                                    <i class="fa-solid fa-award text-xs"></i>
                                                </a>
                                                <!-- Tombol Hubungi Ortu jika ada -->
                                                <?php if (!empty($stu['parent'])): ?>
                                                    <a href="../pesan/messages.php?to=<?= $stu['parent']['id'] ?>"
                                                       class="p-2 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-400 transition" title="Kirim Pesan ke Orang Tua">
                                                        <i class="fa-solid fa-comments text-xs"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <!-- Tombol Profil 360 Siswa -->
                                                <a href="../admin/student_360.php?id=<?= $stu['id'] ?>" target="_blank"
                                                   class="p-2 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-400 transition" title="Profil Terpadu 360° Siswa">
                                                    <i class="fa-solid fa-id-card-clip text-xs"></i>
                                                </a>
                                                <!-- Tombol Catat BK -->
                                                <a href="../bk/counseling.php?student_id=<?= $stu['id'] ?>"
                                                   class="p-2 rounded-lg bg-purple-500/10 hover:bg-purple-500/20 text-purple-400 transition" title="Catat Pelanggaran/Prestasi BK">
                                                    <i class="fa-solid fa-scale-balanced text-xs"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- ============================================================== -->
<!-- MODAL: INPUT CATATAN WALI KELAS -->
<!-- ============================================================== -->
<div id="modalHomeroomNote" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-lg w-full p-6 text-slate-100 shadow-2xl relative my-8">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-4">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-comment-dots text-blue-400"></i>
                Catatan Wali Kelas & Status Kenaikan Rapor
            </h3>
            <button onclick="closeModal('modalHomeroomNote')" class="text-slate-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="save_homeroom_note">
            <input type="hidden" name="student_id" id="note_student_id">

            <div>
                <p class="text-slate-400 text-xs">Siswa Binaan:</p>
                <p class="text-sm font-bold text-white mt-0.5" id="note_student_name"></p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Tahun Ajaran</label>
                    <input type="text" name="academic_year" value="<?= htmlspecialchars($current_class['academic_year'] ?? '2026/2027') ?>" required
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Semester</label>
                    <select name="semester" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="Ganjil">Semester Ganjil</option>
                        <option value="Genap">Semester Genap</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Catatan Perkembangan & Motivasi Siswa</label>
                <textarea name="homeroom_notes" id="note_homeroom_notes" rows="3" placeholder="Tuliskan apresiasi, saran peningkatan belajar, dan karakter siswa..."
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-white focus:outline-none focus:border-blue-500"></textarea>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Kegiatan Ekstrakurikuler & Capaian (Opsional)</label>
                <input type="text" name="extracurricular" id="note_extracurricular" placeholder="Contoh: Pramuka (A - Sangat Aktif), Futsal (B - Aktif)"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
            </div>

            <!-- Keputusan Kenaikan / Kelulusan (Semester Akhir/Genap) -->
            <div class="p-3.5 rounded-2xl bg-slate-950/70 border border-white/10 space-y-3">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-indigo-400"></span>
                    <label class="font-bold text-slate-200 text-xs">Keputusan Kenaikan / Kelulusan (Semester Genap)</label>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11px] text-slate-400 mb-1">Status Keputusan</label>
                        <select name="decision_status" id="note_decision_status" class="w-full bg-slate-900 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500 text-xs">
                            <option value="belum_ditetapkan">Belum Ditetapkan / Berjalan</option>
                            <option value="naik_kelas">Naik Kelas</option>
                            <option value="tinggal_kelas">Tinggal di Kelas Ini</option>
                            <option value="lulus">Lulus Satuan Pendidikan</option>
                            <option value="tidak_lulus">Tidak Lulus</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] text-slate-400 mb-1">Ke Kelas / Tingkat Tujuan</label>
                        <input type="text" name="target_next_class" id="note_target_next_class" placeholder="Contoh: XI MIPA 1"
                               class="w-full bg-slate-900 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500 text-xs">
                    </div>
                </div>
                <p class="text-[10px] text-slate-400 italic">Otomatis tercetak di Lembar Rapor Resmi bagian bawah saat semester genap/akhir.</p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-white/10">
                <button type="button" onclick="closeModal('modalHomeroomNote')" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition">
                    Batal
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold transition shadow-lg shadow-blue-500/25">
                    Simpan Catatan & Status
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
function openNoteModal(stu) {
    document.getElementById('note_student_id').value = stu.id;
    document.getElementById('note_student_name').innerText = stu.name + ' (NISN: ' + (stu.nisn || '-') + ')';
    document.getElementById('note_homeroom_notes').value = stu.homeroom_notes || '';
    document.getElementById('note_extracurricular').value = stu.extracurricular || '';
    document.getElementById('note_decision_status').value = stu.decision_status || 'belum_ditetapkan';
    document.getElementById('note_target_next_class').value = stu.target_next_class || '';
    openModal('modalHomeroomNote');
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
