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
    mkdir($upload_dir, 0755, true);
}

// -------------------------------------------------------------
// 1. RESOLUSI SISWA TERKAIT (UNTUK SISWA & ORANG TUA)
// -------------------------------------------------------------
$target_student_id = null;
$parent_children = [];
$child_info = null;

if ($user_role === 'siswa') {
    $target_student_id = $user_id;
} elseif ($user_role === 'orang_tua') {
    $stmt_child = $pdo->prepare("
        SELECT u.id, u.name, u.nisn, c.name as class_name 
        FROM parent_students ps
        JOIN users u ON ps.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE ps.parent_id = ?
        ORDER BY u.name ASC
    ");
    $stmt_child->execute([$user_id]);
    $parent_children = $stmt_child->fetchAll();
    $p_kids_ids = array_map('intval', array_column($parent_children, 'id'));

    $req_kid = isset($_GET['student_id']) && is_numeric($_GET['student_id']) ? (int)$_GET['student_id'] : (isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0);
    if ($req_kid > 0 && in_array($req_kid, $p_kids_ids, true)) {
        $target_student_id = $req_kid;
    } else {
        $target_student_id = !empty($p_kids_ids) ? (int)$p_kids_ids[0] : 0;
    }

    foreach ($parent_children as $pk) {
        if ((int)$pk['id'] === $target_student_id) {
            $child_info = $pk;
            break;
        }
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

        // Gunakan transaction + FOR UPDATE untuk cegah race condition duplikasi pembayaran
        $pdo->beginTransaction();
        try {
            // Ambil info tagihan dengan row lock
            $stmt_b = $pdo->prepare("SELECT * FROM student_bills WHERE id = ? FOR UPDATE");
            $stmt_b->execute([$bill_id]);
            $bill = $stmt_b->fetch();

            if (!$bill) {
                $pdo->rollBack();
                $message = "Tagihan tidak ditemukan.";
                $message_type = "error";
            // -1 fix: Cek kepemilikan tagihan — siswa hanya tagihan sendiri, orang tua untuk anak-anaknya yang terdaftar
            } elseif (!$can_manage && ($user_role === 'orang_tua' ? !in_array((int)$bill['student_id'], $p_kids_ids, true) : (int)$bill['student_id'] !== $target_student_id)) {
                $pdo->rollBack();
                $message = "Anda tidak memiliki akses ke tagihan ini.";
                $message_type = "error";
            // -6 fix: Cegah pembayaran ganda jika status sudah bukan belum_lunas
            } elseif ($bill['status'] !== 'belum_lunas') {
                $pdo->rollBack();
                $message = "Tagihan ini sudah dalam proses pembayaran atau sudah lunas.";
                $message_type = "error";
            } else {
                // -7 & -8 fix: Validasi ukuran file (maks 5MB) dan MIME type pada upload bukti pembayaran
                $upload_error = false;
                if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
                    $uploadRes = saveSecureUpload(
                        $_FILES['proof_file'],
                        'payments',
                        ['jpg', 'jpeg', 'png', 'pdf'],
                        [],
                        5 * 1024 * 1024,
                        'pay'
                    );
                    if ($uploadRes['success']) {
                        $proof_url = $uploadRes['path'];
                    } else {
                        $pdo->rollBack();
                        $message = $uploadRes['error'];
                        $message_type = "error";
                        $upload_error = true;
                    }
                }

                if (!$upload_error) {
                    // Simpan transaksi pembayaran
                    $stmt_ins_pay = $pdo->prepare("
                        INSERT INTO bill_payments (bill_id, student_id, amount_paid, payment_method, payment_date, proof_url, status, notes)
                        VALUES (?, ?, ?, ?, CURRENT_DATE(), ?, 'menunggu', ?)
                    ");
                    $stmt_ins_pay->execute([$bill_id, $bill['student_id'], $bill['amount'], $payment_method, $proof_url, $notes]);

                    // Update status tagihan jadi menunggu_verifikasi
                    $pdo->prepare("UPDATE student_bills SET status = 'menunggu_verifikasi' WHERE id = ?")->execute([$bill_id]);

                    $pdo->commit();

                    logActivity($pdo, 'SUBMIT_PAYMENT', "Mengajukan pembayaran tagihan ID $bill_id metode $payment_method senilai " . $bill['amount']);
                    if ($payment_method === 'tunai') {
                        $message = "Konfirmasi pembayaran tunai berhasil dikirim. Silakan serahkan setoran di loket Tata Usaha sekolah (jam kerja 07.30 - 15.00 WIB) untuk verifikasi langsung.";
                    } else {
                        $message = "Konfirmasi pembayaran berhasil dikirim. Staf Tata Usaha akan segera memverifikasi mutasi rekening Anda.";
                    }
                    $message_type = "success";
                }
            }
        } catch (Exception $e_pay) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "Gagal memproses pembayaran. Silakan coba lagi.";
            $message_type = "error";
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

            // Notifikasi WhatsApp ke Orang Tua jika Pembayaran Diterima
            if ($decision === 'terima') {
                try {
                    $stmt_parent = $pdo->prepare("
                        SELECT u_p.name as parent_name, u_p.phone as parent_phone, u_s.name as student_name, b.title as bill_title
                        FROM student_bills b
                        JOIN users u_s ON b.student_id = u_s.id
                        LEFT JOIN parent_students ps ON u_s.id = ps.student_id
                        LEFT JOIN users u_p ON ps.parent_id = u_p.id
                        WHERE b.id = ? LIMIT 1
                    ");
                    $stmt_parent->execute([$payment['bill_id']]);
                    $p_pay = $stmt_parent->fetch();
                    if ($p_pay && !empty($p_pay['parent_phone'])) {
                        $formatted_amount = "Rp " . number_format((float)$payment['amount_paid'], 0, ',', '.');
                        $wa_pay_msg = "Halo Bpk/Ibu *{$p_pay['parent_name']}*,\n\n"
                                    . "Pembayaran tagihan *{$p_pay['bill_title']}* untuk ananda *{$p_pay['student_name']}* sebesar *{$formatted_amount}* telah berhasil *DIVERIFIKASI & LUNAS* oleh Bagian Keuangan.\n\n"
                                    . "Kuitansi digital resmi ber-QR Code validasi keaslian dapat diunduh pada portal sekolah. Terima kasih.";
                        sendWhatsAppNotification($pdo, $p_pay['parent_phone'], $p_pay['parent_name'], $wa_pay_msg, 'keuangan');
                    }
                } catch (Exception $e_wa_p) {}
            }

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

        // -6 fix: Gunakan transaction + FOR UPDATE untuk cegah race condition
        $pdo->beginTransaction();
        try {
            $stmt_b = $pdo->prepare("SELECT * FROM student_bills WHERE id = ? FOR UPDATE");
            $stmt_b->execute([$bill_id]);
            $bill = $stmt_b->fetch();

            if (!$bill) {
                $pdo->rollBack();
                $message = "Tagihan tidak ditemukan.";
                $message_type = "error";
            } elseif ($bill['status'] === 'lunas') {
                $pdo->rollBack();
                $message = "Tagihan ini sudah berstatus LUNAS.";
                $message_type = "error";
            } else {
                $stmt_ins = $pdo->prepare("
                    INSERT INTO bill_payments (bill_id, student_id, amount_paid, payment_method, payment_date, status, notes, verified_by, verified_at)
                    VALUES (?, ?, ?, 'tunai', CURRENT_DATE(), 'diterima', 'Pembayaran tunai langsung di loket TU', ?, NOW())
                ");
                $stmt_ins->execute([$bill_id, $bill['student_id'], $bill['amount'], $user_id]);

                $pdo->prepare("UPDATE student_bills SET status = 'lunas' WHERE id = ?")->execute([$bill_id]);

                $pdo->commit();

                logActivity($pdo, 'CASH_PAYMENT', "Pembayaran tunai tagihan ID $bill_id senilai " . $bill['amount']);

                // Notifikasi WhatsApp ke Orang Tua untuk Pembayaran Tunai Loket
                try {
                    $stmt_parent = $pdo->prepare("
                        SELECT u_p.name as parent_name, u_p.phone as parent_phone, u_s.name as student_name, b.title as bill_title
                        FROM student_bills b
                        JOIN users u_s ON b.student_id = u_s.id
                        LEFT JOIN parent_students ps ON u_s.id = ps.student_id
                        LEFT JOIN users u_p ON ps.parent_id = u_p.id
                        WHERE b.id = ? LIMIT 1
                    ");
                    $stmt_parent->execute([$bill_id]);
                    $p_pay = $stmt_parent->fetch();
                    if ($p_pay && !empty($p_pay['parent_phone'])) {
                        $formatted_amount = "Rp " . number_format((float)$bill['amount'], 0, ',', '.');
                        $wa_pay_msg = "Halo Bpk/Ibu *{$p_pay['parent_name']}*,\n\n"
                                    . "Setoran tunai tagihan *{$p_pay['bill_title']}* untuk ananda *{$p_pay['student_name']}* sebesar *{$formatted_amount}* di loket TU telah berhasil dicatat dan status telah *LUNAS*.\n\n"
                                    . "Kuitansi digital sah dapat dicetak melalui portal sekolah. Terima kasih.";
                        sendWhatsAppNotification($pdo, $p_pay['parent_phone'], $p_pay['parent_name'], $wa_pay_msg, 'keuangan');
                    }
                } catch (Exception $e_wa_c) {}

                $message = "Pembayaran tunai tagihan berhasil dicatat dan status telah LUNAS.";
                $message_type = "success";
            }
        } catch (Exception $e_cash) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "Gagal memproses pembayaran tunai. Silakan coba lagi.";
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 6. ONLINE PAYMENT GATEWAY SETTLEMENT (QRIS & Virtual Account - Fase 4)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'instant_gateway_pay') {
    // -2 fix: Gateway payment settlement hanya boleh dilakukan oleh Staf & Admin
    if (!$can_manage) {
        $message = "Anda tidak memiliki wewenang untuk memproses settlement pembayaran gateway.";
        $message_type = "error";
    } elseif (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $bill_id = (int)($_POST['bill_id'] ?? 0);
        $channel = trim($_POST['payment_channel'] ?? 'QRIS');
        $trx_id  = 'GW-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $va_num  = trim($_POST['va_number'] ?? '');

        // -6 fix: Gunakan transaction + FOR UPDATE untuk cegah race condition
        $pdo->beginTransaction();
        try {
            $stmt_b = $pdo->prepare("
                SELECT sb.*, u.name as student_name, u.phone as student_phone, u.id as student_user_id 
                FROM student_bills sb 
                JOIN users u ON sb.student_id = u.id 
                WHERE sb.id = ? FOR UPDATE
            ");
            $stmt_b->execute([$bill_id]);
            $bill = $stmt_b->fetch();

            if (!$bill) {
                $pdo->rollBack();
                $message = "Tagihan tidak ditemukan.";
                $message_type = "error";
            } elseif ($bill['status'] === 'lunas') {
                $pdo->rollBack();
                $message = "Tagihan ini sudah berstatus LUNAS.";
                $message_type = "error";
            } else {
                $stmt_upd = $pdo->prepare("
                    UPDATE student_bills 
                    SET status = 'lunas',
                        payment_channel = ?,
                        gateway_trx_id = ?,
                        va_number = ?,
                        paid_at = CURRENT_TIMESTAMP,
                        notes = CONCAT(COALESCE(notes, ''), ' | Lunas via ', ?, ' (Trx: ', ?, ')')
                    WHERE id = ?
                ");
                $stmt_upd->execute([$channel, $trx_id, $va_num ?: null, $channel, $trx_id, $bill_id]);

                // Catat di bill_payments agar kuitansi cetak otomatis tersedia
                $stmt_ins_p = $pdo->prepare("
                    INSERT INTO bill_payments (bill_id, student_id, amount_paid, payment_method, payment_date, status, verified_by, verified_at, notes)
                    VALUES (?, ?, ?, 'online_gateway', CURRENT_DATE(), 'diterima', ?, NOW(), ?)
                ");
                $stmt_ins_p->execute([$bill_id, $bill['student_id'], $bill['amount'], $user_id, "Settlement Otomatis Gateway $channel (ID: $trx_id)"]);

                $pdo->commit();

                // Kirim notifikasi WA ke Orang Tua
                try {
                    $stmt_parent = $pdo->prepare("
                        SELECT u_p.name, u_p.phone 
                        FROM parent_students ps
                        JOIN users u_p ON ps.parent_id = u_p.id
                        WHERE ps.student_id = ?
                        LIMIT 1
                    ");
                    $stmt_parent->execute([$bill['student_user_id']]);
                    $parent = $stmt_parent->fetch();

                    $target_phone = $parent['phone'] ?? $bill['student_phone'] ?? '';
                    $target_name  = $parent['name'] ?? $bill['student_name'];

                    if (!empty($target_phone)) {
                        $nominal_fmt = "Rp " . number_format($bill['amount'], 0, ',', '.');
                        $wa_msg = "PEMBAYARAN DIVERIFIKASI (LUNAS)\n\n" .
                                  "Yth. Bpk/Ibu $target_name,\n" .
                                  "Pembayaran tagihan *" . $bill['title'] . "* untuk *" . $bill['student_name'] . "* sebesar *$nominal_fmt* telah BERHASIL terverifikasi lunas melalui kanal *$channel* (Trx: $trx_id).\n\n" .
                                  "Kuitansi resmi digital telah tersedia pada portal keuangan sekolah. Terima kasih.\n\n- Bagian Keuangan Sekolah";
                        sendWhatsAppNotification($pdo, $target_phone, $target_name, $wa_msg, 'keuangan');
                    }
                } catch (Exception $e_wa_gw) {}

                logActivity($pdo, 'GATEWAY_PAYMENT', "Pembayaran tagihan ID $bill_id (" . $bill['title'] . ") lunas via gateway $channel");
                $message = "Pembayaran via " . htmlspecialchars($channel) . " sukses diverifikasi! Kuitansi digital telah terbit.";
                $message_type = "success";
            }
        } catch (Exception $e_gw) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "Gagal memproses settlement gateway. Silakan coba lagi.";
            $message_type = "error";
        }
    }
}

// -------------------------------------------------------------
// 7. AMBIL DATA TAGIHAN & PEMBAYARAN
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

    <?php if ($user_role === 'orang_tua' && count($parent_children) > 1): ?>
        <div class="rounded-2xl border border-blue-500/30 bg-blue-950/40 p-4 backdrop-blur flex flex-wrap items-center justify-between gap-3 shadow-lg">
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-blue-300"><i class="fa-solid fa-children mr-1"></i> Pilih Data Tagihan Anak:</span>
                <form method="GET" class="inline-flex items-center gap-2">
                    <select name="student_id" onchange="this.form.submit()" class="rounded-xl border border-blue-500/40 bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white focus:outline-none">
                        <?php foreach ($parent_children as $pk): ?>
                            <option value="<?= $pk['id'] ?>" <?= ($target_student_id == $pk['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pk['name']) ?> (<?= htmlspecialchars($pk['class_name'] ?: 'Umum') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <span class="text-xs text-slate-400">Menampilkan tagihan SPP dan status pembayaran untuk anak terpilih</span>
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
                                        <a href="<?= htmlspecialchars(resolveAssetUrl($pv['proof_url'], '../../')) ?>" target="_blank" class="inline-flex items-center gap-1.5 text-xs text-blue-400 underline hover:text-blue-300">
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
                                        <div class="flex flex-col items-end gap-1">
                                            <?php if (!empty($bill['payment_channel'])): ?>
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold rounded-md bg-purple-500/20 text-purple-300 border border-purple-500/30 uppercase font-mono">
                                                    <i class="fa-solid fa-bolt text-[9px]"></i> <?= htmlspecialchars(strtoupper(str_replace('_', ' ', $bill['payment_channel']))) ?>
                                                </span>
                                            <?php endif; ?>
                                            <a href="receipt.php?bill_id=<?= $bill['id'] ?>" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-1.5 text-xs font-semibold text-emerald-300 hover:bg-emerald-500/20 transition">
                                                <i class="fa-solid fa-print"></i> Kuitansi
                                            </a>
                                        </div>
                                    <?php elseif ($bill['status'] === 'belum_lunas'): ?>
                                        <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                            <button type="button" onclick="openGatewayModal(<?= $bill['id'] ?>, '<?= htmlspecialchars($bill['title'], ENT_QUOTES) ?>', <?= $bill['amount'] ?>, '<?= htmlspecialchars($bill['student_name'] ?? ($current_user['name'] ?? 'Siswa'), ENT_QUOTES) ?>', '<?= htmlspecialchars($bill['nisn'] ?? '0098765432', ENT_QUOTES) ?>')" class="rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-md shadow-purple-500/20 hover:from-purple-500 hover:to-indigo-500 transition cursor-pointer inline-flex items-center gap-1.5">
                                                <i class="fa-solid fa-bolt text-amber-300"></i> Bayar Gateway
                                            </button>
                                            
                                            <?php if ($can_manage): ?>
                                                <form method="POST" onsubmit="return confirm('Catat pembayaran tunai lunas di kasir TU?');" class="inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="quick_cash_pay">
                                                    <input type="hidden" name="bill_id" value="<?= $bill['id'] ?>">
                                                    <button type="submit" class="rounded-xl bg-emerald-600/90 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500 transition cursor-pointer inline-flex items-center gap-1">
                                                        <i class="fa-solid fa-money-bill-wave"></i> Tunai
                                                    </button>
                                                </form>
                                            <?php elseif (in_array($user_role, ['siswa', 'orang_tua'], true)): ?>
                                                <button onclick="openPaymentModal(<?= $bill['id'] ?>, '<?= htmlspecialchars($bill['title'], ENT_QUOTES) ?>', <?= $bill['amount'] ?>)" class="rounded-xl border border-white/10 bg-slate-800 px-2.5 py-1.5 text-xs font-medium text-slate-300 hover:bg-slate-700 transition cursor-pointer inline-flex items-center gap-1">
                                                    <i class="fa-solid fa-upload"></i> Unggah Bukti
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 text-xs text-amber-400 bg-amber-500/10 px-2.5 py-1 rounded-lg border border-amber-500/20">
                                            <i class="fa-solid fa-hourglass-half"></i> Sedang Diverifikasi
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

<!-- Modal Payment Gateway Simulator (Fase 4) -->
<div id="modalGatewayPay" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 p-4 backdrop-blur-md hidden">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-200">
        <!-- Header -->
        <div class="relative bg-gradient-to-r from-purple-900/60 via-indigo-900/50 to-slate-900 p-6 border-b border-white/10">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-purple-500/20 text-purple-300 border border-purple-500/30">
                        <i class="fa-solid fa-bolt text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center gap-2">
                            Payment Gateway
                            <span class="rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 text-[10px] font-mono px-2 py-0.5 font-bold uppercase tracking-wider">
                                Instant Settlement
                            </span>
                        </h3>
                        <p class="text-xs text-slate-300">Simulasi Pembayaran Otomatis & Notifikasi WA</p>
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('modalGatewayPay').classList.add('hidden')" class="rounded-xl p-2 text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <!-- Bill Info Banner -->
            <div class="mt-4 rounded-2xl bg-slate-950/60 p-4 border border-white/5 flex items-center justify-between">
                <div>
                    <div id="gw_bill_title" class="text-xs font-semibold text-slate-300 line-clamp-1"></div>
                    <div id="gw_student_name" class="text-[11px] text-slate-400 font-mono"></div>
                </div>
                <div class="text-right">
                    <div class="text-[10px] text-slate-400 uppercase font-semibold">Total Tagihan</div>
                    <div id="gw_bill_amount" class="text-lg font-black text-emerald-400 font-mono"></div>
                </div>
            </div>
        </div>

        <!-- Body Form -->
        <form method="POST" id="formGatewayPay" class="p-6 space-y-5">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="instant_gateway_pay">
            <input type="hidden" name="bill_id" id="gw_bill_id" value="">
            <input type="hidden" name="payment_channel" id="gw_payment_channel" value="qris">

            <!-- Channel Selector Tabs -->
            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-2">Pilih Saluran Pembayaran (Channel):</label>
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" onclick="selectGatewayChannel('qris')" id="tab_channel_qris" class="gw-channel-tab flex flex-col items-center justify-center p-2.5 rounded-xl border border-purple-500/50 bg-purple-500/10 text-white font-semibold text-xs transition">
                        <i class="fa-solid fa-qrcode text-base mb-1 text-purple-400"></i>
                        <span>QRIS</span>
                    </button>
                    <button type="button" onclick="selectGatewayChannel('bca_va')" id="tab_channel_bca_va" class="gw-channel-tab flex flex-col items-center justify-center p-2.5 rounded-xl border border-white/10 bg-slate-950 text-slate-300 font-semibold text-xs hover:border-white/20 transition">
                        <i class="fa-solid fa-building-columns text-base mb-1 text-blue-400"></i>
                        <span>BCA VA</span>
                    </button>
                    <button type="button" onclick="selectGatewayChannel('mandiri_va')" id="tab_channel_mandiri_va" class="gw-channel-tab flex flex-col items-center justify-center p-2.5 rounded-xl border border-white/10 bg-slate-950 text-slate-300 font-semibold text-xs hover:border-white/20 transition">
                        <i class="fa-solid fa-building-columns text-base mb-1 text-amber-400"></i>
                        <span>Mandiri VA</span>
                    </button>
                    <button type="button" onclick="selectGatewayChannel('bri_va')" id="tab_channel_bri_va" class="gw-channel-tab flex flex-col items-center justify-center p-2.5 rounded-xl border border-white/10 bg-slate-950 text-slate-300 font-semibold text-xs hover:border-white/20 transition">
                        <i class="fa-solid fa-building-columns text-base mb-1 text-cyan-400"></i>
                        <span>BRI VA</span>
                    </button>
                    <button type="button" onclick="selectGatewayChannel('bni_va')" id="tab_channel_bni_va" class="gw-channel-tab flex flex-col items-center justify-center p-2.5 rounded-xl border border-white/10 bg-slate-950 text-slate-300 font-semibold text-xs hover:border-white/20 transition">
                        <i class="fa-solid fa-building-columns text-base mb-1 text-emerald-400"></i>
                        <span>BNI VA</span>
                    </button>
                    <div class="flex flex-col items-center justify-center p-2.5 rounded-xl border border-dashed border-white/10 bg-slate-950/40 text-slate-500 text-[10px] text-center">
                        <i class="fa-solid fa-wallet text-sm mb-0.5"></i>
                        <span>E-Wallet</span>
                    </div>
                </div>
            </div>

            <!-- Panel Content: QRIS -->
            <div id="panel_channel_qris" class="rounded-2xl border border-purple-500/20 bg-purple-950/20 p-4 text-center space-y-3">
                <div class="flex items-center justify-center gap-2">
                    <span class="text-xs font-bold text-purple-300 uppercase tracking-wider">QRIS Standar Nasional</span>
                    <span class="rounded bg-white/10 px-1.5 py-0.5 text-[9px] font-mono text-purple-200">GOPAY / OVO / DANA / BCA</span>
                </div>
                
                <!-- Simulated QR SVG -->
                <div class="inline-block p-3 bg-white rounded-2xl shadow-lg shadow-purple-500/10">
                    <svg class="w-36 h-36" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <!-- Simulated QR Grid -->
                        <rect width="100" height="100" fill="#ffffff" />
                        <rect x="10" y="10" width="25" height="25" fill="#0f172a" rx="4" />
                        <rect x="15" y="15" width="15" height="15" fill="#ffffff" rx="2" />
                        <rect x="19" y="19" width="7" height="7" fill="#0f172a" />

                        <rect x="65" y="10" width="25" height="25" fill="#0f172a" rx="4" />
                        <rect x="70" y="15" width="15" height="15" fill="#ffffff" rx="2" />
                        <rect x="74" y="19" width="7" height="7" fill="#0f172a" />

                        <rect x="10" y="65" width="25" height="25" fill="#0f172a" rx="4" />
                        <rect x="15" y="70" width="15" height="15" fill="#ffffff" rx="2" />
                        <rect x="19" y="74" width="7" height="7" fill="#0f172a" />

                        <!-- Center QR Pattern -->
                        <rect x="42" y="15" width="6" height="6" fill="#0f172a" />
                        <rect x="52" y="15" width="6" height="6" fill="#0f172a" />
                        <rect x="42" y="25" width="6" height="6" fill="#0f172a" />
                        <rect x="15" y="42" width="6" height="6" fill="#0f172a" />
                        <rect x="25" y="42" width="6" height="6" fill="#0f172a" />
                        <rect x="40" y="40" width="20" height="20" fill="#6366f1" rx="4" />
                        <path d="M47 50L53 50M50 47L50 53" stroke="#ffffff" stroke-width="2" stroke-linecap="round"/>
                        <rect x="65" y="42" width="6" height="6" fill="#0f172a" />
                        <rect x="75" y="42" width="6" height="6" fill="#0f172a" />
                        <rect x="42" y="68" width="6" height="6" fill="#0f172a" />
                        <rect x="52" y="68" width="6" height="6" fill="#0f172a" />
                        <rect x="68" y="68" width="18" height="6" fill="#0f172a" />
                        <rect x="68" y="78" width="6" height="8" fill="#0f172a" />
                        <rect x="78" y="78" width="8" height="8" fill="#0f172a" />
                    </svg>
                </div>

                <div class="text-[11px] text-slate-300">
                    NMID: <strong class="text-white font-mono">ID1020030040506</strong> • Berlaku s.d. 24 jam
                </div>
            </div>

            <!-- Panel Content: Virtual Account -->
            <div id="panel_channel_va" class="hidden rounded-2xl border border-blue-500/20 bg-blue-950/20 p-4 space-y-3">
                <div class="flex items-center justify-between">
                    <span id="va_bank_title" class="text-xs font-bold text-blue-300 uppercase">Virtual Account</span>
                    <span class="rounded bg-blue-500/20 text-blue-300 text-[10px] font-mono px-2 py-0.5">Otomatis Terverifikasi</span>
                </div>

                <div class="rounded-xl bg-slate-950/80 p-3 border border-white/5 flex items-center justify-between">
                    <div>
                        <div class="text-[10px] text-slate-400 font-semibold uppercase">Nomor Virtual Account</div>
                        <div id="va_number_display" class="text-lg font-mono font-bold text-amber-300 tracking-wider">8808123456789026</div>
                    </div>
                    <button type="button" onclick="copyVaNumber()" class="rounded-lg bg-white/10 hover:bg-white/20 text-slate-200 px-3 py-1.5 text-xs font-medium transition cursor-pointer flex items-center gap-1.5">
                        <i class="fa-regular fa-copy"></i> <span id="copy_btn_text">Salin</span>
                    </button>
                </div>

                <div class="text-[11px] text-slate-400 space-y-1">
                    <div>1. Buka aplikasi Mobile Banking atau ATM bank pilihan Anda.</div>
                    <div>2. Pilih menu <strong>Transfer &gt; Virtual Account</strong>.</div>
                    <div>3. Masukkan nomor VA di atas. Total bayar akan muncul otomatis.</div>
                </div>
            </div>

            <!-- Automated Execution / Simulation CTA -->
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3.5 text-xs text-emerald-200 flex items-start gap-2.5">
                <i class="fa-solid fa-circle-check text-emerald-400 text-base mt-0.5"></i>
                <div class="space-y-0.5">
                    <div class="font-bold text-emerald-300">Simulator Gateway Instan</div>
                    <div class="text-[11px] text-emerald-200/80">
                        Klik tombol di bawah untuk menyimulasikan pembayaran lunas seketika melalui webhook gateway. Sistem akan langsung memperbarui status tagihan menjadi <strong>LUNAS</strong> dan mengirimkan kuitansi digital via <strong>WhatsApp Gateway</strong>.
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('modalGatewayPay').classList.add('hidden')" class="rounded-xl border border-white/10 px-4 py-2.5 text-xs font-semibold text-slate-300 hover:bg-white/5 transition cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-6 py-2.5 text-xs font-bold text-white shadow-lg shadow-emerald-500/25 hover:from-emerald-500 hover:to-teal-500 transition cursor-pointer flex items-center gap-2">
                    <i class="fa-solid fa-bolt"></i> Simulasikan Sukses Bayar Sekarang
                </button>
            </div>
        </form>
    </div>
</div>

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
    if (infoTunai) infoTunai.classList.remove('hidden');

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

let currentGatewayNisn = '0098765432';

function openGatewayModal(id, title, amount, studentName, studentNisn) {
    document.getElementById('gw_bill_id').value = id;
    document.getElementById('gw_bill_title').innerText = title;
    document.getElementById('gw_student_name').innerText = 'Siswa: ' + studentName + (studentNisn ? ' (' + studentNisn + ')' : '');
    document.getElementById('gw_bill_amount').innerText = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(amount);
    currentGatewayNisn = studentNisn || '0098765432';

    selectGatewayChannel('qris');
    document.getElementById('modalGatewayPay').classList.remove('hidden');
}

function selectGatewayChannel(channel) {
    document.getElementById('gw_payment_channel').value = channel;
    
    // Update tab styling
    document.querySelectorAll('.gw-channel-tab').forEach(el => {
        el.className = 'gw-channel-tab flex flex-col items-center justify-center p-2.5 rounded-xl border border-white/10 bg-slate-950 text-slate-300 font-semibold text-xs hover:border-white/20 transition';
    });
    const activeTab = document.getElementById('tab_channel_' + channel);
    if (activeTab) {
        activeTab.className = 'gw-channel-tab flex flex-col items-center justify-center p-2.5 rounded-xl border border-purple-500/50 bg-purple-500/15 text-white font-semibold text-xs transition';
    }

    const panelQris = document.getElementById('panel_channel_qris');
    const panelVa = document.getElementById('panel_channel_va');
    const vaBankTitle = document.getElementById('va_bank_title');
    const vaDisplay = document.getElementById('va_number_display');

    if (channel === 'qris') {
        panelQris.classList.remove('hidden');
        panelVa.classList.add('hidden');
    } else {
        panelQris.classList.add('hidden');
        panelVa.classList.remove('hidden');

        let prefix = '8808';
        let bankName = 'Virtual Account';
        if (channel === 'bca_va') { prefix = '8808'; bankName = 'BCA Virtual Account'; }
        else if (channel === 'mandiri_va') { prefix = '8890'; bankName = 'Mandiri Virtual Account'; }
        else if (channel === 'bri_va') { prefix = '8812'; bankName = 'BRI Virtual Account (BRIVA)'; }
        else if (channel === 'bni_va') { prefix = '8834'; bankName = 'BNI Virtual Account'; }

        vaBankTitle.innerText = bankName;
        // Clean nisn only digits
        const cleanNisn = currentGatewayNisn.replace(/\D/g, '').padEnd(8, '0').slice(0, 10);
        vaDisplay.innerText = prefix + cleanNisn + '26';
    }
}

function copyVaNumber() {
    const text = document.getElementById('va_number_display').innerText;
    navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('copy_btn_text');
        btn.innerText = 'Tersalin!';
        setTimeout(() => { btn.innerText = 'Salin'; }, 2000);
    });
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
