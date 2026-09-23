<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['guru', 'administrator', 'staf'], true);

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. RESOLUSI KELAS AKTIF
// -------------------------------------------------------------
$classes = $pdo->query("SELECT id, name, grade_level FROM classes ORDER BY grade_level ASC, name ASC")->fetchAll();
$subjects = $pdo->query("SELECT id, name, code FROM subjects ORDER BY name ASC")->fetchAll();
$teachers = $pdo->query("SELECT id, name FROM users WHERE role = 'guru' ORDER BY name ASC")->fetchAll();

// Tentukan kelas default untuk siswa atau orang tua
$default_class_id = null;
if ($user_role === 'siswa') {
    $stmt_sc = $pdo->prepare("SELECT class_id FROM users WHERE id = ?");
    $stmt_sc->execute([$user_id]);
    $default_class_id = $stmt_sc->fetchColumn();
} elseif ($user_role === 'orang_tua') {
    $stmt_pc = $pdo->prepare("
        SELECT u.class_id FROM parent_students ps
        JOIN users u ON ps.student_id = u.id
        WHERE ps.parent_id = ? LIMIT 1
    ");
    $stmt_pc->execute([$user_id]);
    $default_class_id = $stmt_pc->fetchColumn();
}

$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : ($default_class_id ?: ($classes[0]['id'] ?? 1));
$view_mode = $_GET['view'] ?? 'class'; // 'class' atau 'my_teaching' (khusus guru)

if ($user_role === 'guru' && !isset($_GET['class_id']) && !isset($_GET['view'])) {
    $view_mode = 'my_teaching';
}

// -------------------------------------------------------------
// 2. TAMBAH JADWAL (Admin & Guru)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_timetable' && $can_manage) {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $class_id = (int)($_POST['class_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $subject_name = trim($_POST['subject_name'] ?? '');
        $teacher_id = (int)($_POST['teacher_id'] ?? 0);
        $day = $_POST['day'] ?? 'Senin';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $room = trim($_POST['room'] ?? 'R. 101');

        if ($subject_id > 0) {
            foreach ($subjects as $sb) {
                if ($sb['id'] == $subject_id) {
                    $subject_name = $sb['name'];
                    break;
                }
            }
        }

        if (empty($class_id) || empty($subject_name) || empty($teacher_id) || empty($start_time) || empty($end_time)) {
            $message = "Seluruh field wajib diisi lengkap.";
            $message_type = "error";
        } else {
            $stmt_in = $pdo->prepare("
                INSERT INTO timetables (class_id, subject_id, subject_name, teacher_id, day, start_time, end_time, room)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_in->execute([$class_id, $subject_id ?: null, $subject_name, $teacher_id, $day, $start_time, $end_time, $room ?: 'R. Kelas']);
            
            logActivity($pdo, 'CREATE_TIMETABLE', "Menambahkan jadwal $subject_name kelas ID $class_id hari $day");
            $message = "Jadwal pelajaran baru berhasil ditambahkan.";
            $message_type = "success";
            $selected_class_id = $class_id;
        }
    }
}

