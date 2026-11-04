<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Pengguna';
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_admin_or_staff = in_array($user_role, ['administrator', 'staf'], true);

$activity_id = (int)($_GET['activity_id'] ?? 0);
if ($activity_id <= 0) {
    header("Location: index.php?error=invalid_activity");
    exit;
}

// Ambil info aktivitas & ekskul
$stmt_act = $pdo->prepare("
    SELECT ea.*, e.name as ekskul_name, e.code as ekskul_code, e.leader_instructor_id,
           u.name as instructor_name
    FROM extracurricular_activities ea
    JOIN extracurriculars e ON ea.extracurricular_id = e.id
    LEFT JOIN users u ON ea.instructor_id = u.id
    WHERE ea.id = ?
");
$stmt_act->execute([$activity_id]);
$activity = $stmt_act->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    header("Location: index.php?error=activity_not_found");
    exit;
}

$is_this_leader = ($user_role === 'guru' && (int)$activity['leader_instructor_id'] === $user_id);
$can_manage     = ($is_admin_or_staff || $is_this_leader);

if (!$can_manage) {
    header("Location: detail.php?id=" . $activity['extracurricular_id'] . "&error=unauthorized");
    exit;
}

$message = "";
$message_type = "";

// -------------------------------------------------------------
// POST HANDLER: SIMPAN SELURUH REKAP PRESENSI
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan CSRF tidak valid.";
        $message_type = "error";
    } else {
        $statuses = $_POST['status'] ?? [];
        $notes    = $_POST['notes'] ?? [];

        try {
            $pdo->beginTransaction();

            $stmt_save = $pdo->prepare("
                INSERT INTO extracurricular_attendance (activity_id, student_id, status, notes)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), notes = VALUES(notes)
            ");

            $updated_cnt = 0;
            foreach ($statuses as $student_id => $st) {
                $student_id = (int)$student_id;
                $st = in_array($st, ['hadir', 'izin', 'sakit', 'alpa'], true) ? $st : 'hadir';
                $nt = trim($notes[$student_id] ?? '');

                $stmt_save->execute([$activity_id, $student_id, $st, $nt]);
                $updated_cnt++;
            }

            $pdo->commit();
            logActivity($pdo, 'SAVE_EKSKUL_ATTENDANCE', "Menyimpan presensi $updated_cnt siswa pada sesi ID #$activity_id (" . $activity['ekskul_name'] . ")");
            $message = "Rekapitulasi presensi kehadiran berhasil disimpan untuk $updated_cnt peserta didik!";
            $message_type = "success";
        } catch (Exception $e_att) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "Terjadi kegagalan saat menyimpan presensi: " . $e_att->getMessage();
            $message_type = "error";
        }
    }
}

