<?php
/**
 * Komponen Footer Publik Resmi (Unified Footer)
 * Desain modern 3-kolom, data profil sekolah dinamis dari database, dan tautan terpadu.
 */
declare(strict_types=1);

$base_path = $base_path ?? '';

if (!isset($school_info) && isset($pdo)) {
    if (function_exists('getSchoolSettings')) {
        $school_info = getSchoolSettings($pdo);
    }
}

$school_name    = $school_info['school_name'] ?? 'Manajemen-PHP';
$school_address = $school_info['school_address'] ?? 'Jakarta, Indonesia';
$school_phone   = $school_info['school_phone'] ?? '(021) 789-0123';
$school_email   = $school_info['school_email'] ?? 'info@sekolah.sch.id';
?>

<!-- ================= GLOBAL FOOTER ================= -->
<footer class="mt-auto border-t border-white/10 bg-slate-950/90 text-xs text-slate-400 backdrop-blur-md">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-12 sm:py-16">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8 lg:gap-12">
            
            <!-- Kolom 1 & 2: Identitas Lembaga & Deskripsi -->
            <div class="md:col-span-2 space-y-4">
                <a href="<?= $base_path ?>index.php" class="inline-flex items-center gap-2.5 group">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-sky-500 text-white shadow-md shadow-blue-500/25">
                        <i class="fa-solid fa-graduation-cap text-sm"></i>
                    </span>
                    <span class="text-lg font-bold tracking-tight text-white group-hover:text-blue-400 transition">
                        <?= htmlspecialchars($school_name) ?>
                    </span>
                </a>

                <p class="text-xs text-slate-400 max-w-md leading-relaxed">
                    Sistem informasi akademik dan tata kelola sekolah terpadu berbasis hak akses (Administrator, Staf TU, Guru, Siswa, dan Orang Tua). Mendorong digitalisasi pendidikan yang aman, transparan, dan berkesinambungan.
                </p>

                <div class="flex items-center gap-3 pt-1">
                    <span class="inline-flex items-center gap-2 rounded-full border border-emerald-500/25 bg-emerald-500/10 px-3 py-1 text-[11px] font-semibold text-emerald-400">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        Semua Layanan Sistem Berjalan Normal
                    </span>
                </div>

                <?php if (!empty($school_info['social_instagram']) || !empty($school_info['social_youtube']) || !empty($school_info['social_facebook']) || !empty($school_info['social_tiktok'])): ?>
                <div class="flex items-center gap-2.5 pt-2">
                    <span class="text-[11px] text-slate-500 font-medium">Ikuti Kami:</span>
                    <?php if (!empty($school_info['social_instagram'])): ?>
                    <a href="<?= htmlspecialchars($school_info['social_instagram']) ?>" target="_blank" title="Instagram Resmi" class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/5 border border-white/10 text-rose-400 hover:bg-rose-500/20 hover:border-rose-500/30 transition text-xs">
                        <i class="fa-brands fa-instagram"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($school_info['social_youtube'])): ?>
                    <a href="<?= htmlspecialchars($school_info['social_youtube']) ?>" target="_blank" title="Kanal YouTube Resmi" class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/5 border border-white/10 text-red-400 hover:bg-red-500/20 hover:border-red-500/30 transition text-xs">
                        <i class="fa-brands fa-youtube"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($school_info['social_facebook'])): ?>
                    <a href="<?= htmlspecialchars($school_info['social_facebook']) ?>" target="_blank" title="Facebook Resmi" class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/5 border border-white/10 text-blue-400 hover:bg-blue-500/20 hover:border-blue-500/30 transition text-xs">
                        <i class="fa-brands fa-facebook"></i>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($school_info['social_tiktok'])): ?>
                    <a href="<?= htmlspecialchars($school_info['social_tiktok']) ?>" target="_blank" title="TikTok Resmi" class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/5 border border-white/10 text-slate-200 hover:bg-white/20 hover:border-white/30 transition text-xs">
                        <i class="fa-brands fa-tiktok"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Kolom 3: Tautan Cepat Navigasi -->
            <div>
                <h4 class="text-white font-bold text-xs uppercase tracking-wider mb-4 border-l-2 border-blue-500 pl-2">
                    Tautan Cepat
                </h4>
                <ul class="space-y-2.5 text-xs">
                    <li>
                        <a href="<?= $base_path ?>index.php" class="hover:text-blue-400 transition flex items-center gap-1.5">
                            <i class="fa-solid fa-angle-right text-[10px] text-slate-500"></i> Beranda Utama
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>informasi.php" class="hover:text-sky-400 transition flex items-center gap-1.5">
                            <i class="fa-solid fa-angle-right text-[10px] text-slate-500"></i> Papan Informasi
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>about.php" class="hover:text-indigo-400 transition flex items-center gap-1.5">
                            <i class="fa-solid fa-angle-right text-[10px] text-slate-500"></i> Profil & Tentang Kami
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>perpustakaan.php" class="hover:text-cyan-400 transition flex items-center gap-1.5">
                            <i class="fa-solid fa-angle-right text-[10px] text-slate-500"></i> Perpustakaan Digital
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>ppdb/ppdb.php" class="hover:text-emerald-400 transition flex items-center gap-1.5">
                            <i class="fa-solid fa-angle-right text-[10px] text-slate-500"></i> Portal PPDB Online
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>kontak.php" class="hover:text-amber-400 transition flex items-center gap-1.5">
                            <i class="fa-solid fa-angle-right text-[10px] text-slate-500"></i> Kontak Bantuan
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base_path ?>apps.php" class="text-teal-400 hover:text-teal-300 font-semibold transition flex items-center gap-1.5">
                            <i class="fa-solid fa-mobile-screen-button text-[10px] text-teal-400"></i> Unduh Aplikasi Sekolah
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Kolom 4: Kontak & Alamat Sekolah -->
            <div>
                <h4 class="text-white font-bold text-xs uppercase tracking-wider mb-4 border-l-2 border-emerald-500 pl-2">
                    Kontak Sekolah
                </h4>
                <ul class="space-y-3 text-xs text-slate-400">
                    <li class="flex items-start gap-2.5">
                        <i class="fa-solid fa-location-dot mt-0.5 text-blue-400 shrink-0"></i>
                        <span><?= htmlspecialchars($school_address) ?></span>
                    </li>
                    <li class="flex items-center gap-2.5">
                        <i class="fa-solid fa-phone text-emerald-400 shrink-0"></i>
                        <span><?= htmlspecialchars($school_phone) ?></span>
                    </li>
                    <li class="flex items-center gap-2.5">
                        <i class="fa-solid fa-envelope text-indigo-400 shrink-0"></i>
                        <span><?= htmlspecialchars($school_email) ?></span>
                    </li>
                    <li class="pt-1">
                        <a href="<?= $base_path ?>auth/login.php" class="inline-flex items-center gap-1.5 text-blue-400 hover:text-blue-300 font-semibold transition">
                            <i class="fa-solid fa-lock text-[10px]"></i>
                            <span>Portal Login Civitas</span>
                        </a>
                    </li>
                </ul>
            </div>

        </div>

        <!-- Copyright Bottom Bar -->
        <div class="mt-12 pt-6 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between gap-3 text-[11px] text-slate-500">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($school_name) ?>. Seluruh hak cipta dilindungi.</p>
            <p class="flex items-center gap-1">
                <span>Ekosistem Manajemen Sekolah Terpadu</span>
            </p>
        </div>
    </div>
</footer>

<?php
// Live Chat Widget Terpadu (Bisa Digunakan Sebelum & Sesudah Login)
require_once __DIR__ . '/live_chat_widget.php';
?>
