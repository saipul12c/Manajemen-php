<?php
session_start();
require_once __DIR__ . "/../../config/database.php";

requireRole(['administrator']);

$message = "";
$message_type = "";

// -------------------------------------------------------------
// AJAX ENDPOINTS (AUTO-SAVE, TEST KONEKSI, TOGGLE STATUS)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_REQUEST['ajax']) && $_REQUEST['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');

    // Validasi CSRF Token
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        echo json_encode([
            'success' => false,
            'message' => 'Sesi keamanan / CSRF token telah berakhir. Silakan muat ulang halaman.'
        ]);
        exit;
    }

    $action = $_POST['action'] ?? '';

    // 1. Auto-Save Single Field secara Instan
    if ($action === 'auto_save') {
        $id    = (int)($_POST['id'] ?? 0);
        $field = trim($_POST['field'] ?? '');
        $value = $_POST['value'] ?? '';

        if ($id <= 0 || empty($field)) {
            echo json_encode(['success' => false, 'message' => 'Parameter ID atau nama field tidak valid.']);
            exit;
        }

        $success = updateExternalApiField($pdo, $id, $field, $value);
        if ($success) {
            logActivity($pdo, 'AUTO_SAVE_API', "Auto-save field '$field' pada API ID #$id");
            echo json_encode([
                'success' => true,
                'message' => 'Tersimpan otomatis ke database',
                'timestamp' => date('H:i:s'),
                'id' => $id,
                'field' => $field
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui database.']);
        }
        exit;
    }

    // 2. Toggle Status Aktif / Nonaktif
    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $status = ((int)($_POST['is_active'] ?? 0) === 1) ? 1 : 0;

        $success = updateExternalApiField($pdo, $id, 'is_active', $status);
        if ($success) {
            logActivity($pdo, 'TOGGLE_API_STATUS', "Mengubah status API ID #$id menjadi: " . ($status ? 'AKTIF' : 'NONAKTIF'));
            echo json_encode([
                'success' => true,
                'message' => 'Status API berhasil diperbarui',
                'is_active' => $status,
                'timestamp' => date('H:i:s')
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Gagal mengubah status API.']);
        }
        exit;
    }

    // 3. Uji Koneksi / Ping API Real-time
    if ($action === 'test_connection') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID API tidak valid.']);
            exit;
        }

        $result = testExternalApiConnection($pdo, $id);
        logActivity($pdo, 'TEST_EXTERNAL_API', "Uji koneksi API ID #$id: HTTP {$result['status_code']}");
        echo json_encode([
            'success' => true,
            'result' => $result
        ]);
        exit;
    }

    // 4. Hapus API Luar
    if ($action === 'delete_api') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID API tidak valid.']);
            exit;
        }

        $deleted = deleteExternalApi($pdo, $id);
        if ($deleted) {
            logActivity($pdo, 'DELETE_EXTERNAL_API', "Menghapus API luar ID #$id");
            echo json_encode(['success' => true, 'message' => 'Integrasi API berhasil dihapus dari database.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'API sistem inti tidak dapat dihapus. Anda hanya dapat menonaktifkannya.']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi AJAX tidak dikenali.']);
    exit;
}

