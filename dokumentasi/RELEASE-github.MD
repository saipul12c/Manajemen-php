# ⚡ Manajemen-PHP v1.3.0

> Rilis Pembaruan Keempat — Peningkatan Keamanan Siber, Tata Kelola Data Lembaga Dinamis, & Revolusi Interaktivitas Publik: Integrasi Asisten Virtual AI Mandiri Real-Time (Live Chat), Hardening Keamanan Unggahan Terpusat Skala Enterprise di 23 Titik Akses, Arsitektur Tanda Tangan Elektronik (TTE) & Stempel Resmi Berpelindung Anti-Scraping, Sentralisasi Konfigurasi Lembaga Dinamis ke Basis Data (NPSN, Akreditasi, Kurikulum, Visi-Misi, Jam Layanan, & Medsos), Sistem Digital Signage & Kiosk Pengumuman Sekolah 24/7 Nonstop, Perombakan Estetika & Diferensiasi Suite Halaman Error HTTP Responsif, Peningkatan Menyeluruh UX Portal Kontak & Pelacakan Tiket Publik, Standarisasi Kop Surat & Preservasi Warna Cetak pada 21 Template Dokumen Kedinasan, Generator Kode QR Offline Vector Fallback, serta Panel Kontrol Kredensial & TTE Guru oleh Administrator.

---

## ✨ Highlights

- 🤖 **Asisten Virtual AI Mandiri & Real-Time Live Chat (`api/live_chat.php` & `dashboard/admin/live_chat.php`)** — Integrasi mesin kecerdasan buatan (AI) percakapan cerdas tanpa ketergantungan API pihak ketiga berbayar. Dilengkapi basis pengetahuan multi-topik otomatis (Akademik, PPDB, Keuangan/SPP, Presensi, Perpustakaan, BK, dan Ekskul), indikator animasi pengetikan real-time, penanganan fallback cerdas, serta panel kendali Administrator untuk pemantauan interaktif, pengalihan percakapan ke staf (*human takeover*), template respon kilat, dan audit log riwayat obrolan.
- 🛡️ **Pusat Keamanan Unggahan Terpusat Skala Enterprise (23 Titik Akses Hardening)** — Pembangunan mesin validasi berkas terpadu `config/upload_helper.php` dengan inspeksi tipe MIME biner nyata (`finfo` & `exif_imagetype`), whitelist ekstensi ketat, pengacakan nama berkas kriptografis SHA-256 anti-enumerasi/traversal, sanitasi mutlak berkas vektor SVG dari Stored XSS, serta penguncian eksekusi skrip web server di `uploads/.htaccess` (`php_flag engine off` & penolakan eksekusi skrip PHP/CGI/PHTML).
- 🗄️ **Sentralisasi Total Konfigurasi Statis ke Basis Data Dinamis (`school_settings`)** — Eliminasi seluruh data statis yang sebelumnya di-hardcode pada kode sumber antarmuka. Administrator kini dapat mengelola identitas legalitas (NPSN, Akreditasi BAN-S/M, Kurikulum Nasional), narasi sejarah sekolah, visi & butir-butir misi multi-line, jam operasional layanan tata usaha (hari kerja & akhir pekan), seluruh akun media sosial resmi (Instagram, YouTube, Facebook, TikTok), tautan peta Google Maps interaktif, daftar konsentrasi keahlian/jurusan PPDB, hingga headline sambutan landing page langsung dari satu panel terpusat `dashboard/admin/settings.php`.
- 🔏 **Arsitektur Tanda Tangan Elektronik (TTE) & Stempel Resmi Anti-Scraping (`renderOfficialSignatureHtml`)** — Pembaruan mesin render pengesahan resmi kedinasan berpresisi tinggi: efek visual percampuran tinta realistis (`mix-blend-mode: multiply`), proteksi aset visual dari pencurian/ekstraksi ilegal (*anti-scraping*, pelarangan klik kanan, seleksi, dan drag-and-drop), serta preservasi ketajaman warna cetak asli (`print-color-adjust: exact`).
- 📺 **Sistem Digital Signage & Kiosk Mode Pengumuman Sekolah 24/7 Nonstop (`pengumuman.php`)** — Transformasi halaman pengumuman menjadi sistem display informasi digital sekolah otomatis yang siap dipasang pada TV lobi utama dan layar sentuh informasi (*kiosk*). Dilengkapi mode layar penuh (*Fullscreen Kiosk Mode*), sinkronisasi jam digital WIB real-time, transisi carousel lembut tanpa pemotongan teks, bilah indikator progres tayang waktu nyata (*progress bar timer*), serta panel kendali playback (Play/Pause, Navigasi, Toggle Layar Penuh).
- 🎨 **Revolusi Estetika & Diferensiasi Visual Suite Halaman Error HTTP (`error/` & `error/render.php`)** — Peningkatan menyeluruh pada seluruh 14+ halaman error HTTP (400, 401, 402, 403, 404, 405, 408, 409, 410, 413, 414, 415, 419, 422, 429, 500, 502, 503, 504). Setiap kode status kini memiliki identitas visual eksklusif dengan gradien warna tematik, pancaran ambient glow, ikon ilustrasi kontekstual, bahasa diagnostik ramah pengguna, serta tombol aksi resolusi mandiri (*Muat Ulang*, *Kembali*, *Ke Beranda*, dan *Hubungi Helpdesk*).
- 💬 **Peningkatan Signifikan User Experience (UX) Halaman Kontak & Helpdesk (`kontak.php`)** — Pengalaman interaktif baru untuk masyarakat, wali murid, dan alumni saat menghubungi sekolah: indikator jam layanan kantor real-time WIB (buka/tutup otomatis beserta SLA respon), template pesan instan 1-klik (*PPDB, Legalisir, SPP, Akun/Password, Kritik/Saran*), live character counter dengan indikator kualitas progresif, pencarian cepat instan FAQ (*instant filter*), fitur simpan & bagikan tiket langsung ke WhatsApp, serta widget rating kepuasan layanan bintang 1–5 untuk tiket yang telah terselesaikan.
- 📶 **Generator Kode QR Vektor Offline Fallback (Air-Gapped Ready)** — Mesin pembubuh QR Code verifikasi dokumen kini dilengkapi generator SVG mandiri (*pure inline vector*). Ketika server sekolah berada dalam kondisi offline/tanpa koneksi internet publik atau API pihak ketiga mengalami kendala, sistem secara otomatis merender QR Code vektor valid beresolusi tinggi langsung dari kode sumber tanpa menghasilkan gambar rusak (*broken image*).
- 📜 **Standarisasi Kop Surat & Tata Letak Cetak Presisi Tinggi pada 21 Berkas Kedinasan** — Standardisasi format Kop Surat instansi, logo sekolah, penanggalan surat, dan lembar legalitas pada 21 template cetak resmi (Rapor Merdeka, Rapor P5, Kuitansi SPP, Slip Gaji, Kartu Ujian CBT, Berita Acara, Legalisir Ijazah, Surat Panggilan BK, Bebas Pustaka, Surat Keterangan, dsb.) dengan resolusi path dinamis `resolveAssetUrl()`.
- 🎓 **Integrasi Dinamis Pilihan Konsentrasi Keahlian PPDB (`ppdb/ppdb.php`)** — Formulir pendaftaran siswa baru kini menyajikan pilihan jurusan/program keahlian yang secara dinamis dibaca dari basis data melalui `getSchoolMajors($pdo)`, memastikan fleksibilitas penuh bagi sekolah tanpa perlu mengubah kode sumber saat penambahan atau restrukturisasi jurusan.
- 👨‍🏫 **Panel Manajemen Kredensial & Tanda Tangan Guru oleh Admin (`dashboard/admin/users.php`)** — Administrator kini memiliki kendali penuh untuk meninjau, menetapkan Nomor Induk Pegawai (NIP), menambahkan gelar akademik (`title_degree`), meninjau scan tanda tangan digital guru, mengunggah berkas scan TTD baru, atau mereset ke generator tanda tangan SVG resmi sekolah.
- 🌐 **Konektivitas Media Sosial Resmi di Footer Terpadu (`includes/footer.php`)** — Seluruh tautan media sosial sekolah (Instagram, YouTube, Facebook, TikTok) ditampilkan secara rapi dan otomatis menyesuaikan dengan data yang aktif dikonfigurasi di pengaturan instansi.
- 🎛️ **Peningkatan Ergonomi & Kejelasan Hierarki Informasi Dasbor Multi-Peran** — Optimalisasi visual dasbor untuk 6 peran pengguna (Admin, Guru, Siswa, Orang Tua, Staf TU, dan Pembina) guna mengeliminasi kepadatan antarmuka (*visual clutter*), menonjolkan ringkasan metrik statistik utama, dan memastikan data penting terbaca jelas pada berbagai resolusi layar.

---

## 🛠️ Perbaikan Bug, Keamanan & Refaktorisasi Sistem

### 🔒 Keamanan & Kontrol Akses (Security Hardening)

- 🛡️ **Eliminasi Celah Arbitrary File Upload & Remote Code Execution (RCE) Terpusat**
  - **Lokasi:** `config/upload_helper.php`, `uploads/.htaccess`, dan 23 file penanganan unggahan
  - **Deskripsi:** Sebelumnya, validasi berkas unggahan di berbagai modul hanya memeriksa ekstensi nama file atau header `Content-Type` yang dikirimkan peramban (dapat dipalsukan oleh penyerang untuk mengunggah webshell PHP berbahaya).
  - **Solusi:** 
    1. Mengimplementasikan pustaka pengaman terpusat `config/upload_helper.php` dengan fungsi `handleSecureUpload()`.
    2. Wajib melakukan verifikasi tipe MIME menggunakan *magic bytes* via `finfo_file()` dan `exif_imagetype()`.
    3. Mengacak nama seluruh berkas yang diunggah menggunakan token kriptografi `bin2hex(random_bytes(16))` sehingga penyerang tidak dapat memprediksi lokasi maupun melakukan *path traversal* (`../../`).
    4. Memperketat `uploads/.htaccess` dengan menonaktifkan mesin PHP (`php_flag engine off`), menetapkan `SetHandler default-handler`, serta memblokir akses langsung ke ekstensi skrip (`.php`, `.phtml`, `.phar`, `.inc`, `.cgi`, `.sh`, `.asp`).

