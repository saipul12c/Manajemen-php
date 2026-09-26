<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

// Akses diberikan kepada Administrator dan Staf Tata Usaha
requireRole(['administrator', 'staf']);

// Handler Unduh Template CSV Siswa
if (isset($_GET['download_template']) && $_GET['download_template'] === 'csv_siswa') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=template_import_siswa.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Nama Lengkap', 'Email', 'NISN', 'Nama Kelas', 'No HP', 'Alamat', 'Password']);
    fputcsv($output, ['Budi Santoso', 'budi.santoso@siswa.sekolah.sch.id', '0081234567', 'X MIPA 1', '081234567890', 'Jl. Pendidikan No. 10 Jakarta', 'Siswa2026!']);
    fputcsv($output, ['Citra Dewi', 'citra.dewi@siswa.sekolah.sch.id', '0089876543', 'X MIPA 2', '089876543210', 'Jl. Merdeka No. 45 Jakarta', 'Siswa2026!']);
    fclose($output);
    exit;
}

$message = "";
$message_type = "";

// -------------------------------------------------------------
// 1. HAPUS PENGGUNA
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'delete_user') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $delete_id = (int) ($_POST['user_id'] ?? 0);

        if ($delete_id === (int) $_SESSION['user_id']) {
            $message = "Anda tidak dapat menghapus akun Anda sendiri!";
            $message_type = "error";
        } else {
            // Ambil info target pengguna terlebih dahulu
            $stmt_target = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ?");
            $stmt_target->execute([$delete_id]);
            $target_user = $stmt_target->fetch();

            if (!$target_user) {
                $message = "Pengguna tidak ditemukan.";
                $message_type = "error";
            } elseif ($_SESSION['user_role'] === 'staf' && !in_array($target_user['role'], ['siswa', 'orang_tua'], true)) {
                $message = "Staf Tata Usaha tidak memiliki wewenang untuk menghapus akun " . getRoleLabel($target_user['role']) . ".";
                $message_type = "error";
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$delete_id]);
                    logActivity($pdo, 'DELETE_USER', "Menghapus akun: " . $target_user['name'] . " (" . $target_user['role'] . ")");
                    $message = "Pengguna " . htmlspecialchars($target_user['name']) . " berhasil dihapus dari sistem.";
                    $message_type = "success";
                } catch (PDOException $e) {
                    $message = "Gagal menghapus pengguna: " . $e->getMessage();
                    $message_type = "error";
                }
            }
        }
    }
}

// -------------------------------------------------------------
// 2. SIMPAN TAMBAH PENGGUNA
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'create_user') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'siswa';
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if (!array_key_exists($role, ROLES)) {
            $role = 'siswa';
        }

        // Proteksi: Staf TU hanya boleh menambah role siswa atau orang_tua
        if ($_SESSION['user_role'] === 'staf' && !in_array($role, ['siswa', 'orang_tua'], true)) {
            $message = "Staf Tata Usaha hanya diizinkan menambahkan akun Siswa atau Orang Tua.";
            $message_type = "error";
        } elseif ($name === '' || $email === '' || $password === '') {
            $message = "Nama, email, dan password wajib diisi.";
            $message_type = "error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Format email tidak valid.";
            $message_type = "error";
        } elseif (strlen($password) < 6) {
            $message = "Password minimal 6 karakter.";
            $message_type = "error";
        } else {
            $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt_check->execute([$email]);
            if ($stmt_check->fetch()) {
                $message = "Email sudah digunakan oleh akun lain.";
                $message_type = "error";
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt_ins = $pdo->prepare("INSERT INTO users (name, email, password, role, phone, address) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt_ins->execute([$name, $email, $hashed, $role, $phone, $address]);
                logActivity($pdo, 'CREATE_USER', "Menambahkan akun baru: $name ($role)");
                $message = "Pengguna baru (" . htmlspecialchars($name) . ") berhasil ditambahkan.";
                $message_type = "success";
            }
        }
    } // end CSRF check
}