// -------------------------------------------------------------
// 3. HAPUS JADWAL (Admin & Guru)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_timetable' && $can_manage) {
    if (validateCsrfToken()) {
        $delete_id = (int)($_POST['delete_id'] ?? 0);
        $stmt_del = $pdo->prepare("DELETE FROM timetables WHERE id = ?");
        $stmt_del->execute([$delete_id]);
        
        logActivity($pdo, 'DELETE_TIMETABLE', "Menghapus jadwal ID $delete_id");
        $message = "Jadwal pelajaran berhasil dihapus.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 4. QUERY JADWAL PELAJARAN
// -------------------------------------------------------------
$days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$timetables_by_day = [];
foreach ($days as $d) {
    $timetables_by_day[$d] = [];
}

if ($view_mode === 'my_teaching' && $user_role === 'guru') {
    $stmt_tt = $pdo->prepare("
        SELECT t.*, c.name as class_name, u.name as teacher_name
        FROM timetables t
        JOIN classes c ON t.class_id = c.id
        JOIN users u ON t.teacher_id = u.id
        WHERE t.teacher_id = ?
        ORDER BY FIELD(t.day, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'), t.start_time ASC
    ");
    $stmt_tt->execute([$user_id]);
    $schedule_rows = $stmt_tt->fetchAll();
} else {
    $stmt_tt = $pdo->prepare("
        SELECT t.*, c.name as class_name, u.name as teacher_name
        FROM timetables t
        JOIN classes c ON t.class_id = c.id
        JOIN users u ON t.teacher_id = u.id
        WHERE t.class_id = ?
        ORDER BY FIELD(t.day, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'), t.start_time ASC
    ");
    $stmt_tt->execute([$selected_class_id]);
    $schedule_rows = $stmt_tt->fetchAll();
}

foreach ($schedule_rows as $row) {
    if (isset($timetables_by_day[$row['day']])) {
        $timetables_by_day[$row['day']][] = $row;
    }
}

$page_title = "Jadwal Pelajaran Mingguan";
require_once __DIR__ . "/../includes/header.php";
?>

<!-- Print Stylesheet -->
<style>
@media print {
    header, nav, .no-print, button, form, .badge-action {
        display: none !important;
    }
    body {
        background: white !important;
        color: black !important;
    }
    .print-table {
        border-collapse: collapse !important;
        width: 100% !important;
        color: black !important;
    }
    .print-table th, .print-table td {
        border: 1px solid #333 !important;
        padding: 8px !important;
        color: black !important;
    }
    .card-print {
        border: 1px solid #ccc !important;
        background: none !important;
        color: black !important;
        box-shadow: none !important;
    }
}
</style>

<div class="space-y-6">

    <!-- Header & Action Bar -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between no-print">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2.5">
                <i class="fa-solid fa-calendar-week text-blue-400"></i> Jadwal Pelajaran Mingguan
            </h1>
            <p class="text-sm text-slate-400 mt-1">
                Penyusunan dan monitoring alokasi waktu mata pelajaran, guru pengampu, serta ruangan kelas.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-slate-900 px-3.5 py-2 text-sm font-medium text-slate-200 transition hover:bg-white/5 cursor-pointer">
                <i class="fa-solid fa-print"></i> Cetak Jadwal
            </button>
            <?php if ($can_manage): ?>
                <button onclick="document.getElementById('modalAddSchedule').classList.remove('hidden')" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-blue-500/25 transition hover:bg-blue-500 cursor-pointer">
                    <i class="fa-solid fa-plus"></i> Tambah Jadwal
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($message)): ?>
        <div class="rounded-xl border p-4 <?= $message_type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/20 bg-rose-500/10 text-rose-300' ?> text-sm no-print">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Mode & Filter Bar -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur no-print flex flex-col sm:flex-row gap-4 sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($user_role === 'guru'): ?>
                <a href="timetable.php?view=my_teaching" class="inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-sm font-medium transition <?= $view_mode === 'my_teaching' ? 'bg-blue-600 text-white shadow-md' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                    <i class="fa-solid fa-chalkboard-user"></i> Jadwal Mengajar Saya
                </a>
            <?php endif; ?>

            <div class="flex items-center gap-2">
                <span class="text-xs uppercase font-bold tracking-wider text-slate-400">Pilih Kelas:</span>
                <div class="flex flex-wrap gap-1.5">
                    <?php foreach ($classes as $c): ?>
                        <a href="timetable.php?class_id=<?= $c['id'] ?>&view=class" class="rounded-xl px-3 py-1.5 text-xs font-semibold transition <?= ($view_mode === 'class' && $selected_class_id == $c['id']) ? 'bg-white/10 text-blue-400 border border-blue-500/30 font-bold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200 border border-transparent' ?>">
                            <?= htmlspecialchars($c['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="text-xs text-slate-400 flex items-center gap-2">
            <span>Tahun Ajaran: <strong class="text-slate-200">2026/2027 Ganjil</strong></span>
        </div>
    </div>

    <!-- Print Title Header -->
    <div class="hidden print:block text-center mb-6">
        <h2 class="text-xl font-bold uppercase text-black">SMA BINA BANGSA NUSANTARA</h2>
        <p class="text-sm text-gray-600">Jadwal Pelajaran Kelas <?= htmlspecialchars($classes[array_search($selected_class_id, array_column($classes, 'id'))]['name'] ?? 'Semua') ?> - Tahun Pelajaran 2026/2027</p>
        <hr class="border-black my-2">
    </div>

    <!-- Weekly Timetable Grid (Monday to Saturday) -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($days as $day_name): ?>
            <?php 
                $day_schedules = $timetables_by_day[$day_name] ?? [];
                $is_today = (date('l') === ['Senin'=>'Monday', 'Selasa'=>'Tuesday', 'Rabu'=>'Wednesday', 'Kamis'=>'Thursday', 'Jumat'=>'Friday', 'Sabtu'=>'Saturday'][$day_name]);
            ?>
            <div class="rounded-2xl border <?= $is_today ? 'border-blue-500/40 bg-blue-950/20' : 'border-white/10 bg-slate-900/50' ?> flex flex-col overflow-hidden shadow-lg backdrop-blur card-print">
                <!-- Day Header -->
                <div class="border-b border-white/10 px-4 py-3 flex items-center justify-between <?= $is_today ? 'bg-blue-600/15' : 'bg-slate-950/40' ?>">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-bold text-white"><?= $day_name ?></span>
                        <?php if ($is_today): ?>
                            <span class="rounded-md bg-blue-500/20 border border-blue-500/30 px-2 py-0.5 text-[10px] font-bold text-blue-300 uppercase tracking-wider">
                                Hari Ini
                            </span>
                        <?php endif; ?>
                    </div>
                    <span class="text-xs font-semibold text-slate-400">
                        <?= count($day_schedules) ?> Sesi KBM
                    </span>
                </div>

                <!-- Schedule List -->
                <div class="p-3.5 space-y-3 flex-1">
                    <?php if (empty($day_schedules)): ?>
                        <div class="py-8 text-center text-xs text-slate-500">
                            Tidak ada jadwal kegiatan belajar mengajar pada hari ini.
                        </div>
                    <?php else: ?>
                        <?php foreach ($day_schedules as $idx => $item): ?>
                            <div class="group relative rounded-xl border border-white/5 bg-slate-950/50 p-3 hover:border-white/15 transition flex flex-col justify-between">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="rounded-md bg-white/5 border border-white/10 px-2 py-0.5 text-[11px] font-mono font-semibold text-slate-300">
                                                <i class="fa-regular fa-clock mr-1 text-slate-400"></i><?= substr($item['start_time'], 0, 5) ?> - <?= substr($item['end_time'], 0, 5) ?>
                                            </span>
                                            <span class="rounded-md bg-purple-500/10 border border-purple-500/20 px-1.5 py-0.5 text-[10px] font-bold text-purple-300">
                                                <?= htmlspecialchars($item['room'] ?: 'R. Kelas') ?>
                                            </span>
                                        </div>
                                        <h3 class="text-sm font-bold text-white mt-1.5">
                                            <?= htmlspecialchars($item['subject_name']) ?>
                                        </h3>
                                        <p class="text-xs text-slate-400 mt-0.5 flex items-center gap-1.5">
                                            <span><i class="fa-solid fa-chalkboard-user mr-1 text-slate-500"></i><?= htmlspecialchars($item['teacher_name']) ?></span>
                                            <?php if ($view_mode === 'my_teaching'): ?>
                                                <span class="text-blue-400 font-medium">(Kelas <?= htmlspecialchars($item['class_name']) ?>)</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>

                                    <?php if ($can_manage): ?>
                                        <div class="no-print opacity-0 group-hover:opacity-100 transition">
                                            <form method="POST" onsubmit="return confirm('Hapus jadwal mata pelajaran ini?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_timetable">
                                                <input type="hidden" name="delete_id" value="<?= $item['id'] ?>">
                                                <button type="submit" title="Hapus Jadwal" class="rounded-lg p-1 text-slate-500 hover:text-rose-400 hover:bg-rose-500/10 transition cursor-pointer">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<!-- Modal Tambah Jadwal (Admin & Guru) -->
<?php if ($can_manage): ?>
<div id="modalAddSchedule" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm hidden no-print">
    <div class="w-full max-w-lg rounded-2xl border border-white/10 bg-slate-900 p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-calendar-plus text-blue-400"></i> Tambah Jadwal Pelajaran
            </h3>
            <button onclick="document.getElementById('modalAddSchedule').classList.add('hidden')" class="text-slate-400 hover:text-white cursor-pointer text-lg font-bold">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_timetable">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Pilih Kelas</label>
                    <select name="class_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?= $cl['id'] ?>" <?= $selected_class_id == $cl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Hari Pelaksanaan</label>
                    <select name="day" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                        <?php foreach ($days as $dy): ?>
                            <option value="<?= $dy ?>"><?= $dy ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Mata Pelajaran</label>
                    <select name="subject_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                        <?php foreach ($subjects as $sb): ?>
                            <option value="<?= $sb['id'] ?>"><?= htmlspecialchars($sb['name']) ?> (<?= htmlspecialchars($sb['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Guru Pengampu</label>
                    <select name="teacher_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                        <?php foreach ($teachers as $tc): ?>
                            <option value="<?= $tc['id'] ?>" <?= ($user_role === 'guru' && $user_id == $tc['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tc['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Jam Mulai</label>
                    <input type="time" name="start_time" required value="07:30" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Jam Selesai</label>
                    <input type="time" name="end_time" required value="09:00" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Ruangan</label>
                    <input type="text" name="room" placeholder="Contoh: R. 101" value="R. 101" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalAddSchedule').classList.add('hidden')" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/5 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 cursor-pointer">
                    Simpan Jadwal
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