- 🖋️ **Sanitasi Total Berkas Tanda Tangan SVG dari Serangan Stored XSS**
  - **Lokasi:** `config/upload_helper.php`, `dashboard/profile.php`, `dashboard/admin/users.php`, & `dashboard/admin/settings.php`
  - **Deskripsi:** Berkas gambar vektor SVG berbasis format XML berpotensi disusupi muatan skrip JavaScript berbahaya (`<script>`, atribut event `onload`, `onerror`, tag `<foreignObject>`, dan iframe tersembunyi).
  - **Solusi:** Mengintegrasikan pembersih SVG berbasis DOMDocument/Regex ketat yang melucuti seluruh elemen skrip, event handler, namespace eksternal, dan entitas bersarang sebelum berkas diizinkan tersimpan di storage server.

- 🔏 **Proteksi Hak Cipta & Anti-Scraping pada Stempel Institusi dan Tanda Tangan Pimpinan**
  - **Lokasi:** `config/database.php` (`renderOfficialSignatureHtml`)
  - **Deskripsi:** Gambar stempel resmi sekolah dan tanda tangan basah pimpinan rawan diekstraksi/diunduh secara ilegal melalui klik kanan atau drag-and-drop oleh pihak tidak bertanggung jawab untuk memalsukan surat resmi di luar sistem.
  - **Solusi:** Memasang proteksi CSS & interaksi pengguna tingkat tinggi (`pointer-events: none; -webkit-user-drag: none; user-select: none; -webkit-touch-callout: none;`) pada kontainer pengesahan resmi, sehingga elemen grafis tanda tangan dan stempel tidak dapat diklik kanan, diseret, maupun diunduh secara terpisah.

- 📶 **Pencegahan Kegagalan Dokumen Cetak Tanpa Koneksi Internet (Offline QR Fallback)**
  - **Lokasi:** `config/database.php` (`renderOfficialSignatureHtml` & `generateVectorQrSvg`)
  - **Deskripsi:** Jika server diinstal pada jaringan intranet lokal tanpa akses internet atau jika penyedia API QR eksternal mengalami gangguan jaringan, kode QR verifikasi dokumen sebelumnya gagal dimuat dan menampilkan ikon gambar rusak (*broken image*).
  - **Solusi:** Membangun mesin generator QR Code vektor SVG internal berbasis PHP murni (*standalone SVG fallback*). Dokumen resmi kini 100% tahan banting (*fault-tolerant*) dan tetap dapat mencetak kode QR verifikasi valid meskipun berjalan di lingkungan server *air-gapped*.

- 🤖 **Penangkalan Serangan Flooding, Spamming & Injeksi pada Live Chat**
  - **Lokasi:** `api/live_chat.php` & `dashboard/admin/live_chat.php`
  - **Deskripsi:** Layanan chat interaktif rentan dimanfaatkan untuk pengiriman spam pesan berulang, penipuan identitas, maupun injeksi tag HTML/JavaScript dalam obrolan publik.
  - **Solusi:** Menerapkan pembatasan frekuensi pengiriman pesan (*rate limiting* per IP dan ID sesi), sanitasi karakter input pesan dengan `htmlspecialchars()`, validasi sesi percakapan berbasis token UUIDv4, dan pembatasan panjang karakter pesan maksimal.

### 🏛️ Tata Kelola Data Lembaga & Konfigurasi Dinamis

- ⚙️ **Arsitektur Basis Data Pengaturan Terpusat (`school_settings`)**
  - **Lokasi:** `config/database.php`, `dashboard/admin/settings.php`, & `sql/database.sql`
  - **Deskripsi:** Sebelumnya, atribut seperti nomor NPSN, status akreditasi, nama kurikulum, narasi sejarah, visi, misi, dan jam operasional tata usaha tercatat statis di file `about.php` dan `kontak.php`.
  - **Solusi:** 
    1. Memperluas tabel `school_settings` dengan field konfigurasi baru: `school_npsn`, `school_accreditation`, `school_curriculum`, `school_history`, `school_vision`, `school_mission`, `school_work_hours_weekday`, `school_work_hours_weekend`, `social_instagram`, `social_youtube`, `social_facebook`, `social_tiktok`, `maps_embed_url`, `ppdb_available_majors`, `hero_title`, dan `hero_description`.
    2. Menyediakan fungsi parser terpusat `getSchoolMajors($pdo)` dan `getSchoolMissions($pdo)`.
    3. Meremajakan tampilan halaman `dashboard/admin/settings.php` dengan kartu konfigurasi tematik, validasi input, pratinjau peta Google Maps, dan panduan isian yang intuitif.

- 📜 **Dinamisasi Halaman Profil Sekolah (`about.php`)**
  - **Lokasi:** `about.php`
  - **Deskripsi:** Profil sekolah kini sepenuhnya bersumber dari basis data instansi; mencakup kartu akreditasi, NPSN, kurikulum aktif, visi sekolah, daftar butir misi dinamis dengan penomoran elegan, dan narasi sejarah perkembangan institusi.

- 🎒 **Dinamisasi Pilihan Jurusan PPDB Baru (`ppdb/ppdb.php`)**
  - **Lokasi:** `ppdb/ppdb.php`
  - **Deskripsi:** Menu dropdown formulir pendaftaran PPDB sebelumnya memiliki opsi jurusan statis. Kini pilihan jurusan di-generate otomatis dari baris konfigurasi admin tanpa batasan jumlah jurusan.

### 📺 Digital Signage & Pengumuman Sekolah Nonstop

- 🖥️ **Sistem Kiosk Display Mode untuk Layar Lobi (`pengumuman.php`)**
  - **Lokasi:** `pengumuman.php`
  - **Deskripsi:** Kebutuhan sekolah untuk menampilkan pengumuman secara berkesinambungan di layar TV lobi dan area publik tanpa operator manusia.
  - **Solusi:**
    1. Membangun mode khusus *Kiosk Mode* dengan toggle fullscreen otomatis.
    2. Integrasi jam digital server WIB yang diperbarui setiap detik.
    3. Mesin carousel cerdas dengan durasi tayang yang dapat disesuaikan dan bilah progres (*timer bar*).
    4. Tipografi responsif yang memastikan seluruh teks pengumuman terbaca tuntas tanpa terpotong (*no text truncation*).
    5. Opsi kontrol fleksibel: tombol jeda (*pause*), lanjutkan (*resume*), navigasi manual slide, dan keluar dari mode display.

### 🚨 Suite Halaman Error HTTP Modern

- 🎨 **Diferensiasi Estetika 14+ Halaman Error (`error/` & `error/render.php`)**
  - **Lokasi:** `error/error_data.php`, `error/render.php`, `.htaccess`, & direktori `error/`
  - **Deskripsi:** Seluruh halaman penanganan error HTTP kini memiliki identitas visual yang khas, kontras tinggi, dan modern dengan dukungan Tailwind CSS v4:
    - **400 Bad Request:** Nuansa Amber dengan ikon penanda parameter tidak valid.
    - **401 Unauthorized:** Nuansa Violet dengan indikator otentikasi login.
    - **403 Forbidden:** Nuansa Rose dengan gembok pengamanan akses terlarang.
    - **404 Not Found:** Nuansa Indigo dengan visual kompas pencarian berkas.
    - **405 Method Not Allowed:** Nuansa Orange dengan indikator metode HTTP.
    - **408 Request Timeout:** Nuansa Slate/Amber dengan ikon waktu habis.
    - **413 Payload Too Large:** Nuansa Purple dengan peringatan batas ukuran unggahan.
    - **419 Session Expired:** Nuansa Cyan dengan instruksi refresh token CSRF.
    - **429 Too Many Requests:** Nuansa Yellow/Amber dengan batas frekuensi permintaan.
    - **500 Internal Server Error:** Nuansa Red/Rose dengan petunjuk diagnostik sistem.
    - **502 / 503 / 504 Gateway & Service Unavailable:** Nuansa Sky/Blue untuk status jaringan dan pemeliharaan server.
    - Tombol navigasi kontekstual: *Muat Ulang Halaman*, *Kembali ke Halaman Sebelumnya*, *Ke Halaman Beranda*, dan *Buka Tiket Pengaduan Publik*.

### 💬 Revolusi User Experience (UX) Pusat Layanan Kontak

- ⏱️ **Indikator Real-Time Jam Pelayanan & Hotline (WIB)**
  - **Lokasi:** `kontak.php`
  - **Deskripsi:** Pengunjung website sekolah kini dapat langsung mengetahui apakah kantor tata usaha dan hotline sedang buka atau tutup secara presisi berdasarkan zona waktu WIB:
    - *Senin – Jumat (07.30 – 15.30 WIB):* Indikator hijau aktif berdenyut dengan estimasi SLA respon cepat (< 30–60 menit).
    - *Sabtu (08.00 – 12.00 WIB):* Indikator oranye layanan akhir pekan terbatas (khusus legalisir dan PPDB).
    - *Di Luar Jam Kerja & Minggu:* Indikator penanda kantor tutup beserta penegasan bahwa pengiriman tiket daring tetap aktif 24 jam.

- 🪄 **Template Pesan Instan 1-Klik (Quick Topic Auto-Fill)**
  - **Lokasi:** `kontak.php`
  - **Deskripsi:** Disediakan tombol chip template cepat untuk kebutuhan umum masyarakat: *Info PPDB & Berkas*, *Legalisir Dokumen Alumni*, *Konfirmasi Pembayaran SPP*, *Bantuan Akun / Reset Sandi Portal*, serta *Kritik & Saran Civitas*. Satu klik akan otomatis mengatur kategori formulir, subjek pesan, dan memuat draf teks terstruktur siap lengkapi. Dilengkapi tombol *Kosongkan Form* untuk membersihkan input kembali.

- 📊 **Penghitung Karakter & Kata Interaktif (*Live Character Counter*)**
  - **Lokasi:** `kontak.php`
  - **Deskripsi:** Umpan balik langsung saat pengunjung mengetik pesan lengkap dengan batas minimal 10 karakter, bilah progres dinamis, serta label kualitas pesan (*Terlalu singkat*, *Cukup ringkas*, *Sangat informatif & jelas ✓*).

