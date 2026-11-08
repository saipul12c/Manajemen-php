<?php
/**
 * Konsol Manajemen Live Chat Real-time
 * Dashboard Administrator & Staf TU untuk memantau, merespons, dan mengelola 
 * obrolan langsung dari pengunjung publik (sebelum login) serta civitas sekolah (sesudah login).
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['administrator', 'staf']);

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Petugas';
$user_role = $_SESSION['user_role'] ?? 'staf';

$school_info = function_exists('getSchoolSettings') ? getSchoolSettings($pdo) : [];
$school_name = $school_info['school_name'] ?? 'Sekolah';

// Inisialisasi tabel defensif
initLiveChatTables($pdo);

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Tindakan POST synchronous jika ada (misal: Hapus sesi dari admin)
$message_alert = '';
$message_type  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validateCsrfToken()) {
        $message_alert = 'Token keamanan tidak valid. Silakan muat ulang halaman.';
        $message_type  = 'error';
    } else {
        $post_action = $_POST['action'];

        if ($post_action === 'delete_session' && $user_role === 'administrator') {
            $sess_id = (int)($_POST['session_id'] ?? 0);
            if ($sess_id > 0) {
                try {
                    $stmt_del = $pdo->prepare("DELETE FROM live_chat_sessions WHERE id = ?");
                    $stmt_del->execute([$sess_id]);
                    logActivity($pdo, 'DELETE_LIVE_CHAT', "Menghapus sesi live chat ID $sess_id");
                    $message_alert = "Sesi live chat berhasil dihapus.";
                    $message_type  = "success";
                } catch (Exception $e) {
                    $message_alert = "Gagal menghapus sesi: " . $e->getMessage();
                    $message_type  = "error";
                }
            }
        } elseif ($post_action === 'bulk_close') {
            try {
                $pdo->exec("UPDATE live_chat_sessions SET status = 'closed', updated_at = NOW() WHERE status = 'active'");
                logActivity($pdo, 'CLOSE_ALL_LIVE_CHAT', "Menandai seluruh sesi live chat aktif sebagai selesai");
                $message_alert = "Seluruh sesi aktif telah ditandai selesai.";
                $message_type  = "success";
            } catch (Exception $e) {
                $message_alert = "Gagal memperbarui status: " . $e->getMessage();
                $message_type  = "error";
            }
        }
    }
}

// Statistik Singkat
$totalSessions = (int)$pdo->query("SELECT COUNT(*) FROM live_chat_sessions")->fetchColumn();
$activeSessions = (int)$pdo->query("SELECT COUNT(*) FROM live_chat_sessions WHERE status = 'active'")->fetchColumn();
$unreadMessages = (int)$pdo->query("SELECT COUNT(*) FROM live_chat_messages WHERE is_read = 0 AND sender_type IN ('guest', 'user')")->fetchColumn();
$guestSessions  = (int)$pdo->query("SELECT COUNT(*) FROM live_chat_sessions WHERE user_id IS NULL")->fetchColumn();
$userSessions   = (int)$pdo->query("SELECT COUNT(*) FROM live_chat_sessions WHERE user_id IS NOT NULL")->fetchColumn();

$page_title = "Layanan Live Chat Masuk";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-6">

    <!-- HEADER & STATS CARD -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-tr from-teal-600 via-emerald-600 to-cyan-500 text-white shadow-lg shadow-teal-500/25">
                    <i class="fa-solid fa-headset text-lg"></i>
                </span>
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold text-white tracking-tight">Konsol Live Chat Masuk</h1>
                    <p class="text-xs text-slate-400">
                        Pusat obrolan langsung realtime dari pengunjung publik (sebelum login) dan civitas sekolah (sesudah login).
                    </p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <!-- Tombol Modal Pengaturan AI -->
            <button type="button" 
                    onclick="openAiSettingsModal()" 
                    class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-500/30 bg-indigo-500/10 hover:bg-indigo-500/20 px-3.5 py-2 text-xs font-semibold text-indigo-300 transition cursor-pointer shadow-sm shadow-indigo-500/10">
                <i class="fa-solid fa-robot text-xs text-indigo-400"></i>
                <span>Pengaturan AI</span>
            </button>

            <button type="button" 
                    onclick="fetchSessionList(true)" 
                    class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3.5 py-2 text-xs font-semibold text-slate-200 transition cursor-pointer">
                <i id="btnRefreshIcon" class="fa-solid fa-rotate text-xs"></i>
                <span>Segarkan</span>
            </button>

            <?php if ($user_role === 'administrator'): ?>
                <form method="POST" onsubmit="return confirm('Tandai seluruh sesi obrolan aktif sebagai Selesai?');" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="bulk_close">
                    <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 px-3.5 py-2 text-xs font-semibold text-rose-300 transition cursor-pointer">
                        <i class="fa-solid fa-check-double text-xs"></i>
                        <span>Selesaikan Semua</span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alert Notifikasi -->
    <?php if (!empty($message_alert)): ?>
        <div class="rounded-2xl border p-4 text-xs font-medium flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
            <div class="flex items-center gap-2">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-rose-400' ?> text-sm"></i>
                <span><?= htmlspecialchars($message_alert) ?></span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white">&times;</button>
        </div>
    <?php endif; ?>

    <!-- STATS COUNTER -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur-xl">
            <div class="flex items-center justify-between">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Pesan Belum Dibaca</p>
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-500/20 text-rose-400 text-xs">
                    <i class="fa-solid fa-envelope"></i>
                </span>
            </div>
            <p id="statUnreadCount" class="text-2xl font-black text-rose-400 mt-2"><?= $unreadMessages ?></p>
            <p class="text-[10px] text-slate-500 mt-1">Butuh respon segera</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur-xl">
            <div class="flex items-center justify-between">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Sesi Aktif</p>
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-teal-500/20 text-teal-400 text-xs">
                    <i class="fa-solid fa-comments"></i>
                </span>
            </div>
            <p id="statActiveCount" class="text-2xl font-black text-teal-400 mt-2"><?= $activeSessions ?></p>
            <p class="text-[10px] text-slate-500 mt-1">Obrolan sedang berlangsung</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur-xl">
            <div class="flex items-center justify-between">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Tamu (Sebelum Login)</p>
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500/20 text-amber-400 text-xs">
                    <i class="fa-solid fa-user-tag"></i>
                </span>
            </div>
            <p class="text-2xl font-black text-amber-300 mt-2"><?= $guestSessions ?></p>
            <p class="text-[10px] text-slate-500 mt-1">Pengunjung & Calon Siswa</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur-xl">
            <div class="flex items-center justify-between">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Civitas Terdaftar</p>
                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-blue-500/20 text-blue-400 text-xs">
                    <i class="fa-solid fa-graduation-cap"></i>
                </span>
            </div>
            <p class="text-2xl font-black text-blue-400 mt-2"><?= $userSessions ?></p>
            <p class="text-[10px] text-slate-500 mt-1">Siswa, Guru, Staf, Ortu</p>
        </div>
    </div>

    <!-- MAIN TWO-COLUMN LIVE CHAT DESK -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 min-h-[620px] h-[calc(100vh-280px)]">

        <!-- KOLOM KIRI: DAFTAR PERCAKAPAN (lg:col-span-4) -->
        <div class="lg:col-span-4 flex flex-col rounded-3xl border border-white/10 bg-slate-900/70 backdrop-blur-xl overflow-hidden shadow-xl">
            
            <!-- Filter & Search Bar -->
            <div class="p-3.5 border-b border-white/10 space-y-2.5 bg-slate-950/40">
                <div class="relative">
                    <input type="text" 
                           id="searchChatQuery" 
                           placeholder="Cari nama, email, pesan..." 
                           oninput="debounceSearch()"
                           class="w-full rounded-xl border border-white/10 bg-white/5 pl-9 pr-3.5 py-2 text-xs text-white placeholder-slate-500 focus:border-teal-500 focus:outline-none transition">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-xs text-slate-500"></i>
                </div>

                <!-- Tab Filter Kategori -->
                <div class="flex items-center gap-1 overflow-x-auto no-scrollbar pb-0.5 text-[11px]">
                    <button type="button" onclick="setSessionFilter('all', this)" class="session-tab-btn px-2.5 py-1 rounded-lg font-bold bg-teal-600 text-white transition cursor-pointer">
                        Semua
                    </button>
                    <button type="button" onclick="setSessionFilter('unread', this)" class="session-tab-btn px-2.5 py-1 rounded-lg font-semibold bg-white/5 text-slate-300 hover:text-white transition cursor-pointer">
                        Unread
                    </button>
                    <button type="button" onclick="setSessionFilter('active', this)" class="session-tab-btn px-2.5 py-1 rounded-lg font-semibold bg-white/5 text-slate-300 hover:text-white transition cursor-pointer">
                        Aktif
                    </button>
                    <button type="button" onclick="setSessionFilter('guest', this)" class="session-tab-btn px-2.5 py-1 rounded-lg font-semibold bg-white/5 text-slate-300 hover:text-white transition cursor-pointer">
                        Tamu
                    </button>
                    <button type="button" onclick="setSessionFilter('user', this)" class="session-tab-btn px-2.5 py-1 rounded-lg font-semibold bg-white/5 text-slate-300 hover:text-white transition cursor-pointer">
                        Civitas
                    </button>
                    <button type="button" onclick="setSessionFilter('closed', this)" class="session-tab-btn px-2.5 py-1 rounded-lg font-semibold bg-white/5 text-slate-300 hover:text-white transition cursor-pointer">
                        Selesai
                    </button>
                </div>
            </div>

            <!-- List Sesi Percakapan (Scrollable) -->
            <div id="sessionListContainer" class="flex-1 overflow-y-auto divide-y divide-white/5">
                <div class="p-8 text-center text-xs text-slate-500">
                    <i class="fa-solid fa-spinner fa-spin text-lg text-teal-400 mb-2"></i>
                    <p>Memuat daftar live chat...</p>
                </div>
            </div>

            <!-- Footer Kolom Kiri -->
            <div class="p-3 border-t border-white/10 bg-slate-950/60 flex items-center justify-between text-[11px] text-slate-500">
                <span id="sessionListCountText">0 obrolan</span>
                <span class="flex items-center gap-1 text-emerald-400">
                    <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>Realtime Polling Aktif</span>
                </span>
            </div>

        </div>

        <!-- KOLOM KANAN: RUANG CHAT & BALASAN ADMIN (lg:col-span-8) -->
        <div class="lg:col-span-8 flex flex-col rounded-3xl border border-white/10 bg-slate-900/70 backdrop-blur-xl overflow-hidden shadow-xl">
            
            <!-- STATE A: KOSONG (Belum Ada Sesi Dipilih) -->
            <div id="chatEmptyState" class="flex-1 flex flex-col items-center justify-center p-8 text-center">
                <div class="h-20 w-20 rounded-3xl border border-white/10 bg-white/5 flex items-center justify-center text-3xl text-slate-600 mb-4">
                    <i class="fa-solid fa-comments"></i>
                </div>
                <h3 class="text-base font-bold text-white">Belum Ada Percakapan Dipilih</h3>
                <p class="text-xs text-slate-400 max-w-sm mt-1">
                    Silakan pilih salah satu sesi obrolan di sebelah kiri untuk melihat percakapan lengkap dan membalas pengunjung.
                </p>
            </div>

            <!-- STATE B: OBROLAN AKTIF -->
            <div id="chatActiveThread" class="hidden flex-1 flex flex-col min-h-0">
                
                <!-- 1. Header Lawan Bicara -->
                <div class="p-4 border-b border-white/10 bg-slate-950/60 flex items-center justify-between shrink-0">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="relative shrink-0">
                            <span id="threadAvatar" class="flex h-11 w-11 items-center justify-center rounded-2xl bg-teal-500/20 text-teal-400 text-lg font-bold border border-teal-500/30">
                                T
                            </span>
                            <span id="threadStatusIndicator" class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full bg-emerald-400 border-2 border-slate-900"></span>
                        </div>

                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 id="threadName" class="text-sm font-bold text-white truncate">Nama Pengunjung</h3>
                                <span id="threadRoleBadge" class="text-[9px] font-extrabold px-2 py-0.5 rounded-full bg-slate-800 text-slate-300 border border-white/10 uppercase">
                                    Tamu
                                </span>
                                <span id="threadDeptBadge" class="text-[9px] font-bold px-2 py-0.5 rounded-md bg-teal-500/10 text-teal-300 border border-teal-500/20">
                                    Layanan Umum
                                </span>
                            </div>

                            <p id="threadContactInfo" class="text-[11px] text-slate-400 truncate mt-0.5">
                                email@domain.com • IP: 127.0.0.1
                            </p>
                        </div>
                    </div>

                    <!-- Tombol Aksi Cepat Header Chat -->
                    <div class="flex items-center gap-2 shrink-0">
                        <!-- Toggle AI Sesi Ini (Ambil Alih / Serahkan ke AI) -->
                        <button type="button" 
                                id="btnThreadAiToggle" 
                                onclick="toggleCurrentSessionAi()" 
                                class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-500/30 bg-indigo-500/10 hover:bg-indigo-500/20 px-3 py-1.5 text-xs font-semibold text-indigo-300 transition cursor-pointer" 
                                title="Ambil alih obrolan atau aktifkan AI kembali">
                            <i id="threadAiIcon" class="fa-solid fa-robot text-xs text-indigo-400"></i>
                            <span id="threadAiText">AI Aktif</span>
                        </button>

                        <!-- Direct WhatsApp -->
                        <a id="btnThreadWa" href="#" target="_blank" class="hidden inline-flex items-center gap-1.5 rounded-xl border border-emerald-500/30 bg-emerald-500/10 hover:bg-emerald-500/20 px-3 py-1.5 text-xs font-semibold text-emerald-300 transition">
                            <i class="fa-brands fa-whatsapp"></i>
                            <span class="hidden sm:inline">WhatsApp</span>
                        </a>

                        <!-- Direct Email -->
                        <a id="btnThreadMail" href="#" class="hidden inline-flex items-center gap-1.5 rounded-xl border border-sky-500/30 bg-sky-500/10 hover:bg-sky-500/20 px-3 py-1.5 text-xs font-semibold text-sky-300 transition">
                            <i class="fa-solid fa-envelope"></i>
                            <span class="hidden sm:inline">Email</span>
                        </a>

                        <!-- Toggle Status (Selesaikan / Buka Kembali) -->
                        <button type="button" 
                                id="btnToggleStatus" 
                                onclick="toggleCurrentSessionStatus()"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-semibold text-slate-300 transition cursor-pointer">
                            <i id="btnStatusIcon" class="fa-solid fa-circle-check text-xs"></i>
                            <span id="btnStatusText">Selesai</span>
                        </button>

                        <!-- Cetak Transkrip Arsip -->
                        <button type="button" 
                                onclick="printAdminChatTranscript()" 
                                class="inline-flex items-center gap-1.5 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-3 py-1.5 text-xs font-semibold text-slate-300 transition cursor-pointer" 
                                title="Cetak Transkrip Obrolan">
                            <i class="fa-solid fa-print text-xs"></i>
                            <span class="hidden sm:inline">Cetak</span>
                        </button>

                        <?php if ($user_role === 'administrator'): ?>
                            <!-- Hapus Sesi -->
                            <form id="formDeleteCurrentSession" method="POST" onsubmit="return confirm('Hapus percakapan ini secara permanen?');" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="action" value="delete_session">
                                <input type="hidden" name="session_id" id="deleteSessionId" value="0">
                                <button type="submit" class="p-2 rounded-xl text-slate-500 hover:text-rose-400 hover:bg-white/5 transition cursor-pointer" title="Hapus Permanen">
                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 2. Kontainer Riwayat Pesan (Scrollable) -->
                <div id="threadMessagesBox" class="flex-1 p-5 overflow-y-auto space-y-3.5 scroll-smooth bg-slate-950/20">
                    <!-- Pesan percakapan dirender via JavaScript -->
                </div>

                <!-- 3. Template Jawaban Cepat (Canned Responses) -->
                <div class="px-4 py-2 border-t border-white/5 bg-slate-950/40 flex items-center gap-2 overflow-x-auto no-scrollbar text-xs">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 shrink-0">Template Cepat:</span>
                    <button type="button" onclick="insertCannedResponse('Halo, terima kasih telah menghubungi kami. Ada yang bisa kami bantu?')" class="shrink-0 px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-teal-300 border border-white/10 transition cursor-pointer text-[11px]">
                        👋 Salam Pembuka
                    </button>
                    <button type="button" onclick="insertCannedResponse('Pendaftaran PPDB online dapat diakses melalui menu PPDB Online di halaman utama web kami.')" class="shrink-0 px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-teal-300 border border-white/10 transition cursor-pointer text-[11px]">
                        🎓 Info PPDB
                    </button>
                    <button type="button" onclick="insertCannedResponse('Untuk rincian tagihan dan konfirmasi pembayaran SPP, Anda dapat mengeceknya pada menu Keuangan di dashboard masing-masing.')" class="shrink-0 px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-teal-300 border border-white/10 transition cursor-pointer text-[11px]">
                        💳 Biaya SPP
                    </button>
                    <button type="button" onclick="insertCannedResponse('Layanan kantor Tata Usaha beroperasi hari Senin s.d. Jumat pukul 07.30 - 15.30 WIB.')" class="shrink-0 px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-teal-300 border border-white/10 transition cursor-pointer text-[11px]">
                        ⏰ Jam Kerja TU
                    </button>
                    <button type="button" onclick="insertCannedResponse('Sama-sama, senang bisa membantu Anda. Jika ada pertanyaan lain, jangan ragu untuk menghubungi kami kembali!')" class="shrink-0 px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-teal-300 border border-white/10 transition cursor-pointer text-[11px]">
                        ✅ Salam Penutup
                    </button>
                </div>

                <!-- 4. Kotak Formulir Balasan Petugas -->
                <div class="p-4 border-t border-white/10 bg-slate-950/80 shrink-0">
                    <!-- Preview Lampiran Admin -->
                    <div id="adminAttachmentPreview" class="hidden mb-2 items-center justify-between text-xs text-teal-300 bg-teal-500/10 px-3.5 py-1.5 rounded-xl border border-teal-500/20">
                        <div class="flex items-center gap-2 truncate">
                            <i class="fa-solid fa-paperclip text-xs"></i>
                            <span id="adminAttachmentName" class="truncate font-medium">lampiran.jpg</span>
                        </div>
                        <button type="button" onclick="cancelAdminAttachment()" class="text-slate-400 hover:text-rose-400 transition cursor-pointer ml-2">
                            <i class="fa-solid fa-xmark text-xs"></i>
                        </button>
                    </div>

                    <form id="formAdminReply" onsubmit="handleSendAdminReply(event)" class="flex items-end gap-2.5">
                        <input type="file" id="adminAttachmentInput" class="hidden" accept="image/*,.pdf,.doc,.docx" onchange="handleAdminAttachmentSelected(this)">
                        
                        <!-- Tombol Lampiran Admin -->
                        <button type="button" 
                                onclick="document.getElementById('adminAttachmentInput').click()" 
                                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-teal-300 transition cursor-pointer" 
                                title="Kirim Berkas / Foto (Maks 5MB)">
                            <i class="fa-solid fa-paperclip text-xs"></i>
                        </button>

                        <div class="relative flex-1">
                            <textarea id="adminReplyInput" 
                                      rows="2" 
                                      maxlength="3000"
                                      placeholder="Ketik balasan untuk pengunjung ini... (Tekan Enter untuk mengirim, Shift+Enter untuk baris baru)"
                                      onkeydown="handleAdminKeyDown(event)"
                                      class="w-full resize-none rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-white placeholder-slate-500 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 transition leading-relaxed"></textarea>
                        </div>

                        <button type="submit" 
                                id="btnAdminSend"
                                class="inline-flex items-center justify-center gap-2 rounded-2xl bg-gradient-to-tr from-teal-600 via-emerald-600 to-cyan-500 hover:from-teal-500 hover:to-cyan-400 px-5 py-3.5 text-xs font-bold text-white shadow-lg shadow-teal-500/25 transition cursor-pointer shrink-0">
                            <span>Kirim</span>
                            <i class="fa-solid fa-paper-plane text-xs"></i>
                        </button>
                    </form>
                    <p class="text-[10px] text-slate-500 mt-2 flex items-center justify-between">
                        <span>Balasan Anda akan langsung muncul di widget live chat pengunjung.</span>
                        <span>Shift+Enter untuk baris baru</span>
                    </p>
                </div>

            </div>

        </div>

    </div>

</div>

<!-- ================= MODAL PENGATURAN AI LIVE CHAT ================= -->
<div id="modalAiSettings" class="hidden fixed inset-0 z-[100050] overflow-y-auto bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="relative w-full max-w-3xl rounded-3xl border border-white/10 bg-slate-900 shadow-2xl shadow-black/80 overflow-hidden animate-fade-in text-slate-100">
        
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-white/10 bg-slate-950/70 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500/20 to-teal-500/20 text-indigo-400 border border-indigo-500/30 text-lg">
                    <i class="fa-solid fa-robot"></i>
                </span>
                <div>
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <span>Pusat Kendali Kecerdasan Buatan (AI) Live Chat</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">Sentinel NLP Active</span>
                    </h3>
                    <p class="text-[11px] text-slate-400">Konfigurasi mesin NLP mandiri in-house, pelatihan pengetahuan, dan integrasi model LLM</p>
                </div>
            </div>
            <button type="button" onclick="closeAiSettingsModal()" class="p-2 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition cursor-pointer">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        <!-- Tab Navigation -->
        <div class="flex items-center gap-2 border-b border-white/10 px-6 pt-3 bg-slate-950/40">
            <button type="button" onclick="switchAiTab('config')" id="tabBtnAiConfig" class="px-4 py-2 text-xs font-bold border-b-2 border-indigo-500 text-indigo-400 transition flex items-center gap-2">
                <i class="fa-solid fa-sliders text-xs"></i> <span>Konfigurasi Mesin</span>
            </button>
            <button type="button" onclick="switchAiTab('nlp')" id="tabBtnAiNlp" class="px-4 py-2 text-xs font-semibold border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition flex items-center gap-2">
                <i class="fa-solid fa-brain text-xs"></i> <span>Sentinel NLP & Pelatihan</span>
            </button>
            <button type="button" onclick="switchAiTab('bench')" id="tabBtnAiBench" class="px-4 py-2 text-xs font-semibold border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition flex items-center gap-2">
                <i class="fa-solid fa-flask text-xs"></i> <span>Uji Coba & Chain of Thought</span>
            </button>
        </div>

        <!-- Modal Body Content -->
        <div class="p-6 max-h-[72vh] overflow-y-auto space-y-5">
            
            <!-- ================= TAB 1: KONFIGURASI MESIN ================= -->
            <div id="aiTabContentConfig" class="space-y-4">
                <form id="formAiSettings" onsubmit="handleSaveAiSettings(event)" class="space-y-4">
                    <!-- Toggle Aktifkan AI Global -->
                    <div class="flex items-center justify-between p-3.5 rounded-2xl border border-white/10 bg-white/5">
                        <div>
                            <h4 class="text-xs font-bold text-white">Aktifkan Respon Otomatis AI</h4>
                            <p class="text-[10px] text-slate-400 mt-0.5">AI akan otomatis menjawab pertanyaan pengunjung & civitas 24/7</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" id="aiSettingEnabled" class="sr-only peer">
                            <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-teal-500"></div>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Asisten AI</label>
                            <input type="text" id="aiSettingBotName" placeholder="Contoh: AI Asisten Sekolah" class="w-full rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none transition">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Pilihan Mesin AI (Provider)</label>
                            <select id="aiSettingProvider" onchange="toggleAiProviderFields()" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs text-white focus:border-indigo-500 focus:outline-none transition cursor-pointer">
                                <option value="hybrid">🌟 Hybrid (Sentinel NLP Mandiri + Fallback Cloud LLM)</option>
                                <option value="local">⚡ Sentinel NLP Mandiri Murni (100% In-House, Cepat & Privat)</option>
                                <option value="gemini">🤖 Google Gemini AI (Cloud)</option>
                                <option value="openai">🧠 OpenAI GPT (Cloud)</option>
                                <option value="custom_llm">🖥️ Self-Hosted / Ollama / LocalAI</option>
                            </select>
                        </div>
                    </div>

                    <!-- Kolom Kredensial LLM Eksternal -->
                    <div id="aiCredentialsBlock" class="space-y-4 pt-1">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1">API Key <span class="text-slate-500">(Gemini / OpenAI)</span></label>
                                <input type="password" id="aiSettingApiKey" placeholder="AIzaSy... atau sk-proj-..." class="w-full rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none transition">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Model</label>
                                <input type="text" id="aiSettingModel" placeholder="gemini-1.5-flash / gpt-4o-mini / llama3" class="w-full rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none transition">
                            </div>
                        </div>

                        <div id="aiEndpointGroup" class="hidden">
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Custom Endpoint URL <span class="text-slate-500">(Opsional / Ollama)</span></label>
                            <input type="url" id="aiSettingEndpoint" placeholder="http://localhost:11434/v1/chat/completions" class="w-full rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none transition">
                        </div>
                    </div>

                    <!-- System Prompt / Persona -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Instruksi Khusus / Persona AI</label>
                        <textarea id="aiSettingPrompt" rows="3" placeholder="Aturan tone suara atau petunjuk khusus untuk AI..." class="w-full rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none transition leading-relaxed"></textarea>
                        <p class="text-[10px] text-slate-500 mt-1">Sistem secara otomatis menyertakan data profil sekolah, kuota PPDB, daftar SPP, dan jam operasional TU dari database sebagai referensi jawaban.</p>
                    </div>

                    <div id="aiSettingsSaveAlert" class="hidden text-xs p-3 rounded-xl"></div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-white/10">
                        <button type="button" onclick="closeAiSettingsModal()" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-400 hover:text-white hover:bg-white/5 transition">
                            Batal
                        </button>
                        <button type="submit" id="btnSaveAiSettings" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-gradient-to-r from-teal-600 to-indigo-600 hover:from-teal-500 hover:to-indigo-500 text-xs font-bold text-white shadow-md transition">
                            <i class="fa-solid fa-floppy-disk text-xs"></i>
                            <span>Simpan Konfigurasi</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- ================= TAB 2: SENTINEL NLP & PELATIHAN ================= -->
            <div id="aiTabContentNlp" class="hidden space-y-5">
                <!-- Statistik Mesin NLP -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="p-3.5 rounded-2xl border border-white/10 bg-white/5 text-center">
                        <span class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Status NLP</span>
                        <span id="nlpStatStatus" class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-400 mt-1">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span>Online</span>
                        </span>
                    </div>
                    <div class="p-3.5 rounded-2xl border border-white/10 bg-white/5 text-center">
                        <span class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Total Intents</span>
                        <span id="nlpStatIntents" class="text-sm font-bold text-indigo-300 mt-1 block">-</span>
                    </div>
                    <div class="p-3.5 rounded-2xl border border-white/10 bg-white/5 text-center">
                        <span class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Pola Pertanyaan</span>
                        <span id="nlpStatPatterns" class="text-sm font-bold text-teal-300 mt-1 block">-</span>
                    </div>
                    <div class="p-3.5 rounded-2xl border border-white/10 bg-white/5 text-center">
                        <span class="block text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Terpelajari</span>
                        <span id="nlpStatLearned" class="text-sm font-bold text-amber-300 mt-1 block">-</span>
                    </div>
                </div>

                <!-- Form Pelatihan Mandiri (Self-Learning) -->
                <div class="p-4 rounded-2xl border border-indigo-500/30 bg-indigo-950/20 space-y-3">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-bold text-indigo-300 flex items-center gap-2">
                            <i class="fa-solid fa-graduation-cap text-indigo-400"></i>
                            <span>Latih Pengetahuan AI Mandiri (Self-Learning Q&A)</span>
                        </h4>
                        <span class="text-[10px] text-slate-400">Tersimpan permanen di engine/data/knowledge.json</span>
                    </div>
                    <p class="text-[11px] text-slate-300 leading-relaxed">
                        Ajarkan pola pertanyaan baru dan jawaban resmi spesifik untuk sekolah Anda. Mesin Sentinel NLP akan otomatis mengekstrak kata kunci dan melakukan pencocokan semantik Skip-Gram & TF-IDF.
                    </p>

                    <form id="formTeachNlp" onsubmit="handleTeachNlp(event)" class="space-y-3 pt-1">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-300 mb-1">Pertanyaan / Kata Kunci Baru</label>
                            <input type="text" id="teachQuestionInput" required placeholder="Contoh: apakah ada beasiswa prestasi untuk siswa baru?" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none transition">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-300 mb-1">Jawaban Resmi AI</label>
                            <textarea id="teachAnswerInput" required rows="3" placeholder="Tuliskan jawaban lengkap yang ingin disampaikan oleh AI saat pertanyaan tersebut diajukan..." class="w-full rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none transition leading-relaxed"></textarea>
                        </div>
                        <div id="teachAlertBox" class="hidden text-xs p-3 rounded-xl"></div>
                        <div class="flex justify-end">
                            <button type="submit" id="btnTeachSubmit" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-xs font-bold text-white shadow transition">
                                <i class="fa-solid fa-plus text-xs"></i>
                                <span>Ajarkan ke Otak AI</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Daftar Pengetahuan Terpelajari -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-bold text-slate-200 flex items-center gap-2">
                            <i class="fa-solid fa-list-check text-slate-400"></i>
                            <span>Daftar Pengetahuan Hasil Pelatihan Mandiri</span>
                        </h4>
                        <button type="button" onclick="loadNlpStatsAndLearned()" class="text-[11px] text-indigo-400 hover:text-indigo-300 transition flex items-center gap-1">
                            <i class="fa-solid fa-rotate text-[10px]"></i> Segarkan
                        </button>
                    </div>
                    <div id="nlpLearnedListContainer" class="p-3 rounded-2xl border border-white/10 bg-slate-950/60 max-h-48 overflow-y-auto space-y-2 text-xs text-slate-400">
                        <div class="text-center py-4 text-slate-500 text-xs">Memuat daftar pengetahuan...</div>
                    </div>
                </div>
            </div>

            <!-- ================= TAB 3: UJI COBA & CHAIN OF THOUGHT ================= -->
            <div id="aiTabContentBench" class="hidden space-y-4">
                <div>
                    <h4 class="text-xs font-bold text-indigo-300 flex items-center gap-1.5 mb-1">
                        <i class="fa-solid fa-flask text-xs"></i>
                        <span>Live Test Bench & Cognitive Inspector</span>
                    </h4>
                    <p class="text-[11px] text-slate-400">Uji coba penalaran Sentinel NLP secara langsung dan inspeksi alur berpikir (Chain of Thought), analisis sentimen, serta waktu inferensi.</p>
                </div>

                <!-- Pintasan Pertanyaan Populer -->
                <div class="flex flex-wrap gap-1.5 items-center">
                    <span class="text-[10px] text-slate-500 font-semibold mr-1">Contoh Cepat:</span>
                    <button type="button" onclick="setAiTestQuery('Bagaimana syarat dan alur PPDB online?')" class="px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[10px] text-slate-300 transition">📋 Info PPDB</button>
                    <button type="button" onclick="setAiTestQuery('Berapa rincian biaya SPP dan metode pembayarannya?')" class="px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[10px] text-slate-300 transition">💳 Tagihan SPP</button>
                    <button type="button" onclick="setAiTestQuery('Kapan jam operasional pelayanan kantor TU?')" class="px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[10px] text-slate-300 transition">⏰ Jam TU</button>
                    <button type="button" onclick="setAiTestQuery('Hitung 250000 * 3')" class="px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[10px] text-slate-300 transition">🧮 Matematika</button>
                    <button type="button" onclick="setAiTestQuery('Saya merasa sangat lelah dan sedih hari ini')" class="px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 border border-white/10 text-[10px] text-slate-300 transition">❤️ Empati Afektif</button>
                </div>

                <!-- Input Simulasi -->
                <div class="flex items-center gap-2">
                    <input type="text" id="aiTestQueryInput" placeholder="Ketik pertanyaan untuk diuji oleh Sentinel NLP..." class="flex-1 rounded-xl border border-white/10 bg-slate-950 px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none transition">
                    <button type="button" onclick="executeAiSimulation()" id="btnExecuteAiTest" class="px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-xs font-bold text-white transition shrink-0 flex items-center gap-1.5 shadow">
                        <i class="fa-solid fa-play text-xs"></i> <span>Uji AI</span>
                    </button>
                </div>

                <!-- Box Hasil Respon & Inspeksi Chain of Thought -->
                <div id="aiTestResultBox" class="hidden p-4 rounded-2xl border border-indigo-500/25 bg-slate-950/80 space-y-3 text-xs">
                    <!-- Dinamis terisi melalui executeAiSimulation -->
                </div>
            </div>

        </div>
    </div>
</div>

<!-- JAVASCRIPT KONSOL REALTIME OPERATOR -->
<script>
(function() {
    const API_URL = '../../api/live_chat.php';
    let currentFilter = 'all';
    let searchQuery = '';
    let selectedSessionId = 0;
    let selectedSession = null;
    let pollDeskTimer = null;
    let lastRenderedMsgId = 0;
    let searchDebounceTimeout = null;

    const sessionListContainer = document.getElementById('sessionListContainer');
    const chatEmptyState = document.getElementById('chatEmptyState');
    const chatActiveThread = document.getElementById('chatActiveThread');
    const messagesBox = document.getElementById('threadMessagesBox');
    const replyInput = document.getElementById('adminReplyInput');
    const sendBtn = document.getElementById('btnAdminSend');

    // 1. Ambil Daftar Sesi dari Server
    window.fetchSessionList = async function(isManual = false) {
        const refreshIcon = document.getElementById('btnRefreshIcon');
        if (isManual && refreshIcon) refreshIcon.classList.add('fa-spin');

        try {
            const url = `${API_URL}?action=admin_get_sessions&filter=${encodeURIComponent(currentFilter)}&q=${encodeURIComponent(searchQuery)}`;
            const res = await fetch(url);
            const data = await res.json();

            if (data.success) {
                renderSessionList(data.sessions || []);
                // Update stats counter
                if (data.total_active !== undefined) {
                    document.getElementById('statActiveCount').textContent = data.total_active;
                }
                if (data.total_unread !== undefined) {
                    document.getElementById('statUnreadCount').textContent = data.total_unread;
                }
            }
        } catch (e) {
            console.error('Fetch sessions failed:', e);
        } finally {
            if (isManual && refreshIcon) {
                setTimeout(() => refreshIcon.classList.remove('fa-spin'), 500);
            }
        }
    };

    // 2. Render Daftar Sesi ke Kolom Kiri
    function renderSessionList(sessions) {
        const countText = document.getElementById('sessionListCountText');
        if (countText) countText.textContent = `${sessions.length} obrolan`;

        if (sessions.length === 0) {
            sessionListContainer.innerHTML = `
                <div class="p-8 text-center text-xs text-slate-500">
                    <i class="fa-solid fa-inbox text-2xl mb-2 text-slate-600"></i>
                    <p>Tidak ada percakapan yang cocok dengan filter.</p>
                </div>
            `;
            return;
        }

        let html = '';
        sessions.forEach(sess => {
            const isSelected = (parseInt(sess.id) === selectedSessionId);
            const isGuest = !sess.user_id;
            const displayName = isGuest ? (sess.guest_name || 'Tamu Tanpa Nama') : (sess.reg_user_name || sess.guest_name || 'Pengguna');
            const roleLabel = isGuest ? 'Tamu' : (sess.reg_user_role || sess.role_label || 'Civitas');
            const initial = displayName.charAt(0).toUpperCase();
            const unread = parseInt(sess.unread_count) || 0;
            const isClosed = sess.status === 'closed';

            let roleBadgeClass = 'border-slate-500/30 bg-slate-500/10 text-slate-300';
            if (roleLabel.toLowerCase() === 'siswa') roleBadgeClass = 'border-sky-500/30 bg-sky-500/10 text-sky-300';
            else if (roleLabel.toLowerCase() === 'guru') roleBadgeClass = 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300';
            else if (roleLabel.toLowerCase() === 'tamu') roleBadgeClass = 'border-amber-500/30 bg-amber-500/10 text-amber-300';

            html += `
                <div onclick="selectChatSession(${sess.id})" 
                     class="p-3.5 transition-all duration-200 cursor-pointer flex items-start gap-3 relative select-none ${isSelected ? 'bg-teal-500/15 border-l-4 border-teal-400' : 'hover:bg-white/5 border-l-4 border-transparent'}">
                    
                    <!-- Avatar -->
                    <div class="relative shrink-0 mt-0.5">
                        <span class="flex h-10 w-10 items-center justify-center rounded-2xl ${isGuest ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'bg-blue-500/20 text-blue-300 border border-blue-500/30'} font-bold text-sm">
                            ${initial}
                        </span>
                        <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full ${isClosed ? 'bg-slate-500' : 'bg-emerald-400'} border-2 border-slate-900"></span>
                    </div>

                    <!-- Detail Info Sesi -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-1">
                            <h4 class="text-xs font-bold text-white truncate">${escapeHtml(displayName)}</h4>
                            <span class="text-[10px] text-slate-500 shrink-0">${timeAgo(sess.updated_at || sess.created_at)}</span>
                        </div>

                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span class="text-[9px] font-extrabold uppercase px-1.5 py-0.2 rounded border ${roleBadgeClass}">
                                ${escapeHtml(roleLabel)}
                            </span>
                            <span class="text-[9px] text-slate-400 truncate">
                                ${escapeHtml(sess.department || 'Umum')}
                            </span>
                            ${parseInt(sess.needs_human) === 1 ? `
                                <span class="text-[8px] font-black uppercase px-1.5 py-0.2 rounded bg-rose-500/20 text-rose-300 border border-rose-500/40 animate-pulse">
                                    <i class="fa-solid fa-triangle-exclamation"></i> Butuh Petugas
                                </span>
                            ` : ''}
                            ${sess.ai_enabled !== undefined && parseInt(sess.ai_enabled) === 0 ? `
                                <span class="text-[8px] font-semibold uppercase px-1.5 py-0.2 rounded bg-slate-800 text-slate-400 border border-slate-700">
                                    <i class="fa-solid fa-user"></i> Manual
                                </span>
                            ` : ''}
                        </div>

                        <p class="text-[11px] text-slate-400 truncate mt-1">
                            ${sess.last_sender ? `<span class="text-slate-300 font-semibold">${escapeHtml(sess.last_sender)}:</span> ` : ''}
                            ${escapeHtml(sess.last_message || 'Belum ada pesan')}
                        </p>
                    </div>

                    <!-- Unread Badge -->
                    ${unread > 0 ? `
                        <span class="shrink-0 flex h-5 w-5 items-center justify-center rounded-full bg-rose-500 text-[10px] font-black text-white shadow-md shadow-rose-500/30">
                            ${unread}
                        </span>
                    ` : ''}

                </div>
            `;
        });

        sessionListContainer.innerHTML = html;
    }

    // 3. Pilih Percakapan Sesi Tertentu
    window.selectChatSession = async function(sessionId) {
        selectedSessionId = sessionId;
        lastRenderedMsgId = 0;

        // Buka Thread di Kanan
        chatEmptyState.classList.add('hidden');
        chatActiveThread.classList.remove('hidden');
        messagesBox.innerHTML = `
            <div class="p-8 text-center text-xs text-slate-500">
                <i class="fa-solid fa-spinner fa-spin text-lg text-teal-400 mb-2"></i>
                <p>Memuat riwayat percakapan...</p>
            </div>
        `;

        if (document.getElementById('deleteSessionId')) {
            document.getElementById('deleteSessionId').value = sessionId;
        }

        await fetchChatMessages(sessionId);
        // Segarkan daftar sesi di kiri agar unread badge hilang
        fetchSessionList(false);
        setTimeout(() => replyInput?.focus(), 150);
    };

    // 4. Ambil Pesan Obrolan Sesi Terpilih
    async function fetchChatMessages(sessionId) {
        if (!sessionId) return;
        try {
            const res = await fetch(`${API_URL}?action=admin_get_chat&session_id=${sessionId}`);
            const data = await res.json();

            if (data.success && data.session) {
                selectedSession = data.session;
                updateThreadHeader(data.session);
                renderThreadMessages(data.messages || []);
            }
        } catch (e) {
            console.error('Fetch chat messages error:', e);
        }
    }

    // 5. Update Header Obrolan
    function updateThreadHeader(sess) {
        const isGuest = !sess.user_id;
        const displayName = isGuest ? (sess.guest_name || 'Tamu') : (sess.reg_user_name || sess.guest_name || 'Pengguna');
        const roleLabel = isGuest ? 'Tamu' : (sess.reg_user_role || sess.role_label || 'Civitas');
        const contact = sess.guest_email || sess.reg_user_email || sess.guest_phone || 'Tanpa kontak tambahan';
        const isClosed = sess.status === 'closed';

        document.getElementById('threadAvatar').textContent = displayName.charAt(0).toUpperCase();
        document.getElementById('threadName').textContent = displayName;
        document.getElementById('threadRoleBadge').textContent = roleLabel;
        document.getElementById('threadDeptBadge').textContent = sess.department || 'Umum';
        document.getElementById('threadContactInfo').textContent = `${contact} • Dimulai: ${formatDate(sess.created_at)}`;

        // Status Indicator
        const indicator = document.getElementById('threadStatusIndicator');
        if (indicator) {
            indicator.className = `absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full ${isClosed ? 'bg-slate-500' : 'bg-emerald-400'} border-2 border-slate-900`;
        }

        // WhatsApp button
        const btnWa = document.getElementById('btnThreadWa');
        let phone = (sess.guest_phone || '').replace(/[^0-9]/g, '');
        if (phone.startsWith('0')) phone = '62' + phone.substring(1);
        if (phone) {
            btnWa.classList.remove('hidden');
            btnWa.href = `https://wa.me/${phone}?text=${encodeURIComponent('Halo ' + displayName + ', menindaklanjuti pesan live chat Anda di portal sekolah, ')}`;
        } else {
            btnWa.classList.add('hidden');
        }

        // Email button
        const btnMail = document.getElementById('btnThreadMail');
        const email = sess.guest_email || sess.reg_user_email;
        if (email) {
            btnMail.classList.remove('hidden');
            btnMail.href = `mailto:${encodeURIComponent(email)}?subject=${encodeURIComponent('Tindak Lanjut Live Chat Sekolah')}`;
        } else {
            btnMail.classList.add('hidden');
        }

        // Button status toggle
        const btnStatusText = document.getElementById('btnStatusText');
        const btnStatusIcon = document.getElementById('btnStatusIcon');
        if (isClosed) {
            btnStatusText.textContent = 'Buka Kembali';
            btnStatusIcon.className = 'fa-solid fa-rotate-left text-xs text-amber-400';
        } else {
            btnStatusText.textContent = 'Tandai Selesai';
            btnStatusIcon.className = 'fa-solid fa-circle-check text-xs text-emerald-400';
        }

        // Thread AI Toggle button
        const btnAi = document.getElementById('btnThreadAiToggle');
        const iconAi = document.getElementById('threadAiIcon');
        const textAi = document.getElementById('threadAiText');
        if (btnAi) {
            const isAiOn = (sess.ai_enabled === undefined || parseInt(sess.ai_enabled) === 1);
            if (isAiOn) {
                textAi.textContent = 'AI Aktif (Ambil Alih)';
                btnAi.className = 'inline-flex items-center gap-1.5 rounded-xl border border-indigo-500/30 bg-indigo-500/10 hover:bg-indigo-500/20 px-3 py-1.5 text-xs font-semibold text-indigo-300 transition cursor-pointer';
                iconAi.className = 'fa-solid fa-robot text-xs text-indigo-400';
            } else {
                textAi.textContent = 'Manual (Aktifkan AI)';
                btnAi.className = 'inline-flex items-center gap-1.5 rounded-xl border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 px-3 py-1.5 text-xs font-semibold text-amber-300 transition cursor-pointer';
                iconAi.className = 'fa-solid fa-user-check text-xs text-amber-400';
            }
        }
    }

    // 6. Render Riwayat Pesan ke Box
    function renderThreadMessages(messages) {
        messagesBox.innerHTML = '';
        if (messages.length === 0) {
            messagesBox.innerHTML = `
                <div class="p-8 text-center text-xs text-slate-500">
                    <p>Belum ada pesan dalam sesi ini.</p>
                </div>
            `;
            return;
        }

        messages.forEach(msg => {
            appendAdminThreadMessage(msg);
            lastRenderedMsgId = Math.max(lastRenderedMsgId, parseInt(msg.id) || 0);
        });

        messagesBox.scrollTop = messagesBox.scrollHeight;
    }

    function appendAdminThreadMessage(msg) {
        if (document.getElementById('admin_msg_' + msg.id)) return;

        const isAdmin = msg.sender_type === 'admin';
        const isBot = msg.sender_type === 'bot';

        const wrapper = document.createElement('div');
        wrapper.id = 'admin_msg_' + msg.id;

        const safeText = escapeHtml(msg.message)
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/\n/g, '<br>');

        // Render lampiran gambar atau dokumen jika ada
        let attachmentHtml = '';
        if (msg.attachment_url) {
            const attUrl = (msg.attachment_url.startsWith('http') || msg.attachment_url.startsWith('/')) 
                ? msg.attachment_url 
                : ('../../' + msg.attachment_url);
            const isImg = /\.(jpg|jpeg|png|webp|gif)$/i.test(msg.attachment_url);
            if (isImg) {
                attachmentHtml = `
                    <div class="mt-2 rounded-xl overflow-hidden border border-white/10 max-w-[260px]">
                        <a href="${attUrl}" target="_blank" title="Klik untuk memperbesar gambar">
                            <img src="${attUrl}" alt="Lampiran" class="w-full h-auto max-h-52 object-cover hover:opacity-90 transition rounded-lg" loading="lazy">
                        </a>
                    </div>
                `;
            } else {
                attachmentHtml = `
                    <div class="mt-2">
                        <a href="${attUrl}" target="_blank" download class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-semibold text-teal-300 transition border border-white/10">
                            <i class="fa-solid fa-file-arrow-down"></i>
                            <span class="truncate max-w-[160px]">Unduh Dokumen</span>
                        </a>
                    </div>
                `;
            }
        }

        if (isBot) {
            // Pesan Bot di Tengah
            wrapper.className = 'flex justify-center my-2';
            wrapper.innerHTML = `
                <div class="max-w-[85%] rounded-2xl bg-indigo-950/40 border border-indigo-500/20 text-indigo-300 px-4 py-2 text-xs text-center leading-relaxed">
                    <span class="inline-flex items-center gap-1 font-bold text-[10px] uppercase tracking-wider text-indigo-400 mb-0.5">
                        <i class="fa-solid fa-robot text-xs"></i> Virtual Assistant Bot:
                    </span><br>
                    ${safeText}
                    ${attachmentHtml}
                    <div class="text-[9px] text-indigo-400/60 mt-1">${msg.time_str || ''}</div>
                </div>
            `;
        } else if (isAdmin) {
            // Pesan Balasan Petugas di Kanan
            wrapper.className = 'flex flex-col items-end space-y-1';
            wrapper.innerHTML = `
                <div class="flex items-center gap-1.5 pr-1">
                    <span class="text-[10px] font-bold text-teal-400">${escapeHtml(msg.sender_name || 'Petugas')}</span>
                    <span class="text-[8px] font-extrabold uppercase px-1 rounded bg-teal-500/20 text-teal-300 border border-teal-500/30">Admin</span>
                </div>
                <div class="max-w-[78%] rounded-2xl rounded-tr-sm bg-gradient-to-r from-teal-600 to-emerald-600 text-white px-4 py-2.5 text-xs shadow-md leading-relaxed break-words">
                    ${safeText}
                    ${attachmentHtml}
                </div>
                <div class="text-[10px] text-slate-500 pr-1 flex items-center gap-1">
                    <span>${msg.time_str || ''}</span>
                    <span class="text-teal-400">✓✓</span>
                </div>
            `;
        } else {
            // Pesan Pengunjung / Tamu / Siswa di Kiri
            wrapper.className = 'flex flex-col items-start space-y-1';
            wrapper.innerHTML = `
                <div class="flex items-center gap-1.5 pl-1">
                    <span class="text-[10px] font-bold text-slate-300">${escapeHtml(msg.sender_name || 'Pengunjung')}</span>
                    <span class="text-[8px] font-extrabold uppercase px-1 rounded bg-white/10 text-slate-400 border border-white/10">${msg.sender_type === 'user' ? 'Civitas' : 'Tamu'}</span>
                </div>
                <div class="max-w-[78%] rounded-2xl rounded-tl-sm bg-slate-800 border border-white/10 text-slate-200 px-4 py-2.5 text-xs shadow-md leading-relaxed break-words">
                    ${safeText}
                    ${attachmentHtml}
                </div>
                <div class="text-[10px] text-slate-500 pl-1">
                    <span>${msg.time_str || ''}</span>
                </div>
            `;
        }

        messagesBox.appendChild(wrapper);
    }

    let adminSelectedAttachment = null;
    window.handleAdminAttachmentSelected = function(input) {
        if (!input.files || input.files.length === 0) return;
        adminSelectedAttachment = input.files[0];
        const preview = document.getElementById('adminAttachmentPreview');
        const name = document.getElementById('adminAttachmentName');
        if (preview && name) {
            name.textContent = adminSelectedAttachment.name + ` (${(adminSelectedAttachment.size / 1024).toFixed(0)} KB)`;
            preview.classList.remove('hidden');
            preview.classList.add('flex');
        }
    };

    window.cancelAdminAttachment = function() {
        adminSelectedAttachment = null;
        const fileInput = document.getElementById('adminAttachmentInput');
        if (fileInput) fileInput.value = '';
        const preview = document.getElementById('adminAttachmentPreview');
        if (preview) {
            preview.classList.add('hidden');
            preview.classList.remove('flex');
        }
    };

    // 7. Kirim Balasan Admin
    window.handleSendAdminReply = async function(e) {
        if (e) e.preventDefault();
        const text = replyInput.value.trim();
        if ((!text && !adminSelectedAttachment) || !selectedSessionId) return;

        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        try {
            const formData = new FormData();
            formData.append('action', 'admin_reply');
            formData.append('session_id', selectedSessionId);
            formData.append('message', text);
            if (adminSelectedAttachment) {
                formData.append('attachment', adminSelectedAttachment);
            }

            const res = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            cancelAdminAttachment();

            if (data.success) {
                replyInput.value = '';
                replyInput.style.height = 'auto';
                
                // Tambahkan pesan langsung ke UI
                appendAdminThreadMessage({
                    id: data.message_id || Date.now(),
                    sender_type: 'admin',
                    sender_name: '<?= htmlspecialchars($user_name) ?> (Petugas)',
                    message: text || '[Lampiran]',
                    attachment_url: data.attachment_url || null,
                    time_str: data.time_str || 'Baru saja'
                });
                messagesBox.scrollTop = messagesBox.scrollHeight;

                // Segarkan daftar sesi di kiri
                fetchSessionList(false);
            } else {
                alert(data.message || 'Gagal mengirim balasan.');
            }
        } catch (err) {
            alert('Terjadi kesalahan jaringan.');
        } finally {
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<span>Kirim</span> <i class="fa-solid fa-paper-plane text-xs"></i>';
            replyInput.focus();
        }
    };

    window.handleAdminKeyDown = function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSendAdminReply();
        }
    };

    // 8. Masukkan Template Jawaban Cepat
    window.insertCannedResponse = function(text) {
        replyInput.value = text;
        replyInput.focus();
    };

    // 9. Ubah Status Sesi (Selesai / Aktif)
    window.toggleCurrentSessionStatus = async function() {
        if (!selectedSession || !selectedSessionId) return;
        const currentStatus = selectedSession.status || 'active';
        const newStatus = (currentStatus === 'active') ? 'closed' : 'active';

        try {
            const formData = new FormData();
            formData.append('action', 'admin_update_status');
            formData.append('session_id', selectedSessionId);
            formData.append('status', newStatus);

            const res = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                selectedSession.status = newStatus;
                updateThreadHeader(selectedSession);
                fetchSessionList(false);
                fetchChatMessages(selectedSessionId);
            }
        } catch (e) {
            alert('Gagal mengubah status sesi.');
        }
    };

    // 10. Filter & Pencarian
    window.setSessionFilter = function(filter, btn) {
        currentFilter = filter;
        document.querySelectorAll('.session-tab-btn').forEach(b => {
            b.className = 'session-tab-btn px-2.5 py-1 rounded-lg font-semibold bg-white/5 text-slate-300 hover:text-white transition cursor-pointer';
        });
        btn.className = 'session-tab-btn px-2.5 py-1 rounded-lg font-bold bg-teal-600 text-white transition cursor-pointer';
        fetchSessionList(false);
    };

    window.debounceSearch = function() {
        clearTimeout(searchDebounceTimeout);
        searchDebounceTimeout = setTimeout(() => {
            searchQuery = document.getElementById('searchChatQuery')?.value.trim() || '';
            fetchSessionList(false);
        }, 300);
    };

    // 11. Polling Otomatis Desk Operator (Tiap 3.5 detik)
    function startDeskPolling(customInterval = 3500) {
        clearInterval(pollDeskTimer);
        pollDeskTimer = setInterval(() => {
            fetchSessionList(false);
            if (selectedSessionId > 0) {
                fetchChatMessages(selectedSessionId);
            }
        }, customInterval);
    }

    // Adaptive Page Visibility Listener (Menghemat resource server InfinityFree saat tab tidak aktif)
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            fetchSessionList(false);
            if (selectedSessionId > 0) fetchChatMessages(selectedSessionId);
            startDeskPolling(3500);
        } else {
            startDeskPolling(25000);
        }
    });

    // Cetak Transkrip Arsip Admin
    window.printAdminChatTranscript = function() {
        if (!selectedSession || !selectedSessionId) {
            alert('Pilih salah satu sesi obrolan terlebih dahulu untuk mencetak transkrip.');
            return;
        }
        const msgNodes = messagesBox.children;
        if (!msgNodes || msgNodes.length === 0) {
            alert('Belum ada pesan dalam sesi ini.');
            return;
        }

        const sessName = escapeHtml(selectedSession.guest_name || selectedSession.reg_user_name || 'Tamu');
        let printContent = `
            <!DOCTYPE html>
            <html lang="id">
            <head>
                <meta charset="UTF-8">
                <title>Transkrip Arsip Live Chat - #${selectedSessionId} ${sessName}</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; padding: 25px; color: #1e293b; line-height: 1.5; font-size: 13px; }
                    .header { border-bottom: 2px solid #0d9488; padding-bottom: 12px; margin-bottom: 20px; }
                    .header h2 { margin: 0; color: #0f766e; }
                    .header p { margin: 4px 0 0; color: #64748b; font-size: 12px; }
                    .item { margin-bottom: 12px; padding: 10px 14px; border-radius: 8px; border: 1px solid #e2e8f0; }
                    .item-admin { background: #f0fdfa; border-left: 4px solid #0d9488; margin-left: 40px; }
                    .item-client { background: #f8fafc; border-left: 4px solid #3b82f6; margin-right: 40px; }
                    .item-bot { background: #f5f3ff; border-left: 4px solid #8b5cf6; text-align: center; }
                    img { max-width: 200px; height: auto; border-radius: 6px; margin-top: 6px; }
                    @media print { body { padding: 0; } }
                </style>
            </head>
            <body>
                <div class="header">
                    <h2>Arsip Konsultasi Live Chat Sekolah</h2>
                    <p>Sesi ID: #${selectedSessionId} • Klien: <strong>${sessName}</strong> (${selectedSession.role_label || 'Tamu'}) • Departemen: ${escapeHtml(selectedSession.department || 'Umum')} • Tanggal: ${new Date().toLocaleString('id-ID')}</p>
                </div>
        `;

        Array.from(msgNodes).forEach(node => {
            const clone = node.cloneNode(true);
            const isAdm = clone.classList.contains('items-end');
            const isCenter = clone.classList.contains('justify-center');
            const cls = isCenter ? 'item-bot' : (isAdm ? 'item-admin' : 'item-client');
            printContent += `<div class="item ${cls}">${clone.innerHTML}</div>`;
        });

        printContent += `
                <div style="margin-top: 30px; text-align: center; font-size: 11px; color: #94a3b8; border-top: 1px dashed #cbd5e1; padding-top: 15px;">
                    Arsip resmi dicetak dari Konsol Operator Sistem Manajemen Sekolah.
                </div>
                <script>window.onload = function() { window.print(); }<\/script>
            </body>
            </html>
        `;

        const printWin = window.open('', '_blank', 'width=850,height=750');
        if (printWin) {
            printWin.document.write(printContent);
            printWin.document.close();
        }
    };

    // 12. Helper Format Waktu & Teks
    function timeAgo(dateString) {
        if (!dateString) return '-';
        const d = new Date(dateString);
        const now = new Date();
        const diffMs = now - d;
        const diffSec = Math.floor(diffMs / 1000);
        const diffMin = Math.floor(diffSec / 60);
        const diffHr  = Math.floor(diffMin / 60);

        if (diffSec < 60) return 'Baru saja';
        if (diffMin < 60) return `${diffMin} mnt lalu`;
        if (diffHr < 24) return `${diffHr} jam lalu`;
        return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
    }

    function formatDate(dateString) {
        if (!dateString) return '-';
        const d = new Date(dateString);
        return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function escapeHtml(string) {
        const entityMap = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        };
        return String(string || '').replace(/[&<>"']/g, s => entityMap[s]);
    }

    // 13. AI Settings, Sentinel NLP & Test Bench Functions
    window.switchAiTab = function(tab) {
        const tabs = ['config', 'nlp', 'bench'];
        tabs.forEach(t => {
            const btn = document.getElementById('tabBtnAi' + t.charAt(0).toUpperCase() + t.slice(1));
            const content = document.getElementById('aiTabContent' + t.charAt(0).toUpperCase() + t.slice(1));
            if (t === tab) {
                if (btn) {
                    btn.className = 'px-4 py-2 text-xs font-bold border-b-2 border-indigo-500 text-indigo-400 transition flex items-center gap-2';
                }
                if (content) content.classList.remove('hidden');
            } else {
                if (btn) {
                    btn.className = 'px-4 py-2 text-xs font-semibold border-b-2 border-transparent text-slate-400 hover:text-slate-200 transition flex items-center gap-2';
                }
                if (content) content.classList.add('hidden');
            }
        });

        if (tab === 'nlp') {
            loadNlpStatsAndLearned();
        }
    };

    window.openAiSettingsModal = function() {
        document.getElementById('modalAiSettings').classList.remove('hidden');
        loadAiSettings();
        loadNlpStatsAndLearned();
    };

    window.closeAiSettingsModal = function() {
        document.getElementById('modalAiSettings').classList.add('hidden');
        const alertBox = document.getElementById('aiSettingsSaveAlert');
        if (alertBox) alertBox.classList.add('hidden');
    };

    window.toggleAiProviderFields = function() {
        const prov = document.getElementById('aiSettingProvider').value;
        const credBlock = document.getElementById('aiCredentialsBlock');
        const endpGroup = document.getElementById('aiEndpointGroup');
        const modelInput = document.getElementById('aiSettingModel');

        if (prov === 'local') {
            credBlock.classList.add('hidden');
        } else {
            credBlock.classList.remove('hidden');
            if (prov === 'custom_llm') {
                endpGroup.classList.remove('hidden');
                modelInput.placeholder = 'llama3 / mistral / custom';
            } else if (prov === 'openai') {
                endpGroup.classList.add('hidden');
                modelInput.placeholder = 'gpt-4o-mini / gpt-4o';
            } else {
                endpGroup.classList.add('hidden');
                modelInput.placeholder = 'gemini-1.5-flash / gemini-2.0-flash';
            }
        }
    };

    window.loadAiSettings = async function() {
        try {
            const res = await fetch(`${API_URL}?action=admin_get_ai_settings`);
            const data = await res.json();
            if (data.success) {
                document.getElementById('aiSettingEnabled').checked = (data.livechat_ai_enabled === '1');
                document.getElementById('aiSettingBotName').value = data.livechat_ai_bot_name || 'AI Asisten Sekolah';
                document.getElementById('aiSettingProvider').value = data.livechat_ai_provider || 'hybrid';
                document.getElementById('aiSettingApiKey').value = data.livechat_ai_api_key || '';
                document.getElementById('aiSettingModel').value = data.livechat_ai_model || 'gemini-1.5-flash';
                document.getElementById('aiSettingEndpoint').value = data.livechat_ai_endpoint || '';
                document.getElementById('aiSettingPrompt').value = data.livechat_ai_system_prompt || '';
                toggleAiProviderFields();
            }
        } catch (e) {
            console.error('Load AI settings error:', e);
        }
    };

    window.loadNlpStatsAndLearned = async function() {
        try {
            // Ambil Statistik
            const resStats = await fetch(`${API_URL}?action=admin_get_nlp_stats`);
            const dataStats = await resStats.json();
            if (dataStats.success && dataStats.stats) {
                const s = dataStats.stats;
                const statusEl = document.getElementById('nlpStatStatus');
                if (statusEl) {
                    statusEl.innerHTML = s.status === 'online'
                        ? `<span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> <span>Online (PHP ${escapeHtml(s.engine_version || '2.0')})</span>`
                        : `<span class="w-2 h-2 rounded-full bg-amber-500"></span> <span>Siaga</span>`;
                }
                const intentsEl = document.getElementById('nlpStatIntents');
                if (intentsEl) intentsEl.textContent = (s.total_intents || 0) + ' Kategori';

                const patternsEl = document.getElementById('nlpStatPatterns');
                if (patternsEl) patternsEl.textContent = (s.total_patterns || 0) + ' Pola';

                const learnedEl = document.getElementById('nlpStatLearned');
                if (learnedEl) learnedEl.textContent = (s.total_learned || 0) + ' Entri';
            }

            // Ambil Daftar Pembelajaran Mandiri
            const resLearned = await fetch(`${API_URL}?action=admin_get_nlp_learned`);
            const dataLearned = await resLearned.json();
            const container = document.getElementById('nlpLearnedListContainer');
            if (container) {
                if (dataLearned.success && Array.isArray(dataLearned.learned) && dataLearned.learned.length > 0) {
                    let html = '';
                    dataLearned.learned.forEach(item => {
                        html += `
                            <div class="flex items-start justify-between gap-3 p-2.5 rounded-xl bg-white/5 border border-white/5 hover:border-white/10 transition">
                                <div class="space-y-1 overflow-hidden">
                                    <div class="font-bold text-slate-200 text-xs flex items-center gap-1.5">
                                        <i class="fa-solid fa-circle-question text-indigo-400 text-[10px]"></i>
                                        <span>${escapeHtml(item.question)}</span>
                                    </div>
                                    <div class="text-[11px] text-slate-300 leading-relaxed truncate">${escapeHtml(item.answer)}</div>
                                    ${item.created_at ? `<div class="text-[9px] text-slate-500">Dipelajari: ${escapeHtml(item.created_at)}</div>` : ''}
                                </div>
                                <button type="button" onclick="deleteNlpLearned('${escapeHtml(item.question).replace(/'/g, "\\'")}')" class="p-1.5 rounded-lg text-slate-500 hover:text-rose-400 hover:bg-rose-500/10 transition shrink-0" title="Hapus pengetahuan ini">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </button>
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<div class="text-center py-4 text-slate-500 text-xs">Belum ada materi pengetahuan hasil pembelajaran mandiri.</div>';
                }
            }
        } catch (e) {
            console.error('Load NLP stats error:', e);
        }
    };

    window.handleTeachNlp = async function(e) {
        e.preventDefault();
        const qInput = document.getElementById('teachQuestionInput');
        const aInput = document.getElementById('teachAnswerInput');
        const btn = document.getElementById('btnTeachSubmit');
        const alertBox = document.getElementById('teachAlertBox');

        const q = qInput.value.trim();
        const a = aInput.value.trim();
        if (!q || !a) return;

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Melatih...';
        alertBox.classList.add('hidden');

        try {
            const formData = new FormData();
            formData.append('action', 'admin_nlp_teach');
            formData.append('question', q);
            formData.append('answer', a);

            const res = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            alertBox.classList.remove('hidden');
            if (data.success) {
                alertBox.className = 'text-xs p-3 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-300';
                alertBox.innerHTML = '<i class="fa-solid fa-circle-check mr-1.5"></i> ' + data.message;
                qInput.value = '';
                aInput.value = '';
                loadNlpStatsAndLearned();
            } else {
                alertBox.className = 'text-xs p-3 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-300';
                alertBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1.5"></i> ' + (data.message || 'Gagal melatih AI.');
            }
        } catch (err) {
            alertBox.classList.remove('hidden');
            alertBox.className = 'text-xs p-3 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-300';
            alertBox.innerHTML = 'Terjadi kesalahan koneksi server.';
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-plus text-xs"></i> <span>Ajarkan ke Otak AI</span>';
        }
    };

    window.deleteNlpLearned = async function(question) {
        if (!confirm(`Hapus materi pengetahuan terpelajari untuk: "${question}"?`)) return;

        try {
            const formData = new FormData();
            formData.append('action', 'admin_delete_nlp_learned');
            formData.append('question', question);

            const res = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                loadNlpStatsAndLearned();
            } else {
                alert(data.message || 'Gagal menghapus pengetahuan.');
            }
        } catch (e) {
            alert('Terjadi kesalahan koneksi saat menghapus.');
        }
    };

    window.setAiTestQuery = function(text) {
        const input = document.getElementById('aiTestQueryInput');
        if (input) {
            input.value = text;
            executeAiSimulation();
        }
    };

    window.handleSaveAiSettings = async function(e) {
        e.preventDefault();
        const saveBtn = document.getElementById('btnSaveAiSettings');
        const alertBox = document.getElementById('aiSettingsSaveAlert');
        alertBox.classList.add('hidden');

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Menyimpan...';

        try {
            const formData = new FormData();
            formData.append('action', 'admin_save_ai_settings');
            formData.append('livechat_ai_enabled', document.getElementById('aiSettingEnabled').checked ? '1' : '0');
            formData.append('livechat_ai_bot_name', document.getElementById('aiSettingBotName').value.trim());
            formData.append('livechat_ai_provider', document.getElementById('aiSettingProvider').value);
            formData.append('livechat_ai_api_key', document.getElementById('aiSettingApiKey').value.trim());
            formData.append('livechat_ai_model', document.getElementById('aiSettingModel').value.trim());
            formData.append('livechat_ai_endpoint', document.getElementById('aiSettingEndpoint').value.trim());
            formData.append('livechat_ai_system_prompt', document.getElementById('aiSettingPrompt').value.trim());

            const res = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            alertBox.classList.remove('hidden');
            if (data.success) {
                alertBox.className = 'text-xs p-3 rounded-xl bg-emerald-500/15 border border-emerald-500/30 text-emerald-300';
                alertBox.innerHTML = '<i class="fa-solid fa-circle-check mr-1.5"></i> ' + data.message;
                setTimeout(() => closeAiSettingsModal(), 1200);
            } else {
                alertBox.className = 'text-xs p-3 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-300';
                alertBox.innerHTML = '<i class="fa-solid fa-triangle-exclamation mr-1.5"></i> ' + (data.message || 'Gagal menyimpan.');
            }
        } catch (err) {
            alertBox.classList.remove('hidden');
            alertBox.className = 'text-xs p-3 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-300';
            alertBox.innerHTML = 'Terjadi kesalahan koneksi server.';
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk text-xs"></i> <span>Simpan Konfigurasi</span>';
        }
    };

    window.executeAiSimulation = async function() {
        const queryInput = document.getElementById('aiTestQueryInput');
        const q = queryInput.value.trim();
        if (!q) return;

        const testBtn = document.getElementById('btnExecuteAiTest');
        const resultBox = document.getElementById('aiTestResultBox');

        testBtn.disabled = true;
        testBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        resultBox.classList.remove('hidden');
        resultBox.innerHTML = '<div class="flex items-center gap-2 text-indigo-300"><i class="fa-solid fa-brain text-indigo-400 fa-bounce"></i> <span>Sentinel NLP sedang memproses tokenisasi, analisis afektif, dan pencocokan semantik...</span></div>';

        try {
            const formData = new FormData();
            formData.append('action', 'admin_test_ai');
            formData.append('message', q);

            const res = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success && data.reply) {
                const formatted = escapeHtml(data.reply)
                    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\*(.*?)\*/g, '<em>$1</em>')
                    .replace(/\n/g, '<br>');

                // Ekstrak metadata Sentinel NLP
                const meta = data.nlp_meta || {};
                const intentStr = meta.intent || 'sekolah_kontekstual';
                const confidencePct = meta.confidence ? Math.round(meta.confidence * 100) + '%' : '100%';
                const timeMs = meta.time_ms !== undefined ? meta.time_ms + ' ms' : '< 5 ms';
                const sentimentScore = meta.sentiment ? (meta.sentiment.score !== undefined ? meta.sentiment.score : '0') : '0';
                const isCrisis = meta.sentiment && meta.sentiment.is_crisis;

                let thoughtBlock = '';
                if (meta.thought) {
                    thoughtBlock = `
                        <details class="mt-3 pt-2.5 border-t border-white/10 group cursor-pointer">
                            <summary class="text-[11px] font-bold text-indigo-300 flex items-center justify-between hover:text-indigo-200 transition select-none">
                                <span class="flex items-center gap-1.5">
                                    <i class="fa-solid fa-diagram-project text-xs"></i>
                                    <span>Inspeksi Alur Berpikir (Chain of Thought & Trace)</span>
                                </span>
                                <span class="text-[10px] text-slate-400 group-open:rotate-180 transition-transform">▼</span>
                            </summary>
                            <div class="mt-2 p-3 rounded-xl bg-slate-900 border border-white/5 font-mono text-[11px] text-slate-300 leading-relaxed overflow-x-auto whitespace-pre-wrap">${escapeHtml(meta.thought)}</div>
                        </details>
                    `;
                }

                resultBox.innerHTML = `
                    <div class="flex flex-wrap items-center gap-2 pb-2 border-b border-white/10 text-[10px]">
                        <span class="px-2 py-0.5 rounded-full font-bold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                            Intent: ${escapeHtml(intentStr)}
                        </span>
                        <span class="px-2 py-0.5 rounded-full font-bold bg-teal-500/20 text-teal-300 border border-teal-500/30">
                            Keyakinan: ${escapeHtml(confidencePct)}
                        </span>
                        <span class="px-2 py-0.5 rounded-full font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                            Waktu: ${escapeHtml(timeMs)}
                        </span>
                        ${isCrisis ? '<span class="px-2 py-0.5 rounded-full font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30">🚨 Mode Krisis</span>' : ''}
                    </div>
                    <div class="space-y-1">
                        <div class="text-[10px] uppercase font-bold text-slate-400 tracking-wider">Hasil Tanggapan AI:</div>
                        <div class="text-slate-100 leading-relaxed">${formatted}</div>
                    </div>
                    ${thoughtBlock}
                `;
            } else {
                resultBox.innerHTML = `<span class="text-rose-400">${data.message || 'Gagal memproses simulasi.'}</span>`;
            }
        } catch (err) {
            resultBox.innerHTML = '<span class="text-rose-400">Terjadi kesalahan koneksi pengujian.</span>';
        } finally {
            testBtn.disabled = false;
            testBtn.innerHTML = '<i class="fa-solid fa-play text-xs"></i> <span>Uji AI</span>';
        }
    };

    window.toggleCurrentSessionAi = async function() {
        if (!selectedSession || !selectedSessionId) return;
        const currentAi = (selectedSession.ai_enabled === undefined || parseInt(selectedSession.ai_enabled) === 1);
        const newAi = currentAi ? 0 : 1;

        try {
            const formData = new FormData();
            formData.append('action', 'admin_toggle_session_ai');
            formData.append('session_id', selectedSessionId);
            formData.append('ai_enabled', newAi);

            const res = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                selectedSession.ai_enabled = newAi;
                updateThreadHeader(selectedSession);
                fetchSessionList(false);
                fetchChatMessages(selectedSessionId);
            }
        } catch (e) {
            alert('Gagal mengubah mode AI sesi.');
        }
    };

    // Start
    fetchSessionList(false);
    startDeskPolling();

})();
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>

