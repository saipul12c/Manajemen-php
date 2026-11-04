<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['administrator', 'staf']);

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'administrator';

$school_info = getSchoolSettings($pdo);
$active_year = $school_info['academic_year'] ?? '2026/2027';

$message = "";
$message_type = "";
$active_tab = $_GET['tab'] ?? 'naik_kelas';

// -------------------------------------------------------------
// 1. PROSES KENAIKAN KELAS MASSAL (PROMOTION)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_promotion') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $from_class_id = (int)($_POST['from_class_id'] ?? 0);
        $to_class_id   = (int)($_POST['to_class_id'] ?? 0);
        $target_year   = trim($_POST['academic_year'] ?? $active_year);
        $student_ids   = $_POST['student_ids'] ?? [];
        $decisions     = $_POST['decision'] ?? []; // 'naik' or 'tinggal'
        $admin_notes   = trim($_POST['notes'] ?? '');

        if ($from_class_id <= 0 || $to_class_id <= 0) {
            $message = "Pilih kelas asal dan kelas tujuan kenaikan terlebih dahulu.";
            $message_type = "error";
        } elseif ($from_class_id === $to_class_id) {
            $message = "Kelas tujuan harus berbeda dari kelas asal.";
            $message_type = "error";
        } elseif (empty($student_ids)) {
            $message = "Tidak ada siswa yang dipilih untuk diproses.";
            $message_type = "error";
        } else {
            try {
                $pdo->beginTransaction();

                // Ambil nama kelas
                $stmt_cn = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
                $stmt_cn->execute([$from_class_id]);
                $from_class_name = $stmt_cn->fetchColumn() ?: "Kelas #$from_class_id";

                $stmt_cn->execute([$to_class_id]);
                $to_class_name = $stmt_cn->fetchColumn() ?: "Kelas #$to_class_id";

                $promoted_count = 0;
                $retained_count = 0;

                $stmt_up_promoted = $pdo->prepare("UPDATE users SET class_id = ? WHERE id = ? AND role = 'siswa'");

                foreach ($student_ids as $sid) {
                    $sid = (int)$sid;
                    $dec = $decisions[$sid] ?? 'naik';

                    if ($dec === 'naik') {
                        $stmt_up_promoted->execute([$to_class_id, $sid]);
                        $promoted_count++;
                    } else {
                        // Siswa tinggal kelas tetap di kelas asal
                        $retained_count++;
                    }
                }

                // Catat ke Log Kenaikan Kelas
                $stmt_log = $pdo->prepare("
                    INSERT INTO academic_promotion_logs 
                    (action_type, from_class, to_class, academic_year, students_count, promoted_count, retained_count, executed_by, notes)
                    VALUES ('promotion', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $total_processed = count($student_ids);
                $stmt_log->execute([
                    $from_class_name, $to_class_name, $target_year, 
                    $total_processed, $promoted_count, $retained_count, 
                    $user_id, $admin_notes ?: "Kenaikan kelas dari $from_class_name ke $to_class_name"
                ]);

                $pdo->commit();

                logActivity($pdo, 'STUDENT_PROMOTION', "Memproses kenaikan kelas: $promoted_count naik ke $to_class_name, $retained_count tinggal di $from_class_name");
                $message = "Kenaikan kelas massal berhasil diproses! ($promoted_count siswa naik ke $to_class_name, $retained_count siswa tinggal).";
                $message_type = "success";
                $active_tab = 'riwayat';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $message = "Gagal memproses kenaikan kelas: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// 2. PROSES KELULUSAN SISWA TINGKAT AKHIR & ARSIP ALUMNI
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_graduation') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $grad_class_id  = (int)($_POST['grad_class_id'] ?? 0);
        $grad_year      = trim($_POST['graduation_year'] ?? date('Y'));
        $student_ids    = $_POST['student_ids'] ?? [];
        $grad_status    = $_POST['grad_status'] ?? []; // 'lulus' / 'tidak_lulus'
        $diploma_prefix = trim($_POST['diploma_prefix'] ?? 'DN-01/M-SMA/14/');
        $admin_notes    = trim($_POST['notes'] ?? '');

        if ($grad_class_id <= 0) {
            $message = "Pilih rombel tingkat akhir yang akan diluluskan.";
            $message_type = "error";
        } elseif (empty($student_ids)) {
            $message = "Tidak ada siswa yang dipilih.";
            $message_type = "error";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_cn = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
                $stmt_cn->execute([$grad_class_id]);
                $grad_class_name = $stmt_cn->fetchColumn() ?: "Kelas Akhir #$grad_class_id";

                $lulus_count = 0;
                $tidak_lulus_count = 0;

                $stmt_stu_data = $pdo->prepare("SELECT id, name, nisn, gender, phone, email FROM users WHERE id = ?");
                $stmt_ins_alumni = $pdo->prepare("
                    INSERT INTO alumni_records 
                    (user_id, nisn, full_name, gender, graduation_year, last_class, diploma_number, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_up_grad = $pdo->prepare("
                    UPDATE users 
                    SET status = 'lulus', status_reason = ?, status_updated_at = NOW(), status_updated_by = ?, class_id = NULL
                    WHERE id = ?
                ");
                $stmt_up_retained = $pdo->prepare("
                    UPDATE users 
                    SET status = 'aktif', status_reason = ?, status_updated_at = NOW(), status_updated_by = ?
                    WHERE id = ?
                ");

                foreach ($student_ids as $idx => $sid) {
                    $sid = (int)$sid;
                    $status_val = $grad_status[$sid] ?? 'lulus';

                    $stmt_stu_data->execute([$sid]);
                    $stu = $stmt_stu_data->fetch();

                    if (!$stu) continue;

                    $dip_no = $status_val === 'lulus' ? ($diploma_prefix . sprintf('%04d', $idx + 1)) : null;

                    if ($status_val === 'lulus') {
                        $stmt_ins_alumni->execute([
                            $sid, $stu['nisn'] ?: null, $stu['name'], $stu['gender'] ?? 'L',
                            $grad_year, $grad_class_name, $dip_no, 'lulus'
                        ]);
                        $stmt_up_grad->execute(["Dinyatakan Lulus Tahun Ajaran $grad_year dari rombel $grad_class_name", $user_id, $sid]);
                        $lulus_count++;

                        // Kirim Notifikasi WhatsApp Ucapan Selamat
                        if (!empty($stu['phone'])) {
                            $wa_msg = "Selamat dan Sukses! Bpk/Ibu Wali Murid & Ananda *{$stu['name']}*,\n\n"
                                    . "Berdasarkan Sidang Pleno Kelulusan SMA Bina Bangsa, ananda dinyatakan *LULUS* Tahun Ajaran *{$grad_year}*.\n\n"
                                    . "Nomor Ijazah/SKL: *{$dip_no}*\n"
                                    . "Terima kasih atas dedikasi belajar selama menempuh pendidikan di sekolah. Data ananda telah terdaftar resmi di Buku Alumni & Sistem Tracer Study Sekolah.\n\n"
                                    . "Sukses selalu untuk jenjang perguruan tinggi dan karir ke depan!";
                            sendWhatsAppNotification($pdo, $stu['phone'], $stu['name'], $wa_msg, 'akademik');
                        }
                    } else {
                        $stmt_up_retained->execute(["Belum Memenuhi Syarat Kelulusan $grad_year", $user_id, $sid]);
                        $tidak_lulus_count++;
                    }
                }

                // Log kelulusan
                $stmt_log = $pdo->prepare("
                    INSERT INTO academic_promotion_logs 
                    (action_type, from_class, to_class, academic_year, students_count, promoted_count, retained_count, executed_by, notes)
                    VALUES ('graduation', ?, 'Alumni Sekolah', ?, ?, ?, ?, ?, ?)
                ");
                $stmt_log->execute([
                    $grad_class_name, $grad_year, count($student_ids), 
                    $lulus_count, $tidak_lulus_count, $user_id, 
                    $admin_notes ?: "Penetapan kelulusan siswa $grad_class_name Tahun $grad_year"
                ]);

                $pdo->commit();

                logActivity($pdo, 'STUDENT_GRADUATION', "Memproses kelulusan $grad_class_name: $lulus_count Lulus (diarsipkan ke alumni), $tidak_lulus_count tidak lulus");
                $message = "Proses kelulusan selesai! $lulus_count siswa berhasil diarsipkan ke Buku Alumni & menerima nomor ijazah resmi.";
                $message_type = "success";
                $active_tab = 'riwayat';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $message = "Gagal memproses kelulusan: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// 3. QUERY DATA KELAS & ROMBEL
// -------------------------------------------------------------
$classes = $pdo->query("SELECT id, name, grade_level, academic_year FROM classes ORDER BY grade_level ASC, name ASC")->fetchAll();

// Resolusi Siswa Kelas Terpilih untuk Kenaikan
$selected_from_class = isset($_GET['from_class_id']) ? (int)$_GET['from_class_id'] : 0;
$students_for_promotion = [];
if ($selected_from_class > 0) {
    $stmt_stus = $pdo->prepare("
        SELECT u.id, u.name, u.nisn, u.gender, u.email, u.phone,
               (SELECT COUNT(*) FROM student_attendance WHERE student_id = u.id AND status = 'hadir') as hadir_count,
               (SELECT COUNT(*) FROM student_attendance WHERE student_id = u.id) as total_attendance,
               (SELECT COALESCE(SUM(points), 0) FROM counseling_records WHERE student_id = u.id AND type = 'pelanggaran') as bk_violations,
               (SELECT AVG(score) FROM assignment_submissions WHERE student_id = u.id AND status = 'selesai') as avg_tugas
        FROM users u 
        WHERE u.class_id = ? AND u.role = 'siswa' AND u.status = 'aktif'
        ORDER BY u.name ASC
    ");
    $stmt_stus->execute([$selected_from_class]);
    $students_for_promotion = $stmt_stus->fetchAll();
}

// Resolusi Siswa Kelas Akhir untuk Kelulusan
$selected_grad_class = isset($_GET['grad_class_id']) ? (int)$_GET['grad_class_id'] : 0;
$students_for_graduation = [];
if ($selected_grad_class > 0) {
    $stmt_grads = $pdo->prepare("
        SELECT u.id, u.name, u.nisn, u.gender, u.phone, u.email,
               (SELECT AVG(score) FROM assignment_submissions WHERE student_id = u.id AND status = 'selesai') as avg_score
        FROM users u 
        WHERE u.class_id = ? AND u.role = 'siswa' AND u.status = 'aktif'
        ORDER BY u.name ASC
    ");
    $stmt_grads->execute([$selected_grad_class]);
    $students_for_graduation = $stmt_grads->fetchAll();
}

// Riwayat Log Kenaikan & Kelulusan
$promotion_logs = $pdo->query("
    SELECT l.*, u.name as executor_name 
    FROM academic_promotion_logs l
    LEFT JOIN users u ON l.executed_by = u.id
    ORDER BY l.created_at DESC LIMIT 30
")->fetchAll();

$page_title = "Kenaikan Kelas & Kelulusan Massal";
require_once __DIR__ . "/../includes/header.php";
?>

<!-- Header Banner -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-indigo-500/20 text-indigo-400 text-xl border border-indigo-500/30 shadow-lg shadow-indigo-500/10">
                <i class="fa-solid fa-arrows-up-to-line"></i>
            </span>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-2xl font-bold text-white tracking-tight">Kenaikan Kelas & Kelulusan Massal</h1>
                    <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 font-mono">
                        TA <?= htmlspecialchars($active_year) ?>
                    </span>
                </div>
                <p class="text-xs text-slate-400 mt-0.5">Wizard otomatisasi rollover semester, penetapan kenaikan rombel, dan pengarsipan alumni sekolah</p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-2.5">
        <a href="classes.php" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition">
            <i class="fa-solid fa-school"></i> Data Rombel
        </a>
        <a href="../alumni/tracer_study.php" class="inline-flex items-center gap-1.5 rounded-xl border border-purple-500/30 bg-purple-500/10 hover:bg-purple-500/20 px-4 py-2 text-xs font-semibold text-purple-300 transition">
            <i class="fa-solid fa-graduation-cap"></i> Buku Tracer Alumni
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="mb-6 rounded-2xl border p-4 text-xs font-medium flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <span><?= htmlspecialchars($message) ?></span>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<!-- Tab Navigation Navigation -->
<div class="flex items-center gap-2 border-b border-white/10 pb-4 mb-6 overflow-x-auto text-xs font-bold">
    <a href="promotion.php?tab=naik_kelas" 
       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl transition whitespace-nowrap <?= $active_tab === 'naik_kelas' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
        <i class="fa-solid fa-arrow-up-right-dots"></i> Kenaikan Kelas Massal
    </a>
    <a href="promotion.php?tab=kelulusan" 
       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl transition whitespace-nowrap <?= $active_tab === 'kelulusan' ? 'bg-purple-600 text-white shadow-lg shadow-purple-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
        <i class="fa-solid fa-user-graduate"></i> Kelulusan Tingkat Akhir (Alumni)
    </a>
    <a href="promotion.php?tab=riwayat" 
       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl transition whitespace-nowrap <?= $active_tab === 'riwayat' ? 'bg-slate-800 text-white shadow-md' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
        <i class="fa-solid fa-clock-rotate-left"></i> Riwayat & Log Rollover (<?= count($promotion_logs) ?>)
    </a>
</div>

<!-- ============================================================== -->
<!-- TAB 1: KENAIKAN KELAS MASSAL -->
<!-- ============================================================== -->
<?php if ($active_tab === 'naik_kelas'): ?>
    <div class="space-y-6">
        
        <!-- Step 1: Pilih Kelas Asal & Tujuan -->
        <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 backdrop-blur shadow-xl">
            <h3 class="text-sm font-bold text-white mb-4 flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-500/20 text-indigo-400 text-xs">1</span>
                Pilih Rombongan Belajar Asal & Target Kenaikan
            </h3>

            <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                <input type="hidden" name="tab" value="naik_kelas">
                
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Kelas Asal (Rombel Saat Ini):</label>
                    <select name="from_class_id" required onchange="this.form.submit()"
                            class="w-full bg-slate-950 border border-white/10 rounded-xl px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                        <option value="">-- Pilih Kelas Asal --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $selected_from_class === (int)$c['id'] ? 'selected' : '' ?>>
                                Kelas <?= htmlspecialchars($c['name']) ?> (Tingkat <?= htmlspecialchars($c['grade_level']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sm:col-span-2 flex items-center gap-2">
                    <span class="text-slate-400 text-xs italic">
                        <?= $selected_from_class > 0 ? 'Ditemukan ' . count($students_for_promotion) . ' siswa aktif pada kelas ini.' : 'Pilih kelas asal untuk memuat daftar siswa dan kalkulasi rekomendasi kenaikan.' ?>
                    </span>
                </div>
            </form>
        </div>

        <?php if ($selected_from_class > 0): ?>
            <?php if (empty($students_for_promotion)): ?>
                <div class="rounded-3xl border border-white/10 bg-slate-900/40 p-12 text-center text-slate-400">
                    <i class="fa-solid fa-user-xmark text-4xl mb-3 text-slate-500"></i>
                    <p class="text-sm font-semibold text-slate-200">Tidak ada siswa aktif di kelas ini.</p>
                    <p class="text-xs text-slate-500 mt-1">Seluruh siswa mungkin telah dipindahkan atau berstatus non-aktif.</p>
                </div>
            <?php else: ?>
                <!-- Step 2: Konfirmasi Siswa & Kelas Tujuan -->
                <form method="POST" action="promotion.php?tab=naik_kelas&from_class_id=<?= $selected_from_class ?>" onsubmit="return confirm('Apakah Anda yakin ingin memproses kenaikan kelas untuk siswa yang dipilih?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="process_promotion">
                    <input type="hidden" name="from_class_id" value="<?= $selected_from_class ?>">

                    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 backdrop-blur shadow-xl space-y-5">
                        
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-white/10">
                            <div>
                                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-500/20 text-indigo-400 text-xs">2</span>
                                    Daftar Rekomendasi Kenaikan Kelas Siswa
                                </h3>
                                <p class="text-xs text-slate-400 mt-0.5">Sistem secara cerdas menganalisis persentase absensi dan rata-rata nilai tugas/ujian</p>
                            </div>

                            <div class="flex items-center gap-3">
                                <div>
                                    <label class="block text-[11px] font-semibold text-slate-300 mb-1">Pindahkan ke Kelas Tujuan: <span class="text-rose-400">*</span></label>
                                    <select name="to_class_id" required 
                                            class="bg-slate-950 border border-indigo-500/40 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-indigo-500">
                                        <option value="">-- Pilih Kelas Tujuan --</option>
                                        <?php foreach ($classes as $c): ?>
                                            <?php if ((int)$c['id'] !== $selected_from_class): ?>
                                                <option value="<?= $c['id'] ?>">
                                                    Kelas <?= htmlspecialchars($c['name']) ?> (Tingkat <?= htmlspecialchars($c['grade_level']) ?>)
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Tabel Siswa -->
                        <div class="overflow-x-auto rounded-2xl border border-white/10">
                            <table class="w-full text-left text-xs text-slate-300">
                                <thead class="bg-slate-950/80 uppercase text-[10px] text-slate-400 tracking-wider font-semibold border-b border-white/10">
                                    <tr>
                                        <th class="py-3 px-4 text-center w-12">
                                            <input type="checkbox" id="checkAllPromotion" checked onclick="toggleAllPromotion(this)" class="rounded border-white/20 bg-slate-900 text-indigo-600 focus:ring-0">
                                        </th>
                                        <th class="py-3 px-4">Nama Siswa & NISN</th>
                                        <th class="py-3 px-4 text-center">Kehadiran (%)</th>
                                        <th class="py-3 px-4 text-center">Rata-rata Nilai</th>
                                        <th class="py-3 px-4 text-center">Poin Pelanggaran</th>
                                        <th class="py-3 px-4 text-center">Rekomendasi Sistem</th>
                                        <th class="py-3 px-4 text-center">Keputusan</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <?php foreach ($students_for_promotion as $i => $s): 
                                        $att_total = (int)$s['total_attendance'];
                                        $att_hadir = (int)$s['hadir_count'];
                                        $att_rate = $att_total > 0 ? round(($att_hadir / $att_total) * 100, 1) : 100.0;
                                        $avg_score = $s['avg_tugas'] !== null ? round((float)$s['avg_tugas'], 1) : 75.0;
                                        $bk_pts = (int)$s['bk_violations'];

                                        // Kriteria Kenaikan: Kehadiran >= 75%, Rata-rata >= 70, Poin Pelanggaran < 50
                                        $is_recommended = ($att_rate >= 75 && $avg_score >= 70 && $bk_pts < 50);
                                    ?>
                                        <tr class="hover:bg-white/5 transition">
                                            <td class="py-3 px-4 text-center">
                                                <input type="checkbox" name="student_ids[]" value="<?= $s['id'] ?>" checked class="prom-checkbox rounded border-white/20 bg-slate-900 text-indigo-600 focus:ring-0">
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="font-bold text-white block"><?= htmlspecialchars($s['name']) ?></span>
                                                <span class="text-[11px] font-mono text-slate-400">NISN: <?= htmlspecialchars($s['nisn'] ?: '-') ?></span>
                                            </td>
                                            <td class="py-3 px-4 text-center font-mono">
                                                <span class="<?= $att_rate >= 75 ? 'text-emerald-400' : 'text-rose-400 font-bold' ?>"><?= $att_rate ?>%</span>
                                                <span class="text-[10px] text-slate-500 block"><?= $att_hadir ?>/<?= $att_total ?> hari</span>
                                            </td>
                                            <td class="py-3 px-4 text-center font-mono">
                                                <span class="font-bold <?= $avg_score >= 75 ? 'text-emerald-300' : ($avg_score >= 70 ? 'text-amber-300' : 'text-rose-400') ?>">
                                                    <?= number_format($avg_score, 1) ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4 text-center font-mono">
                                                <span class="<?= $bk_pts > 0 ? 'text-amber-400 font-bold' : 'text-slate-400' ?>"><?= $bk_pts ?> Pts</span>
                                            </td>
                                            <td class="py-3 px-4 text-center">
                                                <?php if ($is_recommended): ?>
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-500/20">
                                                        <i class="fa-solid fa-circle-check"></i> Layak Naik
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-0.5 text-[10px] font-bold text-amber-400 border border-amber-500/20">
                                                        <i class="fa-solid fa-triangle-exclamation"></i> Perlu Evaluasi
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 px-4 text-center">
                                                <select name="decision[<?= $s['id'] ?>]" class="bg-slate-950 border border-white/10 rounded-lg px-2.5 py-1 text-xs text-white focus:outline-none">
                                                    <option value="naik" <?= $is_recommended ? 'selected' : '' ?>>Naik Kelas</option>
                                                    <option value="tinggal" <?= !$is_recommended ? 'selected' : '' ?>>Tinggal di Kelas Ini</option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Catatan Berita Acara Kenaikan (Opsional):</label>
                            <input type="text" name="notes" placeholder="Contoh: Berita Acara Rapat Kenaikan Kelas Dewan Guru Semester Genap 2026"
                                   class="w-full bg-slate-950 border border-white/10 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-indigo-500">
                        </div>

                        <div class="flex items-center justify-between pt-3 border-t border-white/10">
                            <span class="text-xs text-slate-400">Total Siswa Diproses: <strong><?= count($students_for_promotion) ?> Siswa</strong></span>
                            <button type="submit" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs shadow-lg shadow-indigo-600/30 transition flex items-center gap-2 cursor-pointer">
                                <i class="fa-solid fa-arrows-turn-right"></i>
                                <span>Proses Kenaikan Kelas Massal Sekarang</span>
                            </button>
                        </div>

                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>

    </div>
<?php endif; ?>

<!-- ============================================================== -->
<!-- TAB 2: KELULUSAN SISWA TINGKAT AKHIR & ARSIP ALUMNI -->
<!-- ============================================================== -->
<?php if ($active_tab === 'kelulusan'): ?>
    <div class="space-y-6">
        
        <div class="rounded-3xl border border-purple-500/30 bg-gradient-to-r from-purple-950/30 via-slate-900 to-indigo-950/20 p-5 sm:p-6 backdrop-blur shadow-xl">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
                <div>
                    <h3 class="text-sm font-bold text-white mb-1 flex items-center gap-2">
                        <i class="fa-solid fa-graduation-cap text-purple-400"></i>
                        Kelulusan Siswa Tingkat Akhir & Arsip Alumni
                    </h3>
                    <p class="text-xs text-slate-300 max-w-3xl leading-relaxed">
                        Siswa yang dinyatakan <strong>LULUS</strong> akan secara otomatis diubah statusnya menjadi <code>lulus</code>, diarsipkan ke <strong>Buku Alumni Sekolah</strong>, diterbitkan nomor ijazah sementara, serta tersinkronisasi ke modul <strong>Tracer Study</strong> dan <strong>Legalisir Online</strong>.
                    </p>
                </div>
                <a href="../alumni/tracer_study.php" 
                   class="inline-flex items-center gap-2 rounded-xl border border-indigo-500/30 bg-indigo-500/10 hover:bg-indigo-500/20 px-3.5 py-2 text-xs font-semibold text-indigo-300 shrink-0 transition">
                    <i class="fa-solid fa-user-graduate text-indigo-400"></i> Buka Tracer Study Alumni
                </a>
            </div>

            <form method="GET" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                <input type="hidden" name="tab" value="kelulusan">
                
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Pilih Rombel Tingkat Akhir:</label>
                    <select name="grad_class_id" required onchange="this.form.submit()"
                            class="w-full bg-slate-950 border border-white/10 rounded-xl px-3.5 py-2.5 text-xs text-white focus:outline-none focus:border-purple-500">
                        <option value="">-- Pilih Kelas Tingkat Akhir --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $selected_grad_class === (int)$c['id'] ? 'selected' : '' ?>>
                                Kelas <?= htmlspecialchars($c['name']) ?> (Tingkat <?= htmlspecialchars($c['grade_level']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if ($selected_grad_class > 0): ?>
            <?php if (empty($students_for_graduation)): ?>
                <div class="rounded-3xl border border-white/10 bg-slate-900/40 p-12 text-center text-slate-400">
                    <i class="fa-solid fa-user-xmark text-4xl mb-3 text-slate-500"></i>
                    <p class="text-sm font-semibold text-slate-200">Tidak ada siswa aktif di kelas ini.</p>
                </div>
            <?php else: ?>
                <form method="POST" action="promotion.php?tab=kelulusan&grad_class_id=<?= $selected_grad_class ?>" onsubmit="return confirm('PENTING: Seluruh siswa yang dinyatakan LULUS akan dilepaskan dari rombel dan diarsipkan ke Buku Alumni. Lanjutkan?');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="process_graduation">
                    <input type="hidden" name="grad_class_id" value="<?= $selected_grad_class ?>">

                    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 backdrop-blur shadow-xl space-y-5">
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pb-4 border-b border-white/10">
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tahun Kelulusan Resmi: <span class="text-rose-400">*</span></label>
                                <input type="text" name="graduation_year" required value="<?= date('Y') ?>" placeholder="Contoh: 2026"
                                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-purple-500 font-mono">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Awalan Format Nomor Ijazah / SKL:</label>
                                <input type="text" name="diploma_prefix" required value="DN-01/M-SMA/14/<?= date('y') ?>-"
                                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-purple-500 font-mono">
                            </div>
                        </div>

                        <!-- Tabel Kelulusan -->
                        <div class="overflow-x-auto rounded-2xl border border-white/10">
                            <table class="w-full text-left text-xs text-slate-300">
                                <thead class="bg-slate-950/80 uppercase text-[10px] text-slate-400 tracking-wider font-semibold border-b border-white/10">
                                    <tr>
                                        <th class="py-3 px-4 text-center w-12">
                                            <input type="checkbox" id="checkAllGraduation" checked onclick="toggleAllGraduation(this)" class="rounded border-white/20 bg-slate-900 text-purple-600 focus:ring-0">
                                        </th>
                                        <th class="py-3 px-4">Nama Siswa & NISN</th>
                                        <th class="py-3 px-4 text-center">Status Tanggungan (Clearance)</th>
                                        <th class="py-3 px-4 text-center">Rata-rata Nilai</th>
                                        <th class="py-3 px-4 text-center">Nomor WhatsApp</th>
                                        <th class="py-3 px-4 text-center">Status Kelulusan</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5">
                                    <?php foreach ($students_for_graduation as $s): 
                                        $clr = getStudentClearanceStatus($pdo, (int)$s['id']);
                                    ?>
                                        <tr class="hover:bg-white/5 transition">
                                            <td class="py-3 px-4 text-center">
                                                <input type="checkbox" name="student_ids[]" value="<?= $s['id'] ?>" checked class="grad-checkbox rounded border-white/20 bg-slate-900 text-purple-600 focus:ring-0">
                                            </td>
                                            <td class="py-3 px-4">
                                                <span class="font-bold text-white block"><?= htmlspecialchars($s['name']) ?></span>
                                                <span class="text-[11px] font-mono text-slate-400">NISN: <?= htmlspecialchars($s['nisn'] ?: '-') ?></span>
                                            </td>
                                            <td class="py-3 px-4 text-center">
                                                <?php if ($clr['is_cleared']): ?>
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-400 border border-emerald-500/20">
                                                        <i class="fa-solid fa-check"></i> Bebas Tanggungan
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-500/10 px-2 py-0.5 text-[10px] font-bold text-rose-400 border border-rose-500/20" title="Ada tagihan SPP atau pinjaman perpus belum lunas">
                                                        <i class="fa-solid fa-triangle-exclamation"></i> Ada Tanggungan
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3 px-4 text-center font-mono">
                                                <span class="font-bold text-emerald-300">
                                                    <?= $s['avg_score'] !== null ? number_format((float)$s['avg_score'], 1) : '-' ?>
                                                </span>
                                            </td>
                                            <td class="py-3 px-4 text-center font-mono text-slate-400">
                                                <?= htmlspecialchars($s['phone'] ?: '-') ?>
                                            </td>
                                            <td class="py-3 px-4 text-center">
                                                <select name="grad_status[<?= $s['id'] ?>]" class="bg-slate-950 border border-white/10 rounded-lg px-2.5 py-1 text-xs text-white focus:outline-none">
                                                    <option value="lulus" selected>Lulus (Arsipkan Alumni)</option>
                                                    <option value="tidak_lulus">Tidak Lulus</option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center justify-between pt-3 border-t border-white/10">
                            <span class="text-xs text-slate-400">Total Calon Lulusan: <strong><?= count($students_for_graduation) ?> Siswa</strong></span>
                            <button type="submit" class="px-6 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-bold text-xs shadow-lg shadow-purple-600/30 transition flex items-center gap-2 cursor-pointer">
                                <i class="fa-solid fa-graduation-cap"></i>
                                <span>Tetapkan Kelulusan & Arsipkan ke Alumni</span>
                            </button>
                        </div>

                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>

    </div>
<?php endif; ?>

<!-- ============================================================== -->
<!-- TAB 3: RIWAYAT & LOG ROLLOVER -->
<!-- ============================================================== -->
<?php if ($active_tab === 'riwayat'): ?>
    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 sm:p-6 backdrop-blur shadow-xl">
        <h3 class="text-sm font-bold text-white mb-4 flex items-center gap-2">
            <i class="fa-solid fa-clock-rotate-left text-slate-400"></i>
            Riwayat Pemrosesan Kenaikan Kelas & Kelulusan
        </h3>

        <?php if (empty($promotion_logs)): ?>
            <div class="py-12 text-center text-slate-400">
                <i class="fa-regular fa-folder-open text-3xl mb-2 text-slate-500 block"></i>
                <p class="text-xs">Belum ada riwayat kenaikan kelas atau kelulusan yang tercatat.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto rounded-2xl border border-white/10">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-950/80 uppercase text-[10px] text-slate-400 tracking-wider font-semibold border-b border-white/10">
                        <tr>
                            <th class="py-3 px-4">Waktu Eksekusi</th>
                            <th class="py-3 px-4">Jenis Aksi</th>
                            <th class="py-3 px-4">Rombel Asal & Tujuan</th>
                            <th class="py-3 px-4 text-center">Tahun Ajaran</th>
                            <th class="py-3 px-4 text-center">Hasil Siswa</th>
                            <th class="py-3 px-4">Eksekutor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5 font-mono text-[11px]">
                        <?php foreach ($promotion_logs as $log): ?>
                            <tr class="hover:bg-white/5 transition">
                                <td class="py-3 px-4 text-slate-400">
                                    <?= date('d/m/Y H:i', strtotime($log['created_at'])) ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php if ($log['action_type'] === 'promotion'): ?>
                                        <span class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-[10px] font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                                            Kenaikan Kelas
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-[10px] font-bold bg-purple-500/20 text-purple-300 border border-purple-500/30">
                                            Kelulusan Alumni
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-slate-200">
                                    <?= htmlspecialchars($log['from_class'] ?? '-') ?> &rarr; <strong class="text-white"><?= htmlspecialchars($log['to_class'] ?? '-') ?></strong>
                                </td>
                                <td class="py-3 px-4 text-center text-slate-300">
                                    <?= htmlspecialchars($log['academic_year']) ?>
                                </td>
                                <td class="py-3 px-4 text-center">
                                    <span class="text-emerald-400 font-bold"><?= $log['promoted_count'] ?> Sukses</span>
                                    <?php if ($log['retained_count'] > 0): ?>
                                        &bull; <span class="text-rose-400 font-bold"><?= $log['retained_count'] ?> Tertahan</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-slate-300">
                                    <?= htmlspecialchars($log['executor_name'] ?: 'Admin') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
function toggleAllPromotion(master) {
    document.querySelectorAll('.prom-checkbox').forEach(cb => cb.checked = master.checked);
}
function toggleAllGraduation(master) {
    document.querySelectorAll('.grad-checkbox').forEach(cb => cb.checked = master.checked);
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
