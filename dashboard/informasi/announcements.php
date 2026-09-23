<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['administrator', 'staf'], true);

// Endpoint AJAX untuk melihat daftar pembaca pengumuman (Khusus Admin/Staf)
if (isset($_GET['get_readers'])) {
    if (!$can_manage) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    header('Content-Type: application/json');
    $ann_id = (int) $_GET['get_readers'];
    $readers = getAnnouncementReaders($pdo, $ann_id);
    echo json_encode($readers);
    exit;
}

$message = "";
$message_type = "";

// Pastikan folder upload lampiran ada
$upload_dir = __DIR__ . "/../../uploads/announcements/";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Ambil daftar seluruh kelas untuk target rombel
$all_classes = [];
try {
    $all_classes = $pdo->query("SELECT id, name, grade_level FROM classes ORDER BY grade_level ASC, name ASC")->fetchAll();
} catch (Exception $e) {}

// -------------------------------------------------------------
// 1. TAMBAH PENGUMUMAN BARU (Admin & Staf)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_announcement') {
    if (!$can_manage) {
        header("Location: announcements.php?error=unauthorized");
        exit;
    }
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan coba lagi.";
        $message_type = "error";
    } else {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $target_role = trim($_POST['target_role'] ?? 'semua');
        $class_id = !empty($_POST['class_id']) ? (int) $_POST['class_id'] : null;
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
                // Handle sinkronisasi kalender jika dicentang
                $event_id = null;
                if (!empty($_POST['sync_calendar']) && !empty($_POST['event_date'])) {
                    try {
                        $ev_title = $title;
                        $ev_desc = strip_tags($content);
                        $ev_date = $_POST['event_date'];
                        $ev_end = !empty($_POST['event_end_date']) ? $_POST['event_end_date'] : null;
                        $ev_cat = $_POST['calendar_category'] ?? ($category === 'darurat' ? 'libur' : ($category === 'akademik' ? 'akademik' : 'kegiatan'));
                        $ev_color = $_POST['calendar_color'] ?? ($category === 'darurat' ? 'rose' : ($category === 'penting' ? 'amber' : 'blue'));

                        $stmt_cev = $pdo->prepare("
                            INSERT INTO calendar_events (title, description, event_date, end_date, category, color, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt_cev->execute([$ev_title, $ev_desc, $ev_date, $ev_end, $ev_cat, $ev_color, $user_id]);
                        $event_id = (int) $pdo->lastInsertId();
                    } catch (Exception $e) {}
                }

                $stmt_ins = $pdo->prepare("
                    INSERT INTO announcements (title, content, target_role, class_id, category, is_pinned, attachment_url, status, expires_at, event_id, author_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_ins->execute([$title, $content, $target_role, $class_id, $category, $is_pinned, $attachment_url, $status, $expires_at, $event_id, $user_id]);
                logActivity($pdo, 'create_announcement', "Membuat pengumuman: $title");
                $message = $status === 'draft' ? "Pengumuman disimpan sebagai draft." : "Pengumuman berhasil diterbitkan" . ($event_id ? " dan disinkronkan ke Kalender Sekolah." : ".");
                $message_type = "success";
            }
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
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan coba lagi.";
        $message_type = "error";
    } else {
        $edit_id = (int) ($_POST['edit_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $target_role = trim($_POST['target_role'] ?? 'semua');
        $class_id = !empty($_POST['class_id']) ? (int) $_POST['class_id'] : null;
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
            $params = [$title, $content, $target_role, $class_id, $category, $is_pinned, $status, $expires_at];

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
            $stmt_upd = $pdo->prepare("
                UPDATE announcements 
                SET title = ?, content = ?, target_role = ?, class_id = ?, category = ?, is_pinned = ?, status = ?, expires_at = ? $attachment_sql 
                WHERE id = ?
            ");
            $stmt_upd->execute($params);
            logActivity($pdo, 'edit_announcement', "Mengedit pengumuman ID: $edit_id - $title");
            $message = "Pengumuman berhasil diperbarui.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 3. KONFIRMASI BACA PENGUMUMAN (Read Receipts)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
    if (validateCsrfToken()) {
        $ann_id = (int) ($_POST['announcement_id'] ?? 0);
        if ($ann_id > 0) {
            $stmt_mr = $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)");
            $stmt_mr->execute([$ann_id, $user_id]);
            logActivity($pdo, 'read_announcement', "Membaca pengumuman ID: $ann_id");
            $message = "Pengumuman telah Anda konfirmasi sebagai sudah dibaca & dipahami.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 4. SINKRONKAN KE KALENDER CEPAT (Admin & Staf)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_calendar_quick' && $can_manage) {
    if (validateCsrfToken()) {
        $ann_id = (int) ($_POST['announcement_id'] ?? 0);
        $ev_date = !empty($_POST['event_date']) ? $_POST['event_date'] : date('Y-m-d');
        $stmt_sq = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
        $stmt_sq->execute([$ann_id]);
        $row_sq = $stmt_sq->fetch();
        if ($row_sq) {
            $ev_cat = ($row_sq['category'] === 'darurat') ? 'libur' : (($row_sq['category'] === 'akademik') ? 'akademik' : 'kegiatan');
            $ev_color = ($row_sq['category'] === 'darurat') ? 'rose' : ($row_sq['category'] === 'penting' ? 'amber' : 'blue');
            $stmt_cev = $pdo->prepare("
                INSERT INTO calendar_events (title, description, event_date, category, color, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt_cev->execute([$row_sq['title'], strip_tags($row_sq['content']), $ev_date, $ev_cat, $ev_color, $user_id]);
            $ev_id = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE announcements SET event_id = ? WHERE id = ?")->execute([$ev_id, $ann_id]);
            logActivity($pdo, 'sync_calendar_announcement', "Sinkronkan pengumuman ID $ann_id ke kalender event ID $ev_id");
            $message = "Pengumuman berhasil disinkronkan ke Kalender Sekolah!";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 5. PIN / UNPIN PENGUMUMAN (Admin & Staf)
// -------------------------------------------------------------
if (isset($_GET['pin_id']) && $can_manage) {
    $pin_id = (int) $_GET['pin_id'];
    $pdo->prepare("UPDATE announcements SET is_pinned = NOT is_pinned WHERE id = ?")->execute([$pin_id]);
    logActivity($pdo, 'toggle_pin_announcement', "Toggle pin pengumuman ID: $pin_id");
    header("Location: announcements.php?msg=pin_toggled");
    exit;
}

// -------------------------------------------------------------
// 6. HAPUS PENGUMUMAN (Admin & Staf) 
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_announcement' && $can_manage) {
    if (validateCsrfToken()) {
        $delete_id = (int) ($_POST['delete_id'] ?? 0);
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
}

// Flash messages from redirect
if (isset($_GET['msg']) && $_GET['msg'] === 'pin_toggled') {
    $message = "Status pin pengumuman berhasil diubah.";
    $message_type = "success";
}

// -------------------------------------------------------------
// 7. FILTER & PENCARIAN DENGAN SEGMENTASI KELAS
// -------------------------------------------------------------
$filter_category = $_GET['category'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int) $_GET['class_id'] : '';
$search_q = trim($_GET['q'] ?? '');

$where_clauses = [];
$params_q = [];

if ($can_manage) {
    // Admin & Staf melihat semua status atau sesuai filter
    if ($filter_status !== '') {
        $where_clauses[] = "a.status = ?";
        $params_q[] = $filter_status;
    }
    if ($filter_class !== '') {
        $where_clauses[] = "a.class_id = ?";
        $params_q[] = $filter_class;
    }
} else {
    // Role non-admin hanya melihat published dan belum expired
    $where_clauses[] = "a.status = 'published'";
    $where_clauses[] = "(a.expires_at IS NULL OR a.expires_at > NOW())";
    $where_clauses[] = "a.target_role IN ('semua', ?)";
    $params_q[] = $user_role;

    // Segmentasi kelas untuk siswa & orang tua
    if ($user_role === 'siswa') {
        $student_class = $pdo->query("SELECT class_id FROM users WHERE id = $user_id")->fetchColumn();
        if ($student_class) {
            $where_clauses[] = "(a.class_id IS NULL OR a.class_id = ?)";
            $params_q[] = (int) $student_class;
        } else {
            $where_clauses[] = "a.class_id IS NULL";
        }
    } elseif ($user_role === 'orang_tua') {
        $stmt_pk = $pdo->prepare("
            SELECT DISTINCT u.class_id 
            FROM parent_students ps 
            JOIN users u ON ps.student_id = u.id 
            WHERE ps.parent_id = ? AND u.class_id IS NOT NULL
        ");
        $stmt_pk->execute([$user_id]);
        $parent_classes = $stmt_pk->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($parent_classes)) {
            $in_slots = implode(',', array_fill(0, count($parent_classes), '?'));
            $where_clauses[] = "(a.class_id IS NULL OR a.class_id IN ($in_slots))";
            foreach ($parent_classes as $pcid) {
                $params_q[] = (int) $pcid;
            }
        } else {
            $where_clauses[] = "a.class_id IS NULL";
        }
    }
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
    SELECT a.*, u.name as author_name, u.role as author_role, c.name as class_name 
    FROM announcements a 
    JOIN users u ON a.author_id = u.id 
    LEFT JOIN classes c ON a.class_id = c.id
    $where_sql
    ORDER BY a.is_pinned DESC, a.id DESC
");
$stmt->execute($params_q);
$announcements = $stmt->fetchAll();

// Pre-fetch status konfirmasi baca untuk user saat ini
$user_reads = $pdo->query("SELECT announcement_id FROM announcement_reads WHERE user_id = $user_id")->fetchAll(PDO::FETCH_COLUMN);
$user_reads_map = array_flip($user_reads);

// Pre-fetch jumlah pembaca untuk tiap pengumuman (Khusus Admin/Staf)
$read_counts_map = [];
if ($can_manage) {
    try {
        $rc_raw = $pdo->query("SELECT announcement_id, COUNT(*) as cnt FROM announcement_reads GROUP BY announcement_id")->fetchAll();
        foreach ($rc_raw as $rc) {
            $read_counts_map[$rc['announcement_id']] = (int) $rc['cnt'];
        }
    } catch (Exception $e) {}
}

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

<!-- Quill.js Editor Assets -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<style>
/* Styling Dark Mode Elegan untuk Quill Editor */
.ql-toolbar.ql-snow {
    border-color: rgba(255, 255, 255, 0.12) !important;
    background: rgba(15, 23, 42, 0.9) !important;
    border-top-left-radius: 0.75rem;
    border-top-right-radius: 0.75rem;
}
.ql-container.ql-snow {
    border-color: rgba(255, 255, 255, 0.12) !important;
    background: #020617 !important;
    border-bottom-left-radius: 0.75rem;
    border-bottom-right-radius: 0.75rem;
    color: #f8fafc !important;
    font-size: 0.875rem;
    min-height: 150px;
}
.ql-snow .ql-stroke { stroke: #94a3b8 !important; }
.ql-snow .ql-fill { fill: #94a3b8 !important; }
.ql-snow .ql-picker { color: #94a3b8 !important; }
.ql-snow .ql-picker-options { 
    background-color: #0f172a !important; 
    border-color: rgba(255, 255, 255, 0.12) !important; 
    border-radius: 0.5rem;
}
.ql-snow .ql-picker-item:hover { color: #60a5fa !important; }
.ql-editor.ql-blank::before { color: #64748b !important; font-style: normal; }
.announcement-rendered-content p { margin-bottom: 0.5rem; }
.announcement-rendered-content ul { list-style-type: disc; margin-left: 1.25rem; margin-bottom: 0.5rem; }
.announcement-rendered-content ol { list-style-type: decimal; margin-left: 1.25rem; margin-bottom: 0.5rem; }
.announcement-rendered-content strong { color: #ffffff; }
.announcement-rendered-content a { color: #60a5fa; text-decoration: underline; }
.announcement-rendered-content blockquote { border-left: 3px solid #3b82f6; padding-left: 0.75rem; color: #94a3b8; font-style: italic; }
</style>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
            <span class="text-blue-400"><i class="fa-solid fa-bullhorn"></i></span> Pengumuman & Agenda Sekolah
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            <?= $can_manage ? 'Kelola, terbitkan, dan atur prioritas informasi resmi sekolah dengan konfirmasi baca.' : 'Informasi resmi dan agenda terbaru untuk peran ' . htmlspecialchars(getRoleLabel($user_role)) . '.' ?>
        </p>
    </div>

    <?php if ($can_manage): ?>
        <div>
            <button onclick="document.getElementById('modalCreateAnnounce').classList.remove('hidden')" 
                    class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition cursor-pointer">
                <i class="fa-solid fa-plus"></i> Terbitkan Pengumuman
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== ''): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/20 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <span><i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-amber-400' ?>"></i></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<!-- Statistik Admin -->
<?php if ($can_manage): ?>
<div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
    <div class="rounded-2xl border border-white/10 bg-white/5 p-4 text-center">
        <span class="text-xs text-slate-400">Total Pengumuman</span>
        <p class="text-2xl font-extrabold text-white mt-1"><?= $stats['total'] ?></p>
    </div>
    <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4 text-center">
        <span class="text-xs text-emerald-400">Terbit Aktif</span>
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
    <form method="GET" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-[220px]">
            <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>" placeholder="Cari judul atau isi pengumuman..." 
                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500 placeholder-slate-500">
        </div>
        <select name="category" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            <option value="">Semua Kategori</option>
            <?php foreach (ANNOUNCEMENT_CATEGORIES as $k => $cat): ?>
                <option value="<?= $k ?>" <?= $filter_category === $k ? 'selected' : '' ?>><?= $cat['label'] ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($can_manage): ?>
            <!-- Filter Rombel Kelas untuk Admin -->
            <select name="class_id" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                <option value="">Semua Rombel/Kelas</option>
                <?php foreach ($all_classes as $cls): ?>
                    <option value="<?= $cls['id'] ?>" <?= $filter_class === (int)$cls['id'] ? 'selected' : '' ?>>
                        Kelas <?= htmlspecialchars($cls['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                <option value="">Semua Status</option>
                <option value="published" <?= $filter_status === 'published' ? 'selected' : '' ?>>Published</option>
                <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draft</option>
            </select>
        <?php endif; ?>
        <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2.5 text-sm font-semibold text-white transition cursor-pointer">
            Filter
        </button>
        <?php if ($search_q || $filter_category || $filter_status || $filter_class !== ''): ?>
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
            <span class="text-4xl text-slate-500"><i class="fa-regular fa-envelope-open"></i></span>
            <p class="mt-3 text-base font-semibold text-white">Belum Ada Pengumuman</p>
            <p class="text-xs text-slate-500 mt-1">
                <?= ($search_q || $filter_category || $filter_status || $filter_class !== '') 
                    ? 'Tidak ada pengumuman yang cocok dengan filter Anda. Coba ubah kriteria pencarian.'
                    : 'Saat ini belum ada pengumuman yang ditujukan untuk Anda.' ?>
            </p>
        </div>
    <?php else: ?>
        <?php foreach ($announcements as $a): 
            $is_expired = ($a['expires_at'] && strtotime($a['expires_at']) <= time());
            $is_draft = ($a['status'] === 'draft');
            $cat_info = ANNOUNCEMENT_CATEGORIES[$a['category'] ?? 'umum'] ?? ANNOUNCEMENT_CATEGORIES['umum'];
            $is_read_by_user = isset($user_reads_map[$a['id']]);
            $readers_count = $read_counts_map[$a['id']] ?? 0;
        ?>
            <div class="rounded-3xl border <?= $a['is_pinned'] ? 'border-amber-500/40 bg-gradient-to-r from-amber-950/20 via-slate-900/60 to-slate-900/30 ring-1 ring-amber-500/20' : ($a['category'] === 'darurat' ? 'border-rose-500/30 bg-gradient-to-r from-rose-950/20 via-slate-900/60 to-slate-900/30' : 'border-white/10 bg-white/5') ?> p-6 shadow-xl transition hover:border-white/20 <?= $is_draft ? 'opacity-70' : '' ?>">
                
                <!-- Header Badges -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4 border-b border-white/5">
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if ($a['is_pinned']): ?>
                            <span class="inline-flex items-center rounded-lg border border-amber-500/40 bg-amber-500/15 px-2 py-0.5 text-xs font-bold text-amber-300 animate-pulse">
                                <i class="fa-solid fa-thumbtack mr-1"></i> Disematkan
                            </span>
                        <?php endif; ?>

                        <?php if ($is_draft): ?>
                            <span class="inline-flex items-center rounded-lg border border-slate-500/30 bg-slate-500/10 px-2 py-0.5 text-xs font-semibold text-slate-400">
                                <i class="fa-solid fa-file-pen mr-1"></i> Draft
                            </span>
                        <?php endif; ?>

                        <?php if ($is_expired): ?>
                            <span class="inline-flex items-center rounded-lg border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-400">
                                <i class="fa-regular fa-clock mr-1"></i> Kedaluwarsa
                            </span>
                        <?php endif; ?>

                        <span class="inline-flex items-center rounded-lg border px-2.5 py-0.5 text-xs font-semibold <?= $cat_info['badge'] ?>">
                            <?= $cat_info['icon'] ?> <?= $cat_info['label'] ?>
                        </span>

                        <span class="inline-flex items-center rounded-lg border px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wider <?= $a['target_role'] === 'semua' ? 'border-blue-500/30 bg-blue-500/10 text-blue-300' : getRoleBadge($a['target_role']) ?>">
                            <i class="fa-solid fa-users mr-1"></i> <?= $a['target_role'] === 'semua' ? 'Semua Role' : getRoleLabel($a['target_role']) ?>
                        </span>

                        <?php if (!empty($a['class_name'])): ?>
                            <span class="inline-flex items-center rounded-lg border border-purple-500/30 bg-purple-500/10 px-2.5 py-0.5 text-xs font-semibold text-purple-300">
                                <i class="fa-solid fa-graduation-cap mr-1"></i> <?= htmlspecialchars($a['class_name']) ?>
                            </span>
                        <?php endif; ?>

                        <?php if (!empty($a['event_id'])): ?>
                            <span class="inline-flex items-center rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-300">
                                <i class="fa-solid fa-calendar-days mr-1"></i> Kalender Sekolah
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-3 text-xs text-slate-500">
                        <span><i class="fa-regular fa-calendar mr-1"></i> <?= date('d M Y, H:i', strtotime($a['created_at'])) ?></span>
                        <?php if ($a['updated_at'] && $a['updated_at'] !== $a['created_at']): ?>
                            <span class="text-blue-400"><i class="fa-solid fa-pen-to-square mr-1"></i> Diperbarui</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Content -->
                <div class="pt-4">
                    <h2 class="text-lg font-bold text-white mb-1 flex items-center gap-2">
                        <?php if ($a['category'] === 'darurat'): ?>
                            <span class="text-rose-400 animate-pulse"><i class="fa-solid fa-triangle-exclamation"></i></span>
                        <?php endif; ?>
                        <?= htmlspecialchars($a['title']) ?>
                    </h2>
                    <p class="text-xs text-slate-400 mb-3">
                        Oleh <strong class="text-slate-200"><?= htmlspecialchars($a['author_name']) ?></strong> (<?= htmlspecialchars(getRoleLabel($a['author_role'])) ?>)
                        <?php if ($a['expires_at']): ?>
                            • Berlaku hingga: <span class="<?= $is_expired ? 'text-rose-400' : 'text-emerald-400' ?>"><?= date('d M Y, H:i', strtotime($a['expires_at'])) ?></span>
                        <?php endif; ?>
                    </p>

                    <!-- Rendered Rich Text Content -->
                    <div class="text-sm text-slate-300 leading-relaxed announcement-rendered-content">
                        <?= sanitizeAnnouncementHtml($a['content']) ?>
                    </div>

                    <!-- Lampiran -->
                    <?php if (!empty($a['attachment_url'])): ?>
                        <div class="mt-4 flex items-center gap-3 p-3 rounded-xl border border-white/10 bg-slate-900/60">
                            <span class="text-xl text-blue-400"><i class="fa-solid fa-paperclip"></i></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-white truncate"><?= htmlspecialchars($a['attachment_url']) ?></p>
                                <p class="text-[10px] text-slate-500">Lampiran berkas pendukung</p>
                            </div>
                            <a href="../../uploads/announcements/<?= htmlspecialchars($a['attachment_url']) ?>" target="_blank" download
                               class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/30 px-3 py-1.5 text-xs font-semibold text-blue-300 transition">
                                <i class="fa-solid fa-download"></i> Unduh
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Action Bar & Konfirmasi Baca -->
                <div class="mt-4 pt-3 border-t border-white/5 flex flex-wrap items-center justify-between gap-3">
                    
                    <!-- Sisi Kiri: Status Baca Pengguna -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if ($is_read_by_user): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-xs font-semibold text-emerald-300">
                                <i class="fa-solid fa-circle-check"></i> Sudah Dibaca & Dipahami
                            </span>
                        <?php else: ?>
                            <form method="POST" class="inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="announcement_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-blue-600 hover:bg-blue-500 text-xs font-bold text-white transition shadow-lg shadow-blue-500/20 cursor-pointer">
                                    <i class="fa-solid fa-check"></i> Tandai Sudah Membaca
                                </button>
                            </form>
                        <?php endif; ?>

                        <!-- Tombol Cetak Surat Edaran Resmi -->
                        <a href="print_announcement.php?id=<?= $a['id'] ?>" target="_blank" 
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-xs font-semibold text-slate-300 hover:text-white transition">
                            <i class="fa-solid fa-print"></i> Cetak Surat Edaran
                        </a>
                    </div>

                    <!-- Sisi Kanan: Aksi Admin / Staf -->
                    <?php if ($can_manage): ?>
                        <div class="flex items-center gap-2 flex-wrap">
                            <!-- Tombol Lihat Pembaca -->
                            <button onclick="openReadersModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title']), ENT_QUOTES) ?>')" 
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-purple-500/30 bg-purple-500/10 hover:bg-purple-500/20 px-3 py-1.5 text-xs font-semibold text-purple-300 transition cursor-pointer">
                                <i class="fa-regular fa-eye"></i> <?= $readers_count ?> Pembaca
                            </button>

                            <?php if (empty($a['event_id'])): ?>
                                <button onclick="openQuickSyncModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title']), ENT_QUOTES) ?>')" 
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3 py-1.5 text-xs font-semibold text-emerald-300 transition cursor-pointer">
                                    <i class="fa-solid fa-calendar-plus"></i> Ke Kalender
                                </button>
                            <?php endif; ?>

                            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES, 'UTF-8') ?>)" 
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-blue-500/30 bg-blue-500/10 hover:bg-blue-500/20 px-3 py-1.5 text-xs font-semibold text-blue-300 transition cursor-pointer">
                                <i class="fa-solid fa-pen"></i> Edit
                            </button>

                            <a href="announcements.php?pin_id=<?= $a['id'] ?>" 
                               class="inline-flex items-center gap-1.5 rounded-xl border <?= $a['is_pinned'] ? 'border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 text-amber-300' : 'border-white/10 bg-white/5 hover:bg-white/10 text-slate-300' ?> px-3 py-1.5 text-xs font-semibold transition">
                                <i class="fa-solid fa-thumbtack"></i> <?= $a['is_pinned'] ? 'Lepas Pin' : 'Sematkan' ?>
                            </a>

                            <button onclick="confirmDelete(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['title']), ENT_QUOTES) ?>')"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-300 transition cursor-pointer">
                                <i class="fa-solid fa-trash-can"></i> Hapus
                            </button>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ========================================== -->
<!-- MODAL: TAMBAH PENGUMUMAN BARU (Admin/Staf) -->
<!-- ========================================== -->
<?php if ($can_manage): ?>
<div id="modalCreateAnnounce" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-blue-400"><i class="fa-solid fa-bullhorn"></i></span> Buat Pengumuman Baru
            </h3>
            <button onclick="document.getElementById('modalCreateAnnounce').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" id="createAnnounceForm" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_announcement">
            <input type="hidden" name="content" id="createContentHidden">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-300">Judul Pengumuman *</label>
                    <input type="text" name="title" required placeholder="Contoh: Jadwal Libur Semester Ganjil & Masuk Sekolah" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Kategori *</label>
                    <select name="category" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <?php foreach (ANNOUNCEMENT_CATEGORIES as $k => $cat): ?>
                            <option value="<?= $k ?>"><?= $cat['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Target Audiens *</label>
                    <select name="target_role" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="semua">Semua Warga Sekolah</option>
                        <option value="siswa">Khusus Siswa</option>
                        <option value="guru">Khusus Guru</option>
                        <option value="orang_tua">Khusus Orang Tua / Wali</option>
                        <option value="staf">Khusus Staf</option>
                    </select>
                </div>

                <!-- Target Kelas Tertentu -->
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Target Rombel/Kelas (Opsional)</label>
                    <select name="class_id" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="">Semua Rombel/Kelas</option>
                        <?php foreach ($all_classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>">Kelas <?= htmlspecialchars($cls['name']) ?> (Tingkat <?= htmlspecialchars($cls['grade_level']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-[10px] text-slate-500">Pilih jika pengumuman ini hanya untuk rombel kelas tertentu.</p>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Status Publikasi</label>
                    <select name="status" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="published">Terbitkan Langsung</option>
                        <option value="draft">Simpan Sebagai Draft</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-300">Tanggal Kedaluwarsa (Opsional)</label>
                    <input type="datetime-local" name="expires_at" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    <p class="mt-1 text-[10px] text-slate-500">Setelah tanggal ini, pengumuman otomatis tidak tampil bagi siswa dan orang tua.</p>
                </div>
            </div>

            <!-- Rich Text Editor Quill -->
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Isi Pengumuman (Rich Text Editor) *</label>
                <div id="createQuillEditor" class="rounded-xl"></div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Lampiran Berkas (Opsional, maks 10MB)</label>
                <input type="file" name="attachment" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.zip,.rar"
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1 file:text-xs file:font-semibold file:text-white cursor-pointer">
                <p class="mt-1 text-[10px] text-slate-500">Format didukung: PDF, DOCX, XLSX, PPTX, JPG, PNG, ZIP, RAR</p>
            </div>

            <!-- Opsi Sinkronisasi Kalender Akademik -->
            <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4 space-y-3">
                <div class="flex items-center gap-3">
                    <input type="checkbox" name="sync_calendar" id="createSyncCalendar" value="1" onchange="document.getElementById('calendarSyncDetails').classList.toggle('hidden', !this.checked)" class="rounded border-white/20 bg-slate-950 text-emerald-500 focus:ring-emerald-500">
                    <label for="createSyncCalendar" class="text-sm font-semibold text-emerald-300 cursor-pointer">
                        <i class="fa-solid fa-calendar-days text-emerald-400 mr-1"></i> <strong>Sinkronkan ke Kalender Akademik Sekolah</strong>
                    </label>
                </div>

                <div id="calendarSyncDetails" class="hidden grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-emerald-500/10">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-300">Tanggal Mulai Agenda *</label>
                        <input type="date" name="event_date" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-300">Tanggal Berakhir (Opsional)</label>
                        <input type="date" name="event_end_date" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-blue-500">
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3 p-3 rounded-xl border border-white/10 bg-slate-950/50">
                <input type="checkbox" name="is_pinned" id="createPinned" value="1" class="rounded border-white/20 bg-slate-950 text-blue-500 focus:ring-blue-500">
                <label for="createPinned" class="text-sm text-slate-300 cursor-pointer">
                    <i class="fa-solid fa-thumbtack text-amber-400 mr-1"></i> <strong>Sematkan (Pin) ke atas</strong> — pengumuman ini akan selalu muncul di posisi teratas
                </label>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalCreateAnnounce').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-blue-500/20 cursor-pointer">
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
    <div class="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-blue-400"><i class="fa-solid fa-pen-to-square"></i></span> Edit Pengumuman
            </h3>
            <button onclick="document.getElementById('modalEditAnnounce').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" id="editAnnounceForm" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_announcement">
            <input type="hidden" name="edit_id" id="editId">
            <input type="hidden" name="content" id="editContentHidden">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-300">Judul Pengumuman *</label>
                    <input type="text" name="title" id="editTitle" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Kategori *</label>
                    <select name="category" id="editCategory" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <?php foreach (ANNOUNCEMENT_CATEGORIES as $k => $cat): ?>
                            <option value="<?= $k ?>"><?= $cat['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Target Audiens *</label>
                    <select name="target_role" id="editTargetRole" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="semua">Semua Warga Sekolah</option>
                        <option value="siswa">Khusus Siswa</option>
                        <option value="guru">Khusus Guru</option>
                        <option value="orang_tua">Khusus Orang Tua / Wali</option>
                        <option value="staf">Khusus Staf</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Target Rombel/Kelas (Opsional)</label>
                    <select name="class_id" id="editClassId" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="">Semua Rombel/Kelas</option>
                        <?php foreach ($all_classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>">Kelas <?= htmlspecialchars($cls['name']) ?> (Tingkat <?= htmlspecialchars($cls['grade_level']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Status Publikasi</label>
                    <select name="status" id="editStatus" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="published">Published</option>
                        <option value="draft">Draft</option>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-xs font-medium text-slate-300">Tanggal Kedaluwarsa</label>
                    <input type="datetime-local" name="expires_at" id="editExpiresAt" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>
            </div>

            <!-- Rich Text Editor Quill Edit -->
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Isi Pengumuman *</label>
                <div id="editQuillEditor" class="rounded-xl"></div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Lampiran Berkas Baru (Opsional)</label>
                <input type="file" name="attachment" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.zip,.rar"
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none file:mr-3 file:rounded-lg file:border-0 file:bg-blue-600 file:px-3 file:py-1 file:text-xs file:font-semibold file:text-white cursor-pointer">
            </div>

            <div id="editAttachmentInfo" class="hidden p-3 rounded-xl border border-white/10 bg-slate-950/50 flex items-center justify-between gap-3">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="text-blue-400"><i class="fa-solid fa-paperclip"></i></span>
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
                    <i class="fa-solid fa-thumbtack text-amber-400 mr-1"></i> <strong>Sematkan (Pin) ke atas</strong>
                </label>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalEditAnnounce').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-emerald-500/20 cursor-pointer">
                    <i class="fa-solid fa-floppy-disk mr-1"></i> Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: DAFTAR PEMBACA PENGUMUMAN (Admin)   -->
<!-- ========================================== -->
<div id="modalReaders" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-xl max-h-[85vh] flex flex-col rounded-3xl border border-purple-500/30 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10">
            <div>
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <span class="text-purple-400"><i class="fa-regular fa-eye"></i></span> Riwayat Konfirmasi Baca
                </h3>
                <p class="text-xs text-slate-400 mt-1 line-clamp-1" id="readersModalTitle"></p>
            </div>
            <button onclick="document.getElementById('modalReaders').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="my-4 flex items-center justify-between p-3 rounded-2xl bg-purple-500/10 border border-purple-500/20 text-purple-300 text-xs">
            <span>Total Pengguna yang Telah Membaca:</span>
            <span class="font-bold text-base text-white" id="readersTotalCount">0</span>
        </div>

        <div class="flex-1 overflow-y-auto space-y-2 pr-1" id="readersListContainer">
            <p class="text-center text-xs text-slate-500 py-8">Memuat data pembaca...</p>
        </div>

        <div class="pt-4 border-t border-white/10 flex justify-end">
            <button onclick="document.getElementById('modalReaders').classList.add('hidden')" class="rounded-xl bg-white/10 hover:bg-white/15 px-4 py-2 text-xs font-semibold text-slate-200 transition cursor-pointer">
                Tutup
            </button>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: SINKRONISASI KALENDER CEPAT         -->
<!-- ========================================== -->
<div id="modalQuickSync" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-md rounded-3xl border border-emerald-500/30 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="text-center mb-6">
            <span class="text-5xl text-emerald-400"><i class="fa-solid fa-calendar-days"></i></span>
            <h3 class="text-lg font-bold text-white mt-3">Sinkronkan ke Kalender?</h3>
            <p class="text-xs text-slate-400 mt-1" id="quickSyncTitle"></p>
        </div>
        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sync_calendar_quick">
            <input type="hidden" name="announcement_id" id="quickSyncAnnId" value="">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Tanggal Pelaksanaan Agenda *</label>
                <input type="date" name="event_date" required value="<?= date('Y-m-d') ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white outline-none focus:border-blue-500">
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('modalQuickSync').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2 text-xs font-semibold text-white transition shadow-lg shadow-emerald-500/20 cursor-pointer inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-calendar-check"></i> Ya, Masukkan ke Kalender
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
            <span class="text-5xl text-rose-500"><i class="fa-solid fa-trash-can"></i></span>
            <h3 class="text-lg font-bold text-white mt-3">Hapus Pengumuman?</h3>
            <p class="text-sm text-slate-400 mt-2">Anda akan menghapus pengumuman:</p>
            <p class="text-base font-bold text-rose-300 mt-1" id="deleteTitle"></p>
            <p class="text-xs text-slate-500 mt-2">Tindakan ini tidak dapat dibatalkan. Lampiran berkas juga akan ikut dihapus.</p>
        </div>
        <div class="flex justify-center gap-3">
            <button type="button" onclick="document.getElementById('modalDeleteConfirm').classList.add('hidden')" 
                    class="rounded-xl border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                Batal
            </button>
            <form method="POST" id="deleteForm" class="inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_announcement">
                <input type="hidden" name="delete_id" id="deleteIdInput" value="">
                <button type="submit" class="rounded-xl bg-rose-600 hover:bg-rose-500 px-5 py-2.5 text-sm font-semibold text-white transition shadow-lg shadow-rose-500/20 cursor-pointer inline-flex items-center gap-1.5">
                    <i class="fa-solid fa-trash-can"></i> Ya, Hapus Sekarang
                </button>
            </form>
        </div>
    </div>
</div>

<script>
// Inisialisasi Quill.js Editors
let createQuill = null;
let editQuill = null;

const quillToolbarOptions = [
    ['bold', 'italic', 'underline', 'strike'],
    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
    [{ 'header': [1, 2, 3, false] }],
    ['blockquote', 'code-block'],
    ['link'],
    ['clean']
];

document.addEventListener('DOMContentLoaded', function() {
    // Inisialisasi Create Quill
    const createElem = document.getElementById('createQuillEditor');
    if (createElem) {
        createQuill = new Quill('#createQuillEditor', {
            theme: 'snow',
            placeholder: 'Tuliskan rincian pengumuman resmi sekolah secara lengkap di sini...',
            modules: { toolbar: quillToolbarOptions }
        });
    }

    // Inisialisasi Edit Quill
    const editElem = document.getElementById('editQuillEditor');
    if (editElem) {
        editQuill = new Quill('#editQuillEditor', {
            theme: 'snow',
            placeholder: 'Tuliskan rincian pengumuman resmi sekolah secara lengkap di sini...',
            modules: { toolbar: quillToolbarOptions }
        });
    }

    // Bind form submit untuk copy innerHTML ke hidden input
    const createForm = document.getElementById('createAnnounceForm');
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            if (createQuill) {
                const html = createQuill.root.innerHTML;
                const text = createQuill.getText().trim();
                if (text === '') {
                    alert('Isi pengumuman wajib diisi.');
                    e.preventDefault();
                    return false;
                }
                document.getElementById('createContentHidden').value = html;
            }
        });
    }

    const editForm = document.getElementById('editAnnounceForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            if (editQuill) {
                const html = editQuill.root.innerHTML;
                const text = editQuill.getText().trim();
                if (text === '') {
                    alert('Isi pengumuman wajib diisi.');
                    e.preventDefault();
                    return false;
                }
                document.getElementById('editContentHidden').value = html;
            }
        });
    }
});

// Buka Modal Edit & Isi Data
function openEditModal(data) {
    document.getElementById('editId').value = data.id;
    document.getElementById('editTitle').value = data.title;
    document.getElementById('editTargetRole').value = data.target_role;
    document.getElementById('editClassId').value = data.class_id || '';
    document.getElementById('editCategory').value = data.category || 'umum';
    document.getElementById('editStatus').value = data.status || 'published';
    document.getElementById('editPinned').checked = (data.is_pinned == 1);

    // Set content ke Quill Editor
    if (editQuill) {
        editQuill.root.innerHTML = data.content || '';
    }

    // Expires at
    if (data.expires_at) {
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

// Buka Modal Pembaca Pengumuman
function openReadersModal(annId, title) {
    document.getElementById('readersModalTitle').textContent = title;
    document.getElementById('readersTotalCount').textContent = '...';
    const container = document.getElementById('readersListContainer');
    container.innerHTML = '<p class="text-center text-xs text-slate-500 py-8">Memuat data pembaca...</p>';
    document.getElementById('modalReaders').classList.remove('hidden');

    fetch('announcements.php?get_readers=' + annId)
        .then(res => res.json())
        .then(data => {
            document.getElementById('readersTotalCount').textContent = data.length;
            if (!data || data.length === 0) {
                container.innerHTML = '<p class="text-center text-xs text-slate-500 py-8">Belum ada pengguna yang mengonfirmasi baca pengumuman ini.</p>';
                return;
            }
            let html = '';
            data.forEach(r => {
                const readDate = new Date(r.read_at).toLocaleString('id-ID', {
                    day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
                });
                const roleBadge = r.role === 'siswa' ? 'bg-blue-500/20 text-blue-300 border-blue-500/30' :
                                  (r.role === 'guru' ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' :
                                  (r.role === 'orang_tua' ? 'bg-amber-500/20 text-amber-300 border-amber-500/30' : 'bg-slate-500/20 text-slate-300 border-slate-500/30'));
                const classLabel = r.class_name ? ` • <i class="fa-solid fa-graduation-cap"></i> ${r.class_name}` : '';
                html += `
                    <div class="flex items-center justify-between p-3 rounded-2xl border border-white/5 bg-white/5">
                        <div>
                            <p class="text-xs font-bold text-white">${r.name}</p>
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                <span class="border px-1.5 py-0.2 rounded-md ${roleBadge}">${r.role.toUpperCase()}</span>
                                ${classLabel}
                            </p>
                        </div>
                        <span class="text-[11px] text-slate-400"><i class="fa-regular fa-clock"></i> ${readDate}</span>
                    </div>
                `;
            });
            container.innerHTML = html;
        })
        .catch(err => {
            container.innerHTML = '<p class="text-center text-xs text-rose-400 py-8">Gagal memuat data pembaca.</p>';
        });
}

// Buka Modal Quick Sync Kalender
function openQuickSyncModal(annId, title) {
    document.getElementById('quickSyncAnnId').value = annId;
    document.getElementById('quickSyncTitle').textContent = title;
    document.getElementById('modalQuickSync').classList.remove('hidden');
}

// Konfirmasi Hapus
function confirmDelete(id, title) {
    document.getElementById('deleteTitle').textContent = title;
    document.getElementById('deleteIdInput').value = id;
    document.getElementById('modalDeleteConfirm').classList.remove('hidden');
}

// Close modals on outside click
document.querySelectorAll('#modalCreateAnnounce, #modalEditAnnounce, #modalDeleteConfirm, #modalReaders, #modalQuickSync').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.classList.add('hidden');
    });
});

// Close modals on ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('#modalCreateAnnounce, #modalEditAnnounce, #modalDeleteConfirm, #modalReaders, #modalQuickSync').forEach(m => m.classList.add('hidden'));
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
