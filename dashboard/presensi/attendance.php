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
// 1. PROSES SIMPAN / UPDATE PRESENSI (Guru / Staf / Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    if (!$can_manage) {
        header("Location: attendance.php?error=unauthorized");
        exit;
    }
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {

    $attendance_date = $_POST['attendance_date'] ?? date('Y-m-d');
    $statuses = $_POST['status'] ?? [];
    $notes = $_POST['notes'] ?? [];

    if (empty($attendance_date)) {
        $message = "Tanggal presensi tidak valid.";
        $message_type = "error";
    } else {
        try {
            $pdo->beginTransaction();

            $stmt_upsert = $pdo->prepare("
                INSERT INTO student_attendance (student_id, date, status, notes, recorded_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    notes = VALUES(notes),
                    recorded_by = VALUES(recorded_by)
            ");

            foreach ($statuses as $student_id => $st) {
                $st_val = in_array($st, ['hadir', 'sakit', 'izin', 'alpa'], true) ? $st : 'hadir';
                $note_val = trim($notes[$student_id] ?? '');
                $stmt_upsert->execute([(int)$student_id, $attendance_date, $st_val, $note_val ?: null, $user_id]);
            }

            $pdo->commit();
            $message = "Presensi tanggal " . date('d M Y', strtotime($attendance_date)) . " berhasil disimpan!";
            $message_type = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Gagal menyimpan presensi: " . $e->getMessage();
            $message_type = "error";
        }
    }
    } // end CSRF check
}

// -------------------------------------------------------------
// 2. DATA UNTUK GURU / STAF / ADMIN
// -------------------------------------------------------------
$selected_date = $_GET['date'] ?? date('Y-m-d');
$active_tab = $_GET['tab'] ?? 'input'; // 'input' atau 'rekap'
$selected_month = $_GET['month'] ?? date('m');
$selected_year = $_GET['year'] ?? date('Y');

$students = [];
$daily_attendance = [];
$monthly_recap = [];

if ($can_manage) {
    // Ambil semua siswa
    $stmt_students = $pdo->query("SELECT id, name, email, phone FROM users WHERE role = 'siswa' ORDER BY name ASC");
    $students = $stmt_students->fetchAll();

    // Presensi pada tanggal terpilih
    $stmt_daily = $pdo->prepare("SELECT student_id, status, notes FROM student_attendance WHERE date = ?");
    $stmt_daily->execute([$selected_date]);
    while ($row = $stmt_daily->fetch()) {
        $daily_attendance[$row['student_id']] = [
            'status' => $row['status'],
            'notes' => $row['notes']
        ];
    }

    // Rekapitulasi bulanan
    $stmt_recap = $pdo->prepare("
        SELECT 
            u.id as student_id,
            u.name as student_name,
            u.email as student_email,
            COUNT(CASE WHEN sa.status = 'hadir' THEN 1 END) as total_hadir,
            COUNT(CASE WHEN sa.status = 'sakit' THEN 1 END) as total_sakit,
            COUNT(CASE WHEN sa.status = 'izin' THEN 1 END) as total_izin,
            COUNT(CASE WHEN sa.status = 'alpa' THEN 1 END) as total_alpa,
            COUNT(sa.id) as total_recorded
        FROM users u
        LEFT JOIN student_attendance sa ON u.id = sa.student_id 
            AND MONTH(sa.date) = ? AND YEAR(sa.date) = ?
        WHERE u.role = 'siswa'
        GROUP BY u.id, u.name, u.email
        ORDER BY u.name ASC
    ");
    $stmt_recap->execute([$selected_month, $selected_year]);
    $monthly_recap = $stmt_recap->fetchAll();
} else {
    // -------------------------------------------------------------
    // 3. DATA UNTUK SISWA & ORANG TUA
    // -------------------------------------------------------------
    // BUG-12 fix: Jangan fallback ke siswa random. Cari siswa yang memang terhubung via parent_students
    $target_student_id = $user_id;
    $linked_student = null;
    if ($user_role === 'orang_tua') {
        try {
            $stmt_child = $pdo->prepare("
                SELECT u.id, u.name, u.nisn 
                FROM parent_students ps
                JOIN users u ON ps.student_id = u.id
                WHERE ps.parent_id = ? 
                LIMIT 1
            ");
            $stmt_child->execute([$user_id]);
            $linked_student = $stmt_child->fetch();
        } catch (Exception $e) {}

        if ($linked_student) {
            $target_student_id = (int) $linked_student['id'];
        } else {
            $target_student_id = 0; // Tidak ada siswa terhubung
        }
    }

    // Riwayat presensi siswa
    $stmt_history = $pdo->prepare("
        SELECT sa.*, r.name as recorded_by_name
        FROM student_attendance sa
        LEFT JOIN users r ON sa.recorded_by = r.id
        WHERE sa.student_id = ?
        ORDER BY sa.date DESC
        LIMIT 60
    ");
    $stmt_history->execute([$target_student_id]);
    $student_history = $stmt_history->fetchAll();

    // Ringkasan metrik kehadiran
    $stmt_stats = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir,
            COUNT(CASE WHEN status = 'sakit' THEN 1 END) as sakit,
            COUNT(CASE WHEN status = 'izin' THEN 1 END) as izin,
            COUNT(CASE WHEN status = 'alpa' THEN 1 END) as alpa,
            COUNT(*) as total
        FROM student_attendance
        WHERE student_id = ?
    ");
    $stmt_stats->execute([$target_student_id]);
    $student_stats = $stmt_stats->fetch() ?: ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alpa' => 0, 'total' => 0];
    
    $total_days = (int) ($student_stats['total'] ?? 0);
    $hadir_days = (int) ($student_stats['hadir'] ?? 0);
    $attendance_rate = $total_days > 0 ? round(($hadir_days / $total_days) * 100, 1) : 100;
}

$page_title = "Presensi Siswa";
require_once __DIR__ . "/../includes/header.php";

// Helper nama hari dalam bahasa Indonesia
if (!function_exists('hariIndo')) {
    function hariIndo($dateStr) {
        $days = [
            'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
        ];
        $dayEng = date('l', strtotime($dateStr));
        return $days[$dayEng] ?? $dayEng;
    }
}
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-500/20 text-emerald-400 text-lg border border-emerald-500/30 shadow-lg shadow-emerald-500/10">
                <i class="fa-solid fa-calendar-check"></i>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Presensi Siswa</h1>
                <p class="text-sm text-slate-400">
                    <?= $can_manage ? "Pencatatan kehadiran harian dan rekapitulasi statistik kehadiran siswa" : "Pantau riwayat dan statistik kehadiran belajar di sekolah" ?>
                </p>
            </div>
        </div>
    </div>

    <?php if ($can_manage): ?>
        <div class="flex items-center gap-2 bg-white/5 border border-white/10 p-1.5 rounded-2xl">
            <a href="attendance.php?tab=input&date=<?= htmlspecialchars($selected_date) ?>" 
               class="px-4 py-2 rounded-xl text-sm font-medium transition inline-flex items-center gap-2 <?= $active_tab === 'input' ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/30' : 'text-slate-400 hover:text-white' ?>">
                <i class="fa-solid fa-pen-to-square"></i> Input Presensi
            </a>
            <a href="attendance.php?tab=rekap&month=<?= $selected_month ?>&year=<?= $selected_year ?>" 
               class="px-4 py-2 rounded-xl text-sm font-medium transition inline-flex items-center gap-2 <?= $active_tab === 'rekap' ? 'bg-blue-600 text-white shadow-lg shadow-blue-600/30' : 'text-slate-400 hover:text-white' ?>">
                <i class="fa-solid fa-chart-column"></i> Rekapitulasi
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Alert Notifikasi -->
<?php if ($message): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <span class="text-base"><i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-amber-400' ?>"></i></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<?php if ($can_manage && $active_tab === 'input'): ?>
    <!-- ========================================================= -->
    <!-- VIEW GURU / STAF / ADMIN: INPUT PRESENSI HARIAN -->
    <!-- ========================================================= -->
    
    <!-- Filter Tanggal & Aksi Cepat -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-6 backdrop-blur shadow-xl mb-6">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <form method="GET" action="attendance.php" class="flex flex-wrap items-center gap-3">
                <input type="hidden" name="tab" value="input">
                <label for="date" class="text-sm font-semibold text-slate-300 flex items-center gap-2">
                    <span>Pilih Tanggal:</span>
                </label>
                <input type="date" 
                       id="date"
                       name="date" 
                       value="<?= htmlspecialchars($selected_date) ?>" 
                       onchange="this.form.submit()"
                       class="rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                <span class="text-xs font-medium text-slate-400 bg-white/5 border border-white/10 px-3 py-2 rounded-xl">
                    <?= hariIndo($selected_date) ?>, <?= date('d M Y', strtotime($selected_date)) ?>
                </span>
            </form>

            <div class="flex items-center gap-2">
                <button type="button" 
                        onclick="markAllStatus('hadir')"
                        class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-2 text-xs font-semibold text-emerald-300 transition hover:bg-emerald-500/20 shadow-sm inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-check-double"></i> Tandai Semua Hadir
                </button>
                <button type="button" 
                        onclick="markAllStatus('izin')"
                        class="rounded-xl border border-blue-500/30 bg-blue-500/10 px-3.5 py-2 text-xs font-semibold text-blue-300 transition hover:bg-blue-500/20 shadow-sm inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-envelope"></i> Semua Izin
                </button>
            </div>
        </div>
    </div>

    <!-- Form Input Presensi -->
    <form method="POST" action="attendance.php?tab=input&date=<?= htmlspecialchars($selected_date) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_attendance">
        <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($selected_date) ?>">

        <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-xl backdrop-blur">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-6 py-4">No</th>
                            <th class="px-6 py-4">Nama Siswa</th>
                            <th class="px-6 py-4 text-center">Status Kehadiran</th>
                            <th class="px-6 py-4">Keterangan / Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-slate-400">
                                    <span class="text-3xl block mb-2 text-slate-500"><i class="fa-solid fa-users"></i></span>
                                    Belum ada data akun siswa yang terdaftar di sistem.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $index => $stu): 
                                $current_status = $daily_attendance[$stu['id']]['status'] ?? 'hadir';
                                $current_notes = $daily_attendance[$stu['id']]['notes'] ?? '';
                            ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="px-6 py-4 font-mono text-xs text-slate-500">
                                        <?= $index + 1 ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600/20 text-blue-400 font-bold text-sm border border-blue-500/20">
                                                <?= strtoupper(substr($stu['name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <p class="font-semibold text-white"><?= htmlspecialchars($stu['name']) ?></p>
                                                <p class="text-xs text-slate-400"><?= htmlspecialchars($stu['email']) ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center justify-center gap-2">
                                            <!-- Hadir -->
                                            <label class="cursor-pointer inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-white/5 bg-slate-950/80 text-xs font-medium text-slate-300 has-[:checked]:border-emerald-500/50 has-[:checked]:bg-emerald-500/20 has-[:checked]:text-emerald-300 transition">
                                                <input type="radio" 
                                                       name="status[<?= $stu['id'] ?>]" 
                                                       value="hadir" 
                                                       class="radio-status radio-hadir accent-emerald-500" 
                                                       <?= $current_status === 'hadir' ? 'checked' : '' ?>>
                                                <span>Hadir</span>
                                            </label>

                                            <!-- Sakit -->
                                            <label class="cursor-pointer inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-white/5 bg-slate-950/80 text-xs font-medium text-slate-300 has-[:checked]:border-amber-500/50 has-[:checked]:bg-amber-500/20 has-[:checked]:text-amber-300 transition">
                                                <input type="radio" 
                                                       name="status[<?= $stu['id'] ?>]" 
                                                       value="sakit" 
                                                       class="radio-status radio-sakit accent-amber-500" 
                                                       <?= $current_status === 'sakit' ? 'checked' : '' ?>>
                                                <span>Sakit</span>
                                            </label>

                                            <!-- Izin -->
                                            <label class="cursor-pointer inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-white/5 bg-slate-950/80 text-xs font-medium text-slate-300 has-[:checked]:border-blue-500/50 has-[:checked]:bg-blue-500/20 has-[:checked]:text-blue-300 transition">
                                                <input type="radio" 
                                                       name="status[<?= $stu['id'] ?>]" 
                                                       value="izin" 
                                                       class="radio-status radio-izin accent-blue-500" 
                                                       <?= $current_status === 'izin' ? 'checked' : '' ?>>
                                                <span>Izin</span>
                                            </label>

                                            <!-- Alpa -->
                                            <label class="cursor-pointer inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-white/5 bg-slate-950/80 text-xs font-medium text-slate-300 has-[:checked]:border-rose-500/50 has-[:checked]:bg-rose-500/20 has-[:checked]:text-rose-300 transition">
                                                <input type="radio" 
                                                       name="status[<?= $stu['id'] ?>]" 
                                                       value="alpa" 
                                                       class="radio-status radio-alpa accent-rose-500" 
                                                       <?= $current_status === 'alpa' ? 'checked' : '' ?>>
                                                <span>Alpa</span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <input type="text" 
                                               name="notes[<?= $stu['id'] ?>]" 
                                               value="<?= htmlspecialchars($current_notes) ?>" 
                                               placeholder="Opsional (cth: Surat dokter / Acara keluarga)" 
                                               class="w-full max-w-xs rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="border-t border-white/10 bg-slate-950/50 p-4 sm:p-6 flex items-center justify-between">
                <p class="text-xs text-slate-400">
                    Total Siswa: <strong class="text-white"><?= count($students) ?></strong> anak
                </p>
                <button type="submit" 
                        class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition hover:from-blue-500 hover:to-indigo-500 inline-flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Presensi Hari Ini
                </button>
            </div>
        </div>
    </form>

<?php elseif ($can_manage && $active_tab === 'rekap'): ?>
    <!-- ========================================================= -->
    <!-- VIEW GURU / STAF / ADMIN: REKAPITULASI BULANAN -->
    <!-- ========================================================= -->

    <!-- Filter Bulan & Tahun -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-6 backdrop-blur shadow-xl mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <form method="GET" action="attendance.php" class="flex flex-wrap items-center gap-3">
                <input type="hidden" name="tab" value="rekap">
                <label class="text-sm font-semibold text-slate-300">Pilih Periode:</label>
                
                <select name="month" 
                        onchange="this.form.submit()" 
                        class="rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                    <?php 
                    $months = [
                        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
                        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
                        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
                    ];
                    foreach ($months as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $selected_month === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="year" 
                        onchange="this.form.submit()" 
                        class="rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                    <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                        <option value="<?= $y ?>" <?= (int)$selected_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </form>

            <div class="flex items-center gap-2">
                <button type="button" 
                        onclick="window.print()" 
                        class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 transition inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-print"></i> Cetak Rekap
                </button>
            </div>
        </div>
    </div>

    <!-- Tabel Rekapitulasi -->
    <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-xl backdrop-blur">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-6 py-4">No</th>
                        <th class="px-6 py-4">Nama Siswa</th>
                        <th class="px-4 py-4 text-center">Hadir (H)</th>
                        <th class="px-4 py-4 text-center">Sakit (S)</th>
                        <th class="px-4 py-4 text-center">Izin (I)</th>
                        <th class="px-4 py-4 text-center">Alpa (A)</th>
                        <th class="px-6 py-4 text-center">% Kehadiran</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($monthly_recap)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-slate-400">
                                Belum ada data presensi pada periode bulan ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($monthly_recap as $i => $rec): 
                            $tot = (int) $rec['total_recorded'];
                            $had = (int) $rec['total_hadir'];
                            $pct = ($tot > 0) ? round(($had / $tot) * 100, 1) : 0;
                        ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="px-6 py-4 font-mono text-xs text-slate-500"><?= $i + 1 ?></td>
                                <td class="px-6 py-4">
                                    <p class="font-semibold text-white"><?= htmlspecialchars($rec['student_name']) ?></p>
                                    <p class="text-xs text-slate-400"><?= htmlspecialchars($rec['student_email']) ?></p>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-block px-2.5 py-1 rounded-lg font-semibold text-xs border border-emerald-500/30 bg-emerald-500/10 text-emerald-300">
                                        <?= $rec['total_hadir'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-block px-2.5 py-1 rounded-lg font-semibold text-xs border border-amber-500/30 bg-amber-500/10 text-amber-300">
                                        <?= $rec['total_sakit'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-block px-2.5 py-1 rounded-lg font-semibold text-xs border border-blue-500/30 bg-blue-500/10 text-blue-300">
                                        <?= $rec['total_izin'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-block px-2.5 py-1 rounded-lg font-semibold text-xs border border-rose-500/30 bg-rose-500/10 text-rose-300">
                                        <?= $rec['total_alpa'] ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <div class="w-24 bg-slate-800 rounded-full h-2 overflow-hidden border border-white/5">
                                            <div class="h-2 rounded-full <?= $pct >= 85 ? 'bg-emerald-500' : ($pct >= 70 ? 'bg-amber-500' : 'bg-rose-500') ?>" style="width: <?= $pct ?>%"></div>
                                        </div>
                                        <span class="font-bold text-xs <?= $pct >= 85 ? 'text-emerald-400' : ($pct >= 70 ? 'text-amber-400' : 'text-rose-400') ?>">
                                            <?= $pct ?>%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <!-- ========================================================= -->
    <!-- VIEW SISWA & ORANG TUA: RIWAYAT & STATISTIK PRIBADI -->
    <!-- ========================================================= -->

    <?php if ($user_role === 'orang_tua'): ?>
        <?php if ($linked_student): ?>
            <div class="mb-6 rounded-2xl border border-indigo-500/30 bg-indigo-500/10 p-4 flex items-center justify-between text-indigo-200">
                <div class="flex items-center gap-3">
                    <span class="text-xl text-indigo-400"><i class="fa-solid fa-user-graduate"></i></span>
                    <div>
                        <p class="text-xs font-bold text-indigo-400 uppercase tracking-wider">Memantau Presensi Anak</p>
                        <p class="text-sm font-semibold text-white"><?= htmlspecialchars($linked_student['name']) ?> <span class="font-mono text-xs text-slate-400">(NISN: <?= htmlspecialchars($linked_student['nisn'] ?? '-') ?>)</span></p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="mb-6 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 flex items-center gap-3 text-amber-200">
                <span class="text-xl text-amber-400"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <div>
                    <p class="text-xs font-bold text-amber-400 uppercase tracking-wider">Belum Ada Siswa Terhubung</p>
                    <p class="text-sm">Akun orang tua ini belum terhubung dengan data siswa manapun. Silakan hubungi administrator sekolah.</p>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- KPI Metrics Grid -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
        <!-- Persentase -->
        <div class="col-span-2 lg:col-span-1 rounded-3xl border border-white/10 bg-gradient-to-br from-blue-900/30 to-slate-900/40 p-5 shadow-xl backdrop-blur">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-2">Tingkat Kehadiran</p>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-extrabold text-white tracking-tight"><?= $attendance_rate ?>%</span>
                <span class="text-xs font-semibold <?= $attendance_rate >= 85 ? 'text-emerald-400' : ($attendance_rate >= 75 ? 'text-amber-400' : 'text-rose-400') ?>">
                    <?= $attendance_rate >= 85 ? 'Sangat Baik' : ($attendance_rate >= 75 ? 'Cukup' : 'Perlu Perhatian') ?>
                </span>
            </div>
            <div class="w-full bg-slate-800 rounded-full h-1.5 mt-3 overflow-hidden">
                <div class="h-1.5 rounded-full <?= $attendance_rate >= 85 ? 'bg-emerald-500' : ($attendance_rate >= 75 ? 'bg-amber-500' : 'bg-rose-500') ?>" style="width: <?= $attendance_rate ?>%"></div>
            </div>
        </div>

        <!-- Hadir -->
        <div class="rounded-3xl border border-emerald-500/20 bg-slate-900/60 p-5 shadow-xl backdrop-blur">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Hadir</p>
                <span class="text-emerald-400"><i class="fa-solid fa-circle-check"></i></span>
            </div>
            <p class="text-2xl font-bold text-emerald-300 mt-2"><?= $student_stats['hadir'] ?? 0 ?></p>
            <p class="text-xs text-slate-500 mt-1">Hari belajar</p>
        </div>

        <!-- Sakit -->
        <div class="rounded-3xl border border-amber-500/20 bg-slate-900/60 p-5 shadow-xl backdrop-blur">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Sakit</p>
                <span class="text-amber-400"><i class="fa-solid fa-notes-medical"></i></span>
            </div>
            <p class="text-2xl font-bold text-amber-300 mt-2"><?= $student_stats['sakit'] ?? 0 ?></p>
            <p class="text-xs text-slate-500 mt-1">Dengan surat</p>
        </div>

        <!-- Izin -->
        <div class="rounded-3xl border border-blue-500/20 bg-slate-900/60 p-5 shadow-xl backdrop-blur">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Izin</p>
                <span class="text-blue-400"><i class="fa-solid fa-envelope"></i></span>
            </div>
            <p class="text-2xl font-bold text-blue-300 mt-2"><?= $student_stats['izin'] ?? 0 ?></p>
            <p class="text-xs text-slate-500 mt-1">Dispensasi</p>
        </div>

        <!-- Alpa -->
        <div class="rounded-3xl border border-rose-500/20 bg-slate-900/60 p-5 shadow-xl backdrop-blur">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Alpa</p>
                <span class="text-rose-400"><i class="fa-solid fa-circle-xmark"></i></span>
            </div>
            <p class="text-2xl font-bold text-rose-300 mt-2"><?= $student_stats['alpa'] ?? 0 ?></p>
            <p class="text-xs text-slate-500 mt-1">Tanpa keterangan</p>
        </div>
    </div>

    <!-- Riwayat Tabel Kehadiran -->
    <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-xl backdrop-blur">
        <div class="border-b border-white/10 px-6 py-4 flex items-center justify-between">
            <h2 class="text-base font-semibold text-white">Riwayat Kehadiran Terakhir</h2>
            <span class="text-xs text-slate-400">Menampilkan 60 catatan terbaru</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="px-6 py-3.5">Hari & Tanggal</th>
                        <th class="px-6 py-3.5 text-center">Status</th>
                        <th class="px-6 py-3.5">Keterangan / Catatan</th>
                        <th class="px-6 py-3.5">Dicatat Oleh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($student_history)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-slate-400">
                                <span class="text-3xl block mb-2 text-slate-500"><i class="fa-solid fa-clipboard-list"></i></span>
                                Belum ada riwayat catatan presensi untuk akun Anda.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($student_history as $his): ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="px-6 py-4">
                                    <p class="font-medium text-white"><?= hariIndo($his['date']) ?>, <?= date('d M Y', strtotime($his['date'])) ?></p>
                                    <p class="text-xs text-slate-500 font-mono"><?= $his['date'] ?></p>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg font-semibold text-xs border <?= getAttendanceBadge($his['status']) ?>">
                                        <?= getAttendanceIcon($his['status']) ?> <?= getAttendanceLabel($his['status']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-slate-300">
                                    <?= htmlspecialchars($his['notes'] ?: '-') ?>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">
                                    <?= htmlspecialchars($his['recorded_by_name'] ?? 'Biro Akademik') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<script>
function markAllStatus(status) {
    const radios = document.querySelectorAll('.radio-' + status);
    radios.forEach(radio => {
        radio.checked = true;
    });
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
