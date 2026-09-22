<?php
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = $_SESSION["user_id"];
$user_name = $_SESSION["user_name"] ?? "Pengguna";
$user_email = $_SESSION["user_email"] ?? "";
$user_role = $_SESSION["user_role"] ?? "siswa";

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
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans antialiased selection:bg-blue-600 selection:text-white">

    <!-- Top Navigation -->
    <header class="sticky top-0 z-40 border-b border-white/10 bg-slate-950/85 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 sm:px-6 py-4">
            
            <div class="flex items-center gap-8">
                <a href="<?= $dash_url ?>index.php" class="flex items-center gap-2.5 text-xl font-bold tracking-tight">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-500/30">
                        ⚡
                    </span>
                    <span>Manajemen<span class="text-blue-500">-PHP</span></span>
                </a>

                <!-- Desktop Navigation Menu -->
                <nav class="hidden md:flex items-center gap-1 text-sm font-medium">
                    <a href="<?= $dash_url ?>index.php" 
                       class="rounded-xl px-3 py-2 transition <?= $current_page === 'index.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        📊 Dashboard
                    </a>

                    <a href="<?= $dash_url ?>presensi/attendance.php" 
                       class="rounded-xl px-3 py-2 transition <?= $current_page === 'attendance.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        📅 Presensi
                    </a>

                    <a href="<?= $dash_url ?>akademik/assignments.php" 
                       class="rounded-xl px-3 py-2 transition <?= $current_page === 'assignments.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        📚 Tugas
                    </a>

                    <a href="<?= $dash_url ?>Modul-ujian/exams.php" 
                       class="rounded-xl px-3 py-2 transition <?= in_array($current_page, ['exams.php', 'exam_questions.php', 'exam_take.php', 'exam_results.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        📝 Ujian & Latihan
                    </a>

                    <a href="<?= $dash_url ?>akademik/calendar.php" 
                       class="rounded-xl px-3 py-2 transition <?= $current_page === 'calendar.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        🗓️ Kalender
                    </a>

                    <a href="<?= $dash_url ?>informasi/announcements.php" 
                       class="rounded-xl px-3 py-2 transition <?= $current_page === 'announcements.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        📢 Pengumuman
                    </a>

                    <a href="<?= $dash_url ?>surat/requests.php" 
                       class="rounded-xl px-3 py-2 transition <?= in_array($current_page, ['requests.php', 'request_print.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        📋 Layanan Surat
                    </a>

                    <?php if (in_array($user_role, ['guru', 'administrator'], true)): ?>
                        <a href="<?= $dash_url ?>akademik/gradebook.php" 
                           class="rounded-xl px-3 py-2 transition <?= $current_page === 'gradebook.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            📊 Buku Nilai
                        </a>
                    <?php endif; ?>

                    <?php if (in_array($user_role, ['siswa', 'orang_tua'], true)): ?>
                        <a href="<?= $dash_url ?>akademik/report_card.php" 
                           class="rounded-xl px-3 py-2 transition <?= $current_page === 'report_card.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            📈 Rapor
                        </a>
                    <?php endif; ?>

                    <?php if ($user_role === 'siswa'): ?>
                        <a href="<?= $dash_url ?>Modul-ujian/exam_card.php" 
                           class="rounded-xl px-3 py-2 transition <?= $current_page === 'exam_card.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            🪪 Kartu Ujian
                        </a>
                    <?php endif; ?>

                    <?php if ($user_role === 'administrator'): ?>
                        <a href="<?= $dash_url ?>admin/classes.php" 
                           class="rounded-xl px-3 py-2 transition <?= $current_page === 'classes.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            🏫 Kelas
                        </a>
                        <a href="<?= $dash_url ?>admin/users.php" 
                           class="rounded-xl px-3 py-2 transition <?= $current_page === 'users.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            👥 User
                        </a>
                        <a href="<?= $dash_url ?>admin/settings.php" 
                           class="rounded-xl px-3 py-2 transition <?= in_array($current_page, ['settings.php', 'audit_logs.php']) ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                            ⚙️ Pengaturan
                        </a>
                    <?php endif; ?>

                    <a href="<?= $dash_url ?>profile.php" 
                       class="rounded-xl px-3 py-2 transition <?= $current_page === 'profile.php' ? 'bg-white/10 text-white font-semibold' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                        👤 Profil
                    </a>
                </nav>
            </div>

            <!-- User Info & Logout -->
            <div class="flex items-center gap-3 sm:gap-4">
                <div class="hidden text-right sm:block">
                    <div class="flex items-center justify-end gap-2">
                        <span class="text-sm font-semibold text-white">
                            <?= htmlspecialchars($user_name) ?>
                        </span>
                        <span class="inline-flex items-center rounded-lg border px-2 py-0.5 text-xs font-semibold uppercase tracking-wider <?= getRoleBadge($user_role) ?>">
                            <?= htmlspecialchars(getRoleLabel($user_role)) ?>
                        </span>
                    </div>
                    <p class="text-xs text-slate-400">
                        <?= htmlspecialchars($user_email) ?>
                    </p>
                </div>

                <a href="<?= $dash_url ?>index.php?logout=1" 
                   title="Keluar dari Akun"
                   onclick="return confirm('Apakah Anda yakin ingin keluar?');"
                   class="rounded-xl border border-red-500/20 bg-red-500/10 px-3.5 py-2 text-sm font-medium text-red-400 transition hover:bg-red-500/20 hover:text-red-300">
                    Keluar 🚪
                </a>
            </div>

        </div>

        <!-- Mobile Sub-Menu -->
        <div class="flex md:hidden border-t border-white/5 px-4 py-2 gap-2 overflow-x-auto text-xs font-medium">
            <a href="<?= $dash_url ?>index.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'index.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                Dashboard
            </a>
            <a href="<?= $dash_url ?>presensi/attendance.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'attendance.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                Presensi
            </a>
            <a href="<?= $dash_url ?>akademik/assignments.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'assignments.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                Tugas
            </a>
            <a href="<?= $dash_url ?>Modul-ujian/exams.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= in_array($current_page, ['exams.php', 'exam_questions.php', 'exam_take.php', 'exam_results.php']) ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                Ujian & Latihan
            </a>
            <a href="<?= $dash_url ?>akademik/calendar.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'calendar.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                Kalender
            </a>
            <a href="<?= $dash_url ?>informasi/announcements.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'announcements.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                Pengumuman
            </a>
            <a href="<?= $dash_url ?>surat/requests.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= in_array($current_page, ['requests.php', 'request_print.php']) ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                Layanan Surat
            </a>
            <?php if (in_array($user_role, ['guru', 'administrator'], true)): ?>
                <a href="<?= $dash_url ?>akademik/gradebook.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'gradebook.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    Buku Nilai
                </a>
            <?php endif; ?>
            <?php if (in_array($user_role, ['siswa', 'orang_tua'], true)): ?>
                <a href="<?= $dash_url ?>akademik/report_card.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'report_card.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    Rapor
                </a>
            <?php endif; ?>
            <?php if ($user_role === 'siswa'): ?>
                <a href="<?= $dash_url ?>Modul-ujian/exam_card.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'exam_card.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    Kartu Ujian
                </a>
            <?php endif; ?>
            <?php if ($user_role === 'administrator'): ?>
                <a href="<?= $dash_url ?>admin/classes.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'classes.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    Kelas
                </a>
                <a href="<?= $dash_url ?>admin/users.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'users.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    User
                </a>
                <a href="<?= $dash_url ?>admin/settings.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= in_array($current_page, ['settings.php', 'audit_logs.php']) ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                    Pengaturan
                </a>
            <?php endif; ?>
            <a href="<?= $dash_url ?>profile.php" class="px-2.5 py-1.5 rounded-lg whitespace-nowrap <?= $current_page === 'profile.php' ? 'bg-white/10 text-white' : 'text-slate-400' ?>">
                Profil
            </a>
        </div>
    </header>

    <main class="flex-1 mx-auto w-full max-w-7xl px-4 sm:px-6 py-8">