- 🔍 **Filter Pencarian Cepat FAQ (*Instant Live Search*)**
  - **Lokasi:** `kontak.php`
  - **Deskripsi:** Kotak pencarian instan di atas akordion FAQ yang menyaring pertanyaan dan jawaban secara langsung saat kata kunci diketik, menampilkan jumlah kecocokan, tombol pembersih pencarian, serta *fallback card* ramah pengguna jika topik tidak ditemukan.

- 📱 **Simpan & Bagikan Tiket ke WhatsApp serta Rating Layanan Bintang 5**
  - **Lokasi:** `kontak.php`
  - **Deskripsi:** Tombol *Simpan ke WhatsApp* memungkinkan pemohon menyimpan atau mengirimkan kode tiket beserta link pelacakan langsung ke WhatsApp pribadi. Selain itu, tiket yang telah berstatus `selesai` dilengkapi widget evaluasi kepuasan layanan bintang 1–5 interaktif dengan notifikasi toast konfirmasi.

### 🐛 Perbaikan Bug & Optimalisasi Tampilan

- 🖨️ **Penyelarasan Warna Cetak & Penghilangan Pemotongan Halaman (Print Fidelity)**
  - **Lokasi:** 21 berkas template cetak kedinasan
  - **Deskripsi:** Peramban modern (Chrome, Edge, Safari, Firefox) secara bawaan sering menghilangkan warna latar belakang (background), bayangan, dan garis pembatas kop surat saat dialog cetak PDF dibuka (*color drop*).
  - **Solusi:** Menambahkan deklarasi CSS global `@media print` dengan atribut `-webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact;`, menormalkan margin cetak standar dinas (`@page { margin: 12mm 15mm; }`), dan mengatur pemutus halaman otomatis (*page-break-inside: avoid*).

- 🔗 **Resolusi Path Logo & Aset Kop Surat di Subdirektori Bertingkat**
  - **Lokasi:** `config/database.php` (`resolveAssetUrl`), `dashboard/surat/request_print.php`, `dashboard/alumni/legalisir_print.php`, dsb.
  - **Deskripsi:** Berkas cetak yang terletak di dalam folder bersarang (misal: `dashboard/akademik/`, `dashboard/keuangan/`) sebelumnya berpotensi gagal memuat file logo dan stempel karena perbedaan path relatif (`../../uploads/` vs `/uploads/`).
  - **Solusi:** Standardisasi fungsi `resolveAssetUrl()` yang secara cerdas mendeteksi skema URL absolut, path server relatif, atau menyematkan fallback gambar SVG resmi jika berkas fisik tidak ditemukan.

- 🏷️ **Koreksi Variabel NIP & Nama Kepala Sekolah pada Seluruh Berkas Cetak**
  - **Lokasi:** `dashboard/akademik/gradebook_print.php`, `dashboard/akademik/p5_report.php`, `dashboard/bk/counseling_letter.php`, `dashboard/keuangan/receipt.php`, `dashboard/perpustakaan/clearance.php`
  - **Deskripsi:** Ditemukan ketidakkonsistenan penamaan variabel basis data pada beberapa berkas cetak lawas (antara `$school['headmaster_nip']` vs `$school['principal_nip']`, serta `$school['headmaster_name']` vs `$school['principal_name']`) yang menyebabkan NIP atau nama pimpinan terkadang kosong.
  - **Solusi:** Menyelaraskan seluruh kueri dan variabel penampung menjadi struktur terpadu dengan fallback otomatis bertingkat: `htmlspecialchars($school['principal_name'] ?? $school['headmaster_name'] ?? 'Kepala Sekolah')` dan penanganan NIP yang seragam.

- 📊 **Penguraian Kepadatan Tampilan Dashboard & Penyempurnaan Spasi (Ergonomi UI)**
  - **Lokasi:** `dashboard/index.php`, `dashboard/admin/`, `dashboard/guru/`, `dashboard/siswa/`, `dashboard/ortu/`
  - **Deskripsi:** Kepadatan informasi (*dashboard crowding*) pada beberapa peran pengguna dianalisis dan disempurnakan agar metrik paling mendesak (tugas yang perlu diperiksa, presensi hari ini, tagihan jatuh tempo, jadwal mengajar) tampil menonjol dan tidak melelahkan mata pengguna.

---

## 🏗️ Modul & Fitur Lengkap

### Kecerdasan Buatan & Realtime Live Chat
- Mesin percakapan AI mandiri (`api/live_chat.php`) dengan arsitektur pencocokan intent kata kunci multi-layer
- Pemetaan otomatis topik bantuan instan: Prosedur Pendaftaran PPDB, Kalender Akademik & Ujian, Pembayaran SPP & Kuitansi, Rekap Kehadiran, Katalog Buku Perpustakaan, Bimbingan Konseling, dan Ekstrakurikuler
- Penanganan auto-response cerdas ketika pertanyaan tidak terdaftar dalam basis pengetahuan dengan opsi penyambungan ke nomor WhatsApp Helpdesk atau petugas piket
- Animasi indikator pengetikan (*typing indicator effect*) untuk memberikan pengalaman percakapan alami bagi pengunjung
- Panel Dasbor Pengelola Live Chat (`dashboard/admin/live_chat.php`) untuk Administrator dan Resepsionis/Staf Humas
- Fitur *Human Takeover*: staf dapat mengambil alih obrolan bot secara instan dan melanjutkan percakapan dua arah dengan pengunjung secara langsung
- Penyediaan pustaka balasan cepat (*Quick Reply Templates*) untuk mempercepat respon pertanyaan repetitif
- Filter sesi chat dinamis: `Aktif`, `Selesai (Resolved)`, dan `Diekskalasi ke Staf`
- Riwayat transkrip obrolan tersimpan lengkap dengan catatan waktu, identitas pengunjung, dan audit log

### Pusat Pengamanan Unggahan & Media Berkas (Centralized Upload Engine)
- Mesin pembantu unggah aman terpusat `config/upload_helper.php` dengan fungsi `handleSecureUpload()`
- Validasi berkas biner ketat menggunakan PHP Fileinfo (`finfo`) dan `exif_imagetype` untuk mencegah penipuan ekstensi file (*file extension spoofing*)
- Whitelist ekstensi berkas spesifik sesuai peruntukan berkas (Dokumen: PDF/DOCX/XLSX, Gambar: JPG/PNG/WEBP, Vektor: SVG tersanitasi)
- Pembangkit nama acak unik aman berbasis kriptografi 32 karakter heksadesimal (`random_bytes`) guna mengeliminasi serangan *Direct Object Access* dan *Directory Climbing*
- Pembersihan menyeluruh muatan berbahaya pada berkas SVG (XSS sanitizer) sebelum berkas disetujui masuk ke server
- Penerapan aturan konfigurasi web server Apache `uploads/.htaccess` dengan pematian menyeluruh interpretasi kode PHP (`php_flag engine off`)
- Migrasi dan audit keamanan tuntas pada seluruh 23 titik unggahan di dalam sistem aplikasi:
  1. Pasfoto Profil Pengguna (`dashboard/profile.php`, `dashboard/admin/users.php`)
  2. Logo Utama Sekolah & Favicon (`dashboard/admin/settings.php`)
  3. Scan Tanda Tangan Digital Kepala Sekolah (`dashboard/admin/settings.php`)
  4. Scan Stempel Basah Institusi (`dashboard/admin/settings.php`)
  5. Scan Tanda Tangan Guru & Tenaga Kependidikan (`dashboard/profile.php`, `dashboard/admin/users.php`)
  6. Bukti Transfer Pembayaran SPP Siswa (`dashboard/keuangan/payments.php`)
  7. Berkas Pengumpulan Tugas Belajar Siswa (`dashboard/akademik/assignment_submit.php`)
  8. Materi Pembelajaran & Bahan Ajar Guru (`dashboard/akademik/materials.php`)
  9. Modul Ajar Kurikulum Merdeka & Dokumen Perangkat Ajar (`dashboard/akademik/merdeka_materials.php`)
  10. Gambar Utama Berita & Artikel Jurnalisme Sekolah (`dashboard/informasi/news.php`)
  11. Album Foto Kegiatan Galeri Sekolah (`dashboard/informasi/gallery.php`)
  12. Lampiran Bukti Penanganan Kasus Konseling BK (`dashboard/bk/counseling.php`)
  13. Berkas Persyaratan Pendaftaran Siswa Baru PPDB (KK, Akta, Ijazah, KIP) (`ppdb/ppdb.php`)
  14. Scan Berkas Ijazah Asli untuk Pengajuan Legalisir Digital Alumni (`dashboard/alumni/legalisir.php`)
  15. Foto Sampul Buku Perpustakaan (`dashboard/perpustakaan/books.php`)
  16. Berkas E-Book Digital Perpustakaan dalam format PDF (`dashboard/perpustakaan/books.php`)
  17. Piagam Penghargaan & Sertifikat Kejuaraan Ekstrakurikuler (`dashboard/ekskul/achievements.php`)
  18. Scan Arsip Surat Masuk & Surat Keluar Kedinasan (`dashboard/surat/inward.php`, `dashboard/surat/outward.php`)
  19. Dokumen Lampiran Pengajuan Surat Keterangan Siswa (`dashboard/surat/requests.php`)
  20. Gambar Pendukung & Audio Butir Soal Asesmen CBT (`dashboard/Modul-ujian/questions.php`)
  21. Foto Fisik Aset Inventaris Sarana dan Prasarana (`dashboard/sarpras/inventory.php`)
  22. Lampiran Riwayat Rekam Medis Kesehatan Siswa di UKS (`dashboard/uks/health_records.php`)
  23. File Backup Basis Data SQL (`dashboard/admin/backup.php`)

