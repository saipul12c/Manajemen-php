<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'guru';

// Siswa dan orang tua tidak memiliki akses ke modul ini
if (in_array($user_role, ['siswa', 'orang_tua'], true)) {
    header("Location: ../index.php");
    exit;
}

$can_manage = in_array($user_role, ['administrator', 'staf'], true);

// Filter Periode Bulan (format: YYYY-MM, default: bulan berjalan)
$current_month = date('Y-m');
$filter_month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : $current_month;
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_teacher = isset($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

$success_message = '';
$error_message = '';

// Ambil konfigurasi standar sekolah
$settings = getSchoolSettings($pdo);
$base_salary_default = isset($settings['payroll_base_salary_default']) ? (float)$settings['payroll_base_salary_default'] : 3000000.00;
$teaching_rate_hour = isset($settings['payroll_teaching_rate_hour']) ? (float)$settings['payroll_teaching_rate_hour'] : 50000.00;
$homeroom_allowance = isset($settings['payroll_homeroom_allowance']) ? (float)$settings['payroll_homeroom_allowance'] : 500000.00;

// POST Handlers (Admin & Staf Only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    if (!validateCsrfToken()) {
        $error_message = "Token CSRF tidak valid atau sesi Anda telah kedaluwarsa. Silakan muat ulang halaman.";
    } else {
        $action = $_POST['action'] ?? '';

    // 1. Generate / Kalkulasi Otomatis Batch Payroll Bulan Terpilih
    if ($action === 'generate_batch') {
        $gen_month = $_POST['month_period'] ?? $filter_month;
        if (!preg_match('/^\d{4}-\d{2}$/', $gen_month)) {
            $error_message = "Format periode bulan tidak valid.";
        } else {
            // Ambil seluruh guru aktif
            $stmt_teachers = $pdo->query("SELECT id, name, phone FROM users WHERE role = 'guru' ORDER BY id ASC");
            $teachers = $stmt_teachers->fetchAll();

            $generated_count = 0;
            $skipped_count = 0;

            foreach ($teachers as $t) {
                $t_id = (int)$t['id'];

                // Cek apakah sudah ada payroll di bulan ini
                $stmt_check = $pdo->prepare("SELECT id, status FROM teacher_payrolls WHERE teacher_id = ? AND month_period = ?");
                $stmt_check->execute([$t_id, $gen_month]);
                $existing = $stmt_check->fetch();

                if ($existing) {
                    $skipped_count++;
                    continue;
                }

                // Cek apakah wali kelas
                $stmt_hr = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE homeroom_teacher_id = ?");
                $stmt_hr->execute([$t_id]);
                $is_homeroom = ((int)$stmt_hr->fetchColumn()) > 0;
                $pos_allowance = $is_homeroom ? $homeroom_allowance : 0.00;

                // Hitung jam mengajar riil dari teaching_journals (termasuk jam tugas inval guru pengganti)
                $stmt_j = $pdo->prepare("
                    SELECT COUNT(*) FROM teaching_journals 
                    WHERE ((teacher_id = ? AND status = 'terlaksana') OR (substitute_teacher_id = ? AND status = 'inval'))
                      AND DATE_FORMAT(date, '%Y-%m') = ?
                ");
                $stmt_j->execute([$t_id, $t_id, $gen_month]);
                $journal_count = (int)$stmt_j->fetchColumn();
                $teaching_hours = $journal_count * 2; // Standar 2 JP per tatap muka KBM
                $total_teaching_honor = $teaching_hours * $teaching_rate_hour;

                // Hitung penalti absensi dari teacher_attendance (kolom teacher_id)
                $stmt_att = $pdo->prepare("
                    SELECT COUNT(*) FROM teacher_attendance 
                    WHERE teacher_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? 
                    AND (status = 'alpa' OR status = 'terlambat' OR (distance_meters IS NOT NULL AND distance_meters > 150))
                ");
                $stmt_att->execute([$t_id, $gen_month]);
                $penalties = (int)$stmt_att->fetchColumn();
                $attendance_deductions = $penalties * 50000.00; // Rp 50.000 per pelanggaran/keterlambatan

                $base_sal = $base_salary_default;
                $net_salary = max(0, $base_sal + $pos_allowance + $total_teaching_honor - $attendance_deductions);

                // Buat kode payroll unik
                $clean_month = str_replace('-', '', $gen_month);
                $code = "PAY-" . $clean_month . "-" . str_pad($t_id, 3, '0', STR_PAD_LEFT);

                $stmt_ins = $pdo->prepare("
                    INSERT INTO teacher_payrolls 
                    (payroll_code, teacher_id, month_period, base_salary, position_allowance, teaching_hours, teaching_rate_per_hour, total_teaching_honor, attendance_deductions, net_salary, status, payment_method, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 'Transfer Bank', ?, ?)
                ");
                $notes = "Auto-generated: " . $journal_count . " Jurnal Mengajar (" . $teaching_hours . " JP), " . ($is_homeroom ? 'Wali Kelas, ' : '') . $penalties . " Catatan Presensi.";
                $stmt_ins->execute([$code, $t_id, $gen_month, $base_sal, $pos_allowance, $teaching_hours, $teaching_rate_hour, $total_teaching_honor, $attendance_deductions, $net_salary, $notes, $user_id]);
                $generated_count++;
            }

            $success_message = "Kalkulasi payroll berhasil! {$generated_count} data guru dihitung, {$skipped_count} data dilewati (sudah dibuat).";
        }
    }

    // 2. Setujui Payroll (Approve)
    elseif ($action === 'approve') {
        $p_id = (int)($_POST['payroll_id'] ?? 0);
        $stmt_app = $pdo->prepare("UPDATE teacher_payrolls SET status = 'disetujui' WHERE id = ? AND status = 'draft'");
        $stmt_app->execute([$p_id]);
        $success_message = "Data payroll berhasil disetujui (Approved). Siap untuk dicairkan.";
    }

    // 3. Cairkan / Bayar Payroll (Disburse & WhatsApp Notification)
    elseif ($action === 'pay') {
        $p_id = (int)($_POST['payroll_id'] ?? 0);
        $stmt_fetch = $pdo->prepare("
            SELECT p.*, u.name as teacher_name, u.phone as teacher_phone 
            FROM teacher_payrolls p 
            JOIN users u ON p.teacher_id = u.id 
            WHERE p.id = ?
        ");
        $stmt_fetch->execute([$p_id]);
        $p_data = $stmt_fetch->fetch();

        if ($p_data) {
            $stmt_pay = $pdo->prepare("UPDATE teacher_payrolls SET status = 'dibayar', payment_date = CURDATE() WHERE id = ?");
            $stmt_pay->execute([$p_id]);

            // Kirim Notifikasi WhatsApp ke Guru (Fase 1 Engine)
            if (!empty($p_data['teacher_phone'])) {
                $month_name = date('F Y', strtotime($p_data['month_period'] . '-01'));
                $formatted_net = "Rp " . number_format($p_data['net_salary'], 0, ',', '.');
                $wa_msg = "Halo Bpk/Ibu *{$p_data['teacher_name']}*,\n\n"
                        . "Gaji & honor mengajar Anda untuk periode *{$month_name}* sebesar *{$formatted_net}* telah berhasil dicairkan melalui *{$p_data['payment_method']}*.\n\n"
                        . "Nomor Slip: *{$p_data['payroll_code']}*\n"
                        . "Anda dapat mengunduh dan mencetak slip gaji digital melalui Portal SIM Sekolah.\n\n"
                        . "Terima kasih atas dedikasi dan pengabdian Anda dalam mendidik siswa-siswi kita. Salam hangat dari Bagian Keuangan.";

                sendWhatsAppNotification($pdo, $p_data['teacher_phone'], $wa_msg, 'payroll_disbursement', (int)$p_data['teacher_id']);
            }

            // Integrasi Otomatis ke Buku Kas Umum (BKU)
            try {
                $bkk_no = "BKK-PAY-" . str_replace(['PAY-', '-'], ['', '/'], $p_data['payroll_code']);
                $stmt_chk_exp = $pdo->prepare("SELECT id FROM financial_expenses WHERE expense_no = ?");
                $stmt_chk_exp->execute([$bkk_no]);
                if (!$stmt_chk_exp->fetch()) {
                    $stmt_ins_exp = $pdo->prepare("
                        INSERT INTO financial_expenses (expense_no, category, title, expense_date, amount, recipient, notes, recorded_by)
                        VALUES (?, 'Gaji & Honor Guru / Pegawai', ?, CURDATE(), ?, ?, ?, ?)
                    ");
                    $month_fmt = date('F Y', strtotime($p_data['month_period'] . '-01'));
                    $exp_title = "Pencairan Gaji & Honor Guru Periode {$month_fmt} ({$p_data['payroll_code']})";
                    $exp_notes = "Otomatisasi Modul Payroll. Metode: {$p_data['payment_method']}. {$p_data['notes']}";
                    $stmt_ins_exp->execute([$bkk_no, $exp_title, $p_data['net_salary'], $p_data['teacher_name'], $exp_notes, $user_id]);
                    logActivity($pdo, 'PAYROLL_TO_BKU', "Otomatis mencatat pengeluaran BKU {$bkk_no} dari pencairan gaji {$p_data['payroll_code']}");
                }
            } catch (Exception $e_bku) {
                // Abaikan jika pencatatan BKU mengalami hambatan sementara
            }

            $success_message = "Gaji berhasil dicairkan! Notifikasi WhatsApp resmi terkirim & otomatis tercatat di Buku Kas Umum (BKU).";
        }
    }

    // 4. Batch Cairkan Semua yang Disetujui
    elseif ($action === 'batch_pay') {
        $stmt_approved = $pdo->prepare("
            SELECT p.id, p.payroll_code, p.net_salary, p.month_period, p.payment_method, u.id as teacher_id, u.name as teacher_name, u.phone as teacher_phone
            FROM teacher_payrolls p
            JOIN users u ON p.teacher_id = u.id
            WHERE p.month_period = ? AND p.status = 'disetujui'
        ");
        $stmt_approved->execute([$filter_month]);
        $approved_list = $stmt_approved->fetchAll();

        $paid_count = 0;
        foreach ($approved_list as $row) {
            $pdo->prepare("UPDATE teacher_payrolls SET status = 'dibayar', payment_date = CURDATE() WHERE id = ?")->execute([$row['id']]);

            // Sinkronisasi otomatis ke Buku Kas Umum (BKU)
            try {
                $bkk_no = "BKK-PAY-" . str_replace(['PAY-', '-'], ['', '/'], $row['payroll_code']);
                $stmt_chk_exp = $pdo->prepare("SELECT id FROM financial_expenses WHERE expense_no = ?");
                $stmt_chk_exp->execute([$bkk_no]);
                if (!$stmt_chk_exp->fetch()) {
                    $stmt_ins_exp = $pdo->prepare("
                        INSERT INTO financial_expenses (expense_no, category, title, expense_date, amount, recipient, notes, recorded_by)
                        VALUES (?, 'Gaji & Honor Guru / Pegawai', ?, CURDATE(), ?, ?, ?, ?)
                    ");
                    $m_fmt = date('F Y', strtotime($row['month_period'] . '-01'));
                    $exp_title = "Pencairan Gaji & Honor Guru Periode {$m_fmt} ({$row['payroll_code']})";
                    $exp_notes = "Otomatisasi Modul Payroll (Batch). Metode: {$row['payment_method']}";
                    $stmt_ins_exp->execute([$bkk_no, $exp_title, $row['net_salary'], $row['teacher_name'], $exp_notes, $user_id]);
                }
            } catch (Exception $e_bku) {}

            if (!empty($row['teacher_phone'])) {
                $month_name = date('F Y', strtotime($row['month_period'] . '-01'));
                $formatted_net = "Rp " . number_format($row['net_salary'], 0, ',', '.');
                $wa_msg = "Halo Bpk/Ibu *{$row['teacher_name']}*,\n\n"
                        . "Gaji & honor mengajar Anda periode *{$month_name}* sebesar *{$formatted_net}* telah berhasil dicairkan melalui *{$row['payment_method']}*.\n"
                        . "Nomor Slip: *{$row['payroll_code']}*\n\n"
                        . "Slip gaji digital dapat diakses di portal sekolah. Terima kasih atas dedikasi Anda.";
                sendWhatsAppNotification($pdo, $row['teacher_phone'], $wa_msg, 'payroll_disbursement', (int)$row['teacher_id']);
            }
            $paid_count++;
        }

        $success_message = "Pencairan massal selesai! {$paid_count} guru telah dicairkan gajinya, tercatat di BKU, dan menerima notifikasi WhatsApp.";
    }

    // 5. Update / Penyesuaian Manual Komponen Gaji
    elseif ($action === 'adjust_payroll') {
        $p_id = (int)($_POST['payroll_id'] ?? 0);
        $base = (float)($_POST['base_salary'] ?? 0);
        $pos = (float)($_POST['position_allowance'] ?? 0);
        $thours = (int)($_POST['teaching_hours'] ?? 0);
        $trate = (float)($_POST['teaching_rate_per_hour'] ?? 0);
        $thonor = $thours * $trate;
        $deductions = (float)($_POST['attendance_deductions'] ?? 0);
        $method = trim($_POST['payment_method'] ?? 'Transfer Bank');
        $notes = trim($_POST['notes'] ?? '');

        $net = max(0, $base + $pos + $thonor - $deductions);

        $stmt_up = $pdo->prepare("
            UPDATE teacher_payrolls 
            SET base_salary = ?, position_allowance = ?, teaching_hours = ?, teaching_rate_per_hour = ?, total_teaching_honor = ?, attendance_deductions = ?, net_salary = ?, payment_method = ?, notes = ?
            WHERE id = ? AND status != 'dibayar'
        ");
        $stmt_up->execute([$base, $pos, $thours, $trate, $thonor, $deductions, $net, $method, $notes, $p_id]);
        $success_message = "Penyesuaian rincian gaji berhasil disimpan.";
    }

    // 6. Hapus Data Payroll (Khusus Draft)
    elseif ($action === 'delete_payroll') {
        $p_id = (int)($_POST['payroll_id'] ?? 0);
        $stmt_del = $pdo->prepare("DELETE FROM teacher_payrolls WHERE id = ? AND status = 'draft'");
        $stmt_del->execute([$p_id]);
        $success_message = "Draft data payroll berhasil dihapus.";
    }

    // 7. Hitung Ulang & Sinkronisasi Mandiri dari Jurnal KBM & Presensi PTK
    elseif ($action === 'recalculate_single') {
        $p_id = (int)($_POST['payroll_id'] ?? 0);
        $stmt_cur = $pdo->prepare("SELECT * FROM teacher_payrolls WHERE id = ? AND status = 'draft'");
        $stmt_cur->execute([$p_id]);
        $cur_p = $stmt_cur->fetch();

        if ($cur_p) {
            $t_id = (int)$cur_p['teacher_id'];
            $p_month = $cur_p['month_period'];

            // Cek status wali kelas
            $stmt_hr = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE homeroom_teacher_id = ?");
            $stmt_hr->execute([$t_id]);
            $is_homeroom = ((int)$stmt_hr->fetchColumn()) > 0;
            $pos_allowance = $is_homeroom ? $homeroom_allowance : 0.00;

            // Hitung jam mengajar aktual dari teaching_journals (termasuk tugas inval guru pengganti)
            $stmt_j = $pdo->prepare("
                SELECT COUNT(*) FROM teaching_journals 
                WHERE ((teacher_id = ? AND status = 'terlaksana') OR (substitute_teacher_id = ? AND status = 'inval'))
                  AND DATE_FORMAT(date, '%Y-%m') = ?
            ");
            $stmt_j->execute([$t_id, $t_id, $p_month]);
            $journal_count = (int)$stmt_j->fetchColumn();
            $teaching_hours = $journal_count * 2;
            $total_teaching_honor = $teaching_hours * $teaching_rate_hour;

            // Hitung penalti absensi dari teacher_attendance
            $stmt_att = $pdo->prepare("
                SELECT COUNT(*) FROM teacher_attendance 
                WHERE teacher_id = ? AND DATE_FORMAT(date, '%Y-%m') = ? 
                  AND (status = 'alpa' OR status = 'terlambat' OR (distance_meters IS NOT NULL AND distance_meters > 150))
            ");
            $stmt_att->execute([$t_id, $p_month]);
            $penalties = (int)$stmt_att->fetchColumn();
            $attendance_deductions = $penalties * 50000.00;

            $base_sal = (float)$cur_p['base_salary'] > 0 ? (float)$cur_p['base_salary'] : $base_salary_default;
            $net_salary = max(0, $base_sal + $pos_allowance + $total_teaching_honor - $attendance_deductions);

            $notes = "Sinkronisasi Realtime: " . $journal_count . " Jurnal KBM (" . $teaching_hours . " JP), " . ($is_homeroom ? 'Wali Kelas, ' : '') . $penalties . " Catatan Presensi.";

            $stmt_up_calc = $pdo->prepare("
                UPDATE teacher_payrolls 
                SET position_allowance = ?, teaching_hours = ?, teaching_rate_per_hour = ?, total_teaching_honor = ?, attendance_deductions = ?, net_salary = ?, notes = ?
                WHERE id = ?
            ");
            $stmt_up_calc->execute([$pos_allowance, $teaching_hours, $teaching_rate_hour, $total_teaching_honor, $attendance_deductions, $net_salary, $notes, $p_id]);

            $success_message = "Kalkulasi ulang berhasil! Data disinkronkan langsung dari {$journal_count} Jurnal Mengajar dan Presensi Guru.";
        }
    }
}
}

// Build query data payroll
$params = [];
$where_clauses = [];

// Jika role guru, hanya bisa melihat data miliknya
if ($user_role === 'guru') {
    $where_clauses[] = "p.teacher_id = ?";
    $params[] = $user_id;
} else {
    if ($filter_teacher > 0) {
        $where_clauses[] = "p.teacher_id = ?";
        $params[] = $filter_teacher;
    }
}

if (!empty($filter_month)) {
    $where_clauses[] = "p.month_period = ?";
    $params[] = $filter_month;
}

if (!empty($filter_status) && in_array($filter_status, ['draft', 'disetujui', 'dibayar'], true)) {
    $where_clauses[] = "p.status = ?";
    $params[] = $filter_status;
}

$where_sql = $where_clauses ? "WHERE " . implode(" AND ", $where_clauses) : "";

$query = "
    SELECT p.*, u.name as teacher_name, u.email as teacher_email, u.phone as teacher_phone,
           c.name as homeroom_class
    FROM teacher_payrolls p
    JOIN users u ON p.teacher_id = u.id
    LEFT JOIN classes c ON c.homeroom_teacher_id = u.id
    {$where_sql}
    ORDER BY p.id DESC
";
$stmt_list = $pdo->prepare($query);
$stmt_list->execute($params);
$payrolls = $stmt_list->fetchAll();

// Statistik ringkasan periode ini
$stat_params = [$filter_month];
$stat_teacher_sql = ($user_role === 'guru') ? " AND teacher_id = {$user_id}" : "";

$stmt_stats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_records,
        SUM(net_salary) as total_budget,
        SUM(CASE WHEN status = 'dibayar' THEN net_salary ELSE 0 END) as total_paid,
        SUM(CASE WHEN status != 'dibayar' THEN net_salary ELSE 0 END) as total_pending,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as count_draft,
        SUM(CASE WHEN status = 'disetujui' THEN 1 ELSE 0 END) as count_approved,
        SUM(CASE WHEN status = 'dibayar' THEN 1 ELSE 0 END) as count_paid
    FROM teacher_payrolls
    WHERE month_period = ? {$stat_teacher_sql}
");
$stmt_stats->execute($stat_params);
$stats = $stmt_stats->fetch();

// Daftar guru untuk dropdown filter (admin & staf)
$teachers_dropdown = [];
if ($can_manage) {
    $teachers_dropdown = $pdo->query("SELECT id, name FROM users WHERE role = 'guru' ORDER BY name ASC")->fetchAll();
}

$page_title = "Penggajian & Honor Guru (Payroll)";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Flash Messages -->
    <?php if ($success_message): ?>
        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-4 text-emerald-300 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-circle-check text-lg text-emerald-400"></i>
                <span class="text-sm font-semibold"><?= htmlspecialchars($success_message) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-white cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="rounded-2xl border border-rose-500/20 bg-rose-500/10 p-4 text-rose-300 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-circle-exclamation text-lg text-rose-400"></i>
                <span class="text-sm font-semibold"><?= htmlspecialchars($error_message) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-rose-400 hover:text-white cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <!-- Page Header & Action Bar -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-purple-400 mb-1">
                <span>Modul Keuangan</span>
                <span>•</span>
                <span>Fase 5: Kompensasi Guru & Staf</span>
            </div>
            <h1 class="text-2xl font-black text-white flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-tr from-purple-600 to-indigo-600 text-white shadow-lg shadow-purple-500/20">
                    <i class="fa-solid fa-wallet text-lg"></i>
                </span>
                Penggajian & Honor Pendidik
            </h1>
            <p class="text-xs text-slate-400 mt-1">
                Manajemen gaji pokok, tunjangan wali kelas, honor jurnal mengajar, potongan presensi, dan slip gaji digital.
            </p>
        </div>

        <?php if ($can_manage): ?>
            <div class="flex items-center gap-2 flex-wrap">
                <!-- Button Trigger Batch Generate Modal -->
                <button type="button" onclick="document.getElementById('modalGeneratePayroll').classList.remove('hidden')" class="rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-purple-500/25 hover:from-purple-500 hover:to-indigo-500 transition cursor-pointer flex items-center gap-2">
                    <i class="fa-solid fa-calculator"></i> Hitung Payroll Periode Ini
                </button>

                <?php if (($stats['count_approved'] ?? 0) > 0): ?>
                    <form method="POST" onsubmit="return confirm('Cairkan seluruh gaji berstatus Disetujui (<?= $stats['count_approved'] ?> guru) dan kirimkan notifikasi WhatsApp?');" class="inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="batch_pay">
                        <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-emerald-500/25 hover:bg-emerald-500 transition cursor-pointer flex items-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i> Cairkan Massal (<?= $stats['count_approved'] ?>)
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Summary Stats Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 shadow-lg backdrop-blur">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-400">Total Anggaran Periode</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400">
                    <i class="fa-solid fa-money-check-dollar"></i>
                </div>
            </div>
            <div class="mt-2 text-xl font-black text-white font-mono">
                Rp <?= number_format($stats['total_budget'] ?? 0, 0, ',', '.') ?>
            </div>
            <div class="mt-1 text-[11px] text-slate-500">
                <?= (int)($stats['total_records'] ?? 0) ?> Pengajar Terdata (<?= date('F Y', strtotime($filter_month . '-01')) ?>)
            </div>
        </div>

        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-950/20 p-5 shadow-lg backdrop-blur">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-emerald-300">Telah Dicairkan</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
            </div>
            <div class="mt-2 text-xl font-black text-emerald-400 font-mono">
                Rp <?= number_format($stats['total_paid'] ?? 0, 0, ',', '.') ?>
            </div>
            <div class="mt-1 text-[11px] text-emerald-400/70">
                <?= (int)($stats['count_paid'] ?? 0) ?> Guru Selesai Ditransfer
            </div>
        </div>

        <div class="rounded-2xl border border-amber-500/20 bg-amber-950/20 p-5 shadow-lg backdrop-blur">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-amber-300">Menunggu Pembayaran</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                    <i class="fa-solid fa-clock"></i>
                </div>
            </div>
            <div class="mt-2 text-xl font-black text-amber-400 font-mono">
                Rp <?= number_format($stats['total_pending'] ?? 0, 0, ',', '.') ?>
            </div>
            <div class="mt-1 text-[11px] text-amber-400/70">
                <?= (int)($stats['count_draft'] ?? 0) ?> Draft • <?= (int)($stats['count_approved'] ?? 0) ?> Disetujui
            </div>
        </div>

        <div class="rounded-2xl border border-purple-500/20 bg-purple-950/20 p-5 shadow-lg backdrop-blur">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-purple-300">Tarif Honor Standar</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-purple-500/20 text-purple-400">
                    <i class="fa-solid fa-sliders"></i>
                </div>
            </div>
            <div class="mt-2 text-sm font-bold text-white">
                Rp <?= number_format($teaching_rate_hour, 0, ',', '.') ?> <span class="text-xs font-normal text-slate-400">/ JP</span>
            </div>
            <div class="mt-1 text-[11px] text-slate-400">
                Wali Kelas: +Rp <?= number_format($homeroom_allowance, 0, ',', '.') ?>
            </div>
        </div>
    </div>

    <!-- Filters & Period Bar -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <form method="GET" class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <label class="text-xs font-semibold text-slate-400">Periode:</label>
                    <input type="month" name="month" value="<?= htmlspecialchars($filter_month) ?>" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none focus:border-purple-500">
                </div>

                <div class="flex items-center gap-2">
                    <label class="text-xs font-semibold text-slate-400">Status:</label>
                    <select name="status" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                        <option value="">Semua Status</option>
                        <option value="draft" <?= $filter_status === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="disetujui" <?= $filter_status === 'disetujui' ? 'selected' : '' ?>>Disetujui</option>
                        <option value="dibayar" <?= $filter_status === 'dibayar' ? 'selected' : '' ?>>Dibayar</option>
                    </select>
                </div>

                <?php if ($can_manage && !empty($teachers_dropdown)): ?>
                    <div class="flex items-center gap-2">
                        <label class="text-xs font-semibold text-slate-400">Guru:</label>
                        <select name="teacher_id" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                            <option value="">Semua Guru</option>
                            <?php foreach ($teachers_dropdown as $td): ?>
                                <option value="<?= $td['id'] ?>" <?= $filter_teacher == $td['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($td['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <button type="submit" class="rounded-xl bg-slate-800 px-4 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-700 transition cursor-pointer">
                    <i class="fa-solid fa-filter mr-1"></i> Terapkan
                </button>
            </div>

            <div class="text-xs text-slate-400">
                Menampilkan <strong class="text-white"><?= count($payrolls) ?></strong> data penggajian
            </div>
        </form>
    </div>

    <!-- Main Payroll Table -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/50 shadow-lg backdrop-blur overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-300">
                <thead class="bg-slate-950/60 text-xs uppercase font-semibold text-slate-400 border-b border-white/10">
                    <tr>
                        <th class="px-5 py-3.5">Kode & Periode</th>
                        <th class="px-5 py-3.5">Nama Pendidik</th>
                        <th class="px-5 py-3.5 text-right">Gaji Pokok</th>
                        <th class="px-5 py-3.5 text-right">Tunjangan</th>
                        <th class="px-5 py-3.5 text-center">Honor Jam Mengajar</th>
                        <th class="px-5 py-3.5 text-right">Potongan</th>
                        <th class="px-5 py-3.5 text-right">Gaji Bersih</th>
                        <th class="px-5 py-3.5 text-center">Status</th>
                        <th class="px-5 py-3.5 text-right">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($payrolls)): ?>
                        <tr>
                            <td colspan="9" class="px-5 py-12 text-center text-slate-500">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <i class="fa-solid fa-file-invoice-dollar text-4xl text-slate-600 mb-1"></i>
                                    <p class="font-medium">Belum ada data penggajian untuk filter periode ini.</p>
                                    <?php if ($can_manage): ?>
                                        <button onclick="document.getElementById('modalGeneratePayroll').classList.remove('hidden')" class="mt-2 text-xs font-semibold text-purple-400 hover:text-purple-300 underline">
                                            Klik di sini untuk menghitung payroll bulan ini
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payrolls as $p): ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <td class="px-5 py-4">
                                    <div class="font-mono text-xs font-bold text-purple-300"><?= htmlspecialchars($p['payroll_code']) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= date('F Y', strtotime($p['month_period'] . '-01')) ?></div>
                                </td>

                                <td class="px-5 py-4">
                                    <div class="font-bold text-white"><?= htmlspecialchars($p['teacher_name']) ?></div>
                                    <div class="flex items-center gap-1.5 mt-0.5 text-xs">
                                        <?php if (!empty($p['homeroom_class'])): ?>
                                            <span class="rounded bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 text-[10px] px-1.5 py-0.5 font-semibold">
                                                Wali <?= htmlspecialchars($p['homeroom_class']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-500">Guru Mapel</span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="px-5 py-4 text-right font-mono text-xs text-slate-300">
                                    <?= number_format($p['base_salary'], 0, ',', '.') ?>
                                </td>

                                <td class="px-5 py-4 text-right font-mono text-xs text-slate-300">
                                    <?= number_format($p['position_allowance'], 0, ',', '.') ?>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <div class="font-mono text-xs font-semibold text-white">
                                        Rp <?= number_format($p['total_teaching_honor'], 0, ',', '.') ?>
                                    </div>
                                    <div class="text-[10px] text-slate-500">
                                        <?= (int)$p['teaching_hours'] ?> Jam @ <?= number_format($p['teaching_rate_per_hour'], 0, ',', '.') ?>
                                    </div>
                                </td>

                                <td class="px-5 py-4 text-right font-mono text-xs text-rose-400">
                                    <?= $p['attendance_deductions'] > 0 ? '-' . number_format($p['attendance_deductions'], 0, ',', '.') : '0' ?>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <div class="font-mono text-sm font-black text-emerald-400">
                                        Rp <?= number_format($p['net_salary'], 0, ',', '.') ?>
                                    </div>
                                    <div class="text-[10px] text-slate-500">
                                        <?= htmlspecialchars($p['payment_method'] ?: 'Transfer Bank') ?>
                                    </div>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <span class="rounded-lg border px-2.5 py-1 text-xs font-semibold <?= getPayrollStatusBadge($p['status']) ?>">
                                        <?= getPayrollStatusLabel($p['status']) ?>
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-right">
                                    <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                        <!-- Cetak Slip Gaji (Seluruh Role yang Berhak) -->
                                        <a href="payroll_slip.php?id=<?= $p['id'] ?>" target="_blank" class="rounded-xl border border-white/10 bg-slate-800 px-2.5 py-1.5 text-xs font-semibold text-slate-300 hover:bg-slate-700 transition cursor-pointer inline-flex items-center gap-1" title="Cetak Slip Gaji">
                                            <i class="fa-solid fa-print"></i> Slip
                                        </a>

                                        <?php if ($can_manage): ?>
                                            <?php if ($p['status'] === 'draft'): ?>
                                                <!-- Tombol Edit / Penyesuaian -->
                                                <button type="button" onclick="openAdjustModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)" class="rounded-xl border border-white/10 bg-slate-800 px-2.5 py-1.5 text-xs font-semibold text-slate-300 hover:bg-slate-700 transition cursor-pointer" title="Sesuaikan Angka Manual">
                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                </button>

                                                <!-- Tombol Sinkron Ulang KBM & Presensi Otomatis -->
                                                <form method="POST" class="inline" onsubmit="return confirm('Sinkronkan ulang jam mengajar riil dari Jurnal KBM dan catatan Presensi Guru bulan ini?');">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="recalculate_single">
                                                    <input type="hidden" name="payroll_id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="rounded-xl border border-purple-500/30 bg-purple-500/10 hover:bg-purple-500/25 text-purple-300 px-2 py-1.5 text-xs font-semibold transition cursor-pointer" title="Sinkron Ulang KBM & Presensi">
                                                        <i class="fa-solid fa-rotate text-xs"></i>
                                                    </button>
                                                </form>

                                                <!-- Tombol Setujui -->
                                                <form method="POST" class="inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="payroll_id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="rounded-xl bg-blue-600 px-2.5 py-1.5 text-xs font-semibold text-white hover:bg-blue-500 transition cursor-pointer" title="Setujui Penggajian">
                                                        <i class="fa-solid fa-check"></i> Setujui
                                                    </button>
                                                </form>

                                                <!-- Tombol Hapus Draft -->
                                                <form method="POST" onsubmit="return confirm('Hapus draft penggajian ini?');" class="inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="delete_payroll">
                                                    <input type="hidden" name="payroll_id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="rounded-xl border border-rose-500/20 text-rose-400 hover:bg-rose-500/10 px-2 py-1.5 text-xs transition cursor-pointer" title="Hapus">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </form>

                                            <?php elseif ($p['status'] === 'disetujui'): ?>
                                                <!-- Tombol Cairkan / Bayar (dengan notifikasi WA) -->
                                                <form method="POST" onsubmit="return confirm('Cairkan gaji ini ke rekening guru dan kirimkan notifikasi resmi via WhatsApp Gateway?');" class="inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="pay">
                                                    <input type="hidden" name="payroll_id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white shadow-md shadow-emerald-500/20 hover:bg-emerald-500 transition cursor-pointer inline-flex items-center gap-1.5">
                                                        <i class="fa-brands fa-whatsapp text-sm"></i> Cairkan & WA
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-[11px] text-emerald-400 font-mono">
                                                    <i class="fa-solid fa-check-double mr-0.5"></i> <?= date('d/m/Y', strtotime($p['payment_date'] ?? 'now')) ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal Batch Generate Payroll (Admin / Staf) -->
<?php if ($can_manage): ?>
<div id="modalGeneratePayroll" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm hidden">
    <div class="w-full max-w-md rounded-2xl border border-white/10 bg-slate-900 p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-calculator text-purple-400"></i> Hitung Payroll Otomatis
            </h3>
            <button onclick="document.getElementById('modalGeneratePayroll').classList.add('hidden')" class="rounded-lg p-1 text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="generate_batch">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Periode Bulan Penggajian</label>
                <input type="month" name="month_period" value="<?= htmlspecialchars($filter_month) ?>" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-sm text-white focus:outline-none focus:border-purple-500">
            </div>

            <div class="rounded-xl border border-purple-500/20 bg-purple-950/20 p-3.5 text-xs text-purple-200 space-y-2">
                <div class="font-bold flex items-center gap-1.5 text-purple-300">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Logika Kalkulasi Otomatis:
                </div>
                <ul class="list-disc list-inside space-y-1 text-purple-200/90 text-[11px]">
                    <li><strong>Gaji Pokok:</strong> Standar Rp <?= number_format($base_salary_default, 0, ',', '.') ?>.</li>
                    <li><strong>Tunjangan Wali Kelas:</strong> Otomatis ditambahkan Rp <?= number_format($homeroom_allowance, 0, ',', '.') ?> jika guru ditugaskan sebagai wali kelas aktif.</li>
                    <li><strong>Honor Mengajar:</strong> Mengakumulasikan seluruh entri Jurnal Mengajar (2 JP/entri) dikali tarif Rp <?= number_format($teaching_rate_hour, 0, ',', '.') ?>/jam.</li>
                    <li><strong>Potongan Presensi:</strong> Pemotongan otomatis Rp 50.000 untuk setiap ketidakhadiran tanpa izin atau presensi di luar radius GPS sekolah.</li>
                </ul>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalGeneratePayroll').classList.add('hidden')" class="rounded-xl border border-white/10 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/5 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-purple-600 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-purple-500/25 hover:bg-purple-500 cursor-pointer flex items-center gap-1.5">
                    <i class="fa-solid fa-play"></i> Mulai Kalkulasi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Penyesuaian Manual (Adjust Modal) -->
<div id="modalAdjustPayroll" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm hidden">
    <div class="w-full max-w-lg rounded-2xl border border-white/10 bg-slate-900 p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-sliders text-blue-400"></i> Penyesuaian Rincian Payroll
            </h3>
            <button onclick="document.getElementById('modalAdjustPayroll').classList.add('hidden')" class="rounded-lg p-1 text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="adjust_payroll">
            <input type="hidden" name="payroll_id" id="adj_payroll_id" value="">

            <div>
                <label class="block text-xs font-semibold text-slate-400 mb-1">Guru Pendidik</label>
                <div id="adj_teacher_name" class="text-sm font-bold text-white"></div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Gaji Pokok (Rp)</label>
                    <input type="number" name="base_salary" id="adj_base_salary" step="1000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tunjangan Jabatan (Rp)</label>
                    <input type="number" name="position_allowance" id="adj_pos_allowance" step="1000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:outline-none font-mono">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Jam Mengajar (JP)</label>
                    <input type="number" name="teaching_hours" id="adj_teaching_hours" min="0" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tarif per Jam (Rp)</label>
                    <input type="number" name="teaching_rate_per_hour" id="adj_teaching_rate" step="1000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:outline-none font-mono">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Potongan Presensi (Rp)</label>
                    <input type="number" name="attendance_deductions" id="adj_deductions" step="1000" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-rose-300 focus:outline-none font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Metode Pembayaran</label>
                    <input type="text" name="payment_method" id="adj_method" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Catatan Tambahan</label>
                <input type="text" name="notes" id="adj_notes" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:outline-none">
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalAdjustPayroll').classList.add('hidden')" class="rounded-xl border border-white/10 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/5 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 cursor-pointer">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openAdjustModal(p) {
    document.getElementById('adj_payroll_id').value = p.id;
    document.getElementById('adj_teacher_name').innerText = p.teacher_name + ' (' + p.payroll_code + ')';
    document.getElementById('adj_base_salary').value = p.base_salary;
    document.getElementById('adj_pos_allowance').value = p.position_allowance;
    document.getElementById('adj_teaching_hours').value = p.teaching_hours;
    document.getElementById('adj_teaching_rate').value = p.teaching_rate_per_hour;
    document.getElementById('adj_deductions').value = p.attendance_deductions;
    document.getElementById('adj_method').value = p.payment_method || 'Transfer Bank';
    document.getElementById('adj_notes').value = p.notes || '';

    document.getElementById('modalAdjustPayroll').classList.remove('hidden');
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
