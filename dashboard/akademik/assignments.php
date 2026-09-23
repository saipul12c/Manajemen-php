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
    mkdir($upload_dir, 0755, true);
}

// -------------------------------------------------------------
// 1. TAMBAH TUGAS (Guru / Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_assignment') {
    if (!$is_teacher) {
        header("Location: assignments.php?error=unauthorized");
        exit;
    }
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan coba lagi.";
        $message_type = "error";
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+3 days'));
        $attachment_link = trim($_POST['attachment_link'] ?? '');
        $attachment_url = $attachment_link ?: null;

        // Handle upload berkas soal / lampiran jika ada
        if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === UPLOAD_ERR_OK) {
            $f_tmp  = $_FILES['attachment_file']['tmp_name'];
            $f_name = $_FILES['attachment_file']['name'];
            $f_ext  = strtolower(pathinfo($f_name, PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip', 'xls', 'xlsx', 'ppt', 'pptx'];

            if (in_array($f_ext, $allowed, true) && $_FILES['attachment_file']['size'] <= 20 * 1024 * 1024) {
                $new_f_name = 'soal_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $f_ext;
                $dest = $upload_dir . '/' . $new_f_name;
                if (move_uploaded_file($f_tmp, $dest)) {
                    $attachment_url = '../../uploads/assignments/' . $new_f_name;
                }
            }
        }

        if ($subject === '' || $title === '' || $due_date === '') {
            $message = "Mata pelajaran, judul tugas, dan batas waktu wajib diisi.";
            $message_type = "error";
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO assignments (subject, title, description, due_date, attachment_url, teacher_id) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$subject, $title, $description, $due_date, $attachment_url, $user_id]);
            logActivity($pdo, 'CREATE_ASSIGNMENT', "Membuat tugas: $title ($subject)");
            $message = "Tugas pembelajaran baru berhasil dibuat dan dibagikan kepada siswa.";
            $message_type = "success";
        }
    } // end CSRF check
}

// -------------------------------------------------------------
// 2. SISWA: KUMPULKAN BERKAS TUGAS (Upload File / Link)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_assignment' && $user_role === 'siswa') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan coba lagi.";
        $message_type = "error";
    } else {
    $assignment_id = (int) ($_POST['assignment_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $link_url = trim($_POST['link_url'] ?? '');
    $file_url = $link_url ?: null;

    // BUG-09 fix: Handle File Upload jika ada dengan validasi MIME-type
    if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['submission_file']['tmp_name'];
        $file_name = $_FILES['submission_file']['name'];
        $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed   = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'zip'];
        $allowed_mimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg',
            'image/png',
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream'
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected_mime = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);

        if (!in_array($file_ext, $allowed, true) || !in_array($detected_mime, $allowed_mimes, true)) {
            $message = "Format atau isi file tidak didukung. Gunakan berkas asli: PDF, DOC, DOCX, JPG, PNG, atau ZIP.";
            $message_type = "error";
        } elseif ($_FILES['submission_file']['size'] > 15 * 1024 * 1024) {
            $message = "Ukuran file melebihi batas maksimal 15MB.";
            $message_type = "error";
        } else {
            $new_filename = 'tugas_' . $assignment_id . '_siswa_' . $user_id . '_' . time() . '.' . $file_ext;
            $destination  = $upload_dir . '/' . $new_filename;
            if (move_uploaded_file($file_tmp, $destination)) {
                $file_url = '../../uploads/assignments/' . $new_filename;
            }
        }
    }

    if ($assignment_id > 0 && $message_type !== 'error') {
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
    } // end CSRF check
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
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
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
    } // end CSRF check
}

