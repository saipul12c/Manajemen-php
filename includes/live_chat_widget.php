<?php
/**
 * Komponen Floating Live Chat Widget (Unified)
 * Dapat digunakan SEBELUM LOGIN (Tamu/Pengunjung/Calon Siswa) 
 * dan SESUDAH LOGIN (Siswa, Guru, Wali Murid, Staf, Admin).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Deteksi status login
$widget_is_logged_in = isset($_SESSION['user_id']);
$widget_user_id      = $widget_is_logged_in ? (int)$_SESSION['user_id'] : 0;
$widget_user_name    = $_SESSION['user_name'] ?? '';
$widget_user_role    = $_SESSION['user_role'] ?? 'pengguna';
$widget_user_email   = $_SESSION['user_email'] ?? '';

// Hitung path relatif ke api/live_chat.php berdasarkan kedalaman skrip yang memanggil
$widget_root_dir    = realpath(__DIR__ . '/..');
$widget_script_dir  = realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? ''));
if ($widget_root_dir && $widget_script_dir) {
    $widget_rel_path = str_replace('\\', '/', substr($widget_script_dir, strlen($widget_root_dir)));
    $widget_depth    = count(array_filter(explode('/', $widget_rel_path)));
    $widget_api_root = str_repeat('../', $widget_depth);
} else {
    $widget_api_root = (isset($base_path) && $base_path !== '') ? $base_path : '';
}
$widget_api_url = $widget_api_root . 'api/live_chat.php';

// Pengaturan Sekolah
$widget_school_name = 'Pusat Layanan Sekolah';
if (isset($school_info) && !empty($school_info['school_name'])) {
    $widget_school_name = $school_info['school_name'];
} elseif (isset($pdo) && function_exists('getSchoolSettings')) {
    try {
        $s_info = getSchoolSettings($pdo);
        if (!empty($s_info['school_name'])) {
            $widget_school_name = $s_info['school_name'];
        }
    } catch (Exception $e) {}
}
?>

<!-- ================= LIVE CHAT FLOATING WIDGET ================= -->
<div id="schoolLiveChatContainer" class="fixed bottom-6 right-6 z-[99999] font-sans antialiased select-none pointer-events-auto">
    
    <!-- 1. FLOATING LAUNCHER BUTTON -->
    <div class="relative flex items-center justify-end">
        
        <!-- Tooltip Mini (Otomatis muncul untuk pengunjung baru) -->
        <div id="liveChatTooltip" 
             onclick="openSchoolLiveChat()"
             class="hidden sm:flex items-center gap-2 mr-3 px-3.5 py-2 rounded-2xl bg-slate-900/90 text-white border border-white/10 shadow-2xl backdrop-blur-md text-xs font-semibold cursor-pointer hover:border-teal-400/50 hover:bg-slate-800 transition-all duration-300 transform hover:-translate-x-1 animate-pulse">
            <span class="flex h-2 w-2 rounded-full bg-emerald-400"></span>
            <span>Butuh bantuan? Chat kami</span>
            <i class="fa-solid fa-chevron-right text-[10px] text-teal-400"></i>
        </div>

        <!-- Tombol Utama Live Chat -->
        <button id="btnToggleLiveChat" 
                type="button" 
                onclick="toggleSchoolLiveChat()"
                aria-label="Buka Live Chat Layanan Bantuan" 
                class="group relative flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-tr from-teal-600 via-emerald-600 to-cyan-500 text-white shadow-xl shadow-teal-500/30 hover:shadow-2xl hover:shadow-teal-500/50 hover:scale-105 active:scale-95 transition-all duration-300 cursor-pointer border border-white/20">
            
            <!-- Ikon Buka (Headset / Pesan) -->
            <i id="liveChatIconOpen" class="fa-solid fa-comments text-2xl transition-transform duration-300 group-hover:rotate-6"></i>
            
            <!-- Ikon Tutup (X) -->
            <i id="liveChatIconClose" class="fa-solid fa-xmark text-2xl hidden transition-transform duration-300 group-hover:rotate-90"></i>
            
            <!-- Badge Unread -->
            <span id="liveChatUnreadBadge" 
                  class="hidden absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-rose-500 text-[10px] font-black text-white ring-2 ring-slate-950 shadow-md animate-bounce">
                0
            </span>
            
            <!-- Ping animasi online -->
            <span class="absolute -bottom-0.5 -left-0.5 flex h-3.5 w-3.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3.5 w-3.5 bg-emerald-500 border-2 border-slate-950"></span>
            </span>
        </button>
    </div>

    <!-- 2. CHAT BOX PANEL -->
    <div id="schoolLiveChatBox" 
         class="hidden fixed sm:absolute bottom-0 sm:bottom-20 right-0 sm:right-0 w-screen sm:w-[390px] h-[92vh] sm:h-[580px] max-h-[95vh] flex-col rounded-t-3xl sm:rounded-3xl border border-white/10 bg-slate-950/95 text-slate-100 shadow-2xl shadow-black/80 backdrop-blur-2xl overflow-hidden transition-all duration-300 z-[100000]">
        
        <!-- A. HEADER CHAT -->
        <div class="flex items-center justify-between border-b border-white/10 bg-gradient-to-r from-slate-900 via-slate-900/90 to-teal-950/60 px-4 py-3.5 shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <div class="relative shrink-0">
                    <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-tr from-teal-600 to-cyan-500 text-white shadow-md shadow-teal-500/20">
                        <i class="fa-solid fa-headset text-lg"></i>
                    </span>
                    <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-emerald-400 border-2 border-slate-900"></span>
                </div>
                <div class="min-w-0">
                    <h3 class="text-sm font-bold text-white truncate flex items-center gap-1.5">
                        <span>Live Chat Sekolah</span>
                        <span class="text-[9px] font-extrabold px-1.5 py-0.5 rounded-full bg-teal-500/20 text-teal-300 border border-teal-500/30">Online</span>
                    </h3>
                    <p id="liveChatHeaderSubtitle" class="text-[11px] text-slate-400 truncate">
                        <?= $widget_is_logged_in 
                            ? '👤 ' . htmlspecialchars($widget_user_name) . ' (' . htmlspecialchars(ucfirst($widget_user_role)) . ')' 
                            : 'Petugas TU & Virtual Assistant' ?>
                    </p>
                </div>
            </div>

            <!-- Tombol Opsi Header -->
            <div class="flex items-center gap-1 shrink-0">
                <!-- Sound Toggle -->
                <button type="button" 
                        id="btnLiveChatSound" 
                        onclick="toggleLiveChatSound()" 
                        class="p-2 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition cursor-pointer" 
                        title="Bunyi Notifikasi">
                    <i id="liveChatSoundIcon" class="fa-solid fa-volume-high text-xs"></i>
                </button>
                <!-- Close / Minimize -->
                <button type="button" 
                        onclick="toggleSchoolLiveChat()" 
                        class="p-2 rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition cursor-pointer" 
                        title="Tutup Obrolan">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>
        </div>

        <!-- B. KONTEN BODY (DUA TAMPILAN: FORM MULAI vs PERCAKAPAN) -->
        
        <!-- B1. VIEW: FORM ONBOARDING TAMU (Jika belum login & belum ada sesi aktif) -->
        <div id="liveChatGuestFormView" class="hidden flex-1 p-5 overflow-y-auto flex flex-col justify-between">
            <div class="space-y-4">
                <div class="text-center pt-2 pb-1">
                    <div class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-teal-500/10 text-teal-400 border border-teal-500/20 mb-2">
                        <i class="fa-solid fa-hand-wave text-xl"></i>
                    </div>
                    <h4 class="text-base font-bold text-white">Selamat Datang!</h4>
                    <p class="text-xs text-slate-400 mt-1 max-w-xs mx-auto">
                        Silakan isi nama Anda untuk terhubung langsung dengan tim administrasi <?= htmlspecialchars($widget_school_name) ?>.
                    </p>
                </div>

                <!-- Formulir Mulai Chat -->
                <form id="formStartLiveChat" onsubmit="handleStartLiveChat(event)" class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Lengkap <span class="text-rose-400">*</span></label>
                        <input type="text" 
                               id="liveChatInputName" 
                               required 
                               placeholder="Contoh: Budi Santoso"
                               class="w-full rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 transition">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Email / No. WhatsApp <span class="text-slate-500">(Opsional)</span></label>
                        <input type="text" 
                               id="liveChatInputContact" 
                               placeholder="Untuk tindak lanjut jawaban"
                               class="w-full rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 transition">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1">Kategori Pertanyaan</label>
                        <select id="liveChatSelectDept" class="w-full rounded-xl border border-white/10 bg-slate-900 px-3.5 py-2.5 text-xs text-white focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 transition cursor-pointer">
                            <option value="Layanan Umum">Layanan Umum & Informasi</option>
                            <option value="PPDB Online">Penerimaan Siswa Baru (PPDB)</option>
                            <option value="Akademik & Nilai">Akademik & Kegiatan Belajar</option>
                            <option value="Keuangan & SPP">Biaya Pendidikan & SPP</option>
                            <option value="Bantuan Teknis">Bantuan Login & Teknis</option>
                        </select>
                    </div>

                    <!-- Topik Cepat -->
                    <div class="pt-1">
                        <p class="text-[11px] font-semibold text-slate-400 mb-2">Atau pilih pertanyaan cepat:</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button type="button" onclick="selectQuickTopic('PPDB Online', 'Halo, saya ingin menanyakan syarat dan jadwal PPDB siswa baru.')" class="rounded-lg border border-teal-500/30 bg-teal-500/10 hover:bg-teal-500/20 px-2.5 py-1 text-[11px] font-medium text-teal-300 transition cursor-pointer">
                                🎓 Info PPDB
                            </button>
                            <button type="button" onclick="selectQuickTopic('Keuangan & SPP', 'Halo, bagaimana informasi pembayaran biaya SPP/sekolah?')" class="rounded-lg border border-amber-500/30 bg-amber-500/10 hover:bg-amber-500/20 px-2.5 py-1 text-[11px] font-medium text-amber-300 transition cursor-pointer">
                                💳 Biaya SPP
                            </button>
                            <button type="button" onclick="selectQuickTopic('Layanan Umum', 'Halo, jam berapa jam kerja operasional kantor tata usaha?')" class="rounded-lg border border-sky-500/30 bg-sky-500/10 hover:bg-sky-500/20 px-2.5 py-1 text-[11px] font-medium text-sky-300 transition cursor-pointer">
                                ⏰ Jam Buka TU
                            </button>
                        </div>
                    </div>

                    <div id="liveChatGuestError" class="hidden text-xs text-rose-400 bg-rose-500/10 border border-rose-500/20 p-2.5 rounded-xl"></div>

                    <div class="pt-2">
                        <button type="submit" 
                                id="btnSubmitStartChat"
                                class="w-full flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-teal-600 via-emerald-600 to-cyan-600 hover:from-teal-500 hover:to-cyan-500 py-3 text-xs font-bold text-white shadow-lg shadow-teal-600/30 transition cursor-pointer">
                            <span>Mulai Obrolan Sekarang</span>
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </button>
                    </div>
                </form>
            </div>

            <div class="pt-4 text-center border-t border-white/5 mt-4">
                <p class="text-[10px] text-slate-500">
                    Punya akun civitas? <a href="<?= $widget_api_root ?>auth/login.php" class="text-teal-400 hover:underline">Masuk di sini</a> untuk riwayat percakapan permanen.
                </p>
            </div>
        </div>

        <!-- B2. VIEW: PERCAKAPAN AKTIF (Pesan Berjalan) -->
        <div id="liveChatConversationView" class="hidden flex-1 flex flex-col min-h-0">
            
            <!-- Banner Identitas Pengguna jika login -->
            <?php if ($widget_is_logged_in): ?>
                <div class="bg-teal-500/10 border-b border-teal-500/20 px-4 py-2 text-[11px] text-teal-300 flex items-center justify-between">
                    <span class="flex items-center gap-1.5 truncate">
                        <i class="fa-solid fa-circle-check text-teal-400 text-xs"></i>
                        <span>Terhubung: <strong><?= htmlspecialchars($widget_user_name) ?></strong></span>
                    </span>
                    <span class="text-[10px] uppercase font-bold bg-teal-500/20 px-2 py-0.5 rounded-md border border-teal-500/30">
                        <?= htmlspecialchars($widget_user_role) ?>
                    </span>
                </div>
            <?php endif; ?>

            <!-- Container Pesan (Scrollable) -->
            <div id="liveChatMessagesContainer" class="flex-1 p-4 overflow-y-auto space-y-3 scroll-smooth">
                <!-- Pesan diinjeksi via JavaScript -->
            </div>

            <!-- Chips FAQ Cepat di atas Input -->
            <div class="px-3 py-1.5 border-t border-white/5 bg-slate-900/60 flex items-center gap-1.5 overflow-x-auto no-scrollbar text-[11px]">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500 shrink-0">FAQ:</span>
                <button type="button" onclick="triggerQuickFaq('ppdb')" class="shrink-0 px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-teal-300 border border-white/10 transition cursor-pointer">
                    📋 Info PPDB
                </button>
                <button type="button" onclick="triggerQuickFaq('biaya')" class="shrink-0 px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-teal-300 border border-white/10 transition cursor-pointer">
                    💳 Biaya/SPP
                </button>
                <button type="button" onclick="triggerQuickFaq('jadwal')" class="shrink-0 px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-teal-300 border border-white/10 transition cursor-pointer">
                    ⏰ Jam Layanan
                </button>
                <button type="button" onclick="triggerQuickFaq('kontak')" class="shrink-0 px-2.5 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-slate-300 hover:text-teal-300 border border-white/10 transition cursor-pointer">
                    📞 Kontak Kami
                </button>
            </div>

            <!-- C. FOOTER INPUT PESAN -->
            <div class="p-3 border-t border-white/10 bg-slate-900/80 backdrop-blur-md shrink-0">
                <!-- Preview Lampiran jika ada -->
                <div id="liveChatAttachmentPreview" class="hidden mb-2 items-center justify-between text-[11px] text-teal-300 bg-teal-500/10 px-3 py-1.5 rounded-xl border border-teal-500/20">
                    <div class="flex items-center gap-2 truncate">
                        <i class="fa-solid fa-paperclip text-xs"></i>
                        <span id="liveChatAttachmentName" class="truncate font-medium">lampiran.jpg</span>
                    </div>
                    <button type="button" onclick="cancelLiveChatAttachment()" class="text-slate-400 hover:text-rose-400 transition cursor-pointer ml-2">
                        <i class="fa-solid fa-xmark text-xs"></i>
                    </button>
                </div>

                <form id="formSendLiveChatMessage" onsubmit="handleSendLiveChatMessage(event)" class="flex items-center gap-2">
                    <input type="file" id="liveChatAttachmentInput" class="hidden" accept="image/*,.pdf,.doc,.docx" onchange="handleLiveChatAttachmentSelected(this)">
                    
                    <!-- Tombol Lampiran (Klip Kertas) -->
                    <button type="button" 
                            onclick="document.getElementById('liveChatAttachmentInput').click()" 
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-teal-300 transition cursor-pointer" 
                            title="Kirim Foto / Lampiran (Maks 5MB)">
                        <i class="fa-solid fa-paperclip text-xs"></i>
                    </button>

                    <div class="relative flex-1">
                        <textarea id="liveChatInputMessage" 
                                  rows="1" 
                                  maxlength="3000"
                                  placeholder="Ketik pertanyaan Anda..." 
                                  onkeydown="handleLiveChatKeyDown(event)"
                                  class="w-full resize-none rounded-xl border border-white/10 bg-white/5 px-3.5 py-2.5 text-xs text-white placeholder-slate-500 focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500 transition leading-relaxed"></textarea>
                    </div>

                    <button type="submit" 
                            id="btnLiveChatSend" 
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-tr from-teal-600 to-cyan-500 hover:from-teal-500 hover:to-cyan-400 text-white shadow-md shadow-teal-500/25 transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-paper-plane text-xs"></i>
                    </button>
                </form>

                <div class="mt-2 flex items-center justify-between text-[10px] text-slate-500">
                    <div class="flex items-center gap-3">
                        <button type="button" onclick="confirmCloseChatSession()" class="hover:text-rose-400 transition cursor-pointer">
                            <i class="fa-solid fa-power-off text-[9px] mr-1"></i> Akhiri
                        </button>
                        <button type="button" onclick="printChatTranscript()" class="hover:text-teal-400 transition cursor-pointer">
                            <i class="fa-solid fa-print text-[9px] mr-1"></i> Cetak Transkrip
                        </button>
                    </div>
                    <span>Layanan Live Terpadu</span>
                </div>
            </div>

        </div>

    </div>

</div>

<!-- ================= JAVASCRIPT LOGIKA CLIENT LIVE CHAT ================= -->
<script>
(function() {
    // Mencegah inisialisasi ganda jika widget terpanggil lebih dari satu kali
    if (window.SCHOOL_LIVE_CHAT_READY) return;
    window.SCHOOL_LIVE_CHAT_READY = true;

    // Konfigurasi & State
    const API_URL = <?= json_encode($widget_api_url) ?>;
    const IS_LOGGED_IN = <?= $widget_is_logged_in ? 'true' : 'false' ?>;
    const CURRENT_USER = {
        id: <?= $widget_user_id ?>,
        name: <?= json_encode($widget_user_name) ?>,
        role: <?= json_encode($widget_user_role) ?>,
        email: <?= json_encode($widget_user_email) ?>
    };

    let chatOpen = false;
    let sessionToken = localStorage.getItem('school_live_chat_token') || '';
    let currentSession = null;
    let lastMessageId = 0;
    let pollIntervalTimer = null;
    let soundEnabled = localStorage.getItem('school_live_chat_sound') !== 'false';
    let unreadCount = 0;
    let originalDocTitle = document.title;
    let titleBlinkInterval = null;

    // Elemen DOM
    const chatContainer = document.getElementById('schoolLiveChatContainer');
    const chatBox = document.getElementById('schoolLiveChatBox');
    const iconOpen = document.getElementById('liveChatIconOpen');
    const iconClose = document.getElementById('liveChatIconClose');
    const unreadBadge = document.getElementById('liveChatUnreadBadge');
    const tooltip = document.getElementById('liveChatTooltip');
    const guestFormView = document.getElementById('liveChatGuestFormView');
    const convView = document.getElementById('liveChatConversationView');
    const messagesContainer = document.getElementById('liveChatMessagesContainer');
    const messageInput = document.getElementById('liveChatInputMessage');
    const soundIcon = document.getElementById('liveChatSoundIcon');

    // Update icon sound awal
    updateSoundIcon();

    // 1. Inisialisasi Sesi Pertama Kali
    async function initLiveChat() {
        try {
            const res = await fetch(`${API_URL}?action=init&session_token=${encodeURIComponent(sessionToken)}`);
            const data = await res.json();

            if (data.success) {
                if (data.active_session && data.session) {
                    currentSession = data.session;
                    sessionToken = data.session.session_token;
                    localStorage.setItem('school_live_chat_token', sessionToken);
                    
                    showConversationView();
                    renderMessages(data.messages || [], false);
                } else {
                    // Tamu butuh isi nama
                    showGuestFormView();
                }
            }
        } catch (e) {
            console.warn('Live Chat init notice:', e);
            showGuestFormView();
        }

        // Mulai polling background (santai) untuk unread pesan
        startPolling(false);
    }

    // 2. Tampilkan Form Tamu
    function showGuestFormView() {
        if (guestFormView) guestFormView.classList.remove('hidden');
        if (convView) convView.classList.add('hidden');
    }

    // 3. Tampilkan Percakapan Aktif
    function showConversationView() {
        if (guestFormView) guestFormView.classList.add('hidden');
        if (convView) convView.classList.remove('hidden');
        scrollToBottom();
    }

    // 4. Buka / Tutup Chat Box
    window.toggleSchoolLiveChat = function() {
        chatOpen = !chatOpen;
        if (chatOpen) {
            chatBox.classList.remove('hidden');
            chatBox.classList.add('flex');
            iconOpen.classList.add('hidden');
            iconClose.classList.remove('hidden');
            if (tooltip) tooltip.classList.add('hidden');
            
            // Reset unread
            unreadCount = 0;
            updateUnreadBadge();
            stopTitleBlink();

            // Fokus ke input jika ada
            if (convView && !convView.classList.contains('hidden')) {
                setTimeout(() => messageInput?.focus(), 150);
            }

            scrollToBottom();
            // Polling lebih cepat saat chat terbuka
            startPolling(true);
        } else {
            chatBox.classList.add('hidden');
            chatBox.classList.remove('flex');
            iconOpen.classList.remove('hidden');
            iconClose.classList.add('hidden');
            
            // Polling lebih lambat saat chat tertutup
            startPolling(false);
        }
    };

    window.openSchoolLiveChat = function(prefillTopic = '') {
        if (!chatOpen) {
            toggleSchoolLiveChat();
        }
        if (prefillTopic && document.getElementById('liveChatSelectDept')) {
            document.getElementById('liveChatSelectDept').value = prefillTopic;
        }
    };

    // 5. Mulai Sesi Chat Tamu
    window.handleStartLiveChat = async function(e) {
        e.preventDefault();
        const errElem = document.getElementById('liveChatGuestError');
        const submitBtn = document.getElementById('btnSubmitStartChat');
        errElem.classList.add('hidden');

        const name = document.getElementById('liveChatInputName')?.value.trim();
        const contact = document.getElementById('liveChatInputContact')?.value.trim();
        const dept = document.getElementById('liveChatSelectDept')?.value;

        if (!name) {
            errElem.textContent = 'Nama lengkap wajib diisi.';
            errElem.classList.remove('hidden');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Menghubungkan...';

        try {
            const formData = new FormData();
            formData.append('action', 'start_session');
            formData.append('guest_name', name);
            formData.append('guest_email', contact);
            formData.append('department', dept);

            const res = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success && data.session_token) {
                sessionToken = data.session_token;
                currentSession = data.session;
                localStorage.setItem('school_live_chat_token', sessionToken);

                showConversationView();
                renderMessages(data.messages || [], false);
                playChimeSound();
            } else {
                errElem.textContent = data.message || 'Gagal memulai sesi.';
                errElem.classList.remove('hidden');
            }
        } catch (err) {
            errElem.textContent = 'Terjadi kesalahan koneksi server.';
            errElem.classList.remove('hidden');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<span>Mulai Obrolan Sekarang</span> <i class="fa-solid fa-arrow-right text-xs"></i>';
        }
    };

    // 6. Pilih Topik Cepat
    window.selectQuickTopic = function(dept, promptText) {
        const deptSelect = document.getElementById('liveChatSelectDept');
        if (deptSelect) deptSelect.value = dept;
        const nameInput = document.getElementById('liveChatInputName');
        if (nameInput && !nameInput.value) {
            nameInput.focus();
        }
    };

    let selectedAttachmentFile = null;

    window.handleLiveChatAttachmentSelected = function(input) {
        if (!input.files || input.files.length === 0) return;
        selectedAttachmentFile = input.files[0];
        const previewBox = document.getElementById('liveChatAttachmentPreview');
        const nameText = document.getElementById('liveChatAttachmentName');
        if (previewBox && nameText) {
            nameText.textContent = selectedAttachmentFile.name + ` (${(selectedAttachmentFile.size / 1024).toFixed(0)} KB)`;
            previewBox.classList.remove('hidden');
            previewBox.classList.add('flex');
        }
    };

    window.cancelLiveChatAttachment = function() {
        selectedAttachmentFile = null;
        const fileInput = document.getElementById('liveChatAttachmentInput');
        if (fileInput) fileInput.value = '';
        const previewBox = document.getElementById('liveChatAttachmentPreview');
        if (previewBox) {
            previewBox.classList.add('hidden');
            previewBox.classList.remove('flex');
        }
    };

    // 7. Kirim Pesan
    window.handleSendLiveChatMessage = async function(e) {
        if (e) e.preventDefault();
        const msg = messageInput.value.trim();
        if ((!msg && !selectedAttachmentFile) || !sessionToken) return;

        // Render optimistik lokal langsung di UI
        const tempId = 'temp_' + Date.now();
        appendMessage({
            id: tempId,
            sender_type: IS_LOGGED_IN ? 'user' : 'guest',
            sender_name: IS_LOGGED_IN ? CURRENT_USER.name : (currentSession?.guest_name || 'Anda'),
            message: msg || (selectedAttachmentFile ? `[Mengunggah: ${selectedAttachmentFile.name}]` : ''),
            time_str: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }),
            is_pending: true
        });

        messageInput.value = '';
        messageInput.style.height = 'auto';
        scrollToBottom();

        // Tampilkan indikator AI sedang mengetik
        showTypingIndicator();

        try {
            const formData = new FormData();
            formData.append('action', 'send_message');
            formData.append('session_token', sessionToken);
            formData.append('message', msg);
            if (selectedAttachmentFile) {
                formData.append('attachment', selectedAttachmentFile);
            }

            const res = await fetch(API_URL, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            // Reset lampiran setelah dikirim
            cancelLiveChatAttachment();

            if (data.success) {
                const tempNode = document.getElementById('msg_' + tempId);
                if (tempNode) {
                    tempNode.id = 'msg_' + data.message_id;
                    const statusCheck = tempNode.querySelector('.msg-status');
                    if (statusCheck) statusCheck.innerHTML = '✓';
                }
                lastMessageId = Math.max(lastMessageId, data.message_id || 0);

                // Jika AI merespons langsung, render dengan delay halus
                if (data.bot_replied && data.ai_info && data.ai_info.message) {
                    setTimeout(() => {
                        removeTypingIndicator();
                        appendMessage({
                            id: data.ai_info.message_id || ('bot_' + Date.now()),
                            sender_type: 'bot',
                            sender_name: data.ai_info.bot_name || 'AI Asisten Sekolah',
                            message: data.ai_info.message,
                            time_str: new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
                        });
                        lastMessageId = Math.max(lastMessageId, data.ai_info.message_id || 0);
                        playChimeSound();
                        scrollToBottom();
                    }, 500);
                } else {
                    removeTypingIndicator();
                    if (data.bot_replied) {
                        setTimeout(pollNewMessages, 400);
                    }
                }
            } else {
                removeTypingIndicator();
                alert(data.message || 'Gagal mengirim pesan.');
            }
        } catch (err) {
            console.error('Send message error:', err);
            removeTypingIndicator();
        }
    };

    function showTypingIndicator() {
        removeTypingIndicator();
        const ind = document.createElement('div');
        ind.id = 'liveChatTypingIndicator';
        ind.className = 'flex items-center gap-2 pl-1 py-1 text-[11px] text-slate-400 animate-pulse';
        ind.innerHTML = `
            <span class="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-500/20 text-[10px] text-indigo-400 border border-indigo-500/30">
                <i class="fa-solid fa-robot"></i>
            </span>
            <span class="italic font-medium text-indigo-300">AI Asisten sedang merumuskan jawaban...</span>
            <span class="inline-flex gap-1 ml-1">
                <span class="h-1.5 w-1.5 rounded-full bg-teal-400 animate-bounce"></span>
                <span class="h-1.5 w-1.5 rounded-full bg-cyan-400 animate-bounce [animation-delay:0.2s]"></span>
                <span class="h-1.5 w-1.5 rounded-full bg-indigo-400 animate-bounce [animation-delay:0.4s]"></span>
            </span>
        `;
        messagesContainer.appendChild(ind);
        scrollToBottom();
    }

    function removeTypingIndicator() {
        const el = document.getElementById('liveChatTypingIndicator');
        if (el) el.remove();
    }

    window.handleLiveChatKeyDown = function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSendLiveChatMessage();
        }
    };

    // 8. Render Pesan ke DOM
    function renderMessages(messages, isAppend = false) {
        if (!isAppend) {
            messagesContainer.innerHTML = '';
        }

        messages.forEach(msg => {
            appendMessage(msg);
            lastMessageId = Math.max(lastMessageId, parseInt(msg.id) || 0);
        });

        scrollToBottom();
    }

    function appendMessage(msg) {
        removeTypingIndicator();
        if (document.getElementById('msg_' + msg.id)) return;

        const isMe = (msg.sender_type === 'guest' && !IS_LOGGED_IN) ||
                     (msg.sender_type === 'user' && IS_LOGGED_IN);
        const isBot = msg.sender_type === 'bot';
        const isAdmin = msg.sender_type === 'admin';

        const bubbleWrapper = document.createElement('div');
        bubbleWrapper.id = 'msg_' + msg.id;
        bubbleWrapper.className = `flex flex-col ${isMe ? 'items-end' : 'items-start'} space-y-1 animate-fade-in`;

        // Format teks dengan aman & dukung bold serta link sederhana
        const safeText = escapeHtml(msg.message)
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/\n/g, '<br>');

        // Render lampiran gambar atau dokumen jika ada
        let attachmentHtml = '';
        if (msg.attachment_url) {
            const attUrl = (msg.attachment_url.startsWith('http') || msg.attachment_url.startsWith('/')) 
                ? msg.attachment_url 
                : (API_URL.replace(/api\/live_chat\.php.*$/, '') + msg.attachment_url);
            const isImg = /\.(jpg|jpeg|png|webp|gif)$/i.test(msg.attachment_url);
            if (isImg) {
                attachmentHtml = `
                    <div class="mt-2 rounded-xl overflow-hidden border border-white/10 max-w-[220px]">
                        <a href="${attUrl}" target="_blank" title="Klik untuk memperbesar gambar">
                            <img src="${attUrl}" alt="Lampiran Foto" class="w-full h-auto max-h-44 object-cover hover:opacity-90 transition cursor-zoom-in rounded-lg" loading="lazy">
                        </a>
                    </div>
                `;
            } else {
                attachmentHtml = `
                    <div class="mt-2">
                        <a href="${attUrl}" target="_blank" download class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-xs font-semibold text-teal-300 transition border border-white/10">
                            <i class="fa-solid fa-file-arrow-down"></i>
                            <span class="truncate max-w-[140px]">Unduh Dokumen</span>
                        </a>
                    </div>
                `;
            }
        }

        let bubbleHtml = '';
        if (isMe) {
            // Bubble Pengguna / Tamu (Kanan)
            bubbleHtml = `
                <div class="max-w-[85%] rounded-2xl rounded-tr-sm bg-gradient-to-r from-teal-600 to-emerald-600 text-white px-3.5 py-2.5 text-xs shadow-md leading-relaxed break-words">
                    ${safeText}
                    ${attachmentHtml}
                </div>
                <div class="flex items-center gap-1 text-[10px] text-slate-500 pr-1">
                    <span>${msg.time_str || ''}</span>
                    <span class="msg-status text-teal-400 font-bold">${msg.is_pending ? '...' : (msg.is_read ? '✓✓' : '✓')}</span>
                </div>
            `;
        } else {
            // Bubble Admin / Bot (Kiri)
            const badgeLabel = isBot ? (msg.sender_name || 'AI Assistant') : 'Petugas Staf';
            const badgeColor = isBot 
                ? 'bg-indigo-500/20 text-indigo-300 border-indigo-500/30' 
                : 'bg-teal-500/20 text-teal-300 border-teal-500/30';
            const icon = isBot ? 'fa-robot text-indigo-400' : 'fa-headset text-teal-400';
            const bubbleBg = isBot
                ? 'bg-slate-900 border border-indigo-500/20 text-slate-200'
                : 'bg-slate-900 border border-white/10 text-slate-200';

            bubbleHtml = `
                <div class="flex items-center gap-1.5 pl-1 mb-0.5">
                    <span class="flex h-4 w-4 items-center justify-center rounded-full bg-slate-800 text-[9px]">
                        <i class="fa-solid ${icon}"></i>
                    </span>
                    <span class="text-[10px] font-bold text-slate-300">${escapeHtml(msg.sender_name || badgeLabel)}</span>
                    <span class="text-[8px] font-bold uppercase px-1.5 py-0.2 rounded border ${badgeColor}">${isBot ? 'Kecerdasan Buatan' : 'Petugas TU'}</span>
                </div>
                <div class="max-w-[88%] rounded-2xl rounded-tl-sm ${bubbleBg} px-3.5 py-2.5 text-xs shadow-md leading-relaxed break-words">
                    ${safeText}
                    ${attachmentHtml}
                </div>
                <div class="text-[10px] text-slate-500 pl-1">
                    <span>${msg.time_str || ''}</span>
                </div>
            `;
        }

        bubbleWrapper.innerHTML = bubbleHtml;
        messagesContainer.appendChild(bubbleWrapper);
    }

    // 9. Polling Pesan Baru
    async function pollNewMessages() {
        if (!sessionToken) return;

        try {
            const res = await fetch(`${API_URL}?action=poll&session_token=${encodeURIComponent(sessionToken)}&last_id=${lastMessageId}`);
            const data = await res.json();

            if (data.success && data.messages && data.messages.length > 0) {
                let hasIncoming = false;
                data.messages.forEach(m => {
                    appendMessage(m);
                    lastMessageId = Math.max(lastMessageId, parseInt(m.id) || 0);
                    if (m.sender_type === 'admin' || m.sender_type === 'bot') {
                        hasIncoming = true;
                    }
                });

                scrollToBottom();

                if (hasIncoming) {
                    playChimeSound();
                    if (!chatOpen) {
                        unreadCount += data.messages.length;
                        updateUnreadBadge();
                        startTitleBlink();
                    }
                }
            }

            if (data.session_status === 'closed' && currentSession) {
                currentSession.status = 'closed';
            }
        } catch (e) {
            // Polling fail silent
        }
    }

    function startPolling(isFast = false) {
        clearInterval(pollIntervalTimer);
        const interval = isFast ? 3500 : 12000;
        pollIntervalTimer = setInterval(pollNewMessages, interval);
    }

    // Adaptive Smart Polling (Menghemat beban server & query jika tab browser diminimalkan)
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            pollNewMessages();
            startPolling(chatOpen);
        } else {
            clearInterval(pollIntervalTimer);
            pollIntervalTimer = setInterval(pollNewMessages, 35000);
        }
    });

    // Cetak / Simpan Transkrip Percakapan
    window.printChatTranscript = function() {
        const chatNodes = messagesContainer.querySelectorAll('.flex.flex-col');
        if (!chatNodes || chatNodes.length === 0) {
            alert('Belum ada riwayat pesan untuk dicetak.');
            return;
        }

        const clientName = escapeHtml(CURRENT_USER.name || currentSession?.guest_name || 'Tamu Pengunjung');
        let printContent = `
            <!DOCTYPE html>
            <html lang="id">
            <head>
                <meta charset="UTF-8">
                <title>Transkrip Live Chat - ${clientName}</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; padding: 28px; color: #1e293b; line-height: 1.5; font-size: 13px; }
                    .header { border-bottom: 2px solid #0d9488; padding-bottom: 14px; margin-bottom: 22px; }
                    .header h2 { margin: 0; color: #0f766e; font-size: 18px; }
                    .header p { margin: 4px 0 0; color: #64748b; font-size: 12px; }
                    .msg-item { margin-bottom: 12px; padding: 10px 14px; border-radius: 10px; }
                    .msg-me { background: #f0fdfa; border: 1px solid #ccfbf1; border-left: 4px solid #0d9488; margin-left: 40px; }
                    .msg-bot { background: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #6366f1; margin-right: 40px; }
                    .sender-tag { font-weight: bold; font-size: 11px; text-transform: uppercase; margin-bottom: 4px; color: #475569; }
                    .time-tag { float: right; font-size: 11px; color: #94a3b8; }
                    img { max-width: 200px; height: auto; border-radius: 6px; margin-top: 6px; }
                    .footer-note { margin-top: 35px; border-top: 1px dashed #cbd5e1; padding-top: 15px; font-size: 11px; color: #94a3b8; text-align: center; }
                    @media print { body { padding: 0; } }
                </style>
            </head>
            <body>
                <div class="header">
                    <h2>Transkrip Resmi Layanan Live Chat Sekolah</h2>
                    <p>Nama: <strong>${clientName}</strong> • Sesi: ${escapeHtml(sessionToken)} • Tanggal Cetak: ${new Date().toLocaleString('id-ID')}</p>
                </div>
        `;

        chatNodes.forEach(node => {
            const isMe = node.classList.contains('items-end');
            const clone = node.cloneNode(true);
            const statusEl = clone.querySelector('.msg-status');
            if (statusEl) statusEl.remove();
            printContent += `
                <div class="msg-item ${isMe ? 'msg-me' : 'msg-bot'}">
                    ${clone.innerHTML}
                </div>
            `;
        });

        printContent += `
                <div class="footer-note">
                    Dokumen digital ini dicetak dari Pusat Layanan Live Chat Sistem Manajemen Sekolah.
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

    // 10. Quick FAQ Trigger
    window.triggerQuickFaq = async function(faqKey) {
        if (!sessionToken) return;
        try {
            const formData = new FormData();
            formData.append('action', 'faq_click');
            formData.append('session_token', sessionToken);
            formData.append('faq_key', faqKey);

            await fetch(API_URL, {
                method: 'POST',
                body: formData
            });

            setTimeout(pollNewMessages, 300);
        } catch (e) {}
    };

    // 11. Akhiri Sesi
    window.confirmCloseChatSession = async function() {
        if (!confirm('Apakah Anda yakin ingin mengakhiri sesi obrolan ini?')) return;
        if (!sessionToken) return;

        try {
            const formData = new FormData();
            formData.append('action', 'close_session');
            formData.append('session_token', sessionToken);

            await fetch(API_URL, {
                method: 'POST',
                body: formData
            });

            // Hapus token lokal agar jika dibuka lagi bisa mulai baru
            localStorage.removeItem('school_live_chat_token');
            sessionToken = '';
            currentSession = null;
            lastMessageId = 0;

            showGuestFormView();
        } catch (e) {}
    };

    // 12. Audio Notification Chime (Web Audio API - Murni Tanpa File Eksternal)
    function playChimeSound() {
        if (!soundEnabled) return;
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();
            const now = ctx.currentTime;

            // Nada ganda lembut (D5 -> A5)
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, now); // D5
            osc.frequency.exponentialRampToValueAtTime(880, now + 0.12); // A5

            gain.gain.setValueAtTime(0.12, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.35);

            osc.connect(gain);
            gain.connect(ctx.destination);

            osc.start(now);
            osc.stop(now + 0.35);
        } catch (e) {}
    }

    window.toggleLiveChatSound = function() {
        soundEnabled = !soundEnabled;
        localStorage.setItem('school_live_chat_sound', soundEnabled ? 'true' : 'false');
        updateSoundIcon();
    };

    function updateSoundIcon() {
        if (!soundIcon) return;
        if (soundEnabled) {
            soundIcon.className = 'fa-solid fa-volume-high text-xs text-teal-400';
        } else {
            soundIcon.className = 'fa-solid fa-volume-xmark text-xs text-slate-500';
        }
    }

    // 13. UI Helpers
    function scrollToBottom() {
        if (messagesContainer) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }

    function updateUnreadBadge() {
        if (!unreadBadge) return;
        if (unreadCount > 0) {
            unreadBadge.textContent = unreadCount > 9 ? '9+' : unreadCount;
            unreadBadge.classList.remove('hidden');
        } else {
            unreadBadge.classList.add('hidden');
        }
    }

    function startTitleBlink() {
        stopTitleBlink();
        let show = true;
        titleBlinkInterval = setInterval(() => {
            document.title = show ? `(1) Pesan Baru - Live Chat` : originalDocTitle;
            show = !show;
        }, 1200);
    }

    function stopTitleBlink() {
        if (titleBlinkInterval) {
            clearInterval(titleBlinkInterval);
            titleBlinkInterval = null;
            document.title = originalDocTitle;
        }
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

    // Inisialisasi saat script dimuat
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLiveChat);
    } else {
        initLiveChat();
    }

})();
</script>

