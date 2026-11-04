<?php
session_start();
require_once __DIR__ . "/../config/database.php";

requireLogin();

$user_id = (int) $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'siswa';
$page_title = "Pusat Instalasi Aplikasi (PWA & APK)";

// Deteksi base protocol & domain saat ini untuk QR Code mobile
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host_server = $_SERVER['HTTP_HOST'];
$base_app_url = $protocol . $host_server . dirname($_SERVER['PHP_SELF']);

require_once __DIR__ . "/includes/header.php";
?>

<div class="space-y-8 max-w-7xl mx-auto pb-12">

    <!-- Hero Banner Header -->
    <div class="relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-br from-slate-900 via-blue-950/40 to-slate-900 p-6 sm:p-10 shadow-2xl">
        <div class="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-blue-600/10 blur-3xl pointer-events-none"></div>
        <div class="absolute -left-16 -bottom-16 h-64 w-64 rounded-full bg-emerald-600/10 blur-3xl pointer-events-none"></div>
        
        <div class="relative z-10 max-w-3xl space-y-4">
            <div class="inline-flex items-center gap-2 rounded-full border border-blue-500/30 bg-blue-500/10 px-3.5 py-1 text-xs font-bold text-blue-400">
                <i class="fa-solid fa-layer-group"></i> 1 Database Terpadu • Multi-Aplikasi Mandiri
            </div>
            <h1 class="text-2xl sm:text-4xl font-extrabold tracking-tight text-white leading-tight">
                Pusat Pemasangan & Unduh <span class="bg-gradient-to-r from-blue-400 via-teal-300 to-emerald-400 bg-clip-text text-transparent">3 Aplikasi Sistem</span>
            </h1>
            <p class="text-sm sm:text-base text-slate-300 leading-relaxed">
                Kini website sekolah dapat diinstal menjadi <strong>3 aplikasi terpisah</strong> di HP (Android/iOS) dan Laptop/PC Anda. Setiap aplikasi berjalan secara mandiri dengan ikon dan tampilan khusus, namun seluruh data tetap terhubung ke satu sistem yang sama secara real-time.
            </p>
        </div>
    </div>

    <!-- 3 Core Applications Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <!-- KARTU 1: CBT UJIAN SEKOLAH -->
        <div class="group relative flex flex-col justify-between rounded-3xl border border-blue-500/30 bg-slate-900/90 p-6 shadow-xl hover:border-blue-500/60 hover:shadow-2xl hover:shadow-blue-500/10 transition-all duration-300">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div class="h-16 w-16 rounded-2xl bg-gradient-to-br from-blue-700 to-cyan-600 p-1 shadow-lg shadow-blue-600/30 group-hover:scale-105 transition-transform duration-200">
                        <img src="./Modul-ujian/icons/icon.svg" alt="CBT Icon" class="h-full w-full object-contain">
                    </div>
                    <span class="rounded-full border border-blue-500/30 bg-blue-500/15 px-3 py-1 text-[11px] font-bold text-blue-300 uppercase tracking-wider">
                        Aplikasi 1
                    </span>
                </div>

                <div>
                    <h3 class="text-xl font-bold text-white flex items-center gap-2">
                        CBT Ujian Sekolah
                    </h3>
                    <p class="text-xs text-slate-400 mt-1 line-clamp-2">
                        Modul Ujian & Latihan Soal berbasis komputer dengan mode layar penuh, anti-curang, dan pengacakan soal.
                    </p>
                </div>

                <div class="space-y-2 rounded-2xl bg-slate-950/60 border border-white/5 p-3.5 text-xs">
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Mode Tampilan:</span>
                        <span class="text-blue-300 font-semibold">Fullscreen (Kiosk)</span>
                    </div>
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Target:</span>
                        <span class="text-slate-200">Siswa & Guru Pengawas</span>
                    </div>
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Sistem:</span>
                        <span class="text-emerald-400 font-semibold">● Terkoneksi Real-time</span>
                    </div>
                </div>
            </div>

            <div class="pt-6 space-y-2">
                <a href="./Modul-ujian/exams.php" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-blue-600/20 transition cursor-pointer">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka & Pasang CBT
                </a>
                <button type="button" onclick="showQrModal('CBT Ujian Sekolah', '<?= $base_app_url ?>/Modul-ujian/exams.php', '#2563eb')" class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                    <i class="fa-solid fa-qrcode"></i> Scan QR Pasang di HP
                </button>
            </div>
        </div>

        <!-- KARTU 2: PRESENSI MANDIRI & QR -->
        <div class="group relative flex flex-col justify-between rounded-3xl border border-emerald-500/30 bg-slate-900/90 p-6 shadow-xl hover:border-emerald-500/60 hover:shadow-2xl hover:shadow-emerald-500/10 transition-all duration-300">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div class="h-16 w-16 rounded-2xl bg-gradient-to-br from-emerald-700 to-teal-600 p-1 shadow-lg shadow-emerald-600/30 group-hover:scale-105 transition-transform duration-200">
                        <img src="./presensi/icons/icon.svg" alt="Presensi Icon" class="h-full w-full object-contain">
                    </div>
                    <span class="rounded-full border border-emerald-500/30 bg-emerald-500/15 px-3 py-1 text-[11px] font-bold text-emerald-300 uppercase tracking-wider">
                        Aplikasi 2
                    </span>
                </div>

                <div>
                    <h3 class="text-xl font-bold text-white flex items-center gap-2">
                        Presensi Siswa & Guru
                    </h3>
                    <p class="text-xs text-slate-400 mt-1 line-clamp-2">
                        Pencatatan kehadiran harian instan berbasis scan kartu pelajar (QR Code) & kartu RFID otomatis.
                    </p>
                </div>

                <div class="space-y-2 rounded-2xl bg-slate-950/60 border border-white/5 p-3.5 text-xs">
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Mode Tampilan:</span>
                        <span class="text-emerald-300 font-semibold">Standalone (App)</span>
                    </div>
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Fitur Kamera:</span>
                        <span class="text-slate-200">Pemindai QR Cepat</span>
                    </div>
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Sistem:</span>
                        <span class="text-emerald-400 font-semibold">● Terkoneksi Real-time</span>
                    </div>
                </div>
            </div>

            <div class="pt-6 space-y-2">
                <a href="./presensi/attendance.php" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-emerald-600/20 transition cursor-pointer">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka & Pasang Presensi
                </a>
                <button type="button" onclick="showQrModal('Presensi Siswa & Guru', '<?= $base_app_url ?>/presensi/attendance.php', '#059669')" class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                    <i class="fa-solid fa-qrcode"></i> Scan QR Pasang di HP
                </button>
            </div>
        </div>

        <!-- KARTU 3: E-PERPUSTAKAAN DIGITAL -->
        <div class="group relative flex flex-col justify-between rounded-3xl border border-amber-500/30 bg-slate-900/90 p-6 shadow-xl hover:border-amber-500/60 hover:shadow-2xl hover:shadow-amber-500/10 transition-all duration-300">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div class="h-16 w-16 rounded-2xl bg-gradient-to-br from-amber-700 to-yellow-600 p-1 shadow-lg shadow-amber-600/30 group-hover:scale-105 transition-transform duration-200">
                        <img src="./perpustakaan/icons/icon.svg" alt="Perpus Icon" class="h-full w-full object-contain">
                    </div>
                    <span class="rounded-full border border-amber-500/30 bg-amber-500/15 px-3 py-1 text-[11px] font-bold text-amber-300 uppercase tracking-wider">
                        Aplikasi 3
                    </span>
                </div>

                <div>
                    <h3 class="text-xl font-bold text-white flex items-center gap-2">
                        E-Perpustakaan Digital
                    </h3>
                    <p class="text-xs text-slate-400 mt-1 line-clamp-2">
                        Pencarian katalog koleksi buku digital, peminjaman kilat via barcode, dan buku tamu perpustakaan.
                    </p>
                </div>

                <div class="space-y-2 rounded-2xl bg-slate-950/60 border border-white/5 p-3.5 text-xs">
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Mode Tampilan:</span>
                        <span class="text-amber-300 font-semibold">Standalone (App)</span>
                    </div>
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Fitur Scanner:</span>
                        <span class="text-slate-200">Barcode Buku & Tamu</span>
                    </div>
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Sistem:</span>
                        <span class="text-emerald-400 font-semibold">● Terkoneksi Real-time</span>
                    </div>
                </div>
            </div>

            <div class="pt-6 space-y-2">
                <a href="./perpustakaan/books.php" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-amber-600 hover:bg-amber-500 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-amber-600/20 transition cursor-pointer">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka & Pasang Perpus
                </a>
                <button type="button" onclick="showQrModal('E-Perpustakaan Digital', '<?= $base_app_url ?>/perpustakaan/books.php', '#d97706')" class="w-full inline-flex items-center justify-center gap-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 px-4 py-2 text-xs font-semibold text-slate-300 transition cursor-pointer">
                    <i class="fa-solid fa-qrcode"></i> Scan QR Pasang di HP
                </button>
            </div>
        </div>

    </div>

    <!-- PANDUAN CARA INSTALASI PWA (DI SEMUA PERANGKAT) -->
    <div class="rounded-3xl border border-white/10 bg-slate-900/80 p-6 sm:p-8 space-y-6">
        <div class="flex items-center gap-3 border-b border-white/10 pb-4">
            <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-500/20 text-blue-400 text-lg">
                <i class="fa-solid fa-circle-question"></i>
            </span>
            <div>
                <h3 class="text-lg font-bold text-white">Cara Memasang (Instal) Aplikasi ke Layar Utama</h3>
                <p class="text-xs text-slate-400">Tidak perlu download file berukuran besar. Aplikasi dipasang langsung dari browser Anda.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-xs text-slate-300">
            <!-- Android -->
            <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-5 space-y-3">
                <div class="flex items-center gap-2 text-emerald-400 font-bold text-sm">
                    <i class="fa-brands fa-android text-base"></i> Android (Google Chrome)
                </div>
                <ol class="list-decimal list-inside space-y-2 leading-relaxed">
                    <li>Buka salah satu modul di atas (CBT, Presensi, atau Perpus).</li>
                    <li>Muncul banner <strong>"Install Aplikasi"</strong> di bagian bawah, atau klik ikon titik tiga (⋮) di kanan atas browser.</li>
                    <li>Pilih menu <strong>"Instal aplikasi"</strong> atau <strong>"Tambahkan ke Layar Utama"</strong>.</li>
                    <li>Ikon aplikasi resmi akan terpasang di menu HP Anda!</li>
                </ol>
            </div>

            <!-- iOS / iPhone -->
            <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-5 space-y-3">
                <div class="flex items-center gap-2 text-blue-400 font-bold text-sm">
                    <i class="fa-brands fa-apple text-base"></i> iPhone / iPad (Safari)
                </div>
                <ol class="list-decimal list-inside space-y-2 leading-relaxed">
                    <li>Buka halaman modul aplikasi menggunakan browser <strong>Safari</strong>.</li>
                    <li>Ketuk ikon <strong>Bagikan / Share</strong> (kotak dengan panah ke atas) di bagian bawah layar.</li>
                    <li>Gulir ke bawah dan pilih <strong>"Tambahkan ke Layar Utama" (Add to Home Screen)</strong>.</li>
                    <li>Ketuk <strong>Tambah (Add)</strong> di pojok kanan atas.</li>
                </ol>
            </div>

            <!-- PC / Laptop -->
            <div class="rounded-2xl border border-white/5 bg-slate-950/60 p-5 space-y-3">
                <div class="flex items-center gap-2 text-cyan-400 font-bold text-sm">
                    <i class="fa-brands fa-windows text-base"></i> Komputer / Laptop (Chrome / Edge)
                </div>
                <ol class="list-decimal list-inside space-y-2 leading-relaxed">
                    <li>Buka halaman aplikasi melalui Google Chrome atau Microsoft Edge.</li>
                    <li>Perhatikan kolom alamat URL di bagian atas, klik ikon <strong>Komputer / Install (⊕)</strong>.</li>
                    <li>Klik tombol <strong>"Instal"</strong> pada jendela konfirmasi.</li>
                    <li>Aplikasi akan langsung terbuka di jendela mandiri tanpa tab browser!</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- JALUR B: PAKET INSTALLER ANDROID (APK WRAPPER) -->
    <div class="rounded-3xl border border-purple-500/30 bg-gradient-to-r from-purple-950/40 via-slate-900 to-slate-900 p-6 sm:p-8 shadow-xl space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-white/10 pb-4">
            <div class="flex items-center gap-3">
                <span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-purple-500/20 border border-purple-500/40 text-purple-400 text-xl">
                    <i class="fa-brands fa-android"></i>
                </span>
                <div>
                    <h3 class="text-lg font-bold text-white flex items-center gap-2">
                        Jalur B: Paket Installer Android (.APK)
                        <span class="rounded-lg bg-purple-500/20 text-purple-300 border border-purple-500/30 px-2 py-0.5 text-[10px] font-extrabold uppercase">Tersedia</span>
                    </h3>
                    <p class="text-xs text-slate-400">File konfigurasi wrapper WebView Android telah disiapkan di folder <code>apps/android/</code> dalam projek Anda.</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 text-xs text-slate-300">
            <div class="space-y-3">
                <h4 class="font-bold text-white text-sm flex items-center gap-2">
                    <i class="fa-solid fa-wand-magic-sparkles text-amber-400"></i> Cara 1: Generate APK Instan via PWABuilder (1 Menit)
                </h4>
                <p class="leading-relaxed text-slate-400">
                    Karena kita sudah membuat file <code>manifest.json</code> lengkap untuk CBT, Presensi, dan Perpustakaan, Anda dapat langsung mengunduh file APK siap pasang tanpa perlu install Android Studio:
                </p>
                <ol class="list-decimal list-inside space-y-1.5 text-slate-300">
                    <li>Buka situs resmi <strong>PWABuilder.com</strong> di browser.</li>
                    <li>Masukkan link URL modul (misal: link CBT Anda).</li>
                    <li>Klik <strong>Package for Android</strong> dan klik <strong>Download APK</strong>.</li>
                    <li>Selesai! File APK resmi siap dibagikan ke siswa/guru.</li>
                </ol>
            </div>

            <div class="space-y-3">
                <h4 class="font-bold text-white text-sm flex items-center gap-2">
                    <i class="fa-solid fa-code text-cyan-400"></i> Cara 2: Source Code Template Android Studio
                </h4>
                <p class="leading-relaxed text-slate-400">
                    Kami telah menyusun template source code native WebView untuk 3 aplikasi di direktori:
                </p>
                <div class="rounded-xl bg-slate-950 p-3 font-mono text-[11px] text-purple-300 border border-white/5 space-y-1">
                    <div>📁 <strong>apps/android/cbt-app/</strong> (Lockdown Kiosk Fullscreen)</div>
                    <div>📁 <strong>apps/android/presensi-app/</strong> (Hardware Camera & QR Acceleration)</div>
                    <div>📁 <strong>apps/android/perpustakaan-app/</strong> (Barcode & File Upload Perms)</div>
                </div>
                <p class="text-slate-400 text-[11px]">
                    Cukup buka folder tersebut di <strong>Android Studio</strong> dan klik <em>Build > Build APK</em>.
                </p>
            </div>
        </div>
    </div>

