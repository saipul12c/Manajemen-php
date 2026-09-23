<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$can_manage = in_array($user_role, ['staf', 'administrator'], true);

$message = "";
$message_type = "";

// Buat folder upload bukti jika belum ada
$upload_dir = __DIR__ . "/../../uploads/payments";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// -------------------------------------------------------------
// 1. RESOLUSI SISWA TERKAIT (UNTUK SISWA & ORANG TUA)
// -------------------------------------------------------------
$target_student_id = null;
if ($user_role === 'siswa') {
    $target_student_id = $user_id;
} elseif ($user_role === 'orang_tua') {
    $stmt_child = $pdo->prepare("
        SELECT u.id, u.name, u.nisn, c.name as class_name 
        FROM parent_students ps
        JOIN users u ON ps.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE ps.parent_id = ? LIMIT 1
    ");
    $stmt_child->execute([$user_id]);
    $child_info = $stmt_child->fetch();
    if ($child_info) {
        $target_student_id = (int)$child_info['id'];
    }
}

// -------------------------------------------------------------
// 2. BUAT TAGIHAN BARU (Staf & Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_bill' && $can_manage) {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $title = trim($_POST['title'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days'));
        $month_period = trim($_POST['month_period'] ?? date('F Y'));
        $target_mode = $_POST['target_mode'] ?? 'single'; // 'single' atau 'class'
        $single_student_id = (int)($_POST['student_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);

        if (empty($title) || $amount <= 0) {
            $message = "Judul tagihan dan nominal biaya wajib valid.";
            $message_type = "error";
        } else {
            $students_to_bill = [];
            if ($target_mode === 'class' && $class_id > 0) {
                $stmt_cls = $pdo->prepare("SELECT id FROM users WHERE role = 'siswa' AND class_id = ?");
                $stmt_cls->execute([$class_id]);
                $students_to_bill = $stmt_cls->fetchAll(PDO::FETCH_COLUMN);
            } elseif ($single_student_id > 0) {
                $students_to_bill = [$single_student_id];
            }

            if (empty($students_to_bill)) {
                $message = "Tidak ada siswa yang dipilih untuk dibuatkan tagihan.";
                $message_type = "error";
            } else {
                $stmt_ins_bill = $pdo->prepare("
                    INSERT INTO student_bills (student_id, title, amount, due_date, month_period, status)
                    VALUES (?, ?, ?, ?, ?, 'belum_lunas')
                ");
                foreach ($students_to_bill as $sid) {
                    $stmt_ins_bill->execute([$sid, $title, $amount, $due_date, $month_period]);
                }
                logActivity($pdo, 'CREATE_BILLS', "Membuat tagihan $title senilai Rp $amount untuk " . count($students_to_bill) . " siswa");
                $message = "Tagihan berhasil diterbitkan untuk " . count($students_to_bill) . " siswa.";
                $message_type = "success";
            }
        }
    }
}

// -------------------------------------------------------------
// 3. KONFIRMASI PEMBAYARAN / UPLOAD BUKTI (Siswa & Orang Tua)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_payment') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $bill_id = (int)($_POST['bill_id'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'transfer_bank';
        if (!in_array($payment_method, ['transfer_bank', 'qris', 'tunai'], true)) {
            $payment_method = 'transfer_bank';
        }
        $notes = trim($_POST['notes'] ?? '');
        $proof_url = null;

        // Ambil info tagihan
        $stmt_b = $pdo->prepare("SELECT * FROM student_bills WHERE id = ?");
        $stmt_b->execute([$bill_id]);
        $bill = $stmt_b->fetch();

        if (!$bill) {
            $message = "Tagihan tidak ditemukan.";
            $message_type = "error";
        } else {
            // Upload bukti transfer jika ada
            if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
                $file_tmp = $_FILES['proof_file']['tmp_name'];
                $file_name = $_FILES['proof_file']['name'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];

                if (in_array($file_ext, $allowed_exts, true)) {
                    $new_file_name = 'pay_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
                    $dest_path = $upload_dir . '/' . $new_file_name;
                    if (move_uploaded_file($file_tmp, $dest_path)) {
                        $proof_url = 'uploads/payments/' . $new_file_name;
                    }
                }
            }

            // Simpan transaksi pembayaran
            $stmt_ins_pay = $pdo->prepare("
                INSERT INTO bill_payments (bill_id, student_id, amount_paid, payment_method, payment_date, proof_url, status, notes)
                VALUES (?, ?, ?, ?, CURRENT_DATE(), ?, 'menunggu', ?)
            ");
            $stmt_ins_pay->execute([$bill_id, $bill['student_id'], $bill['amount'], $payment_method, $proof_url, $notes]);

            // Update status tagihan jadi menunggu_verifikasi
            $pdo->prepare("UPDATE student_bills SET status = 'menunggu_verifikasi' WHERE id = ?")->execute([$bill_id]);

            logActivity($pdo, 'SUBMIT_PAYMENT', "Mengajukan pembayaran tagihan ID $bill_id metode $payment_method senilai " . $bill['amount']);
            if ($payment_method === 'tunai') {
                $message = "Konfirmasi pembayaran tunai berhasil dikirim. Silakan serahkan setoran di loket Tata Usaha sekolah (jam kerja 07.30 - 15.00 WIB) untuk verifikasi langsung.";
            } else {
                $message = "Konfirmasi pembayaran berhasil dikirim. Staf Tata Usaha akan segera memverifikasi mutasi rekening Anda.";
            }
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 4. VERIFIKASI PEMBAYARAN (Staf & Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_payment' && $can_manage) {
    if (validateCsrfToken()) {
        $payment_id = (int)($_POST['payment_id'] ?? 0);
        $decision = $_POST['decision'] ?? 'terima'; // 'terima' atau 'tolak'
        $admin_notes = trim($_POST['admin_notes'] ?? '');

        $stmt_p = $pdo->prepare("SELECT * FROM bill_payments WHERE id = ?");
        $stmt_p->execute([$payment_id]);
        $payment = $stmt_p->fetch();

        if ($payment) {
            $new_status = ($decision === 'terima') ? 'diterima' : 'ditolak';
            $stmt_up_p = $pdo->prepare("
                UPDATE bill_payments 
                SET status = ?, verified_by = ?, verified_at = NOW(), notes = CONCAT(IFNULL(notes,''), ' | ', ?)
                WHERE id = ?
            ");
            $stmt_up_p->execute([$new_status, $user_id, $admin_notes ?: ($decision === 'terima' ? 'Terverifikasi' : 'Ditolak'), $payment_id]);

            // Update status tagihan induk
            $bill_status = ($decision === 'terima') ? 'lunas' : 'belum_lunas';
            $pdo->prepare("UPDATE student_bills SET status = ? WHERE id = ?")->execute([$bill_status, $payment['bill_id']]);

            logActivity($pdo, 'VERIFY_PAYMENT', "Verifikasi pembayaran ID $payment_id ($new_status)");
            $message = "Status pembayaran berhasil diperbarui menjadi: " . strtoupper($new_status);
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 5. PEMBAYARAN TUNAI LOKET (Staf & Admin)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_cash_pay' && $can_manage) {
    if (validateCsrfToken()) {
        $bill_id = (int)($_POST['bill_id'] ?? 0);
        $stmt_b = $pdo->prepare("SELECT * FROM student_bills WHERE id = ?");
        $stmt_b->execute([$bill_id]);
        $bill = $stmt_b->fetch();

        if ($bill) {
            $stmt_ins = $pdo->prepare("
                INSERT INTO bill_payments (bill_id, student_id, amount_paid, payment_method, payment_date, status, notes, verified_by, verified_at)
                VALUES (?, ?, ?, 'tunai', CURRENT_DATE(), 'diterima', 'Pembayaran tunai langsung di loket TU', ?, NOW())
            ");
            $stmt_ins->execute([$bill_id, $bill['student_id'], $bill['amount'], $user_id]);

            $pdo->prepare("UPDATE student_bills SET status = 'lunas' WHERE id = ?")->execute([$bill_id]);

            logActivity($pdo, 'CASH_PAYMENT', "Pembayaran tunai tagihan ID $bill_id senilai " . $bill['amount']);
            $message = "Pembayaran tunai tagihan berhasil dicatat dan status telah LUNAS.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 6. AMBIL DATA TAGIHAN & PEMBAYARAN
// -------------------------------------------------------------
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();
$all_students = $pdo->query("SELECT id, name, nisn, class_id FROM users WHERE role = 'siswa' ORDER BY name ASC")->fetchAll();

$filter_status = $_GET['status'] ?? '';
$filter_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;

$sql_bills = "
    SELECT b.*, u.name as student_name, u.nisn, c.name as class_name,
           (SELECT p.id FROM bill_payments p WHERE p.bill_id = b.id AND p.status = 'diterima' LIMIT 1) as verified_payment_id
    FROM student_bills b
    JOIN users u ON b.student_id = u.id
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE 1=1
";
$params_bills = [];

if (!$can_manage) {
    // Siswa & Orang Tua hanya melihat tagihan milik siswa yang bersangkutan
    $sql_bills .= " AND b.student_id = ?";
    $params_bills[] = $target_student_id ?: 0;
} else {
    if ($filter_status !== '') {
        $sql_bills .= " AND b.status = ?";
        $params_bills[] = $filter_status;
    }
    if ($filter_class !== null) {
        $sql_bills .= " AND u.class_id = ?";
        $params_bills[] = $filter_class;
    }
}

$sql_bills .= " ORDER BY FIELD(b.status, 'menunggu_verifikasi', 'belum_lunas', 'lunas'), b.due_date ASC";
$stmt_b_list = $pdo->prepare($sql_bills);
$stmt_b_list->execute($params_bills);
$bills = $stmt_b_list->fetchAll();

// Pembayaran pending menunggu verifikasi (khusus Admin & Staf)
$pending_verifications = [];
if ($can_manage) {
    $stmt_pv = $pdo->query("
        SELECT p.*, b.title as bill_title, u.name as student_name, u.nisn, c.name as class_name
        FROM bill_payments p
        JOIN student_bills b ON p.bill_id = b.id
        JOIN users u ON p.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE p.status = 'menunggu'
        ORDER BY p.id ASC
    ");
    $pending_verifications = $stmt_pv->fetchAll();
}

// Statistik Ringkas Keuangan
$stat_total_bills = 0;
$stat_paid_bills = 0;
$stat_unpaid_bills = 0;
foreach ($bills as $b) {
    if ($b['status'] === 'lunas') {
        $stat_paid_bills += (float)$b['amount'];
    } else {
        $stat_unpaid_bills += (float)$b['amount'];
    }
    $stat_total_bills += (float)$b['amount'];
}

$page_title = "Manajemen Keuangan & SPP";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Top Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2.5">
                <i class="fa-solid fa-credit-card text-blue-400"></i> Keuangan & Pembayaran SPP
            </h1>
            <p class="text-sm text-slate-400 mt-1">
                Layanan administrasi SPP bulanan, uang kegiatan, monitoring tagihan, dan cetak kuitansi resmi.
            </p>
        </div>

        <?php if ($can_manage): ?>
            <div class="flex items-center gap-2">
                <a href="financial_report.php" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-slate-300 transition hover:bg-white/10 cursor-pointer">
                    <i class="fa-solid fa-chart-simple"></i> Laporan Keuangan & PDF
                </a>
                <button onclick="document.getElementById('modalCreateBill').classList.remove('hidden')" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow-lg shadow-blue-500/25 transition hover:bg-blue-500 cursor-pointer self-start sm:self-auto">
                    <i class="fa-solid fa-plus"></i> Terbitkan Tagihan Baru
                </button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($message)): ?>
        <div class="rounded-xl border p-4 <?= $message_type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/20 bg-rose-500/10 text-rose-300' ?> text-sm">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Financial Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur">
            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Tagihan Terdata</span>
            <div class="text-xl sm:text-2xl font-bold text-white mt-1">
                <?= formatRupiah($stat_total_bills) ?>
            </div>
            <p class="text-xs text-slate-500 mt-1"><?= count($bills) ?> tagihan siswa</p>
        </div>

        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-950/20 p-4 backdrop-blur">
            <span class="text-xs font-semibold text-emerald-400 uppercase tracking-wider">Lunas Terbayar</span>
            <div class="text-xl sm:text-2xl font-bold text-emerald-300 mt-1">
                <?= formatRupiah($stat_paid_bills) ?>
            </div>
            <p class="text-xs text-emerald-500/70 mt-1">Dana telah terverifikasi bendahara</p>
        </div>

        <div class="rounded-2xl border border-amber-500/20 bg-amber-950/20 p-4 backdrop-blur">
            <span class="text-xs font-semibold text-amber-400 uppercase tracking-wider">Sisa Belum Lunas</span>
            <div class="text-xl sm:text-2xl font-bold text-amber-300 mt-1">
                <?= formatRupiah($stat_unpaid_bills) ?>
            </div>
            <p class="text-xs text-amber-500/70 mt-1">Menunggu pembayaran / verifikasi</p>
        </div>
    </div>

    <!-- Student & Parent Guidance Banner -->
    <?php if (!$can_manage): ?>
        <div class="rounded-2xl border border-blue-500/20 bg-gradient-to-r from-blue-950/40 via-slate-900/60 to-slate-900/40 p-4 backdrop-blur shadow-lg">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="space-y-1">
                    <h4 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-lightbulb text-amber-400"></i> Panduan Pembayaran Uang Sekolah & SPP
                    </h4>
                    <p class="text-xs text-slate-300">
                        Sistem mendukung pembayaran via <strong class="text-emerald-400"><i class="fa-solid fa-money-bill-wave mr-1"></i>Setor Tunai (Cash di Loket TU)</strong>, <strong class="text-blue-400"><i class="fa-solid fa-building-columns mr-1"></i>Transfer Bank</strong>, maupun <strong class="text-purple-400"><i class="fa-solid fa-qrcode mr-1"></i>QRIS Instan</strong>.
                    </p>
                </div>
                <div class="flex items-center gap-2 text-xs text-slate-300">
                    <span class="rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 px-2.5 py-1 font-medium inline-flex items-center gap-1.5"><i class="fa-solid fa-money-bill-wave"></i> Cash / Loket TU</span>
                    <span class="rounded-lg bg-blue-500/10 border border-blue-500/20 text-blue-400 px-2.5 py-1 font-medium inline-flex items-center gap-1.5"><i class="fa-solid fa-building-columns"></i> Transfer Bank</span>
                    <span class="rounded-lg bg-purple-500/10 border border-purple-500/20 text-purple-400 px-2.5 py-1 font-medium inline-flex items-center gap-1.5"><i class="fa-solid fa-qrcode"></i> QRIS</span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Pending Verification Section (Khusus Admin & Staf) -->
    <?php if ($can_manage && !empty($pending_verifications)): ?>
        <div class="rounded-2xl border border-amber-500/30 bg-amber-950/20 p-5 shadow-lg space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-bold text-amber-200 flex items-center gap-2">
                    <i class="fa-solid fa-clock text-amber-400"></i> Verifikasi Pembayaran Menunggu Konfirmasi (<?= count($pending_verifications) ?>)
                </h3>
                <span class="text-xs text-amber-400 font-medium">Periksa bukti transfer dan cocokkan mutasi rekening</span>
            </div>

            <div class="overflow-x-auto rounded-xl border border-white/10 bg-slate-950">
                <table class="w-full text-left text-sm text-slate-300">
                    <thead class="bg-white/5 text-xs uppercase font-semibold text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Siswa / Kelas</th>
                            <th class="px-4 py-3">Tagihan</th>
                            <th class="px-4 py-3">Nominal</th>
                            <th class="px-4 py-3">Metode</th>
                            <th class="px-4 py-3">Bukti Transfer</th>
                            <th class="px-4 py-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($pending_verifications as $pv): ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-bold text-white"><?= htmlspecialchars($pv['student_name']) ?></div>
                                    <div class="text-xs text-slate-400">NISN: <?= htmlspecialchars($pv['nisn']) ?> (<?= htmlspecialchars($pv['class_name']) ?>)</div>
                                </td>
                                <td class="px-4 py-3 font-medium text-slate-200"><?= htmlspecialchars($pv['bill_title']) ?></td>
                                <td class="px-4 py-3 font-bold text-emerald-400"><?= formatRupiah($pv['amount_paid']) ?></td>
                                <td class="px-4 py-3">
                                    <?php if ($pv['payment_method'] === 'tunai'): ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-md bg-emerald-500/10 px-2 py-0.5 text-xs font-semibold text-emerald-400 border border-emerald-500/20">
                                            <i class="fa-solid fa-money-bill-wave"></i> Tunai (Kasir TU)
                                        </span>
                                    <?php elseif ($pv['payment_method'] === 'qris'): ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-md bg-purple-500/10 px-2 py-0.5 text-xs font-semibold text-purple-400 border border-purple-500/20">
                                            <i class="fa-solid fa-qrcode"></i> QRIS
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 rounded-md bg-blue-500/10 px-2 py-0.5 text-xs font-semibold text-blue-400 border border-blue-500/20">
                                            <i class="fa-solid fa-building-columns"></i> Transfer Bank
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if (!empty($pv['proof_url'])): ?>
                                        <a href="../../<?= htmlspecialchars($pv['proof_url']) ?>" target="_blank" class="inline-flex items-center gap-1.5 text-xs text-blue-400 underline hover:text-blue-300">
                                            <i class="fa-regular fa-image"></i> Lihat Bukti
                                        </a>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-500"><?= $pv['payment_method'] === 'tunai' ? 'Setoran Fisik di TU' : 'Tanpa Bukti File' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <form method="POST" class="inline-flex items-center gap-2">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="verify_payment">
                                        <input type="hidden" name="payment_id" value="<?= $pv['id'] ?>">
                                        <button type="submit" name="decision" value="terima" class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-500 transition cursor-pointer inline-flex items-center gap-1">
                                            <i class="fa-solid fa-check"></i> Setujui
                                        </button>
                                        <button type="submit" name="decision" value="tolak" class="rounded-lg bg-rose-600/80 px-2.5 py-1 text-xs font-semibold text-white hover:bg-rose-500 transition cursor-pointer inline-flex items-center gap-1" onclick="return confirm('Tolak konfirmasi pembayaran ini?');">
                                            <i class="fa-solid fa-xmark"></i> Tolak
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filter Bar for Staff / Admin -->
    <?php if ($can_manage): ?>
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur">
            <form method="GET" class="flex flex-wrap items-center gap-3">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Filter Status:</span>
                <select name="status" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                    <option value="">Semua Status</option>
                    <option value="belum_lunas" <?= $filter_status === 'belum_lunas' ? 'selected' : '' ?>>Belum Lunas</option>
                    <option value="menunggu_verifikasi" <?= $filter_status === 'menunggu_verifikasi' ? 'selected' : '' ?>>Menunggu Verifikasi</option>
                    <option value="lunas" <?= $filter_status === 'lunas' ? 'selected' : '' ?>>Lunas</option>
                </select>

                <select name="class_id" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                    <option value="">Semua Kelas</option>
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?= $cl['id'] ?>" <?= ($filter_class !== null && $filter_class == $cl['id']) ? 'selected' : '' ?>>
                            Kelas <?= htmlspecialchars($cl['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-1.5 text-xs font-medium text-slate-200 hover:bg-slate-700 transition cursor-pointer">
                    Terapkan
                </button>
            </form>
        </div>
    <?php endif; ?>

    <!-- Bills Table -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/50 shadow-lg backdrop-blur overflow-hidden">
        <div class="border-b border-white/10 px-5 py-4 flex items-center justify-between">
            <h3 class="text-base font-bold text-white">Daftar Tagihan Biaya Pendidikan</h3>
            <span class="text-xs text-slate-400">Menampilkan <?= count($bills) ?> data</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-slate-950/60 text-xs uppercase font-semibold text-slate-400">
                    <tr>
                        <th class="px-5 py-3.5">Nama Tagihan</th>
                        <?php if ($can_manage): ?>
                            <th class="px-5 py-3.5">Siswa & Kelas</th>
                        <?php endif; ?>
                        <th class="px-5 py-3.5">Periode</th>
                        <th class="px-5 py-3.5">Nominal</th>
                        <th class="px-5 py-3.5">Jatuh Tempo</th>
                        <th class="px-5 py-3.5">Status</th>
                        <th class="px-5 py-3.5 text-right">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($bills)): ?>
                        <tr>
                            <td colspan="7" class="px-5 py-8 text-center text-slate-500">
                                Tidak ada data tagihan yang sesuai.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bills as $bill): ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="px-5 py-4">
                                    <div class="font-bold text-white"><?= htmlspecialchars($bill['title']) ?></div>
                                    <?php if (!empty($bill['notes'])): ?>
                                        <div class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars($bill['notes']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <?php if ($can_manage): ?>
                                    <td class="px-5 py-4">
                                        <div class="font-medium text-slate-200"><?= htmlspecialchars($bill['student_name']) ?></div>
                                        <div class="text-xs text-slate-500">NISN: <?= htmlspecialchars($bill['nisn']) ?> (<?= htmlspecialchars($bill['class_name']) ?>)</div>
                                    </td>
                                <?php endif; ?>

                                <td class="px-5 py-4 text-xs font-medium text-slate-400">
                                    <?= htmlspecialchars($bill['month_period'] ?: '-') ?>
                                </td>

                                <td class="px-5 py-4 font-bold text-white">
                                    <?= formatRupiah($bill['amount']) ?>
                                </td>

                                <td class="px-5 py-4 text-xs font-mono text-slate-400">
                                    <?= date('d M Y', strtotime($bill['due_date'])) ?>
                                </td>

                                <td class="px-5 py-4">
                                    <span class="rounded-lg border px-2.5 py-1 text-xs font-semibold <?= getBillStatusBadge($bill['status']) ?>">
                                        <?= getBillStatusLabel($bill['status']) ?>
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <?php if ($bill['status'] === 'lunas'): ?>
                                        <a href="receipt.php?bill_id=<?= $bill['id'] ?>" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-300 hover:bg-emerald-500/20 transition">
                                            <i class="fa-solid fa-print"></i> Cetak Kuitansi
                                        </a>
                                    <?php elseif ($can_manage): ?>
                                        <form method="POST" onsubmit="return confirm('Catat pembayaran tunai lunas di kasir TU?');" class="inline">
                                             <?= csrfField() ?>
                                             <input type="hidden" name="action" value="quick_cash_pay">
                                             <input type="hidden" name="bill_id" value="<?= $bill['id'] ?>">
                                             <button type="submit" class="rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500 transition cursor-pointer inline-flex items-center gap-1.5">
                                                 <i class="fa-solid fa-money-bill-wave"></i> Bayar Tunai
                                             </button>
                                        </form>
                                    <?php elseif (in_array($user_role, ['siswa', 'orang_tua'], true) && $bill['status'] === 'belum_lunas'): ?>
                                        <button onclick="openPaymentModal(<?= $bill['id'] ?>, '<?= htmlspecialchars($bill['title'], ENT_QUOTES) ?>', <?= $bill['amount'] ?>)" class="rounded-xl bg-blue-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-blue-500 transition cursor-pointer inline-flex items-center gap-1.5">
                                            <i class="fa-solid fa-credit-card"></i> Bayar Sekarang
                                        </button>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-500">Diproses TU</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal Konfirmasi Pembayaran (Siswa & Orang Tua) -->
<div id="modalPayBill" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm hidden">
    <div class="w-full max-w-md rounded-2xl border border-white/10 bg-slate-900 p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-credit-card text-blue-400"></i> Konfirmasi Pembayaran
            </h3>
            <button onclick="document.getElementById('modalPayBill').classList.add('hidden')" class="rounded-lg p-1 text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="submit_payment">
            <input type="hidden" name="bill_id" id="pay_bill_id" value="">

            <div>
                <label class="block text-xs font-semibold text-slate-400 mb-1">Tagihan</label>
                <div id="pay_bill_title" class="text-sm font-bold text-white"></div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-400 mb-1">Total Biaya</label>
                <div id="pay_bill_amount" class="text-xl font-extrabold text-emerald-400"></div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Metode Pembayaran</label>
                <select name="payment_method" id="pay_method_select" onchange="handlePaymentMethodChange(this.value)" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:outline-none">
                    <option value="transfer_bank">Transfer Bank (BCA / Mandiri / BNI)</option>
                    <option value="qris">QRIS Standar Pembayaran</option>
                    <option value="tunai">Setor Tunai / Cash (Loket Tata Usaha)</option>
                </select>
            </div>

            <!-- Dynamic Info Boxes based on payment method -->
            <div id="info_transfer_bank" class="rounded-xl border border-blue-500/20 bg-blue-950/30 p-3.5 text-xs text-blue-200 space-y-1.5">
                <div class="font-bold flex items-center gap-1.5 text-blue-300">
                    <i class="fa-solid fa-building-columns"></i> Rekening Resmi Sekolah:
                </div>
                <div class="space-y-0.5">
                    <div>• BCA: <strong class="text-white font-mono">123-456-7890</strong> (a.n Yayasan Pendidikan Bina Bangsa)</div>
                    <div>• Bank Mandiri: <strong class="text-white font-mono">123-00-9876543-2</strong> (a.n Bina Bangsa School)</div>
                </div>
                <div class="text-[11px] text-blue-400/80 pt-0.5">Transfer sesuai nominal, lalu foto/unggah bukti transfer Anda di bawah.</div>
            </div>

            <div id="info_qris" class="hidden rounded-xl border border-purple-500/20 bg-purple-950/30 p-3.5 text-xs text-purple-200 space-y-1.5">
                <div class="font-bold flex items-center gap-1.5 text-purple-300">
                    <i class="fa-solid fa-qrcode"></i> Pembayaran QRIS Instan:
                </div>
                <div>NMID: <strong class="text-white font-mono">ID1020030040506</strong> (Bina Bangsa Education Official)</div>
                <div class="text-[11px] text-purple-400/80">Dapat discan melalui BCA Mobile, Livin' Mandiri, GoPay, OVO, Dana, LinkAja, atau ShopeePay. Harap screenshot bukti transaksi sukses.</div>
            </div>

            <div id="info_tunai" class="hidden rounded-xl border border-emerald-500/20 bg-emerald-950/30 p-3.5 text-xs text-emerald-200 space-y-1.5">
                <div class="font-bold flex items-center gap-1.5 text-emerald-300">
                    <i class="fa-solid fa-money-bill-wave"></i> Petunjuk Pembayaran Tunai (Cash):
                </div>
                <div>Lokasi: <strong class="text-white">Loket Tata Usaha (Gedung Utama Lt. 1)</strong></div>
                <div>Jam Layanan: <strong class="text-white">Senin s.d. Jumat (07.30 - 15.00 WIB)</strong></div>
                <div class="text-[11px] text-emerald-400/80">Silakan serahkan uang tunai ke petugas kasir. Jika Anda sudah membayar dan memegang slip tanda terima fisik dari kasir, Anda dapat mengunggah fotonya di bawah untuk konfirmasi sistem (opsional).</div>
            </div>

            <div>
                <label id="proof_label" class="block text-xs font-semibold text-slate-300 mb-1">Unggah Struk / Bukti Transfer</label>
                <input type="file" name="proof_file" id="proof_file_input" accept=".jpg,.jpeg,.png,.pdf" class="w-full rounded-xl border border-white/10 bg-slate-950 px-2 py-1.5 text-xs text-slate-300 file:mr-2 file:rounded-lg file:border-0 file:bg-blue-600 file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-white cursor-pointer">
                <p id="proof_hint" class="text-[11px] text-slate-400 mt-1">Format file: JPG, JPEG, PNG, atau PDF (Maks. 5MB)</p>
            </div>

            <div>
                <label id="notes_label" class="block text-xs font-semibold text-slate-300 mb-1">Catatan Tambahan (Pengirim / Penyetor)</label>
                <input type="text" name="notes" id="notes_input" placeholder="Contoh: Transfer via BCA a.n Hendra Wijaya" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalPayBill').classList.add('hidden')" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/5 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 cursor-pointer">
                    Kirim Konfirmasi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Terbitkan Tagihan Baru (Admin & Staf) -->
<?php if ($can_manage): ?>
<div id="modalCreateBill" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm hidden">
    <div class="w-full max-w-lg rounded-2xl border border-white/10 bg-slate-900 p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-plus text-blue-400"></i> Terbitkan Tagihan Siswa
            </h3>
            <button onclick="document.getElementById('modalCreateBill').classList.add('hidden')" class="rounded-lg p-1 text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_bill">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Nama / Judul Tagihan</label>
                <input type="text" name="title" required placeholder="Contoh: SPP Bulan November 2026" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Nominal Biaya (Rp)</label>
                    <input type="number" name="amount" required min="1000" step="500" value="350000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Jatuh Tempo</label>
                    <input type="date" name="due_date" required value="<?= date('Y-m-d', strtotime('+14 days')) ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Periode Bulan / Keterangan</label>
                <input type="text" name="month_period" placeholder="Contoh: November 2026 atau Semester Ganjil" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Target Penerima Tagihan</label>
                <div class="flex items-center gap-4 py-1">
                    <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
                        <input type="radio" name="target_mode" value="class" checked onchange="toggleBillTarget('class')" class="text-blue-600">
                        Massal Satu Kelas
                    </label>
                    <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer">
                        <input type="radio" name="target_mode" value="single" onchange="toggleBillTarget('single')" class="text-blue-600">
                        Siswa Tertentu
                    </label>
                </div>
            </div>

            <div id="targetClassBox">
                <label class="block text-xs font-semibold text-slate-300 mb-1">Pilih Kelas</label>
                <select name="class_id" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>">Kelas <?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="targetStudentBox" class="hidden">
                <label class="block text-xs font-semibold text-slate-300 mb-1">Pilih Siswa</label>
                <select name="student_id" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none">
                    <?php foreach ($all_students as $st): ?>
                        <option value="<?= $st['id'] ?>">
                            <?= htmlspecialchars($st['name']) ?> (NISN: <?= htmlspecialchars($st['nisn']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalCreateBill').classList.add('hidden')" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/5 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 cursor-pointer">
                    Terbitkan Tagihan
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function handlePaymentMethodChange(method) {
    const infoBank = document.getElementById('info_transfer_bank');
    const infoQris = document.getElementById('info_qris');
    const infoTunai = document.getElementById('info_tunai');
    const proofLabel = document.getElementById('proof_label');
    const proofHint = document.getElementById('proof_hint');
    const notesLabel = document.getElementById('notes_label');
    const notesInput = document.getElementById('notes_input');

    if (infoBank) infoBank.classList.add('hidden');
    if (infoQris) infoQris.classList.add('hidden');
    if (infoTunai) infoTunai.classList.add('hidden');

    if (method === 'tunai') {
        if (infoTunai) infoTunai.classList.remove('hidden');
        if (proofLabel) proofLabel.innerText = 'Unggah Foto Slip / Tanda Terima Loket (Opsional)';
        if (proofHint) proofHint.innerText = 'Jika sudah membayar di loket TU dan menerima slip kuitansi fisik, Anda dapat mengunggah fotonya di sini.';
        if (notesLabel) notesLabel.innerText = 'Catatan Penyetor Tunai';
        if (notesInput) notesInput.placeholder = 'Contoh: Disetorkan langsung oleh Ibu Dewi (Wali Murid) di loket TU';
    } else if (method === 'qris') {
        if (infoQris) infoQris.classList.remove('hidden');
        if (proofLabel) proofLabel.innerText = 'Unggah Tangkapan Layar (Screenshot) Bukti QRIS';
        if (proofHint) proofHint.innerText = 'Screenshot layar bukti pembayaran sukses dari aplikasi GoPay/OVO/Dana/BCA.';
        if (notesLabel) notesLabel.innerText = 'Catatan Transaksi QRIS';
        if (notesInput) notesInput.placeholder = 'Contoh: Transaksi QRIS via GoPay / BCA atas nama Siswa';
    } else {
        if (infoBank) infoBank.classList.remove('hidden');
        if (proofLabel) proofLabel.innerText = 'Unggah Struk / Bukti Transfer';
        if (proofHint) proofHint.innerText = 'Format file: JPG, JPEG, PNG, atau PDF (Maks. 5MB)';
        if (notesLabel) notesLabel.innerText = 'Catatan Tambahan (Rekening / Nama Pengirim)';
        if (notesInput) notesInput.placeholder = 'Contoh: Transfer via BCA a.n Hendra Wijaya';
    }
}

function openPaymentModal(id, title, amount) {
    document.getElementById('pay_bill_id').value = id;
    document.getElementById('pay_bill_title').innerText = title;
    document.getElementById('pay_bill_amount').innerText = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount);
    
    const methodSelect = document.getElementById('pay_method_select');
    if (methodSelect) {
        methodSelect.value = 'transfer_bank';
        handlePaymentMethodChange('transfer_bank');
    }
    const fileInput = document.getElementById('proof_file_input');
    if (fileInput) fileInput.value = '';
    const notesInput = document.getElementById('notes_input');
    if (notesInput) notesInput.value = '';

    document.getElementById('modalPayBill').classList.remove('hidden');
}

function toggleBillTarget(mode) {
    if (mode === 'class') {
        document.getElementById('targetClassBox').classList.remove('hidden');
        document.getElementById('targetStudentBox').classList.add('hidden');
    } else {
        document.getElementById('targetClassBox').classList.add('hidden');
        document.getElementById('targetStudentBox').classList.remove('hidden');
    }
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