### Panel Pengaturan Sekolah & Tata Kelola Kelembagaan
- Dashboard admin konfigurasi lembaga terintegrasi (`dashboard/admin/settings.php`)
- Pengaturan identitas legalitas institusi: Nomor Pokok Sekolah Nasional (NPSN), Akreditasi Sekolah (BAN-S/M), dan Kurikulum Acuan
- Manajemen narasi sejarah sekolah serta visi dan butir-butir misi multi-line
- Pengaturan jam kerja operasional kantor tata usaha hari kerja dan akhir pekan
- Manajemen tautan akun media sosial resmi sekolah (Instagram, YouTube, Facebook, TikTok)
- Konfigurasi URL Google Maps Embed interaktif untuk petunjuk arah kampus
- Konfigurasi daftar konsentrasi keahlian / jurusan pendaftaran PPDB berbasis baris teks
- Manajemen teks headline sambutan dan deskripsi singkat pada landing page utama
- Helper parser array global `getSchoolMajors()` dan `getSchoolMissions()` di `config/database.php`

### Digital Signage & Papan Pengumuman Kiosk
- Tampilan digital signage pengumuman sekolah nonstop 24/7 (`pengumuman.php`)
- Mode Layar Penuh (*Fullscreen Kiosk Display*) untuk monitor TV lobi dan aula
- Jam digital real-time sinkron zona waktu Indonesia Barat (WIB)
- Animasi auto-slide carousel berkecepatan teratur dengan bilah progres waktu (*timer bar*)
- Desain tata letak anti-potong (*no text clipping/truncation*) untuk keterbacaan optimal
- Panel kontrol pemutaran: jeda slide (*pause*), lanjutkan (*resume*), tombol navigasi slide manual, dan tombol keluar mode kiosk

### Halaman Error HTTP Interaktif & Diagnostik
- Suite penanganan 14+ kode status HTTP lengkap dengan visual gradien eksklusif
- Ilustrasi dan ikon tematik modern untuk setiap jenis kegagalan jaringan atau server
- Deskripsi diagnostik bersahabat dalam Bahasa Indonesia yang memandu penyelesaian kendala
- Navigasi breadcrumbs dan tombol aksi mandiri: Muat Ulang, Kembali, Beranda, dan Laporkan Tiket

### Pusat Layanan Kontak & Pelacakan Tiket Publik Berbasis UX
- Formulir pengaduan dan pertanyaan publik berpenomoran tiket resmi otomatis (`kontak.php`)
- Indikator buka/tutup kantor tata usaha dan hotline real-time berbasis jam operasional WIB
- 5 Template pesan instan 1-klik untuk topik PPDB, legalisir, SPP, bantuan akun, dan kritik/saran
- Bilah progres dan penghitung karakter serta kata interaktif dengan validasi minimal 10 karakter
- Pelacakan progres penanganan tiket terpadu: antrean diterima, telaah staf, dan penyelesaian tuntas
- Tanggapan resmi bertanda tangan digital instansi dari staf pengelola
- Kotak pencarian cepat instan FAQ dengan kalkulasi jumlah pertanyaan cocok
- Tombol simpan dan bagikan kode tiket beserta tautan pelacakan langsung ke WhatsApp
- Widget survei kepuasan layanan publik bintang 1–5 untuk tiket berstatus selesai
- Lembar bukti tiket pengaduan publik siap cetak dinas (`print stylesheet`)

### Arsitektur Tanda Tangan Elektronik (TTE), Stempel & Kop Surat Terpadu
- Modul render legalitas terpadu `renderOfficialSignatureHtml()` di `config/database.php`
- Efek pencampuran visual tinta basah (*ink blending*) menggunakan CSS `mix-blend-mode: multiply` sehingga stempel dan tanda tangan bertumpuk natural di atas kertas
- Proteksi visual anti-scraping & anti-cloning (`pointer-events: none; -webkit-user-drag: none; user-select: none;`) untuk melindungi stempel dan tanda tangan pimpinan sekolah dari pencurian aset
- Sistem Kode QR Verifikasi Dokumen Mandiri (*Independent Vector QR Generator*) yang siap bekerja dalam jaringan tertutup/offline (air-gapped)
- Portal Publik Verifikasi Keabsahan Dokumen (`verify_document.php`) dengan enkripsi token QR SHA-256 dan validasi silang terhadap pangkalan data arsip sekolah
- Standardisasi Kop Surat Resmi, Logo Institusi, Identitas Dinas Pendidikan, dan Garis Ganda Pembatas pada 21 Berkas Cetak Kedinasan:
  1. Surat Keterangan Siswa Aktif & Dokumen Pelayanan TU (`dashboard/surat/request_print.php`)
  2. Lembar Pengesahan Legalisir Ijazah & Transkrip Nilai Alumni (`dashboard/alumni/legalisir_print.php`)
  3. Buku Rapor Capaian Hasil Belajar Kurikulum Merdeka (`dashboard/akademik/report_card.php`)
  4. Cetak Rapor Massal Satu Rombel Kelas (`dashboard/akademik/report_card_bulk.php`)
  5. Lembar Kuitansi Pembayaran SPP & Biaya Pendidikan Resmi (`dashboard/keuangan/receipt.php`)
  6. Slip Gaji & Rekapitulasi Honor Pendidik/Tenaga Kependidikan (`dashboard/keuangan/payroll_slip.php`)
  7. Kartu Tanda Peserta Asesmen & Ujian CBT (`dashboard/Modul-ujian/exam_card.php`)
  8. Berita Acara & Lembar Presensi Ujian Ruang CBT (`dashboard/Modul-ujian/exam_print.php`)
  9. Surat Keterangan Bebas Pustaka Perpustakaan (`dashboard/perpustakaan/clearance.php`)
  10. Surat Panggilan Konseling Orang Tua/Wali Murid BK (`dashboard/bk/counseling_letter.php`)
  11. Formulir Buku Induk Biodata Profil Siswa Dapodik (`dashboard/admin/student_profile_print.php`)
  12. Laporan Rekapitulasi Pembukuan Kas & Realisasi Anggaran Keuangan (`dashboard/keuangan/financial_print.php`)
  13. Laporan Rekapitulasi Presensi Kehadiran Siswa Bulanan (`dashboard/presensi/attendance_print.php`)
  14. Piagam Penghargaan & Sertifikat Kejuaraan Ekstrakurikuler (`dashboard/ekskul/print_certificate.php`)
  15. Surat Edaran Pengumuman & Pemberitahuan Dinas Sekolah (`dashboard/informasi/print_announcement.php`)
  16. Daftar Hadir Tes Seleksi & Ujian Masuk Peserta PPDB Baru (`dashboard/admin/ppdb_attendance_print.php`)
  17. Buku Rekapitulasi Nilai Akademik Mata Pelajaran / Leger Nilai Guru (`dashboard/akademik/gradebook_print.php`)
  18. Lembar Rapor Projek Penguatan Profil Pelajar Pancasila (P5) (`dashboard/akademik/p5_report.php`)
  19. Kartu Tanda Bukti Pendaftaran PPDB Online Calon Siswa Baru (`ppdb/ppdb_card.php`)
  20. Lembar Verifikasi Keaslian & Audit Dokumen Publik (`verify_document.php`)
  21. Master Template Engine Kop Surat Terpadu (`config/database.php`)

### Manajemen Pengguna & Kredensial Pendidik
- Panel konfigurasi guru dan pegawai terpadu di Admin User Management (`dashboard/admin/users.php`)
- Pengaturan Nomor Induk Pegawai (NIP / NUPTK) dan penulisan Gelar Akademik resmi guru (`title_degree`)
- Penampil langsung pratinjau scan tanda tangan digital guru di modal edit admin
- Opsi unggah berkas scan tanda tangan baru oleh Administrator dengan validasi tipe berkas otomatis
- Opsi reset tanda tangan guru ke generator SVG resmi berbasis kaligrafi nama digital

**Full Changelog**: https://github.com/saipul12c/Manajemen-php/compare/v1.2.0...v1.3.0


---

# ⚡ Manajemen-PHP v1.2.0

> Rilis Pembaruan Ketiga — Peningkatan Skala Besar: Implementasi Penuh Kurikulum Merdeka & Rapor Projek P5, Radar Profil Siswa 360°, Modul Alumni & Legalisir Ijazah Digital Ber-QR TTE, Ekstrakurikuler & Hall of Fame Prestasi, Wizard Kenaikan Kelas Massal, Sistem Penggajian Guru (Payroll), Ekosistem Multi-Aplikasi Mobile (3 Android APK + PWA), Integrasi Multi-API Gateway & Webhook Pembayaran, CBT Seleksi Masuk PPDB, Portal Berita Sekolah Publik, Analisis Butir Soal Asesmen, serta Penguatan Keamanan Skala Enterprise (Patch IDOR, XSS, & Sanitasi Berkas).

---

## ✨ Highlights

