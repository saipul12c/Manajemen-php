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

// Ambil data ujian (BUG-01 fix: parameterized query)
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

    // Header Kolom
    fputcsv($output, ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_answer', 'explanation', 'image_url']);

    // Contoh Soal 1
    fputcsv($output, [
        'Organ tubuh manusia yang berfungsi memompa darah ke seluruh tubuh adalah...',
        'Paru-paru',
        'Jantung',
        'Hati',
        'Ginjal',
        'B',
        'Jantung adalah organ muskular berongga yang berfungsi memompa darah ke seluruh tubuh.',
        ''
    ]);

    // Contoh Soal 2
    fputcsv($output, [
        'Ibukota baru negara Indonesia yang berada di wilayah Kalimantan Timur adalah...',
        'Nusantara (IKN)',
        'Balikpapan',
        'Samarinda',
        'Banjarmasin',
        'A',
        'Ibu Kota Nusantara (IKN) ditetapkan sebagai ibu kota baru Republik Indonesia.',
        'https://images.unsplash.com/photo-1579684385127-1ef15d508118?w=800'
    ]);

    fclose($output);
    exit;
}

// -------------------------------------------------------------
// 1. TAMBAH SOAL (MANUAL)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_question') {
    // BUG-05 fix: Validasi CSRF Token
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan muat ulang halaman.";
        $message_type = "error";
    } else {
        $question_type = in_array($_POST['question_type'] ?? '', ['multiple_choice', 'essay']) ? $_POST['question_type'] : 'multiple_choice';
        $max_score = (float) ($_POST['max_score'] ?? 10.00);
        if ($max_score <= 0) $max_score = 10.00;

        $question_text = trim($_POST['question_text'] ?? '');
        $option_a = trim($_POST['option_a'] ?? '');
        $option_b = trim($_POST['option_b'] ?? '');
        $option_c = trim($_POST['option_c'] ?? '');
        $option_d = trim($_POST['option_d'] ?? '');
        $correct_answer = strtoupper(trim($_POST['correct_answer'] ?? 'A'));
        $explanation = trim($_POST['explanation'] ?? '');

        $has_error = false;
        if ($question_text === '') {
            $message = "Pertanyaan soal wajib diisi.";
            $message_type = "error";
            $has_error = true;
        } elseif ($question_type === 'multiple_choice') {
            if (!in_array($correct_answer, ['A', 'B', 'C', 'D'], true)) {
                $correct_answer = 'A';
            }
            if ($option_a === '' || $option_b === '' || $option_c === '' || $option_d === '') {
                $message = "Semua pilihan jawaban (A, B, C, D) wajib diisi untuk soal Pilihan Ganda.";
                $message_type = "error";
                $has_error = true;
            }
        } else {
            // Mode Esai: Kosongkan opsi dan kunci PG
            $option_a = null;
            $option_b = null;
            $option_c = null;
            $option_d = null;
            $correct_answer = null;
        }

        if (!$has_error) {
            $image_url = null;

            // BUG-09 fix: Validasi MIME-type dan ekstensi gambar
            if (!empty($_FILES['question_image']['name']) && $_FILES['question_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                $file_name = $_FILES['question_image']['name'];
                $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $tmp_name = $_FILES['question_image']['tmp_name'];

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $detected_mime = finfo_file($finfo, $tmp_name);
                finfo_close($finfo);

                if (in_array($ext, $allowed_exts, true) && in_array($detected_mime, $allowed_mimes, true)) {
                    $upload_dir = __DIR__ . "/../../uploads/exams/";
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $new_filename = "soal_" . $exam_id . "_" . uniqid() . "." . $ext;
                    $dest_path = $upload_dir . $new_filename;

                    if (move_uploaded_file($tmp_name, $dest_path)) {
                        $image_url = "../../uploads/exams/" . $new_filename;
                    }
                }
            // BUG-10 fix: Validasi image_url harus berprotokol http/https
            } elseif (!empty($_POST['image_url'])) {
                $raw_url = trim($_POST['image_url']);
                if (filter_var($raw_url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $raw_url)) {
                    $image_url = $raw_url;
                }
            }

            $stmt_ins = $pdo->prepare("
                INSERT INTO exam_questions (exam_id, question_type, max_score, question_text, image_url, option_a, option_b, option_c, option_d, correct_answer, explanation)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_ins->execute([$exam_id, $question_type, $max_score, $question_text, $image_url, $option_a, $option_b, $option_c, $option_d, $correct_answer, $explanation]);
            $message = "Butir soal baru (" . ($question_type === 'essay' ? 'Esai / Uraian' : 'Pilihan Ganda') . ") berhasil ditambahkan.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 1B. IMPOR SOAL MASSAL VIA FILE CSV
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_csv') {
    // BUG-05 fix: Validasi CSRF Token
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
                    INSERT INTO exam_questions (exam_id, question_text, image_url, option_a, option_b, option_c, option_d, correct_answer, explanation)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                while (($data = fgetcsv($handle, 3000, $delimiter)) !== false) {
                    $row_idx++;
                    // Lewati baris pertama jika berupa header
                    if ($row_idx === 1 && isset($data[0]) && stripos($data[0], 'question_text') !== false) {
                        continue;
                    }

                    $q_text = trim($data[0] ?? '');
                    $opt_a = trim($data[1] ?? '');
                    $opt_b = trim($data[2] ?? '');
                    $opt_c = trim($data[3] ?? '');
                    $opt_d = trim($data[4] ?? '');
                    $correct = strtoupper(trim($data[5] ?? 'A'));
                    $expl = trim($data[6] ?? '');
                    $img = trim($data[7] ?? '');

                    if (!in_array($correct, ['A', 'B', 'C', 'D'], true)) {
                        $correct = 'A';
                    }

                    if ($q_text !== '' && $opt_a !== '' && $opt_b !== '') {
                        $stmt_in_q->execute([
                            $exam_id,
                            $q_text,
                            $img !== '' ? $img : null,
                            $opt_a,
                            $opt_b,
                            $opt_c !== '' ? $opt_c : '-',
                            $opt_d !== '' ? $opt_d : '-',
                            $correct,
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
// 2. HAPUS SOAL (BUG-02 fix: POST method + CSRF)
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

<div class="space-y-6">

    <!-- Header & Navigation Back -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-white/10 pb-6">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <a href="exams.php" class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-400 hover:underline">
                    <i class="fa-solid fa-arrow-left"></i> Kembali ke Modul Ujian & Latihan
                </a>
            </div>
            <div class="flex items-center gap-3">
                <span class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-bold <?= $cat_info['badge'] ?>">
                    <span><?= $cat_info['icon'] ?></span>
                    <span><?= htmlspecialchars($cat_info['label']) ?></span>
                </span>
                <span class="text-xs text-slate-400">Mapel: <strong class="text-white"><?= htmlspecialchars($exam['subject']) ?></strong></span>
                <span class="text-xs text-slate-400">KKM: <strong class="text-white"><?= $exam['passing_grade'] ?></strong></span>
                <span class="text-xs text-slate-400">Durasi: <strong class="text-white"><?= $exam['duration_minutes'] > 0 ? $exam['duration_minutes'] . ' Menit' : '<i class="fa-solid fa-bolt mr-1 text-amber-400"></i>Fleksibel' ?></strong></span>
            </div>
            <h1 class="text-2xl font-extrabold text-white mt-2">
                <?= htmlspecialchars($exam['title']) ?>
            </h1>
        </div>

        <div class="flex items-center gap-2">
            <a href="exam_questions.php?id=<?= $exam['id'] ?>&action=download_template" 
               class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-2 text-xs font-bold text-emerald-300 hover:bg-emerald-500/20 transition flex items-center gap-1.5 shadow-sm">
                <i class="fa-solid fa-file-csv"></i> Unduh Template CSV
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

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

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

                        <!-- Pilihan Tipe Soal (PG vs Esai) -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                                Tipe Butir Soal <span class="text-red-400">*</span>
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="flex items-center gap-2 rounded-xl border border-white/10 bg-slate-800 p-2.5 cursor-pointer hover:border-blue-500 transition">
                                    <input type="radio" name="question_type" value="multiple_choice" checked onchange="toggleQuestionType('multiple_choice')" class="text-blue-600 focus:ring-blue-500">
                                    <span class="text-xs font-semibold text-white"><i class="fa-regular fa-circle-dot mr-1.5 text-blue-400"></i>Pilihan Ganda</span>
                                </label>
                                <label class="flex items-center gap-2 rounded-xl border border-white/10 bg-slate-800 p-2.5 cursor-pointer hover:border-purple-500 transition">
                                    <input type="radio" name="question_type" value="essay" onchange="toggleQuestionType('essay')" class="text-purple-600 focus:ring-purple-500">
                                    <span class="text-xs font-semibold text-white"><i class="fa-solid fa-pen-nib mr-1.5 text-purple-400"></i>Esai / Uraian</span>
                                </label>
                            </div>
                        </div>

                        <!-- Bobot Skor Maksimal Esai (Hanya Tampil jika Esai) -->
                        <div id="essayOptionsGroup" class="hidden rounded-2xl border border-purple-500/30 bg-purple-500/10 p-3 space-y-1">
                            <label class="block text-xs font-bold uppercase tracking-wider text-purple-300">
                                Bobot Skor Maksimal Esai (Poin) <span class="text-red-400">*</span>
                            </label>
                            <input type="number" name="max_score" min="1" max="100" value="10" 
                                   class="w-full rounded-xl border border-white/10 bg-slate-800 px-3.5 py-2 text-xs text-white focus:border-purple-500 focus:outline-none">
                            <span class="text-[10px] text-slate-400 block">Guru dapat memberi nilai 0 s/d bobot ini saat mengoreksi jawaban siswa.</span>
                        </div>

                        <!-- Teks Pertanyaan -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                                Pertanyaan / Soal <span class="text-red-400">*</span>
                            </label>
                            <textarea name="question_text" rows="3" required placeholder="Tuliskan pertanyaan di sini..." 
                                      class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2.5 text-sm text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none"></textarea>
                        </div>

                        <!-- Lampiran Gambar Soal (Upload / URL) -->
                        <div class="rounded-2xl border border-white/10 bg-white/5 p-3.5 space-y-3">
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-300">
                                <i class="fa-regular fa-image text-slate-400 mr-1.5"></i>Lampiran Gambar / Diagram (Opsional)
                            </label>
                            <div>
                                <span class="block text-[11px] text-slate-400 mb-1">Unggah berkas gambar (JPG, PNG, WEBP):</span>
                                <input type="file" name="question_image" accept="image/*" 
                                       class="w-full text-xs text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500 cursor-pointer">
                            </div>
                            <div>
                                <span class="block text-[11px] text-slate-400 mb-1">Atau masukkan URL Gambar langsung:</span>
                                <input type="url" name="image_url" placeholder="https://example.com/diagram.png" 
                                       class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-1.5 text-xs text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none">
                            </div>
                        </div>

                        <!-- GRUP PILIHAN GANDA (A, B, C, D & Kunci) -->
                        <div id="mcqOptionsGroup" class="space-y-4">
                            <!-- Opsi A, B, C, D -->
                            <div class="space-y-2.5">
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300">
                                    Pilihan Jawaban (A, B, C, D) <span class="text-red-400">*</span>
                                </label>

                                <div class="flex items-center gap-2">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600/30 text-blue-300 font-bold text-xs shrink-0">A</span>
                                    <input type="text" name="option_a" placeholder="Pilihan A" 
                                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                                </div>

                                <div class="flex items-center gap-2">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600/30 text-blue-300 font-bold text-xs shrink-0">B</span>
                                    <input type="text" name="option_b" placeholder="Pilihan B" 
                                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                                </div>

                                <div class="flex items-center gap-2">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600/30 text-blue-300 font-bold text-xs shrink-0">C</span>
                                    <input type="text" name="option_c" placeholder="Pilihan C" 
                                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                                </div>

                                <div class="flex items-center gap-2">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600/30 text-blue-300 font-bold text-xs shrink-0">D</span>
                                    <input type="text" name="option_d" placeholder="Pilihan D" 
                                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                                </div>
                            </div>

                            <!-- Kunci Jawaban Benar -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                                    Kunci Jawaban Benar <span class="text-emerald-400">*</span>
                                </label>
                                <select name="correct_answer" 
                                        class="w-full rounded-xl border border-emerald-500/30 bg-slate-800 px-4 py-2.5 text-sm font-bold text-emerald-300 focus:border-emerald-500 focus:outline-none">
                                    <option value="A">Pilihan A</option>
                                    <option value="B">Pilihan B</option>
                                    <option value="C">Pilihan C</option>
                                    <option value="D">Pilihan D</option>
                                </select>
                            </div>
                        </div>

                        <!-- Pembahasan / Rubrik Acuan -->
                        <div>
                            <label id="explLabel" class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                                Penjelasan / Pembahasan Soal (Opsional)
                            </label>
                            <textarea name="explanation" rows="2" placeholder="Penjelasan kunci atau rubrik penilaian esai..." 
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
                                    Unggah puluhan soal sekaligus menggunakan berkas spreadsheet CSV.
                                </p>
                            </div>
                        </div>

                        <div class="pt-1">
                            <a href="exam_questions.php?id=<?= $exam['id'] ?>&action=download_template" 
                               class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 px-3.5 py-2 text-xs font-bold text-white shadow-md shadow-blue-500/25 transition">
                                <i class="fa-solid fa-download"></i> Unduh Format Template (.CSV)
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
                            <strong class="text-white block">Struktur Kolom CSV:</strong>
                            <div>1. question_text (Pertanyaan)</div>
                            <div>2. option_a s/d option_d (Pilihan A, B, C, D)</div>
                            <div>3. correct_answer (A, B, C, atau D)</div>
                            <div>4. explanation (Pembahasan opsional)</div>
                            <div>5. image_url (Link gambar opsional)</div>
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
                const essay = document.getElementById('essayOptionsGroup');
                const explLabel = document.getElementById('explLabel');
                if (type === 'essay') {
                    mcq.classList.add('hidden');
                    essay.classList.remove('hidden');
                    explLabel.innerText = "Rubrik Penilaian / Kunci Jawaban Acuan Guru (Opsional)";
                } else {
                    mcq.classList.remove('hidden');
                    essay.classList.add('hidden');
                    explLabel.innerText = "Penjelasan / Pembahasan Soal (Opsional)";
                }
            }
        </script>

        <!-- Daftar Soal Tersimpan (Kanan) -->
        <div class="lg:col-span-7 space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-list-ol text-blue-400"></i> Daftar Butir Soal (<?= count($questions) ?>)
                </h2>
                <span class="text-xs text-slate-400">Pilihan Ganda & Esai Terintegrasi</span>
            </div>

            <?php if (empty($questions)): ?>
                <div class="rounded-3xl border border-white/10 bg-slate-900/40 p-10 text-center">
                    <span class="text-3xl block mb-2 text-slate-600"><i class="fa-solid fa-pen-to-square"></i></span>
                    <h3 class="text-base font-bold text-white">Belum Ada Soal</h3>
                    <p class="text-xs text-slate-400 mt-1">Gunakan formulir di sebelah kiri untuk mulai memasukkan soal pilihan ganda atau esai.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($questions as $index => $q): ?>
                        <?php $is_essay = ($q['question_type'] === 'essay'); ?>
                        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 shadow-lg relative group">
                            <div class="flex items-start justify-between gap-4 mb-3">
                                <div class="flex items-center gap-2">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg <?= $is_essay ? 'bg-purple-600' : 'bg-blue-600' ?> text-white text-xs font-extrabold">
                                        <?= $index + 1 ?>
                                    </span>
                                    <?php if ($is_essay): ?>
                                        <span class="text-[11px] font-bold text-purple-300 bg-purple-500/20 border border-purple-500/30 px-2 py-0.5 rounded-md">
                                            <i class="fa-solid fa-pen-nib mr-1"></i>Soal Esai (Maks: <?= number_format($q['max_score'], 0) ?> Poin)
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] font-semibold text-blue-300 bg-blue-500/15 border border-blue-500/20 px-2 py-0.5 rounded-md">
                                            <i class="fa-regular fa-circle-dot mr-1"></i>Pilihan Ganda
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

                            <!-- Pertanyaan -->
                            <p class="text-sm font-medium text-white mb-3 leading-relaxed">
                                <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                            </p>

                            <!-- Lampiran Gambar Soal (Jika ada) -->
                            <?php if (!empty($q['image_url'])): ?>
                                <div class="my-3 rounded-2xl overflow-hidden border border-white/10 bg-slate-950 p-2 max-w-sm">
                                    <img src="<?= htmlspecialchars($q['image_url']) ?>" alt="Ilustrasi Soal" 
                                         class="rounded-xl max-h-48 w-auto object-contain mx-auto">
                                </div>
                            <?php endif; ?>

                            <!-- Tampilan Opsi Pilihan Ganda / Rubrik Esai -->
                            <?php if (!$is_essay): ?>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                    <div class="rounded-xl border p-2.5 flex items-center gap-2 <?= $q['correct_answer'] === 'A' ? 'border-emerald-500/50 bg-emerald-500/15 text-emerald-200 font-semibold' : 'border-white/5 bg-white/5 text-slate-300' ?>">
                                        <span class="font-bold <?= $q['correct_answer'] === 'A' ? 'text-emerald-400' : 'text-slate-400' ?>">A.</span>
                                        <span><?= htmlspecialchars($q['option_a'] ?? '') ?></span>
                                        <?php if ($q['correct_answer'] === 'A'): ?><span class="ml-auto text-emerald-400 font-bold"><i class="fa-solid fa-check mr-1"></i>Kunci</span><?php endif; ?>
                                    </div>
                                    <div class="rounded-xl border p-2.5 flex items-center gap-2 <?= $q['correct_answer'] === 'B' ? 'border-emerald-500/50 bg-emerald-500/15 text-emerald-200 font-semibold' : 'border-white/5 bg-white/5 text-slate-300' ?>">
                                        <span class="font-bold <?= $q['correct_answer'] === 'B' ? 'text-emerald-400' : 'text-slate-400' ?>">B.</span>
                                        <span><?= htmlspecialchars($q['option_b'] ?? '') ?></span>
                                        <?php if ($q['correct_answer'] === 'B'): ?><span class="ml-auto text-emerald-400 font-bold"><i class="fa-solid fa-check mr-1"></i>Kunci</span><?php endif; ?>
                                    </div>
                                    <div class="rounded-xl border p-2.5 flex items-center gap-2 <?= $q['correct_answer'] === 'C' ? 'border-emerald-500/50 bg-emerald-500/15 text-emerald-200 font-semibold' : 'border-white/5 bg-white/5 text-slate-300' ?>">
                                        <span class="font-bold <?= $q['correct_answer'] === 'C' ? 'text-emerald-400' : 'text-slate-400' ?>">C.</span>
                                        <span><?= htmlspecialchars($q['option_c'] ?? '') ?></span>
                                        <?php if ($q['correct_answer'] === 'C'): ?><span class="ml-auto text-emerald-400 font-bold"><i class="fa-solid fa-check mr-1"></i>Kunci</span><?php endif; ?>
                                    </div>
                                    <div class="rounded-xl border p-2.5 flex items-center gap-2 <?= $q['correct_answer'] === 'D' ? 'border-emerald-500/50 bg-emerald-500/15 text-emerald-200 font-semibold' : 'border-white/5 bg-white/5 text-slate-300' ?>">
                                        <span class="font-bold <?= $q['correct_answer'] === 'D' ? 'text-emerald-400' : 'text-slate-400' ?>">D.</span>
                                        <span><?= htmlspecialchars($q['option_d'] ?? '') ?></span>
                                        <?php if ($q['correct_answer'] === 'D'): ?><span class="ml-auto text-emerald-400 font-bold"><i class="fa-solid fa-check mr-1"></i>Kunci</span><?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="rounded-xl border border-purple-500/20 bg-purple-500/5 p-3 text-xs text-purple-200 space-y-1">
                                    <strong class="text-white block"><i class="fa-solid fa-lightbulb text-amber-400 mr-1"></i>Rubrik Penilaian / Kunci Jawaban Acuan:</strong>
                                    <p class="text-slate-300"><?= !empty($q['explanation']) ? nl2br(htmlspecialchars($q['explanation'])) : 'Belum ada catatan rubrik acuan. Siswa akan mengetik jawaban bebas.' ?></p>
                                </div>
                            <?php endif; ?>

                            <!-- Pembahasan PG -->
                            <?php if (!$is_essay && !empty($q['explanation'])): ?>
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

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
