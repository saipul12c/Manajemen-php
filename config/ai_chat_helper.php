<?php
/**
 * AI Chat Helper & Knowledge Engine
 * Pusat Pemrosesan Kecerdasan Buatan Terintegrasi untuk Live Chat Sekolah
 * 
 * Mendukung 2 Arsitektur AI:
 * 1. Kecerdasan Buatan Mandiri / Lokal (In-House Rule & Context-Aware Knowledge Engine)
 *    - 100% Berjalan di server sendiri tanpa biaya/kuota API & tanpa ketergantungan internet.
 *    - Mengambil data real-time langsung dari database sekolah (Profil, PPDB, Berita, Kalender, Tagihan, Perpus).
 * 2. Integrasi Cloud LLM (Google Gemini, OpenAI GPT, atau Self-Hosted Ollama/LocalAI)
 *    - Menggunakan RAG (Retrieval-Augmented Generation) dengan konteks data sekolah sebagai ground truth.
 *    - Mendukung Multi-turn Conversation Context (mengingat pesan sebelumnya).
 *    - Otomatis Fallback ke AI Mandiri jika kuota API habis atau offline.
 */
declare(strict_types=1);

if (!defined('AI_CHAT_HELPER_LOADED')) {
    define('AI_CHAT_HELPER_LOADED', true);
}

/**
 * Sentinel NLP Autoloader & Singleton Instance Manager
 * Mengintegrasikan mesin pemrosesan bahasa alami (NLP), TF-IDF, Sentimen,
 * Penalaran Logika, Matematika, Asisten Kode, Memori Percakapan, dan Self-Learning
 * dari folder /engine/NLP/.
 */
