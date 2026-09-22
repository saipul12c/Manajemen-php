<?php
session_start();
require_once __DIR__ . "/../config/database.php";

requireRole(['administrator']);

$user_id = (int) $_SESSION['user_id'];
$message = "";
$message_type = "";

// 1. TAMBAH KELAS BARU
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_class') {
    $name = trim($_POST['name'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? '10');
    $academic_year = trim($_POST['academic_year'] ?? '2026/2027');

    if ($name === '') {
        $message = "Nama kelas wajib diisi.";
        $message_type = "error";
    } else {
        $stmt = $pdo->prepare("INSERT INTO classes (name, grade_level, academic_year) VALUES (?, ?, ?)");
        $stmt->execute([$name, $grade_level, $academic_year]);
        logActivity($pdo, 'CREATE_CLASS', "Menambahkan kelas baru: $name");
        $message = "Kelas $name berhasil ditambahkan!";
        $message_type = "success";
    }
}

// 2. HAPUS KELAS
if (isset($_GET['delete_id'])) {
    $del_id = (int) $_GET['delete_id'];
    $pdo->prepare("UPDATE users SET class_id = NULL WHERE class_id = ?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM classes WHERE id = ?")->execute([$del_id]);
    logActivity($pdo, 'DELETE_CLASS', "Menghapus kelas ID: $del_id");
    $message = "Kelas berhasil dihapus.";
    $message_type = "success";
}

// 3. ASSIGN SISWA KE KELAS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_student') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $target_class_id = (int)($_POST['target_class_id'] ?? 0);

    if ($student_id > 0) {
        $pdo->prepare("UPDATE users SET class_id = ? WHERE id = ? AND role = 'siswa'")->execute([$target_class_id ?: null, $student_id]);
        $message = "Kelas siswa berhasil diperbarui.";
        $message_type = "success";
    }
}

// Query Daftar Kelas
$classes_list = $pdo->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM users WHERE class_id = c.id AND role = 'siswa') as student_count
    FROM classes c
    ORDER BY c.grade_level ASC, c.name ASC
")->fetchAll();

// Query Siswa untuk modal assign
$all_students = $pdo->query("SELECT id, name, email, class_id FROM users WHERE role = 'siswa' ORDER BY name ASC")->fetchAll();

$page_title = "Manajemen Kelas & Rombel";
require_once __DIR__ . "/includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-500/20 text-blue-400 text-xl border border-blue-500/30 shadow-lg shadow-blue-500/10">
                🏫
            </span>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">Manajemen Kelas & Rombongan Belajar</h1>
                <p class="text-sm text-slate-400">Atur pembagian kelas siswa untuk integrasi presensi, tugas, dan ujian</p>
            </div>
        </div>
    </div>

    <button onclick="document.getElementById('modalAddClass').classList.remove('hidden')" 
            class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition">
        <span>➕</span> Tambah Kelas Baru
    </button>
</div>

<?php if ($message): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <span><?= htmlspecialchars($message) ?></span>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100">✕</button>
    </div>
<?php endif; ?>

<!-- Grid Kartu Kelas -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <?php foreach ($classes_list as $cl): ?>
        <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-6 shadow-xl backdrop-blur flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold uppercase tracking-wider px-2.5 py-1 rounded-lg border border-blue-500/30 bg-blue-500/10 text-blue-300">
                        Tingkat <?= htmlspecialchars($cl['grade_level']) ?>
                    </span>
                    <a href="classes.php?delete_id=<?= $cl['id'] ?>" 
                       onclick="return confirm('Hapus kelas ini? Siswa di dalamnya akan menjadi tanpa kelas.');"
                       class="text-xs text-rose-400 hover:text-rose-300">
                        🗑️ Hapus
                    </a>
                </div>
                <h3 class="text-xl font-bold text-white"><?= htmlspecialchars($cl['name']) ?></h3>
                <p class="text-xs text-slate-400 mt-1">Tahun Ajaran: <?= htmlspecialchars($cl['academic_year']) ?></p>
            </div>

            <div class="mt-6 pt-4 border-t border-white/5 flex items-center justify-between">
                <span class="text-xs text-slate-400">Total Siswa:</span>
                <span class="text-lg font-black text-white"><?= $cl['student_count'] ?> anak</span>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Tabel Penempatan Siswa per Kelas -->
<div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-xl backdrop-blur">
    <div class="border-b border-white/10 p-5 flex items-center justify-between">
        <h3 class="text-base font-bold text-white">Daftar Siswa & Rombel</h3>
        <span class="text-xs text-slate-400">Pindahkan siswa ke rombel yang sesuai</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-slate-300">
            <thead class="border-b border-white/10 bg-white/5 text-[10px] uppercase font-bold text-slate-400">
                <tr>
                    <th class="px-6 py-3.5">Nama Siswa</th>
                    <th class="px-6 py-3.5">Email</th>
                    <th class="px-6 py-3.5">Kelas Saat Ini</th>
                    <th class="px-6 py-3.5 text-right">Ubah Kelas</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php foreach ($all_students as $stu): ?>
                    <tr class="hover:bg-white/[0.02]">
                        <td class="px-6 py-3 font-bold text-white"><?= htmlspecialchars($stu['name']) ?></td>
                        <td class="px-6 py-3 text-slate-400"><?= htmlspecialchars($stu['email']) ?></td>
                        <td class="px-6 py-3">
                            <span class="px-2 py-0.5 rounded-md border border-white/10 bg-white/5 text-slate-300">
                                <?php 
                                $cname = 'Belum Ada Kelas';
                                foreach ($classes_list as $cl) {
                                    if ($cl['id'] == $stu['class_id']) { $cname = $cl['name']; break; }
                                }
                                echo htmlspecialchars($cname);
                                ?>
                            </span>
                        </td>
                        <td class="px-6 py-3 text-right">
                            <form method="POST" class="inline-flex items-center gap-2">
                                <input type="hidden" name="action" value="assign_student">
                                <input type="hidden" name="student_id" value="<?= $stu['id'] ?>">
                                <select name="target_class_id" class="rounded-lg border border-white/10 bg-slate-950 px-2 py-1 text-xs text-white">
                                    <option value="0">-- Tanpa Kelas --</option>
                                    <?php foreach ($classes_list as $cl): ?>
                                        <option value="<?= $cl['id'] ?>" <?= $cl['id'] == $stu['class_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cl['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="rounded-lg bg-blue-600 hover:bg-blue-500 px-2.5 py-1 text-xs font-semibold text-white">
                                    Simpan
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah Kelas -->
<div id="modalAddClass" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-md rounded-3xl border border-white/10 bg-slate-900 p-6 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-4">
            <h3 class="text-lg font-bold text-white">➕ Tambah Kelas / Rombel</h3>
            <button onclick="document.getElementById('modalAddClass').classList.add('hidden')" class="text-slate-400 hover:text-white">✕</button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_class">
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Kelas *</label>
                <input type="text" name="name" required placeholder="Contoh: X MIPA 1 atau XI IPS 2" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tingkat</label>
                    <select name="grade_level" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white">
                        <option value="10">Kelas 10</option>
                        <option value="11">Kelas 11</option>
                        <option value="12">Kelas 12</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tahun Ajaran</label>
                    <input type="text" name="academic_year" value="2026/2027" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white">
                </div>
            </div>
            <div class="pt-4 flex justify-end gap-2 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalAddClass').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/10">Batal</button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2 text-xs font-semibold text-white">Simpan Kelas</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