// Ambil seluruh siswa anggota aktif beserta status presensi saat ini
$stmt_list = $pdo->prepare("
    SELECT em.student_id, em.role, u.name as student_name, u.nisn, u.gender, c.name as class_name,
           COALESCE(att.status, 'hadir') as current_status,
           att.notes as current_notes
    FROM extracurricular_members em
    JOIN users u ON em.student_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    LEFT JOIN extracurricular_attendance att ON (att.activity_id = ? AND att.student_id = em.student_id)
    WHERE em.extracurricular_id = ? AND em.status = 'aktif'
    ORDER BY FIELD(em.role, 'ketua', 'wakil_ketua', 'sekretaris', 'bendahara', 'anggota'), u.name ASC
");
$stmt_list->execute([$activity_id, $activity['extracurricular_id']]);
$attendance_rows = $stmt_list->fetchAll(PDO::FETCH_ASSOC);

// Hitung metrik saat ini
$count_hadir = count(array_filter($attendance_rows, fn($r) => $r['current_status'] === 'hadir'));
$count_izin  = count(array_filter($attendance_rows, fn($r) => $r['current_status'] === 'izin'));
$count_sakit = count(array_filter($attendance_rows, fn($r) => $r['current_status'] === 'sakit'));
$count_alpa  = count(array_filter($attendance_rows, fn($r) => $r['current_status'] === 'alpa'));

$page_title = "Input Presensi: " . htmlspecialchars($activity['activity_title']);
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6 max-w-5xl mx-auto">

    <!-- Top Breadcrumb & Header Title -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-slate-400 mb-1">
                <a href="index.php" class="hover:text-blue-400 transition">Ekstrakurikuler</a>
                <span><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
                <a href="detail.php?id=<?= $activity['extracurricular_id'] ?>&tab=kegiatan" class="hover:text-blue-400 transition"><?= htmlspecialchars($activity['ekskul_name']) ?></a>
                <span><i class="fa-solid fa-chevron-right text-[10px]"></i></span>
                <span class="text-blue-400 font-medium">Input Presensi</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-500/25">
                    <i class="fa-solid fa-clipboard-user text-lg"></i>
                </span>
                <span>Presensi Sesi: <?= htmlspecialchars($activity['activity_title']) ?></span>
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Tanggal: <strong><?= date('d F Y', strtotime($activity['activity_date'])) ?></strong> • Ekskul: <strong><?= htmlspecialchars($activity['ekskul_name']) ?></strong>
            </p>
        </div>

        <a href="detail.php?id=<?= $activity['extracurricular_id'] ?>&tab=kegiatan" 
           class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-300 transition self-start sm:self-auto">
            <i class="fa-solid fa-arrow-left mr-1.5"></i> Kembali ke Agenda
        </a>
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

    <!-- Statistik Ringkas Kehadiran Sesi -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="p-3.5 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-center">
            <span class="text-[10px] uppercase font-bold text-emerald-400 tracking-wider block">Hadir</span>
            <span id="stat_hadir" class="text-2xl font-black text-emerald-300 mt-0.5 block"><?= $count_hadir ?></span>
        </div>
        <div class="p-3.5 rounded-2xl bg-blue-500/10 border border-blue-500/20 text-center">
            <span class="text-[10px] uppercase font-bold text-blue-400 tracking-wider block">Izin</span>
            <span id="stat_izin" class="text-2xl font-black text-blue-300 mt-0.5 block"><?= $count_izin ?></span>
        </div>
        <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-center">
            <span class="text-[10px] uppercase font-bold text-amber-400 tracking-wider block">Sakit</span>
            <span id="stat_sakit" class="text-2xl font-black text-amber-300 mt-0.5 block"><?= $count_sakit ?></span>
        </div>
        <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-center">
            <span class="text-[10px] uppercase font-bold text-rose-400 tracking-wider block">Alpa</span>
            <span id="stat_alpa" class="text-2xl font-black text-rose-300 mt-0.5 block"><?= $count_alpa ?></span>
        </div>
    </div>

    <!-- Form Presensi Anggota -->
    <form method="POST" class="space-y-4">
        <?= csrfField() ?>

        <div class="rounded-3xl border border-white/10 bg-slate-900/90 shadow-xl overflow-hidden">
            <!-- Header Bar with Quick Action -->
            <div class="p-4 border-b border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-950/40">
                <div>
                    <h2 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-blue-400"></i>
                        <span>Lembar Absensi Peserta (<?= count($attendance_rows) ?> Siswa Aktif)</span>
                    </h2>
                    <p class="text-[11px] text-slate-400">Pilih status kehadiran dan beri catatan jika diperlukan.</p>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" onclick="setAllAttendance('hadir')"
                            class="px-3 py-1.5 rounded-xl bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border border-emerald-500/30 text-xs font-bold transition flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-check-double text-[11px]"></i>
                        <span>Set Semua Hadir</span>
                    </button>
                </div>
            </div>

            <?php if (empty($attendance_rows)): ?>
                <div class="p-12 text-center">
                    <i class="fa-solid fa-users-slash text-3xl text-slate-500 mb-2"></i>
                    <p class="text-xs font-semibold text-slate-300">Tidak ada anggota aktif pada ekstrakurikuler ini.</p>
                    <p class="text-[11px] text-slate-500 mt-0.5">Pastikan anggota telah disetujui statusnya menjadi 'aktif'.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-slate-950/60 text-slate-400 border-b border-white/10 uppercase text-[10px] tracking-wider">
                            <tr>
                                <th class="py-3 px-4 w-12">No</th>
                                <th class="py-3 px-4">Siswa</th>
                                <th class="py-3 px-4">Kelas</th>
                                <th class="py-3 px-4 text-center">Kehadiran</th>
                                <th class="py-3 px-4">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php 
                            $no = 1;
                            foreach ($attendance_rows as $row): 
                                $sid = $row['student_id'];
                                $c_status = $row['current_status'];
                            ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="py-3 px-4 text-slate-400 font-mono"><?= $no++ ?></td>
                                    <td class="py-3 px-4">
                                        <div class="font-bold text-white"><?= htmlspecialchars($row['student_name']) ?></div>
                                        <div class="text-[10px] text-slate-400 font-mono">
                                            NISN: <?= htmlspecialchars($row['nisn'] ?: '-') ?> • <span class="text-amber-400 uppercase"><?= htmlspecialchars($row['role']) ?></span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 text-slate-300 font-medium">
                                        <?= htmlspecialchars($row['class_name'] ?? 'Siswa') ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex items-center justify-center gap-1.5 sm:gap-2">
                                            <!-- Hadir -->
                                            <label class="cursor-pointer">
                                                <input type="radio" name="status[<?= $sid ?>]" value="hadir" <?= $c_status === 'hadir' ? 'checked' : '' ?>
                                                       onchange="recalcStats()" class="peer sr-only">
                                                <span class="px-2.5 py-1 rounded-xl text-[10px] font-bold border transition block
                                                             peer-checked:bg-emerald-500 peer-checked:text-slate-950 peer-checked:border-emerald-400
                                                             bg-slate-950 text-slate-400 border-white/10 hover:border-emerald-500/50">
                                                    Hadir
                                                </span>
                                            </label>

                                            <!-- Izin -->
                                            <label class="cursor-pointer">
                                                <input type="radio" name="status[<?= $sid ?>]" value="izin" <?= $c_status === 'izin' ? 'checked' : '' ?>
                                                       onchange="recalcStats()" class="peer sr-only">
                                                <span class="px-2.5 py-1 rounded-xl text-[10px] font-bold border transition block
                                                             peer-checked:bg-blue-500 peer-checked:text-white peer-checked:border-blue-400
                                                             bg-slate-950 text-slate-400 border-white/10 hover:border-blue-500/50">
                                                    Izin
                                                </span>
                                            </label>

                                            <!-- Sakit -->
                                            <label class="cursor-pointer">
                                                <input type="radio" name="status[<?= $sid ?>]" value="sakit" <?= $c_status === 'sakit' ? 'checked' : '' ?>
                                                       onchange="recalcStats()" class="peer sr-only">
                                                <span class="px-2.5 py-1 rounded-xl text-[10px] font-bold border transition block
                                                             peer-checked:bg-amber-500 peer-checked:text-slate-950 peer-checked:border-amber-400
                                                             bg-slate-950 text-slate-400 border-white/10 hover:border-amber-500/50">
                                                    Sakit
                                                </span>
                                            </label>

                                            <!-- Alpa -->
                                            <label class="cursor-pointer">
                                                <input type="radio" name="status[<?= $sid ?>]" value="alpa" <?= $c_status === 'alpa' ? 'checked' : '' ?>
                                                       onchange="recalcStats()" class="peer sr-only">
                                                <span class="px-2.5 py-1 rounded-xl text-[10px] font-bold border transition block
                                                             peer-checked:bg-rose-500 peer-checked:text-white peer-checked:border-rose-400
                                                             bg-slate-950 text-slate-400 border-white/10 hover:border-rose-500/50">
                                                    Alpa
                                                </span>
                                            </label>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <input type="text" name="notes[<?= $sid ?>]" value="<?= htmlspecialchars($row['current_notes'] ?? '') ?>"
                                               placeholder="Catatan keikutsertaan..."
                                               class="w-full bg-slate-950 border border-white/10 rounded-xl px-2.5 py-1 text-xs text-slate-300 focus:outline-none focus:border-blue-500">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-4 border-t border-white/10 flex items-center justify-between gap-4 bg-slate-950/40">
                    <span class="text-xs text-slate-400">
                        Pastikan presensi telah sesuai sebelum menyimpan.
                    </span>
                    <button type="submit" 
                            class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-xs font-bold text-white shadow-lg shadow-blue-500/25 transition cursor-pointer flex items-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <span>Simpan Presensi Pertemuan</span>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </form>

</div>

<script>
function setAllAttendance(status) {
    const radios = document.querySelectorAll(`input[type="radio"][value="${status}"]`);
    radios.forEach(r => {
        r.checked = true;
    });
    recalcStats();
}

function recalcStats() {
    let h = 0, i = 0, s = 0, a = 0;
    document.querySelectorAll('input[type="radio"]:checked').forEach(r => {
        if (r.value === 'hadir') h++;
        else if (r.value === 'izin') i++;
        else if (r.value === 'sakit') s++;
        else if (r.value === 'alpa') a++;
    });

    const elH = document.getElementById('stat_hadir');
    const elI = document.getElementById('stat_izin');
    const elS = document.getElementById('stat_sakit');
    const elA = document.getElementById('stat_alpa');

    if (elH) elH.textContent = h;
    if (elI) elI.textContent = i;
    if (elS) elS.textContent = s;
    if (elA) elA.textContent = a;
}
</script>

<?php
require_once __DIR__ . "/../includes/footer.php";
?>
