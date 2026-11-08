<?php
session_start();

// -07 fix: Handle Logout via POST with CSRF validation
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
    $stmt_class = $pdo->prepare("SELECT class_id FROM users WHERE id = ?");
    $stmt_class->execute([$user_id]);
    $std_class = $stmt_class->fetchColumn();
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

    // Query Asesmen & Ujian Aktif (disesuaikan dengan kelas siswa / orang tua & kecualikan ujian PPDB)
    if ($user_role === 'siswa') {
        if (!empty($std_class)) {
            $stmt_act_ex = $pdo->prepare("
                SELECT e.*, u.name as teacher_name, 
                       (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.id) as q_count 
                FROM exams e 
                JOIN users u ON e.teacher_id = u.id 
                WHERE e.status = 'aktif' 
                  AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
                  AND (e.class_id IS NULL OR e.class_id = 0 OR e.class_id = ?)
                ORDER BY e.id DESC LIMIT 4
            ");
            $stmt_act_ex->execute([(int)$std_class]);
        } else {
            $stmt_act_ex = $pdo->prepare("
                SELECT e.*, u.name as teacher_name, 
                       (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.id) as q_count 
                FROM exams e 
                JOIN users u ON e.teacher_id = u.id 
                WHERE e.status = 'aktif' 
                  AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
                  AND (e.class_id IS NULL OR e.class_id = 0)
                ORDER BY e.id DESC LIMIT 4
            ");
            $stmt_act_ex->execute();
        }
    } elseif ($user_role === 'orang_tua' && !empty($p_classes)) {
        $in_pcl = implode(',', array_fill(0, count($p_classes), '?'));
        $stmt_act_ex = $pdo->prepare("
            SELECT e.*, u.name as teacher_name, 
                   (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.id) as q_count 
            FROM exams e 
            JOIN users u ON e.teacher_id = u.id 
            WHERE e.status = 'aktif' 
              AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
              AND (e.class_id IS NULL OR e.class_id = 0 OR e.class_id IN ($in_pcl))
            ORDER BY e.id DESC LIMIT 4
        ");
        $stmt_act_ex->execute($p_classes);
    } else {
        $stmt_act_ex = $pdo->query("
            SELECT e.*, u.name as teacher_name, 
                   (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.id) as q_count 
            FROM exams e 
            JOIN users u ON e.teacher_id = u.id 
            WHERE e.status = 'aktif' 
              AND (e.is_ppdb IS NULL OR e.is_ppdb = 0)
            ORDER BY e.id DESC LIMIT 4
        ");
    }
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
            $stmt_cls_name = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
            $stmt_cls_name->execute([(int)$std_class]);
            $student_class_name = $stmt_cls_name->fetchColumn();
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
        $parent_children = [];
        try {
            $stmt_all_kids = $pdo->prepare("
                SELECT u.id, u.name, u.nisn, u.gender, c.name as class_name, 
                       hr.name as homeroom_name, hr.phone as homeroom_phone
                FROM parent_students ps
                JOIN users u ON ps.student_id = u.id
                LEFT JOIN classes c ON u.class_id = c.id
                LEFT JOIN users hr ON c.homeroom_teacher_id = hr.id
                WHERE ps.parent_id = ?
                ORDER BY u.name ASC
            ");
            $stmt_all_kids->execute([$user_id]);
            $parent_children = $stmt_all_kids->fetchAll();
        } catch (Exception $e) {}

        $selected_child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;
        if ($selected_child_id > 0) {
            foreach ($parent_children as $pk) {
                if ((int)$pk['id'] === $selected_child_id) {
                    $linked_child = $pk;
                    break;
                }
            }
        }
        if (!$linked_child && !empty($parent_children)) {
            $linked_child = $parent_children[0];
        }

        if ($linked_child) {
            $att_student_id = (int)$linked_child['id'];
        }

        // Statistik Presensi Anak Bulan Berjalan
        $child_att_stats = ['hadir' => 0, 'sakit' => 0, 'izin' => 0, 'alpa' => 0, 'total' => 0, 'rate' => 100];
        try {
            $stmt_ch_att_m = $pdo->prepare("
                SELECT 
                    COUNT(CASE WHEN status = 'hadir' THEN 1 END) as hadir,
                    COUNT(CASE WHEN status = 'sakit' THEN 1 END) as sakit,
                    COUNT(CASE WHEN status = 'izin' THEN 1 END) as izin,
                    COUNT(CASE WHEN status = 'alpa' THEN 1 END) as alpa,
                    COUNT(*) as total
                FROM student_attendance
                WHERE student_id = ? AND DATE_FORMAT(date, '%Y-%m') = DATE_FORMAT(CURRENT_DATE(), '%Y-%m')
            ");
            $stmt_ch_att_m->execute([$att_student_id]);
            $ch_m_row = $stmt_ch_att_m->fetch();
            if ($ch_m_row && (int)$ch_m_row['total'] > 0) {
                $child_att_stats = [
                    'hadir' => (int)$ch_m_row['hadir'],
                    'sakit' => (int)$ch_m_row['sakit'],
                    'izin'  => (int)$ch_m_row['izin'],
                    'alpa'  => (int)$ch_m_row['alpa'],
                    'total' => (int)$ch_m_row['total'],
                    'rate'  => round(((int)$ch_m_row['hadir'] / (int)$ch_m_row['total']) * 100, 1)
                ];
            }
        } catch (Exception $e) {}

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

            // 4. Data Holistik 360° Anak (Fase 3: Student 360° Radar)
            $child_today_att = null;
            $child_total_unpaid = 0.0;
            $child_unpaid_count = 0;
            $child_bk_points = 0;
            $child_active_loans_count = 0;

            // Presensi hari ini
            $stmt_att_today = $pdo->prepare("SELECT status, notes FROM student_attendance WHERE student_id = ? AND date = CURRENT_DATE() LIMIT 1");
            $stmt_att_today->execute([$att_student_id]);
            $child_today_att = $stmt_att_today->fetch();

            // Tagihan belum lunas
            $stmt_child_bills = $pdo->prepare("
                SELECT b.amount,
                       COALESCE((SELECT SUM(amount_paid) FROM bill_payments WHERE bill_id = b.id AND status = 'diterima'), 0) as paid_amount
                FROM student_bills b
                WHERE b.student_id = ? AND b.status != 'lunas'
            ");
            $stmt_child_bills->execute([$att_student_id]);
            while ($bl = $stmt_child_bills->fetch()) {
                $rem = max(0, (float)$bl['amount'] - (float)$bl['paid_amount']);
                $child_total_unpaid += $rem;
                $child_unpaid_count++;
            }

            // Poin Pelanggaran BK
            $stmt_ch_bk = $pdo->prepare("SELECT COALESCE(SUM(points), 0) FROM counseling_records WHERE student_id = ? AND type = 'pelanggaran'");
            $stmt_ch_bk->execute([$att_student_id]);
            $child_bk_points = (int)$stmt_ch_bk->fetchColumn();

            // Pinjaman Buku Perpustakaan Aktif
            $stmt_ch_lib = $pdo->prepare("SELECT COUNT(*) FROM library_loans WHERE user_id = ? AND status = 'dipinjam'");
            $stmt_ch_lib->execute([$att_student_id]);
            $child_active_loans_count = (int)$stmt_ch_lib->fetchColumn();

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

// Data Sistem Berita Terintegrasi untuk Dashboard
$dash_pending_news_count = 0;
$dash_my_news_stats = ['published' => 0, 'pending' => 0, 'rejected' => 0, 'draft' => 0];
$dash_latest_news = [];
try {
    if (in_array($user_role, ['administrator', 'staf'], true)) {
        $dash_pending_news_count = (int)$pdo->query("SELECT COUNT(*) FROM `news` WHERE `status` = 'pending'")->fetchColumn();
    }
    if ($user_role === 'guru') {
        $stmt_mn = $pdo->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'published' THEN 1 END) as pub,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pnd,
                COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rej,
                COUNT(CASE WHEN status = 'draft' THEN 1 END) as drf
            FROM news WHERE author_id = ?
        ");
        $stmt_mn->execute([$user_id]);
        $mn_row = $stmt_mn->fetch();
        if ($mn_row) {
            $dash_my_news_stats = [
                'published' => (int)$mn_row['pub'],
                'pending'   => (int)$mn_row['pnd'],
                'rejected'  => (int)$mn_row['rej'],
                'draft'     => (int)$mn_row['drf']
            ];
        }
    }
    $dash_latest_news = $pdo->query("
        SELECT n.id, n.title, n.slug, n.category, n.thumbnail, n.created_at, n.views, u.name as author_name, u.role as author_role
        FROM news n
        JOIN users u ON n.author_id = u.id
        WHERE n.status = 'published'
        ORDER BY n.published_at DESC LIMIT 4
    ")->fetchAll();
} catch (Exception $e) {}

// Data Guru Tambahan untuk Beranda
$teacher_att_today = null;
$teacher_journals_today = 0;
$teacher_pending_submissions = 0;
if ($user_role === 'guru') {
    try {
        $stmt_ta_now = $pdo->prepare("SELECT * FROM teacher_attendance WHERE teacher_id = ? AND date = CURRENT_DATE() LIMIT 1");
        $stmt_ta_now->execute([$user_id]);
        $teacher_att_today = $stmt_ta_now->fetch();

        $stmt_tj_now = $pdo->prepare("SELECT COUNT(*) FROM teaching_journals WHERE teacher_id = ? AND date = CURRENT_DATE()");
        $stmt_tj_now->execute([$user_id]);
        $teacher_journals_today = (int)$stmt_tj_now->fetchColumn();

        $stmt_t_sub = $pdo->prepare("
            SELECT COUNT(*) 
            FROM assignment_submissions sub
            JOIN assignments a ON sub.assignment_id = a.id
            WHERE a.teacher_id = ? AND sub.status = 'submitted'
        ");
        $stmt_t_sub->execute([$user_id]);
        $teacher_pending_submissions = (int)$stmt_t_sub->fetchColumn();
    } catch (Exception $e) {}
}

// Deteksi Integrasi API Cuaca Eksternal Aktif (Fase API Luar)
$dash_weather_active = false;
$dash_weather_url = '';
try {
    $stmt_w = $pdo->query("SELECT endpoint_url FROM external_apis WHERE api_code = 'weather_api' AND is_active = 1 LIMIT 1");
    $dash_weather_url = $stmt_w ? $stmt_w->fetchColumn() : '';
    if (!empty($dash_weather_url)) {
        $dash_weather_active = true;
    }
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
            <div class="flex items-center gap-2 flex-wrap mb-3">
                <div class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold uppercase tracking-wider <?= getRoleBadge($user_role) ?>">
                    <i class="<?= getRoleIcon($user_role) ?> text-[10px]"></i>
                    <span>Peran: <?= htmlspecialchars(getRoleLabel($user_role)) ?></span>
                </div>
                <?php if ($dash_weather_active): ?>
                    <div id="school-weather-badge" class="inline-flex items-center gap-1.5 rounded-full border border-cyan-500/30 bg-cyan-500/10 px-3 py-1 text-xs font-semibold text-cyan-300 shadow-sm" title="Prakiraan Cuaca Real-time Sekolah via External API">
                        <i class="fa-solid fa-cloud-sun text-cyan-400" id="weather-icon"></i>
                        <span id="school-weather-text">Cuaca: Memuat...</span>
                    </div>
                <?php endif; ?>
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
                <a href="admin/external_apis.php" class="rounded-xl border border-cyan-500/30 bg-cyan-500/15 hover:bg-cyan-500/25 text-cyan-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Kelola API Luar
                </a>
                <a href="admin/users.php" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-blue-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-users"></i> Kelola Pengguna
                </a>
                <a href="admin/classes.php" class="rounded-xl border border-indigo-500/30 bg-indigo-500/15 hover:bg-indigo-500/25 text-indigo-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-school"></i> Rombel & Kelas
                </a>
                <a href="informasi/news.php" class="rounded-xl border border-sky-500/30 bg-sky-500/15 hover:bg-sky-500/25 text-sky-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-newspaper"></i> Berita Sekolah
                </a>
                <a href="informasi/announcements.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-bullhorn text-slate-300"></i> Pengumuman
                </a>
            <?php elseif ($user_role === 'guru'): ?>
                <a href="presensi/teacher_attendance.php" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-emerald-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-user-clock"></i> Presensi Mandiri
                </a>
                <a href="akademik/journal.php" class="rounded-xl border border-amber-500/30 bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-book-bookmark"></i> Jurnal KBM
                </a>
                <a href="akademik/assignments.php" class="rounded-xl border border-blue-500/30 bg-blue-500/15 hover:bg-blue-500/25 text-blue-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                    <i class="fa-solid fa-list-check"></i> Tugas Siswa
                </a>
                <?php if (!empty($is_homeroom_teacher)): ?>
                    <a href="akademik/homeroom.php" class="rounded-xl border border-purple-500/30 bg-purple-500/15 hover:bg-purple-500/25 text-purple-300 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2">
                        <i class="fa-solid fa-chalkboard-user"></i> Wali Kelas
                    </a>
                <?php endif; ?>
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
                <a href="informasi/news.php" class="rounded-xl bg-sky-600 hover:bg-sky-500 px-4 py-2.5 text-xs font-semibold text-white shadow-lg shadow-sky-500/20 transition flex items-center gap-2">
                    <i class="fa-solid fa-newspaper"></i> Kelola Berita
                </a>
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

<!-- ============================================================== -->
<!-- BANNER PERSETUJUAN BERITA GURU (Khusus Admin / Staf / Guru) -->
<!-- ============================================================== -->
<?php if (in_array($user_role, ['administrator', 'staf'], true) && $dash_pending_news_count > 0): ?>
    <div class="mb-6 p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-amber-950/40 via-slate-900 to-amber-900/20 border border-amber-500/40 shadow-lg shadow-amber-500/10 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-start gap-3.5">
            <div class="h-10 w-10 rounded-2xl bg-amber-500/20 border border-amber-500/30 flex items-center justify-center text-amber-400 shrink-0 text-lg animate-pulse">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div>
                <h4 class="text-sm font-bold text-white flex items-center gap-2">
                    Moderasi Berita Guru Menunggu Persetujuan
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-400 text-slate-950"><?= $dash_pending_news_count ?> Berita</span>
                </h4>
                <p class="text-xs text-slate-300 mt-0.5 leading-relaxed">
                    Terdapat tulisan berita dari dewan guru yang menunggu peninjauan dan persetujuan dari Staf atau Administrator sebelum tayang ke publik.
                </p>
            </div>
        </div>
        <a href="informasi/news.php?status=pending" class="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs shadow-md shadow-amber-500/20 transition whitespace-nowrap flex items-center gap-1.5 self-start sm:self-auto">
            <span>Periksa & Setujui</span>
            <i class="fa-solid fa-arrow-right text-[11px]"></i>
        </a>
    </div>
<?php elseif ($user_role === 'guru' && ($dash_my_news_stats['pending'] > 0 || $dash_my_news_stats['rejected'] > 0)): ?>
    <div class="mb-6 p-4 rounded-2xl bg-slate-900 border border-blue-500/30 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400 text-sm">
                <i class="fa-solid fa-feather-pointed"></i>
            </span>
            <div class="text-xs">
                <p class="font-bold text-white">Status Publikasi Tulisan Berita Anda:</p>
                <p class="text-slate-300">
                    <span class="text-emerald-400 font-semibold"><?= $dash_my_news_stats['published'] ?> Terbit</span> • 
                    <span class="text-amber-400 font-semibold"><?= $dash_my_news_stats['pending'] ?> Menunggu Persetujuan</span> • 
                    <?php if ($dash_my_news_stats['rejected'] > 0): ?>
                        <span class="text-rose-400 font-semibold"><?= $dash_my_news_stats['rejected'] ?> Perlu Revisi</span>
                    <?php else: ?>
                        <span class="text-slate-400"><?= $dash_my_news_stats['draft'] ?> Draf</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <a href="informasi/news.php?scope=my" class="px-3.5 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold transition whitespace-nowrap self-start sm:self-auto">
            Kelola Berita Saya →
        </a>
    </div>
<?php endif; ?>

<!-- ============================================================== -->
<!-- WIDGET KHUSUS GURU: AKTIVITAS & MONITORING HARIAN -->
<!-- ============================================================== -->
<?php if ($user_role === 'guru'): ?>
    <div class="mb-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Card 1: Status Presensi Hari Ini -->
        <div class="p-4 rounded-2xl border border-white/10 bg-slate-900/60 backdrop-blur flex items-center justify-between shadow-lg">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl <?= $teacher_att_today ? ($teacher_att_today['status'] === 'hadir' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-400') : 'bg-rose-500/20 text-rose-400' ?>">
                    <i class="fa-solid <?= $teacher_att_today ? 'fa-user-check' : 'fa-user-clock' ?> text-lg"></i>
                </span>
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Presensi Mandiri</p>
                    <p class="text-sm font-bold text-white mt-0.5">
                        <?php if ($teacher_att_today): ?>
                            <span class="capitalize text-emerald-400"><?= htmlspecialchars($teacher_att_today['status']) ?></span>
                            <span class="text-xs text-slate-400">(<?= substr($teacher_att_today['check_in_time'] ?? '', 0, 5) ?>)</span>
                        <?php else: ?>
                            <span class="text-rose-400">Belum Clock-In</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <a href="presensi/teacher_attendance.php" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition" title="Buka Presensi">
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
        </div>

        <!-- Card 2: Jurnal Mengajar Hari Ini -->
        <div class="p-4 rounded-2xl border border-white/10 bg-slate-900/60 backdrop-blur flex items-center justify-between shadow-lg">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                    <i class="fa-solid fa-book-bookmark text-lg"></i>
                </span>
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Jurnal KBM Hari Ini</p>
                    <p class="text-sm font-bold text-white mt-0.5"><?= $teacher_journals_today ?> Catatan Terisi</p>
                </div>
            </div>
            <a href="akademik/journal.php" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition" title="Tulis Jurnal">
                <i class="fa-solid fa-plus text-xs"></i>
            </a>
        </div>

        <!-- Card 3: Tugas Siswa Perlu Dinilai -->
        <div class="p-4 rounded-2xl border border-white/10 bg-slate-900/60 backdrop-blur flex items-center justify-between shadow-lg">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400">
                    <i class="fa-solid fa-list-check text-lg"></i>
                </span>
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Tugas Menunggu Nilai</p>
                    <p class="text-sm font-bold text-white mt-0.5"><?= $teacher_pending_submissions ?> Submission</p>
                </div>
            </div>
            <a href="akademik/assignments.php" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition" title="Periksa Tugas">
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
        </div>

        <!-- Card 4: Wali Kelas / Perangkat Ajar -->
        <div class="p-4 rounded-2xl border border-white/10 bg-slate-900/60 backdrop-blur flex items-center justify-between shadow-lg">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-violet-500/20 text-violet-400">
                    <i class="fa-solid <?= !empty($is_homeroom_teacher) ? 'fa-chalkboard-user' : 'fa-folder-tree' ?> text-lg"></i>
                </span>
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider"><?= !empty($is_homeroom_teacher) ? 'Wali Kelas' : 'Perangkat Ajar' ?></p>
                    <p class="text-sm font-bold text-white mt-0.5 truncate max-w-[120px]"><?= !empty($is_homeroom_teacher) ? htmlspecialchars($homeroom_class_info['name']) : 'RPP & Modul' ?></p>
                </div>
            </div>
            <a href="<?= !empty($is_homeroom_teacher) ? 'akademik/homeroom.php' : 'akademik/curriculum.php' ?>" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition" title="Buka Modul">
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </a>
        </div>
    </div>
<?php endif; ?>

<!-- ============================================================== -->
<!-- WIDGET KHUSUS ORANG TUA: RADAR 360° MONITORING ANAK -->
<!-- ============================================================== -->
<?php if ($user_role === 'orang_tua'): ?>
    <div class="mb-8 space-y-4">
        
        <!-- Header & Multi-Child Switcher -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-gradient-to-r from-purple-950/40 via-slate-900 to-indigo-950/30 p-4 sm:p-5 rounded-3xl border border-purple-500/30 shadow-xl backdrop-blur">
            <div class="flex items-center gap-3.5">
                <div class="h-12 w-12 rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-600 flex items-center justify-center text-white text-xl shadow-lg shadow-purple-600/30 shrink-0">
                    <i class="fa-solid fa-child-reaching"></i>
                </div>
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30">
                            Radar 360° Perkembangan Anak
                        </span>
                        <?php if ($child_alpa_today): ?>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-300 border border-rose-500/30 animate-pulse">
                                ⚠️ Alpa Hari Ini
                            </span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-base sm:text-lg font-bold text-white mt-1">
                        <?= $linked_child ? htmlspecialchars($linked_child['name']) : 'Belum Ada Siswa Terhubung' ?>
                    </h3>
                    <p class="text-xs text-slate-300">
                        <?php if ($linked_child): ?>
                            NISN: <span class="font-mono text-purple-300"><?= htmlspecialchars($linked_child['nisn'] ?: '-') ?></span> • 
                            Rombel: <span class="text-white font-medium"><?= htmlspecialchars($linked_child['class_name'] ?: 'Umum') ?></span>
                            <?php if (!empty($linked_child['homeroom_name'])): ?>
                                • Wali Kelas: <span class="text-slate-200"><?= htmlspecialchars($linked_child['homeroom_name']) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            Silakan hubungi staf Tata Usaha untuk menautkan akun dengan data siswa.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Multi-Child Pills / Action -->
            <div class="flex items-center gap-2 flex-wrap self-start sm:self-auto">
                <?php if (count($parent_children) > 1): ?>
                    <div class="flex items-center gap-1.5 p-1 rounded-2xl bg-slate-950/60 border border-white/10 text-xs">
                        <span class="text-[11px] text-slate-400 pl-2 font-medium">Pilih Anak:</span>
                        <?php foreach ($parent_children as $ch): ?>
                            <a href="index.php?child_id=<?= $ch['id'] ?>" 
                               class="px-3 py-1 rounded-xl text-xs font-semibold transition <?= ((int)$ch['id'] === $att_student_id) ? 'bg-purple-600 text-white shadow-md' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                                <?= htmlspecialchars(explode(' ', $ch['name'])[0]) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($linked_child && !empty($linked_child['homeroom_phone'])): ?>
                    <?php 
                    $clean_wa = preg_replace('/[^0-9]/', '', $linked_child['homeroom_phone']);
                    if (str_starts_with($clean_wa, '0')) { $clean_wa = '62' . substr($clean_wa, 1); }
                    ?>
                    <a href="https://wa.me/<?= $clean_wa ?>?text=Halo%20Bapak%2FIbu%20Wali%20Kelas%2C%20saya%20orang%20tua%20dari%20<?= urlencode($linked_child['name']) ?>" 
                       target="_blank" 
                       class="px-3.5 py-2 rounded-xl bg-emerald-600/90 hover:bg-emerald-500 text-white text-xs font-semibold shadow-md shadow-emerald-600/20 transition flex items-center gap-1.5"
                       title="Hubungi Wali Kelas via WhatsApp">
                        <i class="fa-brands fa-whatsapp text-sm"></i>
                        <span>Wali Kelas</span>
                    </a>
                <?php endif; ?>

                <?php if ($linked_child): ?>
                    <a href="akademik/report_card.php?student_id=<?= $linked_child['id'] ?>" 
                       class="px-3.5 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-semibold shadow-md shadow-purple-600/20 transition flex items-center gap-1.5">
                        <i class="fa-solid fa-chart-line text-xs"></i>
                        <span>Buka Rapor</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($linked_child): ?>
            <!-- 4 Grid Radar Metrik -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                
                <!-- 1. Presensi Hari Ini & Rekap Bulanan -->
                <div class="p-4 rounded-2xl border border-white/10 bg-slate-900/60 backdrop-blur flex flex-col justify-between shadow-lg">
                    <div>
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Presensi Hari Ini</span>
                            <span class="flex h-8 w-8 items-center justify-center rounded-xl <?= $child_today_att ? ($child_today_att['status'] === 'hadir' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-400') : 'bg-slate-800 text-slate-400' ?>">
                                <i class="fa-solid fa-clipboard-user text-sm"></i>
                            </span>
                        </div>
                        <p class="text-sm font-bold text-white mt-2">
                            <?php if ($child_today_att): ?>
                                <span class="capitalize <?= $child_today_att['status'] === 'hadir' ? 'text-emerald-400' : ($child_today_att['status'] === 'alpa' ? 'text-rose-400' : 'text-amber-400') ?>">
                                    <?= htmlspecialchars($child_today_att['status']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-slate-400">Belum Tercatat</span>
                            <?php endif; ?>
                        </p>
                        <div class="mt-3">
                            <div class="flex justify-between text-[11px] mb-1">
                                <span class="text-slate-400">Kehadiran Bulan Ini</span>
                                <strong class="text-emerald-400 font-mono"><?= $child_att_stats['rate'] ?>%</strong>
                            </div>
                            <div class="h-1.5 w-full bg-slate-800 rounded-full overflow-hidden">
                                <div class="h-full bg-emerald-500 rounded-full" style="width: <?= min(100, $child_att_stats['rate']) ?>%"></div>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-1">
                                H: <?= $child_att_stats['hadir'] ?> &bull; S: <?= $child_att_stats['sakit'] ?> &bull; I: <?= $child_att_stats['izin'] ?> &bull; A: <span class="<?= $child_att_stats['alpa'] > 0 ? 'text-rose-400 font-bold' : '' ?>"><?= $child_att_stats['alpa'] ?></span>
                            </p>
                        </div>
                    </div>
                    <div class="pt-3 border-t border-white/5 mt-3">
                        <a href="surat/requests.php" class="text-xs font-semibold text-emerald-400 hover:text-emerald-300 flex items-center gap-1">
                            <span>Ajukan Izin / Sakit Online</span>
                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </a>
                    </div>
                </div>

                <!-- 2. Tugas & Deadline Mendatang -->
                <div class="p-4 rounded-2xl border border-white/10 bg-slate-900/60 backdrop-blur flex flex-col justify-between shadow-lg">
                    <div>
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Pekerjaan Rumah (PR)</span>
                            <span class="flex h-8 w-8 items-center justify-center rounded-xl <?= !empty($child_due_assignments) ? 'bg-amber-500/20 text-amber-400' : 'bg-blue-500/20 text-blue-400' ?>">
                                <i class="fa-solid fa-list-check text-sm"></i>
                            </span>
                        </div>
                        <p class="text-sm font-bold text-white mt-2">
                            <?= count($child_due_assignments) > 0 ? count($child_due_assignments) . ' Tugas Mendekati Batas' : 'Semua Tugas Selesai' ?>
                        </p>
                        <div class="mt-2 space-y-1.5">
                            <?php if (!empty($child_due_assignments)): ?>
                                <?php foreach (array_slice($child_due_assignments, 0, 2) as $da): ?>
                                    <div class="p-1.5 rounded-lg bg-white/5 text-[11px] flex justify-between items-center">
                                        <span class="truncate text-slate-300 max-w-[130px]"><?= htmlspecialchars($da['title']) ?></span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded font-mono font-bold bg-amber-500/20 text-amber-300">
                                            <?= $da['days_left'] == 0 ? 'Hari ini' : ($da['days_left'] . ' hr lagi') ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-xs text-slate-400 leading-relaxed">
                                    Tidak ada tugas mendekati batas waktu dalam 2 hari ke depan.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="pt-3 border-t border-white/5 mt-3">
                        <a href="akademik/assignments.php" class="text-xs font-semibold text-blue-400 hover:text-blue-300 flex items-center gap-1">
                            <span>Pantau Penugasan</span>
                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </a>
                    </div>
                </div>

                <!-- 3. Asesmen CBT & Nilai Ujian -->
                <div class="p-4 rounded-2xl border border-white/10 bg-slate-900/60 backdrop-blur flex flex-col justify-between shadow-lg">
                    <div>
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Asesmen & CBT</span>
                            <span class="flex h-8 w-8 items-center justify-center rounded-xl <?= !empty($child_remedial_exams) ? 'bg-rose-500/20 text-rose-400' : 'bg-purple-500/20 text-purple-400' ?>">
                                <i class="fa-solid fa-file-pen text-sm"></i>
                            </span>
                        </div>
                        <p class="text-sm font-bold text-white mt-2">
                            <?= !empty($child_remedial_exams) ? count($child_remedial_exams) . ' Ujian Butuh Remedial' : 'Pencapaian Ujian Baik' ?>
                        </p>
                        <div class="mt-2 space-y-1.5">
                            <?php if (!empty($child_remedial_exams)): ?>
                                <?php foreach ($child_remedial_exams as $re): ?>
                                    <div class="p-1.5 rounded-lg bg-rose-500/10 border border-rose-500/20 text-[11px] flex justify-between items-center">
                                        <span class="truncate text-slate-200 max-w-[120px]"><?= htmlspecialchars($re['subject']) ?></span>
                                        <span class="font-mono text-rose-300 font-bold text-[10px]">
                                            Skor: <?= number_format((float)$re['score'], 0) ?> (KKM <?= $re['passing_grade'] ?>)
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-xs text-slate-400 leading-relaxed">
                                    Hasil ujian siswa telah memenuhi Kriteria Ketercapaian Tujuan Pembelajaran (KKTP).
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="pt-3 border-t border-white/5 mt-3">
                        <a href="Modul-ujian/exams.php" class="text-xs font-semibold text-purple-400 hover:text-purple-300 flex items-center gap-1">
                            <span>Lihat Jadwal Ujian</span>
                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </a>
                    </div>
                </div>

                <!-- 4. Keuangan & Tagihan SPP -->
                <div class="p-4 rounded-2xl border border-white/10 bg-slate-900/60 backdrop-blur flex flex-col justify-between shadow-lg">
                    <div>
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Tagihan Keuangan</span>
                            <span class="flex h-8 w-8 items-center justify-center rounded-xl <?= $child_unpaid_count > 0 ? 'bg-amber-500/20 text-amber-400' : 'bg-emerald-500/20 text-emerald-400' ?>">
                                <i class="fa-solid fa-wallet text-sm"></i>
                            </span>
                        </div>
                        <p class="text-sm font-bold mt-2 font-mono <?= $child_unpaid_count > 0 ? 'text-amber-400' : 'text-emerald-400' ?>">
                            <?= $child_unpaid_count > 0 ? formatRupiah($child_total_unpaid) : 'Semua Lunas ✓' ?>
                        </p>
                        <p class="text-xs text-slate-400 mt-1">
                            <?= $child_unpaid_count > 0 ? $child_unpaid_count . ' tagihan menunggu pembayaran' : 'Tidak ada tagihan tertunggak' ?>
                        </p>
                        <div class="mt-3 flex items-center gap-2 text-[11px] text-slate-300">
                            <span><i class="fa-solid fa-book-bookmark text-teal-400 mr-1"></i> Perpus: <strong><?= $child_active_loans_count ?> Pinjam</strong></span>
                            <span>&bull;</span>
                            <span><i class="fa-solid fa-shield-halved text-blue-400 mr-1"></i> BK: <strong class="<?= $child_bk_points > 0 ? 'text-amber-400' : 'text-emerald-400' ?>"><?= $child_bk_points ?> Poin</strong></span>
                        </div>
                    </div>
                    <div class="pt-3 border-t border-white/5 mt-3">
                        <a href="keuangan/payments.php" class="text-xs font-semibold text-amber-400 hover:text-amber-300 flex items-center gap-1">
                            <span>Bayar Online / QRIS</span>
                            <i class="fa-solid fa-arrow-right text-[10px]"></i>
                        </a>
                    </div>
                </div>

            </div>
        <?php endif; ?>

    </div>
<?php endif; ?>

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

<!-- ============================================================== -->
<!-- WIDGET: KABAR & BERITA TERKINI SEKOLAH -->
<!-- ============================================================== -->
<?php if (!empty($dash_latest_news)): ?>
<div class="mb-8">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-sky-500/10 text-sky-400 text-xs">
                <i class="fa-solid fa-newspaper"></i>
            </span>
            <h3 class="text-sm font-bold text-white uppercase tracking-wider">Kabar & Berita Sekolah Terkini</h3>
        </div>
        <div class="flex items-center gap-3">
            <?php if (in_array($user_role, ['administrator', 'staf', 'guru'], true)): ?>
                <a href="informasi/news.php" class="text-xs font-semibold text-blue-400 hover:text-blue-300 transition flex items-center gap-1">
                    <span>Kelola Berita</span>
                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </a>
            <?php endif; ?>
            <a href="../berita.php" class="text-xs font-semibold text-slate-400 hover:text-white transition">
                Portal Publik →
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <?php foreach ($dash_latest_news as $ln): ?>
            <?php 
                $ln_cat = NEWS_CATEGORIES[$ln['category']] ?? ['label' => ucfirst($ln['category']), 'badge' => 'border-slate-500/30 bg-slate-500/10 text-slate-300'];
                $ln_thumb = !empty($ln['thumbnail']) ? resolveAssetUrl($ln['thumbnail'], '../') : null;
            ?>
            <a href="../berita.php?id=<?= $ln['id'] ?>" class="group rounded-2xl border border-white/10 bg-slate-900/70 overflow-hidden hover:border-sky-500/40 hover:bg-slate-900/95 transition duration-300 flex flex-col justify-between shadow-lg">
                <div>
                    <div class="h-28 w-full bg-slate-950 relative overflow-hidden">
                        <?php if ($ln_thumb): ?>
                            <img src="<?= $ln_thumb ?>" alt="<?= htmlspecialchars($ln['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                        <?php else: ?>
                            <div class="w-full h-full bg-gradient-to-tr from-slate-950 to-indigo-950/60 flex items-center justify-center text-slate-700">
                                <i class="fa-solid fa-newspaper text-3xl"></i>
                            </div>
                        <?php endif; ?>
                        <div class="absolute top-2 left-2">
                            <span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider backdrop-blur-md border <?= $ln_cat['badge'] ?>">
                                <?= htmlspecialchars($ln_cat['label']) ?>
                            </span>
                        </div>
                    </div>

                    <div class="p-3.5 space-y-1.5">
                        <h4 class="text-xs sm:text-sm font-bold text-white line-clamp-2 group-hover:text-sky-400 transition leading-snug">
                            <?= htmlspecialchars($ln['title']) ?>
                        </h4>
                        <p class="text-[10px] text-slate-400">
                            Oleh: <strong class="text-slate-300"><?= htmlspecialchars($ln['author_name']) ?></strong> (<?= htmlspecialchars(getRoleLabel($ln['author_role'])) ?>)
                        </p>
                    </div>
                </div>

                <div class="p-3.5 pt-0 border-t border-white/5 flex items-center justify-between text-[10px] text-slate-400 mt-2">
                    <span><?= date('d M Y', strtotime($ln['created_at'])) ?></span>
                    <span class="text-sky-400 font-semibold group-hover:underline flex items-center gap-1">
                        Baca <i class="fa-solid fa-chevron-right text-[8px]"></i>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

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
        <!-- 1. Total Pengguna -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 sm:p-5 shadow-lg backdrop-blur hover:border-blue-500/40 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-400 font-medium">Total Akun</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/15 text-blue-400 group-hover:scale-110 transition">
                    <i class="fa-solid fa-users text-sm"></i>
                </span>
            </div>
            <p class="text-2xl sm:text-3xl font-extrabold text-white mt-3"><?= number_format($total_users) ?></p>
            <div class="mt-2 pt-2 border-t border-white/5">
                <a href="admin/users.php" class="text-[11px] text-blue-400 hover:text-blue-300 font-semibold flex items-center justify-between">
                    <span>Kelola Pengguna</span>
                    <i class="fa-solid fa-arrow-right text-[9px]"></i>
                </a>
            </div>
        </div>

        <!-- 2. Surat Diproses -->
        <div class="rounded-2xl border <?= $total_requests_pending > 0 ? 'border-amber-500/30 bg-amber-500/5' : 'border-white/10 bg-slate-900/60' ?> p-4 sm:p-5 shadow-lg backdrop-blur hover:border-amber-500/50 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-400 font-medium">Surat Masuk</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400 group-hover:scale-110 transition">
                    <i class="fa-solid fa-file-lines text-sm"></i>
                </span>
            </div>
            <div class="flex items-baseline gap-2 mt-3">
                <p class="text-2xl sm:text-3xl font-extrabold text-amber-300"><?= $total_requests_pending ?></p>
                <?php if ($total_requests_pending > 0): ?>
                    <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-500/20 text-amber-300 font-bold">Antrean</span>
                <?php endif; ?>
            </div>
            <div class="mt-2 pt-2 border-t border-white/5">
                <a href="surat/requests.php" class="text-[11px] text-amber-400 hover:text-amber-300 font-semibold flex items-center justify-between">
                    <span>Lihat Permohonan</span>
                    <i class="fa-solid fa-arrow-right text-[9px]"></i>
                </a>
            </div>
        </div>

        <!-- 3. Tugas Belajar -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 sm:p-5 shadow-lg backdrop-blur hover:border-emerald-500/40 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-400 font-medium">Tugas Aktif</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-400 group-hover:scale-110 transition">
                    <i class="fa-solid fa-book-open text-sm"></i>
                </span>
            </div>
            <p class="text-2xl sm:text-3xl font-extrabold text-emerald-300 mt-3"><?= number_format($total_assignments) ?></p>
            <div class="mt-2 pt-2 border-t border-white/5">
                <a href="akademik/assignments.php" class="text-[11px] text-emerald-400 hover:text-emerald-300 font-semibold flex items-center justify-between">
                    <span>Daftar Tugas</span>
                    <i class="fa-solid fa-arrow-right text-[9px]"></i>
                </a>
            </div>
        </div>

        <!-- 4. Ujian & Asesmen CBT -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 sm:p-5 shadow-lg backdrop-blur hover:border-purple-500/40 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between group">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-400 font-medium">Ujian CBT</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-purple-500/15 text-purple-400 group-hover:scale-110 transition">
                    <i class="fa-solid fa-file-pen text-sm"></i>
                </span>
            </div>
            <p class="text-2xl sm:text-3xl font-extrabold text-purple-300 mt-3"><?= number_format($total_exams) ?></p>
            <div class="mt-2 pt-2 border-t border-white/5">
                <a href="Modul-ujian/exams.php" class="text-[11px] text-purple-400 hover:text-purple-300 font-semibold flex items-center justify-between">
                    <span>Kelola Asesmen</span>
                    <i class="fa-solid fa-arrow-right text-[9px]"></i>
                </a>
            </div>
        </div>

        <!-- 5. Pengumuman Sekolah -->
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 sm:p-5 shadow-lg backdrop-blur hover:border-cyan-500/40 hover:-translate-y-0.5 transition duration-200 flex flex-col justify-between col-span-2 sm:col-span-1 group">
            <div class="flex items-center justify-between">
                <span class="text-xs text-slate-400 font-medium">Pengumuman</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-cyan-500/15 text-cyan-400 group-hover:scale-110 transition">
                    <i class="fa-solid fa-bullhorn text-sm"></i>
                </span>
            </div>
            <p class="text-2xl sm:text-3xl font-extrabold text-cyan-300 mt-3"><?= count($latest_announcements) ?></p>
            <div class="mt-2 pt-2 border-t border-white/5">
                <a href="informasi/announcements.php" class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold flex items-center justify-between">
                    <span>Buka Informasi</span>
                    <i class="fa-solid fa-arrow-right text-[9px]"></i>
                </a>
            </div>
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
                            <!-- -08 fix: Form POST dengan CSRF untuk quick update status surat -->
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
        <!-- Ruang Kerja KBM & Pembelajaran Guru (Sistem Tab Cerdas) -->
        <div class="lg:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-6 shadow-xl backdrop-blur">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6 pb-4 border-b border-white/5">
                <div>
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-layer-group text-blue-400"></i> Ruang Kerja KBM Guru
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Kelola penugasan, paket ujian CBT, dan jadwal mengajar hari ini.</p>
                </div>
                
                <!-- Tab Navigation Pills -->
                <div class="inline-flex p-1 rounded-2xl bg-slate-950/80 border border-white/10 text-xs font-semibold self-start sm:self-auto overflow-x-auto max-w-full">
                    <button type="button" onclick="switchDashboardTab('guru_kbm', 'tab_guru_tugas', this)" 
                            class="tab-btn-guru_kbm px-3.5 py-1.5 rounded-xl transition flex items-center gap-2 bg-blue-600 text-white shadow-md shadow-blue-500/20 cursor-pointer" 
                            data-tab-group="guru_kbm" data-tab-target="tab_guru_tugas">
                        <i class="fa-solid fa-book-open"></i>
                        <span>Tugas</span>
                        <span class="tab-badge px-1.5 py-0.5 rounded-full text-[10px] bg-white/20 text-white"><?= count($my_assignments) ?></span>
                    </button>
                    <button type="button" onclick="switchDashboardTab('guru_kbm', 'tab_guru_ujian', this)" 
                            class="tab-btn-guru_kbm px-3.5 py-1.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white hover:bg-white/5 cursor-pointer" 
                            data-tab-group="guru_kbm" data-tab-target="tab_guru_ujian">
                        <i class="fa-solid fa-file-pen"></i>
                        <span>Ujian CBT</span>
                        <span class="tab-badge px-1.5 py-0.5 rounded-full text-[10px] bg-white/10 text-slate-300"><?= count($my_exams) ?></span>
                    </button>
                    <button type="button" onclick="switchDashboardTab('guru_kbm', 'tab_guru_jadwal', this)" 
                            class="tab-btn-guru_kbm px-3.5 py-1.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white hover:bg-white/5 cursor-pointer" 
                            data-tab-group="guru_kbm" data-tab-target="tab_guru_jadwal">
                        <i class="fa-solid fa-calendar-day"></i>
                        <span>Jadwal Hari Ini</span>
                        <span class="tab-badge px-1.5 py-0.5 rounded-full text-[10px] bg-white/10 text-slate-300"><?= count($dash_today_schedules) ?></span>
                    </button>
                </div>
            </div>

            <!-- TAB 1: TUGAS PEMBELAJARAN GURU -->
            <div id="tab_guru_tugas" class="tab-panel-guru_kbm space-y-4">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>Daftar tugas yang sedang aktif dan dikerjakan siswa:</span>
                    <a href="akademik/assignments.php" class="font-semibold text-blue-400 hover:text-blue-300 flex items-center gap-1">
                        <span>Kelola Tugas</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <?php if (empty($my_assignments)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs rounded-2xl bg-slate-900/40 border border-white/5 flex flex-col items-center justify-center gap-2">
                        <i class="fa-solid fa-clipboard-check text-2xl text-slate-600 mb-1"></i>
                        <span>Anda belum membuat tugas pelajaran aktif.</span>
                        <a href="akademik/assignments.php" class="mt-2 px-3 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold transition text-xs">
                            <i class="fa-solid fa-plus mr-1"></i> Buat Tugas Baru
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($my_assignments as $asg): ?>
                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 hover:border-white/10 transition flex items-center justify-between gap-4">
                                <div>
                                    <span class="rounded px-2 py-0.5 text-[10px] font-semibold border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 mb-1 inline-block">
                                        <?= htmlspecialchars($asg['subject']) ?>
                                    </span>
                                    <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($asg['title']) ?></h4>
                                    <p class="text-xs text-slate-400 mt-0.5">Batas Pengumpulan: <strong class="text-amber-300"><?= date('d M Y', strtotime($asg['due_date'])) ?></strong></p>
                                </div>
                                <a href="akademik/assignments.php" class="px-3 py-1.5 rounded-xl bg-white/5 hover:bg-white/10 text-xs font-semibold text-blue-400 hover:text-blue-300 transition shrink-0">
                                    Detail & Nilai →
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 2: PAKET UJIAN CBT GURU -->
            <div id="tab_guru_ujian" class="tab-panel-guru_kbm hidden space-y-4">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>Asesmen UTS, UKK, Ujian Harian, & Latihan Siswa:</span>
                    <a href="Modul-ujian/exams.php" class="font-semibold text-blue-400 hover:text-blue-300 flex items-center gap-1">
                        <span>Buka Semua (<?= count($my_exams) ?>)</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <?php if (empty($my_exams)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs rounded-2xl bg-slate-900/40 border border-white/5 flex flex-col items-center justify-center gap-2">
                        <i class="fa-solid fa-file-circle-plus text-2xl text-slate-600 mb-1"></i>
                        <span>Anda belum membuat paket ujian atau latihan CBT.</span>
                        <a href="Modul-ujian/exams.php" class="mt-2 px-3 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold transition text-xs">
                            <i class="fa-solid fa-plus mr-1"></i> Buat Ujian / Latihan
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($my_exams as $ex): 
                            $c_info = EXAM_CATEGORIES[$ex['category']] ?? ['label' => $ex['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '<i class="fa-solid fa-file-pen"></i>'];
                        ?>
                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 hover:border-white/10 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4">
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
                                <div class="flex items-center gap-2 shrink-0">
                                    <a href="Modul-ujian/exam_questions.php?id=<?= $ex['id'] ?>" class="rounded-xl bg-white/10 hover:bg-white/20 px-3 py-1.5 text-xs font-semibold text-white transition">
                                        <i class="fa-solid fa-list-ol mr-1"></i> Soal (<?= $ex['q_count'] ?>)
                                    </a>
                                    <a href="Modul-ujian/exam_results.php?id=<?= $ex['id'] ?>" class="rounded-xl bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/30 px-3 py-1.5 text-xs font-semibold text-blue-300 transition">
                                        <i class="fa-solid fa-chart-simple mr-1"></i> Nilai (<?= $ex['sub_count'] ?>)
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 3: JADWAL MENGAJAR HARI INI GURU -->
            <div id="tab_guru_jadwal" class="tab-panel-guru_kbm hidden space-y-4">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>Jadwal mengajar kelas pada hari <strong><?= $today_id_name ?></strong>:</span>
                    <a href="akademik/timetable.php" class="font-semibold text-blue-400 hover:text-blue-300 flex items-center gap-1">
                        <span>Lihat Jadwal Lengkap</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <?php if (empty($dash_today_schedules)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs rounded-2xl bg-slate-900/40 border border-white/5 flex flex-col items-center justify-center gap-2">
                        <i class="fa-solid fa-mug-hot text-2xl text-slate-600 mb-1"></i>
                        <span>Tidak ada jadwal mengajar pada hari <strong><?= $today_id_name ?></strong>.</span>
                        <p class="text-[11px] text-slate-500">Anda dapat memanfaatkan waktu untuk menyusun RPP atau memeriksa tugas siswa.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($dash_today_schedules as $sch): ?>
                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 hover:border-white/10 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                <div class="flex items-center gap-3.5">
                                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-500/15 text-blue-400 shrink-0 text-base">
                                        <i class="fa-solid fa-chalkboard-user"></i>
                                    </span>
                                    <div>
                                        <div class="flex items-center gap-2 mb-0.5">
                                            <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-blue-500/20 text-blue-300 border border-blue-500/30">
                                                <?= htmlspecialchars($sch['class_name'] ?? 'Kelas') ?>
                                            </span>
                                            <h4 class="text-sm font-bold text-white"><?= htmlspecialchars($sch['subject'] ?? 'Pelajaran') ?></h4>
                                        </div>
                                        <p class="text-xs text-slate-400">
                                            <i class="fa-solid fa-clock text-slate-500 mr-1"></i>
                                            <?= substr($sch['start_time'], 0, 5) ?> - <?= substr($sch['end_time'], 0, 5) ?> WIB
                                            <?php if (!empty($sch['room'])): ?>
                                                • <i class="fa-solid fa-location-dot text-slate-500 mr-1"></i> Ruang: <?= htmlspecialchars($sch['room']) ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="shrink-0 flex items-center gap-2">
                                    <a href="akademik/journal.php?class_id=<?= $sch['class_id'] ?>" class="px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition flex items-center gap-1.5 shadow-md shadow-emerald-600/20">
                                        <i class="fa-solid fa-pen-to-square"></i> Isi Jurnal
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

        <!-- ========================================================= -->
        <!-- WIDGET INTEGRASI FASE 3: STUDENT 360° EXECUTIVE RADAR -->
        <!-- ========================================================= -->
        <div class="mb-6 rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 backdrop-blur-xl shadow-xl">
            <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-4">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-500/20 text-indigo-400 text-sm font-bold border border-indigo-500/30">
                        <i class="fa-solid fa-compass"></i>
                    </span>
                    <div>
                        <h3 class="text-sm sm:text-base font-bold text-white flex items-center gap-2">
                            Student 360° Executive Radar
                            <span class="rounded-full bg-indigo-500/15 border border-indigo-500/30 px-2 py-0.5 text-[10px] text-indigo-300 font-bold uppercase tracking-wider">Pemantauan Terpadu</span>
                        </h3>
                        <p class="text-xs text-slate-400">Ringkasan indikator kunci aktivitas ananda hari ini dalam satu tampilan.</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- 1. Presensi Hari Ini -->
                <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-4 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between text-xs text-slate-400 mb-1.5">
                            <span class="font-semibold flex items-center gap-1.5"><i class="fa-solid fa-clock text-blue-400"></i> Presensi Hari Ini</span>
                            <span class="font-mono text-[10px]"><?= date('d M') ?></span>
                        </div>
                        <?php if ($child_today_att): 
                            $c_st = $child_today_att['status'];
                            $badge_color = ($c_st === 'hadir') ? 'text-emerald-400' : (($c_st === 'alpa') ? 'text-rose-400' : 'text-amber-400');
                        ?>
                            <p class="text-base font-extrabold <?= $badge_color ?> uppercase mt-1">
                                <?= $c_st === 'hadir' ? 'Hadir di Sekolah' : strtoupper($c_st) ?>
                            </p>
                            <p class="text-[11px] text-slate-400 mt-0.5 italic"><?= htmlspecialchars($child_today_att['notes'] ?: 'Tercatat pada sistem presensi') ?></p>
                        <?php else: ?>
                            <p class="text-base font-extrabold text-slate-400 mt-1">Belum Terdata</p>
                            <p class="text-[11px] text-slate-500 mt-0.5">Menunggu input wali kelas / scan QR</p>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 pt-2 border-t border-white/5">
                        <a href="presensi/attendance.php" class="text-[11px] text-blue-400 hover:text-blue-300 font-semibold flex items-center justify-between">
                            <span>Rekap: <?= $my_attendance_stats['rate'] ?>% Hadir</span>
                            <i class="fa-solid fa-arrow-right text-[9px]"></i>
                        </a>
                    </div>
                </div>

                <!-- 2. Tagihan SPP & Keuangan -->
                <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-4 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between text-xs text-slate-400 mb-1.5">
                            <span class="font-semibold flex items-center gap-1.5"><i class="fa-solid fa-wallet text-emerald-400"></i> Keuangan (SPP)</span>
                            <i class="fa-solid <?= $child_total_unpaid == 0 ? 'fa-circle-check text-emerald-400' : 'fa-circle-exclamation text-amber-400' ?>"></i>
                        </div>
                        <?php if ($child_total_unpaid == 0): ?>
                            <p class="text-base font-extrabold text-emerald-400 mt-1">Lunas (Nihil)</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Tidak ada tunggakan iuran</p>
                        <?php else: ?>
                            <p class="text-base font-extrabold text-rose-400 mt-1 font-mono">Rp <?= number_format($child_total_unpaid, 0, ',', '.') ?></p>
                            <p class="text-[11px] text-amber-300/80 mt-0.5"><?= $child_unpaid_count ?> tagihan belum lunas</p>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 pt-2 border-t border-white/5">
                        <a href="keuangan/payments.php" class="text-[11px] text-emerald-400 hover:text-emerald-300 font-semibold flex items-center justify-between">
                            <span><?= $child_total_unpaid > 0 ? 'Bayar Online Sekarang' : 'Riwayat Pembayaran' ?></span>
                            <i class="fa-solid fa-arrow-right text-[9px]"></i>
                        </a>
                    </div>
                </div>

                <!-- 3. Rekam Karakter & BK -->
                <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-4 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between text-xs text-slate-400 mb-1.5">
                            <span class="font-semibold flex items-center gap-1.5"><i class="fa-solid fa-handshake-angle text-purple-400"></i> Kedisiplinan BK</span>
                            <span class="font-mono text-[10px]"><?= $child_bk_points ?> Poin</span>
                        </div>
                        <?php if ($child_bk_points == 0): ?>
                            <p class="text-base font-extrabold text-purple-300 mt-1">Sangat Baik</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">0 Catatan Pelanggaran</p>
                        <?php else: ?>
                            <p class="text-base font-extrabold text-amber-400 mt-1"><?= $child_bk_points ?> Poin Sanksi</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Perlu perhatian pembinaan</p>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 pt-2 border-t border-white/5">
                        <a href="bk/counseling.php" class="text-[11px] text-purple-400 hover:text-purple-300 font-semibold flex items-center justify-between">
                            <span>Lihat Catatan BK</span>
                            <i class="fa-solid fa-arrow-right text-[9px]"></i>
                        </a>
                    </div>
                </div>

                <!-- 4. Peminjaman Perpustakaan -->
                <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-4 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between text-xs text-slate-400 mb-1.5">
                            <span class="font-semibold flex items-center gap-1.5"><i class="fa-solid fa-book text-cyan-400"></i> Perpustakaan</span>
                            <span class="font-mono text-[10px]"><?= $child_active_loans_count ?> Buku</span>
                        </div>
                        <?php if ($child_active_loans_count == 0): ?>
                            <p class="text-base font-extrabold text-cyan-300 mt-1">Bebas Pustaka</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Tidak ada buku dipinjam</p>
                        <?php else: ?>
                            <p class="text-base font-extrabold text-cyan-300 mt-1"><?= $child_active_loans_count ?> Buku Dipinjam</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Periksa tanggal jatuh tempo</p>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 pt-2 border-t border-white/5">
                        <a href="perpustakaan/loans.php" class="text-[11px] text-cyan-400 hover:text-cyan-300 font-semibold flex items-center justify-between">
                            <span>Katalog & Sirkulasi</span>
                            <i class="fa-solid fa-arrow-right text-[9px]"></i>
                        </a>
                    </div>
                </div>
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
        <!-- Ruang Pemantauan Belajar Anak (Sistem Tab Cerdas) -->
        <div class="lg:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-6 shadow-xl backdrop-blur">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6 pb-4 border-b border-white/5">
                <div>
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-shield-cat text-purple-400"></i> Pemantauan Pembelajaran Ananda
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Pantau tugas, rekap nilai ujian, dan jadwal pelajaran harian anak.</p>
                </div>
                
                <!-- Tab Navigation Pills -->
                <div class="inline-flex p-1 rounded-2xl bg-slate-950/80 border border-white/10 text-xs font-semibold self-start sm:self-auto overflow-x-auto max-w-full">
                    <button type="button" onclick="switchDashboardTab('parent_kbm', 'tab_parent_tugas', this)" 
                            class="tab-btn-parent_kbm px-3.5 py-1.5 rounded-xl transition flex items-center gap-2 bg-blue-600 text-white shadow-md shadow-blue-500/20 cursor-pointer" 
                            data-tab-group="parent_kbm" data-tab-target="tab_parent_tugas">
                        <i class="fa-solid fa-book-open"></i>
                        <span>Tugas Anak</span>
                        <span class="tab-badge px-1.5 py-0.5 rounded-full text-[10px] bg-white/20 text-white"><?= count($active_assignments) ?></span>
                    </button>
                    <button type="button" onclick="switchDashboardTab('parent_kbm', 'tab_parent_ujian', this)" 
                            class="tab-btn-parent_kbm px-3.5 py-1.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white hover:bg-white/5 cursor-pointer" 
                            data-tab-group="parent_kbm" data-tab-target="tab_parent_ujian">
                        <i class="fa-solid fa-file-pen"></i>
                        <span>Ujian & Evaluasi</span>
                        <span class="tab-badge px-1.5 py-0.5 rounded-full text-[10px] bg-white/10 text-slate-300"><?= count($active_exams) ?></span>
                    </button>
                    <button type="button" onclick="switchDashboardTab('parent_kbm', 'tab_parent_jadwal', this)" 
                            class="tab-btn-parent_kbm px-3.5 py-1.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white hover:bg-white/5 cursor-pointer" 
                            data-tab-group="parent_kbm" data-tab-target="tab_parent_jadwal">
                        <i class="fa-solid fa-calendar-day"></i>
                        <span>Jadwal Pelajaran</span>
                        <span class="tab-badge px-1.5 py-0.5 rounded-full text-[10px] bg-white/10 text-slate-300"><?= count($dash_today_schedules) ?></span>
                    </button>
                </div>
            </div>

            <!-- TAB 1: PANTAUAN TUGAS ANAK -->
            <div id="tab_parent_tugas" class="tab-panel-parent_kbm space-y-4">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>Daftar tugas pelajaran aktif dari guru untuk ananda:</span>
                    <a href="akademik/assignments.php" class="font-semibold text-purple-400 hover:text-purple-300 flex items-center gap-1">
                        <span>Buka Modul Tugas</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <?php if (empty($active_assignments)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs rounded-2xl bg-slate-900/40 border border-white/5 flex flex-col items-center justify-center gap-2">
                        <i class="fa-solid fa-circle-check text-2xl text-emerald-400 mb-1"></i>
                        <span class="text-white font-semibold">Tidak ada tugas aktif untuk ananda saat ini.</span>
                        <p class="text-[11px] text-slate-400">Semua tugas telah diserahkan atau belum ada tugas baru.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($active_assignments as $asg): ?>
                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 hover:border-white/10 transition flex items-center justify-between gap-4">
                                <div>
                                    <span class="rounded px-2 py-0.5 text-[10px] font-semibold border border-purple-500/30 bg-purple-500/10 text-purple-300 mb-1 inline-block">
                                        <?= htmlspecialchars($asg['subject']) ?>
                                    </span>
                                    <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($asg['title']) ?></h4>
                                    <p class="text-xs text-slate-400 mt-0.5">Guru Pengampu: <?= htmlspecialchars($asg['teacher_name']) ?></p>
                                </div>
                                <span class="text-xs text-amber-300 font-semibold shrink-0">
                                    Deadline: <?= date('d M Y', strtotime($asg['due_date'])) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 2: PANTAUAN UJIAN & REMEDIAL ANAK -->
            <div id="tab_parent_ujian" class="tab-panel-parent_kbm hidden space-y-4">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>Jadwal UTS, UKK, Ujian Harian, dan Latihan Rutin:</span>
                    <a href="Modul-ujian/exams.php" class="font-semibold text-purple-400 hover:text-purple-300 flex items-center gap-1">
                        <span>Buka Semua (<?= count($active_exams) ?>)</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <?php if (empty($active_exams)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs rounded-2xl bg-slate-900/40 border border-white/5 flex flex-col items-center justify-center gap-2">
                        <i class="fa-solid fa-file-shield text-2xl text-slate-600 mb-1"></i>
                        <span class="text-white font-semibold">Tidak ada ujian atau latihan yang sedang berlangsung.</span>
                        <p class="text-[11px] text-slate-400">Pantau pengumuman sekolah saat periode ujian semester.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($active_exams as $ex): 
                            $c_info = EXAM_CATEGORIES[$ex['category']] ?? ['label' => $ex['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '<i class="fa-solid fa-file-pen"></i>'];
                        ?>
                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 hover:border-white/10 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4">
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
                                <a href="Modul-ujian/exams.php" class="rounded-xl bg-purple-600/20 hover:bg-purple-600/30 border border-purple-500/30 px-3.5 py-1.5 text-xs font-semibold text-purple-300 transition text-center shrink-0">
                                    Pantau Nilai →
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 3: JADWAL PELAJARAN ANAK -->
            <div id="tab_parent_jadwal" class="tab-panel-parent_kbm hidden space-y-4">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>Jadwal KBM kelas ananda pada hari <strong><?= $today_id_name ?></strong>:</span>
                    <a href="akademik/timetable.php" class="font-semibold text-purple-400 hover:text-purple-300 flex items-center gap-1">
                        <span>Jadwal Lengkap</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <?php if (empty($dash_today_schedules)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs rounded-2xl bg-slate-900/40 border border-white/5 flex flex-col items-center justify-center gap-2">
                        <i class="fa-solid fa-mug-hot text-2xl text-slate-600 mb-1"></i>
                        <span class="text-white font-semibold">Tidak ada jadwal KBM ananda pada hari <strong><?= $today_id_name ?></strong>.</span>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($dash_today_schedules as $sch): ?>
                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 hover:border-white/10 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                <div class="flex items-center gap-3.5">
                                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-purple-500/15 text-purple-400 shrink-0 text-base">
                                        <i class="fa-solid fa-book"></i>
                                    </span>
                                    <div>
                                        <h4 class="text-sm font-bold text-white"><?= htmlspecialchars($sch['subject'] ?? 'Pelajaran') ?></h4>
                                        <p class="text-xs text-slate-400 mt-0.5">
                                            <i class="fa-solid fa-clock text-slate-500 mr-1"></i>
                                            <?= substr($sch['start_time'], 0, 5) ?> - <?= substr($sch['end_time'], 0, 5) ?> WIB
                                            • <i class="fa-solid fa-chalkboard-user text-slate-500 mr-1"></i> <?= htmlspecialchars($sch['teacher_name'] ?? 'Guru Pengampu') ?>
                                            <?php if (!empty($sch['room'])): ?>
                                                • <i class="fa-solid fa-location-dot text-slate-500 mr-1"></i> R. <?= htmlspecialchars($sch['room']) ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
        <!-- Ruang Belajar & Asesmen Siswa (Sistem Tab Cerdas) -->
        <div class="lg:col-span-2 rounded-3xl border border-white/10 bg-white/5 p-6 shadow-xl backdrop-blur">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6 pb-4 border-b border-white/5">
                <div>
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-graduation-cap text-blue-400"></i> Ruang Belajar & Evaluasi Siswa
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">Pantau tugas mandiri, ikuti ujian CBT, dan cek jadwal KBM hari ini.</p>
                </div>
                
                <!-- Tab Navigation Pills -->
                <div class="inline-flex p-1 rounded-2xl bg-slate-950/80 border border-white/10 text-xs font-semibold self-start sm:self-auto overflow-x-auto max-w-full">
                    <button type="button" onclick="switchDashboardTab('siswa_kbm', 'tab_siswa_tugas', this)" 
                            class="tab-btn-siswa_kbm px-3.5 py-1.5 rounded-xl transition flex items-center gap-2 bg-blue-600 text-white shadow-md shadow-blue-500/20 cursor-pointer" 
                            data-tab-group="siswa_kbm" data-tab-target="tab_siswa_tugas">
                        <i class="fa-solid fa-book-open-reader"></i>
                        <span>Tugas Aktif</span>
                        <span class="tab-badge px-1.5 py-0.5 rounded-full text-[10px] bg-white/20 text-white"><?= count($active_assignments) ?></span>
                    </button>
                    <button type="button" onclick="switchDashboardTab('siswa_kbm', 'tab_siswa_ujian', this)" 
                            class="tab-btn-siswa_kbm px-3.5 py-1.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white hover:bg-white/5 cursor-pointer" 
                            data-tab-group="siswa_kbm" data-tab-target="tab_siswa_ujian">
                        <i class="fa-solid fa-file-pen"></i>
                        <span>Ujian & Latihan</span>
                        <span class="tab-badge px-1.5 py-0.5 rounded-full text-[10px] bg-white/10 text-slate-300"><?= count($active_exams) ?></span>
                    </button>
                    <button type="button" onclick="switchDashboardTab('siswa_kbm', 'tab_siswa_jadwal', this)" 
                            class="tab-btn-siswa_kbm px-3.5 py-1.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white hover:bg-white/5 cursor-pointer" 
                            data-tab-group="siswa_kbm" data-tab-target="tab_siswa_jadwal">
                        <i class="fa-solid fa-calendar-day"></i>
                        <span>Jadwal Hari Ini</span>
                        <span class="tab-badge px-1.5 py-0.5 rounded-full text-[10px] bg-white/10 text-slate-300"><?= count($dash_today_schedules) ?></span>
                    </button>
                </div>
            </div>

            <!-- TAB 1: TUGAS BELAJAR SISWA -->
            <div id="tab_siswa_tugas" class="tab-panel-siswa_kbm space-y-4">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>Kerjakan dan tandai tugas yang sudah Anda selesaikan:</span>
                    <a href="akademik/assignments.php" class="font-semibold text-blue-400 hover:text-blue-300 flex items-center gap-1">
                        <span>Lihat Semua Tugas</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <?php if (empty($active_assignments)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs rounded-2xl bg-slate-900/40 border border-white/5 flex flex-col items-center justify-center gap-2">
                        <i class="fa-solid fa-circle-check text-2xl text-emerald-400 mb-1"></i>
                        <span class="text-white font-semibold">Tidak ada tugas aktif saat ini.</span>
                        <p class="text-[11px] text-slate-400">Semua tugas telah diselesaikan atau belum ada penugasan baru dari guru.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($active_assignments as $asg): 
                            $is_done = (($my_submissions[$asg['id']] ?? '') === 'selesai');
                        ?>
                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 hover:border-white/10 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                <div>
                                    <span class="rounded px-2 py-0.5 text-[10px] font-semibold border border-blue-500/30 bg-blue-500/10 text-blue-300 mb-1 inline-block">
                                        <?= htmlspecialchars($asg['subject']) ?>
                                    </span>
                                    <h4 class="text-sm font-semibold text-white"><?= htmlspecialchars($asg['title']) ?></h4>
                                    <p class="text-xs text-slate-400 mt-0.5">Guru: <?= htmlspecialchars($asg['teacher_name']) ?> • Deadline: <span class="text-amber-300 font-semibold"><?= date('d M Y', strtotime($asg['due_date'])) ?></span></p>
                                </div>
                                <div class="shrink-0">
                                    <a href="akademik/assignments.php?toggle_id=<?= $asg['id'] ?>" 
                                       class="inline-flex items-center justify-center gap-1.5 py-1.5 px-3.5 rounded-xl text-xs font-semibold transition <?= $is_done ? 'bg-emerald-600/20 text-emerald-300 border border-emerald-500/30' : 'bg-blue-600 hover:bg-blue-500 text-white shadow-md shadow-blue-500/20' ?>">
                                        <i class="fa-solid fa-check text-xs"></i> <?= $is_done ? 'Selesai' : 'Tandai Selesai' ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 2: UJIAN CBT & LATIHAN SISWA -->
            <div id="tab_siswa_ujian" class="tab-panel-siswa_kbm hidden space-y-4">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>Penilaian UTS, UKK, Ujian Harian, serta Latihan Rutin:</span>
                    <a href="Modul-ujian/exams.php" class="font-semibold text-blue-400 hover:text-blue-300 flex items-center gap-1">
                        <span>Buka Semua (<?= count($active_exams) ?>)</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <?php if (empty($active_exams)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs rounded-2xl bg-slate-900/40 border border-white/5 flex flex-col items-center justify-center gap-2">
                        <i class="fa-solid fa-mug-hot text-2xl text-slate-600 mb-1"></i>
                        <span class="text-white font-semibold">Tidak ada ujian atau latihan yang sedang aktif.</span>
                        <p class="text-[11px] text-slate-400">Pantau pengumuman guru untuk jadwal asesmen mendatang.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($active_exams as $ex): 
                            $c_info = EXAM_CATEGORIES[$ex['category']] ?? ['label' => $ex['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '<i class="fa-solid fa-file-pen"></i>'];
                            $sub_info = $my_exam_subs[$ex['id']] ?? null;
                            $already_done = ($sub_info !== null);
                            $my_score = $already_done ? (float) $sub_info['score'] : 0;
                            $remedial_ready = $already_done && !empty($sub_info['remedial_granted']);
                        ?>
                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 hover:border-white/10 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4">
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
                                <div class="shrink-0">
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
                <?php endif; ?>
            </div>

            <!-- TAB 3: JADWAL PELAJARAN HARI INI SISWA -->
            <div id="tab_siswa_jadwal" class="tab-panel-siswa_kbm hidden space-y-4">
                <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>Jadwal KBM kelas Anda pada hari <strong><?= $today_id_name ?></strong>:</span>
                    <a href="akademik/timetable.php" class="font-semibold text-blue-400 hover:text-blue-300 flex items-center gap-1">
                        <span>Lihat Kalender Lengkap</span>
                        <i class="fa-solid fa-arrow-right text-[10px]"></i>
                    </a>
                </div>

                <?php if (empty($dash_today_schedules)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs rounded-2xl bg-slate-900/40 border border-white/5 flex flex-col items-center justify-center gap-2">
                        <i class="fa-solid fa-mug-hot text-2xl text-slate-600 mb-1"></i>
                        <span class="text-white font-semibold">Tidak ada jadwal KBM pada hari <strong><?= $today_id_name ?></strong>.</span>
                        <p class="text-[11px] text-slate-500">Gunakan hari ini untuk mengulang materi atau istirahat bersama keluarga.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($dash_today_schedules as $sch): ?>
                            <div class="p-4 rounded-2xl bg-slate-900/60 border border-white/5 hover:border-white/10 transition flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                <div class="flex items-center gap-3.5">
                                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-500/15 text-blue-400 shrink-0 text-base">
                                        <i class="fa-solid fa-book"></i>
                                    </span>
                                    <div>
                                        <h4 class="text-sm font-bold text-white"><?= htmlspecialchars($sch['subject'] ?? 'Pelajaran') ?></h4>
                                        <p class="text-xs text-slate-400 mt-0.5">
                                            <i class="fa-solid fa-clock text-slate-500 mr-1"></i>
                                            <?= substr($sch['start_time'], 0, 5) ?> - <?= substr($sch['end_time'], 0, 5) ?> WIB
                                            • <i class="fa-solid fa-chalkboard-user text-slate-500 mr-1"></i> <?= htmlspecialchars($sch['teacher_name'] ?? 'Guru Pengampu') ?>
                                            <?php if (!empty($sch['room'])): ?>
                                                • <i class="fa-solid fa-location-dot text-slate-500 mr-1"></i> R. <?= htmlspecialchars($sch['room']) ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="shrink-0">
                                    <a href="akademik/materials.php" class="px-3.5 py-1.5 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300 text-xs font-semibold transition flex items-center gap-1.5">
                                        <i class="fa-solid fa-book-open"></i> Bahan Ajar
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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

<!-- Script Tab Switcher Dashboard Multi-Role (KBM Guru, Siswa, & Orang Tua) -->
<script>
function switchDashboardTab(group, targetId, btnEl) {
    if (!group || !targetId) return;

    // 1. Sembunyikan semua panel dalam grup ini
    const panels = document.querySelectorAll('.tab-panel-' + group);
    panels.forEach(p => p.classList.add('hidden'));

    // 2. Tampilkan panel yang dipilih
    const target = document.getElementById(targetId);
    if (target) {
        target.classList.remove('hidden');
    }

    // 3. Update styling tombol tab dalam grup tombol
    if (btnEl && btnEl.parentElement) {
        const allBtns = btnEl.parentElement.querySelectorAll('.tab-btn-' + group);
        allBtns.forEach(b => {
            b.classList.remove('bg-blue-600', 'text-white', 'shadow-md', 'shadow-blue-500/20');
            b.classList.add('text-slate-400', 'hover:text-white', 'hover:bg-white/5');
            const bBadge = b.querySelector('.tab-badge');
            if (bBadge) {
                bBadge.classList.remove('bg-white/20', 'text-white');
                bBadge.classList.add('bg-white/10', 'text-slate-300');
            }
        });

        btnEl.classList.remove('text-slate-400', 'hover:text-white', 'hover:bg-white/5');
        btnEl.classList.add('bg-blue-600', 'text-white', 'shadow-md', 'shadow-blue-500/20');
        const activeBadge = btnEl.querySelector('.tab-badge');
        if (activeBadge) {
            activeBadge.classList.remove('bg-white/10', 'text-slate-300');
            activeBadge.classList.add('bg-white/20', 'text-white');
        }
    }

    // 4. Simpan preferensi tab aktif di LocalStorage agar bertahan saat refresh
    try {
        localStorage.setItem('dash_active_tab_' + group, targetId);
    } catch(e) {}
}

// Pulihkan tab terakhir yang dibuka saat halaman dimuat
document.addEventListener('DOMContentLoaded', () => {
    ['guru_kbm', 'siswa_kbm', 'parent_kbm'].forEach(group => {
        try {
            const savedTab = localStorage.getItem('dash_active_tab_' + group);
            if (savedTab) {
                const btn = document.querySelector(`[data-tab-group="${group}"][data-tab-target="${savedTab}"]`);
                if (btn) {
                    btn.click();
                }
            }
        } catch(e) {}
    });
});
</script>

<?php if ($dash_weather_active): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const weatherUrl = <?= json_encode($dash_weather_url) ?>;
    const txtEl = document.getElementById('school-weather-text');
    const icoEl = document.getElementById('weather-icon');
    if (!weatherUrl || !txtEl) return;

    fetch(weatherUrl)
        .then(res => res.json())
        .then(data => {
            if (data && data.current_weather) {
                const temp = Math.round(data.current_weather.temperature);
                const wind = Math.round(data.current_weather.windspeed);
                const code = data.current_weather.weathercode;
                let desc = 'Cerah';
                let iconClass = 'fa-solid fa-sun text-amber-400';

                if (code >= 1 && code <= 3) {
                    desc = 'Cerah Berawan';
                    iconClass = 'fa-solid fa-cloud-sun text-cyan-300';
                } else if (code >= 45 && code <= 48) {
                    desc = 'Berkabut';
                    iconClass = 'fa-solid fa-smog text-slate-400';
                } else if (code >= 51 && code <= 67) {
                    desc = 'Hujan Ringan';
                    iconClass = 'fa-solid fa-cloud-rain text-blue-400';
                } else if (code >= 80) {
                    desc = 'Hujan Deras';
                    iconClass = 'fa-solid fa-cloud-showers-heavy text-blue-500';
                }

                txtEl.innerText = `${desc} ${temp}°C (Angin ${wind} km/j)`;
                if (icoEl) icoEl.className = iconClass;
            } else {
                txtEl.innerText = 'Cuaca Sekolah Terhubung';
            }
        })
        .catch(() => {
            txtEl.innerText = 'Info Cuaca Aktif';
        });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . "/includes/footer.php"; ?>