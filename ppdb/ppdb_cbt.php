<?php
/**
 * Ujian CBT Seleksi Online Calon Siswa Baru (PPDB)
 * Standar Nasional: Navigasi 1 Soal per Layar, Real-time Auto-save Ajax, Papan Nomor Soal, Indikator Ragu-ragu, & Timer Server-sync.
 * Manajemen-PHP
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

$school_info = getSchoolSettings($pdo);
$reg_no = trim($_GET['reg'] ?? $_POST['registration_no'] ?? '');

$error_msg = null;
$success_msg = null;
$applicant = null;

// 1. Validasi Calon Siswa
if (empty($reg_no)) {
    $error_msg = "Nomor registrasi PPDB tidak ditemukan. Silakan akses melalui portal Cek Status PPDB.";
} else {
    $stmt_app = $pdo->prepare("SELECT * FROM ppdb_registrations WHERE registration_no = ? LIMIT 1");
    $stmt_app->execute([$reg_no]);
    $applicant = $stmt_app->fetch();

    if (!$applicant) {
        $error_msg = "Data pendaftar dengan nomor registrasi '$reg_no' tidak terdaftar.";
    } elseif ($applicant['cbt_score'] !== null || ($applicant['cbt_status'] ?? '') === 'selesai') {
        header("Location: ppdb.php?tab=cek&reg=" . urlencode($reg_no) . "&msg=cbt_completed");
        exit;
    }
}

// 2. Otentikasi Calon Siswa (Cek Sesi, QR Token, atau Tanggal Lahir)
$is_authenticated = false;
$auth_error = null;
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if ($applicant) {
    if (isset($_SESSION['ppdb_verified_reg']) && $_SESSION['ppdb_verified_reg'] === $applicant['registration_no']) {
        $is_authenticated = true;
    } elseif (!empty($token) && !empty($applicant['qr_token']) && hash_equals($applicant['qr_token'], $token)) {
        $is_authenticated = true;
        $_SESSION['ppdb_verified_reg'] = $applicant['registration_no'];
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'auth_cbt') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $auth_error = "Sesi keamanan Anda telah berakhir. Silakan muat ulang halaman.";
        } else {
            $fail_key = 'cbt_auth_fails_' . $applicant['id'];
            $fails = (int)($_SESSION[$fail_key] ?? 0);
            if ($fails >= 5) {
                $auth_error = "Terlalu banyak percobaan gagal. Silakan tunggu beberapa saat atau hubungi panitia PPDB.";
            } else {
                $birth_date_input = trim($_POST['birth_date'] ?? '');
                if (!empty($birth_date_input) && $birth_date_input === $applicant['birth_date']) {
                    $is_authenticated = true;
                    $_SESSION['ppdb_verified_reg'] = $applicant['registration_no'];
                    unset($_SESSION[$fail_key]);
                } else {
                    $_SESSION[$fail_key] = $fails + 1;
                    $rem = max(0, 5 - ($fails + 1));
                    $auth_error = "Tanggal lahir yang dimasukkan tidak cocok dengan berkas pendaftaran. Sisa kesempatan: {$rem} kali.";
                }
            }
        }
    }
}

// 3. Ambil Paket Ujian Seleksi PPDB yang Aktif
$exam = null;
$questions = [];
if ($applicant) {
    $stmt_ex = $pdo->prepare("SELECT * FROM exams WHERE is_ppdb = 1 AND status = 'aktif' ORDER BY id DESC LIMIT 1");
    $stmt_ex->execute();
    $exam = $stmt_ex->fetch();

    if (!$exam) {
        $error_msg = "Saat ini belum ada paket Ujian CBT Seleksi PPDB yang aktif dari pihak panitia sekolah.";
    } else {
        $stmt_q = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY id ASC");
        $stmt_q->execute([$exam['id']]);
        $questions = $stmt_q->fetchAll();

        if (empty($questions)) {
            $error_msg = "Bank soal untuk paket ujian seleksi ini belum diisi oleh panitia.";
        }
    }
}

// -------------------------------------------------------------
// 4. AJAX ENDPOINT: REAL-TIME AUTO-SAVE JAWABAN & RAGU-RAGU (ANTI-MATI LAMPU)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_single_answer' && $applicant && $exam) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$is_authenticated) {
        echo json_encode(['success' => false, 'error' => 'Akses otentikasi tidak sah.']);
        exit;
    }
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Sesi keamanan berakhir. Muat ulang halaman.']);
        exit;
    }

    $qid = (int)($_POST['question_id'] ?? 0);
    $answer = trim($_POST['answer'] ?? '');
    $is_doubt = (int)($_POST['is_doubt'] ?? 0);

    $current_answers = !empty($applicant['cbt_answers']) ? json_decode($applicant['cbt_answers'], true) : [];
    if (!is_array($current_answers)) $current_answers = [];

    $current_doubts = !empty($applicant['cbt_doubt_answers']) ? json_decode($applicant['cbt_doubt_answers'], true) : [];
    if (!is_array($current_doubts)) $current_doubts = [];

    if ($answer !== '') {
        $current_answers[$qid] = strtoupper($answer);
    } else {
        unset($current_answers[$qid]);
    }

    if ($is_doubt === 1) {
        if (!in_array($qid, $current_doubts, true)) {
            $current_doubts[] = $qid;
        }
    } else {
        $current_doubts = array_values(array_diff($current_doubts, [$qid]));
    }

    $ans_json = json_encode($current_answers, JSON_UNESCAPED_UNICODE);
    $doubts_json = json_encode($current_doubts, JSON_UNESCAPED_UNICODE);

    try {
        $stmt_save = $pdo->prepare("UPDATE ppdb_registrations SET cbt_answers = ?, cbt_doubt_answers = ?, cbt_status = 'sedang_mengerjakan' WHERE id = ?");
        $stmt_save->execute([$ans_json, $doubts_json, $applicant['id']]);

        echo json_encode([
            'success'        => true,
            'qid'            => $qid,
            'answer'         => $answer,
            'is_doubt'       => $is_doubt,
            'total_answered' => count($current_answers),
            'total_doubts'   => count($current_doubts),
            'answered_qids'  => array_keys($current_answers),
            'doubt_qids'     => $current_doubts
        ]);
    } catch (Exception $e) {
        error_log("PPDB CBT save answer error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Gagal menyimpan jawaban. Terjadi kesalahan pada server.']);
    }
    exit;
}

// -------------------------------------------------------------
// 5. TIMER SINKRONISASI SERVER & SIKLUS PENGERJAAN
// -------------------------------------------------------------
$duration_minutes = (int)($exam['duration_minutes'] ?? 45);
$duration_seconds = max(300, $duration_minutes * 60);
$remaining_seconds = $duration_seconds;

if ($applicant && $is_authenticated && $exam) {
    if (empty($applicant['cbt_start_time'])) {
        $now_str = date('Y-m-d H:i:s');
        $stmt_start = $pdo->prepare("UPDATE ppdb_registrations SET cbt_start_time = ?, cbt_status = 'sedang_mengerjakan' WHERE id = ?");
        $stmt_start->execute([$now_str, $applicant['id']]);
        $applicant['cbt_start_time'] = $now_str;
    }
    $start_ts = strtotime($applicant['cbt_start_time']);
    $elapsed = max(0, time() - $start_ts);
    $remaining_seconds = max(0, $duration_seconds - $elapsed);
}

// Ambil jawaban yang tersimpan sebelumnya (Restorasi jika mati lampu/refresh)
$saved_answers = !empty($applicant['cbt_answers']) ? json_decode($applicant['cbt_answers'], true) : [];
if (!is_array($saved_answers)) $saved_answers = [];

$saved_doubts = !empty($applicant['cbt_doubt_answers']) ? json_decode($applicant['cbt_doubt_answers'], true) : [];
if (!is_array($saved_doubts)) $saved_doubts = [];

// -------------------------------------------------------------
// 6. FINALISASI & PENGUMPULAN JAWABAN (SUBMIT EXAM)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_cbt' && $applicant && $exam && !empty($questions)) {
    if (!$is_authenticated) {
        $error_msg = "Akses tidak diizinkan. Silakan verifikasi identitas pendaftar terlebih dahulu.";
    } elseif (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_msg = "Sesi keamanan telah berakhir. Silakan muat ulang halaman.";
    } else {
        // Gabungkan jawaban dari form submit dengan auto-save yang sudah tersimpan di database
        $user_answers = $_POST['answers'] ?? [];
        if (!is_array($user_answers)) $user_answers = [];

        // Prioritaskan jawaban terkini
        $final_answers_map = array_replace($saved_answers, $user_answers);

        $total_questions = count($questions);
        $total_correct = 0;
        $earned_score = 0.0;
        $max_possible_score = 0.0;

        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            $max_score = (float)($q['max_score'] ?: 10.0);
            $max_possible_score += $max_score;

            $chosen = trim($final_answers_map[$qid] ?? '');
            if (!empty($chosen) && strtoupper($chosen) === strtoupper(trim($q['correct_answer'] ?? ''))) {
                $total_correct++;
                $earned_score += $max_score;
            }
        }

        // Normalisasi ke skala 100
        $final_cbt_score = ($max_possible_score > 0) ? round(($earned_score / $max_possible_score) * 100, 2) : 0.0;

        // Bobot Seleksi Masuk: 40% Nilai Rapor + 60% Nilai CBT Seleksi
        $calc_base = (float)($applicant['calculated_score'] ?: 75.0);
        $new_selection_score = round(($calc_base * 0.40) + ($final_cbt_score * 0.60), 2);

        $final_ans_json = json_encode($final_answers_map, JSON_UNESCAPED_UNICODE);

        // Update ke database
        $stmt_upd = $pdo->prepare("
            UPDATE ppdb_registrations 
            SET cbt_exam_id = ?, cbt_score = ?, selection_score = ?, cbt_answers = ?, cbt_status = 'selesai', status = 'diverifikasi' 
            WHERE id = ?
        ");
        $stmt_upd->execute([$exam['id'], $final_cbt_score, $new_selection_score, $final_ans_json, $applicant['id']]);

        logActivity($pdo, 'PPDB_CBT_SUBMIT', "Pendaftar {$applicant['full_name']} ({$applicant['registration_no']}) menyelesaikan CBT PPDB skor: $final_cbt_score (Benar: $total_correct/$total_questions)");

        // Kirim Notifikasi WhatsApp ke Wali Murid
        if (!empty($applicant['parent_phone'])) {
            $wa_msg = "🎉 *PEMBERITAHUAN SELEKSI CBT PPDB*\n\n"
                    . "Yth. Bpk/Ibu *" . $applicant['parent_name'] . "*,\n"
                    . "Calon peserta didik *" . $applicant['full_name'] . "* (No. Reg: *" . $applicant['registration_no'] . "*) telah berhasil menyelesaikan ujian seleksi:\n"
                    . "• Paket: *" . $exam['title'] . "*\n"
                    . "• Skor CBT: *" . $final_cbt_score . " / 100*\n"
                    . "• Total Benar: *" . $total_correct . " dari " . $total_questions . " soal*\n"
                    . "• Skor Seleksi Gabungan: *" . $new_selection_score . "*\n\n"
                    . "Pengumuman kelulusan resmi dapat dipantau berkala melalui Portal PPDB Online. Terima kasih.";
            sendWhatsAppNotification($pdo, $applicant['parent_phone'], $applicant['parent_name'], $wa_msg, 'ppdb');
        }

        header("Location: ppdb.php?tab=cek&reg=" . urlencode($applicant['registration_no']) . "&cbt_score=" . urlencode((string)$final_cbt_score));
        exit;
    }
}

$page_title = "Ujian CBT Seleksi PPDB - " . ($applicant ? htmlspecialchars($applicant['full_name']) : 'Portal PPDB');
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.6); }
        ::-webkit-scrollbar-thumb { background: rgba(100, 116, 139, 0.4); border-radius: 9999px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(100, 116, 139, 0.7); }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col antialiased selection:bg-indigo-600 selection:text-white select-none">

    <!-- Top Navigation Header -->
    <header class="sticky top-0 z-40 border-b border-white/10 bg-slate-900/90 backdrop-blur-xl px-4 sm:px-6 py-3 flex items-center justify-between shadow-lg">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-tr from-indigo-600 to-purple-600 text-white shadow-md shadow-indigo-600/30">
                <i class="fa-solid fa-graduation-cap text-base"></i>
            </div>
            <div>
                <h1 class="text-sm font-extrabold text-white tracking-tight flex items-center gap-2">
                    <span><?= htmlspecialchars($school_info['school_name'] ?? 'SMA Bina Bangsa') ?></span>
                    <span class="hidden sm:inline-block px-2 py-0.5 rounded-md text-[10px] uppercase font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">CBT PPDB</span>
                </h1>
                <p class="text-[11px] text-slate-400 font-mono">
                    <?= $applicant ? htmlspecialchars($applicant['registration_no']) . ' • ' . htmlspecialchars($applicant['full_name']) : 'Portal Ujian Masuk' ?>
                </p>
            </div>
        </div>

        <?php if ($applicant && $is_authenticated && $exam && !empty($questions)): ?>
            <div class="flex items-center gap-3">
                <!-- Status Auto-Save Indicator -->
                <div id="saveStatusIndicator" class="hidden sm:flex items-center gap-1.5 px-3 py-1 rounded-xl text-[11px] font-semibold text-emerald-400 bg-emerald-500/10 border border-emerald-500/20 transition-all opacity-0">
                    <i class="fa-solid fa-cloud-arrow-up text-xs"></i>
                    <span>Tersimpan</span>
                </div>

                <!-- Server Synced Countdown Timer -->
                <div class="flex items-center gap-2 rounded-2xl border border-indigo-500/30 bg-indigo-950/40 px-3.5 py-1.5 font-mono text-sm text-indigo-300 shadow-inner">
                    <i class="fa-regular fa-clock text-indigo-400"></i>
                    <span id="timerDisplay" class="font-bold tracking-wider">--:--</span>
                </div>

                <!-- Tombol Buka Lembar Nomor Soal (Mobile) -->
                <button type="button" onclick="toggleMobileNavGrid()" class="lg:hidden p-2 rounded-xl bg-white/5 border border-white/10 text-slate-300 hover:text-white" title="Papan Nomor Soal">
                    <i class="fa-solid fa-grip text-base"></i>
                </button>
            </div>
        <?php endif; ?>
    </header>

    <main class="flex-1 w-full max-w-7xl mx-auto p-4 sm:p-6 lg:p-8">

        <?php if (!empty($error_msg)): ?>
            <!-- Error State -->
            <div class="max-w-md mx-auto my-12 rounded-3xl border border-rose-500/30 bg-slate-900/90 p-8 text-center shadow-2xl backdrop-blur space-y-4">
                <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-rose-500/10 text-rose-400 text-3xl border border-rose-500/20">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <h3 class="text-lg font-bold text-white">Tidak Dapat Mengakses Ujian</h3>
                <p class="text-xs text-slate-400 leading-relaxed"><?= htmlspecialchars($error_msg) ?></p>
                <div class="pt-2">
                    <a href="ppdb.php?tab=cek" class="inline-flex items-center gap-2 rounded-xl bg-slate-800 hover:bg-slate-700 px-5 py-2.5 text-xs font-bold text-white transition">
                        <i class="fa-solid fa-arrow-left"></i> Kembali ke Cek Status PPDB
                    </a>
                </div>
            </div>

        <?php elseif (!$is_authenticated && $applicant): ?>
            <!-- Otentikasi Calon Siswa (Verifikasi Tanggal Lahir) -->
            <div class="max-w-md mx-auto my-12 rounded-3xl border border-indigo-500/30 bg-slate-900/90 p-8 shadow-2xl backdrop-blur space-y-6">
                <div class="text-center space-y-2">
                    <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-500/10 text-indigo-400 text-3xl border border-indigo-500/20 mb-1">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <h2 class="text-xl font-black text-white">Verifikasi Identitas Peserta</h2>
                    <p class="text-xs text-slate-400">
                        Untuk keamanan seleksi, silakan konfirmasi tanggal lahir calon peserta didik sesuai data pendaftaran.
                    </p>
                </div>

                <div class="p-4 rounded-2xl bg-slate-950/70 border border-white/5 space-y-1 text-xs">
                    <div class="text-slate-400">Nama Calon Siswa: <strong class="text-white"><?= htmlspecialchars($applicant['full_name']) ?></strong></div>
                    <div class="text-slate-400">Nomor Registrasi: <strong class="text-emerald-400 font-mono"><?= htmlspecialchars($applicant['registration_no']) ?></strong></div>
                    <div class="text-slate-400">Peminatan: <strong class="text-white"><?= htmlspecialchars($applicant['chosen_major']) ?></strong></div>
                </div>

                <?php if (!empty($auth_error)): ?>
                    <div class="p-3.5 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs flex items-center gap-2">
                        <i class="fa-solid fa-circle-exclamation shrink-0"></i>
                        <span><?= htmlspecialchars($auth_error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="ppdb_cbt.php?reg=<?= urlencode($applicant['registration_no']) ?>" class="space-y-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="auth_cbt">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tanggal Lahir Calon Siswa *</label>
                        <input type="date" name="birth_date" required autofocus
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 font-mono">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-indigo-600 hover:bg-indigo-500 py-3 text-xs font-bold text-white shadow-lg shadow-indigo-600/30 transition flex items-center justify-center gap-2 cursor-pointer">
                        <span>Konfirmasi & Mulai Ujian</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </form>
            </div>

        <?php elseif ($applicant && $is_authenticated && $exam && !empty($questions)): ?>
            <!-- ============================================================= -->
            <!-- CBT ENGINE UTAMA: 1 SOAL PER LAYAR + REAL-TIME AUTO SAVE      -->
            <!-- ============================================================= -->
            <form id="cbtExamForm" method="POST" action="ppdb_cbt.php?reg=<?= urlencode($applicant['registration_no']) ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="submit_cbt">
                <input type="hidden" name="registration_no" value="<?= htmlspecialchars($applicant['registration_no']) ?>">

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

                    <!-- KOLOM UTAMA: LEMBAR SOAL AKTIF (8 KOLOM) -->
                    <div class="lg:col-span-8 space-y-5">

                        <!-- Kartu Informasi Ujian & Sesi -->
                        <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-4 sm:p-5 backdrop-blur flex flex-wrap items-center justify-between gap-3 text-xs">
                            <div class="flex items-center gap-2.5">
                                <span class="px-2.5 py-1 rounded-lg bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 font-bold uppercase text-[10px]">
                                    <?= htmlspecialchars($exam['subject']) ?>
                                </span>
                                <h3 class="font-bold text-white text-sm"><?= htmlspecialchars($exam['title']) ?></h3>
                            </div>
                            <div class="flex items-center gap-3 text-slate-400 font-mono">
                                <span><i class="fa-solid fa-list-check text-indigo-400 mr-1"></i><span id="currentQuestionNumDisplay">1</span> / <?= count($questions) ?> Soal</span>
                                <span>•</span>
                                <span><i class="fa-solid fa-bullseye text-emerald-400 mr-1"></i>KKM: <?= (float)$exam['passing_grade'] ?></span>
                            </div>
                        </div>

                        <!-- CONTAINER SELURUH SOAL (Hanya 1 Soal Ditampilkan per Waktu) -->
                        <?php foreach ($questions as $idx => $q): 
                            $qid = (int)$q['id'];
                            $saved_val = $saved_answers[$qid] ?? '';
                            $is_doubt = in_array($qid, $saved_doubts, true);
                        ?>
                            <div id="questionCard_<?= $idx ?>" class="question-slide <?= $idx === 0 ? '' : 'hidden' ?> rounded-3xl border border-white/10 bg-slate-900/80 p-6 sm:p-8 shadow-2xl backdrop-blur space-y-6" data-qindex="<?= $idx ?>" data-qid="<?= $qid ?>">
                                
                                <div class="flex items-center justify-between border-b border-white/5 pb-4">
                                    <div class="flex items-center gap-3">
                                        <span class="flex h-9 w-9 items-center justify-center rounded-2xl bg-indigo-600 text-white font-extrabold text-sm shadow-md shadow-indigo-600/30 font-mono">
                                            <?= $idx + 1 ?>
                                        </span>
                                        <div>
                                            <span class="text-xs uppercase font-extrabold tracking-wider text-indigo-400 font-mono block">
                                                Pilihan Ganda
                                            </span>
                                            <span class="text-[11px] text-slate-400 font-mono">Bobot: <?= (float)$q['max_score'] ?> Poin</span>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <span id="badgeDoubt_<?= $qid ?>" class="<?= $is_doubt ? '' : 'hidden' ?> px-2.5 py-0.5 rounded-md text-[10px] font-extrabold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                            <i class="fa-solid fa-triangle-exclamation mr-1"></i> Ragu-Ragu
                                        </span>
                                        <span id="badgeAnswered_<?= $qid ?>" class="<?= !empty($saved_val) ? '' : 'hidden' ?> px-2.5 py-0.5 rounded-md text-[10px] font-extrabold uppercase bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                            <i class="fa-solid fa-circle-check mr-1"></i> Terjawab: <span id="badgeAnswerVal_<?= $qid ?>"><?= htmlspecialchars($saved_val) ?></span>
                                        </span>
                                    </div>
                                </div>

                                <!-- Teks Butir Soal -->
                                <div class="text-base text-slate-100 leading-relaxed font-normal">
                                    <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                                </div>

                                <!-- Audio Soal (Jika Tersedia) -->
                                <?php if (!empty($q['audio_url'])): ?>
                                    <div class="p-3 rounded-2xl border border-indigo-500/30 bg-indigo-500/10 max-w-lg">
                                        <span class="text-xs font-bold text-indigo-300 block mb-1.5"><i class="fa-solid fa-volume-high mr-1"></i> Audio Soal (Listening)</span>
                                        <audio controls class="w-full h-8" preload="metadata">
                                            <source src="../<?= htmlspecialchars($q['audio_url']) ?>">
                                            Browser Anda tidak mendukung pemutar audio.
                                        </audio>
                                    </div>
                                <?php endif; ?>

                                <!-- Gambar Soal (Jika Tersedia) -->
                                <?php if (!empty($q['image_url'])): ?>
                                    <div class="rounded-2xl overflow-hidden border border-white/10 max-w-lg bg-slate-950/80 p-2">
                                        <img src="../<?= htmlspecialchars($q['image_url']) ?>" alt="Ilustrasi Soal" class="w-full h-auto object-contain rounded-xl">
                                    </div>
                                <?php endif; ?>

                                <!-- Opsi Jawaban (A, B, C, D, E) -->
                                <div class="grid grid-cols-1 gap-3 pt-2">
                                    <?php foreach (['A', 'B', 'C', 'D', 'E'] as $opt): 
                                        $opt_field = 'option_' . strtolower($opt);
                                        if (empty($q[$opt_field])) continue;
                                        $input_id = "opt_{$qid}_{$opt}";
                                        $is_checked = (strtoupper((string)$saved_val) === $opt);
                                    ?>
                                        <div class="relative group">
                                            <input type="radio" 
                                                   name="answers[<?= $qid ?>]" 
                                                   id="<?= $input_id ?>" 
                                                   value="<?= $opt ?>" 
                                                   <?= $is_checked ? 'checked' : '' ?>
                                                   onchange="saveAnswerLive(<?= $qid ?>, '<?= $opt ?>', <?= $idx ?>)"
                                                   class="peer hidden">
                                            <label for="<?= $input_id ?>" 
                                                   class="flex items-start sm:items-center gap-3.5 p-4 rounded-2xl border border-white/10 bg-slate-950/60 hover:bg-white/5 peer-checked:border-indigo-500 peer-checked:bg-indigo-600/15 peer-checked:text-white transition cursor-pointer text-sm">
                                                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-white/5 border border-white/10 text-xs font-black text-slate-300 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:border-indigo-500 shrink-0 font-mono transition">
                                                    <?= $opt ?>
                                                </span>
                                                <span class="text-slate-200 peer-checked:text-white font-medium leading-relaxed">
                                                    <?= htmlspecialchars($q[$opt_field]) ?>
                                                </span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- Tombol Navigasi Bawah (Sebelumnya, Ragu-ragu, Selanjutnya) -->
                        <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-4 sm:p-5 flex flex-wrap items-center justify-between gap-3 shadow-xl backdrop-blur">
                            <button type="button" id="btnPrev" onclick="navigateQuestion(-1)" 
                                    class="px-5 py-2.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-xs font-bold text-slate-300 hover:text-white transition flex items-center gap-2 cursor-pointer disabled:opacity-30 disabled:pointer-events-none">
                                <i class="fa-solid fa-arrow-left"></i> Soal Sebelumnya
                            </button>

                            <button type="button" id="btnDoubt" onclick="toggleDoubtLive()" 
                                    class="px-5 py-2.5 rounded-xl border border-amber-500/40 bg-amber-500/10 hover:bg-amber-500/20 text-xs font-bold text-amber-300 transition flex items-center gap-2 cursor-pointer">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <span id="doubtBtnText">Tandai Ragu-Ragu</span>
                            </button>

                            <button type="button" id="btnNext" onclick="navigateQuestion(1)" 
                                    class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-xs font-bold text-white shadow-lg shadow-indigo-600/30 transition flex items-center gap-2 cursor-pointer">
                                <span>Soal Selanjutnya</span> <i class="fa-solid fa-arrow-right"></i>
                            </button>

                            <button type="button" id="btnFinishPrompt" onclick="openFinishModal()" 
                                    class="hidden px-6 py-2.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-xs font-extrabold text-white shadow-lg shadow-emerald-600/30 transition flex items-center gap-2 cursor-pointer">
                                <i class="fa-solid fa-circle-check"></i> Selesaikan Ujian
                            </button>
                        </div>
                    </div>

                    <!-- KOLOM KANAN: PAPAN NOMOR SOAL & STATUS RINGKASAN (4 KOLOM) -->
                    <div id="panelNavGrid" class="lg:col-span-4 space-y-5">
                        
                        <!-- Panel Rekapitulasi & Papan Grid Nomor Soal -->
                        <div class="rounded-3xl border border-white/10 bg-slate-900/80 p-5 sm:p-6 shadow-2xl backdrop-blur sticky top-20 space-y-4">
                            <div class="flex items-center justify-between border-b border-white/10 pb-3">
                                <h4 class="font-extrabold text-white text-xs uppercase tracking-wider flex items-center gap-2">
                                    <i class="fa-solid fa-grip text-indigo-400"></i>
                                    <span>Papan Nomor Soal</span>
                                </h4>
                                <span class="text-[11px] font-mono text-slate-400">Total: <?= count($questions) ?></span>
                            </div>

                            <!-- Statistik Status Jawaban -->
                            <div class="grid grid-cols-3 gap-2 text-center text-[10px]">
                                <div class="p-2 rounded-xl bg-slate-950/70 border border-white/5">
                                    <span class="text-slate-400 block">Terjawab</span>
                                    <strong id="statAnsweredCount" class="text-emerald-400 font-mono text-sm mt-0.5 block"><?= count($saved_answers) ?></strong>
                                </div>
                                <div class="p-2 rounded-xl bg-slate-950/70 border border-white/5">
                                    <span class="text-slate-400 block">Ragu-Ragu</span>
                                    <strong id="statDoubtCount" class="text-amber-400 font-mono text-sm mt-0.5 block"><?= count($saved_doubts) ?></strong>
                                </div>
                                <div class="p-2 rounded-xl bg-slate-950/70 border border-white/5">
                                    <span class="text-slate-400 block">Belum</span>
                                    <strong id="statUnansweredCount" class="text-slate-300 font-mono text-sm mt-0.5 block"><?= max(0, count($questions) - count($saved_answers)) ?></strong>
                                </div>
                            </div>

                            <!-- Grid Nomor Soal 1 s.d N -->
                            <div class="grid grid-cols-5 gap-2 max-h-72 overflow-y-auto pr-1">
                                <?php foreach ($questions as $idx => $q): 
                                    $qid = (int)$q['id'];
                                    $is_ans = !empty($saved_answers[$qid]);
                                    $is_dbt = in_array($qid, $saved_doubts, true);
                                    
                                    $btn_class = "border-white/10 bg-slate-950/80 text-slate-300 hover:border-white/30";
                                    if ($is_dbt) {
                                        $btn_class = "border-amber-500/50 bg-amber-500/20 text-amber-300 font-bold";
                                    } elseif ($is_ans) {
                                        $btn_class = "border-emerald-500/50 bg-emerald-500/20 text-emerald-300 font-bold";
                                    }
                                ?>
                                    <button type="button" 
                                            id="navGridBtn_<?= $idx ?>" 
                                            onclick="jumpToQuestion(<?= $idx ?>)"
                                            class="grid-item-btn flex flex-col items-center justify-center p-2 rounded-xl border text-xs font-mono transition cursor-pointer <?= $btn_class ?> <?= $idx === 0 ? 'ring-2 ring-indigo-400' : '' ?>">
                                        <span class="font-bold text-xs"><?= $idx + 1 ?></span>
                                        <span id="gridAnsLabel_<?= $idx ?>" class="text-[9px] uppercase opacity-80"><?= $is_ans ? htmlspecialchars($saved_answers[$qid]) : '-' ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <!-- Tombol Kumpulkan Ujian -->
                            <div class="pt-2 border-t border-white/10">
                                <button type="button" onclick="openFinishModal()" 
                                        class="w-full py-3 rounded-2xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-extrabold text-xs shadow-xl shadow-emerald-600/20 transition flex items-center justify-center gap-2 cursor-pointer">
                                    <i class="fa-solid fa-paper-plane"></i>
                                    <span>Kumpulkan & Selesai Ujian</span>
                                </button>
                                <span class="block text-[10px] text-slate-500 text-center mt-2">
                                    Jawaban otomatis disimpan ke server secara langsung.
                                </span>
                            </div>
                        </div>

                    </div>
                </div>
            </form>

            <!-- MODAL KONFIRMASI PENGUMPULAN UJIAN (REKAP STATUS) -->
            <div id="modalFinish" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                <div class="rounded-3xl border border-white/15 bg-slate-900 p-6 sm:p-8 max-w-md w-full shadow-2xl text-center space-y-5">
                    <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-500/20 text-emerald-400 text-3xl border border-emerald-500/30">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>

                    <div>
                        <h3 class="text-xl font-black text-white">Konfirmasi Pengumpulan Ujian</h3>
                        <p class="text-xs text-slate-300 mt-1 leading-relaxed">
                            Apakah Anda yakin ingin menyelesaikan dan mengumpulkan lembar jawaban ujian CBT ini?
                        </p>
                    </div>

                    <!-- Rekap Pengerjaan di Modal -->
                    <div class="grid grid-cols-3 gap-2.5 p-3 rounded-2xl bg-slate-950 border border-white/10 text-xs text-center font-mono">
                        <div>
                            <span class="text-slate-400 text-[10px] block">Terjawab</span>
                            <strong id="modalRecapAnswered" class="text-emerald-400 font-bold text-base"><?= count($saved_answers) ?></strong>
                        </div>
                        <div>
                            <span class="text-slate-400 text-[10px] block">Ragu-Ragu</span>
                            <strong id="modalRecapDoubt" class="text-amber-400 font-bold text-base"><?= count($saved_doubts) ?></strong>
                        </div>
                        <div>
                            <span class="text-slate-400 text-[10px] block">Belum Diisi</span>
                            <strong id="modalRecapUnanswered" class="text-rose-400 font-bold text-base"><?= max(0, count($questions) - count($saved_answers)) ?></strong>
                        </div>
                    </div>

                    <div id="unansweredWarningAlert" class="hidden p-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300 text-xs text-left flex items-start gap-2">
                        <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0"></i>
                        <span>Perhatian: Masih terdapat soal yang belum dijawab atau bertanda ragu-ragu.</span>
                    </div>

                    <div class="flex items-center justify-center gap-3 pt-2">
                        <button type="button" onclick="closeFinishModal()" 
                                class="px-5 py-2.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-xs font-semibold text-slate-300 transition cursor-pointer">
                            Periksa Kembali
                        </button>
                        <button type="button" onclick="submitFinalExam()" 
                                class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-extrabold transition shadow-lg shadow-emerald-600/30 flex items-center gap-1.5 cursor-pointer">
                            <i class="fa-solid fa-check-double"></i> Ya, Kumpulkan Sekarang
                        </button>
                    </div>
                </div>
            </div>

            <!-- MODAL PERINGATAN KECURANGAN (LOCKDOWN / TAB SWITCH DETECTOR) -->
            <div id="modalCheatingWarning" class="fixed inset-0 z-50 hidden bg-rose-950/90 backdrop-blur-md flex items-center justify-center p-4">
                <div class="rounded-3xl border border-rose-500/40 bg-slate-900 p-6 sm:p-8 max-w-md w-full shadow-2xl text-center space-y-4">
                    <div class="inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-rose-500/20 text-rose-400 text-3xl border border-rose-500/30">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <h3 class="text-lg font-black text-rose-300">Peringatan: Aktivitas Mencurigakan!</h3>
                    <p class="text-xs text-slate-300 leading-relaxed">
                        Sistem mendeteksi Anda berpindah jendela / tab browser atau membuka aplikasi lain. Aktivitas ini dicatat dalam log pengawasan panitia ujian.
                    </p>
                    <div class="p-3 rounded-xl bg-slate-950 border border-white/10 text-xs font-mono">
                        Jumlah Pelanggaran: <strong id="tabSwitchCountDisplay" class="text-rose-400 font-bold">1</strong> / 3 Toleransi
                    </div>
                    <button type="button" onclick="dismissWarningModal()" class="w-full rounded-xl bg-rose-600 hover:bg-rose-500 py-2.5 text-xs font-bold text-white transition cursor-pointer">
                        Saya Mengerti & Kembali Mengerjakan
                    </button>
                </div>
            </div>

            <script>
                // DATA KREDENSIAL & SINKRONISASI
                const TOTAL_QUESTIONS = <?= count($questions) ?>;
                const CSRF_TOKEN = '<?= generateCsrfToken() ?>';
                const REG_NO = '<?= addslashes($applicant['registration_no']) ?>';
                let currentQuestionIndex = 0;
                let remainingSeconds = <?= (int)$remaining_seconds ?>;
                let isExamSubmitted = false;

                // State Jawaban & Ragu-Ragu di Client
                let clientAnswers = <?= json_encode($saved_answers) ?> || {};
                let clientDoubts = <?= json_encode($saved_doubts) ?> || [];
                let tabSwitchViolations = 0;

                // 1. NAVIGASI SOAL SATU PER SATU
                function showQuestion(index) {
                    if (index < 0 || index >= TOTAL_QUESTIONS) return;

                    // Sembunyikan semua soal
                    document.querySelectorAll('.question-slide').forEach(card => card.classList.add('hidden'));

                    // Tampilkan soal aktif
                    const activeCard = document.getElementById('questionCard_' + index);
                    if (activeCard) {
                        activeCard.classList.remove('hidden');
                    }

                    // Update tombol nomor soal aktif di grid
                    document.querySelectorAll('.grid-item-btn').forEach(btn => btn.classList.remove('ring-2', 'ring-indigo-400'));
                    const gridBtn = document.getElementById('navGridBtn_' + index);
                    if (gridBtn) {
                        gridBtn.classList.add('ring-2', 'ring-indigo-400');
                    }

                    currentQuestionIndex = index;
                    document.getElementById('currentQuestionNumDisplay').textContent = index + 1;

                    // Update Status Tombol Prev & Next
                    document.getElementById('btnPrev').disabled = (index === 0);
                    if (index === TOTAL_QUESTIONS - 1) {
                        document.getElementById('btnNext').classList.add('hidden');
                        document.getElementById('btnFinishPrompt').classList.remove('hidden');
                    } else {
                        document.getElementById('btnNext').classList.remove('hidden');
                        document.getElementById('btnFinishPrompt').classList.add('hidden');
                    }

                    // Update status tombol ragu-ragu di soal ini
                    const qid = parseInt(activeCard.getAttribute('data-qid'));
                    updateDoubtButtonState(qid);
                }

                function navigateQuestion(offset) {
                    showQuestion(currentQuestionIndex + offset);
                }

                function jumpToQuestion(index) {
                    showQuestion(index);
                    // Tutup modal mobile grid jika terbuka
                    if (window.innerWidth < 1024) {
                        const panel = document.getElementById('panelNavGrid');
                        if (panel.classList.contains('mobile-nav-open')) {
                            toggleMobileNavGrid();
                        }
                    }
                }

                // 2. AJAX REAL-TIME AUTO-SAVE
                function saveAnswerLive(qid, selectedOpt, qIndex) {
                    clientAnswers[qid] = selectedOpt;

                    // Tampilkan feedback visual instan di UI
                    showSaveIndicator();
                    updateBadgeAnswer(qid, selectedOpt);
                    updateGridButtonState(qIndex, qid);

                    // Kirim ke server via background fetch
                    const formData = new FormData();
                    formData.append('action', 'save_single_answer');
                    formData.append('csrf_token', CSRF_TOKEN);
                    formData.append('registration_no', REG_NO);
                    formData.append('question_id', qid);
                    formData.append('answer', selectedOpt);
                    formData.append('is_doubt', clientDoubts.includes(qid) ? 1 : 0);

                    fetch('ppdb_cbt.php?reg=' + encodeURIComponent(REG_NO), {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            updateStatsDisplay(data.total_answered, data.total_doubts);
                        }
                    })
                    .catch(err => console.warn('Auto-save background sync notice:', err));
                }

                function toggleDoubtLive() {
                    const activeCard = document.getElementById('questionCard_' + currentQuestionIndex);
                    if (!activeCard) return;
                    const qid = parseInt(activeCard.getAttribute('data-qid'));

                    let isNowDoubt = false;
                    const doubtIndex = clientDoubts.indexOf(qid);
                    if (doubtIndex > -1) {
                        clientDoubts.splice(doubtIndex, 1);
                        isNowDoubt = false;
                    } else {
                        clientDoubts.push(qid);
                        isNowDoubt = true;
                    }

                    updateDoubtButtonState(qid);
                    updateGridButtonState(currentQuestionIndex, qid);

                    const badgeDoubt = document.getElementById('badgeDoubt_' + qid);
                    if (badgeDoubt) {
                        if (isNowDoubt) badgeDoubt.classList.remove('hidden');
                        else badgeDoubt.classList.add('hidden');
                    }

                    // Simpan status ragu-ragu ke server
                    const formData = new FormData();
                    formData.append('action', 'save_single_answer');
                    formData.append('csrf_token', CSRF_TOKEN);
                    formData.append('registration_no', REG_NO);
                    formData.append('question_id', qid);
                    formData.append('answer', clientAnswers[qid] || '');
                    formData.append('is_doubt', isNowDoubt ? 1 : 0);

                    fetch('ppdb_cbt.php?reg=' + encodeURIComponent(REG_NO), {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            updateStatsDisplay(data.total_answered, data.total_doubts);
                        }
                    });
                }

                function updateDoubtButtonState(qid) {
                    const btn = document.getElementById('btnDoubt');
                    const textSpan = document.getElementById('doubtBtnText');
                    if (clientDoubts.includes(qid)) {
                        btn.classList.add('bg-amber-500', 'text-slate-950', 'border-amber-400');
                        btn.classList.remove('bg-amber-500/10', 'text-amber-300', 'border-amber-500/40');
                        textSpan.textContent = 'Lepas Tanda Ragu';
                    } else {
                        btn.classList.remove('bg-amber-500', 'text-slate-950', 'border-amber-400');
                        btn.classList.add('bg-amber-500/10', 'text-amber-300', 'border-amber-500/40');
                        textSpan.textContent = 'Tandai Ragu-Ragu';
                    }
                }

                function updateBadgeAnswer(qid, opt) {
                    const badge = document.getElementById('badgeAnswered_' + qid);
                    const valSpan = document.getElementById('badgeAnswerVal_' + qid);
                    if (badge && valSpan) {
                        valSpan.textContent = opt;
                        badge.classList.remove('hidden');
                    }
                }

                function updateGridButtonState(index, qid) {
                    const btn = document.getElementById('navGridBtn_' + index);
                    const ansLabel = document.getElementById('gridAnsLabel_' + index);
                    if (!btn) return;

                    const isDoubt = clientDoubts.includes(qid);
                    const hasAns = !!clientAnswers[qid];

                    btn.classList.remove('border-white/10', 'bg-slate-950/80', 'text-slate-300', 'border-amber-500/50', 'bg-amber-500/20', 'text-amber-300', 'border-emerald-500/50', 'bg-emerald-500/20', 'text-emerald-300');

                    if (isDoubt) {
                        btn.classList.add('border-amber-500/50', 'bg-amber-500/20', 'text-amber-300', 'font-bold');
                    } else if (hasAns) {
                        btn.classList.add('border-emerald-500/50', 'bg-emerald-500/20', 'text-emerald-300', 'font-bold');
                    } else {
                        btn.classList.add('border-white/10', 'bg-slate-950/80', 'text-slate-300');
                    }

                    if (ansLabel) {
                        ansLabel.textContent = hasAns ? clientAnswers[qid] : '-';
                    }
                }

                function updateStatsDisplay(ansCount, doubtCount) {
                    document.getElementById('statAnsweredCount').textContent = ansCount;
                    document.getElementById('statDoubtCount').textContent = doubtCount;
                    document.getElementById('statUnansweredCount').textContent = Math.max(0, TOTAL_QUESTIONS - ansCount);
                }

                function showSaveIndicator() {
                    const ind = document.getElementById('saveStatusIndicator');
                    if (ind) {
                        ind.style.opacity = '1';
                        setTimeout(() => { ind.style.opacity = '0'; }, 1800);
                    }
                }

                // 3. SERVER-SYNCED COUNTDOWN TIMER
                function startCountdownTimer() {
                    function tick() {
                        if (isExamSubmitted) return;

                        const mins = Math.floor(remainingSeconds / 60);
                        const secs = remainingSeconds % 60;
                        const display = document.getElementById('timerDisplay');
                        if (display) {
                            display.textContent = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
                            if (remainingSeconds <= 300) { // 5 menit terakhir
                                display.parentElement.classList.remove('text-indigo-300', 'border-indigo-500/30', 'bg-indigo-950/40');
                                display.parentElement.classList.add('text-rose-400', 'border-rose-500/40', 'bg-rose-950/50', 'animate-pulse');
                            }
                        }

                        if (remainingSeconds <= 0) {
                            isExamSubmitted = true;
                            alert('Waktu ujian seleksi telah habis! Seluruh jawaban Anda otomatis dikumpulkan ke server.');
                            document.getElementById('cbtExamForm').submit();
                        } else {
                            remainingSeconds--;
                            setTimeout(tick, 1000);
                        }
                    }
                    tick();
                }

                // 4. MODAL KONFIRMASI PENGUMPULAN
                function openFinishModal() {
                    const answered = Object.keys(clientAnswers).length;
                    const doubts = clientDoubts.length;
                    const unanswered = Math.max(0, TOTAL_QUESTIONS - answered);

                    document.getElementById('modalRecapAnswered').textContent = answered;
                    document.getElementById('modalRecapDoubt').textContent = doubts;
                    document.getElementById('modalRecapUnanswered').textContent = unanswered;

                    const warnAlert = document.getElementById('unansweredWarningAlert');
                    if (unanswered > 0 || doubts > 0) {
                        warnAlert.classList.remove('hidden');
                    } else {
                        warnAlert.classList.add('hidden');
                    }

                    document.getElementById('modalFinish').classList.remove('hidden');
                }

                function closeFinishModal() {
                    document.getElementById('modalFinish').classList.add('hidden');
                }

                function submitFinalExam() {
                    isExamSubmitted = true;
                    document.getElementById('cbtExamForm').submit();
                }

                // 5. DETEKSI KECURANGAN / PINDAH TAB (LOCKDOWN WARNING)
                window.addEventListener('blur', () => {
                    if (isExamSubmitted) return;
                    tabSwitchViolations++;
                    document.getElementById('tabSwitchCountDisplay').textContent = tabSwitchViolations;
                    document.getElementById('modalCheatingWarning').classList.remove('hidden');
                    
                    if (tabSwitchViolations >= 3) {
                        alert('Batas toleransi berpindah jendela telah habis (3 kali). Ujian Anda otomatis dikumpulkan demi integritas seleksi.');
                        submitFinalExam();
                    }
                });

                function dismissWarningModal() {
                    document.getElementById('modalCheatingWarning').classList.add('hidden');
                }

                function toggleMobileNavGrid() {
                    const panel = document.getElementById('panelNavGrid');
                    panel.classList.toggle('hidden');
                }

                // Inisialisasi Tampilan Awal
                window.addEventListener('DOMContentLoaded', () => {
                    showQuestion(0);
                    startCountdownTimer();
                });
            </script>
        <?php endif; ?>

    </main>

</body>
</html>
