<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Pengguna';
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_admin_or_staff = in_array($user_role, ['administrator', 'staf'], true);
$is_teacher = ($user_role === 'guru');

$message = "";
$message_type = "";

// -------------------------------------------------------------
// POST HANDLERS (DAFTAR EKSKUL, BUAT EKSKUL, EDIT EKSKUL)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan CSRF tidak valid. Silakan muat ulang halaman.";
        $message_type = "error";
    } else {
        $action = $_POST['action'] ?? '';

        // 1. SISWA MENDAFTAR EKSKUL MANDIRI
        if ($action === 'join_ekskul' && $user_role === 'siswa') {
            $ekskul_id = (int)($_POST['ekskul_id'] ?? 0);
            
            // Cek ketersediaan ekskul & kuota
            $stmt_chk = $pdo->prepare("
                SELECT e.*, 
                       (SELECT COUNT(*) FROM extracurricular_members WHERE extracurricular_id = e.id AND status = 'aktif') as current_members
                FROM extracurriculars e 
                WHERE e.id = ? AND e.is_active = 1
            ");
            $stmt_chk->execute([$ekskul_id]);
            $ekskul = $stmt_chk->fetch();

            if (!$ekskul) {
                $message = "Ekstrakurikuler tidak ditemukan atau sedang dinonaktifkan.";
                $message_type = "error";
            } elseif ($ekskul['max_members'] > 0 && $ekskul['current_members'] >= $ekskul['max_members']) {
                $message = "Kuota anggota untuk ekstrakurikuler " . htmlspecialchars($ekskul['name']) . " sudah penuh.";
                $message_type = "error";
            } else {
                // Cek apakah sudah pernah mendaftar
                $stmt_mem = $pdo->prepare("SELECT id, status FROM extracurricular_members WHERE extracurricular_id = ? AND student_id = ?");
                $stmt_mem->execute([$ekskul_id, $user_id]);
                $existing = $stmt_mem->fetch();

                if ($existing) {
                    if ($existing['status'] === 'aktif') {
                        $message = "Anda sudah terdaftar aktif sebagai anggota ekstrakurikuler ini.";
                        $message_type = "error";
                    } elseif ($existing['status'] === 'menunggu') {
                        $message = "Pendaftaran Anda masih menunggu verifikasi dari pembina.";
                        $message_type = "error";
                    } else {
                        // Aktifkan kembali jika sebelumnya nonaktif/ditolak
                        $up_st = $pdo->prepare("UPDATE extracurricular_members SET status = 'menunggu', joined_at = CURRENT_DATE() WHERE id = ?");
                        $up_st->execute([$existing['id']]);
                        logActivity($pdo, 'JOIN_EKSKUL', "Siswa mendaftar ulang ekskul: " . $ekskul['name']);
                        $message = "Permohonan bergabung ke ekstrakurikuler " . htmlspecialchars($ekskul['name']) . " berhasil diajukan dan menunggu persetujuan pembina.";
                        $message_type = "success";
                    }
                } else {
                    $status_init = ($ekskul['category'] === 'wajib') ? 'aktif' : 'menunggu';
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO extracurricular_members 
                        (extracurricular_id, student_id, role, status, grade_predicate, joined_at)
                        VALUES (?, ?, 'anggota', ?, 'A', CURRENT_DATE())
                    ");
                    $stmt_ins->execute([$ekskul_id, $user_id, $status_init]);
                    logActivity($pdo, 'JOIN_EKSKUL', "Siswa bergabung ke ekskul: " . $ekskul['name']);
                    
                    if ($status_init === 'aktif') {
                        $message = "Selamat! Anda resmi terdaftar sebagai anggota " . htmlspecialchars($ekskul['name']) . ".";
                    } else {
                        $message = "Pendaftaran ke " . htmlspecialchars($ekskul['name']) . " berhasil dikirim! Menunggu konfirmasi pembina.";
                    }
                    $message_type = "success";
                }
            }
        }

        // 2. SISWA KELUAR / BATALKAN PENDAFTARAN EKSKUL PILIHAN
        elseif ($action === 'leave_ekskul' && $user_role === 'siswa') {
            $ekskul_id = (int)($_POST['ekskul_id'] ?? 0);
            
            // Cek apakah bukan ekskul wajib
            $stmt_cat = $pdo->prepare("SELECT name, category FROM extracurriculars WHERE id = ?");
            $stmt_cat->execute([$ekskul_id]);
            $ek_info = $stmt_cat->fetch();

            if ($ek_info && $ek_info['category'] === 'wajib') {
                $message = "Ekstrakurikuler Wajib (Pramuka) tidak dapat ditinggalkan.";
                $message_type = "error";
            } else {
                $stmt_del = $pdo->prepare("DELETE FROM extracurricular_members WHERE extracurricular_id = ? AND student_id = ?");
                $stmt_del->execute([$ekskul_id, $user_id]);
                logActivity($pdo, 'LEAVE_EKSKUL', "Siswa membatalkan keanggotaan ekskul ID #$ekskul_id");
                $message = "Anda telah membatalkan keikutsertaan dari ekstrakurikuler tersebut.";
                $message_type = "success";
            }
        }

        // 3. ADMIN / STAF / GURU MEMBUAT EKSKUL BARU
        elseif ($action === 'create_ekskul' && ($is_admin_or_staff || $is_teacher)) {
            $name        = trim($_POST['name'] ?? '');
            $code        = strtoupper(trim($_POST['code'] ?? ''));
            $category    = in_array($_POST['category'] ?? '', ['wajib', 'pilihan'], true) ? $_POST['category'] : 'pilihan';
            $instructor  = !empty($_POST['leader_instructor_id']) ? (int)$_POST['leader_instructor_id'] : null;
            $day         = trim($_POST['schedule_day'] ?? 'Jumat');
            $time        = trim($_POST['schedule_time'] ?? '15:30 - 17:00');
            $location    = trim($_POST['location'] ?? 'Lapangan Utama');
            $max_members = (int)($_POST['max_members'] ?? 40);
            $icon        = trim($_POST['logo_icon'] ?? 'fa-trophy');
            $desc        = trim($_POST['description'] ?? '');

            if (empty($name) || empty($code)) {
                $message = "Nama ekstrakurikuler dan kode unik wajib diisi.";
                $message_type = "error";
            } else {
                try {
                    $stmt_in = $pdo->prepare("
                        INSERT INTO extracurriculars 
                        (name, code, category, description, leader_instructor_id, schedule_day, schedule_time, location, max_members, logo_icon, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt_in->execute([$name, $code, $category, $desc, $instructor, $day, $time, $location, $max_members, $icon]);
                    logActivity($pdo, 'CREATE_EKSKUL', "Membuat ekstrakurikuler baru: $name ($code)");
                    $message = "Ekstrakurikuler '$name' berhasil ditambahkan ke sistem!";
                    $message_type = "success";
                } catch (PDOException $e_in) {
                    if (str_contains($e_in->getMessage(), 'Duplicate entry') || $e_in->getCode() == 23000) {
                        $message = "Kode ekstrakurikuler '$code' sudah digunakan. Silakan gunakan kode lain.";
                    } else {
                        $message = "Gagal menyimpan ekstrakurikuler: " . $e_in->getMessage();
                    }
                    $message_type = "error";
                }
            }
        }

        // 4. TOGGLE STATUS AKTIF / NONAKTIF EKSKUL
        elseif ($action === 'toggle_active' && $is_admin_or_staff) {
            $ekskul_id = (int)($_POST['ekskul_id'] ?? 0);
            $current_st = (int)($_POST['current_status'] ?? 1);
            $new_st = ($current_st === 1) ? 0 : 1;

            $stmt_tog = $pdo->prepare("UPDATE extracurriculars SET is_active = ? WHERE id = ?");
            $stmt_tog->execute([$new_st, $ekskul_id]);
            logActivity($pdo, 'TOGGLE_EKSKUL', "Mengubah status aktif ekskul ID #$ekskul_id menjadi: $new_st");
            $message = "Status aktif ekstrakurikuler berhasil diperbarui.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// AMBIL DATA & STATISTIK EKSTRAKURIKULER
// -------------------------------------------------------------
$filter_cat = $_GET['category'] ?? '';
$search_q   = trim($_GET['q'] ?? '');

$only_active = !$is_admin_or_staff; // Admin & Staf bisa lihat yang dinonaktifkan
$ekskul_list = getExtracurriculars($pdo, (!empty($filter_cat) ? $filter_cat : null), $only_active);

// Filter search di PHP jika ada
if (!empty($search_q)) {
    $ekskul_list = array_filter($ekskul_list, function($e) use ($search_q) {
        return stripos($e['name'], $search_q) !== false ||
               stripos($e['code'], $search_q) !== false ||
               stripos($e['location'], $search_q) !== false ||
               stripos($e['leader_name'] ?? '', $search_q) !== false;
    });
}

// Statistik Keseluruhan
$total_ekskul_cnt = (int)$pdo->query("SELECT COUNT(*) FROM extracurriculars WHERE is_active = 1")->fetchColumn();
$total_wajib_cnt  = (int)$pdo->query("SELECT COUNT(*) FROM extracurriculars WHERE is_active = 1 AND category = 'wajib'")->fetchColumn();
$total_pilihan_cnt= (int)$pdo->query("SELECT COUNT(*) FROM extracurriculars WHERE is_active = 1 AND category = 'pilihan'")->fetchColumn();
$total_members_cnt= (int)$pdo->query("SELECT COUNT(DISTINCT student_id) FROM extracurricular_members WHERE status = 'aktif'")->fetchColumn();
$total_achieve_cnt= (int)$pdo->query("SELECT COUNT(*) FROM extracurricular_achievements")->fetchColumn();

// Jika Siswa, ambil ekskul yang diikuti
$my_ekskul = [];
if ($user_role === 'siswa') {
    $my_ekskul = getStudentExtracurriculars($pdo, $user_id);
} elseif ($user_role === 'orang_tua') {
    // Ambil data ekskul anak-anak yang terhubung
    $stmt_child = $pdo->prepare("SELECT student_id FROM parent_students WHERE parent_id = ?");
    $stmt_child->execute([$user_id]);
    $children_ids = $stmt_child->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($children_ids)) {
        $in_q = implode(',', array_map('intval', $children_ids));
        $my_ekskul = $pdo->query("
            SELECT em.*, e.name as ekskul_name, e.code as ekskul_code, e.category as ekskul_category,
                   e.schedule_day, e.schedule_time, e.location, e.logo_icon,
                   u_st.name as student_name, u_st.nisn, c.name as class_name,
                   u_lead.name as leader_name
            FROM extracurricular_members em
            JOIN extracurriculars e ON em.extracurricular_id = e.id
            JOIN users u_st ON em.student_id = u_st.id
            LEFT JOIN classes c ON u_st.class_id = c.id
            LEFT JOIN users u_lead ON e.leader_instructor_id = u_lead.id
            WHERE em.student_id IN ($in_q)
            ORDER BY u_st.name ASC, e.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Ambil daftar guru untuk dropdown pembina di form
$teachers = [];
if ($is_admin_or_staff || $is_teacher) {
    $teachers = $pdo->query("SELECT id, name, nip FROM users WHERE role IN ('guru', 'administrator', 'staf') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = "Ekstrakurikuler & Prestasi Siswa";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Top Header Banner & Actions -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-slate-400 mb-1">
                <a href="../index.php" class="hover:text-blue-400 transition">Dashboard</a>
                <span><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
                <span class="text-amber-400 font-medium">Ekstrakurikuler & Prestasi</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-tr from-amber-500 via-orange-500 to-yellow-400 text-white shadow-lg shadow-amber-500/25">
                    <i class="fa-solid fa-trophy text-lg"></i>
                </span>
                Ekstrakurikuler & Pengembangan Bakat
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Wadah pembinaan minat, bakat, kepemimpinan karakter, dan portofolio prestasi kompetisi civitas sekolah.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <!-- Link ke Galeri Prestasi -->
            <a href="achievements.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 border border-white/10 text-xs font-semibold text-amber-300 hover:text-white transition shadow-sm">
                <i class="fa-solid fa-medal text-amber-400"></i>
                <span>Galeri Prestasi & Juara</span>
            </a>

            <!-- Tombol Buat Ekskul Baru (Admin, Staf, Guru) -->
            <?php if ($is_admin_or_staff || $is_teacher): ?>
                <button type="button" onclick="openModal('createEkskulModal')"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-xs font-bold text-white shadow-lg shadow-blue-500/20 transition cursor-pointer">
                    <i class="fa-solid fa-plus"></i>
                    <span>Tambah Ekskul Baru</span>
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

    <!-- 4 Metrik Statistik Utama -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/10 shadow-sm relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 w-16 h-16 rounded-full bg-blue-500/5 group-hover:scale-125 transition-transform"></div>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Total Ekskul</p>
                    <p class="text-2xl font-extrabold text-white mt-1"><?= $total_ekskul_cnt ?></p>
                    <p class="text-[10px] text-blue-400 font-medium mt-0.5"><?= $total_wajib_cnt ?> Wajib • <?= $total_pilihan_cnt ?> Pilihan</p>
                </div>
                <div class="h-10 w-10 rounded-xl bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400 text-lg">
                    <i class="fa-solid fa-shapes"></i>
                </div>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/10 shadow-sm relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 w-16 h-16 rounded-full bg-emerald-500/5 group-hover:scale-125 transition-transform"></div>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Siswa Aktif</p>
                    <p class="text-2xl font-extrabold text-white mt-1"><?= $total_members_cnt ?></p>
                    <p class="text-[10px] text-emerald-400 font-medium mt-0.5">Partisipan terdaftar</p>
                </div>
                <div class="h-10 w-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 text-lg">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/10 shadow-sm relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 w-16 h-16 rounded-full bg-amber-500/5 group-hover:scale-125 transition-transform"></div>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Prestasi Terdata</p>
                    <p class="text-2xl font-extrabold text-white mt-1"><?= $total_achieve_cnt ?></p>
                    <p class="text-[10px] text-amber-400 font-medium mt-0.5">Kejuaraan resmi</p>
                </div>
                <div class="h-10 w-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 text-lg">
                    <i class="fa-solid fa-award"></i>
                </div>
            </div>
        </div>

        <div class="p-4 rounded-2xl bg-slate-900/80 border border-white/10 shadow-sm relative overflow-hidden group">
            <div class="absolute -right-4 -bottom-4 w-16 h-16 rounded-full bg-purple-500/5 group-hover:scale-125 transition-transform"></div>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Nilai Rapor</p>
                    <p class="text-2xl font-extrabold text-white mt-1">Otomatis</p>
                    <p class="text-[10px] text-purple-400 font-medium mt-0.5">Sinkron E-Rapor</p>
                </div>
                <div class="h-10 w-10 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400 text-lg">
                    <i class="fa-solid fa-file-invoice"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- KHUSUS SISWA & ORANG TUA: DAFTAR EKSKUL YANG DIIKUTI SAYA / ANAK -->
    <?php if ($user_role === 'siswa' || $user_role === 'orang_tua'): ?>
        <div class="rounded-3xl border border-amber-500/20 bg-gradient-to-b from-amber-500/5 via-slate-900/90 to-slate-900/90 p-5 shadow-xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                <div class="flex items-center gap-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400 border border-amber-500/30">
                        <i class="fa-solid fa-id-badge text-sm"></i>
                    </span>
                    <div>
                        <h2 class="text-sm font-bold text-white">
                            <?= $user_role === 'siswa' ? 'Ekstrakurikuler yang Saya Ikuti' : 'Kegiatan Ekstrakurikuler Siswa (Anak)' ?>
                        </h2>
                        <p class="text-[11px] text-slate-400">Pantau jadwal latihan rutin, persentase kehadiran, dan predikat nilai perkembangan.</p>
                    </div>
                </div>
                <a href="achievements.php" class="text-xs font-semibold text-amber-400 hover:text-amber-300 flex items-center gap-1.5 self-start sm:self-auto">
                    <span>Lihat Portofolio Piagam</span>
                    <i class="fa-solid fa-arrow-right text-[10px]"></i>
                </a>
            </div>

            <?php if (empty($my_ekskul)): ?>
                <div class="rounded-2xl border border-white/5 bg-slate-950/40 p-6 text-center">
                    <i class="fa-solid fa-circle-info text-2xl text-slate-500 mb-2"></i>
                    <p class="text-xs font-medium text-slate-300">Belum ada ekstrakurikuler yang diikuti.</p>
                    <p class="text-[11px] text-slate-500 mt-0.5">Silakan pilih ekstrakurikuler di bawah ini dan klik tombol "Daftar Ekskul".</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5">
                    <?php foreach ($my_ekskul as $me): 
                        $status_badge = match($me['status']) {
                            'aktif'   => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
                            'menunggu'=> 'bg-amber-500/15 text-amber-300 border-amber-500/30',
                            'ditolak' => 'bg-rose-500/15 text-rose-300 border-rose-500/30',
                            default   => 'bg-slate-800 text-slate-400 border-slate-700'
                        };
                    ?>
                        <div class="rounded-2xl border border-white/10 bg-slate-950/60 p-4 flex flex-col justify-between hover:border-amber-500/40 transition group">
                            <div>
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex items-center gap-2.5">
                                        <div class="h-9 w-9 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400 text-sm">
                                            <i class="fa-solid <?= htmlspecialchars($me['logo_icon'] ?: 'fa-trophy') ?>"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-xs font-bold text-white truncate"><?= htmlspecialchars($me['ekskul_name']) ?></p>
                                            <span class="text-[10px] text-slate-400"><?= htmlspecialchars($me['ekskul_code']) ?> • <?= ucfirst($me['ekskul_category']) ?></span>
                                        </div>
                                    </div>
                                    <span class="text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full border <?= $status_badge ?>">
                                        <?= ucfirst($me['status']) ?>
                                    </span>
                                </div>

                                <?php if ($user_role === 'orang_tua' && !empty($me['student_name'])): ?>
                                    <div class="mt-2.5 px-2.5 py-1.5 rounded-xl bg-white/5 border border-white/5 text-[11px] flex items-center justify-between">
                                        <span class="text-slate-400">Anak:</span>
                                        <span class="font-semibold text-slate-200"><?= htmlspecialchars($me['student_name']) ?> (<?= htmlspecialchars($me['class_name'] ?? 'Siswa') ?>)</span>
                                    </div>
                                <?php endif; ?>

                                <div class="mt-3 space-y-1.5 text-[11px]">
                                    <div class="flex items-center gap-2 text-slate-300">
                                        <i class="fa-regular fa-clock text-amber-400 w-3.5 text-center"></i>
                                        <span><?= htmlspecialchars($me['schedule_day']) ?>, <?= htmlspecialchars($me['schedule_time']) ?></span>
                                    </div>
                                    <div class="flex items-center gap-2 text-slate-300">
                                        <i class="fa-solid fa-location-dot text-amber-400 w-3.5 text-center"></i>
                                        <span class="truncate"><?= htmlspecialchars($me['location']) ?></span>
                                    </div>
                                    <div class="flex items-center gap-2 text-slate-400">
                                        <i class="fa-solid fa-user-tie text-slate-500 w-3.5 text-center"></i>
                                        <span class="truncate">Pembina: <?= htmlspecialchars($me['leader_name'] ?? 'Guru Pembina') ?></span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-t border-white/5 flex items-center justify-between gap-2">
                                <div class="text-[11px]">
                                    <span class="text-slate-400">Peran:</span>
                                    <span class="font-bold text-amber-300 uppercase ml-1"><?= htmlspecialchars($me['role']) ?></span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="detail.php?id=<?= $me['extracurricular_id'] ?>" 
                                       class="px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-[11px] font-semibold text-white transition">
                                        Detail Sesi
                                    </a>
                                    <?php if ($user_role === 'siswa' && $me['ekskul_category'] !== 'wajib'): ?>
                                        <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan/keluar dari ekstrakurikuler ini?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="leave_ekskul">
                                            <input type="hidden" name="ekskul_id" value="<?= $me['extracurricular_id'] ?>">
                                            <button type="submit" class="p-1.5 rounded-xl text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition" title="Batal / Keluar">
                                                <i class="fa-solid fa-arrow-right-from-bracket text-xs"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- DAFTAR KATALOG SELURUH EKSTRAKURIKULER -->
    <div class="space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-shapes text-blue-400"></i>
                    <span>Katalog Ekstrakurikuler Sekolah</span>
                </h2>
                <p class="text-xs text-slate-400">Daftar cabang kegiatan dan organisasi ekstrakurikuler resmi satuan pendidikan.</p>
            </div>

            <!-- Filter Kategori & Search Bar -->
            <div class="flex flex-wrap items-center gap-2">
                <a href="index.php" class="px-3 py-1.5 rounded-xl text-xs font-semibold transition <?= empty($filter_cat) ? 'bg-blue-600 text-white shadow-sm' : 'bg-slate-800 text-slate-300 hover:bg-slate-700' ?>">
                    Semua
                </a>
                <a href="index.php?category=wajib" class="px-3 py-1.5 rounded-xl text-xs font-semibold transition <?= $filter_cat === 'wajib' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-slate-800 text-slate-300 hover:bg-slate-700' ?>">
                    Wajib
                </a>
                <a href="index.php?category=pilihan" class="px-3 py-1.5 rounded-xl text-xs font-semibold transition <?= $filter_cat === 'pilihan' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-slate-800 text-slate-300 hover:bg-slate-700' ?>">
                    Pilihan
                </a>
            </div>
        </div>

        <?php if (empty($ekskul_list)): ?>
            <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-12 text-center">
                <i class="fa-solid fa-folder-open text-4xl text-slate-500 mb-3"></i>
                <h3 class="text-sm font-bold text-white">Tidak Ada Data Ekstrakurikuler</h3>
                <p class="text-xs text-slate-400 mt-1">Belum ada ekstrakurikuler yang sesuai dengan filter atau kata kunci pencarian Anda.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <?php foreach ($ekskul_list as $ek): 
                    $curr_members = (int)$ek['active_members_count'];
                    $max_cap      = (int)$ek['max_members'];
                    $pct_filled   = ($max_cap > 0) ? min(100, round(($curr_members / $max_cap) * 100)) : 0;
                    $is_full      = ($max_cap > 0 && $curr_members >= $max_cap);

                    // Cek apakah siswa yang sedang login sudah terdaftar
                    $already_joined = false;
                    $join_status = '';
                    if ($user_role === 'siswa') {
                        foreach ($my_ekskul as $mk) {
                            if ((int)$mk['extracurricular_id'] === (int)$ek['id']) {
                                $already_joined = true;
                                $join_status = $mk['status'];
                                break;
                            }
                        }
                    }
                ?>
                    <div class="rounded-3xl border border-white/10 bg-slate-900/90 p-5 flex flex-col justify-between hover:border-blue-500/40 transition-all duration-200 shadow-lg relative group">
                        
                        <!-- Top Card Header -->
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <div class="h-12 w-12 rounded-2xl bg-gradient-to-tr from-slate-800 to-slate-700 border border-white/10 flex items-center justify-center text-amber-400 text-xl shadow-md group-hover:scale-105 transition-transform">
                                    <i class="fa-solid <?= htmlspecialchars($ek['logo_icon'] ?: 'fa-trophy') ?>"></i>
                                </div>
                                <div class="flex flex-col items-end gap-1">
                                    <span class="text-[9px] font-extrabold uppercase tracking-wider px-2 py-0.5 rounded-full border <?= $ek['category'] === 'wajib' ? 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30' : 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' ?>">
                                        <?= strtoupper($ek['category']) ?>
                                    </span>
                                    <?php if ($is_admin_or_staff && (int)$ek['is_active'] === 0): ?>
                                        <span class="text-[8px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/30">
                                            Nonaktif
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mt-3.5">
                                <h3 class="text-sm font-bold text-white group-hover:text-blue-400 transition truncate" title="<?= htmlspecialchars($ek['name']) ?>">
                                    <?= htmlspecialchars($ek['name']) ?>
                                </h3>
                                <p class="text-[10px] text-slate-400 font-mono mt-0.5"><?= htmlspecialchars($ek['code']) ?></p>
                            </div>

                            <p class="text-[11px] text-slate-300 mt-2 line-clamp-2 leading-relaxed" title="<?= htmlspecialchars($ek['description'] ?? '') ?>">
                                <?= htmlspecialchars($ek['description'] ?: 'Kegiatan ekstrakurikuler pembinaan bakat dan minat siswa.') ?>
                            </p>

                            <!-- Jadwal & Lokasi -->
                            <div class="mt-3.5 space-y-1.5 text-[11px] bg-slate-950/50 p-2.5 rounded-xl border border-white/5">
                                <div class="flex items-center gap-2 text-slate-300">
                                    <i class="fa-regular fa-calendar-check text-blue-400 w-3 text-center"></i>
                                    <span><?= htmlspecialchars($ek['schedule_day']) ?>, <?= htmlspecialchars($ek['schedule_time']) ?></span>
                                </div>
                                <div class="flex items-center gap-2 text-slate-300">
                                    <i class="fa-solid fa-location-dot text-rose-400 w-3 text-center"></i>
                                    <span class="truncate"><?= htmlspecialchars($ek['location']) ?></span>
                                </div>
                                <div class="flex items-center gap-2 text-slate-400">
                                    <i class="fa-solid fa-chalkboard-user text-amber-400 w-3 text-center"></i>
                                    <span class="truncate"><?= htmlspecialchars($ek['leader_name'] ?? 'Belum Ditentukan') ?></span>
                                </div>
                            </div>

                            <!-- Kuota Kapasitas -->
                            <div class="mt-3">
                                <div class="flex items-center justify-between text-[10px] text-slate-400 mb-1">
                                    <span>Kapasitas Anggota</span>
                                    <span class="font-bold <?= $is_full ? 'text-rose-400' : 'text-slate-300' ?>">
                                        <?= $curr_members ?> / <?= $max_cap ?> Siswa
                                    </span>
                                </div>
                                <div class="h-1.5 w-full bg-slate-800 rounded-full overflow-hidden">
                                    <div class="h-full <?= $is_full ? 'bg-rose-500' : 'bg-gradient-to-r from-blue-500 to-emerald-500' ?>" style="width: <?= $pct_filled ?>%"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer Action Buttons -->
                        <div class="mt-5 pt-3 border-t border-white/5 flex items-center justify-between gap-2">
                            <a href="detail.php?id=<?= $ek['id'] ?>" class="flex-1 text-center py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-white transition">
                                <i class="fa-solid fa-eye text-slate-400 mr-1"></i> Detail
                            </a>

                            <?php if ($user_role === 'siswa'): ?>
                                <?php if ($already_joined): ?>
                                    <span class="px-3 py-2 rounded-xl text-[11px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                        <i class="fa-solid fa-check mr-1"></i> Terdaftar
                                    </span>
                                <?php elseif ($is_full): ?>
                                    <span class="px-3 py-2 rounded-xl text-[11px] font-bold bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                        Penuh
                                    </span>
                                <?php else: ?>
                                    <form method="POST">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="join_ekskul">
                                        <input type="hidden" name="ekskul_id" value="<?= $ek['id'] ?>">
                                        <button type="submit" class="py-2 px-3 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white transition shadow-sm cursor-pointer">
                                            Daftar
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($is_admin_or_staff): ?>
                                <form method="POST" onsubmit="return confirm('Ubah status keaktifan ekstrakurikuler ini?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="ekskul_id" value="<?= $ek['id'] ?>">
                                    <input type="hidden" name="current_status" value="<?= (int)$ek['is_active'] ?>">
                                    <button type="submit" class="p-2 rounded-xl text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer" title="<?= (int)$ek['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?>">
                                        <i class="fa-solid <?= (int)$ek['is_active'] === 1 ? 'fa-toggle-on text-emerald-400' : 'fa-toggle-off text-slate-500' ?> text-base"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- ============================================================== -->
<!-- MODAL: TAMBAH EKSTRAKURIKULER BARU (ADMIN / STAF / GURU) -->
<!-- ============================================================== -->
<?php if ($is_admin_or_staff || $is_teacher): ?>
<div id="createEkskulModal" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm hidden flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-lg w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto custom-scrollbar">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400">
                    <i class="fa-solid fa-shapes text-sm"></i>
                </span>
                Tambah Ekstrakurikuler Baru
            </h3>
            <button type="button" onclick="closeModal('createEkskulModal')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_ekskul">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Ekstrakurikuler <span class="text-rose-400">*</span></label>
                    <input type="text" name="name" required placeholder="Contoh: Robotik & Coding Club"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kode Unik <span class="text-rose-400">*</span></label>
                    <input type="text" name="code" required placeholder="Contoh: EKS-ROB"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 uppercase focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kategori Sifat</label>
                    <select name="category" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-blue-500">
                        <option value="pilihan">Pilihan (Minat & Bakat)</option>
                        <option value="wajib">Wajib (Kepanduan/Pramuka)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Guru Pembina / Pelatih</label>
                <select name="leader_instructor_id" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-blue-500">
                    <option value="">-- Pilih Guru Pembina --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?> <?= !empty($t['nip']) ? '('.htmlspecialchars($t['nip']).')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Hari Latihan</label>
                    <select name="schedule_day" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-blue-500">
                        <option value="Senin">Senin</option>
                        <option value="Selasa">Selasa</option>
                        <option value="Rabu">Rabu</option>
                        <option value="Kamis">Kamis</option>
                        <option value="Jumat" selected>Jumat</option>
                        <option value="Sabtu">Sabtu</option>
                        <option value="Minggu">Minggu</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Jam Pelaksanaan</label>
                    <input type="text" name="schedule_time" value="15:30 - 17:00" placeholder="15:30 - 17:00"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Lokasi Latihan / Ruangan</label>
                    <input type="text" name="location" value="Lapangan Utama" placeholder="Contoh: Lab Komputer 1"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kapasitas Maksimal (Kuota)</label>
                    <input type="number" name="max_members" value="40" min="5" max="200"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Font Awesome Icon (Class)</label>
                <input type="text" name="logo_icon" value="fa-trophy" placeholder="Contoh: fa-futbol, fa-flag, fa-laptop-code"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Deskripsi Singkat & Visi Kegiatan</label>
                <textarea name="description" rows="3" placeholder="Jelaskan fokus kegiatan dan kompetensi yang dilatih..."
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-xs text-slate-200 focus:outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="pt-3 border-t border-white/10 flex items-center justify-end gap-2">
                <button type="button" onclick="closeModal('createEkskulModal')"
                        class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-white transition">
                    Batal
                </button>
                <button type="submit"
                        class="px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition">
                    Simpan Ekstrakurikuler
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.remove('hidden');
    }
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.add('hidden');
    }
}
</script>

<?php
require_once __DIR__ . "/../includes/footer.php";
?>
