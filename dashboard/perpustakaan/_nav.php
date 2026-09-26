<?php
/**
 * Sub-Navigasi Terpadu Modul Perpustakaan Digital
 * Komponen tab navigasi seragam untuk seluruh fitur perpustakaan sekolah.
 */
declare(strict_types=1);

$cur_file = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff  = in_array($user_role, ['administrator', 'staf'], true);

$nav_items = [
    [
        'file' => 'books.php',
        'label' => 'Katalog & E-Book',
        'icon'  => 'fa-solid fa-book-open-reader',
        'desc'  => 'Daftar koleksi & modul',
        'roles' => ['all'],
    ],
    [
        'file' => 'loans.php',
        'label' => 'Sirkulasi Pinjaman',
        'icon'  => 'fa-solid fa-arrows-rotate',
        'desc'  => 'Peminjaman & denda',
        'roles' => ['all'],
    ],
    [
        'file' => 'reservations.php',
        'label' => 'Booking & Reservasi',
        'icon'  => 'fa-solid fa-bookmark',
        'desc'  => 'Antrean & pemesanan',
        'roles' => ['all'],
    ],
    [
        'file' => 'scan.php',
        'label' => 'Scan Cepat',
        'icon'  => 'fa-solid fa-barcode',
        'desc'  => 'Kasir sirkulasi QR',
        'roles' => ['staf', 'administrator'],
    ],
    [
        'file' => 'visitors.php',
        'label' => 'Buku Tamu',
        'icon'  => 'fa-solid fa-clipboard-user',
        'desc'  => 'Presensi pengunjung',
        'roles' => ['all'],
    ],
    [
        'file' => 'print_labels.php',
        'label' => 'Cetak Label',
        'icon'  => 'fa-solid fa-tags',
        'desc'  => 'Barcode & nomor panggil',
        'roles' => ['staf', 'administrator'],
    ],
    [
        'file' => 'clearance.php',
        'label' => 'Bebas Pustaka',
        'icon'  => 'fa-solid fa-file-circle-check',
        'desc'  => 'Surat bebas tanggungan',
        'roles' => ['all'],
    ],
];
?>

<div class="mb-6 rounded-2xl border border-white/10 bg-slate-900/60 p-2 backdrop-blur-xl shadow-xl">
    <div class="flex items-center gap-1.5 overflow-x-auto no-scrollbar scroll-smooth py-0.5 px-0.5">
        <?php foreach ($nav_items as $item): ?>
            <?php 
                $can_view = in_array('all', $item['roles'], true) || in_array($user_role, $item['roles'], true);
                if (!$can_view) continue;
                $is_active = ($cur_file === $item['file']);
            ?>
            <a href="<?= $item['file'] ?>" 
               class="group relative flex items-center gap-2.5 rounded-xl px-3.5 py-2 text-xs sm:text-sm font-semibold transition-all duration-200 whitespace-nowrap cursor-pointer <?= $is_active 
                    ? 'bg-gradient-to-r from-teal-500/20 to-blue-500/20 text-white border border-teal-500/40 shadow-md shadow-teal-500/10' 
                    : 'text-slate-400 hover:text-slate-200 hover:bg-white/5 border border-transparent' ?>">
                <div class="flex h-7 w-7 items-center justify-center rounded-lg <?= $is_active ? 'bg-teal-500 text-white shadow-sm shadow-teal-500/30' : 'bg-slate-800 text-slate-400 group-hover:text-teal-400 group-hover:bg-slate-800/80' ?> transition">
                    <i class="<?= $item['icon'] ?> text-xs"></i>
                </div>
                <div>
                    <div class="leading-tight"><?= $item['label'] ?></div>
                    <div class="text-[10px] font-normal <?= $is_active ? 'text-teal-300/80' : 'text-slate-500' ?> hidden sm:block"><?= $item['desc'] ?></div>
                </div>
                <?php if ($is_active): ?>
                    <span class="absolute -bottom-1 left-1/2 -translate-x-1/2 h-1 w-6 rounded-full bg-teal-400 sm:hidden"></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
