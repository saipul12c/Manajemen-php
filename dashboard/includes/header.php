<?php
require_once __DIR__ . "/../../config/database.php";

requireLogin();

// -22 fix: Security Headers with worker-src blob for Tailwind browser compiler
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https://cdn.jsdelivr.net; worker-src 'self' blob:; style-src 'self' 'unsafe-inline' blob: https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com data:; img-src 'self' data: https:; media-src 'self' https:; frame-src 'self' https://www.youtube.com https://drive.google.com; connect-src 'self' https://cdn.jsdelivr.net blob:;");

$user_id = (int)$_SESSION["user_id"];
$user_name = $_SESSION["user_name"] ?? "Pengguna";
$user_email = $_SESSION["user_email"] ?? "";
$user_role = $_SESSION["user_role"] ?? "siswa";

// Active page indicator & base URL resolution
$current_page = basename($_SERVER['PHP_SELF']);
$parent_folder = basename(dirname($_SERVER['PHP_SELF']));
$dash_url = ($parent_folder === 'dashboard') ? '' : '../';

// Hitung pesan unread
$unread_msg_count = isset($pdo) ? getUnreadMessagesCount($pdo, $user_id) : 0;

// Hitung pesan kontak tamu baru (khusus admin & staf)
$unread_contact_count = 0;
if (in_array($user_role, ['administrator', 'staf'], true) && isset($pdo)) {
    try {
        $unread_contact_count = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'baru'")->fetchColumn();
    } catch (Exception $e) {}
}

// Hitung pesan live chat baru (khusus admin & staf)
$unread_live_chat_count = 0;
if (in_array($user_role, ['administrator', 'staf'], true) && isset($pdo)) {
    try {
        $unread_live_chat_count = function_exists('getUnreadLiveChatCount') 
            ? getUnreadLiveChatCount($pdo) 
            : (int)$pdo->query("SELECT COUNT(*) FROM live_chat_messages WHERE is_read = 0 AND sender_type IN ('guest', 'user')")->fetchColumn();
    } catch (Exception $e) {}
}

// Hitung berita pending yang butuh persetujuan (khusus admin & staf)
$pending_news_nav_count = 0;
if (in_array($user_role, ['administrator', 'staf'], true) && isset($pdo)) {
    try {
        $pending_news_nav_count = getPendingNewsCount($pdo);
    } catch (Exception $e) {}
}

// Cek status Wali Kelas jika pengguna adalah guru
$is_homeroom_teacher = false;
$homeroom_class_info = null;
if ($user_role === 'guru' && isset($pdo)) {
    try {
        $stmt_hr = $pdo->prepare("SELECT id, name FROM classes WHERE homeroom_teacher_id = ? LIMIT 1");
        $stmt_hr->execute([$user_id]);
        $homeroom_class_info = $stmt_hr->fetch(PDO::FETCH_ASSOC);
        if ($homeroom_class_info) {
            $is_homeroom_teacher = true;
        }
    } catch (Exception $e) {}
}