spl_autoload_register(function ($class) {
    $prefix = 'Sentinel\\NLP\\';
    $baseDir = __DIR__ . '/../engine/NLP/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Dapatkan instance tunggal ChatbotAI (Sentinel NLP Engine)
 * Terhubung 100% ke database MySQL (Tabel sentinel_nlp_knowledge & sentinel_nlp_sessions)
 */
function getSentinelNLPInstance(?PDO $pdo = null): ?\Sentinel\NLP\ChatbotAI {
    static $nlpInstance = null;

    if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    if ($pdo !== null && function_exists('initLiveChatTables')) {
        initLiveChatTables($pdo);
    }

    if ($nlpInstance !== null) {
        if ($pdo !== null && $nlpInstance->getPDO() === null) {
            $nlpInstance->setPDO($pdo);
        }
        return $nlpInstance;
    }

    $nlpFile = __DIR__ . '/../engine/NLP/ChatbotAI.php';
    $knowledgePath = __DIR__ . '/../engine/data/knowledge.json';

    if (!file_exists($nlpFile) || !file_exists($knowledgePath)) {
        return null;
    }

    try {
        $nlpInstance = new \Sentinel\NLP\ChatbotAI($knowledgePath, $pdo);
        return $nlpInstance;
    } catch (\Throwable $e) {
        error_log("Failed to initialize Sentinel NLP Engine: " . $e->getMessage());
        return null;
    }
}

/**
 * Ambil statistik performa dan korpus kosakata dari mesin NLP Sentinel
 */
function getSentinelNLPStats(?PDO $pdo = null): array {
    if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    $nlp = getSentinelNLPInstance($pdo);
    if (!$nlp) {
        return [
            'status'           => 'offline',
            'total_intents'    => 0,
            'total_patterns'   => 0,
            'total_learned'    => 0,
            'total_knowledge'  => 0,
            'positive_lexicon' => 0,
            'negative_lexicon' => 0,
        ];
    }

    $stats = $nlp->getStats();

    // Hitung total pengetahuan terpelajari langsung dari MySQL
    if ($pdo !== null) {
        try {
            $dbLearnedCount = (int)$pdo->query("SELECT COUNT(*) FROM sentinel_nlp_knowledge")->fetchColumn();
            $stats['total_learned'] = $dbLearnedCount;
            $stats['total_knowledge'] = $stats['total_patterns'] + $dbLearnedCount;
        } catch (\Exception $e) {}
    }

    $stats['status'] = 'active';
    $stats['storage_mode'] = '100% MySQL Database (sentinel_nlp_knowledge)';
    $stats['engine_version'] = 'Sentinel NLP Cognitive v2.5';
    return $stats;
}

/**
 * Mengajarkan pengetahuan Q&A baru secara eksklusif ke tabel MySQL sentinel_nlp_knowledge
 */
function teachSentinelNLP(string $question, string $answer, ?PDO $pdo = null): array {
    if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    $nlp = getSentinelNLPInstance($pdo);
    if (!$nlp) {
        return ['success' => false, 'message' => 'Gagal menginisialisasi modul Sentinel NLP.'];
    }

    $ok = $nlp->teach($question, $answer);
    return [
        'success' => (bool)$ok,
        'message' => $ok 
            ? 'Pengetahuan baru berhasil disimpan ke database MySQL dan diindeks oleh Sentinel NLP!' 
            : 'Gagal menyimpan pengetahuan ke basis data.'
    ];
}

/**
 * Ambil daftar seluruh pertanyaan yang telah dipelajari dari database MySQL
 */
function getSentinelNLPLearnedList(?PDO $pdo = null): array {
    if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    if ($pdo !== null) {
        try {
            $stmt = $pdo->query("SELECT question, answer, category, created_at FROM sentinel_nlp_knowledge ORDER BY id DESC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {}
    }

    $nlp = getSentinelNLPInstance($pdo);
    if (!$nlp) {
        return [];
    }
    $data = $nlp->getKnowledgeData();
    return $data['learned'] ?? [];
}

/**
 * Menghapus entri pengetahuan yang dipelajari eksklusif dari database MySQL
 */
function deleteSentinelNLPLearned(string $question, ?PDO $pdo = null): array {
    if ($pdo === null && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        $pdo = $GLOBALS['pdo'];
    }

    $deleted = false;
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare("DELETE FROM sentinel_nlp_knowledge WHERE LOWER(TRIM(question)) = LOWER(TRIM(?))");
            $stmt->execute([$question]);
            $deleted = ($stmt->rowCount() > 0);
        } catch (\Exception $e) {}
    }

    $nlp = getSentinelNLPInstance($pdo);
    if ($nlp) {
        $nlp->deleteLearned($question);
    }

    return [
        'success' => true,
        'message' => 'Pengetahuan berhasil dihapus dari basis data MySQL Sentinel NLP.'
    ];
}

/**
 * 1. Ambil Pengetahuan Lengkap Sekolah dari Database Real-time (Ground Truth Context)
 */
function getSchoolKnowledgeContext(PDO $pdo): array {
    static $knowledge = null;
    if ($knowledge !== null) {
        return $knowledge;
    }

    $settings = function_exists('getSchoolSettings') ? getSchoolSettings($pdo) : [];

    // Profil Dasar Sekolah
    $school_name     = $settings['school_name'] ?? 'Sekolah';
    $school_address  = $settings['school_address'] ?? 'Jl. Pendidikan Nasional, Jakarta';
    $school_phone    = $settings['school_phone'] ?? '(021) 789-0123';
    $school_email    = $settings['school_email'] ?? 'info@sekolah.sch.id';
    $school_web      = $settings['school_website'] ?? 'https://sekolah.sch.id';
    $headmaster      = $settings['headmaster_name'] ?? 'Kepala Sekolah';
    $academic_year   = $settings['academic_year'] ?? '2026/2027';
    $semester        = $settings['academic_semester'] ?? 'Ganjil';

    // PPDB Data Real-time
    $ppdb_status     = $settings['ppdb_status'] ?? 'buka';
    $ppdb_wave       = $settings['ppdb_wave_name'] ?? 'Gelombang 1';
    $ppdb_quota      = $settings['ppdb_quota'] ?? '150';
    $ppdb_start      = $settings['ppdb_start_date'] ?? '';
    $ppdb_end        = $settings['ppdb_end_date'] ?? '';
    $total_ppdb_pendaftar = 0;
    try {
        $total_ppdb_pendaftar = (int)$pdo->query("SELECT COUNT(*) FROM ppdb_registrations")->fetchColumn();
    } catch (Exception $e) {}

    // Berita & Pengumuman Terkini (3 Teratas)
    $recent_news = [];
    try {
        $stmt_news = $pdo->query("
            SELECT title, category, DATE_FORMAT(published_at, '%d %M %Y') as tgl 
            FROM news 
            WHERE status = 'published' 
            ORDER BY published_at DESC LIMIT 3
        ");
        $recent_news = $stmt_news->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Pengumuman Aktif
    $recent_announcements = [];
    try {
        $stmt_ann = $pdo->query("
            SELECT title, category, DATE_FORMAT(created_at, '%d %M %Y') as tgl 
            FROM announcements 
            WHERE status = 'published' 
            ORDER BY is_pinned DESC, created_at DESC LIMIT 3
        ");
        $recent_announcements = $stmt_ann->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Kalender Kegiatan Terdekat
    $upcoming_events = [];
    try {
        $stmt_cal = $pdo->query("
            SELECT title, event_date, category 
            FROM calendar_events 
            WHERE event_date >= CURDATE() 
            ORDER BY event_date ASC LIMIT 3
        ");
        $upcoming_events = $stmt_cal->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Biaya & Tagihan Resmi (SPP)
    $payment_types = [];
    try {
        $stmt_pay = $pdo->query("SELECT name, amount FROM payment_types LIMIT 5");
        $payment_types = $stmt_pay->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Buku Perpustakaan Populer
    $library_sample = [];
    try {
        $stmt_lib = $pdo->query("SELECT title, author, category FROM library_books WHERE stock_available > 0 LIMIT 4");
        $library_sample = $stmt_lib->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $knowledge = [
        'school_name'     => $school_name,
        'school_address'  => $school_address,
        'school_phone'    => $school_phone,
        'school_email'    => $school_email,
        'school_website'  => $school_web,
        'headmaster'      => $headmaster,
        'academic_year'   => $academic_year,
        'semester'        => $semester,
        'ppdb'            => [
            'status'         => $ppdb_status,
            'wave'           => $ppdb_wave,
            'quota'          => $ppdb_quota,
            'start_date'     => $ppdb_start,
            'end_date'       => $ppdb_end,
            'total_pelamar'  => $total_ppdb_pendaftar,
            'tracks'         => ['Reguler', 'Zonasi Jarak', 'Prestasi Akademik/Non-Akademik', 'Afirmasi'],
            'documents'      => ['Kartu Keluarga (KK)', 'Akta Kelahiran', 'Ijazah / SKL Jenjang Sebelumnya', 'Pas Foto Berwarna Terbaru', 'Piagam Prestasi (khusus jalur prestasi)']
        ],
        'recent_news'     => $recent_news,
        'announcements'   => $recent_announcements,
        'events'          => $upcoming_events,
        'payment_types'   => $payment_types,
        'library_sample'  => $library_sample,
        'office_hours'    => 'Senin – Jumat pukul 07.30 s.d. 15.30 WIB (Sabtu, Minggu & Hari Libur Nasional tutup)',
        'facilities'      => ['Laboratorium Komputer & Multimedia', 'Perpustakaan Digital & Buku Fisik', 'Laboratorium IPA & Bahasa', 'Lapangan Olahraga Multifungsi (Futsal, Basket, Voli)', 'Masjid Sekolah', 'UKS Berstandar', 'Kantin Sehat Higienis', 'Akses Internet WiFi Civitas'],
        'extracurricular' => ['Pramuka (Wajib)', 'Paskibra', 'PMR / KSPAN', 'Futsal & Sepakbola', 'Bola Basket', 'Klub Robotik & Coding', 'Seni Musik & Tari Tradisional', 'Rohis & Rokris']
    ];

    return $knowledge;
}

/**
 * 2. Deteksi Apakah Pengguna Meminta Bicara dengan Staf Manusia (Eskalasi / Handoff)
 */
function detectHandoffIntent(string $message): bool {
    $lower = mb_strtolower($message);
    $handoff_keywords = [
        'bicara dengan orang', 'bicara dengan staf', 'bicara dengan petugas', 'bicara dengan admin',
        'hubungkan dengan admin', 'hubungkan ke staf', 'operator manusia', 'customer service',
        'mau bicara langsung', 'petugas asli', 'bukan bot', 'hubungi staf', 'mau komplain',
        'operator tolong', 'manusia tolong', 'minta nomor wa admin', 'kontak admin langsung',
        'ngomong sama orang'
    ];

    foreach ($handoff_keywords as $kw) {
        if (strpos($lower, $kw) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * 3. Mesin Kecerdasan Buatan Mandiri / Lokal (Sentinel NLP & In-House Context Engine)
 *    Mampu menjawab secara cerdas, kontekstual, ramah, dan solutif dengan integrasi
 *    penuh ke mesin pemrosesan bahasa alami (NLP), penalaran logika, analisis emosi,
 *    dan basis pengetahuan sekolah.
 */
function generateLocalAIResponse(string $userMessage, array $context, string $senderName, string $senderRole, array $history = [], ?array &$nlpDebug = null): string {
    $msg = trim($userMessage);
    $lower = mb_strtolower($msg);
    $school = $context['school_name'] ?? 'Sekolah Kami';

    // 1. Cek jika butuh handoff manusia
    if (detectHandoffIntent($userMessage)) {
        return "Baik, Kak **" . htmlspecialchars($senderName) . "**! Pertanyaan Anda telah saya tandai untuk **ditindaklanjuti oleh Petugas Staf TU / Admin** kami.\n\nPetugas kami telah diberi notifikasi prioritas di konsol chat dan akan segera merespons Anda di sini. Anda juga dapat menghubungi layanan telepon resmi kami di **" . htmlspecialchars($context['school_phone'] ?? '(021) 789-0123') . "** pada jam kerja operasional.";
    }

    // 2. Integrasi Mesin NLP Sentinel (/engine/NLP/)
    $nlp = getSentinelNLPInstance();
    if ($nlp !== null) {
        try {
            $nlpSessionId = 'livechat_' . md5($senderName . '_' . $school);
            $nlpRes = $nlp->respond($userMessage, [
                'session_id'  => $nlpSessionId,
                'history'     => $history,
                'sender_name' => $senderName,
                'sender_role' => $senderRole
            ]);

            if (is_array($nlpRes)) {
                $nlpDebug = $nlpRes;
                $intent = $nlpRes['intent'] ?? 'unknown';

                // 2.1 Protokol Krisis Emosional / Dukungan Empati Afektif
                if (!empty($nlpRes['sentiment']['is_crisis']) || in_array($intent, ['crisis_support', 'dukungan_empati_duka', 'emotional_support'], true)) {
                    return $nlpRes['reply'];
                }

                // 2.2 Alat Komputasi & Penalaran Khusus (Matematika, Koding, Logika, Teks Kreatif, Memori)
                $toolIntents = [
                    'arithmetic', 'percentage', 'sqrt', 'power', 'date_time', 'factorial',
                    'math_calculation', 'math_percentage', 'math_sqrt', 'math_power', 'math_date_time',
                    'code_generation', 'code_debug', 'code_explanation', 'coding_assistant',
                    'logic_deduction', 'logic_sequence', 'logic_puzzle_solver',
                    'creative_poem', 'creative_story', 'creative_rhyme', 'creative_paraphrase',
                    'cognitive_concept_synthesis', 'compound_discourse', 'compound_discourse_orchestration',
                    'user_introduction', 'session_fact_saved', 'memory_recall', 'session_memory_query',
                    'episodic_memory_synthesis', 'decision_tree_inference', 'neural_network_inference'
                ];
                if (in_array($intent, $toolIntents, true) || str_starts_with($intent, 'coding_') || str_starts_with($intent, 'math_') || str_starts_with($intent, 'logic_')) {
                    return $nlpRes['reply'];
                }

                // 2.3 Ensiklopedia Sains & Pengetahuan Terpelajari (Learned Q&A)
                if (str_starts_with($intent, 'knowledge_') || in_array($intent, ['session_learned_knowledge', 'pembelajaran_mandiri'], true)) {
                    return $nlpRes['reply'];
                }

                // 2.4 Intent Kedinasan Sekolah dengan Confidence Memadai
                if (!empty($nlpRes['confidence']) && $nlpRes['confidence'] >= 0.35 && $intent !== 'unknown') {
                    // Enrich jawaban dengan ground-truth real-time database sekolah
                    if ($intent === 'ppdb_sekolah' || preg_match('/\b(ppdb|daftar baru|pendaftaran|siswa baru|calon siswa|jalur zonasi)\b/i', $lower)) {
                        $ppdb = $context['ppdb'] ?? [];
                        $statusStr = (($ppdb['status'] ?? 'buka') === 'buka') ? '✅ **SEDANG DIBUKA**' : '⚠️ **SEDANG DITUTUP**';
                        $waveStr = $ppdb['wave'] ?? 'Gelombang 1';
                        $quotaStr = $ppdb['quota'] ?? '150';
                        $docsList = implode("\n• ", $ppdb['documents'] ?? ['Kartu Keluarga', 'Akta Kelahiran', 'Ijazah/SKL']);
                        $tracksList = implode(', ', $ppdb['tracks'] ?? ['Reguler', 'Zonasi Jarak', 'Prestasi', 'Afirmasi']);

                        return "📋 **Informasi Penerimaan Peserta Didik Baru (PPDB) $school:**\n\n"
                             . "• **Status Saat Ini:** $statusStr ($waveStr)\n"
                             . "• **Target Kuota:** $quotaStr Siswa\n"
                             . "• **Jalur Pendaftaran:** $tracksList\n\n"
                             . "📄 **Berkas Persyaratan yang Dibutuhkan:**\n• $docsList\n\n"
                             . "💡 **Alur Pendaftaran:**\n"
                             . "1. Buka menu **PPDB Online** pada website sekolah ini.\n"
                             . "2. Isi formulir identitas calon siswa & orang tua secara lengkap.\n"
                             . "3. Unggah scan berkas dokumen persyaratan.\n"
                             . "4. Unduh & cetak **Kartu Bukti Pendaftaran** dengan QR Code resmi.\n"
                             . "5. Pantau hasil verifikasi berkas dan pengumuman seleksi berkala di portal PPDB.";
                    }

                    if ($intent === 'spp_keuangan' || preg_match('/\b(spp|biaya|uang sekolah|tagihan|rekening|transfer)\b/i', $lower)) {
                        $paymentInfo = "";
                        if (!empty($context['payment_types'])) {
                            $paymentInfo = "\n\n📌 **Rincian Tarif Tagihan Resmi:**";
                            foreach ($context['payment_types'] as $p) {
                                $paymentInfo .= "\n• " . htmlspecialchars($p['name']) . ": **Rp " . number_format((float)$p['amount'], 0, ',', '.') . "**";
                            }
                        }
                        return "💳 **Informasi Pembayaran & SPP $school:**\n\n"
                             . "1. **Untuk Siswa/Wali Murid Terdaftar:** Rincian tagihan semester berjalan, status lunas, dan kuitansi resmi dapat diakses langsung pada menu **Keuangan** di Dashboard Portal masing-masing.\n"
                             . "2. **Metode Pembayaran:**\n"
                             . "   • Transfer Bank resmi sekolah atau Virtual Account (BCA, Mandiri, BNI, BRI).\n"
                             . "   • Loket Kasir Tata Usaha Sekolah pada jam operasional kerja.\n"
                             . "   • QRIS resmi sekolah.\n"
                             . "3. **Konfirmasi Pembayaran:** Bukti transfer dapat diunggah melalui dashboard akun atau dikonfirmasikan ke staf Tata Usaha.$paymentInfo";
                    }

                    if ($intent === 'jam_operasional_tu' || preg_match('/\b(jam kerja|jam operasional|jam berapa|buka jam|tutup jam)\b/i', $lower)) {
                        return "⏰ **Jam Kerja & Pelayanan Tata Usaha $school:**\n\n"
                             . "• **Senin s.d. Jumat:** 07.30 – 15.30 WIB\n"
                             . "• **Kegiatan Belajar Siswa:** 07.00 – 15.00 WIB\n"
                             . "• **Sabtu, Minggu & Hari Libur Nasional:** Kantor dan pelayanan tatap muka tutup.\n\n"
                             . "💬 Seluruh pertanyaan live chat di luar jam kerja akan tersimpan aman di sistem kami dan dijawab pada hari kerja berikutnya.";
                    }

                    if ($intent === 'kontak_alamat_sekolah' || preg_match('/\b(alamat|lokasi|dimana|di mana|telepon|kontak|kepala sekolah)\b/i', $lower)) {
                        return "📍 **Alamat & Kontak Resmi $school:**\n\n"
                             . "• **Alamat Kampus:** " . htmlspecialchars($context['school_address'] ?? '') . "\n"
                             . "• **Telepon Resmi:** " . htmlspecialchars($context['school_phone'] ?? '') . "\n"
                             . "• **Email Resmi:** " . htmlspecialchars($context['school_email'] ?? '') . "\n"
                             . "• **Website Utama:** " . htmlspecialchars($context['school_website'] ?? '') . "\n"
                             . "• **Kepala Sekolah:** " . htmlspecialchars($context['headmaster'] ?? '') . "\n\n"
                             . "Silakan berkunjung langsung pada jam kerja operasional atau hubungi nomor telepon kami untuk konfirmasi kunjungan.";
                    }

                    if ($intent === 'fasilitas_sekolah') {
                        $fasilitas = implode("\n• ", $context['facilities'] ?? []);
                        return "🏫 **Fasilitas Utama di $school:**\n\n• $fasilitas\n\nSeluruh sarana dan prasarana dirawat secara berkala guna mendukung kegiatan belajar mengajar yang optimal.";
                    }

                    if ($intent === 'ekstrakurikuler_sekolah') {
                        $ekskul = implode("\n• ", $context['extracurricular'] ?? []);
                        return "⚽ **Pilihan Ekstrakurikuler Siswa di $school:**\n\n• $ekskul\n\nPendaftaran ekstrakurikuler dapat dilakukan mandiri melalui dashboard portal siswa.";
                    }

                    if ($intent === 'perpustakaan_sekolah') {
                        return "📚 **Layanan Perpustakaan $school:**\n\n"
                             . "• Siswa dan guru aktif dapat mencari, meminjam, dan mereservasi buku secara online melalui portal menu **Perpustakaan**.\n"
                             . "• Jam Buka Ruang Baca: Setiap hari sekolah pukul 07.30 s.d. 15.30 WIB.\n"
                             . "• Dilengkapi dengan koleksi buku pelajaran Kurikulum Merdeka, fiksi, referensi ilmiah, dan E-Book digital.";
                    }

                    // Intent umum lainnya (salam, identitas, kabar, humor, terima_kasih, tips, dll.)
                    return $nlpRes['reply'];
                }
            }
        } catch (\Throwable $e_nlp) {
            error_log("Sentinel NLP Query Error: " . $e_nlp->getMessage());
        }
    }

    // 3. Fallback Berbasis Pola Rule Kontekstual Jika NLP Offline / Tidak Menemukan Kecocokan
    if (preg_match('/\b(halo|hai|hi|hello|selamat pagi|selamat siang|selamat sore|selamat malam|assalamu[\'\s]?alaikum|pagi|siang|sore|malam)\b/i', $lower)) {
        $roleGreeting = ($senderRole === 'Tamu') ? 'Bapak/Ibu/Adik Pengunjung' : $senderName;
        return "Halo, selamat datang **$roleGreeting**! 👋\n\nSaya adalah **AI Virtual Assistant** resmi dari **$school**. Saya siap membantu Anda memberikan informasi seputar:\n• 📋 **PPDB Online** (Jalur, Syarat, Alur Verifikasi)\n• 💳 **Biaya Pendidikan & Pembayaran SPP**\n• ⏰ **Jam Operasional & Pelayanan TU**\n• 📚 **Perpustakaan & Kalender Akademik**\n• 📍 **Alamat & Kontak Resmi Sekolah**\n\nAda hal spesifik yang ingin Anda tanyakan hari ini?";
    }

    if (preg_match('/\b(ppdb|daftar baru|pendaftaran|siswa baru|calon siswa|masuk sekolah|gelombang|syarat daftar|formulir)\b/i', $lower)) {
        $ppdb = $context['ppdb'] ?? [];
        $statusStr = (($ppdb['status'] ?? 'buka') === 'buka') ? '✅ **SEDANG DIBUKA**' : '⚠️ **SEDANG DITUTUP**';
        $waveStr = $ppdb['wave'] ?? 'Gelombang 1';
        $quotaStr = $ppdb['quota'] ?? '150';
        $docsList = implode("\n• ", $ppdb['documents'] ?? ['Kartu Keluarga', 'Akta Kelahiran', 'Ijazah/SKL']);
        $tracksList = implode(', ', $ppdb['tracks'] ?? ['Reguler', 'Zonasi Jarak', 'Prestasi', 'Afirmasi']);

        return "📋 **Informasi Penerimaan Peserta Didik Baru (PPDB) $school:**\n\n"
             . "• **Status Saat Ini:** $statusStr ($waveStr)\n"
             . "• **Target Kuota:** $quotaStr Siswa\n"
             . "• **Jalur Pendaftaran:** $tracksList\n\n"
             . "📄 **Berkas Persyaratan yang Dibutuhkan:**\n• $docsList\n\n"
             . "💡 **Alur Pendaftaran:**\n"
             . "1. Buka menu **PPDB Online** pada website sekolah ini.\n"
             . "2. Isi formulir identitas calon siswa & orang tua secara lengkap.\n"
             . "3. Unggah scan berkas dokumen persyaratan.\n"
             . "4. Unduh & cetak **Kartu Bukti Pendaftaran** dengan QR Code resmi.\n"
             . "5. Pantau hasil verifikasi berkas dan pengumuman seleksi berkala di portal PPDB.";
    }

    if (preg_match('/\b(spp|biaya|uang sekolah|bayar|tagihan|rekening|transfer|kasir|keuangan|daftar ulang|lunas)\b/i', $lower)) {
        $paymentInfo = "";
        if (!empty($context['payment_types'])) {
            $paymentInfo = "\n\n📌 **Rincian Tarif Tagihan Resmi:**";
            foreach ($context['payment_types'] as $p) {
                $paymentInfo .= "\n• " . htmlspecialchars($p['name']) . ": **Rp " . number_format((float)$p['amount'], 0, ',', '.') . "**";
            }
        }
        return "💳 **Informasi Pembayaran & SPP $school:**\n\n"
             . "1. **Untuk Siswa/Wali Murid Terdaftar:** Rincian tagihan semester berjalan, status lunas, dan kuitansi resmi dapat diakses langsung pada menu **Keuangan** di Dashboard Portal masing-masing.\n"
             . "2. **Metode Pembayaran:**\n"
             . "   • Transfer Bank resmi sekolah atau Virtual Account (BCA, Mandiri, BNI, BRI).\n"
             . "   • Loket Kasir Tata Usaha Sekolah pada jam operasional kerja.\n"
             . "   • QRIS resmi sekolah.\n"
             . "3. **Konfirmasi Pembayaran:** Bukti transfer dapat diunggah melalui dashboard akun atau dikonfirmasikan ke staf Tata Usaha.$paymentInfo";
    }

    if (preg_match('/\b(jam kerja|jam operasional|jam berapa|buka jam|tutup jam|hari kerja|hari libur|layanan tu)\b/i', $lower)) {
        return "⏰ **Jam Kerja & Pelayanan Tata Usaha $school:**\n\n"
             . "• **Senin s.d. Jumat:** 07.30 – 15.30 WIB\n"
             . "• **Kegiatan Belajar Siswa:** 07.00 – 15.00 WIB\n"
             . "• **Sabtu, Minggu & Hari Libur Nasional:** Kantor dan pelayanan tatap muka tutup.\n\n"
             . "💬 Seluruh pertanyaan live chat di luar jam kerja akan tersimpan aman di sistem kami dan dijawab pada hari kerja berikutnya.";
    }

    if (preg_match('/\b(alamat|lokasi|dimana|di mana|kantor|telepon|nomor telp|no telp|email|kontak|hubungi|maps|denah)\b/i', $lower)) {
        return "📍 **Alamat & Kontak Resmi $school:**\n\n"
             . "• **Alamat Kampus:** " . htmlspecialchars($context['school_address'] ?? '') . "\n"
             . "• **Telepon Resmi:** " . htmlspecialchars($context['school_phone'] ?? '') . "\n"
             . "• **Email Resmi:** " . htmlspecialchars($context['school_email'] ?? '') . "\n"
             . "• **Website Utama:** " . htmlspecialchars($context['school_website'] ?? '') . "\n"
             . "• **Kepala Sekolah:** " . htmlspecialchars($context['headmaster'] ?? '') . "\n\n"
             . "Silakan berkunjung langsung pada jam kerja operasional atau hubungi nomor telepon kami untuk konfirmasi kunjungan.";
    }

    if (preg_match('/\b(fasilitas|sarana|prasarana|gedung|ekskul|ekstrakurikuler|kegiatan siswa|olahraga|lab|laboratorium|masjid)\b/i', $lower)) {
        $fasilitas = implode("\n• ", $context['facilities'] ?? []);
        $ekskul = implode("\n• ", $context['extracurricular'] ?? []);
        return "🏫 **Fasilitas & Ekstrakurikuler di $school:**\n\n"
             . "🏢 **Sarana & Fasilitas Utama:**\n• $fasilitas\n\n"
             . "⚽ **Pilihan Ekstrakurikuler Siswa:**\n• $ekskul\n\n"
             . "Seluruh fasilitas dirawat secara berkala guna menjamin kenyamanan belajar dan perkembangan bakat civitas akademika.";
    }

    if (preg_match('/\b(perpus|perpustakaan|buku|pinjam buku|baca buku|e-book|ebook|katalog)\b/i', $lower)) {
        $bookList = "";
        if (!empty($context['library_sample'])) {
            $bookList = "\n\n📖 **Contoh Koleksi Tersedia:**";
            foreach ($context['library_sample'] as $b) {
                $bookList .= "\n• *" . htmlspecialchars($b['title']) . "* (" . htmlspecialchars($b['category']) . ")";
            }
        }
        return "📚 **Layanan Perpustakaan $school:**\n\n"
             . "• Siswa dan guru aktif dapat mencari, meminjam, dan mereservasi buku secara online melalui portal menu **Perpustakaan**.\n"
             . "• Jam Buka Ruang Baca: Setiap hari sekolah pukul 07.30 s.d. 15.30 WIB.\n"
             . "• Dilengkapi dengan koleksi buku pelajaran Kurikulum Merdeka, fiksi, referensi ilmiah, dan E-Book digital.$bookList";
    }

    if (preg_match('/\b(berita|kegiatan|pengumuman|prestasi|info terbaru|agenda|acara)\b/i', $lower)) {
        $newsText = "";
        if (!empty($context['recent_news'])) {
            $newsText .= "\n\n📰 **Berita & Artikel Terbaru:**";
            foreach ($context['recent_news'] as $n) {
                $newsText .= "\n• **" . htmlspecialchars($n['title']) . "** (" . $n['tgl'] . ")";
            }
        }
        $calText = "";
        if (!empty($context['events'])) {
            $calText .= "\n\n🗓️ **Agenda Kegiatan Mendatang:**";
            foreach ($context['events'] as $ev) {
                $calText .= "\n• **" . htmlspecialchars($ev['title']) . "** (Tgl: " . $ev['event_date'] . ")";
            }
        }
        return "📢 **Warta & Informasi Terkini $school:**" . $newsText . $calText . "\n\nSelengkapnya dapat disimak pada menu **Berita** & **Pengumuman** di portal web sekolah.";
    }

    if (preg_match('/\b(login|masuk akun|lupa sandi|lupa password|reset password|nisn|akun saya|tidak bisa masuk)\b/i', $lower)) {
        return "🔐 **Bantuan Akun & Login Portal Sekolah:**\n\n"
             . "1. **Siswa:** Gunakan username berupa NISN atau Email terdaftar saat registrasi.\n"
             . "2. **Guru & Staf:** Gunakan NIP/Email dinas yang telah didaftarkan administrator.\n"
             . "3. **Lupa Password:** Silakan gunakan menu 'Lupa Kata Sandi' pada form login atau hubungi bagian Tata Usaha sekolah.\n"
             . "4. Jika Anda pengunjung/calon siswa baru, Anda tidak perlu login untuk bertanya di live chat ini atau mendaftar PPDB.";
    }

    if (preg_match('/\b(terima kasih|makasih|thanks|thank you|matur nuwun|syukron|ok kak|oke kak|sip|baik terimakasih)\b/i', $lower)) {
        return "Sama-sama, Kak **" . htmlspecialchars($senderName) . "**! Senang bisa membantu Anda. 😊\n\nJika ada pertanyaan lain di masa mendatang seputar **$school**, jangan ragu untuk menyapa kami kembali. Semoga harimu menyenangkan dan sukses selalu!";
    }

    // 4. Fallback Cerdas
    return "Terima kasih atas pesan Anda, Kak **" . htmlspecialchars($senderName) . "**.\n\n"
         . "Sebagai **AI Virtual Assistant $school**, saya dapat membantu menjelaskan:\n"
         . "1. **PPDB Siswa Baru:** Ketik *\"Info PPDB\"* atau *\"Syarat pendaftaran\"*\n"
         . "2. **Keuangan & SPP:** Ketik *\"Biaya SPP\"* atau *\"Cara bayar uang sekolah\"*\n"
         . "3. **Waktu Pelayanan:** Ketik *\"Jam kerja TU\"*\n"
         . "4. **Kontak & Alamat:** Ketik *\"Lokasi sekolah\"*\n"
         . "5. **Bantuan Staf:** Ketik *\"Bicara dengan staf/admin\"* jika ingin disambungkan langsung dengan petugas kami.\n\n"
         . "Silakan sampaikan pertanyaan Anda secara lebih detail agar saya dapat memberikan jawaban yang tepat.";
}

/**
 * 4. Integrasi Model Kecerdasan Buatan Cloud LLM (Gemini / OpenAI / Ollama)
 */
function generateLLMAIResponse(string $userMessage, array $context, array $history, array $aiConfig): ?string {
    $provider = $aiConfig['provider'] ?? 'gemini';
    $apiKey   = trim($aiConfig['api_key'] ?? '');
    $model    = trim($aiConfig['model'] ?? '');
    $endpoint = trim($aiConfig['endpoint'] ?? '');
    $sysPrompt = trim($aiConfig['system_prompt'] ?? '');

    // Bangun Konteks Pengetahuan Sekolah sebagai System Instruction (RAG Ground Truth)
    $schoolContextStr = "DATA PENGETAHUAN RESMI SEKOLAH (GROUND TRUTH):\n"
                      . "- Nama Sekolah: " . $context['school_name'] . "\n"
                      . "- Alamat: " . $context['school_address'] . "\n"
                      . "- Telepon: " . $context['school_phone'] . "\n"
                      . "- Email: " . $context['school_email'] . "\n"
                      . "- Website: " . $context['school_website'] . "\n"
                      . "- Kepala Sekolah: " . $context['headmaster'] . "\n"
                      . "- Tahun Akademik: " . $context['academic_year'] . " Semester " . $context['semester'] . "\n"
                      . "- Status PPDB: " . $context['ppdb']['status'] . " (Gelombang: " . $context['ppdb']['wave'] . ", Kuota: " . $context['ppdb']['quota'] . ")\n"
                      . "- Jalur PPDB: " . implode(', ', $context['ppdb']['tracks']) . "\n"
                      . "- Berkas PPDB: " . implode(', ', $context['ppdb']['documents']) . "\n"
                      . "- Jam Pelayanan TU: " . $context['office_hours'] . "\n";

    if (!empty($context['payment_types'])) {
        $schoolContextStr .= "- Daftar Tarif Biaya Sekolah:\n";
        foreach ($context['payment_types'] as $pt) {
            $schoolContextStr .= "  * " . $pt['name'] . ": Rp " . number_format((float)$pt['amount'], 0, ',', '.') . "\n";
        }
    }

    $finalSystemPrompt = $sysPrompt . "\n\n"
                       . "ATURAN MENJAWAB:\n"
                       . "1. Jawablah dalam Bahasa Indonesia yang ramah, santun, terstruktur rapi dengan markdown (bullet points/bold).\n"
                       . "2. Prioritaskan dan rujuk selalu data resmi sekolah di atas. JANGAN MENGARANG fakta di luar data sekolah.\n"
                       . "3. Jika pengguna menanyakan hal yang membutuhkan tindakan staf (misal reset akun, urusan berkas fisik), arahkan untuk menghubungi staf TU atau datang langsung.\n\n"
                       . $schoolContextStr;

    // -------------------------------------------------------------------------
    // A. GOOGLE GEMINI API
    // -------------------------------------------------------------------------
    if ($provider === 'gemini') {
        if (empty($apiKey)) return null;
        if (empty($model)) $model = 'gemini-1.5-flash';

        $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

        // Siapkan conversation history untuk multi-turn chat
        $contents = [];
        // Masukkan riwayat pesan sebelumnya (maksimal 4 percakapan terakhir)
        foreach ($history as $h) {
            $role = ($h['sender_type'] === 'bot') ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $h['message']]]
            ];
        }
        // Masukkan pesan terkini
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]]
        ];

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $finalSystemPrompt]]
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.6,
                'maxOutputTokens' => 800,
            ]
        ];

        $ch = curl_init($geminiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 9);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if (!$err && $res) {
            $decoded = json_decode($res, true);
            if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
                return trim($decoded['candidates'][0]['content']['parts'][0]['text']);
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // B. OPENAI GPT ATAU OLLAMA / CUSTOM OPENAI-COMPATIBLE API
    // -------------------------------------------------------------------------
    if ($provider === 'openai' || $provider === 'custom_llm') {
        $openaiUrl = !empty($endpoint) ? $endpoint : 'https://api.openai.com/v1/chat/completions';
        if (empty($model)) $model = ($provider === 'custom_llm') ? 'llama3' : 'gpt-4o-mini';

        $messages = [
            ['role' => 'system', 'content' => $finalSystemPrompt]
        ];
        foreach ($history as $h) {
            $role = ($h['sender_type'] === 'bot') ? 'assistant' : 'user';
            $messages[] = [
                'role' => $role,
                'content' => $h['message']
            ];
        }
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage
        ];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.6,
            'max_tokens' => 800,
        ];

        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $ch = curl_init($openaiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 9);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if (!$err && $res) {
            $decoded = json_decode($res, true);
            if (isset($decoded['choices'][0]['message']['content'])) {
                return trim($decoded['choices'][0]['message']['content']);
            }
        }
        return null;
    }

    return null;
}

/**
 * 5. Orkestrator Utama Pemrosesan Respon AI untuk Sesi Live Chat
 */
function processAIChatResponse(PDO $pdo, int $sessionId, string $userMessage, string $senderType, ?int $senderId, string $senderName): array {
    $settings = function_exists('getSchoolSettings') ? getSchoolSettings($pdo) : [];

    $ai_enabled_global = ($settings['livechat_ai_enabled'] ?? '1') === '1';
    if (!$ai_enabled_global) {
        return ['replied' => false, 'reason' => 'AI disabled globally'];
    }

    // Ambil info sesi
    $stmt = $pdo->prepare("SELECT * FROM live_chat_sessions WHERE id = ? LIMIT 1");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session || $session['status'] === 'closed') {
        return ['replied' => false, 'reason' => 'Session inactive or closed'];
    }

    // Cek apakah AI diaktifkan untuk sesi ini
    if (isset($session['ai_enabled']) && (int)$session['ai_enabled'] === 0) {
        return ['replied' => false, 'reason' => 'AI disabled for this session'];
    }

    // Cek jika petugas admin baru saja merespons kurang dari 2 menit yang lalu,
    // biarkan percakapan manusia berlangsung tanpa interupsi bot, KECUALI pengguna menyapa kembali
    $stmt_recent_admin = $pdo->prepare("
        SELECT id FROM live_chat_messages 
        WHERE session_id = ? AND sender_type = 'admin' AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        LIMIT 1
    ");
    $stmt_recent_admin->execute([$sessionId]);
    if ($stmt_recent_admin->fetch()) {
        // Admin sedang aktif mengobrol, tahan auto AI
        return ['replied' => false, 'reason' => 'Human admin is actively chatting'];
    }

    // Cegah spam bot berulang dalam rentang 3 detik
    $stmt_cooldown = $pdo->prepare("
        SELECT id FROM live_chat_messages 
        WHERE session_id = ? AND sender_type = 'bot' AND created_at > DATE_SUB(NOW(), INTERVAL 3 SECOND)
        LIMIT 1
    ");
    $stmt_cooldown->execute([$sessionId]);
    if ($stmt_cooldown->fetch()) {
        return ['replied' => false, 'reason' => 'Cooldown active'];
    }

    // Ambil riwayat percakapan terkini untuk konteks multi-turn
    $stmt_hist = $pdo->prepare("
        SELECT sender_type, sender_name, message 
        FROM live_chat_messages 
        WHERE session_id = ? 
        ORDER BY id DESC LIMIT 6
    ");
    $stmt_hist->execute([$sessionId]);
    $history = array_reverse($stmt_hist->fetchAll(PDO::FETCH_ASSOC));

    // Ambil konteks pengetahuan sekolah
    $context = getSchoolKnowledgeContext($pdo);
    $senderRole = $session['role_label'] ?? 'Tamu';

    // Cek apakah ada eskalasi bantuan manusia
    $needsHuman = detectHandoffIntent($userMessage);
    if ($needsHuman) {
        try {
            $pdo->prepare("UPDATE live_chat_sessions SET needs_human = 1 WHERE id = ?")->execute([$sessionId]);

            // Kirim notifikasi darurat ke WhatsApp petugas/sekolah jika modul WA tersedia
            if (function_exists('sendWhatsAppNotification') && !empty($context['school_phone'])) {
                $throttleKey = 'wa_chat_notif_' . $sessionId;
                if (!isset($_SESSION[$throttleKey]) || (time() - (int)$_SESSION[$throttleKey]) > 600) {
                    $_SESSION[$throttleKey] = time();
                    $waAlertMsg = "🚨 *NOTIFIKASI LIVE CHAT MASUK*\n"
                                . "Pengunjung: *" . htmlspecialchars($senderName) . "* (" . $senderRole . ")\n"
                                . "Departemen: *" . ($session['department'] ?? 'Layanan Umum') . "*\n"
                                . "Pesan: \"" . mb_substr($userMessage, 0, 150) . "\"\n\n"
                                . "⚠️ *Pengunjung meminta bantuan staf/admin manusia.* Mohon segera buka menu Live Chat di Dashboard Admin untuk merespons.";
                    @sendWhatsAppNotification($pdo, $context['school_phone'], $waAlertMsg, 'sistem');
                }
            }
        } catch (Exception $e) {}
    }

    $provider = $settings['livechat_ai_provider'] ?? 'hybrid';
    $botName  = $settings['livechat_ai_bot_name'] ?? 'AI Asisten Sekolah';
    $aiReply  = null;
    $engineUsed = 'local';

    // Konfigurasi AI Eksternal
    $aiConfig = [
        'provider'      => ($provider === 'hybrid' || $provider === 'gemini') ? 'gemini' : $provider,
        'api_key'       => $settings['livechat_ai_api_key'] ?? '',
        'model'         => $settings['livechat_ai_model'] ?? 'gemini-1.5-flash',
        'endpoint'      => $settings['livechat_ai_endpoint'] ?? '',
        'system_prompt' => $settings['livechat_ai_system_prompt'] ?? ''
    ];

    // Jika api_key kosong, periksa apakah tersimpan di tabel external_apis kategori 'ai'
    if (empty($aiConfig['api_key'])) {
        try {
            $stmt_ext = $pdo->query("SELECT * FROM external_apis WHERE category = 'ai' AND is_active = 1 LIMIT 1");
            $extRow = $stmt_ext->fetch(PDO::FETCH_ASSOC);
            if ($extRow) {
                $aiConfig['api_key'] = $extRow['api_key'] ?? '';
                if (!empty($extRow['endpoint_url']) && empty($aiConfig['endpoint'])) {
                    $aiConfig['endpoint'] = $extRow['endpoint_url'];
                }
            }
        } catch (Exception $e) {}
    }

    // Strategi Eksekusi Sesuai Mode Provider
    if ($provider !== 'local' && !empty($aiConfig['api_key'])) {
        $aiReply = generateLLMAIResponse($userMessage, $context, $history, $aiConfig);
        if ($aiReply !== null) {
            $engineUsed = 'cloud_llm';
        }
    }

    // Fallback otomatis ke Kecerdasan Buatan Mandiri Lokal (Sentinel NLP) jika LLM tidak aktif / gagal
    $nlpDebug = null;
    if ($aiReply === null) {
        $aiReply = generateLocalAIResponse($userMessage, $context, $senderName, $senderRole, $history, $nlpDebug);
        $engineUsed = ($nlpDebug && !empty($nlpDebug['intent']) && $nlpDebug['intent'] !== 'unknown') ? 'sentinel_nlp' : 'local_brain';
    }

    // Simpan balasan AI ke database
    $stmt_ins = $pdo->prepare("
        INSERT INTO live_chat_messages (session_id, sender_type, sender_id, sender_name, message, is_read)
        VALUES (?, 'bot', NULL, ?, ?, 0)
    ");
    $stmt_ins->execute([$sessionId, $botName, $aiReply]);
    $newMsgId = (int)$pdo->lastInsertId();

    // Perbarui waktu update sesi
    $pdo->prepare("UPDATE live_chat_sessions SET updated_at = NOW() WHERE id = ?")->execute([$sessionId]);

    return [
        'replied'     => true,
        'message_id'  => $newMsgId,
        'message'     => $aiReply,
        'bot_name'    => $botName,
        'engine_used' => $engineUsed,
        'needs_human' => $needsHuman,
        'nlp_meta'    => $nlpDebug
    ];
}