// -------------------------------------------------------------
// 3. SIMPAN EDIT PENGGUNA
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'edit_user') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $edit_id = (int) ($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'siswa';
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $new_password = $_POST['new_password'] ?? '';

        if (!array_key_exists($role, ROLES)) {
            $role = 'siswa';
        }

        // Cek target akun yang ingin diedit
        $stmt_cur = $pdo->prepare("SELECT id, name, role FROM users WHERE id = ?");
        $stmt_cur->execute([$edit_id]);
        $target_user = $stmt_cur->fetch();

        if (!$target_user) {
            $message = "Pengguna tidak ditemukan.";
            $message_type = "error";
        } elseif ($_SESSION['user_role'] === 'staf' && (!in_array($target_user['role'], ['siswa', 'orang_tua'], true) || !in_array($role, ['siswa', 'orang_tua'], true))) {
            $message = "Staf Tata Usaha hanya memiliki wewenang mengelola data akun Siswa dan Orang Tua.";
            $message_type = "error";
        } elseif ($edit_id === (int) $_SESSION['user_id'] && $role !== $_SESSION['user_role']) {
            $message = "Anda tidak dapat mengubah role akun Anda sendiri!";
            $message_type = "error";
        } elseif ($name === '' || $email === '') {
            $message = "Nama dan email wajib diisi.";
            $message_type = "error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Format email tidak valid.";
            $message_type = "error";
        } else {
            // Cek duplikasi email pada user lain
            $stmt_check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt_check->execute([$email, $edit_id]);
            if ($stmt_check->fetch()) {
                $message = "Email sudah digunakan oleh akun lain.";
                $message_type = "error";
            } else {
                if ($new_password !== '') {
                    if (strlen($new_password) < 6) {
                        $message = "Password baru minimal 6 karakter.";
                        $message_type = "error";
                    } else {
                        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                        $stmt_up = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, phone = ?, address = ?, password = ? WHERE id = ?");
                        $stmt_up->execute([$name, $email, $role, $phone, $address, $hashed, $edit_id]);
                        logActivity($pdo, 'EDIT_USER', "Memperbarui akun dan password: $name ($role)");
                        $message = "Data pengguna dan password berhasil diperbarui.";
                        $message_type = "success";
                    }
                } else {
                    $stmt_up = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, phone = ?, address = ? WHERE id = ?");
                    $stmt_up->execute([$name, $email, $role, $phone, $address, $edit_id]);
                    logActivity($pdo, 'EDIT_USER', "Memperbarui akun: $name ($role)");
                    $message = "Data pengguna berhasil diperbarui.";
                    $message_type = "success";
                }

                // Jika mengedit akun diri sendiri, perbarui data session juga
                if ($edit_id === (int) $_SESSION['user_id']) {
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                }
            }
        }
    } // end CSRF check
}

// -------------------------------------------------------------
// 3B. IMPOR SISWA MASSAL (CSV)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'import_csv_students') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $csv_tmp = $_FILES['csv_file']['tmp_name'];
            $file_ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

            if (!in_array($file_ext, ['csv', 'txt'], true)) {
                $message = "Format berkas harus berupa file CSV (.csv).";
                $message_type = "error";
            } else {
                $handle = fopen($csv_tmp, 'r');
                $imported_count = 0;
                $skipped_count = 0;
                $row_idx = 0;

                // Siapkan pemetaan nama kelas -> class_id
                $classes_map = $pdo->query("SELECT LOWER(name), id FROM classes")->fetchAll(PDO::FETCH_KEY_PAIR);

                while (($row = fgetcsv($handle, 1000, ",")) !== false) {
                    $row_idx++;
                    if ($row_idx === 1) {
                        // Lewati baris header judul
                        continue;
                    }
                    if (empty($row[0]) || empty($row[1])) {
                        continue;
                    }

                    $name     = trim($row[0]);
                    $email    = trim($row[1]);
                    $nisn     = trim($row[2] ?? '');
                    $c_name   = strtolower(trim($row[3] ?? ''));
                    $phone    = trim($row[4] ?? '');
                    $address  = trim($row[5] ?? '');
                    $pass_raw = !empty($row[6]) ? trim($row[6]) : 'Siswa' . date('Y') . '!';

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $skipped_count++;
                        continue;
                    }

                    $class_id = $classes_map[$c_name] ?? null;

                    // Cek duplikasi email
                    $stmt_chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt_chk->execute([$email]);
                    if ($stmt_chk->fetch()) {
                        $skipped_count++;
                        continue;
                    }

                    $hashed_pwd = password_hash($pass_raw, PASSWORD_DEFAULT);
                    $stmt_ins = $pdo->prepare("
                        INSERT INTO users (name, email, password, role, nisn, class_id, phone, address)
                        VALUES (?, ?, ?, 'siswa', ?, ?, ?, ?)
                    ");
                    $stmt_ins->execute([$name, $email, $hashed_pwd, $nisn, $class_id, $phone, $address]);
                    $imported_count++;
                }
                fclose($handle);

                logActivity($pdo, 'IMPORT_STUDENTS_CSV', "Mengimpor $imported_count siswa massal via CSV ($skipped_count dilewati)");
                $message = "Proses impor selesai: Berhasil menambahkan $imported_count siswa baru ($skipped_count baris dilewati/duplikat).";
                $message_type = "success";
            }
        } else {
            $message = "Pilih berkas CSV untuk diimpor.";
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 4. DATA EDIT (Jika sedang mode edit)
// -------------------------------------------------------------
$editing_user = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = (int) $_GET['id'];
    $stmt_fetch = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt_fetch->execute([$edit_id]);
    $editing_user = $stmt_fetch->fetch();

    // Validasi keamanan: cegah staf membuka modal edit untuk role selain siswa/ortu
    if ($editing_user && $_SESSION['user_role'] === 'staf' && !in_array($editing_user['role'], ['siswa', 'orang_tua'], true)) {
        $message = "Staf Tata Usaha hanya memiliki wewenang untuk mengedit akun Siswa dan Orang Tua.";
        $message_type = "error";
        $editing_user = null;
    }
}

