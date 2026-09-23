<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['guru', 'administrator'], true);

$message = "";
$message_type = "";

// Buat folder upload jika belum ada
$upload_dir = __DIR__ . "/../../uploads/materials";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// -------------------------------------------------------------
// 1. TAMBAH MATERI PEMBELAJARAN (Guru & Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_material' && $can_manage) {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $title = trim($_POST['title'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $file_type = $_POST['file_type'] ?? 'document';
        $link_url = trim($_POST['link_url'] ?? '');
        $file_url = null;

        // Handle File Upload jika ada
        if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp = $_FILES['material_file']['tmp_name'];
            $file_name = $_FILES['material_file']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_exts = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'mp4', 'png', 'jpg'];

            if (!in_array($file_ext, $allowed_exts, true)) {
                $message = "Format file tidak didukung. Gunakan PDF, Office, ZIP, atau MP4.";
                $message_type = "error";
            } else {
                $new_file_name = 'mat_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
                $dest_path = $upload_dir . '/' . $new_file_name;
                if (move_uploaded_file($file_tmp, $dest_path)) {
                    $file_url = 'uploads/materials/' . $new_file_name;
                }
            }
        }

        if (empty($title) || empty($subject)) {
            $message = "Judul materi dan mata pelajaran wajib diisi.";
            $message_type = "error";
        } elseif (empty($message)) {
            $stmt_in = $pdo->prepare("
                INSERT INTO learning_materials (title, subject, class_id, teacher_id, description, file_url, link_url, file_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_in->execute([$title, $subject, $class_id, $user_id, $description ?: null, $file_url, $link_url ?: null, $file_type]);

            logActivity($pdo, 'CREATE_MATERIAL', "Mengunggah materi pembelajaran $title");
            $message = "Materi pembelajaran berhasil dipublikasikan.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 2. HAPUS MATERI (Guru yang bersangkutan & Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_material' && $can_manage) {
    if (validateCsrfToken()) {
        $delete_id = (int)($_POST['delete_id'] ?? 0);
        $query_del = "DELETE FROM learning_materials WHERE id = ?";
        $params_del = [$delete_id];
        if ($user_role !== 'administrator') {
            $query_del .= " AND teacher_id = ?";
            $params_del[] = $user_id;
        }
        $stmt_del = $pdo->prepare($query_del);
        $stmt_del->execute($params_del);

        logActivity($pdo, 'DELETE_MATERIAL', "Menghapus materi pembelajaran ID $delete_id");
        $message = "Materi pembelajaran berhasil dihapus.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 3. TRACK DOWNLOAD / VIEW COUNTER
// -------------------------------------------------------------
if (isset($_GET['download']) && is_numeric($_GET['download'])) {
    $dl_id = (int)$_GET['download'];
    $pdo->prepare("UPDATE learning_materials SET download_count = download_count + 1 WHERE id = ?")->execute([$dl_id]);
    $stmt_file = $pdo->prepare("SELECT file_url, link_url FROM learning_materials WHERE id = ?");
    $stmt_file->execute([$dl_id]);
    $target_m = $stmt_file->fetch();

    if ($target_m) {
        if (!empty($target_m['file_url'])) {
            $full_path = __DIR__ . '/../../' . $target_m['file_url'];
            if (file_exists($full_path)) {
                header('Location: ../../' . $target_m['file_url']);
                exit;
            }
        } elseif (!empty($target_m['link_url'])) {
            header('Location: ' . $target_m['link_url']);
            exit;
        }
    }
}

// -------------------------------------------------------------
// 4. FILTER & QUERY MATERI
// -------------------------------------------------------------
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();
$subjects = $pdo->query("SELECT DISTINCT name FROM subjects ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);

$filter_subject = trim($_GET['subject'] ?? '');
$filter_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT m.*, u.name as teacher_name, c.name as class_name
    FROM learning_materials m
    JOIN users u ON m.teacher_id = u.id
    LEFT JOIN classes c ON m.class_id = c.id
    WHERE 1=1
";
$params = [];

if ($filter_subject !== '') {
    $sql .= " AND m.subject = ?";
    $params[] = $filter_subject;
}

if ($filter_class !== null) {
    $sql .= " AND (m.class_id = ? OR m.class_id IS NULL)";
    $params[] = $filter_class;
}

if ($search !== '') {
    $sql .= " AND (m.title LIKE ? OR m.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY m.id DESC";
$stmt_mat = $pdo->prepare($sql);
$stmt_mat->execute($params);
$materials = $stmt_mat->fetchAll();

$page_title = "Materi Pembelajaran & E-Learning";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Header & Action -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2.5">
                <i class="fa-solid fa-book-open text-blue-400"></i> Materi & Modul Pembelajaran
            </h1>
            <p class="text-sm text-slate-400 mt-1">
                Pusat referensi bahan ajar, slide presentasi, ringkasan rumus, dan video pembelajaran digital.
            </p>
        </div>

        <?php if ($can_manage): ?>
            <button onclick="document.getElementById('modalAddMaterial').classList.remove('hidden')" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-blue-500/25 transition hover:bg-blue-500 cursor-pointer self-start sm:self-auto">
                <i class="fa-solid fa-plus"></i> Unggah Bahan Ajar
            </button>
        <?php endif; ?>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($message)): ?>
        <div class="rounded-xl border p-4 <?= $message_type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/20 bg-rose-500/10 text-rose-300' ?> text-sm">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Filter & Search Bar -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <div>
                <input type="text" name="q" placeholder="Cari judul materi..." value="<?= htmlspecialchars($search) ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none">
            </div>

            <div>
                <select name="subject" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                    <option value="">Semua Mata Pelajaran</option>
                    <?php foreach ($subjects as $sb): ?>
                        <option value="<?= htmlspecialchars($sb) ?>" <?= $filter_subject === $sb ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sb) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <select name="class_id" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                    <option value="">Semua Tingkat / Kelas</option>
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?= $cl['id'] ?>" <?= ($filter_class !== null && $filter_class == $cl['id']) ? 'selected' : '' ?>>
                            Kelas <?= htmlspecialchars($cl['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-xl bg-slate-800 px-4 py-2 text-sm font-medium text-slate-200 hover:bg-slate-700 transition cursor-pointer">
                    <i class="fa-solid fa-magnifying-glass"></i> Filter
                </button>
                <?php if ($filter_subject !== '' || $filter_class !== null || $search !== ''): ?>
                    <a href="materials.php" class="rounded-xl border border-white/10 px-3 py-2 text-sm text-slate-400 hover:text-white transition">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Materials Grid -->
    <?php if (empty($materials)): ?>
        <div class="rounded-2xl border border-white/10 bg-slate-900/40 p-12 text-center text-slate-400">
            <span class="text-4xl block mb-2 text-slate-600"><i class="fa-solid fa-book"></i></span>
            <p class="text-base font-semibold text-slate-300">Belum ada materi pembelajaran yang ditemukan</p>
            <p class="text-xs text-slate-500 mt-1">Coba sesuaikan kata kunci pencarian atau filter mata pelajaran.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php foreach ($materials as $m): ?>
                <?php
                    $is_video = ($m['file_type'] === 'video' || (strpos($m['link_url'] ?? '', 'youtube.com') !== false || strpos($m['link_url'] ?? '', 'youtu.be') !== false));
                    $type_badge = match($m['file_type']) {
                        'video' => 'border-rose-500/30 bg-rose-500/10 text-rose-300',
                        'link'  => 'border-blue-500/30 bg-blue-500/10 text-blue-300',
                        default => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300'
                    };
                    $type_icon = match($m['file_type']) {
                        'video' => '<i class="fa-solid fa-video mr-1"></i> Video',
                        'link'  => '<i class="fa-solid fa-link mr-1"></i> Tautan Web',
                        default => '<i class="fa-regular fa-file-lines mr-1"></i> Dokumen'
                    };
                ?>
                <div class="flex flex-col justify-between rounded-2xl border border-white/10 bg-slate-900/50 p-5 shadow-lg backdrop-blur hover:border-white/20 transition">
                    <div>
                        <!-- Top Meta -->
                        <div class="flex items-center justify-between gap-2 mb-3">
                            <span class="rounded-lg border px-2 py-0.5 text-[11px] font-semibold <?= $type_badge ?>">
                                <?= $type_icon ?>
                            </span>
                            <span class="text-[11px] font-medium text-slate-400">
                                <i class="fa-solid fa-school text-slate-500 mr-1"></i><?= htmlspecialchars($m['class_name'] ?: 'Semua Kelas') ?>
                            </span>
                        </div>

                        <!-- Title & Subject -->
                        <h3 class="text-base font-bold text-white tracking-tight leading-snug line-clamp-2">
                            <?= htmlspecialchars($m['title']) ?>
                        </h3>
                        <div class="flex items-center gap-2 mt-1.5">
                            <span class="text-xs font-semibold text-blue-400">
                                <i class="fa-solid fa-book-bookmark mr-1"></i><?= htmlspecialchars($m['subject']) ?>
                            </span>
                            <span class="text-slate-600">•</span>
                            <span class="text-xs text-slate-400">
                                <i class="fa-solid fa-chalkboard-user mr-1 text-slate-500"></i><?= htmlspecialchars($m['teacher_name']) ?>
                            </span>
                        </div>

                        <!-- Description -->
                        <?php if (!empty($m['description'])): ?>
                            <p class="text-xs text-slate-400 mt-3 line-clamp-3 leading-relaxed">
                                <?= nl2br(htmlspecialchars($m['description'])) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Bottom Action & Download Count -->
                    <div class="mt-5 pt-4 border-t border-white/5 flex items-center justify-between">
                        <span class="text-[11px] text-slate-500 flex items-center gap-1">
                            <i class="fa-solid fa-download mr-1"></i> <?= number_format($m['download_count']) ?> kali diakses
                        </span>

                        <div class="flex items-center gap-2">
                            <?php if (!empty($m['file_url']) || !empty($m['link_url'])): ?>
                                <a href="materials.php?download=<?= $m['id'] ?>" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600/90 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-500 transition">
                                    <span><?= $is_video ? '<i class="fa-solid fa-play mr-1"></i>Tonton' : '<i class="fa-solid fa-arrow-down-to-bracket mr-1"></i>Buka / Unduh' ?></span>
                                </a>
                            <?php endif; ?>

                            <?php if ($user_role === 'administrator' || ($user_role === 'guru' && $m['teacher_id'] == $user_id)): ?>
                                <form method="POST" onsubmit="return confirm('Hapus materi pembelajaran ini?');" class="inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_material">
                                    <input type="hidden" name="delete_id" value="<?= $m['id'] ?>">
                                    <button type="submit" title="Hapus Materi" class="rounded-lg p-1.5 text-slate-500 hover:text-rose-400 hover:bg-rose-500/10 transition cursor-pointer">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Modal Tambah Materi Pembelajaran (Guru & Admin) -->
<?php if ($can_manage): ?>
<div id="modalAddMaterial" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm hidden">
    <div class="w-full max-w-lg rounded-2xl border border-white/10 bg-slate-900 p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-book-open text-blue-400"></i> Unggah Materi Pembelajaran
            </h3>
            <button onclick="document.getElementById('modalAddMaterial').classList.add('hidden')" class="text-slate-400 hover:text-white cursor-pointer text-lg font-bold">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_material">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Judul Materi Pembelajaran</label>
                <input type="text" name="title" required placeholder="Contoh: Modul Ekskresi & Respirasi Kelas 11" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Mata Pelajaran</label>
                    <input list="subjects_list" name="subject" required placeholder="Pilih atau ketik mapel" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                    <datalist id="subjects_list">
                        <?php foreach ($subjects as $sb): ?>
                            <option value="<?= htmlspecialchars($sb) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kelas Target</label>
                    <select name="class_id" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                        <option value="">Semua Kelas</option>
                        <?php foreach ($classes as $cl): ?>
                            <option value="<?= $cl['id'] ?>">Kelas <?= htmlspecialchars($cl['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Jenis Bahan Ajar</label>
                    <select name="file_type" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                        <option value="document">Dokumen / E-Book (PDF/PPT/Word)</option>
                        <option value="video">Video Edukasi (YouTube/MP4)</option>
                        <option value="link">Tautan Web Eksternal</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">File Dokumen (Opsional)</label>
                    <input type="file" name="material_file" class="w-full rounded-xl border border-white/10 bg-slate-950 px-2 py-1.5 text-xs text-slate-300 file:mr-2 file:rounded-lg file:border-0 file:bg-blue-600 file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-white">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Tautan Eksternal / Video YouTube (Opsional)</label>
                <input type="url" name="link_url" placeholder="https://www.youtube.com/watch?v=... atau Google Drive" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Ringkasan / Petunjuk Belajar</label>
                <textarea name="description" rows="3" placeholder="Tuliskan petunjuk pembelajaran bagi siswa..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalAddMaterial').classList.add('hidden')" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/5 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 cursor-pointer">
                    Unggah & Terbitkan
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
