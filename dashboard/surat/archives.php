<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['staf', 'administrator']);

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'staf';

$message = "";
$message_type = "";

// Buat direktori upload berkas surat jika belum ada
$upload_letter_dir = __DIR__ . "/../../uploads/letters";
if (!is_dir($upload_letter_dir)) {
    mkdir($upload_letter_dir, 0755, true);
}

// -------------------------------------------------------------
// 1. TAMBAH ARSIP SURAT MASUK / SURAT KELUAR
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_mail') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $mail_type = $_POST['mail_type'] === 'keluar' ? 'keluar' : 'masuk';
        $agenda_no = trim($_POST['agenda_no'] ?? '');
        $reference_no = trim($_POST['reference_no'] ?? '');
        $sender_or_recipient = trim($_POST['sender_or_recipient'] ?? '');
        $mail_date = !empty($_POST['mail_date']) ? $_POST['mail_date'] : date('Y-m-d');
        $received_or_sent_date = !empty($_POST['received_or_sent_date']) ? $_POST['received_or_sent_date'] : date('Y-m-d');
        $subject = trim($_POST['subject'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $disposition_instruction = trim($_POST['disposition_instruction'] ?? '');
        $disposition_target = trim($_POST['disposition_target'] ?? '');
        $attachment_file = null;

        // Auto-generate agenda_no jika dikosongkan
        if ($agenda_no === '') {
            $prefix = ($mail_type === 'masuk') ? 'AG-IN/' : 'AG-OUT/';
            $stmt_cnt = $pdo->prepare("SELECT COUNT(*) FROM mail_archives WHERE mail_type = ? AND YEAR(created_at) = YEAR(CURDATE())");
            $stmt_cnt->execute([$mail_type]);
            $cnt = $stmt_cnt->fetchColumn() + 1;
            $agenda_no = $prefix . date('Y/m/') . sprintf('%03d', $cnt);
        }

        // Upload berkas scan PDF / Gambar
        if (isset($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === UPLOAD_ERR_OK) {
            $file_tmp  = $_FILES['attachment_file']['tmp_name'];
            $file_name = $_FILES['attachment_file']['name'];
            $file_size = $_FILES['attachment_file']['size'];
            $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext   = ['pdf', 'jpg', 'jpeg', 'png'];

            if (!in_array($file_ext, $allowed_ext, true)) {
                $message = "Format file berkas harus berupa PDF, JPG, atau PNG.";
                $message_type = "error";
            } elseif ($file_size > 15 * 1024 * 1024) {
                $message = "Ukuran file berkas maksimal 15MB.";
                $message_type = "error";
            } else {
                $new_filename = 'agenda_' . $mail_type . '_' . time() . '_' . rand(100, 999) . '.' . $file_ext;
                $destination  = $upload_letter_dir . '/' . $new_filename;
                if (move_uploaded_file($file_tmp, $destination)) {
                    $attachment_file = '../../uploads/letters/' . $new_filename;
                }
            }
        }

        if ($message_type !== 'error') {
            if ($reference_no === '' || $sender_or_recipient === '' || $subject === '') {
                $message = "Nomor surat, instansi/tujuan, dan perihal wajib diisi.";
                $message_type = "error";
            } else {
                $stmt_ins = $pdo->prepare("
                    INSERT INTO mail_archives 
                    (mail_type, agenda_no, reference_no, sender_or_recipient, mail_date, received_or_sent_date, subject, description, disposition_instruction, disposition_target, attachment_file, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_ins->execute([
                    $mail_type, $agenda_no, $reference_no, $sender_or_recipient, 
                    $mail_date, $received_or_sent_date, $subject, $description, 
                    $disposition_instruction, $disposition_target, $attachment_file, $user_id
                ]);
                logActivity($pdo, 'CREATE_MAIL_ARCHIVE', "Mencatat arsip surat $mail_type: $reference_no ($subject)");
                $message = "Surat " . strtoupper($mail_type) . " berhasil dicatat ke dalam Buku Agenda!";
                $message_type = "success";
            }
        }
    }
}

// -------------------------------------------------------------
// 2. UPDATE DISPOSISI KEPALA SEKOLAH
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_disposition') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $mail_id = (int)($_POST['mail_id'] ?? 0);
        $disposition_instruction = trim($_POST['disposition_instruction'] ?? '');
        $disposition_target = trim($_POST['disposition_target'] ?? '');

        if ($mail_id > 0) {
            $stmt_upd = $pdo->prepare("UPDATE mail_archives SET disposition_instruction = ?, disposition_target = ? WHERE id = ?");
            $stmt_upd->execute([$disposition_instruction, $disposition_target, $mail_id]);
            logActivity($pdo, 'UPDATE_DISPOSITION', "Memperbarui disposisi surat ID: $mail_id");
            $message = "Lembar disposisi surat berhasil diperbarui.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 3. HAPUS ARSIP SURAT
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_mail') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan tidak valid.";
        $message_type = "error";
    } else {
        $delete_id = (int)($_POST['delete_id'] ?? 0);
        $stmt_f = $pdo->prepare("SELECT reference_no, mail_type FROM mail_archives WHERE id = ?");
        $stmt_f->execute([$delete_id]);
        $row_del = $stmt_f->fetch();

        if ($row_del) {
            $pdo->prepare("DELETE FROM mail_archives WHERE id = ?")->execute([$delete_id]);
            logActivity($pdo, 'DELETE_MAIL_ARCHIVE', "Menghapus arsip surat: " . $row_del['reference_no']);
            $message = "Arsip surat berhasil dihapus dari buku agenda.";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// 4. QUERY DAN FILTER BUKU AGENDA
// -------------------------------------------------------------
$tab = trim($_GET['tab'] ?? 'masuk');
if (!in_array($tab, ['semua', 'masuk', 'keluar'], true)) {
    $tab = 'masuk';
}
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT m.*, u.name as recorder_name 
    FROM mail_archives m 
    LEFT JOIN users u ON m.recorded_by = u.id 
    WHERE 1=1
";
$params = [];

if ($tab !== 'semua') {
    $sql .= " AND m.mail_type = ?";
    $params[] = $tab;
}

if ($search !== '') {
    $sql .= " AND (m.reference_no LIKE ? OR m.agenda_no LIKE ? OR m.sender_or_recipient LIKE ? OR m.subject LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY m.received_or_sent_date DESC, m.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$mails_list = $stmt->fetchAll();

// Statistik Persuratan
$stat_total = (int)$pdo->query("SELECT COUNT(*) FROM mail_archives")->fetchColumn();
$stat_masuk = (int)$pdo->query("SELECT COUNT(*) FROM mail_archives WHERE mail_type = 'masuk'")->fetchColumn();
$stat_keluar = (int)$pdo->query("SELECT COUNT(*) FROM mail_archives WHERE mail_type = 'keluar'")->fetchColumn();
$stat_disposisi_pending = (int)$pdo->query("SELECT COUNT(*) FROM mail_archives WHERE mail_type = 'masuk' AND (disposition_instruction IS NULL OR disposition_instruction = '')")->fetchColumn();

$page_title = "Buku Agenda Surat Masuk & Keluar";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white flex items-center gap-2">
            <span class="text-amber-400"><i class="fa-solid fa-book-bookmark"></i></span> Buku Agenda Surat Masuk & Keluar
        </h1>
        <p class="mt-1 text-sm text-slate-400">
            Pencatatan nomor agenda, korespondensi instansi kedinasan, disposisi pimpinan, dan arsip dokumen digital.
        </p>
    </div>

    <div class="flex items-center gap-2 flex-wrap">
        <button onclick="openModalCreate('masuk')" 
                class="inline-flex items-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-blue-500/20 transition cursor-pointer">
            <i class="fa-solid fa-inbox"></i> Catat Surat Masuk
        </button>
        <button onclick="openModalCreate('keluar')" 
                class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/20 transition cursor-pointer">
            <i class="fa-solid fa-paper-plane"></i> Catat Surat Keluar
        </button>
        <a href="requests.php" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2.5 text-sm font-semibold text-slate-300 transition flex items-center gap-2">
            <i class="fa-solid fa-file-signature"></i> Layanan Persuratan Siswa
        </a>
    </div>
</div>

<!-- Alert Notifikasi -->
<?php if ($message !== ''): ?>
    <div class="mb-6 rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
        <div class="flex items-center gap-3">
            <span><i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-amber-400' ?>"></i></span>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100 cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
    </div>
<?php endif; ?>

<!-- Kartu Ringkasan Statistik Arsip -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-slate-400">Total Arsip Surat</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/20 text-blue-400"><i class="fa-solid fa-archive text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stat_total) ?></p>
        <span class="text-[11px] text-slate-500">Seluruh dokumen terdaftar</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-cyan-400">Surat Masuk</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-cyan-500/20 text-cyan-400"><i class="fa-solid fa-inbox text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stat_masuk) ?></p>
        <span class="text-[11px] text-slate-500">Dari dinas & instansi luar</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-indigo-400">Surat Keluar</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-500/20 text-indigo-400"><i class="fa-solid fa-paper-plane text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stat_keluar) ?></p>
        <span class="text-[11px] text-slate-500">Diterbitkan oleh sekolah</span>
    </div>

    <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 shadow-lg backdrop-blur">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-amber-400">Perlu Disposisi</span>
            <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/20 text-amber-400"><i class="fa-solid fa-pen-fancy text-xs"></i></span>
        </div>
        <p class="text-2xl font-black text-white mt-2"><?= number_format($stat_disposisi_pending) ?></p>
        <span class="text-[11px] text-slate-500">Surat belum ada arahan</span>
    </div>
</div>

<!-- Navigasi Tab & Pencarian -->
<div class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
    <!-- Tab Filter -->
    <div class="flex rounded-2xl border border-white/10 bg-slate-900/60 p-1">
        <a href="archives.php?tab=masuk<?= $search ? '&search='.urlencode($search) : '' ?>" 
           class="px-4 py-2 rounded-xl text-xs font-bold transition <?= $tab === 'masuk' ? 'bg-blue-600 text-white shadow-md' : 'text-slate-400 hover:text-white' ?>">
            <i class="fa-solid fa-inbox mr-1.5"></i> Surat Masuk (<?= $stat_masuk ?>)
        </a>
        <a href="archives.php?tab=keluar<?= $search ? '&search='.urlencode($search) : '' ?>" 
           class="px-4 py-2 rounded-xl text-xs font-bold transition <?= $tab === 'keluar' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-400 hover:text-white' ?>">
            <i class="fa-solid fa-paper-plane mr-1.5"></i> Surat Keluar (<?= $stat_keluar ?>)
        </a>
        <a href="archives.php?tab=semua<?= $search ? '&search='.urlencode($search) : '' ?>" 
           class="px-4 py-2 rounded-xl text-xs font-bold transition <?= $tab === 'semua' ? 'bg-slate-700 text-white shadow-md' : 'text-slate-400 hover:text-white' ?>">
            Semua (<?= $stat_total ?>)
        </a>
    </div>

    <!-- Search Form -->
    <form method="GET" class="flex gap-2">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div class="relative w-full sm:w-72">
            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-3 text-xs text-slate-500"></i>
            <input 
                type="text" 
                name="search" 
                value="<?= htmlspecialchars($search) ?>" 
                placeholder="Cari nomor, perihal, atau instansi..." 
                class="w-full rounded-xl border border-white/10 bg-slate-950 pl-9 pr-4 py-2 text-xs text-white placeholder-slate-500 outline-none focus:border-blue-500"
            >
        </div>
        <button type="submit" class="rounded-xl bg-slate-800 hover:bg-slate-700 px-4 py-2 text-xs font-semibold text-slate-200 transition">
            Cari
        </button>
        <?php if ($search !== ''): ?>
            <a href="archives.php?tab=<?= $tab ?>" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-2 text-xs font-semibold text-slate-400 transition">
                Reset
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabel Buku Agenda -->
<div class="overflow-hidden rounded-3xl border border-white/10 bg-slate-900/60 shadow-2xl backdrop-blur">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm text-slate-300">
            <thead class="border-b border-white/10 bg-white/5 text-xs uppercase tracking-wider text-slate-400">
                <tr>
                    <th class="px-6 py-4 font-semibold">No. Agenda & Jenis</th>
                    <th class="px-6 py-4 font-semibold">Nomor & Tgl Surat</th>
                    <th class="px-6 py-4 font-semibold">Pengirim / Penerima</th>
                    <th class="px-6 py-4 font-semibold">Perihal & Ringkasan</th>
                    <th class="px-6 py-4 font-semibold">Disposisi Pimpinan</th>
                    <th class="px-6 py-4 font-semibold text-right">Aksi & Berkas</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
                <?php if (empty($mails_list)): ?>
                    <tr>
                        <td colspan="6" class="py-12 text-center text-slate-400 text-sm">
                            Belum ada arsip surat pada kategori ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($mails_list as $m): ?>
                        <tr class="hover:bg-white/[0.02] transition">
                            <td class="px-6 py-4">
                                <span class="font-mono text-xs font-bold text-white block">
                                    <?= htmlspecialchars($m['agenda_no']) ?>
                                </span>
                                <span class="inline-flex items-center gap-1 rounded px-2 py-0.5 text-[10px] font-bold uppercase mt-1 <?= $m['mail_type'] === 'masuk' ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/30' : 'bg-indigo-500/20 text-indigo-300 border border-indigo-500/30' ?>">
                                    <i class="fa-solid <?= $m['mail_type'] === 'masuk' ? 'fa-inbox' : 'fa-paper-plane' ?>"></i> Surat <?= ucfirst($m['mail_type']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-100 text-xs font-mono"><?= htmlspecialchars($m['reference_no']) ?></div>
                                <div class="text-[11px] text-slate-400 mt-0.5">
                                    Tgl Surat: <?= date('d M Y', strtotime($m['mail_date'])) ?>
                                </div>
                                <div class="text-[10px] text-slate-500">
                                    <?= $m['mail_type'] === 'masuk' ? 'Diterima' : 'Dikirim' ?>: <?= date('d M Y', strtotime($m['received_or_sent_date'])) ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-semibold text-white"><?= htmlspecialchars($m['sender_or_recipient']) ?></div>
                                <div class="text-[11px] text-slate-500 mt-0.5 font-mono">
                                    Dicatat: <?= htmlspecialchars($m['recorder_name'] ?? 'Staf') ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 max-w-xs">
                                <div class="font-medium text-slate-200"><?= htmlspecialchars($m['subject']) ?></div>
                                <?php if (!empty($m['description'])): ?>
                                    <div class="text-xs text-slate-400 mt-1 line-clamp-2 italic">
                                        <?= htmlspecialchars($m['description']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($m['mail_type'] === 'masuk'): ?>
                                    <?php if (!empty($m['disposition_instruction'])): ?>
                                        <div class="p-2.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-300">
                                            <div class="font-bold flex items-center gap-1">
                                                <i class="fa-solid fa-stamp text-[10px]"></i> <?= htmlspecialchars($m['disposition_target'] ?: 'Seluruh Tim') ?>:
                                            </div>
                                            <p class="mt-0.5 italic">"<?= htmlspecialchars($m['disposition_instruction']) ?>"</p>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-500 italic flex items-center gap-1">
                                            <i class="fa-solid fa-hourglass text-[10px]"></i> Belum ada disposisi
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-xs text-slate-500">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                    <?php if (!empty($m['attachment_file'])): ?>
                                        <a href="<?= htmlspecialchars($m['attachment_file']) ?>" target="_blank" 
                                           class="rounded-lg border border-blue-500/30 bg-blue-500/10 hover:bg-blue-500/20 px-2.5 py-1.5 text-xs font-semibold text-blue-300 transition inline-flex items-center gap-1" title="Lihat Berkas Lampiran">
                                            <i class="fa-solid fa-file-pdf"></i> Berkas
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($m['mail_type'] === 'masuk'): ?>
                                        <button onclick='openModalDisposition(<?= json_encode($m) ?>)' 
                                                class="rounded-lg border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 px-2.5 py-1.5 text-xs font-semibold text-amber-300 transition cursor-pointer" title="Isi Lembar Disposisi">
                                            <i class="fa-solid fa-pen-fancy"></i> Disposisi
                                        </button>
                                    <?php endif; ?>

                                    <form method="POST" class="inline" onsubmit="return confirm('Hapus arsip surat <?= addslashes(htmlspecialchars($m['reference_no'])) ?> dari agenda?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_mail">
                                        <input type="hidden" name="delete_id" value="<?= $m['id'] ?>">
                                        <button type="submit" class="rounded-lg border border-rose-500/20 bg-rose-500/10 hover:bg-rose-500/20 px-2 py-1.5 text-xs font-semibold text-rose-400 transition cursor-pointer" title="Hapus">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL: CATAT SURAT MASUK / SURAT KELUAR -->
<!-- ========================================================= -->
<div id="modalCreateMail" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-2xl rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 id="modalMailTitle" class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-blue-400"><i class="fa-solid fa-envelope-circle-check"></i></span> Catat Surat Agenda
            </h3>
            <button onclick="document.getElementById('modalCreateMail').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_mail">
            <input type="hidden" id="formMailType" name="mail_type" value="masuk">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Nomor Agenda (Opsional)</label>
                    <input type="text" name="agenda_no" placeholder="Otomatis jika dikosongkan..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500 font-mono">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Nomor Surat Resmi *</label>
                    <input type="text" name="reference_no" required placeholder="Contoh: 420/125/Disdik/IX/2026" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500 font-mono">
                </div>
            </div>

            <div>
                <label id="lblSenderRecipient" class="mb-1 block text-xs font-semibold uppercase text-slate-300">Instansi Pengirim *</label>
                <input type="text" name="sender_or_recipient" required placeholder="Contoh: Dinas Pendidikan Provinsi / Puskesmas / SMPN 1" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Tanggal Surat Tertera *</label>
                    <input type="date" name="mail_date" required value="<?= date('Y-m-d') ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500">
                </div>
                <div>
                    <label id="lblDateReceivedSent" class="mb-1 block text-xs font-semibold uppercase text-slate-300">Tanggal Diterima *</label>
                    <input type="date" name="received_or_sent_date" required value="<?= date('Y-m-d') ?>" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Perihal / Pokok Surat *</label>
                <input type="text" name="subject" required placeholder="Contoh: Sosialisasi Bantuan PIP / Undangan Rapat Koordinasi" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Ringkasan Isi / Catatan Surat</label>
                <textarea name="description" rows="2" placeholder="Tuliskan ringkasan isi surat..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-blue-500"></textarea>
            </div>

            <div id="sectionDisposition">
                <div class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4 space-y-3">
                    <span class="text-xs font-bold text-amber-300 uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fa-solid fa-stamp"></i> Arahan Disposisi Kepala Sekolah (Opsional)
                    </span>
                    <div>
                        <label class="mb-1 block text-[11px] text-slate-400">Diteruskan Kepada (Target Disposisi):</label>
                        <input type="text" name="disposition_target" placeholder="Contoh: Waka Kurikulum / Pembina UKS / Bendahara BOS" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white outline-none focus:border-amber-500">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] text-slate-400">Instruksi / Catatan Disposisi:</label>
                        <input type="text" name="disposition_instruction" placeholder="Contoh: Hadiri dan koordinasikan tindak lanjut ke dewan guru" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white outline-none focus:border-amber-500">
                    </div>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Unggah Scan Surat / Berkas PDF (Opsional)</label>
                <input type="file" name="attachment_file" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-slate-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-blue-600 file:text-white hover:file:bg-blue-500">
                <p class="text-[11px] text-slate-500 mt-1">Format: PDF, JPG, atau PNG. Maksimal 15 MB.</p>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalCreateMail').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-blue-500/25 cursor-pointer flex items-center gap-2">
                    <i class="fa-solid fa-save"></i> Simpan ke Buku Agenda
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================================= -->
<!-- MODAL: ISI / PERBARUI DISPOSISI KEPALA SEKOLAH -->
<!-- ========================================================= -->
<div id="modalDisposition" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur p-4 hidden">
    <div class="w-full max-w-lg rounded-3xl border border-white/10 bg-slate-900 p-6 sm:p-8 shadow-2xl">
        <div class="flex items-center justify-between pb-4 border-b border-white/10 mb-6">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-amber-400"><i class="fa-solid fa-stamp"></i></span> Lembar Disposisi Kepala Sekolah
            </h3>
            <button onclick="document.getElementById('modalDisposition').classList.add('hidden')" class="text-slate-400 hover:text-white text-lg font-bold cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_disposition">
            <input type="hidden" id="dispMailId" name="mail_id" value="">

            <div class="p-3 rounded-xl bg-slate-950/70 border border-white/5 text-xs space-y-1">
                <div class="font-bold text-white" id="dispSubject"></div>
                <div class="text-slate-400" id="dispRef"></div>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Diteruskan Kepada (Target Tindak Lanjut) *</label>
                <input type="text" id="dispTarget" name="disposition_target" required placeholder="Contoh: Waka Kurikulum / Staf TU / Guru BK" class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500">
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-300">Instruksi / Catatan Disposisi *</label>
                <textarea id="dispInstruction" name="disposition_instruction" rows="3" required placeholder="Contoh: Pelajari dan laksanakan koordinasi jadwal dengan pihak terkait..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-4 py-2 text-sm text-white outline-none focus:border-amber-500"></textarea>
            </div>

            <div class="mt-6 flex justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalDisposition').classList.add('hidden')" class="rounded-xl border border-white/10 bg-white/5 px-4 py-2 text-sm font-semibold text-slate-300 hover:bg-white/10 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-amber-600 hover:bg-amber-500 px-5 py-2 text-sm font-semibold text-white transition shadow-lg shadow-amber-500/25 cursor-pointer">
                    Simpan Disposisi
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openModalCreate(type) {
    document.getElementById('formMailType').value = type;
    const modal = document.getElementById('modalCreateMail');
    const title = document.getElementById('modalMailTitle');
    const lblSender = document.getElementById('lblSenderRecipient');
    const lblDate = document.getElementById('lblDateReceivedSent');
    const secDisp = document.getElementById('sectionDisposition');

    if (type === 'masuk') {
        title.innerHTML = '<span class="text-cyan-400"><i class="fa-solid fa-inbox"></i></span> Catat Surat Masuk Baru';
        lblSender.innerText = 'Instansi Pengirim *';
        lblDate.innerText = 'Tanggal Diterima *';
        secDisp.classList.remove('hidden');
    } else {
        title.innerHTML = '<span class="text-indigo-400"><i class="fa-solid fa-paper-plane"></i></span> Catat Surat Keluar Baru';
        lblSender.innerText = 'Instansi Tujuan Surat *';
        lblDate.innerText = 'Tanggal Dikirim *';
        secDisp.classList.add('hidden');
    }
    modal.classList.remove('hidden');
}

function openModalDisposition(mail) {
    document.getElementById('dispMailId').value = mail.id;
    document.getElementById('dispSubject').innerText = mail.subject;
    document.getElementById('dispRef').innerText = mail.reference_no + ' - ' + mail.sender_or_recipient;
    document.getElementById('dispTarget').value = mail.disposition_target || '';
    document.getElementById('dispInstruction').value = mail.disposition_instruction || '';
    document.getElementById('modalDisposition').classList.remove('hidden');
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
