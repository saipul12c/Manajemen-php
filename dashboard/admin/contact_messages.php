<?php
/**
 * Kotak Masuk Pesan Tamu (Public Contact Messages Inbox)
 * Dashboard Admin & Staf TU untuk membaca, memproses, dan membalas pesan dari formulir kontak publik.
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['administrator', 'staf']);

$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'staf';
$school_info = getSchoolSettings($pdo);
$school_name = $school_info['school_name'] ?? 'Sekolah';

// Pastikan tabel contact_messages ada (defensif)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `contact_messages` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `ticket_code` VARCHAR(50) NOT NULL UNIQUE,
            `name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NOT NULL,
            `phone` VARCHAR(30) DEFAULT NULL,
            `category` VARCHAR(50) NOT NULL DEFAULT 'umum',
            `subject` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `status` ENUM('baru', 'diproses', 'selesai') NOT NULL DEFAULT 'baru',
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {}

$message_alert = "";
$message_type  = "";

// -------------------------------------------------------------
// 1. UPDATE STATUS PESAN
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!validateCsrfToken()) {
        $message_alert = "Token keamanan tidak valid. Silakan muat ulang.";
        $message_type  = "error";
    } else {
        $msg_id     = (int)($_POST['message_id'] ?? 0);
        $new_status = trim($_POST['status'] ?? '');
        $allowed    = ['baru', 'diproses', 'selesai'];

        if ($msg_id > 0 && in_array($new_status, $allowed, true)) {
            try {
                $stmt_up = $pdo->prepare("UPDATE contact_messages SET status = ? WHERE id = ?");
                $stmt_up->execute([$new_status, $msg_id]);
                logActivity($pdo, 'UPDATE_CONTACT_MSG', "Mengubah status pesan kontak ID $msg_id menjadi $new_status");

                $message_alert = "Status pesan berhasil diperbarui menjadi " . strtoupper($new_status);
                $message_type  = "success";
            } catch (Exception $e) {
                $message_alert = "Gagal memperbarui status: " . $e->getMessage();
                $message_type  = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// 2. HAPUS PESAN (KHUSUS ADMINISTRATOR)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_message') {
    if ($user_role !== 'administrator') {
        $message_alert = "Hanya Administrator yang memiliki akses untuk menghapus pesan.";
        $message_type  = "error";
    } elseif (!validateCsrfToken()) {
        $message_alert = "Token keamanan tidak valid.";
        $message_type  = "error";
    } else {
        $msg_id = (int)($_POST['message_id'] ?? 0);
        if ($msg_id > 0) {
            try {
                $stmt_del = $pdo->prepare("DELETE FROM contact_messages WHERE id = ?");
                $stmt_del->execute([$msg_id]);
                logActivity($pdo, 'DELETE_CONTACT_MSG', "Menghapus pesan kontak ID $msg_id");

                $message_alert = "Pesan berhasil dihapus permanen.";
                $message_type  = "success";
            } catch (Exception $e) {
                $message_alert = "Gagal menghapus pesan: " . $e->getMessage();
                $message_type  = "error";
            }
        }
    }
}

// -------------------------------------------------------------
// 3. FILTER & PENCARIAN
// -------------------------------------------------------------
$filter_status   = trim($_GET['status'] ?? 'all');
$filter_category = trim($_GET['category'] ?? 'all');
$search_query    = trim($_GET['q'] ?? '');

$where_clauses = [];
$params = [];

if ($filter_status !== 'all' && in_array($filter_status, ['baru', 'diproses', 'selesai'], true)) {
    $where_clauses[] = "status = ?";
    $params[] = $filter_status;
}

if ($filter_category !== 'all' && !empty($filter_category)) {
    $where_clauses[] = "category = ?";
    $params[] = $filter_category;
}

if (!empty($search_query)) {
    $where_clauses[] = "(ticket_code LIKE ? OR name LIKE ? OR email LIKE ? OR phone LIKE ? OR subject LIKE ? OR message LIKE ?)";
    $like = "%{$search_query}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Fetch Messages
$stmt = $pdo->prepare("SELECT * FROM contact_messages {$where_sql} ORDER BY id DESC LIMIT 200");
$stmt->execute($params);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Hitung Statistik
$stat_total    = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages")->fetchColumn();
$stat_baru     = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'baru'")->fetchColumn();
$stat_diproses = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'diproses'")->fetchColumn();
$stat_selesai  = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'selesai'")->fetchColumn();

// Helper Kategori Label & Badge
function getContactCatLabel(string $cat): string {
    return match(strtolower($cat)) {
        'ppdb'      => 'PPDB / Pendaftaran',
        'akademik'  => 'Akademik & KBM',
        'keuangan'  => 'Keuangan / SPP',
        'teknis'    => 'Bantuan Teknis',
        'saran'     => 'Kritik & Saran',
        default     => ucfirst($cat)
    };
}

function getContactCatBadge(string $cat): string {
    return match(strtolower($cat)) {
        'ppdb'      => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
        'akademik'  => 'border-sky-500/30 bg-sky-500/10 text-sky-300',
        'keuangan'  => 'border-amber-500/30 bg-amber-500/10 text-amber-300',
        'teknis'    => 'border-indigo-500/30 bg-indigo-500/10 text-indigo-300',
        'saran'     => 'border-purple-500/30 bg-purple-500/10 text-purple-300',
        default     => 'border-slate-500/30 bg-slate-500/10 text-slate-300'
    };
}

$page_title = "Kotak Masuk Pesan Tamu";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- PAGE HEADER -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-500/20 text-amber-400 text-xl border border-amber-500/30 shadow-lg shadow-amber-500/10">
                    <i class="fa-solid fa-envelope-open-text"></i>
                </span>
                <div>
                    <h1 class="text-2xl font-bold text-white tracking-tight">Kotak Masuk Pesan Tamu</h1>
                    <p class="text-xs sm:text-sm text-slate-400">Kelola dan tindak lanjuti pesan, pertanyaan, dan konsultasi dari halaman kontak publik</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a href="../../kontak.php" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition">
                <i class="fa-solid fa-arrow-up-right-from-square text-[11px]"></i>
                <span>Buka Halaman Kontak</span>
            </a>
        </div>
    </div>

    <!-- NOTIFIKASI ALERT -->
    <?php if (!empty($message_alert)): ?>
        <div class="rounded-2xl border p-4 text-xs sm:text-sm font-medium flex items-center justify-between gap-3 shadow-xl <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
            <div class="flex items-center gap-3">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-lg text-emerald-400' : 'fa-circle-exclamation text-lg text-rose-400' ?>"></i>
                <span><?= htmlspecialchars($message_alert) ?></span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- STATS OVERVIEW CARDS -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <a href="contact_messages.php?status=all" 
           class="rounded-3xl border border-white/10 bg-slate-900/60 p-5 backdrop-blur hover:border-white/20 transition group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs text-slate-400 font-medium">Total Pesan</span>
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400">
                    <i class="fa-solid fa-inbox text-sm"></i>
                </span>
            </div>
            <div class="text-2xl sm:text-3xl font-black text-white"><?= $stat_total ?></div>
            <span class="text-[11px] text-slate-500 mt-1 block">Semua pesan masuk</span>
        </a>

        <a href="contact_messages.php?status=baru" 
           class="rounded-3xl border border-amber-500/20 bg-amber-500/5 p-5 backdrop-blur hover:border-amber-500/40 transition group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs text-amber-400 font-bold">Pesan Baru</span>
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400">
                    <i class="fa-solid fa-bell text-sm"></i>
                </span>
            </div>
            <div class="text-2xl sm:text-3xl font-black text-amber-300"><?= $stat_baru ?></div>
            <span class="text-[11px] text-amber-400/70 mt-1 flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-full bg-amber-400 animate-pulse"></span>
                Menunggu respon panitia
            </span>
        </a>

        <a href="contact_messages.php?status=diproses" 
           class="rounded-3xl border border-sky-500/20 bg-sky-500/5 p-5 backdrop-blur hover:border-sky-500/40 transition group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs text-sky-400 font-bold">Sedang Diproses</span>
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-500/20 text-sky-400">
                    <i class="fa-solid fa-spinner text-sm"></i>
                </span>
            </div>
            <div class="text-2xl sm:text-3xl font-black text-sky-300"><?= $stat_diproses ?></div>
            <span class="text-[11px] text-sky-400/70 mt-1 block">Dalam penanganan staf</span>
        </a>

        <a href="contact_messages.php?status=selesai" 
           class="rounded-3xl border border-emerald-500/20 bg-emerald-500/5 p-5 backdrop-blur hover:border-emerald-500/40 transition group">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs text-emerald-400 font-bold">Selesai / Terjawab</span>
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-400">
                    <i class="fa-solid fa-circle-check text-sm"></i>
                </span>
            </div>
            <div class="text-2xl sm:text-3xl font-black text-emerald-300"><?= $stat_selesai ?></div>
            <span class="text-[11px] text-emerald-400/70 mt-1 block">Telah ditindaklanjuti</span>
        </a>
    </div>

    <!-- FILTER & SEARCH BAR -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/60 p-4 sm:p-5 backdrop-blur">
        <form method="GET" action="contact_messages.php" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
            
            <!-- Input Cari -->
            <div class="sm:col-span-5 relative">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-3 text-slate-500 text-xs"></i>
                <input type="text" name="q" value="<?= htmlspecialchars($search_query) ?>" placeholder="Cari nama, email, tiket, subjek..."
                       class="w-full rounded-xl border border-white/10 bg-slate-950 pl-9 pr-4 py-2 text-xs text-white placeholder-slate-500 focus:border-amber-500 focus:outline-none">
            </div>

            <!-- Filter Status -->
            <div class="sm:col-span-3">
                <select name="status" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-slate-300 focus:border-amber-500 focus:outline-none">
                    <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>Semua Status (<?= $stat_total ?>)</option>
                    <option value="baru" <?= $filter_status === 'baru' ? 'selected' : '' ?>>Status: Baru (<?= $stat_baru ?>)</option>
                    <option value="diproses" <?= $filter_status === 'diproses' ? 'selected' : '' ?>>Status: Diproses (<?= $stat_diproses ?>)</option>
                    <option value="selesai" <?= $filter_status === 'selesai' ? 'selected' : '' ?>>Status: Selesai (<?= $stat_selesai ?>)</option>
                </select>
            </div>

            <!-- Filter Kategori -->
            <div class="sm:col-span-2">
                <select name="category" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-slate-300 focus:border-amber-500 focus:outline-none">
                    <option value="all" <?= $filter_category === 'all' ? 'selected' : '' ?>>Semua Kategori</option>
                    <option value="ppdb" <?= $filter_category === 'ppdb' ? 'selected' : '' ?>>PPDB</option>
                    <option value="akademik" <?= $filter_category === 'akademik' ? 'selected' : '' ?>>Akademik</option>
                    <option value="keuangan" <?= $filter_category === 'keuangan' ? 'selected' : '' ?>>Keuangan</option>
                    <option value="teknis" <?= $filter_category === 'teknis' ? 'selected' : '' ?>>Teknis</option>
                    <option value="saran" <?= $filter_category === 'saran' ? 'selected' : '' ?>>Kritik & Saran</option>
                </select>
            </div>

            <!-- Tombol Submit & Reset -->
            <div class="sm:col-span-2 flex items-center gap-2">
                <button type="submit" class="flex-1 rounded-xl bg-amber-600 hover:bg-amber-500 py-2 text-xs font-bold text-white shadow-md shadow-amber-600/20 transition cursor-pointer">
                    Filter
                </button>
                <?php if ($filter_status !== 'all' || $filter_category !== 'all' || !empty($search_query)): ?>
                    <a href="contact_messages.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 p-2 text-xs text-slate-300 transition" title="Reset Filter">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <!-- TABEL DATA PESAN MASUK -->
    <div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-xl backdrop-blur">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="border-b border-white/10 bg-white/5 text-[10px] uppercase font-bold text-slate-400">
                    <tr>
                        <th class="px-5 py-3.5">Tiket & Waktu</th>
                        <th class="px-5 py-3.5">Pengirim</th>
                        <th class="px-4 py-3.5">Kategori & Subjek</th>
                        <th class="px-5 py-3.5">Ringkasan Pesan</th>
                        <th class="px-4 py-3.5 text-center">Status</th>
                        <th class="px-5 py-3.5 text-right">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5 font-sans">
                    <?php if (empty($messages)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-16 text-center text-slate-400">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="h-14 w-14 rounded-full bg-white/5 flex items-center justify-center text-2xl text-slate-500 mb-3">
                                        <i class="fa-regular fa-envelope-open"></i>
                                    </div>
                                    <p class="text-sm font-semibold text-slate-300">Tidak ada pesan ditemukan</p>
                                    <p class="text-xs text-slate-500 mt-1 max-w-sm">Belum ada pesan yang sesuai dengan kriteria filter atau pencarian Anda.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($messages as $msg): 
                            // Persiapan data JSON untuk modal popup
                            $json_data = htmlspecialchars(json_encode($msg), ENT_QUOTES, 'UTF-8');
                            $clean_phone = preg_replace('/[^0-9]/', '', (string)$msg['phone']);
                            if (str_starts_with($clean_phone, '0')) {
                                $clean_phone = '62' . substr($clean_phone, 1);
                            }
                            $wa_reply_text = urlencode("Halo {$msg['name']}, terima kasih telah menghubungi {$school_name}. Menindaklanjuti pesan Anda terkait [{$msg['ticket_code']}: {$msg['subject']}], ");
                            $mailto_link = "mailto:" . rawurlencode($msg['email']) . "?subject=" . rawurlencode("Tindak Lanjut Pesan [{$msg['ticket_code']}]: {$msg['subject']}");
                        ?>
                            <tr class="hover:bg-white/[0.02] transition <?= $msg['status'] === 'baru' ? 'bg-amber-500/[0.02]' : '' ?>">
                                
                                <!-- Tiket & Waktu -->
                                <td class="px-5 py-4 whitespace-nowrap">
                                    <span class="font-mono text-xs font-bold text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded-lg border border-amber-500/20">
                                        <?= htmlspecialchars($msg['ticket_code']) ?>
                                    </span>
                                    <span class="block text-[11px] text-slate-400 mt-1.5">
                                        <i class="fa-regular fa-clock mr-1 text-[10px]"></i>
                                        <?= date('d M Y, H:i', strtotime($msg['created_at'])) ?> WIB
                                    </span>
                                </td>

                                <!-- Pengirim -->
                                <td class="px-5 py-4">
                                    <strong class="text-white text-xs block"><?= htmlspecialchars($msg['name']) ?></strong>
                                    <span class="text-[11px] text-slate-400 block font-mono"><?= htmlspecialchars($msg['email']) ?></span>
                                    <?php if (!empty($msg['phone'])): ?>
                                        <span class="text-[11px] text-emerald-400 block font-mono mt-0.5">
                                            <i class="fa-brands fa-whatsapp mr-1 text-[10px]"></i><?= htmlspecialchars($msg['phone']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Kategori & Subjek -->
                                <td class="px-4 py-4 max-w-xs">
                                    <span class="inline-block px-2 py-0.5 rounded-md text-[10px] font-bold border mb-1 <?= getContactCatBadge($msg['category']) ?>">
                                        <?= htmlspecialchars(getContactCatLabel($msg['category'])) ?>
                                    </span>
                                    <span class="block font-semibold text-slate-200 truncate" title="<?= htmlspecialchars($msg['subject']) ?>">
                                        <?= htmlspecialchars($msg['subject']) ?>
                                    </span>
                                </td>

                                <!-- Ringkasan Pesan -->
                                <td class="px-5 py-4 max-w-xs">
                                    <p class="text-xs text-slate-400 line-clamp-2 leading-relaxed">
                                        <?= htmlspecialchars($msg['message']) ?>
                                    </p>
                                </td>

                                <!-- Status Badge -->
                                <td class="px-4 py-4 text-center whitespace-nowrap">
                                    <?php if ($msg['status'] === 'baru'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border border-amber-500/30 bg-amber-500/10 text-amber-300">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                                            BARU
                                        </span>
                                    <?php elseif ($msg['status'] === 'diproses'): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border border-sky-500/30 bg-sky-500/10 text-sky-300">
                                            <span class="h-1.5 w-1.5 rounded-full bg-sky-400"></span>
                                            DIPROSES
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold border border-emerald-500/30 bg-emerald-500/10 text-emerald-300">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                                            SELESAI
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Tombol Tindakan -->
                                <td class="px-5 py-4 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1.5">
                                        
                                        <!-- Buka Detail Modal -->
                                        <button type="button" onclick="openDetailModal(<?= $json_data ?>)" 
                                                class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/15 px-2.5 py-1.5 text-xs text-slate-200 transition cursor-pointer"
                                                title="Baca Rincian Pesan">
                                            <i class="fa-regular fa-eye"></i>
                                        </button>

                                        <!-- Balas WhatsApp Cepat -->
                                        <?php if (!empty($clean_phone)): ?>
                                            <a href="https://wa.me/<?= $clean_phone ?>?text=<?= $wa_reply_text ?>" target="_blank"
                                               class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-2.5 py-1.5 text-xs text-emerald-400 transition"
                                               title="Balas via WhatsApp">
                                                <i class="fa-brands fa-whatsapp"></i>
                                            </a>
                                        <?php endif; ?>

                                        <!-- Balas Email -->
                                        <a href="<?= $mailto_link ?>" 
                                           class="rounded-xl border border-indigo-500/30 bg-indigo-500/10 hover:bg-indigo-500/20 px-2.5 py-1.5 text-xs text-indigo-400 transition"
                                           title="Balas via Email">
                                            <i class="fa-solid fa-paper-plane"></i>
                                        </a>

                                        <!-- Hapus (Khusus Administrator) -->
                                        <?php if ($user_role === 'administrator'): ?>
                                            <form method="POST" action="contact_messages.php" class="inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus pesan tiket <?= htmlspecialchars($msg['ticket_code']) ?>?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete_message">
                                                <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                                                <button type="submit" class="rounded-xl border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 px-2.5 py-1.5 text-xs text-rose-400 transition cursor-pointer" title="Hapus Pesan">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
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

<!-- ================= MODAL DETAIL & RESPON PESAN ================= -->
<div id="contactDetailModal" class="fixed inset-0 z-50 hidden bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-4 overflow-y-auto">
    <div class="relative w-full max-w-2xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl my-8 text-slate-100 max-h-[90vh] overflow-y-auto">
        
        <button onclick="closeDetailModal()" class="absolute top-5 right-5 text-slate-400 hover:text-white transition h-8 w-8 rounded-full bg-white/5 flex items-center justify-center cursor-pointer">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="flex items-center gap-3 border-b border-white/10 pb-5 mb-5">
            <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-500/20 text-amber-400 text-xl border border-amber-500/30">
                <i class="fa-solid fa-envelope-open-text"></i>
            </span>
            <div>
                <div class="flex items-center gap-2">
                    <span id="modalTicketCode" class="font-mono text-xs font-bold text-amber-400 bg-amber-500/10 px-2 py-0.5 rounded-lg border border-amber-500/20"></span>
                    <span id="modalCategoryBadge" class="text-[10px] font-bold px-2 py-0.5 rounded-md border uppercase"></span>
                </div>
                <h3 id="modalSubject" class="text-lg font-bold text-white mt-1"></h3>
            </div>
        </div>

        <!-- Profil Pengirim -->
        <div class="rounded-2xl border border-white/5 bg-slate-950 p-4 mb-5 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
            <div>
                <span class="text-slate-400 block text-[11px]">Nama Pengirim:</span>
                <strong id="modalName" class="text-white text-sm"></strong>
            </div>
            <div>
                <span class="text-slate-400 block text-[11px]">Waktu Pengiriman:</span>
                <span id="modalDate" class="text-slate-300"></span>
            </div>
            <div>
                <span class="text-slate-400 block text-[11px]">Alamat Email:</span>
                <span id="modalEmail" class="text-slate-200 font-mono"></span>
            </div>
            <div>
                <span class="text-slate-400 block text-[11px]">No. Telepon / WhatsApp:</span>
                <span id="modalPhone" class="text-emerald-400 font-mono"></span>
            </div>
        </div>

        <!-- Isi Pesan Lengkap -->
        <div class="mb-6">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Isi Pesan Masuk:</label>
            <div id="modalMessage" class="rounded-2xl border border-white/10 bg-slate-950/70 p-4 text-xs sm:text-sm text-slate-200 leading-relaxed whitespace-pre-wrap max-h-56 overflow-y-auto"></div>
        </div>

        <!-- Form Ubah Status -->
        <form method="POST" action="contact_messages.php" class="rounded-2xl border border-white/10 bg-white/5 p-4 mb-5 flex flex-col sm:flex-row items-center justify-between gap-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="message_id" id="modalMessageIdInput" value="">

            <div class="flex items-center gap-2 w-full sm:w-auto">
                <label class="text-xs font-semibold text-slate-300 shrink-0">Ubah Status:</label>
                <select name="status" id="modalStatusSelect" class="rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white focus:border-amber-500 focus:outline-none">
                    <option value="baru">Baru</option>
                    <option value="diproses">Sedang Diproses</option>
                    <option value="selesai">Selesai / Terjawab</option>
                </select>
                <button type="submit" class="rounded-xl bg-amber-600 hover:bg-amber-500 px-3.5 py-1.5 text-xs font-bold text-white transition cursor-pointer">
                    Simpan
                </button>
            </div>

            <!-- Tombol Balas WhatsApp / Email di dalam Modal -->
            <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
                <a id="modalWaBtn" href="#" target="_blank" class="rounded-xl bg-emerald-600 hover:bg-emerald-500 px-3.5 py-1.5 text-xs font-bold text-white transition flex items-center gap-1.5">
                    <i class="fa-brands fa-whatsapp"></i> Balas WA
                </a>
                <a id="modalMailBtn" href="#" class="rounded-xl bg-indigo-600 hover:bg-indigo-500 px-3.5 py-1.5 text-xs font-bold text-white transition flex items-center gap-1.5">
                    <i class="fa-solid fa-envelope"></i> Balas Email
                </a>
            </div>
        </form>

        <div class="flex justify-end pt-2 border-t border-white/10">
            <button type="button" onclick="closeDetailModal()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                Tutup
            </button>
        </div>

    </div>
</div>

<script>
function openDetailModal(data) {
    if (!data) return;

    document.getElementById('modalMessageIdInput').value = data.id || '';
    document.getElementById('modalTicketCode').textContent = data.ticket_code || '-';
    document.getElementById('modalSubject').textContent = data.subject || 'Tanpa Subjek';
    document.getElementById('modalName').textContent = data.name || '-';
    document.getElementById('modalEmail').textContent = data.email || '-';
    document.getElementById('modalPhone').textContent = data.phone || '-';
    document.getElementById('modalMessage').textContent = data.message || '';
    
    // Format Waktu
    if (data.created_at) {
        const d = new Date(data.created_at);
        document.getElementById('modalDate').textContent = d.toLocaleDateString('id-ID', {
            day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit'
        }) + ' WIB';
    } else {
        document.getElementById('modalDate').textContent = '-';
    }

    // Category Badge
    const catBadge = document.getElementById('modalCategoryBadge');
    catBadge.textContent = (data.category || 'UMUM').toUpperCase();
    catBadge.className = 'text-[10px] font-bold px-2 py-0.5 rounded-md border uppercase ';
    if (data.category === 'ppdb') catBadge.className += 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300';
    else if (data.category === 'akademik') catBadge.className += 'border-sky-500/30 bg-sky-500/10 text-sky-300';
    else if (data.category === 'keuangan') catBadge.className += 'border-amber-500/30 bg-amber-500/10 text-amber-300';
    else if (data.category === 'teknis') catBadge.className += 'border-indigo-500/30 bg-indigo-500/10 text-indigo-300';
    else catBadge.className += 'border-slate-500/30 bg-slate-500/10 text-slate-300';

    // Status Select
    document.getElementById('modalStatusSelect').value = data.status || 'baru';

    // Link WhatsApp
    const waBtn = document.getElementById('modalWaBtn');
    let cleanPhone = (data.phone || '').replace(/[^0-9]/g, '');
    if (cleanPhone.startsWith('0')) {
        cleanPhone = '62' + cleanPhone.substring(1);
    }
    if (cleanPhone) {
        waBtn.classList.remove('hidden');
        const text = encodeURIComponent(`Halo ${data.name}, terima kasih telah menghubungi pihak sekolah. Menindaklanjuti pesan Anda terkait [${data.ticket_code}: ${data.subject}], `);
        waBtn.href = `https://wa.me/${cleanPhone}?text=${text}`;
    } else {
        waBtn.classList.add('hidden');
    }

    // Link Mailto
    const mailBtn = document.getElementById('modalMailBtn');
    if (data.email) {
        mailBtn.classList.remove('hidden');
        mailBtn.href = `mailto:${encodeURIComponent(data.email)}?subject=${encodeURIComponent('Tindak Lanjut Pesan [' + data.ticket_code + ']: ' + data.subject)}`;
    } else {
        mailBtn.classList.add('hidden');
    }

    document.getElementById('contactDetailModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeDetailModal() {
    document.getElementById('contactDetailModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
}

// Close on backdrop
document.getElementById('contactDetailModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeDetailModal();
    }
});

// Close on Esc
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDetailModal();
    }
});
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