// Role yang diizinkan untuk dikelola oleh pengguna yang sedang login
$manageable_roles = ($_SESSION['user_role'] === 'staf')
    ? ['siswa' => ROLES['siswa'], 'orang_tua' => ROLES['orang_tua']]
    : ROLES;

// -------------------------------------------------------------
// 5. QUERY DAFTAR PENGGUNA (Search & Filter Role)
// -------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$filter_role = trim($_GET['role'] ?? '');

$sql = "SELECT id, name, email, role, phone, address, created_at FROM users WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($filter_role !== '' && array_key_exists($filter_role, ROLES)) {
    $sql .= " AND role = ?";
    $params[] = $filter_role;
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users_list = $stmt->fetchAll();

$page_title = ($_SESSION['user_role'] === 'staf') ? "Kelola Siswa & Orang Tua" : "Manajemen Pengguna";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
            <i class="fa-solid fa-users text-blue-400"></i> <?= $_SESSION['user_role'] === 'staf' ? 'Kelola Siswa & Orang Tua' : 'Manajemen Pengguna' ?>
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            <?= $_SESSION['user_role'] === 'staf' ? 'Kelola akun Siswa dan Orang Tua / Wali untuk administrasi kesiswaan & data pokok.' : 'Kelola data akun, peran akses, dan kredensial 5 role dalam sistem.' ?>
        </p>
    </div>

    <div class="flex items-center gap-2 flex-wrap">
        <button onclick="document.getElementById('modalImportCsv').classList.remove('hidden')" 
                class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-emerald-400 transition cursor-pointer">
            <i class="fa-solid fa-file-csv"></i> Impor Siswa (CSV)
        </button>
        <button onclick="document.getElementById('modalCreate').classList.remove('hidden')" 
                class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition cursor-pointer">
            <i class="fa-solid fa-plus"></i> <?= $_SESSION['user_role'] === 'staf' ? 'Tambah Siswa / Ortu' : 'Tambah Pengguna' ?>
        </button>
    </div>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== ''): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/20 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <span><?= $message_type === 'success' ? '<i class="fa-solid fa-circle-check text-emerald-400"></i>' : '<i class="fa-solid fa-triangle-exclamation text-rose-400"></i>' ?></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<!-- Filter & Pencarian -->