// -------------------------------------------------------------
// NOTIFIKASI REAL-TIME SISTEM (MULTI-CHANNEL IN-APP BELL)
// -------------------------------------------------------------
$system_notifications = [];
if (isset($pdo) && isset($user_id)) {
    try {
        // 1. Pengumuman baru yang belum dibaca (Semua Role)
        $stmt_notif_ann = $pdo->prepare("
            SELECT a.id, a.title, a.created_at 
            FROM announcements a
            WHERE a.status = 'published'
              AND (a.expires_at IS NULL OR a.expires_at >= NOW())
              AND (a.target_role = 'semua' OR a.target_role = ?)
              AND NOT EXISTS (
                  SELECT 1 FROM announcement_reads r 
                  WHERE r.announcement_id = a.id AND r.user_id = ?
              )
            ORDER BY a.is_pinned DESC, a.created_at DESC LIMIT 3
        ");
        $stmt_notif_ann->execute([$user_role, $user_id]);
        while ($ann = $stmt_notif_ann->fetch()) {
            $system_notifications[] = [
                'type' => 'announcement',
                'title' => $ann['title'],
                'time' => date('d M H:i', strtotime($ann['created_at'])),
                'link' => $dash_url . 'informasi/announcements.php',
                'icon' => 'fa-solid fa-bullhorn text-amber-400',
                'badge' => 'Pengumuman'
            ];
        }

        // 2. Notifikasi khusus Siswa
        if ($user_role === 'siswa') {
            $stmt_notif_as = $pdo->prepare("
                SELECT a.title, a.due_date 
                FROM assignments a
                WHERE a.due_date >= CURRENT_DATE()
                  AND NOT EXISTS (
                      SELECT 1 FROM assignment_submissions s 
                      WHERE s.assignment_id = a.id AND s.student_id = ? AND s.status = 'selesai'
                  )
                ORDER BY a.due_date ASC LIMIT 2
            ");
            $stmt_notif_as->execute([$user_id]);
            while ($as = $stmt_notif_as->fetch()) {
                $system_notifications[] = [
                    'type' => 'assignment',
                    'title' => 'Tugas: ' . $as['title'],
                    'time' => 'Batas: ' . date('d M', strtotime($as['due_date'])),
                    'link' => $dash_url . 'akademik/assignments.php',
                    'icon' => 'fa-solid fa-list-check text-blue-400',
                    'badge' => 'Tugas'
                ];
            }

            $stmt_notif_bill = $pdo->prepare("
                SELECT title, amount FROM student_bills 
                WHERE student_id = ? AND status != 'lunas' 
                ORDER BY due_date ASC LIMIT 1
            ");
            $stmt_notif_bill->execute([$user_id]);
            if ($b = $stmt_notif_bill->fetch()) {
                $system_notifications[] = [
                    'type' => 'finance',
                    'title' => 'Tagihan: ' . $b['title'],
                    'time' => formatRupiah((float)$b['amount']),
                    'link' => $dash_url . 'keuangan/payments.php',
                    'icon' => 'fa-solid fa-wallet text-emerald-400',
                    'badge' => 'Keuangan'
                ];
            }
        }

        // 3. Notifikasi khusus Guru
        if ($user_role === 'guru') {
            $stmt_notif_sub = $pdo->prepare("
                SELECT COUNT(*) FROM assignment_submissions s
                JOIN assignments a ON s.assignment_id = a.id
                WHERE a.teacher_id = ? AND s.status = 'selesai' AND s.score IS NULL
            ");
            $stmt_notif_sub->execute([$user_id]);
            $pending_sub_cnt = (int)$stmt_notif_sub->fetchColumn();
            if ($pending_sub_cnt > 0) {
                $system_notifications[] = [
                    'type' => 'grading',
                    'title' => "$pending_sub_cnt tugas siswa menunggu penilaian",
                    'time' => 'Perlu Dinilai',
                    'link' => $dash_url . 'akademik/assignments.php',
                    'icon' => 'fa-solid fa-pen-nib text-cyan-400',
                    'badge' => 'Koreksi'
                ];
            }
        }

        // 4. Notifikasi khusus Staf & Admin
        if (in_array($user_role, ['staf', 'administrator'], true)) {
            $stmt_notif_req = $pdo->query("SELECT COUNT(*) FROM service_requests WHERE status = 'menunggu'");
            $pending_req_cnt = (int)$stmt_notif_req->fetchColumn();
            if ($pending_req_cnt > 0) {
                $system_notifications[] = [
                    'type' => 'service_request',
                    'title' => "$pending_req_cnt permohonan surat belum diproses",
                    'time' => 'Menunggu TU',
                    'link' => $dash_url . 'surat/requests.php',
                    'icon' => 'fa-solid fa-envelope-open-text text-amber-400',
                    'badge' => 'Persuratan'
                ];
            }

            $stmt_notif_ppdb = $pdo->query("SELECT COUNT(*) FROM ppdb_registrations WHERE status = 'menunggu_verifikasi'");
            $pending_ppdb_cnt = (int)$stmt_notif_ppdb->fetchColumn();
            if ($pending_ppdb_cnt > 0) {
                $system_notifications[] = [
                    'type' => 'ppdb',
                    'title' => "$pending_ppdb_cnt calon siswa PPDB perlu verifikasi",
                    'time' => 'PPDB Online',
                    'link' => $dash_url . 'admin/ppdb.php',
                    'icon' => 'fa-solid fa-user-graduate text-purple-400',
                    'badge' => 'PPDB'
                ];
            }
        }
    } catch (Exception $e_notif) {}
}
$notif_total_count = count($system_notifications);

// Mapping judul kategori dan breadcrumb
$module_titles = [
    'index.php' => 'Dashboard Beranda',
    'timetable.php' => 'Jadwal Pelajaran',
    'attendance.php' => 'Presensi Harian',
    'attendance_report.php' => 'Rekap Presensi',
    'scan_qr.php' => 'Pemindai QR Presensi',
    'teacher_attendance.php' => 'Presensi Mandiri Guru',
    'journal.php' => 'Jurnal Mengajar KBM',
    'curriculum.php' => 'Perangkat Ajar & Kurikulum',
    'homeroom.php' => 'Dashboard Wali Kelas',
    'report_card_bulk.php' => 'Cetak Rapor Massal Kelas',
    'materials.php' => 'Bahan Ajar & Modul',
    'assignments.php' => 'Tugas & Pekerjaan Rumah',
    'exams.php' => 'Daftar Ujian CBT',
    'exam_questions.php' => 'Bank Soal Ujian',
    'exam_take.php' => 'Ruang Ujian Siswa',
    'exam_results.php' => 'Hasil & Nilai Ujian',
    'exam_card.php' => 'Kartu Peserta Ujian',
    'payments.php' => 'Pembayaran SPP & Kas',
    'receipt.php' => 'Kuitansi Pembayaran',
    'financial_report.php' => 'Laporan Keuangan',
    'expenses.php' => 'Buku Kas Umum (BKU)',
    'payroll.php' => 'Penggajian & Honor Guru (Payroll)',
    'payroll_slip.php' => 'Slip Gaji Digital Guru',
    'wa_gateway.php' => 'WhatsApp Gateway API & Logs',
    'external_apis.php' => 'Pengaturan API Luar & Integrasi',
    'counseling.php' => 'Bimbingan Konseling (BK)',
    'counseling_letter.php' => 'Surat Panggilan BK',
    'messages.php' => 'Pesan & Konsultasi',
    'calendar.php' => 'Kalender Akademik',
    'announcements.php' => 'Pengumuman Resmi',
    'news.php' => 'Berita & Artikel Sekolah',
    'requests.php' => 'Layanan Persuratan',
    'request_print.php' => 'Cetak Surat Resmi',
    'archives.php' => 'Agenda Surat Masuk/Keluar',
    'books.php' => 'Perpustakaan Digital',
    'loans.php' => 'Peminjaman Buku',
    'scan.php' => 'Scan Barcode Perpus',
    'visitors.php' => 'Buku Tamu Perpus',
    'print_labels.php' => 'Cetak Barcode Buku',
    'clearance.php' => 'Bebas Pustaka',
    'reservations.php' => 'Reservasi Buku',
    'ppdb.php' => 'PPDB Online Siswa Baru',
    'contact_messages.php' => 'Kotak Masuk Pesan Tamu',
    'live_chat.php' => 'Layanan Live Chat Masuk',
    'gradebook.php' => 'Buku Nilai Guru',
    'gradebook_print.php' => 'Cetak Ledger Nilai',
    'report_card.php' => 'E-Rapor Digital',
    'qr_card.php' => 'Kartu Pelajar Digital',
    'classes.php' => 'Manajemen Kelas & Rombel',
    'promotion.php' => 'Kenaikan Kelas & Kelulusan Massal',
    'inventory.php' => 'Inventaris Sarpras',
    'tracer_study.php' => 'Tracer Study Alumni (BAN-SM)',
    'users.php' => 'Manajemen Pengguna',
    'student_360.php' => 'Profil Terpadu Siswa 360°',
    'backup.php' => 'Backup & Restore Database',
    'settings.php' => 'Pengaturan Sistem',
    'audit_logs.php' => 'Log Aktivitas Sistem',
    'profile.php' => 'Profil Saya'
];
$display_page_title = $page_title ?? ($module_titles[$current_page] ?? 'Dashboard');

// Tanggal hari ini dalam format Bahasa Indonesia
$nama_hari = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
$nama_bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$hari_ini = $nama_hari[date('l')];
$tgl_ini = date('j') . ' ' . $nama_bulan[(int)date('n')] . ' ' . date('Y');

// Deteksi kategori grup navigasi aktif pada sidebar
$active_nav_group = 'main';
if (in_array($current_page, ['timetable.php', 'attendance.php', 'attendance_report.php', 'scan_qr.php', 'teacher_attendance.php', 'journal.php', 'curriculum.php', 'qr_card.php', 'materials.php', 'assignments.php', 'gradebook.php', 'gradebook_print.php', 'report_card.php', 'p5_projects.php', 'p5_assessment.php', 'p5_report.php', 'homeroom.php', 'report_card_bulk.php'], true) || $parent_folder === 'ekskul') {
    $active_nav_group = 'kbm';
} elseif (in_array($current_page, ['exams.php', 'exam_questions.php', 'exam_take.php', 'exam_results.php', 'exam_card.php'], true) || $parent_folder === 'Modul-ujian') {
    $active_nav_group = 'exam';
} elseif (in_array($current_page, ['payments.php', 'receipt.php', 'financial_report.php', 'expenses.php', 'payroll.php', 'payroll_slip.php', 'counseling.php', 'counseling_letter.php', 'requests.php', 'request_print.php', 'legalisir.php', 'legalisir_print.php', 'tracer_study.php', 'archives.php', 'books.php', 'loans.php', 'scan.php', 'visitors.php', 'print_labels.php', 'clearance.php', 'reservations.php', 'inventory.php'], true) || in_array($parent_folder, ['keuangan', 'surat', 'perpustakaan', 'sarpras', 'alumni', 'bk'], true)) {
    $active_nav_group = 'services';
} elseif (in_array($current_page, ['ppdb.php', 'contact_messages.php', 'live_chat.php', 'classes.php', 'promotion.php', 'users.php', 'wa_gateway.php', 'external_apis.php', 'backup.php', 'settings.php', 'audit_logs.php'], true) || $parent_folder === 'admin') {
    $active_nav_group = 'admin';
} elseif ($current_page === 'profile.php') {
    $active_nav_group = 'account';
}

if (!function_exists('isNavGroupOpen')) {
    function isNavGroupOpen(string $groupId, string $activeGroup): bool {
        if ($groupId === $activeGroup) {
            return true;
        }
        if ($groupId === 'main' && in_array($activeGroup, ['main', 'account'], true)) {
            return true;
        }
        return false;
    }
}
?>
<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($display_page_title) ?> - Manajemen-PHP</title>
    
    <!-- Google Fonts & Tailwind -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    
    <!-- PWA Dynamic Manifest Integration for 3 Specialized Applications -->
    <?php
    $pwa_manifest_link = '';
    $pwa_theme_color = '#0f172a';
    $pwa_app_title = 'Manajemen-PHP';
    $pwa_app_icon = $dash_url . 'Modul-ujian/icons/icon.svg';

    if ($parent_folder === 'Modul-ujian') {
        $pwa_manifest_link = 'manifest.json';
        $pwa_theme_color = '#1e3a8a';
        $pwa_app_title = 'CBT Ujian Sekolah';
        $pwa_app_icon = 'icons/icon.svg';
    } elseif ($parent_folder === 'presensi') {
        $pwa_manifest_link = 'manifest.json';
        $pwa_theme_color = '#059669';
        $pwa_app_title = 'Presensi Siswa & Guru';
        $pwa_app_icon = 'icons/icon.svg';
    } elseif ($parent_folder === 'perpustakaan') {
        $pwa_manifest_link = 'manifest.json';
        $pwa_theme_color = '#d97706';
        $pwa_app_title = 'E-Perpustakaan Digital';
        $pwa_app_icon = 'icons/icon.svg';
    }
    ?>
    <?php if (!empty($pwa_manifest_link)): ?>
    <link rel="manifest" href="<?= $pwa_manifest_link ?>">
    <meta name="theme-color" content="<?= $pwa_theme_color ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($pwa_app_title) ?>">
    <link rel="apple-touch-icon" href="<?= $pwa_app_icon ?>">
    <?php else: ?>
    <meta name="theme-color" content="#0f172a">
    <?php endif; ?>
    
    <!-- Scanner & QR Libs -->
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        }

        /* Global Slim Modern Scrollbar */
        * {
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.35) transparent;
        }

        ::-webkit-scrollbar,
        *::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track,
        *::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb,
        *::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3);
            border-radius: 9999px;
            transition: background-color 0.2s ease;
        }

        ::-webkit-scrollbar-thumb:hover,
        *::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.6);
        }

        ::-webkit-scrollbar-corner,
        *::-webkit-scrollbar-corner {
            background: transparent;
        }

        /* Unified Smooth Sidebar Styling & Desktop Collapse */
        #sidebar {
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1), transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.25s ease-in-out, visibility 0.3s ease-in-out;
        }
        @media (min-width: 1024px) {
            #sidebar {
                left: auto !important;
                margin-left: 0;
                transform: translateX(0);
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }
            body.sidebar-desktop-collapsed #sidebar,
            html.sidebar-desktop-collapsed #sidebar {
                margin-left: -18rem !important;
                transform: translateX(-100%) !important;
                opacity: 0 !important;
                visibility: hidden !important;
                pointer-events: none !important;
            }
            body.sidebar-desktop-collapsed #mainContentWrapper,
            html.sidebar-desktop-collapsed #mainContentWrapper {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }

        /* Print Media Stylesheet */
        @media print {
            #sidebar, #sidebarBackdrop, #topNavHeader, .no-print {
                display: none !important;
            }
            #mainContentWrapper {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            body {
                background: white !important;
                color: black !important;
            }
        }
    </style>
    <script>
        try {
            if (localStorage.getItem('sidebar_desktop_collapsed') === 'true' && window.innerWidth >= 1024) {
                document.documentElement.classList.add('sidebar-desktop-collapsed');
            }
        } catch (e) {}
    </script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-blue-600 selection:text-white">

    <!-- Mobile Sidebar Backdrop Overlay -->
    <div id="sidebarBackdrop" onclick="closeSidebar()" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-40 lg:hidden hidden transition-opacity duration-300 opacity-0 pointer-events-none no-print"></div>

    <div class="min-h-screen flex">

        <!-- ============================================================== -->
        <!-- SIDEBAR / SLIDEBAR UTAMA -->
        <!-- ============================================================== -->
        <aside id="sidebar" class="fixed lg:sticky top-0 left-0 z-50 h-screen w-72 bg-slate-900/95 backdrop-blur-xl border-r border-white/10 flex flex-col shrink-0 -translate-x-full lg:translate-x-0 no-print shadow-2xl lg:shadow-none">
            
            <!-- Sidebar Header / Brand -->
            <div class="p-4 border-b border-white/10 flex items-center justify-between shrink-0 bg-slate-950/30">
                <a href="<?= $dash_url ?>index.php" class="flex items-center gap-3 group">
                    <div class="h-10 w-10 rounded-2xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-cyan-500 flex items-center justify-center text-white shadow-lg shadow-blue-500/25 group-hover:scale-105 transition-transform duration-200">
                        <i class="fa-solid fa-graduation-cap text-lg"></i>
                    </div>
                    <div class="min-w-0">
                        <span class="text-base font-extrabold tracking-tight text-white flex items-center gap-1">
                            Manajemen<span class="text-blue-400">-PHP</span>
                        </span>
                        <p class="text-[10px] text-slate-400 font-medium truncate">Portal Sekolah Terpadu</p>
                    </div>
                </a>
                
                <!-- Close Button (Available on Both Desktop & Mobile) -->
                <button type="button" onclick="closeSidebar()" class="p-2 rounded-xl text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer" title="Tutup / Sembunyikan Sidebar">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <!-- Profile Info Card -->
            <div class="px-4 py-3 border-b border-white/5 bg-slate-950/50 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="relative shrink-0">
                        <div class="h-10 w-10 rounded-xl bg-gradient-to-tr from-slate-800 to-slate-700 border border-white/10 flex items-center justify-center text-white font-bold text-sm shadow">
                            <?= strtoupper(mb_substr($user_name, 0, 1)) ?>
                        </div>
                        <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-emerald-500 border-2 border-slate-900" title="Online"></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-white truncate" title="<?= htmlspecialchars($user_name) ?>">
                            <?= htmlspecialchars($user_name) ?>
                        </p>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span class="inline-flex items-center gap-1 text-[9px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded border <?= getRoleBadge($user_role) ?>">
                                <i class="<?= getRoleIcon($user_role) ?> text-[8px]"></i>
                                <?= htmlspecialchars(getRoleLabel($user_role)) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Filter / Live Search in Sidebar -->
            <div class="px-3 pt-3 pb-2 shrink-0 border-b border-white/5">
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-500"></i>
                    <input type="text" id="sidebarMenuSearch" placeholder="Cari menu modul..." autocomplete="off"
                           class="w-full bg-slate-950/60 border border-white/10 rounded-xl pl-8 pr-7 py-1.5 text-xs text-slate-200 placeholder:text-slate-500 focus:outline-none focus:border-blue-500/60 focus:ring-1 focus:ring-blue-500/50 transition">
                    <button id="clearMenuSearch" onclick="clearSidebarSearch()" class="hidden absolute right-2.5 top-2 text-slate-500 hover:text-slate-300 text-xs">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- Navigation Links Scrollable List -->
            <nav id="sidebarNavList" class="flex-1 overflow-y-auto px-3 py-3 space-y-4 custom-scrollbar text-xs font-medium">

                <!-- Quick Accordion Action Bar -->
                <div class="flex items-center justify-between px-3 pb-1 text-[10px] text-slate-500 font-bold uppercase tracking-wider select-none border-b border-white/5">
                    <span>Daftar Modul</span>
                    <button type="button" onclick="toggleAllMenuGroups()" id="btnToggleAllGroups" 
                            class="text-[10px] text-slate-400 hover:text-blue-400 transition flex items-center gap-1.5 cursor-pointer py-0.5" 
                            title="Buka / Ciutkan Semua Kategori">
                        <span id="labelToggleAllGroups"><?= isNavGroupOpen('main', $active_nav_group) && isNavGroupOpen('kbm', $active_nav_group) ? 'Ciutkan' : 'Buka Semua' ?></span>
                        <i id="iconToggleAllGroups" class="fa-solid <?= isNavGroupOpen('main', $active_nav_group) && isNavGroupOpen('kbm', $active_nav_group) ? 'fa-angles-up' : 'fa-angles-down' ?> text-[8px]"></i>
                    </button>
                </div>

                <!-- 1. KELOMPOK: NAVIGASI UTAMA -->
                <div class="space-y-1" data-menu-group="main">
                    <button type="button" onclick="toggleMenuGroup('main')" data-menu-toggle="main"
                            aria-expanded="<?= isNavGroupOpen('main', $active_nav_group) ? 'true' : 'false' ?>"
                            class="w-full flex items-center justify-between px-3 py-1.5 rounded-xl text-[10px] font-bold uppercase tracking-wider text-slate-400 hover:text-slate-200 hover:bg-white/5 transition group cursor-pointer select-none">
                        <span data-menu-category>Menu Utama</span>
                        <i data-menu-chevron="main" class="fa-solid fa-chevron-down text-[8px] text-slate-500 group-hover:text-slate-300 transition-transform duration-200 <?= !isNavGroupOpen('main', $active_nav_group) ? '-rotate-90' : '' ?>"></i>
                    </button>

                    <div data-menu-items-container="main" class="space-y-1 <?= !isNavGroupOpen('main', $active_nav_group) ? 'hidden' : '' ?>">
                        <a href="<?= $dash_url ?>index.php" data-menu-item="beranda dashboard home" <?= $current_page === 'index.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'index.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'index.php' ? 'bg-blue-500/20 text-blue-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-chart-pie text-xs"></i>
                                </span>
                                <span>Beranda Dashboard</span>
                            </div>
                        </a>

                        <a href="<?= $dash_url ?>apps_hub.php" data-menu-item="instal pasang aplikasi cbt presensi perpustakaan pwa apk android download" <?= $current_page === 'apps_hub.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'apps_hub.php' ? 'bg-gradient-to-r from-blue-600/25 to-emerald-600/25 text-emerald-300 font-semibold border-l-[3px] border-emerald-400 shadow-sm shadow-emerald-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'apps_hub.php' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/5 text-slate-400 group-hover:text-emerald-400' ?>">
                                    <i class="fa-solid fa-mobile-screen-button text-xs"></i>
                                </span>
                                <span>Pasang Aplikasi</span>
                            </div>
                            <span class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">3 App</span>
                        </a>

                        <a href="<?= $dash_url ?>akademik/calendar.php" data-menu-item="kalender akademik agenda event kegiatan" <?= $current_page === 'calendar.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'calendar.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'calendar.php' ? 'bg-cyan-500/20 text-cyan-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-calendar-alt text-xs"></i>
                                </span>
                                <span>Kalender Akademik</span>
                            </div>
                        </a>

                        <a href="<?= $dash_url ?>informasi/announcements.php" data-menu-item="pengumuman informasi berita surat edaran" <?= $current_page === 'announcements.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'announcements.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'announcements.php' ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-bullhorn text-xs"></i>
                                </span>
                                <span>Pengumuman Sekolah</span>
                            </div>
                        </a>

                        <?php if (in_array($user_role, ['administrator', 'staf', 'guru'], true)): ?>
                            <a href="<?= $dash_url ?>informasi/news.php" data-menu-item="berita sekolah artikel publikasi liputan persetujuan approval redaksi kabar kegiatan" <?= $current_page === 'news.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'news.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'news.php' ? 'bg-sky-500/20 text-sky-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-newspaper text-xs"></i>
                                    </span>
                                    <span>Berita Sekolah</span>
                                </div>
                                <?php if ($pending_news_nav_count > 0): ?>
                                    <span class="inline-flex items-center justify-center rounded-full bg-amber-500 px-2 py-0.5 text-[10px] font-bold text-slate-950 shadow-sm shadow-amber-500/30 animate-pulse" title="<?= $pending_news_nav_count ?> berita butuh persetujuan">
                                        <?= $pending_news_nav_count ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>

                        <a href="<?= $dash_url ?>pesan/messages.php" data-menu-item="pesan obrolan chat konsultasi diskusi unread" <?= $current_page === 'messages.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'messages.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'messages.php' ? 'bg-indigo-500/20 text-indigo-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-comments text-xs"></i>
                                </span>
                                <span>Pesan & Konsultasi</span>
                            </div>
                            <?php if ($unread_msg_count > 0): ?>
                                <span class="inline-flex items-center justify-center rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-bold text-white shadow-sm shadow-rose-600/40">
                                    <?= $unread_msg_count ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>

                <!-- 2. KELOMPOK: PEMBELAJARAN & KBM -->
                <div class="space-y-1" data-menu-group="kbm">
                    <button type="button" onclick="toggleMenuGroup('kbm')" data-menu-toggle="kbm"
                            aria-expanded="<?= isNavGroupOpen('kbm', $active_nav_group) ? 'true' : 'false' ?>"
                            class="w-full flex items-center justify-between px-3 py-1.5 rounded-xl text-[10px] font-bold uppercase tracking-wider text-slate-400 hover:text-slate-200 hover:bg-white/5 transition group cursor-pointer select-none">
                        <span data-menu-category>Pembelajaran & KBM</span>
                        <i data-menu-chevron="kbm" class="fa-solid fa-chevron-down text-[8px] text-slate-500 group-hover:text-slate-300 transition-transform duration-200 <?= !isNavGroupOpen('kbm', $active_nav_group) ? '-rotate-90' : '' ?>"></i>
                    </button>

                    <div data-menu-items-container="kbm" class="space-y-1 <?= !isNavGroupOpen('kbm', $active_nav_group) ? 'hidden' : '' ?>">
                        <a href="<?= $dash_url ?>akademik/timetable.php" data-menu-item="jadwal pelajaran kbm jadwal mengajar kelas" <?= $current_page === 'timetable.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'timetable.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'timetable.php' ? 'bg-blue-500/20 text-blue-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-calendar-days text-xs"></i>
                                </span>
                                <span>Jadwal Pelajaran</span>
                            </div>
                        </a>

                        <a href="<?= $dash_url ?>presensi/attendance.php" data-menu-item="presensi kehadiran absensi alpa izin sakit" <?= in_array($current_page, ['attendance.php', 'attendance_report.php']) ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['attendance.php', 'attendance_report.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['attendance.php', 'attendance_report.php']) ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-clipboard-user text-xs"></i>
                                </span>
                                <span>Presensi Kehadiran</span>
                            </div>
                        </a>

                        <?php if (in_array($user_role, ['guru', 'staf', 'administrator'], true)): ?>
                            <a href="<?= $dash_url ?>presensi/scan_qr.php" data-menu-item="scan qr presensi pemindai barcode kamera absen" <?= $current_page === 'scan_qr.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'scan_qr.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'scan_qr.php' ? 'bg-teal-500/20 text-teal-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-qrcode text-xs"></i>
                                    </span>
                                    <span>Scan QR Presensi</span>
                                </div>
                            </a>

                            <a href="<?= $dash_url ?>presensi/teacher_attendance.php" data-menu-item="presensi guru staf clock in check in izin sakit cuti kehadiran" <?= $current_page === 'teacher_attendance.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'teacher_attendance.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'teacher_attendance.php' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-user-clock text-xs"></i>
                                    </span>
                                    <span>Presensi Mandiri Guru</span>
                                </div>
                            </a>
                        <?php endif; ?>

                        <?php if (in_array($user_role, ['guru', 'administrator'], true)): ?>
                            <a href="<?= $dash_url ?>akademik/journal.php" data-menu-item="jurnal mengajar agenda kbm catatan guru materi tatap muka inval absensi kelas" <?= $current_page === 'journal.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'journal.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'journal.php' ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-book-bookmark text-xs"></i>
                                    </span>
                                    <span>Jurnal Mengajar KBM</span>
                                </div>
                            </a>
                        <?php endif; ?>

                        <?php if (in_array($user_role, ['guru', 'staf', 'administrator'], true)): ?>
                            <a href="<?= $dash_url ?>akademik/curriculum.php" data-menu-item="perangkat ajar kurikulum rpp modul ajar silabus prota promes kktp administrasi" <?= $current_page === 'curriculum.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'curriculum.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'curriculum.php' ? 'bg-cyan-500/20 text-cyan-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-folder-tree text-xs"></i>
                                    </span>
                                    <span>Perangkat Ajar & RPP</span>
                                </div>
                            </a>
                        <?php endif; ?>

                        <a href="<?= $dash_url ?>presensi/qr_card.php" data-menu-item="kartu qr kartu pelajar id card identitas qr code" <?= $current_page === 'qr_card.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'qr_card.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'qr_card.php' ? 'bg-indigo-500/20 text-indigo-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-id-card text-xs"></i>
                                </span>
                                <span>Kartu Pelajar Digital</span>
                            </div>
                        </a>

                        <a href="<?= $dash_url ?>akademik/materials.php" data-menu-item="materi pelajaran bahan ajar modul e-learning buku video" <?= $current_page === 'materials.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'materials.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'materials.php' ? 'bg-sky-500/20 text-sky-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-book-open text-xs"></i>
                                </span>
                                <span>Bahan Ajar & Materi</span>
                            </div>
                        </a>

                        <a href="<?= $dash_url ?>akademik/assignments.php" data-menu-item="tugas pr pekerjaan rumah pengumpulan submission nilai" <?= $current_page === 'assignments.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'assignments.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'assignments.php' ? 'bg-purple-500/20 text-purple-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-list-check text-xs"></i>
                                </span>
                                <span>Tugas & Evaluasi</span>
                            </div>
                        </a>

                        <?php if (in_array($user_role, ['guru', 'administrator'], true)): ?>
                            <a href="<?= $dash_url ?>akademik/gradebook.php" data-menu-item="buku nilai ledger nilai siswa rapor akademik" <?= in_array($current_page, ['gradebook.php', 'gradebook_print.php']) ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['gradebook.php', 'gradebook_print.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['gradebook.php', 'gradebook_print.php']) ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-chart-column text-xs"></i>
                                    </span>
                                    <span>Buku Nilai Siswa</span>
                                </div>
                            </a>
                        <?php endif; ?>

                        <a href="<?= $dash_url ?>akademik/report_card.php" data-menu-item="rapor raport siswa hasil belajar kkm nilai akhir e-rapor" <?= $current_page === 'report_card.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'report_card.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'report_card.php' ? 'bg-yellow-500/20 text-yellow-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-award text-xs"></i>
                                </span>
                                <span>E-Rapor Akademik</span>
                            </div>
                        </a>

                        <a href="<?= $dash_url ?>akademik/p5_projects.php" data-menu-item="projek p5 kurikulum merdeka profil pelajar pancasila tema dimensi rapor p5 penilaian bskap" <?= in_array($current_page, ['p5_projects.php', 'p5_assessment.php', 'p5_report.php']) ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['p5_projects.php', 'p5_assessment.php', 'p5_report.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['p5_projects.php', 'p5_assessment.php', 'p5_report.php']) ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-seedling text-xs"></i>
                                </span>
                                <span>Rapor Projek P5</span>
                            </div>
                            <span class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Merdeka</span>
                        </a>

                        <a href="<?= $dash_url ?>ekskul/index.php" data-menu-item="ekstrakurikuler ekskul prestasi lomba piagam kejuaraan bakat minat pramuka paskibra pmr futsal robotik" <?= ($parent_folder === 'ekskul') ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= ($parent_folder === 'ekskul') ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= ($parent_folder === 'ekskul') ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-trophy text-xs"></i>
                                </span>
                                <span>Ekskul & Prestasi</span>
                            </div>
                            <span class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30">Bakat</span>
                        </a>

                        <?php if ($user_role === 'administrator' || ($user_role === 'guru' && $is_homeroom_teacher)): ?>
                            <a href="<?= $dash_url ?>akademik/homeroom.php" data-menu-item="wali kelas monitoring kelas rapor massal siswa binaan absensi pelanggaran catatan wali" <?= in_array($current_page, ['homeroom.php', 'report_card_bulk.php']) ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['homeroom.php', 'report_card_bulk.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['homeroom.php', 'report_card_bulk.php']) ? 'bg-violet-500/20 text-violet-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-chalkboard-user text-xs"></i>
                                    </span>
                                    <span>Wali Kelas <?= $homeroom_class_info ? '('.htmlspecialchars($homeroom_class_info['name']).')' : '' ?></span>
                                </div>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 3. KELOMPOK: ASESMEN & UJIAN CBT -->
                <div class="space-y-1" data-menu-group="exam">
                    <button type="button" onclick="toggleMenuGroup('exam')" data-menu-toggle="exam"
                            aria-expanded="<?= isNavGroupOpen('exam', $active_nav_group) ? 'true' : 'false' ?>"
                            class="w-full flex items-center justify-between px-3 py-1.5 rounded-xl text-[10px] font-bold uppercase tracking-wider text-slate-400 hover:text-slate-200 hover:bg-white/5 transition group cursor-pointer select-none">
                        <span data-menu-category>Ujian & Asesmen</span>
                        <i data-menu-chevron="exam" class="fa-solid fa-chevron-down text-[8px] text-slate-500 group-hover:text-slate-300 transition-transform duration-200 <?= !isNavGroupOpen('exam', $active_nav_group) ? '-rotate-90' : '' ?>"></i>
                    </button>

                    <div data-menu-items-container="exam" class="space-y-1 <?= !isNavGroupOpen('exam', $active_nav_group) ? 'hidden' : '' ?>">
                        <a href="<?= $dash_url ?>Modul-ujian/exams.php" data-menu-item="ujian cbt bank soal tes asesmen nilai hasil ujian kartu ujian" <?= in_array($current_page, ['exams.php', 'exam_questions.php', 'exam_take.php', 'exam_results.php', 'exam_card.php']) ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['exams.php', 'exam_questions.php', 'exam_take.php', 'exam_results.php', 'exam_card.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['exams.php', 'exam_questions.php', 'exam_take.php', 'exam_results.php', 'exam_card.php']) ? 'bg-rose-500/20 text-rose-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-file-pen text-xs"></i>
                                </span>
                                <span>Modul Ujian CBT</span>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- 4. KELOMPOK: LAYANAN & FASILITAS SEKOLAH -->
                <div class="space-y-1" data-menu-group="services">
                    <button type="button" onclick="toggleMenuGroup('services')" data-menu-toggle="services"
                            aria-expanded="<?= isNavGroupOpen('services', $active_nav_group) ? 'true' : 'false' ?>"
                            class="w-full flex items-center justify-between px-3 py-1.5 rounded-xl text-[10px] font-bold uppercase tracking-wider text-slate-400 hover:text-slate-200 hover:bg-white/5 transition group cursor-pointer select-none">
                        <span data-menu-category>Layanan Sekolah</span>
                        <i data-menu-chevron="services" class="fa-solid fa-chevron-down text-[8px] text-slate-500 group-hover:text-slate-300 transition-transform duration-200 <?= !isNavGroupOpen('services', $active_nav_group) ? '-rotate-90' : '' ?>"></i>
                    </button>

                    <div data-menu-items-container="services" class="space-y-1 <?= !isNavGroupOpen('services', $active_nav_group) ? 'hidden' : '' ?>">
                        <a href="<?= $dash_url ?>keuangan/payments.php" data-menu-item="keuangan spp pembayaran tagihan kas kwitansi iuran" <?= in_array($current_page, ['payments.php', 'receipt.php', 'financial_report.php']) ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['payments.php', 'receipt.php', 'financial_report.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['payments.php', 'receipt.php', 'financial_report.php']) ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-wallet text-xs"></i>
                                </span>
                                <span>Keuangan & SPP</span>
                            </div>
                        </a>

                        <?php if (in_array($user_role, ['administrator', 'staf'], true)): ?>
                            <a href="<?= $dash_url ?>keuangan/expenses.php" data-menu-item="buku kas umum bku pengeluaran operasional belanja kas keluar biaya" <?= in_array($current_page, ['expenses.php']) ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['expenses.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['expenses.php']) ? 'bg-rose-500/20 text-rose-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-receipt text-xs"></i>
                                    </span>
                                    <span>Buku Kas Umum (BKU)</span>
                                </div>
                            </a>
                        <?php endif; ?>

                        <?php if (in_array($user_role, ['administrator', 'staf', 'guru'], true)): ?>
                            <a href="<?= $dash_url ?>keuangan/payroll.php" data-menu-item="penggajian payroll honor gaji slip tunjangan insentif guru staf" <?= in_array($current_page, ['payroll.php', 'payroll_slip.php']) ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['payroll.php', 'payroll_slip.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['payroll.php', 'payroll_slip.php']) ? 'bg-purple-500/20 text-purple-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-money-check-dollar text-xs"></i>
                                    </span>
                                    <span>Penggajian Guru (Payroll)</span>
                                </div>
                            </a>
                        <?php endif; ?>

                        <a href="<?= $dash_url ?>bk/counseling.php" data-menu-item="bimbingan konseling bk pelanggaran prestasi poin disiplin" <?= in_array($current_page, ['counseling.php', 'counseling_letter.php']) ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['counseling.php', 'counseling_letter.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['counseling.php', 'counseling_letter.php']) ? 'bg-purple-500/20 text-purple-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-scale-balanced text-xs"></i>
                                </span>
                                <span>Bimbingan Konseling (BK)</span>
                            </div>
                        </a>

                        <a href="<?= $dash_url ?>surat/requests.php" data-menu-item="surat permohonan surat izin dispensasi keterangan aktif tata usaha" <?= in_array($current_page, ['requests.php', 'request_print.php']) ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['requests.php', 'request_print.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['requests.php', 'request_print.php']) ? 'bg-cyan-500/20 text-cyan-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-envelope-open-text text-xs"></i>
                                </span>
                                <span>Layanan Persuratan</span>
                            </div>
                        </a>

                        <a href="<?= $dash_url ?>alumni/legalisir.php" data-menu-item="legalisir ijazah skl transkrip alumni online verifikasi tanda tangan kepala sekolah watermark pengesahan" <?= in_array($current_page, ['legalisir.php', 'legalisir_print.php']) ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['legalisir.php', 'legalisir_print.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['legalisir.php', 'legalisir_print.php']) ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-stamp text-xs"></i>
                                </span>
                                <span>Legalisir Digital Alumni</span>
                            </div>
                        </a>

                        <a href="<?= $dash_url ?>alumni/tracer_study.php" data-menu-item="tracer study alumni jejak karir kuliah kerja wirausaha akreditasi ban-sm bmw lulusan" <?= in_array($current_page, ['tracer_study.php']) ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['tracer_study.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['tracer_study.php']) ? 'bg-indigo-500/20 text-indigo-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-user-graduate text-xs"></i>
                                </span>
                                <span>Tracer Study Alumni</span>
                            </div>
                            <span class="inline-flex items-center rounded-md bg-indigo-500/15 px-1.5 py-0.5 text-[9px] font-bold text-indigo-300 border border-indigo-500/20">BAN-SM</span>
                        </a>

                        <?php if (in_array($user_role, ['administrator', 'staf'], true)): ?>
                            <a href="<?= $dash_url ?>surat/archives.php" data-menu-item="agenda surat masuk keluar arsip disposisi ekspedisi nomor surat tu" <?= in_array($current_page, ['archives.php']) ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['archives.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['archives.php']) ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-box-archive text-xs"></i>
                                    </span>
                                    <span>Agenda Surat Masuk/Keluar</span>
                                </div>
                            </a>
                        <?php endif; ?>

                        <a href="<?= $dash_url ?>perpustakaan/books.php" data-menu-item="perpustakaan buku perpus pinjam pinjaman e-book katalog" <?= in_array($current_page, ['books.php', 'loans.php', 'scan.php', 'visitors.php', 'print_labels.php', 'clearance.php', 'reservations.php']) ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['books.php', 'loans.php', 'scan.php', 'visitors.php', 'print_labels.php', 'clearance.php', 'reservations.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['books.php', 'loans.php', 'scan.php', 'visitors.php', 'print_labels.php', 'clearance.php', 'reservations.php']) ? 'bg-teal-500/20 text-teal-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-book-bookmark text-xs"></i>
                                </span>
                                <span>Perpustakaan Digital</span>
                            </div>
                        </a>

                        <?php if (in_array($user_role, ['administrator', 'staf', 'guru'], true)): ?>
                            <a href="<?= $dash_url ?>sarpras/inventory.php" data-menu-item="sarpras inventaris aset barang sarana prasarana peminjaman ruangan fasilitas alat" <?= in_array($current_page, ['inventory.php']) ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['inventory.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['inventory.php']) ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-boxes-stacked text-xs"></i>
                                    </span>
                                    <span>Inventaris Sarpras</span>
                                </div>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 5. KELOMPOK: ADMINISTRASI & SISTEM (ADMIN / STAF) -->
                <?php if (in_array($user_role, ['administrator', 'staf'], true)): ?>
                    <div class="space-y-1" data-menu-group="admin">
                        <button type="button" onclick="toggleMenuGroup('admin')" data-menu-toggle="admin"
                                aria-expanded="<?= isNavGroupOpen('admin', $active_nav_group) ? 'true' : 'false' ?>"
                                class="w-full flex items-center justify-between px-3 py-1.5 rounded-xl text-[10px] font-bold uppercase tracking-wider text-slate-400 hover:text-slate-200 hover:bg-white/5 transition group cursor-pointer select-none">
                            <span data-menu-category>Administrasi & Sistem</span>
                            <i data-menu-chevron="admin" class="fa-solid fa-chevron-down text-[8px] text-slate-500 group-hover:text-slate-300 transition-transform duration-200 <?= !isNavGroupOpen('admin', $active_nav_group) ? '-rotate-90' : '' ?>"></i>
                        </button>

                        <div data-menu-items-container="admin" class="space-y-1 <?= !isNavGroupOpen('admin', $active_nav_group) ? 'hidden' : '' ?>">
                            <a href="<?= $dash_url ?>admin/ppdb.php" data-menu-item="ppdb pendaftaran siswa baru formulir pmb online seleksi" <?= $current_page === 'ppdb.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'ppdb.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'ppdb.php' ? 'bg-rose-500/20 text-rose-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-graduation-cap text-xs"></i>
                                    </span>
                                    <span>PPDB Online</span>
                                </div>
                            </a>

                            <a href="<?= $dash_url ?>admin/contact_messages.php" data-menu-item="pesan tamu kontak inbox pengaduan pertanyaan formulir bantuan tiket" <?= $current_page === 'contact_messages.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'contact_messages.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'contact_messages.php' ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-envelope-open-text text-xs"></i>
                                    </span>
                                    <span>Pesan Tamu / Kontak</span>
                                </div>
                                <?php if ($unread_contact_count > 0): ?>
                                    <span class="inline-flex items-center justify-center rounded-full bg-amber-500 px-2 py-0.5 text-[10px] font-bold text-slate-950 shadow-sm shadow-amber-500/30">
                                        <?= $unread_contact_count ?>
                                    </span>
                                <?php endif; ?>
                            </a>

                            <a href="<?= $dash_url ?>admin/live_chat.php" data-menu-item="live chat pesan obrolan langsung tamu siswa bantuan online realtime" <?= $current_page === 'live_chat.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'live_chat.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'live_chat.php' ? 'bg-teal-500/20 text-teal-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-headset text-xs"></i>
                                    </span>
                                    <span>Live Chat Masuk</span>
                                </div>
                                <?php if ($unread_live_chat_count > 0): ?>
                                    <span class="inline-flex items-center justify-center rounded-full bg-teal-500 px-2 py-0.5 text-[10px] font-bold text-slate-950 shadow-sm shadow-teal-500/30">
                                        <?= $unread_live_chat_count ?>
                                    </span>
                                <?php endif; ?>
                            </a>

                            <a href="<?= $dash_url ?>admin/classes.php" data-menu-item="kelas rombel rombongan belajar tingkat wali kelas" <?= $current_page === 'classes.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'classes.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'classes.php' ? 'bg-indigo-500/20 text-indigo-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-school text-xs"></i>
                                    </span>
                                    <span>Manajemen Kelas</span>
                                </div>
                            </a>

                            <a href="<?= $dash_url ?>admin/promotion.php" data-menu-item="kenaikan kelas kelulusan massal rollover tahun ajaran alumni siswa" <?= $current_page === 'promotion.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'promotion.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'promotion.php' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-arrows-up-to-line text-xs"></i>
                                    </span>
                                    <span>Kenaikan & Kelulusan</span>
                                </div>
                                <span class="inline-flex items-center rounded-md bg-emerald-500/15 px-1.5 py-0.5 text-[9px] font-bold text-emerald-300 border border-emerald-500/20">Rollover</span>
                            </a>

                            <a href="<?= $dash_url ?>admin/users.php" data-menu-item="user pengguna akun role guru siswa admin staf ortu" <?= $current_page === 'users.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'users.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'users.php' ? 'bg-blue-500/20 text-blue-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-solid fa-users text-xs"></i>
                                    </span>
                                    <span><?= $user_role === 'staf' ? 'Kelola Siswa & Ortu' : 'Kelola Pengguna' ?></span>
                                </div>
                            </a>

                            <a href="<?= $dash_url ?>admin/wa_gateway.php" data-menu-item="whatsapp gateway wa broadcast notifikasi api pesan kirim blast log" <?= $current_page === 'wa_gateway.php' ? 'aria-current="page"' : '' ?>
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'wa_gateway.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'wa_gateway.php' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                        <i class="fa-brands fa-whatsapp text-xs"></i>
                                    </span>
                                    <span>WhatsApp Gateway</span>
                                </div>
                            </a>

                            <?php if ($user_role === 'administrator'): ?>
                                <a href="<?= $dash_url ?>admin/external_apis.php" data-menu-item="api luar eksternal integrasi gateway webhook rest token secret openai midtrans fonnte" <?= $current_page === 'external_apis.php' ? 'aria-current="page"' : '' ?>
                                   class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'external_apis.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'external_apis.php' ? 'bg-cyan-500/20 text-cyan-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                            <i class="fa-solid fa-cloud-arrow-up text-xs"></i>
                                        </span>
                                        <span>Kelola API Luar</span>
                                    </div>
                                    <span class="inline-flex items-center rounded-md bg-cyan-500/15 px-1.5 py-0.5 text-[9px] font-bold text-cyan-300 border border-cyan-500/20">Auto-DB</span>
                                </a>

                                <a href="<?= $dash_url ?>admin/backup.php" data-menu-item="backup restore database dump salinan sql" <?= $current_page === 'backup.php' ? 'aria-current="page"' : '' ?>
                                   class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'backup.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'backup.php' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                            <i class="fa-solid fa-database text-xs"></i>
                                        </span>
                                        <span>Backup Database</span>
                                    </div>
                                </a>

                                <a href="<?= $dash_url ?>admin/settings.php" data-menu-item="pengaturan setelan konfigurasi log audit aktivitas" <?= in_array($current_page, ['settings.php', 'audit_logs.php']) ? 'aria-current="page"' : '' ?>
                                   class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['settings.php', 'audit_logs.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['settings.php', 'audit_logs.php']) ? 'bg-slate-500/20 text-slate-300' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                            <i class="fa-solid fa-gear text-xs"></i>
                                        </span>
                                        <span>Pengaturan & Audit</span>
                                    </div>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 6. KELOMPOK: PENGGUNA -->
                <div class="space-y-1" data-menu-group="account">
                    <button type="button" onclick="toggleMenuGroup('account')" data-menu-toggle="account"
                            aria-expanded="<?= isNavGroupOpen('account', $active_nav_group) ? 'true' : 'false' ?>"
                            class="w-full flex items-center justify-between px-3 py-1.5 rounded-xl text-[10px] font-bold uppercase tracking-wider text-slate-400 hover:text-slate-200 hover:bg-white/5 transition group cursor-pointer select-none">
                        <span data-menu-category>Akun Saya</span>
                        <i data-menu-chevron="account" class="fa-solid fa-chevron-down text-[8px] text-slate-500 group-hover:text-slate-300 transition-transform duration-200 <?= !isNavGroupOpen('account', $active_nav_group) ? '-rotate-90' : '' ?>"></i>
                    </button>

                    <div data-menu-items-container="account" class="space-y-1 <?= !isNavGroupOpen('account', $active_nav_group) ? 'hidden' : '' ?>">
                        <a href="<?= $dash_url ?>profile.php" data-menu-item="profil akun saya ganti password foto data diri" <?= $current_page === 'profile.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'profile.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'profile.php' ? 'bg-blue-500/20 text-blue-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-circle-user text-xs"></i>
                                </span>
                                <span>Profil Pengguna</span>
                            </div>
                        </a>

                        <?php if (in_array($user_role, ['guru', 'staf'], true)): ?>
                            <a href="<?= $dash_url ?>profile.php#signature" data-menu-item="tanda tangan digital tte guru scan nip"
                               class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all text-slate-400 hover:text-amber-300 hover:bg-amber-500/10 border-l-[3px] border-transparent">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500/10 text-amber-400 group-hover:bg-amber-500/20">
                                        <i class="fa-solid fa-signature text-xs"></i>
                                    </span>
                                    <span>Tanda Tangan (TTE)</span>
                                </div>
                                <span class="text-[9px] px-1.5 py-0.5 rounded font-mono font-bold bg-amber-500/15 text-amber-300 border border-amber-500/20">TTE</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

            </nav>

            <!-- Sidebar Footer / Logout -->
            <div class="p-3 border-t border-white/10 shrink-0 bg-slate-950/40">
                <form method="POST" action="<?= $dash_url ?>index.php" onsubmit="return confirm('Apakah Anda yakin ingin keluar dari sistem?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" 
                            class="w-full flex items-center justify-center gap-2 rounded-xl border border-red-500/20 bg-red-500/10 hover:bg-red-500/20 px-3 py-2 text-xs font-semibold text-red-400 hover:text-red-300 transition duration-150 cursor-pointer">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span>Keluar dari Akun</span>
                    </button>
                </form>
                <div class="mt-2 text-center text-[10px] text-slate-400">
                    Sistem Terkoneksi • v1.1.0
                </div>
            </div>

        </aside>

        <!-- ============================================================== -->
        <!-- AREA KONTEN UTAMA (TOPBAR + MAIN CONTENT) -->
        <!-- ============================================================== -->
        <div id="mainContentWrapper" class="flex-1 flex flex-col min-w-0 min-h-screen transition-all duration-300">
            
            <!-- Sticky Topbar Navigation -->
            <header id="topNavHeader" class="sticky top-0 z-30 border-b border-white/10 bg-slate-950/85 backdrop-blur-md px-4 sm:px-6 py-3 shrink-0">
                <div class="flex items-center justify-between gap-3">
                    
                    <!-- Left: Sidebar Toggle & Breadcrumb -->
                    <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                        <!-- Mobile Hamburger Button -->
                        <button type="button" onclick="toggleSidebar()" 
                                class="lg:hidden p-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition cursor-pointer" 
                                title="Buka Menu">
                            <i class="fa-solid fa-bars text-base"></i>
                        </button>

                        <!-- Desktop Sidebar Toggle Button -->
                        <button type="button" onclick="toggleDesktopSidebar()" 
                                class="hidden lg:flex p-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition cursor-pointer" 
                                title="Buka/Tutup Sidebar (Ctrl+B)">
                            <i class="fa-solid fa-bars-staggered text-sm"></i>
                        </button>

                        <!-- Breadcrumb & Page Title -->
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5 text-xs text-slate-400">
                                <a href="<?= $dash_url ?>index.php" class="hover:text-blue-400 transition flex items-center gap-1">
                                    <i class="fa-solid fa-house text-[10px]"></i>
                                    <span class="hidden sm:inline">Portal</span>
                                </a>
                                <span>/</span>
                                <span class="text-slate-300 font-medium truncate"><?= htmlspecialchars($display_page_title) ?></span>
                            </div>
                            <h2 class="text-sm sm:text-base font-bold text-white truncate leading-tight">
                                <?= htmlspecialchars($display_page_title) ?>
                            </h2>
                        </div>
                    </div>

                    <!-- Right: Info Tanggal, Quick Action, Profile & Logout -->
                    <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                        
                        <!-- Hari & Tanggal -->
                        <div class="hidden md:flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-white/10 bg-white/5 text-xs text-slate-300">
                            <i class="fa-regular fa-calendar text-blue-400"></i>
                            <span><?= $hari_ini ?>, <?= $tgl_ini ?></span>
                        </div>

                        <!-- Lonceng Notifikasi Real-time In-App -->
                        <div class="relative" id="notifDropdownContainer">
                            <button type="button" onclick="toggleNotificationDropdown()"
                                    class="relative p-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition cursor-pointer"
                                    title="Pemberitahuan Sistem">
                                <i class="fa-solid fa-bell text-sm"></i>
                                <?php if ($notif_total_count > 0): ?>
                                    <span class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-amber-500 text-[9px] font-bold text-slate-950 animate-pulse shadow-md shadow-amber-500/30">
                                        <?= $notif_total_count ?>
                                    </span>
                                <?php endif; ?>
                            </button>

                            <!-- Dropdown Menu -->
                            <div id="notifDropdownMenu" class="hidden absolute right-0 mt-2 w-80 sm:w-96 rounded-2xl border border-white/10 bg-slate-900/95 backdrop-blur-xl p-3 shadow-2xl z-50 text-slate-100 space-y-2">
                                <div class="flex items-center justify-between pb-2 border-b border-white/10 px-1">
                                    <span class="text-xs font-bold text-white flex items-center gap-1.5">
                                        <i class="fa-solid fa-bell text-amber-400 text-xs"></i>
                                        Pemberitahuan Sistem
                                    </span>
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                        <?= $notif_total_count ?> Baru
                                    </span>
                                </div>

                                <div class="max-h-72 overflow-y-auto space-y-1.5 divide-y divide-white/5 pr-1">
                                    <?php if ($notif_total_count > 0): ?>
                                        <?php foreach ($system_notifications as $notif): ?>
                                            <a href="<?= htmlspecialchars($notif['link']) ?>" class="block pt-1.5 first:pt-0 p-2 rounded-xl hover:bg-white/5 transition group">
                                                <div class="flex items-start gap-2.5">
                                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/5 text-xs mt-0.5">
                                                        <i class="<?= htmlspecialchars($notif['icon']) ?>"></i>
                                                    </span>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center justify-between gap-1">
                                                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-white/10 text-slate-300"><?= htmlspecialchars($notif['badge']) ?></span>
                                                            <span class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($notif['time']) ?></span>
                                                        </div>
                                                        <p class="text-xs font-medium text-slate-200 group-hover:text-blue-400 transition mt-1 truncate">
                                                            <?= htmlspecialchars($notif['title']) ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="py-6 text-center text-slate-400">
                                            <i class="fa-regular fa-bell-slash text-2xl text-slate-500 block mb-1"></i>
                                            <p class="text-xs font-medium text-slate-300">Semua tugas & tagihan selesai!</p>
                                            <p class="text-[10px] text-slate-500 mt-0.5">Tidak ada notifikasi mendesak saat ini.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="pt-2 border-t border-white/10 text-center">
                                    <a href="<?= $dash_url ?>informasi/announcements.php" class="text-[11px] font-semibold text-blue-400 hover:text-blue-300 transition">
                                        Buka Pengumuman Sekolah &rarr;
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Script Toggle Dropdown -->
                        <script>
                        function toggleNotificationDropdown() {
                            const menu = document.getElementById('notifDropdownMenu');
                            if (menu) menu.classList.toggle('hidden');
                        }
                        document.addEventListener('click', function(e) {
                            const container = document.getElementById('notifDropdownContainer');
                            const menu = document.getElementById('notifDropdownMenu');
                            if (container && menu && !container.contains(e.target)) {
                                menu.classList.add('hidden');
                            }
                        });
                        </script>

                        <!-- Pesan Shortcut Button -->
                        <a href="<?= $dash_url ?>pesan/messages.php" 
                           class="relative p-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-slate-300 hover:text-white transition"
                           title="Kotak Masuk Pesan">
                            <i class="fa-solid fa-comments text-sm"></i>
                            <?php if ($unread_msg_count > 0): ?>
                                <span class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-rose-600 text-[9px] font-bold text-white">
                                    <?= $unread_msg_count ?>
                                </span>
                            <?php endif; ?>
                        </a>

                        <!-- User Profile Pill -->
                        <a href="<?= $dash_url ?>profile.php" class="flex items-center gap-2 p-1 sm:pr-3 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 transition group">
                            <div class="h-7 w-7 rounded-lg bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-xs font-bold text-white">
                                <?= strtoupper(mb_substr($user_name, 0, 1)) ?>
                            </div>
                            <div class="hidden sm:block text-left leading-none">
                                <span class="text-xs font-bold text-white block group-hover:text-blue-400 transition truncate max-w-[120px]">
                                    <?= htmlspecialchars($user_name) ?>
                                </span>
                                <span class="text-[10px] text-slate-400 uppercase tracking-wider">
                                    <?= htmlspecialchars(getRoleLabel($user_role)) ?>
                                </span>
                            </div>
                        </a>

                        <!-- Quick Logout Button -->
                        <form method="POST" action="<?= $dash_url ?>index.php" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin keluar?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" 
                                    title="Keluar dari Akun"
                                    class="p-2 sm:px-3 sm:py-1.5 rounded-xl border border-red-500/20 bg-red-500/10 hover:bg-red-500/20 text-red-400 hover:text-red-300 text-xs font-medium transition cursor-pointer flex items-center gap-1.5">
                                <i class="fa-solid fa-right-from-bracket"></i>
                                <span class="hidden sm:inline">Keluar</span>
                            </button>
                        </form>

                    </div>

                </div>
            </header>

            <!-- Main Page Content Starts Here -->
            <main class="flex-1 w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
