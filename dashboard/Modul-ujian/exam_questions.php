<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['guru', 'administrator'], true);

if (!$can_manage) {
    header("Location: exams.php?error=unauthorized");
    exit;
}

$exam_id = (int) ($_GET['id'] ?? 0);
if ($exam_id <= 0) {
    header("Location: exams.php");
    exit;
}

// Ambil data ujian (-01 fix: parameterized query)
$exam_sql = "
    SELECT e.*, u.name as teacher_name 
    FROM exams e 
    JOIN users u ON e.teacher_id = u.id 
    WHERE e.id = ?";
$exam_params = [$exam_id];
if ($user_role === 'guru') {
    $exam_sql .= " AND e.teacher_id = ?";
    $exam_params[] = $user_id;
}
$stmt_e = $pdo->prepare($exam_sql);
$stmt_e->execute($exam_params);
$exam = $stmt_e->fetch();

if (!$exam) {
    header("Location: exams.php?error=notfound");
    exit;
}

$message = "";
$message_type = "";

if (isset($_GET['created'])) {
    $message = "Paket ujian berhasil dibuat! Silakan tambahkan butir soal di bawah ini.";
    $message_type = "success";
}

// -------------------------------------------------------------
// DOWNLOAD FORMAT TEMPLATE CSV
// -------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $clean_title = preg_replace('/[^A-Za-z0-9_\-]/', '_', $exam['title']);
    $filename = "Template_Soal_" . substr($clean_title, 0, 20) . ".csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    // Header Kolom Lengkap SD, SMP, SMA/SMK & AKM
    fputcsv($output, ['question_type', 'question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'option_e', 'correct_answer', 'max_score', 'explanation', 'image_url', 'audio_url']);

    // Contoh Soal 1: Pilihan Ganda SMA (Opsi A - E)
    fputcsv($output, [
        'multiple_choice',
        'Organ tubuh manusia yang berfungsi memompa darah ke seluruh tubuh adalah...',
        'Paru-paru',
        'Jantung',
        'Hati',
        'Ginjal',
        'Lambung',
        'B',
        '10',
        'Jantung adalah organ muskular berongga yang berfungsi memompa darah ke seluruh tubuh.',
        '',
        ''
    ]);

    // Contoh Soal 2: Pilihan Ganda Kompleks (AKM Centang >1 Jawaban Benar)
    fputcsv($output, [
        'multiple_choice_complex',
        'Berikut ini yang merupakan contoh sumber energi terbarukan ramah lingkungan adalah...',
        'Energi Surya (Matahari)',
        'Batu Bara',
        'Energi Angin (Bayu)',
        'Minyak Bumi Mentah',
        'Energi Panas Bumi (Geotermal)',
        'A,C,E',
        '10',
        'Matahari, angin, dan panas bumi adalah sumber energi bersih yang dapat diperbarui secara alami.',
        '',
        ''
    ]);

    // Contoh Soal 3: Benar / Salah (Logika Evaluatif)
    fputcsv($output, [
        'true_false',
        'Indonesia memproklamasikan kemerdekaannya pada tanggal 17 Agustus 1945.',
        '',
        '',
        '',
        '',
        '',
        'B',
        '10',
        'Pernyataan ini BENAR, proklamasi dibacakan oleh Ir. Soekarno pada 17 Agustus 1945.',
        '',
        ''
    ]);

    // Contoh Soal 4: Isian Singkat (Auto-scoring kata/angka)
    fputcsv($output, [
        'short_answer',
        'Ibu Kota Nusantara (IKN) terletak di wilayah provinsi...',
        '',
        '',
        '',
        '',
        '',
        'Kalimantan Timur|Kaltim',
        '10',
        'IKN terletak di Kabupaten Penajam Paser Utara dan Kutai Kartanegara, Kalimantan Timur.',
        '',
        ''
    ]);

    // Contoh Soal 5: Esai / Uraian
    fputcsv($output, [
        'essay',
        'Jelaskan 3 langkah mitigasi bencana gempa bumi saat berada di dalam ruangan kelas!',
        '',
        '',
        '',
        '',
        '',
        '',
        '20',
        'Rubrik: 1) Berlindung di bawah meja (Drop, Cover, Hold On), 2) Menjauhi jendela kaca, 3) Evakuasi tenang mengikuti jalur hijau.',
        '',
        ''
    ]);

    fclose($output);
    exit;
}

// -------------------------------------------------------------
// 1. TAMBAH SOAL (MANUAL)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_question') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan muat ulang halaman.";
        $message_type = "error";
    } else {
        $question_type = in_array($_POST['question_type'] ?? '', ['multiple_choice', 'multiple_choice_complex', 'true_false', 'short_answer', 'essay']) ? $_POST['question_type'] : 'multiple_choice';
        $max_score = (float) ($_POST['max_score'] ?? 10.00);
        if ($max_score <= 0) $max_score = 10.00;

        $question_text = trim($_POST['question_text'] ?? '');
        $option_a = trim($_POST['option_a'] ?? '');
        $option_b = trim($_POST['option_b'] ?? '');
        $option_c = trim($_POST['option_c'] ?? '');
        $option_d = trim($_POST['option_d'] ?? '');
        $option_e = trim($_POST['option_e'] ?? '');
        $explanation = trim($_POST['explanation'] ?? '');
        $correct_answer = null;

        $has_error = false;
        if ($question_text === '') {
            $message = "Pertanyaan soal wajib diisi.";
            $message_type = "error";
            $has_error = true;
        } elseif ($question_type === 'multiple_choice') {
            $correct_answer = strtoupper(trim($_POST['correct_answer_mc'] ?? 'A'));
            if (!in_array($correct_answer, ['A', 'B', 'C', 'D', 'E'], true)) {
                $correct_answer = 'A';
            }
            if ($option_a === '' || $option_b === '') {
                $message = "Minimal pilihan jawaban A dan B wajib diisi untuk soal Pilihan Ganda.";
                $message_type = "error";
                $has_error = true;
            }
        } elseif ($question_type === 'multiple_choice_complex') {
            $checked = $_POST['correct_answer_complex'] ?? [];
            if (is_array($checked) && !empty($checked)) {
                $clean_checked = array_map('strtoupper', array_map('trim', $checked));
                sort($clean_checked);
                $correct_answer = implode(',', $clean_checked);
            } else {
                $correct_answer = strtoupper(trim($_POST['correct_answer_complex_text'] ?? 'A'));
            }
            if ($option_a === '' || $option_b === '') {
                $message = "Minimal pilihan jawaban A dan B wajib diisi untuk Pilihan Ganda Kompleks.";
                $message_type = "error";
                $has_error = true;
            }
        } elseif ($question_type === 'true_false') {
            $correct_answer = in_array(strtoupper(trim($_POST['correct_answer_tf'] ?? 'B')), ['B', 'S'], true) ? strtoupper(trim($_POST['correct_answer_tf'])) : 'B';
            $option_a = 'Benar';
            $option_b = 'Salah';
            $option_c = null;
            $option_d = null;
            $option_e = null;
        } elseif ($question_type === 'short_answer') {
            $correct_answer = trim($_POST['correct_answer_short'] ?? '');
            if ($correct_answer === '') {
                $message = "Kunci jawaban isian singkat wajib diisi.";
                $message_type = "error";
                $has_error = true;
            }
            $option_a = null; $option_b = null; $option_c = null; $option_d = null; $option_e = null;
        } else { // essay
            $option_a = null; $option_b = null; $option_c = null; $option_d = null; $option_e = null;
            $correct_answer = null;
        }

        if (!$has_error) {
            $image_url = null;
            $audio_url = null;

            // Validasi & Upload Gambar Soal
            if (!empty($_FILES['question_image']['name']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
                $imgRes = saveSecureUpload(
                    $_FILES['question_image'],
                    'exams',
                    ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                    ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
                    5 * 1024 * 1024,
                    "soal_" . $exam_id
                );
                if ($imgRes['success']) {
                    $image_url = $imgRes['path'];
                } else {
                    $message = "Gagal mengunggah gambar soal: " . $imgRes['error'];
                    $message_type = "error";
                    $has_error = true;
                }
            } elseif (!empty($_POST['image_url'])) {
                $raw_url = trim($_POST['image_url']);
                if (filter_var($raw_url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $raw_url)) {
                    $image_url = $raw_url;
                }
            }

            // Validasi & Upload Audio Listening
            if (!$has_error && !empty($_FILES['question_audio']['name']) && $_FILES['question_audio']['error'] === UPLOAD_ERR_OK) {
                $audioRes = saveSecureUpload(
                    $_FILES['question_audio'],
                    'exams_audio',
                    ['mp3', 'wav', 'ogg', 'm4a', 'aac'],
                    ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/mp4', 'audio/x-m4a', 'audio/aac'],
                    20 * 1024 * 1024,
                    "audio_" . $exam_id
                );
                if ($audioRes['success']) {
                    $audio_url = $audioRes['path'];
                } else {
                    $message = "Gagal mengunggah berkas audio listening: " . $audioRes['error'];
                    $message_type = "error";
                    $has_error = true;
                }
            } elseif (!empty($_POST['audio_url'])) {
                $raw_a_url = trim($_POST['audio_url']);
                if (filter_var($raw_a_url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $raw_a_url)) {
                    $audio_url = $raw_a_url;
                }
            }

            $stmt_ins = $pdo->prepare("
                INSERT INTO exam_questions (exam_id, question_type, max_score, question_text, image_url, audio_url, option_a, option_b, option_c, option_d, option_e, correct_answer, explanation)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_ins->execute([$exam_id, $question_type, $max_score, $question_text, $image_url, $audio_url, $option_a, $option_b, $option_c, $option_d, $option_e, $correct_answer, $explanation]);
            $message = "Butir soal baru (" . getExamQuestionTypeLabel($question_type) . ") berhasil ditambahkan.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 1B. IMPOR SOAL MASSAL VIA FILE CSV
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan muat ulang halaman.";
        $message_type = "error";
    } else {
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $file_path = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file_path, 'r');

            if ($handle !== false) {
                $inserted = 0;
                $row_idx = 0;

                // Deteksi delimiter koma vs titik koma
                $first_line = fgets($handle);
                rewind($handle);
                $delimiter = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';

                $stmt_in_q = $pdo->prepare("
                    INSERT INTO exam_questions (exam_id, question_type, max_score, question_text, image_url, audio_url, option_a, option_b, option_c, option_d, option_e, correct_answer, explanation)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                while (($data = fgetcsv($handle, 4000, $delimiter)) !== false) {
                    $row_idx++;
                    if ($row_idx === 1 && isset($data[0]) && (stripos($data[0], 'question') !== false || stripos($data[0], 'tipe') !== false)) {
                        continue;
                    }

                    $col_count = count($data);
                    if ($col_count >= 10 && in_array(strtolower(trim($data[0])), ['multiple_choice', 'multiple_choice_complex', 'true_false', 'short_answer', 'essay'])) {
                        // Format Baru: question_type, question_text, option_a, option_b, option_c, option_d, option_e, correct_answer, max_score, explanation, image_url, audio_url
                        $q_type = strtolower(trim($data[0]));
                        $q_text = trim($data[1] ?? '');
                        $opt_a = trim($data[2] ?? '');
                        $opt_b = trim($data[3] ?? '');
                        $opt_c = trim($data[4] ?? '');
                        $opt_d = trim($data[5] ?? '');
                        $opt_e = trim($data[6] ?? '');
                        $correct = trim($data[7] ?? 'A');
                        $m_score = !empty($data[8]) ? (float)$data[8] : 10.0;
                        $expl = trim($data[9] ?? '');
                        $img = trim($data[10] ?? '');
                        $aud = trim($data[11] ?? '');
                    } else {
                        // Format Lama: question_text, option_a, option_b, option_c, option_d, correct_answer, explanation, image_url
                        $q_type = 'multiple_choice';
                        $q_text = trim($data[0] ?? '');
                        $opt_a = trim($data[1] ?? '');
                        $opt_b = trim($data[2] ?? '');
                        $opt_c = trim($data[3] ?? '');
                        $opt_d = trim($data[4] ?? '');
                        $opt_e = null;
                        $correct = strtoupper(trim($data[5] ?? 'A'));
                        $m_score = 10.0;
                        $expl = trim($data[6] ?? '');
                        $img = trim($data[7] ?? '');
                        $aud = null;
                    }

                    if ($q_text !== '') {
                        $stmt_in_q->execute([
                            $exam_id,
                            $q_type,
                            $m_score > 0 ? $m_score : 10.0,
                            $q_text,
                            $img !== '' ? $img : null,
                            $aud !== '' ? $aud : null,
                            $opt_a !== '' ? $opt_a : null,
                            $opt_b !== '' ? $opt_b : null,
                            $opt_c !== '' ? $opt_c : null,
                            $opt_d !== '' ? $opt_d : null,
                            $opt_e !== '' ? $opt_e : null,
                            $correct !== '' ? $correct : null,
                            $expl !== '' ? $expl : null
                        ]);
                        $inserted++;
                    }
                }
                fclose($handle);

                if ($inserted > 0) {
                    $message = "Sukses! Berhasil mengimpor $inserted butir soal secara massal dari file CSV.";
                    $message_type = "success";
                } else {
                    $message = "Tidak ada butir soal yang berhasil diimpor. Pastikan file CSV sesuai format template.";
                    $message_type = "error";
                }
            } else {
                $message = "Gagal membuka file CSV yang diunggah.";
                $message_type = "error";
            }
        } else {
            $message = "Silakan pilih berkas file CSV terlebih dahulu.";
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 2. HAPUS SOAL (-02 fix: POST method + CSRF)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_question') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $q_del_id = (int) ($_POST['delete_question_id'] ?? 0);
        $stmt_del = $pdo->prepare("DELETE FROM exam_questions WHERE id = ? AND exam_id = ?");
        $stmt_del->execute([$q_del_id, $exam_id]);
        $message = "Butir soal berhasil dihapus.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 3. DAFTAR SOAL
// -------------------------------------------------------------
$stmt_q = $pdo->prepare("SELECT * FROM exam_questions WHERE exam_id = ? ORDER BY id ASC");
$stmt_q->execute([$exam_id]);
$questions = $stmt_q->fetchAll();

$page_title = "Kelola Soal - " . $exam['title'];
require_once __DIR__ . "/../includes/header.php";

$cat_info = EXAM_CATEGORIES[$exam['category']] ?? ['label' => $exam['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '<i class="fa-solid fa-file-pen"></i>'];
?>

<!-- KaTeX Library untuk Rumus Matematika & Sains -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css">
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/contrib/auto-render.min.js" onload="renderMathInElement(document.body, {delimiters: [{left: '$$', right: '$$', display: true}, {left: '$', right: '$', display: false}]});"></script>

<div class="space-y-6">

    <!-- Header & Navigation Back -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-white/10 pb-6">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <a href="exams.php" class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-400 hover:underline">
                    <i class="fa-solid fa-arrow-left"></i> Kembali ke Modul Ujian & Latihan
                </a>
            </div>
            <div class="flex items-center gap-2.5 flex-wrap">
                <span class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-bold <?= $cat_info['badge'] ?>">
                    <span><?= $cat_info['icon'] ?></span>
                    <span><?= htmlspecialchars($cat_info['label']) ?></span>
                </span>
                <span class="inline-flex items-center gap-1 rounded-lg border px-2 py-0.5 text-xs font-bold <?= getExamLevelBadge($exam['education_level'] ?? 'semua') ?>">
                    <i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars(getExamLevelLabel($exam['education_level'] ?? 'semua')) ?>
                </span>
                <span class="text-xs text-slate-400">Mapel: <strong class="text-white"><?= htmlspecialchars($exam['subject']) ?></strong></span>
                <span class="text-xs text-slate-400">KKM: <strong class="text-white"><?= $exam['passing_grade'] ?></strong></span>
                <span class="text-xs text-slate-400">Durasi: <strong class="text-white"><?= $exam['duration_minutes'] > 0 ? $exam['duration_minutes'] . ' Menit' : '<i class="fa-solid fa-bolt mr-1 text-amber-400"></i>Fleksibel' ?></strong></span>
            </div>
            <h1 class="text-2xl font-extrabold text-white mt-2">
                <?= htmlspecialchars($exam['title']) ?>
            </h1>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <a href="exam_questions.php?id=<?= $exam['id'] ?>&action=download_template" 
               class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-2 text-xs font-bold text-emerald-300 hover:bg-emerald-500/20 transition flex items-center gap-1.5 shadow-sm">
                <i class="fa-solid fa-file-csv"></i> Unduh Template CSV (Lengkap)
            </a>
            <a href="exam_results.php?id=<?= $exam['id'] ?>" 
               class="inline-flex items-center gap-1.5 rounded-xl border border-blue-500/30 bg-blue-500/10 px-4 py-2 text-xs font-bold text-blue-300 hover:bg-blue-500/20 transition">
                <i class="fa-solid fa-chart-column"></i> Rekap Nilai Siswa
            </a>
            <a href="exams.php" 
               class="rounded-xl bg-white/10 px-4 py-2 text-xs font-bold text-white hover:bg-white/20 transition">
                Selesai / Tutup
            </a>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($message !== ''): ?>
        <div class="rounded-2xl border p-4 text-sm <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

        <!-- Form Tambah Soal: Dual Tab Manual / CSV (Kiri) -->
        <div class="lg:col-span-5">
            <div class="rounded-3xl border border-white/10 bg-slate-900/80 p-6 shadow-xl sticky top-24 space-y-4">
                
                <!-- Tab Selector: Input Manual vs Impor CSV -->
                <div class="grid grid-cols-2 gap-1.5 rounded-2xl bg-white/5 p-1 text-xs font-bold">
                    <button type="button" id="tabBtnManual" onclick="switchQuestionTab('manual')" 
                            class="inline-flex items-center justify-center gap-1.5 rounded-xl py-2 transition bg-blue-600 text-white shadow-sm cursor-pointer">
                        <i class="fa-solid fa-pen-to-square"></i> Input Manual
                    </button>
                    <button type="button" id="tabBtnCsv" onclick="switchQuestionTab('csv')" 
                            class="inline-flex items-center justify-center gap-1.5 rounded-xl py-2 transition text-slate-400 hover:text-white cursor-pointer">
                        <i class="fa-solid fa-file-import"></i> Impor CSV Massal
                    </button>
                </div>

                <!-- 1. FORM INPUT MANUAL -->
                <div id="formSectionManual">
                    <form method="POST" action="exam_questions.php?id=<?= $exam['id'] ?>" enctype="multipart/form-data" class="space-y-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="add_question">

                        <!-- Pilihan 5 Tipe Soal Standar Nasional & AKM -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                                Tipe Butir Soal <span class="text-red-400">*</span>
                            </label>
                            <select name="question_type" id="questionTypeSelect" onchange="toggleQuestionType(this.value)" 
                                    class="w-full rounded-xl border border-white/10 bg-slate-800 px-3.5 py-2.5 text-xs text-white font-semibold focus:border-blue-500 focus:outline-none">
                                <option value="multiple_choice">Pilihan Ganda Tunggal (A-E)</option>
                                <option value="multiple_choice_complex">Pilihan Ganda Kompleks (AKM Centang >1)</option>
                                <option value="true_false">Benar / Salah (Logika Evaluatif)</option>
                                <option value="short_answer">Isian Singkat (Penilaian Otomatis)</option>
                                <option value="essay">Esai / Uraian (Penilaian Guru)</option>
                            </select>
                        </div>

                        <!-- Bobot Skor Maksimal -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300">
                                    Bobot Poin Soal <span class="text-red-400">*</span>
                                </label>
                                <span class="text-[10px] text-slate-400">Default: 10 Poin</span>
                            </div>
                            <input type="number" name="max_score" min="1" max="100" value="10" 
                                   class="w-full rounded-xl border border-white/10 bg-slate-800 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                        </div>

                        <!-- Teks Pertanyaan & Bantuan Rumus Matematika -->
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300">
                                    Pertanyaan / Teks Soal <span class="text-red-400">*</span>
                                </label>
                                <button type="button" onclick="document.getElementById('mathHelperModal').classList.remove('hidden')" 
                                        class="text-[11px] text-amber-400 hover:text-amber-300 font-bold flex items-center gap-1 cursor-pointer">
                                    <i class="fa-solid fa-square-root-variable"></i> Panduan Rumus (LaTeX)
                                </button>
                            </div>
                            <textarea name="question_text" id="qTextInput" rows="3" required placeholder="Tuliskan teks pertanyaan di sini... Gunakan $x^2$ atau $$\frac{a}{b}$$ untuk rumus matematika." 
                                      class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none"></textarea>
                        </div>

                        <!-- Lampiran Multimedia: Gambar & Audio Listening -->
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-3.5 space-y-3">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-300">
                                <i class="fa-solid fa-photo-film text-blue-400 mr-1.5"></i>Lampiran Multimedia (Gambar & Audio)
                            </label>
                            
                            <!-- Gambar -->
                            <div>
                                <span class="block text-[11px] text-slate-400 mb-1"><i class="fa-regular fa-image mr-1"></i>Unggah Gambar (JPG/PNG/WEBP):</span>
                                <input type="file" name="question_image" accept="image/*" 
                                       class="w-full text-xs text-slate-300 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500 cursor-pointer">
                                <input type="url" name="image_url" placeholder="Atau paste URL Gambar langsung (https://...)" 
                                       class="mt-1.5 w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-1.5 text-xs text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none">
                            </div>

                            <!-- Audio Listening -->
                            <div class="border-t border-white/5 pt-2">
                                <span class="block text-[11px] text-slate-400 mb-1"><i class="fa-solid fa-headphones mr-1 text-sky-400"></i>Audio Listening (MP3/WAV/OGG):</span>
                                <input type="file" name="question_audio" accept="audio/*" 
                                       class="w-full text-xs text-slate-300 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-[11px] file:font-semibold file:bg-sky-600 file:text-white hover:file:bg-sky-500 cursor-pointer">
                                <input type="url" name="audio_url" placeholder="Atau paste URL Audio langsung (https://...)" 
                                       class="mt-1.5 w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-1.5 text-xs text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none">
                            </div>
                        </div>

                        <!-- GRUP PILIHAN GANDA (A, B, C, D, E) -->
                        <div id="mcqOptionsGroup" class="space-y-3">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-300">
                                Pilihan Jawaban (A s/d E) <span class="text-red-400">*</span>
                            </label>

                            <div class="flex items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-600/30 text-blue-300 font-bold text-xs shrink-0">A</span>
                                <input type="text" name="option_a" id="optA" placeholder="Pilihan A" 
                                       class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-600/30 text-blue-300 font-bold text-xs shrink-0">B</span>
                                <input type="text" name="option_b" id="optB" placeholder="Pilihan B" 
                                       class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-600/30 text-blue-300 font-bold text-xs shrink-0">C</span>
                                <input type="text" name="option_c" id="optC" placeholder="Pilihan C" 
                                       class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-600/30 text-blue-300 font-bold text-xs shrink-0">D</span>
                                <input type="text" name="option_d" id="optD" placeholder="Pilihan D" 
                                       class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-1.5 text-xs text-white focus:border-blue-500 focus:outline-none">
                            </div>

                            <!-- Opsi E (Standar SMA/SMK) -->
                            <div class="flex items-center gap-2">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-purple-600/30 text-purple-300 font-bold text-xs shrink-0" title="Standar SMA / SMK">E</span>
                                <input type="text" name="option_e" id="optE" placeholder="Pilihan E (Wajib untuk SMA/SMK, opsional SD/SMP)" 
                                       class="w-full rounded-xl border border-purple-500/30 bg-slate-800 px-3 py-1.5 text-xs text-white focus:border-purple-500 focus:outline-none">
                            </div>

                            <!-- Kunci PG Tunggal -->
                            <div id="singleKeyGroup">
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1">
                                    Kunci Jawaban Benar <span class="text-emerald-400">*</span>
                                </label>
                                <select name="correct_answer_mc" 
                                        class="w-full rounded-xl border border-emerald-500/30 bg-slate-800 px-3.5 py-2 text-xs font-bold text-emerald-300 focus:border-emerald-500 focus:outline-none">
                                    <option value="A">Pilihan A</option>
                                    <option value="B">Pilihan B</option>
                                    <option value="C">Pilihan C</option>
                                    <option value="D">Pilihan D</option>
                                    <option value="E">Pilihan E (SMA/SMK)</option>
                                </select>
                            </div>

                            <!-- Kunci PG Kompleks (Checkboxes) -->
                            <div id="complexKeyGroup" class="hidden rounded-2xl border border-indigo-500/30 bg-indigo-500/10 p-3 space-y-2">
                                <label class="block text-xs font-bold uppercase tracking-wider text-indigo-300">
                                    Centang Seluruh Jawaban Benar (AKM) <span class="text-emerald-400">*</span>
                                </label>
                                <div class="flex items-center gap-3 text-xs font-bold text-white flex-wrap">
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" name="correct_answer_complex[]" value="A" class="rounded bg-slate-900 border-white/20 text-indigo-600"> Opsi A
                                    </label>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" name="correct_answer_complex[]" value="B" class="rounded bg-slate-900 border-white/20 text-indigo-600"> Opsi B
                                    </label>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" name="correct_answer_complex[]" value="C" class="rounded bg-slate-900 border-white/20 text-indigo-600"> Opsi C
                                    </label>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" name="correct_answer_complex[]" value="D" class="rounded bg-slate-900 border-white/20 text-indigo-600"> Opsi D
                                    </label>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" name="correct_answer_complex[]" value="E" class="rounded bg-slate-900 border-white/20 text-indigo-600"> Opsi E
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- GRUP BENAR / SALAH -->
                        <div id="tfOptionsGroup" class="hidden rounded-2xl border border-cyan-500/30 bg-cyan-500/10 p-3 space-y-2">
                            <label class="block text-xs font-bold uppercase tracking-wider text-cyan-300">
                                Kunci Nilai Kebenaran Pernyataan <span class="text-red-400">*</span>
                            </label>
                            <div class="grid grid-cols-2 gap-2 text-xs font-bold">
                                <label class="flex items-center justify-center gap-2 p-2.5 rounded-xl border border-emerald-500/40 bg-emerald-500/20 text-emerald-300 cursor-pointer">
                                    <input type="radio" name="correct_answer_tf" value="B" checked class="text-emerald-500"> Benar (B)
                                </label>
                                <label class="flex items-center justify-center gap-2 p-2.5 rounded-xl border border-rose-500/40 bg-rose-500/20 text-rose-300 cursor-pointer">
                                    <input type="radio" name="correct_answer_tf" value="S" class="text-rose-500"> Salah (S)
                                </label>
                            </div>
                        </div>

                        <!-- GRUP ISIAN SINGKAT -->
                        <div id="shortAnswerGroup" class="hidden rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-3 space-y-1.5">
                            <label class="block text-xs font-bold uppercase tracking-wider text-emerald-300">
                                Kunci Jawaban Isian Singkat <span class="text-red-400">*</span>
                            </label>
                            <input type="text" name="correct_answer_short" placeholder="Contoh: Fotosintesis atau 25" 
                                   class="w-full rounded-xl border border-white/10 bg-slate-900 px-3.5 py-2 text-xs text-white focus:border-emerald-500 focus:outline-none">
                            <span class="text-[10px] text-slate-400 block">Dapat menggunakan tanda pipa (|) untuk alternatif jawaban, misal: <em>fotosintesis|proses fotosintesis</em></span>
                        </div>

                        <!-- Pembahasan / Rubrik Acuan -->
                        <div>
                            <label id="explLabel" class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                                Penjelasan / Pembahasan Soal (Opsional)
                            </label>
                            <textarea name="explanation" rows="2" placeholder="Penjelasan kunci jawaban atau rubrik penilaian..." 
                                      class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2 text-xs text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none"></textarea>
                        </div>

                        <button type="submit" 
                                class="w-full inline-flex items-center justify-center gap-1.5 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-blue-500 shadow-lg shadow-blue-500/25 transition cursor-pointer">
                            <i class="fa-solid fa-floppy-disk"></i> Simpan Butir Soal
                        </button>
                    </form>
                </div>

                <!-- 2. FORM IMPOR CSV MASSAL -->
                <div id="formSectionCsv" class="hidden space-y-4">
                    <div class="rounded-2xl border border-blue-500/20 bg-blue-500/10 p-4 space-y-3">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h4 class="text-xs font-bold text-white">Petunjuk Impor Massal</h4>
                                <p class="text-[11px] text-blue-200/80 mt-0.5">
                                    Mendukung seluruh tipe soal SD, SMP, SMA/SMK (PG A-E, PG Kompleks AKM, Benar/Salah, Isian Singkat, & Esai).
                                </p>
                            </div>
                        </div>

                        <div class="pt-1">
                            <a href="exam_questions.php?id=<?= $exam['id'] ?>&action=download_template" 
                               class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 px-3.5 py-2 text-xs font-bold text-white shadow-md shadow-blue-500/25 transition">
                                <i class="fa-solid fa-download"></i> Unduh Format Template CSV
                            </a>
                        </div>
                    </div>

                    <form method="POST" action="exam_questions.php?id=<?= $exam['id'] ?>" enctype="multipart/form-data" class="space-y-4">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="import_csv">

                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                                Pilih File CSV Soal <span class="text-red-400">*</span>
                            </label>
                            <input type="file" name="csv_file" accept=".csv" required 
                                   class="w-full text-xs text-slate-300 file:mr-3 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-emerald-600 file:text-white hover:file:bg-emerald-500 cursor-pointer rounded-xl border border-white/10 bg-slate-800 p-2">
                            <span class="block text-[11px] text-slate-400 mt-1.5">
                                Mendukung pemisah tanda koma (,) atau titik koma (;) secara otomatis.
                            </span>
                        </div>

                        <div class="rounded-xl border border-white/10 bg-slate-800/60 p-3 text-[11px] text-slate-400 space-y-1">
                            <strong class="text-white block">Kolom Template CSV Baru (12 Kolom):</strong>
                            <div>1. question_type (multiple_choice, multiple_choice_complex, true_false, short_answer, essay)</div>
                            <div>2. question_text &bull; 3. option_a s/d option_e</div>
                            <div>4. correct_answer (A, A,C, B, atau kata isian)</div>
                            <div>5. max_score &bull; 6. explanation &bull; 7. image_url &bull; 8. audio_url</div>
                        </div>

                        <button type="submit" 
                                class="w-full inline-flex items-center justify-center gap-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-3 text-xs font-extrabold text-white shadow-lg shadow-emerald-500/25 transition cursor-pointer">
                            <i class="fa-solid fa-rocket"></i> Mulai Impor Butir Soal
                        </button>
                    </form>
                </div>

            </div>
        </div>

        <script>
            function switchQuestionTab(tabName) {
                const formManual = document.getElementById('formSectionManual');
                const formCsv = document.getElementById('formSectionCsv');
                const btnManual = document.getElementById('tabBtnManual');
                const btnCsv = document.getElementById('tabBtnCsv');

                if (tabName === 'csv') {
                    formManual.classList.add('hidden');
                    formCsv.classList.remove('hidden');

                    btnCsv.className = "rounded-xl py-2 transition bg-blue-600 text-white shadow-sm";
                    btnManual.className = "rounded-xl py-2 transition text-slate-400 hover:text-white";
                } else {
                    formCsv.classList.add('hidden');
                    formManual.classList.remove('hidden');

                    btnManual.className = "rounded-xl py-2 transition bg-blue-600 text-white shadow-sm";
                    btnCsv.className = "rounded-xl py-2 transition text-slate-400 hover:text-white";
                }
            }

            function toggleQuestionType(type) {
                const mcq = document.getElementById('mcqOptionsGroup');
                const singleKey = document.getElementById('singleKeyGroup');
                const complexKey = document.getElementById('complexKeyGroup');
                const tfGroup = document.getElementById('tfOptionsGroup');
                const shortGroup = document.getElementById('shortAnswerGroup');
                const explLabel = document.getElementById('explLabel');

                // Sembunyikan semua dulu
                mcq.classList.add('hidden');
                singleKey.classList.add('hidden');
                complexKey.classList.add('hidden');
                tfGroup.classList.add('hidden');
                shortGroup.classList.add('hidden');

                if (type === 'multiple_choice') {
                    mcq.classList.remove('hidden');
                    singleKey.classList.remove('hidden');
                    explLabel.innerText = "Penjelasan / Pembahasan Soal (Opsional)";
                } else if (type === 'multiple_choice_complex') {
                    mcq.classList.remove('hidden');
                    complexKey.classList.remove('hidden');
                    explLabel.innerText = "Penjelasan / Pembahasan Soal (Opsional)";
                } else if (type === 'true_false') {
                    tfGroup.classList.remove('hidden');
                    explLabel.innerText = "Penjelasan Mengapa Benar/Salah (Opsional)";
                } else if (type === 'short_answer') {
                    shortGroup.classList.remove('hidden');
                    explLabel.innerText = "Penjelasan Kunci Jawaban (Opsional)";
                } else if (type === 'essay') {
                    explLabel.innerText = "Rubrik Penilaian / Kunci Jawaban Acuan Guru";
                }
            }
        </script>

        <!-- Daftar Soal Tersimpan (Kanan) -->
        <div class="lg:col-span-7 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-list-ol text-blue-400"></i> Daftar Butir Soal (<?= count($questions) ?>)
                </h2>
                <span class="text-xs text-slate-400">Standar Evaluasi SD, SMP, SMA/SMK</span>
            </div>

            <?php if (empty($questions)): ?>
                <div class="rounded-3xl border border-white/10 bg-slate-900/40 p-10 text-center">
                    <span class="text-3xl block mb-2 text-slate-600"><i class="fa-solid fa-pen-to-square"></i></span>
                    <h3 class="text-base font-bold text-white">Belum Ada Soal</h3>
                    <p class="text-xs text-slate-400 mt-1">Gunakan formulir di sebelah kiri untuk memasukkan soal pilihan ganda (A-E), AKM kompleks, benar/salah, isian, atau esai.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($questions as $index => $q): ?>
                        <?php 
                            $q_type = $q['question_type'] ?? 'multiple_choice';
                            $type_label = getExamQuestionTypeLabel($q_type);
                            $type_badge = getExamQuestionTypeBadge($q_type);
                        ?>
                        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 shadow-lg relative group">
                            <div class="flex items-start justify-between gap-4 mb-3">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-600 text-white text-xs font-extrabold font-mono">
                                        <?= $index + 1 ?>
                                    </span>
                                    <span class="text-[11px] font-bold px-2 py-0.5 rounded-md border <?= $type_badge ?>">
                                        <?= htmlspecialchars($type_label) ?>
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-mono">
                                        Bobot: <?= (float)$q['max_score'] ?> Poin
                                    </span>
                                    <?php if (!empty($q['audio_url'])): ?>
                                        <span class="text-[10px] font-bold text-sky-300 bg-sky-500/10 border border-sky-500/30 px-1.5 py-0.5 rounded">
                                            <i class="fa-solid fa-headphones mr-1"></i>Listening
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" action="exam_questions.php?id=<?= $exam['id'] ?>" class="inline" onsubmit="return confirm('Hapus nomor soal ini?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="delete_question_id" value="<?= $q['id'] ?>">
                                    <button type="submit" 
                                       class="inline-flex items-center gap-1 text-xs text-red-400 hover:text-red-300 font-semibold p-1 hover:bg-red-500/10 rounded-lg transition cursor-pointer"
                                       title="Hapus Soal">
                                        <i class="fa-solid fa-trash"></i> Hapus
                                    </button>
                                </form>
                            </div>

                            <!-- Pertanyaan (KaTeX Auto-rendered) -->
                            <div class="text-sm font-medium text-white mb-3 leading-relaxed">
                                <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                            </div>

                            <!-- Lampiran Audio Listening (Jika ada) -->
                            <?php if (!empty($q['audio_url'])): ?>
                                <div class="my-3 rounded-2xl border border-sky-500/30 bg-sky-950/30 p-3 flex items-center gap-3">
                                    <i class="fa-solid fa-headphones text-sky-400 text-lg"></i>
                                    <div class="flex-1">
                                        <span class="text-[11px] font-bold text-sky-300 block mb-1">Audio Listening:</span>
                                        <audio controls class="w-full h-8" preload="none">
                                            <source src="<?= htmlspecialchars($q['audio_url']) ?>">
                                            Browser tidak mendukung pemutar audio.
                                        </audio>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Lampiran Gambar Soal (Jika ada) -->
                            <?php if (!empty($q['image_url'])): ?>
                                <div class="my-3 rounded-2xl overflow-hidden border border-white/10 bg-slate-950 p-2 max-w-sm">
                                    <img src="<?= htmlspecialchars(resolveAssetUrl($q['image_url'], '../../')) ?>" alt="Ilustrasi Soal" 
                                         class="rounded-xl max-h-48 w-auto object-contain mx-auto">
                                </div>
                            <?php endif; ?>

                            <!-- Tampilan Sesuai Tipe Soal -->
                            <?php if ($q_type === 'multiple_choice' || $q_type === 'multiple_choice_complex'): ?>
                                <?php 
                                    $correct_keys = explode(',', strtoupper($q['correct_answer'] ?? ''));
                                    $opts = [
                                        'A' => $q['option_a'],
                                        'B' => $q['option_b'],
                                        'C' => $q['option_c'],
                                        'D' => $q['option_d'],
                                        'E' => $q['option_e'],
                                    ];
                                ?>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                    <?php foreach ($opts as $opt_k => $opt_v): ?>
                                        <?php if (empty($opt_v)) continue; ?>
                                        <?php $is_key = in_array($opt_k, $correct_keys, true); ?>
                                        <div class="rounded-xl border p-2.5 flex items-center gap-2 <?= $is_key ? 'border-emerald-500/50 bg-emerald-500/15 text-emerald-200 font-semibold' : 'border-white/5 bg-white/5 text-slate-300' ?>">
                                            <span class="font-bold font-mono <?= $is_key ? 'text-emerald-400' : 'text-slate-400' ?>"><?= $opt_k ?>.</span>
                                            <span><?= htmlspecialchars($opt_v) ?></span>
                                            <?php if ($is_key): ?><span class="ml-auto text-emerald-400 font-bold"><i class="fa-solid fa-check mr-1"></i>Kunci</span><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($q_type === 'true_false'): ?>
                                <div class="flex items-center gap-3 text-xs">
                                    <span class="font-bold text-slate-400">Kunci Kebenaran:</span>
                                    <span class="px-3 py-1 rounded-xl font-black <?= $q['correct_answer'] === 'B' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40' : 'bg-rose-500/20 text-rose-300 border border-rose-500/40' ?>">
                                        <?= $q['correct_answer'] === 'B' ? '<i class="fa-solid fa-check mr-1"></i>BENAR (B)' : '<i class="fa-solid fa-xmark mr-1"></i>SALAH (S)' ?>
                                    </span>
                                </div>

                            <?php elseif ($q_type === 'short_answer'): ?>
                                <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs text-emerald-200 flex items-center gap-2">
                                    <i class="fa-solid fa-key text-emerald-400"></i>
                                    <div>
                                        <strong class="text-white">Kunci Jawaban Isian:</strong>
                                        <span class="font-mono font-bold bg-slate-900 px-2 py-0.5 rounded border border-white/10 ml-1"><?= htmlspecialchars($q['correct_answer'] ?? '') ?></span>
                                    </div>
                                </div>

                            <?php elseif ($q_type === 'essay'): ?>
                                <div class="rounded-xl border border-purple-500/20 bg-purple-500/5 p-3 text-xs text-purple-200 space-y-1">
                                    <strong class="text-white block"><i class="fa-solid fa-lightbulb text-amber-400 mr-1"></i>Rubrik Penilaian / Kunci Jawaban Acuan:</strong>
                                    <p class="text-slate-300"><?= !empty($q['explanation']) ? nl2br(htmlspecialchars($q['explanation'])) : 'Belum ada catatan rubrik acuan. Siswa akan mengetik jawaban bebas.' ?></p>
                                </div>
                            <?php endif; ?>

                            <!-- Pembahasan (Jika ada) -->
                            <?php if ($q_type !== 'essay' && !empty($q['explanation'])): ?>
                                <div class="mt-3 rounded-xl border border-blue-500/20 bg-blue-500/10 p-2.5 text-xs text-blue-300">
                                    <strong class="text-white font-semibold"><i class="fa-solid fa-lightbulb text-amber-400 mr-1"></i>Pembahasan:</strong> <?= htmlspecialchars($q['explanation']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<!-- Modal Panduan Rumus Matematika (KaTeX / LaTeX) -->
<div id="mathHelperModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md">
    <div class="relative w-full max-w-lg rounded-3xl border border-white/15 bg-slate-900 p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-square-root-variable text-amber-400"></i> Panduan Menulis Rumus (LaTeX)
            </h3>
            <button type="button" onclick="document.getElementById('mathHelperModal').classList.add('hidden')" class="text-slate-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <p class="text-xs text-slate-300 leading-relaxed">
            Ketik rumus matematika atau sains di dalam tanda dolar <code class="text-amber-300 font-mono">$...$</code> untuk rumus sebaris (inline) atau <code class="text-amber-300 font-mono">$$...$$</code> untuk rumus di tengah (blok).
        </p>
        <div class="rounded-2xl border border-white/10 bg-slate-950 p-4 space-y-2.5 text-xs font-mono">
            <div class="flex justify-between border-b border-white/5 pb-1.5">
                <span class="text-amber-300">$x^2 + y^2 = r^2$</span>
                <span class="text-slate-300">Pangkat kuadrat</span>
            </div>
            <div class="flex justify-between border-b border-white/5 pb-1.5">
                <span class="text-amber-300">$\frac{a}{b}$</span>
                <span class="text-slate-300">Pecahan (fraction)</span>
            </div>
            <div class="flex justify-between border-b border-white/5 pb-1.5">
                <span class="text-amber-300">$\sqrt{x}$</span>
                <span class="text-slate-300">Bentuk akar</span>
            </div>
            <div class="flex justify-between border-b border-white/5 pb-1.5">
                <span class="text-amber-300">$\pm$ dan $\times$</span>
                <span class="text-slate-300">Simbol plus-minus & kali</span>
            </div>
            <div class="flex justify-between">
                <span class="text-amber-300">$H_2SO_4$</span>
                <span class="text-slate-300">Rumus kimia (indeks bawah)</span>
            </div>
        </div>
        <button type="button" onclick="document.getElementById('mathHelperModal').classList.add('hidden')" 
                class="w-full rounded-xl bg-blue-600 hover:bg-blue-500 py-2.5 text-xs font-bold text-white transition">
            Saya Mengerti
        </button>
    </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