- 🌟 **Kurikulum Merdeka & Projek Profil Pelajar Pancasila (P5)** — Manajemen lengkap 8 tema projek P5 standar BSKAP Kemendikbudristek, pemetaan dimensi/elemen/sub-elemen, rubrik penilaian fase 4 skala (MB, SB, BSH, SAB), cetak Lembar Rapor Projek P5 resmi format A4, modul perangkat ajar guru (Modul Ajar, ATP, Prota, Promes), Jurnal Mengajar KBM harian & guru inval, serta Cetak E-Rapor Massal 1 Rombel.
- 🎯 **Radar Profil Siswa 360 Derajat (`student_360.php`)** — Panel analitik holistik yang memotret perkembangan peserta didik dalam 1 layar: biodata Dapodik, grafik radar capaian akademik per mapel, rasio presensi kehadiran, akumulasi poin pelanggaran & prestasi BK, riwayat pembayaran SPP, keaktifan ekstrakurikuler, dan catatan rapor semester.
- 🏆 **Manajemen Ekstrakurikuler & Portofolio Akreditasi BAN-SM** — Katalog klub bakat minat, pendaftaran mandiri siswa, lembar presensi mingguan pembina dengan fitur kilat *"Set Hadir Semua"*, penilaian predikat rapor terintegrasi, galeri Hall of Fame prestasi kejuaraan dengan ekspor CSV portofolio akreditasi, dan cetak piagam/sertifikat ber-QR TTE.
- 🎓 **Modul Alumni, Tracer Study & Legalisir Digital Ijazah** — Penelusuran jejak karir lulusan (kuliah, kerja, wirausaha) berstandar instrumen akreditasi BAN-SM, layanan pengajuan legalisir ijazah online oleh alumni, verifikasi staf TU, serta cetak lembar pengesahan legalisir resmi berstempel dan QR Code validasi keaslian dokumen (`verify_document.php`).
- 🚀 **Wizard Kenaikan Kelas & Kelulusan Massal (`promotion.php`)** — Otomatisasi mutasi rombel tahun ajaran baru secara massal dalam 1 klik, fleksibilitas keputusan kenaikan/tinggal kelas per siswa, kelulusan otomatis ke status Alumni (`alumni_records`), dan pencatatan riwayat audit mutasi kelas.
- 💵 **Sistem Penggajian & Slip Gaji Guru (Payroll)** — Otomatisasi kalkulasi honor bulanan dewan guru berdasarkan jam tatap muka KBM (terintegrasi jurnal mengajar), tunjangan wali kelas, potongan kasbon/keterlambatan, dan cetak slip gaji dinas resmi (`payroll_slip.php`).
- 📲 **Ekosistem Multi-Aplikasi Mobile (3 Android APK + PWA Terpadu)** — Pusat unduhan multi-aplikasi (`apps.php` & `apps_hub.php`) dengan 3 aplikasi mandiri: CBT Ujian Kiosk Screen (anti-curang), Presensi Kehadiran Geofencing GPS, dan E-Perpustakaan barcode scanner, lengkap dengan Web App Manifests terpisah & paket source code Java wrapper Android Studio.
- 🔌 **Integrasi Multi-API Gateway, Webhook & REST API RFID Fisik** — Panel kontrol terpusat `external_apis.php` berfitur auto-save Ajax untuk konfigurasi WhatsApp Gateway, Payment Gateway (Midtrans, Xendit, Tripay) + Webhook listener otomatis (`payment_webhook.php`), Mail SMTP, Google Maps, serta REST API Gateway presensi perangkat keras RFID gerbang sekolah (`rfid_attendance.php`).
- 📝 **CBT Seleksi Masuk PPDB Online & Cetak Berita Acara DHPU** — Portal ujian berbasis komputer mandiri bagi calon siswa baru (`ppdb/ppdb_cbt.php`) dengan auto-save jawaban, navigasi dinamis, penanda ragu-ragu, serta format cetak resmi Daftar Hadir Peserta Ujian (DHPU) dan Berita Acara Ruang Ujian.
- 📰 **Portal Berita Sekolah Publik & Redaksi Jurnalisme** — Portal publik berita kegiatan sekolah (`berita.php`) dengan featured headlines, hit counter pembaca, dan integrasi silang kalender & buku perpus, dikelola melalui dashboard redaksi artikel terpadu (`dashboard/informasi/news.php`).
- 📊 **Analisis Butir Soal & Daya Pembeda Asesmen CBT** — Kalkulasi otomatis Indeks Tingkat Kesukaran ($P$) soal pilihan ganda (Mudah, Sedang, Sukar), grafik distribusi efektivitas distraktor/pengecoh (opsi A–D), dan analisis rata-rata skor butir esai terbuka.
- 📍 **Presensi Mandiri Pendidik & Geofencing GPS** — Presensi masuk (*Smart Clock-In*) dan pulang (*Smart Clock-Out*) real-time dengan live clock server, kalkulasi jarak radius lokasi sekolah menggunakan formula Haversine, serta pengajuan cuti/izin/sakit online pendidik.
- 🔑 **Pemulihan Kata Sandi Mandiri & Hardening Akun** — Alur reset password 2 langkah via token berbatas waktu SHA-256 (1 jam), proteksi rate limiting per IP, masking nomor ponsel aman, dan integrasi tombol darurat WhatsApp Helpdesk.
- 🚨 **Suite 14+ Custom Error Pages Responsif** — Penanganan error HTTP komprehensif (400, 401, 402, 403, 404, 405, 408, 409, 410, 413, 414, 415, 419, 422, 429, 500, 502, 503, 504) lengkap dengan visualisasi modern, pesan diagnostik informatif, dan rekomendasi resolusi mandiri.

---

## 🛠️ Perbaikan Bug dan Peningkatan Keamanan

### 🔒 Keamanan & Kontrol Akses (Security Hardening)
- 🛡️ **Pencegahan Akses Ilegal E-Rapor Siswa (IDOR/BOLA Fix) — *Special Credit to kta1kri***
  - **Pelapor Keamanan:** `kta1kri` (Security Researcher)
  - **Lokasi:** `dashboard/akademik/report_card.php`
  - **Deskripsi:** Menutup celah *Broken Object-Level Authorization* (IDOR/BOLA) di mana siswa yang login sebelumnya dapat melihat isi lengkap buku rapor digital siswa lain (NISN, catatan wali kelas, nilai mata pelajaran, dan kehadiran) dengan memanipulasi parameter URL `?student_id=`.
  - **Solusi:** Menerapkan kontrol akses berbasis peran (RBAC) ketat: siswa mutlak hanya dapat membuka rapor miliknya sendiri (`$target_student_id = $user_id`), orang tua divalidasi melalui relasi resmi tabel `parent_students`, dan hanya guru/staf/admin yang memiliki wewenang memilih siswa binaan.

- 💰 **Pengamanan Kepemilikan Tagihan & Pembayaran (Anti-IDOR) — *Reported by kta1kri***
  - **Pelapor Keamanan:** `kta1kri`
  - **Lokasi:** `dashboard/keuangan/payments.php` (`submit_payment`)
  - **Deskripsi:** Menutup celah di mana siswa sebelumnya dapat mengirimkan pengajuan pembayaran atas nomor tagihan (`bill_id`) siswa lain.
  - **Solusi:** Menambahkan verifikasi kepemilikan tagihan di dalam transaksi basis data dengan penguncian baris (`FOR UPDATE`), memvalidasi batas ukuran file bukti transfer (maks. 5MB), dan memeriksa tipe MIME asli via `finfo`.

- 👨‍👩‍👦 **Perbaikan Logika Pembayaran Tagihan Orang Tua Multi-Anak**
  - **Lokasi:** `dashboard/keuangan/payments.php`
  - **Deskripsi:** Memperbaiki *logic error* pada akun orang tua yang memiliki lebih dari satu anak, di mana sebelumnya sistem memblokir pembayaran tagihan anak kedua dan seterusnya. Kini orang tua dapat membayar tagihan seluruh anak yang sah terhubung di `parent_students`.

- 🌐 **Pengamanan Signature Webhook Pembayaran Otomatis**
  - **Lokasi:** `api/payment_webhook.php`
  - **Deskripsi:** Menghilangkan celah bypass signature HMAC jika secret key belum disetel. Kini sistem menolak permintaan (`503 Service Unavailable`) jika konfigurasi belum lengkap, serta mewajibkan verifikasi signature menggunakan `hash_equals`.

- 📑 **Penguatan Verifikasi Integritas Dokumen & Tanda Tangan Elektronik (TTE)**
  - **Lokasi:** `verify_document.php`
  - **Deskripsi:** Mencegah pemalsuan tautan verifikasi dokumen publik (*hash forgery*) dengan menghapus celah validasi parameter kosong dan mewajibkan pencocokan nomor berkas secara nyata terhadap pangkalan data arsip resmi sekolah (`service_requests`, `mail_archives`, `student_bills`, atau `ppdb_registrations`) sebelum dokumen dinyatakan sah.

- 📻 **Penghapusan Kunci Bawaan & Pengamanan CORS API Presensi RFID**
  - **Lokasi:** `api/rfid_attendance.php`
  - **Deskripsi:** Menghapus nilai kunci bawaan (*hardcoded fallback key*) di kode sumber sehingga 100% bersumber dari konfigurasi database (`school_settings`), serta memperbaiki kebijakan CORS agar tidak merefleksikan header *Host/Origin* sembarangan.

- 🔐 **Peningkatan Privasi Pengguna & Penangkalan Enumerasi pada Lupa Sandi**
  - **Lokasi:** `auth/forgot_password.php`
  - **Deskripsi:** Memperbaiki *logic flaw* pada fungsi `maskPhone()` di mana 2 digit terakhir nomor ponsel sebelumnya tercetak secara terbuka di layar pada tahap tantangan Google Phone Challenge. Masking diperketat menjadi format `0812-••••-••••`, penyamaran nama akun ditingkatkan, serta diterapkan pembatasan frekuensi (*rate limiting* per IP) untuk mencegah spam OTP WhatsApp (*WA Bombing*).

- 🪪 **Pencegahan Kebocoran Masif Data Pribadi (PII) & IDOR pada Kartu Pendaftaran PPDB**
  - **Lokasi:** `ppdb/ppdb_card.php` & `ppdb/ppdb_verify.php`
  - **Deskripsi:** Menutup celah *Broken Access Control* / IDOR di mana kartu tanda bukti pendaftaran fisik PPDB sebelumnya dapat dibuka bebas tanpa otentikasi hanya dengan menebak parameter `?reg=PPDB-2026-XXXX`. Celah ini sebelumnya berpotensi membocorkan data pribadi (PII) sensitif seperti NIK, NISN, tanggal lahir, nama orang tua, kontak WhatsApp wali murid, dan alamat rumah.
  - **Solusi:** Menerapkan otorisasi bertingkat (hanya dapat diakses panitia sekolah yang sedang login, sesi terverifikasi tanggal lahir di tab Cek Status, atau melalui token keamanan kriptografi permanen `qr_token` yang tercetak pada barcode kartu).

- 🛡️ **Proteksi File Konfigurasi Sensitif & Larangan Eksekusi Skrip pada Folder Unggahan**
  - **Lokasi:** `.htaccess` & `uploads/.htaccess`
  - **Deskripsi:** Menutup celah pengunduhan langsung file lingkungan sensitif seperti `.env`, dotfiles (`.git`), file cadangan basis data (`.sql`, `.bak`), file log, dan folder internal sistem (`config`, `sql`, `includes`) di level web server Apache.
  - **Solusi:** Memperketat rule pemblokiran di root `.htaccess` serta membuat konfigurasi pengaman baru di `uploads/.htaccess` dengan `php_flag engine off` dan `SetHandler default-handler` guna mematikan eksekusi skrip PHP secara total di seluruh subdirektori unggahan.