<div class="mb-6 rounded-2xl border border-white/10 bg-white/5 p-4">
    <form method="GET" class="flex flex-col sm:flex-row gap-3">
        <div class="flex-1">
            <input 
                type="text" 
                name="search" 
                value="<?= htmlspecialchars($search) ?>" 
                placeholder="Cari berdasarkan nama, email, atau telepon..." 
                class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-2 text-sm text-white placeholder-slate-500 outline-none focus:border-blue-500"
            >
        </div>

        <div class="sm:w-48">
            <select name="role" class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-sm text-white outline-none focus:border-blue-500">
                <option value="">Semua Role (5)</option>
                <?php foreach (ROLES as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= $filter_role === $k ? 'selected' : '' ?>>
                        <?= $lbl ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="rounded-xl bg-slate-800 hover:bg-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 transition flex items-center gap-2 cursor-pointer">
                <i class="fa-solid fa-magnifying-glass text-xs"></i> Filter
            </button>
            <?php if ($search !== '' || $filter_role !== ''): ?>
                <a href="users.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-sm font-semibold text-slate-400 transition">
                    Reset
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Tabel Pengguna -->
<div class="overflow-hidden rounded-3xl border border-white/10 bg-white/5 shadow-2xl">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-6 py-4 font-semibold">Pengguna</th>
                    <th class="px-6 py-4 font-semibold">Peran (Role)</th>
                    <th class="px-6 py-4 font-semibold">Kontak</th>
                    <th class="px-6 py-4 font-semibold">Terdaftar</th>
                    <th class="px-6 py-4 font-semibold text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php if (empty($users_list)): ?>
                    <tr>
                        <td colspan="5" class="py-12 text-center text-slate-400 text-sm">
                            Tidak ada data pengguna yang sesuai dengan filter pencarian.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users_list as $u): ?>
                        <tr class="hover:bg-white/[0.02] transition">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-800 text-base font-bold text-slate-200">
                                        <?= strtoupper(mb_substr($u['name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="font-semibold text-white flex items-center gap-2">
                                            <?= htmlspecialchars($u['name']) ?>
                                            <?php if ($u['id'] === (int)$_SESSION['user_id']): ?>
                                                <span class="rounded bg-blue-500/20 px-1.5 py-0.5 text-[10px] text-blue-300 font-normal">Anda</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-xs text-slate-400"><?= htmlspecialchars($u['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-lg border px-2.5 py-1 text-xs font-semibold tracking-wide <?= getRoleBadge($u['role']) ?>">
                                    <?= htmlspecialchars(getRoleLabel($u['role'])) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-300">
                                <div><?= htmlspecialchars($u['phone'] ?: '-') ?></div>
                                <?php if (!empty($u['address'])): ?>
                                    <div class="text-[11px] text-slate-500 truncate max-w-xs"><?= htmlspecialchars($u['address']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-xs text-slate-400">
                                <?= date('d M Y, H:i', strtotime($u['created_at'])) ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <?php 
                                $can_manage_row = ($_SESSION['user_role'] === 'administrator') || 
                                    ($_SESSION['user_role'] === 'staf' && in_array($u['role'], ['siswa', 'orang_tua'], true));
                                ?>
                                <?php if ($can_manage_row): ?>
                                    <div class="inline-flex items-center gap-2">
                                        <?php if ($u['role'] === 'siswa'): ?>
                                            <a href="student_profile_print.php?id=<?= $u['id'] ?>" target="_blank" 
                                               class="rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1.5 text-xs font-semibold text-emerald-400 hover:bg-emerald-500/20 transition inline-flex items-center gap-1.5" title="Cetak Lembar Buku Induk Siswa">
                                                <i class="fa-solid fa-address-book text-xs"></i> Buku Induk
                                            </a>
                                        <?php endif; ?>
                                        <a href="users.php?action=edit&id=<?= $u['id'] ?>" 
                                           class="rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-xs font-semibold text-slate-200 hover:bg-white/10 transition inline-flex items-center gap-1.5">
                                            <i class="fa-solid fa-pen-to-square text-xs"></i> Edit
                                        </a>
                                        <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Yakin ingin menghapus pengguna <?= addslashes(htmlspecialchars($u['name'])) ?>? Data yang dihapus tidak dapat dipulihkan!');">
                                                <input type="hidden" name="form_type" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <?= csrfField() ?>
                                                <button type="submit" class="rounded-lg border border-rose-500/20 bg-rose-500/10 px-2.5 py-1.5 text-xs font-semibold text-rose-400 hover:bg-rose-500/20 transition inline-flex items-center gap-1.5 cursor-pointer">
                                                    <i class="fa-solid fa-trash text-xs"></i> Hapus
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-white/5 bg-white/5 px-2.5 py-1 text-xs font-medium text-slate-500" title="Akun dilindungi hanya dapat dikelola Administrator">
                                        <i class="fa-solid fa-lock text-[10px]"></i> Dilindungi
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL: TAMBAH PENGGUNA BARU -->
<!-- ========================================================= -->
<div id="modalCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-user-plus text-blue-400"></i> <?= $_SESSION['user_role'] === 'staf' ? 'Tambah Siswa / Orang Tua' : 'Tambah Pengguna Baru' ?>
            </h3>
            <button onclick="document.getElementById('modalCreate').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="form_type" value="create_user">
            <?= csrfField() ?>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Nama Lengkap *</label>
                <input type="text" name="name" required placeholder="Contoh: Budi Santoso" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Email *</label>
                <input type="email" name="email" required placeholder="nama@sekolah.id" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Peran (Role) *</label>
                    <select name="role" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <?php foreach ($manageable_roles as $k => $lbl): ?>
                            <option value="<?= $k ?>"><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Password Awal *</label>
                    <input type="password" name="password" required placeholder="Min 6 karakter" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Nomor Telepon (Opsional)</label>
                <input type="text" name="phone" placeholder="081234567890" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Alamat / Keterangan (Opsional)</label>
                <textarea name="address" rows="2" placeholder="Alamat tinggal atau domisili..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalCreate').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition cursor-pointer">
                    Simpan Pengguna
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL: EDIT PENGGUNA -->
<!-- ========================================================= -->
<?php if ($editing_user): ?>
<div id="modalEdit" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-user-pen text-blue-400"></i> Edit Pengguna: <?= htmlspecialchars($editing_user['name']) ?>
            </h3>
            <a href="users.php" class="text-slate-400 hover:text-white text-lg"><i class="fa-solid fa-xmark"></i></a>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="form_type" value="edit_user">
            <input type="hidden" name="user_id" value="<?= $editing_user['id'] ?>">
            <?= csrfField() ?>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Nama Lengkap *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editing_user['name']) ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Email *</label>
                <input type="email" name="email" required value="<?= htmlspecialchars($editing_user['email']) ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Peran (Role) *</label>
                    <select name="role" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <?php foreach ($manageable_roles as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= $editing_user['role'] === $k ? 'selected' : '' ?>>
                                <?= $lbl ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Ganti Password (Opsional)</label>
                    <input type="password" name="new_password" placeholder="Kosongkan jika tetap" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Nomor Telepon</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($editing_user['phone'] ?? '') ?>" placeholder="081234567890" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Alamat / Keterangan</label>
                <textarea name="address" rows="2" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"><?= htmlspecialchars($editing_user['address'] ?? '') ?></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <a href="users.php" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10">
                    Batal
                </a>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ========================================================= -->
<!-- MODAL: IMPOR SISWA MASSAL (CSV) -->
<!-- ========================================================= -->
<div id="modalImportCsv" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-file-csv text-emerald-400"></i> Impor Siswa Massal (CSV)
            </h3>
            <button onclick="document.getElementById('modalImportCsv').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="mb-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 p-4 text-xs text-emerald-300">
            <p class="font-bold flex items-center gap-1.5 mb-1 text-sm text-emerald-200">
                <i class="fa-solid fa-circle-info"></i> Petunjuk Format Berkas CSV:
            </p>
            <p class="mb-2 text-slate-300">File CSV harus memiliki kolom urutan: <strong>Nama Lengkap, Email, NISN, Nama Kelas, No HP, Alamat, Password</strong>.</p>
            <a href="users.php?download_template=csv_siswa" class="inline-flex items-center gap-1.5 font-bold underline text-emerald-400 hover:text-emerald-300">
                <i class="fa-solid fa-download"></i> Unduh File Template CSV (.csv) Contoh
            </a>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="form_type" value="import_csv_students">

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Pilih Berkas CSV *</label>
                <input type="file" name="csv_file" required accept=".csv,.txt" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-emerald-600 file:text-white hover:file:bg-emerald-500">
                <p class="text-[11px] text-slate-500 mt-1">Gunakan pemisah koma (comma-separated values). Password default: Siswa2026! jika kolom password kosong.</p>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalImportCsv').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-emerald-500/25 cursor-pointer flex items-center gap-2">
                    <i class="fa-solid fa-upload"></i> Mulai Impor Data
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
