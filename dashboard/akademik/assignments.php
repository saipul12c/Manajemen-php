<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_teacher = in_array($user_role, ['guru', 'administrator'], true);

$message = "";
$message_type = "";

// Buat direktori upload tugas jika belum ada
$upload_dir = __DIR__ . "/../../uploads/assignments";
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

// -------------------------------------------------------------
// 1. TAMBAH TUGAS (Guru / Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_assignment') {
    if (!$is_teacher) {
        header("Location: assignments.php?error=unauthorized");
        exit;
    }

    $subject = trim($_POST['subject'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+3 days'));

    if ($subject === '' || $title === '' || $due_date === '') {
        $message = "Mata pelajaran, judul tugas, dan batas waktu wajib diisi.";
        $message_type = "error";
    } else {
        $stmt_ins = $pdo->prepare("INSERT INTO assignments (subject, title, description, due_date, teacher_id) VALUES (?, ?, ?, ?, ?)");
        $stmt_ins->execute([$subject, $title, $description, $due_date, $user_id]);
        logActivity($pdo, 'CREATE_ASSIGNMENT', "Membuat tugas: $title ($subject)");
        $message = "Tugas pembelajaran baru berhasil dibuat dan dibagikan.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 2. SISWA: KUMPULKAN BERKAS TUGAS (Upload File / Link)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_assignment' && $user_role === 'siswa') {
    $assignment_id = (int) ($_POST['assignment_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $link_url = trim($_POST['link_url'] ?? '');
    $file_url = $link_url ?: null;

    // Handle File Upload jika ada
    if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['submission_file']['tmp_name'];
        $file_name = $_FILES['submission_file']['name'];
        $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed   = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip'];

        if (in_array($file_ext, $allowed, true)) {
            $new_filename = 'tugas_' . $assignment_id . '_siswa_' . $user_id . '_' . time() . '.' . $file_ext;
            $destination  = $upload_dir . '/' . $new_filename;
            if (move_uploaded_file($file_tmp, $destination)) {
                $file_url = '../../uploads/assignments/' . $new_filename;
            }
        }
    }

    if ($assignment_id > 0) {
        $stmt_upsert = $pdo->prepare("
            INSERT INTO assignment_submissions (assignment_id, student_id, status, notes, file_url, submitted_at)
            VALUES (?, ?, 'selesai', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                status = 'selesai',
                notes = VALUES(notes),
                file_url = COALESCE(VALUES(file_url), file_url),
                submitted_at = NOW()
        ");
        $stmt_upsert->execute([$assignment_id, $user_id, $notes, $file_url]);
        logActivity($pdo, 'SUBMIT_ASSIGNMENT', "Mengumpulkan tugas ID: $assignment_id");
        $message = "Jawaban tugas berhasil dikumpulkan kepada guru!";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 3. SISWA: TOGGLE STATUS SELESAI CEPAT
// -------------------------------------------------------------
if (isset($_GET['toggle_id']) && $user_role === 'siswa') {
    $assign_id = (int) $_GET['toggle_id'];

    $stmt_sub = $pdo->prepare("SELECT id, status FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?");
    $stmt_sub->execute([$assign_id, $user_id]);
    $existing = $stmt_sub->fetch();

    if ($existing) {
        $new_status = ($existing['status'] === 'selesai') ? 'belum' : 'selesai';
        $stmt_up = $pdo->prepare("UPDATE assignment_submissions SET status = ?, submitted_at = NOW() WHERE id = ?");
        $stmt_up->execute([$new_status, $existing['id']]);
    } else {
        $stmt_in = $pdo->prepare("INSERT INTO assignment_submissions (assignment_id, student_id, status, submitted_at) VALUES (?, ?, 'selesai', NOW())");
        $stmt_in->execute([$assign_id, $user_id]);
    }

    header("Location: assignments.php?msg=updated");
    exit;
}

// -------------------------------------------------------------
// 4. GURU: PENILAIAN & FEEDBACK TUGAS SISWA
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'grade_submission' && $is_teacher) {
    $submission_id = (int) ($_POST['submission_id'] ?? 0);
    $score = $_POST['score'] !== '' ? (float) $_POST['score'] : null;
    $feedback = trim($_POST['feedback'] ?? '');

    if ($submission_id > 0) {
        $stmt_gr = $pdo->prepare("UPDATE assignment_submissions SET score = ?, feedback = ? WHERE id = ?");
        $stmt_gr->execute([$score, $feedback, $submission_id]);
        logActivity($pdo, 'GRADE_ASSIGNMENT', "Memberikan nilai $score pada submission ID: $submission_id");
        $message = "Nilai dan koreksi tugas berhasil disimpan!";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 5. HAPUS TUGAS (Guru / Admin)
// -------------------------------------------------------------
if (isset($_GET['delete_id']) && $is_teacher) {
    $delete_id = (int) $_GET['delete_id'];
    $stmt_del = $pdo->prepare("DELETE FROM assignments WHERE id = ?" . ($user_role === 'guru' ? " AND teacher_id = $user_id" : ""));
    $stmt_del->execute([$delete_id]);
    $message = "Tugas berhasil dihapus.";
    $message_type = "success";
}

// -------------------------------------------------------------
// 6. QUERY DAFTAR TUGAS & SUBMISSIONS
// -------------------------------------------------------------
$stmt_assign = $pdo->query("
    SELECT a.*, u.name as teacher_name,
           (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND status = 'selesai') as total_submitted
    FROM assignments a 
    JOIN users u ON a.teacher_id = u.id 
    ORDER BY a.due_date ASC, a.id DESC
");
$assignments = $stmt_assign->fetchAll();

// Data pengumpulan spesifik per siswa (jika login sebagai siswa)
$my_submissions = [];
if ($user_role === 'siswa') {
    $stmt_my = $pdo->prepare("SELECT * FROM assignment_submissions WHERE student_id = ?");
    $stmt_my->execute([$user_id]);
    while ($row = $stmt_my->fetch()) {
        $my_submissions[$row['assignment_id']] = $row;
    }
}

// Detail koreksi per tugas (jika guru sedang membuka modal koreksi)
$selected_assign_id = isset($_GET['review_id']) ? (int)$_GET['review_id'] : 0;
$review_submissions = [];
$reviewed_assignment = null;

if ($selected_assign_id > 0 && $is_teacher) {
    $stmt_rv_as = $pdo->prepare("SELECT * FROM assignments WHERE id = ?");
    $stmt_rv_as->execute([$selected_assign_id]);
    $reviewed_assignment = $stmt_rv_as->fetch();

    if ($reviewed_assignment) {
        $stmt_rv = $pdo->prepare("
            SELECT s.id as submission_id, s.assignment_id, s.status, s.notes, s.file_url, s.score, s.feedback, s.submitted_at,
                   u.name as student_name, u.email as student_email
            FROM assignment_submissions s
            JOIN users u ON s.student_id = u.id
            WHERE s.assignment_id = ?
            ORDER BY s.submitted_at DESC
        ");
        $stmt_rv->execute([$selected_assign_id]);
        $review_submissions = $stmt_rv->fetchAll();
    }
}

$page_title = "Tugas Pembelajaran";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
            <span>📚</span> Tugas & Pengumpulan Berkas
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            <?= $is_teacher ? 'Kelola instruksi tugas, periksa berkas kiriman siswa, dan berikan nilai evaluasi.' : 'Kerjakan tugas, unggah berkas jawaban, dan pantau nilai feedback dari guru.' ?>
        </p>
    </div>

    <?php if ($is_teacher): ?>
        <div class="flex items-center gap-2">
            <a href="gradebook.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-slate-300 transition flex items-center gap-1.5">
                <span>📊</span> Buku Rekap Nilai
            </a>
            <button onclick="document.getElementById('modalCreateAssign').classList.remove('hidden')" 
                    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition">
                <span>➕</span> Buat Tugas Baru
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== '' || isset($_GET['msg'])): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between border-emerald-500/30 bg-emerald-500/10 text-emerald-300">
        <div class="flex items-center gap-3">
            <span>✅</span>
            <span><?= htmlspecialchars($message ?: "Status pengerjaan tugas berhasil diperbarui.") ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100">✕</button>
    </div>
<?php endif; ?>

<!-- ========================================================= -->
<!-- MODAL KOREKSI TUGAS OLEH GURU (Review & Grading) -->
<!-- ========================================================= -->
<?php if ($selected_assign_id > 0 && $reviewed_assignment && $is_teacher): ?>
<div class="mb-8 rounded-3xl border border-blue-500/30 bg-slate-900/90 p-6 shadow-2xl backdrop-blur">
    <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="rounded-lg border border-blue-500/30 bg-blue-500/10 px-2 py-0.5 text-xs font-semibold text-blue-300">
                    <?= htmlspecialchars($reviewed_assignment['subject']) ?>
                </span>
                <span class="text-xs text-slate-400">Deadline: <?= date('d M Y', strtotime($reviewed_assignment['due_date'])) ?></span>
            </div>
            <h2 class="text-lg font-bold text-white">Koreksi & Penilaian: <?= htmlspecialchars($reviewed_assignment['title']) ?></h2>
        </div>
        <a href="assignments.php" class="rounded-xl border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-slate-300 hover:bg-white/10">
            ✕ Tutup Panel Koreksi
        </a>
    </div>

    <?php if (empty($review_submissions)): ?>
        <div class="p-8 text-center text-slate-400 text-xs">
            Belum ada siswa yang mengumpulkan tugas ini.
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($review_submissions as $sub): ?>
                <div class="p-4 rounded-2xl bg-slate-950 border border-white/5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="space-y-1 max-w-md">
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-sm text-white"><?= htmlspecialchars($sub['student_name']) ?></span>
                            <span class="text-xs text-slate-500">• <?= htmlspecialchars($sub['student_email']) ?></span>
                        </div>
                        <p class="text-xs text-slate-400">
                            Dikumpulkan: <span class="text-slate-200"><?= $sub['submitted_at'] ? date('d M Y H:i', strtotime($sub['submitted_at'])) : '-' ?></span>
                        </p>
                        <?php if (!empty($sub['notes'])): ?>
                            <p class="text-xs text-slate-300 italic bg-white/5 p-2 rounded-lg mt-1">"<?= htmlspecialchars($sub['notes']) ?>"</p>
                        <?php endif; ?>
                        <?php if (!empty($sub['file_url'])): ?>
                            <div class="pt-1">
                                <a href="<?= htmlspecialchars($sub['file_url']) ?>" target="_blank" 
                                   class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-400 hover:underline">
                                    <span>📎</span> Buka / Unduh Berkas Jawaban ↗
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Form Input Nilai & Feedback -->
                    <form method="POST" action="assignments.php?review_id=<?= $selected_assign_id ?>" class="flex flex-wrap items-center gap-3 bg-white/[0.02] p-3 rounded-xl border border-white/5">
                        <input type="hidden" name="action" value="grade_submission">
                        <input type="hidden" name="submission_id" value="<?= $sub['submission_id'] ?>">

                        <div>
                            <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Skor (0-100)</label>
                            <input type="number" step="0.1" min="0" max="100" name="score" 
                                   value="<?= $sub['score'] !== null ? htmlspecialchars($sub['score']) : '' ?>" 
                                   placeholder="Nilai" 
                                   class="w-20 rounded-lg border border-white/10 bg-slate-900 px-2.5 py-1.5 text-xs font-bold text-white focus:border-blue-500 focus:outline-none">
                        </div>

                        <div class="flex-1 min-w-[160px]">
                            <label class="block text-[10px] uppercase font-bold text-slate-400 mb-1">Catatan Evaluasi / Feedback</label>
                            <input type="text" name="feedback" 
                                   value="<?= htmlspecialchars($sub['feedback'] ?? '') ?>" 
                                   placeholder="Catatan guru..." 
                                   class="w-full rounded-lg border border-white/10 bg-slate-900 px-2.5 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                        </div>

                        <div class="pt-4">
                            <button type="submit" class="rounded-lg bg-emerald-600 hover:bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-white shadow transition">
                                Simpan Nilai
                            </button>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Daftar Tugas Card Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php if (empty($assignments)): ?>
        <div class="col-span-full rounded-3xl border border-white/10 bg-white/5 p-12 text-center text-slate-400">
            <span class="text-4xl">📝</span>
            <p class="mt-3 text-base font-semibold text-white">Belum Ada Tugas Aktif</p>
            <p class="text-xs text-slate-500 mt-1">Saat ini belum ada tugas yang dibagikan oleh dewan guru.</p>
        </div>
    <?php else: ?>
        <?php foreach ($assignments as $as): 
            $sub_data = $my_submissions[$as['id']] ?? null;
            $is_done = ($user_role === 'siswa' && ($sub_data['status'] ?? '') === 'selesai');
            $has_score = ($sub_data !== null && $sub_data['score'] !== null);
            $is_overdue = (strtotime($as['due_date']) < strtotime(date('Y-m-d')));
        ?>
            <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-6 flex flex-col justify-between shadow-xl transition hover:border-white/20 relative backdrop-blur">
                <div>
                    <div class="flex items-center justify-between gap-2 mb-3">
                        <span class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-300">
                            <?= htmlspecialchars($as['subject']) ?>
                        </span>
                        
                        <?php if ($is_teacher && ($as['teacher_id'] == $user_id || $user_role === 'administrator')): ?>
                            <a href="assignments.php?delete_id=<?= $as['id'] ?>" 
                               onclick="return confirm('Hapus tugas ini?');"
                               class="text-xs text-rose-400 hover:text-rose-300">
                                🗑️ Hapus
                            </a>
                        <?php endif; ?>
                    </div>

                    <h3 class="text-base font-bold text-white mb-2"><?= htmlspecialchars($as['title']) ?></h3>
                    <p class="text-xs text-slate-300 leading-relaxed line-clamp-3 mb-4">
                        <?= htmlspecialchars($as['description'] ?: 'Tidak ada deskripsi tambahan.') ?>
                    </p>
                </div>

                <div class="pt-4 border-t border-white/5 space-y-3">
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span>Guru: <strong class="text-slate-200"><?= htmlspecialchars($as['teacher_name']) ?></strong></span>
                        <span class="<?= $is_overdue ? 'text-rose-400 font-semibold' : 'text-amber-300' ?>">
                            ⏰ <?= date('d M Y', strtotime($as['due_date'])) ?>
                        </span>
                    </div>

                    <?php if ($user_role === 'siswa'): ?>
                        <!-- Status Pengerjaan & Nilai Siswa -->
                        <?php if ($has_score): ?>
                            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs">
                                <div class="flex items-center justify-between font-bold text-emerald-300 mb-1">
                                    <span>Nilai Evaluasi:</span>
                                    <span class="text-sm"><?= number_format((float)$sub_data['score'], 1) ?> / 100</span>
                                </div>
                                <?php if (!empty($sub_data['feedback'])): ?>
                                    <p class="text-slate-300 italic text-[11px]">"<?= htmlspecialchars($sub_data['feedback']) ?>"</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="pt-1 flex gap-2">
                            <button type="button"
                                    onclick="openSubmitModal(<?= $as['id'] ?>, '<?= htmlspecialchars(addslashes($as['title'])) ?>')"
                                    class="flex-1 py-2 px-3 rounded-xl text-xs font-semibold text-center transition bg-blue-600 hover:bg-blue-500 text-white shadow-lg shadow-blue-500/20">
                                📤 Kumpulkan Berkas
                            </button>
                            <a href="assignments.php?toggle_id=<?= $as['id'] ?>" 
                               title="<?= $is_done ? 'Klik untuk tandai belum' : 'Klik untuk tandai selesai' ?>"
                               class="py-2 px-3 rounded-xl text-xs font-semibold transition border <?= $is_done ? 'bg-emerald-600/20 text-emerald-300 border-emerald-500/30' : 'bg-white/5 text-slate-400 border-white/10' ?>">
                                <?= $is_done ? '✅ Selesai' : 'Belum' ?>
                            </a>
                        </div>

                    <?php elseif ($is_teacher): ?>
                        <div class="pt-2 flex items-center justify-between">
                            <span class="text-xs text-slate-400">
                                Terkumpul: <strong class="text-white"><?= $as['total_submitted'] ?></strong> siswa
                            </span>
                            <a href="assignments.php?review_id=<?= $as['id'] ?>" 
                               class="rounded-xl bg-purple-600/20 hover:bg-purple-600/30 border border-purple-500/30 px-3 py-1.5 text-xs font-semibold text-purple-300 transition">
                                📝 Koreksi & Nilai →
                            </a>
                        </div>
                    <?php elseif ($user_role === 'orang_tua'): ?>
                        <div class="pt-2 text-center text-xs text-slate-400 bg-slate-950/60 py-2 rounded-xl border border-white/5">
                            Status: <span class="text-amber-400 font-medium">Batas Waktu: <?= date('d M Y', strtotime($as['due_date'])) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ========================================================= -->
<!-- MODAL KUMPULKAN BERKAS TUGAS (SISWA) -->
<!-- ========================================================= -->
<?php if ($user_role === 'siswa'): ?>
<div id="modalSubmitAssignment" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <div>
                <span class="text-xs font-semibold text-blue-400 uppercase tracking-wider">Pengumpulan Tugas</span>
                <h3 id="submitModalTitle" class="text-lg font-bold text-white"></h3>
            </div>
            <button onclick="document.getElementById('modalSubmitAssignment').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="submit_assignment">
            <input type="hidden" id="submitModalAssignId" name="assignment_id" value="">

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-300">Unggah Berkas Jawaban (PDF, DOCX, JPG, PNG, ZIP)</label>
                <input type="file" name="submission_file" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500">
                <p class="text-[11px] text-slate-500 mt-1">Maksimal 15 MB. Pastikan berkas dapat terbaca jelas.</p>
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-300">Atau Tautan Online (Google Drive / GitHub / Docs)</label>
                <input type="url" name="link_url" placeholder="https://drive.google.com/..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1.5 block text-xs font-medium text-slate-300">Catatan Pengerjaan untuk Guru (Opsional)</label>
                <textarea name="notes" rows="3" placeholder="Tuliskan catatan tambahan jika ada..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalSubmitAssignment').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-blue-500/25">
                    Kirim Jawaban
                </button>
            </div>
        </form>
    </div>
</div>
<script>
function openSubmitModal(id, title) {
    document.getElementById('submitModalAssignId').value = id;
    document.getElementById('submitModalTitle').textContent = title;
    document.getElementById('modalSubmitAssignment').classList.remove('hidden');
}
</script>
<?php endif; ?>

<!-- ========================================================= -->
<!-- MODAL TAMBAH TUGAS (GURU / ADMIN) -->
<!-- ========================================================= -->
<?php if ($is_teacher): ?>
<div id="modalCreateAssign" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span>📚</span> Buat Tugas Pelajaran Baru
            </h3>
            <button onclick="document.getElementById('modalCreateAssign').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_assignment">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Mata Pelajaran *</label>
                    <input type="text" name="subject" required placeholder="Contoh: Matematika" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Batas Waktu (Deadline) *</label>
                    <input type="date" name="due_date" required value="<?= date('Y-m-d', strtotime('+3 days')) ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Judul Tugas *</label>
                <input type="text" name="title" required placeholder="Contoh: Latihan Aljabar Bab 2" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Instruksi & Deskripsi Tugas</label>
                <textarea name="description" rows="3" placeholder="Jelaskan petunjuk pengerjaan tugas kepada siswa..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalCreateAssign').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition">
                    Bagikan Tugas
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
