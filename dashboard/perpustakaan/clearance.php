<?php
/**
 * Verifikasi & Surat Keterangan Bebas Perpustakaan (Library Clearance)
 * Memverifikasi tanggungan koleksi buku dan denda siswa serta menerbitkan surat bebas pustaka resmi.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$page_title = "Surat Bebas Perpustakaan";
$is_librarian = in_array($user_role, ['administrator', 'staf'], true);

// Identifikasi Target Siswa
$target_student_id = null;

if ($user_role === 'siswa') {
    $target_student_id = $user_id;
} elseif ($user_role === 'orang_tua') {
    $stmt_child = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
    $stmt_child->execute([$user_id]);
    $target_student_id = (int) $stmt_child->fetchColumn();
} else {
    // Staf & Admin bisa memilih siswa dari query string atau form pencarian
    if (isset($_GET['student_id']) && is_numeric($_GET['student_id'])) {
        $target_student_id = (int) $_GET['student_id'];
    }
}

// Ambil info siswa
$student_info = null;
if ($target_student_id > 0) {
    $stmt_s = $pdo->prepare("
        SELECT u.id, u.name, u.nisn, u.gender, c.name as class_name, c.grade_level
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE u.id = ? AND u.role = 'siswa'
        LIMIT 1
    ");
    $stmt_s->execute([$target_student_id]);
    $student_info = $stmt_s->fetch();
}

// Cek Tanggungan Sirkulasi & Denda
$active_loans = [];
$total_fines  = 0.00;
$is_cleared   = false;

if ($student_info) {
    // 1. Buku yang masih berstatus 'dipinjam'
    $stmt_al = $pdo->prepare("
        SELECT l.id, l.borrow_date, l.due_date, b.code as book_code, b.title as book_title, b.shelf_location,
               DATEDIFF(CURRENT_DATE(), l.due_date) as days_overdue,
               CASE WHEN CURRENT_DATE() > l.due_date THEN DATEDIFF(CURRENT_DATE(), l.due_date) * 1000 ELSE 0 END as calculated_fine
        FROM library_loans l
        JOIN library_books b ON l.book_id = b.id
        WHERE l.user_id = ? AND l.status = 'dipinjam'
        ORDER BY l.due_date ASC
    ");
    $stmt_al->execute([$student_info['id']]);
    $active_loans = $stmt_al->fetchAll();

    // 2. Denda yang belum terselesaikan
    foreach ($active_loans as $al) {
        $total_fines += (float) $al['calculated_fine'];
    }

    $is_cleared = (count($active_loans) === 0 && $total_fines <= 0);
}

// Ambil data sekolah untuk Kop Surat
$school_info    = getSchoolSettings($pdo);
$school_name    = $school_info['school_name'] ?? 'SMA Bina Bangsa Nusantara';
$school_address = $school_info['school_address'] ?? 'Jl. Pendidikan Nasional No. 45, Jakarta';
$school_phone   = $school_info['school_phone'] ?? '(021) 789-0123';
$school_email   = $school_info['school_email'] ?? 'info@binabangsa.sch.id';
$academic_year  = $school_info['academic_year'] ?? '2026/2027 Ganjil';

// Nomor surat unik
$letter_no = "421.3 / " . ($student_info ? str_pad((string)$student_info['id'], 3, '0', STR_PAD_LEFT) : '001') . " / PERPUS / " . date('Y');

// Daftar siswa untuk pencarian staf
$students_dropdown = [];
if ($is_librarian) {
    $students_dropdown = $pdo->query("
        SELECT u.id, u.name, u.nisn, c.name as class_name
        FROM users u
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE u.role = 'siswa'
        ORDER BY c.name ASC, u.name ASC
    ")->fetchAll();
}

include __DIR__ . "/../includes/header.php";
?>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    #certificateSheet, #certificateSheet * {
        visibility: visible;
    }
    #certificateSheet {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 15mm 20mm;
        background: white !important;
        color: black !important;
        box-shadow: none !important;
        border: none !important;
    }
    .no-print {
        display: none !important;
    }
}
</style>

<div class="space-y-6">

    <!-- Sub Navigasi Modul Perpustakaan -->
    <div class="no-print">
        <?php include __DIR__ . "/_nav.php"; ?>

        <!-- Header Section -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <div class="inline-flex items-center gap-2 rounded-full border border-teal-500/20 bg-teal-500/10 px-3 py-1 text-xs font-semibold text-teal-400 mb-2">
                    <i class="fa-solid fa-file-circle-check"></i> Surat Keterangan Administrasi
                </div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white">Surat Bebas Perpustakaan</h1>
                <p class="text-sm text-slate-400 mt-1">Validasi status pengembalian seluruh koleksi buku dan bebas denda untuk syarat kelulusan / mutasi.</p>
            </div>

            <?php if ($student_info && $is_cleared): ?>
                <div class="flex items-center gap-3">
                    <button type="button" onclick="window.print()" 
                            class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2.5 text-xs sm:text-sm font-bold text-white shadow-lg shadow-blue-600/25 transition flex items-center gap-2 cursor-pointer">
                        <i class="fa-solid fa-print"></i> Cetak Surat Resmi (Print / PDF)
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pemilih Siswa untuk Admin & Staf -->
        <?php if ($is_librarian): ?>
            <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur-xl shadow-xl">
                <form method="GET" action="clearance.php" class="flex flex-col sm:flex-row items-center gap-3">
                    <div class="w-full sm:flex-1">
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Pilih Siswa yang Diverifikasi:</label>
                        <select name="student_id" required onchange="this.form.submit()"
                                class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs sm:text-sm text-white focus:border-teal-500 focus:outline-none">
                            <option value="">-- Pilih Nama Siswa / NISN --</option>
                            <?php foreach ($students_dropdown as $st): ?>
                                <option value="<?= $st['id'] ?>" <?= ($target_student_id === (int)$st['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($st['name']) ?> (<?= htmlspecialchars($st['class_name'] ?: 'Tanpa Kelas') ?> - NISN: <?= htmlspecialchars($st['nisn'] ?: '-') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sm:pt-5 w-full sm:w-auto">
                        <button type="submit" class="w-full sm:w-auto rounded-xl bg-teal-600 hover:bg-teal-500 px-5 py-2.5 text-xs font-bold text-white transition">
                            Cek Tanggungan
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- KARTU STATUS VALIDASI -->
        <?php if ($student_info): ?>
            <div class="rounded-3xl border p-6 backdrop-blur-xl shadow-xl <?= $is_cleared ? 'border-emerald-500/40 bg-emerald-950/20' : 'border-rose-500/40 bg-rose-950/20' ?>">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="flex h-14 w-14 items-center justify-center rounded-2xl <?= $is_cleared ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-rose-500/20 text-rose-400 border border-rose-500/30' ?> text-2xl font-black">
                            <i class="fa-solid <?= $is_cleared ? 'fa-shield-halved' : 'fa-circle-exclamation' ?>"></i>
                        </div>
                        <div>
                            <span class="rounded-md px-2.5 py-0.5 text-[10px] font-bold uppercase border <?= $is_cleared ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30' : 'bg-rose-500/10 text-rose-300 border-rose-500/30' ?>">
                                <?= $is_cleared ? 'BEBAS PERPUSTAKAAN' : 'MASIH MEMILIKI TANGGUNGAN' ?>
                            </span>
                            <h3 class="text-xl font-bold text-white mt-1"><?= htmlspecialchars($student_info['name']) ?></h3>
                            <p class="text-xs text-slate-300 mt-0.5">
                                <?= htmlspecialchars($student_info['class_name'] ?: 'Siswa') ?> • NISN: <strong class="font-mono text-white"><?= htmlspecialchars($student_info['nisn'] ?: '-') ?></strong>
                            </p>
                        </div>
                    </div>

                    <div class="text-left sm:text-right">
                        <span class="text-xs text-slate-400 block">Status Buku Pinjaman</span>
                        <span class="text-base font-bold <?= count($active_loans) === 0 ? 'text-emerald-400' : 'text-rose-400' ?>">
                            <?= count($active_loans) ?> Buku Belum Kembali
                        </span>
                        <div class="text-xs <?= $total_fines <= 0 ? 'text-emerald-400' : 'text-rose-400 font-bold' ?> mt-0.5">
                            Denda: <?= formatRupiah($total_fines) ?>
                        </div>
                    </div>
                </div>

                <!-- DAFTAR BUKU TANGGUNGAN JIKA BELUM BEBAS -->
                <?php if (!$is_cleared): ?>
                    <div class="mt-5 pt-4 border-t border-rose-500/20 space-y-3">
                        <h4 class="text-xs font-bold text-rose-300 uppercase tracking-wider">
                            Rincian Buku yang Wajib Dikembalikan ke Perpustakaan:
                        </h4>
                        <div class="space-y-2">
                            <?php foreach ($active_loans as $al): ?>
                                <div class="rounded-2xl border border-white/10 bg-slate-950/80 p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                                    <div>
                                        <span class="font-mono text-[10px] text-teal-400">[<?= htmlspecialchars($al['book_code']) ?>]</span>
                                        <h5 class="font-bold text-white"><?= htmlspecialchars($al['book_title']) ?></h5>
                                        <span class="text-slate-400 text-[11px]">Lokasi Rak: <?= htmlspecialchars($al['shelf_location']) ?></span>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-slate-400 text-[11px]">Jatuh Tempo: <span class="font-mono text-white"><?= date('d/m/Y', strtotime($al['due_date'])) ?></span></div>
                                        <?php if ($al['days_overdue'] > 0): ?>
                                            <span class="text-rose-400 font-bold">Terlambat <?= $al['days_overdue'] ?> hari (Denda: <?= formatRupiah((float)$al['calculated_fine']) ?>)</span>
                                        <?php else: ?>
                                            <span class="text-emerald-400">Dalam masa pinjam</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-xs text-rose-300/80 italic mt-2">
                            * Harap kembalikan buku fisik di atas ke meja petugas perpustakaan untuk mendapatkan Surat Bebas Perpustakaan resmi.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- ======================================================== -->
    <!-- LEMBAR RESMI SURAT BEBAS PERPUSTAKAAN (READY FOR PRINT)   -->
    <!-- ======================================================== -->
    <?php if ($student_info && $is_cleared): ?>
        <div id="certificateSheet" class="rounded-3xl border border-white/10 bg-white p-8 sm:p-12 text-slate-900 shadow-2xl max-w-4xl mx-auto font-serif">
            
            <!-- KOP SURAT SEKOLAH -->
            <div class="text-center border-b-4 border-double border-slate-900 pb-4 mb-6">
                <h2 class="text-xl sm:text-2xl font-bold uppercase tracking-wider text-slate-900"><?= htmlspecialchars($school_name) ?></h2>
                <h3 class="text-sm font-semibold uppercase tracking-widest text-slate-700 mt-0.5">Unit Pelaksana Teknis (UPT) Perpustakaan Sekolah</h3>
                <p class="text-xs text-slate-600 mt-1.5 font-sans leading-relaxed">
                    <?= htmlspecialchars($school_address) ?> • Telp: <?= htmlspecialchars($school_phone) ?> • Email: <?= htmlspecialchars($school_email) ?>
                </p>
            </div>

            <!-- JUDUL SURAT -->
            <div class="text-center my-6">
                <h3 class="text-base sm:text-lg font-bold uppercase tracking-wider underline text-slate-900">
                    SURAT KETERANGAN BEBAS PERPUSTAKAAN
                </h3>
                <p class="text-xs text-slate-600 font-sans mt-1">Nomor: <?= $letter_no ?></p>
            </div>

            <!-- ISI PERNYATAAN -->
            <div class="space-y-4 text-xs sm:text-sm leading-relaxed text-slate-800 font-sans">
                <p>
                    Yang bertanda tangan di bawah ini, Kepala Unit Perpustakaan <strong><?= htmlspecialchars($school_name) ?></strong>, menerangkan dengan sesungguhnya bahwa peserta didik:
                </p>

                <!-- TABEL DATA SISWA -->
                <div class="my-4 px-6 py-3 bg-slate-50 border border-slate-200 rounded-xl">
                    <table class="w-full text-xs sm:text-sm">
                        <tr>
                            <td class="py-1.5 w-40 text-slate-600 font-semibold">Nama Lengkap</td>
                            <td class="py-1.5 w-4">:</td>
                            <td class="py-1.5 font-bold text-slate-900 uppercase"><?= htmlspecialchars($student_info['name']) ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 text-slate-600 font-semibold">Nomor Induk Siswa Nasional (NISN)</td>
                            <td class="py-1.5">:</td>
                            <td class="py-1.5 font-mono font-bold text-slate-900"><?= htmlspecialchars($student_info['nisn'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 text-slate-600 font-semibold">Kelas / Rombel</td>
                            <td class="py-1.5">:</td>
                            <td class="py-1.5 font-semibold text-slate-900"><?= htmlspecialchars($student_info['class_name'] ?: '-') ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 text-slate-600 font-semibold">Tahun Pelajaran</td>
                            <td class="py-1.5">:</td>
                            <td class="py-1.5 text-slate-900"><?= htmlspecialchars($academic_year) ?></td>
                        </tr>
                    </table>
                </div>

                <p>
                    Telah diverifikasi secara elektronik melalui Sistem Informasi Perpustakaan Sekolah dan dinyatakan:
                </p>

                <div class="p-4 my-3 text-center border-2 border-emerald-600 bg-emerald-50 rounded-2xl">
                    <span class="text-sm sm:text-base font-black tracking-wide text-emerald-800 uppercase">
                        ✓ TIDAK MEMILIKI TANGGUNGAN PINJAMAN BUKU MAUPUN DENDA
                    </span>
                    <p class="text-[11px] text-emerald-700 mt-0.5">Seluruh kewajiban literasi dan koleksi fisik perpustakaan telah diselesaikan dengan baik.</p>
                </div>

                <p>
                    Surat keterangan ini diberikan sebagai syarat administratif kelulusan, pengambilan ijazah, atau mutasi sekolah.
                </p>
            </div>

            <!-- TANDA TANGAN & QR CODE -->
            <div class="mt-10 pt-6 flex justify-between items-end font-sans text-xs">
                <!-- QR Code Verification Badge -->
                <div class="text-center border border-slate-300 p-2.5 rounded-xl bg-slate-50">
                    <div id="verifyQrcode" class="flex justify-center mb-1">
                        <!-- Render QR canvas -->
                        <div class="h-16 w-16 bg-slate-900 text-white flex items-center justify-center rounded text-[10px] font-mono">
                            VERIFIED
                        </div>
                    </div>
                    <span class="text-[9px] text-slate-500 font-mono block">Dokumen Sah Terverifikasi</span>
                    <span class="text-[8px] text-slate-400 font-mono"><?= substr(md5($letter_no), 0, 12) ?></span>
                </div>

                <!-- Bagian Tanda Tangan -->
                <div class="text-center w-56">
                    <p class="text-slate-600">Dikeluarkan di: Jakarta</p>
                    <p class="text-slate-600">Pada tanggal: <?= date('d F Y') ?></p>
                    <p class="font-bold text-slate-800 mt-2 mb-14">Kepala Perpustakaan Sekolah,</p>
                    
                    <p class="font-bold text-slate-900 underline uppercase">Dra. Hj. Nurul Hidayati, M.Pd</p>
                    <p class="text-[11px] text-slate-600 font-mono">NIP. 19780512 200501 2 006</p>
                </div>
            </div>

        </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
