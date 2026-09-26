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

// Hitung pesan kontak tamu baru (khusus admin & staf)
$unread_contact_count = 0;
if (in_array($user_role, ['administrator', 'staf'], true) && isset($pdo)) {
    try {
        $unread_contact_count = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'baru'")->fetchColumn();
    } catch (Exception $e) {}
}

// Active page indicator & base URL resolution
$current_page = basename($_SERVER['PHP_SELF']);
$parent_folder = basename(dirname($_SERVER['PHP_SELF']));
$dash_url = ($parent_folder === 'dashboard') ? '' : '../';

// Mapping judul kategori dan breadcrumb
$module_titles = [
    'index.php' => 'Dashboard Beranda',
    'timetable.php' => 'Jadwal Pelajaran',
    'attendance.php' => 'Presensi Harian',
    'attendance_report.php' => 'Rekap Presensi',
    'scan_qr.php' => 'Pemindai QR Presensi',
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
    'counseling.php' => 'Bimbingan Konseling (BK)',
    'counseling_letter.php' => 'Surat Panggilan BK',
    'messages.php' => 'Pesan & Konsultasi',
    'calendar.php' => 'Kalender Akademik',
    'announcements.php' => 'Pengumuman Resmi',
    'requests.php' => 'Layanan Persuratan',
    'request_print.php' => 'Cetak Surat Resmi',
    'books.php' => 'Perpustakaan Digital',
    'loans.php' => 'Peminjaman Buku',
    'scan.php' => 'Scan Barcode Perpus',
    'visitors.php' => 'Buku Tamu Perpus',
    'print_labels.php' => 'Cetak Barcode Buku',
    'clearance.php' => 'Bebas Pustaka',
    'reservations.php' => 'Reservasi Buku',
    'ppdb.php' => 'PPDB Online Siswa Baru',
    'contact_messages.php' => 'Kotak Masuk Pesan Tamu',
    'gradebook.php' => 'Buku Nilai Guru',
    'gradebook_print.php' => 'Cetak Ledger Nilai',
    'report_card.php' => 'E-Rapor Digital',
    'qr_card.php' => 'Kartu Pelajar Digital',
    'classes.php' => 'Manajemen Kelas & Rombel',
    'users.php' => 'Manajemen Pengguna',
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
    
    <!-- Scanner & QR Libs -->
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
        }

        /* Custom Scrollbar */
        .custom-scrollbar::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.12);
            border-radius: 9999px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        /* Desktop Sidebar Collapsed Transition */
        @media (min-width: 1024px) {
            body.sidebar-desktop-collapsed #sidebar {
                margin-left: -18rem;
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
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased selection:bg-blue-600 selection:text-white">

    <!-- Mobile Sidebar Backdrop Overlay -->
    <div id="sidebarBackdrop" onclick="closeSidebar()" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm z-40 lg:hidden hidden transition-opacity duration-300 opacity-0 pointer-events-none no-print"></div>

    <div class="min-h-screen flex">

        <!-- ============================================================== -->
        <!-- SIDEBAR / SLIDEBAR UTAMA -->
        <!-- ============================================================== -->
        <aside id="sidebar" class="fixed lg:sticky top-0 left-0 z-50 h-screen w-72 bg-slate-900/95 backdrop-blur-xl border-r border-white/10 flex flex-col transition-all duration-300 ease-in-out shrink-0 -translate-x-full lg:translate-x-0 no-print shadow-2xl lg:shadow-none">
            
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
                
                <!-- Close Button (Mobile Only) -->
                <button type="button" onclick="closeSidebar()" class="lg:hidden p-2 rounded-xl text-slate-400 hover:text-white hover:bg-white/10 transition" title="Tutup Menu">
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
            <nav id="sidebarNavList" class="flex-1 overflow-y-auto px-3 py-3 space-y-5 custom-scrollbar text-xs font-medium">

                <!-- 1. KELOMPOK: NAVIGASI UTAMA -->
                <div class="space-y-1">
                    <p data-menu-category class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                        Menu Utama
                    </p>

                    <a href="<?= $dash_url ?>index.php" data-menu-item="beranda dashboard home" <?= $current_page === 'index.php' ? 'aria-current="page"' : '' ?>
                       class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'index.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'index.php' ? 'bg-blue-500/20 text-blue-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                <i class="fa-solid fa-chart-pie text-xs"></i>
                            </span>
                            <span>Beranda Dashboard</span>
                        </div>
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

                <!-- 2. KELOMPOK: PEMBELAJARAN & KBM -->
                <div class="space-y-1">
                    <p data-menu-category class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                        Pembelajaran & KBM
                    </p>

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
                </div>

                <!-- 3. KELOMPOK: ASESMEN & UJIAN CBT -->
                <div class="space-y-1">
                    <p data-menu-category class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                        Ujian & Asesmen
                    </p>

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

                <!-- 4. KELOMPOK: LAYANAN & FASILITAS SEKOLAH -->
                <div class="space-y-1">
                    <p data-menu-category class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                        Layanan Sekolah
                    </p>

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
                </div>

                <!-- 5. KELOMPOK: ADMINISTRASI & SISTEM (ADMIN / STAF) -->
                <?php if (in_array($user_role, ['administrator', 'staf'], true)): ?>
                    <div class="space-y-1">
                        <p data-menu-category class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                            Administrasi & Sistem
                        </p>

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

                        <a href="<?= $dash_url ?>admin/classes.php" data-menu-item="kelas rombel rombongan belajar tingkat wali kelas" <?= $current_page === 'classes.php' ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'classes.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'classes.php' ? 'bg-indigo-500/20 text-indigo-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-school text-xs"></i>
                                </span>
                                <span>Manajemen Kelas</span>
                            </div>
                        </a>

                        <a href="<?= $dash_url ?>sarpras/inventory.php" data-menu-item="sarpras inventaris aset barang sarana prasarana peminjaman ruangan fasilitas alat" <?= in_array($current_page, ['inventory.php']) ? 'aria-current="page"' : '' ?>
                           class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= in_array($current_page, ['inventory.php']) ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                            <div class="flex items-center gap-2.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= in_array($current_page, ['inventory.php']) ? 'bg-amber-500/20 text-amber-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                    <i class="fa-solid fa-boxes-stacked text-xs"></i>
                                </span>
                                <span>Inventaris Sarpras</span>
                            </div>
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

                        <?php if ($user_role === 'administrator'): ?>
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
                <?php endif; ?>

                <!-- 6. KELOMPOK: PENGGUNA -->
                <div class="space-y-1">
                    <p data-menu-category class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                        Akun Saya
                    </p>

                    <a href="<?= $dash_url ?>profile.php" data-menu-item="profil akun saya ganti password foto data diri" <?= $current_page === 'profile.php' ? 'aria-current="page"' : '' ?>
                       class="group flex items-center justify-between rounded-xl px-3 py-2 transition-all <?= $current_page === 'profile.php' ? 'bg-blue-600/15 text-blue-400 font-semibold border-l-[3px] border-blue-500 shadow-sm shadow-blue-500/10' : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border-l-[3px] border-transparent' ?>">
                        <div class="flex items-center gap-2.5">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $current_page === 'profile.php' ? 'bg-blue-500/20 text-blue-400' : 'bg-white/5 text-slate-400 group-hover:text-slate-200' ?>">
                                <i class="fa-solid fa-circle-user text-xs"></i>
                            </span>
                            <span>Profil Pengguna</span>
                        </div>
                    </a>
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
                    Sistem Terkoneksi • v2.4
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
