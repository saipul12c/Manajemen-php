<?php
session_start();

// BUG-07 fix: Handle Logout via POST with CSRF validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'logout') {
    if (isset($_SESSION['csrf_token']) && isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        session_unset();
        session_destroy();
        header("Location: ../index.php");
        exit;
    }
}

// Verifikasi Wajib Login
require_once __DIR__ . "/../config/database.php";
requireLogin();

// Handle konfirmasi baca pengumuman darurat dari pop-up modal (Fase 1 & Fase 2)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ack_announcement') {
    if (validateCsrfToken()) {
        $ack_id = (int) ($_POST['announcement_id'] ?? 0);
        $curr_uid = (int) $_SESSION['user_id'];
        if ($ack_id > 0) {
            $stmt_ack = $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)");
            $stmt_ack->execute([$ack_id, $curr_uid]);
            logActivity($pdo, 'ack_announcement', "Konfirmasi baca pengumuman darurat ID: $ack_id");
            header("Location: index.php?msg=ack_success");
            exit;
        }
    }
}

$page_title = "Dashboard";
require_once __DIR__ . "/includes/header.php";

// -------------------------------------------------------------
// PENGAMBILAN DATA DINAMIS BERDASARKAN ROLE & KELAS (Fase 1)
// -------------------------------------------------------------
$class_filter_sql = "";
$class_params = [];
if ($user_role === 'siswa') {
    $std_class = $pdo->query("SELECT class_id FROM users WHERE id = $user_id")->fetchColumn();
    if ($std_class) {
        $class_filter_sql = " AND (a.class_id IS NULL OR a.class_id = ?)";
        $class_params[] = (int) $std_class;
    } else {
        $class_filter_sql = " AND a.class_id IS NULL";
    }
} elseif ($user_role === 'orang_tua') {
    $stmt_pck = $pdo->prepare("
        SELECT DISTINCT u.class_id 
        FROM parent_students ps 
        JOIN users u ON ps.student_id = u.id 
        WHERE ps.parent_id = ? AND u.class_id IS NOT NULL
    ");
    $stmt_pck->execute([$user_id]);
    $p_classes = $stmt_pck->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($p_classes)) {
        $in_cl = implode(',', array_fill(0, count($p_classes), '?'));
        $class_filter_sql = " AND (a.class_id IS NULL OR a.class_id IN ($in_cl))";
        foreach ($p_classes as $pcl) { $class_params[] = (int) $pcl; }
    } else {
        $class_filter_sql = " AND a.class_id IS NULL";
    }
}

// 1. Pengumuman Terkait
$stmt_ann = $pdo->prepare("
    SELECT a.*, u.name as author_name 
    FROM announcements a 
    JOIN users u ON a.author_id = u.id 
    WHERE a.status = 'published'
      AND (a.expires_at IS NULL OR a.expires_at > NOW())
      AND a.target_role IN ('semua', ?) 
      $class_filter_sql
    ORDER BY a.is_pinned DESC, a.id DESC LIMIT 5
");
$stmt_ann->execute(array_merge([$user_role], $class_params));
$latest_announcements = $stmt_ann->fetchAll();

// Ambil pengumuman darurat/penting yang di-pin untuk alert banner
$stmt_urgent = $pdo->prepare("
    SELECT a.*, u.name as author_name 
    FROM announcements a 
    JOIN users u ON a.author_id = u.id 
    WHERE a.status = 'published'
      AND (a.expires_at IS NULL OR a.expires_at > NOW())
      AND a.target_role IN ('semua', ?) 
      AND (a.category IN ('darurat', 'penting') OR a.is_pinned = 1)
      $class_filter_sql
    ORDER BY FIELD(a.category, 'darurat', 'penting') ASC, a.id DESC LIMIT 3
");
$stmt_urgent->execute(array_merge([$user_role], $class_params));
$urgent_announcements = $stmt_urgent->fetchAll();

// Cek Pengumuman Darurat yang BELUM dibaca untuk Auto Pop-up Modal saat login (Fase 2)
$unread_emergency_announcement = null;
$stmt_em = $pdo->prepare("
    SELECT a.*, u.name as author_name 
    FROM announcements a
    JOIN users u ON a.author_id = u.id
    LEFT JOIN announcement_reads ar ON ar.announcement_id = a.id AND ar.user_id = ?
    WHERE a.status = 'published'
      AND (a.expires_at IS NULL OR a.expires_at > NOW())
      AND a.target_role IN ('semua', ?)
      AND a.category IN ('darurat', 'penting')
      AND ar.id IS NULL
      $class_filter_sql
    ORDER BY FIELD(a.category, 'darurat', 'penting') ASC, a.id DESC LIMIT 1
");
$stmt_em->execute(array_merge([$user_id, $user_role], $class_params));
$unread_emergency_announcement = $stmt_em->fetch();

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
                'remedial_granted' => $rx['remedial_granted'] ?? 0
            ];
        }
    }
}

