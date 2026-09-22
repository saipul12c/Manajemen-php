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

// Ambil info ujian
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

$now_ts = time();
$start_ts = !empty($exam['start_time']) ? strtotime($exam['start_time']) : null;
$due_ts = !empty($exam['due_date']) ? strtotime($exam['due_date']) : null;

// Cek Jadwal Mulai Ujian
if ($start_ts !== null && $now_ts < $start_ts) {
    $page_title = "Ujian Belum Dimulai - " . $exam['title'];
    require_once __DIR__ . "/../includes/header.php";
    ?>
    <div class="max-w-xl mx-auto my-12 rounded-3xl border border-amber-500/30 bg-slate-900/90 p-8 text-center shadow-2xl space-y-5">
        <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-amber-500/10 border border-amber-500/20 text-4xl shadow-inner">
            ⏳
        </div>
        <div class="space-y-2">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-300">
                Jadwal Ujian Belum Dimulai
            </span>
            <h1 class="text-2xl font-black text-white"><?= htmlspecialchars($exam['title']) ?></h1>
            <p class="text-sm text-slate-400">
                Ujian ini baru dapat diakses pada jadwal yang telah ditetapkan oleh Guru Pengawas:
            </p>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm font-semibold text-amber-300 inline-block">
                📅 <?= date('d M Y, H:i', $start_ts) ?> WIB
            </div>
        </div>
        <div class="pt-4">
            <a href="exams.php" class="rounded-xl bg-slate-800 hover:bg-slate-700 px-6 py-2.5 text-xs font-bold text-white transition inline-block">
                ← Kembali ke Daftar Ujian
            </a>
        </div>
    </div>
    <?php
    require_once __DIR__ . "/../includes/footer.php";
    exit;
}

// Cek Batas Akhir Ujian (Due Date)
if ($due_ts !== null && $now_ts > $due_ts) {
    $page_title = "Waktu Ujian Berakhir - " . $exam['title'];
    require_once __DIR__ . "/../includes/header.php";
    ?>
    <div class="max-w-xl mx-auto my-12 rounded-3xl border border-rose-500/30 bg-slate-900/90 p-8 text-center shadow-2xl space-y-5">
        <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-rose-500/10 border border-rose-500/20 text-4xl shadow-inner">
            🛑
        </div>
        <div class="space-y-2">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-rose-500/30 bg-rose-500/10 px-3 py-1 text-xs font-bold text-rose-300">
                Waktu Pelaksanaan Telah Ditutup
            </span>
            <h1 class="text-2xl font-black text-white"><?= htmlspecialchars($exam['title']) ?></h1>
            <p class="text-sm text-slate-400">
                Batas akhir pengumpulan untuk modul ini telah lewat pada:
            </p>
            <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-sm font-semibold text-rose-300 inline-block">
                📅 <?= date('d M Y, H:i', $due_ts) ?> WIB
            </div>
        </div>
        <div class="pt-4">
            <a href="exams.php" class="rounded-xl bg-slate-800 hover:bg-slate-700 px-6 py-2.5 text-xs font-bold text-white transition inline-block">
                ← Kembali ke Daftar Ujian
            </a>
        </div>
    </div>
    <?php
    require_once __DIR__ . "/../includes/footer.php";
    exit;
}

// Cek apakah siswa sudah pernah mengerjakan & cek izin remedial
$stmt_sub = $pdo->prepare("SELECT * FROM exam_submissions WHERE exam_id = ? AND student_id = ?");
$stmt_sub->execute([$exam_id, $user_id]);
$existing_submission = $stmt_sub->fetch();

$is_remedial_attempt = false;
if ($existing_submission) {
    if (!empty($existing_submission['remedial_granted']) && (int)$existing_submission['remedial_granted'] === 1) {
        $is_remedial_attempt = true;
    } else {
        header("Location: exam_results.php?id=$exam_id&already_taken=1");
        exit;
    }
}

// Ambil butir soal
$stmt_q = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY id ASC");
$stmt_q->execute([$exam_id]);
$questions = $stmt_q->fetchAll();

if (empty($questions)) {
    header("Location: exams.php?error=no_questions");
    exit;
}

// -------------------------------------------------------------
// PENGACAKAN SOAL (SHUFFLE) JIKA DIAKTIFKAN GURU
// -------------------------------------------------------------
if (!empty($exam['randomize_questions'])) {
    // Seed deterministik per siswa & ujian agar urutan tidak berubah saat siswa me-refresh halaman
    mt_srand($exam_id * 10007 + $user_id);
    for ($i = count($questions) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        $tmp = $questions[$i];
        $questions[$i] = $questions[$j];
        $questions[$j] = $tmp;
    }
    mt_srand();
}

$total_q = count($questions);

// -------------------------------------------------------------
// VERIFIKASI SISTEM TOKEN UJIAN (JIKA DIHARUSKAN)
// -------------------------------------------------------------
$exam_token = trim($exam['token'] ?? '');
$is_token_required = !empty($exam_token);
$is_unlocked = false;

