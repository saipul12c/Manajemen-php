<?php
/**
 * Komponen Navigasi Utama Publik (Unified Navbar)
 * Responsif (Desktop Pill & Mobile Drawer), deteksi role login, dan aktif indikator dinamis.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Konfigurasi path dasar ('' untuk halaman di root, '../' untuk subfolder seperti ppdb/)
$base_path = $base_path ?? '';
$nav_active = $nav_active ?? '';

// Pastikan pengaturan sekolah tersedia
if (!isset($school_info) && isset($pdo)) {
    if (function_exists('getSchoolSettings')) {
        $school_info = getSchoolSettings($pdo);
    }
}
$school_name = $school_info['school_name'] ?? 'Manajemen-PHP';

$is_logged_in = isset($_SESSION['user_id']);
$user_role = $_SESSION['user_role'] ?? '';
$user_name = $_SESSION['user_name'] ?? '';
?>

<!-- ================= GLOBAL NAVBAR ================= -->
<header class="sticky top-0 z-50 border-b border-white/10 bg-slate-950/85 backdrop-blur-md">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8 py-3.5">
        
        <!-- Brand Logo -->
        <a href="<?= $base_path ?>index.php" class="flex items-center gap-2.5 group">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-sky-500 text-white shadow-lg shadow-blue-500/25 group-hover:scale-105 transition duration-300">
                <i class="fa-solid fa-graduation-cap text-base"></i>
            </span>
            <div class="flex flex-col">
                <span class="text-base sm:text-lg font-bold tracking-tight text-white group-hover:text-blue-400 transition leading-tight">
                    <?= htmlspecialchars($school_name) ?>
                </span>
                <span class="text-[10px] text-slate-400 font-medium tracking-wide">Portal Sistem Terpadu</span>
            </div>
        </a>

        <!-- Desktop Navigation Pill -->
        <nav class="hidden lg:flex items-center gap-1 bg-white/5 border border-white/10 px-3 py-1.5 rounded-full backdrop-blur-sm shadow-inner">
            <a href="<?= $base_path ?>index.php" 
               class="px-3 py-1.5 text-xs font-semibold rounded-full transition <?= $nav_active === 'beranda' ? 'text-blue-400 bg-blue-600/20 border border-blue-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-house mr-1 text-slate-400"></i> Beranda
            </a>

            <a href="<?= $base_path ?>informasi.php" 
               class="px-3 py-1.5 text-xs font-semibold rounded-full transition <?= $nav_active === 'informasi' ? 'text-sky-400 bg-sky-600/20 border border-sky-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-bullhorn mr-1 text-sky-400"></i> Informasi
            </a>

            <a href="<?= $base_path ?>about.php" 
               class="px-3 py-1.5 text-xs font-semibold rounded-full transition <?= $nav_active === 'about' ? 'text-indigo-400 bg-indigo-600/20 border border-indigo-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-circle-info mr-1 text-indigo-400"></i> Tentang Kami
            </a>

            <a href="<?= $base_path ?>perpustakaan.php" 
               class="px-3 py-1.5 text-xs font-semibold rounded-full transition <?= $nav_active === 'perpustakaan' ? 'text-cyan-400 bg-cyan-600/20 border border-cyan-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-book-bookmark mr-1 text-cyan-400"></i> Perpustakaan
            </a>

            <a href="<?= $base_path ?>ppdb/ppdb.php" 
               class="px-3 py-1.5 text-xs font-semibold rounded-full transition <?= $nav_active === 'ppdb' ? 'text-emerald-400 bg-emerald-600/20 border border-emerald-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-user-plus mr-1 text-emerald-400"></i> PPDB Online
            </a>

            <a href="<?= $base_path ?>kontak.php" 
               class="px-3 py-1.5 text-xs font-semibold rounded-full transition <?= $nav_active === 'kontak' ? 'text-amber-400 bg-amber-600/20 border border-amber-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-envelope mr-1 text-amber-400"></i> Kontak
            </a>
        </nav>

        <!-- User Auth Buttons & Mobile Toggle -->
        <div class="flex items-center gap-2.5">
            <?php if ($is_logged_in): ?>
                <a href="<?= $base_path ?>dashboard/index.php" 
                   class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-3.5 sm:px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition">
                    <i class="fa-solid fa-gauge-high"></i>
                    <span>Dashboard (<?= htmlspecialchars(ucfirst($user_role)) ?>)</span>
                </a>
            <?php else: ?>
                <a href="<?= $base_path ?>auth/login.php" 
                   class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 px-3.5 sm:px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-lg shadow-blue-600/25 transition">
                    <i class="fa-solid fa-right-to-bracket text-xs"></i>
                    <span>Masuk</span>
                </a>
            <?php endif; ?>

            <!-- Mobile Hamburger Toggle Button -->
            <button id="btnUnifiedMobileMenu" type="button" 
                    class="lg:hidden flex h-9 w-9 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-300 hover:text-white hover:bg-white/10 transition cursor-pointer" 
                    aria-label="Buka Menu Navigasi">
                <i class="fa-solid fa-bars text-sm"></i>
            </button>
        </div>
    </div>

    <!-- Mobile Navigation Drawer -->
    <div id="unifiedMobileMenu" class="hidden lg:hidden border-t border-white/10 bg-slate-950/95 px-4 sm:px-6 py-4 space-y-1.5 backdrop-blur-xl">
        <a href="<?= $base_path ?>index.php" 
           class="flex items-center gap-2.5 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'beranda' ? 'text-blue-400 bg-blue-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
            <i class="fa-solid fa-house w-5 text-slate-400"></i> Beranda
        </a>
        <a href="<?= $base_path ?>informasi.php" 
           class="flex items-center gap-2.5 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'informasi' ? 'text-sky-400 bg-sky-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
            <i class="fa-solid fa-bullhorn w-5 text-sky-400"></i> Papan Informasi
        </a>
        <a href="<?= $base_path ?>about.php" 
           class="flex items-center gap-2.5 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'about' ? 'text-indigo-400 bg-indigo-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
            <i class="fa-solid fa-circle-info w-5 text-indigo-400"></i> Tentang Kami
        </a>
        <a href="<?= $base_path ?>perpustakaan.php" 
           class="flex items-center gap-2.5 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'perpustakaan' ? 'text-cyan-400 bg-cyan-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
            <i class="fa-solid fa-book-bookmark w-5 text-cyan-400"></i> Perpustakaan Digital
        </a>
        <a href="<?= $base_path ?>ppdb/ppdb.php" 
           class="flex items-center gap-2.5 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'ppdb' ? 'text-emerald-400 bg-emerald-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
            <i class="fa-solid fa-user-plus w-5 text-emerald-400"></i> PPDB Online
        </a>
        <a href="<?= $base_path ?>kontak.php" 
           class="flex items-center gap-2.5 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'kontak' ? 'text-amber-400 bg-amber-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
            <i class="fa-solid fa-envelope w-5 text-amber-400"></i> Kontak Bantuan
        </a>

        <div class="pt-3 border-t border-white/10">
            <?php if ($is_logged_in): ?>
                <a href="<?= $base_path ?>dashboard/index.php" 
                   class="flex items-center justify-center gap-2 rounded-xl bg-blue-600 py-2.5 text-xs font-bold text-white shadow-md shadow-blue-500/20">
                    <i class="fa-solid fa-gauge-high"></i> Ke Dashboard (<?= htmlspecialchars(ucfirst($user_role)) ?>)
                </a>
            <?php else: ?>
                <a href="<?= $base_path ?>auth/login.php" 
                   class="flex items-center justify-center gap-2 rounded-xl bg-blue-600 py-2.5 text-xs font-bold text-white shadow-md shadow-blue-500/20">
                    <i class="fa-solid fa-right-to-bracket"></i> Masuk ke Akun
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<script>
// Toggle Mobile Drawer
document.addEventListener('DOMContentLoaded', function() {
    const btnToggle = document.getElementById('btnUnifiedMobileMenu');
    const drawer = document.getElementById('unifiedMobileMenu');
    if (btnToggle && drawer) {
        btnToggle.addEventListener('click', function() {
            drawer.classList.toggle('hidden');
        });
    }
});
</script>
