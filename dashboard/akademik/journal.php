<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'guru';
$can_manage_all = in_array($user_role, ['administrator', 'staf'], true);
$can_create = in_array($user_role, ['guru', 'administrator', 'staf'], true);

if (!$can_create) {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

$page_title = "Jurnal Mengajar KBM";
$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. TAMBAH JURNAL MENGAJAR BARU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_journal') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $teacher_id = $can_manage_all ? (int)($_POST['teacher_id'] ?? $user_id) : $user_id;
        $class_id = (int)($_POST['class_id'] ?? 0);
        $subject_name = trim($_POST['subject_name'] ?? '');
        $date = $_POST['date'] ?? date('Y-m-d');
        $start_time = $_POST['start_time'] ?? '07:30';
        $end_time = $_POST['end_time'] ?? '09:00';
        $topic = trim($_POST['topic'] ?? '');
        $competency_notes = trim($_POST['competency_notes'] ?? '');
        $student_attendance_summary = trim($_POST['student_attendance_summary'] ?? '');
        $class_condition_notes = trim($_POST['class_condition_notes'] ?? '');
        $status = $_POST['status'] ?? 'terlaksana';
        $substitute_teacher_id = !empty($_POST['substitute_teacher_id']) ? (int)$_POST['substitute_teacher_id'] : null;

        if ($class_id <= 0 || empty($subject_name) || empty($topic)) {
            $message = "Kelas, mata pelajaran, dan topik/materi pokok wajib diisi.";
            $message_type = "error";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO teaching_journals 
                (teacher_id, class_id, subject_name, date, start_time, end_time, topic, competency_notes, student_attendance_summary, class_condition_notes, status, substitute_teacher_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $teacher_id, $class_id, $subject_name, $date, $start_time, $end_time,
                $topic, $competency_notes ?: null, $student_attendance_summary ?: null,
                $class_condition_notes ?: null, $status, $substitute_teacher_id
            ]);

            // Sinkronisasi otomatis ke presensi harian jika opsi diaktifkan
            $sync_count = 0;
            if (isset($_POST['sync_attendance']) && $_POST['sync_attendance'] === '1') {
                try {
                    $stmt_students = $pdo->prepare("SELECT id FROM users WHERE class_id = ? AND role = 'siswa'");
                    $stmt_students->execute([$class_id]);
                    $class_student_ids = $stmt_students->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($class_student_ids)) {
                        $stmt_check_att = $pdo->prepare("SELECT student_id FROM student_attendance WHERE student_id = ? AND date = ?");
                        $stmt_ins_att = $pdo->prepare("
                            INSERT INTO student_attendance (student_id, date, status, notes, recorded_by) 
                            VALUES (?, ?, 'hadir', ?, ?)
                        ");

                        foreach ($class_student_ids as $sid) {
                            $stmt_check_att->execute([$sid, $date]);
                            if (!$stmt_check_att->fetch()) {
                                $stmt_ins_att->execute([$sid, $date, 'Sinkronisasi Otomatis dari Jurnal KBM: ' . $subject_name, $teacher_id]);
                                $sync_count++;
                            }
                        }
                    }
                } catch (Exception $e) {}
            }

            logActivity($pdo, 'CREATE_TEACHING_JOURNAL', "Mencatat jurnal KBM: $subject_name topik '$topic' di kelas ID $class_id (Sync presensi: $sync_count siswa)");
            $message = "Jurnal mengajar berhasil disimpan!" . ($sync_count > 0 ? " ($sync_count kehadiran siswa berhasil disinkronkan ke presensi)." : "");
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 2. PERBARUI / EDIT JURNAL MENGAJAR
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_journal') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $journal_id = (int)($_POST['journal_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $subject_name = trim($_POST['subject_name'] ?? '');
        $date = $_POST['date'] ?? date('Y-m-d');
        $start_time = $_POST['start_time'] ?? '07:30';
        $end_time = $_POST['end_time'] ?? '09:00';
        $topic = trim($_POST['topic'] ?? '');
        $competency_notes = trim($_POST['competency_notes'] ?? '');
        $student_attendance_summary = trim($_POST['student_attendance_summary'] ?? '');
        $class_condition_notes = trim($_POST['class_condition_notes'] ?? '');
        $status = $_POST['status'] ?? 'terlaksana';
        $substitute_teacher_id = !empty($_POST['substitute_teacher_id']) ? (int)$_POST['substitute_teacher_id'] : null;

        // Cek kepemilikan
        $stmt_chk = $pdo->prepare("SELECT teacher_id FROM teaching_journals WHERE id = ?");
        $stmt_chk->execute([$journal_id]);
        $owner_id = (int)$stmt_chk->fetchColumn();

        if ($journal_id <= 0 || (!$can_manage_all && $owner_id !== $user_id)) {
            $message = "Anda tidak berhak mengubah jurnal ini.";
            $message_type = "error";
        } else {
            $stmt = $pdo->prepare("
                UPDATE teaching_journals SET
                    class_id = ?, subject_name = ?, date = ?, start_time = ?, end_time = ?,
                    topic = ?, competency_notes = ?, student_attendance_summary = ?,
                    class_condition_notes = ?, status = ?, substitute_teacher_id = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $class_id, $subject_name, $date, $start_time, $end_time,
                $topic, $competency_notes ?: null, $student_attendance_summary ?: null,
                $class_condition_notes ?: null, $status, $substitute_teacher_id, $journal_id
            ]);

            logActivity($pdo, 'UPDATE_TEACHING_JOURNAL', "Memperbarui jurnal KBM ID: $journal_id");
            $message = "Jurnal mengajar berhasil diperbarui.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 3. HAPUS JURNAL MENGAJAR
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_journal') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $journal_id = (int)($_POST['journal_id'] ?? 0);
        $stmt_chk = $pdo->prepare("SELECT teacher_id FROM teaching_journals WHERE id = ?");
        $stmt_chk->execute([$journal_id]);
        $owner_id = (int)$stmt_chk->fetchColumn();

        if ($journal_id <= 0 || (!$can_manage_all && $owner_id !== $user_id)) {
            $message = "Anda tidak berhak menghapus jurnal ini.";
            $message_type = "error";
        } else {
            $stmt_del = $pdo->prepare("DELETE FROM teaching_journals WHERE id = ?");
            $stmt_del->execute([$journal_id]);
            logActivity($pdo, 'DELETE_TEACHING_JOURNAL', "Menghapus jurnal KBM ID: $journal_id");
            $message = "Jurnal mengajar berhasil dihapus.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 4. DATA FILTER & PENGAMBILAN DATA
// -------------------------------------------------------------
$classes = $pdo->query("SELECT id, name, grade_level FROM classes ORDER BY grade_level ASC, name ASC")->fetchAll();
$subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name ASC")->fetchAll();
$teachers = $pdo->query("SELECT id, name FROM users WHERE role = 'guru' ORDER BY name ASC")->fetchAll();

$filter_month = $_GET['month'] ?? date('Y-m');
$filter_class = isset($_GET['class_id']) && is_numeric($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$filter_teacher = isset($_GET['teacher_id']) && is_numeric($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : ($can_manage_all ? 0 : $user_id);

$query_sql = "
    SELECT tj.*, c.name as class_name, u.name as teacher_name, sub.name as substitute_name
    FROM teaching_journals tj
    JOIN classes c ON tj.class_id = c.id
    JOIN users u ON tj.teacher_id = u.id
    LEFT JOIN users sub ON tj.substitute_teacher_id = sub.id
    WHERE 1=1
";
$params = [];

if (!$can_manage_all) {
    $query_sql .= " AND tj.teacher_id = ?";
    $params[] = $user_id;
} elseif ($filter_teacher > 0) {
    $query_sql .= " AND tj.teacher_id = ?";
    $params[] = $filter_teacher;
}

if ($filter_class > 0) {
    $query_sql .= " AND tj.class_id = ?";
    $params[] = $filter_class;
}

if (!empty($filter_month)) {
    $query_sql .= " AND DATE_FORMAT(tj.date, '%Y-%m') = ?";
    $params[] = $filter_month;
}

$query_sql .= " ORDER BY tj.date DESC, tj.start_time DESC";

$stmt = $pdo->prepare($query_sql);
$stmt->execute($params);
$journals = $stmt->fetchAll();

// Hitung statistik
$total_kbm = count($journals);
$count_terlaksana = 0;
$count_inval = 0;
$count_kendala = 0;
foreach ($journals as $j) {
    if ($j['status'] === 'terlaksana') $count_terlaksana++;
    elseif ($j['status'] === 'inval') $count_inval++;
    elseif ($j['status'] === 'kendala') $count_kendala++;
}

// Ambil jadwal mengajar hari ini untuk Smart Auto-Fill KBM
$days_map = [
    'Sunday' => 'Minggu',
    'Monday' => 'Senin',
    'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu',
    'Thursday' => 'Kamis',
    'Friday' => 'Jumat',
    'Saturday' => 'Sabtu'
];
$today_day_indo = $days_map[date('l')] ?? 'Senin';
$current_clock_time = date('H:i:s');

$stmt_today_sched = $pdo->prepare("
    SELECT t.*, c.name as class_name
    FROM timetables t
    JOIN classes c ON t.class_id = c.id
    WHERE (t.teacher_id = ? OR ? = 1) AND t.day = ?
    ORDER BY t.start_time ASC
");
$stmt_today_sched->execute([$user_id, $can_manage_all ? 1 : 0, $today_day_indo]);
$today_schedules = $stmt_today_sched->fetchAll();

require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Header Page & Action Buttons -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-slate-900/60 p-6 rounded-3xl border border-white/10 backdrop-blur-xl relative overflow-hidden">
        <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-gradient-to-br from-blue-600/20 to-indigo-600/20 rounded-full blur-2xl pointer-events-none"></div>
        <div class="relative z-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-blue-500/10 border border-blue-500/20 text-blue-400 text-xs font-semibold uppercase tracking-wider mb-2">
                <i class="fa-solid fa-book-journal-whills"></i> Agenda KBM Harian
            </div>
            <h1 class="text-2xl font-black text-white tracking-tight">Jurnal Mengajar Guru</h1>
            <p class="text-slate-400 text-sm mt-1">Dokumentasi keterlaksanaan materi pelajaran, kompetensi siswa, dan situasi kelas per tatap muka.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2.5 relative z-10">
            <button onclick="window.print()" class="px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 border border-white/10 text-xs font-semibold flex items-center gap-2 transition shadow-sm">
                <i class="fa-solid fa-print"></i> Cetak Logbook
            </button>
            <button onclick="openModal('modalCreateJournal')" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white text-xs font-bold flex items-center gap-2 transition shadow-lg shadow-blue-500/25">
                <i class="fa-solid fa-plus"></i> Isi Jurnal KBM
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

    <!-- Statistik Ringkas -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
            <div class="h-11 w-11 rounded-xl bg-blue-500/10 border border-blue-500/20 text-blue-400 flex items-center justify-center shrink-0 text-base">
                <i class="fa-solid fa-chalkboard-user"></i>
            </div>
            <div>
                <p class="text-xs text-slate-400 font-medium">Total Tatap Muka</p>
                <p class="text-xl font-bold text-white"><?= $total_kbm ?></p>
            </div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
            <div class="h-11 w-11 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0 text-base">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <p class="text-xs text-slate-400 font-medium">Terlaksana Penuh</p>
                <p class="text-xl font-bold text-emerald-400"><?= $count_terlaksana ?></p>
            </div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
            <div class="h-11 w-11 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center shrink-0 text-base">
                <i class="fa-solid fa-user-clock"></i>
            </div>
            <div>
                <p class="text-xs text-slate-400 font-medium">Guru Inval / Pengganti</p>
                <p class="text-xl font-bold text-amber-400"><?= $count_inval ?></p>
            </div>
        </div>
        <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center gap-3.5">
            <div class="h-11 w-11 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center justify-center shrink-0 text-base">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <p class="text-xs text-slate-400 font-medium">Kendala Teknis/Kelas</p>
                <p class="text-xl font-bold text-rose-400"><?= $count_kendala ?></p>
            </div>
        </div>
    </div>

    <!-- Smart Schedule Banner: Jadwal Mengajar Hari Ini -->
    <?php if (!empty($today_schedules)): ?>
    <div class="p-5 rounded-3xl bg-gradient-to-r from-blue-950/40 via-indigo-950/40 to-slate-900/60 border border-blue-500/20 backdrop-blur shadow-xl relative overflow-hidden">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
            <div class="flex items-center gap-2.5">
                <div class="h-8 w-8 rounded-lg bg-blue-500/20 text-blue-400 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-calendar-day"></i>
                </div>
                <div>
                    <h3 class="text-sm font-bold text-white">Jadwal Mengajar Anda Hari Ini (<?= htmlspecialchars($today_day_indo) ?>, <?= date('d M Y') ?>)</h3>
                    <p class="text-xs text-slate-400">Klik "Mulai KBM" pada slot pelajaran untuk membuka dan mengisi jurnal tatap muka secara instan.</p>
                </div>
            </div>
            <span class="text-xs font-mono font-semibold px-2.5 py-1 rounded-xl bg-slate-800 text-slate-300 border border-white/10 self-start sm:self-auto">
                <i class="fa-regular fa-clock text-blue-400 mr-1"></i> <?= date('H:i') ?> WIB
            </span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php foreach ($today_schedules as $sch): 
                $is_current_slot = ($sch['start_time'] <= $current_clock_time && $sch['end_time'] >= $current_clock_time);
            ?>
                <div class="p-3.5 rounded-2xl border transition flex items-center justify-between gap-3 <?= $is_current_slot ? 'bg-emerald-950/30 border-emerald-500/40 shadow-lg shadow-emerald-950/40' : 'bg-slate-950/50 border-white/5 hover:border-white/15' ?>">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-white text-xs"><?= htmlspecialchars($sch['class_name']) ?></span>
                            <?php if ($is_current_slot): ?>
                                <span class="px-1.5 py-0.5 rounded bg-emerald-500 text-slate-950 text-[9px] font-black uppercase">Sesi Berlangsung</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs font-semibold text-blue-300 truncate mt-0.5"><?= htmlspecialchars($sch['subject_name']) ?></p>
                        <p class="text-[11px] text-slate-400 font-mono mt-0.5">
                            <i class="fa-regular fa-clock text-[10px]"></i> <?= substr($sch['start_time'], 0, 5) ?> - <?= substr($sch['end_time'], 0, 5) ?> WIB
                            <?= !empty($sch['room']) ? '&bull; R. ' . htmlspecialchars($sch['room']) : '' ?>
                        </p>
                    </div>
                    <button type="button" 
                            onclick="openModal('modalCreateJournal'); autoFillFromSchedule(<?= (int)$sch['class_id'] ?>, '<?= htmlspecialchars(addslashes($sch['subject_name'])) ?>', '<?= substr($sch['start_time'], 0, 5) ?>', '<?= substr($sch['end_time'], 0, 5) ?>');"
                            class="px-3 py-2 rounded-xl text-xs font-bold transition shrink-0 cursor-pointer flex items-center gap-1.5 <?= $is_current_slot ? 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-md shadow-emerald-600/30' : 'bg-blue-600/80 hover:bg-blue-600 text-white' ?>">
                        <i class="fa-solid fa-play text-[10px]"></i> Mulai KBM
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter Bar Card -->
    <div class="p-4 rounded-2xl bg-slate-900/40 border border-white/5 no-print">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Bulan Pelaksanaan</label>
                <input type="month" name="month" value="<?= htmlspecialchars($filter_month) ?>"
                       class="w-full bg-slate-950/60 border border-white/10 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-blue-500">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Rombongan Belajar (Kelas)</label>
                <select name="class_id" class="w-full bg-slate-950/60 border border-white/10 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-blue-500">
                    <option value="0">-- Semua Rombel --</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filter_class === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?> (Tingkat <?= htmlspecialchars($c['grade_level']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($can_manage_all): ?>
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Guru Pengampu</label>
                <select name="teacher_id" class="w-full bg-slate-950/60 border border-white/10 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-blue-500">
                    <option value="0">-- Semua Guru --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $filter_teacher === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="flex items-center gap-2">
                <button type="submit" class="flex-1 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold transition">
                    <i class="fa-solid fa-filter mr-1"></i> Terapkan
                </button>
                <a href="journal.php" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 text-xs font-medium transition" title="Reset Filter">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Tabel Daftar Jurnal Mengajar -->
    <div class="bg-slate-900/60 border border-white/10 rounded-3xl overflow-hidden backdrop-blur-xl shadow-xl">
        <div class="p-5 border-b border-white/10 flex items-center justify-between">
            <h2 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-list-check text-blue-400"></i>
                Catatan Realisasi KBM
                <span class="text-xs font-normal text-slate-400">(<?= count($journals) ?> rekaman)</span>
            </h2>
        </div>

        <?php if (empty($journals)): ?>
            <div class="py-16 text-center text-slate-400">
                <div class="h-16 w-16 mx-auto mb-3 rounded-2xl bg-white/5 flex items-center justify-center text-2xl text-slate-500">
                    <i class="fa-solid fa-book-open"></i>
                </div>
                <p class="text-sm font-medium text-slate-300">Belum ada catatan jurnal mengajar pada periode ini.</p>
                <p class="text-xs text-slate-500 mt-1">Klik tombol "Isi Jurnal KBM" di atas untuk mulai mencatat agenda tatap muka Anda.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-950/70 uppercase text-[10px] text-slate-400 tracking-wider font-semibold border-b border-white/10">
                        <tr>
                            <th class="py-3.5 px-4">Waktu & Tanggal</th>
                            <th class="py-3.5 px-4">Kelas & Mapel</th>
                            <?php if ($can_manage_all): ?>
                                <th class="py-3.5 px-4">Guru</th>
                            <?php endif; ?>
                            <th class="py-3.5 px-4">Topik & Capaian Belajar</th>
                            <th class="py-3.5 px-4">Presensi Siswa</th>
                            <th class="py-3.5 px-4">Status</th>
                            <th class="py-3.5 px-4 text-center no-print">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($journals as $row): 
                            $badge_color = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
                            $status_label = 'Terlaksana';
                            if ($row['status'] === 'inval') {
                                $badge_color = 'bg-amber-500/10 text-amber-400 border-amber-500/20';
                                $status_label = 'Inval (Pengganti)';
                            } elseif ($row['status'] === 'kendala') {
                                $badge_color = 'bg-rose-500/10 text-rose-400 border-rose-500/20';
                                $status_label = 'Terkendala';
                            }
                        ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <div class="font-bold text-white"><?= date('d M Y', strtotime($row['date'])) ?></div>
                                    <div class="text-[11px] text-slate-400 font-mono mt-0.5">
                                        <i class="fa-regular fa-clock text-[10px] mr-1"></i><?= substr($row['start_time'], 0, 5) ?> - <?= substr($row['end_time'], 0, 5) ?>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4">
                                    <span class="inline-block px-2 py-0.5 rounded bg-indigo-500/15 text-indigo-300 font-semibold text-[10px] border border-indigo-500/20 mb-1">
                                        <?= htmlspecialchars($row['class_name']) ?>
                                    </span>
                                    <p class="font-semibold text-white"><?= htmlspecialchars($row['subject_name']) ?></p>
                                </td>
                                <?php if ($can_manage_all): ?>
                                    <td class="py-3.5 px-4">
                                        <p class="font-medium text-slate-200"><?= htmlspecialchars($row['teacher_name']) ?></p>
                                        <?php if (!empty($row['substitute_name'])): ?>
                                            <p class="text-[10px] text-amber-400 mt-0.5"><i class="fa-solid fa-user-clock"></i> Inval: <?= htmlspecialchars($row['substitute_name']) ?></p>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td class="py-3.5 px-4 max-w-xs">
                                    <p class="font-bold text-slate-200"><?= htmlspecialchars($row['topic']) ?></p>
                                    <?php if (!empty($row['competency_notes'])): ?>
                                        <p class="text-[11px] text-slate-400 mt-0.5 line-clamp-2"><?= htmlspecialchars($row['competency_notes']) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($row['class_condition_notes'])): ?>
                                        <div class="text-[10px] text-amber-300/80 bg-amber-500/5 px-2 py-1 rounded mt-1 border border-amber-500/10">
                                            <i class="fa-solid fa-circle-info mr-1"></i><?= htmlspecialchars($row['class_condition_notes']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="text-xs text-slate-300 font-medium">
                                        <?= htmlspecialchars($row['student_attendance_summary'] ?: 'Semua Hadir') ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-semibold border <?= $badge_color ?>">
                                        <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                        <?= $status_label ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-center whitespace-nowrap no-print">
                                    <?php if ($can_manage_all || (int)$row['teacher_id'] === $user_id): ?>
                                        <div class="flex items-center justify-center gap-1.5">
                                            <button type="button" onclick='openEditModal(<?= json_encode($row) ?>)'
                                                    class="p-2 rounded-lg bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 transition" title="Edit Jurnal">
                                                <i class="fa-solid fa-pen-to-square text-xs"></i>
                                            </button>
                                            <form method="POST" onsubmit="return confirm('Hapus rekaman jurnal ini?')" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                                <input type="hidden" name="action" value="delete_journal">
                                                <input type="hidden" name="journal_id" value="<?= $row['id'] ?>">
                                                <button type="submit" class="p-2 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 transition" title="Hapus Jurnal">
                                                    <i class="fa-solid fa-trash text-xs"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-slate-600 text-xs">-</span>
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
<!-- MODAL: ISI JURNAL KBM BARU -->
<!-- ============================================================== -->
<div id="modalCreateJournal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-xl w-full p-6 text-slate-100 shadow-2xl relative my-8">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-4">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-book-journal-whills text-blue-400"></i>
                Isi Jurnal Mengajar KBM
            </h3>
            <button onclick="closeModal('modalCreateJournal')" class="text-slate-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="create_journal">

            <?php if ($can_manage_all): ?>
            <div>
                <label class="block font-semibold text-slate-300 mb-1">Guru Pengampu</label>
                <select name="teacher_id" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $t['id'] == $user_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if (!empty($today_schedules)): ?>
            <!-- Smart Auto-Fill dari Jadwal Timetable Hari Ini -->
            <div class="p-3.5 rounded-2xl bg-gradient-to-r from-blue-900/30 to-indigo-900/30 border border-blue-500/25">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[11px] font-bold text-blue-300 flex items-center gap-1.5">
                        <i class="fa-solid fa-bolt text-amber-400"></i> Ambil Cepat dari Jadwal Hari Ini (<?= htmlspecialchars($today_day_indo) ?>):
                    </span>
                    <span class="text-[10px] text-slate-400 font-mono"><?= date('H:i') ?> WIB</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($today_schedules as $sch): 
                        $is_current_slot = ($sch['start_time'] <= $current_clock_time && $sch['end_time'] >= $current_clock_time);
                    ?>
                        <button type="button" 
                                onclick="autoFillFromSchedule(<?= (int)$sch['class_id'] ?>, '<?= htmlspecialchars(addslashes($sch['subject_name'])) ?>', '<?= substr($sch['start_time'], 0, 5) ?>', '<?= substr($sch['end_time'], 0, 5) ?>')"
                                class="px-2.5 py-1.5 rounded-xl border text-[11px] font-semibold transition text-left cursor-pointer flex items-center gap-1.5 <?= $is_current_slot ? 'border-emerald-500/50 bg-emerald-500/20 text-emerald-200 hover:bg-emerald-500/30 ring-1 ring-emerald-500/30' : 'border-white/10 bg-slate-950/70 text-slate-300 hover:bg-white/10' ?>"
                                title="Klik untuk mengisi otomatis kelas, mapel & jam">
                            <span class="font-bold text-white"><?= htmlspecialchars($sch['class_name']) ?></span>
                            <span class="text-slate-400">&bull;</span>
                            <span><?= htmlspecialchars($sch['subject_name']) ?></span>
                            <span class="font-mono text-[10px] text-slate-400">(<?= substr($sch['start_time'], 0, 5) ?>-<?= substr($sch['end_time'], 0, 5) ?>)</span>
                            <?php if ($is_current_slot): ?>
                                <span class="px-1.5 py-0.2 rounded bg-emerald-400 text-slate-950 text-[9px] font-black uppercase tracking-wider">KBM Aktif</span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Rombel (Kelas) <span class="text-rose-400">*</span></label>
                    <select name="class_id" id="create_class_id" required class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="">-- Pilih Kelas --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Mata Pelajaran <span class="text-rose-400">*</span></label>
                    <input type="text" name="subject_name" id="create_subject_name" required placeholder="Contoh: Matematika" list="subjectList"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    <datalist id="subjectList">
                        <?php foreach ($subjects as $sb): ?>
                            <option value="<?= htmlspecialchars($sb['name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Tanggal KBM</label>
                    <input type="date" name="date" id="create_date" value="<?= date('Y-m-d') ?>" required
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Jam Mulai</label>
                    <input type="time" name="start_time" id="create_start_time" value="07:30" required
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Jam Selesai</label>
                    <input type="time" name="end_time" id="create_end_time" value="09:00" required
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Topik / Bab Pokok Bahasan <span class="text-rose-400">*</span></label>
                <input type="text" name="topic" id="create_topic" required placeholder="Contoh: Fungsi Kuadrat & Persamaan Parabola"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Capaian Kompetensi / Kegiatan Belajar</label>
                <textarea name="competency_notes" rows="2.5" placeholder="Penjelasan singkat indikator kompetensi siswa hari ini atau metode pembelajaran yang digunakan..."
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-white focus:outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Rekap Kehadiran Siswa</label>
                    <input type="text" name="student_attendance_summary" placeholder="Contoh: 35 Hadir, 1 Izin (Budi)"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Status KBM</label>
                    <select name="status" id="createStatusSelect" onchange="toggleSubstituteTeacher(this.value, 'createSubDiv')"
                            class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="terlaksana">Terlaksana Penuh</option>
                        <option value="inval">Inval (Digantikan Guru Lain)</option>
                        <option value="kendala">Terkendala / Tidak Tuntas</option>
                    </select>
                </div>
            </div>

            <!-- Smart Attendance Sync Option -->
            <div class="rounded-xl border border-blue-500/20 bg-blue-500/5 p-3 flex items-start gap-2.5">
                <input type="checkbox" id="sync_attendance" name="sync_attendance" value="1" checked
                       class="mt-0.5 rounded border-white/20 bg-slate-950 text-blue-500 focus:ring-0">
                <label for="sync_attendance" class="text-xs text-slate-300 cursor-pointer leading-relaxed">
                    <strong class="text-blue-400 block font-semibold">Sinkronisasi Otomatis ke Presensi Harian</strong>
                    Otomatis catat status 'hadir' bagi siswa di kelas ini yang presensinya belum terinput pada tanggal KBM ini.
                </label>
            </div>

            <div id="createSubDiv" class="hidden">
                <label class="block font-semibold text-amber-300 mb-1">Guru Pengganti (Inval)</label>
                <select name="substitute_teacher_id" class="w-full bg-slate-950 border border-amber-500/30 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-amber-500">
                    <option value="">-- Pilih Guru Inval --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Catatan Kejadian / Situasi Kelas (Opsional)</label>
                <input type="text" name="class_condition_notes" placeholder="Contoh: Proyektor sempat error 10 menit, diganti papan tulis manual"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-white/10">
                <button type="button" onclick="closeModal('modalCreateJournal')" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition">
                    Batal
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-bold transition shadow-lg shadow-blue-500/25">
                    Simpan Jurnal
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: EDIT JURNAL KBM -->
<!-- ============================================================== -->
<div id="modalEditJournal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-xl w-full p-6 text-slate-100 shadow-2xl relative my-8">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-4">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-pen-to-square text-indigo-400"></i>
                Edit Jurnal Mengajar KBM
            </h3>
            <button onclick="closeModal('modalEditJournal')" class="text-slate-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="edit_journal">
            <input type="hidden" name="journal_id" id="edit_journal_id">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Rombel (Kelas)</label>
                    <select name="class_id" id="edit_class_id" required class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Mata Pelajaran</label>
                    <input type="text" name="subject_name" id="edit_subject_name" required
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Tanggal KBM</label>
                    <input type="date" name="date" id="edit_date" required
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Jam Mulai</label>
                    <input type="time" name="start_time" id="edit_start_time" required
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Jam Selesai</label>
                    <input type="time" name="end_time" id="edit_end_time" required
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Topik / Bab Pokok Bahasan</label>
                <input type="text" name="topic" id="edit_topic" required
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Capaian Kompetensi / Kegiatan Belajar</label>
                <textarea name="competency_notes" id="edit_competency_notes" rows="2.5"
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-white focus:outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Rekap Kehadiran Siswa</label>
                    <input type="text" name="student_attendance_summary" id="edit_student_attendance_summary"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Status KBM</label>
                    <select name="status" id="edit_status" onchange="toggleSubstituteTeacher(this.value, 'editSubDiv')"
                            class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        <option value="terlaksana">Terlaksana Penuh</option>
                        <option value="inval">Inval (Digantikan Guru Lain)</option>
                        <option value="kendala">Terkendala / Tidak Tuntas</option>
                    </select>
                </div>
            </div>

            <div id="editSubDiv" class="hidden">
                <label class="block font-semibold text-amber-300 mb-1">Guru Pengganti (Inval)</label>
                <select name="substitute_teacher_id" id="edit_substitute_teacher_id" class="w-full bg-slate-950 border border-amber-500/30 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-amber-500">
                    <option value="">-- Pilih Guru Inval --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Catatan Kejadian / Situasi Kelas</label>
                <input type="text" name="class_condition_notes" id="edit_class_condition_notes"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-blue-500">
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-white/10">
                <button type="button" onclick="closeModal('modalEditJournal')" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition">
                    Batal
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold transition shadow-lg shadow-indigo-500/25">
                    Perbarui Jurnal
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
function toggleSubstituteTeacher(status, divId) {
    const el = document.getElementById(divId);
    if (status === 'inval') {
        el.classList.remove('hidden');
    } else {
        el.classList.add('hidden');
    }
}
function openEditModal(data) {
    document.getElementById('edit_journal_id').value = data.id;
    document.getElementById('edit_class_id').value = data.class_id;
    document.getElementById('edit_subject_name').value = data.subject_name;
    document.getElementById('edit_date').value = data.date;
    document.getElementById('edit_start_time').value = data.start_time.substring(0, 5);
    document.getElementById('edit_end_time').value = data.end_time.substring(0, 5);
    document.getElementById('edit_topic').value = data.topic;
    document.getElementById('edit_competency_notes').value = data.competency_notes || '';
    document.getElementById('edit_student_attendance_summary').value = data.student_attendance_summary || '';
    document.getElementById('edit_class_condition_notes').value = data.class_condition_notes || '';
    document.getElementById('edit_status').value = data.status;
    document.getElementById('edit_substitute_teacher_id').value = data.substitute_teacher_id || '';
    toggleSubstituteTeacher(data.status, 'editSubDiv');
    openModal('modalEditJournal');
}

function autoFillFromSchedule(classId, subjectName, startTime, endTime) {
    const classEl = document.getElementById('create_class_id');
    const subjEl = document.getElementById('create_subject_name');
    const startEl = document.getElementById('create_start_time');
    const endEl = document.getElementById('create_end_time');
    const topicEl = document.getElementById('create_topic');

    if (classEl) classEl.value = classId;
    if (subjEl) subjEl.value = subjectName;
    if (startEl) startEl.value = startTime;
    if (endEl) endEl.value = endTime;
    if (topicEl) {
        topicEl.focus();
        topicEl.classList.add('ring-2', 'ring-blue-500');
        setTimeout(() => topicEl.classList.remove('ring-2', 'ring-blue-500'), 1500);
    }
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
