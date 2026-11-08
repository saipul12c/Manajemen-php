<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'guru';
$can_supervise = in_array($user_role, ['administrator', 'staf'], true);
$can_upload = in_array($user_role, ['guru', 'administrator', 'staf'], true);

if (!$can_upload) {
    header("Location: ../index.php?error=unauthorized");
    exit;
}

$page_title = "Perangkat Ajar & Kurikulum";
$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. TAMBAH DOKUMEN PERANGKAT AJAR
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_doc') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $teacher_id = $can_supervise ? (int)($_POST['teacher_id'] ?? $user_id) : $user_id;
        $subject_name = trim($_POST['subject_name'] ?? '');
        $grade_level = $_POST['grade_level'] ?? '10';
        $academic_year = trim($_POST['academic_year'] ?? '2026/2027');
        $semester = $_POST['semester'] ?? 'Ganjil';
        $doc_type = $_POST['doc_type'] ?? 'modul_ajar';
        $title = trim($_POST['title'] ?? '');
        $file_url = trim($_POST['file_url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'draft';

        // Support direct file upload dengan validasi keamanan terpusat
        if (isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];
            $uploadRes = saveSecureUpload(
                $_FILES['doc_file'],
                'curriculum',
                $allowed,
                [],
                25 * 1024 * 1024,
                'doc'
            );
            if ($uploadRes['success']) {
                $file_url = $uploadRes['path'];
            } else {
                $message = $uploadRes['error'];
                $message_type = "error";
            }
        }

        if (empty($title) || empty($subject_name)) {
            $message = "Judul dokumen dan mata pelajaran wajib diisi.";
            $message_type = "error";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO curriculum_documents 
                (teacher_id, subject_name, grade_level, academic_year, semester, doc_type, title, file_url, description, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $teacher_id, $subject_name, $grade_level, $academic_year, $semester,
                $doc_type, $title, $file_url ?: null, $description ?: null, $status
            ]);

            logActivity($pdo, 'CREATE_CURRICULUM_DOC', "Menambahkan perangkat ajar: $title ($doc_type)");
            $message = "Perangkat pembelajaran berhasil diunggah!";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 2. AJUKAN SUPERVISI OLEH GURU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_for_review') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        $stmt_chk = $pdo->prepare("SELECT teacher_id FROM curriculum_documents WHERE id = ?");
        $stmt_chk->execute([$doc_id]);
        $owner_id = (int)$stmt_chk->fetchColumn();

        if ($doc_id > 0 && ($can_supervise || $owner_id === $user_id)) {
            $pdo->prepare("UPDATE curriculum_documents SET status = 'diajukan' WHERE id = ?")->execute([$doc_id]);
            logActivity($pdo, 'SUBMIT_CURRICULUM_DOC', "Mengajukan supervisi perangkat ajar ID $doc_id");
            $message = "Dokumen berhasil diajukan untuk supervisi kurikulum/kepala sekolah.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 3. SUPERVISI OLEH ADMIN / KURIKULUM (ACC / REVISI)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'supervise_doc' && $can_supervise) {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        $status = $_POST['status'] ?? 'disetujui';
        $review_notes = trim($_POST['review_notes'] ?? '');

        if ($doc_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE curriculum_documents SET 
                    status = ?, review_notes = ?, reviewed_by = ?, reviewed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$status, $review_notes ?: null, $user_id, $doc_id]);
            logActivity($pdo, 'SUPERVISE_CURRICULUM_DOC', "Supervisi perangkat ajar ID $doc_id status: $status");
            $message = "Hasil supervisi dan catatan telaah kurikulum berhasil disimpan.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 4. HAPUS DOKUMEN
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_doc') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        $stmt_chk = $pdo->prepare("SELECT teacher_id FROM curriculum_documents WHERE id = ?");
        $stmt_chk->execute([$doc_id]);
        $owner_id = (int)$stmt_chk->fetchColumn();

        if ($doc_id > 0 && ($can_supervise || $owner_id === $user_id)) {
            $pdo->prepare("DELETE FROM curriculum_documents WHERE id = ?")->execute([$doc_id]);
            logActivity($pdo, 'DELETE_CURRICULUM_DOC', "Menghapus perangkat ajar ID $doc_id");
            $message = "Perangkat pembelajaran berhasil dihapus.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 5. QUERY LIST DATA & FILTER
// -------------------------------------------------------------
$subjects = $pdo->query("SELECT name FROM subjects ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
$teachers = $pdo->query("SELECT id, name FROM users WHERE role = 'guru' ORDER BY name ASC")->fetchAll();

$filter_type = $_GET['doc_type'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_teacher = isset($_GET['teacher_id']) && is_numeric($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : ($can_supervise ? 0 : $user_id);

$query_sql = "
    SELECT cd.*, u.name as teacher_name, rev.name as reviewer_name
    FROM curriculum_documents cd
    JOIN users u ON cd.teacher_id = u.id
    LEFT JOIN users rev ON cd.reviewed_by = rev.id
    WHERE 1=1
";
$params = [];

if (!$can_supervise) {
    $query_sql .= " AND cd.teacher_id = ?";
    $params[] = $user_id;
} elseif ($filter_teacher > 0) {
    $query_sql .= " AND cd.teacher_id = ?";
    $params[] = $filter_teacher;
}

if (!empty($filter_type)) {
    $query_sql .= " AND cd.doc_type = ?";
    $params[] = $filter_type;
}

if (!empty($filter_status)) {
    $query_sql .= " AND cd.status = ?";
    $params[] = $filter_status;
}

$query_sql .= " ORDER BY cd.id DESC";

$stmt = $pdo->prepare($query_sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Mapping tipe dokumen
$doc_type_labels = [
    'modul_ajar' => 'Modul Ajar',
    'rpp' => 'RPP / Desain KBM',
    'silabus' => 'Silabus / ATP',
    'prota' => 'Program Tahunan (Prota)',
    'promes' => 'Program Semester (Promes)',
    'kktp' => 'KKTP / KKM',
    'lainnya' => 'Dokumen Lain'
];

require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Header Page -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-slate-900/60 p-6 rounded-3xl border border-white/10 backdrop-blur-xl relative overflow-hidden">
        <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-gradient-to-br from-indigo-600/20 to-purple-600/20 rounded-full blur-2xl pointer-events-none"></div>
        <div class="relative z-10">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 text-xs font-semibold uppercase tracking-wider mb-2">
                <i class="fa-solid fa-graduation-cap"></i> Administrasi Kurikulum
            </div>
            <h1 class="text-2xl font-black text-white tracking-tight">Perangkat Ajar & Kurikulum</h1>
            <p class="text-slate-400 text-sm mt-1">Pengarsipan Modul Ajar, RPP, Prota, Promes, dan proses supervisi akademik oleh kurikulum/kepala sekolah.</p>
        </div>
        <div class="flex items-center gap-2.5 relative z-10">
            <button onclick="openModal('modalCreateDoc')" class="px-4 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white text-xs font-bold flex items-center gap-2 transition shadow-lg shadow-indigo-500/25">
                <i class="fa-solid fa-cloud-arrow-up"></i> Unggah Perangkat Ajar
            </button>
        </div>
    </div>

    <!-- Feedback Message Alert -->
    <?php if (!empty($message)): ?>
        <div class="p-4 rounded-2xl flex items-center gap-3 border <?= $message_type === 'success' ? 'bg-emerald-950/40 border-emerald-500/40 text-emerald-300' : 'bg-rose-950/40 border-rose-500/40 text-rose-300' ?>">
            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-rose-400' ?> text-lg shrink-0"></i>
            <span class="text-sm font-medium"><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="p-4 rounded-2xl bg-slate-900/40 border border-white/5 no-print">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Kategori Dokumen</label>
                <select name="doc_type" class="w-full bg-slate-950/60 border border-white/10 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-indigo-500">
                    <option value="">-- Semua Perangkat --</option>
                    <?php foreach ($doc_type_labels as $key => $lbl): ?>
                        <option value="<?= $key ?>" <?= $filter_type === $key ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Status Supervisi</label>
                <select name="status" class="w-full bg-slate-950/60 border border-white/10 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-indigo-500">
                    <option value="">-- Semua Status --</option>
                    <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draf (Belum Diajukan)</option>
                    <option value="diajukan" <?= $filter_status === 'diajukan' ? 'selected' : '' ?>>Diajukan (Menunggu Supervisi)</option>
                    <option value="disetujui" <?= $filter_status === 'disetujui' ? 'selected' : '' ?>>Disetujui</option>
                    <option value="revisi" <?= $filter_status === 'revisi' ? 'selected' : '' ?>>Perlu Revisi</option>
                </select>
            </div>
            <?php if ($can_supervise): ?>
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Dewan Guru</label>
                <select name="teacher_id" class="w-full bg-slate-950/60 border border-white/10 rounded-xl px-3.5 py-2 text-xs text-white focus:outline-none focus:border-indigo-500">
                    <option value="0">-- Semua Guru --</option>
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $filter_teacher === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="flex items-center gap-2">
                <button type="submit" class="flex-1 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold transition">
                    <i class="fa-solid fa-filter mr-1"></i> Terapkan
                </button>
                <a href="curriculum.php" class="px-3 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 text-xs font-medium transition" title="Reset Filter">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Cards Grid Perangkat Ajar -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php if (empty($documents)): ?>
            <div class="col-span-full py-16 text-center text-slate-400 bg-slate-900/40 rounded-3xl border border-white/5">
                <div class="h-16 w-16 mx-auto mb-3 rounded-2xl bg-white/5 flex items-center justify-center text-2xl text-slate-500">
                    <i class="fa-solid fa-file-circle-check"></i>
                </div>
                <p class="text-sm font-medium text-slate-300">Belum ada perangkat ajar yang diunggah.</p>
                <p class="text-xs text-slate-500 mt-1">Gunakan tombol "Unggah Perangkat Ajar" di atas untuk menambahkan dokumen administrasi guru.</p>
            </div>
        <?php else: ?>
            <?php foreach ($documents as $doc): 
                $status_bg = 'bg-slate-800 text-slate-400 border-white/10';
                $status_label = 'Draf';
                if ($doc['status'] === 'diajukan') {
                    $status_bg = 'bg-amber-500/10 text-amber-400 border-amber-500/30';
                    $status_label = 'Menunggu Supervisi';
                } elseif ($doc['status'] === 'disetujui') {
                    $status_bg = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30';
                    $status_label = 'Disetujui Kurikulum';
                } elseif ($doc['status'] === 'revisi') {
                    $status_bg = 'bg-rose-500/10 text-rose-400 border-rose-500/30';
                    $status_label = 'Perlu Revisi';
                }
            ?>
                <div class="p-5 rounded-3xl bg-slate-900/60 border border-white/10 backdrop-blur-xl flex flex-col justify-between hover:border-indigo-500/30 transition group shadow-lg">
                    <div>
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider border <?= $status_bg ?>">
                                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                <?= $status_label ?>
                            </span>
                            <span class="text-[10px] text-slate-400 font-semibold px-2 py-0.5 rounded bg-white/5 border border-white/5">
                                <?= $doc_type_labels[$doc['doc_type']] ?? $doc['doc_type'] ?>
                            </span>
                        </div>

                        <h3 class="text-sm font-bold text-white group-hover:text-indigo-300 transition line-clamp-2 mb-1.5">
                            <?= htmlspecialchars($doc['title']) ?>
                        </h3>

                        <div class="space-y-1 text-[11px] text-slate-400 mb-3">
                            <p><i class="fa-solid fa-book-bookmark text-indigo-400 w-4"></i> <?= htmlspecialchars($doc['subject_name']) ?> (Tingkat <?= htmlspecialchars($doc['grade_level']) ?>)</p>
                            <p><i class="fa-solid fa-calendar text-indigo-400 w-4"></i> Tahun <?= htmlspecialchars($doc['academic_year']) ?> - <?= htmlspecialchars($doc['semester']) ?></p>
                            <?php if ($can_supervise): ?>
                                <p><i class="fa-solid fa-user-tie text-indigo-400 w-4"></i> Guru: <?= htmlspecialchars($doc['teacher_name']) ?></p>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($doc['description'])): ?>
                            <p class="text-xs text-slate-400/90 mb-3 line-clamp-2 bg-slate-950/40 p-2.5 rounded-xl border border-white/5">
                                <?= htmlspecialchars($doc['description']) ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($doc['review_notes'])): ?>
                            <div class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-200 text-xs mb-3">
                                <p class="text-[10px] uppercase font-bold text-amber-400 flex items-center gap-1">
                                    <i class="fa-solid fa-clipboard-check"></i> Catatan Telaah Supervisi:
                                </p>
                                <p class="text-[11px] mt-1 text-slate-300"><?= htmlspecialchars($doc['review_notes']) ?></p>
                                <p class="text-[9px] text-slate-400 mt-1 italic">Oleh: <?= htmlspecialchars($doc['reviewer_name'] ?? 'Penyelia') ?> pada <?= date('d/m/Y H:i', strtotime($doc['reviewed_at'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="pt-4 border-t border-white/5 flex items-center justify-between gap-2 mt-2">
                        <div>
                            <?php if (!empty($doc['file_url'])): 
                                $doc_link = resolveAssetUrl($doc['file_url'], '../../');
                            ?>
                                <a href="<?= htmlspecialchars($doc_link) ?>" target="_blank"
                                   class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-400 border border-indigo-500/30 text-xs font-semibold transition">
                                    <i class="fa-solid fa-file-arrow-down"></i> Berkas
                                </a>
                            <?php else: ?>
                                <span class="text-[10px] text-slate-500 italic">Tanpa Berkas</span>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center gap-1.5">
                            <?php if ($doc['status'] === 'draft' && ((int)$doc['teacher_id'] === $user_id || $can_supervise)): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="action" value="submit_for_review">
                                    <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1.5 rounded-xl bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 border border-amber-500/30 text-xs font-semibold transition" title="Ajukan untuk ditelaah">
                                        <i class="fa-solid fa-paper-plane mr-1"></i> Ajukan
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($can_supervise): ?>
                                <button type="button" onclick='openSuperviseModal(<?= json_encode($doc) ?>)'
                                        class="px-2.5 py-1.5 rounded-xl bg-purple-500/20 hover:bg-purple-500/30 text-purple-300 border border-purple-500/30 text-xs font-semibold transition">
                                    <i class="fa-solid fa-stamp mr-1"></i> Telaah
                                </button>
                            <?php endif; ?>

                            <?php if ($can_supervise || (int)$doc['teacher_id'] === $user_id): ?>
                                <form method="POST" onsubmit="return confirm('Hapus dokumen perangkat ajar ini?')" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="action" value="delete_doc">
                                    <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
                                    <button type="submit" class="p-1.5 rounded-xl text-slate-500 hover:text-rose-400 hover:bg-rose-500/10 transition" title="Hapus">
                                        <i class="fa-solid fa-trash text-xs"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: UNGGAH PERANGKAT AJAR -->
<!-- ============================================================== -->
<div id="modalCreateDoc" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-xl w-full p-6 text-slate-100 shadow-2xl relative my-8">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-4">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-cloud-arrow-up text-indigo-400"></i>
                Unggah Perangkat Ajar Baru
            </h3>
            <button onclick="closeModal('modalCreateDoc')" class="text-slate-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="create_doc">

            <?php if ($can_supervise): ?>
            <div>
                <label class="block font-semibold text-slate-300 mb-1">Guru Pengampu</label>
                <select name="teacher_id" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-indigo-500">
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= $t['id'] == $user_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Jenis Perangkat <span class="text-rose-400">*</span></label>
                    <select name="doc_type" required class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-indigo-500">
                        <?php foreach ($doc_type_labels as $key => $lbl): ?>
                            <option value="<?= $key ?>"><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Mata Pelajaran <span class="text-rose-400">*</span></label>
                    <input type="text" name="subject_name" required placeholder="Contoh: Matematika" list="subjectListDoc"
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-indigo-500">
                    <datalist id="subjectListDoc">
                        <?php foreach ($subjects as $s_name): ?>
                            <option value="<?= htmlspecialchars($s_name) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Judul Dokumen <span class="text-rose-400">*</span></label>
                <input type="text" name="title" required placeholder="Contoh: Modul Ajar Matematika Fase E - Fungsi & Aljabar"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-indigo-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Jenjang / Tingkat</label>
                    <select name="grade_level" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-indigo-500">
                        <option value="10">Kelas 10 (Fase E)</option>
                        <option value="11">Kelas 11 (Fase F)</option>
                        <option value="12">Kelas 12 (Fase F)</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Tahun Ajaran</label>
                    <input type="text" name="academic_year" value="2026/2027" required
                           class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-indigo-500">
                </div>
                <div>
                    <label class="block font-semibold text-slate-300 mb-1">Semester</label>
                    <select name="semester" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-indigo-500">
                        <option value="Ganjil">Semester Ganjil</option>
                        <option value="Genap">Semester Genap</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Unggah Berkas Dokumen (PDF, Word, Excel, PPT)</label>
                <input type="file" name="doc_file"
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white file:mr-3 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-indigo-600 file:text-white hover:file:bg-indigo-500 cursor-pointer">
                <p class="text-[10px] text-slate-500 mt-1">Atau gunakan URL tautan penyimpanan cloud (Google Drive/OneDrive) di bawah:</p>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Tautan URL Berkas (Cloud Link)</label>
                <input type="url" name="file_url" placeholder="https://drive.google.com/file/d/..."
                       class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-indigo-500">
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Keterangan / Deskripsi Singkat</label>
                <textarea name="description" rows="2" placeholder="Cakupan alokasi waktu JP, tujuan pembelajaran umum, dsb..."
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-white focus:outline-none focus:border-indigo-500"></textarea>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Status Awal</label>
                <select name="status" class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-indigo-500">
                    <option value="draft">Simpan sebagai Draf</option>
                    <option value="diajukan">Langsung Ajukan untuk Supervisi</option>
                </select>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-white/10">
                <button type="button" onclick="closeModal('modalCreateDoc')" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition">
                    Batal
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold transition shadow-lg shadow-indigo-500/25">
                    Unggah Dokumen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: SUPERVISI / TELAAH KURIKULUM (ADMIN / KURIKULUM) -->
<!-- ============================================================== -->
<?php if ($can_supervise): ?>
<div id="modalSuperviseDoc" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm hidden p-4 overflow-y-auto">
    <div class="bg-slate-900 border border-white/10 rounded-3xl max-w-lg w-full p-6 text-slate-100 shadow-2xl relative my-8">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-4">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-stamp text-purple-400"></i>
                Supervisi Perangkat Ajar
            </h3>
            <button onclick="closeModal('modalSuperviseDoc')" class="text-slate-400 hover:text-white p-1">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4 text-xs">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="supervise_doc">
            <input type="hidden" name="doc_id" id="sup_doc_id">

            <div>
                <p class="text-slate-400 text-xs">Dokumen yang ditelaah:</p>
                <p class="text-sm font-bold text-white mt-0.5" id="sup_doc_title"></p>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Keputusan Supervisi <span class="text-rose-400">*</span></label>
                <select name="status" id="sup_doc_status" required class="w-full bg-slate-950 border border-white/10 rounded-xl px-3 py-2 text-white focus:outline-none focus:border-purple-500">
                    <option value="disetujui">Disetujui (Memenuhi Standar Kurikulum)</option>
                    <option value="revisi">Perlu Revisi (Ada Catatan Perbaikan)</option>
                    <option value="draft">Kembalikan ke Draf</option>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-slate-300 mb-1">Catatan Telaah & Rekomendasi Supervisi</label>
                <textarea name="review_notes" id="sup_review_notes" rows="4" placeholder="Berikan catatan konstruktif mengenai tujuan pembelajaran, rubrik asesmen, atau diferensiasi konten..."
                          class="w-full bg-slate-950 border border-white/10 rounded-xl p-3 text-white focus:outline-none focus:border-purple-500"></textarea>
            </div>

            <div class="flex items-center justify-end gap-2 pt-3 border-t border-white/10">
                <button type="button" onclick="closeModal('modalSuperviseDoc')" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 transition">
                    Batal
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-bold transition shadow-lg shadow-purple-500/25">
                    Simpan Hasil Supervisi
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
function openSuperviseModal(doc) {
    document.getElementById('sup_doc_id').value = doc.id;
    document.getElementById('sup_doc_title').innerText = doc.title + ' (' + doc.subject_name + ')';
    document.getElementById('sup_doc_status').value = doc.status === 'diajukan' ? 'disetujui' : doc.status;
    document.getElementById('sup_review_notes').value = doc.review_notes || '';
    openModal('modalSuperviseDoc');
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
