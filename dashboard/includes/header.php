<?php
require_once __DIR__ . "/../../config/database.php";

requireLogin();

// BUG-22 fix: Security Headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com data:; img-src 'self' data: https:; media-src 'self' https:; frame-src 'self' https://www.youtube.com https://drive.google.com;");

$user_id = (int)$_SESSION["user_id"];
$user_name = $_SESSION["user_name"] ?? "Pengguna";
$user_email = $_SESSION["user_email"] ?? "";
$user_role = $_SESSION["user_role"] ?? "siswa";

// Hitung pesan unread
$unread_msg_count = isset($pdo) ? getUnreadMessagesCount($pdo, $user_id) : 0;

// Active page indicator & base URL resolution
$current_page = basename($_SERVER['PHP_SELF']);
$parent_folder = basename(dirname($_SERVER['PHP_SELF']));
$dash_url = ($parent_folder === 'dashboard') ? '' : '../';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Dashboard') ?> - Manajemen-PHP</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans antialiased selection:bg-blue-600 selection:text-white">

    <!-- Top Navigation -->
    <header class="sticky top-0 z-40 border-b border-white/10 bg-slate-950/90 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-3 sm:px-6 py-3.5">
            
            <div class="flex items-center gap-4 lg:gap-6">
                <a href="<?= $dash_url ?>index.php" class="flex items-center gap-2 text-lg sm:text-xl font-bold tracking-tight shrink-0">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-500/30">
                        <i class="fa-solid fa-bolt text-sm"></i>
                    </span>
                    <span class="hidden sm:inline">Manajemen<span class="text-blue-500">-PHP</span></span>
                </a>

                <!-- Desktop Navigation Menu -->
                <nav class="hidden lg:flex items-center gap-0.5 xl:gap-1 text-xs xl:text-sm font-medium">
                    <a href="<?= $dash_url ?>index.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'index.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-chart-pie mr-1.5 opacity-80"></i>Beranda
                    </a>

                    <a href="<?= $dash_url ?>akademik/timetable.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'timetable.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-calendar-days mr-1.5 opacity-80"></i>Jadwal
                    </a>

                    <a href="<?= $dash_url ?>presensi/attendance.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= in_array($current_page, ['attendance.php', 'attendance_report.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-clipboard-user mr-1.5 opacity-80"></i>Presensi
                    </a>

                    <?php if (in_array($user_role, ['guru', 'staf', 'administrator'], true)): ?>
                        <a href="<?= $dash_url ?>presensi/scan_qr.php" 
                           class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'scan_qr.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            <i class="fa-solid fa-qrcode mr-1.5 opacity-80"></i>Scan QR
                        </a>
                    <?php endif; ?>

                    <a href="<?= $dash_url ?>akademik/materials.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'materials.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-book-open mr-1.5 opacity-80"></i>Materi
                    </a>

                    <a href="<?= $dash_url ?>akademik/assignments.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'assignments.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-list-check mr-1.5 opacity-80"></i>Tugas
                    </a>

                    <a href="<?= $dash_url ?>Modul-ujian/exams.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= in_array($current_page, ['exams.php', 'exam_questions.php', 'exam_take.php', 'exam_results.php', 'exam_card.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-file-pen mr-1.5 opacity-80"></i>Ujian
                    </a>

                    <a href="<?= $dash_url ?>keuangan/payments.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= in_array($current_page, ['payments.php', 'receipt.php', 'financial_report.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-wallet mr-1.5 opacity-80"></i>Keuangan
                    </a>

                    <a href="<?= $dash_url ?>bk/counseling.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= in_array($current_page, ['counseling.php', 'counseling_letter.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-scale-balanced mr-1.5 opacity-80"></i>BK
                    </a>

                    <a href="<?= $dash_url ?>pesan/messages.php" 
                       class="relative rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'messages.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-comments mr-1.5 opacity-80"></i>Pesan
                        <?php if ($unread_msg_count > 0): ?>
                            <span class="ml-1 inline-flex items-center justify-center rounded-full bg-rose-600 px-1.5 py-0.5 text-[10px] font-bold text-white">
                                <?= $unread_msg_count ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <a href="<?= $dash_url ?>akademik/calendar.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'calendar.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-calendar-alt mr-1.5 opacity-80"></i>Kalender
                    </a>

                    <a href="<?= $dash_url ?>informasi/announcements.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'announcements.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-bullhorn mr-1.5 opacity-80"></i>Pengumuman
                    </a>

                    <a href="<?= $dash_url ?>surat/requests.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= in_array($current_page, ['requests.php', 'request_print.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-envelope-open-text mr-1.5 opacity-80"></i>Surat
                    </a>

                    <a href="<?= $dash_url ?>perpustakaan/books.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= in_array($current_page, ['books.php', 'loans.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-book-bookmark mr-1.5 opacity-80"></i>Perpus
                    </a>

                    <?php if (in_array($user_role, ['administrator', 'staf'], true)): ?>
                        <a href="<?= $dash_url ?>admin/ppdb.php" 
                           class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'ppdb.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            <i class="fa-solid fa-graduation-cap mr-1.5 opacity-80"></i>PPDB
                        </a>
                    <?php endif; ?>

                    <?php if (in_array($user_role, ['guru', 'administrator'], true)): ?>
                        <a href="<?= $dash_url ?>akademik/gradebook.php" 
                           class="rounded-xl px-2.5 py-1.5 transition <?= in_array($current_page, ['gradebook.php', 'gradebook_print.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            <i class="fa-solid fa-chart-column mr-1.5 opacity-80"></i>Nilai
                        </a>
                    <?php endif; ?>

                    <a href="<?= $dash_url ?>akademik/report_card.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'report_card.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-award mr-1.5 opacity-80"></i>Rapor
                    </a>

                    <a href="<?= $dash_url ?>presensi/qr_card.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'qr_card.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-id-card mr-1.5 opacity-80"></i>Kartu
                    </a>

                    <?php if ($user_role === 'administrator'): ?>
                        <a href="<?= $dash_url ?>admin/classes.php" 
                           class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'classes.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            <i class="fa-solid fa-school mr-1.5 opacity-80"></i>Kelas
                        </a>
                        <a href="<?= $dash_url ?>admin/users.php" 
                           class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'users.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            <i class="fa-solid fa-users mr-1.5 opacity-80"></i>User
                        </a>
                        <a href="<?= $dash_url ?>admin/backup.php" 
                           class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'backup.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            <i class="fa-solid fa-database mr-1.5 opacity-80"></i>Backup
                        </a>
                        <a href="<?= $dash_url ?>admin/settings.php" 
                           class="rounded-xl px-2.5 py-1.5 transition <?= in_array($current_page, ['settings.php', 'audit_logs.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            <i class="fa-solid fa-gear mr-1.5 opacity-80"></i>Setelan
                        </a>
                    <?php endif; ?>

                    <a href="<?= $dash_url ?>profile.php" 
                       class="rounded-xl px-2.5 py-1.5 transition <?= $current_page === 'profile.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        <i class="fa-solid fa-circle-user mr-1.5 opacity-80"></i>Profil
                    </a>
                </nav>
            </div>

            <!-- User Info & Logout -->
            <div class="flex items-center gap-2 sm:gap-4 shrink-0">
                <div class="hidden text-right md:block">
                    <div class="flex items-center justify-end gap-2">
                        <span class="text-sm font-semibold text-white">
                            <?= htmlspecialchars($user_name) ?>
                        </span>
                        <span class="inline-flex items-center gap-1 rounded-lg border px-2 py-0.5 text-xs font-semibold uppercase tracking-wider <?= getRoleBadge($user_role) ?>">
                            <i class="<?= getRoleIcon($user_role) ?> text-[10px]"></i>
                            <?= htmlspecialchars(getRoleLabel($user_role)) ?>
                        </span>
                    </div>
                    <p class="text-xs text-slate-400">
                        <?= htmlspecialchars($user_email) ?>
                    </p>
                </div>

                <!-- BUG-07 fix: Logout via POST form with CSRF -->
                <form method="POST" action="<?= $dash_url ?>index.php" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin keluar?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" 
                       title="Keluar dari Akun"
                       class="inline-flex items-center gap-1.5 rounded-xl border border-red-500/20 bg-red-500/10 px-3 py-1.5 text-xs sm:text-sm font-medium text-red-400 transition hover:bg-red-500/20 hover:text-red-300 cursor-pointer">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span>Keluar</span>
                    </button>
                </form>
            </div>

        </div>

        <!-- Mobile Sub-Menu -->
        <div class="flex lg:hidden border-t border-white/5 px-3 py-2 gap-1.5 overflow-x-auto text-xs font-medium scrollbar-thin">
            <a href="<?= $dash_url ?>index.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'index.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-chart-pie mr-1"></i>Beranda
            </a>
            <a href="<?= $dash_url ?>akademik/timetable.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'timetable.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-calendar-days mr-1"></i>Jadwal
            </a>
            <a href="<?= $dash_url ?>presensi/attendance.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'attendance.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-clipboard-user mr-1"></i>Presensi
            </a>
            <?php if (in_array($user_role, ['guru', 'staf', 'administrator'], true)): ?>
                <a href="<?= $dash_url ?>presensi/scan_qr.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'scan_qr.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    <i class="fa-solid fa-qrcode mr-1"></i>Scan QR
                </a>
            <?php endif; ?>
            <a href="<?= $dash_url ?>akademik/materials.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'materials.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-book-open mr-1"></i>Materi
            </a>
            <a href="<?= $dash_url ?>akademik/assignments.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'assignments.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-list-check mr-1"></i>Tugas
            </a>
            <a href="<?= $dash_url ?>Modul-ujian/exams.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= in_array($current_page, ['exams.php', 'exam_questions.php', 'exam_take.php', 'exam_results.php']) ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-file-pen mr-1"></i>Ujian
            </a>
            <a href="<?= $dash_url ?>keuangan/payments.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= in_array($current_page, ['payments.php', 'receipt.php', 'financial_report.php']) ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-wallet mr-1"></i>Keuangan
            </a>
            <a href="<?= $dash_url ?>bk/counseling.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'counseling.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-scale-balanced mr-1"></i>BK
            </a>
            <a href="<?= $dash_url ?>pesan/messages.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'messages.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-comments mr-1"></i>Pesan <?= $unread_msg_count > 0 ? "($unread_msg_count)" : '' ?>
            </a>
            <a href="<?= $dash_url ?>akademik/calendar.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'calendar.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-calendar-alt mr-1"></i>Kalender
            </a>
            <a href="<?= $dash_url ?>informasi/announcements.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'announcements.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-bullhorn mr-1"></i>Pengumuman
            </a>
            <a href="<?= $dash_url ?>surat/requests.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= in_array($current_page, ['requests.php', 'request_print.php']) ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-envelope-open-text mr-1"></i>Surat
            </a>
            <a href="<?= $dash_url ?>perpustakaan/books.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= in_array($current_page, ['books.php', 'loans.php']) ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-book-bookmark mr-1"></i>Perpus
            </a>
            <?php if (in_array($user_role, ['administrator', 'staf'], true)): ?>
                <a href="<?= $dash_url ?>admin/ppdb.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'ppdb.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    <i class="fa-solid fa-graduation-cap mr-1"></i>PPDB
                </a>
            <?php endif; ?>
            <?php if (in_array($user_role, ['guru', 'administrator'], true)): ?>
                <a href="<?= $dash_url ?>akademik/gradebook.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'gradebook.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    <i class="fa-solid fa-chart-column mr-1"></i>Nilai
                </a>
            <?php endif; ?>
            <a href="<?= $dash_url ?>akademik/report_card.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'report_card.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400' ?>">
                <i class="fa-solid fa-award mr-1"></i>Rapor
            </a>
            <a href="<?= $dash_url ?>presensi/qr_card.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'qr_card.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400' ?>">
                <i class="fa-solid fa-id-card mr-1"></i>Kartu
            </a>
            <?php if ($user_role === 'administrator'): ?>
                <a href="<?= $dash_url ?>admin/classes.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'classes.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    <i class="fa-solid fa-school mr-1"></i>Kelas
                </a>
                <a href="<?= $dash_url ?>admin/users.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'users.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    <i class="fa-solid fa-users mr-1"></i>User
                </a>
                <a href="<?= $dash_url ?>admin/backup.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'backup.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    <i class="fa-solid fa-database mr-1"></i>Backup
                </a>
                <a href="<?= $dash_url ?>admin/settings.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= in_array($current_page, ['settings.php', 'audit_logs.php']) ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    <i class="fa-solid fa-gear mr-1"></i>Setelan
                </a>
            <?php endif; ?>
            <a href="<?= $dash_url ?>profile.php" class="px-2.5 py-1 rounded-lg whitespace-nowrap <?= $current_page === 'profile.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                <i class="fa-solid fa-circle-user mr-1"></i>Profil
            </a>
        </div>
    </header>

    <main class="flex-1 mx-auto w-full max-w-7xl px-4 sm:px-6 py-8">