- 🖋️ **Pencegahan Stored XSS pada Upload Berkas Scan Tanda Tangan Guru**
  - **Lokasi:** `dashboard/profile.php`
  - **Deskripsi:** Mencegah serangan *Stored Cross-Site Scripting* (XSS) melalui unggahan file tanda tangan berformat SVG yang berpotensi disusupi muatan skrip JavaScript berbahaya.
  - **Solusi:** Menerapkan sanitasi otomatis berkas XML/SVG untuk membersihkan tag `<script>`, atribut event JavaScript (`onload`, `onerror`, dll.), serta elemen `<foreignObject>` sebelum disimpan ke server.

- 🌐 **Pengaktifan Verifikasi SSL/TLS & Penangkalan MitM pada Koneksi API Eksternal**
  - **Lokasi:** `config/database.php` (`testExternalApiConnection` & `callExternalApi`)
  - **Deskripsi:** Mengaktifkan kembali validasi ketat sertifikat SSL/TLS (`CURLOPT_SSL_VERIFYPEER = true` dan `CURLOPT_SSL_VERIFYHOST = 2`) pada panggilan API ke pihak ketiga (Payment Gateway, WA Gateway, dll.) guna mencegah penyadapan kredensial transaksi (*Man-in-the-Middle*).

- 🍪 **Inisialisasi Keamanan Cookie Sesi Terpusat (*Session Security*)**
  - **Lokasi:** `config/database.php`
  - **Deskripsi:** Menetapkan parameter keamanan cookie sesi (`HttpOnly`, `SameSite=Lax`, dan flag `Secure` saat berjalan di HTTPS) secara terpusat di baris teratas konfigurasi sistem sebelum sesi PHP dimulai.

- 🔒 **Pembersihan Data Konseling Siswa (BK) dari Kueri Berita Publik**
  - **Lokasi:** `berita.php`
  - **Deskripsi:** Menghapus relasi join ke tabel `counseling_records` pada kueri pembacaan berita publik yang sebelumnya menarik data pelanggaran kedisiplinan dan nama siswa ke dalam memori eksekusi halaman artikel.

- 📝 **Validasi CSRF Token & Pembatasan Percobaan Login CBT PPDB**
  - **Lokasi:** `ppdb/ppdb_cbt.php`
  - **Deskripsi:** Menambahkan validasi `validateCsrfToken()` pada form konfirmasi tanggal lahir peserta seleksi, serta menerapkan batas maksimal 5 kali percobaan salah sebelum akses ditunda sementara demi mencegah tebakan berulang.

- 🛡️ **Pencegahan Kebocoran Struktur Database (*Exception Leak*) & Perbaikan Rate Limiter IP**
  - **Lokasi:** `ppdb/ppdb_cbt.php`, `ppdb/ppdb.php`, `kontak.php`, `dashboard/perpustakaan/books.php`, & `auth/login.php`
  - **Deskripsi:** Mengganti pengembalian mentah pesan error database (`$e->getMessage()`) ke publik dengan pesan umum yang aman, serta menyelaraskan pembacaan IP di `auth/login.php` menggunakan `getClientIp()` agar tidak memicu penguncian akun massal (*DoS*) di balik reverse proxy.

### 🎨 UI/UX, Stabilitas Sistem & Penanganan Error
- 🚨 **Arsitektur Halaman Error HTTP Terpusat (14+ Error Handlers)**
  - **Lokasi:** `error/error_data.php`, `error/render.php`, `.htaccess`, & direktori `error/`
  - **Deskripsi:** Mengimplementasikan penanganan error HTTP komprehensif untuk kode status: 400, 401, 402, 403, 404, 405, 408, 409, 410, 413, 414, 415, 419, 422, 429, 500, 502, 503, dan 504. Setiap halaman dilengkapi ilustrasi visual tematik, status badges, penjelasan ramah pengguna, tombol kembali ke navigasi aman, serta saran resolusi mandiri.

- 📱 **Penyempurnaan Tampilan Responsif & Tabel Data pada Perangkat Mobile**
  - **Lokasi:** Berbagai modul dashboard (`dashboard/akademik/journal.php`, `dashboard/ekskul/`, `dashboard/alumni/`, `dashboard/keuangan/payroll.php`)
  - **Deskripsi:** Menambahkan pembungkus tabel responsif (*overflow-x-auto*), perbaikan badge status, penyesuaian tata letak grid, dan modal dialog interaktif agar ramah disentuh pada layar smartphone dan tablet.

- 🔍 **Optimalisasi Kueri Database & Efisiensi Migrasi Otomatis**
  - **Lokasi:** `config/database.php`
  - **Deskripsi:** Menghindari eksekusi DDL berulang pada setiap muatan halaman dengan menambahkan mekanisme flag migration lock file (`.db_migrated_v20`) dan caching sesi, sehingga mengurangi beban transaksi I/O database secara signifikan.

---

## 🏗️ Modul & Fitur Lengkap

### Kurikulum Merdeka & Projek Profil Pelajar Pancasila (P5)
- Manajemen 8 tema projek P5 resmi Kemendikbudristek: Gaya Hidup Berkelanjutan, Kearifan Lokal, Bhinneka Tunggal Ika, Bangunlah Jiwa dan Raganya, Suara Demokrasi, Rekayasa dan Teknologi, Kewirausahaan, dan Kebekerjaan
- Penunjukan koordinator projek dan pembentukan tim fasilitator pendidik lintas mata pelajaran
- Pemetaan dimensi, elemen, sub-elemen, dan target capaian fase perkembangan peserta didik
- Lembar penilaian projek interaktif dengan rubrik capaian 4 skala: MB (Mulai Berkembang), SB (Sedang Berkembang), BSH (Berkembang Sesuai Harapan), dan SAB (Sangat Berkembang)
- Cetak lembar Rapor Projek P5 resmi standar BSKAP Kemendikbudristek ukuran A4 lengkap dengan deskripsi capaian, catatan proses fasilitator, dan pengesahan Kepala Sekolah
- Pengelolaan berkas Perangkat Ajar (Modul Ajar, ATP, Silabus, Prota, Promes, KKTP) dengan opsi upload file langsung (PDF/Word/Excel) atau tautan cloud storage (Google Drive / OneDrive)
- Alur supervisi kurikulum terpadu: `Draft → Diajukan → Disetujui / Perlu Revisi` disertai catatan evaluasi pimpinan

### Manajemen Akademik, Jurnal KBM & E-Rapor Massal
- Jurnal Mengajar KBM Harian (`journal.php`): pencatatan agenda tatap muka kelas, mata pelajaran, durasi jam pelajaran, materi pokok bahasan, rekap kehadiran siswa, dan catatan situasi kelas
- Penanganan status KBM fleksibel: `Terlaksana`, `Terkendala`, atau `Digantikan Guru Piket (Inval)` disertai penunjukan guru pengganti
- Filter jurnal per bulan/kelas dan cetak buku agenda mengajar resmi siap supervisi dinas
- Dashboard & Monitoring Rombel Wali Kelas (`homeroom.php`): statistik kehadiran kelas binaan, radar tugas mata pelajaran yang belum dikumpulkan, rekam jejak poin BK, serta input catatan kepribadian siswa
- E-Rapor Kurikulum Merdeka (`report_card.php`): narasi capaian pembelajaran otomatis (capaian tertinggi dan perlu peningkatan), tab sampul rapor, biodata buku induk Dapodik, dan lembar nilai akademik
- Cetak E-Rapor massal satu rombel kelas (`report_card_bulk.php`) dalam 1 klik dengan pemisah halaman cetak dinas (*CSS page-break: always*)

### Ekstrakurikuler, Presensi Pembina & Portofolio Prestasi
- Katalog ekstrakurikuler bakat & minat (`dashboard/ekskul/index.php`) lengkap dengan informasi kuota kapasitas, jadwal latihan rutin mingguan, dan profil guru pembina
- Pendaftaran mandiri siswa dengan validasi kuota otomatis dan status persetujuan pembina
- Manajemen anggota klub dan penunjukan struktur organisasi kepengurusan (Ketua, Wakil, Sekretaris, Bendahara, Anggota)
- Lembar presensi mingguan pembina (`attendance.php`) dengan tombol kilat *"Set Hadir Semua"* dalam 1 klik serta audit status (Hadir, Izin, Sakit, Alpa)
- Input nilai predikat rapor semester (A/B/C/D) dan deskripsi catatan pembina yang tersinkronisasi otomatis ke Bagian C E-Rapor Siswa
- Galeri Hall of Fame prestasi kejuaraan siswa (`achievements.php`) dari tingkat Sekolah hingga Internasional
- Ekspor CSV portofolio prestasi siswa yang diformat khusus untuk kebutuhan Akreditasi Sekolah (BAN-SM / Penjaminan Mutu)
- Penerbitan dan cetak Piagam Penghargaan & Sertifikat Resmi (`print_certificate.php`) ukuran A4 ber-QR Code verifikasi TTE dan kop surat resmi

### Alumni, Tracer Study & Legalisir Digital Ijazah
- Kuesioner penelusuran lulusan / Tracer Study (`tracer_study.php`): pelacakan status karir alumni (Kuliah, Bekerja, Wirausaha, Mencari Kerja), institusi, jabatan, pendapatan, dan relevansi kurikulum
- Visualisasi metrik statistik serapan lulusan di dashboard dan ekspor data CSV standar instrumen akreditasi BAN-SM
- Layanan permohonan legalisir ijazah/transkrip digital mandiri oleh alumni (`legalisir.php`) dengan upload scan dokumen fisik
- Pipeline verifikasi berkas oleh Staf Tata Usaha: `Menunggu → Diverifikasi → Disetujui / Perlu Revisi / Ditolak` secara transparan
- Cetak lembar pengesahan legalisir resmi (`legalisir_print.php`) ukuran A4 berstempel dinas digital, tanda tangan Kepala Sekolah, dan QR Code validasi keaslian dokumen (`verify_document.php`)

### Radar Profil Siswa 360 Derajat (Student 360)
- Dasbor analitik holistik siswa (`student_360.php`) dalam satu layar terpadu
- Visualisasi biodata lengkap Dapodik, grafik radar pencapaian nilai kompetensi belajar per mata pelajaran, rasio persentase kehadiran, akumulasi rekam jejak kedisiplinan & prestasi BK, riwayat tagihan SPP keuangan, keanggotaan klub ekskul, dan catatan rapor semester
- Aksesibilitas multi-role: Administrator, Staf TU, Guru/Wali Kelas, serta Siswa & Orang Tua (monitoring mandiri)

