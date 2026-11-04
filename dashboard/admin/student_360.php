<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';

// Aksesibilitas: Guru, Staf, Administrator, Siswa (profil sendiri), Orang Tua (anaknya)
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($student_id <= 0) {
    if ($user_role === 'siswa') {
        $student_id = $user_id;
    } elseif ($user_role === 'orang_tua') {
        $stmt_c = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ? LIMIT 1");
        $stmt_c->execute([$user_id]);
        $student_id = (int)($stmt_c->fetchColumn() ?: 0);
    }
}

if ($student_id <= 0) {
    header("Location: ../index.php?error=invalid_student");
    exit;
}

// Cek otorisasi
$can_view = false;
if (in_array($user_role, ['administrator', 'staf', 'guru'], true)) {
    $can_view = true;
} elseif ($user_role === 'siswa' && $student_id === $user_id) {
    $can_view = true;
} elseif ($user_role === 'orang_tua') {
    $chk_p = $pdo->prepare("SELECT 1 FROM parent_students WHERE parent_id = ? AND student_id = ?");
    $chk_p->execute([$user_id, $student_id]);
    if ($chk_p->fetch()) {
        $can_view = true;
    }
}

if (!$can_view) {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

// Ambil data agregasi 360 derajat siswa
$data = getStudent360Data($pdo, $student_id);
if (!$data || empty($data['student'])) {
    header("Location: users.php?error=student_not_found");
    exit;
}

$stu         = $data['student'];
$att         = $data['attendance'];
$acad        = $data['academics'];
$bk          = $data['counseling'];
$fin         = $data['finance'];
$lib         = $data['library'];
$requests    = $data['requests'];
$clearance   = $data['clearance'];
$ekskul_data = $data['extracurriculars'] ?? [];
$achieve_data= $data['achievements'] ?? [];

// Hitung nomor telepon ortu untuk WhatsApp link
$parent_phone_clean = preg_replace('/[^0-9]/', '', $stu['parent_phone'] ?? '');
if ($parent_phone_clean && substr($parent_phone_clean, 0, 1) === '0') {
    $parent_phone_clean = '62' . substr($parent_phone_clean, 1);
}

// Tab aktif
$active_tab = $_GET['tab'] ?? 'ringkasan';

require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Top Breadcrumb & Quick Action -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-slate-400 mb-1">
                <a href="../index.php" class="hover:text-blue-400 transition">Dashboard</a>
                <span><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
                <?php if (in_array($user_role, ['administrator', 'staf'], true)): ?>
                    <a href="users.php" class="hover:text-blue-400 transition">Manajemen Siswa</a>
                    <span><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
                <?php elseif ($user_role === 'guru'): ?>
                    <a href="../akademik/homeroom.php" class="hover:text-blue-400 transition">Wali Kelas</a>
                    <span><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
                <?php endif; ?>
                <span class="text-blue-400 font-medium">Profil Terpadu 360°</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 to-blue-500 text-white shadow-lg shadow-indigo-500/30 text-lg">
                    <i class="fa-solid fa-id-card-clip"></i>
                </span>
                Profil Holistik 360° Siswa
            </h1>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <!-- Cetak Lembar Buku Induk -->
            <a href="student_profile_print.php?id=<?= $stu['id'] ?>" target="_blank"
               class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs font-semibold text-slate-200 transition">
                <i class="fa-solid fa-address-book text-emerald-400"></i> Buku Induk
            </a>
            <!-- Cetak E-Rapor -->
            <a href="../akademik/report_card.php?student_id=<?= $stu['id'] ?>" target="_blank"
               class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs font-semibold text-slate-200 transition">
                <i class="fa-solid fa-award text-amber-400"></i> E-Rapor
            </a>
            <!-- Kartu QR Presensi -->
            <a href="../presensi/qr_card.php?student_id=<?= $stu['id'] ?>" target="_blank"
               class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs font-semibold text-slate-200 transition">
                <i class="fa-solid fa-qrcode text-blue-400"></i> Kartu QR
            </a>
            <!-- Cetak Kartu Ujian -->
            <a href="../Modul-ujian/exam_card.php?student_id=<?= $stu['id'] ?>" target="_blank"
               class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs font-semibold text-slate-200 transition">
                <i class="fa-solid fa-id-card text-purple-400"></i> Kartu Ujian
            </a>
            <?php if (in_array($user_role, ['administrator', 'guru', 'staf'], true)): ?>
                <a href="../bk/counseling.php?student_id=<?= $stu['id'] ?>"
                   class="inline-flex items-center gap-2 rounded-xl bg-purple-600 hover:bg-purple-500 px-3.5 py-2 text-xs font-bold text-white transition shadow-lg shadow-purple-600/30">
                    <i class="fa-solid fa-scale-balanced"></i> Catat BK
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Student Hero Profile Card -->
    <div class="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-br from-slate-900 via-slate-900/90 to-indigo-950/40 p-6 sm:p-8 shadow-2xl backdrop-blur">
        <div class="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-indigo-500/10 blur-3xl pointer-events-none"></div>
        <div class="absolute -left-16 -bottom-16 h-64 w-64 rounded-full bg-blue-500/10 blur-3xl pointer-events-none"></div>

        <div class="relative flex flex-col lg:flex-row lg:items-center justify-between gap-6">
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5">
                <!-- Avatar Siswa -->
                <div class="relative shrink-0">
                    <div class="h-20 w-20 sm:h-24 sm:w-24 rounded-2xl bg-gradient-to-tr from-blue-600 via-indigo-600 to-purple-600 p-[2px] shadow-xl">
                        <div class="h-full w-full rounded-2xl bg-slate-900 flex items-center justify-center overflow-hidden">
                            <span class="text-3xl sm:text-4xl font-black text-transparent bg-clip-text bg-gradient-to-br from-blue-200 to-indigo-300">
                                <?= strtoupper(substr($stu['name'], 0, 1)) ?>
                            </span>
                        </div>
                    </div>
                    <span class="absolute -bottom-1 -right-1 flex h-6 w-6 items-center justify-center rounded-full bg-emerald-500 text-white text-[11px] ring-4 ring-slate-900" title="Terdaftar Aktif">
                        <i class="fa-solid fa-check"></i>
                    </span>
                </div>

                <!-- Info Pokok -->
                <div class="space-y-1.5">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <h2 class="text-xl sm:text-2xl font-bold text-white tracking-tight">
                            <?= htmlspecialchars($stu['name']) ?>
                        </h2>
                        <?= getUserStatusBadge($stu['status'] ?? 'aktif') ?>
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-blue-500/15 text-blue-300 border border-blue-500/30">
                            Kelas <?= htmlspecialchars($stu['class_name'] ?? 'Belum Ditentukan') ?>
                        </span>
                        <?php if (!empty($stu['ppdb_track'])): ?>
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-indigo-500/15 text-indigo-300 border border-indigo-500/25">
                                PPDB <?= htmlspecialchars(ucfirst($stu['ppdb_track'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                        <div class="flex items-center gap-1.5">
                            <i class="fa-solid fa-barcode text-slate-500"></i>
                            <span>NISN: <strong class="text-slate-200 font-mono"><?= htmlspecialchars($stu['nisn'] ?: '-') ?></strong></span>
                        </div>
                        <?php if (!empty($stu['nik'])): ?>
                            <div class="flex items-center gap-1.5">
                                <i class="fa-solid fa-id-card text-slate-500"></i>
                                <span>NIK: <strong class="text-slate-200 font-mono"><?= htmlspecialchars($stu['nik']) ?></strong></span>
                            </div>
                        <?php endif; ?>
                        <div class="flex items-center gap-1.5">
                            <i class="fa-solid fa-envelope text-slate-500"></i>
                            <span><?= htmlspecialchars($stu['email']) ?></span>
                        </div>
                    </div>

                    <!-- Kontak Orang Tua / Wali -->
                    <div class="flex flex-wrap items-center gap-3 pt-1 text-xs">
                        <?php if (!empty($stu['parent_name'])): ?>
                            <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-white/5 border border-white/10 text-slate-300">
                                <i class="fa-solid fa-user-group text-indigo-400"></i>
                                <span>Ortu: <strong><?= htmlspecialchars($stu['parent_name']) ?></strong></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($stu['parent_phone'])): ?>
                            <a href="https://wa.me/<?= $parent_phone_clean ?>?text=<?= urlencode("Halo Bpk/Ibu " . ($stu['parent_name'] ?: 'Orang Tua') . ", kami dari pihak sekolah terkait ananda " . $stu['name'] . ".") ?>"
                               target="_blank"
                               class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 hover:bg-emerald-500/25 transition font-semibold"
                               title="Kirim Pesan WhatsApp Langsung">
                                <i class="fa-brands fa-whatsapp text-emerald-400 text-sm"></i>
                                <span><?= htmlspecialchars($stu['parent_phone']) ?></span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Clearance Status Box -->
            <div class="lg:w-72 shrink-0 rounded-2xl border p-4 <?= $clearance['eligible'] ? 'border-emerald-500/30 bg-emerald-950/20' : 'border-amber-500/30 bg-amber-950/20' ?>">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs font-semibold text-slate-400">Status 3-in-1 Clearance</span>
                    <?php if ($clearance['eligible']): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                            <i class="fa-solid fa-circle-check"></i> Bebas Tanggungan
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                            <i class="fa-solid fa-triangle-exclamation"></i> <?= count($clearance['reasons'] ?? []) ?> Catatan
                        </span>
                    <?php endif; ?>
                </div>

                <p class="text-xs <?= $clearance['eligible'] ? 'text-emerald-300/90' : 'text-amber-200/90' ?> leading-relaxed">
                    <?= htmlspecialchars($clearance['summary']) ?>
                </p>

                <?php if (!empty($clearance['reasons'])): ?>
                    <ul class="mt-2 space-y-1 text-[11px] text-amber-300/80">
                        <?php foreach (array_slice($clearance['reasons'], 0, 2) as $reason): ?>
                            <li class="flex items-start gap-1.5">
                                <i class="fa-solid fa-circle-minus text-amber-400 mt-0.5 text-[9px]"></i>
                                <span><?= htmlspecialchars($reason) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 6-Pillar Metric Grid Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3.5">
        <!-- 1. Presensi -->
        <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 transition hover:border-emerald-500/30">
            <div class="flex items-center justify-between text-emerald-400 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Presensi</span>
                <i class="fa-solid fa-clipboard-user text-base"></i>
            </div>
            <div class="text-2xl font-bold text-white"><?= $att['hadir_ratio'] ?>%</div>
            <p class="text-[11px] text-slate-400 mt-1">
                H: <?= $att['hadir'] ?> &bull; A: <span class="<?= $att['alpa'] > 0 ? 'text-rose-400 font-bold' : '' ?>"><?= $att['alpa'] ?></span>
            </p>
        </div>

        <!-- 2. Nilai Rata-rata Tugas -->
        <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 transition hover:border-blue-500/30">
            <div class="flex items-center justify-between text-blue-400 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Rerata Tugas</span>
                <i class="fa-solid fa-list-check text-base"></i>
            </div>
            <div class="text-2xl font-bold text-white"><?= $acad['avg_assignment_score'] ?></div>
            <p class="text-[11px] text-slate-400 mt-1"><?= count($acad['assignments']) ?> tugas tersubmit</p>
        </div>

        <!-- 3. Nilai Rata-rata CBT -->
        <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 transition hover:border-indigo-500/30">
            <div class="flex items-center justify-between text-indigo-400 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Rerata CBT</span>
                <i class="fa-solid fa-laptop-code text-base"></i>
            </div>
            <div class="text-2xl font-bold text-white"><?= $acad['avg_exam_score'] ?></div>
            <p class="text-[11px] text-slate-400 mt-1"><?= count($acad['exams']) ?> ujian dikerjakan</p>
        </div>

        <!-- 4. Poin BK Kedisiplinan -->
        <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 transition hover:border-purple-500/30">
            <div class="flex items-center justify-between text-purple-400 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Poin BK</span>
                <i class="fa-solid fa-scale-balanced text-base"></i>
            </div>
            <div class="text-2xl font-bold <?= $bk['violations_points'] >= 30 ? 'text-rose-400' : 'text-white' ?>">
                <?= $bk['violations_points'] ?>
            </div>
            <p class="text-[11px] text-slate-400 mt-1">
                <?= $bk['violations_count'] ?> kasus &bull; <?= $bk['achievements_count'] ?> prestasi
            </p>
        </div>

        <!-- 5. Tunggakan SPP -->
        <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 transition hover:border-amber-500/30">
            <div class="flex items-center justify-between text-amber-400 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Keuangan</span>
                <i class="fa-solid fa-wallet text-base"></i>
            </div>
            <div class="text-xl font-bold <?= $fin['unpaid_count'] > 0 ? 'text-amber-400' : 'text-emerald-400' ?>">
                <?= $fin['unpaid_count'] > 0 ? 'Rp ' . number_format($fin['total_unpaid'], 0, ',', '.') : 'Lunas' ?>
            </div>
            <p class="text-[11px] text-slate-400 mt-1"><?= $fin['unpaid_count'] ?> tagihan belum tuntas</p>
        </div>

        <!-- 6. Pustaka Pinjaman Aktif -->
        <div class="rounded-2xl border border-white/5 bg-slate-900/60 p-4 transition hover:border-teal-500/30">
            <div class="flex items-center justify-between text-teal-400 mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Perpustakaan</span>
                <i class="fa-solid fa-book-bookmark text-base"></i>
            </div>
            <div class="text-2xl font-bold text-white"><?= $lib['active_loans_count'] ?></div>
            <p class="text-[11px] text-slate-400 mt-1">
                buku pinjam <?= $lib['total_fines'] > 0 ? '(Denda Rp ' . number_format($lib['total_fines'], 0, ',', '.') . ')' : '' ?>
            </p>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="border-b border-white/10">
        <nav class="flex space-x-2 sm:space-x-6 overflow-x-auto pb-1" aria-label="Tabs">
            <a href="?id=<?= $student_id ?>&tab=ringkasan"
               class="whitespace-nowrap py-3 px-2 border-b-2 font-medium text-xs sm:text-sm transition flex items-center gap-2 <?= $active_tab === 'ringkasan' ? 'border-blue-500 text-blue-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-chart-pie"></i> Ringkasan & Dokumen
            </a>
            <a href="?id=<?= $student_id ?>&tab=akademik"
               class="whitespace-nowrap py-3 px-2 border-b-2 font-medium text-xs sm:text-sm transition flex items-center gap-2 <?= $active_tab === 'akademik' ? 'border-blue-500 text-blue-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-graduation-cap"></i> Akademik & CBT (<?= count($acad['assignments']) + count($acad['exams']) ?>)
            </a>
            <a href="?id=<?= $student_id ?>&tab=presensi"
               class="whitespace-nowrap py-3 px-2 border-b-2 font-medium text-xs sm:text-sm transition flex items-center gap-2 <?= $active_tab === 'presensi' ? 'border-blue-500 text-blue-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-calendar-check"></i> Presensi Harian (<?= count($att['recent']) ?>)
            </a>
            <a href="?id=<?= $student_id ?>&tab=bk"
               class="whitespace-nowrap py-3 px-2 border-b-2 font-medium text-xs sm:text-sm transition flex items-center gap-2 <?= $active_tab === 'bk' ? 'border-blue-500 text-blue-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-scale-balanced"></i> BK & Tata Tertib (<?= count($bk['records']) ?>)
            </a>
            <a href="?id=<?= $student_id ?>&tab=keuangan"
               class="whitespace-nowrap py-3 px-2 border-b-2 font-medium text-xs sm:text-sm transition flex items-center gap-2 <?= $active_tab === 'keuangan' ? 'border-blue-500 text-blue-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-receipt"></i> Keuangan SPP (<?= count($fin['bills']) ?>)
            </a>
            <a href="?id=<?= $student_id ?>&tab=perpustakaan"
               class="whitespace-nowrap py-3 px-2 border-b-2 font-medium text-xs sm:text-sm transition flex items-center gap-2 <?= $active_tab === 'perpustakaan' ? 'border-blue-500 text-blue-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-book"></i> Perpustakaan (<?= count($lib['loans']) ?>)
            </a>
            <a href="?id=<?= $student_id ?>&tab=ekskul"
               class="whitespace-nowrap py-3 px-2 border-b-2 font-medium text-xs sm:text-sm transition flex items-center gap-2 <?= $active_tab === 'ekskul' ? 'border-blue-500 text-blue-400 font-bold' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
                <i class="fa-solid fa-trophy text-amber-400"></i> Ekskul & Prestasi (<?= count($ekskul_data) ?>)
            </a>
        </nav>
    </div>

    <!-- TAB CONTENT PANELS -->

    <?php if ($active_tab === 'ringkasan'): ?>
        <!-- Panel 1: Ringkasan & Biodata Terpadu -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Data Pokok Siswa & PPDB -->
            <div class="lg:col-span-2 space-y-6">
                <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl backdrop-blur">
                    <h3 class="text-base font-bold text-white flex items-center gap-2.5 mb-4">
                        <span class="text-blue-400"><i class="fa-solid fa-user"></i></span>
                        Data Pribadi & Riwayat Masuk Sekolah
                    </h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                        <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                            <span class="text-slate-400 block mb-1">Tempat, Tanggal Lahir</span>
                            <span class="font-semibold text-white">
                                <?= htmlspecialchars(($stu['birth_place'] ?? '-') . ', ' . (!empty($stu['birth_date']) ? date('d F Y', strtotime($stu['birth_date'])) : '-')) ?>
                            </span>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                            <span class="text-slate-400 block mb-1">Jenis Kelamin / Agama</span>
                            <span class="font-semibold text-white">
                                <?= htmlspecialchars(($stu['gender'] === 'L' ? 'Laki-laki' : ($stu['gender'] === 'P' ? 'Perempuan' : '-')) . ' / ' . ($stu['religion'] ?? '-')) ?>
                            </span>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                            <span class="text-slate-400 block mb-1">Asal Sekolah Sebelumnya</span>
                            <span class="font-semibold text-white">
                                <?= htmlspecialchars($stu['previous_school'] ?? 'Data Pokok Dapodik') ?>
                            </span>
                        </div>
                        <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                            <span class="text-slate-400 block mb-1">Nomor Registrasi PPDB & Skor CBT Masuk</span>
                            <span class="font-semibold text-white flex items-center gap-2">
                                <?= htmlspecialchars($stu['registration_no'] ?: 'Siswa Reguler') ?>
                                <?php if (isset($stu['ppdb_cbt_score']) && $stu['ppdb_cbt_score'] !== null): ?>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-500/20 text-blue-300">
                                        Skor CBT: <?= number_format((float)$stu['ppdb_cbt_score'], 1) ?>
                                    </span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Layanan Persuratan TU Siswa -->
                <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl backdrop-blur">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-bold text-white flex items-center gap-2.5">
                            <span class="text-indigo-400"><i class="fa-solid fa-file-signature"></i></span>
                            Layanan Surat & Dokumen TU
                        </h3>
                        <?php if (in_array($user_role, ['administrator', 'staf'], true)): ?>
                            <a href="../surat/requests.php" class="text-xs text-blue-400 hover:underline">Kelola Surat &rarr;</a>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($requests)): ?>
                        <div class="text-center py-6 text-slate-500 text-xs">
                            <i class="fa-solid fa-inbox text-2xl mb-1.5 block"></i>
                            Belum ada riwayat permohonan surat dari siswa ini.
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-white/5">
                            <?php foreach ($requests as $req): ?>
                                <div class="py-3 flex items-center justify-between gap-4 text-xs">
                                    <div>
                                        <div class="font-bold text-white"><?= htmlspecialchars($req['service_type'] ?? 'Surat Keterangan') ?></div>
                                        <p class="text-slate-400 text-[11px] mt-0.5"><?= htmlspecialchars($req['purpose'] ?? '-') ?></p>
                                        <span class="text-[10px] text-slate-500"><?= date('d M Y', strtotime($req['created_at'])) ?></span>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $req['status'] === 'selesai' ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-amber-500/15 text-amber-300 border border-amber-500/30' ?>">
                                            <?= ucfirst($req['status']) ?>
                                        </span>
                                        <?php if ($req['status'] === 'selesai'): ?>
                                            <a href="../surat/request_print.php?id=<?= $req['id'] ?>" target="_blank"
                                               class="p-1.5 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 transition" title="Cetak Surat">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Kartu Alamat & Kontak Lengkap -->
            <div class="space-y-6">
                <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl backdrop-blur">
                    <h3 class="text-base font-bold text-white flex items-center gap-2.5 mb-4">
                        <span class="text-emerald-400"><i class="fa-solid fa-location-dot"></i></span>
                        Alamat & Domisili
                    </h3>
                    <div class="space-y-3 text-xs">
                        <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                            <span class="text-slate-400 block mb-1">Alamat Tinggal Siswa:</span>
                            <span class="text-slate-200"><?= nl2br(htmlspecialchars($stu['address'] ?: 'Belum diisi')) ?></span>
                        </div>
                        <?php if (!empty($stu['parent_address']) && $stu['parent_address'] !== $stu['address']): ?>
                            <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5">
                                <span class="text-slate-400 block mb-1">Alamat Domisili Orang Tua:</span>
                                <span class="text-slate-200"><?= nl2br(htmlspecialchars($stu['parent_address'])) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- QR Code Presensi Card Preview -->
                <div class="rounded-3xl border border-white/5 bg-gradient-to-br from-slate-900 to-indigo-950/30 p-6 shadow-xl text-center">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">ID Barcode Presensi</h4>
                    <div class="inline-block p-3 rounded-2xl bg-white shadow-xl mx-auto mb-3">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode($stu['nisn'] ?: $stu['email']) ?>"
                             alt="QR Siswa" class="h-28 w-28">
                    </div>
                    <div class="font-mono text-xs text-slate-300 font-bold"><?= htmlspecialchars($stu['nisn'] ?: $stu['email']) ?></div>
                    <p class="text-[11px] text-slate-500 mt-1">Dapat discan di Pemindai QR Presensi Harian</p>
                </div>
            </div>
        </div>

    <?php elseif ($active_tab === 'akademik'): ?>
        <!-- Panel 2: Akademik, Tugas & CBT -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Tugas & Pekerjaan Rumah -->
            <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2.5">
                        <span class="text-blue-400"><i class="fa-solid fa-list-check"></i></span>
                        Nilai Tugas & PR Terakhir
                    </h3>
                    <span class="text-xs text-blue-400 font-bold">Rerata: <?= $acad['avg_assignment_score'] ?></span>
                </div>

                <?php if (empty($acad['assignments'])): ?>
                    <p class="text-center py-6 text-slate-500 text-xs">Belum ada submission tugas yang dinilai.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($acad['assignments'] as $asg): ?>
                            <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5 flex items-center justify-between gap-3 text-xs">
                                <div>
                                    <div class="font-bold text-white"><?= htmlspecialchars($asg['title']) ?></div>
                                    <span class="text-[11px] text-slate-400"><?= htmlspecialchars($asg['subject']) ?></span>
                                    <?php if (!empty($asg['feedback'])): ?>
                                        <p class="text-[11px] text-indigo-300 italic mt-1">"<?= htmlspecialchars($asg['feedback']) ?>"</p>
                                    <?php endif; ?>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="text-base font-bold text-blue-400"><?= $asg['score'] ?? '-' ?></div>
                                    <span class="text-[10px] text-slate-500"><?= date('d/m/y', strtotime($asg['submitted_at'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Ujian CBT Online -->
            <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2.5">
                        <span class="text-purple-400"><i class="fa-solid fa-laptop-code"></i></span>
                        Riwayat Ujian CBT Online
                    </h3>
                    <span class="text-xs text-purple-400 font-bold">Rerata: <?= $acad['avg_exam_score'] ?></span>
                </div>

                <?php if (empty($acad['exams'])): ?>
                    <p class="text-center py-6 text-slate-500 text-xs">Belum ada ujian CBT yang diselesaikan.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($acad['exams'] as $ex): ?>
                            <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5 flex items-center justify-between gap-3 text-xs">
                                <div>
                                    <div class="font-bold text-white"><?= htmlspecialchars($ex['title']) ?></div>
                                    <span class="text-[11px] text-slate-400"><?= htmlspecialchars($ex['subject']) ?> (<?= htmlspecialchars(strtoupper($ex['category'])) ?>)</span>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="text-base font-bold <?= (float)$ex['score'] >= 75 ? 'text-emerald-400' : 'text-amber-400' ?>">
                                        <?= number_format((float)$ex['score'], 1) ?>
                                    </div>
                                    <span class="text-[10px] text-slate-500"><?= date('d/m/y', strtotime($ex['submitted_at'])) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($active_tab === 'presensi'): ?>
        <!-- Panel 3: Log Presensi Terakhir -->
        <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl">
            <h3 class="text-base font-bold text-white flex items-center gap-2.5 mb-4">
                <span class="text-emerald-400"><i class="fa-solid fa-calendar-check"></i></span>
                Rekam Presensi 15 Hari Terakhir
            </h3>

            <?php if (empty($att['recent'])): ?>
                <p class="text-center py-6 text-slate-500 text-xs">Belum ada catatan presensi tersimpan.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-white/10 text-slate-400 font-semibold">
                                <th class="py-2.5 px-3">Tanggal</th>
                                <th class="py-2.5 px-3">Status</th>
                                <th class="py-2.5 px-3">Jam Masuk</th>
                                <th class="py-2.5 px-3">Jam Pulang</th>
                                <th class="py-2.5 px-3">Metode / Catatan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach ($att['recent'] as $log): ?>
                                <tr class="hover:bg-white/[0.02]">
                                    <td class="py-3 px-3 text-slate-200 font-medium"><?= date('d M Y', strtotime($log['date'])) ?></td>
                                    <td class="py-3 px-3">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold 
                                            <?= $log['status'] === 'hadir' ? 'bg-emerald-500/15 text-emerald-300' : 
                                               ($log['status'] === 'terlambat' ? 'bg-amber-500/15 text-amber-300' : 
                                               ($log['status'] === 'alpa' ? 'bg-rose-500/15 text-rose-300' : 'bg-blue-500/15 text-blue-300')) ?>">
                                            <?= strtoupper($log['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-3 text-slate-300 font-mono"><?= $log['check_in_time'] ?: '-' ?></td>
                                    <td class="py-3 px-3 text-slate-300 font-mono"><?= $log['check_out_time'] ?: '-' ?></td>
                                    <td class="py-3 px-3 text-slate-400 text-[11px]"><?= htmlspecialchars($log['notes'] ?: 'Scan QR Presensi') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($active_tab === 'bk'): ?>
        <!-- Panel 4: Bimbingan Konseling & Disiplin -->
        <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-base font-bold text-white flex items-center gap-2.5">
                    <span class="text-purple-400"><i class="fa-solid fa-scale-balanced"></i></span>
                    Daftar Catatan Kasus & Prestasi
                </h3>
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-xl text-xs font-bold bg-rose-500/15 text-rose-300 border border-rose-500/25">
                        Total Poin Pelanggaran: <?= $bk['violations_points'] ?>
                    </span>
                    <a href="../bk/counseling_letter.php?student_id=<?= $student_id ?>" target="_blank"
                       class="text-xs text-purple-400 hover:underline">
                        <i class="fa-solid fa-print"></i> Cetak Surat BK
                    </a>
                </div>
            </div>

            <?php if (empty($bk['records'])): ?>
                <p class="text-center py-6 text-slate-500 text-xs">Belum ada catatan pelanggaran atau prestasi untuk siswa ini.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($bk['records'] as $r): ?>
                        <div class="p-4 rounded-2xl bg-white/5 border <?= $r['type'] === 'prestasi' ? 'border-emerald-500/20' : 'border-rose-500/20' ?> text-xs">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $r['type'] === 'prestasi' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300' ?>">
                                            <?= strtoupper($r['type']) ?>
                                        </span>
                                        <span class="font-bold text-white"><?= htmlspecialchars($r['title']) ?></span>
                                    </div>
                                    <p class="text-slate-300 text-[11px] mt-1.5"><?= htmlspecialchars($r['notes'] ?: '-') ?></p>
                                    <?php if (!empty($r['action_taken'])): ?>
                                        <div class="text-[11px] text-purple-300 mt-1">
                                            <strong>Tindakan:</strong> <?= htmlspecialchars($r['action_taken']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-right shrink-0">
                                    <span class="text-sm font-bold <?= $r['type'] === 'prestasi' ? 'text-emerald-400' : 'text-rose-400' ?>">
                                        <?= $r['type'] === 'prestasi' ? '+' : '-' ?><?= $r['points'] ?> Poin
                                    </span>
                                    <div class="text-[10px] text-slate-500 mt-0.5"><?= date('d M Y', strtotime($r['date'])) ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($active_tab === 'keuangan'): ?>
        <!-- Panel 5: Keuangan & SPP -->
        <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl">
            <h3 class="text-base font-bold text-white flex items-center gap-2.5 mb-4">
                <span class="text-amber-400"><i class="fa-solid fa-receipt"></i></span>
                Buku Tagihan & Pembayaran SPP
            </h3>

            <?php if (empty($fin['bills'])): ?>
                <p class="text-center py-6 text-slate-500 text-xs">Belum ada tagihan keuangan untuk siswa ini.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-white/10 text-slate-400 font-semibold">
                                <th class="py-2.5 px-3">Jenis Biaya</th>
                                <th class="py-2.5 px-3">Jatuh Tempo</th>
                                <th class="py-2.5 px-3">Nominal Tagihan</th>
                                <th class="py-2.5 px-3">Terbayar</th>
                                <th class="py-2.5 px-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach ($fin['bills'] as $b): ?>
                                <tr class="hover:bg-white/[0.02]">
                                    <td class="py-3 px-3 font-semibold text-white"><?= htmlspecialchars($b['bill_category'] ?? 'SPP Bulanan') ?></td>
                                    <td class="py-3 px-3 text-slate-300"><?= date('d M Y', strtotime($b['due_date'])) ?></td>
                                    <td class="py-3 px-3 text-slate-200">Rp <?= number_format($b['amount'], 0, ',', '.') ?></td>
                                    <td class="py-3 px-3 text-emerald-400">Rp <?= number_format($b['total_paid'] ?? 0, 0, ',', '.') ?></td>
                                    <td class="py-3 px-3">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $b['status'] === 'lunas' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-rose-500/15 text-rose-300' ?>">
                                            <?= strtoupper($b['status']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($active_tab === 'perpustakaan'): ?>
        <!-- Panel 6: Perpustakaan -->
        <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl">
            <h3 class="text-base font-bold text-white flex items-center gap-2.5 mb-4">
                <span class="text-teal-400"><i class="fa-solid fa-book-bookmark"></i></span>
                Riwayat Peminjaman Buku Perpustakaan
            </h3>

            <?php if (empty($lib['loans'])): ?>
                <p class="text-center py-6 text-slate-500 text-xs">Belum ada riwayat peminjaman buku perpustakaan.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($lib['loans'] as $ln): ?>
                        <div class="p-3.5 rounded-2xl bg-white/5 border border-white/5 flex items-center justify-between gap-3 text-xs">
                            <div>
                                <div class="font-bold text-white"><?= htmlspecialchars($ln['book_title']) ?></div>
                                <span class="text-[11px] text-slate-400">Penulis: <?= htmlspecialchars($ln['author'] ?: '-') ?></span>
                            </div>
                            <div class="text-right shrink-0">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $ln['status'] === 'dikembalikan' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-300' ?>">
                                    <?= ucfirst($ln['status']) ?>
                                </span>
                                <div class="text-[10px] text-slate-500 mt-1">Tempo: <?= date('d/m/y', strtotime($ln['due_date'])) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($active_tab === 'ekskul'): ?>
        <!-- Panel 7: Ekstrakurikuler & Prestasi Siswa -->
        <div class="space-y-6">
            <!-- 1. Ekstrakurikuler yang Diikuti -->
            <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2.5">
                        <span class="text-amber-400"><i class="fa-solid fa-shapes"></i></span>
                        Kegiatan Ekstrakurikuler & Penilaian Karakter
                    </h3>
                    <a href="../ekskul/index.php" class="text-xs font-semibold text-amber-400 hover:text-amber-300">
                        Kelola Ekskul <i class="fa-solid fa-arrow-right ml-1"></i>
                    </a>
                </div>

                <?php if (empty($ekskul_data)): ?>
                    <p class="text-center py-6 text-slate-500 text-xs">Siswa ini belum terdaftar pada kegiatan ekstrakurikuler sekolah.</p>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($ekskul_data as $ek): ?>
                            <div class="p-4 rounded-2xl bg-white/5 border border-white/5 flex flex-col justify-between hover:border-amber-500/30 transition">
                                <div>
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex items-center gap-2.5">
                                            <div class="h-9 w-9 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 text-sm">
                                                <i class="fa-solid <?= htmlspecialchars($ek['logo_icon'] ?: 'fa-trophy') ?>"></i>
                                            </div>
                                            <div>
                                                <h4 class="text-xs font-bold text-white"><?= htmlspecialchars($ek['ekskul_name']) ?></h4>
                                                <span class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($ek['ekskul_code']) ?> • <?= ucfirst($ek['ekskul_category']) ?></span>
                                            </div>
                                        </div>
                                        <span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase <?= $ek['status'] === 'aktif' ? 'bg-emerald-500/15 text-emerald-300' : 'bg-amber-500/15 text-amber-300' ?>">
                                            <?= ucfirst($ek['status']) ?>
                                        </span>
                                    </div>

                                    <div class="mt-3 grid grid-cols-2 gap-2 text-[11px] bg-slate-950/40 p-2.5 rounded-xl border border-white/5">
                                        <div>
                                            <span class="text-slate-400 block text-[10px]">Jabatan:</span>
                                            <span class="font-bold text-amber-300 uppercase"><?= htmlspecialchars($ek['role']) ?></span>
                                        </div>
                                        <div>
                                            <span class="text-slate-400 block text-[10px]">Nilai Rapor:</span>
                                            <span class="font-bold text-blue-400">Predikat <?= htmlspecialchars($ek['grade_predicate'] ?: 'A') ?></span>
                                        </div>
                                    </div>

                                    <?php if (!empty($ek['grade_notes'])): ?>
                                        <p class="text-[11px] text-slate-300 mt-2.5 italic">
                                            "<?= htmlspecialchars($ek['grade_notes']) ?>"
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-4 pt-3 border-t border-white/5 flex items-center justify-between text-[11px]">
                                    <span class="text-[10px] text-slate-400">Pembina: <?= htmlspecialchars($ek['leader_name'] ?? 'Guru Pembina') ?></span>
                                    <a href="../ekskul/print_certificate.php?member_id=<?= $ek['id'] ?>" target="_blank"
                                       class="px-2.5 py-1 rounded-lg bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 text-[10px] font-bold transition">
                                        <i class="fa-solid fa-certificate mr-1"></i> Sertifikat
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 2. Portofolio Prestasi & Piagam -->
            <div class="rounded-3xl border border-white/5 bg-slate-900/60 p-6 shadow-xl">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-bold text-white flex items-center gap-2.5">
                        <span class="text-amber-400"><i class="fa-solid fa-award"></i></span>
                        Portofolio Prestasi & Piagam Kejuaraan (<?= count($achieve_data) ?>)
                    </h3>
                    <a href="../ekskul/achievements.php" class="text-xs font-semibold text-amber-400 hover:text-amber-300">
                        Galeri Prestasi <i class="fa-solid fa-arrow-right ml-1"></i>
                    </a>
                </div>

                <?php if (empty($achieve_data)): ?>
                    <p class="text-center py-6 text-slate-500 text-xs">Belum ada catatan kejuaraan / piagam penghargaan yang diraih siswa.</p>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($achieve_data as $ach): ?>
                            <div class="p-4 rounded-2xl bg-amber-500/5 border border-amber-500/20 flex flex-col justify-between hover:border-amber-500/40 transition">
                                <div>
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <span class="text-xs font-extrabold text-amber-300 block"><?= htmlspecialchars($ach['rank_achieved']) ?></span>
                                            <h4 class="text-sm font-bold text-white mt-0.5"><?= htmlspecialchars($ach['competition_name']) ?></h4>
                                        </div>
                                        <span class="px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                            <?= htmlspecialchars($ach['level']) ?>
                                        </span>
                                    </div>

                                    <p class="text-[11px] text-slate-400 mt-2">
                                        <i class="fa-regular fa-calendar mr-1"></i><?= date('d M Y', strtotime($ach['achievement_date'])) ?>
                                        <?= !empty($ach['ekskul_name']) ? ' • Cabang: ' . htmlspecialchars($ach['ekskul_name']) : '' ?>
                                    </p>

                                    <?php if (!empty($ach['description'])): ?>
                                        <p class="text-[11px] text-slate-300 mt-2 italic leading-relaxed">
                                            "<?= htmlspecialchars($ach['description']) ?>"
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-4 pt-3 border-t border-white/5 flex items-center justify-between text-[11px]">
                                    <span class="text-[10px] text-slate-400">Verifikator: <?= htmlspecialchars($ach['verifier_name'] ?? 'Sistem') ?></span>
                                    <a href="../ekskul/print_certificate.php?achievement_id=<?= $ach['id'] ?>" target="_blank"
                                       class="px-2.5 py-1 rounded-lg bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 text-[10px] font-bold transition">
                                        <i class="fa-solid fa-print mr-1"></i> Piagam
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
require_once __DIR__ . "/../includes/footer.php";
?>
