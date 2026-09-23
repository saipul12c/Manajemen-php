<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['guru', 'staf', 'administrator'], true);

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. TAMBAH EVENT / AGENDA KEGIATAN (Guru / Staf / Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_event') {
    if (!$can_manage) {
        header("Location: calendar.php?error=unauthorized");
        exit;
    }
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $event_date = $_POST['event_date'] ?? '';
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $category = $_POST['category'] ?? 'kegiatan';
    $color = $_POST['color'] ?? 'blue';

    if ($title === '' || empty($event_date)) {
        $message = "Nama kegiatan dan tanggal mulai wajib diisi.";
        $message_type = "error";
    } else {
        $stmt_in = $pdo->prepare("
            INSERT INTO calendar_events (title, description, event_date, end_date, category, color, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_in->execute([$title, $description ?: null, $event_date, $end_date, $category, $color, $user_id]);
        $message = "Agenda kegiatan baru berhasil ditambahkan ke kalender.";
        $message_type = "success";
    }
    } // end CSRF check
}

// -------------------------------------------------------------
// 2. HAPUS EVENT (Guru / Staf / Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_event' && $can_manage) {
    if (validateCsrfToken()) {
        $del_id = (int) ($_POST['delete_id'] ?? 0);
        $query_del = "DELETE FROM calendar_events WHERE id = ?";
        $params_del = [$del_id];
        if ($user_role !== 'administrator') {
            $query_del .= " AND created_by = ?";
            $params_del[] = $user_id;
        }
        $stmt_del = $pdo->prepare($query_del);
        $stmt_del->execute($params_del);

        $message = "Agenda kegiatan berhasil dihapus.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 3. RESOLUSI BULAN & TAHUN
// -------------------------------------------------------------
$req_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$req_year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Validasi batas tahun & bulan
if ($req_month < 1) {
    $req_month = 12;
    $req_year--;
} elseif ($req_month > 12) {
    $req_month = 1;
    $req_year++;
}

$month_name_id = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$month_title = $month_name_id[$req_month] . " " . $req_year;

// Navigasi
$prev_month = $req_month - 1;
$prev_year  = $req_year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

$next_month = $req_month + 1;
$next_year  = $req_year;
if ($next_month > 12) { $next_month = 1; $next_year++; }

// Hari dalam bulan
$first_day_timestamp = mktime(0, 0, 0, $req_month, 1, $req_year);
$total_days_in_month = (int)date('t', $first_day_timestamp);
// 1 = Senin, 7 = Minggu (ISO-8601)
$first_day_of_week   = (int)date('N', $first_day_timestamp);

$start_month_date = sprintf('%04d-%02d-01', $req_year, $req_month);
$end_month_date   = sprintf('%04d-%02d-%02d', $req_year, $req_month, $total_days_in_month);

// -------------------------------------------------------------
// 4. AGREGASI AGENDA TERPADU (Events, Exams, Assignments)
// -------------------------------------------------------------
$calendar_data = []; // Key: 'YYYY-MM-DD' => array of events

// 4a. Ambil event dari tabel `calendar_events`
$stmt_ev = $pdo->prepare("
    SELECT e.*, u.name as author_name 
    FROM calendar_events e
    JOIN users u ON e.created_by = u.id
    WHERE (e.event_date <= ? AND (e.end_date >= ? OR e.end_date IS NULL))
       OR (e.event_date BETWEEN ? AND ?)
    ORDER BY e.event_date ASC
");
$stmt_ev->execute([$end_month_date, $start_month_date, $start_month_date, $end_month_date]);
$events = $stmt_ev->fetchAll();

foreach ($events as $ev) {
    $ev_start = $ev['event_date'];
    $ev_end   = $ev['end_date'] ?: $ev['event_date'];

    // Looping rentang hari untuk event multi-hari
    $curr = max($ev_start, $start_month_date);
    $limit = min($ev_end, $end_month_date);

    while ($curr <= $limit) {
        $calendar_data[$curr][] = [
            'id'          => $ev['id'],
            'type'        => 'event',
            'title'       => $ev['title'],
            'description' => $ev['description'],
            'category'    => $ev['category'],
            'color'       => $ev['color'],
            'author_name' => $ev['author_name'],
            'created_by'  => $ev['created_by'],
            'is_multi'    => ($ev['end_date'] && $ev['end_date'] !== $ev['event_date']),
        ];
        $curr = date('Y-m-d', strtotime($curr . ' +1 day'));
    }
}

// 4b. Ambil jadwal Ujian dari tabel `exams`
$stmt_ex = $pdo->prepare("
    SELECT id, title, subject, category, due_date, start_time 
    FROM exams 
    WHERE (DATE(due_date) BETWEEN ? AND ?) 
       OR (start_time IS NOT NULL AND DATE(start_time) BETWEEN ? AND ?)
");
$stmt_ex->execute([$start_month_date, $end_month_date, $start_month_date, $end_month_date]);
while ($ex = $stmt_ex->fetch()) {
    $ex_date = date('Y-m-d', strtotime($ex['due_date']));
    $calendar_data[$ex_date][] = [
        'id'          => $ex['id'],
        'type'        => 'exam',
        'title'       => "[Ujian] " . $ex['subject'] . " - " . $ex['title'],
        'description' => "Batas pengerjaan asesmen: " . date('H:i', strtotime($ex['due_date'])) . " WIB",
        'category'    => 'ujian',
        'color'       => 'purple',
        'url'         => '../Modul-ujian/exams.php',
    ];
}

// 4c. Ambil batas pengumpulan Tugas dari tabel `assignments`
$stmt_as = $pdo->prepare("
    SELECT id, subject, title, due_date 
    FROM assignments 
    WHERE due_date BETWEEN ? AND ?
");
$stmt_as->execute([$start_month_date, $end_month_date]);
while ($as = $stmt_as->fetch()) {
    $as_date = $as['due_date'];
    $calendar_data[$as_date][] = [
        'id'          => $as['id'],
        'type'        => 'assignment',
        'title'       => "[Tugas] " . $as['subject'] . " - " . $as['title'],
        'description' => "Batas akhir pengumpulan tugas belajar",
        'category'    => 'akademik',
        'color'       => 'blue',
        'url'         => 'assignments.php',
    ];
}

// 4d. Agenda terdekat (Next 5 upcoming events global)
$stmt_upcoming = $pdo->query("
    SELECT * FROM (
        SELECT id, title, event_date as date, category, 'event' as src FROM calendar_events WHERE event_date >= CURRENT_DATE()
        UNION ALL
        SELECT id, CONCAT('[Ujian] ', subject, ' - ', title) as title, DATE(due_date) as date, 'ujian' as category, 'exam' as src FROM exams WHERE due_date >= CURRENT_TIMESTAMP
        UNION ALL
        SELECT id, CONCAT('[Tugas] ', subject, ' - ', title) as title, due_date as date, 'akademik' as category, 'assignment' as src FROM assignments WHERE due_date >= CURRENT_DATE()
    ) as combined
    ORDER BY date ASC
    LIMIT 6
");
$upcoming_agenda = $stmt_upcoming->fetchAll();

$page_title = "Kalender Akademik";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-500/20 text-blue-400 text-lg border border-blue-500/30 shadow-lg shadow-blue-500/10">
                <i class="fa-solid fa-calendar-days"></i>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Kalender Akademik & Agenda</h1>
                <p class="text-sm text-slate-400">Jadwal kegiatan sekolah, tenggat tugas, dan periode ujian terpadu</p>
            </div>
        </div>
    </div>

    <?php if ($can_manage): ?>
        <button onclick="document.getElementById('modalAddEvent').classList.remove('hidden')" 
                class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition hover:from-blue-500 hover:to-indigo-500 cursor-pointer">
            <i class="fa-solid fa-plus"></i>
            <span>Tambah Agenda Sekolah</span>
        </button>
    <?php endif; ?>
</div>

<!-- Alert Notifikasi -->
<?php if ($message): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <span><?= $message_type === 'success' ? '<i class="fa-solid fa-circle-check text-emerald-400"></i>' : '<i class="fa-solid fa-triangle-exclamation text-rose-400"></i>' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<!-- Navigasi Kalender & Kategori Legend -->
<div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur shadow-xl mb-6">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        
        <!-- Bulan & Tombol Navigasi -->
        <div class="flex items-center gap-3">
            <h2 class="text-xl font-bold text-white tracking-tight min-w-[180px]">
                <?= $month_title ?>
            </h2>
            <div class="flex items-center gap-1 bg-white/5 border border-white/10 p-1 rounded-xl">
                <a href="calendar.php?month=<?= $prev_month ?>&year=<?= $prev_year ?>" 
                   title="Bulan Sebelumnya"
                   class="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition">
                    ◀
                </a>
                <a href="calendar.php" 
                   title="Bulan Ini"
                   class="px-3 py-1.5 rounded-lg text-xs font-semibold text-slate-300 hover:text-white hover:bg-white/10 transition">
                    Bulan Ini
                </a>
                <a href="calendar.php?month=<?= $next_month ?>&year=<?= $next_year ?>" 
                   title="Bulan Berikutnya"
                   class="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition">
                    ▶
                </a>
            </div>
        </div>

        <!-- Legend Label -->
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-blue-500/30 bg-blue-500/10 text-blue-300 font-medium">
                <span class="h-2 w-2 rounded-full bg-blue-400"></span> Akademik / Tugas
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-purple-500/30 bg-purple-500/10 text-purple-300 font-medium">
                <span class="h-2 w-2 rounded-full bg-purple-400"></span> Ujian / Asesmen
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 font-medium">
                <span class="h-2 w-2 rounded-full bg-emerald-400"></span> Kegiatan Sekolah
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-rose-500/30 bg-rose-500/10 text-rose-300 font-medium">
                <span class="h-2 w-2 rounded-full bg-rose-400"></span> Hari Libur
            </span>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-4 gap-6">
    
    <!-- GRID KALENDER BULANAN (3 Kolom di layar besar) -->
    <div class="xl:col-span-3">
        <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-xl backdrop-blur">
            
            <!-- Nama Hari (Senin - Minggu) -->
            <div class="grid grid-cols-7 border-b border-white/10 bg-white/5 text-center text-xs font-bold uppercase tracking-wider text-slate-400">
                <div class="py-3">Sen</div>
                <div class="py-3">Sel</div>
                <div class="py-3">Rab</div>
                <div class="py-3">Kam</div>
                <div class="py-3">Jum</div>
                <div class="py-3 text-amber-400">Sab</div>
                <div class="py-3 text-rose-400">Min</div>
            </div>

            <!-- Grid Sel Hari -->
            <div class="grid grid-cols-7 divide-x divide-y divide-white/5">
                <?php
                // 1. Kotak kosong sebelum hari pertama (Senin = 1, jadi jika hari pertama = 3, cetak 2 kotak kosong)
                for ($blank = 1; $blank < $first_day_of_week; $blank++) {
                    echo '<div class="min-h-[105px] sm:min-h-[120px] bg-slate-950/40 p-2 opacity-30"></div>';
                }

                // 2. Kotak untuk masing-masing hari dalam bulan
                $today_str = date('Y-m-d');

                for ($day = 1; $day <= $total_days_in_month; $day++) {
                    $curr_date_str = sprintf('%04d-%02d-%02d', $req_year, $req_month, $day);
                    $is_today = ($curr_date_str === $today_str);
                    $day_events = $calendar_data[$curr_date_str] ?? [];
                    
                    // Cek apakah hari Minggu
                    $is_sunday = (date('N', strtotime($curr_date_str)) == 7);
                ?>
                    <div class="group relative min-h-[105px] sm:min-h-[120px] p-2 sm:p-2.5 transition hover:bg-white/[0.02] <?= $is_today ? 'bg-blue-600/10' : '' ?>">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-lg text-xs font-bold font-mono <?= $is_today ? 'bg-blue-600 text-white shadow-md shadow-blue-500/50' : ($is_sunday ? 'text-rose-400' : 'text-slate-300') ?>">
                                <?= $day ?>
                            </span>

                            <?php if (!empty($day_events)): ?>
                                <span class="text-[10px] font-bold text-slate-500 font-mono">
                                    <?= count($day_events) ?> agd
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Daftar Badge Event pada Tanggal Ini -->
                        <div class="space-y-1">
                            <?php 
                            $max_display = 2;
                            $shown = 0;
                            foreach ($day_events as $idx => $ev_item): 
                                if ($shown >= $max_display) {
                                    $remaining = count($day_events) - $max_display;
                                    echo "<span class='block text-[10px] text-slate-400 font-medium pl-1'>+{$remaining} lainnya...</span>";
                                    break;
                                }
                                $shown++;

                                $badge_class = 'border-slate-500/30 bg-slate-500/20 text-slate-300';
                                if ($ev_item['category'] === 'libur') $badge_class = 'border-rose-500/40 bg-rose-500/20 text-rose-200';
                                elseif ($ev_item['category'] === 'ujian') $badge_class = 'border-purple-500/40 bg-purple-500/20 text-purple-200';
                                elseif ($ev_item['category'] === 'akademik') $badge_class = 'border-blue-500/40 bg-blue-500/20 text-blue-200';
                                elseif ($ev_item['category'] === 'kegiatan') $badge_class = 'border-emerald-500/40 bg-emerald-500/20 text-emerald-200';
                            ?>
                                <div class="cursor-pointer truncate rounded-md border px-1.5 py-0.5 text-[11px] font-medium leading-tight transition hover:opacity-80 <?= $badge_class ?>"
                                     onclick="showEventDetail(<?= htmlspecialchars(json_encode($ev_item)) ?>, '<?= $curr_date_str ?>')"
                                     title="<?= htmlspecialchars($ev_item['title']) ?>">
                                    <?= htmlspecialchars($ev_item['title']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php
                }

                // 3. Kotak kosong setelah hari terakhir
                $last_day_of_week = (int)date('N', mktime(0, 0, 0, $req_month, $total_days_in_month, $req_year));
                for ($blank_end = $last_day_of_week + 1; $blank_end <= 7; $blank_end++) {
                    echo '<div class="min-h-[105px] sm:min-h-[120px] bg-slate-950/40 p-2 opacity-30"></div>';
                }
                ?>
            </div>

        </div>
    </div>

    <!-- SIDEBAR: AGENDA TERDEKAT & INFORMASI -->
    <div class="space-y-6">
        
        <!-- Agenda Mendatang -->
        <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-6 shadow-xl backdrop-blur">
            <h3 class="text-base font-bold text-white tracking-tight mb-4 flex items-center gap-2">
                <i class="fa-regular fa-clock text-amber-400"></i>
                <span>Agenda Mendatang</span>
            </h3>

            <div class="space-y-3">
                <?php if (empty($upcoming_agenda)): ?>
                    <p class="text-xs text-slate-400 py-4 text-center">Tidak ada agenda terdekat dalam waktu dekat.</p>
                <?php else: ?>
                    <?php foreach ($upcoming_agenda as $up): 
                        $days_diff = (int) round((strtotime($up['date']) - strtotime(date('Y-m-d'))) / 86400);
                        $badge_class = 'text-blue-400 border-blue-500/30 bg-blue-500/10';
                        if ($up['category'] === 'libur') $badge_class = 'text-rose-400 border-rose-500/30 bg-rose-500/10';
                        elseif ($up['category'] === 'ujian') $badge_class = 'text-purple-400 border-purple-500/30 bg-purple-500/10';
                        elseif ($up['category'] === 'kegiatan') $badge_class = 'text-emerald-400 border-emerald-500/30 bg-emerald-500/10';
                    ?>
                        <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-3.5 transition hover:border-white/10">
                            <div class="flex items-center justify-between gap-2 mb-1.5">
                                <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-md border <?= $badge_class ?>">
                                    <?= htmlspecialchars($up['category']) ?>
                                </span>
                                <span class="text-xs text-slate-400 font-mono">
                                    <?= $days_diff === 0 ? 'Hari ini' : ($days_diff === 1 ? 'Besok' : "$days_diff hari lagi") ?>
                                </span>
                            </div>
                            <p class="font-semibold text-sm text-white line-clamp-2"><?= htmlspecialchars($up['title']) ?></p>
                            <p class="text-xs text-slate-500 mt-1 flex items-center gap-1.5">
                                <i class="fa-regular fa-calendar"></i> <?= date('d M Y', strtotime($up['date'])) ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tips Penggunaan -->
        <div class="rounded-3xl border border-white/10 bg-gradient-to-br from-blue-900/20 to-slate-900/40 p-6 shadow-xl backdrop-blur">
            <h4 class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                <i class="fa-solid fa-lightbulb text-amber-400"></i>
                <span>Integrasi Otomatis</span>
            </h4>
            <p class="text-xs leading-relaxed text-slate-400">
                Semua tanggal batas waktu tugas (<em class="text-slate-300">assignments</em>) dan ujian aktif (<em class="text-slate-300">exams</em>) otomatis muncul di kalender tanpa perlu diinput manual dua kali.
            </p>
        </div>

    </div>

</div>

<!-- ========================================================= -->
<!-- MODAL: DETAIL EVENT / AGENDA -->
<!-- ========================================================= -->
<div id="modalEventDetail" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 backdrop-blur-sm p-4">
    <div class="w-full max-w-md rounded-3xl border border-white/10 bg-slate-900 p-6 shadow-2xl">
        <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-4">
            <div class="flex items-center gap-2">
                <span id="detailCategoryBadge" class="text-xs font-bold uppercase tracking-wider px-2.5 py-1 rounded-lg border"></span>
            </div>
            <button onclick="document.getElementById('modalEventDetail').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <h3 id="detailTitle" class="text-lg font-bold text-white mb-2"></h3>
        <p id="detailDate" class="text-xs text-slate-400 mb-4 flex items-center gap-1.5 font-mono"></p>

        <div class="rounded-2xl border border-white/5 bg-slate-950/70 p-4 mb-6">
            <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold mb-1">Keterangan:</p>
            <p id="detailDesc" class="text-sm text-slate-300 leading-relaxed"></p>
        </div>

        <div id="detailAction" class="flex items-center justify-between">
            <button type="button" 
                    onclick="document.getElementById('modalEventDetail').classList.add('hidden')" 
                    class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10">
                Tutup
            </button>
            <div id="detailDeleteBtn"></div>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL: TAMBAH AGENDA BARU (Guru / Staf / Admin) -->
<!-- ========================================================= -->
<?php if ($can_manage): ?>
<div id="modalAddEvent" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 backdrop-blur-sm p-4 overflow-y-auto">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 shadow-2xl my-8">
        <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-calendar-plus text-blue-400"></i>
                <span>Tambah Agenda Sekolah</span>
            </h3>
            <button onclick="document.getElementById('modalAddEvent').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" action="calendar.php?month=<?= $req_month ?>&year=<?= $req_year ?>" class="space-y-4">
            <input type="hidden" name="action" value="create_event">
            <?= csrfField() ?>

            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Nama Kegiatan / Agenda *</label>
                <input type="text" name="title" required placeholder="Contoh: Rapat Wali Murid Semester Ganjil" 
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Tanggal Mulai *</label>
                    <input type="date" name="event_date" required value="<?= date('Y-m-d') ?>"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Tanggal Selesai (Opsional)</label>
                    <input type="date" name="end_date"
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Kategori Agenda</label>
                    <select name="category" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
                        <option value="kegiatan">Kegiatan Sekolah</option>
                        <option value="akademik">Akademik</option>
                        <option value="libur">Hari Libur / Cuti</option>
                        <option value="ujian">Ujian / Evaluasi</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Warna Tanda</label>
                    <select name="color" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
                        <option value="emerald">Hijau (Emerald)</option>
                        <option value="blue">Biru (Blue)</option>
                        <option value="purple">Ungu (Purple)</option>
                        <option value="rose">Merah (Rose)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Keterangan / Deskripsi</label>
                <textarea name="description" rows="3" placeholder="Rincian tempat, susunan acara, atau instruksi..." 
                          class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none"></textarea>
            </div>

            <div class="pt-4 flex items-center justify-end gap-3 border-t border-white/10">
                <button type="button" 
                        onclick="document.getElementById('modalAddEvent').classList.add('hidden')"
                        class="rounded-xl border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-semibold text-slate-300 hover:bg-white/10">
                    Batal
                </button>
                <button type="submit" 
                        class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 hover:from-blue-500 hover:to-indigo-500">
                    Simpan Agenda
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function showEventDetail(item, dateStr) {
    document.getElementById('detailTitle').textContent = item.title;
    document.getElementById('detailDate').innerHTML = '<i class="fa-regular fa-calendar mr-1.5"></i>' + dateStr;
    document.getElementById('detailDesc').textContent = item.description || 'Tidak ada catatan tambahan untuk agenda ini.';

    const catBadge = document.getElementById('detailCategoryBadge');
    catBadge.textContent = item.category.toUpperCase();

    if (item.category === 'libur') {
        catBadge.className = 'text-xs font-bold uppercase tracking-wider px-2.5 py-1 rounded-lg border border-rose-500/30 bg-rose-500/10 text-rose-300';
    } else if (item.category === 'ujian') {
        catBadge.className = 'text-xs font-bold uppercase tracking-wider px-2.5 py-1 rounded-lg border border-purple-500/30 bg-purple-500/10 text-purple-300';
    } else if (item.category === 'akademik') {
        catBadge.className = 'text-xs font-bold uppercase tracking-wider px-2.5 py-1 rounded-lg border border-blue-500/30 bg-blue-500/10 text-blue-300';
    } else {
        catBadge.className = 'text-xs font-bold uppercase tracking-wider px-2.5 py-1 rounded-lg border border-emerald-500/30 bg-emerald-500/10 text-emerald-300';
    }

    const delContainer = document.getElementById('detailDeleteBtn');
    delContainer.innerHTML = '';

    <?php if ($can_manage): ?>
    if (item.type === 'event') {
        const delForm = document.createElement('form');
        delForm.method = 'POST';
        delForm.action = 'calendar.php?month=<?= $req_month ?>&year=<?= $req_year ?>';
        delForm.innerHTML = '<input type="hidden" name="action" value="delete_event">' +
            '<input type="hidden" name="delete_id" value="' + item.id + '">' +
            '<?= csrfField() ?>';
        delForm.onsubmit = function() {
            return confirm('Apakah Anda yakin ingin menghapus agenda kegiatan ini?');
        };
        const delBtn = document.createElement('button');
        delBtn.type = 'submit';
        delBtn.className = 'inline-flex items-center gap-1.5 rounded-xl border border-rose-500/30 bg-rose-500/10 px-3.5 py-2 text-xs font-semibold text-rose-300 hover:bg-rose-500/20 cursor-pointer';
        delBtn.innerHTML = '<i class="fa-solid fa-trash"></i> Hapus Agenda';
        delForm.appendChild(delBtn);
        delContainer.appendChild(delForm);
    }
    <?php endif; ?>

    if (item.url) {
        const linkBtn = document.createElement('a');
        linkBtn.href = item.url;
        linkBtn.className = 'inline-flex items-center gap-1.5 rounded-xl bg-blue-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-blue-500 cursor-pointer';
        linkBtn.innerHTML = 'Buka Halaman <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>';
        delContainer.appendChild(linkBtn);
    }

    const modal = document.getElementById('modalEventDetail');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
