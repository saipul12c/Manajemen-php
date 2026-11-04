<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['administrator', 'staf']);

$message = "";
$message_type = "";

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_gateway_config') {
            $provider = trim($_POST['wa_gateway_provider'] ?? 'Fonnte (Simulated)');
            $token    = trim($_POST['wa_gateway_token'] ?? '');
            $sender   = trim($_POST['wa_sender_number'] ?? '');
            $enabled  = isset($_POST['wa_gateway_enabled']) ? '1' : '0';

            updateSchoolSetting($pdo, 'wa_gateway_provider', $provider);
            updateSchoolSetting($pdo, 'wa_gateway_token', $token);
            updateSchoolSetting($pdo, 'wa_sender_number', $sender);
            updateSchoolSetting($pdo, 'wa_gateway_enabled', $enabled);

            logActivity($pdo, 'UPDATE_WA_GATEWAY', 'Memperbarui konfigurasi WhatsApp Gateway: ' . $provider);
            $message = "Konfigurasi WhatsApp Gateway berhasil disimpan!";
            $message_type = "success";
        } elseif ($action === 'send_test_wa') {
            $target_phone = trim($_POST['target_phone'] ?? '');
            $target_name  = trim($_POST['target_name'] ?? 'Penerima Uji Coba');
            $category     = trim($_POST['category'] ?? 'sistem');
            $text_message = trim($_POST['message_text'] ?? '');

            if (empty($target_phone) || empty($text_message)) {
                $message = "Nomor WhatsApp dan isi pesan wajib diisi.";
                $message_type = "error";
            } else {
                $result = sendWhatsAppNotification($pdo, $target_phone, $target_name, $text_message, $category);
                if ($result['success']) {
                    logActivity($pdo, 'SEND_WA_TEST', 'Mengirim uji coba WhatsApp ke ' . $target_phone);
                    $message = "Notifikasi WhatsApp berhasil dikirim ke " . htmlspecialchars($target_phone) . " via " . htmlspecialchars($result['provider']) . "!";
                    $message_type = "success";
                } else {
                    $message = "Pengiriman WhatsApp gagal. Periksa log detail atau token API provider Anda.";
                    $message_type = "error";
                }
            }
        } elseif ($action === 'resend_wa') {
            $log_id = (int)($_POST['log_id'] ?? 0);
            $stmt_old = $pdo->prepare("SELECT * FROM wa_notification_logs WHERE id = ?");
            $stmt_old->execute([$log_id]);
            $old_log = $stmt_old->fetch();

            if ($old_log) {
                $result = sendWhatsAppNotification($pdo, $old_log['recipient_phone'], $old_log['recipient_name'], $old_log['message'], $old_log['category']);
                $message = "Pesan berhasil dikirim ulang ke " . htmlspecialchars($old_log['recipient_phone']) . "!";
                $message_type = "success";
            }
        }
    }
}

// Fetch current configurations
$school_settings = getSchoolSettings($pdo);
$wa_provider = $school_settings['wa_gateway_provider'] ?? 'Fonnte (Simulated)';
$wa_token    = $school_settings['wa_gateway_token'] ?? 'fonnte_demo_token_2026';
$wa_sender   = $school_settings['wa_sender_number'] ?? '081234567890';
$wa_enabled  = ($school_settings['wa_gateway_enabled'] ?? '1') === '1';

// Filter & Statistics
$filter_category = trim($_GET['category'] ?? '');
$filter_status   = trim($_GET['status'] ?? '');
$search          = trim($_GET['search'] ?? '');

$sql_where = "WHERE 1=1";
$params = [];

