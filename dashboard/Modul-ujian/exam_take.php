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

// -------------------------------------------------------------
// CEK PEMBATASAN TARGET KELAS (ROMBEL) UNTUK SISWA
// -------------------------------------------------------------
if ($user_role === 'siswa') {
    $stmt_u = $pdo->prepare("SELECT class_id FROM users WHERE id = ?");
    $stmt_u->execute([$user_id]);
    $stu_class = (int)$stmt_u->fetchColumn();

    if (!empty($exam['class_id']) && (int)$exam['class_id'] > 0 && $stu_class !== (int)$exam['class_id']) {
        $page_title = "Akses Ujian Ditolak - Pembatasan Kelas";
        require_once __DIR__ . "/../includes/header.php";
        ?>
        <div class="max-w-xl mx-auto my-12 rounded-3xl border border-amber-500/40 bg-slate-900/95 p-8 text-center shadow-2xl space-y-4">
            <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-500/10 border border-amber-500/30 text-2xl text-amber-400">
                <i class="fa-solid fa-lock"></i>
            </div>
            <h2 class="text-xl font-bold text-white">Ujian Ini Dikhususkan Untuk Rombel/Kelas Tertentu</h2>
            <p class="text-xs text-slate-400">
                Paket ujian ini ditugaskan oleh guru untuk target kelas tertentu. Akun Anda tidak terdaftar pada kelas ini.
            </p>
            <div class="pt-2">
                <a href="exams.php" class="inline-flex items-center gap-2 rounded-xl bg-slate-800 hover:bg-slate-700 px-5 py-2 text-xs font-bold text-white transition">
                    <i class="fa-solid fa-arrow-left"></i> Kembali ke Daftar Ujian
                </a>
            </div>
        </div>
        <?php
        require_once __DIR__ . "/../includes/footer.php";
        exit;
    }
}

