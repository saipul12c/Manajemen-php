<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireLogin();

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';

$message_status = "";
$message_status_type = "";

// -------------------------------------------------------------
// 1. KIRIM PESAN BARU
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    if (!validateCsrfToken()) {
        $message_status = "Token keamanan tidak valid.";
        $message_status_type = "error";
    } else {
        $receiver_id = (int)($_POST['receiver_id'] ?? 0);
        $text_message = trim($_POST['message'] ?? '');

        if ($receiver_id <= 0 || empty($text_message)) {
            $message_status = "Pesan teks dan tujuan tidak boleh kosong.";
            $message_status_type = "error";
        } elseif ($receiver_id === $user_id) {
            $message_status = "Anda tidak dapat mengirim pesan kepada diri sendiri.";
            $message_status_type = "error";
        } else {
            $stmt_send = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, message, is_read)
                VALUES (?, ?, ?, 0)
            ");
            $stmt_send->execute([$user_id, $receiver_id, $text_message]);

            logActivity($pdo, 'SEND_MESSAGE', "Mengirim pesan ke pengguna ID $receiver_id");
            header("Location: messages.php?contact_id=$receiver_id");
            exit;
        }
    }
}

// -------------------------------------------------------------
// 2. QUERY KONTAK & PERCAKAPAN
// -------------------------------------------------------------
// Dapatkan semua user yang pernah berkirim pesan dengan user saat ini
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
$stmt_contacts = $pdo->prepare("
    SELECT DISTINCT 
        CASE WHEN m.sender_id = :uid THEN m.receiver_id ELSE m.sender_id END as contact_id,
        u.name as contact_name,
        u.email as contact_email,
        u.role as contact_role,
        (SELECT msg.message FROM messages msg 
         WHERE (msg.sender_id = :uid AND msg.receiver_id = u.id) 
            OR (msg.sender_id = u.id AND msg.receiver_id = :uid)
         ORDER BY msg.id DESC LIMIT 1) as last_message,
        (SELECT msg.created_at FROM messages msg 
         WHERE (msg.sender_id = :uid AND msg.receiver_id = u.id) 
            OR (msg.sender_id = u.id AND msg.receiver_id = :uid)
         ORDER BY msg.id DESC LIMIT 1) as last_message_time,
        (SELECT COUNT(*) FROM messages msg 
         WHERE msg.sender_id = u.id AND msg.receiver_id = :uid AND msg.is_read = 0) as unread_count
    FROM messages m
    JOIN users u ON u.id = (CASE WHEN m.sender_id = :uid THEN m.receiver_id ELSE m.sender_id END)
    WHERE m.sender_id = :uid OR m.receiver_id = :uid
    ORDER BY last_message_time DESC
");
$stmt_contacts->execute([':uid' => $user_id]);
$conversations = $stmt_contacts->fetchAll();
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

// Tentukan kontak aktif yang sedang dibuka
$active_contact_id = isset($_GET['contact_id']) ? (int)$_GET['contact_id'] : ($conversations[0]['contact_id'] ?? null);

$active_contact = null;
$chat_messages = [];

if ($active_contact_id && $active_contact_id !== $user_id) {
    // Ambil info kontak aktif
    $stmt_c = $pdo->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
    $stmt_c->execute([$active_contact_id]);
    $active_contact = $stmt_c->fetch();

    if ($active_contact) {
        // Tandai semua pesan dari kontak ini sebagai sudah dibaca
        $stmt_read = $pdo->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
        ");
        $stmt_read->execute([$active_contact_id, $user_id]);

        // Ambil riwayat percakapan
        $stmt_chat = $pdo->prepare("
            SELECT * FROM messages
            WHERE (sender_id = ? AND receiver_id = ?)
               OR (sender_id = ? AND receiver_id = ?)
            ORDER BY id ASC
        ");
        $stmt_chat->execute([$user_id, $active_contact_id, $active_contact_id, $user_id]);
        $chat_messages = $stmt_chat->fetchAll();
    }
}

// Ambil daftar seluruh user untuk modal "Mulai Chat Baru"
$stmt_all_users = $pdo->prepare("
    SELECT id, name, email, role, 
           (SELECT c.name FROM classes c WHERE c.id = users.class_id) as class_name
    FROM users 
    WHERE id != ? 
    ORDER BY role ASC, name ASC
");
$stmt_all_users->execute([$user_id]);
$all_system_users = $stmt_all_users->fetchAll();

$page_title = "Pesan & Konsultasi Internal";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- Top Action Bar -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white flex items-center gap-2.5">
                <span class="text-blue-400"><i class="fa-solid fa-comments"></i></span> Pesan & Konsultasi Internal
            </h1>
            <p class="text-sm text-slate-400 mt-1">
                Saluran komunikasi resmi antara guru, orang tua murid, siswa, dan staf administrasi sekolah.
            </p>
        </div>

        <button onclick="document.getElementById('modalNewChat').classList.remove('hidden')" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 transition cursor-pointer self-start sm:self-auto">
            <i class="fa-solid fa-pen-to-square"></i> Mulai Percakapan Baru
        </button>
    </div>

    <!-- Alert Message -->
    <?php if (!empty($message_status)): ?>
        <div class="rounded-xl border p-4 <?= $message_status_type === 'success' ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/20 bg-rose-500/10 text-rose-300' ?> text-sm">
            <?= htmlspecialchars($message_status) ?>
        </div>
    <?php endif; ?>

    <!-- Main Two-Column Chat Box -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/60 shadow-2xl backdrop-blur overflow-hidden grid grid-cols-1 md:grid-cols-12 min-h-[580px]">

        <!-- Left: Conversations Threads List (4 Cols) -->
        <div class="md:col-span-4 border-r border-white/10 flex flex-col bg-slate-950/40">
            <!-- Sidebar Header -->
            <div class="p-4 border-b border-white/10 flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Kontak Percakapan</span>
                <span class="text-xs text-slate-500"><?= count($conversations) ?> kontak</span>
            </div>

            <!-- Thread List -->
            <div class="flex-1 overflow-y-auto divide-y divide-white/5">
                <?php if (empty($conversations)): ?>
                    <div class="p-8 text-center text-xs text-slate-500">
                        Belum ada riwayat percakapan.<br>Klik tombol di atas untuk memulai.
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $c): ?>
                        <a href="messages.php?contact_id=<?= $c['contact_id'] ?>" class="flex items-start gap-3 p-3.5 transition <?= ($active_contact_id == $c['contact_id']) ? 'bg-blue-600/15 border-l-4 border-blue-500' : 'hover:bg-white/[0.03]' ?>">
                            <!-- Avatar -->
                            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-800 text-sm font-bold text-white shrink-0 border border-white/10 shadow-sm">
                                <?= strtoupper(substr($c['contact_name'], 0, 2)) ?>
                            </div>

                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-1">
                                    <h4 class="text-sm font-bold text-white truncate"><?= htmlspecialchars($c['contact_name']) ?></h4>
                                    <?php if (!empty($c['last_message_time'])): ?>
                                        <span class="text-[10px] text-slate-500 shrink-0 font-mono">
                                            <?= date('H:i', strtotime($c['last_message_time'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span class="rounded px-1.5 py-0.2 text-[9px] font-bold uppercase tracking-wider <?= getRoleBadge($c['contact_role']) ?>">
                                        <?= htmlspecialchars(getRoleLabel($c['contact_role'])) ?>
                                    </span>
                                </div>

                                <p class="text-xs text-slate-400 truncate mt-1">
                                    <?= htmlspecialchars($c['last_message'] ?: 'Belum ada pesan.') ?>
                                </p>
                            </div>

                            <?php if ($c['unread_count'] > 0): ?>
                                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-rose-600 text-[10px] font-bold text-white shrink-0 shadow-md">
                                    <?= $c['unread_count'] ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Active Chat View (8 Cols) -->
        <div class="md:col-span-8 flex flex-col justify-between bg-slate-900/30">
            <?php if (!$active_contact): ?>
                <div class="flex-1 flex flex-col items-center justify-center p-8 text-center text-slate-500">
                    <span class="text-5xl block mb-3 text-slate-600"><i class="fa-solid fa-comments"></i></span>
                    <h3 class="text-base font-bold text-slate-300">Pilih Kontak untuk Memulai Pesan</h3>
                    <p class="text-xs text-slate-500 mt-1 max-w-sm">
                        Gunakan panel di sebelah kiri atau klik tombol "Mulai Percakapan Baru" untuk berdiskusi dengan dewan guru, staf, atau wali murid.
                    </p>
                </div>
            <?php else: ?>
                <!-- Active Contact Bar -->
                <div class="p-4 border-b border-white/10 flex items-center justify-between bg-slate-950/60">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-600 text-sm font-bold text-white shadow-md">
                            <?= strtoupper(substr($active_contact['name'], 0, 2)) ?>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-white"><?= htmlspecialchars($active_contact['name']) ?></h3>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="rounded px-1.5 py-0.2 text-[9px] font-bold uppercase tracking-wider <?= getRoleBadge($active_contact['role']) ?>">
                                    <?= htmlspecialchars(getRoleLabel($active_contact['role'])) ?>
                                </span>
                                <span class="text-xs text-slate-400"><?= htmlspecialchars($active_contact['email']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages Thread View -->
                <div id="chatMessagesBox" class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-3.5 max-h-[460px]">
                    <?php if (empty($chat_messages)): ?>
                        <div class="py-12 text-center text-xs text-slate-500">
                            Belum ada pesan dalam percakapan ini. Kirimkan pesan pertama di bawah!
                        </div>
                    <?php else: ?>
                        <?php foreach ($chat_messages as $msg): ?>
                            <?php $is_me = ($msg['sender_id'] == $user_id); ?>
                            <div class="flex flex-col <?= $is_me ? 'items-end' : 'items-start' ?>">
                                <div class="max-w-md rounded-2xl px-4 py-2.5 text-sm shadow-md <?= $is_me ? 'bg-blue-600 text-white rounded-br-none' : 'bg-slate-800 text-slate-200 rounded-bl-none border border-white/5' ?>">
                                    <p class="leading-relaxed whitespace-pre-wrap"><?= htmlspecialchars($msg['message']) ?></p>
                                </div>
                                <span class="text-[10px] text-slate-500 font-mono mt-1 px-1">
                                    <?= date('H:i', strtotime($msg['created_at'])) ?>
                                    <?php if ($is_me): ?>
                                        <span class="ml-1"><i class="fa-solid <?= $msg['is_read'] ? 'fa-check-double text-blue-400' : 'fa-check text-slate-400' ?>"></i></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Message Input Footer -->
                <div class="p-3.5 border-t border-white/10 bg-slate-950/60">
                    <form method="POST" class="flex items-center gap-2">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="send_message">
                        <input type="hidden" name="receiver_id" value="<?= $active_contact['id'] ?>">

                        <input type="text" name="message" required placeholder="Tuliskan pesan atau konsultasi di sini..." autocomplete="off" class="flex-1 rounded-2xl border border-white/10 bg-slate-900 px-4 py-2.5 text-sm text-white focus:border-blue-500 focus:outline-none">
                        
                        <button type="submit" class="flex items-center justify-center rounded-2xl bg-blue-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 transition cursor-pointer">
                            <span class="inline-flex items-center gap-1.5">Kirim <i class="fa-solid fa-paper-plane text-xs"></i></span>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<!-- Modal Mulai Percakapan Baru -->
<div id="modalNewChat" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm hidden">
    <div class="w-full max-w-md rounded-2xl border border-white/10 bg-slate-900 p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
                <span class="text-blue-400"><i class="fa-solid fa-pen-to-square"></i></span> Percakapan Baru
            </h3>
            <button onclick="document.getElementById('modalNewChat').classList.add('hidden')" class="text-slate-400 hover:text-white cursor-pointer text-lg font-bold">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" class="space-y-4">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_message">

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Pilih Penerima Pesan</label>
                <select name="receiver_id" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:outline-none">
                    <option value="">-- Pilih Civitas Sekolah --</option>
                    <?php foreach ($all_system_users as $usr): ?>
                        <option value="<?= $usr['id'] ?>">
                            <?= htmlspecialchars($usr['name']) ?> (<?= getRoleLabel($usr['role']) ?><?= $usr['class_name'] ? ' - ' . $usr['class_name'] : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Pesan Awal</label>
                <textarea name="message" rows="3" required placeholder="Tuliskan pesan konsultasi Anda..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-sm text-white focus:outline-none"></textarea>
            </div>

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-white/10">
                <button type="button" onclick="document.getElementById('modalNewChat').classList.add('hidden')" class="rounded-xl border border-white/10 px-4 py-2 text-sm font-medium text-slate-300 hover:bg-white/5 cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 px-5 py-2 text-sm font-medium text-white shadow-lg shadow-blue-500/25 hover:bg-blue-500 cursor-pointer">
                    Kirim Pesan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Auto scroll to bottom of chat
document.addEventListener('DOMContentLoaded', function() {
    const box = document.getElementById('chatMessagesBox');
    if (box) {
        box.scrollTop = box.scrollHeight;
    }
});
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