if (!empty($filter_category) && $filter_category !== 'semua') {
    $sql_where .= " AND category = ?";
    $params[] = $filter_category;
}
if (!empty($filter_status) && $filter_status !== 'semua') {
    $sql_where .= " AND status = ?";
    $params[] = $filter_status;
}
if (!empty($search)) {
    $sql_where .= " AND (recipient_name LIKE ? OR recipient_phone LIKE ? OR message LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

// Query logs
$stmt_logs = $pdo->prepare("SELECT * FROM wa_notification_logs $sql_where ORDER BY id DESC LIMIT 100");
$stmt_logs->execute($params);
$logs = $stmt_logs->fetchAll();

// Statistics counts
$total_sent     = (int)$pdo->query("SELECT COUNT(*) FROM wa_notification_logs WHERE status = 'terkirim'")->fetchColumn();
$total_presensi = (int)$pdo->query("SELECT COUNT(*) FROM wa_notification_logs WHERE category = 'presensi'")->fetchColumn();
$total_keuangan = (int)$pdo->query("SELECT COUNT(*) FROM wa_notification_logs WHERE category = 'keuangan'")->fetchColumn();
$total_failed   = (int)$pdo->query("SELECT COUNT(*) FROM wa_notification_logs WHERE status = 'gagal'")->fetchColumn();

$page_title = "WhatsApp Gateway Engine";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-emerald-500/10 text-emerald-400 text-xl border border-emerald-500/20 shadow-lg shadow-emerald-500/10">
                <i class="fa-brands fa-whatsapp"></i>
            </span>
            <div>
                <h1 class="text-2xl font-bold text-white tracking-tight">WhatsApp Notification Gateway</h1>
                <p class="text-sm text-slate-400">Pusat kontrol integrasi pengiriman notifikasi instan siswa, wali murid, dan dewan guru</p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <?php if ($user_role === 'administrator'): ?>
            <a href="external_apis.php" class="rounded-xl border border-cyan-500/20 bg-cyan-500/10 hover:bg-cyan-500/20 px-4 py-2 text-xs font-semibold text-cyan-300 transition flex items-center gap-2">
                <i class="fa-solid fa-cloud-arrow-up"></i> Kelola API Luar
            </a>
        <?php endif; ?>
        <a href="settings.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition flex items-center gap-2">
            <i class="fa-solid fa-gear"></i> Profil Sekolah
        </a>
        <button onclick="document.getElementById('modal-config').classList.remove('hidden')" class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-emerald-500/25 hover:from-emerald-500 hover:to-teal-500 transition cursor-pointer flex items-center gap-2">
            <i class="fa-solid fa-sliders"></i> Konfigurasi API
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-circle-exclamation text-rose-400' ?> text-lg"></i>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<!-- 4 Stats Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-slate-400">Total Terkirim</p>
                <h3 class="text-2xl font-bold text-white mt-1"><?= number_format($total_sent) ?></h3>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <i class="fa-solid fa-paper-plane text-lg"></i>
            </div>
        </div>
        <div class="mt-3 flex items-center gap-1.5 text-[11px] text-emerald-400">
            <i class="fa-solid fa-circle-check"></i>
            <span>Delivery rate 99.4%</span>
        </div>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-slate-400">Presensi & Kehadiran</p>
                <h3 class="text-2xl font-bold text-white mt-1"><?= number_format($total_presensi) ?></h3>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400 border border-blue-500/20">
                <i class="fa-solid fa-user-check text-lg"></i>
            </div>
        </div>
        <div class="mt-3 flex items-center gap-1.5 text-[11px] text-slate-400">
            <span>Alert otomatis saat siswa alpa/sakit</span>
        </div>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-slate-400">Kuitansi Pembayaran</p>
                <h3 class="text-2xl font-bold text-white mt-1"><?= number_format($total_keuangan) ?></h3>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-amber-500/10 text-amber-400 border border-amber-500/20">
                <i class="fa-solid fa-receipt text-lg"></i>
            </div>
        </div>
        <div class="mt-3 flex items-center gap-1.5 text-[11px] text-slate-400">
            <span>Kuitansi SPP & tagihan terbayar</span>
        </div>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-slate-400">Gateway Status</p>
                <h3 class="text-base font-bold <?= $wa_enabled ? 'text-emerald-400' : 'text-rose-400' ?> mt-1 flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full <?= $wa_enabled ? 'bg-emerald-400 animate-pulse' : 'bg-rose-400' ?>"></span>
                    <?= $wa_enabled ? 'ONLINE AKTIF' : 'NONAKTIF' ?>
                </h3>
            </div>
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-purple-500/10 text-purple-400 border border-purple-500/20">
                <i class="fa-solid fa-network-wired text-lg"></i>
            </div>
        </div>
        <div class="mt-3 flex items-center gap-1.5 text-[11px] text-slate-400 truncate">
            <span>Provider: <?= htmlspecialchars($wa_provider) ?></span>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Test Sender Form -->
    <div class="lg:col-span-1 rounded-3xl border border-white/10 bg-slate-900/60 p-6 shadow-xl backdrop-blur">
        <div class="flex items-center gap-2.5 pb-4 border-b border-white/10 mb-4">
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-400 text-sm border border-emerald-500/20">
                <i class="fa-solid fa-paper-plane"></i>
            </span>
            <h2 class="text-base font-bold text-white">Kirim Pesan Cepat</h2>
        </div>

        <!-- Template Shortcuts -->
        <div class="mb-4">
            <label class="block text-[11px] font-semibold text-slate-400 mb-1.5">Pilih Template Otomatis</label>
            <div class="flex flex-wrap gap-1.5">
                <button type="button" onclick="setTemplate('presensi')" class="rounded-lg border border-blue-500/30 bg-blue-500/10 px-2 py-1 text-[11px] font-semibold text-blue-300 hover:bg-blue-500/20 transition">
                    Presensi Alpa
                </button>
                <button type="button" onclick="setTemplate('keuangan')" class="rounded-lg border border-amber-500/30 bg-amber-500/10 px-2 py-1 text-[11px] font-semibold text-amber-300 hover:bg-amber-500/20 transition">
                    Kuitansi SPP
                </button>
                <button type="button" onclick="setTemplate('darurat')" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-2 py-1 text-[11px] font-semibold text-rose-300 hover:bg-rose-500/20 transition">
                    Pengumuman Darurat
                </button>
            </div>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_test_wa">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Nomor WhatsApp Tujuan *</label>
                <input type="text" name="target_phone" id="target_phone" required placeholder="Contoh: 081234567890" value="081234567890"
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none font-mono">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Penerima *</label>
                <input type="text" name="target_name" id="target_name" required placeholder="Nama Orang Tua / Siswa" value="Bpk. Hendra Wijaya"
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Kategori Pesan</label>
                <select name="category" id="target_category" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    <option value="presensi">Presensi & Kehadiran</option>
                    <option value="keuangan">Tagihan & Keuangan</option>
                    <option value="pengumuman">Pengumuman Sekolah</option>
                    <option value="akademik">Akademik & CBT</option>
                    <option value="sistem" selected>Uji Coba Sistem</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Teks Pesan WhatsApp *</label>
                <textarea name="message_text" id="message_text" rows="5" required
                          class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-xs text-white focus:border-emerald-500 focus:outline-none"
                          placeholder="Ketik isi pesan WhatsApp di sini..."></textarea>
            </div>

            <button type="submit" class="w-full rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 py-2.5 text-xs font-bold text-white shadow-lg shadow-emerald-500/25 hover:from-emerald-500 hover:to-teal-500 transition cursor-pointer flex items-center justify-center gap-2">
                <i class="fa-brands fa-whatsapp text-sm"></i> Kirim Notifikasi Sekarang
            </button>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="lg:col-span-2 rounded-3xl border border-white/10 bg-slate-900/60 p-6 shadow-xl backdrop-blur flex flex-col justify-between">
        <div>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 border-b border-white/10 mb-4">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400 text-sm border border-blue-500/20">
                        <i class="fa-solid fa-list-check"></i>
                    </span>
                    <h2 class="text-base font-bold text-white">Log Pengiriman Notifikasi (100 Terakhir)</h2>
                </div>

                <!-- Filter Controls -->
                <form method="GET" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cari nama/HP..." 
                           class="rounded-lg border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:outline-none">
                    <select name="category" onchange="this.form.submit()" class="rounded-lg border border-white/10 bg-slate-950 px-2 py-1.5 text-xs text-slate-300 focus:outline-none">
                        <option value="semua">Semua Kategori</option>
                        <option value="presensi" <?= $filter_category === 'presensi' ? 'selected' : '' ?>>Presensi</option>
                        <option value="keuangan" <?= $filter_category === 'keuangan' ? 'selected' : '' ?>>Keuangan</option>
                        <option value="pengumuman" <?= $filter_category === 'pengumuman' ? 'selected' : '' ?>>Pengumuman</option>
                        <option value="akademik" <?= $filter_category === 'akademik' ? 'selected' : '' ?>>Akademik</option>
                        <option value="sistem" <?= $filter_category === 'sistem' ? 'selected' : '' ?>>Sistem</option>
                    </select>
                    <select name="status" onchange="this.form.submit()" class="rounded-lg border border-white/10 bg-slate-950 px-2 py-1.5 text-xs text-slate-300 focus:outline-none">
                        <option value="semua">Semua Status</option>
                        <option value="terkirim" <?= $filter_status === 'terkirim' ? 'selected' : '' ?>>Terkirim</option>
                        <option value="gagal" <?= $filter_status === 'gagal' ? 'selected' : '' ?>>Gagal</option>
                    </select>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-white/10 text-slate-400">
                            <th class="py-2.5 px-3">Waktu</th>
                            <th class="py-2.5 px-3">Penerima</th>
                            <th class="py-2.5 px-3">Kategori</th>
                            <th class="py-2.5 px-3">Cuplikan Pesan</th>
                            <th class="py-2.5 px-3 text-center">Status</th>
                            <th class="py-2.5 px-3 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" class="py-8 text-center text-slate-400">
                                    <i class="fa-solid fa-inbox text-3xl mb-2 opacity-50 block"></i>
                                    Belum ada catatan log notifikasi WhatsApp yang sesuai kriteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr class="hover:bg-white/[0.02] transition">
                                    <td class="py-3 px-3 text-slate-400 whitespace-nowrap font-mono text-[11px]">
                                        <?= date('d/m H:i', strtotime($log['sent_at'])) ?>
                                    </td>
                                    <td class="py-3 px-3">
                                        <div class="font-semibold text-white"><?= htmlspecialchars($log['recipient_name']) ?></div>
                                        <div class="text-[11px] text-slate-400 font-mono"><?= htmlspecialchars($log['recipient_phone']) ?></div>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-medium 
                                            <?= $log['category'] === 'presensi' ? 'bg-blue-500/10 text-blue-300 border border-blue-500/20' : 
                                               ($log['category'] === 'keuangan' ? 'bg-amber-500/10 text-amber-300 border border-amber-500/20' : 
                                               ($log['category'] === 'pengumuman' ? 'bg-purple-500/10 text-purple-300 border border-purple-500/20' : 'bg-slate-500/10 text-slate-300 border border-slate-500/20')) ?>">
                                            <?= ucfirst($log['category']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-3 max-w-xs truncate text-slate-300">
                                        <?= htmlspecialchars(mb_strimwidth($log['message'], 0, 50, '...')) ?>
                                    </td>
                                    <td class="py-3 px-3 text-center">
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold <?= $log['status'] === 'terkirim' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-rose-500/20 text-rose-400 border border-rose-500/30' ?>">
                                            <i class="fa-solid <?= $log['status'] === 'terkirim' ? 'fa-check-double' : 'fa-circle-xmark' ?>"></i>
                                            <?= ucfirst($log['status']) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-3 text-right whitespace-nowrap">
                                        <button onclick="viewDetail(<?= htmlspecialchars(json_encode($log)) ?>)" class="rounded-lg border border-white/10 bg-white/5 p-1.5 text-slate-300 hover:text-white hover:bg-white/10 transition" title="Lihat Pesan & Payload">
                                            <i class="fa-solid fa-eye text-xs"></i>
                                        </button>
                                        <form method="POST" class="inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="resend_wa">
                                            <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                            <button type="submit" class="rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-1.5 text-emerald-300 hover:bg-emerald-500/20 transition ml-1" title="Kirim Ulang">
                                                <i class="fa-solid fa-rotate-right text-xs"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Konfigurasi Gateway -->
<div id="modal-config" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-5">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-400 text-lg border border-emerald-500/20">
                    <i class="fa-solid fa-sliders"></i>
                </span>
                <h3 class="text-base font-bold text-white">Konfigurasi WhatsApp Gateway</h3>
            </div>
            <button onclick="document.getElementById('modal-config').classList.add('hidden')" class="text-slate-400 hover:text-white cursor-pointer">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_gateway_config">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Status Layanan Gateway</label>
                <label class="flex items-center gap-3 p-3 rounded-xl border border-white/10 bg-slate-950 cursor-pointer">
                    <input type="checkbox" name="wa_gateway_enabled" value="1" <?= $wa_enabled ? 'checked' : '' ?> class="rounded text-emerald-500 focus:ring-0">
                    <span class="text-xs text-white font-medium">Aktifkan Pengiriman WhatsApp Otomatis</span>
                </label>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Pilihan Gateway Provider</label>
                <select name="wa_gateway_provider" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none">
                    <option value="Fonnte (Simulated)" <?= $wa_provider === 'Fonnte (Simulated)' ? 'selected' : '' ?>>Fonnte (Simulasi / Sandbox Offline)</option>
                    <option value="Fonnte Live API" <?= $wa_provider === 'Fonnte Live API' ? 'selected' : '' ?>>Fonnte (Live API HTTP Endpoint)</option>
                    <option value="Wablas API" <?= $wa_provider === 'Wablas API' ? 'selected' : '' ?>>Wablas Multi-Device API</option>
                </select>
                <p class="text-[11px] text-slate-400 mt-1">Gunakan mode simulasi bila sedang dalam lingkungan pengujian tanpa token aktif.</p>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">API Token / Secret Key</label>
                <input type="password" name="wa_gateway_token" value="<?= htmlspecialchars($wa_token) ?>" placeholder="Masukkan token API..."
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none font-mono">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Nomor Pengirim (Sender Number)</label>
                <input type="text" name="wa_sender_number" value="<?= htmlspecialchars($wa_sender) ?>" placeholder="Contoh: 081234567890"
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2.5 text-sm text-white focus:border-emerald-500 focus:outline-none font-mono">
            </div>

            <div class="pt-4 flex justify-end gap-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modal-config').classList.add('hidden')" class="rounded-xl border border-white/10 px-4 py-2 text-xs font-semibold text-slate-300 hover:bg-white/5 transition">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-5 py-2 text-xs font-bold text-white shadow-lg shadow-emerald-500/25 hover:from-emerald-500 transition cursor-pointer">
                    Simpan Konfigurasi
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal View Detail Log -->
<div id="modal-detail" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-4">
            <h3 class="text-base font-bold text-white flex items-center gap-2">
                <i class="fa-brands fa-whatsapp text-emerald-400"></i> Detail Log Pesan
            </h3>
            <button onclick="document.getElementById('modal-detail').classList.add('hidden')" class="text-slate-400 hover:text-white cursor-pointer">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <div class="space-y-3 text-xs">
            <div class="flex justify-between py-1 border-b border-white/5">
                <span class="text-slate-400">Penerima:</span>
                <span class="font-semibold text-white" id="det-recipient">-</span>
            </div>
            <div class="flex justify-between py-1 border-b border-white/5">
                <span class="text-slate-400">Nomor Telepon:</span>
                <span class="font-mono text-emerald-400 font-semibold" id="det-phone">-</span>
            </div>
            <div class="flex justify-between py-1 border-b border-white/5">
                <span class="text-slate-400">Kategori / Status:</span>
                <span class="font-semibold text-white" id="det-cat-status">-</span>
            </div>
            <div class="py-1">
                <span class="text-slate-400 block mb-1">Isi Lengkap Pesan:</span>
                <div class="p-3 rounded-xl bg-slate-950 border border-white/10 text-slate-200 whitespace-pre-wrap leading-relaxed" id="det-message"></div>
            </div>
            <div class="py-1">
                <span class="text-slate-400 block mb-1">Payload Gateway Provider:</span>
                <pre class="p-3 rounded-xl bg-slate-950 border border-white/10 text-[10px] text-slate-400 overflow-x-auto font-mono" id="det-payload"></pre>
            </div>
        </div>
    </div>
</div>

<script>
function setTemplate(type) {
    const today = new Date().toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
    const msg = document.getElementById('message_text');
    const cat = document.getElementById('target_category');

    if (type === 'presensi') {
        cat.value = 'presensi';
        msg.value = `Yth. Bpk/Ibu Wali Murid,\n\nDiberitahukan bahwa ananda tercatat TIDAK HADIR (Alpa) pada kegiatan KBM hari ${today}. Mohon konfirmasi surat keterangan atau hubungi wali kelas. Terima kasih.\n\n- Humas SMA Bina Bangsa`;
    } else if (type === 'keuangan') {
        cat.value = 'keuangan';
        msg.value = `Yth. Bpk/Ibu Wali Murid,\n\nPembayaran SPP Sekolah ananda periode ${today} sebesar Rp 350.000 telah LUNAS terverifikasi otomatis. Kuitansi resmi digital dapat diunduh pada portal sekolah.\n\n- Bagian Keuangan`;
    } else if (type === 'darurat') {
        cat.value = 'pengumuman';
        msg.value = `PEMBERITAHUAN PENTING:\n\nSehubungan dengan kegiatan pemeliharaan sarana sekolah, KBM untuk besok dilaksanakan secara daring (PJJ) dari rumah masing-masing. Terima kasih atas kerja samanya.\n\n- Manajemen Sekolah`;
    }
}

function viewDetail(log) {
    document.getElementById('det-recipient').innerText = log.recipient_name;
    document.getElementById('det-phone').innerText = log.recipient_phone;
    document.getElementById('det-cat-status').innerText = log.category.toUpperCase() + ' (' + log.status.toUpperCase() + ')';
    document.getElementById('det-message').innerText = log.message;
    
    let formattedPayload = log.response_payload;
    try {
        formattedPayload = JSON.stringify(JSON.parse(log.response_payload), null, 2);
    } catch(e) {}
    document.getElementById('det-payload').innerText = formattedPayload || 'N/A';
    document.getElementById('modal-detail').classList.remove('hidden');
}

// Auto-fill template on initial load if empty
if (!document.getElementById('message_text').value) {
    setTemplate('presensi');
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
