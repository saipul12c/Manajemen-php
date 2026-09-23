<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';

$exam_id = (int) ($_GET['id'] ?? 0);
if ($exam_id <= 0) {
    header("Location: exams.php");
    exit;
}

// Ambil data ujian
$stmt_e = $pdo->prepare("
    SELECT e.*, u.name as teacher_name 
    FROM exams e 
    JOIN users u ON e.teacher_id = u.id 
    WHERE e.id = ?
");
$stmt_e->execute([$exam_id]);
$exam = $stmt_e->fetch();

if (!$exam) {
    header("Location: exams.php?error=notfound");
    exit;
}

$cat_info = EXAM_CATEGORIES[$exam['category']] ?? ['label' => $exam['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '<i class="fa-solid fa-pen-to-square"></i>'];

// Tentukan apakah user melihat sebagai pengajar/admin atau sebagai siswa/orang tua
$can_view_all = in_array($user_role, ['guru', 'administrator', 'staf'], true);

// Ambil butir soal untuk review pembahasan & perhitungan
$stmt_q = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY id ASC");
$stmt_q->execute([$exam_id]);
$questions = $stmt_q->fetchAll();

$essay_questions_list = array_values(array_filter($questions, fn($q) => ($q['question_type'] ?? 'multiple_choice') === 'essay'));
$has_essay_questions = count($essay_questions_list) > 0;

// Flag apakah kunci jawaban harus dirahasiakan dari siswa
$hide_answers = !empty($exam['hide_answers_until_due']) && !empty($exam['due_date']) && (time() < strtotime($exam['due_date'])) && !$can_view_all;

// -------------------------------------------------------------
// AKSI GURU / ADMIN: SIMPAN NILAI KOREKSI ESAI
// -------------------------------------------------------------
if ($can_view_all && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_essay_grades') {
    // BUG-04 fix: Validasi CSRF Token untuk penilaian esai
    if (!validateCsrfToken()) {
        header("Location: exam_results.php?id=$exam_id&error=invalid_csrf");
        exit;
    }
    $target_sub_id = (int) ($_POST['submission_id'] ?? 0);
    $submitted_essay_scores = $_POST['essay_scores'] ?? []; // [qid => score]

    $stmt_tgt = $pdo->prepare("SELECT * FROM exam_submissions WHERE id = ? AND exam_id = ?");
    $stmt_tgt->execute([$target_sub_id, $exam_id]);
    $sub_to_grade = $stmt_tgt->fetch();

    if ($sub_to_grade) {
        $sub_answers = json_decode($sub_to_grade['answers'] ?? '{}', true);

        $total_possible = 0;
        $earned_pg = 0;
        $earned_essay = 0;
        $clean_essay_scores = [];

        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $max_pts = !empty($q['max_score']) ? (float)$q['max_score'] : 10;
            $total_possible += $max_pts;

            if (($q['question_type'] ?? 'multiple_choice') === 'essay') {
                $awarded = isset($submitted_essay_scores[$qid]) ? max(0, min($max_pts, (float)$submitted_essay_scores[$qid])) : 0;
                $clean_essay_scores[$qid] = $awarded;
                $earned_essay += $awarded;
            } else {
                $s_ans = $sub_answers[$qid] ?? '';
                if ($s_ans !== '' && strtoupper($s_ans) === strtoupper($q['correct_answer'])) {
                    $earned_pg += $max_pts;
                }
            }
        }

        $new_score = ($total_possible > 0) ? round((($earned_pg + $earned_essay) / $total_possible) * 100, 2) : 0;
        $essay_scores_json = json_encode($clean_essay_scores);

        $stmt_save = $pdo->prepare("
            UPDATE exam_submissions 
            SET score = ?, 
                essay_scores = ?, 
                essay_graded = 1 
            WHERE id = ?
        ");
        $stmt_save->execute([$new_score, $essay_scores_json, $target_sub_id]);

        header("Location: exam_results.php?id=$exam_id&msg=essay_graded");
        exit;
    }
}

// -------------------------------------------------------------
// AKSI GURU / ADMIN: BERI ATAU BATALKAN IZIN REMEDIAL (BUG-03 fix: POST + CSRF)
// -------------------------------------------------------------
if ($can_view_all && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['grant_remedial', 'revoke_remedial'], true)) {
    if (!validateCsrfToken()) {
        header("Location: exam_results.php?id=$exam_id&error=invalid_csrf");
        exit;
    }
    $action = $_POST['action'];
    $sub_id = (int) ($_POST['sub_id'] ?? 0);

    if ($sub_id > 0) {
        if ($action === 'grant_remedial') {
            $stmt_grant = $pdo->prepare("UPDATE exam_submissions SET remedial_granted = 1 WHERE id = ? AND exam_id = ?");
            $stmt_grant->execute([$sub_id, $exam_id]);
            header("Location: exam_results.php?id=$exam_id&msg=remedial_granted");
            exit;
        } elseif ($action === 'revoke_remedial') {
            $stmt_rev = $pdo->prepare("UPDATE exam_submissions SET remedial_granted = 0 WHERE id = ? AND exam_id = ?");
            $stmt_rev->execute([$sub_id, $exam_id]);
            header("Location: exam_results.php?id=$exam_id&msg=remedial_revoked");
            exit;
        }
    }
}

// Jika Guru/Admin: Ambil seluruh submission siswa
$all_submissions = [];
$avg_score = 0;
$highest_score = 0;
$lowest_score = 100;
$pass_count = 0;

if ($can_view_all) {
    $stmt_all = $pdo->prepare("
        SELECT es.*, u.name as student_name, u.email as student_email 
        FROM exam_submissions es 
        JOIN users u ON es.student_id = u.id 
        WHERE es.exam_id = ? 
        ORDER BY es.score DESC, es.submitted_at ASC
    ");
    $stmt_all->execute([$exam_id]);
    $all_submissions = $stmt_all->fetchAll();

    if (!empty($all_submissions)) {
        $total_score_sum = 0;
        foreach ($all_submissions as $s) {
            $total_score_sum += (float) $s['score'];
            if ($s['score'] > $highest_score) $highest_score = (float) $s['score'];
            if ($s['score'] < $lowest_score) $lowest_score = (float) $s['score'];
            if ($s['score'] >= $exam['passing_grade']) $pass_count++;
        }
        $avg_score = $total_score_sum / count($all_submissions);
    } else {
        $lowest_score = 0;
    }
}

// Untuk Siswa atau Orang Tua: Ambil hasil submission milik siswa
$my_submission = null;
$target_student_id = $user_id;

if ($user_role === 'siswa') {
    $stmt_my = $pdo->prepare("SELECT * FROM exam_submissions WHERE exam_id = ? AND student_id = ?");
    $stmt_my->execute([$exam_id, $target_student_id]);
    $my_submission = $stmt_my->fetch();
} elseif ($user_role === 'orang_tua') {
    $stmt_p = $pdo->prepare("
        SELECT es.*, u.name as student_name 
        FROM exam_submissions es 
        JOIN users u ON es.student_id = u.id 
        WHERE es.exam_id = ? 
        ORDER BY es.id DESC LIMIT 1
    ");
    $stmt_p->execute([$exam_id]);
    $my_submission = $stmt_p->fetch();
}

$page_title = "Rekap Nilai - " . $exam['title'];
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-8">

    <!-- Header & Back Button -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-white/10 pb-6">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <a href="exams.php" class="text-xs font-semibold text-blue-400 hover:underline">
                    ← Kembali ke Modul Ujian & Latihan
                </a>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                <span class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-bold <?= $cat_info['badge'] ?>">
                    <span><?= $cat_info['icon'] ?></span>
                    <span><?= htmlspecialchars($cat_info['label']) ?></span>
                </span>
                <span class="text-xs text-slate-400">Mapel: <strong class="text-white"><?= htmlspecialchars($exam['subject']) ?></strong></span>
                <span class="text-xs text-slate-400">KKM: <strong class="text-white"><?= $exam['passing_grade'] ?></strong></span>
                <span class="text-xs text-slate-400">Guru: <strong class="text-white"><?= htmlspecialchars($exam['teacher_name']) ?></strong></span>
                <?php if (!empty($exam['token'])): ?>
                    <span class="inline-flex items-center gap-1.5 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[11px] font-mono font-bold text-amber-300">
                        <i class="fa-solid fa-key"></i> Token: <?= htmlspecialchars($exam['token']) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($exam['hide_answers_until_due'])): ?>
                    <span class="inline-flex items-center gap-1.5 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[11px] font-bold text-amber-300" title="Kunci jawaban dirahasiakan hingga batas akhir ujian">
                        <i class="fa-solid fa-lock"></i> Kunci Dirahasiakan
                    </span>
                <?php endif; ?>
            </div>
            <h1 class="text-2xl font-extrabold text-white mt-2">
                <?= htmlspecialchars($exam['title']) ?>
            </h1>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <?php if (in_array($user_role, ['guru', 'administrator', 'staf'], true)): ?>
                <a href="exam_print.php?id=<?= $exam['id'] ?>" target="_blank"
                   class="rounded-xl border border-blue-500/30 bg-blue-500/10 hover:bg-blue-500/20 px-3.5 py-2 text-xs font-bold text-blue-300 transition flex items-center gap-1.5 shadow-sm">
                    <i class="fa-solid fa-print"></i> Cetak Berita Acara & Nilai
                </a>
                <a href="exam_export.php?id=<?= $exam['id'] ?>" 
                   class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3.5 py-2 text-xs font-bold text-emerald-300 transition flex items-center gap-1.5 shadow-sm">
                    <i class="fa-solid fa-file-arrow-down"></i> Unduh CSV / Excel
                </a>
                <a href="exam_questions.php?id=<?= $exam['id'] ?>" 
                   class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-white transition flex items-center gap-1.5">
                    <i class="fa-solid fa-pen-to-square"></i> Kelola Soal (<?= count($questions) ?>)
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Sukses Submit Ujian Standar -->
    <?php if (isset($_GET['submitted'])): ?>
        <div class="rounded-3xl border border-emerald-500/30 bg-emerald-500/10 p-6 text-emerald-200 shadow-xl flex items-center gap-4">
            <i class="fa-solid fa-circle-check text-emerald-400 text-3xl"></i>
            <div>
                <h3 class="text-lg font-bold text-white">Ujian Berhasil Dikumpulkan!</h3>
                <p class="text-xs sm:text-sm text-emerald-300/90 mt-0.5">
                    Jawaban Anda telah tersimpan ke sistem.
                    <?php if ($has_essay_questions): ?>
                        Ujian ini memiliki soal esai yang akan diperiksa oleh guru sebelum skor akhir difinalisasi.
                    <?php else: ?>
                        Berikut adalah rekapitulasi skor dan pembahasan butir soal.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Alert Sukses Submit Remedial -->
    <?php if (isset($_GET['remedial_submitted'])): ?>
        <div class="rounded-3xl border border-purple-500/30 bg-purple-500/10 p-6 text-purple-200 shadow-xl flex items-center gap-4">
            <i class="fa-solid fa-wand-magic-sparkles text-purple-400 text-3xl"></i>
            <div>
                <h3 class="text-lg font-bold text-white">Sesi Remedial Berhasil Dikumpulkan!</h3>
                <p class="text-xs sm:text-sm text-purple-300/90 mt-0.5">
                    Nilai baru hasil pengerjaan remedial Anda telah diperbarui ke sistem rekapitulasi.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Alert Feedback Guru (Beri Remedial / Koreksi Esai) -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'essay_graded'): ?>
        <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-xs font-semibold text-emerald-300 flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> Penilaian soal esai berhasil disimpan! Skor akhir siswa telah diperbarui secara otomatis.
        </div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'remedial_granted'): ?>
        <div class="rounded-2xl border border-blue-500/30 bg-blue-500/10 p-4 text-xs font-semibold text-blue-300 flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> Izin remedial berhasil diberikan kepada siswa terpilih. Siswa kini dapat mengerjakan ulang modul ujian ini.
        </div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'remedial_revoked'): ?>
        <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-xs font-semibold text-amber-300 flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> Izin remedial berhasil dibatalkan.
        </div>
    <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_csrf'): ?>
        <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4 text-xs font-semibold text-rose-300 flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> Token keamanan tidak valid atau telah kadaluarsa. Silakan ulangi aksi Anda.
        </div>
    <?php endif; ?>

    <!-- TAMPILAN SISWA & ORANG TUA: KARTU SKOR & PEMBAHASAN -->
    <?php if (in_array($user_role, ['siswa', 'orang_tua'], true)): ?>
        <?php if ($my_submission): ?>
            <?php 
                $my_score = (float) $my_submission['score'];
                $is_passed = ($my_score >= $exam['passing_grade']);
                $answers_data = json_decode($my_submission['answers'] ?? '{}', true);
                $is_remedial_sub = !empty($my_submission['is_remedial']) && (int)$my_submission['is_remedial'] === 1;
                $remedial_ready = !empty($my_submission['remedial_granted']) && (int)$my_submission['remedial_granted'] === 1;
            ?>

            <!-- Banner Undangan Remedial Aktif (Jika Guru Mengizinkan) -->
            <?php if ($remedial_ready && $user_role === 'siswa'): ?>
                <div class="rounded-3xl border border-amber-500/50 bg-gradient-to-r from-amber-950/70 via-slate-900 to-slate-900 p-6 shadow-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-start gap-3.5">
                        <i class="fa-solid fa-bullhorn text-amber-400 text-2xl mt-1"></i>
                        <div>
                            <span class="inline-flex items-center gap-1 rounded-md border border-amber-500/40 bg-amber-500/20 px-2 py-0.5 text-[11px] font-black text-amber-300 uppercase tracking-wider mb-1">
                                Kesempatan Remedial Dibuka
                            </span>
                            <h3 class="text-base sm:text-lg font-bold text-white">Guru Pengawas telah memberikan izin perbaikan nilai!</h3>
                            <p class="text-xs text-amber-200/80 mt-0.5">
                                Manfaatkan kesempatan ini untuk memperbaiki skor di bawah standar KKM (<?= $exam['passing_grade'] ?>).
                            </p>
                        </div>
                    </div>
                    <a href="exam_take.php?id=<?= $exam['id'] ?>&remedial=1" 
                       class="rounded-xl bg-amber-500 hover:bg-amber-400 px-5 py-3 text-xs font-black text-slate-950 shadow-lg shadow-amber-500/20 transition shrink-0 text-center inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-pen-to-square"></i> Kerjakan Remedial Sekarang
                    </a>
                </div>
            <?php endif; ?>

            <!-- Score Banner -->
            <div class="rounded-3xl border border-white/10 bg-gradient-to-r <?= $is_passed ? 'from-emerald-950/60 via-slate-900 to-slate-900' : 'from-rose-950/60 via-slate-900 to-slate-900' ?> p-6 sm:p-8 shadow-2xl">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-6">
                    <div>
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-extrabold uppercase tracking-wider <?= $is_passed ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
                                <?= $is_passed ? '<i class="fa-solid fa-circle-check"></i> MEMENUHI KKM KELULUSAN' : '<i class="fa-solid fa-triangle-exclamation"></i> PERLU REMEDIAL / EVALUASI' ?>
                            </span>
                            <?php if ($is_remedial_sub): ?>
                                <span class="inline-flex items-center gap-1 rounded-full border border-purple-500/30 bg-purple-500/20 px-2.5 py-1 text-xs font-bold text-purple-300">
                                    <i class="fa-solid fa-wand-magic-sparkles mr-1"></i> Hasil Remedial
                                </span>
                            <?php endif; ?>
                        </div>
                        <h2 class="text-xl sm:text-2xl font-bold text-white">
                            <?= ($user_role === 'orang_tua') ? 'Hasil Belajar Putra/Putri Anda' : 'Hasil Pengerjaan Anda' ?>
                        </h2>
                        <p class="text-xs sm:text-sm text-slate-400 mt-1">
                            Diserahkan pada <?= date('d M Y, H:i', strtotime($my_submission['submitted_at'])) ?> WIB
                        </p>
                    </div>

                    <div class="flex items-center gap-6 border-t sm:border-t-0 sm:border-l border-white/10 pt-4 sm:pt-0 sm:pl-8">
                        <div class="text-center">
                            <span class="block text-xs uppercase tracking-wider text-slate-400 font-bold">Skor Akhir</span>
                            <span class="text-4xl sm:text-5xl font-black text-white">
                                <?= number_format($my_score, 0) ?>
                            </span>
                            <span class="block text-xs text-slate-400">dari 100</span>
                        </div>

                        <div class="text-xs space-y-1.5 border-l border-white/10 pl-6 text-slate-300">
                            <div>Benar: <strong class="text-emerald-400"><?= $my_submission['total_correct'] ?></strong> / <?= $my_submission['total_questions'] ?> Soal</div>
                            <div>Salah: <strong class="text-rose-400"><?= $my_submission['total_questions'] - $my_submission['total_correct'] ?></strong> Soal</div>
                            <div>Standar KKM: <strong class="text-white"><?= $exam['passing_grade'] ?></strong></div>
                            <?php if ($is_remedial_sub && $my_submission['previous_score'] !== null): ?>
                                <div class="pt-1 border-t border-white/10 text-purple-300">
                                    Nilai Awal: <strong class="line-through text-slate-400"><?= number_format($my_submission['previous_score'], 0) ?></strong> &rarr; Remedial: <strong class="text-white"><?= number_format($my_score, 0) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Review Butir Soal & Kunci Jawaban -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-magnifying-glass text-blue-400"></i> Evaluasi & Pembahasan Soal
                    </h3>
                    <span class="text-xs text-slate-400">Total <?= count($questions) ?> Butir Soal</span>
                </div>

                <?php if ($hide_answers): ?>
                    <!-- Kunci Jawaban & Pembahasan Dirahasiakan Sementara -->
                    <div class="rounded-3xl border border-amber-500/30 bg-slate-900/90 p-8 sm:p-10 text-center space-y-4 shadow-2xl">
                        <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-amber-500/10 border border-amber-500/20 text-3xl text-amber-400 shadow-inner">
                            <i class="fa-solid fa-lock"></i>
                        </div>
                        <div class="space-y-2 max-w-lg mx-auto">
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-300">
                                Perlindungan Keamanan Integritas CBT
                            </span>
                            <h3 class="text-xl font-black text-white">Kunci Jawaban & Pembahasan Dirahasiakan Sementara</h3>
                            <p class="text-xs sm:text-sm text-slate-400 leading-relaxed">
                                Untuk menjaga kejujuran pelaksanaan evaluasi (UTS / UKK / Ujian Resmi), kunci jawaban dan pembahasan butir soal baru akan dibuka secara otomatis kepada seluruh peserta setelah batas waktu ujian resmi berakhir:
                            </p>
                            <div class="pt-2">
                                <span class="inline-flex items-center gap-2 rounded-2xl border border-amber-500/40 bg-amber-500/10 px-5 py-2.5 text-xs sm:text-sm font-mono font-black text-amber-300 shadow-md">
                                    <i class="fa-regular fa-calendar-days"></i> Dibuka Otomatis: <?= date('d M Y, H:i', strtotime($exam['due_date'])) ?> WIB
                                </span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Pembahasan Lengkap Terbuka -->
                    <?php 
                    $my_essay_scores = json_decode($my_submission['essay_scores'] ?? '{}', true);
                    $is_essay_graded = !empty($my_submission['essay_graded']) && (int)$my_submission['essay_graded'] === 1;
                    ?>
                    <div class="space-y-4">
                        <?php foreach ($questions as $idx => $q): ?>
                            <?php 
                                $q_type = $q['question_type'] ?? 'multiple_choice';
                                $student_choice = $answers_data[$q['id']] ?? '';
                                $is_essay = ($q_type === 'essay');
                                $is_correct = (!$is_essay && strtoupper($student_choice) === strtoupper($q['correct_answer']));
                                
                                $card_border = $is_essay ? 'border-purple-500/30' : ($is_correct ? 'border-emerald-500/30' : 'border-rose-500/30');
                            ?>
                            <div class="rounded-3xl border <?= $card_border ?> bg-slate-900/70 p-6 shadow-xl space-y-4">
                                
                                <!-- Header Soal & Status Jawaban -->
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-white/5 pb-3">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-xl bg-white/10 text-white font-extrabold text-xs">
                                            <?= $idx + 1 ?>
                                        </span>
                                        <?php if ($is_essay): ?>
                                            <span class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-0.5 text-xs font-bold bg-purple-500/20 text-purple-300 border border-purple-500/30">
                                                <i class="fa-solid fa-pen-nib"></i> Soal Esai / Uraian
                                            </span>
                                            <span class="text-xs text-slate-400">Bobot Maks: <?= (int)$q['max_score'] ?> Poin</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-bold <?= $is_correct ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-rose-500/20 text-rose-300 border border-rose-500/30' ?>">
                                                <?= $is_correct ? '<i class="fa-solid fa-check"></i> Pilihan Ganda Benar' : '<i class="fa-solid fa-xmark"></i> Pilihan Ganda Salah' ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div>
                                        <?php if ($is_essay): ?>
                                            <?php if ($is_essay_graded): ?>
                                                <span class="rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-3 py-1 text-xs font-bold text-emerald-300 inline-flex items-center gap-1">
                                                    <i class="fa-solid fa-check"></i> Nilai Guru: <strong><?= (float)($my_essay_scores[$q['id']] ?? 0) ?></strong> / <?= (int)$q['max_score'] ?> Poin
                                                </span>
                                            <?php else: ?>
                                                <span class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-300 inline-flex items-center gap-1">
                                                    <i class="fa-regular fa-clock"></i> Menunggu Penilaian Guru
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="text-xs text-slate-400">
                                                Jawaban Anda: <strong class="<?= $is_correct ? 'text-emerald-400' : 'text-rose-400' ?>"><?= $student_choice ?: '-' ?></strong> | Kunci: <strong class="text-emerald-400"><?= $q['correct_answer'] ?></strong>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Pertanyaan -->
                                <p class="text-sm font-medium text-white leading-relaxed">
                                    <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                                </p>

                                <!-- Gambar Pendukung Soal (Jika Ada) -->
                                <?php if (!empty($q['image_url'])): ?>
                                    <div class="pt-1">
                                        <div class="inline-block rounded-2xl overflow-hidden border border-white/15 bg-black/40 p-2 shadow-lg max-w-full">
                                            <img src="<?= htmlspecialchars($q['image_url']) ?>" 
                                                 alt="Gambar Pendukung Soal <?= $idx + 1 ?>" 
                                                 class="max-h-64 w-auto rounded-xl object-contain">
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($is_essay): ?>
                                    <!-- Review Jawaban Esai Siswa -->
                                    <div class="space-y-2">
                                        <span class="block text-xs font-bold text-purple-300 flex items-center gap-1.5"><i class="fa-solid fa-pen-fancy"></i> Lembar Jawaban Uraian Anda:</span>
                                        <div class="rounded-2xl border border-purple-500/20 bg-purple-950/20 p-4 text-xs sm:text-sm text-slate-100 whitespace-pre-wrap font-sans leading-relaxed">
                                            <?= !empty($student_choice) ? htmlspecialchars($student_choice) : '<em class="text-slate-500">Tidak ada jawaban yang diisi.</em>' ?>
                                        </div>
                                    </div>

                                    <!-- Rubrik Pedoman Penilaian (Jika Ada) -->
                                    <?php if (!empty($q['explanation'])): ?>
                                        <div class="rounded-2xl border border-blue-500/20 bg-blue-500/10 p-3 text-xs text-blue-300">
                                            <strong class="font-bold text-white flex items-center gap-1.5 mb-0.5"><i class="fa-solid fa-clipboard-list text-blue-400"></i> Rubrik / Pedoman Penilaian Soal:</strong>
                                            <?= nl2br(htmlspecialchars($q['explanation'])) ?>
                                        </div>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <!-- Pilihan Jawaban PG -->
                                    <?php 
                                    $options = [
                                        'A' => $q['option_a'],
                                        'B' => $q['option_b'],
                                        'C' => $q['option_c'],
                                        'D' => $q['option_d'],
                                    ];
                                    ?>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                        <?php foreach ($options as $opt_key => $opt_val): ?>
                                            <?php 
                                                $is_this_correct = ($opt_key === $q['correct_answer']);
                                                $is_this_student = ($opt_key === $student_choice);

                                                $opt_style = 'border-white/5 bg-white/5 text-slate-400';
                                                if ($is_this_correct) {
                                                    $opt_style = 'border-emerald-500/50 bg-emerald-500/15 text-emerald-200 font-semibold';
                                                } elseif ($is_this_student && !$is_this_correct) {
                                                    $opt_style = 'border-rose-500/50 bg-rose-500/15 text-rose-300 line-through';
                                                }
                                            ?>
                                            <div class="rounded-xl border p-2.5 flex items-center gap-2 <?= $opt_style ?>">
                                                <span class="font-bold"><?= $opt_key ?>.</span>
                                                <span><?= htmlspecialchars($opt_val) ?></span>
                                                <?php if ($is_this_correct): ?>
                                                    <span class="ml-auto text-emerald-400 font-bold flex items-center gap-1"><i class="fa-solid fa-check"></i> Kunci</span>
                                                <?php elseif ($is_this_student): ?>
                                                    <span class="ml-auto text-rose-400 font-bold">Pilihan Anda</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Pembahasan PG -->
                                    <?php if (!empty($q['explanation'])): ?>
                                        <div class="rounded-2xl border border-blue-500/20 bg-blue-500/10 p-3 text-xs text-blue-300">
                                            <strong class="font-bold text-white flex items-center gap-1.5 mb-0.5"><i class="fa-solid fa-lightbulb text-amber-400"></i> Pembahasan / Penjelasan:</strong>
                                            <?= htmlspecialchars($q['explanation']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div class="rounded-3xl border border-white/10 bg-slate-900/40 p-12 text-center">
                <i class="fa-regular fa-file-lines text-slate-500 text-4xl block mb-3"></i>
                <h3 class="text-lg font-bold text-white">Belum Ada Riwayat Pengerjaan</h3>
                <p class="text-sm text-slate-400 mt-1 max-w-md mx-auto">
                    Ujian atau latihan ini belum dikerjakan.
                </p>
                <div class="mt-4">
                    <a href="exam_take.php?id=<?= $exam['id'] ?>" class="rounded-xl bg-blue-600 px-5 py-2.5 text-xs font-bold text-white hover:bg-blue-500 transition inline-flex items-center gap-1.5">
                        <i class="fa-solid fa-pen-to-square"></i> Kerjakan Sekarang
                    </a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>

    <!-- TAMPILAN GURU / ADMIN / STAF: STATISTIK & REKAP KELAS -->
    <?php if ($can_view_all): ?>
        
        <!-- Summary Stats Grid -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5">
                <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Total Peserta</span>
                <span class="text-2xl sm:text-3xl font-extrabold text-white block mt-1">
                    <?= count($all_submissions) ?> Siswa
                </span>
            </div>

            <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5">
                <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Rata-Rata Nilai</span>
                <span class="text-2xl sm:text-3xl font-extrabold text-blue-400 block mt-1">
                    <?= number_format($avg_score, 1) ?>
                </span>
            </div>

            <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5">
                <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Nilai Tertinggi</span>
                <span class="text-2xl sm:text-3xl font-extrabold text-emerald-400 block mt-1">
                    <?= number_format($highest_score, 0) ?>
                </span>
            </div>

            <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-5">
                <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Tingkat Kelulusan</span>
                <span class="text-2xl sm:text-3xl font-extrabold text-purple-400 block mt-1">
                    <?= count($all_submissions) > 0 ? number_format(($pass_count / count($all_submissions)) * 100, 0) : 0 ?>%
                </span>
                <span class="text-[11px] text-slate-400 block mt-0.5"><?= $pass_count ?> Siswa Lulus KKM (<?= $exam['passing_grade'] ?>)</span>
            </div>
        </div>

        <!-- Tabel Rekapitulasi Nilai Siswa -->
        <div class="rounded-3xl border border-white/10 bg-slate-900/80 overflow-hidden shadow-xl">
            <div class="p-5 border-b border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <h3 class="text-base font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-chart-simple text-blue-400"></i> Daftar Nilai Peserta Didik (<?= count($all_submissions) ?>)
                </h3>
                <?php if (!empty($all_submissions)): ?>
                    <a href="exam_export.php?id=<?= $exam['id'] ?>" 
                       class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-3.5 py-1.5 text-xs font-bold text-white shadow-md shadow-emerald-500/20 transition">
                        <i class="fa-solid fa-file-arrow-down"></i> Unduh Rekap Nilai (.CSV)
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($all_submissions)): ?>
                <div class="p-12 text-center text-sm text-slate-400">
                    Belum ada siswa yang menyelesaikan modul ujian/latihan ini.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-white/5 text-xs uppercase tracking-wider text-slate-400 border-b border-white/10">
                            <tr>
                                <th class="py-3.5 px-4 font-semibold">No</th>
                                <th class="py-3.5 px-4 font-semibold">Nama Siswa</th>
                                <th class="py-3.5 px-4 font-semibold">Email</th>
                                <th class="py-3.5 px-4 font-semibold text-center">Jawaban Benar</th>
                                <th class="py-3.5 px-4 font-semibold text-center">Skor Nilai</th>
                                <th class="py-3.5 px-4 font-semibold text-center">Status KKM</th>
                                <?php if ($has_essay_questions): ?>
                                    <th class="py-3.5 px-4 font-semibold text-center">Koreksi Esai</th>
                                <?php endif; ?>
                                <th class="py-3.5 px-4 font-semibold text-center">Aksi Remedial</th>
                                <th class="py-3.5 px-4 font-semibold">Waktu Pengumpulan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5">
                            <?php foreach ($all_submissions as $idx => $sub): ?>
                                <?php 
                                    $score_num = (float) $sub['score'];
                                    $pass = ($score_num >= $exam['passing_grade']);
                                    $is_remed = !empty($sub['is_remedial']) && (int)$sub['is_remedial'] === 1;
                                    $granted = !empty($sub['remedial_granted']) && (int)$sub['remedial_granted'] === 1;
                                    $essay_done = !empty($sub['essay_graded']) && (int)$sub['essay_graded'] === 1;
                                ?>
                                <tr class="hover:bg-white/5 transition">
                                    <td class="py-3.5 px-4 font-bold text-slate-400"><?= $idx + 1 ?></td>
                                    <td class="py-3.5 px-4 font-bold text-white">
                                        <?= htmlspecialchars($sub['student_name']) ?>
                                        <?php if ($is_remed): ?>
                                            <span class="inline-flex items-center rounded-md bg-purple-500/20 text-purple-300 border border-purple-500/30 px-1.5 py-0.2 text-[10px] font-bold ml-1">
                                                Remedial
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-4 text-xs text-slate-400"><?= htmlspecialchars($sub['student_email']) ?></td>
                                    <td class="py-3.5 px-4 text-center font-medium"><?= $sub['total_correct'] ?> / <?= $sub['total_questions'] ?></td>
                                    <td class="py-3.5 px-4 text-center">
                                        <div class="flex flex-col items-center">
                                            <span class="text-base font-black <?= $pass ? 'text-emerald-400' : 'text-rose-400' ?>">
                                                <?= number_format($score_num, 0) ?>
                                            </span>
                                            <?php if ($is_remed && $sub['previous_score'] !== null): ?>
                                                <span class="text-[10px] text-slate-500 line-through">
                                                    Semula: <?= number_format($sub['previous_score'], 0) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4 text-center">
                                        <span class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs font-bold border <?= $pass ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
                                            <?= $pass ? '<i class="fa-solid fa-circle-check text-emerald-400"></i> Lulus' : '<i class="fa-solid fa-triangle-exclamation text-rose-400"></i> Remedial' ?>
                                        </span>
                                    </td>
                                    <?php if ($has_essay_questions): ?>
                                        <td class="py-3.5 px-4 text-center">
                                            <button type="button" 
                                                    onclick="openEssayGradingModal(<?= htmlspecialchars(json_encode([
                                                        'id' => (int)$sub['id'],
                                                        'student_name' => $sub['student_name'],
                                                        'answers' => json_decode($sub['answers'] ?? '{}', true),
                                                        'essay_scores' => json_decode($sub['essay_scores'] ?? '{}', true),
                                                        'score' => (float)$sub['score']
                                                    ])) ?>)"
                                                    class="inline-flex items-center gap-1.5 rounded-xl <?= $essay_done ? 'border border-purple-500/30 bg-purple-500/10 text-purple-300 hover:bg-purple-500/20' : 'bg-purple-600 hover:bg-purple-500 text-white shadow-md shadow-purple-500/20 font-bold' ?> px-3 py-1.5 text-xs transition cursor-pointer">
                                                <i class="fa-solid <?= $essay_done ? 'fa-check' : 'fa-pen-to-square' ?>"></i>
                                                <span><?= $essay_done ? 'Edit Nilai' : 'Koreksi Esai' ?></span>
                                            </button>
                                        </td>
                                    <?php endif; ?>
                                    <td class="py-3.5 px-4 text-center">
                                        <?php if ($granted): ?>
                                            <div class="flex items-center justify-center gap-1.5">
                                                <span class="inline-flex items-center gap-1 rounded-lg border border-amber-500/40 bg-amber-500/20 px-2 py-1 text-[11px] font-bold text-amber-300">
                                                    <i class="fa-solid fa-arrows-rotate fa-spin mr-1"></i> Menunggu Ujian
                                                </span>
                                                <form method="POST" action="exam_results.php?id=<?= $exam_id ?>" class="inline" onsubmit="return confirm('Batalkan izin remedial untuk siswa ini?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="revoke_remedial">
                                                    <input type="hidden" name="sub_id" value="<?= $sub['id'] ?>">
                                                    <button type="submit" 
                                                        title="Batalkan Izin" 
                                                        class="rounded-lg bg-white/5 hover:bg-rose-500/20 hover:text-rose-300 px-2 py-1 text-xs text-slate-400 transition cursor-pointer">
                                                        <i class="fa-solid fa-xmark"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <?php if (!$pass): ?>
                                                <form method="POST" action="exam_results.php?id=<?= $exam_id ?>" class="inline" onsubmit="return confirm('Berikan izin remedial untuk siswa <?= addslashes($sub['student_name']) ?>?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="grant_remedial">
                                                    <input type="hidden" name="sub_id" value="<?= $sub['id'] ?>">
                                                    <button type="submit" 
                                                        class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600/80 hover:bg-blue-600 px-3 py-1.5 text-xs font-bold text-white transition shadow-sm cursor-pointer">
                                                        <i class="fa-solid fa-arrows-rotate"></i> Izinkan Remedial
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-xs text-slate-500 font-medium">-</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-4 text-xs text-slate-400">
                                        <?= date('d M Y, H:i', strtotime($sub['submitted_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($has_essay_questions): ?>
            <?php 
            $essay_questions_json = json_encode(array_map(function($q) {
                return [
                    'id' => (int)$q['id'],
                    'question_text' => $q['question_text'],
                    'max_score' => (float)($q['max_score'] ?: 10),
                    'explanation' => $q['explanation'] ?? '',
                    'image_url' => $q['image_url'] ?? '',
                ];
            }, $essay_questions_list));
            ?>

            <!-- MODAL KOREKSI MANUAL ESAI SISWA -->
            <div id="essayGradingModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm p-4 overflow-y-auto animate-in fade-in duration-150">
                <div class="max-w-2xl w-full rounded-3xl border border-purple-500/30 bg-slate-900 p-6 sm:p-7 shadow-2xl space-y-5 my-8">
                    <div class="flex items-start justify-between gap-4 border-b border-white/10 pb-4">
                        <div>
                            <span class="inline-flex items-center gap-1.5 rounded-md border border-purple-500/30 bg-purple-500/10 px-2 py-0.5 text-[11px] font-bold text-purple-300">
                                <i class="fa-solid fa-pen-to-square"></i> Form Koreksi Manual Guru
                            </span>
                            <h3 class="text-lg font-black text-white mt-1" id="modalStudentName">Koreksi Jawaban Siswa</h3>
                            <p class="text-xs text-slate-400">Masukkan perolehan poin esai sesuai rubrik penilaian. Skor akhir akan otomatis dikalkulasi.</p>
                        </div>
                        <button type="button" onclick="closeEssayGradingModal()" class="rounded-xl bg-white/5 hover:bg-white/10 p-2 text-slate-400 hover:text-white transition cursor-pointer">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>

                    <form method="POST" action="exam_results.php?id=<?= $exam_id ?>" class="space-y-5">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_essay_grades">
                        <input type="hidden" name="submission_id" id="modalSubmissionId" value="">

                        <div id="modalEssayQuestionsContainer" class="space-y-4 max-h-[60vh] overflow-y-auto pr-1">
                            <!-- Diisi secara dinamis oleh JavaScript -->
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                            <button type="button" onclick="closeEssayGradingModal()" class="rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-400 hover:bg-white/5 transition cursor-pointer">
                                Batal
                            </button>
                            <button type="submit" class="rounded-xl bg-purple-600 hover:bg-purple-500 px-5 py-2.5 text-xs font-extrabold text-white shadow-lg shadow-purple-500/25 transition cursor-pointer inline-flex items-center gap-2">
                                <i class="fa-solid fa-floppy-disk"></i> Simpan Nilai & Perbarui Skor Akhir
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                const ESSAY_QUESTIONS = <?= $essay_questions_json ?>;

                function openEssayGradingModal(sub) {
                    document.getElementById('modalSubmissionId').value = sub.id;
                    document.getElementById('modalStudentName').innerText = 'Koreksi Lembar Esai: ' + sub.student_name;
                    
                    const container = document.getElementById('modalEssayQuestionsContainer');
                    container.innerHTML = '';

                    const answers = sub.answers || {};
                    const scores = sub.essay_scores || {};

                    ESSAY_QUESTIONS.forEach((q, idx) => {
                        const studentAns = answers[q.id] || '';
                        const currScore = (scores[q.id] !== undefined && scores[q.id] !== null) ? scores[q.id] : '';

                        const card = document.createElement('div');
                        card.className = "rounded-2xl border border-white/10 bg-slate-950/60 p-4 space-y-3";
                        
                        let rubrikHtml = '';
                        if (q.explanation) {
                            rubrikHtml = `<div class="rounded-xl border border-blue-500/20 bg-blue-500/10 p-2.5 text-[11px] text-blue-300"><strong class="text-white block mb-0.5">Pedoman Rubrik Guru:</strong>${escapeHtml(q.explanation).replace(/\n/g, '<br>')}</div>`;
                        }

                        let studentAnsHtml = studentAns ? escapeHtml(studentAns).replace(/\n/g, '<br>') : '<em class="text-slate-500">Siswa tidak mengisi jawaban esai ini.</em>';

                        card.innerHTML = `
                            <div class="flex items-center justify-between gap-2 border-b border-white/5 pb-2">
                                <span class="text-xs font-bold text-white flex items-center gap-1.5">
                                    <span class="flex h-5 w-5 items-center justify-center rounded-md bg-purple-600/30 text-purple-300 font-mono text-[11px]">${idx + 1}</span>
                                    Soal Esai No. ${idx + 1}
                                </span>
                                <span class="text-[11px] font-bold text-purple-300">Bobot Maks: ${q.max_score} Poin</span>
                            </div>
                            <div class="text-xs text-slate-200 leading-relaxed font-medium">${escapeHtml(q.question_text).replace(/\n/g, '<br>')}</div>
                            ${rubrikHtml}
                            <div class="space-y-1">
                                <label class="block text-[11px] font-bold text-purple-300">Lembar Jawaban Siswa:</label>
                                <div class="rounded-xl border border-purple-500/20 bg-purple-950/30 p-3 text-xs text-white whitespace-pre-wrap font-sans leading-relaxed">
                                    ${studentAnsHtml}
                                </div>
                            </div>
                            <div class="pt-2 border-t border-white/5 flex items-center justify-between gap-4">
                                <label class="text-xs font-bold text-slate-300">Beri Nilai Perolehan (0 s/d ${q.max_score}):</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" 
                                           step="0.5" 
                                           min="0" 
                                           max="${q.max_score}" 
                                           name="essay_scores[${q.id}]" 
                                           value="${currScore}" 
                                           required 
                                           placeholder="0"
                                           class="w-24 rounded-xl border border-purple-500/40 bg-slate-900 px-3 py-2 text-center text-xs font-bold text-white focus:border-purple-400 focus:outline-none focus:ring-1 focus:ring-purple-400 font-mono">
                                    <span class="text-xs text-slate-400 font-semibold">/ ${q.max_score} Poin</span>
                                </div>
                            </div>
                        `;
                        container.appendChild(card);
                    });

                    document.getElementById('essayGradingModal').classList.remove('hidden');
                }

                function closeEssayGradingModal() {
                    document.getElementById('essayGradingModal').classList.add('hidden');
                }

                function escapeHtml(str) {
                    if (!str) return '';
                    const div = document.createElement('div');
                    div.innerText = str;
                    return div.innerHTML;
                }
            </script>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