if ($is_token_required) {
    if (isset($_SESSION['exam_unlocked'][$exam_id]) && $_SESSION['exam_unlocked'][$exam_id] === true) {
        $is_unlocked = true;
    }
} else {
    $is_unlocked = true;
}

$token_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_token') {
    $input_token = strtoupper(trim($_POST['token'] ?? ''));
    if ($input_token === strtoupper($exam_token)) {
        if (!isset($_SESSION['exam_unlocked'])) {
            $_SESSION['exam_unlocked'] = [];
        }
        $_SESSION['exam_unlocked'][$exam_id] = true;
        header("Location: exam_take.php?id=$exam_id" . ($is_remedial_attempt ? '&remedial=1' : ''));
        exit;
    } else {
        $token_error = "Token ujian salah atau tidak sesuai. Silakan hubungi Guru Pengawas untuk mendapatkan token yang benar.";
    }
}

// Tampilkan Gate Masuk Token jika belum unlocked
if ($is_token_required && !$is_unlocked) {
    $page_title = "Verifikasi Token - " . $exam['title'];
    require_once __DIR__ . "/../includes/header.php";
    $cat_info = EXAM_CATEGORIES[$exam['category']] ?? ['label' => $exam['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '📝'];
    ?>
    <div class="max-w-lg mx-auto my-10 space-y-6">
        <div class="rounded-3xl border border-white/15 bg-slate-900/90 p-8 shadow-2xl text-center space-y-6">
            <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-blue-600/10 border border-blue-500/20 text-4xl shadow-inner">
                🔑
            </div>

            <div>
                <span class="inline-flex items-center gap-1 rounded-md border px-2.5 py-0.5 text-xs font-bold <?= $cat_info['badge'] ?> mb-2">
                    <span><?= $cat_info['icon'] ?></span>
                    <span><?= htmlspecialchars($cat_info['label']) ?></span>
                </span>
                <h1 class="text-xl sm:text-2xl font-black text-white"><?= htmlspecialchars($exam['title']) ?></h1>
                <p class="text-xs text-slate-400 mt-1">
                    Mapel: <strong class="text-white"><?= htmlspecialchars($exam['subject']) ?></strong> | Guru: <strong class="text-white"><?= htmlspecialchars($exam['teacher_name']) ?></strong>
                </p>
                <?php if ($is_remedial_attempt): ?>
                    <span class="inline-flex items-center gap-1 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-[11px] font-extrabold text-amber-300 mt-2">
                        ⚠️ Sesi Remedial Diizinkan
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!empty($token_error)): ?>
                <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs text-rose-300 text-left flex items-start gap-2.5">
                    <span>⚠️</span>
                    <span><?= htmlspecialchars($token_error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="exam_take.php?id=<?= $exam_id ?>" class="space-y-4 text-left">
                <input type="hidden" name="action" value="verify_token">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-2">
                        Masukkan Token Masuk Ujian <span class="text-rose-400">*</span>
                    </label>
                    <input type="text" 
                           name="token" 
                           required 
                           maxlength="20"
                           autofocus
                           autocomplete="off"
                           placeholder="CONTOH: UTS89A" 
                           class="w-full text-center tracking-widest text-xl font-mono font-black uppercase rounded-2xl border border-white/20 bg-slate-950 px-4 py-3.5 text-white focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20">
                    <span class="block text-[11px] text-slate-400 mt-1.5 text-center">
                        Dapatkan token resmi dari pengawas ujian di ruangan kelas Anda.
                    </span>
                </div>

                <div class="pt-2 flex flex-col gap-2.5">
                    <button type="submit" 
                            class="w-full rounded-2xl bg-blue-600 hover:bg-blue-500 py-3.5 text-sm font-extrabold text-white shadow-lg shadow-blue-500/25 transition cursor-pointer">
                        Buka & Mulai Ujian 🚀
                    </button>
                    <a href="exams.php" class="w-full text-center py-2.5 text-xs font-semibold text-slate-400 hover:text-white transition">
                        ← Kembali ke Daftar Ujian
                    </a>
                </div>
            </form>
        </div>
    </div>
    <?php
    require_once __DIR__ . "/../includes/footer.php";
    exit;
}

// -------------------------------------------------------------
// PROSES PENGUMPULAN JAWABAN (SUBMIT EXAM & REMEDIAL)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_answers') {
    $submitted_answers = $_POST['answers'] ?? [];
    $total_correct = 0;
    $answer_records = [];

    $total_possible_score = 0;
    $earned_pg_score = 0;
    $has_essay = false;

    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $q_type = $q['question_type'] ?? 'multiple_choice';
        $max_pts = !empty($q['max_score']) ? (float)$q['max_score'] : 10;
        $total_possible_score += $max_pts;

        if ($q_type === 'essay') {
            $has_essay = true;
            $user_ans = isset($submitted_answers[$qid]) ? trim($submitted_answers[$qid]) : '';
            $answer_records[$qid] = $user_ans;
        } else {
            $user_ans = isset($submitted_answers[$qid]) ? strtoupper(trim($submitted_answers[$qid])) : '';
            $answer_records[$qid] = $user_ans;

            if ($user_ans !== '' && $user_ans === strtoupper($q['correct_answer'])) {
                $total_correct++;
                $earned_pg_score += $max_pts;
            }
        }
    }

    if ($total_possible_score > 0) {
        $score = round(($earned_pg_score / $total_possible_score) * 100, 2);
    } else {
        $score = 0;
    }

    $essay_graded = $has_essay ? 0 : 1;
    $essay_scores_json = json_encode(new stdClass());
    $answers_json = json_encode($answer_records);

    if ($is_remedial_attempt && $existing_submission) {
        // Mode Remedial: Simpan nilai lama ke previous_score, update nilai baru, reset remedial_granted
        $orig_score = ($existing_submission['previous_score'] !== null) ? $existing_submission['previous_score'] : $existing_submission['score'];
        
        $stmt_upd = $pdo->prepare("
            UPDATE exam_submissions 
            SET score = ?, 
                total_correct = ?, 
                total_questions = ?, 
                answers = ?, 
                essay_scores = ?,
                essay_graded = ?,
                previous_score = ?, 
                is_remedial = 1, 
                remedial_granted = 0, 
                submitted_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt_upd->execute([$score, $total_correct, $total_q, $answers_json, $essay_scores_json, $essay_graded, $orig_score, $existing_submission['id']]);

        header("Location: exam_results.php?id=$exam_id&remedial_submitted=1");
        exit;
    } else {
        // Mode Ujian Standar
        $stmt_ins = $pdo->prepare("
            INSERT INTO exam_submissions (exam_id, student_id, score, total_correct, total_questions, answers, essay_scores, essay_graded, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'selesai')
            ON DUPLICATE KEY UPDATE 
                score = VALUES(score),
                total_correct = VALUES(total_correct),
                total_questions = VALUES(total_questions),
                answers = VALUES(answers),
                essay_scores = VALUES(essay_scores),
                essay_graded = VALUES(essay_graded),
                submitted_at = CURRENT_TIMESTAMP
        ");
        $stmt_ins->execute([$exam_id, $user_id, $score, $total_correct, $total_q, $answers_json, $essay_scores_json, $essay_graded]);

        header("Location: exam_results.php?id=$exam_id&submitted=1");
        exit;
    }
}

$page_title = "Pengerjaan - " . $exam['title'];
require_once __DIR__ . "/../includes/header.php";

$cat_info = EXAM_CATEGORIES[$exam['category']] ?? ['label' => $exam['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '📝'];
$duration_minutes = (int) $exam['duration_minutes'];
$is_timed = ($duration_minutes > 0);
?>

<!-- MODAL PERINGATAN KECURANGAN (ANTI-CHEAT DETEKTOR TAB SWITCH) -->
<div id="cheatWarningModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-md p-4 animate-in fade-in duration-200">
    <div class="max-w-md w-full rounded-3xl border border-rose-500/50 bg-slate-900 p-6 sm:p-7 shadow-2xl text-center space-y-4">
        <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-rose-500/20 border border-rose-500/40 text-3xl animate-bounce">
            ⚠️
        </div>
        <div class="space-y-1.5">
            <span class="inline-flex items-center gap-1 rounded-full border border-rose-500/40 bg-rose-500/20 px-3 py-0.5 text-xs font-extrabold text-rose-300 uppercase tracking-wider">
                Peringatan Integritas CBT
            </span>
            <h3 class="text-xl font-black text-white">Terdeteksi Berpindah Tab / Jendela!</h3>
            <p class="text-xs text-slate-300 leading-relaxed pt-1">
                Sistem mendeteksi Anda meninggalkan jendela ujian ini. Seluruh perpindahan tab terekam oleh sistem pengawasan pengawas.
            </p>
        </div>
        <div class="rounded-2xl border border-rose-500/30 bg-rose-950/40 p-3.5 text-xs text-rose-200 font-medium">
            Jumlah Pelanggaran: <strong id="violationCountText" class="text-base text-rose-400 font-extrabold font-mono">1</strong> Kali
            <span class="block text-[11px] text-slate-400 mt-0.5">Maksimal toleransi: 3 kali sebelum ujian berisiko dievaluasi pengawas.</span>
        </div>
        <button type="button" onclick="dismissCheatWarning()" 
                class="w-full rounded-xl bg-rose-600 hover:bg-rose-500 py-3 text-xs font-extrabold text-white shadow-lg shadow-rose-500/30 transition cursor-pointer">
            Saya Mengerti & Kembali ke Lembar Ujian
        </button>
    </div>
</div>

<div class="max-w-7xl mx-auto space-y-6">

    <!-- Sticky Exam Information & Timer Bar -->
    <div class="sticky top-20 z-30 rounded-3xl border border-white/15 bg-slate-900/95 backdrop-blur p-4 sm:p-5 shadow-2xl">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <span class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-[11px] font-bold <?= $cat_info['badge'] ?>">
                        <span><?= $cat_info['icon'] ?></span>
                        <span><?= htmlspecialchars($cat_info['label']) ?></span>
                    </span>
                    <span class="text-xs text-slate-400 font-semibold"><?= htmlspecialchars($exam['subject']) ?></span>
                    <?php if (!empty($exam['randomize_questions'])): ?>
                        <span class="text-[10px] text-cyan-300 font-bold bg-cyan-500/10 border border-cyan-500/30 px-1.5 py-0.5 rounded">
                            🔀 Soal Diacak
                        </span>
                    <?php endif; ?>
                    <!-- Autosave Status Badge -->
                    <span id="autosaveBadge" class="text-[10px] text-emerald-400 font-semibold bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 rounded-full flex items-center gap-1 transition-opacity duration-300">
                        <span>💾</span> <span>Autosave Aktif</span>
                    </span>
                </div>
                <h1 class="text-base sm:text-lg font-bold text-white line-clamp-1">
                    <?= htmlspecialchars($exam['title']) ?>
                </h1>
            </div>

            <!-- Timer / Flexible Mode Badge -->
            <div class="flex items-center gap-3">
                <?php if ($is_timed): ?>
                    <div class="flex items-center gap-2 rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-2 text-amber-300 shadow-inner">
                        <span class="text-lg animate-pulse">⏱️</span>
                        <div>
                            <span class="block text-[10px] uppercase tracking-wider text-amber-400/80 font-bold">Sisa Waktu:</span>
                            <span id="countdownDisplay" class="text-lg font-extrabold tracking-wider font-mono">
                                --:--
                            </span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="flex items-center gap-2 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-2 text-emerald-300">
                        <span class="text-lg">⚡</span>
                        <div>
                            <span class="block text-[10px] uppercase tracking-wider font-bold">Mode Fleksibel</span>
                            <span class="text-xs font-semibold text-emerald-200">Tanpa Batas Waktu</span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Tombol Pintas Selesai -->
                <button type="button" onclick="triggerSubmit()" 
                        class="rounded-2xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2.5 text-xs font-extrabold text-white shadow-lg shadow-emerald-500/25 transition cursor-pointer shrink-0">
                    ✅ Kumpulkan
                </button>
            </div>
        </div>
    </div>

    <!-- Alert Sesi Remedial (Jika Sedang Remedial) -->
    <?php if ($is_remedial_attempt): ?>
        <div class="rounded-3xl border border-amber-500/40 bg-gradient-to-r from-amber-950/50 to-slate-900 p-5 text-amber-200 shadow-xl flex items-start gap-3.5">
            <span class="text-2xl">🔄</span>
            <div class="text-xs sm:text-sm">
                <strong class="text-white block font-bold text-sm">Anda Sedang Mengerjakan Sesi Remedial</strong>
                <span>Nilai ujian sebelumnya: <strong class="text-rose-400"><?= number_format($existing_submission['score'], 0) ?></strong>. Target standar KKM: <strong class="text-emerald-400"><?= $exam['passing_grade'] ?></strong>. Nilai baru hasil remedial akan memperbarui rekap nilai Anda.</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- GRID 2 KOLOM: LEMBAR SOAL (KIRI) & PALET NOMOR CBT (KANAN) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        <!-- KOLOM KIRI: LEMBAR SOAL (8 Kolom) -->
        <div class="lg:col-span-8 space-y-6">

            <!-- Petunjuk Pengerjaan -->
            <div class="rounded-2xl border border-white/10 bg-slate-900/40 p-4 text-xs text-slate-300 flex items-start gap-3">
                <span class="text-xl">ℹ️</span>
                <div>
                    <strong class="text-white block mb-0.5">Petunjuk Pengerjaan:</strong>
                    <?= !empty($exam['description']) ? htmlspecialchars($exam['description']) : 'Pilihlah salah satu jawaban yang paling tepat. Gunakan tombol Ragu-Ragu jika belum yakin. Jawaban tersimpan otomatis secara real-time.' ?>
                    <span class="block mt-1 text-slate-400">Total Soal: <strong class="text-white"><?= $total_q ?> Butir</strong> | KKM: <strong class="text-white"><?= $exam['passing_grade'] ?></strong></span>
                </div>
            </div>

            <!-- Formulir Pengerjaan Soal -->
            <form id="examForm" method="POST" action="exam_take.php?id=<?= $exam['id'] ?>" class="space-y-6 select-none">
                <input type="hidden" name="action" value="submit_answers">

                <?php foreach ($questions as $idx => $q): ?>
                    <div id="soal-<?= $idx + 1 ?>" class="scroll-mt-40 rounded-3xl border border-white/10 bg-slate-900/80 p-6 sm:p-7 shadow-xl space-y-5 transition-all">
                        
                        <!-- Header Nomor & Tombol Ragu-Ragu -->
                        <div class="flex items-center justify-between gap-4 border-b border-white/5 pb-3">
                            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-600 text-white text-sm font-black shrink-0 shadow-lg shadow-blue-500/30">
                                    <?= $idx + 1 ?>
                                </span>
                                <span class="text-xs text-slate-400 font-semibold">Soal No. <?= $idx + 1 ?> dari <?= $total_q ?></span>
                                <?php if (($q['question_type'] ?? 'multiple_choice') === 'essay'): ?>
                                    <span class="rounded-full border border-purple-500/30 bg-purple-500/10 px-2.5 py-0.5 text-[11px] font-extrabold text-purple-300">
                                        📝 Soal Esai / Uraian (Maks. <?= (int)($q['max_score'] ?: 10) ?> Poin)
                                    </span>
                                <?php else: ?>
                                    <span class="rounded-full border border-blue-500/30 bg-blue-500/10 px-2.5 py-0.5 text-[11px] font-bold text-blue-300">
                                        🔘 Pilihan Ganda (Maks. <?= (int)($q['max_score'] ?: 10) ?> Poin)
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Checkbox Ragu-Ragu -->
                            <label class="inline-flex items-center gap-2 text-xs font-bold cursor-pointer rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-1.5 text-amber-300 hover:bg-amber-500/20 transition shrink-0">
                                <input type="checkbox" 
                                       id="doubt-<?= $idx + 1 ?>" 
                                       onchange="toggleDoubt(<?= $idx + 1 ?>, <?= $q['id'] ?>)" 
                                       class="h-4 w-4 rounded border-white/20 bg-slate-950 text-amber-500 focus:ring-amber-400">
                                <span>⚠️ Ragu-Ragu</span>
                            </label>
                        </div>

                        <!-- Teks Soal -->
                        <div class="text-sm sm:text-base font-medium text-white leading-relaxed">
                            <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                        </div>

                        <!-- Gambar Pendukung Soal (Jika Ada) -->
                        <?php if (!empty($q['image_url'])): ?>
                            <div class="pt-1">
                                <div class="inline-block rounded-2xl overflow-hidden border border-white/15 bg-black/40 p-2 shadow-lg max-w-full">
                                    <img src="<?= htmlspecialchars($q['image_url']) ?>" 
                                         alt="Gambar Pendukung Soal <?= $idx + 1 ?>" 
                                         class="max-h-72 w-auto rounded-xl object-contain hover:scale-[1.02] transition-transform duration-300">
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (($q['question_type'] ?? 'multiple_choice') === 'essay'): ?>
                            <!-- Kolom Jawaban Esai / Uraian Siswa -->
                            <div class="space-y-2 pt-1">
                                <div class="flex items-center justify-between text-xs text-slate-400 font-semibold">
                                    <label for="essay-<?= $q['id'] ?>" class="flex items-center gap-1.5 text-purple-400 font-bold">
                                        <span>✍️</span> Tulis Jawaban / Uraian Lengkap Anda:
                                    </label>
                                    <span class="text-[11px] text-slate-400">Tersimpan otomatis saat Anda mengetik</span>
                                </div>
                                <textarea name="answers[<?= $q['id'] ?>]" 
                                          id="essay-<?= $q['id'] ?>" 
                                          rows="6" 
                                          placeholder="Ketik uraian jawaban secara jelas dan terstruktur di sini..." 
                                          oninput="onEssayInput(<?= $idx + 1 ?>, <?= $q['id'] ?>, this.value)"
                                          class="w-full rounded-2xl border border-white/10 bg-slate-950 p-4 text-sm text-white placeholder-slate-500 focus:border-purple-500 focus:outline-none focus:ring-2 focus:ring-purple-500/20 leading-relaxed font-sans"></textarea>
                            </div>
                        <?php else: ?>
                            <!-- Opsi Jawaban Radio List Pilihan Ganda -->
                            <div class="space-y-2.5 pt-1">
                                <?php 
                                $options = [
                                    'A' => $q['option_a'],
                                    'B' => $q['option_b'],
                                    'C' => $q['option_c'],
                                    'D' => $q['option_d'],
                                ];
                                ?>
                                <?php foreach ($options as $key => $opt_text): ?>
                                    <label class="flex items-center gap-3.5 rounded-2xl border border-white/5 bg-white/5 p-3.5 sm:p-4 hover:border-blue-500/50 hover:bg-blue-500/5 transition cursor-pointer group">
                                        <input type="radio" 
                                               name="answers[<?= $q['id'] ?>]" 
                                               id="opt-<?= $q['id'] ?>-<?= $key ?>"
                                               value="<?= $key ?>" 
                                               class="h-4 w-4 text-blue-600 bg-slate-800 border-white/20 focus:ring-blue-500"
                                               onchange="onAnswerSelected(<?= $idx + 1 ?>, <?= $q['id'] ?>, '<?= $key ?>')">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10 text-xs font-black text-slate-300 group-hover:bg-blue-600 group-hover:text-white transition">
                                            <?= $key ?>
                                        </span>
                                        <span class="text-xs sm:text-sm text-slate-200 group-hover:text-white transition">
                                            <?= htmlspecialchars($opt_text) ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Navigasi Cepat Antar Soal (Prev / Next) -->
                        <div class="flex items-center justify-between border-t border-white/5 pt-4 text-xs font-semibold">
                            <?php if ($idx > 0): ?>
                                <button type="button" onclick="jumpToQuestion(<?= $idx ?>)" 
                                        class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-slate-300 transition">
                                    ← Soal Sebelumnya
                                </button>
                            <?php else: ?>
                                <div></div>
                            <?php endif; ?>

                            <?php if ($idx < $total_q - 1): ?>
                                <button type="button" onclick="jumpToQuestion(<?= $idx + 2 ?>)" 
                                        class="rounded-xl bg-blue-600/30 hover:bg-blue-600/50 border border-blue-500/30 px-3.5 py-2 text-blue-300 transition">
                                    Soal Berikutnya →
                                </button>
                            <?php else: ?>
                                <button type="button" onclick="triggerSubmit()" 
                                        class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-white font-bold transition">
                                    Periksa & Kumpulkan ✅
                                </button>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>

                <!-- Bottom Actions Bar -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/90 p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-2xl">
                    <div>
                        <span class="text-xs text-slate-400 block">Selesai menjawab semua butir soal?</span>
                        <span class="text-sm font-bold text-white">Pastikan tidak ada nomor yang bertanda ragu-ragu.</span>
                    </div>

                    <div class="flex items-center gap-3">
                        <a href="exams.php" onclick="return confirm('Apakah Anda yakin ingin keluar? Pastikan ujian telah selesai sebelum keluar.');" 
                           class="rounded-xl px-4 py-2.5 text-xs font-semibold text-slate-400 hover:bg-white/5 transition">
                            Batalkan
                        </a>
                        <button type="submit" onclick="return confirmSubmit();" 
                                class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-6 py-3 text-sm font-extrabold text-white shadow-lg shadow-emerald-500/25 transition cursor-pointer">
                            ✅ Kumpulkan Jawaban Ujian
                        </button>
                    </div>
                </div>

            </form>

        </div>

        <!-- KOLOM KANAN: PALET NOMOR CBT INTERAKTIF (4 Kolom) -->
        <div class="lg:col-span-4 sticky top-44 space-y-4">

            <div class="rounded-3xl border border-white/10 bg-slate-900/90 backdrop-blur p-5 shadow-2xl space-y-5">
                
                <!-- Palette Header & Ringkasan -->
                <div class="flex items-center justify-between border-b border-white/10 pb-3">
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <span>📋</span> Palet Nomor Soal
                    </h3>
                    <span class="text-xs font-bold text-blue-400">Total <?= $total_q ?></span>
                </div>

                <!-- Status Counters -->
                <div class="grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-2.5">
                        <span class="block text-[10px] uppercase tracking-wider text-emerald-400 font-bold">Terjawab</span>
                        <span id="countAnswered" class="text-lg font-black text-white">0</span>
                    </div>
                    <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-2.5">
                        <span class="block text-[10px] uppercase tracking-wider text-amber-400 font-bold">Ragu</span>
                        <span id="countDoubt" class="text-lg font-black text-white">0</span>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-white/5 p-2.5">
                        <span class="block text-[10px] uppercase tracking-wider text-slate-400 font-bold">Belum</span>
                        <span id="countUnanswered" class="text-lg font-black text-white"><?= $total_q ?></span>
                    </div>
                </div>

                <!-- Petunjuk Warna -->
                <div class="flex items-center justify-center gap-4 text-[10px] text-slate-400 pt-1">
                    <span class="flex items-center gap-1.5">
                        <span class="h-3 w-3 rounded-full bg-slate-700 inline-block"></span> Belum
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="h-3 w-3 rounded-full bg-emerald-600 inline-block"></span> Dijawab
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="h-3 w-3 rounded-full bg-amber-500 inline-block"></span> Ragu-Ragu
                    </span>
                </div>

                <!-- Grid Kotak Nomor Soal -->
                <div class="grid grid-cols-5 gap-2 max-h-[50vh] overflow-y-auto pr-1">
                    <?php for ($n = 1; $n <= $total_q; $n++): ?>
                        <button type="button" 
                                id="palette-btn-<?= $n ?>" 
                                onclick="jumpToQuestion(<?= $n ?>)" 
                                class="h-10 rounded-xl border border-white/10 bg-slate-800 text-xs font-bold text-slate-300 hover:border-blue-500 transition shadow-sm">
                            <?= $n ?>
                        </button>
                    <?php endfor; ?>
                </div>

                <!-- Tombol Submit di Palette -->
                <div class="pt-2 border-t border-white/10">
                    <button type="button" onclick="triggerSubmit()" 
                            class="w-full rounded-2xl bg-emerald-600 hover:bg-emerald-500 py-3 text-xs font-extrabold text-white shadow-lg shadow-emerald-500/20 transition cursor-pointer">
                        ✅ Kumpulkan Ujian
                    </button>
                </div>

            </div>

        </div>

    </div>

</div>

<!-- SCRIPT AUTOSAVE, NAVIGASI CBT & ANTI-CHEAT ENGINE -->
<script>
    const EXAM_ID = <?= $exam_id ?>;
    const USER_ID = <?= $user_id ?>;
    const TOTAL_QUESTIONS = <?= $total_q ?>;
    const DRAFT_KEY = `exam_draft_${EXAM_ID}_${USER_ID}`;
    const VIOLATION_KEY = `exam_violations_${EXAM_ID}_${USER_ID}`;

    const examForm = document.getElementById('examForm');
    const cheatModal = document.getElementById('cheatWarningModal');
    const violationText = document.getElementById('violationCountText');
    const autosaveBadge = document.getElementById('autosaveBadge');

    let answersState = {}; // { qid: 'A' }
    let doubtsState = {};   // { index: true }
    let lastViolationTime = 0;

    // 1. Inisialisasi & Pulihkan Autosave dari LocalStorage
    function initAutosave() {
        const saved = localStorage.getItem(DRAFT_KEY);
        if (saved) {
            try {
                const parsed = JSON.parse(saved);
                answersState = parsed.answers || {};
                doubtsState = parsed.doubts || {};

                // Restore jawaban radio & essay
                for (const [qid, val] of Object.entries(answersState)) {
                    const radio = document.getElementById(`opt-${qid}-${val}`);
                    if (radio) {
                        radio.checked = true;
                    }
                    const essayBox = document.getElementById(`essay-${qid}`);
                    if (essayBox) {
                        essayBox.value = val;
                    }
                }

                // Restore status ragu-ragu
                for (const [idx, isDoubt] of Object.entries(doubtsState)) {
                    const doubtBox = document.getElementById(`doubt-${idx}`);
                    if (doubtBox) {
                        doubtBox.checked = isDoubt;
                    }
                }

                // Flash badge pulih
                autosaveBadge.classList.remove('text-emerald-400');
                autosaveBadge.classList.add('text-blue-400');
                autosaveBadge.innerHTML = '<span>💾</span> <span>Draft Dipulihkan</span>';
                setTimeout(() => {
                    autosaveBadge.innerHTML = '<span>💾</span> <span>Autosave Aktif</span>';
                    autosaveBadge.classList.remove('text-blue-400');
                    autosaveBadge.classList.add('text-emerald-400');
                }, 3000);

            } catch (e) {
                console.error("Gagal membaca draft ujian:", e);
            }
        }
        updatePaletteAndCounters();
    }

    // 2. Simpan ke LocalStorage
    function saveDraft() {
        const payload = {
            answers: answersState,
            doubts: doubtsState,
            savedAt: new Date().toISOString()
        };
        localStorage.setItem(DRAFT_KEY, JSON.stringify(payload));

        // Animasi autosave badge
        autosaveBadge.classList.add('opacity-40');
        setTimeout(() => autosaveBadge.classList.remove('opacity-40'), 250);
    }

    // 3. Handler saat pilihan jawaban dipilih
    function onAnswerSelected(questionIndex, questionId, choice) {
        answersState[questionId] = choice;
        saveDraft();
        updatePaletteAndCounters();
    }

    // 3b. Handler saat jawaban esai diketik
    function onEssayInput(questionIndex, questionId, text) {
        if (text && text.trim() !== '') {
            answersState[questionId] = text;
        } else {
            delete answersState[questionId];
        }
        saveDraft();
        updatePaletteAndCounters();
    }

    // 4. Handler saat tombol ragu-ragu di-toggle
    function toggleDoubt(questionIndex, questionId) {
        const doubtBox = document.getElementById(`doubt-${questionIndex}`);
        doubtsState[questionIndex] = doubtBox ? doubtBox.checked : false;
        saveDraft();
        updatePaletteAndCounters();
    }

    // 5. Update Status Palet Nomor & Counter Ringkasan
    function updatePaletteAndCounters() {
        let answeredCount = 0;
        let doubtCount = 0;

        // Ambil semua butir dari DOM
        <?php foreach ($questions as $idx => $q): ?>
            (function() {
                const idx = <?= $idx + 1 ?>;
                const qid = <?= $q['id'] ?>;
                const btn = document.getElementById(`palette-btn-${idx}`);
                if (!btn) return;

                const ansVal = answersState[qid];
                const hasAnswer = Boolean(ansVal !== undefined && ansVal !== null && ansVal.toString().trim() !== '');
                const isDoubt = Boolean(doubtsState[idx]);

                if (hasAnswer) answeredCount++;
                if (isDoubt) doubtCount++;

                // Reset kelas warna
                btn.className = "h-10 rounded-xl text-xs font-bold transition shadow-sm cursor-pointer ";

                if (isDoubt) {
                    btn.className += "bg-amber-500 text-slate-950 font-black border-2 border-amber-300 shadow-amber-500/20";
                } else if (hasAnswer) {
                    btn.className += "bg-emerald-600 text-white font-black border border-emerald-400 shadow-emerald-500/20";
                } else {
                    btn.className += "bg-slate-800 text-slate-300 border border-white/10 hover:border-blue-500";
                }
            })();
        <?php endforeach; ?>

        const unansweredCount = TOTAL_QUESTIONS - answeredCount;

        document.getElementById('countAnswered').innerText = answeredCount;
        document.getElementById('countDoubt').innerText = doubtCount;
        document.getElementById('countUnanswered').innerText = unansweredCount;
    }

    // 6. Navigasi Melompat ke Soal Tertentu (Smooth Scroll)
    function jumpToQuestion(questionIndex) {
        const el = document.getElementById(`soal-${questionIndex}`);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.classList.add('ring-2', 'ring-blue-500');
            setTimeout(() => el.classList.remove('ring-2', 'ring-blue-500'), 1500);
        }
    }

    // 7. Konfirmasi & Kumpulkan Ujian
    function confirmSubmit() {
        let doubtNum = document.getElementById('countDoubt').innerText;
        let unansNum = document.getElementById('countUnanswered').innerText;

        let warning = "Apakah Anda yakin ingin menyelesaikan dan mengumpulkan ujian ini sekarang?";
        if (parseInt(unansNum) > 0 || parseInt(doubtNum) > 0) {
            warning = `Perhatian:\n• Masih ada ${unansNum} soal belum dijawab.\n• Masih ada ${doubtNum} soal ditandai ragu-ragu.\n\nApakah Anda yakin tetap ingin mengumpulkan jawaban?`;
        }

        if (confirm(warning)) {
            // Bersihkan draft penyimpanan lokal setelah submit berhasil
            localStorage.removeItem(DRAFT_KEY);
            sessionStorage.removeItem(VIOLATION_KEY);
            return true;
        }
        return false;
    }

    function triggerSubmit() {
        if (confirmSubmit()) {
            examForm.submit();
        }
    }

    // 8. ANTI-CHEAT DETECTION (Tab Switch & Window Blur Detector)
    function recordViolation() {
        const now = Date.now();
        // Debounce agar tidak double-trigger dalam rentang 1.5 detik
        if (now - lastViolationTime < 1500) return;
        lastViolationTime = now;

        let violations = parseInt(sessionStorage.getItem(VIOLATION_KEY) || "0") + 1;
        sessionStorage.setItem(VIOLATION_KEY, violations);

        violationText.innerText = violations;
        cheatModal.classList.remove('hidden');
    }

    function dismissCheatWarning() {
        cheatModal.classList.add('hidden');
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            recordViolation();
        }
    });

    window.addEventListener('blur', () => {
        recordViolation();
    });

    // 9. PROTEKSI COPY-PASTE & KLIK KANAN
    examForm.addEventListener('contextmenu', (e) => e.preventDefault());
    examForm.addEventListener('copy', (e) => e.preventDefault());
    examForm.addEventListener('cut', (e) => e.preventDefault());

    // Jalankan inisialisasi awal
    initAutosave();
</script>

<?php if ($is_timed): ?>
<script>
    // Live Countdown Timer in JavaScript
    const totalMinutes = <?= $duration_minutes ?>;
    let timeRemaining = totalMinutes * 60; // seconds
    const countdownEl = document.getElementById('countdownDisplay');

    function updateTimer() {
        if (timeRemaining <= 0) {
            countdownEl.innerText = "00:00 (WAKTU HABIS)";
            alert("Waktu ujian telah habis! Seluruh jawaban Anda otomatis dikumpulkan sekarang.");
            localStorage.removeItem(DRAFT_KEY);
            sessionStorage.removeItem(VIOLATION_KEY);
            examForm.submit();
            return;
        }

        const hours = Math.floor(timeRemaining / 3600);
        const minutes = Math.floor((timeRemaining % 3600) / 60);
        const seconds = timeRemaining % 60;

        let formatted = "";
        if (hours > 0) {
            formatted += String(hours).padStart(2, '0') + ":";
        }
        formatted += String(minutes).padStart(2, '0') + ":" + String(seconds).padStart(2, '0');

        countdownEl.innerText = formatted;

        // Warn when less than 5 minutes
        if (timeRemaining <= 300) {
            countdownEl.parentElement.parentElement.classList.add('animate-pulse', 'border-rose-500', 'text-rose-400');
        }

        timeRemaining--;
    }

    updateTimer();
    const timerInterval = setInterval(updateTimer, 1000);
</script>
<?php endif; ?>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
