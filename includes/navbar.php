<?php
/**
 * Komponen Navigasi Utama Publik (Unified Navbar)
 * Responsif (Desktop Pill & Mobile Drawer), Dropdown Layanan, deteksi role login, dan aktif indikator dinamis.
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

$is_service_active = in_array($nav_active, ['layanan', 'perpustakaan', 'ppdb', 'apps'], true);
?>

<!-- Global Slim Modern Scrollbar Style -->
<style>
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
</style>

<!-- ================= GLOBAL NAVBAR ================= -->
<header class="sticky top-0 z-50 border-b border-white/[0.08] bg-slate-950/85 backdrop-blur-md transition-all">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8 py-3">
        
        <!-- Brand Logo -->
        <a href="<?= $base_path ?>index.php" class="flex items-center gap-3 group shrink-0">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-sky-500 text-white shadow-lg shadow-blue-500/20 group-hover:scale-105 group-hover:shadow-blue-500/35 transition duration-300">
                <i class="fa-solid fa-graduation-cap text-base"></i>
            </span>
            <div class="flex flex-col">
                <span class="text-sm sm:text-base font-bold tracking-tight text-white group-hover:text-blue-400 transition leading-tight truncate max-w-[170px] sm:max-w-[240px] lg:max-w-[280px]">
                    <?= htmlspecialchars($school_name) ?>
                </span>
                <span class="text-[10px] text-slate-400 font-medium tracking-wide">Portal Sistem Terpadu</span>
            </div>
        </a>

        <!-- Desktop Navigation Pill (Rapi & Seimbang) -->
        <nav class="hidden lg:flex items-center gap-1 bg-white/[0.04] border border-white/10 px-2.5 py-1 rounded-full backdrop-blur-md shadow-inner">
            <!-- Beranda -->
            <a href="<?= $base_path ?>index.php" 
               class="px-3.5 py-1.5 text-xs font-semibold rounded-full transition-all duration-200 <?= $nav_active === 'beranda' ? 'text-blue-400 bg-blue-600/20 border border-blue-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-house mr-1.5 text-xs <?= $nav_active === 'beranda' ? 'text-blue-400' : 'text-slate-400' ?>"></i>Beranda
            </a>

            <!-- Informasi -->
            <a href="<?= $base_path ?>informasi.php" 
               class="px-3.5 py-1.5 text-xs font-semibold rounded-full transition-all duration-200 <?= $nav_active === 'informasi' ? 'text-sky-400 bg-sky-600/20 border border-sky-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-bullhorn mr-1.5 text-xs <?= $nav_active === 'informasi' ? 'text-sky-400' : 'text-slate-400' ?>"></i>Informasi
            </a>

            <!-- Berita -->
            <a href="<?= $base_path ?>berita.php" 
               class="px-3.5 py-1.5 text-xs font-semibold rounded-full transition-all duration-200 <?= $nav_active === 'berita' ? 'text-blue-400 bg-blue-600/20 border border-blue-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-newspaper mr-1.5 text-xs <?= $nav_active === 'berita' ? 'text-blue-400' : 'text-slate-400' ?>"></i>Berita
            </a>

            <!-- Dropdown Layanan & Fitur Unggulan -->
            <div class="relative group" id="navServicesDropdown">
                <button type="button" 
                        class="px-3.5 py-1.5 text-xs font-semibold rounded-full transition-all duration-200 flex items-center gap-1.5 cursor-pointer <?= $is_service_active ? 'text-cyan-400 bg-cyan-600/20 border border-cyan-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>"
                        aria-expanded="false">
                    <i class="fa-solid fa-shapes mr-0.5 text-xs <?= $is_service_active ? 'text-cyan-400' : 'text-slate-400' ?>"></i>
                    <span>Layanan</span>
                    <i class="fa-solid fa-chevron-down text-[9px] text-slate-400 group-hover:rotate-180 transition-transform duration-200"></i>
                </button>

                <!-- Dropdown Menu Box -->
                <div class="absolute left-1/2 -translate-x-1/2 top-full pt-2 opacity-0 invisible translate-y-1 group-hover:opacity-100 group-hover:visible group-hover:translate-y-0 transition-all duration-200 z-50 pointer-events-none group-hover:pointer-events-auto">
                    <div class="w-72 rounded-2xl border border-white/10 bg-slate-900/95 p-2 shadow-2xl backdrop-blur-xl space-y-1">
                        
                        <!-- PPDB Online -->
                        <a href="<?= $base_path ?>ppdb/ppdb.php" 
                           class="flex items-start gap-3 rounded-xl p-2.5 transition <?= $nav_active === 'ppdb' ? 'bg-emerald-500/15 border border-emerald-500/30' : 'hover:bg-white/5' ?>">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/20 text-emerald-400">
                                <i class="fa-solid fa-user-plus text-xs"></i>
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-1">
                                    <span class="text-xs font-bold text-white">PPDB Online</span>
                                    <span class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Buka</span>
                                </div>
                                <p class="text-[11px] text-slate-400 truncate">Penerimaan peserta didik baru</p>
                            </div>
                        </a>

                        <!-- Perpustakaan Digital -->
                        <a href="<?= $base_path ?>perpustakaan.php" 
                           class="flex items-start gap-3 rounded-xl p-2.5 transition <?= $nav_active === 'perpustakaan' ? 'bg-cyan-500/15 border border-cyan-500/30' : 'hover:bg-white/5' ?>">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-cyan-500/20 text-cyan-400">
                                <i class="fa-solid fa-book-bookmark text-xs"></i>
                            </span>
                            <div class="flex-1 min-w-0">
                                <span class="text-xs font-bold text-white block">Perpustakaan Digital</span>
                                <p class="text-[11px] text-slate-400 truncate">Katalog buku fisik & e-book</p>
                            </div>
                        </a>

                        <!-- Unduh Aplikasi -->
                        <a href="<?= $base_path ?>apps.php" 
                           class="flex items-start gap-3 rounded-xl p-2.5 transition <?= $nav_active === 'apps' ? 'bg-teal-500/15 border border-teal-500/30' : 'hover:bg-white/5' ?>">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-teal-500/20 text-teal-400">
                                <i class="fa-solid fa-mobile-screen-button text-xs"></i>
                            </span>
                            <div class="flex-1 min-w-0">
                                <span class="text-xs font-bold text-white block">Aplikasi Sekolah</span>
                                <p class="text-[11px] text-slate-400 truncate">Aplikasi mobile & portal siswa</p>
                            </div>
                        </a>

                    </div>
                </div>
            </div>

            <!-- Tentang Kami -->
            <a href="<?= $base_path ?>about.php" 
               class="px-3.5 py-1.5 text-xs font-semibold rounded-full transition-all duration-200 <?= $nav_active === 'about' ? 'text-indigo-400 bg-indigo-600/20 border border-indigo-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-circle-info mr-1.5 text-xs <?= $nav_active === 'about' ? 'text-indigo-400' : 'text-slate-400' ?>"></i>Tentang
            </a>

            <!-- Kontak -->
            <a href="<?= $base_path ?>kontak.php" 
               class="px-3.5 py-1.5 text-xs font-semibold rounded-full transition-all duration-200 <?= $nav_active === 'kontak' ? 'text-amber-400 bg-amber-600/20 border border-amber-500/30 shadow-sm' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                <i class="fa-solid fa-envelope mr-1.5 text-xs <?= $nav_active === 'kontak' ? 'text-amber-400' : 'text-slate-400' ?>"></i>Kontak
            </a>

            <!-- Live Chat (Sebelum & Sesudah Login) -->
            <button type="button" 
                    onclick="if(window.openSchoolLiveChat) window.openSchoolLiveChat();" 
                    class="px-3 py-1.5 text-xs font-semibold rounded-full text-teal-400 bg-teal-500/10 hover:bg-teal-500/20 border border-teal-500/30 transition-all duration-200 cursor-pointer flex items-center gap-1.5 shadow-sm">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                <i class="fa-solid fa-headset text-xs"></i>
                <span>Live Chat</span>
            </button>
        </nav>

        <!-- User Auth Buttons & Mobile Toggle (Sisi Kanan) -->
        <div class="flex items-center gap-2.5">
            <?php if ($is_logged_in): ?>
                <a href="<?= $base_path ?>dashboard/index.php" 
                   class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 px-4 py-2 text-xs sm:text-sm font-semibold text-white shadow-lg shadow-blue-600/20 transition">
                    <i class="fa-solid fa-gauge-high text-xs"></i>
                    <span>Dashboard (<?= htmlspecialchars(ucfirst($user_role)) ?>)</span>
                </a>
            <?php else: ?>
                <a href="<?= $base_path ?>auth/login.php" 
                   class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 via-indigo-600 to-blue-500 hover:from-blue-500 hover:to-indigo-500 px-4 sm:px-5 py-2 text-xs sm:text-sm font-bold text-white shadow-lg shadow-blue-600/25 transition duration-200 group">
                    <i class="fa-solid fa-right-to-bracket text-xs transition-transform group-hover:translate-x-0.5"></i>
                    <span>Masuk</span>
                </a>
            <?php endif; ?>

            <!-- Mobile Hamburger Toggle Button -->
            <button id="btnUnifiedMobileMenu" type="button" 
                    class="lg:hidden flex h-10 w-10 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-300 hover:text-white hover:bg-white/10 transition cursor-pointer" 
                    aria-label="Buka Menu Navigasi"
                    aria-expanded="false">
                <i id="iconUnifiedMobileMenu" class="fa-solid fa-bars text-base"></i>
            </button>
        </div>
    </div>

    <!-- Mobile Navigation Drawer -->
    <div id="unifiedMobileMenu" class="hidden lg:hidden border-t border-white/10 bg-slate-950/95 px-4 sm:px-6 py-4 space-y-4 backdrop-blur-xl">
        <!-- Grup Navigasi Utama -->
        <div class="space-y-1">
            <p class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">Navigasi Utama</p>
            <a href="<?= $base_path ?>index.php" 
               class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'beranda' ? 'text-blue-400 bg-blue-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                <i class="fa-solid fa-house w-4 text-center text-slate-400"></i> Beranda
            </a>
            <a href="<?= $base_path ?>informasi.php" 
               class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'informasi' ? 'text-sky-400 bg-sky-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                <i class="fa-solid fa-bullhorn w-4 text-center text-sky-400"></i> Papan Informasi
            </a>
            <a href="<?= $base_path ?>berita.php" 
               class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'berita' ? 'text-blue-400 bg-blue-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                <i class="fa-solid fa-newspaper w-4 text-center text-blue-400"></i> Berita Sekolah
            </a>
            <a href="<?= $base_path ?>about.php" 
               class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'about' ? 'text-indigo-400 bg-indigo-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                <i class="fa-solid fa-circle-info w-4 text-center text-indigo-400"></i> Tentang Kami
            </a>
            <a href="<?= $base_path ?>kontak.php" 
               class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'kontak' ? 'text-amber-400 bg-amber-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                <i class="fa-solid fa-envelope w-4 text-center text-amber-400"></i> Kontak Bantuan
            </a>
            <button type="button" 
                    onclick="if(window.openSchoolLiveChat) { window.openSchoolLiveChat(); document.getElementById('unifiedMobileMenu').classList.add('hidden'); }" 
                    class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium rounded-xl text-teal-300 hover:text-white bg-teal-500/10 hover:bg-teal-500/20 border border-teal-500/20 transition cursor-pointer">
                <span class="flex items-center gap-3">
                    <i class="fa-solid fa-headset w-4 text-center text-teal-400"></i> Live Chat Layanan
                </span>
                <span class="text-[10px] font-bold text-emerald-400 flex items-center gap-1">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Online
                </span>
            </button>
        </div>

        <!-- Grup Layanan & Fasilitas Sekolah -->
        <div class="space-y-1 pt-2 border-t border-white/5">
            <p class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400">Layanan & Fasilitas</p>
            <a href="<?= $base_path ?>ppdb/ppdb.php" 
               class="flex items-center justify-between px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'ppdb' ? 'text-emerald-400 bg-emerald-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                <span class="flex items-center gap-3">
                    <i class="fa-solid fa-user-plus w-4 text-center text-emerald-400"></i> PPDB Online
                </span>
                <span class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Buka</span>
            </a>
            <a href="<?= $base_path ?>perpustakaan.php" 
               class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'perpustakaan' ? 'text-cyan-400 bg-cyan-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                <i class="fa-solid fa-book-bookmark w-4 text-center text-cyan-400"></i> Perpustakaan Digital
            </a>
            <a href="<?= $base_path ?>apps.php" 
               class="flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-xl transition <?= $nav_active === 'apps' ? 'text-teal-400 bg-teal-600/10 font-bold' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                <i class="fa-solid fa-mobile-screen-button w-4 text-center text-teal-400"></i> Unduh Aplikasi Sekolah
            </a>
        </div>

        <!-- Tombol Aksi Autentikasi -->
        <div class="pt-3 border-t border-white/10">
            <?php if ($is_logged_in): ?>
                <a href="<?= $base_path ?>dashboard/index.php" 
                   class="flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 py-3 text-sm font-bold text-white shadow-lg shadow-blue-500/25">
                    <i class="fa-solid fa-gauge-high"></i> Ke Dashboard (<?= htmlspecialchars(ucfirst($user_role)) ?>)
                </a>
            <?php else: ?>
                <a href="<?= $base_path ?>auth/login.php" 
                   class="flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 py-3 text-sm font-bold text-white shadow-lg shadow-blue-500/25">
                    <i class="fa-solid fa-right-to-bracket"></i> Masuk ke Akun Portal
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>

<script>
// Toggle Mobile Drawer & Ubah Ikon Hamburger/Close
document.addEventListener('DOMContentLoaded', function() {
    const btnToggle = document.getElementById('btnUnifiedMobileMenu');
    const drawer = document.getElementById('unifiedMobileMenu');
    const iconToggle = document.getElementById('iconUnifiedMobileMenu');

    if (btnToggle && drawer) {
        btnToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            const isHidden = drawer.classList.toggle('hidden');
            btnToggle.setAttribute('aria-expanded', !isHidden);
            
            if (iconToggle) {
                if (isHidden) {
                    iconToggle.classList.remove('fa-xmark');
                    iconToggle.classList.add('fa-bars');
                } else {
                    iconToggle.classList.remove('fa-bars');
                    iconToggle.classList.add('fa-xmark');
                }
            }
        });

        // Tutup drawer jika mengklik di luar area navbar
        document.addEventListener('click', function(e) {
            if (!drawer.classList.contains('hidden') && !drawer.contains(e.target) && !btnToggle.contains(e.target)) {
                drawer.classList.add('hidden');
                btnToggle.setAttribute('aria-expanded', 'false');
                if (iconToggle) {
                    iconToggle.classList.remove('fa-xmark');
                    iconToggle.classList.add('fa-bars');
                }
            }
        });

        // Tutup drawer jika tombol ESC ditekan
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !drawer.classList.contains('hidden')) {
                drawer.classList.add('hidden');
                btnToggle.setAttribute('aria-expanded', 'false');
                if (iconToggle) {
                    iconToggle.classList.remove('fa-xmark');
                    iconToggle.classList.add('fa-bars');
                }
            }
        });
    }
});
</script>