// -------------------------------------------------------------
// 5. HAPUS TUGAS (Guru / Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_assignment' && $is_teacher) {
    if (validateCsrfToken()) {
        $delete_id = (int) ($_POST['delete_id'] ?? 0);
        $sql_del = "DELETE FROM assignments WHERE id = ?";
        $params_del = [$delete_id];
        if ($user_role === 'guru') {
            $sql_del .= " AND teacher_id = ?";
            $params_del[] = $user_id;
        }
        $stmt_del = $pdo->prepare($sql_del);
        $stmt_del->execute($params_del);
        $message = "Tugas berhasil dihapus.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 6. QUERY DAFTAR TUGAS & SUBMISSIONS
// -------------------------------------------------------------
$stmt_assign = $pdo->query("
    SELECT a.*, u.name as teacher_name,
           (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND status = 'selesai') as total_submitted,
           (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND status = 'selesai' AND score IS NOT NULL) as total_graded
    FROM assignments a 
    JOIN users u ON a.teacher_id = u.id 
    ORDER BY a.due_date ASC, a.id DESC
");
$assignments = $stmt_assign->fetchAll();

// Data pengumpulan spesifik per siswa (jika login sebagai siswa atau orang tua)
$my_submissions = [];
$child_info = null;

if ($user_role === 'siswa') {
    $stmt_my = $pdo->prepare("SELECT * FROM assignment_submissions WHERE student_id = ?");
    $stmt_my->execute([$user_id]);
    while ($row = $stmt_my->fetch()) {
        $my_submissions[$row['assignment_id']] = $row;
    }
} elseif ($user_role === 'orang_tua') {
    $stmt_p = $pdo->prepare("
        SELECT u.id, u.name, u.nisn, c.name as class_name 
        FROM parent_students ps
        JOIN users u ON ps.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE ps.parent_id = ? LIMIT 1
    ");
    $stmt_p->execute([$user_id]);
    $child_info = $stmt_p->fetch();
    if ($child_info) {
        $ch_id = (int)$child_info['id'];
        $stmt_my = $pdo->prepare("SELECT * FROM assignment_submissions WHERE student_id = ?");
        $stmt_my->execute([$ch_id]);
        while ($row = $stmt_my->fetch()) {
            $my_submissions[$row['assignment_id']] = $row;
        }
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
                   u.name as student_name, u.email as student_email, u.nisn
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
            <i class="fa-solid fa-book-open text-blue-400"></i> Tugas & Pengumpulan Berkas
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            <?= $is_teacher ? 'Kelola instruksi tugas, periksa berkas kiriman siswa, dan berikan nilai evaluasi.' : 'Kerjakan tugas, unggah berkas jawaban, dan pantau nilai feedback dari guru.' ?>
        </p>
    </div>

    <?php if ($is_teacher): ?>
        <div class="flex items-center gap-2">
            <a href="gradebook.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-slate-300 transition flex items-center gap-1.5">
                <i class="fa-solid fa-chart-column"></i> Buku Rekap Nilai
            </a>
            <button onclick="document.getElementById('modalCreateAssign').classList.remove('hidden')" 
                    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition cursor-pointer">
                <i class="fa-solid fa-plus"></i> Buat Tugas Baru
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== '' || isset($_GET['msg'])): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= ($message_type === 'error') ? 'border-rose-500/30 bg-rose-500/10 text-rose-300' : 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' ?>">
        <div class="flex items-center gap-3">
            <span><?= ($message_type === 'error') ? '<i class="fa-solid fa-triangle-exclamation text-rose-400"></i>' : '<i class="fa-solid fa-circle-check text-emerald-400"></i>' ?></span>
            <span><?= htmlspecialchars($message ?: "Status pengerjaan tugas berhasil diperbarui.") ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
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
        <a href="assignments.php" class="rounded-xl border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-slate-300 hover:bg-white/10 inline-flex items-center gap-1.5">
            <i class="fa-solid fa-xmark"></i> Tutup Panel Koreksi
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
                                    <i class="fa-solid fa-paperclip"></i> Buka / Unduh Berkas Jawaban <i class="fa-solid fa-arrow-up-right-from-square text-[10px] ml-0.5"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Form Input Nilai & Feedback -->
                    <form method="POST" action="assignments.php?review_id=<?= $selected_assign_id ?>" class="flex flex-wrap items-center gap-3 bg-white/[0.02] p-3 rounded-xl border border-white/5">
                        <input type="hidden" name="action" value="grade_submission">
                        <input type="hidden" name="submission_id" value="<?= $sub['submission_id'] ?>">
                        <?= csrfField() ?>

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
            <span class="text-4xl text-slate-600 block mb-2"><i class="fa-solid fa-book-open-reader"></i></span>
            <p class="mt-3 text-base font-semibold text-white">Belum Ada Tugas Aktif</p>
            <p class="text-xs text-slate-500 mt-1">Saat ini belum ada tugas yang dibagikan oleh dewan guru.</p>
        </div>
    <?php else: ?>
        <?php foreach ($assignments as $as): 
            $sub_data = $my_submissions[$as['id']] ?? null;
            $is_done = ($sub_data !== null && ($sub_data['status'] ?? '') === 'selesai');
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
                            <form method="POST" class="inline" onsubmit="return confirm('Hapus tugas ini?');">
                                <input type="hidden" name="action" value="delete_assignment">
                                <input type="hidden" name="delete_id" value="<?= $as['id'] ?>">
                                <?= csrfField() ?>
                                <button type="submit" class="inline-flex items-center gap-1 text-xs text-rose-400 hover:text-rose-300 cursor-pointer">
                                    <i class="fa-solid fa-trash"></i> Hapus
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <h3 class="text-base font-bold text-white mb-2"><?= htmlspecialchars($as['title']) ?></h3>
                    <p class="text-xs text-slate-300 leading-relaxed line-clamp-3 mb-4">
                        <?= htmlspecialchars($as['description'] ?: 'Tidak ada instruksi tambahan.') ?>
                    </p>

                    <!-- Lampiran Lembar Soal Guru jika ada -->
                    <?php if (!empty($as['attachment_url'])): ?>
                        <div class="mb-4">
                            <a href="<?= htmlspecialchars($as['attachment_url']) ?>" target="_blank" 
                               class="inline-flex items-center gap-1.5 rounded-xl border border-blue-500/30 bg-blue-500/10 px-3 py-1.5 text-xs font-semibold text-blue-300 hover:bg-blue-500/20 transition">
                                <i class="fa-solid fa-paperclip"></i> Unduh Lembar Soal / Lampiran <i class="fa-solid fa-arrow-up-right-from-square text-[10px] ml-0.5"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="pt-4 border-t border-white/5 space-y-3">
                    <div class="flex items-center justify-between text-xs text-slate-400">
                        <span>Guru: <strong class="text-slate-200"><?= htmlspecialchars($as['teacher_name']) ?></strong></span>
                        <span class="<?= $is_overdue ? 'text-rose-400 font-semibold' : 'text-amber-300' ?>">
                            <i class="fa-regular fa-clock mr-1"></i><?= date('d M Y', strtotime($as['due_date'])) ?>
                        </span>
                    </div>

                    <?php if ($user_role === 'siswa'): ?>
                        <!-- Status Pengerjaan & Nilai Siswa -->
                        <?php if ($has_score): ?>
                            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs">
                                <div class="flex items-center justify-between font-bold text-emerald-300 mb-1">
                                    <span class="flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle-check text-emerald-400"></i> Nilai Evaluasi:
                                    </span>
                                    <span class="text-sm font-black text-emerald-200"><?= number_format((float)$sub_data['score'], 1) ?> / 100</span>
                                </div>
                                <?php if (!empty($sub_data['feedback'])): ?>
                                    <p class="text-slate-300 italic text-[11px] bg-white/5 p-2 rounded-lg mt-1 border border-white/5">
                                        Catatan Guru: "<?= htmlspecialchars($sub_data['feedback']) ?>"
                                    </p>
                                <?php endif; ?>
                                <?php if (!empty($sub_data['file_url'])): ?>
                                    <a href="<?= htmlspecialchars($sub_data['file_url']) ?>" target="_blank" class="text-blue-400 hover:underline inline-block mt-2 font-medium text-[11px]">
                                        <i class="fa-solid fa-paperclip mr-1"></i>Buka Berkas Terkirim <i class="fa-solid fa-arrow-up-right-from-square text-[10px] ml-0.5"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($is_done): ?>
                            <div class="rounded-xl border border-blue-500/30 bg-blue-500/10 p-2.5 text-xs text-blue-300 flex items-center justify-between">
                                <span class="flex items-center gap-1.5">
                                    <i class="fa-solid fa-hourglass-half text-blue-400"></i> Terkumpul (Menunggu Penilaian)
                                </span>
                                <?php if (!empty($sub_data['file_url'])): ?>
                                    <a href="<?= htmlspecialchars($sub_data['file_url']) ?>" target="_blank" class="text-blue-400 hover:underline font-semibold text-[11px]">
                                        <i class="fa-solid fa-paperclip mr-1"></i>Berkas <i class="fa-solid fa-arrow-up-right-from-square text-[10px] ml-0.5"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-2 text-xs text-amber-300 flex items-center gap-1.5">
                                <i class="fa-solid fa-circle-exclamation text-amber-400"></i> Belum Mengumpulkan Tugas
                            </div>
                        <?php endif; ?>

                        <div class="pt-1 flex gap-2">
                            <button type="button"
                                    onclick="openSubmitModal(<?= $as['id'] ?>, '<?= htmlspecialchars(addslashes($as['title'])) ?>', '<?= htmlspecialchars(addslashes($sub_data['notes'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($sub_data['file_url'] ?? '')) ?>')"
                                    class="flex-1 py-2 px-3 rounded-xl text-xs font-semibold text-center transition bg-blue-600 hover:bg-blue-500 text-white shadow-lg shadow-blue-500/20 cursor-pointer">
                                <?= $is_done ? '<i class="fa-solid fa-rotate mr-1"></i>Perbarui / Kirim Ulang' : '<i class="fa-solid fa-upload mr-1"></i>Kumpulkan Berkas' ?>
                            </button>
                            <a href="assignments.php?toggle_id=<?= $as['id'] ?>" 
                               title="<?= $is_done ? 'Tandai belum selesai' : 'Tandai selesai' ?>"
                               class="py-2 px-3 rounded-xl text-xs font-semibold transition border <?= $is_done ? 'bg-emerald-600/20 text-emerald-300 border-emerald-500/30' : 'bg-white/5 text-slate-400 border-white/10' ?>">
                                <?= $is_done ? '<i class="fa-solid fa-check mr-1"></i>Selesai' : 'Belum' ?>
                            </a>
                        </div>

                    <?php elseif ($is_teacher): ?>
                        <div class="pt-2 flex items-center justify-between">
                            <span class="text-xs text-slate-400">
                                Terkumpul: <strong class="text-white"><?= $as['total_submitted'] ?></strong> siswa
                                <span class="text-emerald-400 text-[11px] font-medium">(<?= $as['total_graded'] ?? 0 ?> dinilai)</span>
                            </span>
                            <a href="assignments.php?review_id=<?= $as['id'] ?>" 
                                class="rounded-xl bg-purple-600/20 hover:bg-purple-600/30 border border-purple-500/30 px-3 py-1.5 text-xs font-semibold text-purple-300 transition flex items-center gap-1.5">
                                <i class="fa-solid fa-pen-to-square"></i> Koreksi & Nilai <i class="fa-solid fa-arrow-right text-[10px] ml-0.5"></i>
                            </a>
                        </div>
                    <?php elseif ($user_role === 'orang_tua'): ?>
                        <!-- Status Pengerjaan Anak bagi Orang Tua -->
                        <div class="pt-1 space-y-2">
                            <?php if ($child_info): ?>
                                <p class="text-[11px] text-slate-400">Status Belajar: <strong class="text-white"><?= htmlspecialchars($child_info['name']) ?></strong></p>
                            <?php endif; ?>

                            <?php if ($has_score): ?>
                                <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs">
                                    <div class="flex items-center justify-between font-bold text-emerald-300 mb-1">
                                        <span>Nilai Tugas:</span>
                                        <span class="text-sm font-black text-emerald-200"><?= number_format((float)$sub_data['score'], 1) ?> / 100</span>
                                    </div>
                                    <?php if (!empty($sub_data['feedback'])): ?>
                                        <p class="text-slate-300 italic text-[11px] bg-white/5 p-2 rounded-lg mt-1">
                                            Catatan Guru: "<?= htmlspecialchars($sub_data['feedback']) ?>"
                                        </p>
                                    <?php endif; ?>
                                    <?php if (!empty($sub_data['file_url'])): ?>
                                        <a href="<?= htmlspecialchars($sub_data['file_url']) ?>" target="_blank" class="text-blue-400 hover:underline inline-block mt-2 font-medium text-[11px]">
                                            <i class="fa-solid fa-paperclip mr-1"></i>Buka Berkas Jawaban Anak <i class="fa-solid fa-arrow-up-right-from-square text-[10px] ml-0.5"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($is_done): ?>
                                <div class="rounded-xl border border-blue-500/30 bg-blue-500/10 p-2.5 text-xs text-blue-300">
                                    <span><i class="fa-solid fa-hourglass-half mr-1 text-blue-400"></i>Sudah mengumpulkan tugas (Sedang menunggu penilaian guru).</span>
                                    <?php if (!empty($sub_data['file_url'])): ?>
                                        <a href="<?= htmlspecialchars($sub_data['file_url']) ?>" target="_blank" class="text-blue-400 hover:underline block mt-1.5 font-medium text-[11px]">
                                            <i class="fa-solid fa-paperclip mr-1"></i>Buka Berkas Jawaban <i class="fa-solid fa-arrow-up-right-from-square text-[10px] ml-0.5"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-2.5 text-xs text-rose-300">
                                    <span><i class="fa-solid fa-triangle-exclamation mr-1 text-rose-400"></i>Belum mengumpulkan tugas ini. Batas waktu: <?= date('d M Y', strtotime($as['due_date'])) ?></span>
                                </div>
                            <?php endif; ?>
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
            <button onclick="document.getElementById('modalSubmitAssignment').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="submit_assignment">
            <input type="hidden" id="submitModalAssignId" name="assignment_id" value="">
            <?= csrfField() ?>

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
function openSubmitModal(id, title, notes = '', fileUrl = '') {
    document.getElementById('submitModalAssignId').value = id;
    document.getElementById('submitModalTitle').textContent = title;
    const notesElem = document.querySelector('#modalSubmitAssignment textarea[name="notes"]');
    if (notesElem) notesElem.value = notes;
    const linkElem = document.querySelector('#modalSubmitAssignment input[name="link_url"]');
    if (linkElem) linkElem.value = fileUrl.startsWith('http') ? fileUrl : '';
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
                <i class="fa-solid fa-book-open text-blue-400"></i> Buat Tugas Pelajaran Baru
            </h3>
            <button onclick="document.getElementById('modalCreateAssign').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="create_assignment">
            <?= csrfField() ?>

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

            <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-4 space-y-3">
                <label class="block text-xs font-bold text-slate-300">Lampirkan Lembar Soal / Materi (Opsional)</label>
                <div>
                    <input type="file" name="attachment_file" class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-xs text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-purple-600 file:text-white hover:file:bg-purple-500">
                    <p class="text-[11px] text-slate-500 mt-1">Format: PDF, Word, Excel, PowerPoint, ZIP, Gambar (Maks. 20MB)</p>
                </div>
                <div>
                    <input type="url" name="attachment_link" placeholder="Atau tautan Google Drive / Docs..." class="w-full rounded-xl border border-white/10 bg-slate-900 px-3.5 py-2 text-xs text-white outline-none focus:border-blue-500">
                </div>
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
