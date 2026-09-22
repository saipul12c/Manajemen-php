<?php
session_start();
require_once __DIR__ . "/../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['administrator', 'staf'], true);

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. TAMBAH PENGUMUMAN (Khusus Admin & Staf)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_announcement') {
    if (!$can_manage) {
        header("Location: announcements.php?error=unauthorized");
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $target_role = trim($_POST['target_role'] ?? 'semua');

    $valid_targets = ['semua', 'guru', 'siswa', 'orang_tua', 'staf'];
    if (!in_array($target_role, $valid_targets, true)) {
        $target_role = 'semua';
    }

    if ($title === '' || $content === '') {
        $message = "Judul dan isi pengumuman wajib diisi.";
        $message_type = "error";
    } else {
        $stmt_ins = $pdo->prepare("INSERT INTO announcements (title, content, target_role, author_id) VALUES (?, ?, ?, ?)");
        $stmt_ins->execute([$title, $content, $target_role, $user_id]);
        $message = "Pengumuman berhasil diterbitkan.";
        $message_type = "success";
    }
}

// -------------------------------------------------------------
// 2. HAPUS PENGUMUMAN (Khusus Admin & Staf)
// -------------------------------------------------------------
if (isset($_GET['delete_id']) && $can_manage) {
    $delete_id = (int) $_GET['delete_id'];
    $stmt_del = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt_del->execute([$delete_id]);
    $message = "Pengumuman berhasil dihapus.";
    $message_type = "success";
}

// -------------------------------------------------------------
// 3. QUERY PENGUMUMAN BERDASARKAN TARGET ROLE
// -------------------------------------------------------------
if ($can_manage) {
    // Admin & Staf dapat melihat semua pengumuman
    $stmt = $pdo->query("
        SELECT a.*, u.name as author_name, u.role as author_role 
        FROM announcements a 
        JOIN users u ON a.author_id = u.id 
        ORDER BY a.id DESC
    ");
} else {
    // Role lain hanya melihat yang sasaran 'semua' atau role mereka sendiri
    $stmt = $pdo->prepare("
        SELECT a.*, u.name as author_name, u.role as author_role 
        FROM announcements a 
        JOIN users u ON a.author_id = u.id 
        WHERE a.target_role IN ('semua', ?) 
        ORDER BY a.id DESC
    ");
    $stmt->execute([$user_role]);
}
$announcements = $stmt->fetchAll();

$page_title = "Pengumuman & Agenda";
require_once __DIR__ . "/includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
            <span>📢</span> Pengumuman & Agenda Sekolah
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            <?= $can_manage ? 'Kelola dan terbitkan informasi resmi sekolah untuk seluruh role.' : 'Informasi resmi dan agenda terbaru untuk peran ' . htmlspecialchars(getRoleLabel($user_role)) . '.' ?>
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

<!-- Daftar Kartu Pengumuman -->
<div class="space-y-4">
    <?php if (empty($announcements)): ?>
        <div class="rounded-3xl border border-white/10 bg-white/5 p-12 text-center text-slate-400">
            <span class="text-4xl">📭</span>
            <p class="mt-3 text-base font-semibold text-white">Belum Ada Pengumuman</p>
            <p class="text-xs text-slate-500 mt-1">Saat ini belum ada pengumuman yang ditujukan untuk Anda.</p>
        </div>
    <?php else: ?>
        <?php foreach ($announcements as $a): ?>
            <div class="rounded-3xl border border-white/10 bg-white/5 p-6 shadow-xl transition hover:border-white/20">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-4 border-b border-white/5">
                    <div class="flex items-center gap-2.5 flex-wrap">
                        <span class="inline-flex items-center rounded-lg border px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wider <?= $a['target_role'] === 'semua' ? 'border-blue-500/30 bg-blue-500/10 text-blue-300' : getRoleBadge($a['target_role']) ?>">
                            Sasaran: <?= $a['target_role'] === 'semua' ? 'Semua Warga Sekolah' : getRoleLabel($a['target_role']) ?>
                        </span>
                        <span class="text-xs text-slate-400">• Ditulis oleh <strong class="text-slate-200"><?= htmlspecialchars($a['author_name']) ?></strong> (<?= htmlspecialchars(getRoleLabel($a['author_role'])) ?>)</span>
                    </div>

                    <div class="flex items-center gap-3 text-xs text-slate-500">
                        <span>🗓️ <?= date('d M Y, H:i', strtotime($a['created_at'])) ?></span>
                        <?php if ($can_manage): ?>
                            <a href="announcements.php?delete_id=<?= $a['id'] ?>" 
                               onclick="return confirm('Hapus pengumuman ini?');" 
                               class="text-rose-400 hover:text-rose-300 font-medium">
                                Hapus
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pt-4">
                    <h2 class="text-lg font-bold text-white mb-2"><?= htmlspecialchars($a['title']) ?></h2>
                    <p class="text-sm text-slate-300 leading-relaxed whitespace-pre-line"><?= htmlspecialchars($a['content']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal Tambah Pengumuman (Admin/Staf) -->
<?php if ($can_manage): ?>
<div id="modalCreateAnnounce" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span>📢</span> Buat Pengumuman Baru
            </h3>
            <button onclick="document.getElementById('modalCreateAnnounce').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg">✕</button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_announcement">

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Judul Pengumuman *</label>
                <input type="text" name="title" required placeholder="Contoh: Jadwal Libur Semester Ganjil" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Target Audiens (Sasaran Peran) *</label>
                <select name="target_role" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                    <option value="semua">📢 Semua Warga Sekolah (Umum)</option>
                    <option value="siswa">📖 Khusus Siswa</option>
                    <option value="guru">📚 Khusus Guru</option>
                    <option value="orang_tua">🎓 Khusus Orang Tua / Wali</option>
                    <option value="staf">📂 Khusus Staf</option>
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Isi Pengumuman *</label>
                <textarea name="content" rows="4" required placeholder="Tuliskan detail pengumuman secara lengkap di sini..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalCreateAnnounce').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition">
                    Terbitkan Sekarang
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