// -------------------------------------------------------------
// POST HANDLER (FORM LENGKAP SIMPAN / BUAT BARU)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $message = "Token keamanan CSRF tidak valid. Silakan coba kembali.";
        $message_type = "error";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_api_full') {
            $api_id = saveExternalApi($pdo, $_POST);
            if ($api_id > 0) {
                logActivity($pdo, 'SAVE_EXTERNAL_API', "Menyimpan konfigurasi lengkap API luar: " . ($_POST['name'] ?? ''));
                $message = "Konfigurasi API '" . htmlspecialchars($_POST['name'] ?? '') . "' berhasil disimpan ke database!";
                $message_type = "success";
            } else {
                $message = "Terjadi kegagalan saat menyimpan API.";
                $message_type = "error";
            }
        } elseif ($action === 'reset_core_apis') {
            // Re-seed default core apis jika diperlukan
            $pdo->exec("
                INSERT IGNORE INTO `external_apis` 
                (`api_code`, `name`, `category`, `provider`, `endpoint_url`, `api_key`, `api_secret`, `auth_type`, `auth_header_name`, `http_method`, `custom_headers`, `payload_template`, `is_active`, `environment`, `timeout_seconds`, `description`)
                VALUES
                ('wa_gateway', 'WhatsApp Notification Gateway', 'whatsapp', 'Fonnte (Official/Demo)', 'https://api.fonnte.com/send', 'fonnte_demo_token_2026', '', 'header', 'Authorization', 'POST', '{\"Accept\":\"application/json\"}', '{\n  \"target\": \"{target_phone}\",\n  \"message\": \"{message_text}\"\n}', 1, 'production', 10, 'Layanan API pengiriman pesan WhatsApp otomatis untuk notifikasi presensi, tagihan SPP, dan pengumuman sekolah.'),
                ('payment_gateway', 'Payment Gateway & Virtual Account (Midtrans)', 'payment', 'Midtrans Snap & Core API', 'https://app.sandbox.midtrans.com/snap/v1/transactions', 'SB-Mid-server-demo-token-2026', 'SB-Mid-client-demo-token-2026', 'basic', 'Authorization', 'POST', '{\"Accept\":\"application/json\",\"Content-Type\":\"application/json\"}', '{\n  \"transaction_details\": {\n    \"order_id\": \"BILL-EXAMPLE-001\",\n    \"gross_amount\": 350000\n  }\n}', 1, 'sandbox', 15, 'Integrasi gateway pembayaran online instan untuk tagihan SPP siswa (QRIS, VA BCA, Mandiri, BNI, BRI).'),
                ('ai_service', 'AI Assistant & Auto-Grading (Gemini / OpenAI)', 'ai', 'Google Gemini AI', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent', 'AIzaSyDemoKeyExample2026', '', 'query', 'key', 'POST', '{\"Content-Type\":\"application/json\"}', '{\n  \"contents\": [\n    {\n      \"parts\": [\n        {\"text\": \"Analisis dan beri penilaian ringkas untuk jawaban esai ini...\"}\n      ]\n    }\n  ]\n}', 1, 'production', 20, 'Kecerdasan Buatan (LLM) untuk asisten penyusunan materi KBM, bank soal otomatis, dan evaluasi esai siswa CBT.'),
                ('email_service', 'Layanan Email Transaksional & SMTP API', 'mail', 'SendGrid / Resend API', 'https://api.sendgrid.com/v3/mail/send', 'SG.demo_sendgrid_token_example', '', 'bearer', 'Authorization', 'POST', '{\"Content-Type\":\"application/json\"}', '{\n  \"personalizations\": [\n    {\"to\": [{\"email\": \"{recipient_email}\"}]}\n  ],\n  \"from\": {\"email\": \"noreply@binabangsa.sch.id\"},\n  \"subject\": \"{subject}\",\n  \"content\": [{\"type\": \"text/plain\", \"value\": \"{body}\"}]\n}', 1, 'production', 10, 'Pengiriman surat elektronik otomatis untuk kuitansi pembayaran SPP, kartu ujian siswa, dan verifikasi PPDB.'),
                ('maps_geocoding', 'Peta Lokasi & Geocoding Geofencing', 'maps', 'Nominatim OpenStreetMap', 'https://nominatim.openstreetmap.org/search', '', '', 'none', 'Authorization', 'GET', '{\"User-Agent\":\"ManajemenPHP-SchoolSystem/1.0\"}', '{}', 1, 'production', 10, 'Verifikasi koordinat GPS latitude/longitude sekolah dan validasi radius geofencing presensi mandiri guru.'),
                ('weather_api', 'Prakiraan Cuaca Terbuka (BMKG / Open-Meteo)', 'weather', 'Open-Meteo Weather API', 'https://api.open-meteo.com/v1/forecast?latitude=-6.2088&longitude=106.8456&current_weather=true', '', '', 'none', '', 'GET', '{\"Accept\":\"application/json\"}', '{}', 1, 'production', 8, 'Data cuaca dan prakiraan suhu lingkungan sekolah secara langsung untuk monitoring kegiatan upacara dan olahraga luar ruangan.');
            ");
            $message = "Daftar preset integrasi API bawaan sistem berhasil dipulihkan!";
            $message_type = "success";
        }
    }
}

// -------------------------------------------------------------
// AMBIL DATA API LUAR DARI DATABASE
// -------------------------------------------------------------
$apis = getExternalApis($pdo);

// Statistik
$total_apis   = count($apis);
$active_apis  = count(array_filter($apis, fn($a) => (int)$a['is_active'] === 1));
$tested_ok    = count(array_filter($apis, fn($a) => (int)($a['last_status_code'] ?? 0) >= 200 && (int)($a['last_status_code'] ?? 0) < 400));
$sandbox_apis = count(array_filter($apis, fn($a) => ($a['environment'] ?? '') === 'sandbox'));

// Helper Icon & Warna Kategori
function getCategoryMeta(string $cat): array {
    switch ($cat) {
        case 'whatsapp':
            return ['icon' => 'fa-brands fa-whatsapp', 'color' => 'emerald', 'bg' => 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20', 'label' => 'WhatsApp Gateway'];
        case 'payment':
            return ['icon' => 'fa-solid fa-credit-card', 'color' => 'blue', 'bg' => 'bg-blue-500/10 text-blue-400 border-blue-500/20', 'label' => 'Payment Gateway'];
        case 'ai':
            return ['icon' => 'fa-solid fa-brain', 'color' => 'purple', 'bg' => 'bg-purple-500/10 text-purple-400 border-purple-500/20', 'label' => 'AI & LLM'];
        case 'mail':
            return ['icon' => 'fa-solid fa-envelope', 'color' => 'amber', 'bg' => 'bg-amber-500/10 text-amber-400 border-amber-500/20', 'label' => 'Email & SMTP'];
        case 'maps':
            return ['icon' => 'fa-solid fa-map-location-dot', 'color' => 'rose', 'bg' => 'bg-rose-500/10 text-rose-400 border-rose-500/20', 'label' => 'Maps & Geocoding'];
        case 'weather':
            return ['icon' => 'fa-solid fa-cloud-sun', 'color' => 'cyan', 'bg' => 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20', 'label' => 'Cuaca & BMKG'];
        default:
            return ['icon' => 'fa-solid fa-plug', 'color' => 'indigo', 'bg' => 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20', 'label' => 'Kustom REST API'];
    }
}

$page_title = "Pengaturan API Luar & Integrasi";
require_once __DIR__ . "/../includes/header.php";
?>

<div class="space-y-8">
    <!-- Top Header & Breadcrumb -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-slate-400 mb-1">
                <a href="<?= $dash_url ?>index.php" class="hover:text-white transition">Dashboard</a>
                <span>/</span>
                <a href="<?= $dash_url ?>admin/settings.php" class="hover:text-white transition">Pengaturan</a>
                <span>/</span>
                <span class="text-blue-400 font-medium">API Luar & Integrasi</span>
            </div>
            <div class="flex items-center gap-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-tr from-cyan-600 via-blue-600 to-indigo-600 text-white shadow-lg shadow-blue-500/20">
                    <i class="fa-solid fa-cloud-arrow-up text-xl"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-black text-white tracking-tight flex items-center gap-3">
                        Pengaturan Integrasi API Luar
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-0.5 text-xs font-semibold text-emerald-400 border border-emerald-500/20">
                            <span class="h-2 w-2 rounded-full bg-emerald-400 animate-pulse"></span> Auto-Save DB Aktif
                        </span>
                    </h1>
                    <p class="text-sm text-slate-400">Atur kredensial, endpoint eksternal, dan uji respon API secara langsung dengan penyimpanan otomatis ke database.</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2.5 flex-wrap">
            <button type="button" onclick="openAddModal()" 
                    class="rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white px-4 py-2.5 text-xs font-bold transition shadow-lg shadow-blue-600/30 flex items-center gap-2 cursor-pointer">
                <i class="fa-solid fa-plus"></i> Tambah API Luar
            </button>
            <button type="button" onclick="testAllApis()" id="btnTestAll"
                    class="rounded-xl border border-white/10 bg-slate-800/80 hover:bg-slate-700/80 text-slate-200 px-4 py-2.5 text-xs font-semibold transition flex items-center gap-2 cursor-pointer">
                <i class="fa-solid fa-satellite-dish text-cyan-400"></i> Uji Semua Koneksi
            </button>
            <a href="wa_gateway.php" class="rounded-xl border border-emerald-500/20 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-300 px-3.5 py-2.5 text-xs font-semibold transition flex items-center gap-1.5">
                <i class="fa-brands fa-whatsapp text-sm"></i> Panel Log WA
            </a>
        </div>
    </div>

    <!-- Alert Notifikasi Flash -->
    <?php if ($message): ?>
        <div class="rounded-2xl border p-4 text-sm flex items-center justify-between <?= $message_type === 'success' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300' : 'border-rose-500/30 bg-rose-500/10 text-rose-300' ?>">
            <div class="flex items-center gap-3">
                <i class="fa-solid <?= $message_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-triangle-exclamation text-rose-400' ?> text-lg"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-xs font-semibold opacity-70 hover:opacity-100 cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- Summary Metrics Cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-400">Total API Terdaftar</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400">
                    <i class="fa-solid fa-network-wired text-sm"></i>
                </span>
            </div>
            <p class="mt-2 text-2xl font-bold text-white"><?= $total_apis ?></p>
            <p class="text-[11px] text-slate-400 mt-0.5">Integrasi sistem</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-400">API Aktif</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-400">
                    <i class="fa-solid fa-toggle-on text-sm"></i>
                </span>
            </div>
            <p class="mt-2 text-2xl font-bold text-emerald-400"><?= $active_apis ?></p>
            <p class="text-[11px] text-slate-400 mt-0.5">Siap digunakan aplikasi</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-400">Koneksi Terverifikasi</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-cyan-500/10 text-cyan-400">
                    <i class="fa-solid fa-check-double text-sm"></i>
                </span>
            </div>
            <p class="mt-2 text-2xl font-bold text-cyan-400"><?= $tested_ok ?></p>
            <p class="text-[11px] text-slate-400 mt-0.5">Status HTTP OK (2xx/3xx)</p>
        </div>

        <div class="rounded-2xl border border-white/10 bg-slate-900/60 p-4 backdrop-blur shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-slate-400">Mode Sandbox</span>
                <span class="flex h-8 w-8 items-center justify-center rounded-xl bg-amber-500/10 text-amber-400">
                    <i class="fa-solid fa-flask-vial text-sm"></i>
                </span>
            </div>
            <p class="mt-2 text-2xl font-bold text-amber-400"><?= $sandbox_apis ?></p>
            <p class="text-[11px] text-slate-400 mt-0.5">Uji coba / testing</p>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-slate-900/40 p-3 rounded-2xl border border-white/10">
        <!-- Search Input -->
        <div class="relative flex-1 max-w-md">
            <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
            <input type="text" id="searchInput" oninput="filterCards()" placeholder="Cari nama API, provider, atau URL endpoint..."
                   class="w-full rounded-xl border border-white/10 bg-slate-950/80 pl-9 pr-4 py-2 text-xs text-white placeholder-slate-500 focus:border-blue-500 focus:outline-none transition">
        </div>

        <!-- Category Pills -->
        <div class="flex items-center gap-1.5 overflow-x-auto pb-1 sm:pb-0 custom-scrollbar text-xs">
            <button type="button" onclick="setCategoryFilter('semua', this)" class="category-btn active px-3 py-1.5 rounded-xl font-semibold transition border border-blue-500/30 bg-blue-600/20 text-blue-300">
                Semua (<?= $total_apis ?>)
            </button>
            <button type="button" onclick="setCategoryFilter('whatsapp', this)" class="category-btn px-3 py-1.5 rounded-xl font-semibold transition border border-white/5 bg-slate-800/50 text-slate-400 hover:text-white">
                <i class="fa-brands fa-whatsapp text-emerald-400 mr-1"></i> WhatsApp
            </button>
            <button type="button" onclick="setCategoryFilter('payment', this)" class="category-btn px-3 py-1.5 rounded-xl font-semibold transition border border-white/5 bg-slate-800/50 text-slate-400 hover:text-white">
                <i class="fa-solid fa-credit-card text-blue-400 mr-1"></i> Payment
            </button>
            <button type="button" onclick="setCategoryFilter('ai', this)" class="category-btn px-3 py-1.5 rounded-xl font-semibold transition border border-white/5 bg-slate-800/50 text-slate-400 hover:text-white">
                <i class="fa-solid fa-brain text-purple-400 mr-1"></i> AI & LLM
            </button>
            <button type="button" onclick="setCategoryFilter('mail', this)" class="category-btn px-3 py-1.5 rounded-xl font-semibold transition border border-white/5 bg-slate-800/50 text-slate-400 hover:text-white">
                <i class="fa-solid fa-envelope text-amber-400 mr-1"></i> Email
            </button>
            <button type="button" onclick="setCategoryFilter('maps', this)" class="category-btn px-3 py-1.5 rounded-xl font-semibold transition border border-white/5 bg-slate-800/50 text-slate-400 hover:text-white">
                <i class="fa-solid fa-map-location-dot text-rose-400 mr-1"></i> Maps
            </button>
            <button type="button" onclick="setCategoryFilter('weather', this)" class="category-btn px-3 py-1.5 rounded-xl font-semibold transition border border-white/5 bg-slate-800/50 text-slate-400 hover:text-white">
                <i class="fa-solid fa-cloud-sun text-cyan-400 mr-1"></i> Cuaca
            </button>
            <button type="button" onclick="setCategoryFilter('custom', this)" class="category-btn px-3 py-1.5 rounded-xl font-semibold transition border border-white/5 bg-slate-800/50 text-slate-400 hover:text-white">
                <i class="fa-solid fa-plug text-indigo-400 mr-1"></i> Kustom
            </button>
        </div>
    </div>

    <!-- Live Grid Cards: Dedicated API Cards -->
    <div id="apiCardsGrid" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <?php foreach ($apis as $api): 
            $catMeta = getCategoryMeta($api['category']);
            $is_active = ((int)$api['is_active'] === 1);
            $is_core = in_array($api['api_code'], ['wa_gateway', 'payment_gateway'], true);
        ?>
        <div class="api-card rounded-3xl border border-white/10 bg-slate-900/70 p-6 backdrop-blur shadow-xl transition hover:border-white/20 flex flex-col justify-between"
             data-category="<?= htmlspecialchars($api['category']) ?>"
             data-name="<?= htmlspecialchars(strtolower($api['name'] . ' ' . $api['provider'] . ' ' . $api['endpoint_url'])) ?>"
             id="card-api-<?= $api['id'] ?>">

            <div>
                <!-- Card Header -->
                <div class="flex items-start justify-between gap-4 pb-4 border-b border-white/10">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border <?= $catMeta['bg'] ?> text-lg shadow-inner">
                            <i class="<?= $catMeta['icon'] ?>"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="font-bold text-white text-base tracking-tight"><?= htmlspecialchars($api['name']) ?></h3>
                                <span class="rounded-md border border-white/10 bg-white/5 px-2 py-0.5 text-[10px] font-mono text-slate-300">
                                    <?= htmlspecialchars($api['api_code']) ?>
                                </span>
                            </div>
                            <p class="text-xs text-slate-400 mt-0.5 flex items-center gap-2">
                                <span class="inline-flex items-center gap-1 font-medium text-slate-300">
                                    <i class="fa-solid fa-server text-[10px] text-blue-400"></i> <?= htmlspecialchars($api['provider']) ?>
                                </span>
                                <span>•</span>
                                <span class="text-[11px] <?= ($api['environment'] ?? '') === 'production' ? 'text-emerald-400' : 'text-amber-400' ?>">
                                    ● <?= ucfirst($api['environment'] ?? 'production') ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <!-- Instant Toggle Switch -->
                    <div class="flex flex-col items-end gap-1 shrink-0">
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" class="sr-only peer" 
                                   <?= $is_active ? 'checked' : '' ?> 
                                   onchange="toggleApiStatus(<?= $api['id'] ?>, this)">
                            <div class="w-11 h-6 bg-slate-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                        </label>
                        <span class="text-[10px] font-semibold status-label <?= $is_active ? 'text-emerald-400' : 'text-slate-500' ?>" id="status-label-<?= $api['id'] ?>">
                            <?= $is_active ? 'Aktif' : 'Nonaktif' ?>
                        </span>
                    </div>
                </div>

                <!-- Auto-Save Status Bar Realtime -->
                <div class="flex items-center justify-between text-[11px] pt-3 pb-2 text-slate-400" id="save-indicator-<?= $api['id'] ?>">
                    <span class="flex items-center gap-1.5 text-slate-500 font-mono text-[10px]">
                        <i class="fa-solid fa-clock text-[9px]"></i> Pembaruan: <span id="last-update-<?= $api['id'] ?>"><?= date('H:i:s', strtotime($api['updated_at'] ?? 'now')) ?></span>
                    </span>
                    <span class="save-status-msg text-emerald-400/90 font-medium flex items-center gap-1 text-[11px]">
                        <i class="fa-solid fa-check text-[10px]"></i> Siap / Terhubung DB
                    </span>
                </div>

                <!-- Main Form Inputs with Instant Auto-Save (onchange & oninput debounce) -->
                <div class="space-y-3.5 mt-2">
                    <!-- Endpoint URL -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="text-xs font-semibold text-slate-300 flex items-center gap-1.5">
                                Endpoint URL API Luar <span class="text-rose-400">*</span>
                            </label>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-300 font-bold border border-white/10">
                                    <?= htmlspecialchars($api['http_method'] ?? 'POST') ?>
                                </span>
                                <button type="button" onclick="copyToClipboard('<?= htmlspecialchars(addslashes($api['endpoint_url'])) ?>')" 
                                        class="text-[11px] text-slate-400 hover:text-white transition" title="Salin URL">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="flex rounded-xl border border-white/10 bg-slate-950 focus-within:border-blue-500 transition overflow-hidden">
                            <select class="bg-slate-900 border-r border-white/10 px-2.5 py-2 text-xs font-bold text-blue-400 focus:outline-none"
                                    data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="http_method">
                                <option value="POST" <?= ($api['http_method'] ?? 'POST') === 'POST' ? 'selected' : '' ?>>POST</option>
                                <option value="GET" <?= ($api['http_method'] ?? '') === 'GET' ? 'selected' : '' ?>>GET</option>
                                <option value="PUT" <?= ($api['http_method'] ?? '') === 'PUT' ? 'selected' : '' ?>>PUT</option>
                                <option value="DELETE" <?= ($api['http_method'] ?? '') === 'DELETE' ? 'selected' : '' ?>>DELETE</option>
                            </select>
                            <input type="url" required value="<?= htmlspecialchars($api['endpoint_url']) ?>" 
                                   placeholder="https://api.provider.com/v1/endpoint"
                                   data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="endpoint_url"
                                   class="w-full bg-transparent px-3 py-2 text-xs text-white placeholder-slate-600 focus:outline-none font-mono">
                        </div>
                    </div>

                    <!-- API Key / Token & Provider -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Provider / Layanan</label>
                            <input type="text" value="<?= htmlspecialchars($api['provider']) ?>" 
                                   data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="provider"
                                   placeholder="Contoh: Fonnte, Midtrans, Gemini"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="text-xs font-semibold text-slate-300">API Key / Token Rahasia</label>
                                <button type="button" onclick="toggleSecretVisibility('api-key-<?= $api['id'] ?>', this)" 
                                        class="text-[10px] text-slate-400 hover:text-white transition">
                                    <i class="fa-regular fa-eye"></i> Tampil
                                </button>
                            </div>
                            <div class="relative">
                                <input type="password" id="api-key-<?= $api['id'] ?>" value="<?= htmlspecialchars($api['api_key'] ?? '') ?>" 
                                       data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="api_key"
                                       placeholder="Token / Bearer / Server Key..."
                                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white font-mono focus:border-blue-500 focus:outline-none">
                            </div>
                        </div>
                    </div>

                    <!-- Autentikasi & Environment -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Tipe Autentikasi</label>
                            <select data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="auth_type"
                                    class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                                <option value="bearer" <?= ($api['auth_type'] ?? '') === 'bearer' ? 'selected' : '' ?>>Bearer Token</option>
                                <option value="header" <?= ($api['auth_type'] ?? '') === 'header' ? 'selected' : '' ?>>Custom Header (Auth)</option>
                                <option value="basic" <?= ($api['auth_type'] ?? '') === 'basic' ? 'selected' : '' ?>>Basic Auth</option>
                                <option value="query" <?= ($api['auth_type'] ?? '') === 'query' ? 'selected' : '' ?>>URL Query Param (?key=)</option>
                                <option value="none" <?= ($api['auth_type'] ?? '') === 'none' ? 'selected' : '' ?>>Tanpa Auth / Publik</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Lingkungan (Env)</label>
                            <select data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="environment"
                                    class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none font-semibold">
                                <option value="production" <?= ($api['environment'] ?? '') === 'production' ? 'selected' : '' ?>>● Production (Live)</option>
                                <option value="sandbox" <?= ($api['environment'] ?? '') === 'sandbox' ? 'selected' : '' ?>>▲ Sandbox / Testing</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-300 mb-1">Timeout (Detik)</label>
                            <input type="number" min="3" max="60" value="<?= (int)($api['timeout_seconds'] ?? 10) ?>" 
                                   data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="timeout_seconds"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none text-center font-mono">
                        </div>
                    </div>

                    <!-- Client Secret & Auth Header Name (Jika relevan) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1">
                        <div>
                            <label class="block text-[11px] font-medium text-slate-400 mb-1">Nama Header Autentikasi</label>
                            <input type="text" value="<?= htmlspecialchars($api['auth_header_name'] ?? 'Authorization') ?>" 
                                   data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="auth_header_name"
                                   placeholder="Authorization / x-api-key / apikey"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white font-mono focus:border-blue-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-slate-400 mb-1">Client Secret / Merchant ID (Opsional)</label>
                            <input type="password" value="<?= htmlspecialchars($api['api_secret'] ?? '') ?>" 
                                   data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="api_secret"
                                   placeholder="Diperlukan untuk Midtrans / Xendit dll"
                                   class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-white font-mono focus:border-blue-500 focus:outline-none">
                        </div>
                    </div>

                    <!-- Keterangan / Deskripsi -->
                    <div>
                        <label class="block text-[11px] font-medium text-slate-400 mb-1">Deskripsi & Catatan Penggunaan</label>
                        <input type="text" value="<?= htmlspecialchars($api['description'] ?? '') ?>" 
                               data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="description"
                               placeholder="Contoh: Notifikasi tagihan SPP bulanan ke orang tua"
                               class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-1.5 text-xs text-slate-300 focus:border-blue-500 focus:outline-none">
                    </div>

                    <!-- Collapsible Advanced: Payload & Headers -->
                    <div class="pt-1">
                        <button type="button" onclick="toggleAdvanced(<?= $api['id'] ?>)" 
                                class="text-[11px] text-blue-400 hover:text-blue-300 font-semibold flex items-center gap-1.5 cursor-pointer">
                            <i class="fa-solid fa-sliders text-xs"></i> 
                            <span id="adv-btn-text-<?= $api['id'] ?>">Tampilkan Header Kustom & Format JSON</span>
                            <i class="fa-solid fa-chevron-down text-[10px] transition-transform" id="adv-arrow-<?= $api['id'] ?>"></i>
                        </button>

                        <div id="adv-panel-<?= $api['id'] ?>" class="hidden mt-3 space-y-3 pt-3 border-t border-white/5">
                            <div>
                                <label class="block text-[11px] font-semibold text-slate-300 mb-1">Header Kustom Tambahan (JSON)</label>
                                <textarea rows="2" data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="custom_headers"
                                          placeholder='{"Accept": "application/json", "x-signature": "xxx"}'
                                          class="w-full rounded-xl border border-white/10 bg-slate-950 p-2.5 text-[11px] text-emerald-400 font-mono focus:border-blue-500 focus:outline-none"><?= htmlspecialchars($api['custom_headers'] ?? '') ?></textarea>
                            </div>

                            <div>
                                <label class="block text-[11px] font-semibold text-slate-300 mb-1">Format Template Payload (JSON Body)</label>
                                <textarea rows="3" data-api-autosave="true" data-id="<?= $api['id'] ?>" data-field="payload_template"
                                          placeholder='{"target": "{phone}", "message": "{message}"}'
                                          class="w-full rounded-xl border border-white/10 bg-slate-950 p-2.5 text-[11px] text-cyan-300 font-mono focus:border-blue-500 focus:outline-none"><?= htmlspecialchars($api['payload_template'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card Action Footer & Live Ping Status -->
            <div class="mt-6 pt-4 border-t border-white/10 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-center gap-2 flex-wrap">
                    <button type="button" onclick="testConnection(<?= $api['id'] ?>)" id="btn-test-<?= $api['id'] ?>"
                            class="rounded-xl border border-blue-500/30 bg-blue-600/10 hover:bg-blue-600/20 text-blue-300 px-3.5 py-1.5 text-xs font-semibold transition flex items-center gap-1.5 cursor-pointer">
                        <i class="fa-solid fa-satellite-dish"></i> Uji Koneksi (Ping)
                    </button>

                    <button type="button" onclick="showCurlSnippet(<?= htmlspecialchars(json_encode($api)) ?>)" 
                            class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-slate-300 px-3 py-1.5 text-xs font-medium transition flex items-center gap-1.5 cursor-pointer" title="Lihat Contoh CURL">
                        <i class="fa-solid fa-terminal text-slate-400"></i> CURL
                    </button>

                    <?php if (!$is_core): ?>
                        <button type="button" onclick="deleteApi(<?= $api['id'] ?>, '<?= htmlspecialchars(addslashes($api['name'])) ?>')" 
                                class="rounded-xl border border-rose-500/20 bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 px-2.5 py-1.5 text-xs font-semibold transition flex items-center gap-1 cursor-pointer" title="Hapus Integrasi API">
                            <i class="fa-regular fa-trash-can"></i>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Last Test Result Badge -->
                <div id="test-result-badge-<?= $api['id'] ?>" class="text-right">
                    <?php if (!empty($api['last_tested_at'])): 
                        $code = (int)($api['last_status_code'] ?? 0);
                        $badgeClass = ($code >= 200 && $code < 400) 
                            ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' 
                            : ($code > 0 ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' : 'bg-rose-500/10 text-rose-400 border-rose-500/20');
                    ?>
                        <span class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[11px] font-mono font-medium <?= $badgeClass ?>"
                              title="<?= htmlspecialchars($api['last_response'] ?? '') ?>">
                            <span class="h-1.5 w-1.5 rounded-full <?= ($code >= 200 && $code < 400) ? 'bg-emerald-400' : 'bg-amber-400' ?>"></span>
                            HTTP <?= $code ?: 'Err' ?>
                            <span class="text-[10px] opacity-75">(<?= date('d/m H:i', strtotime($api['last_tested_at'])) ?>)</span>
                        </span>
                    <?php else: ?>
                        <span class="text-[11px] text-slate-400 italic">Belum diuji</span>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <!-- Empty State jika tidak ada kartu cocok -->
    <div id="emptySearchState" class="hidden rounded-3xl border border-white/10 bg-slate-900/40 p-12 text-center">
        <i class="fa-solid fa-magnifying-glass text-3xl text-slate-600 mb-3"></i>
        <h4 class="text-base font-bold text-white">Tidak ada API Luar yang cocok</h4>
        <p class="text-xs text-slate-400 mt-1">Coba sesuaikan kata kunci pencarian atau kategori yang Anda pilih.</p>
    </div>

    <!-- Informasi / Petunjuk Keamanan API -->
    <div class="rounded-3xl border border-white/10 bg-gradient-to-r from-slate-900/90 via-slate-900/60 to-blue-950/40 p-6 backdrop-blur shadow-xl">
        <div class="flex items-start gap-4">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-500/10 text-blue-400 border border-blue-500/20 text-lg">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <div class="space-y-2">
                <h4 class="text-sm font-bold text-white tracking-tight">Mekanisme Auto-Save & Keamanan API Eksternal</h4>
                <p class="text-xs text-slate-300 leading-relaxed">
                    Setiap perubahan pada formulir (URL, API Key, Provider, Tipe Autentikasi, maupun status on/off) akan <strong>langsung tersimpan otomatis ke database</strong> saat Anda selesai mengetik atau mengubah pilihan tanpa harus memuat ulang halaman.
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2 text-[11px] text-slate-400">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-check text-emerald-400"></i> Sinkronisasi otomatis ke WhatsApp Gateway
                    </div>
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-check text-cyan-400"></i> Tes koneksi langsung via cURL Server
                    </div>
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-check text-indigo-400"></i> Terintegrasi Audit Log Aktivitas
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: TAMBAH API LUAR BARU -->
<!-- ============================================================== -->
<div id="addApiModal" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4 overflow-y-auto">
    <div class="relative w-full max-w-2xl rounded-3xl border border-white/15 bg-slate-900 p-6 sm:p-8 shadow-2xl my-8">
        <div class="flex items-center justify-between pb-4 border-b border-white/10">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600/20 text-blue-400 border border-blue-500/30">
                    <i class="fa-solid fa-plus text-base"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold text-white">Tambah Integrasi API Luar Baru</h3>
                    <p class="text-xs text-slate-400">Pilih template siap pakai atau daftarkan custom REST API</p>
                </div>
            </div>
            <button type="button" onclick="closeAddModal()" class="rounded-xl p-2 text-slate-400 hover:text-white hover:bg-white/10 transition cursor-pointer">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- Preset Template Quick Selector -->
        <div class="mt-4 p-3 rounded-2xl bg-blue-500/10 border border-blue-500/20">
            <label class="block text-xs font-semibold text-blue-300 mb-1.5 flex items-center gap-1.5">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Gunakan Template Cepat (Auto-Fill):
            </label>
            <select id="presetSelector" onchange="applyPreset(this.value)" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none font-medium">
                <option value="">-- Pilih Template Layanan API Populer --</option>
                <option value="telegram">Telegram Bot Notification API</option>
                <option value="wablas">Wablas WhatsApp Gateway API</option>
                <option value="ultramsg">UltraMsg WhatsApp Multi-Device</option>
                <option value="xendit">Xendit Payment Gateway API</option>
                <option value="openai">OpenAI GPT-4o / ChatGPT API</option>
                <option value="gemini">Google Gemini 1.5 Flash API</option>
                <option value="brevo">Brevo (Sendinblue) Email SMTP API</option>
                <option value="resend">Resend Transactional Mail API</option>
                <option value="openmeteo">Open-Meteo Cuaca Realtime</option>
                <option value="custom">Custom Webhook / REST Endpoint Lainnya</option>
            </select>
        </div>

        <form method="POST" class="space-y-4 mt-5">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_api_full">
            <input type="hidden" name="id" value="0">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kode Unik API (Slug) *</label>
                    <input type="text" name="api_code" id="modal_api_code" required placeholder="misal: telegram_bot, custom_api" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white font-mono focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Integrasi API *</label>
                    <input type="text" name="name" id="modal_name" required placeholder="misal: Bot Telegram Sekolah" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Kategori API *</label>
                    <select name="category" id="modal_category" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                        <option value="custom">Kustom / Lainnya</option>
                        <option value="whatsapp">WhatsApp Gateway</option>
                        <option value="payment">Payment Gateway</option>
                        <option value="ai">AI & LLM</option>
                        <option value="mail">Email Transaksional</option>
                        <option value="maps">Peta & Geocoding</option>
                        <option value="weather">Cuaca / Prakiraan</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Nama Provider / Vendor *</label>
                    <input type="text" name="provider" id="modal_provider" required placeholder="misal: Telegram, OpenAI, Wablas" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Metode HTTP *</label>
                    <select name="http_method" id="modal_http_method" required class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none font-bold text-blue-400">
                        <option value="POST">POST</option>
                        <option value="GET">GET</option>
                        <option value="PUT">PUT</option>
                        <option value="DELETE">DELETE</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Endpoint URL API Luar *</label>
                <input type="url" name="endpoint_url" id="modal_endpoint_url" required placeholder="https://api.domain.com/v1/resource" 
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white font-mono focus:border-blue-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">API Key / Token Rahasia</label>
                    <input type="text" name="api_key" id="modal_api_key" placeholder="Bearer token / API Secret" 
                           class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white font-mono focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-1">Tipe Autentikasi</label>
                    <select name="auth_type" id="modal_auth_type" class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
                        <option value="bearer">Bearer Token</option>
                        <option value="header">Custom Header</option>
                        <option value="basic">Basic Auth</option>
                        <option value="query">URL Query (?key=)</option>
                        <option value="none">Tanpa Autentikasi</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Format Template Payload JSON (Opsional)</label>
                <textarea name="payload_template" id="modal_payload_template" rows="2" placeholder='{"pesan": "halo", "tujuan": "123"}'
                          class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white font-mono focus:border-blue-500 focus:outline-none"></textarea>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-300 mb-1">Deskripsi Singkat</label>
                <input type="text" name="description" id="modal_description" placeholder="Penjelasan fungsi API ini..." 
                       class="w-full rounded-xl border border-white/10 bg-slate-950 px-3 py-2 text-xs text-white focus:border-blue-500 focus:outline-none">
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-white/10">
                <button type="button" onclick="closeAddModal()" class="rounded-xl px-4 py-2 text-xs font-semibold text-slate-400 hover:text-white transition cursor-pointer">
                    Batal
                </button>
                <button type="submit" class="rounded-xl bg-blue-600 hover:bg-blue-500 text-white px-5 py-2 text-xs font-bold transition shadow-lg shadow-blue-600/30 flex items-center gap-2 cursor-pointer">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Simpan ke Database
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL: HASIL UJI KONEKSI & SNIPPET CURL -->
<!-- ============================================================== -->
<div id="infoModal" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="relative w-full max-w-xl rounded-3xl border border-white/15 bg-slate-900 p-6 shadow-2xl">
        <div class="flex items-center justify-between pb-3 border-b border-white/10">
            <h3 class="text-sm font-bold text-white flex items-center gap-2" id="infoModalTitle">
                <i class="fa-solid fa-terminal text-blue-400"></i> Detail Informasi API
            </h3>
            <button type="button" onclick="closeInfoModal()" class="text-slate-400 hover:text-white transition cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="mt-4">
            <pre id="infoModalContent" class="rounded-2xl border border-white/10 bg-slate-950 p-4 text-xs text-slate-200 font-mono overflow-x-auto max-h-80 custom-scrollbar whitespace-pre-wrap"></pre>
        </div>
        <div class="mt-4 flex justify-end">
            <button type="button" onclick="copyModalContent()" class="rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-white px-4 py-1.5 text-xs font-semibold transition flex items-center gap-1.5 cursor-pointer">
                <i class="fa-regular fa-copy"></i> Salin Konten
            </button>
        </div>
    </div>
</div>

<!-- CSRF Token Meta untuk Javascript Fetch -->
<input type="hidden" id="csrf_master_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

<!-- ============================================================== -->
<!-- SCRIPT: REALTIME AUTO-SAVE & INTERAKTIVITAS API -->
<!-- ============================================================== -->
<script>
const CSRF_TOKEN = document.getElementById('csrf_master_token').value;
const debounceTimers = {};

// 1. Tangani Auto-Save saat input berubah (Debounce 600ms untuk ketikan, instant untuk dropdown/toggle)
document.addEventListener('DOMContentLoaded', () => {
    const autosaveElements = document.querySelectorAll('[data-api-autosave="true"]');
    
    autosaveElements.forEach(el => {
        const isTextInput = (el.tagName === 'INPUT' && (el.type === 'text' || el.type === 'password' || el.type === 'url' || el.type === 'number')) || el.tagName === 'TEXTAREA';
        
        if (isTextInput) {
            el.addEventListener('input', () => {
                const apiId = el.getAttribute('data-id');
                const field = el.getAttribute('data-field');
                const value = el.value;
                const timerKey = `${apiId}_${field}`;

                showSavingIndicator(apiId);

                clearTimeout(debounceTimers[timerKey]);
                debounceTimers[timerKey] = setTimeout(() => {
                    executeAutoSave(apiId, field, value);
                }, 600);
            });
        } else {
            // Select atau checkbox
            el.addEventListener('change', () => {
                const apiId = el.getAttribute('data-id');
                const field = el.getAttribute('data-field');
                const value = el.value;
                showSavingIndicator(apiId);
                executeAutoSave(apiId, field, value);
            });
        }
    });
});

// Eksekusi POST AJAX Auto-Save
function executeAutoSave(id, field, value) {
    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'auto_save');
    formData.append('id', id);
    formData.append('field', field);
    formData.append('value', value);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('external_apis.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showSavedIndicator(id, data.timestamp);
        } else {
            showErrorIndicator(id, data.message);
        }
    })
    .catch(err => {
        showErrorIndicator(id, 'Koneksi jaringan terputus');
    });
}

// Tampilkan Status "Menyimpan..."
function showSavingIndicator(id) {
    const el = document.getElementById(`save-indicator-${id}`);
    if (!el) return;
    const msgEl = el.querySelector('.save-status-msg');
    if (msgEl) {
        msgEl.className = 'save-status-msg text-blue-400 font-medium flex items-center gap-1.5 text-[11px] animate-pulse';
        msgEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-[10px]"></i> Menyimpan ke DB...';
    }
}

// Tampilkan Status "Tersimpan Otomatis"
function showSavedIndicator(id, timestamp) {
    const el = document.getElementById(`save-indicator-${id}`);
    if (!el) return;
    const msgEl = el.querySelector('.save-status-msg');
    const timeEl = document.getElementById(`last-update-${id}`);
    if (timeEl && timestamp) {
        timeEl.innerText = timestamp;
    }
    if (msgEl) {
        msgEl.className = 'save-status-msg text-emerald-400 font-semibold flex items-center gap-1 text-[11px]';
        msgEl.innerHTML = '<i class="fa-solid fa-circle-check text-[10px]"></i> Tersimpan di database';
    }
}

// Tampilkan Status Error
function showErrorIndicator(id, msg) {
    const el = document.getElementById(`save-indicator-${id}`);
    if (!el) return;
    const msgEl = el.querySelector('.save-status-msg');
    if (msgEl) {
        msgEl.className = 'save-status-msg text-rose-400 font-medium flex items-center gap-1 text-[11px]';
        msgEl.innerHTML = `<i class="fa-solid fa-triangle-exclamation text-[10px]"></i> ${msg}`;
    }
}

// 2. Toggle Status Aktif / Nonaktif
function toggleApiStatus(id, checkboxEl) {
    const isActive = checkboxEl.checked ? 1 : 0;
    const labelEl = document.getElementById(`status-label-${id}`);
    
    showSavingIndicator(id);

    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'toggle_active');
    formData.append('id', id);
    formData.append('is_active', isActive);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('external_apis.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showSavedIndicator(id, data.timestamp);
            if (labelEl) {
                labelEl.innerText = isActive ? 'Aktif' : 'Nonaktif';
                labelEl.className = `text-[10px] font-semibold status-label ${isActive ? 'text-emerald-400' : 'text-slate-500'}`;
            }
        } else {
            checkboxEl.checked = !isActive;
            showErrorIndicator(id, data.message);
        }
    })
    .catch(() => {
        checkboxEl.checked = !isActive;
        showErrorIndicator(id, 'Gagal terhubung server');
    });
}

// 3. Uji Koneksi API / Live Ping
function testConnection(id) {
    const btn = document.getElementById(`btn-test-${id}`);
    const badgeContainer = document.getElementById(`test-result-badge-${id}`);
    const originalText = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-xs"></i> Menguji...';

    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'test_connection');
    formData.append('id', id);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('external_apis.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalText;

        if (data.success && data.result) {
            const r = data.result;
            const isOk = (r.status_code >= 200 && r.status_code < 400);
            const badgeClass = isOk 
                ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' 
                : (r.status_code > 0 ? 'bg-amber-500/15 text-amber-300 border-amber-500/30' : 'bg-rose-500/15 text-rose-300 border-rose-500/30');

            badgeContainer.innerHTML = `
                <button type="button" onclick="showResponseDetails('${r.status_code}', '${r.latency_ms}', '${escapeHtml(r.response_snippet)}')" 
                        class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[11px] font-mono font-bold transition hover:scale-105 cursor-pointer ${badgeClass}">
                    <span class="h-2 w-2 rounded-full ${isOk ? 'bg-emerald-400' : 'bg-amber-400'}"></span>
                    HTTP ${r.status_code || 'Err'} (${r.latency_ms}ms)
                </button>
            `;
            showSavedIndicator(id);
        } else {
            badgeContainer.innerHTML = `<span class="text-rose-400 text-xs font-semibold">Gagal Uji</span>`;
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = originalText;
        badgeContainer.innerHTML = `<span class="text-rose-400 text-xs font-semibold">Network Error</span>`;
    });
}

