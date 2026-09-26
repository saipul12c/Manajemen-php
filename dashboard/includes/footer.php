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

    // Open Mobile Sidebar
    function openSidebar() {
        if (!sidebar || !sidebarBackdrop) return;
        sidebar.classList.remove('-translate-x-full');
        sidebarBackdrop.classList.remove('hidden', 'pointer-events-none');
        requestAnimationFrame(() => {
            sidebarBackdrop.classList.remove('opacity-0');
            sidebarBackdrop.classList.add('opacity-100');
        });
        document.body.classList.add('overflow-hidden', 'lg:overflow-auto');
    }

    // Close Mobile Sidebar
    function closeSidebar() {
        if (!sidebar || !sidebarBackdrop) return;
        sidebar.classList.add('-translate-x-full');
        sidebarBackdrop.classList.remove('opacity-100');
        sidebarBackdrop.classList.add('opacity-0');
        setTimeout(() => {
            sidebarBackdrop.classList.add('hidden', 'pointer-events-none');
            document.body.classList.remove('overflow-hidden', 'lg:overflow-auto');
        }, 300);
    }

    // Toggle Sidebar (Mobile or Generic)
    function toggleSidebar() {
        if (sidebar && sidebar.classList.contains('-translate-x-full')) {
            openSidebar();
        } else {
            closeSidebar();
        }
    }

    // Toggle Desktop Sidebar (Collapse to give more space for wide tables)
    function toggleDesktopSidebar() {
        const isCollapsed = document.body.classList.toggle('sidebar-desktop-collapsed');
        try {
            localStorage.setItem('sidebar_desktop_collapsed', isCollapsed ? 'true' : 'false');
        } catch (e) {}
    }

    // Restore desktop collapsed state
    try {
        if (localStorage.getItem('sidebar_desktop_collapsed') === 'true') {
            document.body.classList.add('sidebar-desktop-collapsed');
        }
    } catch (e) {}

    // Keyboard Shortcuts
    document.addEventListener('keydown', (e) => {
        // Esc to close mobile sidebar
        if (e.key === 'Escape') {
            closeSidebar();
        }
        // Ctrl+B or Cmd+B to toggle sidebar
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
            e.preventDefault();
            if (window.innerWidth >= 1024) {
                toggleDesktopSidebar();
            } else {
                toggleSidebar();
            }
        }
        // Ctrl+K or / to focus search input in sidebar
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            if (window.innerWidth < 1024) {
                openSidebar();
            }
            if (searchInput) {
                searchInput.focus();
                searchInput.select();
            }
        }
    });

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
        const navCategories = document.querySelectorAll('#sidebarNavList [data-menu-category]');

        if (clearSearchBtn) {
            clearSearchBtn.classList.toggle('hidden', q === '');
        }

        if (q === '') {
            navItems.forEach(el => el.classList.remove('hidden'));
            navCategories.forEach(el => el.classList.remove('hidden'));
            return;
        }

        navItems.forEach(el => {
            const keywords = (el.getAttribute('data-menu-item') || el.innerText).toLowerCase();
            if (keywords.includes(q)) {
                el.classList.remove('hidden');
            } else {
                el.classList.add('hidden');
            }
        });

        // Toggle category titles based on child visibility
        navCategories.forEach(cat => {
            const container = cat.parentElement;
            if (container) {
                const visibleChildren = container.querySelectorAll('[data-menu-item]:not(.hidden)');
                if (visibleChildren.length === 0) {
                    cat.classList.add('hidden');
                } else {
                    cat.classList.remove('hidden');
                }
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            filterSidebarMenu(e.target.value);
        });
    }

    // Auto-scroll active link into view in sidebar upon page load
    window.addEventListener('DOMContentLoaded', () => {
        const activeLink = document.querySelector('#sidebar [aria-current="page"]');
        if (activeLink) {
            activeLink.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    });
</script>

</body>
</html>
