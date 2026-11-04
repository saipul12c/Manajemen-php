        </main>

        <!-- Global Dashboard Footer -->
        <footer class="mt-auto border-t border-white/10 bg-slate-950/80 backdrop-blur py-4 px-4 sm:px-6 lg:px-8 text-xs text-slate-500 no-print">
            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-5 w-5 items-center justify-center rounded-lg bg-blue-500/10 text-blue-400 text-xs">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </span>
                    <p>© <?= date('Y') ?> Manajemen-PHP • Sistem Informasi Manajemen Sekolah Terpadu</p>
                </div>
                <div class="flex items-center gap-4 text-slate-400">
                    <span>Status Sesi: <span class="text-emerald-400 font-medium">● Terhubung</span></span>
                    <span class="text-white/20">|</span>
                    <a href="<?= $dash_url ?>profile.php" class="hover:text-blue-400 transition">Profil Saya</a>
                </div>
            </div>
        </footer>

    </div><!-- End #mainContentWrapper -->
</div><!-- End Layout Wrapper -->

<!-- Sidebar & Global Layout Interactive Script -->
<script>
    // Elements
    const sidebar = document.getElementById('sidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const searchInput = document.getElementById('sidebarMenuSearch');
    const clearSearchBtn = document.getElementById('clearMenuSearch');

    // Check if desktop viewport
    function isDesktopView() {
        return window.innerWidth >= 1024;
    }

    // Open Sidebar (Desktop or Mobile)
    function openSidebar() {
        if (isDesktopView()) {
            document.body.classList.remove('sidebar-desktop-collapsed');
            document.documentElement.classList.remove('sidebar-desktop-collapsed');
            try { localStorage.setItem('sidebar_desktop_collapsed', 'false'); } catch (e) {}
        } else {
            if (!sidebar || !sidebarBackdrop) return;
            sidebar.classList.remove('-translate-x-full');
            sidebarBackdrop.classList.remove('hidden', 'pointer-events-none');
            requestAnimationFrame(() => {
                sidebarBackdrop.classList.remove('opacity-0');
                sidebarBackdrop.classList.add('opacity-100');
            });
            document.body.classList.add('overflow-hidden', 'lg:overflow-auto');
        }
    }

    // Close Sidebar (Desktop or Mobile)
    function closeSidebar() {
        if (isDesktopView()) {
            document.body.classList.add('sidebar-desktop-collapsed');
            document.documentElement.classList.add('sidebar-desktop-collapsed');
            try { localStorage.setItem('sidebar_desktop_collapsed', 'true'); } catch (e) {}
        } else {
            if (!sidebar || !sidebarBackdrop) return;
            sidebar.classList.add('-translate-x-full');
            sidebarBackdrop.classList.remove('opacity-100');
            sidebarBackdrop.classList.add('opacity-0');
            setTimeout(() => {
                sidebarBackdrop.classList.add('hidden', 'pointer-events-none');
                document.body.classList.remove('overflow-hidden', 'lg:overflow-auto');
            }, 300);
        }
    }

    // Toggle Sidebar (Universal for all devices)
    function toggleSidebar() {
        if (isDesktopView()) {
            toggleDesktopSidebar();
        } else {
            if (sidebar && sidebar.classList.contains('-translate-x-full')) {
                openSidebar();
            } else {
                closeSidebar();
            }
        }
    }

    // Toggle Desktop Sidebar (Collapse or Expand)
    function toggleDesktopSidebar() {
        if (!isDesktopView()) {
            toggleSidebar();
            return;
        }
        const isCollapsed = document.body.classList.toggle('sidebar-desktop-collapsed');
        document.documentElement.classList.toggle('sidebar-desktop-collapsed', isCollapsed);
        try {
            localStorage.setItem('sidebar_desktop_collapsed', isCollapsed ? 'true' : 'false');
        } catch (e) {}
    }

    // Restore desktop collapsed state
    try {
        if (localStorage.getItem('sidebar_desktop_collapsed') === 'true' && isDesktopView()) {
            document.body.classList.add('sidebar-desktop-collapsed');
            document.documentElement.classList.add('sidebar-desktop-collapsed');
        }
    } catch (e) {}

    // Keyboard Shortcuts
    document.addEventListener('keydown', (e) => {
        // Esc to close sidebar on any screen
        if (e.key === 'Escape') {
            closeSidebar();
        }
        // Ctrl+B or Cmd+B to toggle sidebar on any screen
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
            e.preventDefault();
            toggleSidebar();
        }
        // Ctrl+K or / to focus search input in sidebar
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openSidebar();
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });

    // ==============================================================
    // SIDEBAR ACCORDION / MENU GROUP COLLAPSE LOGIC
    // ==============================================================

    // Toggle single menu group
    function toggleMenuGroup(groupId, forceOpen = null) {
        const toggleBtn = document.querySelector(`[data-menu-toggle="${groupId}"]`);
        const container = document.querySelector(`[data-menu-items-container="${groupId}"]`);
        const chevron = document.querySelector(`[data-menu-chevron="${groupId}"]`);

        if (!container) return;

        const isCurrentlyHidden = container.classList.contains('hidden');
        const shouldOpen = (forceOpen !== null) ? forceOpen : isCurrentlyHidden;

        if (shouldOpen) {
            container.classList.remove('hidden');
            if (chevron) chevron.classList.remove('-rotate-90');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
        } else {
            container.classList.add('hidden');
            if (chevron) chevron.classList.add('-rotate-90');
            if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
        }

        // Simpan preferensi buka-tutup di localStorage
        try {
            const saved = JSON.parse(localStorage.getItem('sidebar_menu_collapsed_groups') || '{}');
            saved[groupId] = !shouldOpen; // true jika diciutkan
            localStorage.setItem('sidebar_menu_collapsed_groups', JSON.stringify(saved));
        } catch (e) {}

        updateToggleAllButtonState();
    }

    // Toggle semua grup (Buka Semua / Ciutkan)
    function toggleAllMenuGroups() {
        const containers = document.querySelectorAll('#sidebarNavList [data-menu-items-container]');
        if (containers.length === 0) return;

        let allOpen = true;
        containers.forEach(c => {
            if (c.classList.contains('hidden')) allOpen = false;
        });

        const targetOpen = !allOpen;
        containers.forEach(c => {
            const groupId = c.getAttribute('data-menu-items-container');
            if (groupId) {
                toggleMenuGroup(groupId, targetOpen);
            }
        });
    }

    // Perbarui label tombol Toggle All
    function updateToggleAllButtonState() {
        const label = document.getElementById('labelToggleAllGroups');
        const icon = document.getElementById('iconToggleAllGroups');
        const containers = document.querySelectorAll('#sidebarNavList [data-menu-items-container]');
        if (!label || !icon || containers.length === 0) return;

        let allOpen = true;
        containers.forEach(c => {
            if (c.classList.contains('hidden')) allOpen = false;
        });

        if (allOpen) {
            label.textContent = 'Ciutkan';
            icon.className = 'fa-solid fa-angles-up text-[8px]';
        } else {
            label.textContent = 'Buka Semua';
            icon.className = 'fa-solid fa-angles-down text-[8px]';
        }
    }

    // Pulihkan status buka-tutup grup tersimpan
    function restoreMenuGroupPreferences() {
        try {
            const saved = JSON.parse(localStorage.getItem('sidebar_menu_collapsed_groups') || '{}');
            const activeLink = document.querySelector('#sidebar [aria-current="page"]');
            const activeGroupContainer = activeLink ? activeLink.closest('[data-menu-group]') : null;
            const activeGroupId = activeGroupContainer ? activeGroupContainer.getAttribute('data-menu-group') : null;

            document.querySelectorAll('#sidebarNavList [data-menu-items-container]').forEach(c => {
                const groupId = c.getAttribute('data-menu-items-container');
                if (!groupId) return;

                // Grup yang sedang aktif harus selalu terbuka
                if (groupId === activeGroupId) {
                    toggleMenuGroup(groupId, true);
                    return;
                }

                // Terapkan preferensi jika pengguna pernah mengubahnya
                if (groupId in saved) {
                    toggleMenuGroup(groupId, !saved[groupId]);
                }
            });
        } catch (e) {}
        updateToggleAllButtonState();
    }

    // Clear Search Input
    function clearSidebarSearch() {
        if (searchInput) {
            searchInput.value = '';
            filterSidebarMenu('');
            searchInput.focus();
        }
    }

    // Live Filter for Sidebar Menu Items
    function filterSidebarMenu(query) {
        const q = query.trim().toLowerCase();
        const navItems = document.querySelectorAll('#sidebarNavList [data-menu-item]');
        const menuGroups = document.querySelectorAll('#sidebarNavList [data-menu-group]');

        if (clearSearchBtn) {
            clearSearchBtn.classList.toggle('hidden', q === '');
        }

        if (q === '') {
            navItems.forEach(el => el.classList.remove('hidden'));
            menuGroups.forEach(el => el.classList.remove('hidden'));
            restoreMenuGroupPreferences();
            return;
        }

        // Filter item berdasarkan kata kunci
        navItems.forEach(el => {
            const keywords = (el.getAttribute('data-menu-item') || el.innerText).toLowerCase();
            if (keywords.includes(q)) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        });

        // Buka grup otomatis jika ada item anak yang cocok dan sembunyikan grup yang tidak cocok
        menuGroups.forEach(group => {
            const visibleChildren = group.querySelectorAll('[data-menu-item]:not(.hidden)');
            const container = group.querySelector('[data-menu-items-container]');
            const chevron = group.querySelector('[data-menu-chevron]');

            if (visibleChildren.length === 0) {
                group.classList.add('hidden');
            } else {
                group.classList.remove('hidden');
                if (container) container.classList.remove('hidden');
                if (chevron) chevron.classList.remove('-rotate-90');
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            filterSidebarMenu(e.target.value);
        });
    }

    // Inisialisasi saat DOM siap
    window.addEventListener('DOMContentLoaded', () => {
        restoreMenuGroupPreferences();
        const activeLink = document.querySelector('#sidebar [aria-current="page"]');
        if (activeLink) {
            activeLink.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    });
</script>

<!-- ============================================================== -->
<!-- PWA SERVICE WORKER & FLOATING INSTALL PROMPT -->
<!-- ============================================================== -->
<?php
$is_pwa_target = in_array($parent_folder, ['Modul-ujian', 'presensi', 'perpustakaan'], true);
$pwa_app_names = [
    'Modul-ujian' => ['name' => 'CBT Ujian Sekolah', 'color' => 'from-blue-600 to-indigo-600', 'border' => 'border-blue-500/40', 'icon' => 'fa-file-pen text-blue-400'],
    'presensi' => ['name' => 'Presensi Siswa & Guru', 'color' => 'from-emerald-600 to-teal-600', 'border' => 'border-emerald-500/40', 'icon' => 'fa-qrcode text-emerald-400'],
    'perpustakaan' => ['name' => 'E-Perpustakaan Digital', 'color' => 'from-amber-600 to-yellow-600', 'border' => 'border-amber-500/40', 'icon' => 'fa-book-bookmark text-amber-400']
];
$pwa_info = $pwa_app_names[$parent_folder] ?? null;
?>

<?php if ($is_pwa_target && $pwa_info): ?>
<!-- Floating Install Banner -->
<div id="pwaInstallBanner" class="hidden fixed bottom-5 right-5 z-50 max-w-sm w-[calc(100vw-2.5rem)] rounded-3xl border <?= $pwa_info['border'] ?> bg-slate-900/95 backdrop-blur-xl p-4 shadow-2xl animate-in slide-in-from-bottom duration-300">
    <div class="flex items-start gap-3">
        <div class="h-10 w-10 rounded-2xl bg-gradient-to-tr <?= $pwa_info['color'] ?> flex items-center justify-center text-white shrink-0 shadow-lg">
            <i class="fa-solid <?= $pwa_info['icon'] ?> text-base"></i>
        </div>
        <div class="min-w-0 flex-1">
            <h4 class="text-xs font-bold text-white flex items-center gap-1.5">
                Pasang Aplikasi Mandiri
                <span class="rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 px-1 py-0.2 text-[8px] font-extrabold uppercase">PWA</span>
            </h4>
            <p class="text-[11px] text-slate-300 mt-0.5 leading-relaxed">
                Pasang <strong><?= htmlspecialchars($pwa_info['name']) ?></strong> langsung di layar beranda HP / Laptop Anda.
            </p>
            <div class="flex items-center gap-2 mt-3">
                <button type="button" id="btnPwaInstallAction" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r <?= $pwa_info['color'] ?> px-3.5 py-1.5 text-xs font-bold text-white shadow-md hover:brightness-110 transition cursor-pointer">
                    <i class="fa-solid fa-download text-[11px]"></i> Pasang Sekarang
                </button>
                <button type="button" onclick="dismissPwaBanner()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-medium text-slate-400 hover:text-white transition cursor-pointer">
                    Nanti
                </button>
            </div>
        </div>
        <button type="button" onclick="dismissPwaBanner()" class="text-slate-500 hover:text-slate-300 transition text-xs -mt-1 -mr-1 p-1">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
</div>
<?php endif; ?>

<script>
    // Register Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('<?= $dash_url ?>sw.js').then((reg) => {
                console.log('PWA Service Worker registered:', reg.scope);
            }).catch((err) => {
                console.warn('PWA SW registration failed:', err);
            });
        });
    }

    // PWA Install Prompt Listener
    let deferredPrompt = null;
    const pwaBanner = document.getElementById('pwaInstallBanner');
    const btnPwaInstallAction = document.getElementById('btnPwaInstallAction');

    // Cek apakah sudah dalam mode standalone / terpasang
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                         window.matchMedia('(display-mode: fullscreen)').matches || 
                         window.navigator.standalone === true;

    window.addEventListener('beforeinstallprompt', (e) => {
        // Prevent default browser mini-infobar
        e.preventDefault();
        deferredPrompt = e;

        // Tampilkan banner kustom jika belum dipasang dan belum ditutup di sesi ini
        if (pwaBanner && !isStandalone && sessionStorage.getItem('pwa_banner_dismissed') !== 'true') {
            pwaBanner.classList.remove('hidden');
        }
    });

    if (btnPwaInstallAction) {
        btnPwaInstallAction.addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                deferredPrompt = null;
                if (pwaBanner) pwaBanner.classList.add('hidden');
            } else {
                alert('Untuk memasang di perangkat ini, klik menu Titik Tiga (⋮) di browser Anda lalu pilih "Instal Aplikasi" atau "Tambahkan ke Layar Utama".');
            }
        });
    }

    function dismissPwaBanner() {
        if (pwaBanner) {
            pwaBanner.classList.add('hidden');
            sessionStorage.setItem('pwa_banner_dismissed', 'true');
        }
    }
</script>

</body>
</html>
