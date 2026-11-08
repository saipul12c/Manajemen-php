<?php
/**
 * API Real-time Live Chat
 * Melayani komunikasi obrolan langsung untuk Pengunjung Tamu (Sebelum Login)
 * dan Civitas Sekolah (Sesudah Login), serta Konsol Petugas Admin & Staf.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/database.php';

// Pastikan skema tabel live chat sudah diinisialisasi
initLiveChatTables($pdo);

// Ambil payload JSON atau POST biasa
$raw_input = file_get_contents('php://input');
$json_data = json_decode($raw_input, true) ?? [];
$input     = array_merge($_GET, $_POST, $json_data);

$action = trim($input['action'] ?? 'init');

// Identitas sesi login saat ini
$is_logged_in = isset($_SESSION['user_id']);
$user_id      = $is_logged_in ? (int)$_SESSION['user_id'] : null;
$user_name    = $_SESSION['user_name'] ?? 'Pengguna';
$user_role    = $_SESSION['user_role'] ?? 'pengguna';
$user_email   = $_SESSION['user_email'] ?? '';

// Informasi Sekolah
$school_info = function_exists('getSchoolSettings') ? getSchoolSettings($pdo) : [];
$school_name = $school_info['school_name'] ?? 'Sekolah';

// Helper response JSON
function sendResponse(bool $success, string $message = '', array $data = []): void {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Helper Upload Lampiran Chat Aman Terpusat (Gambar & Dokumen)
function handleChatAttachmentUpload(): ?string {
    if (empty($_FILES['attachment']['name']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'docx', 'doc'];
    $uploadRes = saveSecureUpload(
        $_FILES['attachment'],
        'chat_attachments',
        $allowedExts,
        [],
        5 * 1024 * 1024,
        'att'
    );

    if (!$uploadRes['success']) {
        sendResponse(false, $uploadRes['error']);
    }

    return $uploadRes['path'];
}

try {
    switch ($action) {

        // =========================================================
        // 1. INIT / RESTORE CHAT SESSION
        // =========================================================
        case 'init':
            if ($is_logged_in && $user_id) {
                // Pengguna terdaftar (Sudah Login)
                $stmt = $pdo->prepare("
                    SELECT * FROM live_chat_sessions 
                    WHERE user_id = ? AND status = 'active'
                    ORDER BY updated_at DESC LIMIT 1
                ");
                $stmt->execute([$user_id]);
                $session = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$session) {
                    // Buat sesi baru otomatis untuk user yang login
                    $token = 'usr_' . bin2hex(random_bytes(20));
                    $roleLabel = ucfirst($user_role);
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);

                    $stmt_ins = $pdo->prepare("
                        INSERT INTO live_chat_sessions 
                        (session_token, user_id, guest_name, guest_email, role_label, department, status, ip_address, user_agent)
                        VALUES (?, ?, ?, ?, ?, 'Civitas', 'active', ?, ?)
                    ");
                    $stmt_ins->execute([$token, $user_id, $user_name, $user_email, $roleLabel, $ip, $ua]);
                    $sessionId = (int)$pdo->lastInsertId();

                    // Pesan sambutan bot otomatis
                    $welcomeText = "Halo **" . htmlspecialchars($user_name) . "** (" . htmlspecialchars($roleLabel) . ")! Selamat datang di Layanan Live Chat " . htmlspecialchars($school_name) . ". Ada kendala sistem, pertanyaan akademik, atau informasi yang dapat kami bantu hari ini?";
                    $stmt_msg = $pdo->prepare("
                        INSERT INTO live_chat_messages (session_id, sender_type, sender_id, sender_name, message, is_read)
                        VALUES (?, 'bot', NULL, 'Virtual Assistant', ?, 0)
                    ");
                    $stmt_msg->execute([$sessionId, $welcomeText]);

                    $stmt = $pdo->prepare("SELECT * FROM live_chat_sessions WHERE id = ?");
                    $stmt->execute([$sessionId]);
                    $session = $stmt->fetch(PDO::FETCH_ASSOC);
                }

                // Ambil daftar pesan
                $stmt_msgs = $pdo->prepare("
                    SELECT id, sender_type, sender_id, sender_name, message, attachment_url, is_read, 
                           DATE_FORMAT(created_at, '%H:%i') as time_str, created_at
                    FROM live_chat_messages 
                    WHERE session_id = ? 
                    ORDER BY id ASC
                ");
                $stmt_msgs->execute([(int)$session['id']]);
                $messages = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);

                sendResponse(true, 'Sesi pengguna terhubung.', [
                    'is_logged_in'   => true,
                    'active_session' => true,
                    'session'        => $session,
                    'messages'       => $messages,
                    'user'           => [
                        'id'    => $user_id,
                        'name'  => $user_name,
                        'role'  => $user_role,
                        'email' => $user_email
                    ]
                ]);
            } else {
                // Pengguna Tamu (Sebelum Login)
                $session_token = trim($input['session_token'] ?? '');
                if (!empty($session_token)) {
                    $stmt = $pdo->prepare("SELECT * FROM live_chat_sessions WHERE session_token = ? LIMIT 1");
                    $stmt->execute([$session_token]);
                    $session = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($session && $session['status'] === 'active') {
                        $stmt_msgs = $pdo->prepare("
                            SELECT id, sender_type, sender_id, sender_name, message, attachment_url, is_read, 
                                   DATE_FORMAT(created_at, '%H:%i') as time_str, created_at
                            FROM live_chat_messages 
                            WHERE session_id = ? 
                            ORDER BY id ASC
                        ");
                        $stmt_msgs->execute([(int)$session['id']]);
                        $messages = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);

                        sendResponse(true, 'Sesi tamu ditemukan.', [
                            'is_logged_in'   => false,
                            'active_session' => true,
                            'session'        => $session,
                            'messages'       => $messages,
                        ]);
                    }
                }

                // Belum ada sesi tamu aktif
                sendResponse(true, 'Silakan masukkan nama untuk memulai chat tamu.', [
                    'is_logged_in'   => false,
                    'active_session' => false,
                    'require_start'  => true,
                ]);
            }
            break;

        // =========================================================
        // 2. MULAI SESI CHAT TAMU (SEBELUM LOGIN)
        // =========================================================
        case 'start_session':
            $name  = trim($input['guest_name'] ?? '');
            $email = trim($input['guest_email'] ?? '');
            $phone = trim($input['guest_phone'] ?? '');
            $dept  = trim($input['department'] ?? 'Layanan Umum');

            if (empty($name)) {
                sendResponse(false, 'Nama lengkap wajib diisi untuk memulai live chat.');
            }

            if (mb_strlen($name) > 100) {
                $name = mb_substr($name, 0, 100);
            }

            $allowed_depts = ['Layanan Umum', 'PPDB Online', 'Akademik & Nilai', 'Keuangan & SPP', 'Bantuan Teknis'];
            if (!in_array($dept, $allowed_depts, true)) {
                $dept = 'Layanan Umum';
            }

            $token = 'gst_' . bin2hex(random_bytes(20));
            $ip    = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);

            $stmt = $pdo->prepare("
                INSERT INTO live_chat_sessions 
                (session_token, user_id, guest_name, guest_email, guest_phone, role_label, department, status, ip_address, user_agent)
                VALUES (?, NULL, ?, ?, ?, 'Tamu', ?, 'active', ?, ?)
            ");
            $stmt->execute([$token, $name, $email ?: null, $phone ?: null, $dept, $ip, $ua]);
            $sessionId = (int)$pdo->lastInsertId();

            // Kirim pesan otomatis sambutan dari Bot
            $welcomeText = "Halo **" . htmlspecialchars($name) . "**! Selamat datang di Live Chat Pusat Layanan " . htmlspecialchars($school_name) . ".\n\nPertanyaan Anda telah kami terima pada bagian **" . htmlspecialchars($dept) . "**. Petugas admin & staf kami akan segera merespons Anda di sini. Anda juga dapat memilih pertanyaan cepat di bawah ini.";
            
            $stmt_msg = $pdo->prepare("
                INSERT INTO live_chat_messages (session_id, sender_type, sender_id, sender_name, message, is_read)
                VALUES (?, 'bot', NULL, 'Virtual Assistant', ?, 0)
            ");
            $stmt_msg->execute([$sessionId, $welcomeText]);

            // Jika topik PPDB, tambahkan tips cepat
            if ($dept === 'PPDB Online') {
                $ppdbTip = "💡 **Tip Cepat PPDB:** Anda dapat melihat alur lengkap, berkas persyaratan, dan formulir pendaftaran siswa baru langsung melalui tautan portal PPDB Online kami.";
                $stmt_msg->execute([$sessionId, $ppdbTip]);
            }

            $stmt_sess = $pdo->prepare("SELECT * FROM live_chat_sessions WHERE id = ?");
            $stmt_sess->execute([$sessionId]);
            $session = $stmt_sess->fetch(PDO::FETCH_ASSOC);

            $stmt_msgs = $pdo->prepare("
                SELECT id, sender_type, sender_id, sender_name, message, attachment_url, is_read, 
                       DATE_FORMAT(created_at, '%H:%i') as time_str, created_at
                FROM live_chat_messages 
                WHERE session_id = ? 
                ORDER BY id ASC
            ");
            $stmt_msgs->execute([$sessionId]);
            $messages = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);

            sendResponse(true, 'Sesi obrolan berhasil dimulai.', [
                'session_token'  => $token,
                'session'        => $session,
                'messages'       => $messages,
                'active_session' => true
            ]);
            break;

        // =========================================================
        // 3. KIRIM PESAN DARI PENGGUNA (TAMU ATAU USER TERDAFTAR)
        // =========================================================
        case 'send_message':
            $token   = trim($input['session_token'] ?? '');
            $message = trim($input['message'] ?? '');

            if (empty($token)) {
                sendResponse(false, 'Token sesi tidak valid.');
            }

            // Tangani lampiran jika ada
            $attachment_url = handleChatAttachmentUpload();

            if (empty($message) && empty($attachment_url)) {
                sendResponse(false, 'Pesan atau lampiran tidak boleh kosong.');
            }
            if (mb_strlen($message) > 3000) {
                sendResponse(false, 'Pesan terlalu panjang (maksimal 3000 karakter).');
            }

            // Validasi sesi
            $stmt_sess = $pdo->prepare("SELECT * FROM live_chat_sessions WHERE session_token = ? LIMIT 1");
            $stmt_sess->execute([$token]);
            $session = $stmt_sess->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                sendResponse(false, 'Sesi live chat tidak ditemukan. Silakan mulai sesi baru.');
            }

            // Proteksi Rate Limiting & Anti-Spam
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $stmt_rate = $pdo->prepare("
                SELECT COUNT(*) FROM live_chat_messages 
                WHERE session_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ");
            $stmt_rate->execute([(int)$session['id']]);
            if ((int)$stmt_rate->fetchColumn() >= 15) {
                sendResponse(false, 'Anda mengirim terlalu banyak pesan dalam 1 menit. Mohon tunggu sejenak demi kenyamanan bersama.');
            }

            // Kontrol anti-flood (jeda minimal 1 detik antar pesan)
            $stmt_last = $pdo->prepare("SELECT created_at FROM live_chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT 1");
            $stmt_last->execute([(int)$session['id']]);
            $lastCreated = $stmt_last->fetchColumn();
            if ($lastCreated && (time() - strtotime($lastCreated)) < 1) {
                sendResponse(false, 'Mohon tidak mengirim pesan terlalu cepat.');
            }

            // Jika sesi sebelumnya closed, aktifkan kembali saat ada pesan baru
            if ($session['status'] === 'closed') {
                $pdo->prepare("UPDATE live_chat_sessions SET status = 'active' WHERE id = ?")->execute([$session['id']]);
            }

            // Tentukan identitas pengirim
            if ($is_logged_in && (int)$session['user_id'] === $user_id) {
                $sender_type = 'user';
                $sender_id   = $user_id;
                $sender_name = $user_name;
            } elseif ($is_logged_in && in_array($user_role, ['administrator', 'staf'], true) && ($input['as_admin'] ?? false)) {
                // Admin membalas via widget
                $sender_type = 'admin';
                $sender_id   = $user_id;
                $sender_name = $user_name . ' (Petugas)';
            } else {
                $sender_type = 'guest';
                $sender_id   = null;
                $sender_name = $session['guest_name'] ?: 'Tamu';
            }

            // Simpan pesan beserta lampiran jika ada
            $stmt_msg = $pdo->prepare("
                INSERT INTO live_chat_messages (session_id, sender_type, sender_id, sender_name, message, attachment_url, is_read)
                VALUES (?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt_msg->execute([(int)$session['id'], $sender_type, $sender_id, $sender_name, $message ?: '[Lampiran]', $attachment_url]);
            $newMsgId = (int)$pdo->lastInsertId();

            // Perbarui waktu aktivitas sesi
            $pdo->prepare("UPDATE live_chat_sessions SET updated_at = NOW() WHERE id = ?")->execute([(int)$session['id']]);

            // Integrasi Kecerdasan Buatan (AI Engine Terpadu)
            $bot_replied = false;
            $ai_info     = null;
            if ($sender_type !== 'admin') {
                require_once __DIR__ . '/../config/ai_chat_helper.php';
                $ai_res = processAIChatResponse($pdo, (int)$session['id'], $message, $sender_type, $sender_id, $sender_name);
                if (!empty($ai_res['replied'])) {
                    $bot_replied = true;
                    $ai_info     = $ai_res;
                }
            }

            sendResponse(true, 'Pesan terkirim.', [
                'message_id'  => $newMsgId,
                'bot_replied' => $bot_replied,
                'ai_info'     => $ai_info
            ]);
            break;

        // =========================================================
        // 4. REAL-TIME POLLING PESAN BARU
        // =========================================================
        case 'poll':
            $token   = trim($input['session_token'] ?? '');
            $last_id = (int)($input['last_id'] ?? 0);

            if (empty($token)) {
                sendResponse(false, 'Token tidak diberikan.');
            }

            $stmt_sess = $pdo->prepare("SELECT id, status FROM live_chat_sessions WHERE session_token = ? LIMIT 1");
            $stmt_sess->execute([$token]);
            $session = $stmt_sess->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                sendResponse(false, 'Sesi tidak ditemukan.');
            }

            // Ambil pesan dengan id > last_id
            $stmt_msgs = $pdo->prepare("
                SELECT id, sender_type, sender_id, sender_name, message, attachment_url, is_read, 
                       DATE_FORMAT(created_at, '%H:%i') as time_str, created_at
                FROM live_chat_messages 
                WHERE session_id = ? AND id > ?
                ORDER BY id ASC
            ");
            $stmt_msgs->execute([(int)$session['id'], $last_id]);
            $new_messages = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);

            // Tandai pesan dari admin/bot sebagai sudah dibaca oleh pengunjung
            if (!empty($new_messages)) {
                $pdo->prepare("
                    UPDATE live_chat_messages 
                    SET is_read = 1 
                    WHERE session_id = ? AND sender_type IN ('admin', 'bot') AND id > ?
                ")->execute([(int)$session['id'], $last_id]);
            }

            sendResponse(true, 'Poll berhasil.', [
                'messages'       => $new_messages,
                'session_status' => $session['status']
            ]);
            break;

        // =========================================================
        // 5. KLIK QUICK FAQ CHIPS
        // =========================================================
        case 'faq_click':
            $token   = trim($input['session_token'] ?? '');
            $faq_key = trim($input['faq_key'] ?? '');

            if (empty($token)) {
                sendResponse(false, 'Token tidak diberikan.');
            }

            $stmt_sess = $pdo->prepare("SELECT id, guest_name FROM live_chat_sessions WHERE session_token = ? LIMIT 1");
            $stmt_sess->execute([$token]);
            $session = $stmt_sess->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                sendResponse(false, 'Sesi tidak valid.');
            }

            $faq_answers = [
                'ppdb' => "📋 **Informasi Penerimaan Peserta Didik Baru (PPDB):**\n• Pendaftaran dibuka secara online melalui portal PPDB Sekolah.\n• Berkas yang dibutuhkan: Akta Kelahiran, Kartu Keluarga (KK), Ijazah/SKL terakhir, dan pas foto terbaru.\n• Setelah mendaftar, Anda dapat mengunduh Bukti Kartu Pendaftaran untuk verifikasi berkas.",
                'biaya' => "💳 **Informasi Pembayaran & SPP:**\n• Seluruh pembayaran SPP dan administrasi sekolah menggunakan sistem pencatatan resmi.\n• Siswa yang telah memiliki akun portal dapat mengecek status lunas atau riwayat tagihan pada dashboard masing-masing di tab Keuangan.\n• Konfirmasi transfer dapat langsung disampaikan ke staf Tata Usaha.",
                'jadwal' => "⏰ **Jadwal Kegiatan & Jam Pelayanan:**\n• Kegiatan Belajar Mengajar: Senin – Jumat pukul 07.00 – 15.00 WIB.\n• Jam Pelayanan Tata Usaha: Senin – Jumat pukul 07.30 – 15.30 WIB.\n• Pelayanan offline libur pada hari Sabtu, Minggu, dan Hari Libur Nasional.",
                'kontak' => "📞 **Pusat Bantuan & Kontak:**\n• Telepon/WhatsApp: " . ($school_info['school_phone'] ?? '(021) 789-0123') . "\n• Email: " . ($school_info['school_email'] ?? 'info@sekolah.sch.id') . "\n• Alamat: " . ($school_info['school_address'] ?? 'Jakarta, Indonesia') . "\nTim kami siap membantu Anda."
            ];

            $response_text = $faq_answers[$faq_key] ?? "Terima kasih telah bertanya. Petugas kami akan segera menjawab pesan Anda secara langsung.";

            $stmt_ins = $pdo->prepare("
                INSERT INTO live_chat_messages (session_id, sender_type, sender_id, sender_name, message, is_read)
                VALUES (?, 'bot', NULL, 'Virtual Assistant', ?, 0)
            ");
            $stmt_ins->execute([(int)$session['id'], $response_text]);

            $pdo->prepare("UPDATE live_chat_sessions SET updated_at = NOW() WHERE id = ?")->execute([(int)$session['id']]);

            sendResponse(true, 'Jawaban FAQ terkirim.');
            break;

        // =========================================================
        // 6. AKHIRI / TUTUP SESI OLEH PENGUNJUNG
        // =========================================================
        case 'close_session':
            $token = trim($input['session_token'] ?? '');
            if (!empty($token)) {
                $stmt_sess = $pdo->prepare("SELECT id FROM live_chat_sessions WHERE session_token = ? LIMIT 1");
                $stmt_sess->execute([$token]);
                $session = $stmt_sess->fetch(PDO::FETCH_ASSOC);

                if ($session) {
                    $pdo->prepare("UPDATE live_chat_sessions SET status = 'closed', updated_at = NOW() WHERE id = ?")->execute([(int)$session['id']]);
                    $bye = "Percakapan telah diakhiri. Terima kasih telah menghubungi Pusat Layanan " . htmlspecialchars($school_name) . ". Semoga harimu menyenangkan!";
                    $pdo->prepare("
                        INSERT INTO live_chat_messages (session_id, sender_type, sender_id, sender_name, message, is_read)
                        VALUES (?, 'bot', NULL, 'Virtual Assistant', ?, 1)
                    ")->execute([(int)$session['id'], $bye]);
                }
            }
            sendResponse(true, 'Sesi berhasil ditutup.');
            break;

        // =========================================================
        // 7. ADMIN / STAF: AMBIL DAFTAR SESI OBROLAN
        // =========================================================
        case 'admin_get_sessions':
            if (!$is_logged_in || !in_array($user_role, ['administrator', 'staf'], true)) {
                sendResponse(false, 'Akses tidak diizinkan. Khusus Administrator dan Staf.');
            }

            $filter = trim($input['filter'] ?? 'all');
            $search = trim($input['q'] ?? '');

            $where = "WHERE 1=1";
            $params = [];

            if ($filter === 'active') {
                $where .= " AND s.status = 'active'";
            } elseif ($filter === 'closed') {
                $where .= " AND s.status = 'closed'";
            } elseif ($filter === 'guest') {
                $where .= " AND s.user_id IS NULL";
            } elseif ($filter === 'user') {
                $where .= " AND s.user_id IS NOT NULL";
            } elseif ($filter === 'unread') {
                $where .= " AND (SELECT COUNT(*) FROM live_chat_messages m WHERE m.session_id = s.id AND m.is_read = 0 AND m.sender_type IN ('guest', 'user')) > 0";
            }

            if (!empty($search)) {
                $where .= " AND (s.guest_name LIKE ? OR s.guest_email LIKE ? OR s.guest_phone LIKE ? OR u.name LIKE ? OR u.email LIKE ?)";
                $wild = "%$search%";
                $params = array_merge($params, [$wild, $wild, $wild, $wild, $wild]);
            }

            $sql = "
                SELECT s.*, 
                       u.name as reg_user_name, u.email as reg_user_email, u.role as reg_user_role,
                       (SELECT m.message FROM live_chat_messages m WHERE m.session_id = s.id ORDER BY m.id DESC LIMIT 1) as last_message,
                       (SELECT m.sender_name FROM live_chat_messages m WHERE m.session_id = s.id ORDER BY m.id DESC LIMIT 1) as last_sender,
                       (SELECT m.created_at FROM live_chat_messages m WHERE m.session_id = s.id ORDER BY m.id DESC LIMIT 1) as last_message_time,
                       (SELECT COUNT(*) FROM live_chat_messages m WHERE m.session_id = s.id AND m.is_read = 0 AND m.sender_type IN ('guest', 'user')) as unread_count
                FROM live_chat_sessions s
                LEFT JOIN users u ON s.user_id = u.id
                $where
                ORDER BY (unread_count > 0) DESC, s.updated_at DESC
                LIMIT 50
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Ringkasan statistik
            $totalActive = (int)$pdo->query("SELECT COUNT(*) FROM live_chat_sessions WHERE status = 'active'")->fetchColumn();
            $totalUnread = (int)$pdo->query("SELECT COUNT(*) FROM live_chat_messages WHERE is_read = 0 AND sender_type IN ('guest', 'user')")->fetchColumn();

            sendResponse(true, 'Data sesi berhasil dimuat.', [
                'sessions'     => $sessions,
                'total_active' => $totalActive,
                'total_unread' => $totalUnread
            ]);
            break;

        // =========================================================
        // 8. ADMIN / STAF: LIHAT RIWAYAT CHAT LENGKAP & TANDAI BACA
        // =========================================================
        case 'admin_get_chat':
            if (!$is_logged_in || !in_array($user_role, ['administrator', 'staf'], true)) {
                sendResponse(false, 'Akses tidak diizinkan.');
            }

            $session_id = (int)($input['session_id'] ?? 0);
            if ($session_id <= 0) {
                sendResponse(false, 'ID sesi tidak valid.');
            }

            // Ambil info sesi
            $stmt = $pdo->prepare("
                SELECT s.*, u.name as reg_user_name, u.email as reg_user_email, u.role as reg_user_role
                FROM live_chat_sessions s
                LEFT JOIN users u ON s.user_id = u.id
                WHERE s.id = ? LIMIT 1
            ");
            $stmt->execute([$session_id]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                sendResponse(false, 'Sesi tidak ditemukan.');
            }

            // Tandai pesan dari tamu/user sebagai dibaca oleh admin
            $pdo->prepare("
                UPDATE live_chat_messages 
                SET is_read = 1 
                WHERE session_id = ? AND sender_type IN ('guest', 'user') AND is_read = 0
            ")->execute([$session_id]);

            // Ambil seluruh percakapan
            $stmt_msgs = $pdo->prepare("
                SELECT id, sender_type, sender_id, sender_name, message, attachment_url, is_read, 
                       DATE_FORMAT(created_at, '%H:%i') as time_str, created_at
                FROM live_chat_messages 
                WHERE session_id = ? 
                ORDER BY id ASC
            ");
            $stmt_msgs->execute([$session_id]);
            $messages = $stmt_msgs->fetchAll(PDO::FETCH_ASSOC);

            sendResponse(true, 'Riwayat chat berhasil dimuat.', [
                'session'  => $session,
                'messages' => $messages
            ]);
            break;

        // =========================================================
        // 9. ADMIN / STAF: KIRIM BALASAN KE PENGUNJUNG
        // =========================================================
        case 'admin_reply':
            if (!$is_logged_in || !in_array($user_role, ['administrator', 'staf'], true)) {
                sendResponse(false, 'Akses tidak diizinkan.');
            }

            $session_id = (int)($input['session_id'] ?? 0);
            $message    = trim($input['message'] ?? '');
            $attachment_url = handleChatAttachmentUpload();

            if ($session_id <= 0 || (empty($message) && empty($attachment_url))) {
                sendResponse(false, 'Pesan balasan atau lampiran wajib diisi.');
            }

            // Validasi keberadaan sesi
            $stmt_sess = $pdo->prepare("SELECT id, status FROM live_chat_sessions WHERE id = ? LIMIT 1");
            $stmt_sess->execute([$session_id]);
            $session = $stmt_sess->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                sendResponse(false, 'Sesi tidak ditemukan.');
            }

            $admin_display_name = $user_name . ' (' . ucfirst($user_role) . ')';

            $stmt_ins = $pdo->prepare("
                INSERT INTO live_chat_messages (session_id, sender_type, sender_id, sender_name, message, attachment_url, is_read)
                VALUES (?, 'admin', ?, ?, ?, ?, 0)
            ");
            $stmt_ins->execute([$session_id, $user_id, $admin_display_name, $message ?: '[Lampiran]', $attachment_url]);
            $newId = (int)$pdo->lastInsertId();

            // Perbarui waktu update dan pastikan status aktif serta tandai sudah ditangani
            $pdo->prepare("UPDATE live_chat_sessions SET status = 'active', needs_human = 0, updated_at = NOW() WHERE id = ?")->execute([$session_id]);

            sendResponse(true, 'Balasan berhasil dikirim.', [
                'message_id'     => $newId,
                'attachment_url' => $attachment_url,
                'time_str'       => date('H:i')
            ]);
            break;

        // =========================================================
        // 10. ADMIN: UBAH STATUS SESI (AKTIF / SELESAI)
        // =========================================================
        case 'admin_update_status':
            if (!$is_logged_in || !in_array($user_role, ['administrator', 'staf'], true)) {
                sendResponse(false, 'Akses tidak diizinkan.');
            }

            $session_id = (int)($input['session_id'] ?? 0);
            $new_status = trim($input['status'] ?? 'active');

            if (!in_array($new_status, ['active', 'closed'], true)) {
                sendResponse(false, 'Status tidak valid.');
            }

            $stmt = $pdo->prepare("UPDATE live_chat_sessions SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $session_id]);

            // Jika ditutup, tambahkan pesan sistem
            if ($new_status === 'closed') {
                $note = "Petugas telah menandai sesi obrolan ini sebagai Selesai. Terima kasih!";
                $pdo->prepare("
                    INSERT INTO live_chat_messages (session_id, sender_type, sender_id, sender_name, message, is_read)
                    VALUES (?, 'bot', NULL, 'Sistem', ?, 1)
                ")->execute([$session_id, $note]);
            }

            sendResponse(true, 'Status sesi berhasil diperbarui.');
            break;

        // =========================================================
        // 11. ADMIN: HAPUS SESI LENGKAP (KHUSUS ADMINISTRATOR)
        // =========================================================
        case 'admin_delete_session':
            if (!$is_logged_in || $user_role !== 'administrator') {
                sendResponse(false, 'Hanya Administrator yang berhak menghapus data sesi obrolan.');
            }

            $session_id = (int)($input['session_id'] ?? 0);
            if ($session_id <= 0) {
                sendResponse(false, 'ID sesi tidak valid.');
            }

            $pdo->prepare("DELETE FROM live_chat_sessions WHERE id = ?")->execute([$session_id]);

            sendResponse(true, 'Sesi obrolan dan seluruh pesannya berhasil dihapus.');
            break;

        // =========================================================
        // 12. ADMIN: TOGGLE AI PADA SESI TERTENTU (AMBIL ALIH / AKTIFKAN LAGI)
        // =========================================================
        case 'admin_toggle_session_ai':
            if (!$is_logged_in || !in_array($user_role, ['administrator', 'staf'], true)) {
                sendResponse(false, 'Akses tidak diizinkan.');
            }

            $session_id = (int)($input['session_id'] ?? 0);
            $ai_state   = (int)($input['ai_enabled'] ?? 1) ? 1 : 0;

            if ($session_id <= 0) {
                sendResponse(false, 'ID sesi tidak valid.');
            }

            $pdo->prepare("UPDATE live_chat_sessions SET ai_enabled = ?, updated_at = NOW() WHERE id = ?")->execute([$ai_state, $session_id]);

            // Tambahkan pesan sistem informatif
            $msgNotice = $ai_state 
                ? "🤖 *AI Virtual Assistant diaktifkan kembali untuk merespons percakapan ini.*"
                : "👤 *Petugas staf telah mengambil alih percakapan ini (AI dijeda).*";
            $pdo->prepare("
                INSERT INTO live_chat_messages (session_id, sender_type, sender_id, sender_name, message, is_read)
                VALUES (?, 'bot', NULL, 'Sistem', ?, 1)
            ")->execute([$session_id, $msgNotice]);

            sendResponse(true, 'Status AI sesi berhasil diperbarui.', ['ai_enabled' => $ai_state]);
            break;

        // =========================================================
        // 13. ADMIN: SIMPAN PENGATURAN GLOBAL AI LIVE CHAT
        // =========================================================
        case 'admin_save_ai_settings':
            if (!$is_logged_in || $user_role !== 'administrator') {
                sendResponse(false, 'Hanya Administrator yang berhak mengubah konfigurasi AI.');
            }

            $ai_enabled  = isset($input['livechat_ai_enabled']) ? (string)(int)$input['livechat_ai_enabled'] : '1';
            $ai_provider = trim($input['livechat_ai_provider'] ?? 'hybrid');
            $ai_model    = trim($input['livechat_ai_model'] ?? 'gemini-1.5-flash');
            $ai_key      = trim($input['livechat_ai_api_key'] ?? '');
            $ai_endpoint = trim($input['livechat_ai_endpoint'] ?? '');
            $ai_prompt   = trim($input['livechat_ai_system_prompt'] ?? '');
            $bot_name    = trim($input['livechat_ai_bot_name'] ?? 'AI Asisten Sekolah');

            $allowed_providers = ['hybrid', 'local', 'gemini', 'openai', 'custom_llm'];
            if (!in_array($ai_provider, $allowed_providers, true)) {
                $ai_provider = 'hybrid';
            }

            $settings_to_update = [
                'livechat_ai_enabled'       => $ai_enabled,
                'livechat_ai_provider'      => $ai_provider,
                'livechat_ai_model'         => $ai_model,
                'livechat_ai_api_key'       => $ai_key,
                'livechat_ai_endpoint'      => $ai_endpoint,
                'livechat_ai_system_prompt' => $ai_prompt,
                'livechat_ai_bot_name'      => $bot_name,
            ];

            $stmt_upsert = $pdo->prepare("
                INSERT INTO school_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            foreach ($settings_to_update as $k => $v) {
                $stmt_upsert->execute([$k, $v]);
            }

            sendResponse(true, 'Konfigurasi AI Live Chat berhasil disimpan.');
            break;

        // =========================================================
        // 14. ADMIN: AMBIL PENGATURAN AI LIVE CHAT
        // =========================================================
        case 'admin_get_ai_settings':
            if (!$is_logged_in || !in_array($user_role, ['administrator', 'staf'], true)) {
                sendResponse(false, 'Akses tidak diizinkan.');
            }

            $settings = function_exists('getSchoolSettings') ? getSchoolSettings($pdo, true) : [];
            sendResponse(true, 'Pengaturan AI dimuat.', [
                'livechat_ai_enabled'       => $settings['livechat_ai_enabled'] ?? '1',
                'livechat_ai_provider'      => $settings['livechat_ai_provider'] ?? 'hybrid',
                'livechat_ai_model'         => $settings['livechat_ai_model'] ?? 'gemini-1.5-flash',
                'livechat_ai_api_key'       => $settings['livechat_ai_api_key'] ?? '',
                'livechat_ai_endpoint'      => $settings['livechat_ai_endpoint'] ?? '',
                'livechat_ai_system_prompt' => $settings['livechat_ai_system_prompt'] ?? '',
                'livechat_ai_bot_name'      => $settings['livechat_ai_bot_name'] ?? 'AI Asisten Sekolah',
            ]);
            break;

        // =========================================================
        // 15. ADMIN: UJI COBA RESPON AI (LIVE TEST BENCH & SENTINEL NLP TRACE)
        // =========================================================
        case 'admin_test_ai':
            if (!$is_logged_in || !in_array($user_role, ['administrator', 'staf'], true)) {
                sendResponse(false, 'Akses tidak diizinkan.');
            }

            require_once __DIR__ . '/../config/ai_chat_helper.php';
            $test_message = trim($input['message'] ?? 'Halo, bagaimana syarat PPDB online?');
            $context      = getSchoolKnowledgeContext($pdo);
            $nlpDebug     = null;
            $test_reply   = generateLocalAIResponse($test_message, $context, $user_name, ucfirst($user_role), [], $nlpDebug);

            sendResponse(true, 'Simulasi AI berhasil.', [
                'query'    => $test_message,
                'reply'    => $test_reply,
                'nlp_meta' => $nlpDebug
            ]);
            break;

        // =========================================================
        // 16. ADMIN: STATISTIK & METRIK SENTINEL NLP
        // =========================================================
        case 'admin_get_nlp_stats':
            if (!$is_logged_in || !in_array($user_role, ['administrator', 'staf'], true)) {
                sendResponse(false, 'Akses tidak diizinkan.');
            }

            require_once __DIR__ . '/../config/ai_chat_helper.php';
            $stats = getSentinelNLPStats($pdo);
            sendResponse(true, 'Statistik Sentinel NLP berhasil diambil.', ['stats' => $stats]);
            break;

        // =========================================================
        // 17. ADMIN: LATIH PENGETAHUAN MANDIRI (SELF-LEARNING)
        // =========================================================
        case 'admin_nlp_teach':
            if (!$is_logged_in || !in_array($user_role, ['administrator', 'staf'], true)) {
                sendResponse(false, 'Akses tidak diizinkan.');
            }

            require_once __DIR__ . '/../config/ai_chat_helper.php';
            $q = trim($input['question'] ?? '');
            $a = trim($input['answer'] ?? '');
            if (empty($q) || empty($a)) {
                sendResponse(false, 'Pertanyaan dan jawaban wajib diisi.');
            }

            $res = teachSentinelNLP($q, $a, $pdo);
            sendResponse($res['success'], $res['message']);
            break;

        // =========================================================
        // 18. ADMIN: DAFTAR PENGETAHUAN TERPELAJARI
        // =========================================================
        case 'admin_get_nlp_learned':
            if (!$is_logged_in || !in_array($user_role, ['administrator', 'staf'], true)) {
                sendResponse(false, 'Akses tidak diizinkan.');
            }

            require_once __DIR__ . '/../config/ai_chat_helper.php';
            $list = getSentinelNLPLearnedList($pdo);
            sendResponse(true, 'Daftar pembelajaran Sentinel NLP berhasil diambil.', ['learned' => $list]);
            break;

        // =========================================================
        // 19. ADMIN: HAPUS PENGETAHUAN TERPELAJARI (KHUSUS ADMIN)
        // =========================================================
        case 'admin_delete_nlp_learned':
            if (!$is_logged_in || $user_role !== 'administrator') {
                sendResponse(false, 'Hanya Administrator yang berhak menghapus data pembelajaran.');
            }

            require_once __DIR__ . '/../config/ai_chat_helper.php';
            $q = trim($input['question'] ?? '');
            if (empty($q)) {
                sendResponse(false, 'Pertanyaan wajib ditentukan.');
            }

            $res = deleteSentinelNLPLearned($q, $pdo);
            sendResponse($res['success'], $res['message']);
            break;

        default:
            sendResponse(false, 'Aksi tidak dikenal.');
    }
} catch (Exception $e) {
    sendResponse(false, 'Terjadi kesalahan pada server: ' . $e->getMessage());
}