</div>

<!-- MODAL POPUP QR CODE UNTUK HP -->
<div id="qrModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-md p-4 animate-in fade-in duration-200">
    <div class="max-w-sm w-full rounded-3xl border border-white/20 bg-slate-900 p-6 sm:p-7 shadow-2xl text-center space-y-5">
        <div class="flex items-center justify-between border-b border-white/10 pb-3">
            <h3 id="qrModalTitle" class="text-base font-bold text-white truncate">Scan QR Pasang Aplikasi</h3>
            <button type="button" onclick="closeQrModal()" class="text-slate-400 hover:text-white transition">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <p class="text-xs text-slate-300 leading-relaxed">
            Arahkan kamera smartphone Anda ke kode QR di bawah untuk membuka dan memasang aplikasi ini:
        </p>

        <!-- QR Canvas Container -->
        <div class="flex justify-center p-4 rounded-2xl bg-white shadow-inner">
            <div id="qrCanvas"></div>
        </div>

        <div class="rounded-xl bg-slate-950/80 p-3 text-[11px] text-slate-400 break-all font-mono">
            <span id="qrUrlDisplay"></span>
        </div>

        <button type="button" onclick="closeQrModal()" class="w-full rounded-xl bg-slate-800 hover:bg-slate-700 py-2.5 text-xs font-bold text-white transition">
            Tutup
        </button>
    </div>
</div>

<script>
let qrCodeInstance = null;

function showQrModal(appName, targetUrl, color) {
    document.getElementById('qrModalTitle').innerText = 'Pasang: ' + appName;
    document.getElementById('qrUrlDisplay').innerText = targetUrl;

    const qrContainer = document.getElementById('qrCanvas');
    qrContainer.innerHTML = '';

    qrCodeInstance = new QRCode(qrContainer, {
        text: targetUrl,
        width: 180,
        height: 180,
        colorDark: '#0f172a',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });

    document.getElementById('qrModal').classList.remove('hidden');
}

function closeQrModal() {
    document.getElementById('qrModal').classList.add('hidden');
}
</script>

<?php
require_once __DIR__ . "/includes/footer.php";
?>