### Wizard Kenaikan Kelas & Kelulusan Massal Siswa
- Pemrosesan transisi rombongan belajar (rombel) tahun ajaran baru secara massal dalam 1 tahapan wizard (`promotion.php`)
- Fleksibilitas penetapan keputusan kenaikan rombel: tandai *Naik Kelas* atau *Tinggal Kelas* per siswa disertai pencatatan administratif
- Tab kelulusan siswa tingkat akhir: otomatis mengalihkan status siswa menjadi Alumni, mengarsipkan data ke tabel `alumni_records`, dan mencatat seluruh histori mutasi kelas ke tabel `academic_promotion_logs`

### Manajemen Keuangan, Penggajian Guru (Payroll) & Verifikasi Kuitansi
- Modul penggajian bulanan dewan guru (Payroll) (`payroll.php`): otomatisasi perhitungan gaji pokok, honor jam mengajar tatap muka KBM (terintegrasi dengan jurnal mengajar), tunjangan jabatan/wali kelas, dan potongan kasbon/keterlambatan/BPJS
- Cetak slip gaji digital resmi dewan guru (`payroll_slip.php`) standar dinas
- Portal publik verifikasi keabsahan kuitansi pembayaran SPP (`receipt_verify.php`) secara real-time via pemindaian kode QR terenkripsi SHA-256

### Ekosistem Multi-Aplikasi Mobile (Android APK & PWA Hub)
- Portal pusat unduhan dan pemasangan multi-aplikasi mobile terpadu (`apps.php` & `dashboard/apps_hub.php`) dengan deteksi IP/domain otomatis via QR Code dinamis
- 3 Paket Aplikasi Android Native Java Shell Wrapper (`apps/android/`):
  - CBT Ujian (`com.sekolah.cbt`): Layar penuh (immersive fullscreen), Kiosk Mode, anti-screenshot, keep-screen-on
  - Presensi (`com.sekolah.presensi`): Akses kamera QR instan, integrasi Geofencing GPS
  - Perpustakaan (`com.sekolah.perpustakaan`): Barcode scanner kamera, in-browser e-reader PDF anti-copy
- Web App Manifests mandiri untuk CBT Ujian, Presensi, dan Perpustakaan + Service Worker PWA (`sw.js`) untuk caching offline aset inti

### Integrasi Multi-API Gateway, Webhook Pembayaran & REST API RFID Fisik
- Panel kontrol konfigurasi terpusat Multi-API Gateway (`external_apis.php`) dengan fitur Live Auto-Save Ajax ke database tanpa reload halaman
- Konfigurasi terpadu untuk WhatsApp Gateway, Payment Gateway (Midtrans, Xendit, Tripay), Mail SMTP, Google Maps, OpenWeather, dan Webhook Kustom
- Webhook listener pembayaran otomatis (`payment_webhook.php`) untuk update real-time status lunas transaksi QRIS dan Virtual Account ber-signature HMAC SHA-256
- REST API Gateway presensi perangkat keras RFID gerbang sekolah (`rfid_attendance.php`) untuk integrasi tap kartu fisik siswa dan pegawai

### CBT Seleksi Masuk PPDB Online & Cetak Berita Acara DHPU
- Portal pengerjaan ujian seleksi masuk online calon siswa baru (`ppdb/ppdb_cbt.php`) berbasis browser dengan auto-save Ajax, navigasi dinamis, dan penanda ragu-ragu
- Otentikasi aman melalui verifikasi nomor registrasi, tanggal lahir, dan hash QR token kartu pendaftaran
- Hasil ujian CBT otomatis terintegrasi ke dalam bobot perhitungan kelulusan seleksi PPDB
- Cetak Daftar Hadir Peserta Ujian (DHPU) PPDB dan Berita Acara Ruang Ujian resmi (`ppdb_attendance_print.php`) siap pakai pengawas ruangan

### Portal Berita Sekolah Publik & Redaksi Jurnalisme
- Portal berita publik (`berita.php`) dengan tata letak modern, headline berita utama, filter kategori, hit counter pembaca, dan integrasi silang buku perpustakaan & agenda kalender
- Panel redaksi artikel sekolah (`dashboard/informasi/news.php`) dengan alur editorial (Draft, Review, Published), kategori dinamis, tag, thumbnail cover, dan kutipan excerpt

### Analisis Butir Soal Asesmen CBT & Presensi Mandiri Pendidik
- CBT Analisis Butir Soal (`exam_results.php`): kalkulasi otomatis Indeks Tingkat Kesukaran ($P$) soal pilihan ganda (Mudah, Sedang, Sukar), visualisasi grafik efektivitas distraktor/pengecoh (opsi A–D), dan analisis rata-rata skor esai
- Presensi Pendidik (`teacher_attendance.php`): Smart Clock-In/Clock-Out live clock server, validasi radius Geofencing GPS (Formula Haversine), formulir izin/sakit/cuti online pendidik

### Pemulihan Sandi Mandiri & Hardening Keamanan
- Layanan pemulihan kata sandi mandiri (`auth/forgot_password.php`) via token berbatas waktu SHA-256 (1 jam), proteksi rate limiting per IP, masking nomor ponsel aman, dan integrasi tombol darurat WhatsApp Helpdesk
- Validasi CSRF ganda, sanitasi file SVG XSS, pembatasan eksekusi folder uploads, dan pemblokiran dotfiles di `.htaccess`

---

## 🛠️ Tech Stack

| Layer | Teknologi |
|-------|-----------|
| Backend | PHP 8.0+ (Native, PDO Prepared Statements) |
| Database | MySQL 8.0+ / MariaDB 10.4+ (56 Tabel Relasional, Auto-Migration v20) |
| Frontend | Tailwind CSS v4 Browser CDN, Font Awesome 6.5, Plus Jakarta Sans |
| Mobile & PWA | Service Worker PWA, 3 Web App Manifests, Android Native Wrapper (Java Shell WebView) |
| Integrasi API & IoT | REST API RFID Presence Gateway, Payment Webhook HMAC SHA-256, Multi-API Gateway |
| QR, Barcode & TTE | html5-qrcode (Scanner Webcam), qrcodejs (Dynamic QR & Barcode), SHA-256 Digital Signature Validation |
| Web Server | Apache 2.4+ (XAMPP / Laragon) dengan Hardened `.htaccess` Security Rules |

---

**Full Changelog**: https://github.com/saipul12c/Manajemen-php/compare/v1.1.0...v1.2.0

---

# ⚡ Manajemen-PHP v1.1.0

> Rilis Pembaruan Kedua — Sistem Manajemen Sekolah Terpadu dengan penambahan PPDB 4 Jalur Seleksi & QR Token SHA-256, Buku Kas Umum (BKU), Inventaris Sarpras, Buku Agenda Persuratan & SPT Guru/Pegawai standar kedinasan, Peningkatan Perpustakaan Digital (Auto-Fill ISBN & In-Browser E-Reader), Cetak Lembar Buku Induk Siswa (Standar Kemendikbud), serta Pusat Pengaduan Publik Tiket Tamu.

---

## ✨ Highlights

- 🎓 **PPDB Online 4 Jalur & Skoring Cerdas** — Pendaftaran jalur Reguler, Zonasi Domisili (radius km), Prestasi berjenjang (sekolah s/d internasional), dan Afirmasi/KIP. Kalkulator nilai rapor 4 mapel otomatis (`calculatePpdbScore`), verifikasi dokumen 3 status, dan verifikasi QR Code keaslian via SHA-256 (`ppdb_verify.php`).
- ⚡ **Otomatisasi 1-Klik Siswa Diterima** — Auto-provisioning akun siswa (`users`), akun orang tua (`parent_students`), penempatan rombel kelas, dan tagihan awal keuangan (`student_bills`) saat panitia menerima calon siswa.
- 💰 **Buku Kas Umum (BKU) & Kas Pengeluaran** — Pencatatan Bukti Kas Keluar (`EXP-YYYY/MM/NNN`), kategori belanja operasional, upload nota kwitansi asli, kalkulasi arus kas riil (*Penerimaan SPP - Pengeluaran Kas = Saldo Kas Riil*), dan cetak laporan BKU audit.
- 🏢 **Inventaris Sarana & Prasarana (Sarpras)** — Buku inventaris aset per ruangan, kondisi barang (Baik, Rusak Ringan, Rusak Berat), sumber pendanaan (BOS/Yayasan), serta sirkulasi peminjaman alat/fasilitas dengan pemantauan jatuh tempo.
- 📄 **Persuratan Kedinasan, SPT & Lembar Disposisi** — Penerbitan Surat Perintah Tugas (SPT GTK) ber-QR verifikasi standar kedinasan Kemendikbud, buku agenda surat masuk & keluar (`AG-IN`/`AG-OUT`) dengan upload berkas fisik scan, instruksi disposisi Kepala Sekolah, serta sinkronisasi otomatis presensi saat menyetujui surat sakit/izin.
- 📖 **Perpustakaan Digital Modern** — Auto-Fill data buku via Google Books & Open Library API berdasarkan ISBN, In-Browser PDF/E-Book Reader layar penuh anti-copy, reservasi / booking buku mandiri (tahan 2 hari), review rating bintang, dan penerbitan Surat Bebas Perpustakaan digital.
- 👨‍🎓 **Kesiswaan Standar Kemendikbud** — Impor massal siswa via CSV dengan pemetaan kelas otomatis, dan cetak Lembar Buku Induk Siswa Format A–D (Pas Foto 3x4 & pengesahan Kepala Sekolah).
- 🌐 **Portal Publik & Pengaduan Tamu** — Halaman profil sekolah (`about.php`), pusat pengaduan publik tiket tamu (`kontak.php`) dengan nomor tiket otomatis (`TKT-YYYYMMDD-XXXX`) dan proteksi bot honeypot, serta papan informasi mading digital (`informasi.php`).
- 📚 **Dokumentasi Role Terperinci** — Buku panduan operasional lengkap untuk 5 peran pengguna (`Administrator.md`, `Staf.md`, `Guru.md`, `OrangTua.md`, `Siswa.md`).

