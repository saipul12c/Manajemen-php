<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_teacher = in_array($user_role, ['guru', 'administrator'], true);
$can_manage = in_array($user_role, ['guru', 'administrator'], true);

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. TAMBAH PAKET UJIAN / LATIHAN (Guru & Administrator)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_exam') {
    if (!$can_manage) {
        header("Location: exams.php?error=unauthorized");
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $category = trim($_POST['category'] ?? 'uts');
    $description = trim($_POST['description'] ?? '');
    $duration_minutes = (int) ($_POST['duration_minutes'] ?? 0);
    $passing_grade = (int) ($_POST['passing_grade'] ?? 75);
    $token = !empty($_POST['token']) ? strtoupper(trim($_POST['token'])) : null;
    $randomize_questions = isset($_POST['randomize_questions']) ? 1 : 0;
    $hide_answers_until_due = isset($_POST['hide_answers_until_due']) ? 1 : 0;
    $start_time = !empty($_POST['start_time']) ? trim($_POST['start_time']) : date('Y-m-d H:i:s');
    $due_date = trim($_POST['due_date'] ?? '');

    // Validasi kategori
    if (!array_key_exists($category, EXAM_CATEGORIES)) {
        $category = 'uts';
    }

    $type = EXAM_CATEGORIES[$category]['type'];

    if ($title === '' || $subject === '' || $due_date === '') {
        $message = "Judul, mata pelajaran, dan batas waktu wajib diisi.";
        $message_type = "error";
    } else {
        $stmt_in = $pdo->prepare("
            INSERT INTO exams (title, subject, category, type, description, duration_minutes, passing_grade, token, randomize_questions, hide_answers_until_due, start_time, due_date, teacher_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aktif')
        ");
        $stmt_in->execute([
            $title, $subject, $category, $type, $description, 
            $duration_minutes, $passing_grade, $token, $randomize_questions, $hide_answers_until_due, $start_time, $due_date, $user_id
        ]);
        $new_exam_id = $pdo->lastInsertId();

        header("Location: exam_questions.php?id=" . $new_exam_id . "&created=1");
        exit;
    }
}

// -------------------------------------------------------------
// 2. HAPUS UJIAN / LATIHAN (Guru Pembuat / Administrator)
// -------------------------------------------------------------
if (isset($_GET['delete_id']) && $can_manage) {
    $del_id = (int) $_GET['delete_id'];
    $sql_del = "DELETE FROM exams WHERE id = ?";
    $params_del = [$del_id];
    if ($user_role === 'guru') {
        $sql_del .= " AND teacher_id = ?";
        $params_del[] = $user_id;
    }
    $stmt_del = $pdo->prepare($sql_del);
    $stmt_del->execute($params_del);

    $message = "Paket ujian/latihan berhasil dihapus.";
    $message_type = "success";
}

// -------------------------------------------------------------
// 3. FILTER & PENCARIAN
// -------------------------------------------------------------
$tab = $_GET['tab'] ?? 'all'; // all, ujian, latihan
$filter_cat = $_GET['category'] ?? '';
$search = trim($_GET['search'] ?? '');

$sql_exams = "
    SELECT e.*, u.name as teacher_name,
           (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.id) as question_count,
           (SELECT COUNT(*) FROM exam_submissions WHERE exam_id = e.id) as submission_count
";

if ($user_role === 'siswa') {
    $sql_exams .= ", es.score as student_score, es.total_correct as student_correct, es.total_questions as student_total_q, es.submitted_at as student_submitted_at, es.remedial_granted, es.is_remedial, es.previous_score";
}

$sql_exams .= " FROM exams e JOIN users u ON e.teacher_id = u.id";

if ($user_role === 'siswa') {
    $sql_exams .= " LEFT JOIN exam_submissions es ON es.exam_id = e.id AND es.student_id = " . (int)$user_id;
}

$sql_exams .= " WHERE 1=1";
$params_query = [];

if ($tab === 'ujian') {
    $sql_exams .= " AND e.type = 'ujian'";
} elseif ($tab === 'latihan') {
    $sql_exams .= " AND e.type = 'latihan'";
}

if ($filter_cat !== '' && array_key_exists($filter_cat, EXAM_CATEGORIES)) {
    $sql_exams .= " AND e.category = ?";
    $params_query[] = $filter_cat;
}

if ($search !== '') {
    $sql_exams .= " AND (e.title LIKE ? OR e.subject LIKE ? OR u.name LIKE ?)";
    $params_query[] = "%$search%";
    $params_query[] = "%$search%";
    $params_query[] = "%$search%";
}

$sql_exams .= " ORDER BY e.id DESC";

$stmt = $pdo->prepare($sql_exams);
$stmt->execute($params_query);
$exams_list = $stmt->fetchAll();

$page_title = "Ujian & Latihan";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Header & Action Button -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
                <span>📝</span> Modul Ujian & Latihan
            </h1>
            <p class="mt-1 text-sm text-slate-400">
                Sistem terintegrasi untuk Penilaian UTS, UKK, Ujian Harian Fleksibel, dan Latihan Belajar.
            </p>
        </div>

        <?php if ($can_manage): ?>
            <button onclick="document.getElementById('createExamModal').classList.remove('hidden')" 
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 hover:bg-blue-500 transition cursor-pointer">
                <span>➕</span> Buat Ujian / Latihan
            </button>
        <?php endif; ?>
    </div>

    <!-- Alert Message -->
    <?php if ($message !== ''): ?>
        <div class="rounded-2xl border p-4 text-sm <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- PANDUAN & PETUNJUK PENGGUNAAN BERDASARKAN ROLE -->
    <div class="rounded-3xl border border-white/10 bg-gradient-to-r from-blue-950/40 via-slate-900 to-slate-900/90 p-5 sm:p-6 shadow-xl">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-white/10 pb-4 mb-4">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-600/20 border border-blue-500/30 text-xl text-blue-400 shrink-0">
                    💡
                </span>
                <div>
                    <h3 class="text-sm sm:text-base font-bold text-white flex items-center gap-2">
                        Petunjuk & Panduan Sistem Asesmen
                        <span class="rounded-lg px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider <?= getRoleBadge($user_role) ?>">
                            Role Anda: <?= htmlspecialchars(getRoleLabel($user_role)) ?>
                        </span>
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Instruksi praktis untuk memaksimalkan fitur ujian, latihan, dan evaluasi hasil belajar.
                    </p>
                </div>
            </div>

            <button type="button" onclick="document.getElementById('allRolesGuideModal').classList.remove('hidden')" 
                    class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs font-semibold text-slate-300 transition flex items-center gap-1.5 shrink-0">
                <span>📖</span> Baca Panduan Semua Role
            </button>
        </div>

        <!-- Konten Panduan Disesuaikan Khusus Role Saat Ini -->
        <?php if ($user_role === 'siswa'): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>🔑</span> 1. Masuk & Token Ujian
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Jika ujian bertoken, tanyakan kode resmi kepada guru pengawas Anda di kelas untuk membuka soal.
                    </p>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>💾</span> 2. Autosave & Navigasi
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Jawaban tersimpan otomatis. Gunakan tombol kuning <strong>Ragu-Ragu</strong> dan palet nomor di sebelah kanan.
                    </p>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>🛑</span> 3. Jangan Pindah Tab
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Sistem mendeteksi perpindahan tab browser atau aplikasi lain sebagai pelanggaran integritas ujian.
                    </p>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>🔄</span> 4. Sesi Remedial
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Jika nilai di bawah KKM dan guru memberi izin, Anda dapat menekan tombol <strong>Kerjakan Remedial</strong>.
                    </p>
                </div>
            </div>

        <?php elseif ($user_role === 'guru'): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>➕</span> 1. Pembuatan Paket
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Pilih 1 dari 6 kategori asesmen, atur token CBT, jadwal jam mulai, dan centang opsi acak soal (shuffle).
                    </p>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>📥</span> 2. Impor Soal Massal
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Unduh format template CSV di menu Kelola Soal untuk mengunggah puluhan butir soal sekaligus.
                    </p>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>🔄</span> 3. Izin Remedial Siswa
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Pada tabel Rekap Nilai, klik <strong>Izinkan Remedial</strong> untuk siswa yang belum tuntas mencapai KKM.
                    </p>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>📊</span> 4. Ekspor Nilai ke Excel
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Gunakan tombol <strong>Unduh CSV / Excel</strong> untuk mengunduh rekapitulasi nilai rapor kelas.
                    </p>
                </div>
            </div>

        <?php elseif ($user_role === 'orang_tua'): ?>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>📊</span> 1. Pantau Nilai Transparan
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Lihat perolehan skor nilai dan status kelulusan KKM putra/putri Anda pada setiap paket ujian & latihan.
                    </p>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>🔍</span> 2. Evaluasi Pembahasan
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Buka rincian soal untuk melihat jawaban anak dan pembahasan kunci jawaban yang benar dari guru.
                    </p>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>📈</span> 3. Kemajuan Remedial
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Pantau perbandingan nilai awal dan nilai baru setelah anak Anda menyelesaikan sesi remedial.
                    </p>
                </div>
            </div>

        <?php else: ?>
            <!-- Administrator & Staf -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>🛡️</span> 1. Pengawasan Asesmen
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Supervisi seluruh pelaksanaan UTS, UKK, Ujian Harian, dan Latihan siswa di semua dewan guru.
                    </p>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>📋</span> 2. Rekapitulasi Sekolah
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Pantau tingkat kelulusan KKM dan ekspor rekap nilai semua mata pelajaran ke format spreadsheet Excel.
                    </p>
                </div>
                <div class="rounded-2xl border border-white/5 bg-white/5 p-3.5 space-y-1.5">
                    <strong class="text-white flex items-center gap-1.5 font-bold">
                        <span>⚙️</span> 3. Manajemen Paket & Soal
                    </strong>
                    <p class="text-slate-300 text-[11px] leading-relaxed">
                        Bantu guru dalam pengelolaan bank soal, pengaturan jadwal ujian, dan pengelolaan token akses.
                    </p>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Category Summary Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <?php foreach (EXAM_CATEGORIES as $k => $info): ?>
            <?php 
                $active_cat = ($filter_cat === $k);
            ?>
            <a href="exams.php?category=<?= urlencode($k) ?>" 
               class="rounded-2xl border p-3 transition flex flex-col items-center text-center justify-between <?= $active_cat ? 'border-blue-500 bg-blue-500/20 text-white ring-2 ring-blue-500/50' : 'border-white/10 bg-slate-900/40 text-slate-300 hover:border-white/20 hover:bg-white/5' ?>">
                <span class="text-2xl mb-1"><?= $info['icon'] ?></span>
                <span class="text-xs font-bold leading-tight"><?= htmlspecialchars($info['label']) ?></span>
                <span class="mt-2 text-[10px] uppercase font-semibold px-2 py-0.5 rounded-md <?= $info['badge'] ?>">
                    <?= strtoupper($info['type']) ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Filter Tab & Search Bar -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-white/10 pb-4">
        <!-- Tab Filter -->
        <div class="flex items-center gap-2 overflow-x-auto">
            <a href="exams.php" 
               class="rounded-xl px-3.5 py-2 text-xs sm:text-sm font-semibold whitespace-nowrap transition <?= ($tab === 'all' && $filter_cat === '') ? 'bg-white/15 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                Semua Asesmen
            </a>
            <a href="exams.php?tab=ujian" 
               class="rounded-xl px-3.5 py-2 text-xs sm:text-sm font-semibold whitespace-nowrap transition <?= $tab === 'ujian' ? 'bg-purple-600/30 text-purple-300 border border-purple-500/30' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                📑 Ujian Resmi (UTS, UKK, UH)
            </a>
            <a href="exams.php?tab=latihan" 
               class="rounded-xl px-3.5 py-2 text-xs sm:text-sm font-semibold whitespace-nowrap transition <?= $tab === 'latihan' ? 'bg-emerald-600/30 text-emerald-300 border border-emerald-500/30' : 'text-slate-400 hover:bg-white/5 hover:text-slate-200' ?>">
                🎯 Latihan (Harian, Mingguan, Bulanan)
            </a>
        </div>

        <!-- Search input -->
        <form method="GET" class="flex items-center gap-2 w-full md:w-auto">
            <?php if ($tab !== 'all'): ?><input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>"><?php endif; ?>
            <?php if ($filter_cat !== ''): ?><input type="hidden" name="category" value="<?= htmlspecialchars($filter_cat) ?>"><?php endif; ?>
            <div class="relative flex-1 md:w-64">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari judul / mapel..." 
                       class="w-full rounded-xl border border-white/10 bg-slate-900/60 px-3.5 py-2 text-sm text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none">
            </div>
            <button type="submit" class="rounded-xl bg-white/10 px-3.5 py-2 text-sm font-medium text-white hover:bg-white/15 transition">
                Cari
            </button>
            <?php if ($search !== '' || $filter_cat !== '' || $tab !== 'all'): ?>
                <a href="exams.php" class="rounded-xl border border-white/10 px-3 py-2 text-xs font-semibold text-slate-400 hover:bg-white/5">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Exam Cards List -->
    <?php if (empty($exams_list)): ?>
        <div class="rounded-3xl border border-white/10 bg-slate-900/40 p-12 text-center">
            <span class="text-4xl block mb-3">📭</span>
            <h3 class="text-lg font-bold text-white">Belum Ada Ujian / Latihan</h3>
            <p class="text-sm text-slate-400 mt-1 max-w-md mx-auto">
                Tidak ada data asesmen yang sesuai dengan filter saat ini.
                <?php if ($can_manage): ?>
                    Silakan buat paket ujian atau latihan baru dengan tombol di atas.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php foreach ($exams_list as $e): ?>
                <?php 
                    $cat_info = EXAM_CATEGORIES[$e['category']] ?? ['label' => $e['category'], 'badge' => 'border-slate-500 bg-slate-500/10 text-slate-300', 'icon' => '📝'];
                    $is_submitted = ($user_role === 'siswa' && isset($e['student_submitted_at']) && $e['student_submitted_at'] !== null);
                    $has_passed = ($is_submitted && $e['student_score'] >= $e['passing_grade']);
                    $is_flexible = ($e['duration_minutes'] == 0);

                    // Validasi Waktu Jadwal
                    $now = time();
                    $start_ts = !empty($e['start_time']) ? strtotime($e['start_time']) : null;
                    $due_ts = strtotime($e['due_date']);
                    $is_upcoming = ($start_ts !== null && $now < $start_ts);
                    $is_expired = ($now > $due_ts);

                    // Status Remedial
                    $has_remedial_permission = ($user_role === 'siswa' && !empty($e['remedial_granted']) && $e['remedial_granted'] == 1);
                ?>
                <div class="flex flex-col justify-between rounded-3xl border border-white/10 bg-gradient-to-b from-slate-900/80 to-slate-950 p-5 shadow-xl hover:border-white/20 transition group">
                    <div>
                        <!-- Badges Row -->
                        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                            <span class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-bold <?= $cat_info['badge'] ?>">
                                <span><?= $cat_info['icon'] ?></span>
                                <span><?= htmlspecialchars($cat_info['label']) ?></span>
                            </span>

                            <div class="flex items-center gap-1.5">
                                <?php if (!empty($e['token'])): ?>
                                    <?php if ($can_manage): ?>
                                        <span class="rounded-lg border border-purple-500/30 bg-purple-500/10 px-2 py-0.5 text-[11px] font-bold text-purple-300" title="Token Akses Siswa">
                                            🔑 Token: <?= htmlspecialchars($e['token']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="rounded-lg border border-purple-500/30 bg-purple-500/10 px-2 py-0.5 text-[11px] font-semibold text-purple-300">
                                            🔒 Wajib Token
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($is_flexible): ?>
                                    <span class="rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-2 py-0.5 text-[11px] font-semibold text-emerald-300">
                                        ⚡ Fleksibel
                                    </span>
                                <?php else: ?>
                                    <span class="rounded-lg border border-amber-500/20 bg-amber-500/10 px-2 py-0.5 text-[11px] font-semibold text-amber-300">
                                        ⏱️ <?= $e['duration_minutes'] ?> Mnt
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($e['randomize_questions'])): ?>
                                    <span class="rounded-lg border border-cyan-500/30 bg-cyan-500/10 px-2 py-0.5 text-[11px] font-semibold text-cyan-300" title="Soal Diacak Otomatis">
                                        🔀 Acak
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($e['hide_answers_until_due'])): ?>
                                    <span class="rounded-lg border border-purple-500/30 bg-purple-500/10 px-2 py-0.5 text-[11px] font-semibold text-purple-300" title="Kunci Dirahasiakan Hingga Due Date">
                                        🔒 Kunci Dirahasiakan
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Schedule Status Badge -->
                        <div class="mb-2">
                            <?php if ($is_upcoming): ?>
                                <span class="inline-flex items-center gap-1 rounded-md border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-300">
                                    ⏰ Dibuka: <?= date('d M Y, H:i', $start_ts) ?> WIB
                                </span>
                            <?php elseif ($is_expired): ?>
                                <span class="inline-flex items-center gap-1 rounded-md border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 text-[10px] font-bold text-rose-300">
                                    ⛔ Batas Waktu Berakhir
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-300">
                                    🟢 Sedang Berlangsung
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Title & Subject -->
                        <span class="text-xs font-semibold uppercase tracking-wider text-blue-400">
                            <?= htmlspecialchars($e['subject']) ?>
                        </span>
                        <h2 class="text-lg font-bold text-white mt-1 group-hover:text-blue-300 transition line-clamp-2">
                            <?= htmlspecialchars($e['title']) ?>
                        </h2>

                        <?php if (!empty($e['description'])): ?>
                            <p class="mt-2 text-xs text-slate-400 line-clamp-2 leading-relaxed">
                                <?= htmlspecialchars($e['description']) ?>
                            </p>
                        <?php endif; ?>

                        <!-- Metadata Grid -->
                        <div class="mt-4 grid grid-cols-2 gap-2 border-t border-white/5 pt-3 text-xs text-slate-400">
                            <div>
                                <span class="block text-[10px] text-slate-500">Guru Pembuat:</span>
                                <span class="font-medium text-slate-300"><?= htmlspecialchars($e['teacher_name']) ?></span>
                            </div>
                            <div>
                                <span class="block text-[10px] text-slate-500">KKM Kelulusan:</span>
                                <span class="font-semibold text-white"><?= $e['passing_grade'] ?> / 100</span>
                            </div>
                            <div>
                                <span class="block text-[10px] text-slate-500">Batas Waktu:</span>
                                <span class="font-medium text-slate-300"><?= date('d M Y, H:i', strtotime($e['due_date'])) ?></span>
                            </div>
                            <div>
                                <span class="block text-[10px] text-slate-500">Jumlah Soal:</span>
                                <span class="font-semibold text-blue-400"><?= $e['question_count'] ?> Butir</span>
                            </div>
                        </div>

                        <!-- Status Siswa (Khusus Siswa) -->
                        <?php if ($user_role === 'siswa'): ?>
                            <?php if ($has_remedial_permission): ?>
                                <div class="mt-4 rounded-2xl border border-amber-500/40 bg-amber-500/15 p-3 text-xs text-amber-200">
                                    <div class="flex items-center gap-2">
                                        <span class="text-lg">🔄</span>
                                        <div>
                                            <p class="font-bold text-white">Izin Remedial Diberikan Guru!</p>
                                            <p class="text-[10px] text-amber-300/90">
                                                Nilai sebelumnya: <strong><?= number_format($e['student_score'], 0) ?></strong>. Anda dapat mengerjakan ulang untuk perbaikan nilai.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="mt-4 rounded-2xl border p-3 text-xs flex items-center justify-between <?= $is_submitted ? ($has_passed ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300') : 'border-blue-500/30 bg-blue-500/10 text-blue-300' ?>">
                                    <div class="flex items-center gap-2">
                                        <span class="text-base"><?= $is_submitted ? ($has_passed ? '🎉' : '⚠️') : '📌' ?></span>
                                        <div>
                                            <p class="font-bold">
                                                <?= $is_submitted ? 'Sudah Dikerjakan' : 'Belum Dikerjakan' ?>
                                            </p>
                                            <p class="text-[10px] opacity-80">
                                                <?= $is_submitted ? ($has_passed ? 'Memenuhi KKM' : 'Perlu Remedial') : 'Silakan kerjakan saat jadwal dibuka' ?>
                                            </p>
                                        </div>
                                    </div>
                                    <?php if ($is_submitted): ?>
                                        <div class="text-right">
                                            <span class="text-xs font-semibold block text-slate-400">Nilai</span>
                                            <span class="text-base font-extrabold text-white"><?= number_format($e['student_score'], 0) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons Footer -->
                    <div class="mt-5 border-t border-white/5 pt-4 flex flex-wrap items-center justify-between gap-2">
                        <?php if ($can_manage): ?>
                            <!-- Guru / Admin Actions -->
                            <div class="flex items-center gap-2 w-full sm:w-auto">
                                <a href="exam_questions.php?id=<?= $e['id'] ?>" 
                                   class="flex-1 sm:flex-none text-center rounded-xl bg-white/10 hover:bg-white/20 px-3 py-1.5 text-xs font-semibold text-white transition">
                                    📝 Soal (<?= $e['question_count'] ?>)
                                </a>
                                <a href="exam_results.php?id=<?= $e['id'] ?>" 
                                   class="flex-1 sm:flex-none text-center rounded-xl bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/30 px-3 py-1.5 text-xs font-semibold text-blue-300 transition">
                                    📊 Nilai (<?= $e['submission_count'] ?>)
                                </a>
                            </div>
                            <a href="exams.php?delete_id=<?= $e['id'] ?>" 
                               onclick="return confirm('Apakah Anda yakin ingin menghapus paket ujian/latihan ini beserta semua soalnya?');" 
                               class="rounded-xl border border-red-500/20 bg-red-500/10 hover:bg-red-500/20 p-2 text-xs font-semibold text-red-400 transition" 
                               title="Hapus">
                                🗑️
                            </a>
                        <?php elseif ($user_role === 'siswa'): ?>
                            <!-- Siswa Actions -->
                            <?php if ($has_remedial_permission): ?>
                                <a href="exam_take.php?id=<?= $e['id'] ?>&remedial=1" 
                                   class="w-full text-center rounded-xl bg-amber-600 hover:bg-amber-500 shadow-lg shadow-amber-500/25 px-4 py-2 text-xs font-extrabold text-white transition">
                                    🔄 Kerjakan Remedial Sekarang
                                </a>
                            <?php elseif ($is_submitted): ?>
                                <a href="exam_results.php?id=<?= $e['id'] ?>" 
                                   class="w-full text-center rounded-xl bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/30 px-4 py-2 text-xs font-bold text-emerald-300 transition">
                                    🔍 Lihat Nilai & Pembahasan Soal
                                </a>
                            <?php elseif ($is_upcoming): ?>
                                <button disabled class="w-full text-center rounded-xl bg-slate-800 text-slate-500 px-4 py-2 text-xs font-semibold cursor-not-allowed">
                                    ⏳ Belum Dimulai (<?= date('H:i', $start_ts) ?> WIB)
                                </button>
                            <?php elseif ($is_expired): ?>
                                <button disabled class="w-full text-center rounded-xl bg-slate-800 text-slate-500 px-4 py-2 text-xs font-semibold cursor-not-allowed">
                                    ⛔ Waktu Ujian Berakhir
                                </button>
                            <?php else: ?>
                                <?php if ($e['question_count'] > 0): ?>
                                    <a href="exam_take.php?id=<?= $e['id'] ?>" 
                                       class="w-full text-center rounded-xl bg-blue-600 hover:bg-blue-500 shadow-lg shadow-blue-500/25 px-4 py-2 text-xs font-bold text-white transition">
                                        ✍️ Mulai Kerjakan <?= !empty($e['token']) ? '🔒' : '' ?>
                                    </a>
                                <?php else: ?>
                                    <button disabled class="w-full text-center rounded-xl bg-slate-800 text-slate-500 px-4 py-2 text-xs font-semibold cursor-not-allowed">
                                        ⏳ Soal Belum Tersedia
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <!-- Orang Tua / Staf Actions -->
                            <a href="exam_results.php?id=<?= $e['id'] ?>" 
                               class="w-full text-center rounded-xl bg-white/10 hover:bg-white/20 px-4 py-2 text-xs font-semibold text-slate-200 transition">
                                📊 Lihat Rekap Nilai Siswa
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Modal Tambah Ujian / Latihan (Guru & Admin) -->
<?php if ($can_manage): ?>
<div id="createExamModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm overflow-y-auto">
    <div class="relative w-full max-w-xl rounded-3xl border border-white/15 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between mb-5 border-b border-white/10 pb-4">
            <h2 class="text-xl font-bold text-white flex items-center gap-2">
                <span>➕</span> Buat Paket Ujian / Latihan
            </h2>
            <button onclick="document.getElementById('createExamModal').classList.add('hidden')" 
                    class="text-slate-400 hover:text-white text-lg font-bold">✕</button>
        </div>

        <form method="POST" action="exams.php" class="space-y-4">
            <input type="hidden" name="action" value="create_exam">

            <!-- Kategori Asesmen (6 Pilihan) -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Kategori Asesmen <span class="text-red-400">*</span>
                </label>
                <select name="category" required 
                        class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
                    <optgroup label="📑 UJIAN FORMAL">
                        <option value="uts">Ujian Tengah Semester (UTS)</option>
                        <option value="ukk">Ujian Kenaikan Kelas (UKK)</option>
                        <option value="ujian_harian">Ujian Harian (Fleksibel)</option>
                    </optgroup>
                    <optgroup label="🎯 LATIHAN & DRILL">
                        <option value="latihan_harian">Latihan Harian</option>
                        <option value="latihan_mingguan">Latihan Mingguan</option>
                        <option value="latihan_bulanan">Latihan Bulanan</option>
                    </optgroup>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <!-- Mata Pelajaran -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        Mata Pelajaran <span class="text-red-400">*</span>
                    </label>
                    <input type="text" name="subject" required placeholder="Contoh: Matematika" 
                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>

                <!-- KKM (Passing Grade) -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        KKM Kelulusan (0-100)
                    </label>
                    <input type="number" name="passing_grade" min="0" max="100" value="75" required
                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
                </div>
            </div>

            <!-- Judul Ujian -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Judul Ujian / Latihan <span class="text-red-400">*</span>
                </label>
                <input type="text" name="title" required placeholder="Contoh: UTS Semester Ganjil TA 2026/2027" 
                       class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
            </div>

            <!-- Token Ujian & Durasi -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <!-- Token Ujian -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        Token Ujian (Opsional)
                    </label>
                    <div class="flex items-center gap-1.5">
                        <input type="text" id="tokenInput" name="token" placeholder="Misal: UTS8A" maxlength="15"
                               class="w-full rounded-xl border border-white/10 bg-slate-800 px-3.5 py-2 text-sm text-white font-mono uppercase focus:border-blue-500 focus:outline-none">
                        <button type="button" onclick="generateRandomToken()" 
                                class="shrink-0 rounded-xl bg-white/10 hover:bg-white/20 px-3 py-2 text-xs font-bold text-slate-200 transition" 
                                title="Generate Token Otomatis">
                            🎲 Acak
                        </button>
                    </div>
                    <span class="text-[10px] text-slate-400 mt-1 block">Kosongkan jika ujian bebas token.</span>
                </div>

                <!-- Durasi Pengerjaan -->
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        Durasi Menit (0 = Fleksibel)
                    </label>
                    <input type="number" name="duration_minutes" min="0" max="360" value="60" required
                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                    <span class="text-[10px] text-slate-400 mt-1 block">Isi 0 untuk latihan santai tanpa timer.</span>
                </div>
            </div>

            <!-- Jadwal Waktu: Jam Mulai & Batas Waktu -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        Waktu Mulai Ujian (Start Time)
                    </label>
                    <input type="datetime-local" name="start_time" 
                           value="<?= date('Y-m-d\TH:i') ?>"
                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                    <span class="text-[10px] text-slate-400 mt-1 block">Siswa belum bisa masuk sebelum jam ini.</span>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                        Batas Waktu Selesai (Due Date) <span class="text-red-400">*</span>
                    </label>
                    <input type="datetime-local" name="due_date" required 
                           value="<?= date('Y-m-d\TH:i', strtotime('+5 days')) ?>"
                           class="w-full rounded-xl border border-white/10 bg-slate-800 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                    <span class="text-[10px] text-slate-400 mt-1 block">Ujian ditutup setelah tanggal ini.</span>
                </div>
            </div>

            <!-- Deskripsi / Petunjuk -->
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1.5">
                    Petunjuk Pengerjaan & Cakupan Materi
                </label>
                <textarea name="description" rows="2" placeholder="Tuliskan petunjuk pengerjaan atau bab yang diujikan..." 
                          class="w-full rounded-xl border border-white/10 bg-slate-800 px-4 py-2 text-xs text-white focus:border-blue-500 focus:outline-none"></textarea>
            </div>

            <!-- Acak Soal Checkbox -->
            <div class="rounded-2xl border border-white/10 bg-slate-800/60 p-3.5 flex items-start gap-3">
                <input type="checkbox" name="randomize_questions" id="randomize_questions" value="1" 
                       class="h-4 w-4 mt-0.5 rounded border-white/20 bg-slate-900 text-blue-600 focus:ring-blue-500">
                <label for="randomize_questions" class="text-xs text-slate-300 cursor-pointer">
                    <strong class="text-white block">🔀 Acak Urutan Soal (Shuffle)</strong>
                    Urutan nomor soal akan diacak otomatis untuk tiap siswa agar tidak dapat saling menyontek nomor jawaban.
                </label>
            </div>

            <!-- Sembunyikan Kunci Checkbox -->
            <div class="rounded-2xl border border-white/10 bg-slate-800/60 p-3.5 flex items-start gap-3">
                <input type="checkbox" name="hide_answers_until_due" id="hide_answers_until_due" value="1" checked 
                       class="h-4 w-4 mt-0.5 rounded border-white/20 bg-slate-900 text-purple-600 focus:ring-purple-500">
                <label for="hide_answers_until_due" class="text-xs text-slate-300 cursor-pointer">
                    <strong class="text-white block">🔒 Rahasiakan Kunci Jawaban Hingga Batas Ujian Berakhir</strong>
                    Siswa tidak dapat melihat kunci jawaban dan pembahasan sebelum batas waktu ujian (<em class="text-amber-300">due date</em>) resmi ditutup untuk mencegah kebocoran ke siswa lain.
                </label>
            </div>

            <!-- Submit Button -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('createExamModal').classList.add('hidden')" 
                        class="rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-400 hover:bg-white/5 transition">
                    Batal
                </button>
                <button type="submit" 
                        class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-blue-500 shadow-lg shadow-blue-500/30 transition">
                    Lanjut Isi Butir Soal ➔
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function generateRandomToken() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    let token = '';
    for (let i = 0; i < 5; i++) {
        token += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('tokenInput').value = token;
}
</script>
<!-- Modal Panduan Lengkap Semua Role -->
<div id="allRolesGuideModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md overflow-y-auto">
    <div class="relative w-full max-w-3xl rounded-3xl border border-white/15 bg-slate-900 p-6 sm:p-8 shadow-2xl space-y-6">
        <div class="flex items-center justify-between border-b border-white/10 pb-4">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-600/20 border border-blue-500/30 text-xl text-blue-400">
                    📖
                </span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-white">Panduan Lengkap Modul Ujian & Latihan</h2>
                    <p class="text-xs text-slate-400">Petunjuk praktis hak akses dan alur kerja untuk setiap peran di sekolah.</p>
                </div>
            </div>
            <button type="button" onclick="document.getElementById('allRolesGuideModal').classList.add('hidden')" 
                    class="rounded-xl bg-white/5 hover:bg-white/10 p-2 text-slate-400 hover:text-white transition">
                ✕
            </button>
        </div>

        <!-- Role Tabs in Modal -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 border-b border-white/10 pb-3">
            <button type="button" onclick="switchModalGuideTab('siswa')" id="btnGuideSiswa" 
                    class="rounded-xl py-2 px-3 text-xs font-bold transition bg-blue-600 text-white shadow-sm">
                👨‍🎓 Siswa
            </button>
            <button type="button" onclick="switchModalGuideTab('guru')" id="btnGuideGuru" 
                    class="rounded-xl py-2 px-3 text-xs font-bold transition text-slate-400 hover:text-white">
                👨‍🏫 Guru
            </button>
            <button type="button" onclick="switchModalGuideTab('ortu')" id="btnGuideOrtu" 
                    class="rounded-xl py-2 px-3 text-xs font-bold transition text-slate-400 hover:text-white">
                👪 Orang Tua
            </button>
            <button type="button" onclick="switchModalGuideTab('admin')" id="btnGuideAdmin" 
                    class="rounded-xl py-2 px-3 text-xs font-bold transition text-slate-400 hover:text-white">
                🛡️ Admin & Staf
            </button>
        </div>

        <!-- Content Siswa -->
        <div id="guideContentSiswa" class="space-y-3 text-xs text-slate-300">
            <h4 class="text-sm font-bold text-white flex items-center gap-2">
                <span>🎯</span> Langkah Pengerjaan Siswa
            </h4>
            <ul class="space-y-2.5 list-disc list-inside bg-white/5 p-4 rounded-2xl border border-white/5">
                <li><strong class="text-white">Jadwal Ujian:</strong> Pastikan Anda masuk pada saat jam mulai ujian telah dibuka. Jika belum tiba, layar akan menampilkan countdown waktu pelaksanaan.</li>
                <li><strong class="text-white">Token Akses:</strong> Untuk ujian resmi (UTS/UKK/UH), masukkan token yang dibagikan oleh guru pengawas Anda.</li>
                <li><strong class="text-white">Autosave Real-Time:</strong> Setiap nomor yang Anda pilih otomatis tersimpan di memori browser. Jika perangkat mati atau koneksi putus, jawaban Anda aman dan tidak hilang.</li>
                <li><strong class="text-white">Palet Nomor & Ragu-Ragu:</strong> Gunakan tombol kuning <em class="text-amber-300">Ragu-Ragu</em> jika belum yakin, dan klik kotak nomor di sebelah kanan untuk berpindah soal secara instan.</li>
                <li><strong class="text-white">Aturan Anti-Curang:</strong> Jangan membuka tab lain atau berganti aplikasi saat ujian berlangsung untuk menghindari peringatan kecurangan dan diskualifikasi.</li>
                <li><strong class="text-white">Remedial:</strong> Jika nilai Anda di bawah KKM dan guru memberikan izin perbaikan, tombol <em class="text-amber-300">Kerjakan Remedial</em> akan muncul di akun Anda.</li>
            </ul>
        </div>

        <!-- Content Guru -->
        <div id="guideContentGuru" class="hidden space-y-3 text-xs text-slate-300">
            <h4 class="text-sm font-bold text-white flex items-center gap-2">
                <span>📚</span> Langkah Pengelolaan Guru
            </h4>
            <ul class="space-y-2.5 list-disc list-inside bg-white/5 p-4 rounded-2xl border border-white/5">
                <li><strong class="text-white">Membuat Paket Baru:</strong> Klik <em>+ Buat Ujian / Latihan</em>, pilih 1 dari 6 kategori asesmen, tentukan standar KKM, durasi menit (atau 0 untuk latihan santai), dan centang opsi acak soal (shuffle).</li>
                <li><strong class="text-white">Membuat Soal:</strong> Anda dapat menginput manual butir soal dengan gambar (file upload / link URL), kunci jawaban, serta teks penjelasan materi.</li>
                <li><strong class="text-white">Impor Massal CSV:</strong> Gunakan tombol <em>Unduh Template CSV</em> di halaman Kelola Soal untuk menyiapkan puluhan soal di Excel dan mengunggahnya sekali jalan.</li>
                <li><strong class="text-white">Memberikan Izin Remedial:</strong> Buka halaman Rekap Nilai, klik tombol <em>Izinkan Remedial</em> pada baris siswa yang belum tuntas mencapai KKM.</li>
                <li><strong class="text-white">Ekspor Nilai Kelas:</strong> Klik tombol <em>Unduh CSV / Excel</em> untuk mengunduh rekapitulasi nilai rapor yang siap dicetak atau diolah di Excel.</li>
            </ul>
        </div>

        <!-- Content Ortu -->
        <div id="guideContentOrtu" class="hidden space-y-3 text-xs text-slate-300">
            <h4 class="text-sm font-bold text-white flex items-center gap-2">
                <span>👪</span> Pendampingan Belajar Wali Murid
            </h4>
            <ul class="space-y-2.5 list-disc list-inside bg-white/5 p-4 rounded-2xl border border-white/5">
                <li><strong class="text-white">Transparansi Skor:</strong> Wali murid dapat langsung memantau perolehan nilai putra/putrinya segera setelah ujian diselesaikan.</li>
                <li><strong class="text-white">Status Kelulusan KKM:</strong> Sistem memberikan label hijau <em>Memenuhi KKM</em> atau merah <em>Perlu Remedial</em> secara objektif.</li>
                <li><strong class="text-white">Evaluasi Pembahasan:</strong> Klik <em>Lihat Rekap Nilai</em> untuk membaca butir soal yang dijawab salah oleh anak serta mempelajari pembahasan yang disediakan guru.</li>
                <li><strong class="text-white">Riwayat Nilai Remedial:</strong> Anda dapat melihat perkembangan peningkatan nilai anak dari nilai awal sebelum remedial hingga nilai akhir yang dicapai.</li>
            </ul>
        </div>

        <!-- Content Admin -->
        <div id="guideContentAdmin" class="hidden space-y-3 text-xs text-slate-300">
            <h4 class="text-sm font-bold text-white flex items-center gap-2">
                <span>🛡️</span> Supervisi Asesmen Sekolah
            </h4>
            <ul class="space-y-2.5 list-disc list-inside bg-white/5 p-4 rounded-2xl border border-white/5">
                <li><strong class="text-white">Monitoring Menyeluruh:</strong> Memantau seluruh paket ujian UTS, UKK, dan latihan harian dari seluruh dewan guru.</li>
                <li><strong class="text-white">Audit Nilai & Kepatuhan:</strong> Memeriksa tingkat kelulusan KKM sekolah dan mengekspor rekapitulasi nilai ke format CSV/Excel.</li>
                <li><strong class="text-white">Dukungan Teknis:</strong> Membantu guru dalam pengaturan token, jadwal pelaksanaan, dan pemeliharaan butir soal.</li>
            </ul>
        </div>

        <div class="flex justify-end pt-2 border-t border-white/10">
            <button type="button" onclick="document.getElementById('allRolesGuideModal').classList.add('hidden')" 
                    class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-xs font-bold text-white transition">
                Tutup Panduan
            </button>
        </div>
    </div>
</div>

<script>
function switchModalGuideTab(role) {
    const roles = ['siswa', 'guru', 'ortu', 'admin'];
    roles.forEach(r => {
        const content = document.getElementById('guideContent' + r.charAt(0).toUpperCase() + r.slice(1));
        const btn = document.getElementById('btnGuide' + r.charAt(0).toUpperCase() + r.slice(1));
        if (content && btn) {
            if (r === role) {
                content.classList.remove('hidden');
                btn.className = "rounded-xl py-2 px-3 text-xs font-bold transition bg-blue-600 text-white shadow-sm";
            } else {
                content.classList.add('hidden');
                btn.className = "rounded-xl py-2 px-3 text-xs font-bold transition text-slate-400 hover:text-white";
            }
        }
    });
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
