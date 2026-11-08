<?php
/**
 * Definisi Data & Metadata Halaman Error Komprehensif
 * Manajemen-PHP — Sistem Terpadu Manajemen Sekolah
 * 
 * Menyediakan konfigurasi lengkap, arketipe visual, palet warna tematik,
 * dan panduan resolusi kontekstual untuk setiap kode status HTTP (4xx & 5xx).
 */

// Cegah pemanggilan berkas secara langsung demi keamanan
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    exit('Akses langsung ke berkas ini dibatasi demi keamanan.');
}

function getErrorDetails(int $code): array {
    $errors = [
        // ==========================================
        // 404 - Not Found (Arketipe: Cosmic Void)
        // ==========================================
        404 => [
            'code'              => 404,
            'status'            => 'Not Found',
            'theme'             => 'cosmic',
            'category'          => 'Navigasi & Pencarian',
            'badge'             => 'HTTP 404 • Not Found',
            'icon'              => '<i class="fa-solid fa-compass text-purple-400"></i>',
            'accent'            => 'purple',
            'glow_color'        => 'rgba(168, 85, 247, 0.22)',
            'secondary_glow'    => 'rgba(99, 102, 241, 0.18)',
            'border_color'      => 'border-purple-500/30',
            'badge_bg'          => 'bg-purple-500/10 border-purple-500/30 text-purple-300',
            'btn_bg'            => 'bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white shadow-purple-500/25',
            'title'             => 'Halaman Tidak Ditemukan',
            'subtitle'          => 'Koordinat rute yang Anda tuju melayang di luar orbit sistem sekolah.',
            'description'       => 'Halaman, berkas, atau modul yang Anda cari mungkin telah dipindahkan ke tautan baru, dinonaktifkan oleh administrator, atau terjadi salah ketik pada alamat URL peramban Anda.',
            'suggestions'       => [
                'Periksa kembali ejaan URL pada bilah alamat peramban Anda.',
                'Gunakan kolom pencarian cepat di bawah untuk menemukan modul atau fitur sekolah.',
                'Klik salah satu tautan cepat halaman terpopuler atau kembali ke beranda.',
            ],
            'primary_btn'       => ['label' => 'Jelajahi Beranda', 'href' => 'index.php', 'icon' => '<i class="fa-solid fa-house"></i>'],
            'popular_links'     => [
                ['label' => 'Berita & Pengumuman', 'href' => 'berita.php', 'icon' => 'fa-solid fa-newspaper'],
                ['label' => 'Portal PPDB Online', 'href' => 'ppdb/ppdb.php', 'icon' => 'fa-solid fa-user-graduate'],
                ['label' => 'Perpustakaan Digital', 'href' => 'perpustakaan.php', 'icon' => 'fa-solid fa-book-open'],
                ['label' => 'Pusat Informasi', 'href' => 'informasi.php', 'icon' => 'fa-solid fa-circle-info'],
                ['label' => 'Kontak & Bantuan', 'href' => 'kontak.php', 'icon' => 'fa-solid fa-headset'],
            ],
        ],

        // ==========================================
        // 403 - Forbidden (Arketipe: Security Clearance Vault)
        // ==========================================
        403 => [
            'code'              => 403,
            'status'            => 'Forbidden',
            'theme'             => 'security',
            'category'          => 'Keamanan & Izin Akses',
            'badge'             => 'HTTP 403 • Access Restricted',
            'icon'              => '<i class="fa-solid fa-shield-halved text-rose-400"></i>',
            'accent'            => 'rose',
            'glow_color'        => 'rgba(244, 63, 94, 0.22)',
            'secondary_glow'    => 'rgba(225, 29, 72, 0.15)',
            'border_color'      => 'border-rose-500/30',
            'badge_bg'          => 'bg-rose-500/10 border-rose-500/30 text-rose-300',
            'btn_bg'            => 'bg-gradient-to-r from-rose-600 to-pink-600 hover:from-rose-500 hover:to-pink-500 text-white shadow-rose-500/25',
            'title'             => 'Akses Ditolak — Otoritas Terbatas',
            'subtitle'          => 'Akun Anda tidak memiliki hak izin atau wewenang untuk membuka modul ini.',
            'description'       => 'Sistem Manajemen-PHP menerapkan kontrol akses berbasis peran (Role-Based Access Control / RBAC). Area ini dikhususkan bagi Administrator, Kepala Sekolah, atau Staf Berwenang untuk melindungi integritas berkas dan data privasi sekolah.',
            'suggestions'       => [
                'Pastikan Anda masuk (login) menggunakan akun dengan hak akses yang berwenang.',
                'Jika Anda adalah staf/guru pengampu yang memerlukan akses, ajukan izin ke Administrator IT.',
                'Gunakan tombol di bawah untuk berganti akun atau kembali ke dasbor sesuai peran Anda.',
            ],
            'primary_btn'       => ['label' => 'Kembali ke Dasbor', 'href' => 'dashboard/index.php', 'icon' => '<i class="fa-solid fa-gauge-high"></i>'],
            'switch_account_btn'=> ['label' => 'Ganti Akun Lain', 'href' => 'auth/login.php', 'icon' => '<i class="fa-solid fa-user-lock"></i>'],
        ],

        // ==========================================
        // 500 - Internal Server Error (Arketipe: Telemetry Crash)
        // ==========================================
        500 => [
            'code'              => 500,
            'status'            => 'Internal Server Error',
            'theme'             => 'server_crash',
            'category'          => 'Infrastruktur Sistem',
            'badge'             => 'HTTP 500 • Internal Crash',
            'icon'              => '<i class="fa-solid fa-triangle-exclamation text-amber-500"></i>',
            'accent'            => 'amber',
            'glow_color'        => 'rgba(245, 158, 11, 0.22)',
            'secondary_glow'    => 'rgba(239, 68, 68, 0.18)',
            'border_color'      => 'border-amber-500/30',
            'badge_bg'          => 'bg-amber-500/10 border-amber-500/30 text-amber-300',
            'btn_bg'            => 'bg-gradient-to-r from-amber-500 to-red-600 hover:from-amber-400 hover:to-red-500 text-slate-950 font-bold shadow-amber-500/25',
            'title'             => 'Gangguan Tak Terduga di Server',
            'subtitle'          => 'Sistem mendeteksi kendala internal pada thread eksekusi skrip backend.',
            'description'       => 'Server aplikasi mengalami anomali saat memproses permintaan Anda. Demi keamanan, detail trace teknis tidak dipublikasikan ke publik. Sistem telah mencatat kode tiket insiden ini ke log audit keamanan.',
            'suggestions'       => [
                'Muat ulang halaman dalam beberapa detik; gangguan sesaat sering kali pulih secara otomatis.',
                'Salin Kode Tiket Insiden di bawah dan laporkan ke tim teknis IT sekolah jika kendala berlanjut.',
                'Periksa apakah database MySQL atau service web server berjalan normal.',
            ],
            'primary_btn'       => ['label' => 'Muat Ulang Halaman', 'action' => 'reload', 'icon' => '<i class="fa-solid fa-rotate-right"></i>'],
            'diagnostic_mode'   => true,
        ],

        // ==========================================
        // 503 - Service Unavailable (Arketipe: Precision Maintenance)
        // ==========================================
        503 => [
            'code'              => 503,
            'status'            => 'Service Unavailable',
            'theme'             => 'maintenance',
            'category'          => 'Pemeliharaan & Peningkatan',
            'badge'             => 'HTTP 503 • Under Maintenance',
            'icon'              => '<i class="fa-solid fa-screwdriver-wrench text-emerald-400"></i>',
            'accent'            => 'emerald',
            'glow_color'        => 'rgba(16, 185, 129, 0.22)',
            'secondary_glow'    => 'rgba(6, 182, 212, 0.18)',
            'border_color'      => 'border-emerald-500/30',
            'badge_bg'          => 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300',
            'btn_bg'            => 'bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white shadow-emerald-500/25',
            'title'             => 'Pemeliharaan Sistem Berkala',
            'subtitle'          => 'Sistem Manajemen-PHP sedang dalam proses optimalisasi performa & keamanan.',
            'description'       => 'Tim teknis kami sedang memperbarui basis data dan meningkatkan kapasitas server sekolah. Seluruh data akademik, nilai siswa, dan berkas pendaftaran Anda tersimpan aman dan terenkripsi.',
            'suggestions'       => [
                'Halaman ini akan memeriksa ketersediaan sistem secara otomatis setiap beberapa detik.',
                'Hindari pengiriman formulir massal saat proses pemeliharaan sedang berlangsung.',
                'Untuk keperluan darurat administrasi, silakan hubungi narahubung tata usaha sekolah.',
            ],
            'primary_btn'       => ['label' => 'Periksa Ulang Sekarang', 'action' => 'reload', 'icon' => '<i class="fa-solid fa-arrows-rotate"></i>'],
            'auto_refresh'      => 30, // seconds
        ],

        // ==========================================
        // 401 - Unauthorized (Arketipe: Identity Gateway)
        // ==========================================
        401 => [
            'code'              => 401,
            'status'            => 'Unauthorized',
            'theme'             => 'auth_gate',
            'category'          => 'Autentikasi Pengguna',
            'badge'             => 'HTTP 401 • Authentication Required',
            'icon'              => '<i class="fa-solid fa-id-badge text-sky-400"></i>',
            'accent'            => 'sky',
            'glow_color'        => 'rgba(14, 165, 233, 0.22)',
            'secondary_glow'    => 'rgba(59, 130, 246, 0.18)',
            'border_color'      => 'border-sky-500/30',
            'badge_bg'          => 'bg-sky-500/10 border-sky-500/30 text-sky-300',
            'btn_bg'            => 'bg-gradient-to-r from-sky-500 to-blue-600 hover:from-sky-400 hover:to-blue-500 text-white shadow-sky-500/25',
            'title'             => 'Identifikasi Pengguna Diperlukan',
            'subtitle'          => 'Silakan masuk ke portal akun untuk memvalidasi identitas civitas sekolah Anda.',
            'description'       => 'Halaman atau modul yang Anda tuju merupakan area terproteksi khusus guru, siswa, orang tua, staf tata usaha, atau pimpinan sekolah. Token sesi Anda belum terdeteksi atau telah usai.',
            'suggestions'       => [
                'Klik tombol Masuk Akun di bawah untuk mengisi kredensial NISN/NIP dan kata sandi Anda.',
                'Jika sesi Anda sebelumnya telah habis karena tidak aktif, silakan login ulang.',
                'Hubungi bagian tata usaha jika Anda lupa kata sandi atau akun belum diaktivasi.',
            ],
            'primary_btn'       => ['label' => 'Masuk ke Portal Akun', 'href' => 'auth/login.php', 'icon' => '<i class="fa-solid fa-key"></i>'],
            'roles_pills'       => ['Siswa & Wali Murid', 'Dewan Guru', 'Tenaga Kependidikan', 'Administrator'],
        ],

        // ==========================================
        // 429 - Too Many Requests (Arketipe: Tachometer Cooldown)
        // ==========================================
        429 => [
            'code'              => 429,
            'status'            => 'Too Many Requests',
            'theme'             => 'cooldown',
            'category'          => 'Proteksi Lalu Lintas & Rate Limit',
            'badge'             => 'HTTP 429 • Rate Limit Triggered',
            'icon'              => '<i class="fa-solid fa-gauge-simple-high text-rose-500"></i>',
            'accent'            => 'rose',
            'glow_color'        => 'rgba(244, 63, 94, 0.22)',
            'secondary_glow'    => 'rgba(249, 115, 22, 0.18)',
            'border_color'      => 'border-rose-500/30',
            'badge_bg'          => 'bg-rose-500/10 border-rose-500/30 text-rose-300',
            'btn_bg'            => 'bg-gradient-to-r from-rose-600 to-orange-600 hover:from-rose-500 hover:to-orange-500 text-white shadow-rose-500/25',
            'title'             => 'Frekuensi Permintaan Melampaui Batas',
            'subtitle'          => 'Sistem pelindung mendeteksi aktivitas berulang yang terlalu cepat dari perangkat Anda.',
            'description'       => 'Sistem firewall Manajemen-PHP membatasi frekuensi aksi per detik guna mencegah spam formulir, penyerangan brute-force login, dan overload server agar seluruh pengguna mendapatkan performa yang stabil.',
            'suggestions'       => [
                'Harap beri jeda sejenak dan tunggu hingga hitung mundur di bawah selesai.',
                'Hindari menekan tombol formulir secara berulang-ulang dengan cepat.',
                'Pastikan tidak ada ekstensi peramban otomatis atau bot scraper yang sedang aktif.',
            ],
            'primary_btn'       => ['label' => 'Coba Muat Ulang', 'action' => 'reload', 'icon' => '<i class="fa-solid fa-rotate-right"></i>'],
            'cooldown_seconds'  => 60,
        ],

        // ==========================================
        // 419 - Page Expired (Arketipe: Temporal Hourglass)
        // ==========================================
        419 => [
            'code'              => 419,
            'status'            => 'Page Expired',
            'theme'             => 'hourglass',
            'category'          => 'Keamanan Sesi & CSRF',
            'badge'             => 'HTTP 419 • Security Token Expired',
            'icon'              => '<i class="fa-solid fa-hourglass-half text-amber-400"></i>',
            'accent'            => 'amber',
            'glow_color'        => 'rgba(245, 158, 11, 0.22)',
            'secondary_glow'    => 'rgba(168, 85, 247, 0.18)',
            'border_color'      => 'border-amber-500/30',
            'badge_bg'          => 'bg-amber-500/10 border-amber-500/30 text-amber-300',
            'btn_bg'            => 'bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-slate-950 font-bold shadow-amber-500/25',
            'title'             => 'Masa Aktif Formulir Telah Habis',
            'subtitle'          => 'Token keamanan CSRF kadaluarsa karena halaman dibiarkan terlalu lama tanpa aksi.',
            'description'       => 'Untuk melindungi transaksi dan akun sekolah Anda dari serangan Cross-Site Request Forgery (CSRF), token keamanan otomatis dihanguskan setelah beberapa menit tanpa aktivitas guna memastikan keamanan data Anda.',
            'suggestions'       => [
                'Klik tombol Segarkan Sesi di bawah untuk mendapatkan token keamanan yang baru.',
                'Jika Anda sedang mengisi formulir panjang, simpan draft secara berkala.',
                'Pastikan fitur cookie pada peramban Anda diizinkan untuk domain sekolah ini.',
            ],
            'primary_btn'       => ['label' => 'Segarkan Sesi & Muat Ulang', 'action' => 'reload', 'icon' => '<i class="fa-solid fa-arrows-rotate"></i>'],
        ],

        // ==========================================
        // 402 - Payment Required (Arketipe: Tuition & Billing Ledger)
        // ==========================================
        402 => [
            'code'              => 402,
            'status'            => 'Payment Required',
            'theme'             => 'financial',
            'category'          => 'Administrasi Keuangan',
            'badge'             => 'HTTP 402 • Payment Required',
            'icon'              => '<i class="fa-solid fa-file-invoice-dollar text-emerald-400"></i>',
            'accent'            => 'emerald',
            'glow_color'        => 'rgba(16, 185, 129, 0.22)',
            'secondary_glow'    => 'rgba(234, 179, 8, 0.18)',
            'border_color'      => 'border-emerald-500/30',
            'badge_bg'          => 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300',
            'btn_bg'            => 'bg-gradient-to-r from-emerald-600 to-green-600 hover:from-emerald-500 hover:to-green-500 text-white shadow-emerald-500/25',
            'title'             => 'Penyelesaian Administrasi Diperlukan',
            'subtitle'          => 'Akses modul ini memerlukan konfirmasi atau pelunasan tagihan administrasi sekolah.',
            'description'       => 'Modul kartu ujian, pengunduhan rapor digital, atau formulir kelulusan ini mensyaratkan status verifikasi pembayaran biaya administrasi/PPDB yang telah divalidasi oleh bendahara sekolah.',
            'suggestions'       => [
                'Periksa status tagihan Anda pada modul Informasi Keuangan di dasbor siswa.',
                'Unggah bukti transfer pembayaran atau hubungi langsung staf bendahara sekolah.',
                'Jika pembayaran telah lunas, mohon tunggu hingga bendahara memverifikasi transaksi.',
            ],
            'primary_btn'       => ['label' => 'Buka Modul Keuangan', 'href' => 'dashboard/index.php', 'icon' => '<i class="fa-solid fa-wallet"></i>'],
            'contact_btn'       => ['label' => 'Hubungi Bendahara', 'href' => 'kontak.php', 'icon' => '<i class="fa-solid fa-headset"></i>'],
        ],

        // ==========================================
        // 408 - Request Timeout (Arketipe: Latency Wave)
        // ==========================================
        408 => [
            'code'              => 408,
            'status'            => 'Request Timeout',
            'theme'             => 'timeout',
            'category'          => 'Konektivitas Jaringan',
            'badge'             => 'HTTP 408 • Request Timeout',
            'icon'              => '<i class="fa-solid fa-wifi text-yellow-400"></i>',
            'accent'            => 'yellow',
            'glow_color'        => 'rgba(234, 179, 8, 0.22)',
            'secondary_glow'    => 'rgba(59, 130, 246, 0.18)',
            'border_color'      => 'border-yellow-500/30',
            'badge_bg'          => 'bg-yellow-500/10 border-yellow-500/30 text-yellow-300',
            'btn_bg'            => 'bg-gradient-to-r from-yellow-500 to-amber-500 hover:from-yellow-400 hover:to-amber-400 text-slate-950 font-bold shadow-yellow-500/25',
            'title'             => 'Batas Waktu Pengiriman Berakhir',
            'subtitle'          => 'Server tidak menerima permintaan lengkap dari peramban Anda dalam waktu yang ditentukan.',
            'description'       => 'Koneksi internet Anda mungkin mengalami fluktuasi latensi atau pengunggahan berkas terhenti di tengah jalan. Server sekolah menutup koneksi demi menghemat alokasi sumber daya sistem.',
            'suggestions'       => [
                'Gunakan alat tes koneksi di bawah untuk memeriksa stabilitas internet Anda.',
                'Jika sedang mengunggah dokumen/foto, pastikan ukuran berkas tidak terlalu besar.',
                'Coba hubungkan perangkat Anda ke jaringan Wi-Fi yang lebih stabil lalu muat ulang.',
            ],
            'primary_btn'       => ['label' => 'Coba Kirim Ulang', 'action' => 'reload', 'icon' => '<i class="fa-solid fa-rotate-right"></i>'],
            'ping_tool'         => true,
        ],

        // ==========================================
        // 504 - Gateway Timeout (Arketipe: Latency Wave)
        // ==========================================
        504 => [
            'code'              => 504,
            'status'            => 'Gateway Timeout',
            'theme'             => 'timeout',
            'category'          => 'Konektivitas Backend & Gateway',
            'badge'             => 'HTTP 504 • Gateway Timeout',
            'icon'              => '<i class="fa-solid fa-server text-orange-400"></i>',
            'accent'            => 'orange',
            'glow_color'        => 'rgba(249, 115, 22, 0.22)',
            'secondary_glow'    => 'rgba(239, 68, 68, 0.18)',
            'border_color'      => 'border-orange-500/30',
            'badge_bg'          => 'bg-orange-500/10 border-orange-500/30 text-orange-300',
            'btn_bg'            => 'bg-gradient-to-r from-orange-500 to-rose-600 hover:from-orange-400 hover:to-rose-500 text-white shadow-orange-500/25',
            'title'             => 'Batas Waktu Gateway Terlampaui',
            'subtitle'          => 'Web server proxy menunggu terlalu lama respons dari backend PHP atau basis data.',
            'description'       => 'Server proxy bertindak sebagai jembatan dan mengalami batas waktu saat menanti kalkulasi query rumit, kompilasi data tahunan berukuran besar, atau service database yang sedang padat.',
            'suggestions'       => [
                'Bila mengunduh rekap laporan besar, persempit rentang tanggal atau filter kelas.',
                'Tunggu sejenak lalu muat ulang halaman saat antrean pemrosesan server mereda.',
                'Hubungi teknisi IT sekolah jika pemrosesan terus menerus mengalami timeout.',
            ],
            'primary_btn'       => ['label' => 'Muat Ulang Halaman', 'action' => 'reload', 'icon' => '<i class="fa-solid fa-rotate-right"></i>'],
            'ping_tool'         => true,
        ],

        // ==========================================
        // 400 - Bad Request (Arketipe: Syntax Glitch)
        // ==========================================
        400 => [
            'code'              => 400,
            'status'            => 'Bad Request',
            'theme'             => 'syntax_glitch',
            'category'          => 'Validasi Sintaks Permintaan',
            'badge'             => 'HTTP 400 • Bad Request',
            'icon'              => '<i class="fa-solid fa-code text-amber-400"></i>',
            'accent'            => 'amber',
            'glow_color'        => 'rgba(245, 158, 11, 0.22)',
            'secondary_glow'    => 'rgba(217, 119, 6, 0.18)',
            'border_color'      => 'border-amber-500/30',
            'badge_bg'          => 'bg-amber-500/10 border-amber-500/30 text-amber-300',
            'btn_bg'            => 'bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-400 hover:to-orange-400 text-slate-950 font-bold shadow-amber-500/25',
            'title'             => 'Permintaan Tidak Dapat Diproses',
            'subtitle'          => 'Format data atau parameter query yang dikirim tidak valid atau telah dimodifikasi.',
            'description'       => 'Server web menolak memproses paket permintaan karena sintaks atau parameter yang dikirim tidak sesuai dengan kontrak data yang diharapkan oleh sistem manajemen sekolah.',
            'suggestions'       => [
                'Periksa kembali parameter tautan atau isian form yang Anda kirimkan.',
                'Bersihkan cache peramban atau gunakan tombol kembali ke halaman sebelumnya.',
                'Hindari menyisipkan karakter terlarang atau query injeksi pada URL.',
            ],
            'primary_btn'       => ['label' => 'Kembali ke Sebelumnya', 'action' => 'back', 'icon' => '<i class="fa-solid fa-arrow-left"></i>'],
        ],

        // ==========================================
        // 405 - Method Not Allowed (Arketipe: Method Blocked)
        // ==========================================
        405 => [
            'code'              => 405,
            'status'            => 'Method Not Allowed',
            'theme'             => 'method_blocked',
            'category'          => 'Protokol & Metode HTTP',
            'badge'             => 'HTTP 405 • Method Disallowed',
            'icon'              => '<i class="fa-solid fa-ban text-rose-400"></i>',
            'accent'            => 'rose',
            'glow_color'        => 'rgba(244, 63, 94, 0.22)',
            'secondary_glow'    => 'rgba(239, 68, 68, 0.18)',
            'border_color'      => 'border-rose-500/30',
            'badge_bg'          => 'bg-rose-500/10 border-rose-500/30 text-rose-300',
            'btn_bg'            => 'bg-gradient-to-r from-rose-600 to-red-600 hover:from-rose-500 hover:to-red-500 text-white shadow-rose-500/25',
            'title'             => 'Metode Pengiriman Tidak Diizinkan',
            'subtitle'          => 'Metode HTTP yang dipakai tidak didukung oleh rute pemrosesan ini.',
            'description'       => 'Permintaan Anda menggunakan metode (seperti membuka URL aksi POST secara langsung dengan metode GET) yang dilarang demi menjaga keamanan dan keaslian transaksi data sekolah.',
            'suggestions'       => [
                'Kirimkan data melalui tombol formulir resmi yang ada di halaman aplikasi sekolah.',
                'Jangan menyalin atau membuka alamat pemrosesan (action script) secara langsung di peramban.',
                'Gunakan tombol kembali untuk mengulang pengisian formulir dengan benar.',
            ],
            'primary_btn'       => ['label' => 'Kembali ke Formulir', 'action' => 'back', 'icon' => '<i class="fa-solid fa-arrow-left"></i>'],
        ],

        // ==========================================
        // 409 - Conflict (Arketipe: Data Conflict)
        // ==========================================
        409 => [
            'code'              => 409,
            'status'            => 'Conflict',
            'theme'             => 'conflict',
            'category'          => 'Integritas & Duplikasi Data',
            'badge'             => 'HTTP 409 • Data Conflict',
            'icon'              => '<i class="fa-solid fa-code-compare text-orange-400"></i>',
            'accent'            => 'orange',
            'glow_color'        => 'rgba(249, 115, 22, 0.22)',
            'secondary_glow'    => 'rgba(234, 179, 8, 0.18)',
            'border_color'      => 'border-orange-500/30',
            'badge_bg'          => 'bg-orange-500/10 border-orange-500/30 text-orange-300',
            'btn_bg'            => 'bg-gradient-to-r from-orange-500 to-amber-600 hover:from-orange-400 hover:to-amber-500 text-slate-950 font-bold shadow-orange-500/25',
            'title'             => 'Bentrokan Data Terdeteksi',
            'subtitle'          => 'Data yang ingin disimpan bertabrakan dengan data unik yang telah ada di server.',
            'description'       => 'Server mendeteksi konflik data, seperti nomor identitas unik (NISN, NIP, atau Email) yang sudah terdaftar sebelumnya, atau catatan sedang diperbarui bersamaan oleh pengguna lain.',
            'suggestions'       => [
                'Pastikan data unik seperti NISN, NIP, atau Email belum pernah digunakan sebelumnya.',
                'Muat ulang data tabel untuk memastikan Anda menyunting versi data paling teranyar.',
                'Periksa apakah ada bentrok pada alokasi ruangan kelas atau jadwal mengajar.',
            ],
            'primary_btn'       => ['label' => 'Periksa Kembali Isian', 'action' => 'back', 'icon' => '<i class="fa-solid fa-arrow-left"></i>'],
        ],

        // ==========================================
        // 410 - Gone (Arketipe: Historical Archive)
        // ==========================================
        410 => [
            'code'              => 410,
            'status'            => 'Gone',
            'theme'             => 'archived',
            'category'          => 'Arsip & Siklus Hidup Dokumen',
            'badge'             => 'HTTP 410 • Permanently Archived',
            'icon'              => '<i class="fa-solid fa-box-archive text-slate-400"></i>',
            'accent'            => 'slate',
            'glow_color'        => 'rgba(148, 163, 184, 0.20)',
            'secondary_glow'    => 'rgba(100, 116, 139, 0.15)',
            'border_color'      => 'border-slate-500/30',
            'badge_bg'          => 'bg-slate-500/10 border-slate-500/30 text-slate-300',
            'btn_bg'            => 'bg-gradient-to-r from-slate-700 to-slate-800 hover:from-slate-600 hover:to-slate-700 text-white shadow-slate-700/25',
            'title'             => 'Sumber Daya Tidak Lagi Tersedia',
            'subtitle'          => 'Dokumen atau pengumuman yang Anda tuju telah ditutup atau diarsipkan permanen.',
            'description'       => 'Halaman ini pernah aktif di portal sekolah, namun masa berlakunya telah usai, seperti batas akhir gelombang PPDB tahun ajaran lampau atau pengumuman beasiswa yang telah resmi ditutup.',
            'suggestions'       => [
                'Dokumen ini tidak lagi diedarkan secara aktif oleh pihak sekolah.',
                'Kunjungi pusat berita sekolah untuk memantau pengumuman periode terbaru.',
                'Kembali ke halaman beranda untuk mengakses layanan administrasi aktif.',
            ],
            'primary_btn'       => ['label' => 'Menuju Beranda Utama', 'href' => 'index.php', 'icon' => '<i class="fa-solid fa-house"></i>'],
        ],

        // ==========================================
        // 413 - Payload Too Large (Arketipe: Heavy Payload)
        // ==========================================
        413 => [
            'code'              => 413,
            'status'            => 'Payload Too Large',
            'theme'             => 'payload',
            'category'          => 'Kapasitas Unggahan Berkas',
            'badge'             => 'HTTP 413 • Capacity Limit Exceeded',
            'icon'              => '<i class="fa-solid fa-weight-hanging text-amber-500"></i>',
            'accent'            => 'amber',
            'glow_color'        => 'rgba(245, 158, 11, 0.22)',
            'secondary_glow'    => 'rgba(239, 68, 68, 0.18)',
            'border_color'      => 'border-amber-500/30',
            'badge_bg'          => 'bg-amber-500/10 border-amber-500/30 text-amber-300',
            'btn_bg'            => 'bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-400 hover:to-orange-500 text-slate-950 font-bold shadow-amber-500/25',
            'title'             => 'Ukuran Berkas Terlalu Besar',
            'subtitle'          => 'Berkas yang Anda unggah melampaui batas maksimal kapasitas yang diizinkan.',
            'description'       => 'Server menolak berkas (dokumen scan rapor, foto siswa, atau arsip materi) karena melebihi batas kuota maksimal per unggahan (standar maksimal 2MB - 5MB per berkas).',
            'suggestions'       => [
                'Kompres ukuran berkas (PDF atau JPEG) melalui aplikasi kompresi berkas.',
                'Pastikan resolusi foto atau scan dokumen tidak melebihi kapasitas standar.',
                'Bila mengunggah banyak berkas secara serentak, lakukan pengunggahan bertahap.',
            ],
            'primary_btn'       => ['label' => 'Pilih Ulang Berkas', 'action' => 'back', 'icon' => '<i class="fa-solid fa-arrow-left"></i>'],
        ],

        // ==========================================
        // 414 - URI Too Long (Arketipe: URI Overflow)
        // ==========================================
        414 => [
            'code'              => 414,
            'status'            => 'URI Too Long',
            'theme'             => 'uri',
            'category'          => 'Spesifikasi URL & Query',
            'badge'             => 'HTTP 414 • URI Length Overflow',
            'icon'              => '<i class="fa-solid fa-link-slash text-rose-400"></i>',
            'accent'            => 'rose',
            'glow_color'        => 'rgba(244, 63, 94, 0.22)',
            'secondary_glow'    => 'rgba(168, 85, 247, 0.18)',
            'border_color'      => 'border-rose-500/30',
            'badge_bg'          => 'bg-rose-500/10 border-rose-500/30 text-rose-300',
            'btn_bg'            => 'bg-gradient-to-r from-rose-600 to-purple-600 hover:from-rose-500 hover:to-purple-500 text-white shadow-rose-500/25',
            'title'             => 'Alamat Tautan Terlalu Panjang',
            'subtitle'          => 'Panjang URL melebihi kapasitas karakter aman yang dapat diproses server.',
            'description'       => 'Tautan yang dikirim mengandung parameter query yang sangat panjang. Ini umumnya terjadi jika data formulir dalam jumlah besar dikirim dengan metode GET alih-alih metode POST yang aman.',
            'suggestions'       => [
                'Gunakan formulir resmi dengan metode POST untuk transmisi data berukuran besar.',
                'Bersihkan parameter query URL yang berulang atau tidak diperlukan.',
                'Kembali ke halaman beranda untuk mengakses menu secara normal.',
            ],
            'primary_btn'       => ['label' => 'Kembali ke Beranda', 'href' => 'index.php', 'icon' => '<i class="fa-solid fa-house"></i>'],
        ],

        // ==========================================
        // 415 - Unsupported Media Type (Arketipe: Media Filter)
        // ==========================================
        415 => [
            'code'              => 415,
            'status'            => 'Unsupported Media Type',
            'theme'             => 'media_type',
            'category'          => 'Validasi Tipe Berkas',
            'badge'             => 'HTTP 415 • Invalid Media Type',
            'icon'              => '<i class="fa-solid fa-file-shield text-violet-400"></i>',
            'accent'            => 'violet',
            'glow_color'        => 'rgba(139, 92, 246, 0.22)',
            'secondary_glow'    => 'rgba(99, 102, 241, 0.18)',
            'border_color'      => 'border-violet-500/30',
            'badge_bg'          => 'bg-violet-500/10 border-violet-500/30 text-violet-300',
            'btn_bg'            => 'bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white shadow-violet-500/25',
            'title'             => 'Format Berkas Tidak Didukung',
            'subtitle'          => 'Ekstensi atau tipe MIME berkas ditolak oleh kebijakan keamanan sistem.',
            'description'       => 'Untuk menjaga integritas dan keamanan server dari virus atau skrip berbahaya, sistem hanya menerima ekstensi berkas resmi yang diizinkan (seperti PDF, JPG, PNG, DOCX, XLSX).',
            'suggestions'       => [
                'Pastikan hanya mengunggah berkas berformat standar yang diperbolehkan.',
                'Dilarang keras mengunggah berkas berekstensi eksekusi (.php, .exe, .sh, .bat, .js).',
                'Konversikan berkas Anda ke format PDF atau gambar standar sebelum diunggah.',
            ],
            'primary_btn'       => ['label' => 'Pilih Format yang Sesuai', 'action' => 'back', 'icon' => '<i class="fa-solid fa-arrow-left"></i>'],
            'allowed_formats'   => ['PDF', 'JPG / JPEG', 'PNG', 'DOCX', 'XLSX'],
        ],

        // ==========================================
        // 422 - Unprocessable Entity (Arketipe: Field Validation)
        // ==========================================
        422 => [
            'code'              => 422,
            'status'            => 'Unprocessable Entity',
            'theme'             => 'validation',
            'category'          => 'Validasi Isian Formulir',
            'badge'             => 'HTTP 422 • Semantic Validation Failed',
            'icon'              => '<i class="fa-solid fa-clipboard-question text-teal-400"></i>',
            'accent'            => 'teal',
            'glow_color'        => 'rgba(20, 184, 166, 0.22)',
            'secondary_glow'    => 'rgba(56, 189, 248, 0.18)',
            'border_color'      => 'border-teal-500/30',
            'badge_bg'          => 'bg-teal-500/10 border-teal-500/30 text-teal-300',
            'btn_bg'            => 'bg-gradient-to-r from-teal-500 to-cyan-600 hover:from-teal-400 hover:to-cyan-500 text-white shadow-teal-500/25',
            'title'             => 'Data Formulir Tidak Memenuhi Syarat',
            'subtitle'          => 'Sintaks permintaan valid, namun nilai data tidak memenuhi kaidah bisnis sekolah.',
            'description'       => 'Server memahami permintaan Anda, namun tidak dapat menyelesaikannya karena terdapat data yang belum memenuhi kriteria validasi (contoh: nomor telepon kurang digit, tanggal lahir tidak logis, atau kolom wajib belum terisi).',
            'suggestions'       => [
                'Periksa kembali pesan kesalahan warna merah pada formulir terkait.',
                'Pastikan data angka, nomor identitas, dan format email telah diisi dengan benar.',
                'Lengkapi seluruh kolom bertanda bintang (*) sebelum menekan tombol simpan.',
            ],
            'primary_btn'       => ['label' => 'Kembali & Lengkapi Data', 'action' => 'back', 'icon' => '<i class="fa-solid fa-arrow-left"></i>'],
        ],

        // ==========================================
        // 502 - Bad Gateway (Arketipe: Disconnected Nodes)
        // ==========================================
        502 => [
            'code'              => 502,
            'status'            => 'Bad Gateway',
            'theme'             => 'bad_gateway',
            'category'          => 'Jembatan & Proksi Gateway',
            'badge'             => 'HTTP 502 • Bad Gateway Response',
            'icon'              => '<i class="fa-solid fa-network-wired text-indigo-400"></i>',
            'accent'            => 'indigo',
            'glow_color'        => 'rgba(99, 102, 241, 0.22)',
            'secondary_glow'    => 'rgba(168, 85, 247, 0.18)',
            'border_color'      => 'border-indigo-500/30',
            'badge_bg'          => 'bg-indigo-500/10 border-indigo-500/30 text-indigo-300',
            'btn_bg'            => 'bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white shadow-indigo-500/25',
            'title'             => 'Respons Gateway Tidak Valid',
            'subtitle'          => 'Server reverse-proxy menerima respons anomali dari backend prosesor aplikasi.',
            'description'       => 'Web server proksi bertindak sebagai gateway perantara dan menerima respons tidak valid dari service aplikasi di baliknya (seperti PHP-FPM atau service database terdistribusi). Kondisi ini umumnya bersifat sementara.',
            'suggestions'       => [
                'Tunggu 5 hingga 10 detik kemudian muat ulang halaman peramban Anda.',
                'Pastikan service web server Apache/Nginx dan engine PHP berjalan seirama.',
                'Bila masalah berlanjut, hubungi tim pengelola infrastruktur server sekolah.',
            ],
            'primary_btn'       => ['label' => 'Periksa Ulang Koneksi', 'action' => 'reload', 'icon' => '<i class="fa-solid fa-rotate-right"></i>'],
        ],
    ];

    return $errors[$code] ?? [
        'code'              => $code,
        'status'            => 'Unknown Error',
        'theme'             => 'generic',
        'category'          => 'Status Galat Tak Dikenal',
        'badge'             => "HTTP {$code} • System Notice",
        'icon'              => '<i class="fa-solid fa-circle-exclamation text-slate-400"></i>',
        'accent'            => 'slate',
        'glow_color'        => 'rgba(148, 163, 184, 0.20)',
        'secondary_glow'    => 'rgba(100, 116, 139, 0.15)',
        'border_color'      => 'border-slate-500/30',
        'badge_bg'          => 'bg-slate-500/10 border-slate-500/30 text-slate-300',
        'btn_bg'            => 'bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white shadow-blue-500/25',
        'title'             => 'Terjadi Kendala Teknis Tak Terduga',
        'subtitle'          => 'Sistem menerima kode status respons HTTP: ' . $code,
        'description'       => 'Permintaan Anda mengalami gangguan yang belum terdaftar secara spesifik. Sistem telah mencatat insiden ini untuk ditindaklanjuti oleh tim IT sekolah.',
        'suggestions'       => [
            'Kembali ke beranda untuk melanjutkan aktivitas Anda di portal sekolah.',
            'Muat ulang halaman untuk mencoba kembali permintaan Anda.',
            'Hubungi pusat bantuan sekolah jika kendala terus berulang.',
        ],
        'primary_btn'       => ['label' => 'Kembali ke Beranda', 'href' => 'index.php', 'icon' => '<i class="fa-solid fa-house"></i>'],
    ];
}
