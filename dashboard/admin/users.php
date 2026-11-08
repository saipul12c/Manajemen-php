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
                $status = $_POST['status'] ?? 'aktif';
                $status_reason = trim($_POST['status_reason'] ?? '');
                $valid_statuses = ['aktif', 'skorsing', 'dikeluarkan', 'mutasi', 'lulus'];
                if (!in_array($status, $valid_statuses, true)) {
                    $status = 'aktif';
                }

                $nip = trim($_POST['nip'] ?? ($target_user['nip'] ?? ''));
                $title_degree = trim($_POST['title_degree'] ?? ($target_user['title_degree'] ?? ''));
                $sig_update_sql = "";
                $sig_update_param = null;

                if (isset($_POST['reset_signature']) && $_POST['reset_signature'] === '1') {
                    $sig_update_sql = ", signature_image = 'uploads/signatures/default_teacher_ttd.svg'";
                } elseif (isset($_FILES['signature_file']) && $_FILES['signature_file']['error'] === UPLOAD_ERR_OK) {
                    $sigRes = saveSecureUpload(
                        $_FILES['signature_file'],
                        'signatures',
                        ['png', 'svg', 'webp', 'jpg', 'jpeg'],
                        [],
                        2 * 1024 * 1024,
                        "teacher_{$edit_id}"
                    );
                    if ($sigRes['success']) {
                        $sig_update_sql = ", signature_image = ?";
                        $sig_update_param = $sigRes['path'];
                    }
                }

                if ($new_password !== '') {
                    if (strlen($new_password) < 6) {
                        $message = "Password baru minimal 6 karakter.";
                        $message_type = "error";
                    } else {
                        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                        $sql = "UPDATE users 
                                SET name = ?, email = ?, role = ?, phone = ?, address = ?, password = ?, 
                                    status = ?, status_reason = ?, status_updated_at = NOW(), status_updated_by = ?,
                                    nip = ?, title_degree = ? {$sig_update_sql}
                                WHERE id = ?";
                        $params = [$name, $email, $role, $phone, $address, $hashed, $status, $status_reason ?: null, $_SESSION['user_id'], $nip ?: null, $title_degree ?: null];
                        if ($sig_update_param !== null) {
                            $params[] = $sig_update_param;
                        }
                        $params[] = $edit_id;
                        $stmt_up = $pdo->prepare($sql);
                        $stmt_up->execute($params);
                        logActivity($pdo, 'EDIT_USER', "Memperbarui akun dan password: $name ($role), status: $status");
                        $message = "Data pengguna, kredensial, dan password berhasil diperbarui.";
                        $message_type = "success";
                    }
                } else {
                    $sql = "UPDATE users 
                            SET name = ?, email = ?, role = ?, phone = ?, address = ?, 
                                status = ?, status_reason = ?, status_updated_at = NOW(), status_updated_by = ?,
                                nip = ?, title_degree = ? {$sig_update_sql}
                            WHERE id = ?";
                    $params = [$name, $email, $role, $phone, $address, $status, $status_reason ?: null, $_SESSION['user_id'], $nip ?: null, $title_degree ?: null];
                    if ($sig_update_param !== null) {
                        $params[] = $sig_update_param;
                    }
                    $params[] = $edit_id;
                    $stmt_up = $pdo->prepare($sql);
                    $stmt_up->execute($params);
                    logActivity($pdo, 'EDIT_USER', "Memperbarui akun: $name ($role), status: $status");
                    $message = "Data pengguna dan kredensial berhasil diperbarui.";
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
// 5. QUERY DAFTAR PENGGUNA (Search, Filter Role & Status)
// -------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$filter_role = trim($_GET['role'] ?? '');
$filter_status = trim($_GET['status'] ?? '');

$sql = "SELECT id, name, email, role, phone, address, created_at, status, status_reason, status_updated_at FROM users WHERE 1=1";
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

if ($filter_status !== '' && in_array($filter_status, ['aktif', 'skorsing', 'dikeluarkan', 'mutasi', 'lulus'], true)) {
    $sql .= " AND status = ?";
    $params[] = $filter_status;
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

        <div class="sm:w-44">
            <select name="role" class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-sm text-white outline-none focus:border-blue-500">
                <option value="">Semua Role (5)</option>
                <?php foreach (ROLES as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= $filter_role === $k ? 'selected' : '' ?>>
                        <?= $lbl ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sm:w-44">
            <select name="status" class="w-full rounded-xl border border-white/10 bg-slate-900 px-3 py-2 text-sm text-white outline-none focus:border-blue-500">
                <option value="">Semua Status</option>
                <option value="aktif" <?= $filter_status === 'aktif' ? 'selected' : '' ?>>🟢 Aktif</option>
                <option value="skorsing" <?= $filter_status === 'skorsing' ? 'selected' : '' ?>>🟠 Skorsing</option>
                <option value="dikeluarkan" <?= $filter_status === 'dikeluarkan' ? 'selected' : '' ?>>🔴 Dikeluarkan (DO)</option>
                <option value="mutasi" <?= $filter_status === 'mutasi' ? 'selected' : '' ?>>🔵 Mutasi</option>
                <option value="lulus" <?= $filter_status === 'lulus' ? 'selected' : '' ?>>🟣 Lulus</option>
            </select>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="rounded-xl bg-slate-800 hover:bg-slate-700 px-4 py-2 text-sm font-semibold text-slate-200 transition flex items-center gap-2 cursor-pointer">
                <i class="fa-solid fa-magnifying-glass text-xs"></i> Filter
            </button>
            <?php if ($search !== '' || $filter_role !== '' || $filter_status !== ''): ?>
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
                    <th class="px-6 py-4 font-semibold">Status</th>
                    <th class="px-6 py-4 font-semibold">Kontak</th>
                    <th class="px-6 py-4 font-semibold">Terdaftar</th>
                    <th class="px-6 py-4 font-semibold text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php if (empty($users_list)): ?>
                    <tr>
                        <td colspan="6" class="py-12 text-center text-slate-400 text-sm">
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
                            <td class="px-6 py-4">
                                <div>
                                    <?= getUserStatusBadge($u['status'] ?? 'aktif') ?>
                                    <?php if (!empty($u['status_reason'])): ?>
                                        <div class="text-[11px] text-slate-400 mt-1 max-w-[190px] truncate" title="<?= htmlspecialchars($u['status_reason']) ?>">
                                            <i class="fa-solid fa-circle-info text-[9px] mr-1 text-slate-500"></i><?= htmlspecialchars($u['status_reason']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
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
                                    <div class="inline-flex items-center gap-1.5 flex-wrap justify-end">
                                        <?php if ($u['role'] === 'siswa'): ?>
                                            <a href="student_360.php?id=<?= $u['id'] ?>" 
                                               class="rounded-lg border border-indigo-500/30 bg-indigo-500/10 px-2 py-1.5 text-xs font-semibold text-indigo-300 hover:bg-indigo-500/20 transition inline-flex items-center gap-1" title="Profil Terpadu Siswa 360° (Akademik, Presensi, BK, SPP, Perpus)">
                                                <i class="fa-solid fa-id-card-clip text-xs text-indigo-400"></i> 360°
                                            </a>
                                            <a href="student_profile_print.php?id=<?= $u['id'] ?>" target="_blank" 
                                               class="rounded-lg border border-emerald-500/20 bg-emerald-500/10 px-2 py-1.5 text-xs font-semibold text-emerald-400 hover:bg-emerald-500/20 transition inline-flex items-center gap-1" title="Cetak Lembar Buku Induk Siswa">
                                                <i class="fa-solid fa-address-book text-xs"></i> Buku Induk
                                            </a>
                                            <?php if (($u['status'] ?? '') === 'dikeluarkan'): ?>
                                                <a href="../bk/counseling_letter.php?student_id=<?= $u['id'] ?>&type=do" target="_blank" 
                                                   class="rounded-lg border border-rose-500/30 bg-rose-500/15 px-2 py-1.5 text-xs font-bold text-rose-300 hover:bg-rose-500/25 transition inline-flex items-center gap-1" title="Cetak SK Pengembalian Peserta Didik (DO)">
                                                    <i class="fa-solid fa-ban text-xs"></i> SK DO
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <a href="users.php?action=edit&id=<?= $u['id'] ?>" 
                                           class="rounded-lg border border-white/10 bg-white/5 px-2.5 py-1.5 text-xs font-semibold text-slate-200 hover:bg-white/10 transition inline-flex items-center gap-1">
                                            <i class="fa-solid fa-pen-to-square text-xs"></i> Edit
                                        </a>
                                        <?php if ($u['id'] !== (int)$_SESSION['user_id']): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Yakin ingin menghapus pengguna <?= addslashes(htmlspecialchars($u['name'])) ?>? Data yang dihapus tidak dapat dipulihkan!');">
                                                <input type="hidden" name="form_type" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <?= csrfField() ?>
                                                <button type="submit" class="rounded-lg border border-rose-500/20 bg-rose-500/10 px-2 py-1.5 text-xs font-semibold text-rose-400 hover:bg-rose-500/20 transition inline-flex items-center gap-1 cursor-pointer">
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

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
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

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
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

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Status Akun / Kesiswaan *</label>
                    <select name="status" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                        <option value="aktif" <?= ($editing_user['status'] ?? 'aktif') === 'aktif' ? 'selected' : '' ?>>🟢 Aktif</option>
                        <option value="skorsing" <?= ($editing_user['status'] ?? '') === 'skorsing' ? 'selected' : '' ?>>🟠 Skorsing (Sanksi Disiplin)</option>
                        <option value="dikeluarkan" <?= ($editing_user['status'] ?? '') === 'dikeluarkan' ? 'selected' : '' ?>>🔴 Dikeluarkan / DO (Drop Out)</option>
                        <option value="mutasi" <?= ($editing_user['status'] ?? '') === 'mutasi' ? 'selected' : '' ?>>🔵 Mutasi (Pindah Sekolah)</option>
                        <option value="lulus" <?= ($editing_user['status'] ?? '') === 'lulus' ? 'selected' : '' ?>>🟣 Lulus (Alumni)</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Nomor Telepon</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($editing_user['phone'] ?? '') ?>" placeholder="081234567890" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Alasan Status / No. SK / Catatan Sanksi</label>
                <textarea name="status_reason" rows="2" placeholder="Contoh: SK Pengembalian No. 421.3/SK-DO.105/2026 tgl 27/09/2026 karena akumulasi pelanggaran berat..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-xs text-white outline-none focus:border-blue-500"><?= htmlspecialchars($editing_user['status_reason'] ?? '') ?></textarea>
                <p class="text-[11px] text-slate-500 mt-1 flex items-start gap-1.5">
                    <i class="fa-solid fa-circle-info text-amber-400 mt-0.5 shrink-0"></i>
                    <span>Pengguna dengan status <strong>Dikeluarkan</strong> atau <strong>Skorsing</strong> tidak dapat login ke portal, namun seluruh riwayat nilai, rapor, dan arsip BK tetap tersimpan aman.</span>
                </p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-300">Alamat Tempat Tinggal</label>
                <textarea name="address" rows="2" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"><?= htmlspecialchars($editing_user['address'] ?? '') ?></textarea>
            </div>

            <?php if ($editing_user['role'] === 'guru' || $editing_user['role'] === 'staf'): ?>
            <div class="rounded-2xl border border-blue-500/30 bg-blue-500/5 p-4 space-y-3">
                <h4 class="text-xs font-bold text-blue-400 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-signature"></i> Identitas Akademik & Tanda Tangan Digital (TTD)
                </h4>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-[11px] font-medium text-slate-300">NIP / NUPTK</label>
                        <input type="text" name="nip" value="<?= htmlspecialchars($editing_user['nip'] ?? '') ?>" placeholder="19820415 200801 2 015" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white outline-none focus:border-blue-500 font-mono">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium text-slate-300">Gelar Akademik</label>
                        <input type="text" name="title_degree" value="<?= htmlspecialchars($editing_user['title_degree'] ?? '') ?>" placeholder="Contoh: S.Pd, M.Pd" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white outline-none focus:border-blue-500">
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-[11px] font-medium text-slate-300">Tanda Tangan Digital Saat Ini:</label>
                    <div class="flex items-center gap-3 bg-slate-950 p-2.5 rounded-xl border border-white/10">
                        <div class="h-12 w-28 bg-white/5 border border-white/10 rounded-lg p-1 flex items-center justify-center shrink-0">
                            <?php 
                                $user_ttd = !empty($editing_user['signature_image']) ? $editing_user['signature_image'] : 'uploads/signatures/default_teacher_ttd.svg';
                            ?>
                            <img src="<?= htmlspecialchars(resolveAssetUrl($user_ttd, '../../')) ?>" alt="TTD" class="max-h-full max-w-full object-contain">
                        </div>
                        <div class="flex-1 text-[11px] text-slate-400">
                            <label class="block text-[10px] text-slate-500 uppercase font-semibold mb-0.5">Ganti Berkas TTD (PNG/SVG Transparan):</label>
                            <input type="file" name="signature_file" accept=".png,.svg,.webp,.jpg,.jpeg" class="w-full text-[11px] text-slate-300 file:mr-2 file:py-1 file:px-2 file:rounded-lg file:border-0 file:text-[10px] file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500 cursor-pointer">
                        </div>
                    </div>
                    <label class="inline-flex items-center gap-2 mt-2 text-[11px] text-slate-400 cursor-pointer">
                        <input type="checkbox" name="reset_signature" value="1" class="rounded border-white/20 bg-slate-950 text-blue-600 focus:ring-0">
                        <span>Reset tanda tangan ke grafis vektor SVG bawaan</span>
                    </label>
                </div>
            </div>
            <?php endif; ?>

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
