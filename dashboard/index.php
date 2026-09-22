<?php
session_start();

// Handle Logout
if (isset($_GET["logout"])) {
    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit;
}

$page_title = "Dashboard";
require_once __DIR__ . "/includes/header.php";

// -------------------------------------------------------------
// PENGAMBILAN DATA DINAMIS BERDASARKAN ROLE
// -------------------------------------------------------------
// 1. Pengumuman Terkait
$stmt_ann = $pdo->prepare("
    SELECT a.*, u.name as author_name 
    FROM announcements a 
    JOIN users u ON a.author_id = u.id 
    WHERE a.target_role IN ('semua', ?) 
    ORDER BY a.id DESC LIMIT 3
");
$stmt_ann->execute([$user_role]);
$latest_announcements = $stmt_ann->fetchAll();

// 2. Data Khusus Admin
$total_users = 0;
$role_counts = [];
$total_requests_pending = 0;
$total_assignments = 0;
$total_exams = 0;

if ($user_role === 'administrator') {
    try {
        $total_users = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        
        $stmt_r = $pdo->query("SELECT role, COUNT(*) as c FROM users GROUP BY role");
        while ($row = $stmt_r->fetch()) {
            $role_counts[$row['role']] = (int) $row['c'];
        }

        $total_requests_pending = (int) $pdo->query("SELECT COUNT(*) FROM service_requests WHERE status = 'menunggu'")->fetchColumn();
        $total_assignments = (int) $pdo->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
        $total_exams = (int) $pdo->query("SELECT COUNT(*) FROM exams")->fetchColumn();
    } catch (PDOException $e) {}
}

// 3. Data Khusus Staf
$pending_requests = [];
if ($user_role === 'staf') {
    $stmt_staf_req = $pdo->query("
        SELECT r.*, u.name as applicant_name, u.role as applicant_role 
        FROM service_requests r 
        JOIN users u ON r.user_id = u.id 
        WHERE r.status = 'menunggu' 
        ORDER BY r.id DESC LIMIT 4
    ");
    $pending_requests = $stmt_staf_req->fetchAll();
}

// 4. Data Khusus Guru
$my_assignments = [];
$my_exams = [];
if ($user_role === 'guru') {
    $stmt_guru_as = $pdo->prepare("SELECT * FROM assignments WHERE teacher_id = ? ORDER BY due_date ASC LIMIT 4");
    $stmt_guru_as->execute([$user_id]);
    $my_assignments = $stmt_guru_as->fetchAll();

    $stmt_guru_ex = $pdo->prepare("
        SELECT e.*, 
               (SELECT COUNT(*) FROM exam_submissions WHERE exam_id = e.id) as sub_count, 
               (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.id) as q_count 
        FROM exams e 
        WHERE e.teacher_id = ? 
        ORDER BY e.id DESC LIMIT 4
    ");
    $stmt_guru_ex->execute([$user_id]);
    $my_exams = $stmt_guru_ex->fetchAll();
}

// 5. Data Khusus Siswa & Orang Tua
$active_assignments = [];
$my_submissions = [];
$active_exams = [];
$my_exam_subs = [];

if (in_array($user_role, ['siswa', 'orang_tua'], true)) {
    $stmt_active_as = $pdo->query("
        SELECT a.*, u.name as teacher_name 
        FROM assignments a 
        JOIN users u ON a.teacher_id = u.id 
        ORDER BY a.due_date ASC LIMIT 4
    ");
    $active_assignments = $stmt_active_as->fetchAll();

    $stmt_act_ex = $pdo->query("
        SELECT e.*, u.name as teacher_name, 
               (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.id) as q_count 
        FROM exams e 
        JOIN users u ON e.teacher_id = u.id 
        ORDER BY e.id DESC LIMIT 4
    ");
    $active_exams = $stmt_act_ex->fetchAll();

    if ($user_role === 'siswa') {
        $stmt_sub = $pdo->prepare("SELECT assignment_id, status FROM assignment_submissions WHERE student_id = ?");
        $stmt_sub->execute([$user_id]);
        while ($r = $stmt_sub->fetch()) {
            $my_submissions[$r['assignment_id']] = $r['status'];
        }

        $stmt_ex_s = $pdo->prepare("SELECT exam_id, score, remedial_granted FROM exam_submissions WHERE student_id = ?");
        $stmt_ex_s->execute([$user_id]);
        while ($rx = $stmt_ex_s->fetch()) {
            $my_exam_subs[$rx['exam_id']] = [
                'score' => $rx['score'],
                'remedial_granted' => (int)($rx['remedial_granted'] ?? 0)
            ];
        }
    }
}

// 6. Data Presensi & Kalender untuk Dashboard
$my_attendance_stats = ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alpa' => 0, 'total' => 0, 'rate' => 100];
$linked_child = null;
$child_alpa_today = false;
$child_remedial_exams = [];
$child_due_assignments = [];

if (in_array($user_role, ['siswa', 'orang_tua'], true)) {
    $att_student_id = $user_id;
    if ($user_role === 'orang_tua') {
        try {
            $stmt_child = $pdo->prepare("
                SELECT u.id, u.name, u.nisn, u.gender, c.name as class_name 
                FROM parent_students ps
                JOIN users u ON ps.student_id = u.id
                LEFT JOIN classes c ON u.class_id = c.id
                WHERE ps.parent_id = ? LIMIT 1
            ");
            $stmt_child->execute([$user_id]);
            $linked_child = $stmt_child->fetch();
        } catch (Exception $e) {}

        if (!$linked_child) {
            $stmt_fallback = $pdo->query("SELECT u.id, u.name, u.nisn, u.gender, c.name as class_name FROM users u LEFT JOIN classes c ON u.class_id = c.id WHERE u.role = 'siswa' LIMIT 1");
            $linked_child = $stmt_fallback->fetch();
        }
        if ($linked_child) {
            $att_student_id = (int)$linked_child['id'];
        }

        // Cek peringatan anak untuk orang tua
        try {
            // 1. Alpa hari ini
            $stmt_alp = $pdo->prepare("SELECT COUNT(*) FROM student_attendance WHERE student_id = ? AND date = CURRENT_DATE() AND status = 'alpa'");
            $stmt_alp->execute([$att_student_id]);
            if ($stmt_alp->fetchColumn() > 0) {
                $child_alpa_today = true;
            }

            // 2. Remedial ujian
            $stmt_rem = $pdo->prepare("
                SELECT e.title, e.subject, es.score, e.passing_grade 
                FROM exam_submissions es
                JOIN exams e ON es.exam_id = e.id
                WHERE es.student_id = ? AND (es.remedial_granted = 1 OR es.score < e.passing_grade)
                LIMIT 3
            ");
            $stmt_rem->execute([$att_student_id]);
            $child_remedial_exams = $stmt_rem->fetchAll();

            // 3. Tugas mepet deadline (<= 2 hari) belum dikumpulkan
            $stmt_due = $pdo->prepare("
                SELECT a.id, a.title, a.subject, a.due_date, DATEDIFF(a.due_date, CURRENT_DATE()) as days_left
                FROM assignments a
                WHERE a.due_date >= CURRENT_DATE() AND a.due_date <= DATE_ADD(CURRENT_DATE(), INTERVAL 2 DAY)
                AND NOT EXISTS (
                    SELECT 1 FROM assignment_submissions sub WHERE sub.assignment_id = a.id AND sub.student_id = ? AND sub.status = 'selesai'
                )
                LIMIT 3
            ");
            $stmt_due->execute([$att_student_id]);
            $child_due_assignments = $stmt_due->fetchAll();
        } catch (Exception $e) {}
    }

    try {
        $stmt_att_dash = $pdo->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir,
                COUNT(CASE WHEN status = 'sakit' THEN 1 END) as sakit,
                COUNT(CASE WHEN status = 'izin' THEN 1 END) as izin,
                COUNT(CASE WHEN status = 'alpa' THEN 1 END) as alpa,
                COUNT(*) as total
            FROM student_attendance
            WHERE student_id = ?
        ");
        $stmt_att_dash->execute([$att_student_id]);
        $row_att = $stmt_att_dash->fetch();
        if ($row_att) {
            $tot = (int)$row_att['total'];
            $had = (int)$row_att['hadir'];
            $my_attendance_stats = [
                'hadir' => $had,
                'sakit' => (int)$row_att['sakit'],
                'izin'  => (int)$row_att['izin'],
                'alpa'  => (int)$row_att['alpa'],
                'total' => $tot,
                'rate'  => ($tot > 0) ? round(($had / $tot) * 100, 1) : 100
            ];
        }
    } catch (Exception $e) {}
}

// Agenda Kalender Terdekat untuk Dashboard
$dash_upcoming = [];
try {
    $stmt_up_dash = $pdo->query("
        SELECT * FROM (
            SELECT id, title, event_date as date, category, 'event' as src FROM calendar_events WHERE event_date >= CURRENT_DATE()
            UNION ALL
            SELECT id, CONCAT('[Ujian] ', subject, ' - ', title) as title, DATE(due_date) as date, 'ujian' as category, 'exam' as src FROM exams WHERE due_date >= CURRENT_TIMESTAMP
            UNION ALL
            SELECT id, CONCAT('[Tugas] ', subject, ' - ', title) as title, due_date as date, 'akademik' as category, 'assignment' as src FROM assignments WHERE due_date >= CURRENT_DATE()
        ) as combined
        ORDER BY date ASC
        LIMIT 3
    ");
    $dash_upcoming = $stmt_up_dash->fetchAll();
} catch (Exception $e) {}
?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
    <div class="mb-6 rounded-2xl border border-rose-500/20 bg-rose-500/10 p-4 text-sm text-rose-300 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="text-xl">⛔</span>
            <span>Akses ditolak! Anda tidak memiliki izin untuk membuka halaman tersebut.</span>
        </div>
        <a href="index.php" class="text-xs font-semibold text-rose-400 hover:underline">Tutup</a>
    </div>
<?php endif; ?>

<!-- Welcome Banner -->
<div class="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-r from-blue-900/30 via-slate-900/50 to-slate-900/30 p-6 sm:p-8 backdrop-blur shadow-2xl mb-8">
    <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
            <div class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wider mb-3 <?= getRoleBadge($user_role) ?>">
                <span>Role:</span>
                <span><?= htmlspecialchars(getRoleLabel($user_role)) ?></span>
            </div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold text-white">
                Selamat Datang, <?= htmlspecialchars($user_name) ?>! 👋
            </h1>
            <p class="mt-2 text-sm sm:text-base text-slate-400 max-w-2xl">
                Anda berada di portal sistem manajemen sekolah. Semua fitur di bawah ini aktif dan terhubung secara langsung antar peran.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <a href="attendance.php" class="rounded-xl border border-emerald-500/30 bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 px-4 py-2.5 text-sm font-semibold transition flex items-center gap-1.5">
                <span>📅</span> Presensi
            </a>
            <a href="calendar.php" class="rounded-xl border border-cyan-500/30 bg-cyan-500/15 hover:bg-cyan-500/25 text-cyan-300 px-4 py-2.5 text-sm font-semibold transition flex items-center gap-1.5">
                <span>🗓️</span> Kalender
            </a>
            <a href="Modul-ujian/exams.php" class="rounded-xl border border-blue-500/30 bg-blue-500/15 hover:bg-blue-500/25 text-blue-300 px-4 py-2.5 text-sm font-semibold transition flex items-center gap-1.5">
                <span>📝</span> Ujian & Latihan
            </a>
            <a href="announcements.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold transition">
                📢 Pengumuman
            </a>
            <?php if ($user_role === 'administrator'): ?>
                <a href="users.php" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition">
                    👥 Kelola Pengguna
                </a>
                <a href="classes.php" class="rounded-xl border border-indigo-500/30 bg-indigo-500/15 hover:bg-indigo-500/25 text-indigo-300 px-4 py-2.5 text-sm font-semibold transition">
                    🏫 Rombel & Kelas
                </a>
            <?php elseif ($user_role === 'guru'): ?>
                <a href="assignments.php" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-500/20 transition">
                    ➕ Buat Tugas
                </a>
                <a href="gradebook.php" class="rounded-xl border border-amber-500/30 bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 px-4 py-2.5 text-sm font-semibold transition">
                    📚 Buku Nilai
                </a>
            <?php elseif ($user_role === 'siswa'): ?>
                <a href="exam_card.php" class="rounded-xl border border-purple-500/30 bg-purple-500/15 hover:bg-purple-500/25 text-purple-300 px-4 py-2.5 text-sm font-semibold transition">
                    🪪 Kartu Ujian
                </a>
                <a href="report_card.php" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition">
                    📈 E-Rapor
                </a>
            <?php elseif ($user_role === 'orang_tua'): ?>
                <a href="report_card.php<?= $linked_child ? '?student_id='.$linked_child['id'] : '' ?>" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition">
                    📈 Rapor Anak
                </a>
                <a href="requests.php" class="rounded-xl border border-emerald-500/30 bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 px-4 py-2.5 text-sm font-semibold transition">
                    📋 Izin / Sakit
                </a>
            <?php elseif ($user_role === 'staf'): ?>
                <a href="requests.php" class="rounded-xl bg-amber-600 hover:bg-amber-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-amber-500/20 transition">
                    📋 Surat Masuk
                </a>
                <a href="attendance_report.php" class="rounded-xl border border-emerald-500/30 bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 px-4 py-2.5 text-sm font-semibold transition">
                    📊 Rekap Presensi
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========================================================= -->
<!-- 1. TAMPILAN KHUSUS: ADMINISTRATOR -->
<!-- ========================================================= -->
<?php if ($user_role === 'administrator'): ?>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <span class="text-xs text-slate-400 font-medium">Total Pengguna</span>
            <p class="text-3xl font-extrabold text-white mt-2"><?= $total_users ?></p>
            <a href="users.php" class="text-xs text-blue-400 hover:underline mt-1 block">Kelola pengguna →</a>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <span class="text-xs text-slate-400 font-medium">Surat Diproses</span>
            <p class="text-3xl font-extrabold text-amber-300 mt-2"><?= $total_requests_pending ?></p>
            <a href="requests.php" class="text-xs text-amber-400 hover:underline mt-1 block">Lihat permohonan →</a>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <span class="text-xs text-slate-400 font-medium">Tugas Belajar</span>
            <p class="text-3xl font-extrabold text-emerald-300 mt-2"><?= $total_assignments ?></p>
            <a href="assignments.php" class="text-xs text-emerald-400 hover:underline mt-1 block">Daftar tugas →</a>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <span class="text-xs text-slate-400 font-medium">Ujian & Latihan</span>
            <p class="text-3xl font-extrabold text-purple-300 mt-2"><?= $total_exams ?></p>
            <a href="Modul-ujian/exams.php" class="text-xs text-purple-400 hover:underline mt-1 block">Kelola asesmen →</a>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5 col-span-2 sm:col-span-1">
            <span class="text-xs text-slate-400 font-medium">Pengumuman Terbit</span>
            <p class="text-3xl font-extrabold text-blue-300 mt-2"><?= count($latest_announcements) ?></p>
            <a href="announcements.php" class="text-xs text-blue-400 hover:underline mt-1 block">Buka pengumuman →</a>
        </div>
    </div>

    <!-- Role Distribution & Modul Links -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
            <h3 class="text-base font-bold text-white mb-4">👥 Distribusi 5 Role Akun</h3>
            <div class="space-y-3">
                <?php foreach (ROLES as $k => $lbl): ?>
                    <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-900/60 border border-white/5 text-xs">
                        <span class="font-medium text-slate-200"><?= $lbl ?></span>
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-white"><?= $role_counts[$k] ?? 0 ?></span>
                            <span class="inline-flex rounded px-1.5 py-0.5 text-[10px] <?= getRoleBadge($k) ?>"><?= $k ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="lg:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-bold text-white">📢 Pengumuman Sekolah Terkini</h3>
                <a href="announcements.php" class="text-xs text-blue-400 hover:underline">Semua →</a>
            </div>
            <div class="space-y-3">
                <?php foreach ($latest_announcements as $a): ?>
                    <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5">
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <h4 class="text-sm font-bold text-white"><?= htmlspecialchars($a['title']) ?></h4>
                            <span class="text-[10px] text-slate-400"><?= date('d M Y', strtotime($a['created_at'])) ?></span>
                        </div>
                        <p class="text-xs text-slate-300 line-clamp-2"><?= htmlspecialchars($a['content']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

<!-- ========================================================= -->
<!-- 2. TAMPILAN KHUSUS: STAF -->
<!-- ========================================================= -->
<?php elseif ($user_role === 'staf'): ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Permohonan Surat Menunggu -->
        <div class="lg:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span>📋</span> Permohonan Surat yang Butuh Diproses
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Surat yang baru diajukan oleh siswa atau orang tua.</p>
                </div>
                <a href="requests.php" class="text-xs font-semibold text-amber-400 hover:text-amber-300">
                    Buka Semua (<?= count($pending_requests) ?>) →
                </a>
            </div>

            <?php if (empty($pending_requests)): ?>
                <div class="p-8 text-center text-slate-400 text-xs">
                    ✅ Semua permohonan surat sudah diproses. Tidak ada antrean baru.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($pending_requests as $req): ?>
                        <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center justify-between gap-4">
                            <div>
                                <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($req['request_type']) ?></h4>
                                <p class="text-xs text-slate-400">Pemohon: <strong class="text-slate-300"><?= htmlspecialchars($req['applicant_name']) ?></strong> (<?= htmlspecialchars(getRoleLabel($req['applicant_role'])) ?>)</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="requests.php?update_id=<?= $req['id'] ?>&new_status=diproses" 
                                   class="rounded-lg bg-blue-600 hover:bg-blue-500 px-3 py-1.5 text-xs font-semibold text-white transition">
                                    Proses
                                </a>
                                <a href="requests.php?update_id=<?= $req['id'] ?>&new_status=selesai" 
                                   class="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-white transition">
                                    Selesai
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Shortcut & Pengumuman -->
        <div class="space-y-6">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-2">📢 Buat Pengumuman Sekolah</h3>
                <p class="text-xs text-slate-400 mb-4">Terbitkan informasi resmi untuk guru, siswa, atau wali murid.</p>
                <a href="announcements.php" class="block w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-center text-xs font-semibold text-white transition">
                    ➕ Tulis Pengumuman Baru
                </a>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-3">📌 Layanan Tata Usaha</h3>
                <div class="space-y-2 text-xs text-slate-400">
                    <div>• Pembuatan Surat Keterangan Aktif Siswa</div>
                    <div>• Legalisir Rapor & Ijazah Digital</div>
                    <div>• Pengarsipan Berkas Operasional Sekolah</div>
                </div>
            </div>
        </div>
    </div>

<!-- ========================================================= -->
<!-- 3. TAMPILAN KHUSUS: GURU -->
<!-- ========================================================= -->
<?php elseif ($user_role === 'guru'): ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Tugas yang Sedang Dibagikan -->
        <div class="lg:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span>📚</span> Tugas Pembelajaran yang Anda Buat
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Daftar tugas yang sedang aktif dan dikerjakan oleh siswa.</p>
                </div>
                <a href="assignments.php" class="text-xs font-semibold text-emerald-400 hover:text-emerald-300">
                    Kelola Tugas →
                </a>
            </div>

            <?php if (empty($my_assignments)): ?>
                <div class="p-8 text-center text-slate-400 text-xs">
                    Anda belum membuat tugas pelajaran. Klik tombol "Buat Tugas Baru" untuk menambahkan tugas.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($my_assignments as $asg): ?>
                        <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center justify-between gap-4">
                            <div>
                                <span class="rounded px-2 py-0.5 text-[10px] font-semibold border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 mb-1 inline-block">
                                    <?= htmlspecialchars($asg['subject']) ?>
                                </span>
                                <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($asg['title']) ?></h4>
                                <p class="text-xs text-slate-400">Batas Waktu: <?= date('d M Y', strtotime($asg['due_date'])) ?></p>
                            </div>
                            <a href="assignments.php" class="text-xs text-blue-400 hover:underline">
                                Detail →
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Modul Ujian & Latihan yang Anda Kelola -->
            <div class="mt-6 border-t border-white/10 pt-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center gap-2">
                            <span>📝</span> Paket Ujian & Latihan yang Anda Buat
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">Asesmen UTS, UKK, Ujian Harian Fleksibel, & Latihan Siswa.</p>
                    </div>
                    <a href="Modul-ujian/exams.php" class="text-xs font-semibold text-blue-400 hover:text-blue-300">
                        Buka Semua (<?= count($my_exams) ?>) →
                    </a>
                </div>

                <?php if (empty($my_exams)): ?>
                    <div class="p-6 text-center text-slate-400 text-xs rounded-2xl bg-slate-900/40 border border-white/5">
                        Anda belum membuat paket ujian atau latihan. Klik tombol di samping untuk membuat.
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($my_exams as $ex): 
                            $c_info = EXAM_CATEGORIES[$ex['category']] ?? ['label' => $ex['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '📝'];
                        ?>
                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                <div>
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="rounded px-2 py-0.5 text-[10px] font-bold border <?= $c_info['badge'] ?>">
                                            <?= $c_info['icon'] ?> <?= htmlspecialchars($c_info['label']) ?>
                                        </span>
                                        <span class="text-xs font-semibold text-blue-400"><?= htmlspecialchars($ex['subject']) ?></span>
                                    </div>
                                    <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($ex['title']) ?></h4>
                                    <p class="text-xs text-slate-400 mt-0.5">
                                        Soal: <strong class="text-slate-200"><?= $ex['q_count'] ?></strong> butir • 
                                        Peserta: <strong class="text-emerald-400"><?= $ex['sub_count'] ?></strong> siswa • KKM: <?= $ex['passing_grade'] ?>
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="Modul-ujian/exam_questions.php?id=<?= $ex['id'] ?>" class="rounded-lg bg-white/10 hover:bg-white/20 px-3 py-1.5 text-xs font-semibold text-white transition">
                                        Soal (<?= $ex['q_count'] ?>)
                                    </a>
                                    <a href="Modul-ujian/exam_results.php?id=<?= $ex['id'] ?>" class="rounded-lg bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/30 px-3 py-1.5 text-xs font-semibold text-blue-300 transition">
                                        Nilai (<?= $ex['sub_count'] ?>)
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pengumuman untuk Guru -->
        <div class="space-y-6">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-2">⚡ Pintasan Cepat Guru</h3>
                <p class="text-xs text-slate-400 mb-4">Buat soal ujian formal atau latihan harian/mingguan untuk siswa.</p>
                <div class="space-y-2.5">
                    <a href="Modul-ujian/exams.php" class="block w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-center text-xs font-semibold text-white shadow-lg shadow-blue-500/25 transition">
                        ➕ Buat Ujian / Latihan Baru
                    </a>
                    <a href="assignments.php" class="block w-full py-2.5 rounded-xl bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/30 text-center text-xs font-semibold text-emerald-300 transition">
                        📚 Buat Tugas Belajar
                    </a>
                    <a href="gradebook.php" class="block w-full py-2.5 rounded-xl bg-amber-600/20 hover:bg-amber-600/30 border border-amber-500/30 text-center text-xs font-semibold text-amber-300 transition">
                        📊 Rekap Nilai Gabungan
                    </a>
                </div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-3">📢 Pengumuman Guru Terkini</h3>
                <div class="space-y-3">
                    <?php foreach ($latest_announcements as $an): ?>
                        <div class="p-3 rounded-xl bg-slate-900/60 border border-white/5">
                            <h4 class="text-xs font-bold text-white mb-1"><?= htmlspecialchars($an['title']) ?></h4>
                            <p class="text-[11px] text-slate-400 line-clamp-2"><?= htmlspecialchars($an['content']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

<!-- ========================================================= -->
<!-- 4. TAMPILAN KHUSUS: ORANG TUA -->
<!-- ========================================================= -->
<?php elseif ($user_role === 'orang_tua'): ?>

    <?php if ($linked_child): ?>
        <!-- Profil Siswa yang Dipantau -->
        <div class="mb-6 rounded-3xl border border-indigo-500/30 bg-gradient-to-r from-indigo-950/40 via-slate-900/60 to-purple-950/30 p-6 backdrop-blur flex flex-col md:flex-row md:items-center justify-between gap-5 shadow-xl">
            <div class="flex items-center gap-4">
                <div class="h-14 w-14 rounded-2xl bg-indigo-500/20 border border-indigo-500/40 flex items-center justify-center text-3xl shadow-inner">
                    👨‍🎓
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="rounded px-2 py-0.5 text-[10px] font-bold border border-indigo-500/30 bg-indigo-500/20 text-indigo-300">
                            Siswa Binaan
                        </span>
                        <span class="text-xs text-slate-400 font-mono">NISN: <?= htmlspecialchars($linked_child['nisn'] ?? '3271012345') ?></span>
                    </div>
                    <h3 class="text-xl font-black text-white"><?= htmlspecialchars($linked_child['name']) ?></h3>
                    <p class="text-xs text-slate-300 mt-0.5">
                        Kelas: <strong class="text-indigo-400"><?= htmlspecialchars($linked_child['class_name'] ?? 'X PPLG 1') ?></strong>
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                <a href="report_card.php?student_id=<?= $linked_child['id'] ?>" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                    <span>📈</span> Rapor Digital Anak
                </a>
                <a href="exam_card.php?student_id=<?= $linked_child['id'] ?>" class="rounded-xl border border-purple-500/30 bg-purple-500/15 hover:bg-purple-500/25 text-purple-300 px-4 py-2.5 text-xs font-bold transition flex items-center gap-2">
                    <span>🪪</span> Kartu Peserta Ujian
                </a>
                <a href="attendance.php" class="rounded-xl border border-emerald-500/30 bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 px-4 py-2.5 text-xs font-bold transition flex items-center gap-2">
                    <span>📅</span> Riwayat Presensi
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Notifikasi Peringatan Terpadu Anak -->
    <?php if ($child_alpa_today || !empty($child_remedial_exams) || !empty($child_due_assignments)): ?>
        <div class="mb-6 space-y-3">
            <?php if ($child_alpa_today): ?>
                <div class="p-4 rounded-2xl border border-rose-500/40 bg-rose-500/15 text-rose-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 animate-pulse">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">⚠️</span>
                        <div>
                            <p class="text-xs font-black uppercase tracking-wider text-rose-400">Peringatan Presensi Hari Ini</p>
                            <p class="text-sm">Ananda <strong><?= htmlspecialchars($linked_child['name'] ?? 'Siswa') ?></strong> tercatat <strong>ALPA (Tidak Masuk Tanpa Keterangan)</strong> pada presensi hari ini!</p>
                        </div>
                    </div>
                    <a href="requests.php" class="whitespace-nowrap px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-xs font-bold text-white transition shadow-lg shadow-rose-600/30 text-center">
                        Ajukan Izin / Sakit Sekarang →
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!empty($child_remedial_exams)): ?>
                <div class="p-4 rounded-2xl border border-amber-500/30 bg-amber-500/10 text-amber-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">📝</span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-amber-400">Pemberitahuan Remedial Ujian</p>
                            <p class="text-sm">Ananda perlu mengikuti perbaikan nilai (remedial) untuk: 
                                <?php foreach ($child_remedial_exams as $cre): ?>
                                    <span class="font-semibold text-white">[<?= htmlspecialchars($cre['subject']) ?> - Nilai: <?= $cre['score'] ?> (KKM <?= $cre['passing_grade'] ?>)]</span>
                                <?php endforeach; ?>
                            </p>
                        </div>
                    </div>
                    <a href="Modul-ujian/exams.php" class="whitespace-nowrap px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-xs font-bold text-slate-950 transition text-center">
                        Lihat Ujian Anak →
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!empty($child_due_assignments)): ?>
                <div class="p-4 rounded-2xl border border-blue-500/30 bg-blue-500/10 text-blue-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="text-2xl">⏰</span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-blue-400">Pengingat Tugas Mendekati Deadline</p>
                            <p class="text-sm">Ananda memiliki tugas belum diserahkan yang mendekati tenggat waktu:
                                <?php foreach ($child_due_assignments as $cda): ?>
                                    <span class="font-semibold text-white">[<?= htmlspecialchars($cda['title']) ?> (<?= $cda['days_left'] == 0 ? 'Hari Ini' : $cda['days_left'].' hari lagi' ?>)]</span>
                                <?php endforeach; ?>
                            </p>
                        </div>
                    </div>
                    <a href="assignments.php" class="whitespace-nowrap px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white transition text-center">
                        Ingatkan Anak →
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Pantauan Tugas Anak -->
        <div class="lg:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span>🎓</span> Pemantauan Tugas Pembelajaran Anak
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Daftar tugas pelajaran aktif dari guru untuk siswa.</p>
                </div>
                <a href="assignments.php" class="text-xs font-semibold text-purple-400 hover:text-purple-300">
                    Buka Semua →
                </a>
            </div>

            <div class="space-y-3">
                <?php foreach ($active_assignments as $asg): ?>
                    <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center justify-between gap-4">
                        <div>
                            <span class="rounded px-2 py-0.5 text-[10px] font-semibold border border-purple-500/30 bg-purple-500/10 text-purple-300 mb-1 inline-block">
                                <?= htmlspecialchars($asg['subject']) ?>
                            </span>
                            <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($asg['title']) ?></h4>
                            <p class="text-xs text-slate-400">Guru Pengampu: <?= htmlspecialchars($asg['teacher_name']) ?></p>
                        </div>
                        <span class="text-xs text-amber-300 font-medium">
                            Deadline: <?= date('d M Y', strtotime($asg['due_date'])) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pantauan Ujian & Latihan Anak -->
            <div class="mt-6 border-t border-white/10 pt-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center gap-2">
                            <span>📝</span> Pemantauan Ujian & Latihan Anak
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">Jadwal UTS, UKK, Ujian Harian, dan Latihan Rutin.</p>
                    </div>
                    <a href="Modul-ujian/exams.php" class="text-xs font-semibold text-purple-400 hover:text-purple-300">
                        Buka Semua (<?= count($active_exams) ?>) →
                    </a>
                </div>

                <div class="space-y-3">
                    <?php foreach ($active_exams as $ex): 
                        $c_info = EXAM_CATEGORIES[$ex['category']] ?? ['label' => $ex['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '📝'];
                    ?>
                        <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="rounded px-2 py-0.5 text-[10px] font-bold border <?= $c_info['badge'] ?>">
                                        <?= $c_info['icon'] ?> <?= htmlspecialchars($c_info['label']) ?>
                                    </span>
                                    <span class="text-xs font-semibold text-blue-400"><?= htmlspecialchars($ex['subject']) ?></span>
                                </div>
                                <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($ex['title']) ?></h4>
                                <p class="text-xs text-slate-400 mt-0.5">Guru: <?= htmlspecialchars($ex['teacher_name']) ?> • KKM: <strong class="text-white"><?= $ex['passing_grade'] ?></strong></p>
                            </div>
                            <a href="Modul-ujian/exams.php" class="rounded-lg bg-purple-600/20 hover:bg-purple-600/30 border border-purple-500/30 px-3.5 py-1.5 text-xs font-semibold text-purple-300 transition text-center">
                                Pantau Nilai →
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Pengumuman, Presensi & Layanan Surat Orang Tua -->
        <div class="space-y-6">
            <!-- Widget Presensi Kehadiran Anak -->
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span>📅</span> Presensi Kehadiran Anak
                    </h3>
                    <a href="attendance.php" class="text-xs font-semibold text-emerald-400 hover:underline">
                        Rincian →
                    </a>
                </div>
                <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4 mb-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-slate-400">Tingkat Kehadiran:</span>
                        <span class="text-xl font-extrabold text-emerald-300"><?= $my_attendance_stats['rate'] ?>%</span>
                    </div>
                    <div class="w-full bg-slate-800 rounded-full h-1.5 mt-2 overflow-hidden">
                        <div class="h-1.5 bg-emerald-500 rounded-full" style="width: <?= $my_attendance_stats['rate'] ?>%"></div>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-1.5 text-center text-[11px]">
                    <div class="p-2 rounded-xl bg-slate-900/60 border border-white/5">
                        <span class="text-slate-400 block text-[10px]">H</span>
                        <strong class="text-emerald-400 font-bold"><?= $my_attendance_stats['hadir'] ?></strong>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-900/60 border border-white/5">
                        <span class="text-slate-400 block text-[10px]">S</span>
                        <strong class="text-amber-400 font-bold"><?= $my_attendance_stats['sakit'] ?></strong>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-900/60 border border-white/5">
                        <span class="text-slate-400 block text-[10px]">I</span>
                        <strong class="text-blue-400 font-bold"><?= $my_attendance_stats['izin'] ?></strong>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-900/60 border border-white/5">
                        <span class="text-slate-400 block text-[10px]">A</span>
                        <strong class="text-rose-400 font-bold"><?= $my_attendance_stats['alpa'] ?></strong>
                    </div>
                </div>
            </div>

            <!-- Widget Agenda Kalender Terdekat -->
            <?php if (!empty($dash_upcoming)): ?>
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span>🗓️</span> Agenda Terdekat
                    </h3>
                    <a href="calendar.php" class="text-xs font-semibold text-blue-400 hover:underline">
                        Kalender →
                    </a>
                </div>
                <div class="space-y-2">
                    <?php foreach ($dash_upcoming as $du): ?>
                        <div class="p-2.5 rounded-xl bg-slate-900/60 border border-white/5 flex items-center justify-between gap-2">
                            <div class="truncate">
                                <p class="text-xs font-semibold text-white truncate"><?= htmlspecialchars($du['title']) ?></p>
                                <span class="text-[10px] text-slate-400 font-mono">📅 <?= date('d M Y', strtotime($du['date'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-2">📋 Layanan Surat Sekolah</h3>
                <p class="text-xs text-slate-400 mb-4">Ajukan surat izin dispensasi atau permohonan dokumen untuk putra/putri Anda.</p>
                <a href="requests.php" class="block w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-center text-xs font-semibold text-white transition">
                    ➕ Ajukan Permohonan Surat
                </a>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-3">📢 Pengumuman Wali Murid</h3>
                <div class="space-y-3">
                    <?php foreach ($latest_announcements as $an): ?>
                        <div class="p-3 rounded-xl bg-slate-900/60 border border-white/5">
                            <h4 class="text-xs font-bold text-white mb-1"><?= htmlspecialchars($an['title']) ?></h4>
                            <p class="text-[11px] text-slate-400 line-clamp-2"><?= htmlspecialchars($an['content']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

<!-- ========================================================= -->
<!-- 5. TAMPILAN KHUSUS: SISWA -->
<!-- ========================================================= -->
<?php else: ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Checklist Tugas Siswa -->
        <div class="lg:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span>📖</span> Tugas Belajar Aktif Anda
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Kerjakan dan tandai tugas yang sudah Anda selesaikan.</p>
                </div>
                <a href="assignments.php" class="text-xs font-semibold text-blue-400 hover:text-blue-300">
                    Lihat Semua Tugas →
                </a>
            </div>

            <?php if (empty($active_assignments)): ?>
                <div class="p-8 text-center text-slate-400 text-xs">
                    🎉 Tidak ada tugas aktif saat ini.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($active_assignments as $asg): 
                        $is_done = (($my_submissions[$asg['id']] ?? '') === 'selesai');
                    ?>
                        <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <span class="rounded px-2 py-0.5 text-[10px] font-semibold border border-blue-500/30 bg-blue-500/10 text-blue-300 mb-1 inline-block">
                                    <?= htmlspecialchars($asg['subject']) ?>
                                </span>
                                <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($asg['title']) ?></h4>
                                <p class="text-xs text-slate-400">Guru: <?= htmlspecialchars($asg['teacher_name']) ?> • Deadline: <span class="text-amber-300"><?= date('d M Y', strtotime($asg['due_date'])) ?></span></p>
                            </div>
                            <div>
                                <a href="assignments.php?toggle_id=<?= $asg['id'] ?>" 
                                   class="inline-flex items-center justify-center gap-1.5 py-1.5 px-3.5 rounded-xl text-xs font-semibold transition <?= $is_done ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-500/30' : 'bg-blue-600 hover:bg-blue-500 text-white' ?>">
                                    <?= $is_done ? '✅ Selesai' : '📌 Tandai Selesai' ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Modul Ujian & Latihan Siswa -->
            <div class="mt-6 border-t border-white/10 pt-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center gap-2">
                            <span>📝</span> Ujian & Latihan Belajar Anda
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">Penilaian UTS, UKK, Ujian Harian, serta Latihan Harian/Mingguan/Bulanan.</p>
                    </div>
                    <a href="Modul-ujian/exams.php" class="text-xs font-semibold text-blue-400 hover:text-blue-300">
                        Buka Semua (<?= count($active_exams) ?>) →
                    </a>
                </div>

                <div class="space-y-3">
                    <?php foreach ($active_exams as $ex): 
                        $c_info = EXAM_CATEGORIES[$ex['category']] ?? ['label' => $ex['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '📝'];
                        $sub_info = $my_exam_subs[$ex['id']] ?? null;
                        $already_done = ($sub_info !== null);
                        $my_score = $already_done ? (float) $sub_info['score'] : 0;
                        $remedial_ready = $already_done && !empty($sub_info['remedial_granted']);
                    ?>
                        <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="rounded px-2 py-0.5 text-[10px] font-bold border <?= $c_info['badge'] ?>">
                                        <?= $c_info['icon'] ?> <?= htmlspecialchars($c_info['label']) ?>
                                    </span>
                                    <span class="text-xs font-semibold text-blue-400"><?= htmlspecialchars($ex['subject']) ?></span>
                                    <?php if ($ex['duration_minutes'] == 0): ?>
                                        <span class="text-[10px] text-emerald-400 font-semibold">⚡ Fleksibel</span>
                                    <?php else: ?>
                                        <span class="text-[10px] text-amber-400 font-semibold">⏱️ <?= $ex['duration_minutes'] ?> Menit</span>
                                    <?php endif; ?>
                                    <?php if (!empty($ex['token'])): ?>
                                        <span class="text-[10px] text-amber-300 font-mono font-bold bg-amber-500/10 border border-amber-500/20 px-1.5 py-0.5 rounded">🔒 Token</span>
                                    <?php endif; ?>
                                </div>
                                <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($ex['title']) ?></h4>
                                <p class="text-xs text-slate-400 mt-0.5">Guru: <?= htmlspecialchars($ex['teacher_name']) ?> • KKM: <?= $ex['passing_grade'] ?></p>
                            </div>
                            <div>
                                <?php if ($remedial_ready): ?>
                                    <a href="Modul-ujian/exam_take.php?id=<?= $ex['id'] ?>&remedial=1" class="inline-flex items-center gap-1.5 rounded-xl bg-amber-500 hover:bg-amber-400 px-3.5 py-1.5 text-xs font-black text-slate-950 shadow-lg shadow-amber-500/25 transition">
                                        <span>🔄 Kerjakan Remedial</span>
                                    </a>
                                <?php elseif ($already_done): ?>
                                    <a href="Modul-ujian/exam_results.php?id=<?= $ex['id'] ?>" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-1.5 text-xs font-bold text-emerald-300 hover:bg-emerald-500/20 transition">
                                        <span>Nilai: <?= number_format($my_score, 0) ?></span> ➔
                                    </a>
                                <?php else: ?>
                                    <a href="Modul-ujian/exam_take.php?id=<?= $ex['id'] ?>" class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 px-3.5 py-1.5 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition">
                                        <span>✍️ Kerjakan</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Pengumuman, Presensi & Layanan Surat Siswa -->
        <div class="space-y-6">
            <!-- Widget Presensi Kehadiran Siswa -->
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span>📅</span> Presensi Kehadiran
                    </h3>
                    <a href="attendance.php" class="text-xs font-semibold text-emerald-400 hover:underline">
                        Riwayat →
                    </a>
                </div>
                <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4 mb-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-slate-400">Kehadiran Anda:</span>
                        <span class="text-xl font-extrabold text-emerald-300"><?= $my_attendance_stats['rate'] ?>%</span>
                    </div>
                    <div class="w-full bg-slate-800 rounded-full h-1.5 mt-2 overflow-hidden">
                        <div class="h-1.5 bg-emerald-500 rounded-full" style="width: <?= $my_attendance_stats['rate'] ?>%"></div>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-1.5 text-center text-[11px]">
                    <div class="p-2 rounded-xl bg-slate-900/60 border border-white/5">
                        <span class="text-slate-400 block text-[10px]">H</span>
                        <strong class="text-emerald-400 font-bold"><?= $my_attendance_stats['hadir'] ?></strong>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-900/60 border border-white/5">
                        <span class="text-slate-400 block text-[10px]">S</span>
                        <strong class="text-amber-400 font-bold"><?= $my_attendance_stats['sakit'] ?></strong>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-900/60 border border-white/5">
                        <span class="text-slate-400 block text-[10px]">I</span>
                        <strong class="text-blue-400 font-bold"><?= $my_attendance_stats['izin'] ?></strong>
                    </div>
                    <div class="p-2 rounded-xl bg-slate-900/60 border border-white/5">
                        <span class="text-slate-400 block text-[10px]">A</span>
                        <strong class="text-rose-400 font-bold"><?= $my_attendance_stats['alpa'] ?></strong>
                    </div>
                </div>
            </div>

            <!-- Widget Agenda Kalender Terdekat -->
            <?php if (!empty($dash_upcoming)): ?>
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <span>🗓️</span> Agenda Terdekat
                    </h3>
                    <a href="calendar.php" class="text-xs font-semibold text-blue-400 hover:underline">
                        Kalender →
                    </a>
                </div>
                <div class="space-y-2">
                    <?php foreach ($dash_upcoming as $du): ?>
                        <div class="p-2.5 rounded-xl bg-slate-900/60 border border-white/5 flex items-center justify-between gap-2">
                            <div class="truncate">
                                <p class="text-xs font-semibold text-white truncate"><?= htmlspecialchars($du['title']) ?></p>
                                <span class="text-[10px] text-slate-400 font-mono">📅 <?= date('d M Y', strtotime($du['date'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-2">⚡ Pintasan Cepat Siswa</h3>
                <p class="text-xs text-slate-400 mb-4">Akses dokumen akademik dan layanan permohonan surat tata usaha.</p>
                <div class="space-y-2.5">
                    <a href="exam_card.php" class="block w-full py-2.5 rounded-xl border border-purple-500/30 bg-purple-500/15 hover:bg-purple-500/25 text-center text-xs font-bold text-purple-300 transition">
                        🪪 Cetak Kartu Peserta Ujian
                    </a>
                    <a href="report_card.php" class="block w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-center text-xs font-bold text-white shadow-lg shadow-blue-500/20 transition">
                        📈 Cetak / Unduh E-Rapor Digital
                    </a>
                    <a href="Modul-ujian/exams.php" class="block w-full py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-center text-xs font-semibold text-slate-300 transition">
                        📝 Buka Ujian & Latihan
                    </a>
                    <a href="requests.php" class="block w-full py-2.5 rounded-xl bg-white/10 hover:bg-white/20 text-center text-xs font-semibold text-white transition">
                        📋 Ajukan Surat Keterangan / Izin
                    </a>
                </div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-3">📢 Pengumuman Sekolah</h3>
                <div class="space-y-3">
                    <?php foreach ($latest_announcements as $an): ?>
                        <div class="p-3 rounded-xl bg-slate-900/60 border border-white/5">
                            <h4 class="text-xs font-bold text-white mb-1"><?= htmlspecialchars($an['title']) ?></h4>
                            <p class="text-[11px] text-slate-400 line-clamp-2"><?= htmlspecialchars($an['content']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . "/includes/footer.php"; ?>