// -------------------------------------------------------------
// CEK PEMBATASAN JARINGAN IP / SUBNET LAB KOMPUTER SEKOLAH
// -------------------------------------------------------------
$client_ip = getClientIp();
$allowed_ip_range = trim($exam['allowed_ip_range'] ?? '');
if (!empty($allowed_ip_range) && !isIpInSubnet($client_ip, $allowed_ip_range)) {
    $page_title = "Akses Ujian Ditolak - Jaringan Lab Komputer";
    require_once __DIR__ . "/../includes/header.php";
    ?>
    <div class="max-w-xl mx-auto my-12 rounded-3xl border border-rose-500/40 bg-slate-900/95 p-8 text-center shadow-2xl space-y-6">
        <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-rose-500/10 border border-rose-500/30 text-3xl shadow-inner text-rose-400">
            <i class="fa-solid fa-network-wired"></i>
        </div>
        <div class="space-y-3">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-rose-500/30 bg-rose-500/10 px-3.5 py-1 text-xs font-bold text-rose-300">
                <i class="fa-solid fa-ban"></i> Akses Dibatasi: Jaringan Lab Komputer
            </span>
            <h1 class="text-2xl font-black text-white"><?= htmlspecialchars($exam['title']) ?></h1>
            <p class="text-xs text-slate-300 leading-relaxed max-w-md mx-auto">
                Ujian CBT ini dikonfigurasi dalam <strong>Mode Ketat Lab</strong> dan hanya dapat dikerjakan melalui koneksi jaringan lokal laboratorium komputer sekolah.
            </p>
            <div class="rounded-2xl border border-white/10 bg-slate-950 p-4 text-xs font-mono text-left space-y-2">
                <div class="flex justify-between border-b border-white/5 pb-1.5">
                    <span class="text-slate-400">IP Perangkat Anda:</span>
                    <strong class="text-rose-400 font-bold"><?= htmlspecialchars($client_ip) ?></strong>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Subnet Jaringan Lab Resmi:</span>
                    <strong class="text-emerald-400 font-bold"><?= htmlspecialchars($allowed_ip_range) ?></strong>
                </div>
            </div>
            <p class="text-[11px] text-slate-400">
                Silakan hubungi Guru Pengawas atau teknisi laboratorium komputer sekolah jika Anda sudah berada di lab namun mengalami kendala ini.
            </p>
        </div>
        <div class="pt-2">
            <a href="exams.php" class="inline-flex items-center gap-2 rounded-xl bg-slate-800 hover:bg-slate-700 px-6 py-2.5 text-xs font-bold text-white transition">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Daftar Ujian
            </a>
        </div>
    </div>
    <?php
    require_once __DIR__ . "/../includes/footer.php";
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
        <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-amber-500/10 border border-amber-500/20 text-3xl shadow-inner text-amber-400">
            <i class="fa-regular fa-clock"></i>
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
                <i class="fa-regular fa-calendar mr-1.5"></i><?= date('d M Y, H:i', $start_ts) ?> WIB
            </div>
        </div>
        <div class="pt-4">
            <a href="exams.php" class="inline-flex items-center gap-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 px-6 py-2.5 text-xs font-bold text-white transition">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Daftar Ujian
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
        <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-rose-500/10 border border-rose-500/20 text-3xl shadow-inner text-rose-400">
            <i class="fa-solid fa-ban"></i>
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
                <i class="fa-regular fa-calendar mr-1.5"></i><?= date('d M Y, H:i', $due_ts) ?> WIB
            </div>
        </div>
        <div class="pt-4">
            <a href="exams.php" class="inline-flex items-center gap-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 px-6 py-2.5 text-xs font-bold text-white transition">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Daftar Ujian
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
    if (!validateCsrfToken()) {
        $token_error = "Sesi keamanan telah kedaluwarsa. Silakan muat ulang halaman.";
    } else {
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
}

// Tampilkan Gate Masuk Token jika belum unlocked
if ($is_token_required && !$is_unlocked) {
    $page_title = "Verifikasi Token - " . $exam['title'];
    require_once __DIR__ . "/../includes/header.php";
    $cat_info = EXAM_CATEGORIES[$exam['category']] ?? ['label' => $exam['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '<i class="fa-solid fa-file-pen"></i>'];
    ?>
    <div class="max-w-lg mx-auto my-10 space-y-6">
        <div class="rounded-3xl border border-white/15 bg-slate-900/90 p-8 shadow-2xl text-center space-y-6">
            <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-blue-600/10 border border-blue-500/20 text-3xl shadow-inner text-blue-400">
                <i class="fa-solid fa-key"></i>
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
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-[11px] font-extrabold text-amber-300 mt-2">
                        <i class="fa-solid fa-rotate"></i> Sesi Remedial Diizinkan
                    </span>
                <?php endif; ?>
            </div>

            <?php if (!empty($token_error)): ?>
                <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-3.5 text-xs text-rose-300 text-left flex items-start gap-2.5">
                    <i class="fa-solid fa-triangle-exclamation text-rose-400 mt-0.5"></i>
                    <span><?= htmlspecialchars($token_error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="exam_take.php?id=<?= $exam_id ?>" class="space-y-4 text-left">
                <?= csrfField() ?>
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
                            class="w-full inline-flex items-center justify-center gap-2 rounded-2xl bg-blue-600 hover:bg-blue-500 py-3.5 text-sm font-extrabold text-white shadow-lg shadow-blue-500/25 transition cursor-pointer">
                        <span>Buka & Mulai Ujian</span> <i class="fa-solid fa-arrow-right text-xs"></i>
                    </button>
                    <a href="exams.php" class="w-full inline-flex items-center justify-center gap-1.5 text-center py-2.5 text-xs font-semibold text-slate-400 hover:text-white transition">
                        <i class="fa-solid fa-arrow-left"></i> Kembali ke Daftar Ujian
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
// Standar 5 Tipe Soal: PG Tunggal (A-E), PG Kompleks (AKM), Benar/Salah, Isian Singkat, & Esai
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_answers') {
    if (!validateCsrfToken()) {
        header("Location: exam_take.php?id=$exam_id&error=csrf_invalid");
        exit;
    }
    $submitted_answers = $_POST['answers'] ?? [];
    $total_correct = 0;
    $answer_records = [];

    $total_possible_score = 0;
    $earned_score = 0;
    $has_essay = false;

    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $q_type = $q['question_type'] ?? 'multiple_choice';
        $max_pts = !empty($q['max_score']) ? (float)$q['max_score'] : 10;
        $total_possible_score += $max_pts;

        if ($q_type === 'essay') {
            $has_essay = true;
            $user_ans = isset($submitted_answers[$qid]) ? trim((string)$submitted_answers[$qid]) : '';
            $answer_records[$qid] = $user_ans;
        } elseif ($q_type === 'multiple_choice_complex') {
            // Pilihan Ganda Kompleks (AKM - Multi-select)
            $raw_user = $submitted_answers[$qid] ?? [];
            if (is_array($raw_user)) {
                $user_parts = array_filter(array_map(fn($v) => strtoupper(trim((string)$v)), $raw_user));
            } else {
                $user_parts = array_filter(array_map('trim', explode(',', strtoupper((string)$raw_user))));
            }
            sort($user_parts);
            $user_ans = implode(',', $user_parts);
            $answer_records[$qid] = $user_ans;

            $correct_parts = array_filter(array_map(fn($v) => strtoupper(trim((string)$v)), explode(',', (string)$q['correct_answer'])));
            sort($correct_parts);
            $correct_ans = implode(',', $correct_parts);

            if ($user_ans !== '' && $user_ans === $correct_ans) {
                $total_correct++;
                $earned_score += $max_pts;
            }
        } elseif ($q_type === 'true_false') {
            // Benar (B) / Salah (S)
            $user_ans = strtoupper(trim((string)($submitted_answers[$qid] ?? '')));
            if ($user_ans === 'BENAR') $user_ans = 'B';
            if ($user_ans === 'SALAH') $user_ans = 'S';
            $answer_records[$qid] = $user_ans;

            $correct_ans = strtoupper(trim((string)$q['correct_answer']));
            if ($correct_ans === 'BENAR') $correct_ans = 'B';
            if ($correct_ans === 'SALAH') $correct_ans = 'S';

            if ($user_ans !== '' && $user_ans === $correct_ans) {
                $total_correct++;
                $earned_score += $max_pts;
            }
        } elseif ($q_type === 'short_answer') {
            // Isian Singkat (Auto-scoring variasi jawaban dipisah tanda pipa |)
            $user_ans = trim((string)($submitted_answers[$qid] ?? ''));
            $answer_records[$qid] = $user_ans;

            $valid_options = array_map(fn($v) => strtolower(trim((string)$v)), explode('|', (string)$q['correct_answer']));
            if ($user_ans !== '' && in_array(strtolower($user_ans), $valid_options, true)) {
                $total_correct++;
                $earned_score += $max_pts;
            }
        } else {
            // Pilihan Ganda Tunggal (Opsi A - E)
            $user_ans = strtoupper(trim((string)($submitted_answers[$qid] ?? '')));
            $answer_records[$qid] = $user_ans;

            if ($user_ans !== '' && $user_ans === strtoupper(trim((string)$q['correct_answer']))) {
                $total_correct++;
                $earned_score += $max_pts;
            }
        }
    }

    if ($total_possible_score > 0) {
        $score = round(($earned_score / $total_possible_score) * 100, 2);
    } else {
        $score = 0;
    }

    $essay_graded = $has_essay ? 0 : 1;
    $essay_scores_json = json_encode(new stdClass());
    $answers_json = json_encode($answer_records, JSON_UNESCAPED_UNICODE);

    $violation_count = (int)($_POST['violation_count'] ?? 0);
    $is_locked = (int)($_POST['is_locked'] ?? 0);
    if ($violation_count >= 3) {
        $is_locked = 1;
    }

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
                violation_count = ?,
                is_locked = ?,
                submitted_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt_upd->execute([$score, $total_correct, $total_q, $answers_json, $essay_scores_json, $essay_graded, $orig_score, $violation_count, $is_locked, $existing_submission['id']]);

        header("Location: exam_results.php?id=$exam_id&remedial_submitted=1" . ($is_locked ? '&locked=1' : ''));
        exit;
    } else {
        // Mode Ujian Standar
        $stmt_ins = $pdo->prepare("
            INSERT INTO exam_submissions (exam_id, student_id, score, total_correct, total_questions, answers, essay_scores, essay_graded, status, violation_count, is_locked)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'selesai', ?, ?)
            ON DUPLICATE KEY UPDATE 
                score = VALUES(score),
                total_correct = VALUES(total_correct),
                total_questions = VALUES(total_questions),
                answers = VALUES(answers),
                essay_scores = VALUES(essay_scores),
                essay_graded = VALUES(essay_graded),
                violation_count = VALUES(violation_count),
                is_locked = VALUES(is_locked),
                submitted_at = CURRENT_TIMESTAMP
        ");
        $stmt_ins->execute([$exam_id, $user_id, $score, $total_correct, $total_q, $answers_json, $essay_scores_json, $essay_graded, $violation_count, $is_locked]);

        header("Location: exam_results.php?id=$exam_id&submitted=1" . ($is_locked ? '&locked=1' : ''));
        exit;
    }
}

$page_title = "Pengerjaan CBT - " . $exam['title'];
require_once __DIR__ . "/../includes/header.php";
?>

<!-- KaTeX Library untuk Rumus Matematika & Sains (Auto-rendered) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js" onload="renderMathInElement(document.body, {delimiters: [{left: '$$', right: '$$', display: true}, {left: '$', right: '$', display: false}]});"></script>


<?php
$cat_info = EXAM_CATEGORIES[$exam['category']] ?? ['label' => $exam['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '<i class="fa-solid fa-file-pen"></i>'];
$duration_minutes = (int) $exam['duration_minutes'];
$is_timed = ($duration_minutes > 0);
?>

<style>
    .font-size-sm .font-size-target { font-size: 0.875rem !important; line-height: 1.5rem !important; }
    .font-size-base .font-size-target { font-size: 1rem !important; line-height: 1.75rem !important; }
    .font-size-lg .font-size-target { font-size: 1.15rem !important; line-height: 2rem !important; }
</style>

<!-- MODAL PERINGATAN KECURANGAN (ANTI-CHEAT DETEKTOR TAB SWITCH) -->
<div id="cheatWarningModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-md p-4 animate-in fade-in duration-200">
    <div class="max-w-md w-full rounded-3xl border border-rose-500/50 bg-slate-900 p-6 sm:p-7 shadow-2xl text-center space-y-4">
        <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-rose-500/20 border border-rose-500/40 text-2xl text-rose-400 animate-bounce">
            <i class="fa-solid fa-triangle-exclamation"></i>
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

<!-- MODAL LOCKDOWN MAKSIMAL PELANGGARAN -->
<div id="lockedModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/90 backdrop-blur-lg p-4 animate-in fade-in duration-300">
    <div class="max-w-md w-full rounded-3xl border border-rose-500 bg-slate-900 p-7 shadow-2xl text-center space-y-5">
        <div class="inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-rose-500/20 border border-rose-500/40 text-3xl text-rose-400 animate-pulse">
            <i class="fa-solid fa-lock"></i>
        </div>
        <div class="space-y-2">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-rose-500 bg-rose-500/20 px-3.5 py-1 text-xs font-black text-rose-300 uppercase tracking-widest">
                UJIAN DIKUNCI PENGAWAS
            </span>
            <h3 class="text-xl font-black text-white">Batas Pelanggaran Terlampaui!</h3>
            <p class="text-xs text-slate-300 leading-relaxed">
                Anda terdeteksi melakukan tindakan kecurangan/berpindah tab sebanyak <strong class="text-rose-400">3 kali</strong>. Seluruh lembar jawaban Anda otomatis disimpan dan dikumpulkan ke Guru Pengawas dengan status <em>Terkunci</em>.
            </p>
        </div>
        <div class="p-3 rounded-2xl bg-rose-950/40 border border-rose-500/30 text-xs text-rose-300 font-mono">
            Mengumpulkan jawaban otomatis dalam <span id="lockCountdown" class="font-bold text-white text-sm">3</span> detik...
        </div>
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
                    <?php if (!empty($exam['education_level'])): ?>
                        <span class="text-[10px] text-emerald-300 font-bold bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 rounded-full">
                            <?= htmlspecialchars(getExamLevelLabel($exam['education_level'])) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($exam['randomize_questions'])): ?>
                        <span class="text-[10px] text-cyan-300 font-bold bg-cyan-500/10 border border-cyan-500/30 px-1.5 py-0.5 rounded">
                            <i class="fa-solid fa-shuffle mr-1"></i>Acak
                        </span>
                    <?php endif; ?>
                    <!-- Autosave Status Badge -->
                    <span id="autosaveBadge" class="text-[10px] text-emerald-400 font-semibold bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 rounded-full flex items-center gap-1 transition-opacity duration-300">
                        <i class="fa-solid fa-floppy-disk mr-1"></i><span>Autosave Aktif</span>
                    </span>
                    <!-- Fullscreen Lock Toggle -->
                    <button type="button" onclick="toggleFullScreen()" id="fsBtn" class="text-[10px] text-purple-300 font-bold bg-purple-500/10 border border-purple-500/30 px-2 py-0.5 rounded-full flex items-center gap-1 hover:bg-purple-500/20 transition cursor-pointer">
                        <i class="fa-solid fa-expand"></i><span id="fsText">Layar Penuh</span>
                    </button>
                    <!-- Proctoring Anti-Cheat HUD Badge -->
                    <span id="proctorBadge" class="text-[10px] text-emerald-400 font-bold bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 rounded-full flex items-center gap-1">
                        <i class="fa-solid fa-shield-halved"></i><span id="proctorText">Anti-Cheat: 0 / 3</span>
                    </span>
                </div>
                <div class="flex items-center gap-3">
                    <h1 class="text-base sm:text-lg font-bold text-white line-clamp-1">
                        <?= htmlspecialchars($exam['title']) ?>
                    </h1>
                    <!-- Ukuran Teks (Font Size Scaler untuk SD s/d SMA) -->
                    <div class="hidden sm:inline-flex items-center gap-1 bg-white/5 border border-white/10 rounded-xl p-0.5 text-xs">
                        <span class="text-[10px] text-slate-400 font-semibold px-1.5"><i class="fa-solid fa-font mr-1"></i>Teks:</span>
                        <button type="button" onclick="setFontSize('sm')" id="fontBtnSm" class="px-2 py-0.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 font-bold transition text-xs">A-</button>
                        <button type="button" onclick="setFontSize('base')" id="fontBtnBase" class="px-2 py-0.5 rounded-lg bg-blue-600 text-white font-bold transition text-xs">A</button>
                        <button type="button" onclick="setFontSize('lg')" id="fontBtnLg" class="px-2 py-0.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 font-bold transition text-xs">A+</button>
                    </div>
                </div>
            </div>

            <!-- Timer / Flexible Mode Badge -->
            <div class="flex items-center gap-3">
                <?php if ($is_timed): ?>
                    <div class="flex items-center gap-2 rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-2 text-amber-300 shadow-inner">
                        <span class="text-base text-amber-400 animate-pulse"><i class="fa-regular fa-clock"></i></span>
                        <div>
                            <span class="block text-[10px] uppercase tracking-wider text-amber-400/80 font-bold">Sisa Waktu:</span>
                            <span id="countdownDisplay" class="text-lg font-extrabold tracking-wider font-mono">
                                --:--
                            </span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="flex items-center gap-2 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-2 text-emerald-300">
                        <span class="text-base text-emerald-400"><i class="fa-solid fa-bolt"></i></span>
                        <div>
                            <span class="block text-[10px] uppercase tracking-wider font-bold">Mode Fleksibel</span>
                            <span class="text-xs font-semibold text-emerald-200">Tanpa Batas Waktu</span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Tombol Pintas Selesai -->
                <button type="button" onclick="triggerSubmit()" 
                        class="inline-flex items-center gap-1.5 rounded-2xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2.5 text-xs font-extrabold text-white shadow-lg shadow-emerald-500/25 transition cursor-pointer shrink-0">
                    <i class="fa-solid fa-check"></i> Kumpulkan
                </button>
            </div>
        </div>
    </div>

    <!-- Alert Sesi Remedial (Jika Sedang Remedial) -->
    <?php if ($is_remedial_attempt): ?>
        <div class="rounded-3xl border border-amber-500/40 bg-gradient-to-r from-amber-950/50 to-slate-900 p-5 text-amber-200 shadow-xl flex items-start gap-3.5">
            <span class="text-xl text-amber-400 mt-0.5"><i class="fa-solid fa-rotate"></i></span>
            <div class="text-xs sm:text-sm">
                <strong class="text-white block font-bold text-sm">Anda Sedang Mengerjakan Sesi Remedial</strong>
                <span>Nilai ujian sebelumnya: <strong class="text-rose-400"><?= number_format($existing_submission['score'], 0) ?></strong>. Target standar KKM: <strong class="text-emerald-400"><?= $exam['passing_grade'] ?></strong>. Nilai baru hasil remedial akan memperbarui rekap nilai Anda.</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- GRID 2 KOLOM: LEMBAR SOAL 1-PER-LAYAR (KIRI) & PALET NOMOR CBT (KANAN) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

        <!-- KOLOM KIRI: LEMBAR SOAL 1-PER-LAYAR (8 Kolom) -->
        <div class="lg:col-span-8 space-y-5" id="examQuestionContainer">

            <!-- Bar Ringkas Status Soal Saat Ini -->
            <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-3.5 text-xs text-slate-300 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded-lg bg-blue-600/30 text-blue-400 font-mono font-bold text-xs">
                        <i class="fa-solid fa-clipboard-question"></i>
                    </span>
                    <span>Menampilkan Soal No. <strong id="currentQuestionNumDisplay" class="text-white font-mono text-sm">1</strong> dari <strong class="text-white font-mono"><?= $total_q ?></strong></span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-[11px] text-slate-400">KKM: <strong class="text-white"><?= $exam['passing_grade'] ?></strong></span>
                </div>
            </div>

            <!-- Formulir Pengerjaan Soal (1 Soal per Layar Slide CBT) -->
            <form id="examForm" method="POST" action="exam_take.php?id=<?= $exam['id'] ?>" class="space-y-5 select-none">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="submit_answers">
                <input type="hidden" name="violation_count" id="violationCountInput" value="0">
                <input type="hidden" name="is_locked" id="isLockedInput" value="0">

                <?php foreach ($questions as $idx => $q): 
                    $qid = (int) $q['id'];
                    $q_type = $q['question_type'] ?? 'multiple_choice';
                    $max_pts = !empty($q['max_score']) ? (float)$q['max_score'] : 10;
                ?>
                    <div id="soal-<?= $idx + 1 ?>" class="question-slide <?= $idx === 0 ? '' : 'hidden' ?> rounded-3xl border border-white/10 bg-slate-900/90 p-6 sm:p-8 shadow-2xl space-y-6 transition-all" data-index="<?= $idx + 1 ?>" data-qid="<?= $qid ?>">
                        
                        <!-- Header Nomor & Tombol Ragu-Ragu -->
                        <div class="flex items-center justify-between gap-4 border-b border-white/5 pb-4">
                            <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                                <span class="flex h-9 w-9 items-center justify-center rounded-2xl bg-blue-600 text-white text-sm font-black shrink-0 shadow-lg shadow-blue-500/30 font-mono">
                                    <?= $idx + 1 ?>
                                </span>
                                <div>
                                    <span class="text-xs text-slate-400 font-semibold block">Soal No. <?= $idx + 1 ?> dari <?= $total_q ?></span>
                                    <span class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-extrabold <?= getExamQuestionTypeBadge($q_type) ?>">
                                        <?= htmlspecialchars(getExamQuestionTypeLabel($q_type)) ?> (Maks. <?= (int)$max_pts ?> Poin)
                                    </span>
                                </div>
                            </div>

                            <!-- Checkbox / Tombol Ragu-Ragu -->
                            <label class="inline-flex items-center gap-2 text-xs font-bold cursor-pointer rounded-xl border border-amber-500/30 bg-amber-500/10 px-3.5 py-2 text-amber-300 hover:bg-amber-500/20 transition shrink-0">
                                <input type="checkbox" 
                                       id="doubt-<?= $idx + 1 ?>" 
                                       onchange="toggleDoubt(<?= $idx + 1 ?>, <?= $qid ?>)" 
                                       class="h-4 w-4 rounded border-white/20 bg-slate-950 text-amber-500 focus:ring-amber-400 cursor-pointer">
                                <span><i class="fa-regular fa-flag mr-1"></i>Ragu-Ragu</span>
                            </label>
                        </div>

                        <!-- Audio Listening Player (Jika Ada Audio) -->
                        <?php if (!empty($q['audio_url'])): ?>
                            <div class="rounded-2xl border border-sky-500/30 bg-sky-950/30 p-3.5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-inner">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-500/20 text-sky-300 text-lg border border-sky-500/30">
                                        <i class="fa-solid fa-headphones"></i>
                                    </span>
                                    <div>
                                        <span class="text-xs font-bold text-white block">Audio Listening / Pemahaman Menyimak</span>
                                        <span class="text-[11px] text-sky-300/80">Dengarkan audio dengan saksama untuk menjawab pertanyaan berikut.</span>
                                    </div>
                                </div>
                                <audio controls controlsList="nodownload" class="w-full sm:w-72 h-10 rounded-xl bg-slate-900 shadow">
                                    <source src="<?= htmlspecialchars($q['audio_url']) ?>" type="audio/mpeg">
                                    Browser Anda tidak mendukung pemutar audio.
                                </audio>
                            </div>
                        <?php endif; ?>

                        <!-- Teks Soal (KaTeX Auto-rendered) -->
                        <div class="font-size-target font-medium text-white leading-relaxed tracking-wide">
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

                        <!-- AREA INPUT JAWABAN BERDASARKAN TIPE SOAL -->
                        <?php if ($q_type === 'essay'): ?>
                            <!-- Kolom Jawaban Esai / Uraian Siswa -->
                            <div class="space-y-2 pt-1">
                                <div class="flex items-center justify-between text-xs text-slate-400 font-semibold">
                                    <label for="essay-<?= $qid ?>" class="flex items-center gap-1.5 text-purple-400 font-bold">
                                        <i class="fa-solid fa-pen-fancy"></i> Tulis Jawaban / Uraian Lengkap Anda:
                                    </label>
                                    <span class="text-[11px] text-slate-400">Tersimpan otomatis saat Anda mengetik</span>
                                </div>
                                <textarea name="answers[<?= $qid ?>]" 
                                          id="essay-<?= $qid ?>" 
                                          rows="6" 
                                          placeholder="Ketik uraian jawaban secara jelas dan terstruktur di sini..." 
                                          oninput="onTextInput(<?= $idx + 1 ?>, <?= $qid ?>, this.value)"
                                          class="w-full rounded-2xl border border-white/10 bg-slate-950 p-4 text-sm text-white placeholder-slate-500 focus:border-purple-500 focus:outline-none focus:ring-2 focus:ring-purple-500/20 leading-relaxed font-sans"></textarea>
                            </div>

                        <?php elseif ($q_type === 'short_answer'): ?>
                            <!-- Kolom Jawaban Isian Singkat -->
                            <div class="space-y-2 pt-1">
                                <div class="flex items-center justify-between text-xs text-slate-400 font-semibold">
                                    <label for="short-<?= $qid ?>" class="flex items-center gap-1.5 text-cyan-400 font-bold">
                                        <i class="fa-solid fa-i-cursor"></i> Tulis Jawaban Singkat:
                                    </label>
                                    <span class="text-[11px] text-slate-400">Ketik kata/istilah/angka yang tepat</span>
                                </div>
                                <input type="text" 
                                       name="answers[<?= $qid ?>]" 
                                       id="short-<?= $qid ?>" 
                                       placeholder="Ketik jawaban singkat di sini..." 
                                       autocomplete="off"
                                       oninput="onTextInput(<?= $idx + 1 ?>, <?= $qid ?>, this.value)"
                                       class="w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3.5 text-sm text-white placeholder-slate-500 focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/20 font-sans">
                            </div>

                        <?php elseif ($q_type === 'true_false'): ?>
                            <!-- Opsi Benar / Salah (True / False) -->
                            <div class="space-y-3 pt-1">
                                <div class="text-xs text-slate-400 font-semibold">
                                    Tentukan pernyataan di atas apakah bernilai Benar atau Salah:
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <!-- Pilihan Benar -->
                                    <label class="flex items-center gap-3.5 rounded-2xl border border-white/10 bg-white/5 p-4 hover:border-emerald-500 hover:bg-emerald-500/10 transition cursor-pointer group">
                                        <input type="radio" 
                                               name="answers[<?= $qid ?>]" 
                                               id="opt-<?= $qid ?>-B"
                                               value="B" 
                                               class="h-5 w-5 text-emerald-600 bg-slate-800 border-white/20 focus:ring-emerald-500 cursor-pointer"
                                               onchange="onAnswerSelected(<?= $idx + 1 ?>, <?= $qid ?>, 'B')">
                                        <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-300 font-black text-sm group-hover:bg-emerald-600 group-hover:text-white transition">
                                            <i class="fa-solid fa-check"></i>
                                        </span>
                                        <span class="text-sm font-bold text-white">
                                            BENAR
                                        </span>
                                    </label>

                                    <!-- Pilihan Salah -->
                                    <label class="flex items-center gap-3.5 rounded-2xl border border-white/10 bg-white/5 p-4 hover:border-rose-500 hover:bg-rose-500/10 transition cursor-pointer group">
                                        <input type="radio" 
                                               name="answers[<?= $qid ?>]" 
                                               id="opt-<?= $qid ?>-S"
                                               value="S" 
                                               class="h-5 w-5 text-rose-600 bg-slate-800 border-white/20 focus:ring-rose-500 cursor-pointer"
                                               onchange="onAnswerSelected(<?= $idx + 1 ?>, <?= $qid ?>, 'S')">
                                        <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-rose-500/20 text-rose-300 font-black text-sm group-hover:bg-rose-600 group-hover:text-white transition">
                                            <i class="fa-solid fa-xmark"></i>
                                        </span>
                                        <span class="text-sm font-bold text-white">
                                            SALAH
                                        </span>
                                    </label>
                                </div>
                            </div>

                        <?php elseif ($q_type === 'multiple_choice_complex'): ?>
                            <!-- Pilihan Ganda Kompleks (AKM Multi-select Checkbox) -->
                            <div class="space-y-3 pt-1">
                                <div class="rounded-xl border border-indigo-500/30 bg-indigo-500/10 p-2.5 text-xs text-indigo-300 flex items-center gap-2">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <span><strong>Model Asesmen Nasional (AKM):</strong> Anda dapat mencentang satu atau lebih dari satu pilihan jawaban yang benar.</span>
                                </div>
                                <div class="space-y-2.5">
                                    <?php 
                                    $options = [
                                        'A' => $q['option_a'],
                                        'B' => $q['option_b'],
                                        'C' => $q['option_c'],
                                        'D' => $q['option_d'],
                                    ];
                                    if (!empty($q['option_e'])) {
                                        $options['E'] = $q['option_e'];
                                    }
                                    ?>
                                    <?php foreach ($options as $key => $opt_text): ?>
                                        <label class="flex items-center gap-3.5 rounded-2xl border border-white/5 bg-white/5 p-3.5 sm:p-4 hover:border-indigo-500/50 hover:bg-indigo-500/5 transition cursor-pointer group">
                                            <input type="checkbox" 
                                                   name="answers[<?= $qid ?>][]" 
                                                   id="opt-complex-<?= $qid ?>-<?= $key ?>"
                                                   value="<?= $key ?>" 
                                                   class="h-4 w-4 text-indigo-600 bg-slate-800 border-white/20 rounded focus:ring-indigo-500 cursor-pointer"
                                                   onchange="onComplexAnswerChange(<?= $idx + 1 ?>, <?= $qid ?>)">
                                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10 text-xs font-black text-slate-300 group-hover:bg-indigo-600 group-hover:text-white transition font-mono">
                                                <?= $key ?>
                                            </span>
                                            <span class="font-size-target text-slate-200 group-hover:text-white transition">
                                                <?= htmlspecialchars($opt_text) ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- Pilihan Ganda Tunggal (Opsi A - E) -->
                            <div class="space-y-2.5 pt-1">
                                <?php 
                                $options = [
                                    'A' => $q['option_a'],
                                    'B' => $q['option_b'],
                                    'C' => $q['option_c'],
                                    'D' => $q['option_d'],
                                ];
                                if (!empty($q['option_e'])) {
                                    $options['E'] = $q['option_e'];
                                }
                                ?>
                                <?php foreach ($options as $key => $opt_text): ?>
                                    <label class="flex items-center gap-3.5 rounded-2xl border border-white/5 bg-white/5 p-3.5 sm:p-4 hover:border-blue-500/50 hover:bg-blue-500/5 transition cursor-pointer group">
                                        <input type="radio" 
                                               name="answers[<?= $qid ?>]" 
                                               id="opt-<?= $qid ?>-<?= $key ?>"
                                               value="<?= $key ?>" 
                                               class="h-4 w-4 text-blue-600 bg-slate-800 border-white/20 focus:ring-blue-500 cursor-pointer"
                                               onchange="onAnswerSelected(<?= $idx + 1 ?>, <?= $qid ?>, '<?= $key ?>')">
                                        <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-white/10 text-xs font-black text-slate-300 group-hover:bg-blue-600 group-hover:text-white transition font-mono">
                                            <?= $key ?>
                                        </span>
                                        <span class="font-size-target text-slate-200 group-hover:text-white transition">
                                            <?= htmlspecialchars($opt_text) ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Navigasi Bawah Antar Soal (Prev / Ragu / Next) -->
                        <div class="flex items-center justify-between border-t border-white/5 pt-5 text-xs font-semibold">
                            <?php if ($idx > 0): ?>
                                <button type="button" onclick="navigateQuestion(-1)" 
                                        class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-slate-300 hover:text-white transition cursor-pointer inline-flex items-center gap-1.5">
                                    <i class="fa-solid fa-arrow-left"></i> Soal Sebelumnya
                                </button>
                            <?php else: ?>
                                <button type="button" disabled 
                                        class="rounded-xl border border-white/5 bg-white/5 px-4 py-2.5 text-slate-600 cursor-not-allowed inline-flex items-center gap-1.5 opacity-50">
                                    <i class="fa-solid fa-arrow-left"></i> Soal Sebelumnya
                                </button>
                            <?php endif; ?>

                            <!-- Tombol Toggle Ragu-Ragu di Footer -->
                            <button type="button" onclick="toggleDoubtCurrent()" 
                                    class="rounded-xl border border-amber-500/40 bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 px-4 py-2.5 transition cursor-pointer inline-flex items-center gap-1.5">
                                <i class="fa-regular fa-flag"></i> <span class="doubtBtnText">Tandai Ragu-Ragu</span>
                            </button>

                            <?php if ($idx < $total_q - 1): ?>
                                <button type="button" onclick="navigateQuestion(1)" 
                                        class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-white font-bold transition cursor-pointer inline-flex items-center gap-1.5 shadow-md shadow-blue-500/20">
                                    Soal Berikutnya <i class="fa-solid fa-arrow-right"></i>
                                </button>
                            <?php else: ?>
                                <button type="button" onclick="triggerSubmit()" 
                                        class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2.5 text-white font-extrabold transition cursor-pointer inline-flex items-center gap-1.5 shadow-lg shadow-emerald-500/25">
                                    Periksa & Kumpulkan <i class="fa-solid fa-check"></i>
                                </button>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>

                <!-- Bottom Actions Bar Ringkasan -->
                <div class="rounded-3xl border border-white/10 bg-slate-900/90 p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 shadow-2xl">
                    <div>
                        <span class="text-xs text-slate-400 block">Selesai menjawab semua butir soal?</span>
                        <span class="text-xs font-bold text-white">Pastikan semua nomor telah dijawab dan tidak ada yang ragu-ragu.</span>
                    </div>

                    <div class="flex items-center gap-3">
                        <a href="exams.php" onclick="return confirm('Apakah Anda yakin ingin keluar? Pastikan lembar ujian telah dikumpulkan.');" 
                           class="rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:bg-white/5 transition">
                            Keluar
                        </a>
                        <button type="submit" onclick="return confirmSubmit();" 
                                class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2.5 text-xs font-extrabold text-white shadow-lg shadow-emerald-500/25 transition cursor-pointer inline-flex items-center gap-2">
                            <i class="fa-solid fa-circle-check"></i> Kumpulkan Jawaban Ujian
                        </button>
                    </div>
                </div>

            </form>

        </div>

        <!-- KOLOM KANAN: PALET NOMOR CBT INTERAKTIF (4 Kolom) -->
        <div class="lg:col-span-4 sticky top-44 space-y-4">

            <div class="rounded-3xl border border-white/10 bg-slate-900/90 p-5 space-y-4 shadow-2xl backdrop-blur-xl">
                <!-- Palette Header & Ringkasan -->
                <div class="flex items-center justify-between border-b border-white/10 pb-3">
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-table-cells text-blue-400"></i> Palet Nomor Soal
                    </h3>
                    <span class="text-xs font-bold text-blue-400 font-mono">Total <?= $total_q ?></span>
                </div>

                <!-- Status Counters -->
                <div class="grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-2.5">
                        <span class="block text-[10px] uppercase tracking-wider text-emerald-400 font-bold">Terjawab</span>
                        <span id="countAnswered" class="text-lg font-black text-white font-mono">0</span>
                    </div>
                    <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-2.5">
                        <span class="block text-[10px] uppercase tracking-wider text-amber-400 font-bold">Ragu</span>
                        <span id="countDoubt" class="text-lg font-black text-white font-mono">0</span>
                    </div>
                    <div class="rounded-2xl border border-white/5 bg-white/5 p-2.5">
                        <span class="block text-[10px] uppercase tracking-wider text-slate-400 font-bold">Belum</span>
                        <span id="countUnanswered" class="text-lg font-black text-white font-mono"><?= $total_q ?></span>
                    </div>
                </div>

                <!-- Petunjuk Warna Palet -->
                <div class="flex items-center justify-center gap-3 text-[10px] text-slate-400 pt-1">
                    <span class="flex items-center gap-1">
                        <span class="h-2.5 w-2.5 rounded-full bg-slate-700 inline-block"></span> Belum
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-600 inline-block"></span> Dijawab
                    </span>
                    <span class="flex items-center gap-1">
                        <span class="h-2.5 w-2.5 rounded-full bg-amber-500 inline-block"></span> Ragu
                    </span>
                </div>

                <!-- Grid Kotak Nomor Soal 1 s.d N -->
                <div class="grid grid-cols-5 gap-2 max-h-[50vh] overflow-y-auto pr-1">
                    <?php for ($n = 1; $n <= $total_q; $n++): ?>
                        <button type="button" 
                                id="palette-btn-<?= $n ?>" 
                                onclick="jumpToQuestion(<?= $n ?>)" 
                                class="h-10 rounded-xl border border-white/10 bg-slate-800 text-xs font-bold text-slate-300 hover:border-blue-500 transition shadow-sm cursor-pointer font-mono <?= $n === 1 ? 'ring-2 ring-blue-400' : '' ?>">
                            <?= $n ?>
                        </button>
                    <?php endfor; ?>
                </div>

                <!-- Tombol Submit di Palette -->
                <div class="pt-2 border-t border-white/10">
                    <button type="button" onclick="triggerSubmit()" 
                            class="w-full inline-flex items-center justify-center gap-2 rounded-2xl bg-emerald-600 hover:bg-emerald-500 py-3 text-xs font-extrabold text-white shadow-lg shadow-emerald-500/20 transition cursor-pointer">
                        <i class="fa-solid fa-check"></i> Kumpulkan Ujian
                    </button>
                </div>

            </div>

        </div>

    </div>

</div>

<!-- SCRIPT AUTOSAVE, NAVIGASI 1 SOAL PER LAYAR CBT & ANTI-CHEAT ENGINE -->
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

    let currentSlideIndex = 0; // 0-based index
    let answersState = {}; // { qid: 'A' or ['A', 'C'] or 'text' }
    let doubtsState = {};   // { index1Based: true }
    let lastViolationTime = 0;

    // 0. Ukuran Font Switcher (SD s/d SMA)
    function setFontSize(size) {
        const container = document.getElementById('examQuestionContainer');
        if (!container) return;
        container.classList.remove('font-size-sm', 'font-size-base', 'font-size-lg');
        container.classList.add(`font-size-${size}`);

        ['sm', 'base', 'lg'].forEach(s => {
            const btn = document.getElementById(`fontBtn${s.charAt(0).toUpperCase() + s.slice(1)}`);
            if (btn) {
                if (s === size) {
                    btn.className = "px-2 py-0.5 rounded-lg bg-blue-600 text-white font-bold transition text-xs";
                } else {
                    btn.className = "px-2 py-0.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 font-bold transition text-xs";
                }
            }
        });
    }

    // 1. Tampilkan Slide Soal Tertentu (1 Soal per Layar)
    function showQuestionSlide(idx0) {
        if (idx0 < 0 || idx0 >= TOTAL_QUESTIONS) return;
        currentSlideIndex = idx0;

        const slides = document.querySelectorAll('.question-slide');
        slides.forEach((sl, i) => {
            if (i === idx0) {
                sl.classList.remove('hidden');
            } else {
                sl.classList.add('hidden');
            }
        });

        // Update display nomor aktif
        const curDisplay = document.getElementById('currentQuestionNumDisplay');
        if (curDisplay) curDisplay.innerText = (idx0 + 1);

        // Update status tombol ragu di slide aktif
        const isDoubt = Boolean(doubtsState[idx0 + 1]);
        const curSlide = document.getElementById(`soal-${idx0 + 1}`);
        if (curSlide) {
            const doubtLabels = curSlide.querySelectorAll('.doubtBtnText');
            doubtLabels.forEach(el => {
                el.innerText = isDoubt ? "Hilangkan Tanda Ragu" : "Tandai Ragu-Ragu";
            });
        }

        // Highlight palet aktif
        for (let n = 1; n <= TOTAL_QUESTIONS; n++) {
            const pBtn = document.getElementById(`palette-btn-${n}`);
            if (pBtn) {
                if (n === (idx0 + 1)) {
                    pBtn.classList.add('ring-2', 'ring-blue-400', 'ring-offset-2', 'ring-offset-slate-900');
                } else {
                    pBtn.classList.remove('ring-2', 'ring-blue-400', 'ring-offset-2', 'ring-offset-slate-900');
                }
            }
        }

        // Trigger KaTeX render jika ada rumus baru di slide aktif
        if (typeof renderMathInElement === 'function') {
            const activeCard = document.getElementById(`soal-${idx0 + 1}`);
            if (activeCard) {
                renderMathInElement(activeCard, {
                    delimiters: [
                        {left: '$$', right: '$$', display: true},
                        {left: '$', right: '$', display: false}
                    ]
                });
            }
        }
    }

    function navigateQuestion(step) {
        showQuestionSlide(currentSlideIndex + step);
    }

    function jumpToQuestion(questionIndex1Based) {
        showQuestionSlide(questionIndex1Based - 1);
    }

    function toggleDoubtCurrent() {
        const idx = currentSlideIndex + 1;
        const doubtBox = document.getElementById(`doubt-${idx}`);
        if (doubtBox) {
            doubtBox.checked = !doubtBox.checked;
            doubtsState[idx] = doubtBox.checked;
        } else {
            doubtsState[idx] = !doubtsState[idx];
        }
        saveDraft();
        updatePaletteAndCounters();
        showQuestionSlide(currentSlideIndex);
    }

    // 2. Inisialisasi & Pulihkan Autosave dari LocalStorage
    function initAutosave() {
        const saved = localStorage.getItem(DRAFT_KEY);
        if (saved) {
            try {
                const parsed = JSON.parse(saved);
                answersState = parsed.answers || {};
                doubtsState = parsed.doubts || {};

                // Restore jawaban radio, checkbox complex, short answer, & essay
                for (const [qid, val] of Object.entries(answersState)) {
                    if (Array.isArray(val)) {
                        // Complex multiple choice
                        val.forEach(k => {
                            const chk = document.getElementById(`opt-complex-${qid}-${k}`);
                            if (chk) chk.checked = true;
                        });
                    } else {
                        // Radio
                        const radio = document.getElementById(`opt-${qid}-${val}`);
                        if (radio) radio.checked = true;

                        // Short answer
                        const shortBox = document.getElementById(`short-${qid}`);
                        if (shortBox) shortBox.value = val;

                        // Essay
                        const essayBox = document.getElementById(`essay-${qid}`);
                        if (essayBox) essayBox.value = val;
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
                autosaveBadge.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i><span>Draft Dipulihkan</span>';
                setTimeout(() => {
                    autosaveBadge.innerHTML = '<i class="fa-solid fa-floppy-disk mr-1"></i><span>Autosave Aktif</span>';
                    autosaveBadge.classList.remove('text-blue-400');
                    autosaveBadge.classList.add('text-emerald-400');
                }, 3000);

            } catch (e) {
                console.error("Gagal membaca draft ujian:", e);
            }
        }
        updatePaletteAndCounters();
        showQuestionSlide(0);
    }

    // 3. Simpan ke LocalStorage
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

    // 4. Handler Jawaban Radio (Pilihan Ganda & Benar/Salah)
    function onAnswerSelected(questionIndex, questionId, choice) {
        answersState[questionId] = choice;
        saveDraft();
        updatePaletteAndCounters();
    }

    // 4b. Handler Pilihan Ganda Kompleks (AKM Checkbox Multi-select)
    function onComplexAnswerChange(questionIndex, questionId) {
        const checkedList = [];
        const checkboxes = document.querySelectorAll(`input[name="answers[${questionId}][]"]:checked`);
        checkboxes.forEach(c => checkedList.push(c.value));
        
        if (checkedList.length > 0) {
            checkedList.sort();
            answersState[questionId] = checkedList;
        } else {
            delete answersState[questionId];
        }
        saveDraft();
        updatePaletteAndCounters();
    }

    // 4c. Handler Jawaban Isian Singkat & Esai
    function onTextInput(questionIndex, questionId, text) {
        if (text && text.trim() !== '') {
            answersState[questionId] = text;
        } else {
            delete answersState[questionId];
        }
        saveDraft();
        updatePaletteAndCounters();
    }

    // 5. Handler saat tombol ragu-ragu di-toggle via checkbox
    function toggleDoubt(questionIndex, questionId) {
        const doubtBox = document.getElementById(`doubt-${questionIndex}`);
        doubtsState[questionIndex] = doubtBox ? doubtBox.checked : false;
        saveDraft();
        updatePaletteAndCounters();
        showQuestionSlide(currentSlideIndex);
    }

    // 6. Update Status Palet Nomor & Counter Ringkasan
    function updatePaletteAndCounters() {
        let answeredCount = 0;
        let doubtCount = 0;

        <?php foreach ($questions as $idx => $q): ?>
            (function() {
                const idx = <?= $idx + 1 ?>;
                const qid = <?= $q['id'] ?>;
                const btn = document.getElementById(`palette-btn-${idx}`);
                if (!btn) return;

                const ansVal = answersState[qid];
                let hasAnswer = false;
                if (Array.isArray(ansVal)) {
                    hasAnswer = ansVal.length > 0;
                } else if (ansVal !== undefined && ansVal !== null && ansVal.toString().trim() !== '') {
                    hasAnswer = true;
                }
                const isDoubt = Boolean(doubtsState[idx]);

                if (hasAnswer) answeredCount++;
                if (isDoubt) doubtCount++;

                // Reset kelas warna
                let baseClasses = "h-10 rounded-xl text-xs font-bold transition shadow-sm cursor-pointer font-mono ";
                if (idx === (currentSlideIndex + 1)) {
                    baseClasses += "ring-2 ring-blue-400 ring-offset-2 ring-offset-slate-900 ";
                }

                if (isDoubt) {
                    btn.className = baseClasses + "bg-amber-500 text-slate-950 font-black border-2 border-amber-300 shadow-amber-500/20";
                } else if (hasAnswer) {
                    btn.className = baseClasses + "bg-emerald-600 text-white font-black border border-emerald-400 shadow-emerald-500/20";
                } else {
                    btn.className = baseClasses + "bg-slate-800 text-slate-300 border border-white/10 hover:border-blue-500";
                }
            })();
        <?php endforeach; ?>

        const unansweredCount = TOTAL_QUESTIONS - answeredCount;

        document.getElementById('countAnswered').innerText = answeredCount;
        document.getElementById('countDoubt').innerText = doubtCount;
        document.getElementById('countUnanswered').innerText = unansweredCount;
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

    // 8. ADVANCED ANTI-CHEAT & PROCTORING (EXAM BROWSER LOCKDOWN)
    const MAX_VIOLATIONS = 3;
    const IS_LOCKDOWN = <?= (!isset($exam['lockdown_enabled']) || (int)$exam['lockdown_enabled'] === 1) ? 'true' : 'false' ?>;

    function updateProctorBadge(violations) {
        const pBadge = document.getElementById('proctorBadge');
        const pText = document.getElementById('proctorText');
        const countInput = document.getElementById('violationCountInput');
        if (countInput) countInput.value = violations;

        if (pBadge && pText) {
            pText.innerText = `Anti-Cheat: ${violations} / ${MAX_VIOLATIONS}`;
            if (violations === 0) {
                pBadge.className = 'text-[10px] text-emerald-400 font-bold bg-emerald-500/10 border border-emerald-500/30 px-2 py-0.5 rounded-full flex items-center gap-1';
            } else if (violations < MAX_VIOLATIONS) {
                pBadge.className = 'text-[10px] text-amber-400 font-bold bg-amber-500/10 border border-amber-500/30 px-2 py-0.5 rounded-full flex items-center gap-1 animate-pulse';
            } else {
                pBadge.className = 'text-[10px] text-rose-400 font-bold bg-rose-500/20 border border-rose-500/40 px-2 py-0.5 rounded-full flex items-center gap-1 animate-pulse';
            }
        }
    }

    function triggerLockdown() {
        const isLockedInput = document.getElementById('isLockedInput');
        if (isLockedInput) isLockedInput.value = "1";

        const lockedModal = document.getElementById('lockedModal');
        if (lockedModal) lockedModal.classList.remove('hidden');
        dismissCheatWarning();

        // Nonaktifkan seluruh input lembar ujian
        const inputs = examForm.querySelectorAll('input, textarea, button');
        inputs.forEach(el => el.disabled = true);

        let countdown = 3;
        const lockTimer = setInterval(() => {
            countdown--;
            const cdEl = document.getElementById('lockCountdown');
            if (cdEl) cdEl.innerText = countdown;
            if (countdown <= 0) {
                clearInterval(lockTimer);
                localStorage.removeItem(DRAFT_KEY);
                sessionStorage.removeItem(VIOLATION_KEY);
                examForm.submit();
            }
        }, 1000);
    }

    function recordViolation(reason = "Berpindah Tab / Jendela") {
        if (!IS_LOCKDOWN) return;
        const now = Date.now();
        // Debounce agar tidak double-trigger dalam rentang 1.5 detik
        if (now - lastViolationTime < 1500) return;
        lastViolationTime = now;

        let violations = parseInt(sessionStorage.getItem(VIOLATION_KEY) || "0") + 1;
        sessionStorage.setItem(VIOLATION_KEY, violations);

        updateProctorBadge(violations);
        violationText.innerText = violations;

        if (violations >= MAX_VIOLATIONS) {
            triggerLockdown();
        } else {
            cheatModal.classList.remove('hidden');
        }
    }

    function dismissCheatWarning() {
        cheatModal.classList.add('hidden');
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            recordViolation("Meninggalkan Tab Ujian");
        }
    });

    window.addEventListener('blur', () => {
        recordViolation("Membuka Jendela / Aplikasi Lain");
    });

    // Kontrol Layar Penuh (Fullscreen Enforcement)
    function toggleFullScreen() {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen().catch(err => {
                alert("Mode layar penuh memerlukan izin browser Anda.");
            });
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        }
    }

    document.addEventListener('fullscreenchange', () => {
        const fsText = document.getElementById('fsText');
        if (document.fullscreenElement) {
            if (fsText) fsText.innerText = "Keluar Penuh";
        } else {
            if (fsText) fsText.innerText = "Layar Penuh";
            recordViolation("Keluar dari Mode Layar Penuh");
        }
    });

    // 9. BLOKIR INSPECT ELEMENT & SHORTCUT TERLARANG
    window.addEventListener('keydown', (e) => {
        if (
            e.key === 'F12' || 
            (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j' || e.key === 'C' || e.key === 'c')) ||
            (e.ctrlKey && (e.key === 'u' || e.key === 'U' || e.key === 's' || e.key === 'S'))
        ) {
            e.preventDefault();
            recordViolation("Akses Shortcut Terlarang (" + e.key + ")");
            return false;
        }
    });

    examForm.addEventListener('contextmenu', (e) => e.preventDefault());
    examForm.addEventListener('copy', (e) => e.preventDefault());
    examForm.addEventListener('cut', (e) => e.preventDefault());
    examForm.addEventListener('paste', (e) => e.preventDefault());

    // Jalankan inisialisasi awal
    initAutosave();
    const initViolations = parseInt(sessionStorage.getItem(VIOLATION_KEY) || "0");
    updateProctorBadge(initViolations);
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
