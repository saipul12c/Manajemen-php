<?php
/**
 * Renderer Halaman Error Visual Premium
 * Manajemen-PHP
 */

require_once __DIR__ . "/error_data.php";

function renderErrorPage(int $statusCode, ?string $customTitle = null, ?string $customDesc = null): void {
    http_response_code($statusCode);
    $data = getErrorDetails($statusCode);

    // Tentukan relasi path ke root direktori projek secara dinamis
    $currentUri = $_SERVER['REQUEST_URI'] ?? '';
    $pathOnly = explode('?', $currentUri)[0];
    if (preg_match('#/(dashboard/(admin|akademik|bk|keuangan|pesan|presensi|perpustakaan|surat|Modul-ujian))/#i', $pathOnly)) {
        $toRoot = '../../';
    } elseif (preg_match('#/(auth|error|dashboard)/#i', $pathOnly)) {
        $toRoot = '../';
    } else {
        $toRoot = './';
    }

    $title = $customTitle ?? $data['title'];
    $desc = $customDesc ?? $data['description'];
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
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $code ?> - <?= htmlspecialchars($title) ?> | Manajemen-PHP</title>
    
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
             style="background: radial-gradient(circle, <?= $glowColor ?> 0%, transparent 70%);"></div>
        <div class="absolute -bottom-40 right-10 h-[400px] w-[400px] rounded-full blur-[120px] opacity-30"
             style="background: radial-gradient(circle, rgba(59, 130, 246, 0.2) 0%, transparent 70%);"></div>
    </div>

    <!-- Header Navigation Ringan -->
    <header class="relative z-10 border-b border-white/5 bg-slate-950/60 backdrop-blur-md">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
            <a href="<?= $toRoot ?>index.php" class="flex items-center gap-3 text-lg font-bold tracking-tight text-white group">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600 text-white shadow-lg shadow-blue-500/30 transition group-hover:scale-105">
                    <i class="fa-solid fa-bolt text-sm"></i>
                </span>
                <span>Manajemen<span class="text-blue-500">-PHP</span></span>
            </a>

            <div class="flex items-center gap-3 text-xs font-semibold">
                <a href="<?= $toRoot ?>index.php" class="rounded-xl px-3.5 py-2 text-slate-400 hover:text-white hover:bg-white/5 transition">
                    Beranda
                </a>
                <a href="<?= $toRoot ?>dashboard/index.php" class="rounded-xl border border-white/10 bg-white/5 px-3.5 py-2 text-slate-200 hover:bg-white/10 transition">
                    Buka Dashboard →
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="relative z-10 mx-auto w-full max-w-4xl px-4 sm:px-6 py-12 flex-1 flex flex-col items-center justify-center text-center">
        
        <!-- Glassmorphism Card Container -->
        <div class="w-full rounded-3xl border <?= $borderColor ?> bg-slate-900/60 p-8 sm:p-14 backdrop-blur-xl shadow-2xl shadow-black/60 relative overflow-hidden">
            
            <!-- Top Subtle Light Beam inside card -->
            <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent"></div>

            <!-- Visual Icon + Code Stack -->
            <div class="flex flex-col items-center justify-center mb-6">
                
                <!-- Floating Icon with Soft Glow Frame -->
                <div class="float-anim mb-4 inline-flex h-20 w-20 sm:h-24 sm:w-24 items-center justify-center rounded-3xl border <?= $borderColor ?> bg-white/[0.04] text-4xl sm:text-5xl shadow-inner backdrop-blur-sm">
                    <?= $icon ?>
                </div>

                <!-- Status Badge -->
                <div class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1 text-xs font-bold uppercase tracking-wider <?= $badgeBg ?> mb-3 shadow-sm">
                    <span class="h-1.5 w-1.5 rounded-full bg-current animate-ping"></span>
                    <?= htmlspecialchars($badgeText) ?>
                </div>

                <!-- Big Glowing Code -->
                <h1 class="text-6xl sm:text-8xl font-black tracking-tighter text-transparent bg-clip-text bg-gradient-to-b from-white via-slate-200 to-slate-500 drop-shadow-sm select-none">
                    <?= $code ?>
                </h1>
            </div>

            <!-- Error Message Details -->
            <div class="max-w-2xl mx-auto space-y-3 mb-8">
                <h2 class="text-2xl sm:text-3xl font-bold text-white tracking-tight">
                    <?= htmlspecialchars($title) ?>
                </h2>
                
                <p class="text-sm sm:text-base text-slate-300 leading-relaxed font-normal">
                    <?= htmlspecialchars($desc) ?>
                </p>
            </div>

            <!-- Troubleshooting Suggestions Box -->
            <?php if (!empty($suggestions)): ?>
                <div class="mb-8 rounded-2xl border border-white/5 bg-slate-950/50 p-5 text-left max-w-xl mx-auto backdrop-blur-sm">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3 flex items-center gap-2">
                        <i class="fa-solid fa-lightbulb text-amber-400"></i> Saran Tindakan Penyelesaian:
                    </p>
                    <ul class="space-y-2 text-xs text-slate-300">
                        <?php foreach ($suggestions as $suggestion): ?>
                            <li class="flex items-start gap-2">
                                <span class="text-blue-400 mt-0.5">•</span>
                                <span><?= htmlspecialchars($suggestion) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="flex flex-wrap items-center justify-center gap-3">
                
                <!-- Primary Action -->
                <?php if (isset($primaryBtn['action'])): ?>
                    <button onclick="<?= htmlspecialchars($primaryBtn['action']) ?>"
                            class="inline-flex items-center gap-2 rounded-xl <?= $btnBg ?> px-5 py-3 text-xs sm:text-sm font-bold transition shadow-lg cursor-pointer">
                        <span><?= $primaryBtn['icon'] ?? '<i class="fa-solid fa-bolt"></i>' ?></span>
                        <?= htmlspecialchars($primaryBtn['label']) ?>
                    </button>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($primaryBtn['href'] ?? $toRoot . 'index.php') ?>"
                       class="inline-flex items-center gap-2 rounded-xl <?= $btnBg ?> px-5 py-3 text-xs sm:text-sm font-bold transition shadow-lg">
                        <span><?= $primaryBtn['icon'] ?? '<i class="fa-solid fa-bolt"></i>' ?></span>
                        <?= htmlspecialchars($primaryBtn['label']) ?>
                    </a>
                <?php endif; ?>

                <!-- Secondary: Go Back Button -->
                <button onclick="window.history.length > 1 ? window.history.back() : window.location.href='<?= $toRoot ?>index.php'"
                        class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3 text-xs sm:text-sm font-bold text-slate-200 transition cursor-pointer">
                    <i class="fa-solid fa-arrow-left text-xs"></i> Kembali
                </button>

                <!-- Tertiary: Dashboard Quick Link -->
                <a href="<?= $toRoot ?>dashboard/index.php"
                   class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-5 py-3 text-xs sm:text-sm font-bold text-slate-200 transition">
                    <i class="fa-solid fa-gauge text-xs"></i> Dasbor Utama
                </a>

            </div>

        </div>

    </main>

    <!-- Footer -->
    <footer class="relative z-10 border-t border-white/5 bg-slate-950/80 py-6 text-center text-xs text-slate-500">
        <div class="mx-auto max-w-6xl px-6 flex flex-col sm:flex-row items-center justify-between gap-4">
            <p>© <?= date('Y') ?> Manajemen-PHP — Sistem Terpadu Manajemen Sekolah 5 Role.</p>
            <div class="flex items-center gap-4">
                <a href="<?= $toRoot ?>auth/login.php" class="hover:text-slate-300 transition">Login Akun</a>
                <span>•</span>
                <a href="<?= $toRoot ?>dashboard/index.php" class="hover:text-slate-300 transition">Dashboard</a>
                <span>•</span>
                <a href="<?= $toRoot ?>kontak.php" class="hover:text-slate-300 transition">Kontak Bantuan</a>
                <span>•</span>
                <span class="text-slate-600">ID Sesi: <?= substr(session_id() ?: 'guest', 0, 8) ?></span>
            </div>
        </div>
    </footer>

</body>
</html>
<?php
}
