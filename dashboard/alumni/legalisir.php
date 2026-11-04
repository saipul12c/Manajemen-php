<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$is_staff = in_array($user_role, ['staf', 'administrator', 'guru'], true);
$school = getSchoolSettings($pdo);

$message = "";
$message_type = "";

// Buat direktori upload berkas legalisir jika belum ada
$upload_legalisir_dir = __DIR__ . "/../../uploads/legalisir";
if (!is_dir($upload_legalisir_dir)) {
    mkdir($upload_legalisir_dir, 0755, true);
}

// -------------------------------------------------------------
// 1. AJUKAN PERMOHONAN LEGALISIR BARU (Alumni, Siswa, Ortu)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_legalisir') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid. Silakan coba kembali.";
        $message_type = "error";
    } else {
        $alumni_name = trim($_POST['alumni_name'] ?? '');
        $nisn = trim($_POST['nisn'] ?? '');
        $graduation_year = trim($_POST['graduation_year'] ?? '');
        $document_type = trim($_POST['document_type'] ?? 'ijazah');
        $document_number = trim($_POST['document_number'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $contact_whatsapp = trim($_POST['contact_whatsapp'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');
        $file_original = null;

        if (empty($alumni_name) || empty($document_number) || empty($purpose)) {
            $message = "Nama pemohon, nomor dokumen, dan keperluan wajib diisi.";
            $message_type = "error";
        } else {
            // Upload Dokumen Scan Asli
            if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                $file_tmp  = $_FILES['document_file']['tmp_name'];
                $file_name = $_FILES['document_file']['name'];
                $file_size = $_FILES['document_file']['size'];
                $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                $allowed_ext   = ['pdf', 'jpg', 'jpeg', 'png'];
                $allowed_mimes = ['application/pdf', 'image/jpeg', 'image/png'];

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $detected_mime = finfo_file($finfo, $file_tmp);
                finfo_close($finfo);

                if (!in_array($file_ext, $allowed_ext, true) || !in_array($detected_mime, $allowed_mimes, true)) {
                    $message = "Format berkas tidak valid. Harap unggah berkas scan JPG, PNG, atau PDF asli.";
                    $message_type = "error";
                } elseif ($file_size > 12 * 1024 * 1024) {
                    $message = "Ukuran file berkas melebihi batas maksimal 12MB.";
                    $message_type = "error";
                } else {
                    $new_filename = 'leg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
                    $dest_path = $upload_legalisir_dir . '/' . $new_filename;
                    if (move_uploaded_file($file_tmp, $dest_path)) {
                        $file_original = 'uploads/legalisir/' . $new_filename;
                    }
                }
            }

            if ($message_type !== 'error') {
                if (empty($file_original)) {
                    $message = "Scan dokumen asli (Ijazah/SKL/Transkrip) wajib diunggah untuk verifikasi keabsahan.";
                    $message_type = "error";
                } else {
                    // Generate Unique Legalized Tracking Code: LEG-YYYYMM-RAND
                    $legalized_code = 'LEG-' . date('Ym') . '-' . strtoupper(bin2hex(random_bytes(3)));

                    $stmt_in = $pdo->prepare("
                        INSERT INTO legalisir_requests 
                        (user_id, alumni_name, nisn, graduation_year, document_type, document_number, purpose, contact_whatsapp, contact_email, file_original, legalized_code, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'menunggu')
                    ");
                    $stmt_in->execute([
                        $user_id, $alumni_name, $nisn ?: null, $graduation_year ?: null, 
                        $document_type, $document_number, $purpose, $contact_whatsapp ?: null, 
                        $contact_email ?: null, $file_original, $legalized_code
                    ]);

                    logActivity($pdo, 'SUBMIT_LEGALISIR', "Mengajukan legalisir digital $document_type no. $document_number (Kode: $legalized_code)");
                    $message = "Permohonan legalisir digital berhasil diajukan dengan Kode Registrasi: <strong>$legalized_code</strong>. Tim Tata Usaha akan segera memverifikasi kesesuaian berkas dengan buku induk.";
                    $message_type = "success";
                }
            }
        }
    }
}

// -------------------------------------------------------------
// 2. STAF / ADMIN: PROSES APPROVAL / REJECTION LEGALISIR
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve_legalisir', 'reject_legalisir'], true) && $is_staff) {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $req_id = (int)($_POST['request_id'] ?? 0);
        $stmt_chk = $pdo->prepare("SELECT * FROM legalisir_requests WHERE id = ?");
        $stmt_chk->execute([$req_id]);
        $req_data = $stmt_chk->fetch();

        if (!$req_data) {
            $message = "Data permohonan legalisir tidak ditemukan.";
            $message_type = "error";
        } else {
            if ($_POST['action'] === 'approve_legalisir') {
                $stmt_app = $pdo->prepare("
                    UPDATE legalisir_requests 
                    SET status = 'disetujui', processed_by = ?, verified_at = NOW(), rejection_reason = NULL 
                    WHERE id = ?
                ");
                $stmt_app->execute([$user_id, $req_id]);

                logActivity($pdo, 'APPROVE_LEGALISIR', "Menyetujui legalisir digital ID $req_id (Kode: {$req_data['legalized_code']})");

                // Notifikasi WhatsApp jika ada kontak telepon
                if (!empty($req_data['contact_whatsapp'])) {
                    $proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $base_dir = rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/\\');
                    $root_path = preg_replace('/\/dashboard.*$/', '', $base_dir);
                    $verify_link = "{$proto}://{$host}{$root_path}/verify_document.php?doc_type=LEGALISIR&doc_num=" . urlencode($req_data['legalized_code']);
                    $wa_msg = "Halo Kak " . $req_data['alumni_name'] . ",\n\nPermohonan Legalisir Digital Dokumen " . strtoupper($req_data['document_type']) . " Anda dengan nomor registrasi *" . $req_data['legalized_code'] . "* telah *DISETUJUI & DISAHKAN* oleh Kepala Sekolah " . $school['school_name'] . ".\n\nDokumen resmi ber-watermark dan TTE sah dapat diakses melalui tautan berikut:\n" . $verify_link . "\n\nTerima kasih.\nTata Usaha " . $school['school_name'];
                    sendWhatsAppNotification($pdo, $req_data['contact_whatsapp'], $req_data['alumni_name'], $wa_msg, 'persuratan');
                }

                $message = "Permohonan legalisir berhasil disahkan dengan TTE Kepala Sekolah!";
                $message_type = "success";

            } elseif ($_POST['action'] === 'reject_legalisir') {
                $reason = trim($_POST['rejection_reason'] ?? 'Berkas scan tidak terbaca atau tidak sesuai dengan data arsip.');
                $stmt_rej = $pdo->prepare("
                    UPDATE legalisir_requests 
                    SET status = 'ditolak', processed_by = ?, verified_at = NOW(), rejection_reason = ? 
                    WHERE id = ?
                ");
                $stmt_rej->execute([$user_id, $reason, $req_id]);

                logActivity($pdo, 'REJECT_LEGALISIR', "Menolak legalisir digital ID $req_id: $reason");

                if (!empty($req_data['contact_whatsapp'])) {
                    $wa_msg = "Halo Kak " . $req_data['alumni_name'] . ",\n\nMohon maaf, permohonan legalisir dokumen *" . strtoupper($req_data['document_type']) . "* Anda dengan nomor registrasi *" . $req_data['legalized_code'] . "* belum dapat disahkan.\n\nAlasan penolakan:\n\"" . $reason . "\"\n\nSilakan ajukan permohonan ulang dengan berkas perbaikan melalui portal sekolah.\n\nTata Usaha " . $school['school_name'];
                    sendWhatsAppNotification($pdo, $req_data['contact_whatsapp'], $req_data['alumni_name'], $wa_msg, 'persuratan');
                }

                $message = "Permohonan legalisir telah ditolak dengan catatan.";
                $message_type = "info";
            }
        }
    }
}

// -------------------------------------------------------------
// 3. AMBIL DATA DAFTAR LEGALISIR & STATISTIK
// -------------------------------------------------------------
$filter_status = $_GET['status'] ?? 'all';
$search_query = trim($_GET['q'] ?? '');

$sql = "
    SELECT r.*, u.name as submitter_user_name, u.email as submitter_email,
           p.name as processor_name
    FROM legalisir_requests r
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN users p ON r.processed_by = p.id
    WHERE 1=1
";
$params = [];

if (!$is_staff) {
    // Siswa / Alumni hanya melihat miliknya sendiri
    $sql .= " AND r.user_id = ?";
    $params[] = $user_id;
}

if ($filter_status !== 'all' && in_array($filter_status, ['menunggu', 'diverifikasi', 'disetujui', 'ditolak'], true)) {
    $sql .= " AND r.status = ?";
    $params[] = $filter_status;
}

if (!empty($search_query)) {
    $sql .= " AND (r.alumni_name LIKE ? OR r.legalized_code LIKE ? OR r.document_number LIKE ? OR r.nisn LIKE ?)";
    $kw = "%$search_query%";
    $params = array_merge($params, [$kw, $kw, $kw, $kw]);
}

$sql .= " ORDER BY r.created_at DESC";
$stmt_list = $pdo->prepare($sql);
$stmt_list->execute($params);
$requests_list = $stmt_list->fetchAll();

// Statistik Ringkas
$stats_user_filter = $is_staff ? "" : " WHERE user_id = " . (int)$user_id;
$stats_total = (int) $pdo->query("SELECT COUNT(*) FROM legalisir_requests" . $stats_user_filter)->fetchColumn();
$stats_pending = (int) $pdo->query("SELECT COUNT(*) FROM legalisir_requests " . ($is_staff ? "WHERE status = 'menunggu'" : "WHERE user_id = $user_id AND status = 'menunggu'"))->fetchColumn();
$stats_approved = (int) $pdo->query("SELECT COUNT(*) FROM legalisir_requests " . ($is_staff ? "WHERE status = 'disetujui'" : "WHERE user_id = $user_id AND status = 'disetujui'"))->fetchColumn();
$stats_rejected = (int) $pdo->query("SELECT COUNT(*) FROM legalisir_requests " . ($is_staff ? "WHERE status = 'ditolak'" : "WHERE user_id = $user_id AND status = 'ditolak'"))->fetchColumn();

// Auto-fill profil bagi siswa/alumni yang sedang login
$current_user_nisn = '';
$current_user_name = '';
if (!$is_staff) {
    $stmt_uinfo = $pdo->prepare("SELECT name, nisn, phone, email FROM users WHERE id = ?");
    $stmt_uinfo->execute([$user_id]);
    $uinfo = $stmt_uinfo->fetch();
    $current_user_name = $uinfo['name'] ?? '';
    $current_user_nisn = $uinfo['nisn'] ?? '';
    $current_user_phone = $uinfo['phone'] ?? '';
    $current_user_email = $uinfo['email'] ?? '';
}

$page_title = "Portal Legalisir Digital Dokumen Alumni";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Header Action Bar -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-tr from-emerald-600 to-teal-500 text-white text-xl shadow-lg shadow-emerald-500/20 border border-emerald-400/30">
                    <i class="fa-solid fa-stamp"></i>
                </span>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                        Portal Legalisir Digital Alumni
                        <span class="rounded-full bg-emerald-500/20 border border-emerald-500/30 px-2.5 py-0.5 text-[10px] font-bold uppercase text-emerald-300">
                            TTE Resmi Kepsek
                        </span>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-400">
                        Layanan mandiri pengesahan digital Ijazah, SKL, dan Transkrip Nilai dengan watermark sah & QR Code validasi keaslian.
                    </p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button onclick="document.getElementById('modalNewLegalisir').classList.remove('hidden')" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-emerald-600/25 hover:from-emerald-500 hover:to-teal-500 transition cursor-pointer">
                <i class="fa-solid fa-file-circle-plus"></i> Ajukan Legalisir Baru
            </button>
            <a href="../../verify_document.php" target="_blank" class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-slate-800/80 px-3.5 py-2.5 text-xs font-semibold text-slate-200 hover:bg-slate-700 transition">
                <i class="fa-solid fa-shield-halved text-cyan-400"></i> Portal Verifikasi Publik
            </a>
        </div>
    </div>

    <!-- Alert Notifikasi -->
    <?php if (!empty($message)): ?>
        <div class="rounded-2xl border <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200' : ($message_type === 'info' ? 'border-amber-500/30 bg-amber-500/10 text-amber-200' : 'border-rose-500/30 bg-rose-500/10 text-rose-200') ?> p-4 text-xs sm:text-sm flex items-start gap-3 backdrop-blur shadow-lg">
            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : ($message_type === 'info' ? 'fa-circle-info text-amber-400' : 'fa-circle-exclamation text-rose-400') ?> text-lg shrink-0 mt-0.5"></i>
            <div class="flex-1 leading-relaxed">
                <?= $message ?>
            </div>
            <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- Kartu Statistik Metrik -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur">
            <div class="flex items-center justify-between text-slate-400 mb-2">
                <span class="text-xs font-medium uppercase tracking-wider">Total Permohonan</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400 text-sm">
                    <i class="fa-solid fa-folder-open"></i>
                </span>
            </div>
            <p class="text-2xl font-black text-white"><?= number_format($stats_total) ?></p>
            <p class="text-[11px] text-slate-500 mt-1">Berkas diajukan ke sistem</p>
        </div>

        <div class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4 backdrop-blur">
            <div class="flex items-center justify-between text-amber-300 mb-2">
                <span class="text-xs font-medium uppercase tracking-wider">Menunggu Verifikasi</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400 text-sm">
                    <i class="fa-solid fa-clock"></i>
                </span>
            </div>
            <p class="text-2xl font-black text-amber-300"><?= number_format($stats_pending) ?></p>
            <p class="text-[11px] text-amber-400/70 mt-1">Butuh review petugas TU</p>
        </div>

        <div class="rounded-2xl border border-emerald-500/20 bg-emerald-500/5 p-4 backdrop-blur">
            <div class="flex items-center justify-between text-emerald-300 mb-2">
                <span class="text-xs font-medium uppercase tracking-wider">Sah Dilegalisir</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400 text-sm">
                    <i class="fa-solid fa-stamp"></i>
                </span>
            </div>
            <p class="text-2xl font-black text-emerald-300"><?= number_format($stats_approved) ?></p>
            <p class="text-[11px] text-emerald-400/70 mt-1">TTE Kepala Sekolah aktif</p>
        </div>

        <div class="rounded-2xl border border-rose-500/20 bg-rose-500/5 p-4 backdrop-blur">
            <div class="flex items-center justify-between text-rose-300 mb-2">
                <span class="text-xs font-medium uppercase tracking-wider">Ditolak / Koreksi</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-rose-500/20 text-rose-400 text-sm">
                    <i class="fa-solid fa-ban"></i>
                </span>
            </div>
            <p class="text-2xl font-black text-rose-300"><?= number_format($stats_rejected) ?></p>
            <p class="text-[11px] text-rose-400/70 mt-1">Perlu perbaikan berkas</p>
        </div>
    </div>

    <!-- Filter & Toolbar Pencarian -->
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur flex flex-col md:flex-row md:items-center justify-between gap-4">
        
        <!-- Tab Status Filter -->
        <div class="flex items-center gap-1 overflow-x-auto pb-1 md:pb-0">
            <?php
            $tab_options = [
                'all' => ['label' => 'Semua Berkas', 'icon' => 'fa-list'],
                'menunggu' => ['label' => 'Menunggu', 'icon' => 'fa-clock'],
                'disetujui' => ['label' => 'Disetujui Sah', 'icon' => 'fa-circle-check'],
                'ditolak' => ['label' => 'Ditolak', 'icon' => 'fa-circle-xmark'],
            ];
            foreach ($tab_options as $t_key => $t_val):
                $is_active = ($filter_status === $t_key);
            ?>
                <a href="legalisir.php?status=<?= $t_key ?><?= !empty($search_query) ? '&q=' . urlencode($search_query) : '' ?>" 
                   class="inline-flex items-center gap-2 rounded-xl px-3 py-1.5 text-xs font-semibold whitespace-nowrap transition <?= $is_active ? 'bg-blue-600 text-white shadow-md shadow-blue-600/30' : 'text-slate-400 hover:text-white hover:bg-white/5' ?>">
                    <i class="fa-solid <?= $t_val['icon'] ?>"></i>
                    <?= $t_val['label'] ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Search Input -->
        <form method="GET" class="relative max-w-sm w-full">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
            <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>" placeholder="Cari nama, NISN, atau kode legalisir..." 
                   class="w-full rounded-xl border border-white/10 bg-slate-950/80 pl-9 pr-8 py-2 text-xs text-white placeholder:text-slate-500 focus:border-blue-500 focus:outline-none">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-500"></i>
            <?php if (!empty($search_query)): ?>
                <a href="legalisir.php?status=<?= htmlspecialchars($filter_status) ?>" class="absolute right-3 top-2.5 text-xs text-slate-400 hover:text-white">
                    <i class="fa-solid fa-xmark"></i>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabel Daftar Pengajuan Legalisir -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/60 overflow-hidden shadow-2xl backdrop-blur">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="border-b border-white/10 bg-slate-950/60 text-[11px] font-bold uppercase tracking-wider text-slate-400">
                    <tr>
                        <th class="py-3.5 px-4">No. Registrasi / Tanggal</th>
                        <th class="py-3.5 px-4">Nama Pemohon (Alumni)</th>
                        <th class="py-3.5 px-4">Jenis Dokumen</th>
                        <th class="py-3.5 px-4">Keperluan & Kontak</th>
                        <th class="py-3.5 px-4">Status & Pengesahan</th>
                        <th class="py-3.5 px-4 text-right">Aksi & Berkas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 font-sans">
                    <?php if (empty($requests_list)): ?>
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-500">
                                <div class="flex flex-col items-center justify-center gap-2">
                                    <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/5 text-2xl text-slate-600">
                                        <i class="fa-regular fa-folder-open"></i>
                                    </span>
                                    <p class="text-sm font-semibold text-slate-400">Belum ada permohonan legalisir digital.</p>
                                    <p class="text-xs text-slate-600 max-w-sm">Silakan klik tombol "Ajukan Legalisir Baru" untuk memulai permohonan pengesahan digital dokumen alumni Anda.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests_list as $row): 
                            $doc_badge_class = [
                                'ijazah' => 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30',
                                'skl' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
                                'transkrip' => 'bg-amber-500/20 text-amber-300 border-amber-500/30',
                                'rapor' => 'bg-cyan-500/20 text-cyan-300 border-cyan-500/30',
                            ][$row['document_type']] ?? 'bg-slate-500/20 text-slate-300 border-slate-500/30';

                            $status_badge = [
                                'menunggu' => 'bg-amber-500/15 text-amber-300 border-amber-500/30',
                                'diverifikasi' => 'bg-blue-500/15 text-blue-300 border-blue-500/30',
                                'disetujui' => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
                                'ditolak' => 'bg-rose-500/15 text-rose-300 border-rose-500/30',
                            ][$row['status']] ?? 'bg-slate-500/15 text-slate-300 border-slate-500/30';
                        ?>
                            <tr class="hover:bg-white/[0.02] transition">
                                <!-- No Registrasi -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="font-mono font-bold text-white text-xs block text-cyan-300">
                                        <?= htmlspecialchars($row['legalized_code']) ?>
                                    </span>
                                    <span class="text-[10px] text-slate-500 font-mono">
                                        <?= date('d M Y, H:i', strtotime($row['created_at'])) ?> WIB
                                    </span>
                                </td>

                                <!-- Nama Pemohon -->
                                <td class="py-3.5 px-4">
                                    <span class="font-bold text-white text-xs block truncate max-w-[180px]">
                                        <?= htmlspecialchars($row['alumni_name']) ?>
                                    </span>
                                    <span class="text-[10px] text-slate-400 block font-mono">
                                        NISN: <?= htmlspecialchars($row['nisn'] ?: '-') ?> <?= $row['graduation_year'] ? ' | Lulus ' . htmlspecialchars($row['graduation_year']) : '' ?>
                                    </span>
                                </td>

                                <!-- Jenis Dokumen -->
                                <td class="py-3.5 px-4">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider border <?= $doc_badge_class ?>">
                                        <?= strtoupper(htmlspecialchars($row['document_type'])) ?>
                                    </span>
                                    <span class="text-[10px] text-slate-400 block font-mono mt-1 truncate max-w-[160px]" title="Nomor Dokumen Asli">
                                        No: <?= htmlspecialchars($row['document_number']) ?>
                                    </span>
                                </td>

                                <!-- Keperluan & Kontak -->
                                <td class="py-3.5 px-4">
                                    <span class="text-xs text-slate-300 block line-clamp-1 max-w-[200px]" title="<?= htmlspecialchars($row['purpose']) ?>">
                                        <?= htmlspecialchars($row['purpose']) ?>
                                    </span>
                                    <span class="text-[10px] text-slate-400 font-mono flex items-center gap-2 mt-0.5">
                                        <?php if (!empty($row['contact_whatsapp'])): ?>
                                            <span class="text-emerald-400"><i class="fa-brands fa-whatsapp"></i> <?= htmlspecialchars($row['contact_whatsapp']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </td>

                                <!-- Status -->
                                <td class="py-3.5 px-4 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider border <?= $status_badge ?>">
                                        <?php if ($row['status'] === 'disetujui'): ?>
                                            <i class="fa-solid fa-circle-check text-[9px]"></i> Sah Terlegalisir
                                        <?php elseif ($row['status'] === 'menunggu'): ?>
                                            <i class="fa-solid fa-clock text-[9px]"></i> Menunggu
                                        <?php elseif ($row['status'] === 'ditolak'): ?>
                                            <i class="fa-solid fa-circle-xmark text-[9px]"></i> Ditolak
                                        <?php else: ?>
                                            <?= ucfirst($row['status']) ?>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($row['status'] === 'disetujui' && $row['verified_at']): ?>
                                        <span class="text-[9px] text-slate-400 block mt-0.5 font-mono">
                                            Disahkan: <?= date('d/m/Y', strtotime($row['verified_at'])) ?>
                                        </span>
                                    <?php elseif ($row['status'] === 'ditolak' && $row['rejection_reason']): ?>
                                        <span class="text-[9px] text-rose-400 block mt-0.5 truncate max-w-[180px]" title="<?= htmlspecialchars($row['rejection_reason']) ?>">
                                            Alasan: <?= htmlspecialchars($row['rejection_reason']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Aksi -->
                                <td class="py-3.5 px-4 text-right whitespace-nowrap space-x-1">
                                    <!-- Lihat Berkas Asli Scan -->
                                    <?php if (!empty($row['file_original'])): ?>
                                        <a href="../../<?= htmlspecialchars($row['file_original']) ?>" target="_blank" class="inline-flex items-center justify-center h-8 w-8 rounded-xl bg-slate-800 text-slate-300 hover:text-white hover:bg-slate-700 transition" title="Lihat Berkas Scan Asli">
                                            <i class="fa-solid fa-file-lines text-xs"></i>
                                        </a>
                                    <?php endif; ?>

                                    <!-- Tombol Cetak Dokumen Legalisir Sah -->
                                    <?php if ($row['status'] === 'disetujui'): ?>
                                        <a href="legalisir_print.php?id=<?= $row['id'] ?>" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600/20 border border-emerald-500/40 px-2.5 py-1.5 text-xs font-bold text-emerald-300 hover:bg-emerald-600/30 transition shadow-sm" title="Buka & Cetak Dokumen Legalisir Resmi">
                                            <i class="fa-solid fa-stamp text-xs"></i> Cetak Legalisir
                                        </a>
                                    <?php endif; ?>

                                    <!-- Aksi Staf: Review & Approval / Rejection -->
                                    <?php if ($is_staff && $row['status'] === 'menunggu'): ?>
                                        <button onclick="openApproveModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['alumni_name'])) ?>', '<?= htmlspecialchars(addslashes($row['legalized_code'])) ?>', '<?= strtoupper($row['document_type']) ?>')" class="inline-flex items-center gap-1 rounded-xl bg-emerald-600 px-2.5 py-1.5 text-xs font-bold text-white hover:bg-emerald-500 transition cursor-pointer shadow-sm shadow-emerald-600/30" title="Sahkan Dokumen dengan TTE Kepala Sekolah">
                                            <i class="fa-solid fa-check"></i> Sahkan TTE
                                        </button>
                                        <button onclick="openRejectModal(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['alumni_name'])) ?>', '<?= htmlspecialchars(addslashes($row['legalized_code'])) ?>')" class="inline-flex items-center justify-center h-8 w-8 rounded-xl bg-rose-600/20 text-rose-300 border border-rose-500/30 hover:bg-rose-600/30 transition cursor-pointer" title="Tolak Berkas">
                                            <i class="fa-solid fa-xmark text-xs"></i>
                                        </button>
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

<!-- ========================================================= -->
<!-- MODAL: AJUKAN PERMOHONAN LEGALISIR BARU                   -->
<!-- ========================================================= -->
<div id="modalNewLegalisir" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm p-4 overflow-y-auto">
    <div class="relative w-full max-w-xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl my-8">
        
        <div class="flex items-center justify-between border-b border-white/10 pb-4 mb-6">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-emerald-600 text-white text-lg shadow-md shadow-emerald-600/30">
                    <i class="fa-solid fa-stamp"></i>
                </span>
                <div>
                    <h3 class="text-lg font-bold text-white">Form Permohonan Legalisir Digital</h3>
                    <p class="text-xs text-slate-400">Pengesahan Ijazah, SKL, atau Transkrip Resmi</p>
                </div>
            </div>
            <button onclick="document.getElementById('modalNewLegalisir').classList.add('hidden')" class="text-slate-400 hover:text-white p-1 rounded-lg transition">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="submit_legalisir">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Nama Lengkap Alumni / Pemohon <span class="text-rose-400">*</span></label>
                    <input type="text" name="alumni_name" value="<?= htmlspecialchars($current_user_name) ?>" required placeholder="Nama sesuai ijazah asli" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">NISN / No. Induk</label>
                    <input type="text" name="nisn" value="<?= htmlspecialchars($current_user_nisn) ?>" placeholder="Contoh: 0051234567" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Jenis Dokumen <span class="text-rose-400">*</span></label>
                    <select name="document_type" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                        <option value="ijazah">Ijazah Asli</option>
                        <option value="skl">Surat Keterangan Lulus (SKL)</option>
                        <option value="transkrip">Transkrip Nilai Akademik</option>
                        <option value="rapor">Buku Rapor Lengkap</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Tahun Kelulusan</label>
                    <input type="number" name="graduation_year" min="1980" max="<?= date('Y') ?>" value="<?= date('Y') ?>" placeholder="Contoh: <?= date('Y') ?>" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Nomor Seri Dokumen Asli <span class="text-rose-400">*</span></label>
                <input type="text" name="document_number" required placeholder="Contoh: DN-01/M-SMK/13/0012345 atau 421.3/SKL/045" 
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none font-mono">
                <p class="text-[10px] text-slate-500 mt-1">Periksa nomor seri yang tercetak di sudut bawah atau kop dokumen asli Anda.</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Keperluan Legalisir <span class="text-rose-400">*</span></label>
                <input type="text" name="purpose" required placeholder="Contoh: Seleksi Mandiri PTN, Melamar Pekerjaan BUMN, Seleksi TNI-POLRI" 
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">No. WhatsApp Pemohon <span class="text-emerald-400">*</span></label>
                    <input type="text" name="contact_whatsapp" value="<?= htmlspecialchars($current_user_phone ?? '') ?>" required placeholder="Contoh: 081234567890" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                    <p class="text-[10px] text-slate-500 mt-0.5">Notifikasi & tautan berkas legalisir akan dikirimkan via WhatsApp.</p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">Email Pemohon</label>
                    <input type="email" name="contact_email" value="<?= htmlspecialchars($current_user_email ?? '') ?>" placeholder="email@contoh.com" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Unggah Berkas Scan Dokumen Asli <span class="text-rose-400">*</span></label>
                <input type="file" name="document_file" required accept=".pdf, .jpg, .jpeg, .png" 
                       class="w-full text-xs text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-600/20 file:text-blue-300 hover:file:bg-blue-600/30 cursor-pointer">
                <p class="text-[10px] text-slate-500 mt-1">Pastikan scan dokumen jelas, tidak terpotong, dan teks nomor seri terbaca. Format JPG/PNG/PDF (Maks 12MB).</p>
            </div>

            <div class="pt-4 flex items-center justify-end gap-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalNewLegalisir').classList.add('hidden')" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-400 hover:text-white hover:bg-white/5 transition">
                    Batal
                </button>
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-emerald-600/30 hover:from-emerald-500 hover:to-teal-500 transition cursor-pointer">
                    <i class="fa-solid fa-paper-plane"></i> Kirim Permohonan
                </button>
            </div>
        </form>

    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL: APPROVE LEGALISIR (STAF / ADMIN)                   -->
<!-- ========================================================= -->
<div id="modalApprove" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm p-4">
    <div class="relative w-full max-w-md rounded-3xl border border-emerald-500/30 bg-slate-900 p-6 shadow-2xl">
        <div class="flex items-center gap-3 mb-4">
            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-500/20 text-emerald-400 text-xl border border-emerald-500/30">
                <i class="fa-solid fa-shield-check"></i>
            </span>
            <div>
                <h3 class="text-base font-bold text-white">Sahkan Legalisir Digital</h3>
                <p class="text-xs text-slate-400" id="appTargetSub"></p>
            </div>
        </div>

        <p class="text-xs text-slate-300 leading-relaxed mb-4">
            Dengan menyetujui, sistem akan membubuhkan **Watermark Pengesahan**, **TTE Kepala Sekolah**, dan **Stempel Sah Lembaga** pada berkas dokumen ini secara elektronik. Tautan verifikasi publik juga akan otomatis dikirimkan ke WhatsApp pemohon.
        </p>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="approve_legalisir">
            <input type="hidden" name="request_id" id="approveReqId" value="">

            <div class="p-3 rounded-2xl bg-slate-950/80 border border-white/5 text-xs space-y-1">
                <div class="flex justify-between">
                    <span class="text-slate-400">Kode Registrasi:</span>
                    <strong class="text-cyan-300 font-mono" id="appCodeVal"></strong>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-400">Pejabat Pengesah:</span>
                    <strong class="text-white"><?= htmlspecialchars($school['headmaster_name']) ?></strong>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('modalApprove').classList.add('hidden')" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-400 hover:text-white transition">
                    Batal
                </button>
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-500 transition cursor-pointer shadow-lg shadow-emerald-600/30">
                    <i class="fa-solid fa-signature"></i> Bubuhkan Pengesahan Sah
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL: REJECT LEGALISIR (STAF / ADMIN)                    -->
<!-- ========================================================= -->
<div id="modalReject" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm p-4">
    <div class="relative w-full max-w-md rounded-3xl border border-rose-500/30 bg-slate-900 p-6 shadow-2xl">
        <div class="flex items-center gap-3 mb-4">
            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-rose-500/20 text-rose-400 text-xl border border-rose-500/30">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </span>
            <div>
                <h3 class="text-base font-bold text-white">Tolak Permohonan Legalisir</h3>
                <p class="text-xs text-slate-400" id="rejTargetSub"></p>
            </div>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="action" value="reject_legalisir">
            <input type="hidden" name="request_id" id="rejectReqId" value="">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1.5">Alasan Penolakan / Catatan Perbaikan <span class="text-rose-400">*</span></label>
                <textarea name="rejection_reason" rows="3" required placeholder="Contoh: Berkas scan buram dan nomor ijazah tidak terbaca. Mohon unggah scan asli beresolusi tinggi." 
                          class="w-full rounded-xl border border-white/10 bg-slate-950 p-3 text-xs text-white focus:border-rose-500 focus:outline-none"></textarea>
                <p class="text-[10px] text-slate-500 mt-1">Alasan ini akan dikirimkan langsung ke nomor WhatsApp pemohon.</p>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('modalReject').classList.add('hidden')" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-400 hover:text-white transition">
                    Batal
                </button>
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-rose-600 px-4 py-2 text-xs font-bold text-white hover:bg-rose-500 transition cursor-pointer shadow-lg shadow-rose-600/30">
                    <i class="fa-solid fa-ban"></i> Tolak Permohonan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveModal(id, name, code, type) {
    document.getElementById('approveReqId').value = id;
    document.getElementById('appCodeVal').textContent = code;
    document.getElementById('appTargetSub').textContent = name + ' (' + type + ')';
    document.getElementById('modalApprove').classList.remove('hidden');
}

function openRejectModal(id, name, code) {
    document.getElementById('rejectReqId').value = id;
    document.getElementById('rejTargetSub').textContent = name + ' (' + code + ')';
    document.getElementById('modalReject').classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