// 4. Batch Test All APIs
function testAllApis() {
    const cards = document.querySelectorAll('.api-card');
    const btnAll = document.getElementById('btnTestAll');
    if (!cards.length) return;

    btnAll.disabled = true;
    btnAll.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-cyan-400"></i> Menguji Semua...';

    let index = 0;
    function runNext() {
        if (index >= cards.length) {
            btnAll.disabled = false;
            btnAll.innerHTML = '<i class="fa-solid fa-satellite-dish text-cyan-400"></i> Uji Semua Selesai';
            setTimeout(() => {
                btnAll.innerHTML = '<i class="fa-solid fa-satellite-dish text-cyan-400"></i> Uji Semua Koneksi';
            }, 3000);
            return;
        }
        const cardIdStr = cards[index].id;
        const apiId = cardIdStr.replace('card-api-', '');
        testConnection(apiId);
        index++;
        setTimeout(runNext, 400); // interval antar pengujian agar tidak throttling
    }
    runNext();
}

// 5. Hapus API Eksternal
function deleteApi(id, name) {
    if (!confirm(`Apakah Anda yakin ingin menghapus integrasi API "${name}" dari sistem?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('ajax', '1');
    formData.append('action', 'delete_api');
    formData.append('id', id);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('external_apis.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            const card = document.getElementById(`card-api-${id}`);
            if (card) {
                card.classList.add('opacity-0', 'scale-95');
                setTimeout(() => card.remove(), 250);
            }
        } else {
            alert(data.message);
        }
    })
    .catch(() => alert('Gagal menghubungi server.'));
}

// 6. Tampilkan / Sembunyikan Advanced Panel
function toggleAdvanced(id) {
    const panel = document.getElementById(`adv-panel-${id}`);
    const arrow = document.getElementById(`adv-arrow-${id}`);
    const text  = document.getElementById(`adv-btn-text-${id}`);
    if (panel.classList.contains('hidden')) {
        panel.classList.remove('hidden');
        arrow.classList.add('rotate-180');
        text.innerText = 'Sembunyikan Header Kustom & Payload';
    } else {
        panel.classList.add('hidden');
        arrow.classList.remove('rotate-180');
        text.innerText = 'Tampilkan Header Kustom & Format JSON';
    }
}

// 7. Tampilkan Password / Masked Key
function toggleSecretVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = '<i class="fa-regular fa-eye-slash"></i> Sembunyi';
    } else {
        input.type = 'password';
        btn.innerHTML = '<i class="fa-regular fa-eye"></i> Tampil';
    }
}

// 8. Salin ke Clipboard
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('Teks berhasil disalin ke clipboard!');
    }).catch(() => {
        prompt('Salin teks secara manual:', text);
    });
}

// 9. Template Presets Cepat
const PRESETS = {
    telegram: {
        code: 'telegram_bot',
        name: 'Telegram Bot Notification API',
        category: 'custom',
        provider: 'Telegram Bot API',
        method: 'POST',
        url: 'https://api.telegram.org/bot{YOUR_BOT_TOKEN}/sendMessage',
        key: '',
        auth_type: 'none',
        payload: '{\n  "chat_id": "-100123456789",\n  "text": "Notifikasi Sekolah: Ujian KBM Gasal dimulai.",\n  "parse_mode": "HTML"\n}',
        desc: 'Kirim notifikasi otomatis ke Channel / Grup Telegram Guru atau Pengurus Sekolah.'
    },
    wablas: {
        code: 'wablas_gateway',
        name: 'Wablas WhatsApp Gateway API',
        category: 'whatsapp',
        provider: 'Wablas',
        method: 'POST',
        url: 'https://kudus.wablas.com/api/send-message',
        key: 'YOUR_WABLAS_API_TOKEN',
        auth_type: 'header',
        payload: '{\n  "phone": "{phone}",\n  "message": "{message}"\n}',
        desc: 'Gateway pengiriman WhatsApp broadcast dan notifikasi presensi siswa.'
    },
    ultramsg: {
        code: 'ultramsg_wa',
        name: 'UltraMsg WhatsApp Multi-Device',
        category: 'whatsapp',
        provider: 'UltraMsg',
        method: 'POST',
        url: 'https://api.ultramsg.com/instance12345/messages/chat',
        key: 'YOUR_ULTRAMSG_TOKEN',
        auth_type: 'header',
        payload: '{\n  "to": "{phone}",\n  "body": "{message}"\n}',
        desc: 'WhatsApp Gateway berbasis UltraMsg Instance Cloud.'
    },
    xendit: {
        code: 'xendit_payment',
        name: 'Xendit Invoice & Virtual Account',
        category: 'payment',
        provider: 'Xendit',
        method: 'POST',
        url: 'https://api.xendit.co/v2/invoices',
        key: 'xnd_development_SecretKeyExample',
        auth_type: 'basic',
        payload: '{\n  "external_id": "SPP-2026-001",\n  "amount": 350000,\n  "payer_email": "ortu@example.com"\n}',
        desc: 'Pembuatan invoice tagihan SPP online via Virtual Account BCA, Mandiri, QRIS.'
    },
    openai: {
        code: 'openai_gpt',
        name: 'OpenAI GPT-4o Education Assistant',
        category: 'ai',
        provider: 'OpenAI',
        method: 'POST',
        url: 'https://api.openai.com/v1/chat/completions',
        key: 'sk-proj-xxxxxxxxxxxxxxxxxxxx',
        auth_type: 'bearer',
        payload: '{\n  "model": "gpt-4o-mini",\n  "messages": [\n    {"role": "system", "content": "Anda adalah asisten kurikulum guru SMA."},\n    {"role": "user", "content": "Buatkan 5 soal pilihan ganda biologi kelas 10."}\n  ]\n}',
        desc: 'Kecerdasan Buatan untuk perumusan soal CBT otomatis dan bantuan materi ajar RPP.'
    },
    gemini: {
        code: 'gemini_ai',
        name: 'Google Gemini 1.5 Pro / Flash',
        category: 'ai',
        provider: 'Google AI Studio',
        method: 'POST',
        url: 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
        key: 'AIzaSyDemoKeyExample2026',
        auth_type: 'query',
        payload: '{\n  "contents": [\n    {"parts": [{"text": "Evaluasi esai siswa dan berikan skor 0-100."}]}\n  ]\n}',
        desc: 'Penilaian otomatis esai ujian CBT dan rekomendasi bahan belajar personal.'
    },
    brevo: {
        code: 'brevo_email',
        name: 'Brevo (Sendinblue) Transactional Mail',
        category: 'mail',
        provider: 'Brevo',
        method: 'POST',
        url: 'https://api.brevo.com/v3/smtp/email',
        key: 'xkeysib-xxxxxxxxxxxxxxxxxxxx',
        auth_type: 'header',
        payload: '{\n  "sender": {"name": "SMA Bina Bangsa", "email": "info@binabangsa.sch.id"},\n  "to": [{"email": "siswa@example.com"}],\n  "subject": "Bukti Pembayaran SPP",\n  "htmlContent": "<p>Terima kasih telah membayar SPP.</p>"\n}',
        desc: 'Pengiriman email resmi kuitansi pembayaran dan verifikasi pendaftaran PPDB.'
    },
    resend: {
        code: 'resend_mail',
        name: 'Resend Transactional Mail API',
        category: 'mail',
        provider: 'Resend',
        method: 'POST',
        url: 'https://api.resend.com/emails',
        key: 're_123456789_abcdef',
        auth_type: 'bearer',
        payload: '{\n  "from": "noreply@binabangsa.sch.id",\n  "to": ["ortu@example.com"],\n  "subject": "Laporan E-Rapor Semester",\n  "text": "E-Rapor digital telah terbit."\n}',
        desc: 'Layanan email pengiriman rapor digital dan peringatan kehadiran siswa.'
    },
    openmeteo: {
        code: 'open_meteo',
        name: 'Open-Meteo Cuaca Sekolah',
        category: 'weather',
        provider: 'Open-Meteo Public API',
        method: 'GET',
        url: 'https://api.open-meteo.com/v1/forecast?latitude=-6.2088&longitude=106.8456&current_weather=true',
        key: '',
        auth_type: 'none',
        payload: '{}',
        desc: 'Pemantauan suhu, cuaca, dan curah hujan real-time di koordinat sekolah.'
    }
};

function applyPreset(key) {
    if (!key || !PRESETS[key]) return;
    const p = PRESETS[key];
    document.getElementById('modal_api_code').value = p.code;
    document.getElementById('modal_name').value = p.name;
    document.getElementById('modal_category').value = p.category;
    document.getElementById('modal_provider').value = p.provider;
    document.getElementById('modal_http_method').value = p.method;
    document.getElementById('modal_endpoint_url').value = p.url;
    document.getElementById('modal_api_key').value = p.key;
    document.getElementById('modal_auth_type').value = p.auth_type;
    document.getElementById('modal_payload_template').value = p.payload;
    document.getElementById('modal_description').value = p.desc;
}

// 10. Filter & Pencarian
let currentCategory = 'semua';
function setCategoryFilter(category, btn) {
    currentCategory = category;
    document.querySelectorAll('.category-btn').forEach(b => {
        b.className = 'category-btn px-3 py-1.5 rounded-xl font-semibold transition border border-white/5 bg-slate-800/50 text-slate-400 hover:text-white';
    });
    btn.className = 'category-btn active px-3 py-1.5 rounded-xl font-semibold transition border border-blue-500/30 bg-blue-600/20 text-blue-300';
    filterCards();
}

function filterCards() {
    const searchVal = document.getElementById('searchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.api-card');
    let visibleCount = 0;

    cards.forEach(card => {
        const cat = card.getAttribute('data-category');
        const nameData = card.getAttribute('data-name');

        const matchesCat = (currentCategory === 'semua' || cat === currentCategory);
        const matchesSearch = (searchVal === '' || nameData.includes(searchVal));

        if (matchesCat && matchesSearch) {
            card.classList.remove('hidden');
            visibleCount++;
        } else {
            card.classList.add('hidden');
        }
    });

    const emptyEl = document.getElementById('emptySearchState');
    if (visibleCount === 0) {
        emptyEl.classList.remove('hidden');
    } else {
        emptyEl.classList.add('hidden');
    }
}

// 11. Modal Detail CURL & Response
function showCurlSnippet(api) {
    const modal = document.getElementById('infoModal');
    const title = document.getElementById('infoModalTitle');
    const content = document.getElementById('infoModalContent');

    title.innerHTML = `<i class="fa-solid fa-terminal text-blue-400"></i> Contoh Format CURL: ${escapeHtml(api.name)}`;

    let curl = `curl -X ${api.http_method || 'POST'} "${api.endpoint_url}" \\\n`;
    if (api.auth_type === 'bearer' && api.api_key) {
        curl += `  -H "Authorization: Bearer ${api.api_key}" \\\n`;
    } else if (api.auth_type === 'header' && api.api_key) {
        const hName = api.auth_header_name || 'Authorization';
        curl += `  -H "${hName}: ${api.api_key}" \\\n`;
    } else if (api.auth_type === 'basic' && api.api_key) {
        curl += `  -u "${api.api_key}:${api.api_secret || ''}" \\\n`;
    }

    curl += `  -H "Content-Type: application/json"`;
    if (api.http_method === 'POST' || api.http_method === 'PUT') {
        const payload = (api.payload_template && api.payload_template.trim() !== '') ? api.payload_template : '{"test": true}';
        curl += ` \\\n  -d '${payload.replace(/'/g, "\\'")}'`;
    }

    content.innerText = curl;
    modal.classList.remove('hidden');
}

function showResponseDetails(code, latency, snippet) {
    const modal = document.getElementById('infoModal');
    const title = document.getElementById('infoModalTitle');
    const content = document.getElementById('infoModalContent');

    title.innerHTML = `<i class="fa-solid fa-server text-cyan-400"></i> Hasil Uji Terakhir: HTTP ${code} (${latency}ms)`;
    content.innerText = snippet || 'Tidak ada konten respon.';
    modal.classList.remove('hidden');
}

function closeInfoModal() {
    document.getElementById('infoModal').classList.add('hidden');
}

function copyModalContent() {
    const text = document.getElementById('infoModalContent').innerText;
    copyToClipboard(text);
}

// 12. Modal Tambah API
function openAddModal() {
    document.getElementById('addApiModal').classList.remove('hidden');
}
function closeAddModal() {
    document.getElementById('addApiModal').classList.add('hidden');
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
