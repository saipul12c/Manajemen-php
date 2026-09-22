<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['administrator', 'staf'], true);

$message = "";
$message_type = "";

// Pastikan folder upload lampiran ada
$upload_dir = __DIR__ . "/../../uploads/announcements/";
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

// -------------------------------------------------------------
// 1. TAMBAH PENGUMUMAN BARU (Admin & Staf)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_announcement') {
    if (!$can_manage) {
        header("Location: announcements.php?error=unauthorized");
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $target_role = trim($_POST['target_role'] ?? 'semua');
    $category = trim($_POST['category'] ?? 'umum');
    $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
    $status = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
    $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

    $valid_targets = ['semua', 'guru', 'siswa', 'orang_tua', 'staf'];
    $valid_categories = ['umum', 'akademik', 'kegiatan', 'penting', 'darurat'];
    if (!in_array($target_role, $valid_targets, true)) $target_role = 'semua';
    if (!in_array($category, $valid_categories, true)) $category = 'umum';

    if ($title === '' || $content === '') {
        $message = "Judul dan isi pengumuman wajib diisi.";
        $message_type = "error";
    } else {
        // Handle file upload
        $attachment_url = null;
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar'];
            $file_ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            if (in_array($file_ext, $allowed_ext, true) && $_FILES['attachment']['size'] <= 10 * 1024 * 1024) {
                $safe_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['attachment']['name']));
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $safe_name)) {
                    $attachment_url = $safe_name;
                }
            } else {
                $message = "Format file tidak didukung atau ukuran melebihi 10MB.";
                $message_type = "error";
            }
        }

        if ($message === '') {
            $stmt_ins = $pdo->prepare("INSERT INTO announcements (title, content, target_role, category, is_pinned, attachment_url, status, expires_at, author_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$title, $content, $target_role, $category, $is_pinned, $attachment_url, $status, $expires_at, $user_id]);
            logActivity($pdo, 'create_announcement', "Membuat pengumuman: $title");
            $message = $status === 'draft' ? "Pengumuman disimpan sebagai draft." : "Pengumuman berhasil diterbitkan.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 2. EDIT PENGUMUMAN (Admin & Staf)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_announcement') {
    if (!$can_manage) {
        header("Location: announcements.php?error=unauthorized");
        exit;
    }

    $edit_id = (int) ($_POST['edit_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $target_role = trim($_POST['target_role'] ?? 'semua');
    $category = trim($_POST['category'] ?? 'umum');
    $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
    $status = ($_POST['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
    $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;

    $valid_targets = ['semua', 'guru', 'siswa', 'orang_tua', 'staf'];
    $valid_categories = ['umum', 'akademik', 'kegiatan', 'penting', 'darurat'];
    if (!in_array($target_role, $valid_targets, true)) $target_role = 'semua';
    if (!in_array($category, $valid_categories, true)) $category = 'umum';

    if ($title === '' || $content === '' || $edit_id < 1) {
        $message = "Data tidak valid. Pastikan judul dan isi terisi.";
        $message_type = "error";
    } else {
        // Handle new attachment
        $attachment_sql = '';
        $params = [$title, $content, $target_role, $category, $is_pinned, $status, $expires_at];

        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar'];
            $file_ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            if (in_array($file_ext, $allowed_ext, true) && $_FILES['attachment']['size'] <= 10 * 1024 * 1024) {
                $safe_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['attachment']['name']));
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_dir . $safe_name)) {
                    // Hapus lampiran lama jika ada
                    $old_att = $pdo->prepare("SELECT attachment_url FROM announcements WHERE id = ?");
                    $old_att->execute([$edit_id]);
                    $old_file = $old_att->fetchColumn();
                    if ($old_file && file_exists($upload_dir . $old_file)) {
                        @unlink($upload_dir . $old_file);
                    }
                    $attachment_sql = ', attachment_url = ?';
                    $params[] = $safe_name;
                }
            }
        }

        // Handle hapus lampiran
        if (isset($_POST['remove_attachment']) && $_POST['remove_attachment'] === '1') {
            $old_att = $pdo->prepare("SELECT attachment_url FROM announcements WHERE id = ?");
            $old_att->execute([$edit_id]);
            $old_file = $old_att->fetchColumn();
            if ($old_file && file_exists($upload_dir . $old_file)) {
                @unlink($upload_dir . $old_file);
            }
            $attachment_sql = ', attachment_url = NULL';
        }

        $params[] = $edit_id;
        $stmt_upd = $pdo->prepare("UPDATE announcements SET title = ?, content = ?, target_role = ?, category = ?, is_pinned = ?, status = ?, expires_at = ? $attachment_sql WHERE id = ?");
        $stmt_upd->execute($params);
        logActivity($pdo, 'edit_announcement', "Mengedit pengumuman ID: $edit_id - $title");
        $message = "Pengumuman berhasil diperbarui.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 3. PIN / UNPIN PENGUMUMAN (Admin & Staf)
// -------------------------------------------------------------
if (isset($_GET['pin_id']) && $can_manage) {
    $pin_id = (int) $_GET['pin_id'];
    $pdo->prepare("UPDATE announcements SET is_pinned = NOT is_pinned WHERE id = ?")->execute([$pin_id]);
    logActivity($pdo, 'toggle_pin_announcement', "Toggle pin pengumuman ID: $pin_id");
    header("Location: announcements.php?msg=pin_toggled");
    exit;
}

// -------------------------------------------------------------
// 4. HAPUS PENGUMUMAN (Admin & Staf) 
// -------------------------------------------------------------
if (isset($_GET['delete_id']) && $can_manage) {
    $delete_id = (int) $_GET['delete_id'];
    // Hapus file lampiran jika ada
    $old_att = $pdo->prepare("SELECT attachment_url, title FROM announcements WHERE id = ?");
    $old_att->execute([$delete_id]);
    $old_row = $old_att->fetch();
    if ($old_row && $old_row['attachment_url'] && file_exists($upload_dir . $old_row['attachment_url'])) {
        @unlink($upload_dir . $old_row['attachment_url']);
    }
    $pdo->prepare("DELETE FROM announcements WHERE id = ?")->execute([$delete_id]);
    logActivity($pdo, 'delete_announcement', "Menghapus pengumuman: " . ($old_row['title'] ?? "ID $delete_id"));
    $message = "Pengumuman berhasil dihapus.";
    $message_type = "success";
}

// Flash messages from redirect
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'pin_toggled') {
        $message = "Status pin pengumuman berhasil diubah.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 5. FILTER & PENCARIAN
// -------------------------------------------------------------
$filter_category = $_GET['category'] ?? '';
$filter_status = $_GET['status'] ?? '';
$search_q = trim($_GET['q'] ?? '');

// -------------------------------------------------------------
// 6. QUERY PENGUMUMAN BERDASARKAN TARGET ROLE + FILTER
// -------------------------------------------------------------
$where_clauses = [];
$params_q = [];

if ($can_manage) {
    // Admin & Staf melihat semua
    if ($filter_status !== '') {
        $where_clauses[] = "a.status = ?";
        $params_q[] = $filter_status;
    }
} else {
    // Role lain hanya melihat published, belum expired, sasaran sesuai
    $where_clauses[] = "a.status = 'published'";
    $where_clauses[] = "(a.expires_at IS NULL OR a.expires_at > NOW())";
    $where_clauses[] = "a.target_role IN ('semua', ?)";
    $params_q[] = $user_role;
}

if ($filter_category !== '') {
    $where_clauses[] = "a.category = ?";
    $params_q[] = $filter_category;
}

if ($search_q !== '') {
    $where_clauses[] = "(a.title LIKE ? OR a.content LIKE ?)";
    $params_q[] = "%$search_q%";
    $params_q[] = "%$search_q%";
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

$stmt = $pdo->prepare("
    SELECT a.*, u.name as author_name, u.role as author_role 
    FROM announcements a 
    JOIN users u ON a.author_id = u.id 
    $where_sql
    ORDER BY a.is_pinned DESC, a.id DESC
");
$stmt->execute($params_q);
$announcements = $stmt->fetchAll();

// Hitung statistik untuk admin
$stats = ['total' => 0, 'published' => 0, 'draft' => 0, 'pinned' => 0, 'expired' => 0];
if ($can_manage) {
    try {
        $stats['total'] = (int) $pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
        $stats['published'] = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE status = 'published'")->fetchColumn();
        $stats['draft'] = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE status = 'draft'")->fetchColumn();
        $stats['pinned'] = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE is_pinned = 1")->fetchColumn();
        $stats['expired'] = (int) $pdo->query("SELECT COUNT(*) FROM announcements WHERE expires_at IS NOT NULL AND expires_at <= NOW()")->fetchColumn();
    } catch (Exception $e) {}
}

$page_title = "Pengumuman & Agenda";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
            <span>📢</span> Pengumuman & Agenda Sekolah
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            <?= $can_manage ? 'Kelola, terbitkan, dan atur prioritas informasi resmi sekolah.' : 'Informasi resmi dan agenda terbaru untuk peran ' . htmlspecialchars(getRoleLabel($user_role)) . '.' ?>
        </p>
    </div>

    <?php if ($can_manage): ?>
        <div>
            <button onclick="document.getElementById('modalCreateAnnounce').classList.remove('hidden')" 
                    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition">
                <span>➕</span> Terbitkan Pengumuman
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== ''): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/20 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <span><?= $message_type === 'success' ? '✅' : '⚠️' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs opacity-70 hover:opacity-100">✕</button>
    </div>
<?php endif; ?>

<!-- Statistik Admin -->
<?php if ($can_manage): ?>
<div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-center">
        <span class="text-xs text-slate-400">Total</span>
        <p class="text-2xl font-extrabold text-white mt-1"><?= $stats['total'] ?></p>
    </div>
    <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4 text-center">
        <span class="text-xs text-emerald-400">Terbit</span>
        <p class="text-2xl font-extrabold text-emerald-300 mt-1"><?= $stats['published'] ?></p>
    </div>
    <div class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4 text-center">
        <span class="text-xs text-amber-400">Draft</span>
        <p class="text-2xl font-extrabold text-amber-300 mt-1"><?= $stats['draft'] ?></p>
    </div>
    <div class="rounded-2xl border border-blue-500/20 bg-blue-500/5 p-4 text-center">
        <span class="text-xs text-blue-400">Disematkan</span>
        <p class="text-2xl font-extrabold text-blue-300 mt-1"><?= $stats['pinned'] ?></p>
    </div>
    <div class="rounded-2xl border border-rose-500/20 bg-rose-500/5 p-4 text-center col-span-2 sm:col-span-1">
        <span class="text-xs text-rose-400">Kedaluwarsa</span>
        <p class="text-2xl font-extrabold text-rose-300 mt-1"><?= $stats['expired'] ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Filter & Pencarian -->
<div class="mb-6 rounded-2xl border border-white/10 bg-white/5 p-4">
    <form method="GET" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
        <div class="flex-1">
            <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="🔍 Cari judul atau isi pengumuman..." 
                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500 placeholder-slate-500">
        </div>
        <select name="category" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            <option value="">Semua Kategori</option>
            <?php foreach (ANNOUNCEMENT_CATEGORIES as $k => $cat): ?>
                <option value="<?= $k ?>" <?= $filter_category === $k ? 'selected' : '' ?>><?= $cat['icon'] ?> <?= $cat['label'] ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($can_manage): ?>
            <select name="status" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                <option value="">Semua Status</option>
                <option value="published" <?= $filter_status === 'published' ? 'selected' : '' ?>>✅ Published</option>
                <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>📝 Draft</option>
            </select>
        <?php endif; ?>
        <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2.5 text-sm font-semibold text-white transition">
            Filter
        </button>
        <?php if ($search_q || $filter_category || $filter_status): ?>
            <a href="announcements.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-slate-300 transition text-center">
                Reset
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Daftar Kartu Pengumuman -->
<div class="space-y-4">
    <?php if (empty($announcements)): ?>
        <div class="rounded-3xl border border-white/10 bg-white/5 p-12 text-center text-slate-400">
            <span class="text-4xl">📭</span>
            <p class="mt-3 text-base font-semibold text-white">Belum Ada Pengumuman</p>
            <p class="text-xs text-slate-500 mt-1">
                <?= ($search_q || $filter_category || $filter_status) 
                    ? 'Tidak ada pengumuman yang cocok dengan filter Anda. Coba ubah kriteria pencarian.'
                    : 'Saat ini belum ada pengumuman yang ditujukan untuk Anda.' ?>
            </p>
        </div>
    <?php else: ?>
        <?php foreach ($announcements as $a): 
            $is_expired = ($a['expires_at'] && strtotime($a['expires_at']) <= time());
            $is_draft = ($a['status'] === 'draft');
            $cat_info = ANNOUNCEMENT_CATEGORIES[$a['category'] ?? 'umum'] ?? ANNOUNCEMENT_CATEGORIES['umum'];
        ?>
            <div class="rounded-3xl border <?= $a['is_pinned'] ? 'border-amber-500/40 bg-gradient-to-r from-amber-950/20 via-slate-900/60 to-slate-900/30 ring-1 ring-amber-500/20' : ($a['category'] === 'darurat' ? 'border-rose-500/30 bg-gradient-to-r from-rose-950/20 via-slate-900/60 to-slate-900/30' : 'border-white/10 bg-white/5') ?> p-6 shadow-xl transition hover:border-white/20 <?= $is_draft ? 'opacity-70' : '' ?>">
                
                <!-- Header Badges -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4 border-b border-white/5">
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if ($a['is_pinned']): ?>
                            <span class="inline-flex items-center rounded-lg border border-amber-500/40 bg-amber-500/15 px-2 py-0.5 text-xs font-bold text-amber-300 animate-pulse">
                                📌 Disematkan
                            </span>
                        <?php endif; ?>

                        <?php if ($is_draft): ?>
                            <span class="inline-flex items-center rounded-lg border border-slate-500/30 bg-slate-500/10 px-2 py-0.5 text-xs font-semibold text-slate-400">
                                📝 Draft
                            </span>
                        <?php endif; ?>

                        <?php if ($is_expired): ?>
                            <span class="inline-flex items-center rounded-lg border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-400">
                                ⏰ Kedaluwarsa
                            </span>
                        <?php endif; ?>

                        <span class="inline-flex items-center rounded-lg border px-2.5 py-0.5 text-xs font-semibold <?= $cat_info['badge'] ?>">
                            <?= $cat_info['icon'] ?> <?= $cat_info['label'] ?>
                        </span>

                        <span class="inline-flex items-center rounded-lg border px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wider <?= $a['target_role'] === 'semua' ? 'border-blue-500/30 bg-blue-500/10 text-blue-300' : getRoleBadge($a['target_role']) ?>">
                            👥 <?= $a['target_role'] === 'semua' ? 'Semua' : getRoleLabel($a['target_role']) ?>
                        </span>
                    </div>

                    <div class="flex items-center gap-3 text-xs text-slate-500">
                        <span>🗓️ <?= date('d M Y, H:i', strtotime($a['created_at'])) ?></span>
                        <?php if ($a['updated_at'] && $a['updated_at'] !== $a['created_at']): ?>
                            <span class="text-blue-400">✏️ Diperbarui</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Content -->
                <div class="pt-4">
                    <h2 class="text-lg font-bold text-white mb-1 flex items-center gap-2">
                        <?php if ($a['category'] === 'darurat'): ?>
                            <span class="text-rose-400 animate-pulse">🚨</span>
                        <?php endif; ?>
                        <?= htmlspecialchars($a['title']) ?>
                    </h2>
                    <p class="text-xs text-slate-400 mb-3">
                        Oleh <strong class="text-slate-200"><?= htmlspecialchars($a['author_name']) ?></strong> (<?= htmlspecialchars(getRoleLabel($a['author_role'])) ?>)
                        <?php if ($a['expires_at']): ?>
                            • Berlaku hingga: <span class="<?= $is_expired ? 'text-rose-400' : 'text-emerald-400' ?>"><?= date('d M Y, H:i', strtotime($a['expires_at'])) ?></span>
                        <?php endif; ?>
                    </p>
                    <p class="text-sm text-slate-300 leading-relaxed whitespace-pre-line"><?= htmlspecialchars($a['content']) ?></p>

                    <!-- Lampiran -->
                    <?php if (!empty($a['attachment_url'])): ?>
                        <div class="mt-4 flex items-center gap-3 p-3 rounded-xl border border-white/10 bg-slate-900/60">
                            <span class="text-xl">📎</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-white truncate"><?= htmlspecialchars($a['attachment_url']) ?></p>
                                <p class="text-[10px] text-slate-500">Lampiran berkas</p>
                            </div>
                            <a href="../../uploads/announcements/<?= htmlspecialchars($a['attachment_url']) ?>" target="_blank" download
                               class="rounded-lg bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/30 px-3 py-1.5 text-xs font-semibold text-blue-300 transition">
                                ⬇️ Unduh
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Aksi Admin -->
                <?php if ($can_manage): ?>
                    <div class="mt-4 pt-3 border-t border-white/5 flex flex-wrap items-center gap-2">
                        <button onclick="openEditModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8') ?>)" 
                                class="rounded-lg border border-blue-500/30 bg-blue-500/10 hover:bg-blue-500/20 px-3 py-1.5 text-xs font-semibold text-blue-300 transition">
                            ✏️ Edit
                        </button>
                        <a href="announcements.php?pin_id=<?= $a['id'] ?>" 
                           class="rounded-lg border <?= $a['is_pinned'] ? 'border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 text-amber-300' : 'border-white/10 bg-white/5 hover:bg-white/10 text-slate-300' ?> px-3 py-1.5 text-xs font-semibold transition">
                            <?= $a['is_pinned'] ? '📌 Lepas Pin' : '📌 Sematkan' ?>
                        </a>
                        <button onclick="confirmDelete(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title']), ENT_QUOTES) ?>')"
                                class="rounded-lg border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-300 transition">
                            🗑️ Hapus
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ========================================== -->
<!-- MODAL: TAMBAH PENGUMUMAN BARU (Admin/Staf) -->
<!-- ========================================== -->
<?php if ($can_manage): ?>
<div id="modalCreateAnnounce" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span>📢</span> Buat Pengumuman Baru
            </h3>
            <button onclick="document.getElementById('modalCreateAnnounce').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="create_announcement">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-300">Judul Pengumuman *</label>
                    <input type="text" name="title" required placeholder="Contoh: Jadwal Libur Semester Ganjil" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Kategori *</label>
                    <select name="category" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <?php foreach (ANNOUNCEMENT_CATEGORIES as $k => $cat): ?>
                            <option value="<?= $k ?>"><?= $cat['icon'] ?> <?= $cat['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Target Audiens *</label>
                    <select name="target_role" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="semua">📢 Semua Warga Sekolah</option>
                        <option value="siswa">📖 Khusus Siswa</option>
                        <option value="guru">📚 Khusus Guru</option>
                        <option value="orang_tua">🎓 Khusus Orang Tua / Wali</option>
                        <option value="staf">📂 Khusus Staf</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Status Publikasi</label>
                    <select name="status" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="published">✅ Terbitkan Langsung</option>
                        <option value="draft">📝 Simpan Sebagai Draft</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Tanggal Kedaluwarsa (Opsional)</label>
                    <input type="datetime-local" name="expires_at" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Isi Pengumuman *</label>
                <textarea name="content" rows="5" required placeholder="Tuliskan detail pengumuman secara lengkap di sini..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Lampiran Berkas (Opsional, maks 10MB)</label>
                <input type="file" name="attachment" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.zip,.rar"
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1 file:text-xs file:font-semibold file:text-white cursor-pointer">
                <p class="mt-1 text-[10px] text-slate-500">Format: PDF, DOC, XLS, PPT, JPG, PNG, ZIP, RAR</p>
            </div>

            <div class="flex items-center gap-3 p-3 rounded-xl border border-white/10 bg-slate-950/50">
                <input type="checkbox" name="is_pinned" id="createPinned" value="1" class="rounded border-white/20 bg-slate-950 text-blue-500 focus:ring-blue-500">
                <label for="createPinned" class="text-sm text-slate-300 cursor-pointer">
                    📌 <strong>Sematkan (Pin) ke atas</strong> — pengumuman ini akan selalu muncul di posisi teratas
                </label>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalCreateAnnounce').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-blue-500/20">
                    Terbitkan Sekarang
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: EDIT PENGUMUMAN (Admin/Staf)        -->
<!-- ========================================== -->
<div id="modalEditAnnounce" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span>✏️</span> Edit Pengumuman
            </h3>
            <button onclick="document.getElementById('modalEditAnnounce').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="edit_announcement">
            <input type="hidden" name="edit_id" id="editId">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-300">Judul Pengumuman *</label>
                    <input type="text" name="title" id="editTitle" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Kategori *</label>
                    <select name="category" id="editCategory" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <?php foreach (ANNOUNCEMENT_CATEGORIES as $k => $cat): ?>
                            <option value="<?= $k ?>"><?= $cat['icon'] ?> <?= $cat['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Target Audiens *</label>
                    <select name="target_role" id="editTargetRole" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="semua">📢 Semua Warga Sekolah</option>
                        <option value="siswa">📖 Khusus Siswa</option>
                        <option value="guru">📚 Khusus Guru</option>
                        <option value="orang_tua">🎓 Khusus Orang Tua / Wali</option>
                        <option value="staf">📂 Khusus Staf</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Status Publikasi</label>
                    <select name="status" id="editStatus" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="published">✅ Published</option>
                        <option value="draft">📝 Draft</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Tanggal Kedaluwarsa</label>
                    <input type="datetime-local" name="expires_at" id="editExpiresAt" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Isi Pengumuman *</label>
                <textarea name="content" id="editContent" rows="5" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Lampiran Berkas Baru (Opsional)</label>
                <input type="file" name="attachment" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.zip,.rar"
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1 file:text-xs file:font-semibold file:text-white cursor-pointer">
            </div>

            <div id="editAttachmentInfo" class="hidden p-3 rounded-xl border border-white/10 bg-slate-950/50 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <span>📎</span>
                    <span class="text-xs text-slate-300 truncate" id="editAttachmentName"></span>
                </div>
                <label class="flex items-center gap-2 text-xs text-rose-400 cursor-pointer whitespace-nowrap">
                    <input type="checkbox" name="remove_attachment" value="1" class="rounded border-white/20 bg-slate-950 text-rose-500 focus:ring-rose-500">
                    Hapus lampiran
                </label>
            </div>

            <div class="flex items-center gap-3 p-3 rounded-xl border border-white/10 bg-slate-950/50">
                <input type="checkbox" name="is_pinned" id="editPinned" value="1" class="rounded border-white/20 bg-slate-950 text-blue-500 focus:ring-blue-500">
                <label for="editPinned" class="text-sm text-slate-300 cursor-pointer">
                    📌 <strong>Sematkan (Pin) ke atas</strong>
                </label>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalEditAnnounce').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-emerald-500/20">
                    💾 Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: KONFIRMASI HAPUS                    -->
<!-- ========================================== -->
<div id="modalDeleteConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-md rounded-3xl border border-rose-500/30 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="text-center mb-6">
            <span class="text-5xl">🗑️</span>
            <h3 class="text-lg font-bold text-white mt-3">Hapus Pengumuman?</h3>
            <p class="text-sm text-slate-400 mt-2">Anda akan menghapus pengumuman:</p>
            <p class="text-base font-bold text-rose-300 mt-1" id="deleteTitle"></p>
            <p class="text-xs text-slate-500 mt-2">Tindakan ini tidak dapat dibatalkan. Lampiran berkas juga akan ikut dihapus.</p>
        </div>
        <div class="flex justify-center gap-3">
            <button onclick="document.getElementById('modalDeleteConfirm').classList.add('hidden')" 
                    class="rounded-xl border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-semibold text-slate-300 hover:bg-white/10">
                Batal
            </button>
            <a id="deleteLink" href="#" 
               class="rounded-xl bg-rose-600 hover:bg-rose-500 px-5 py-2.5 text-sm font-semibold text-white transition shadow-lg shadow-rose-500/20">
                🗑️ Ya, Hapus Sekarang
            </a>
        </div>
    </div>
</div>

<script>
// Open Edit Modal dan isi data
function openEditModal(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editTitle').value = data.title;
    document.getElementById('editContent').value = data.content;
    document.getElementById('editTargetRole').value = data.target_role;
    document.getElementById('editCategory').value = data.category || 'umum';
    document.getElementById('editStatus').value = data.status || 'published';
    document.getElementById('editPinned').checked = (data.is_pinned == 1);

    // Expires at
    if (data.expires_at) {
        // Konversi ke format datetime-local
        const d = new Date(data.expires_at);
        const pad = n => String(n).padStart(2, '0');
        document.getElementById('editExpiresAt').value = 
            `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    } else {
        document.getElementById('editExpiresAt').value = '';
    }

    // Attachment info
    const attInfo = document.getElementById('editAttachmentInfo');
    if (data.attachment_url) {
        attInfo.classList.remove('hidden');
        document.getElementById('editAttachmentName').textContent = data.attachment_url;
    } else {
        attInfo.classList.add('hidden');
    }

    document.getElementById('modalEditAnnounce').classList.remove('hidden');
}

// Konfirmasi Hapus
function confirmDelete(id, title) {
    document.getElementById('deleteTitle').textContent = title;
    document.getElementById('deleteLink').href = 'announcements.php?delete_id=' + id;
    document.getElementById('modalDeleteConfirm').classList.remove('hidden');
}

// Close modals on outside click
document.querySelectorAll('#modalCreateAnnounce, #modalEditAnnounce, #modalDeleteConfirm').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.classList.add('hidden');
    });
});

// Close modals on ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('#modalCreateAnnounce, #modalEditAnnounce, #modalDeleteConfirm').forEach(m => m.classList.add('hidden'));
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
