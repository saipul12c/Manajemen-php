<?php
/**
 * Renderer Halaman Error Visual Premium & Terproteksi
 * Manajemen-PHP
 * 
 * Mengimplementasikan prinsip keamanan Defense-in-Depth:
 * - Anti XSS / Content Spoofing melalui sanitasi & validasi ketat
 * - HTTP Security Headers (CSP, X-Frame-Options, X-Content-Type-Options)
 * - Anti Cache untuk halaman error
 * - Zero Session-ID Leakage (menggunakan Cryptographic Incident ID)
 * - Absolute Path Resolution (mencegah broken links pada nested route)
 * - Safe Event-Driven UI (tanpa inline JS injection vector)
 */

// Cegah pemanggilan berkas secara langsung
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    exit('Akses langsung ke berkas ini dibatasi demi keamanan.');
}

require_once __DIR__ . "/error_data.php";

function renderErrorPage(int $statusCode, ?string $customTitle = null, ?string $customDesc = null): void {
    // Validasi range status code HTTP aman (400 - 599)
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }

    http_response_code($statusCode);

    // Kirimkan HTTP Security Headers jika belum terkirim
    if (!headers_sent()) {
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
        header("Content-Security-Policy: default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval'; img-src 'self' data: https:; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; frame-ancestors 'none';");
    }

    $data = getErrorDetails($statusCode);

    // Sanitasi input kustom untuk mencegah XSS & Content Spoofing
    if ($customTitle !== null) {
        $cleanTitle = strip_tags($customTitle);
        $cleanTitle = preg_replace('/[\r\n\t]+/', ' ', $cleanTitle);
        $cleanTitle = function_exists('mb_substr') ? mb_substr(trim($cleanTitle), 0, 100, 'UTF-8') : substr(trim($cleanTitle), 0, 100);
        $title = $cleanTitle !== '' ? $cleanTitle : $data['title'];
    } else {
        $title = $data['title'];
    }

    if ($customDesc !== null) {
        $cleanDesc = strip_tags($customDesc);
        $cleanDesc = preg_replace('/[\r\n\t]+/', ' ', $cleanDesc);
        $cleanDesc = function_exists('mb_substr') ? mb_substr(trim($cleanDesc), 0, 400, 'UTF-8') : substr(trim($cleanDesc), 0, 400);
        $desc = $cleanDesc !== '' ? $cleanDesc : $data['description'];
    } else {
        $desc = $data['description'];
    }

    $code = $data['code'];
    $statusText = $data['status'];
    $badgeText = $data['badge'];
    $icon = $data['icon'];
    $suggestions = $data['suggestions'];
    $primaryBtn = $data['primary_btn'];
    $glowColor = $data['glow_color'];
    $borderColor = $data['border_color'];
    $badgeBg = $data['badge_bg'];
    $btnBg = $data['btn_bg'];

    // Resolusi path web absolut secara dinamis agar link tidak pernah rusak
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = '/';

    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']), '/');
        $projectDir = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
        if (strpos($projectDir, $docRoot) === 0) {
            $subPath = trim(substr($projectDir, strlen($docRoot)), '/');
            $basePath = $subPath === '' ? '/' : '/' . $subPath . '/';
        }
    }

    if ($basePath === '/' && preg_match('#^(/[^/]+)?/(error|api|apps|auth|config|dashboard|dokumentasi|includes|ppdb|uploads)/#i', $scriptName, $matches)) {
        if (!empty($matches[1])) {
            $basePath = $matches[1] . '/';
        }
    }

    $homeUrl = $basePath . 'index.php';
    $dashboardUrl = $basePath . 'berita.php';
    $loginUrl = $basePath . 'auth/login.php';
    $contactUrl = $basePath . 'kontak.php';

    // Resolusi URL Primary Button jika bukan JavaScript action
    $primaryHref = null;
    if (isset($primaryBtn['href'])) {
        $rawHref = preg_replace('#^(\.\./)+#', '', $primaryBtn['href']);
        $primaryHref = $basePath . ltrim($rawHref, '/');
    }

    // Incident Reference ID unik (Zero Session ID Leakage)
    $incidentHash = hash('sha256', $statusCode . microtime(true) . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    $incidentId = 'ERR-' . strtoupper(substr($incidentHash, 0, 8));
    $timestamp = date('d/m/Y H:i:s T');
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= (int) $code ?> - <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> | Manajemen-PHP</title>
    
    <!-- Google Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css">
    
    <!-- Tailwind CSS v4 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <style>
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        @keyframes floatSlow {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-8px) rotate(2deg); }
        }
        @keyframes pulseGlow {
            0%, 100% { opacity: 0.5; transform: scale(1); }
            50% { opacity: 0.8; transform: scale(1.05); }
        }
        .float-anim {
            animation: floatSlow 4s ease-in-out infinite;
        }
        .glow-pulse {
            animation: pulseGlow 5s ease-in-out infinite;
        }
    </style>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex flex-col justify-between selection:bg-blue-600 selection:text-white relative overflow-x-hidden">

    <!-- Ambient Glowing Orbs -->
    <div class="pointer-events-none fixed inset-0 overflow-hidden z-0">
        <div class="absolute -top-40 left-1/2 -translate-x-1/2 h-[500px] w-[600px] rounded-full blur-[140px] glow-pulse"
             style="background: radial-gradient(circle, <?= htmlspecialchars($glowColor, ENT_QUOTES, 'UTF-8') ?> 0%, transparent 70%);"></div>
        <div class="absolute -bottom-40 right-10 h-[400px] w-[400px] rounded-full blur-[120px] opacity-30"
             style="background: radial-gradient(circle, rgba(59, 130, 246, 0.2) 0%, transparent 70%);"></div>
    </div>

    <!-- Header Navigation Ringan -->
    <header class="relative z-10 border-b border-white/5 bg-slate-950/60 backdrop-blur-md">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>" class="flex items-center gap-3 text-lg font-bold tracking-tight text-white group">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-500/30 transition group-hover:scale-105">
                    <i class="fa-solid fa-graduation-cap text-sm"></i>
                </span>
                <span>Manajemen<span class="text-blue-500">-PHP</span></span>
            </a>

            <div class="flex items-center gap-3 text-xs font-semibold">
                <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>" class="rounded-xl px-3.5 py-2 text-slate-400 hover:text-white hover:bg-white/5 transition">
                    Beranda
                </a>
                <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="rounded-xl border border-white/10 bg-white/5 px-3.5 py-2 text-slate-200 hover:bg-white/10 transition">
                    News →
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="relative z-10 mx-auto w-full max-w-4xl px-4 sm:px-6 py-12 flex-1 flex flex-col items-center justify-center text-center">
        
        <!-- Glassmorphism Card Container -->
        <div class="w-full rounded-3xl border <?= htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') ?> bg-slate-900/60 p-8 sm:p-14 backdrop-blur-xl shadow-2xl shadow-black/60 relative overflow-hidden">
            
            <!-- Top Subtle Light Beam inside card -->
            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent"></div>

            <!-- Visual Icon + Code Stack -->
            <div class="flex flex-col items-center justify-center mb-6">
                
                <!-- Floating Icon with Soft Glow Frame -->
                <div class="float-anim mb-4 inline-flex h-20 w-20 sm:h-24 sm:w-24 items-center justify-center rounded-3xl border <?= htmlspecialchars($borderColor, ENT_QUOTES, 'UTF-8') ?> bg-white/[0.04] text-4xl sm:text-5xl shadow-inner backdrop-blur-sm">
                    <?= $icon ?>
                </div>

                <!-- Status Badge -->
                <div class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1 text-xs font-bold uppercase tracking-wider <?= htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') ?> mb-3 shadow-sm">
                    <span class="h-1.5 w-1.5 rounded-full bg-current animate-ping"></span>
                    <?= htmlspecialchars($badgeText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>

                <!-- Big Glowing Code -->
                <h1 class="text-6xl sm:text-8xl font-black tracking-tighter text-transparent bg-clip-text bg-gradient-to-b from-white via-slate-200 to-slate-500 drop-shadow-sm select-none">
                    <?= (int) $code ?>
                </h1>
            </div>

            <!-- Error Message Details -->
            <div class="max-w-2xl mx-auto space-y-3 mb-8">
                <h2 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">
                    <?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </h2>
                
                <p class="text-sm sm:text-base text-slate-300 leading-relaxed font-normal">
                    <?= htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </p>
            </div>

            <!-- Troubleshooting Suggestions Box -->
            <?php if (!empty($suggestions) && is_array($suggestions)): ?>
                <div class="mb-8 rounded-2xl border border-white/5 bg-slate-950/50 p-5 text-left max-w-xl mx-auto backdrop-blur-sm">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-lightbulb text-amber-400"></i> Saran Tindakan Penyelesaian:
                    </p>
                    <ul class="space-y-2 text-xs text-slate-300">
                        <?php foreach ($suggestions as $suggestion): ?>
                            <li class="flex items-start gap-2">
                                <span class="text-blue-400 mt-0.5">•</span>
                                <span><?= htmlspecialchars((string) $suggestion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="flex flex-wrap items-center justify-center gap-3">
                
                <!-- Primary Action -->
                <?php if (isset($primaryBtn['action'])): ?>
                    <button type="button"
                            data-action="<?= htmlspecialchars($primaryBtn['action'], ENT_QUOTES, 'UTF-8') ?>"
                            class="inline-flex items-center gap-2 rounded-xl <?= htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8') ?> px-5 py-3 text-xs sm:text-sm font-bold transition shadow-lg cursor-pointer">
                        <span><?= $primaryBtn['icon'] ?? '<i class="fa-solid fa-bolt"></i>' ?></span>
                        <?= htmlspecialchars($primaryBtn['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </button>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($primaryHref ?? $homeUrl, ENT_QUOTES, 'UTF-8') ?>"
                       class="inline-flex items-center gap-2 rounded-xl <?= htmlspecialchars($btnBg, ENT_QUOTES, 'UTF-8') ?> px-5 py-3 text-xs sm:text-sm font-bold transition shadow-lg">
                        <span><?= $primaryBtn['icon'] ?? '<i class="fa-solid fa-bolt"></i>' ?></span>
                        <?= htmlspecialchars($primaryBtn['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                <?php endif; ?>

                <!-- Secondary: Go Back Button -->
                <button type="button"
                        data-action="back"
                        class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3 text-xs sm:text-sm font-bold text-slate-200 transition cursor-pointer">
                    <i class="fa-solid fa-arrow-left text-xs"></i> Kembali
                </button>

                <!-- Tertiary: Dashboard Quick Link -->
                <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>"
                   class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3 text-xs sm:text-sm font-bold text-slate-200 transition">
                    <i class="fa-solid fa-gauge text-xs"></i> Dasbor Utama
                </a>

            </div>

        </div>

    </main>

    <!-- Footer -->
    <footer class="relative z-10 border-t border-white/5 bg-slate-950/80 py-6 text-center text-xs text-slate-500">
        <div class="mx-auto max-w-6xl px-6 flex flex-col sm:flex-row items-center justify-between gap-4">
            <p>© <?= date('Y') ?> Manajemen-PHP — Sistem Terpadu Manajemen Sekolah Terpadu.</p>
            <div class="flex flex-wrap items-center justify-center gap-4">
                <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>" class="hover:text-slate-300 transition">Login Akun</a>
                <span>•</span>
                <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="hover:text-slate-300 transition">Dashboard</a>
                <span>•</span>
                <a href="<?= htmlspecialchars($contactUrl, ENT_QUOTES, 'UTF-8') ?>" class="hover:text-slate-300 transition">Kontak Bantuan</a>
                <span>•</span>
                <span class="text-slate-500 font-mono" title="Referensi Pelaporan Insiden">Ref: <?= htmlspecialchars($incidentId, ENT_QUOTES, 'UTF-8') ?></span>
                <span>•</span>
                <span class="text-slate-600"><?= htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    </footer>

    <!-- Safe Event Listener Script (No inline JS injection, fully CSP compliant) -->
    <script>
    (function() {
        const homeUrl = <?= json_encode($homeUrl) ?>;
        document.querySelectorAll('[data-action]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const action = this.getAttribute('data-action');
                if (action === 'back') {
                    if (window.history.length > 1) {
                        window.history.back();
                    } else {
                        window.location.href = homeUrl;
                    }
                } else if (action === 'reload') {
                    window.location.reload();
                }
            });
        });

        // Keyboard Shortcut: ESC -> Kembali
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = homeUrl;
                }
            }
        });
    })();
    </script>
</body>
</html>
<?php
}
