<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Pengguna';
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_admin_or_staff = in_array($user_role, ['administrator', 'staf'], true);

$ekskul_id = (int)($_GET['id'] ?? 0);
if ($ekskul_id <= 0) {
    header("Location: index.php?error=invalid_id");
    exit;
}

$ekskul = getExtracurricularById($pdo, $ekskul_id);
if (!$ekskul) {
    header("Location: index.php?error=not_found");
    exit;
}

// Cek apakah user adalah pembina ekskul ini
$is_this_leader = ($user_role === 'guru' && (int)$ekskul['leader_instructor_id'] === $user_id);
$can_manage     = ($is_admin_or_staff || $is_this_leader);

$message = "";
$message_type = "";
$active_tab = $_GET['tab'] ?? 'anggota';

// -------------------------------------------------------------
// POST HANDLERS (STATUS ANGGOTA, NILAI RAPOR, BUAT SESI LATIHAN, PRESTASI)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan CSRF tidak valid. Silakan muat ulang.";
        $message_type = "error";
    } elseif (!$can_manage) {
        $message = "Anda tidak memiliki hak wewenang untuk mengubah data ekstrakurikuler ini.";
        $message_type = "error";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. UPDATE STATUS / PERAN / NILAI RAPOR ANGGOTA
        if ($action === 'update_member') {
            $member_id  = (int)($_POST['member_id'] ?? 0);
            $role       = trim($_POST['role'] ?? 'anggota');
            $status     = trim($_POST['status'] ?? 'aktif');
            $predicate  = trim($_POST['grade_predicate'] ?? 'A');
            $notes      = trim($_POST['grade_notes'] ?? '');

            $stmt_up = $pdo->prepare("
                UPDATE extracurricular_members 
                SET role = ?, status = ?, grade_predicate = ?, grade_notes = ?, updated_at = NOW()
                WHERE id = ? AND extracurricular_id = ?
            ");
            $stmt_up->execute([$role, $status, $predicate, $notes, $member_id, $ekskul_id]);
            logActivity($pdo, 'UPDATE_EKSKUL_MEMBER', "Mengubah data anggota ID #$member_id di ekskul ID #$ekskul_id");
            $message = "Data keanggotaan dan predikat nilai rapor berhasil disimpan.";
            $message_type = "success";
            $active_tab = 'anggota';
        }

        // 2. VERIFIKASI CEPAT PENDAFTARAN ANGGOTA (TERIMA / TOLAK)
        elseif ($action === 'verify_application') {
            $member_id = (int)($_POST['member_id'] ?? 0);
            $decision  = trim($_POST['decision'] ?? 'aktif'); // 'aktif' or 'ditolak'

            $stmt_v = $pdo->prepare("UPDATE extracurricular_members SET status = ? WHERE id = ? AND extracurricular_id = ?");
            $stmt_v->execute([$decision, $member_id, $ekskul_id]);
            logActivity($pdo, 'VERIFY_EKSKUL_APPLICANT', "Verifikasi anggota ID #$member_id menjadi: $decision");
            $message = ($decision === 'aktif') ? "Pendaftaran siswa berhasil diterima sebagai anggota aktif." : "Pendaftaran siswa telah ditolak.";
            $message_type = "success";
            $active_tab = 'anggota';
        }

        // 3. KELUARKAN / HAPUS ANGGOTA
        elseif ($action === 'delete_member') {
            $member_id = (int)($_POST['member_id'] ?? 0);
            $stmt_del = $pdo->prepare("DELETE FROM extracurricular_members WHERE id = ? AND extracurricular_id = ?");
            $stmt_del->execute([$member_id, $ekskul_id]);
            logActivity($pdo, 'REMOVE_EKSKUL_MEMBER', "Menghapus anggota ID #$member_id dari ekskul ID #$ekskul_id");
            $message = "Anggota berhasil dikeluarkan dari ekstrakurikuler.";
            $message_type = "success";
            $active_tab = 'anggota';
        }

        // 4. BUAT SESI LATIHAN / KEGIATAN BARU
        elseif ($action === 'create_activity') {
            $act_date  = trim($_POST['activity_date'] ?? date('Y-m-d'));
            $title     = trim($_POST['activity_title'] ?? '');
            $desc      = trim($_POST['description'] ?? '');
            
            if (empty($title)) {
                $message = "Judul agenda sesi latihan wajib diisi.";
                $message_type = "error";
            } else {
                $stmt_act = $pdo->prepare("
                    INSERT INTO extracurricular_activities 
                    (extracurricular_id, activity_date, activity_title, description, instructor_id)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt_act->execute([$ekskul_id, $act_date, $title, $desc, $user_id]);
                $new_act_id = (int)$pdo->lastInsertId();

                // Otomatis populate attendance template untuk semua anggota aktif (status 'hadir' default)
                $active_stus = $pdo->prepare("SELECT student_id FROM extracurricular_members WHERE extracurricular_id = ? AND status = 'aktif'");
                $active_stus->execute([$ekskul_id]);
                $active_ids = $active_stus->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($active_ids)) {
                    $stmt_att_in = $pdo->prepare("INSERT IGNORE INTO extracurricular_attendance (activity_id, student_id, status) VALUES (?, ?, 'hadir')");
                    foreach ($active_ids as $sid) {
                        $stmt_att_in->execute([$new_act_id, $sid]);
                    }
                }

                logActivity($pdo, 'CREATE_EKSKUL_ACTIVITY', "Membuat sesi latihan baru: '$title' di ekskul: " . $ekskul['name']);
                $message = "Sesi latihan baru berhasil dibuat dan daftar presensi siap diisi!";
                $message_type = "success";
                $active_tab = 'kegiatan';
            }
        }

        // 5. CATAT PRESTASI BARU
        elseif ($action === 'add_achievement') {
            $student_id = (int)($_POST['student_id'] ?? 0);
            $comp_name  = trim($_POST['competition_name'] ?? '');
            $level      = trim($_POST['level'] ?? 'kabupaten');
            $rank       = trim($_POST['rank_achieved'] ?? 'Juara 1');
            $ach_date   = trim($_POST['achievement_date'] ?? date('Y-m-d'));
            $desc       = trim($_POST['description'] ?? '');

            if ($student_id <= 0 || empty($comp_name)) {
                $message = "Nama kompetisi dan perwakilan siswa wajib dipilih.";
                $message_type = "error";
            } else {
                $stmt_ach = $pdo->prepare("
                    INSERT INTO extracurricular_achievements 
                    (extracurricular_id, student_id, competition_name, level, rank_achieved, achievement_date, description, verified_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_ach->execute([$ekskul_id, $student_id, $comp_name, $level, $rank, $ach_date, $desc, $user_id]);
                logActivity($pdo, 'ADD_EKSKUL_ACHIEVEMENT', "Mencatat prestasi '$comp_name' ($rank) untuk siswa ID #$student_id");
                $message = "Catatan prestasi kejuaraan berhasil didokumentasikan ke sistem!";
                $message_type = "success";
                $active_tab = 'prestasi';
            }
        }
    }
}

// -------------------------------------------------------------
// AMBIL DATA DETAIL TAB
// -------------------------------------------------------------
// 1. Daftar Anggota
$stmt_members = $pdo->prepare("
    SELECT em.*, u.name as student_name, u.nisn, u.gender, c.name as class_name
    FROM extracurricular_members em
    JOIN users u ON em.student_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE em.extracurricular_id = ?
    ORDER BY FIELD(em.status, 'menunggu', 'aktif', 'nonaktif', 'ditolak'), 
             FIELD(em.role, 'ketua', 'wakil_ketua', 'sekretaris', 'bendahara', 'anggota'),
             u.name ASC
");
$stmt_members->execute([$ekskul_id]);
$members = $stmt_members->fetchAll(PDO::FETCH_ASSOC);

// 2. Daftar Sesi Kegiatan & Presensi
$stmt_activities = $pdo->prepare("
    SELECT ea.*, u.name as instructor_name,
           (SELECT COUNT(*) FROM extracurricular_attendance WHERE activity_id = ea.id AND status = 'hadir') as count_hadir,
           (SELECT COUNT(*) FROM extracurricular_attendance WHERE activity_id = ea.id AND status = 'izin') as count_izin,
           (SELECT COUNT(*) FROM extracurricular_attendance WHERE activity_id = ea.id AND status = 'sakit') as count_sakit,
           (SELECT COUNT(*) FROM extracurricular_attendance WHERE activity_id = ea.id AND status = 'alpa') as count_alpa,
           (SELECT COUNT(*) FROM extracurricular_attendance WHERE activity_id = ea.id) as total_attendance
    FROM extracurricular_activities ea
    LEFT JOIN users u ON ea.instructor_id = u.id
    WHERE ea.extracurricular_id = ?
    ORDER BY ea.activity_date DESC, ea.id DESC
");
$stmt_activities->execute([$ekskul_id]);
$activities = $stmt_activities->fetchAll(PDO::FETCH_ASSOC);

// 3. Daftar Prestasi & Kejuaraan
$stmt_achievements = $pdo->prepare("
    SELECT ach.*, u.name as student_name, u.nisn, c.name as class_name, uv.name as verifier_name
    FROM extracurricular_achievements ach
    JOIN users u ON ach.student_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN users uv ON ach.verified_by = uv.id
    WHERE ach.extracurricular_id = ?
    ORDER BY ach.achievement_date DESC
");
$stmt_achievements->execute([$ekskul_id]);
$achievements = $stmt_achievements->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Detail " . htmlspecialchars($ekskul['name']);
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Top Breadcrumb & Header Info -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-slate-400 mb-1">
                <a href="../index.php" class="hover:text-blue-400 transition">Dashboard</a>
                <span><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
                <a href="index.php" class="hover:text-blue-400 transition">Ekstrakurikuler</a>
                <span><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
                <span class="text-amber-400 font-medium"><?= htmlspecialchars($ekskul['code']) ?></span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-tr from-amber-500 to-orange-500 text-white shadow-lg shadow-amber-500/25">
                    <i class="fa-solid <?= htmlspecialchars($ekskul['logo_icon'] ?: 'fa-trophy') ?> text-xl"></i>
                </span>
                <span><?= htmlspecialchars($ekskul['name']) ?></span>
            </h1>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="index.php" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-300 transition">
                <i class="fa-solid fa-arrow-left mr-1.5"></i> Kembali
            </a>

            <?php if ($can_manage): ?>
                <button type="button" onclick="openModal('createActivityModal')"
                        class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white shadow-lg shadow-blue-500/20 transition cursor-pointer">
                    <i class="fa-solid fa-plus mr-1"></i> Buat Sesi Latihan
                </button>
                <button type="button" onclick="openModal('addAchievementModal')"
                        class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-xs font-bold text-white shadow-lg shadow-amber-500/20 transition cursor-pointer">
                    <i class="fa-solid fa-medal mr-1"></i> Catat Prestasi
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Notifikasi -->
    <?php if (!empty($message)): ?>
        <div class="p-4 rounded-2xl border text-xs font-medium flex items-center justify-between gap-3 <?= $message_type === 'success' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' : 'bg-rose-500/10 border-rose-500/30 text-rose-300' ?>">
            <div class="flex items-center gap-2.5">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-rose-400' ?> text-base"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <!-- Banner Profil Ekstrakurikuler -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/90 p-6 shadow-xl relative overflow-hidden">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div class="md:col-span-3 space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[10px] font-extrabold uppercase px-2.5 py-0.5 rounded-full border <?= $ekskul['category'] === 'wajib' ? 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30' : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' ?>">
                        Ekstrakurikuler <?= ucfirst($ekskul['category']) ?>
                    </span>
                    <span class="text-[10px] font-mono font-bold text-slate-400 bg-white/5 px-2 py-0.5 rounded border border-white/5">
                        Kode: <?= htmlspecialchars($ekskul['code']) ?>
                    </span>
                    <span class="text-[10px] font-semibold text-slate-300 bg-white/5 px-2 py-0.5 rounded border border-white/5">
                        <i class="fa-solid fa-location-dot text-rose-400 mr-1"></i><?= htmlspecialchars($ekskul['location']) ?>
                    </span>
                </div>

                <p class="text-xs text-slate-300 leading-relaxed max-w-3xl">
                    <?= nl2br(htmlspecialchars($ekskul['description'] ?: 'Organisasi ekstrakurikuler resmi di lingkungan sekolah.')) ?>
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2 text-xs">
                    <div class="p-3 rounded-2xl bg-slate-950/60 border border-white/5">
                        <span class="text-[10px] text-slate-400 block mb-0.5">Guru Pembina / Pelatih</span>
                        <span class="font-bold text-white truncate block"><?= htmlspecialchars($ekskul['leader_name'] ?? 'Belum Ditugaskan') ?></span>
                    </div>
                    <div class="p-3 rounded-2xl bg-slate-950/60 border border-white/5">
                        <span class="text-[10px] text-slate-400 block mb-0.5">Jadwal Pertemuan Rutin</span>
                        <span class="font-bold text-amber-300 block"><?= htmlspecialchars($ekskul['schedule_day']) ?>, <?= htmlspecialchars($ekskul['schedule_time']) ?></span>
                    </div>
                    <div class="p-3 rounded-2xl bg-slate-950/60 border border-white/5">
                        <span class="text-[10px] text-slate-400 block mb-0.5">Kapasitas Anggota</span>
                        <span class="font-bold text-emerald-300 block"><?= count($members) ?> / <?= (int)$ekskul['max_members'] ?> Siswa</span>
                    </div>
                </div>
            </div>

            <!-- Quick Metrics Box -->
            <div class="flex flex-col justify-center gap-2.5 p-4 rounded-2xl bg-slate-950/80 border border-white/5">
                <div class="text-center pb-2 border-b border-white/5">
                    <span class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider block">Anggota Aktif</span>
                    <span class="text-3xl font-extrabold text-white mt-1 block"><?= count(array_filter($members, fn($m) => $m['status'] === 'aktif')) ?></span>
                </div>
                <div class="grid grid-cols-2 gap-2 text-center text-xs pt-1">
                    <div>
                        <span class="text-[10px] text-slate-400 block">Total Sesi</span>
                        <span class="font-bold text-blue-400 text-sm"><?= count($activities) ?></span>
                    </div>
                    <div>
                        <span class="text-[10px] text-slate-400 block">Prestasi</span>
                        <span class="font-bold text-amber-400 text-sm"><?= count($achievements) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex border-b border-white/10 gap-2">
        <a href="detail.php?id=<?= $ekskul_id ?>&tab=anggota"
           class="px-5 py-3 text-xs font-bold transition border-b-2 flex items-center gap-2 <?= $active_tab === 'anggota' ? 'border-amber-500 text-amber-400' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
            <i class="fa-solid fa-users"></i>
            <span>Anggota & Nilai Rapor (<?= count($members) ?>)</span>
        </a>
        <a href="detail.php?id=<?= $ekskul_id ?>&tab=kegiatan"
           class="px-5 py-3 text-xs font-bold transition border-b-2 flex items-center gap-2 <?= $active_tab === 'kegiatan' ? 'border-amber-500 text-amber-400' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Sesi Latihan & Presensi (<?= count($activities) ?>)</span>
        </a>
        <a href="detail.php?id=<?= $ekskul_id ?>&tab=prestasi"
           class="px-5 py-3 text-xs font-bold transition border-b-2 flex items-center gap-2 <?= $active_tab === 'prestasi' ? 'border-amber-500 text-amber-400' : 'border-transparent text-slate-400 hover:text-slate-200' ?>">
            <i class="fa-solid fa-trophy"></i>
            <span>Prestasi & Juara (<?= count($achievements) ?>)</span>
        </a>
    </div>

    <!-- ============================================================== -->
    <!-- TAB 1: MANAJEMEN ANGGOTA & NILAI RAPOR -->
    <!-- ============================================================== -->
    <?php if ($active_tab === 'anggota'): ?>
        <div class="rounded-3xl border border-white/10 bg-slate-900/90 shadow-xl overflow-hidden">
            <div class="p-4 border-b border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-950/40">
                <div>
                    <h2 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-address-book text-amber-400"></i>
                        <span>Daftar Peserta Didik & Integrasi Penilaian Rapor</span>
                    </h2>
                    <p class="text-[11px] text-slate-400 mt-0.5">Predikat nilai dan catatan pembina akan otomatis ditarik ke dalam lembar e-rapor siswa.</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-400">Total: <strong class="text-white"><?= count($members) ?></strong> Siswa</span>
                </div>
            </div>

            <?php if (empty($members)): ?>
                <div class="p-12 text-center">
                    <i class="fa-solid fa-user-slash text-3xl text-slate-500 mb-2"></i>
                    <p class="text-xs font-semibold text-slate-300">Belum ada siswa yang bergabung.</p>
                    <p class="text-[11px] text-slate-500 mt-0.5">Siswa dapat mendaftar mandiri melalui halaman katalog ekstrakurikuler.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950/60 text-slate-400 border-b border-white/10 uppercase text-[10px] tracking-wider">
                            <tr>
                                <th class="py-3 px-4">No</th>
                                <th class="py-3 px-4">Nama Siswa & NISN</th>
                                <th class="py-3 px-4">Kelas</th>
                                <th class="py-3 px-4">Jabatan</th>
                                <th class="py-3 px-4">Status</th>
                                <th class="py-3 px-4">Nilai Rapor</th>
                                <th class="py-3 px-4">Catatan Pembina</th>
                                <?php if ($can_manage): ?>
                                    <th class="py-3 px-4 text-center">Aksi</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php 
                            $no = 1;
                            foreach ($members as $m): 
                                $badge_status = match($m['status']) {
                                    'aktif'   => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
                                    'menunggu'=> 'bg-amber-500/15 text-amber-300 border-amber-500/30',
                                    'ditolak' => 'bg-rose-500/15 text-rose-300 border-rose-500/30',
                                    default   => 'bg-slate-800 text-slate-400 border-slate-700'
                                };
                            ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="py-3 px-4 text-slate-400 font-mono"><?= $no++ ?></td>
                                    <td class="py-3 px-4">
                                        <div class="font-bold text-white"><?= htmlspecialchars($m['student_name']) ?></div>
                                        <div class="text-[10px] text-slate-400 font-mono">NISN: <?= htmlspecialchars($m['nisn'] ?: '-') ?></div>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300">
                                        <?= htmlspecialchars($m['class_name'] ?? 'Siswa') ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="font-bold uppercase tracking-wider text-[10px] px-2 py-0.5 rounded border <?= $m['role'] === 'ketua' ? 'bg-amber-500/15 text-amber-300 border-amber-500/30' : 'bg-slate-800 text-slate-300 border-slate-700' ?>">
                                            <?= htmlspecialchars($m['role']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full border <?= $badge_status ?>">
                                            <?= ucfirst($m['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-lg font-bold text-xs bg-blue-500/20 text-blue-300 border border-blue-500/30">
                                            <?= htmlspecialchars($m['grade_predicate'] ?: 'A') ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300 max-w-xs truncate" title="<?= htmlspecialchars($m['grade_notes'] ?? '') ?>">
                                        <?= htmlspecialchars($m['grade_notes'] ?: 'Belum ada catatan pembina.') ?>
                                    </td>
                                    
                                    <?php if ($can_manage): ?>
                                        <td class="py-3 px-4 text-center">
                                            <div class="flex items-center justify-center gap-1.5">
                                                <!-- Jika masih menunggu pendaftaran, tombol Terima / Tolak -->
                                                <?php if ($m['status'] === 'menunggu'): ?>
                                                    <form method="POST" class="inline">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="verify_application">
                                                        <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                                        <input type="hidden" name="decision" value="aktif">
                                                        <button type="submit" class="p-1.5 rounded-lg bg-emerald-500/20 hover:bg-emerald-500 text-emerald-300 hover:text-white transition" title="Terima Siswa">
                                                            <i class="fa-solid fa-check text-xs"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="inline">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="verify_application">
                                                        <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                                        <input type="hidden" name="decision" value="ditolak">
                                                        <button type="submit" class="p-1.5 rounded-lg bg-rose-500/20 hover:bg-rose-500 text-rose-300 hover:text-white transition" title="Tolak">
                                                            <i class="fa-solid fa-xmark text-xs"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <!-- Tombol Edit Nilai & Jabatan -->
                                                <button type="button" 
                                                        onclick="editMember(<?= htmlspecialchars(json_encode($m)) ?>)"
                                                        class="p-1.5 rounded-lg bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 transition" title="Edit Nilai Rapor & Jabatan">
                                                    <i class="fa-solid fa-pen-to-square text-xs"></i>
                                                </button>

                                                <!-- Tombol Cetak Sertifikat Keaktifan -->
                                                <a href="print_certificate.php?member_id=<?= $m['id'] ?>" target="_blank"
                                                   class="p-1.5 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 transition" title="Cetak Sertifikat Keaktifan">
                                                    <i class="fa-solid fa-certificate text-xs"></i>
                                                </a>

                                                <!-- Hapus dari ekskul -->
                                                <form method="POST" onsubmit="return confirm('Keluarkan siswa ini dari ekstrakurikuler?');" class="inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="delete_member">
                                                    <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                                                    <button type="submit" class="p-1.5 rounded-lg text-slate-500 hover:text-rose-400 hover:bg-rose-500/10 transition" title="Keluarkan Anggota">
                                                        <i class="fa-solid fa-trash-can text-xs"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ============================================================== -->
    <!-- TAB 2: SESI LATIHAN & PRESENSI -->
    <!-- ============================================================== -->
    <?php if ($active_tab === 'kegiatan'): ?>
        <div class="space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-clipboard-check text-blue-400"></i>
                        <span>Agenda Pertemuan Latihan & Kehadiran Anggota</span>
                    </h2>
                    <p class="text-[11px] text-slate-400">Catatan jadwal pertemuan tatap muka latihan dan rekap kehadiran.</p>
                </div>

                <?php if ($can_manage): ?>
                    <button type="button" onclick="openModal('createActivityModal')"
                            class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white transition self-start sm:self-auto cursor-pointer">
                        <i class="fa-solid fa-plus mr-1"></i> Tambah Pertemuan Baru
                    </button>
                <?php endif; ?>
            </div>

            <?php if (empty($activities)): ?>
                <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-12 text-center">
                    <i class="fa-solid fa-calendar-xmark text-3xl text-slate-500 mb-2"></i>
                    <p class="text-xs font-semibold text-slate-300">Belum ada agenda pertemuan latihan yang tercatat.</p>
                    <p class="text-[11px] text-slate-500 mt-0.5">Klik tombol "Tambah Pertemuan Baru" untuk mencatat topik latihan perdana.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($activities as $act): 
                        $total_att = (int)$act['total_attendance'];
                        $hadir     = (int)$act['count_hadir'];
                        $pct_att   = ($total_att > 0) ? round(($hadir / $total_att) * 100) : 0;
                    ?>
                        <div class="rounded-3xl border border-white/10 bg-slate-900/90 p-5 flex flex-col justify-between hover:border-blue-500/40 transition shadow-lg">
                            <div>
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-2.5">
                                        <div class="h-10 w-10 rounded-2xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400 text-sm">
                                            <i class="fa-solid fa-calendar-day"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-xs font-bold text-white"><?= htmlspecialchars($act['activity_title']) ?></h3>
                                            <p class="text-[10px] text-slate-400">
                                                <i class="fa-regular fa-clock mr-1"></i><?= date('d M Y', strtotime($act['activity_date'])) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <span class="text-[10px] font-bold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded-full">
                                        <?= $pct_att ?>% Hadir
                                    </span>
                                </div>

                                <p class="text-[11px] text-slate-300 mt-3 line-clamp-2">
                                    <?= nl2br(htmlspecialchars($act['description'] ?: 'Tidak ada catatan materi khusus.')) ?>
                                </p>

                                <!-- Statistik Hadir / Izin / Sakit / Alpa -->
                                <div class="mt-4 grid grid-cols-4 gap-2 text-center text-[10px]">
                                    <div class="p-2 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 font-bold">
                                        <span>Hadir: <?= $hadir ?></span>
                                    </div>
                                    <div class="p-2 rounded-xl bg-blue-500/10 border border-blue-500/20 text-blue-300 font-bold">
                                        <span>Izin: <?= (int)$act['count_izin'] ?></span>
                                    </div>
                                    <div class="p-2 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 font-bold">
                                        <span>Sakit: <?= (int)$act['count_sakit'] ?></span>
                                    </div>
                                    <div class="p-2 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-300 font-bold">
                                        <span>Alpa: <?= (int)$act['count_alpa'] ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5 pt-3 border-t border-white/5 flex items-center justify-between">
                                <span class="text-[10px] text-slate-400">
                                    Pelatih: <?= htmlspecialchars($act['instructor_name'] ?? 'Guru Pembina') ?>
                                </span>
                                <?php if ($can_manage): ?>
                                    <a href="attendance.php?activity_id=<?= $act['id'] ?>" 
                                       class="px-3 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-[11px] font-bold text-white transition flex items-center gap-1.5 shadow-sm">
                                        <i class="fa-solid fa-list-check"></i>
                                        <span>Input Presensi</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ============================================================== -->
    <!-- TAB 3: PRESTASI & SERTIFIKAT JUARA -->
    <!-- ============================================================== -->
    <?php if ($active_tab === 'prestasi'): ?>
        <div class="space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-award text-amber-400"></i>
                        <span>Portofolio Prestasi & Rekam Jejak Kejuaraan</span>
                    </h2>
                    <p class="text-[11px] text-slate-400">Pencapaian piala, medali, dan piagam penghargaan yang diraih perwakilan ekstrakurikuler.</p>
                </div>

                <?php if ($can_manage): ?>
                    <button type="button" onclick="openModal('addAchievementModal')"
                            class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-xs font-bold text-white transition self-start sm:self-auto cursor-pointer">
                        <i class="fa-solid fa-plus mr-1"></i> Catat Prestasi Baru
                    </button>
                <?php endif; ?>
            </div>

            <?php if (empty($achievements)): ?>
                <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-12 text-center">
                    <i class="fa-solid fa-trophy text-3xl text-slate-500 mb-2"></i>
                    <p class="text-xs font-semibold text-slate-300">Belum ada catatan kejuaraan yang didaftarkan.</p>
                    <p class="text-[11px] text-slate-500 mt-0.5">Dokumentasikan seluruh pencapaian lomba siswa di sini.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($achievements as $ach): 
                        $level_badge = match($ach['level']) {
                            'internasional' => 'bg-purple-500/20 text-purple-300 border-purple-500/30',
                            'nasional'      => 'bg-rose-500/20 text-rose-300 border-rose-500/30',
                            'provinsi'      => 'bg-amber-500/20 text-amber-300 border-amber-500/30',
                            'kabupaten'     => 'bg-blue-500/20 text-blue-300 border-blue-500/30',
                            default         => 'bg-slate-800 text-slate-400 border-slate-700'
                        };
                    ?>
                        <div class="rounded-3xl border border-amber-500/20 bg-slate-900/90 p-5 flex flex-col justify-between hover:border-amber-500/50 transition shadow-lg relative group">
                            <div>
                                <div class="flex items-start justify-between gap-2">
                                    <div class="h-10 w-10 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 text-lg">
                                        <i class="fa-solid fa-medal"></i>
                                    </div>
                                    <span class="text-[9px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-full border <?= $level_badge ?>">
                                        Tingkat <?= ucfirst($ach['level']) ?>
                                    </span>
                                </div>

                                <div class="mt-3">
                                    <span class="text-xs font-extrabold text-amber-300 block"><?= htmlspecialchars($ach['rank_achieved']) ?></span>
                                    <h3 class="text-sm font-bold text-white mt-0.5 leading-snug"><?= htmlspecialchars($ach['competition_name']) ?></h3>
                                    <p class="text-[10px] text-slate-400 mt-1">
                                        <i class="fa-regular fa-calendar mr-1"></i><?= date('d M Y', strtotime($ach['achievement_date'])) ?>
                                    </p>
                                </div>

                                <div class="mt-3.5 p-3 rounded-2xl bg-slate-950/60 border border-white/5 space-y-1 text-[11px]">
                                    <div class="flex items-center justify-between">
                                        <span class="text-slate-400">Pemenang:</span>
                                        <span class="font-bold text-white"><?= htmlspecialchars($ach['student_name']) ?></span>
                                    </div>
                                    <div class="flex items-center justify-between text-[10px]">
                                        <span class="text-slate-400">Kelas / NISN:</span>
                                        <span class="text-slate-300 font-mono"><?= htmlspecialchars($ach['class_name'] ?? 'Siswa') ?> (<?= htmlspecialchars($ach['nisn'] ?: '-') ?>)</span>
                                    </div>
                                </div>

                                <?php if (!empty($ach['description'])): ?>
                                    <p class="text-[11px] text-slate-300 mt-2.5 italic">
                                        "<?= htmlspecialchars($ach['description']) ?>"
                                    </p>
                                <?php endif; ?>
                            </div>

                            <div class="mt-4 pt-3 border-t border-white/5 flex items-center justify-between text-[10px] text-slate-400">
                                <span>Diverifikasi: <?= htmlspecialchars($ach['verifier_name'] ?? 'Admin') ?></span>
                                <a href="achievements.php" class="text-amber-400 hover:text-amber-300 font-semibold">
                                    Lihat Galeri <i class="fa-solid fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ============================================================== -->
<!-- MODAL: EDIT ANGGOTA & NILAI RAPOR (PEMBINA / ADMIN) -->
<!-- ============================================================== -->
<div id="editMemberModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-user-pen text-amber-400"></i>
                <span>Edit Keanggotaan & Predikat Rapor</span>
            </h3>
            <button type="button" onclick="closeModal('editMemberModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_member">
            <input type="hidden" name="member_id" id="modal_member_id">

            <div>
                <label class="block text-xs font-semibold text-slate-400 mb-1">Nama Siswa</label>
                <input type="text" id="modal_student_name" readonly
                       class="w-full bg-slate-950/60 border border-white/5 rounded-xl px-3 py-2 text-xs text-slate-300 font-bold">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Jabatan di Ekskul</label>
                    <select name="role" id="modal_role" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200">
                        <option value="anggota">Anggota</option>
                        <option value="ketua">Ketua</option>
                        <option value="wakil_ketua">Wakil Ketua</option>
                        <option value="sekretaris">Sekretaris</option>
                        <option value="bendahara">Bendahara</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Status Keaktifan</label>
                    <select name="status" id="modal_status" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200">
                        <option value="aktif">Aktif</option>
                        <option value="menunggu">Menunggu</option>
                        <option value="nonaktif">Nonaktif</option>
                        <option value="ditolak">Ditolak</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Predikat Nilai Rapor</label>
                <select name="grade_predicate" id="modal_grade_predicate" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200">
                    <option value="A">A - Sangat Baik (Aktif & Berprestasi)</option>
                    <option value="B">B - Baik (Konsisten & Bertanggung Jawab)</option>
                    <option value="C">C - Cukup (Perlu Ditingkatkan Keaktifannya)</option>
                    <option value="D">D - Kurang</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Catatan Pembina untuk Rapor</label>
                <textarea name="grade_notes" id="modal_grade_notes" rows="3" placeholder="Tuliskan catatan perkembangan karakter, kedisiplinan, dan capaian siswa..."
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-xs text-slate-200 focus:outline-none focus:border-amber-500"></textarea>
            </div>

            <div class="pt-3 border-t border-white/10 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('editMemberModal')" class="px-4 py-2 rounded-xl bg-slate-800 text-xs font-semibold text-white">Batal</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-xs font-bold text-white shadow-lg shadow-amber-500/20">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: BUAT SESI LATIHAN BARU -->
<!-- ============================================================== -->
<div id="createActivityModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-calendar-plus text-blue-400"></i>
                <span>Buat Agenda Sesi Latihan Baru</span>
            </h3>
            <button type="button" onclick="closeModal('createActivityModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_activity">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Tanggal Sesi Latihan</label>
                <input type="date" name="activity_date" value="<?= date('Y-m-d') ?>" required
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Judul / Topik Agenda</label>
                <input type="text" name="activity_title" required placeholder="Contoh: Latihan Formasi & Ketahanan Fisik"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Deskripsi / Materi Latihan</label>
                <textarea name="description" rows="3" placeholder="Uraikan rangkaian kegiatan tatap muka..."
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-xs text-slate-200"></textarea>
            </div>

            <div class="pt-3 border-t border-white/10 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('createActivityModal')" class="px-4 py-2 rounded-xl bg-slate-800 text-xs font-semibold text-white">Batal</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white shadow-lg shadow-blue-500/20">Buat Sesi & Template Presensi</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: CATAT PRESTASI BARU -->
<!-- ============================================================== -->
<div id="addAchievementModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-trophy text-amber-400"></i>
                <span>Catat Prestasi Kejuaraan Siswa</span>
            </h3>
            <button type="button" onclick="closeModal('addAchievementModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_achievement">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Siswa Peraih Prestasi <span class="text-rose-400">*</span></label>
                <select name="student_id" required class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200">
                    <option value="">-- Pilih Siswa Anggota --</option>
                    <?php foreach ($members as $mem): ?>
                        <option value="<?= $mem['student_id'] ?>"><?= htmlspecialchars($mem['student_name']) ?> (<?= htmlspecialchars($mem['class_name'] ?? 'Siswa') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Kompetisi / Turnamen <span class="text-rose-400">*</span></label>
                <input type="text" name="competition_name" required placeholder="Contoh: Turnamen Futsal Piala Walikota 2026"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Peringkat / Juara</label>
                    <input type="text" name="rank_achieved" required placeholder="Contoh: Juara 1 / Medali Emas"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tingkat Penyelenggaraan</label>
                    <select name="level" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200">
                        <option value="sekolah">Sekolah</option>
                        <option value="kecamatan">Kecamatan</option>
                        <option value="kabupaten" selected>Kabupaten / Kota</option>
                        <option value="provinsi">Provinsi</option>
                        <option value="nasional">Nasional</option>
                        <option value="internasional">Internasional</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Tanggal Kejuaraan</label>
                <input type="date" name="achievement_date" value="<?= date('Y-m-d') ?>" required
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Deskripsi Tambahan / Catatan Dewan Juri</label>
                <textarea name="description" rows="2" placeholder="Keterangan kategori lomba atau penghargaan khusus..."
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-xs text-slate-200"></textarea>
            </div>

            <div class="pt-3 border-t border-white/10 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('addAchievementModal')" class="px-4 py-2 rounded-xl bg-slate-800 text-xs font-semibold text-white">Batal</button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-xs font-bold text-white shadow-lg shadow-amber-500/20">Simpan Prestasi</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('hidden');
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.add('hidden');
}
function editMember(data) {
    document.getElementById('modal_member_id').value = data.id;
    document.getElementById('modal_student_name').value = data.student_name + ' (' + (data.nisn || '-') + ')';
    document.getElementById('modal_role').value = data.role;
    document.getElementById('modal_status').value = data.status;
    document.getElementById('modal_grade_predicate').value = data.grade_predicate || 'A';
    document.getElementById('modal_grade_notes').value = data.grade_notes || '';
    openModal('editMemberModal');
}
</script>

<?php
require_once __DIR__ . "/../includes/footer.php";
?>