---

## 🏗️ Modul & Fitur Lengkap

### PPDB Online Terpadu
- 4 Jalur seleksi: Reguler, Zonasi Domisili, Prestasi, dan Afirmasi
- Algoritma perhitungan otomatis skor gabungan (rapor + sertifikat prestasi / jarak zonasi)
- Verifikasi berkas 3 status (Lengkap, Perlu Revisi, Ditolak) dengan catatan alasan transparan
- Kartu pendaftaran ber-barcode dan QR Code hash SHA-256 untuk verifikasi keabsahan dokumen
- Panel admin: pengaturan status buka/tutup, gelombang pendaftaran, kuota daya tampung, periode tanggal, dan pesan penutupan kustom
- 1-Klik penerimaan calon siswa langsung membuat akun siswa, akun orang tua, kelas, dan tagihan awal

### Keuangan, SPP & Buku Kas Umum (BKU)
- Kasir pembayaran SPP dengan metode Transfer Bank, QRIS, dan Tunai
- Verifikasi bukti transfer pembayaran dan cetak kwitansi resmi berstempel digital
- Modul kas keluar operasional / Buku Kas Umum (`expenses.php`)
- Penomoran Bukti Kas Keluar otomatis (`EXP-YYYY/MM/NNN`)
- Pengelompokan kategori belanja operasional sekolah (ATK, listrik, internet, konsumsi, perawatan gedung, honorarium dinas)
- Upload scan nota/kwitansi pembelian fisik
- Monitoring neraca arus kas riil sekolah dan cetak laporan BKU siap audit

### Sarana & Prasarana (Sarpras)
- Pencatatan buku inventaris barang dan sarana per penempatan ruangan
- Klasifikasi kondisi barang (Baik, Rusak Ringan, Rusak Berat)
- Pencatatan sumber pendanaan (BOS Reguler, BOS Kinerja, Yayasan, Hibah) dan nilai perolehan aset
- Sirkulasi peminjaman sarana prasarana civitas sekolah dengan peringatan jatuh tempo (*overdue alert*)
- Konfirmasi pengembalian dan verifikasi kondisi fisik akhir barang
- Cetak rekapitulasi buku inventaris aset sekolah

### Persuratan Kedinasan & Kearsipan
- Pengajuan surat mandiri siswa & orang tua (Ket. Aktif, SKBB, SKL Sementara, Beasiswa, Izin Sakit)
- Integrasi presensi otomatis: persetujuan surat izin sakit/dispensasi langsung mencatat kehadiran di tabel presensi
- Penerbitan Surat Perintah Tugas (SPT / SPPD Guru & Pegawai) untuk tugas luar dinas, pelatihan MGMP, pengawasan ujian
- Format cetak standar Kemendikbud: Kop surat resmi, kode klasifikasi arsip, tanda tangan digital Kepala Sekolah, stempel basah, dan QR code hash
- Buku agenda kearsipan Surat Masuk (`AG-IN`) dan Surat Keluar (`AG-OUT`)
- Upload berkas scan surat dinas fisik dan lembar instruksi Disposisi Kepala Sekolah digital

### Perpustakaan Digital & Sirkulasi Modern
- Auto-Fill ISBN via Google Books API dan Open Library API
- In-Browser PDF/E-Book Reader langsung di browser dengan mode Fullscreen anti-copy
- Fitur Reservasi / Booking Mandiri buku fisik (stok ditahan selama 2 hari di meja sirkulasi)
- Review & Rating testimoni komunitas bintang (1–5) pada katalog buku
- Quick Scan barcode anggota & buku via webcam kamera
- Buku tamu presensi kehadiran fisik pengunjung perpustakaan
- Cetak label stiker barcode dan nomor panggil (*call number*) punggung buku
- Penerbitan Surat Keterangan Bebas Perpustakaan digital bagi siswa tingkat akhir

### Kesiswaan & Administrasi Sekolah
- Impor massal data siswa via Excel/CSV dengan pemetaan kelas otomatis dan pembuatan password default
- Unduh template CSV resmi kesiswaan
- Cetak Lembar Buku Induk Siswa Format A–D standar Kemendikbud (Pas Foto 3x4 & lembar pengesahan)
- Pengelolaan rombongan belajar (rombel) dan penugasan guru wali kelas
- Audit trail log aktivitas keamanan sistem dengan pencatatan IP address

### Portal Publik & Layanan Masyarakat
- Halaman Tentang Kami (`about.php`): Profil lembaga, visi-misi resmi, pilar ekosistem peran, dan counter statistik real-time
- Halaman Kontak & Layanan Aduan (`kontak.php`): Formulir tiket pengaduan publik dengan nomor tiket otomatis (`TKT-YYYYMMDD-XXXX`), jam operasional, dan peta navigasi
- Proteksi spam bot ganda: Honeypot Trap tersembunyi dan validasi CSRF token
- Kotak masuk pengaduan admin (`contact_messages.php`) untuk disposisi dan tindak lanjut keluhan
- Papan Informasi & Pengumuman Sekolah publik (`informasi.php` & `pengumuman.php`) dengan live search dan modal popup lampiran

---

## 🛠️ Tech Stack

| Layer | Teknologi |
|-------|-----------|
| Backend | PHP 8.0+ (Native, PDO Prepared Statements) |
| Database | MySQL 8.0+ / MariaDB 10.4+ (35 Tabel Relasional, Auto-Migration v11) |
| Frontend | Tailwind CSS v4 Browser CDN, Font Awesome 6.5, Plus Jakarta Sans |
| QR & Barcode | html5-qrcode (Scanner Webcam), qrcodejs (Generator Barcode & QR) |
| Web Server | Apache 2.4+ (XAMPP / Laragon) dengan `.htaccess` |

---

**Full Changelog**: https://github.com/saipul12c/Manajemen-php/compare/v1.0.0...v.1.1.0

---

# ⚡ Manajemen-PHP v1.0.0

> Rilis pertama — Sistem Manajemen Sekolah Terpadu berbasis PHP 8 + MySQL PDO dengan arsitektur 5 Role (Administrator, Staf, Guru, Orang Tua, Siswa).

---

## ✨ Highlights

- 🎓 **PPDB Online** — Portal pendaftaran peserta didik baru TA 2026/2027 dengan upload berkas, nomor registrasi otomatis, cetak kartu barcode, dan pipeline seleksi multi-tahap
- 📝 **Ujian & Asesmen Online** — 6 kategori (UTS, UKK, Ujian Harian, Latihan Harian/Mingguan/Bulanan), soal PG + Esai, token akses, timer, acak soal, auto-grading, dan sistem remedial
- ✅ **Presensi QR Code** — Scan kehadiran via kamera/webcam dengan generate kartu QR per siswa
- 📚 **E-Learning** — Upload dan distribusi materi pembelajaran (dokumen, video YouTube, Google Drive)
- 💰 **Keuangan & SPP** — Tagihan, pembayaran (transfer/tunai/QRIS), verifikasi, cetak kuitansi, dan laporan keuangan
- 📖 **Perpustakaan Digital** — Katalog buku, E-Book, sirkulasi peminjaman & pengembalian, denda otomatis
- 🤝 **Bimbingan Konseling** — Pencatatan pelanggaran & prestasi siswa dengan sistem poin
- 📢 **Pengumuman Cerdas** — Target per role & kelas, pin, kategori prioritas, tracking pembaca, auto pop-up darurat

---

## 🏗️ Modul & Fitur Lengkap

### Autentikasi & Keamanan
- Login dengan rate limiting (maks. 5x / 15 menit)
- Registrasi mandiri (siswa & orang tua)
- CSRF Protection (token 64-karakter)
- Session regeneration & secure cookie (`HttpOnly`, `SameSite`)
- PDO Prepared Statements (anti SQL Injection)
- Security Headers (CSP, X-Frame-Options, X-Content-Type-Options)
- Audit trail logging dengan IP address

### Akademik
- Jadwal pelajaran mingguan per kelas (Senin–Sabtu)
- Materi & E-Learning dengan tracking unduhan
- Tugas & pengumpulan dengan file attachment + penilaian guru
- Kalender akademik interaktif (akademik, libur, kegiatan, ujian)
- Buku nilai (Gradebook) — cetak & export
- Rapor digital semester dengan catatan wali kelas

### Ujian Online
- Editor soal Pilihan Ganda & Esai dengan gambar
- Token akses keamanan per ujian
- Timer countdown & randomize urutan soal
- Passing grade kustom & sistem remedial
- Cetak kartu ujian, hasil ujian, dan export data

### Administrasi
- Manajemen pengguna & kelas (CRUD)
- Panel verifikasi PPDB
- Pengaturan identitas sekolah (nama, logo, kepala sekolah, dsb.)
- Backup & restore database
- Audit log seluruh aktivitas sistem

### Komunikasi
- Pesan internal antar pengguna (badge notifikasi)
- Layanan surat & permohonan dengan cetak dokumen resmi

---

## 🛠️ Tech Stack

| Layer | Teknologi |
|-------|-----------|
| Backend | PHP 8.0+ (Native) |
| Database | MySQL 8.0+ / MariaDB 10.4+ (PDO) |
| Frontend | Tailwind CSS v4, Font Awesome 6.5 |
| QR Code | html5-qrcode, qrcodejs |
| Web Server | Apache (XAMPP / Laragon) |

## 📦 Instalasi Cepat

```bash
git clone https://github.com/saipul12c/Manajemen-php.git
```

1. Pindahkan ke `htdocs/` atau `www/`
2. Buat database `website_login` — tabel otomatis dibuat saat akses pertama
3. Buka `http://localhost/manajemen-php/`

## 👤 Akun Demo

| Role | Email | Password |
|------|-------|----------|
| Administrator | admin@gmail.com | admin123 |
| Staf | staff@gmail.com | staff123 |
| Guru | guru@gmail.com | guru123 |
| Orang Tua | orangtua@gmail.com | orangtua123 |
| Siswa | siswa@gmail.com | siswa123 |

---

**Full Changelog**: Rilis pertama — seluruh fitur baru.