// Deteksi Data PPDB & Status Kelas untuk Siswa
$my_ppdb_reg = null;
$student_class_name = null;
if ($user_role === 'siswa') {
    try {
        $stmt_my_ppdb = $pdo->prepare("SELECT * FROM ppdb_registrations WHERE user_id = ? OR email = ? ORDER BY id DESC LIMIT 1");
        $stmt_my_ppdb->execute([$user_id, $user_email]);
        $my_ppdb_reg = $stmt_my_ppdb->fetch();

        if (!empty($std_class)) {
            $student_class_name = $pdo->query("SELECT name FROM classes WHERE id = " . (int)$std_class)->fetchColumn();
        }
    } catch (Exception $e) {}
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

        // BUG-12 fix: Jangan fallback ke siswa random — tampilkan pesan bahwa belum ada siswa terhubung
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

// Data Tambahan Modul Baru untuk Dashboard
$days_id_map = [
    'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
    'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu'
];
$today_id_name = $days_id_map[date('l')] ?? 'Senin';

$dash_today_schedules = [];
try {
    if ($user_role === 'guru') {
        $stmt_tt_dash = $pdo->prepare("
            SELECT t.*, c.name as class_name 
            FROM timetables t
            JOIN classes c ON t.class_id = c.id
            WHERE t.teacher_id = ? AND t.day = ?
            ORDER BY t.start_time ASC
        ");
        $stmt_tt_dash->execute([$user_id, $today_id_name]);
        $dash_today_schedules = $stmt_tt_dash->fetchAll();
    } elseif (in_array($user_role, ['siswa', 'orang_tua'], true)) {
        $target_cl_id = null;
        if ($user_role === 'siswa') {
            $stmt_cl_s = $pdo->prepare("SELECT class_id FROM users WHERE id = ?");
            $stmt_cl_s->execute([$user_id]);
            $target_cl_id = $stmt_cl_s->fetchColumn();
        } elseif ($linked_child) {
            $target_cl_id = $linked_child['id'] ? $pdo->query("SELECT class_id FROM users WHERE id = {$linked_child['id']}")->fetchColumn() : null;
        }
        if ($target_cl_id) {
            $stmt_tt_dash = $pdo->prepare("
                SELECT t.*, u.name as teacher_name 
                FROM timetables t
                JOIN users u ON t.teacher_id = u.id
                WHERE t.class_id = ? AND t.day = ?
                ORDER BY t.start_time ASC
            ");
            $stmt_tt_dash->execute([$target_cl_id, $today_id_name]);
            $dash_today_schedules = $stmt_tt_dash->fetchAll();
        }
    }
} catch (Exception $e) {}

// Ringkasan Tagihan Keuangan Belum Lunas
$dash_unpaid_bills = [];
$dash_total_unpaid = 0;
if (in_array($user_role, ['siswa', 'orang_tua'], true) && isset($att_student_id)) {
    try {
        $stmt_ub = $pdo->prepare("SELECT * FROM student_bills WHERE student_id = ? AND status != 'lunas' ORDER BY due_date ASC LIMIT 3");
        $stmt_ub->execute([$att_student_id]);
        $dash_unpaid_bills = $stmt_ub->fetchAll();
        foreach ($dash_unpaid_bills as $ub) {
            $dash_total_unpaid += (float)$ub['amount'];
        }
    } catch (Exception $e) {}
}

// Poin BK & Prestasi
$dash_counseling_points = ['reward' => 0, 'penalty' => 0];
if (in_array($user_role, ['siswa', 'orang_tua'], true) && isset($att_student_id)) {
    try {
        $stmt_cp = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN type = 'prestasi' THEN points ELSE 0 END) as reward,
                SUM(CASE WHEN type = 'pelanggaran' THEN points ELSE 0 END) as penalty
            FROM counseling_records WHERE student_id = ?
        ");
        $stmt_cp->execute([$att_student_id]);
        $cp_row = $stmt_cp->fetch();
        if ($cp_row) {
            $dash_counseling_points['reward'] = (int)($cp_row['reward'] ?? 0);
            $dash_counseling_points['penalty'] = (int)($cp_row['penalty'] ?? 0);
        }
    } catch (Exception $e) {}
}

// Data Modul Perpustakaan & PPDB untuk Quick Stats
$dash_book_count = 0;
$dash_ppdb_pending = 0;
try {
    $dash_book_count = (int)$pdo->query("SELECT COUNT(*) FROM `library_books`")->fetchColumn();
    $dash_ppdb_pending = (int)$pdo->query("SELECT COUNT(*) FROM `ppdb_registrations` WHERE `status` = 'menunggu_verifikasi'")->fetchColumn();
} catch (Exception $e) {}
?>

<?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
    <div class="mb-6 rounded-2xl border border-rose-500/20 bg-rose-500/10 p-4 text-sm text-rose-300 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <span class="text-xl"><i class="fa-solid fa-ban text-rose-400"></i></span>
            <span>Akses ditolak! Anda tidak memiliki izin untuk membuka halaman tersebut.</span>
        </div>
        <a href="index.php" class="text-xs font-semibold text-rose-400 hover:underline">Tutup</a>
    </div>
<?php endif; ?>

<!-- Welcome Banner -->
<div class="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-r from-blue-900/30 via-slate-900/70 to-slate-900/40 p-6 sm:p-8 backdrop-blur shadow-2xl mb-8">
    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
        <div>
            <div class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wider mb-3 <?= getRoleBadge($user_role) ?>">
                <i class="<?= getRoleIcon($user_role) ?> text-[10px]"></i>
                <span>Peran: <?= htmlspecialchars(getRoleLabel($user_role)) ?></span>
            </div>
            <h1 class="text-2xl sm:text-3xl lg:text-4xl font-extrabold text-white tracking-tight">
                Selamat Datang, <?= htmlspecialchars($user_name) ?> 👋
            </h1>
            <p class="mt-2 text-sm text-slate-300 max-w-2xl leading-relaxed">
                Akses semua layanan akademik, presensi, ujian CBT, dan administrasi sekolah secara terpadu melalui menu sidebar di sebelah kiri.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <?php if ($user_role === 'administrator'): ?>
                <a href="admin/users.php" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-users"></i> Kelola Pengguna
                </a>
                <a href="admin/classes.php" class="rounded-xl border border-indigo-500/30 bg-indigo-500/15 hover:bg-indigo-500/25 text-indigo-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-school"></i> Rombel & Kelas
                </a>
                <a href="informasi/announcements.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-bullhorn text-slate-300"></i> Buat Pengumuman
                </a>
            <?php elseif ($user_role === 'guru'): ?>
                <a href="akademik/assignments.php" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-emerald-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-plus"></i> Buat Tugas
                </a>
                <a href="akademik/gradebook.php" class="rounded-xl border border-amber-500/30 bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-book-open"></i> Buku Nilai
                </a>
                <a href="presensi/attendance.php" class="rounded-xl border border-emerald-500/30 bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-clipboard-user"></i> Presensi Kelas
                </a>
            <?php elseif ($user_role === 'siswa'): ?>
                <a href="akademik/timetable.php" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-calendar-days"></i> Jadwal Pelajaran
                </a>
                <a href="Modul-ujian/exams.php" class="rounded-xl border border-purple-500/30 bg-purple-500/15 hover:bg-purple-500/25 text-purple-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-file-pen"></i> Ujian CBT
                </a>
                <a href="akademik/report_card.php" class="rounded-xl border border-blue-500/30 bg-blue-500/15 hover:bg-blue-500/25 text-blue-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-chart-line"></i> E-Rapor
                </a>
            <?php elseif ($user_role === 'orang_tua'): ?>
                <a href="akademik/report_card.php<?= $linked_child ? '?student_id='.$linked_child['id'] : '' ?>" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-chart-line"></i> Rapor Anak
                </a>
                <a href="surat/requests.php" class="rounded-xl border border-emerald-500/30 bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-file-lines"></i> Izin / Sakit
                </a>
            <?php elseif ($user_role === 'staf'): ?>
                <a href="surat/requests.php" class="rounded-xl bg-amber-600 hover:bg-amber-500 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-amber-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-inbox"></i> Surat Masuk
                </a>
                <a href="presensi/attendance_report.php" class="rounded-xl border border-emerald-500/30 bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-chart-pie"></i> Rekap Presensi
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Section Title: Modul Akses Cepat -->
<div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
        <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-blue-500/10 text-blue-400 text-xs">
            <i class="fa-solid fa-grip"></i>
        </span>
        <h3 class="text-sm font-bold text-white uppercase tracking-wider">Akses Cepat Modul Utama</h3>
    </div>
    <span class="text-xs text-slate-400 hidden sm:inline">Pintasan praktis fitur sekolah harian</span>
</div>

<!-- Grid Modul & Akses Cepat (Rapi & Terstruktur) -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <a href="akademik/timetable.php" class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur hover:border-blue-500/40 hover:bg-slate-900/90 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
        <div>
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/15 text-blue-400 group-hover:scale-110 transition duration-200">
                <i class="fa-solid fa-calendar-days text-base"></i>
            </span>
            <h4 class="text-sm font-bold text-white mt-3 group-hover:text-blue-400 transition">Jadwal KBM</h4>
            <p class="text-xs text-slate-400 mt-0.5">Hari <?= $today_id_name ?></p>
        </div>
        <span class="text-xs font-semibold text-blue-400 mt-3 block truncate">
            <?= count($dash_today_schedules) > 0 ? count($dash_today_schedules) . ' Sesi Kelas' : 'Lihat Jadwal →' ?>
        </span>
    </a>

    <a href="akademik/materials.php" class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur hover:border-emerald-500/40 hover:bg-slate-900/90 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
        <div>
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-400 group-hover:scale-110 transition duration-200">
                <i class="fa-solid fa-book-open-reader text-base"></i>
            </span>
            <h4 class="text-sm font-bold text-white mt-3 group-hover:text-emerald-400 transition">Bahan Ajar</h4>
            <p class="text-xs text-slate-400 mt-0.5">Modul & E-Learning</p>
        </div>
        <span class="text-xs font-semibold text-emerald-400 mt-3 block">Buka Materi →</span>
    </a>

    <a href="perpustakaan/books.php" class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur hover:border-teal-500/40 hover:bg-slate-900/90 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
        <div>
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-500/15 text-teal-400 group-hover:scale-110 transition duration-200">
                <i class="fa-solid fa-book-bookmark text-base"></i>
            </span>
            <h4 class="text-sm font-bold text-white mt-3 group-hover:text-teal-400 transition">Perpustakaan</h4>
            <p class="text-xs text-slate-400 mt-0.5">Katalog & E-Book</p>
        </div>
        <span class="text-xs font-semibold text-teal-400 mt-3 block">
            <?= $dash_book_count > 0 ? $dash_book_count . ' Judul Buku' : 'Buka Perpus →' ?>
        </span>
    </a>

    <a href="keuangan/payments.php" class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur hover:border-amber-500/40 hover:bg-slate-900/90 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
        <div>
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400 group-hover:scale-110 transition duration-200">
                <i class="fa-solid fa-wallet text-base"></i>
            </span>
            <h4 class="text-sm font-bold text-white mt-3 group-hover:text-amber-400 transition">Keuangan SPP</h4>
            <p class="text-xs text-slate-400 mt-0.5">Iuran & Kas</p>
        </div>
        <span class="text-xs font-semibold text-amber-400 mt-3 block truncate">
            <?= in_array($user_role, ['siswa', 'orang_tua'], true) ? ($dash_total_unpaid > 0 ? formatRupiah($dash_total_unpaid) : 'Lunas <i class="fa-solid fa-check text-emerald-400 ml-1"></i>') : 'Kelola Kas →' ?>
        </span>
    </a>

    <a href="bk/counseling.php" class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur hover:border-purple-500/40 hover:bg-slate-900/90 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
        <div>
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-purple-500/15 text-purple-400 group-hover:scale-110 transition duration-200">
                <i class="fa-solid fa-scale-balanced text-base"></i>
            </span>
            <h4 class="text-sm font-bold text-white mt-3 group-hover:text-purple-400 transition">Bimbingan BK</h4>
            <p class="text-xs text-slate-400 mt-0.5">Prestasi & Disiplin</p>
        </div>
        <span class="text-xs font-semibold text-purple-400 mt-3 block">
            <?= in_array($user_role, ['siswa', 'orang_tua'], true) ? '+' . $dash_counseling_points['reward'] . ' Poin' : 'Rekam Kasus →' ?>
        </span>
    </a>

    <a href="pesan/messages.php" class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur hover:border-cyan-500/40 hover:bg-slate-900/90 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
        <div>
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-cyan-500/15 text-cyan-400 group-hover:scale-110 transition duration-200">
                <i class="fa-solid fa-comments text-base"></i>
            </span>
            <h4 class="text-sm font-bold text-white mt-3 group-hover:text-cyan-400 transition">Konsultasi</h4>
            <p class="text-xs text-slate-400 mt-0.5">Pesan & Diskusi</p>
        </div>
        <span class="text-xs font-semibold text-cyan-400 mt-3 block truncate">
            <?= $unread_msg_count > 0 ? $unread_msg_count . ' Pesan Baru <i class="fa-solid fa-bell text-cyan-400 ml-1"></i>' : 'Buka Obrolan →' ?>
        </span>
    </a>

    <a href="<?= in_array($user_role, ['guru', 'staf', 'administrator'], true) ? 'presensi/scan_qr.php' : 'presensi/qr_card.php' ?>" class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur hover:border-indigo-500/40 hover:bg-slate-900/90 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
        <div>
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-500/15 text-indigo-400 group-hover:scale-110 transition duration-200">
                <i class="fa-solid <?= in_array($user_role, ['guru', 'staf', 'administrator'], true) ? 'fa-qrcode' : 'fa-id-card' ?> text-base"></i>
            </span>
            <h4 class="text-sm font-bold text-white mt-3 group-hover:text-indigo-400 transition">
                <?= in_array($user_role, ['guru', 'staf', 'administrator'], true) ? 'Scan QR' : 'Kartu Pelajar QR' ?>
            </h4>
            <p class="text-xs text-slate-400 mt-0.5">Presensi Cepat</p>
        </div>
        <span class="text-xs font-semibold text-indigo-400 mt-3 block">
            <?= in_array($user_role, ['guru', 'staf', 'administrator'], true) ? 'Kamera Absen →' : 'Lihat Kartu →' ?>
        </span>
    </a>

    <a href="<?= in_array($user_role, ['administrator', 'staf'], true) ? 'admin/ppdb.php' : '../ppdb/ppdb.php' ?>" class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur hover:border-rose-500/40 hover:bg-slate-900/90 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
        <div>
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-500/15 text-rose-400 group-hover:scale-110 transition duration-200">
                <i class="fa-solid fa-graduation-cap text-base"></i>
            </span>
            <h4 class="text-sm font-bold text-white mt-3 group-hover:text-rose-400 transition">PPDB Online</h4>
            <p class="text-xs text-slate-400 mt-0.5">Siswa Baru</p>
        </div>
        <span class="text-xs font-semibold text-rose-400 mt-3 block truncate">
            <?= in_array($user_role, ['administrator', 'staf'], true) ? ($dash_ppdb_pending > 0 ? $dash_ppdb_pending . ' Menunggu' : 'Panitia PPDB →') : 'Portal PPDB →' ?>
        </span>
    </a>
</div>

<!-- Modal Pop-up Pengumuman Darurat Otomatis Saat Login (Fase 2) -->
<?php if (!empty($unread_emergency_announcement)): ?>
<div id="modalEmergencyPopup" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 backdrop-blur-md p-4">
    <div class="w-full max-w-xl rounded-3xl border border-rose-500/40 bg-slate-900 p-6 sm:p-8 shadow-2xl ring-1 ring-rose-500/20">
        
        <div class="flex items-center gap-3 pb-4 border-b border-rose-500/20">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-rose-500/20 text-rose-400 text-lg">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </span>
            <div>
                <span class="inline-flex items-center rounded-lg border border-rose-500/30 bg-rose-500/10 px-2.5 py-0.5 text-xs font-black uppercase tracking-wider text-rose-300">
                    PEMBERITAHUAN MENDESAK (<?= strtoupper($unread_emergency_announcement['category']) ?>)
                </span>
                <p class="text-xs text-slate-400 mt-0.5">Wajib dibaca dan dikonfirmasi oleh penerima informasi</p>
            </div>
        </div>

        <div class="py-5 space-y-3">
            <h2 class="text-xl font-extrabold text-white">
                <?= htmlspecialchars($unread_emergency_announcement['title']) ?>
            </h2>

            <div class="text-xs text-slate-400 flex items-center gap-3">
                <span><i class="fa-solid fa-user text-slate-400 mr-1"></i> <?= htmlspecialchars($unread_emergency_announcement['author_name']) ?></span>
                <span><i class="fa-solid fa-calendar-day text-slate-400 mr-1"></i> <?= date('d M Y, H:i', strtotime($unread_emergency_announcement['created_at'])) ?></span>
            </div>

            <div class="max-h-60 overflow-y-auto rounded-2xl border border-white/5 bg-slate-950/60 p-4 text-sm text-slate-200 leading-relaxed announcement-rendered-content">
                <?= sanitizeAnnouncementHtml($unread_emergency_announcement['content']) ?>
            </div>

            <?php if (!empty($unread_emergency_announcement['attachment_url'])): ?>
                <div class="p-3 rounded-xl border border-white/10 bg-slate-950/40 flex items-center justify-between text-xs">
                    <span class="text-slate-300 truncate"><i class="fa-solid fa-paperclip text-slate-400 mr-1"></i> Lampiran: <?= htmlspecialchars($unread_emergency_announcement['attachment_url']) ?></span>
                    <a href="../uploads/announcements/<?= htmlspecialchars($unread_emergency_announcement['attachment_url']) ?>" target="_blank" download class="text-blue-400 hover:underline shrink-0">
                        Unduh Berkas <i class="fa-solid fa-download ml-1"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="pt-4 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-3">
            <a href="informasi/print_announcement.php?id=<?= $unread_emergency_announcement['id'] ?>" target="_blank" class="w-full sm:w-auto text-center px-4 py-2.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-xs font-semibold text-slate-300 transition">
                <i class="fa-solid fa-print mr-1"></i> Cetak Dokumen Resmi
            </a>

            <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
                <button type="button" onclick="document.getElementById('modalEmergencyPopup').remove()" class="px-3.5 py-2.5 rounded-xl border border-white/10 text-xs font-semibold text-slate-400 hover:text-white transition cursor-pointer">
                    Tutup Sementara
                </button>
                <form method="POST" class="inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="ack_announcement">
                    <input type="hidden" name="announcement_id" value="<?= $unread_emergency_announcement['id'] ?>">
                    <button type="submit" class="w-full sm:w-auto px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-xs font-extrabold text-white transition shadow-lg shadow-rose-600/30 cursor-pointer">
                        <i class="fa-solid fa-check mr-1"></i> Saya Sudah Membaca
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>
<?php endif; ?>

<!-- Flash Message Konfirmasi Baca -->
<?php if (isset($_GET['msg']) && $_GET['msg'] === 'ack_success'): ?>
    <div class="mb-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-300 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <i class="fa-solid fa-circle-check text-emerald-400 text-lg"></i>
            <span>Terima kasih! Pengumuman mendesak telah Anda konfirmasi sebagai sudah dibaca & dipahami.</span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<!-- Banner Pengumuman Penting / Darurat -->
<?php if (!empty($urgent_announcements)): ?>
<div class="space-y-3 mb-8">
    <?php foreach ($urgent_announcements as $ua): 
        $is_darurat = (($ua['category'] ?? '') === 'darurat');
        $is_penting = (($ua['category'] ?? '') === 'penting');
        if ($is_darurat) {
            $banner_border = 'border-rose-500/40';
            $banner_bg = 'bg-gradient-to-r from-rose-950/30 via-rose-900/20 to-slate-900/30';
            $banner_icon = '<i class="fa-solid fa-triangle-exclamation"></i>';
            $banner_label = 'DARURAT';
            $banner_label_cls = 'text-rose-400';
            $banner_text = 'text-rose-200';
            $banner_btn = 'bg-rose-600 hover:bg-rose-500 shadow-rose-600/30';
            $animate = 'animate-pulse';
        } elseif ($is_penting) {
            $banner_border = 'border-amber-500/40';
            $banner_bg = 'bg-gradient-to-r from-amber-950/30 via-amber-900/15 to-slate-900/30';
            $banner_icon = '<i class="fa-solid fa-bolt"></i>';
            $banner_label = 'PENTING';
            $banner_label_cls = 'text-amber-400';
            $banner_text = 'text-amber-200';
            $banner_btn = 'bg-amber-500 hover:bg-amber-400 text-slate-950 shadow-amber-500/30';
            $animate = '';
        } else {
            $banner_border = 'border-blue-500/30';
            $banner_bg = 'bg-gradient-to-r from-blue-950/20 via-slate-900/40 to-slate-900/30';
            $banner_icon = '<i class="fa-solid fa-thumbtack"></i>';
            $banner_label = 'DISEMATKAN';
            $banner_label_cls = 'text-blue-400';
            $banner_text = 'text-blue-200';
            $banner_btn = 'bg-blue-600 hover:bg-blue-500 shadow-blue-600/30';
            $animate = '';
        }
    ?>
        <div class="p-4 rounded-2xl border <?= $banner_border ?> <?= $banner_bg ?> <?= $banner_text ?> flex flex-col sm:flex-row sm:items-center justify-between gap-3 <?= $animate ?>">
            <div class="flex items-center gap-3 min-w-0">
                <span class="text-xl flex-shrink-0"><?= $banner_icon ?></span>
                <div class="min-w-0">
                    <p class="text-[10px] font-black uppercase tracking-widest <?= $banner_label_cls ?>"><?= $banner_label ?></p>
                    <h4 class="text-sm font-bold text-white truncate"><?= htmlspecialchars($ua['title']) ?></h4>
                    <p class="text-xs <?= $banner_text ?> line-clamp-1 opacity-80"><?= htmlspecialchars(mb_substr($ua['content'], 0, 120)) ?><?= mb_strlen($ua['content']) > 120 ? '...' : '' ?></p>
                </div>
            </div>
            <a href="informasi/announcements.php" class="whitespace-nowrap px-4 py-2 rounded-xl <?= $banner_btn ?> text-xs font-bold text-white transition shadow-lg text-center flex-shrink-0">
                Baca Selengkapnya →
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ========================================================= -->
<!-- 1. TAMPILAN KHUSUS: ADMINISTRATOR -->
<!-- ========================================================= -->
<?php if ($user_role === 'administrator'): ?>

    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <span class="text-xs text-slate-400 font-medium">Total Pengguna</span>
            <p class="text-3xl font-extrabold text-white mt-2"><?= $total_users ?></p>
            <a href="admin/users.php" class="text-xs text-blue-400 hover:underline mt-1 block">Kelola pengguna →</a>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <span class="text-xs text-slate-400 font-medium">Surat Diproses</span>
            <p class="text-3xl font-extrabold text-amber-300 mt-2"><?= $total_requests_pending ?></p>
            <a href="surat/requests.php" class="text-xs text-amber-400 hover:underline mt-1 block">Lihat permohonan →</a>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <span class="text-xs text-slate-400 font-medium">Tugas Belajar</span>
            <p class="text-3xl font-extrabold text-emerald-300 mt-2"><?= $total_assignments ?></p>
            <a href="akademik/assignments.php" class="text-xs text-emerald-400 hover:underline mt-1 block">Daftar tugas →</a>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5">
            <span class="text-xs text-slate-400 font-medium">Ujian & Latihan</span>
            <p class="text-3xl font-extrabold text-purple-300 mt-2"><?= $total_exams ?></p>
            <a href="Modul-ujian/exams.php" class="text-xs text-purple-400 hover:underline mt-1 block">Kelola asesmen →</a>
        </div>

        <div class="rounded-2xl border border-white/10 bg-white/5 p-5 col-span-2 sm:col-span-1">
            <span class="text-xs text-slate-400 font-medium">Pengumuman Terbit</span>
            <p class="text-3xl font-extrabold text-blue-300 mt-2"><?= count($latest_announcements) ?></p>
            <a href="informasi/announcements.php" class="text-xs text-blue-400 hover:underline mt-1 block">Buka pengumuman →</a>
        </div>
    </div>

    <!-- Role Distribution & Modul Links -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
            <h3 class="text-base font-bold text-white mb-4 flex items-center gap-2">
                <i class="fa-solid fa-users text-blue-400"></i> Distribusi 5 Role Akun
            </h3>
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
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-bullhorn text-blue-400"></i> Pengumuman Sekolah Terkini
                </h3>
                <a href="informasi/announcements.php" class="text-xs text-blue-400 hover:underline">Semua →</a>
            </div>
            <div class="space-y-3">
                <?php foreach ($latest_announcements as $a): 
                    $a_cat = ANNOUNCEMENT_CATEGORIES[$a['category'] ?? 'umum'] ?? ANNOUNCEMENT_CATEGORIES['umum'];
                ?>
                    <div class="p-4 rounded-2xl bg-slate-900/60 border <?= !empty($a['is_pinned']) ? 'border-amber-500/30' : 'border-white/5' ?>">
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <div class="flex items-center gap-2 min-w-0">
                                <?php if (!empty($a['is_pinned'])): ?>
                                    <span class="text-amber-400 text-xs flex-shrink-0"><i class="fa-solid fa-thumbtack"></i></span>
                                <?php endif; ?>
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold <?= $a_cat['badge'] ?> flex-shrink-0"><?= $a_cat['icon'] ?> <?= $a_cat['label'] ?></span>
                                <h4 class="text-sm font-bold text-white truncate"><?= htmlspecialchars($a['title']) ?></h4>
                            </div>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <?php if (!empty($a['attachment_url'])): ?>
                                    <span class="text-[10px] text-slate-400"><i class="fa-solid fa-paperclip"></i></span>
                                <?php endif; ?>
                                <span class="text-[10px] text-slate-400"><?= date('d M Y', strtotime($a['created_at'])) ?></span>
                            </div>
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
                        <i class="fa-solid fa-file-lines text-amber-400"></i> Permohonan Surat yang Butuh Diproses
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Surat yang baru diajukan oleh siswa atau orang tua.</p>
                </div>
                <a href="surat/requests.php" class="text-xs font-semibold text-amber-400 hover:text-amber-300">
                    Buka Semua (<?= count($pending_requests) ?>) →
                </a>
            </div>

            <?php if (empty($pending_requests)): ?>
                <div class="p-8 text-center text-slate-400 text-xs flex items-center justify-center gap-2">
                    <i class="fa-solid fa-circle-check text-emerald-400"></i> Semua permohonan surat sudah diproses. Tidak ada antrean baru.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($pending_requests as $req): ?>
                        <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 flex items-center justify-between gap-4">
                            <div>
                                <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($req['request_type']) ?></h4>
                                <p class="text-xs text-slate-400">Pemohon: <strong class="text-slate-300"><?= htmlspecialchars($req['applicant_name']) ?></strong> (<?= htmlspecialchars(getRoleLabel($req['applicant_role'])) ?>)</p>
                            </div>
                            <!-- BUG-08 fix: Form POST dengan CSRF untuk quick update status surat -->
                            <div class="flex items-center gap-2">
                                <form method="POST" action="surat/requests.php" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="update_request_status">
                                    <input type="hidden" name="update_id" value="<?= $req['id'] ?>">
                                    <input type="hidden" name="new_status" value="diproses">
                                    <button type="submit" class="rounded-lg bg-blue-600 hover:bg-blue-500 px-3 py-1.5 text-xs font-semibold text-white transition cursor-pointer">
                                        Proses
                                    </button>
                                </form>
                                <form method="POST" action="surat/requests.php" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="update_request_status">
                                    <input type="hidden" name="update_id" value="<?= $req['id'] ?>">
                                    <input type="hidden" name="new_status" value="selesai">
                                    <button type="submit" class="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-white transition cursor-pointer">
                                        Selesai
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Shortcut & Pengumuman -->
        <div class="space-y-6">
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-2 flex items-center gap-2">
                    <i class="fa-solid fa-bullhorn text-blue-400"></i> Buat Pengumuman Sekolah
                </h3>
                <p class="text-xs text-slate-400 mb-4">Terbitkan informasi resmi untuk guru, siswa, atau wali murid.</p>
                <a href="informasi/announcements.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-center text-xs font-semibold text-white transition">
                    <i class="fa-solid fa-plus"></i> Tulis Pengumuman Baru
                </a>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-briefcase text-blue-400"></i> Layanan Tata Usaha
                </h3>
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
                        <i class="fa-solid fa-book-open text-emerald-400"></i> Tugas Pembelajaran yang Anda Buat
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Daftar tugas yang sedang aktif dan dikerjakan oleh siswa.</p>
                </div>
                <a href="akademik/assignments.php" class="text-xs font-semibold text-emerald-400 hover:text-emerald-300">
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
                            <a href="akademik/assignments.php" class="text-xs text-blue-400 hover:underline">
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
                            <i class="fa-solid fa-file-pen text-blue-400"></i> Paket Ujian & Latihan yang Anda Buat
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
                            $c_info = EXAM_CATEGORIES[$ex['category']] ?? ['label' => $ex['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '<i class="fa-solid fa-file-pen"></i>'];
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
                <h3 class="text-base font-bold text-white mb-2 flex items-center gap-2">
                    <i class="fa-solid fa-bolt text-amber-400"></i> Pintasan Cepat Guru
                </h3>
                <p class="text-xs text-slate-400 mb-4">Buat soal ujian formal atau latihan harian/mingguan untuk siswa.</p>
                <div class="space-y-2.5">
                    <a href="Modul-ujian/exams.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-center text-xs font-semibold text-white shadow-lg shadow-blue-500/25 transition">
                        <i class="fa-solid fa-plus"></i> Buat Ujian / Latihan Baru
                    </a>
                    <a href="akademik/assignments.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/30 text-center text-xs font-semibold text-emerald-300 transition">
                        <i class="fa-solid fa-book-open"></i> Buat Tugas Belajar
                    </a>
                    <a href="akademik/gradebook.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-amber-600/20 hover:bg-amber-600/30 border border-amber-500/30 text-center text-xs font-semibold text-amber-300 transition">
                        <i class="fa-solid fa-chart-column"></i> Rekap Nilai Gabungan
                    </a>
                </div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-bullhorn text-blue-400"></i> Pengumuman Guru Terkini
                </h3>
                <div class="space-y-3">
                    <?php foreach ($latest_announcements as $an): 
                        $an_cat = ANNOUNCEMENT_CATEGORIES[$an['category'] ?? 'umum'] ?? ANNOUNCEMENT_CATEGORIES['umum'];
                    ?>
                        <div class="p-3 rounded-xl bg-slate-900/60 border <?= !empty($an['is_pinned']) ? 'border-amber-500/30' : 'border-white/5' ?>">
                            <div class="flex items-center gap-1.5 mb-1">
                                <?php if (!empty($an['is_pinned'])): ?><span class="text-[10px] text-amber-400"><i class="fa-solid fa-thumbtack"></i></span><?php endif; ?>
                                <span class="rounded px-1 py-0.5 text-[9px] font-semibold <?= $an_cat['badge'] ?>"><?= $an_cat['icon'] ?></span>
                                <h4 class="text-xs font-bold text-white truncate"><?= htmlspecialchars($an['title']) ?></h4>
                                <?php if (!empty($an['attachment_url'])): ?><span class="text-[10px] text-slate-400 ml-auto"><i class="fa-solid fa-paperclip"></i></span><?php endif; ?>
                            </div>
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
                <div class="h-14 w-14 rounded-2xl bg-indigo-500/20 border border-indigo-500/40 flex items-center justify-center text-2xl text-indigo-400 shadow-inner">
                    <i class="fa-solid fa-user-graduate"></i>
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
                <a href="akademik/report_card.php?student_id=<?= $linked_child['id'] ?>" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-chart-line"></i> Rapor Digital Anak
                </a>
                <a href="Modul-ujian/exam_card.php?student_id=<?= $linked_child['id'] ?>" class="rounded-xl border border-purple-500/30 bg-purple-500/15 hover:bg-purple-500/25 text-purple-300 px-4 py-2.5 text-xs font-bold transition flex items-center gap-2">
                    <i class="fa-solid fa-id-card"></i> Kartu Peserta Ujian
                </a>
                <a href="presensi/attendance.php" class="rounded-xl border border-emerald-500/30 bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 px-4 py-2.5 text-xs font-bold transition flex items-center gap-2">
                    <i class="fa-solid fa-calendar-check"></i> Riwayat Presensi
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Info: Belum Ada Siswa Terhubung -->
        <div class="mb-6 rounded-3xl border border-amber-500/30 bg-amber-500/10 p-6 backdrop-blur flex items-center gap-4 text-amber-200 shadow-xl">
            <div class="h-12 w-12 rounded-2xl bg-amber-500/20 border border-amber-500/30 flex items-center justify-center text-xl text-amber-400 shrink-0">
                <i class="fa-solid fa-circle-info"></i>
            </div>
            <div>
                <h4 class="font-bold text-white text-base">Belum Ada Siswa Terhubung</h4>
                <p class="text-xs text-amber-300/80 mt-0.5">Akun orang tua ini belum terhubung dengan data siswa manapun. Silakan hubungi administrator sekolah untuk menghubungkan akun Anda dengan data ananda.</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Notifikasi Peringatan Terpadu Anak -->
    <?php if ($child_alpa_today || !empty($child_remedial_exams) || !empty($child_due_assignments)): ?>
        <div class="mb-6 space-y-3">
            <?php if ($child_alpa_today): ?>
                <div class="p-4 rounded-2xl border border-rose-500/40 bg-rose-500/15 text-rose-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 animate-pulse">
                    <div class="flex items-center gap-3">
                        <span class="text-xl text-rose-400"><i class="fa-solid fa-triangle-exclamation"></i></span>
                        <div>
                            <p class="text-xs font-black uppercase tracking-wider text-rose-400">Peringatan Presensi Hari Ini</p>
                            <p class="text-sm">Ananda <strong><?= htmlspecialchars($linked_child['name'] ?? 'Siswa') ?></strong> tercatat <strong>ALPA (Tidak Masuk Tanpa Keterangan)</strong> pada presensi hari ini!</p>
                        </div>
                    </div>
                    <a href="surat/requests.php" class="whitespace-nowrap px-4 py-2 rounded-xl bg-rose-600 hover:bg-rose-500 text-xs font-bold text-white transition shadow-lg shadow-rose-600/30 text-center">
                        Ajukan Izin / Sakit Sekarang →
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!empty($child_remedial_exams)): ?>
                <div class="p-4 rounded-2xl border border-amber-500/30 bg-amber-500/10 text-amber-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <span class="text-xl text-amber-400"><i class="fa-solid fa-file-pen"></i></span>
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
                        <span class="text-xl text-blue-400"><i class="fa-solid fa-clock"></i></span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-blue-400">Pengingat Tugas Mendekati Deadline</p>
                            <p class="text-sm">Ananda memiliki tugas belum diserahkan yang mendekati tenggat waktu:
                                <?php foreach ($child_due_assignments as $cda): ?>
                                    <span class="font-semibold text-white">[<?= htmlspecialchars($cda['title']) ?> (<?= $cda['days_left'] == 0 ? 'Hari Ini' : $cda['days_left'].' hari lagi' ?>)]</span>
                                <?php endforeach; ?>
                            </p>
                        </div>
                    </div>
                    <a href="akademik/assignments.php" class="whitespace-nowrap px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white transition text-center">
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
                        <i class="fa-solid fa-book-open text-purple-400"></i> Pemantauan Tugas Pembelajaran Anak
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Daftar tugas pelajaran aktif dari guru untuk siswa.</p>
                </div>
                <a href="akademik/assignments.php" class="text-xs font-semibold text-purple-400 hover:text-purple-300">
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
                            <i class="fa-solid fa-file-pen text-purple-400"></i> Pemantauan Ujian & Latihan Anak
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">Jadwal UTS, UKK, Ujian Harian, dan Latihan Rutin.</p>
                    </div>
                    <a href="Modul-ujian/exams.php" class="text-xs font-semibold text-purple-400 hover:text-purple-300">
                        Buka Semua (<?= count($active_exams) ?>) →
                    </a>
                </div>

                <div class="space-y-3">
                    <?php foreach ($active_exams as $ex): 
                        $c_info = EXAM_CATEGORIES[$ex['category']] ?? ['label' => $ex['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '<i class="fa-solid fa-file-pen"></i>'];
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
                        <i class="fa-solid fa-calendar-check text-emerald-400"></i> Presensi Kehadiran Anak
                    </h3>
                    <a href="presensi/attendance.php" class="text-xs font-semibold text-emerald-400 hover:underline">
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
                        <i class="fa-solid fa-calendar-days text-blue-400"></i> Agenda Terdekat
                    </h3>
                    <a href="akademik/calendar.php" class="text-xs font-semibold text-blue-400 hover:underline">
                        Kalender →
                    </a>
                </div>
                <div class="space-y-2">
                    <?php foreach ($dash_upcoming as $du): ?>
                        <div class="p-2.5 rounded-xl bg-slate-900/60 border border-white/5 flex items-center justify-between gap-2">
                            <div class="truncate">
                                <p class="text-xs font-semibold text-white truncate"><?= htmlspecialchars($du['title']) ?></p>
                                <span class="text-[10px] text-slate-400 font-mono"><i class="fa-solid fa-calendar text-xs text-slate-400 mr-1"></i><?= date('d M Y', strtotime($du['date'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-2 flex items-center gap-2">
                    <i class="fa-solid fa-file-lines text-blue-400"></i> Layanan Surat Sekolah
                </h3>
                <p class="text-xs text-slate-400 mb-4">Ajukan surat izin dispensasi atau permohonan dokumen untuk putra/putri Anda.</p>
                <a href="surat/requests.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-center text-xs font-semibold text-white transition">
                    <i class="fa-solid fa-plus"></i> Ajukan Permohonan Surat
                </a>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-bullhorn text-blue-400"></i> Pengumuman Wali Murid
                </h3>
                <div class="space-y-3">
                    <?php foreach ($latest_announcements as $an): 
                        $an_cat = ANNOUNCEMENT_CATEGORIES[$an['category'] ?? 'umum'] ?? ANNOUNCEMENT_CATEGORIES['umum'];
                    ?>
                        <div class="p-3 rounded-xl bg-slate-900/60 border <?= !empty($an['is_pinned']) ? 'border-amber-500/30' : 'border-white/5' ?>">
                            <div class="flex items-center gap-1.5 mb-1">
                                <?php if (!empty($an['is_pinned'])): ?><span class="text-[10px] text-amber-400"><i class="fa-solid fa-thumbtack"></i></span><?php endif; ?>
                                <span class="rounded px-1 py-0.5 text-[9px] font-semibold <?= $an_cat['badge'] ?>"><?= $an_cat['icon'] ?></span>
                                <h4 class="text-xs font-bold text-white truncate"><?= htmlspecialchars($an['title']) ?></h4>
                                <?php if (!empty($an['attachment_url'])): ?><span class="text-[10px] text-slate-400 ml-auto"><i class="fa-solid fa-paperclip"></i></span><?php endif; ?>
                            </div>
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

    <!-- Banner Status Siswa / Pendaftaran PPDB -->
    <?php if ($my_ppdb_reg): 
        $reg_st = $my_ppdb_reg['status'];
    ?>
        <?php if ($reg_st === 'menunggu_verifikasi'): ?>
            <div class="mb-6 rounded-3xl border border-amber-500/40 bg-gradient-to-r from-amber-950/30 via-slate-900/60 to-slate-900/40 p-5 sm:p-6 backdrop-blur shadow-xl">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-500/20 text-amber-400 text-xl shrink-0">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="rounded-lg px-2.5 py-0.5 text-xs font-bold border border-amber-500/40 bg-amber-500/10 text-amber-300 uppercase tracking-wider">
                                    PPDB: Menunggu Verifikasi Berkas
                                </span>
                                <span class="text-xs text-slate-400 font-mono">No. Reg: <?= htmlspecialchars($my_ppdb_reg['registration_no']) ?></span>
                            </div>
                            <h3 class="text-base font-bold text-white">Pendaftaran Anda Sedang Diperiksa Panitia</h3>
                            <p class="text-xs text-slate-300 mt-1 max-w-2xl leading-relaxed">
                                Berkas pendaftaran Anda telah diterima sistem dan sedang dalam antrean verifikasi oleh staf/panitia sekolah. Modul akademik (jadwal, tugas, ujian kelas) akan aktif penuh begitu Anda dinyatakan <strong>Diterima Resmi</strong>.
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 sm:self-center shrink-0">
                        <a href="../ppdb/ppdb_card.php?reg_id=<?= $my_ppdb_reg['id'] ?>" target="_blank" class="px-3.5 py-2 rounded-xl bg-amber-500/20 hover:bg-amber-500/30 border border-amber-500/30 text-xs font-bold text-amber-300 transition flex items-center gap-1.5">
                            <i class="fa-solid fa-print"></i> Cetak Bukti PPDB
                        </a>
                        <a href="../ppdb/ppdb.php?tab=cek&no=<?= urlencode($my_ppdb_reg['registration_no']) ?>" target="_blank" class="px-3.5 py-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 text-xs font-bold text-slate-300 transition flex items-center gap-1.5">
                            <i class="fa-solid fa-magnifying-glass"></i> Cek Berkas
                        </a>
                    </div>
                </div>
            </div>
        <?php elseif ($reg_st === 'diverifikasi'): ?>
            <div class="mb-6 rounded-3xl border border-blue-500/40 bg-gradient-to-r from-blue-950/30 via-slate-900/60 to-slate-900/40 p-5 sm:p-6 backdrop-blur shadow-xl">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-500/20 text-blue-400 text-xl shrink-0">
                            <i class="fa-solid fa-file-circle-check"></i>
                        </span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="rounded-lg px-2.5 py-0.5 text-xs font-bold border border-blue-500/40 bg-blue-500/10 text-blue-300 uppercase tracking-wider">
                                    PPDB: Berkas Sah & Terverifikasi
                                </span>
                                <span class="text-xs text-slate-400 font-mono">No. Reg: <?= htmlspecialchars($my_ppdb_reg['registration_no']) ?></span>
                            </div>
                            <h3 class="text-base font-bold text-white">Tahap Pemeringkatan & Seleksi Nilai</h3>
                            <p class="text-xs text-slate-300 mt-1 max-w-2xl leading-relaxed">
                                Berkas persyaratan Anda dinyatakan lengkap dan valid. Data Anda saat ini sedang dalam proses pemeringkatan seleksi masuk gelombang ini.
                            </p>
                        </div>
                    </div>
                    <a href="../ppdb/ppdb_card.php?reg_id=<?= $my_ppdb_reg['id'] ?>" target="_blank" class="px-3.5 py-2 rounded-xl bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/40 text-xs font-bold text-blue-300 transition flex items-center gap-1.5 self-start sm:self-center shrink-0">
                        <i class="fa-solid fa-print"></i> Kartu Pendaftaran
                    </a>
                </div>
            </div>
        <?php elseif ($reg_st === 'lulus_seleksi'): ?>
            <div class="mb-6 rounded-3xl border border-indigo-500/40 bg-gradient-to-r from-indigo-950/35 via-purple-950/20 to-slate-900/40 p-5 sm:p-6 backdrop-blur shadow-xl">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-start gap-4">
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-500/20 text-indigo-400 text-xl shrink-0">
                            <i class="fa-solid fa-award"></i>
                        </span>
                        <div>
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="rounded-lg px-2.5 py-0.5 text-xs font-bold border border-indigo-500/40 bg-indigo-500/20 text-indigo-300 uppercase tracking-wider">
                                    🎉 LULUS SELEKSI PPDB
                                </span>
                                <span class="text-xs text-slate-400 font-mono">No. Reg: <?= htmlspecialchars($my_ppdb_reg['registration_no']) ?></span>
                            </div>
                            <h3 class="text-base font-bold text-white">Selamat! Anda Dinyatakan Lulus Seleksi Masuk</h3>
                            <p class="text-xs text-slate-300 mt-1 max-w-2xl leading-relaxed">
                                Panitia PPDB sedang memproses penempatan rombongan belajar (kelas) dan aktivasi akun siswa Anda. Silakan hubungi tata usaha atau pantau pengumuman daftar ulang.
                            </p>
                        </div>
                    </div>
                    <a href="../ppdb/ppdb_card.php?reg_id=<?= $my_ppdb_reg['id'] ?>" target="_blank" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-xs font-black text-white transition shadow-lg shadow-indigo-600/30 flex items-center gap-1.5 self-start sm:self-center shrink-0">
                        <i class="fa-solid fa-file-invoice"></i> Unduh Bukti Lulus
                    </a>
                </div>
            </div>
        <?php elseif ($reg_st === 'tidak_lulus'): ?>
            <div class="mb-6 rounded-3xl border border-rose-500/40 bg-gradient-to-r from-rose-950/30 via-slate-900/60 to-slate-900/40 p-5 sm:p-6 backdrop-blur shadow-xl">
                <div class="flex items-start gap-4">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-500/20 text-rose-400 text-xl shrink-0">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </span>
                    <div>
                        <span class="rounded-lg px-2.5 py-0.5 text-xs font-bold border border-rose-500/40 bg-rose-500/10 text-rose-300 uppercase tracking-wider">
                            Pemberitahuan Seleksi PPDB
                        </span>
                        <h3 class="text-base font-bold text-white mt-1">Belum Memenuhi Kuota Seleksi</h3>
                        <p class="text-xs text-slate-300 mt-1 max-w-2xl leading-relaxed">
                            Mohon maaf, berdasarkan kuota kursi dan nilai seleksi, pendaftaran Anda belum memenuhi kualifikasi pada gelombang ini. Terima kasih atas partisipasi Anda.
                        </p>
                    </div>
                </div>
            </div>
        <?php elseif ($reg_st === 'diterima' || !empty($student_class_name)): ?>
            <div class="mb-6 rounded-3xl border border-emerald-500/30 bg-gradient-to-r from-emerald-950/30 via-slate-900/60 to-slate-900/40 p-4 sm:p-5 backdrop-blur flex items-center justify-between gap-4 shadow-xl">
                <div class="flex items-center gap-3.5">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-lg shrink-0">
                        <i class="fa-solid fa-circle-check"></i>
                    </span>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold text-emerald-300">Status: Siswa Resmi Aktif</span>
                            <span class="text-white/20">•</span>
                            <span class="text-xs font-semibold text-white">Kelas: <?= htmlspecialchars($student_class_name ?? 'X PPLG') ?></span>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-0.5">Semua fitur KBM, Ujian CBT, E-Rapor, Presensi, dan Perpustakaan aktif 100%.</p>
                    </div>
                </div>
                <a href="presensi/qr_card.php" class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 text-xs font-semibold hover:bg-emerald-500/20 transition shrink-0">
                    <i class="fa-solid fa-id-card"></i> Kartu Pelajar
                </a>
            </div>
        <?php endif; ?>
    <?php elseif (empty($std_class)): ?>
        <div class="mb-6 rounded-3xl border border-blue-500/30 bg-gradient-to-r from-blue-950/30 via-slate-900/60 to-slate-900/40 p-5 sm:p-6 backdrop-blur shadow-xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-start gap-4">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-500/20 text-blue-400 text-xl shrink-0">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </span>
                    <div>
                        <span class="rounded-lg px-2.5 py-0.5 text-xs font-bold border border-blue-500/40 bg-blue-500/10 text-blue-300 uppercase tracking-wider">
                            Akun Siswa Mandiri
                        </span>
                        <h3 class="text-base font-bold text-white mt-1">Belum Terdaftar di Rombel Kelas / PPDB</h3>
                        <p class="text-xs text-slate-300 mt-1 max-w-2xl leading-relaxed">
                            Akun Anda telah terdaftar sebagai siswa, namun belum memiliki penempatan rombel kelas. Jika Anda merupakan calon siswa baru, silakan lengkapi formulir pendaftaran PPDB Online agar berkas Anda diproses.
                        </p>
                    </div>
                </div>
                <a href="../ppdb/ppdb.php" class="px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white transition shadow-lg shadow-blue-500/25 flex items-center gap-1.5 self-start sm:self-center shrink-0">
                    <i class="fa-solid fa-file-signature"></i> Daftar PPDB Online
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Checklist Tugas Siswa -->
        <div class="lg:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-book-open-reader text-blue-400"></i> Tugas Belajar Aktif Anda
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Kerjakan dan tandai tugas yang sudah Anda selesaikan.</p>
                </div>
                <a href="akademik/assignments.php" class="text-xs font-semibold text-blue-400 hover:text-blue-300">
                    Lihat Semua Tugas →
                </a>
            </div>

            <?php if (empty($active_assignments)): ?>
                <div class="p-8 text-center text-slate-400 text-xs flex items-center justify-center gap-2">
                    <i class="fa-solid fa-circle-check text-emerald-400"></i> Tidak ada tugas aktif saat ini.
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
                                <a href="akademik/assignments.php?toggle_id=<?= $asg['id'] ?>" 
                                   class="inline-flex items-center justify-center gap-1.5 py-1.5 px-3.5 rounded-xl text-xs font-semibold transition <?= $is_done ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-500/30' : 'bg-blue-600 hover:bg-blue-500 text-white' ?>">
                                    <i class="fa-solid fa-check text-xs"></i> <?= $is_done ? 'Selesai' : 'Tandai Selesai' ?>
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
                            <i class="fa-solid fa-file-pen text-blue-400"></i> Ujian & Latihan Belajar Anda
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5">Penilaian UTS, UKK, Ujian Harian, serta Latihan Harian/Mingguan/Bulanan.</p>
                    </div>
                    <a href="Modul-ujian/exams.php" class="text-xs font-semibold text-blue-400 hover:text-blue-300">
                        Buka Semua (<?= count($active_exams) ?>) →
                    </a>
                </div>

                <div class="space-y-3">
                    <?php foreach ($active_exams as $ex): 
                        $c_info = EXAM_CATEGORIES[$ex['category']] ?? ['label' => $ex['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '<i class="fa-solid fa-file-pen"></i>'];
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
                                        <span class="text-[10px] text-emerald-400 font-semibold"><i class="fa-solid fa-bolt mr-1"></i>Fleksibel</span>
                                    <?php else: ?>
                                        <span class="text-[10px] text-amber-400 font-semibold"><i class="fa-solid fa-stopwatch mr-1"></i><?= $ex['duration_minutes'] ?> Menit</span>
                                    <?php endif; ?>
                                    <?php if (!empty($ex['token'])): ?>
                                        <span class="text-[10px] text-amber-300 font-mono font-bold bg-amber-500/10 border border-amber-500/20 px-1.5 py-0.5 rounded"><i class="fa-solid fa-lock text-[9px] mr-1"></i>Token</span>
                                    <?php endif; ?>
                                </div>
                                <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($ex['title']) ?></h4>
                                <p class="text-xs text-slate-400 mt-0.5">Guru: <?= htmlspecialchars($ex['teacher_name']) ?> • KKM: <?= $ex['passing_grade'] ?></p>
                            </div>
                            <div>
                                <?php if ($remedial_ready): ?>
                                    <a href="Modul-ujian/exam_take.php?id=<?= $ex['id'] ?>&remedial=1" class="inline-flex items-center gap-1.5 rounded-xl bg-amber-500 hover:bg-amber-400 px-3.5 py-1.5 text-xs font-black text-slate-950 shadow-lg shadow-amber-500/25 transition">
                                        <i class="fa-solid fa-rotate mr-1"></i> Kerjakan Remedial
                                    </a>
                                <?php elseif ($already_done): ?>
                                    <a href="Modul-ujian/exam_results.php?id=<?= $ex['id'] ?>" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-1.5 text-xs font-bold text-emerald-300 hover:bg-emerald-500/20 transition">
                                        <span>Nilai: <?= number_format($my_score, 0) ?></span> <i class="fa-solid fa-arrow-right text-xs"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="Modul-ujian/exam_take.php?id=<?= $ex['id'] ?>" class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 px-3.5 py-1.5 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition">
                                        <i class="fa-solid fa-pen mr-1"></i> Kerjakan
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
                        <i class="fa-solid fa-calendar-check text-emerald-400"></i> Presensi Kehadiran
                    </h3>
                    <a href="presensi/attendance.php" class="text-xs font-semibold text-emerald-400 hover:underline">
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
                        <i class="fa-solid fa-calendar-days text-blue-400"></i> Agenda Terdekat
                    </h3>
                    <a href="akademik/calendar.php" class="text-xs font-semibold text-blue-400 hover:underline">
                        Kalender →
                    </a>
                </div>
                <div class="space-y-2">
                    <?php foreach ($dash_upcoming as $du): ?>
                        <div class="p-2.5 rounded-xl bg-slate-900/60 border border-white/5 flex items-center justify-between gap-2">
                            <div class="truncate">
                                <p class="text-xs font-semibold text-white truncate"><?= htmlspecialchars($du['title']) ?></p>
                                <span class="text-[10px] text-slate-400 font-mono"><i class="fa-solid fa-calendar text-xs text-slate-400 mr-1"></i><?= date('d M Y', strtotime($du['date'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-2 flex items-center gap-2">
                    <i class="fa-solid fa-bolt text-amber-400"></i> Pintasan Cepat Siswa
                </h3>
                <p class="text-xs text-slate-400 mb-4">Akses dokumen akademik dan layanan permohonan surat tata usaha.</p>
                <div class="space-y-2.5">
                    <a href="Modul-ujian/exam_card.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl border border-purple-500/30 bg-purple-500/15 hover:bg-purple-500/25 text-center text-xs font-bold text-purple-300 transition">
                        <i class="fa-solid fa-id-card"></i> Cetak Kartu Peserta Ujian
                    </a>
                    <a href="akademik/report_card.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-center text-xs font-bold text-white shadow-lg shadow-blue-500/20 transition">
                        <i class="fa-solid fa-chart-line"></i> Cetak / Unduh E-Rapor Digital
                    </a>
                    <a href="Modul-ujian/exams.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-center text-xs font-semibold text-slate-300 transition">
                        <i class="fa-solid fa-file-pen"></i> Buka Ujian & Latihan
                    </a>
                    <a href="surat/requests.php" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl bg-white/10 hover:bg-white/20 text-center text-xs font-semibold text-white transition">
                        <i class="fa-solid fa-file-lines"></i> Ajukan Surat Keterangan / Izin
                    </a>
                </div>
            </div>

            <div class="rounded-3xl border border-white/10 bg-white/5 p-6">
                <h3 class="text-base font-bold text-white mb-3 flex items-center gap-2">
                    <i class="fa-solid fa-bullhorn text-blue-400"></i> Pengumuman Sekolah
                </h3>
                <div class="space-y-3">
                    <?php foreach ($latest_announcements as $an): 
                        $an_cat = ANNOUNCEMENT_CATEGORIES[$an['category'] ?? 'umum'] ?? ANNOUNCEMENT_CATEGORIES['umum'];
                    ?>
                        <div class="p-3 rounded-xl bg-slate-900/60 border <?= !empty($an['is_pinned']) ? 'border-amber-500/30' : 'border-white/5' ?>">
                            <div class="flex items-center gap-1.5 mb-1">
                                <?php if (!empty($an['is_pinned'])): ?><span class="text-[10px] text-amber-400"><i class="fa-solid fa-thumbtack"></i></span><?php endif; ?>
                                <span class="rounded px-1 py-0.5 text-[9px] font-semibold <?= $an_cat['badge'] ?>"><?= $an_cat['icon'] ?></span>
                                <h4 class="text-xs font-bold text-white truncate"><?= htmlspecialchars($an['title']) ?></h4>
                                <?php if (!empty($an['attachment_url'])): ?><span class="text-[10px] text-slate-400 ml-auto"><i class="fa-solid fa-paperclip"></i></span><?php endif; ?>
                            </div>
                            <p class="text-[11px] text-slate-400 line-clamp-2"><?= htmlspecialchars($an['content']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . "/includes/footer.php"; ?>