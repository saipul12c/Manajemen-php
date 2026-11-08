<p align="center">
  <img src="https://img.shields.io/badge/Version-1.3.0-blue?style=for-the-badge" alt="Version 1.3.0">
  <img src="https://img.shields.io/badge/PHP-8.0+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.0+">
  <img src="https://img.shields.io/badge/MySQL-8.0+-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL 8.0+">
  <img src="https://img.shields.io/badge/TailwindCSS-4.x-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white" alt="Tailwind CSS 4">
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License MIT">
</p>

<h1 align="center">⚡ Manajemen-PHP</h1>

<p align="center">
  <strong>Sistem Manajemen Sekolah Terpadu Berbasis Web</strong><br>
  Platform all-in-one untuk otomatisasi administrasi, akademik, keuangan, dan kepegawaian sekolah<br>
  dengan arsitektur multi-role dan antarmuka modern.
</p>

<p align="center">
  <a href="#-fitur-utama">Fitur</a> •
  <a href="#-arsitektur-sistem">Arsitektur</a> •
  <a href="#-sistem-role-hak-akses">Role</a> •
  <a href="#-buku-panduan-operasional-per-role">Panduan Role</a> •
  <a href="#-instalasi--setup">Instalasi</a> •
  <a href="#-panduan-penggunaan">Panduan</a> •
  <a href="#-skema-database">Database</a> •
  <a href="#-keamanan">Keamanan</a> •
  <a href="dokumentasi/RELEASE-github.MD">Catatan Rilis (v1.3.0)</a> •
  <a href="#-kontribusi">Kontribusi</a>
</p>

---

## 📋 Deskripsi Proyek

**Manajemen-PHP** adalah sistem informasi manajemen sekolah (School Management Information System / SMIS) yang dibangun menggunakan **PHP 8+ Native** dan **MySQL** dengan koneksi **PDO**. Sistem ini dirancang untuk mendigitalisasi seluruh proses operasional sekolah — mulai dari pendaftaran siswa baru (PPDB), manajemen akademik, sistem ujian online, hingga administrasi keuangan dan perpustakaan — dalam satu platform terpadu.

Aplikasi ini mengimplementasikan **Role-Based Access Control (RBAC)** dengan 5 peran pengguna yang memiliki hak akses dan tampilan dashboard berbeda, memastikan setiap stakeholder mendapatkan informasi dan fitur yang relevan dengan perannya masing-masing.

### 🎯 Tujuan Proyek

- **Efisiensi Administratif** — Mengurangi beban kerja manual staf tata usaha melalui otomatisasi proses bisnis sekolah.
- **Transparansi Akademik** — Memberikan akses real-time bagi orang tua untuk memantau perkembangan akademik anak.
- **Aksesibilitas Digital** — Menyediakan portal pendaftaran peserta didik baru (PPDB) yang dapat diakses kapan saja dan dari mana saja.
- **Keamanan Data** — Menerapkan standar keamanan web modern untuk melindungi data sensitif civitas sekolah.

---

## 🏗️ Arsitektur Sistem

### Tech Stack

| Layer          | Teknologi                                                                                 |
| -------------- | ----------------------------------------------------------------------------------------- |
| **Backend**    | PHP 8.0+ (Native, tanpa framework)                                                       |
| **Database**   | MySQL 8.0+ dengan PDO (Prepared Statements, 58 tabel relasional)                          |
| **Frontend**   | Tailwind CSS v4 (CDN), Font Awesome 6.5, Plus Jakarta Sans                                |
| **AI & NLP**   | Hybrid AI Engine (Kecerdasan Buatan Mandiri Lokal Tanpa API + Fallback Google Gemini / OpenAI LLM) |
| **Mobile & PWA**| Service Worker PWA, Web App Manifests, Android Studio Native Wrapper (Java WebView Shell) |
| **QR & Barcode**| Standalone Vector SVG QR Engine (Offline Fallback), html5-qrcode, qrcodejs                |
| **Web Server** | Apache (XAMPP / Laragon) dengan `.htaccess` (Larangan Eksekusi Skrip di `uploads/`)       |
| **Session**    | PHP Native Session dengan cookie `HttpOnly`, `SameSite=Lax`, Flag `Secure` di HTTPS        |

### Struktur Direktori

```
manajemen-php/
│
├── index.php                    # Landing page publik (hero interaktif + statistik real-time)
├── about.php                    # Halaman profil sekolah, visi-misi, pilar peran, & keunggulan
├── kontak.php                   # Halaman kontak, pusat bantuan & tiket pengaduan publik
├── informasi.php                # Papan informasi & pengumuman sekolah publik (mading digital)
├── pengumuman.php               # Alias pengalihan langsung ke informasi.php
├── berita.php                   # Portal publik berita, liputan kegiatan & artikel dewan guru
├── perpustakaan.php             # Katalog perpustakaan digital publik (OPAC & e-reader)
├── apps.php                     # Pusat unduhan & pemasangan multi aplikasi mobile (Android APK & PWA)
├── sw.js                        # Service Worker PWA root untuk caching offline & installability
├── verify_document.php          # Portal publik validasi keaslian dokumen & Tanda Tangan Elektronik (QR TTE)
├── .htaccess                    # Konfigurasi Apache (security headers, rewrite rules, error pages)
│
├── includes/                    # Template layout bersama untuk halaman publik
│   ├── navbar.php               #   Navbar bersama landing page & portal publik responsif
│   └── footer.php               #   Footer bersama, jam operasional, & informasi kontak
│
├── apps/                        # Ekosistem Aplikasi Mobile & Native Wrapper
│   └── android/                 #   Paket source code wrapper native Android Studio (Java Shell)
│       ├── README.md            #     Panduan kompilasi APK via Android Studio & PWABuilder
│       ├── app_config.json      #     Konfigurasi multi-app (endpoint host, package ID, permission, tema)
│       ├── cbt-app/             #     Aplikasi Android CBT Ujian (Kiosk Mode, Fullscreen, Anti-Curang)
│       ├── perpustakaan-app/    #     Aplikasi Android E-Perpustakaan (Barcode Scanner & PDF Viewer)
│       └── presensi-app/        #     Aplikasi Android Presensi (Akses Kamera QR & Geofencing GPS)
│
├── dokumentasi/                 # Arsip dokumentasi & riwayat rilis
│   ├── RELEASE-github.MD        #   Catatan rilis versi & changelog
│   └── dokumentasi-role/        #   Buku panduan operasional terperinci per peran pengguna
│       ├── Administrator.md     #     Buku panduan Super Admin (manajemen user, PPDB, audit, backup)
│       ├── Staf.md              #     Buku panduan Tata Usaha (persuratan, BKU, sarpras, legalisir, perpus)
│       ├── Guru.md              #     Buku panduan Tenaga Pendidik (KBM, ujian online, presensi, rapor, P5)
│       ├── OrangTua.md          #     Buku panduan Wali Murid (monitoring multi-anak, izin, bayar SPP)
│       └── Siswa.md             #     Buku panduan Peserta Didik (e-learning, ujian, QR, tracer study)
│
├── ppdb/                        # Modul PPDB Online Terpadu
│   ├── ppdb.php                 #   Portal pendaftaran 4 jalur & tracking seleksi mandiri
│   ├── ppdb_card.php            #   Cetak kartu pendaftaran resmi dengan barcode & QR verifikasi
│   ├── ppdb_cbt.php             #   Portal ujian CBT seleksi masuk online calon siswa baru
│   └── ppdb_verify.php          #   Verifikasi keaslian berkas & kartu PPDB via token SHA-256
│
├── auth/                        # Modul Autentikasi
│   ├── login.php                #   Halaman login dengan rate limiting & demo credentials
│   ├── forgot_password.php      #   Layanan pemulihan kata sandi mandiri ber-token kedaluwarsa
│   ├── logout.php               #   Terminasi sesi pengguna & reset token keamanan
│   └── register.php             #   Dialihkan ke PPDB Online (satu pintu registrasi resmi)
│
├── config/                      # Konfigurasi & Helper Sistem
│   ├── database.php             #   Koneksi PDO, auto-migration, CSRF, helper & scoring, TTE engine
│   ├── ai_chat_helper.php       #   Engine AI mandiri lokal, knowledge retrieval & connector Cloud LLM
│   └── upload_helper.php        #   Pusat validasi unggahan aman terpusat (MIME inspector & SVG sanitizer)
│
├── api/                         # Endpoint API Eksternal & Integrasi Webhook
│   ├── live_chat.php            #   API Real-time Live Chat & Asisten AI Cerdas Multi-Role / Tamu
│   ├── payment_webhook.php      #   Webhook listener pembayaran payment gateway (QRIS & VA)
│   └── rfid_attendance.php      #   REST API Gateway presensi perangkat fisik RFID gerbang sekolah
│
├── dashboard/                   # Area Dashboard (terproteksi login & role-based)
│   ├── index.php                #   Halaman utama dashboard (role-based widgets & feed)
│   ├── profile.php              #   Profil pengguna, NIP, gelar akademik, & TTE/Tanda Tangan Digital Guru
│   ├── apps_hub.php             #   Pusat manajemen & instalasi 3 aplikasi mobile terpadu (PWA & APK)
│   ├── sw.js                    #   Service Worker PWA area dashboard
│   │
│   ├── includes/                #   Template layout dashboard
│   │   ├── header.php           #     Header, navigasi sidebar responsif, badge notifikasi
│   │   └── footer.php           #     Footer dashboard
│   │
│   ├── admin/                   #   Panel Administrasi & Pengaturan
│   │   ├── users.php            #     CRUD pengguna, NIP, gelar akademik, & kelola TTE digital guru
│   │   ├── live_chat.php        #     Konsol pemantauan Live Chat real-time, Human Takeover & konfigurasi AI
│   │   ├── student_profile_print.php # Cetak Lembar Buku Induk Siswa (Standar Kemendikbud)
│   │   ├── student_360.php      #     Radar Profil Siswa 360 Derajat (akademik, disiplin, finansial, ekskul)
│   │   ├── promotion.php        #     Wizard Kenaikan Kelas & Kelulusan Massal per rombel
│   │   ├── contact_messages.php #     Inbox pesan tamu publik & status tindak lanjut tiket
│   │   ├── classes.php          #     Manajemen kelas & rombel
│   │   ├── ppdb.php             #     Verifikasi berkas, seleksi, & 1-klik aktivasi akun siswa
│   │   ├── ppdb_attendance_print.php # Cetak lembar presensi & berita acara ruang ujian PPDB
│   │   ├── settings.php         #     Pengaturan identitas sekolah, kop surat, stempel, TTD KS & kuota PPDB
│   │   ├── wa_gateway.php       #     WhatsApp Gateway API, template pesan & audit log pengiriman
│   │   ├── external_apis.php    #     Manajemen integrasi Multi-API Gateway (WA, Payment, SMTP, Maps, Cuaca)
│   │   ├── audit_logs.php       #     Log aktivitas (audit trail)
│   │   └── backup.php           #     Backup & restore database
│   │
│   ├── akademik/                #   Modul Akademik & Kurikulum Merdeka
│   │   ├── timetable.php        #     Jadwal pelajaran mingguan
│   │   ├── journal.php          #     Jurnal mengajar KBM harian (agenda kelas, inval, cetak)
│   │   ├── curriculum.php       #     Perangkat ajar & Kurikulum Merdeka (Modul Ajar, RPP, Prota, Promes)
│   │   ├── homeroom.php         #     Dashboard monitoring Wali Kelas (kehadiran, tugas, poin BK)
│   │   ├── p5_projects.php      #     Manajemen tema, dimensi & fasilitator projek P5
│   │   ├── p5_assessment.php    #     Lembar penilaian ketercapaian sub-elemen P5 (MB, SB, BSH, SAB)
│   │   ├── p5_report.php        #     Cetak lembar rapor resmi P5 standar BSKAP Kemendikbudristek
│   │   ├── report_card_bulk.php #     Cetak e-rapor massal satu kelas format resmi dinas
│   │   ├── materials.php        #     E-Learning & materi pembelajaran
│   │   ├── assignments.php      #     Tugas & pengumpulan tugas
│   │   ├── calendar.php         #     Kalender akademik & agenda
│   │   ├── gradebook.php        #     Buku nilai (gradebook)
│   │   ├── gradebook_export.php #     Export buku nilai ke spreadsheet
│   │   ├── gradebook_print.php  #     Cetak buku nilai
│   │   └── report_card.php      #     Rapor digital siswa
│   │
│   ├── alumni/                  #   Modul Alumni, Tracer Study & Legalisir Digital
│   │   ├── tracer_study.php     #     Kuesioner penelusuran lulusan, statistik karir/kuliah & ekspor CSV BAN-SM
│   │   ├── legalisir.php        #     Layanan permohonan legalisir ijazah/transkrip digital oleh alumni & verifikasi TU
│   │   └── legalisir_print.php  #     Cetak lembar pengesahan legalisir ijazah berstempel & QR TTE validasi
│   │
│   ├── Modul-ujian/             #   Sistem Ujian & Asesmen Online (CBT)
│   │   ├── exams.php            #     Manajemen bank ujian & latihan
│   │   ├── exam_questions.php   #     Editor soal (PG + Esai + Impor CSV)
│   │   ├── exam_take.php        #     Halaman mengerjakan ujian (Anti-Cheat & Proctoring)
│   │   ├── exam_results.php     #     Hasil ujian & Analisis Butir Soal (Indeks kesukaran & distraktor)
│   │   ├── exam_card.php        #     Cetak kartu ujian
│   │   ├── exam_print.php       #     Cetak hasil ujian
│   │   ├── exam_export.php      #     Export hasil ujian
│   │   ├── manifest.json        #     Web App Manifest PWA CBT Ujian Sekolah
│   │   └── icons/icon.svg       #     Ikon aplikasi CBT
│   │
│   ├── presensi/                #   Modul Presensi Kehadiran
│   │   ├── teacher_attendance.php#    Presensi mandiri guru/staf (Smart Clock-In, Clock-Out, GPS Geofencing)
│   │   ├── attendance.php       #     Input & rekap presensi siswa
│   │   ├── scan_qr.php          #     Scan QR Code presensi kamera
│   │   ├── qr_card.php          #     Generate kartu QR siswa
│   │   ├── attendance_report.php#     Laporan statistik presensi
│   │   ├── attendance_print.php #     Cetak laporan presensi
│   │   ├── attendance_export.php#     Export data presensi
│   │   ├── manifest.json        #     Web App Manifest PWA Presensi
│   │   └── icons/icon.svg       #     Ikon aplikasi presensi
│   │
│   ├── keuangan/                #   Modul Keuangan & SPP
│   │   ├── payments.php         #     Tagihan, pembayaran online gateway & simulasi instant settlement
│   │   ├── payroll.php          #     Penggajian & honor mengajar guru (otomatisasi KBM, wali kelas, potongan)
│   │   ├── payroll_slip.php     #     Cetak slip gaji digital guru resmi standar dinas
│   │   ├── expenses.php         #     Buku Kas Umum (BKU) & pengeluaran kas operasional
│   │   ├── receipt.php          #     Cetak kuitansi pembayaran
│   │   ├── receipt_verify.php   #     Portal publik verifikasi keabsahan kuitansi pembayaran via QR
│   │   ├── financial_report.php #     Laporan keuangan
│   │   ├── financial_print.php  #     Cetak laporan keuangan
│   │   └── financial_export.php #     Export data keuangan
│   │
│   ├── sarpras/                 #   Modul Sarana & Prasarana (Sarpras)
│   │   └── inventory.php        #     Buku inventaris aset ruangan, kondisi barang, & sirkulasi pinjam
│   │
│   ├── perpustakaan/            #   Modul Perpustakaan Digital Terpadu
│   │   ├── _nav.php             #     Sub-navigasi terpadu modul perpustakaan
│   │   ├── books.php            #     Katalog buku, e-book, & Auto-Fill ISBN via API
│   │   ├── loans.php            #     Sirkulasi peminjaman, perpanjangan, & denda
│   │   ├── reservations.php     #     Manajemen antrean booking mandiri buku
│   │   ├── scan.php             #     Quick scan QR sirkulasi buku (webcam)
│   │   ├── visitors.php         #     Buku tamu presensi pengunjung perpustakaan
│   │   ├── print_labels.php     #     Cetak label barcode & nomor panggil buku
│   │   ├── clearance.php        #     Penerbitan surat bebas pustaka digital
│   │   ├── manifest.json        #     Web App Manifest PWA E-Perpustakaan
│   │   └── icons/icon.svg       #     Ikon aplikasi perpustakaan
│   │
│   ├── bk/                      #   Modul Bimbingan Konseling (BK)
│   │   ├── counseling.php       #     Catatan pelanggaran, prestasi, & ambang kritis sanksi berat (>= 100 poin)
│   │   └── counseling_letter.php#     Cetak surat resmi BK (SP 1, SP 2 Pemanggilan, SP 3 Skorsing, & SK DO / Pengembalian Siswa)
│   │
│   ├── ekskul/                  #   Modul Ekstrakurikuler & Prestasi Siswa
│   │   ├── index.php            #     Katalog ekskul, pendaftaran mandiri siswa, kuota, & manajemen klub
│   │   ├── detail.php           #     Manajemen anggota, input predikat e-rapor, sesi latihan, & prestasi
│   │   ├── attendance.php       #     Lembar presensi mingguan pembina (1-klik hadir semua & rekap status)
│   │   ├── achievements.php     #     Galeri Hall of Fame prestasi siswa & ekspor portofolio akreditasi
│   │   └── print_certificate.php#    Cetak piagam penghargaan resmi & sertifikat ber-QR verifikasi TTE
│   │
│   ├── informasi/               #   Modul Informasi, Berita & Pengumuman
│   │   ├── announcements.php    #     CRUD pengumuman (rich text editor)
│   │   ├── news.php             #     Manajemen redaksi artikel berita & liputan kegiatan sekolah
│   │   └── print_announcement.php#    Cetak pengumuman resmi
│   │
│   ├── pesan/                   #   Modul Komunikasi Internal
│   │   └── messages.php         #     Pesan antar pengguna (chat)
│   │
│   └── surat/                   #   Modul Layanan Surat & Permohonan
│       ├── requests.php         #     Pengajuan surat siswa/ortu & penerbitan resmi TU (SPT GTK)
│       ├── request_print.php    #     Cetak surat dinas, SKBB, SKL, & SPT Tugas Guru/Pegawai
│       └── archives.php         #     Buku agenda surat masuk & keluar + lembar disposisi KS
│
├── error/                       # Custom Error Pages
│   ├── error_data.php           #   Metadata & konten halaman error
│   ├── render.php               #   Template renderer error page
│   ├── 400.php                  #   Bad Request
│   ├── 401.php                  #   Unauthorized
│   ├── 403.php                  #   Forbidden
│   ├── 404.php                  #   Not Found
│   ├── 419.php                  #   Page Expired (CSRF)
│   ├── 500.php                  #   Internal Server Error
│   └── 503.php                  #   Service Unavailable
│
├── sql/                         # Skema Database
│   └── database.sql             #   File SQL lengkap (DDL + seed data 58 tabel)
│
└── uploads/                     # Direktori Berkas Upload Sistem
    ├── .htaccess                #   Proteksi Apache: Larangan eksekusi skrip PHP/CGI di folder unggahan
    ├── announcements/           #   Lampiran dokumen edaran pengumuman
    ├── apps/                    #   File installer APK Android (CBT, Presensi, Perpustakaan)
    ├── assignments/             #   Lampiran soal tugas & berkas pengumpulan jawaban siswa
    ├── books/                   #   Gambar cover buku & file modul / e-book PDF
    ├── chat_attachments/       #   Lampiran berkas obrolan live chat (gambar & dokumen tersanitasi)
    ├── exams/                   #   Gambar pendukung soal ujian online
    ├── expenses/                #   Scan berkas nota / kwitansi kas keluar BKU
    ├── legalisir/               #   Scan berkas ijazah/transkrip asli permohonan legalisir
    ├── letters/                 #   Lampiran surat izin/sakit & scan agenda surat masuk/keluar
    ├── materials/               #   Berkas materi pembelajaran E-Learning
    ├── news/                    #   Thumbnail & gambar artikel liputan berita sekolah
    ├── payments/                #   Bukti transfer pembayaran tagihan SPP
    ├── ppdb/                    #   Dokumen pendaftaran calon siswa (rapor, KK, akta, foto)
    └── signatures/              #   Scan tanda tangan digital (PNG/SVG) & stempel resmi sekolah
```

---

## 👥 Sistem Role (Hak Akses)

Manajemen-PHP mengimplementasikan 5 peran pengguna dengan hak akses berlapis:

| # | Role                | Deskripsi                               | Hak Akses Utama                                                                                                     |
|---|---------------------|-----------------------------------------|----------------------------------------------------------------------------------------------------------------------|
| 1 | 🔴 **Administrator** | Pengelola tertinggi sistem              | Full access — CRUD semua data, manajemen pengguna & kelas, wizard kenaikan/kelulusan kelas, Radar Profil Siswa 360, integrasi Multi-API Gateway, PPDB & seleksi CBT, berita sekolah, audit log, backup database, distribusi apps mobile |
| 2 | 🟠 **Staf**          | Tata usaha / administrasi sekolah       | Administrasi persuratan, inventaris sarpras & pinjam, Buku Kas Umum (BKU), verifikasi SPP & validasi kuitansi, verifikasi & cetak legalisir digital ijazah, instrumen akreditasi Tracer Study BAN-SM, PPDB & cetak lembar presensi/berita acara ruang (DHPU), kelola akun siswa & ortu, impor CSV, cetak buku induk siswa, inbox tamu |
| 3 | 🟢 **Guru**          | Pengajar / tenaga pendidik              | Jurnal mengajar KBM, perangkat ajar Kurikulum Merdeka, fasilitator & penilaian Projek P5, presensi mandiri (GPS Geofencing Haversine), bank soal CBT proctoring & analisis butir, buku nilai, e-rapor massal Wali Kelas, Student 360, pembina ekskul, publikasi berita sekolah |
| 4 | 🟣 **Orang Tua**     | Wali murid / orang tua siswa            | Monitoring nilai anak, presensi real-time, Radar Profil Siswa 360, tagihan & bayar SPP online (QRIS/VA), verifikasi keaslian kuitansi QR, permohonan surat izin sakit online, pesan konsultasi ke guru, catatan BK |
| 5 | 🔵 **Siswa & Alumni**| Peserta didik aktif & alumni lulusan    | Pengerjaan ujian online anti-cheat proctoring, materi & tugas, presensi QR, jadwal, rapor, perpustakaan & in-browser e-reader, ekskul, pengajuan surat mandiri, instalasi multi-aplikasi PWA/APK, kuesioner Tracer Study alumni, dan pengajuan legalisir ijazah online |

> **Catatan:** Registrasi publik hanya mengizinkan role `siswa` dan `orang_tua`. Role `administrator`, `staf`, dan `guru` hanya dapat dibuat oleh Administrator melalui panel admin.

### 📚 Buku Panduan Operasional Per Role

Untuk panduan mendalam langkah-demi-langkah (SOP), alur kerja terperinci, dan batasan wewenang teknis untuk setiap stakeholder sekolah, silakan pelajari manual operasional khusus yang telah disusun secara profesional:

| Peran Pengguna | Dokumen Panduan | Fokus & Cakupan Operasional Utama |
| :--- | :--- | :--- |
| 🔴 **Administrator** | [**Administrator.md**](dokumentasi/dokumentasi-role/Administrator.md) | Otoritas penuh sistem, tata kelola akun 5 role, pengaturan kuota & gelombang PPDB, verifikasi skoring 4 jalur, audit log keamanan, pemeliharaan database (*backup & restore*), serta cetak lembar buku induk siswa Kemendikbud. |
| 🟠 **Staf Tata Usaha** | [**Staf.md**](dokumentasi/dokumentasi-role/Staf.md) | Operasional harian kesiswaan, verifikasi permohonan surat & sinkronisasi absensi otomatis, penerbitan SPT dinas GTK ber-QR verifikasi, Buku Agenda Surat Masuk/Keluar (`AG-IN`/`AG-OUT`), Buku Kas Umum (BKU), inventaris sarpras, dan sirkulasi perpustakaan (Auto-Fill ISBN). |
| 🟢 **Guru / Pendidik** | [**Guru.md**](dokumentasi/dokumentasi-role/Guru.md) | Jurnal KBM harian & inval, administrasi Kurikulum Merdeka (Modul Ajar/RPP/Silabus/Prota/Promes), presensi mandiri (Smart Clock-In/Out), bank asesmen CBT 6 kategori & analisis butir soal (indeks $P$ & distraktor), dashboard & monitoring rombel Wali Kelas, cetak e-rapor massal kelas, serta peminjaman sarpras mandiri. |
| 🟣 **Orang Tua / Wali**| [**OrangTua.md**](dokumentasi/dokumentasi-role/OrangTua.md) | Pemantauan kehadiran anak real-time, dukungan multi-anak (*multi-child switcher*), kontrol pengerjaan tugas & rapor digital, pengajuan izin sakit online (upload surat dokter), pembayaran mandiri SPP via Transfer/QRIS & unduh kwitansi sah, serta konsultasi privat ke guru. |
| 🔵 **Siswa / Murid**   | [**Siswa.md**](dokumentasi/dokumentasi-role/Siswa.md) | Unduh modul belajar, serahkan berkas jawaban tugas, pengerjaan asesmen ujian online ber-countdown timer, kartu presensi digital QR Code, In-Browser E-Book reader layar penuh, booking buku mandiri (reservasi 2 hari), serta pengajuan surat siswa mandiri. |

---

## ✨ Fitur Utama

### 🏠 Portal Publik, Profil Sekolah & Pusat Layanan Bantuan

- **Landing Page Interaktif (`index.php`)**:
  - Hero section modern dengan animasi visual, countdown/highlight status PPDB real-time, dan navigasi terpadu
  - Tautan cepat ke seluruh layanan civitas sekolah (PPDB, Pengumuman, Perpustakaan OPAC, Profil, Kontak)
- **Halaman Profil Sekolah (`about.php`)**:
  - Profil kelembagaan, visi & misi resmi, sambutan kepala sekolah, dan struktur ekosistem 5 peran pengguna
  - Counter statistik civitas real-time (jumlah siswa aktif, guru/tenaga pendidik, koleksi perpustakaan, dan pendaftar PPDB)
- **Pusat Bantuan & Pengaduan Tamu (`kontak.php`)**:
  - Formulir pengiriman tiket pengaduan / pesan publik dengan penomoran tiket otomatis (`TKT-YYYYMMDD-XXXX`)
  - Proteksi anti-spam ganda: **Honeypot Bot Trap** dan validasi **CSRF Token**
  - Terintegrasi langsung dengan kotak masuk admin
  - Informasi jam operasional layanan TU, kontak darurat, email, dan integrasi peta navigasi sekolah
- **📢 Papan Informasi & Pengumuman Mandiri (`informasi.php` & `pengumuman.php`)**:
  - Akses publik tanpa perlu login untuk transparansi informasi civitas sekolah
  - Menampilkan pengumuman resmi berkategori: **Penting, Darurat, Akademik, Kegiatan, dan Umum**
  - **Banner Peringatan Khusus** untuk edaran darurat / disematkan (*pinned*)
  - **Live Search & Filter Kategori Interaktif** berbasis JavaScript instan
  - **Modal Detail Pop-Up** lengkap dengan pembuat, waktu tayang, pratinjau lampiran PDF, dan tombol cetak
  - **Widget Kalender & Agenda Mendatang** terintegrasi dengan tabel `calendar_events`
- **📰 Portal Berita & Artikel Liputan Sekolah (`berita.php`)**:
  - Portal jurnalisme sekolah publik yang menampilkan liputan kegiatan, prestasi siswa, dan opini pendidik
  - Hero banner artikel utama (*featured headlines*), filter kategori berita, dan pencarian instan
  - Halaman baca detail artikel lengkap dengan hit counter pembaca, integrasi buku referensi perpus, event kalender, dan daftar berita terkait
- **📲 Pusat Pemasangan Multi-Aplikasi Mobile (`apps.php`)**:
  - Portal unduh terpadu untuk 3 aplikasi mandiri: **CBT Ujian**, **Presensi Kehadiran**, dan **E-Perpustakaan**
  - Generator QR Code dinamis otomatis mendeteksi alamat IP / domain server untuk pemasangan cepat di HP Android/iOS
  - Indikator status ketersediaan file installer APK langsung dari server (`uploads/apps/`)
- **🔑 Pemulihan & Reset Kata Sandi Mandiri (`auth/forgot_password.php`)**:
  - Alur pemulihan akun mandiri 2 langkah (identifikasi username/NISN/email + verifikasi nomor WA/Email)
  - Pengiriman token reset berbatas waktu (kedaluwarsa 1 jam) dengan enkripsi SHA-256
  - Tombol bantuan langsung ke Helpdesk WhatsApp sekolah untuk kendala akun gawat darurat
- **🤖 Asisten Virtual AI Mandiri & Real-Time Live Chat (`api/live_chat.php`)**:
  - Widget live chat interaktif untuk pengunjung publik (sebelum login) dan civitas sekolah (setelah login)
  - Penjawab cerdas otomatis 24/7 seputar pendaftaran PPDB, kalender akademik, tagihan SPP, presensi, buku perpustakaan, konseling BK, dan kegiatan ekskul
  - Mode alih respon (*Human Takeover*) langsung ke staf tata usaha atau admin, serta integrasi tombol kontak darurat WhatsApp Helpdesk

### 🤖 Asisten Virtual AI Mandiri & Real-Time Live Chat System

Inovasi layanan percakapan interaktif cerdas tanpa ketergantungan API pihak ketiga:

- **Arsitektur Cerdas Hybrid (In-House Rule Engine + Cloud LLM)**:
  - **Kecerdasan Buatan Mandiri Lokal**: 100% berjalan di server sekolah tanpa biaya token atau kuota API eksternal. Berbasis *Context-Aware Knowledge Base* yang membaca data sekolah secara langsung dan real-time dari database (profil, PPDB, agenda, koleksi perpustakaan, tagihan).
  - **Integrasi Cloud LLM Opsional**: Kompatibel dengan model Google Gemini, OpenAI GPT, atau Self-Hosted Ollama/LocalAI dengan pendekatan RAG (*Retrieval-Augmented Generation*).
  - **Pencegah Gangguan Otomatis (*Auto-Fallback*)**: Bila kuota API eksternal habis atau jaringan internet luar terputus, sistem secara mulus beralih ke engine AI mandiri internal tanpa membuat chat terputus.
- **Pengalaman Pengguna Interaktif (Live Chat Experience)**:
  - Tersedia di seluruh halaman publik untuk memandu calon wali murid dan tamu tanpa perlu membuat akun.
  - Sesi otomatis terhubung untuk pengguna login (Guru, Siswa, Orang Tua, Staf) dengan identifikasi peran instan.
  - Efek animasi pengetikan (*live typing indicator*) yang merefleksikan kecepatan respon manusiawi.
  - Riwayat obrolan tersimpan otomatis dengan enkripsi sesi token UUIDv4.
- **Konsol Dasbor Administrator & Staf (`dashboard/admin/live_chat.php`)**:
  - Pemantauan seluruh sesi obrolan aktif secara real-time.
  - **Fitur Human Takeover**: Petugas admin atau staf piket dapat mengambil alih percakapan bot dengan 1 klik untuk memberikan jawaban spesifik.
  - **Pustaka Balasan Cepat (*Quick Reply Templates*)**: Koleksi template respon kilat untuk mempercepat penanganan pertanyaan yang sering diajukan (*FAQ*).
  - **Live AI Test Bench**: Pengujian respons bot AI secara langsung di panel admin sebelum diterapkan ke publik.
  - Filter sesi status dinamis (`Aktif`, `Selesai / Ditutup`, `Diekskalasi ke Staf`).

### 🛡️ Pusat Pengamanan Berkas & Media Terpusat Skala Enterprise

Pengamanan menyeluruh terhadap seluruh aset berkas yang diunggah ke dalam sistem aplikasi:

- **Engine Validasi Berkas Terpusat (`config/upload_helper.php`)**:
  - Fungsi tunggal `handleSecureUpload()` untuk menangani seluruh proses validasi, pemeriksaan biner, sanitasi, dan penyimpanan berkas.
  - **Inspeksi Tipe MIME Biner Asli**: Menggunakan PHP `finfo_file()` dan `exif_imagetype()` untuk memverifikasi *magic bytes* fisik berkas. Tidak mempercayai ekstensi atau header `Content-Type` yang dikirimkan peramban pengguna (*anti-MIME spoofing*).
  - **Pengacakan Kriptografis Nama Berkas**: Seluruh berkas otomatis diubah namanya menggunakan hash acak 32 karakter heksadesimal (`bin2hex(random_bytes(16))`) guna mengeliminasi serangan *Direct Object Reference*, duplikasi nama, dan *Directory Climbing* (`../`).
  - **Whitelist Ekstensi Ketat**: Ekstensi dikelompokkan secara spesifik per peruntukan:
    - *Dokumen*: `.pdf`, `.docx`, `.doc`, `.xlsx`, `.xls`
    - *Gambar*: `.jpg`, `.jpeg`, `.png`, `.webp`, `.gif`
    - *Tanda Tangan*: `.png`, `.svg`, `.jpg`, `.jpeg`
  - **Sanitasi Total Berkas Vektor SVG dari Stored XSS**: Pembersihan XML/DOM parser otomatis yang melucuti tag `<script>`, atribut penanganan event JavaScript (`onload`, `onerror`, `onclick`), namespace eksternal, dan elemen berbahaya `<foreignObject>`.
- **Proteksi Server Apache di Folder Unggahan (`uploads/.htaccess`)**:
  - Menonaktifkan mesin eksekusi PHP (`php_flag engine off`).
  - Menetapkan `SetHandler default-handler` untuk memperlakukan semua file hanya sebagai teks/biner statis.
  - Memblokir langsung akses eksekusi ke ekstensi berbahaya: `.php`, `.php3`, `.php4`, `.php5`, `.phtml`, `.phar`, `.inc`, `.cgi`, `.sh`, `.asp`.
- **Audit & Hardening 23 Titik Akses Unggahan**:
  - Pasfoto Profil Pengguna, Logo Sekolah & Favicon, TTD Kepala Sekolah, Stempel Institusi, Scan TTD Guru, Bukti Transfer SPP, Berkas Pengumpulan Tugas, Bahan Ajar E-Learning, Modul Ajar Kurikulum Merdeka, Thumbnail Berita, Galeri Foto, Lampiran Kasus Konseling BK, Berkas PPDB (KK/Akta/Ijazah), Scan Ijazah Legalisir Alumni, Cover Buku Perpus, E-Book PDF Perpus, Piagam Prestasi Ekskul, Arsip Surat Masuk/Keluar, Lampiran Surat Keterangan, Gambar CBT Ujian, Foto Sarpras, Rekam Medis UKS, dan Berkas Backup Basis Data.

### 🔏 Arsitektur Tanda Tangan Elektronik (TTE), Stempel Resmi Anti-Scraping & Kop Surat Presisi

Standardisasi tata kelola pengesahan dokumen resmi digital dan cetak dinas:

- **Mesin Render Pengesahan Terpadu (`renderOfficialSignatureHtml`)**:
  - **Efek Tinta Basah Realistis (*Ink Blending*)**: Menggunakan teknik CSS `mix-blend-mode: multiply` yang menghasilkan efek transparan alami di mana stempel basah warna biru/ungu tampak bertumpuk di atas guratan tinta tanda tangan dan tekstur kertas dokumen.
  - **Proteksi Anti-Scraping & Anti-Cloning Aset Resmi**: Elemen gambar tanda tangan dan cap stempel diproteksi dengan CSS interaktif tingkat tinggi (`pointer-events: none; -webkit-user-drag: none; user-select: none; -webkit-touch-callout: none;`) sehingga tidak dapat diunduh lewat klik kanan, diseret, maupun diekstrak secara ilegal untuk pemalsuan dokumen di luar sistem.
  - **Preservasi Warna Cetak Presisi (*Print Fidelity*)**: Menerapkan deklarasi CSS `@media print` dengan `-webkit-print-color-adjust: exact; print-color-adjust: exact;` sehingga warna stempel, garis kop surat, dan badge status tidak pudar saat dicetak atau diekspor ke PDF.
  - **Generator Kode QR Vektor Offline Fallback (Air-Gapped Ready)**: Mesin pembubuh QR Code verifikasi dokumen kini dilengkapi generator internal vektor SVG mandiri murni (*inline vector*). Sistem tetap dapat merender kode QR verifikasi valid beresolusi tinggi tanpa menghasilkan gambar rusak (*broken image*) meskipun server berada dalam jaringan intranet lokal tanpa internet publik.
- **Standarisasi Kop Surat pada 21 Berkas Template Resmi**:
  - Menggunakan helper `resolveAssetUrl()` untuk menyelesaikan path logo dan aset dinas secara adaptif di seluruh level folder (root maupun subdirektori).
  - Diterapkan secara seragam pada 21 template cetak resmi: Buku Rapor Merdeka, Rapor P5, Kuitansi SPP, Slip Gaji Guru, Kartu Peserta CBT, Berita Acara Ujian, Legalisir Ijazah, Surat Panggilan BK, Surat Bebas Pustaka, Surat Keterangan Siswa, Biodata Buku Induk Dapodik, dsb.
- **Panel Pengelolaan Kredensial & TTE Guru oleh Administrator (`dashboard/admin/users.php`)**:
  - Administrator dapat menetapkan NIP / NUPTK resmi dan gelar akademik guru (`title_degree`, misal: `S.Pd., M.M.`).
  - Meninjau pratinjau langsung scan tanda tangan digital guru di modal edit pengguna.
  - Mengunggah berkas scan tanda tangan baru dengan pembersihan latar belakang otomatis.
  - Opsi 1-klik untuk mereset tanda tangan ke generator SVG resmi berbasis kaligrafi nama digital.

### 🎓 PPDB Online (Penerimaan Peserta Didik Baru Terpadu)

- **Portal Pendaftaran Mandiri 4 Jalur (`ppdb/ppdb.php`)**:
  - **Jalur Reguler / Tes Akademik**: Seleksi berbasis tes dan nilai rapor
  - **Jalur Zonasi Domisili**: Seleksi berbasis jarak kilometer rumah ke sekolah
  - **Jalur Prestasi**: Poin bonus penghargaan sertifikat berjenjang (sekolah s/d internasional)
  - **Jalur Afirmasi / KIP**: Afirmasi siswa dari keluarga ekonomi rentan
- **Kalkulator Nilai Otomatis (`calculatePpdbScore`)**:
  - Menghitung rata-rata nilai rapor 4 mapel pokok (Matematika, IPA, Bahasa Indonesia, Bahasa Inggris)
  - Otomatis mengkalkulasi bobot bonus prestasi dan skoring prioritas jarak zonasi secara akurat
- **Verifikasi Berkas Dokumen Multi-Status**:
  - Panitia dapat menandai status berkas: **Lengkap & Valid**, **Perlu Revisi**, atau **Ditolak**
  - Catatan revisi/penolakan tampil transparan di portal pelacakan status calon siswa
- **Verifikasi Keaslian Kartu Pendaftaran via QR Code (`ppdb_verify.php`)**:
  - Kartu pendaftaran resmi dilengkapi Barcode registrasi dan QR Code ber-token enkripsi SHA-256
  - Panitia/petugas dapat memindai QR code untuk memvalidasi keabsahan data tanpa risiko manipulasi berkas fisik
- **Otomatisasi 1-Klik Penerimaan Siswa Baru**:
  - Saat panitia menetapkan status calon siswa menjadi **Diterima** ke kelas/jurusan:
    1. Otomatis membuat/mengaktifkan akun login siswa di tabel `users`
    2. Otomatis membuat akun wali murid (orang tua) dan relasi pada tabel `parent_students`
    3. Otomatis mengaitkan kewajiban tagihan keuangan awal siswa baru pada tabel `student_bills`
- **Manajemen Gelombang & Kuota PPDB (`dashboard/admin/settings.php`)**:
  - Admin dapat membuka/menutup portal PPDB, mengubah nama gelombang, membatasi kuota daya tampung, mengatur batas periode tanggal, dan menampilkan pesan penutupan kustom
- **📝 Ujian CBT Seleksi Masuk Online Calon Siswa (`ppdb/ppdb_cbt.php`)**:
  - Ujian seleksi berbasis peramban mandiri untuk menguji kompetensi dasar calon siswa baru
  - Antarmuka standar nasional: navigasi 1 soal per layar, auto-save jawaban Ajax instan, papan nomor soal dinamis, penanda tombol *Ragu-ragu*, dan sinkronisasi timer server
  - Otentikasi aman melalui verifikasi nomor registrasi, tanggal lahir, dan hash QR token kartu pendaftaran
  - Hasil skor otomatis tersimpan ke `cbt_score` dan langsung mempengaruhi bobot kelulusan calon siswa
- **🖨️ Cetak Presensi DHPU & Berita Acara Seleksi PPDB (`dashboard/admin/ppdb_attendance_print.php`)**:
  - Format cetak resmi Daftar Hadir Peserta Ujian (DHPU) PPDB dan Berita Acara Ruang Ujian
  - Filter interaktif per tanggal tes, nomor ruang ujian, dan sesi pelaksanaan
  - Dilengkapi kolom tanda tangan peserta, rekapitulasi kehadiran (Hadir/Tidak Hadir), dan tanda tangan pengawas ruang dinas

### 📚 Modul Akademik, KBM & Kurikulum Merdeka

- **🌟 Projek Penguatan Profil Pelajar Pancasila / P5**:
  - **Manajemen Projek & Tema P5**: Konfigurasi tema resmi Kemendikbudristek (Gaya Hidup Berkelanjutan, Kearifan Lokal, Bhinneka Tunggal Ika, Bangunlah Jiwa dan Raganya, Suara Demokrasi, Rekayasa dan Teknologi, Kewirausahaan, Kebekerjaan), fase kurikulum, penunjukan koordinator & tim fasilitator pendidik
  - **Pemetaan Dimensi & Sub-Elemen P5**: Integrasi dimensi Beriman, Bertakwa & Berakhlak Mulia, Berkebinekaan Global, Bergotong Royong, Mandiri, Bernalar Kritis, dan Kreatif ke dalam target capaian fase
  - **Rubrik Penilaian Ketercapaian P5**: Input capaian siswa per sub-elemen berbasis 4 skala standar: **MB** (Mulai Berkembang), **SB** (Sedang Berkembang), **BSH** (Berkembang Sesuai Harapan), dan **SAB** (Sangat Berkembang)
  - **Cetak Lembar Rapor P5 Resmi**: Format cetak rapor P5 standar BSKAP Kemendikbudristek lengkap dengan deskripsi perkembangan projek, catatan proses fasilitator, dan pengesahan Kepala Sekolah

- **Jurnal Mengajar KBM Harian (`dashboard/akademik/journal.php`)**:
  - Pencatatan agenda tatap muka kelas, mata pelajaran, durasi jam pelajaran, materi pokok/topik bahasan
  - Catatan pencapaian ketuntasan kompetensi siswa dan catatan kondisi/situasi kelas
  - Rangkuman absensi siswa di kelas per sesi tatap muka
  - Penanganan status KBM fleksibel: `Terlaksana`, `Terkendala`, atau `Digantikan Guru Piket (Inval)` disertai pemilihan guru pengganti
  - Filter interaktif per bulan/kelas dan cetak rekapitulasi buku agenda mengajar resmi siap supervisi
- **Perangkat Ajar & Administrasi Kurikulum**:
  - Manajemen berkas Modul Ajar, RPP, Silabus / Alur Tujuan Pembelajaran (ATP), Program Tahunan (Prota), Program Semester (Promes), dan KKTP
  - Fleksibilitas metode: Unggah langsung dokumen (*PDF/DOCX/XLSX*) atau tautan cloud (*Google Drive / OneDrive*)
  - Pipeline supervisi terpadu: `Draft → Diajukan → Disetujui / Perlu Revisi` lengkap dengan catatan evaluasi dari tim kurikulum / kepala sekolah
- **Dashboard & Monitoring Wali Kelas**:
  - Panel kendali khusus wali kelas untuk memantau rombel binaan secara holistik
  - Statistik kehadiran rata-rata siswa kelas binaan (rasio hadir, izin, sakit, dan alpa)
  - Radar tugas mata pelajaran yang belum dikumpulkan oleh siswa binaan
  - Pemantauan rekam kedisiplinan BK: akumulasi poin pelanggaran dan penghargaan prestasi siswa
  - Input langsung catatan kepribadian wali kelas, predikat ekstrakurikuler, dan keputusan kenaikan kelas/kelulusan
- **E-Rapor Standar Kurikulum Merdeka**:
  - **Deskripsi Capaian Pembelajaran Dua Sisi**: Narasi *Capaian Tertinggi* dan *Perlu Peningkatan/Pendampingan* otomatis sesuai standar Kurikulum Merdeka (dilengkapi custom override)
  - **3-in-1 Dokumen Lengkap**: Multi-tab switcher & pencetakan Lembar Nilai Akademik, Sampul (Cover) Resmi Rapor, dan Biodata Siswa Format Buku Induk Dapodik
  - **Kotak Keputusan Resmi**: Penetapan Kenaikan Kelas / Kelulusan (Semester Genap) dan integrasi catatan PKL/Prakerin vokasi (SMK)
  - **Rekomendasi Cerdas BK**: Auto-generate catatan motivasi wali kelas berdasarkan rekam jejak kedisiplinan & prestasi BK
- **Cetak Rapor Massal Satu Kelas (`dashboard/akademik/report_card_bulk.php`)**:
  - Cetak e-rapor seluruh siswa dalam satu rombongan belajar sekaligus dalam satu klik
  - Dilengkapi pemilih kelas dinamis, semester, dan pemisah halaman otomatis (*CSS page-break: always*) berstandar dinas pendidikan
- **Jadwal Pelajaran (`timetable.php`)** — Tabel jadwal mingguan per kelas (Senin–Sabtu) dengan informasi guru, ruangan, dan waktu
- **Materi & E-Learning (`materials.php`)** — Upload dan bagikan materi pembelajaran (dokumen, video YouTube, link Google Drive) dengan tracking jumlah unduhan
- **Tugas & Pengumpulan (`assignments.php`)** — Guru membuat tugas dengan deadline; siswa submit berkas jawaban; penilaian & feedback guru
- **Buku Nilai (Gradebook) (`gradebook.php`)** — Rekap seluruh nilai siswa per mata pelajaran, cetak & export ke spreadsheet
- **Kalender Akademik (`calendar.php`)** — Agenda sekolah dengan kategori (akademik, libur, kegiatan, ujian) dan tampilan visual kalender interaktif

### 📝 Sistem Ujian CBT & Analisis Butir Soal

- **6 Kategori Asesmen Fleksibel**:
  - Ujian Formal: UTS (Tengah Semester), UKK (Kenaikan Kelas), Ujian Harian
  - Latihan & Pengayaan: Latihan Harian, Latihan Mingguan, Latihan Bulanan
- **Editor Soal Terpadu & Impor Massal CSV (`exam_questions.php`)**:
  - Mendukung Pilihan Ganda & Esai dengan lampiran gambar soal
  - Impor massal puluhan butir soal sekaligus via file spreadsheet CSV
- **Analisis Butir Soal & Daya Pembeda (`dashboard/Modul-ujian/exam_results.php`)**:
  - Kalkulasi **Indeks Tingkat Kesukaran ($P$)**: Klasifikasi otomatis *Mudah* ($P > 0.70$), *Sedang* ($0.30 \le P \le 0.70$), atau *Sukar* ($P < 0.30$)
  - **Analisis Distraktor / Pengecoh**: Visualisasi grafik distribusi persentase siswa yang memilih opsi A, B, C, atau D
  - Rata-rata pencapaian skor pada butir soal esai terbuka
- **Fitur Pengamanan & Ketertiban Ujian**:
  - Token akses ujian unik per sesi
  - Timer countdown dengan auto-submit saat waktu habis
  - Acak urutan butir soal (*randomize questions*) untuk meminimalisasi kecurangan
- **Koreksi & Remedial**:
  - Auto-grading instan untuk soal pilihan ganda
  - Rubrik penilaian esai terbuka manual oleh guru
  - Fasilitas pemberian kesempatan remedial 1-klik bagi siswa di bawah KKM
- **Cetak & Kartu Ujian**: Cetak rekap nilai per kelas, ekspor spreadsheet, dan cetak kartu peserta ujian berjadwal

### ✅ Presensi Kehadiran Siswa & Mandiri Pendidik

- **Presensi Mandiri Pendidik & Tenaga Kependidikan (`dashboard/presensi/teacher_attendance.php`)**:
  - Jam digital real-time sinkron waktu server (*Live Clock*)
  - **Smart Clock-In**: Satu klik presensi masuk dengan deteksi otomatis keterlambatan (otomatis berstatus *Terlambat* jika melewati 07:15 WIB)
  - **Smart Clock-Out**: Presensi kepulangan saat jam kerja usai
  - **Formulir Permohonan Izin / Sakit / Cuti**: Pengajuan tidak hadir disertai keterangan alasan dan unggahan berkas bukti (surat dokter / dinas)
  - Rekapitulasi riwayat presensi bulanan guru
- **Presensi Harian Siswa (`attendance.php`)**:
  - Input presensi harian per kelas/mapel dengan status: **Hadir, Sakit, Izin, Alpa**
  - Sinkronisasi otomatis dengan surat dispensasi/izin siswa dari layanan TU
- **Scan QR Code Kamera (`scan_qr.php`)** — Presensi kilat (< 1 detik) menggunakan kamera/webcam dari kartu QR siswa
- **Kartu Pelajar Digital QR (`qr_card.php`)** — Cetak kartu identitas siswa dengan barcode & QR Code terenkripsi
- **Laporan Statistik Presensi (`attendance_report.php`)** — Analisis persentase kehadiran, grafik tren, cetak laporan, dan ekspor spreadsheet

### 💰 Modul Keuangan, SPP & Buku Kas Umum (BKU)

- **Manajemen Tagihan & SPP**:
  - Jenis tagihan fleksibel (SPP bulanan, uang kegiatan, sumbangan sarana, dll.)
  - Penagihan per siswa dengan periode bulan, tahun ajaran, dan tanggal jatuh tempo
  - Metode pembayaran: Transfer Bank, Tunai, QRIS dengan upload bukti transfer
  - Pipeline verifikasi: `Belum Lunas → Menunggu Verifikasi → Lunas`
  - Cetak kuitansi resmi pembayaran berstempel digital
  - **Verifikasi Keaslian Kuitansi via QR Code**: Halaman publik untuk memverifikasi keabsahan kuitansi pembayaran SPP secara real-time via pemindaian kode QR terenkripsi SHA-256
  - Laporan rekapitulasi keuangan komprehensif & export data
- **Buku Kas Umum (BKU) & Kas Pengeluaran Operasional (`expenses.php`)**:
  - Pencatatan Bukti Kas Keluar (BKK) bernomor urut otomatis (`BKK-YYYY/MM/NNN`)
  - Kategori belanja: ATK & Operasional Kantor, Listrik & Internet, Konsumsi & Rapat, Pemeliharaan & Kebersihan, Kesiswaan & Lomba, Honor/Transport Tugas, dll.
  - Upload dan verifikasi dokumen fisik bukti nota / kwitansi pembelian
  - **Monitoring Arus Kas Real-Time**: Kalkulasi otomatis Total Penerimaan Kas SPP vs Total Pengeluaran Kas vs Sisa Saldo Kas Riil
  - Filter pengeluaran per bulan/tahun dan cetak laporan BKU siap audit

### 🏢 Modul Inventaris Sarana & Prasarana (Sarpras)

- **Buku Inventaris Aset Sekolah (`sarpras/inventory.php`)**:
  - Pencatatan aset sekolah berdasarkan penempatan ruangan (Lab Komputer, Lab IPA, Ruang Guru, Ruang TU, Kelas, Perpustakaan, Aula, Gudang Olahraga, dll.)
  - Klasifikasi kategori: Elektronik, Mebel & Perabot, Alat Peraga, Perlengkapan Kantor, Kendaraan, dan Peralatan Olahraga
  - Monitoring kondisi barang real-time: **Baik (Layak Pakai)**, **Rusak Ringan (Perlu Servis)**, dan **Rusak Berat (Usul Penghapusan)**
  - Pencatatan sumber pendanaan (BOS Reguler, BOS Kinerja, Yayasan, Hibah) dan nilai perolehan aset
  - **Metrik Dashboard Aset**: Total unit terdata, unit layak pakai, unit butuh perbaikan, dan akumulasi estimasi nilai aset (Rp)
- **Sirkulasi Peminjaman Sarpras & Fasilitas**:
  - Formulir pencatatan peminjaman peralatan/ruangan oleh guru, siswa/OSIS, pembina ekskul, atau tamu
  - Tracking batas waktu pengembalian dengan penanda status jatuh tempo
  - Konfirmasi pengembalian sarana dan pencatatan kondisi barang saat kembali
- **Cetak Rekapitulasi Inventaris** untuk laporan pertanggungjawaban sarana prasarana sekolah

### 📖 Perpustakaan Digital & Katalog Publik (OPAC)

Sistem perpustakaan sekolah modern yang terintegrasi penuh antara **katalog publik (`perpustakaan.php`)** dan **dashboard manajemen sirkulasi**:

- **Katalog Publik Terbuka (OPAC)** — Aksesibel langsung tanpa login, dilengkapi pencarian cerdas (judul, penulis, ISBN), filter kategori interaktif, dan ketersediaan stok buku fisik/digital secara real-time.
- **Fitur 1: Reservasi / Booking Buku Mandiri** — Siswa & guru yang login dapat melakukan pemesanan (booking) buku mandiri secara online. Buku ditahan selama 2 hari di meja sirkulasi; siswa dapat memantau status antrean (`menunggu`, `disiapkan`, `selesai`, `dibatalkan`, `kedaluwarsa`) atau membatalkannya kapan saja.
- **Fitur 2: Review & Rating Buku Komunitas (1–5 Bintang)** — Pembaca dapat memberikan ulasan tertulis dan rating 1-5 bintang. Tampilan katalog menyajikan skor rata-rata, jumlah pembaca, dan rekap ulasan siswa lain.
- **Fitur 3: In-Browser PDF/E-Book Reader** — Membaca koleksi modul dan e-book digital langsung di peramban web tanpa perlu mengunduh file. Dilengkapi mode **Fullscreen (Layar Penuh)**, tautan tab baru, dan proteksi klik kanan (anti-copy/save).
- **Fitur 4: Auto-Fill ISBN via API** — Integrasi Google Books API & Open Library API pada dashboard staf/admin. Cukup ketik nomor ISBN, sistem otomatis melengkapi judul, penulis, penerbit, tahun rilis, dan pemetaan kategori perpustakaan sekolah secara instan.
- **Sirkulasi Peminjaman & Denda Otomatis** — Pencatatan transaksi peminjaman, perpanjangan masa pinjam, pengembalian, dan penghitungan denda keterlambatan harian secara otomatis.
- **Fitur Penunjang Sirkulasi Lengkap**:
  - **Quick Scan Barcode** — Pemindaian cepat QR code anggota dan barcode buku via webcam/kamera.
  - **Buku Tamu Pengunjung** — Pencatatan kehadiran civitas yang berkunjung ke ruang perpustakaan.
  - **Cetak Label & Barcode** — Pembuatan stiker nomor panggil (call number) dan barcode buku siap tempel.
  - **Surat Bebas Pustaka** — Verifikasi digital dan penerbitan surat bebas tanggungan perpustakaan untuk siswa tingkat akhir.

### 🤝 Bimbingan Konseling (BK)

- Pencatatan **pelanggaran disiplin** siswa dengan sistem poin
- Pencatatan **prestasi akademik & non-akademik** siswa
- Kategori catatan yang fleksibel (kedisiplinan, akademik, dll.)
- Tindakan yang diambil (peringatan, pembinaan, apresiasi)
- **Cetak surat BK** resmi untuk orang tua

### 🏆 Modul Ekstrakurikuler & Prestasi Siswa

- **Katalog & Pendaftaran Mandiri Siswa (`dashboard/ekskul/index.php`)**:
  - Siswa dapat menelusuri katalog klub bakat minat (Pramuka, Paskibra, PMR, Futsal, Basket, Robotika & IT, Tari Tradisional, English Club, dll.) lengkap dengan informasi jadwal latihan mingguan, kuota kapasitas terisi, dan profil guru pembina
  - Pendaftaran mandiri 1-klik dengan validasi kuota maksimal otomatis dan status persetujuan pembina
  - Orang tua dapat memantau ekskul aktif putra-putrinya secara transparan dari dashboard
- **Manajemen Anggota & Penilaian Pembina (`dashboard/ekskul/detail.php`)**:
  - Pembina/Guru dapat mengelola keanggotaan (Aktif, Menunggu Persetujuan, Ditolak, Alumni) dan menetapkan peran siswa (Ketua, Wakil, Sekretaris, Bendahara, Anggota)
  - Pembina dapat memberikan penilaian predikat rapor semester (`A - Sangat Baik`, `B - Baik`, `C - Cukup`, `D - Kurang`) dan catatan deskripsi perkembangan bakat siswa
- **Lembar Presensi Mingguan Pembina (`dashboard/ekskul/attendance.php`)**:
  - Penjadwalan sesi latihan rutin & kegiatan kompetisi
  - Lembar presensi interaktif dengan fitur kilat **"Set Semua Hadir"** dalam 1 klik
  - Audit kehadiran siswa dengan statistik per sesi (Hadir, Izin, Sakit, Alpa) dan persentase keaktifan
- **Integrasi Langsung Nilai Rapor & Student 360**:
  - Nilai predikat dan catatan pembina otomatis disinkronkan dan ditampilkan di **Bagian C E-Rapor Digital**
  - Data keanggotaan dan daftar riwayat kejuaraan tampil di **Radar Profil Siswa 360**
- **Galeri Hall of Fame & Portofolio Akreditasi BAN-SM (`dashboard/ekskul/achievements.php`)**:
  - Rekam jejak kejuaraan/penghargaan siswa dari tingkat Sekolah, Kecamatan, Kabupaten, Provinsi, Nasional, hingga Internasional
  - **Ekspor CSV Portofolio Prestasi** yang diformat khusus untuk kebutuhan akreditasi sekolah (BAN-SM / Penjaminan Mutu)
  - Formulir pengajuan prestasi mandiri dengan verifikasi oleh pembina atau tim kesiswaan
- **Penerbitan Piagam & Sertifikat Digital Ber-QR TTE (`dashboard/ekskul/print_certificate.php`)**:
  - Cetak piagam penghargaan kejuaraan atau sertifikat keikutsertaan ekskul format resmi A4
  - Terintegrasi kop surat dinas sekolah, tanda tangan digital Kepala Sekolah & Pembina, serta kode hash validasi QR Code

### 📢 Informasi, Berita & Pengumuman

- **CRUD Pengumuman Sekolah (`announcements.php`)**:
  - CRUD pengumuman dengan **Rich Text Editor**
  - Target pengumuman per role (semua, guru, siswa, orang tua, staf) dan per kelas
  - Kategori: Umum, Akademik, Kegiatan, Penting, Darurat
  - Fitur **Pin pengumuman** (selalu tampil di atas)
  - **Lampiran file** pada pengumuman
  - Status draft/published dengan tanggal kedaluwarsa
  - Integrasi kalender event
  - **Tracking pembaca** — Lacak siapa saja yang sudah membaca pengumuman
  - **Auto pop-up modal** untuk pengumuman darurat yang belum dikonfirmasi baca
  - **Cetak pengumuman** resmi dengan kop sekolah
- **📰 Manajemen Redaksi & Publikasi Berita Sekolah (`dashboard/informasi/news.php`)**:
  - Dashboard editorial untuk mengelola kabar sekolah, galeri kegiatan, artikel ilmiah dewan guru, dan liputan prestasi
  - Pengelompokan kategori dinamis, tag kata kunci, unggah thumbnail cover gambar, serta ringkasan kutipan (*excerpt*)
  - Pipeline persetujuan publikasi: `Draft → Menunggu Review → Terbit (Published)`
  - Integrasi silang ke koleksi buku perpustakaan, agenda kalender akademik, dan rekam prestasi siswa/BK
  - Statistik pembaca real-time dan preview instan di portal publik `berita.php`

### 💬 Pesan & Konsultasi Internal

- Sistem pesan antar pengguna (guru ↔ orang tua, siswa ↔ guru, dll.)
- Indikator pesan belum dibaca (badge notifikasi real-time)
- Lampiran file pada pesan
- Riwayat percakapan tersimpan

### 📄 Layanan Surat, Agenda Masuk/Keluar & Penugasan Dinas

- **Layanan Permohonan Surat Siswa & Orang Tua**:
  - Pengajuan mandiri siswa & wali murid: Surat Keterangan Aktif, SKBB, SKL Sementara, Undangan Orang Tua, Rekomendasi Beasiswa, Surat Izin Sakit, & Dispensasi
  - Pipeline verifikasi permohonan: `Menunggu → Diproses → Selesai / Ditolak`
  - Lampiran dokumen/surat dokter dengan validasi MIME-type aman
  - **Integrasi Presensi Otomatis**: Persetujuan surat izin/sakit otomatis menyinkronkan status kehadiran siswa ke tabel `student_attendance`
  - **Aksesibilitas Multi-Anak Orang Tua**: Wali murid dapat memantau dan mencetak surat untuk semua anak yang terhubung via tabel `parent_students`
- **Penerbitan Surat Resmi Langsung & SPT Guru/Pegawai**:
  - Staf TU dapat langsung menerbitkan surat resmi sekolah berkop dinas
  - **Surat Perintah Tugas (SPT / SPPD) Guru & Pegawai** — Surat penugasan resmi bagi pendidik dan tenaga kependidikan untuk tugas kedinasan, kepengawasan, dan pelatihan MGMP dengan format standar kedinasan Republik Indonesia
  - Format cetak resmi berstandar Kemendikbud: Kop surat resmi, penomoran kode klasifikasi arsip (`421.3`, `800/SPT-GTK`, dll.), tanda tangan digital Kepala Sekolah, simulasi stempel basah terakreditasi, dan verifikasi hash QR code
- **Buku Agenda Surat Masuk & Surat Keluar (`archives.php`)**:
  - Dua tab navigasi terpisah untuk Surat Masuk dan Surat Keluar
  - Penomoran agenda resmi otomatis: `AG-IN/YYYY/MM/NNN` dan `AG-OUT/YYYY/MM/NNN`
  - Pencatatan nomor surat luar, instansi pengirim/tujuan, perihal, dan tanggal penerimaan
  - Unggah dan preview arsip scan berkas fisik (PDF, JPG, PNG)
  - **Lembar Disposisi Kepala Sekolah Digital**: Pencatatan instruksi disposisi Kepala Sekolah kepada guru/staf beserta batas waktu tindak lanjut

### 🛠️ Panel Administrasi & Kesiswaan

- **Manajemen Pengguna & Kredensial Pendidik (`dashboard/admin/users.php`)**:
  - CRUD pengguna (5 role) dengan proteksi hak akses hirarkis (Staf TU khusus mengelola siswa & wali murid)
  - **Manajemen Kredensial & TTE Guru**: Penetapan NIP/NUPTK, gelar akademik (`title_degree`), pratinjau scan tanda tangan digital guru, unggah scan baru, atau reset ke generator SVG otomatis
  - **Impor Siswa Massal via CSV** — Unggah banyak data siswa sekaligus dari Excel/CSV lengkap dengan pemetaan kelas otomatis dan pembuatan password default
  - **Unduh Template CSV** contoh resmi langsung dari dashboard
  - **Cetak Lembar Buku Induk Siswa (`student_profile_print.php`)** — Lembar buku induk standar Kemendikbud memuat Bagian A–D (Data Diri, Alamat, Asal Sekolah, Orang Tua/Wali), kotak Pas Foto 3x4 cm, dan tanda tangan pengesahan Kepala Sekolah & Staf TU
- **🤖 Konsol Live Chat & Pusat Kendali AI Real-Time (`dashboard/admin/live_chat.php`)**:
  - Pemantauan seluruh sesi obrolan pengunjung publik dan civitas sekolah secara real-time
  - Fitur pengalihan instan dari bot ke petugas staf/admin (*Human Takeover*)
  - Pustaka *Quick Reply Templates* untuk menjawab pertanyaan berulang dengan 1 klik
  - Konfigurasi engine AI (Model, System Prompt, Endpoint, Ground-truth context) dan live testing simulator
- **🎯 Radar Profil Siswa 360 Derajat (`dashboard/admin/student_360.php`)**:
  - Tampilan profil analitik holistik siswa dalam satu layar terpadu: biodata lengkap Dapodik, grafik radar nilai capaian belajar, rasio presensi kehadiran, akumulasi rekam jejak kedisiplinan & prestasi BK, riwayat tagihan SPP keuangan, keanggotaan klub ekskul, dan catatan rapor semester
  - Aksesibilitas multi-role: Administrator, Staf TU, Guru/Wali Kelas, serta Siswa & Orang Tua (monitoring mandiri)
- **🚀 Wizard Kenaikan Kelas & Kelulusan Massal (`dashboard/admin/promotion.php`)**:
  - Pemrosesan transisi rombongan belajar (rombel) tahun ajaran baru secara massal dalam 1 klik
  - Fleksibilitas keputusan individu: tandai *Naik Kelas* atau *Tinggal Kelas* disertai pencatatan catatan administratif
  - Tab Kelulusan Siswa Tingkat Akhir: otomatis mengalihkan status siswa menjadi **Alumni**, mengarsipkan data ke tabel `alumni_records`, dan mencatat seluruh histori mutasi kelas ke tabel `academic_promotion_logs`
- **🔌 Manajemen Integrasi Multi-API Gateway (`dashboard/admin/external_apis.php`)**:
  - Panel kontrol terpusat untuk konfigurasi API pihak ketiga: WhatsApp Gateway, Payment Gateway (Midtrans, Xendit, Tripay), Mail SMTP, Google Maps, OpenWeather, dan Webhook Kustom
  - Fitur **Live Auto-Save Ajax** ke basis data tanpa reload halaman, switch toggle status aktif, dan tool uji coba koneksi instan
- **📲 Pusat Distribusi Multi-Aplikasi Mobile (`dashboard/apps_hub.php`)**:
  - Panel panduan manajemen dan pemasangan 3 aplikasi mandiri sekolah di dashboard bagi seluruh civitas akademika
  - Generator QR code URL dinamis untuk scan instalasi langsung via HP (Android/iOS) dan unduhan paket APK
- **Kotak Masuk Pesan Tamu Publik (`contact_messages.php`)**:
  - Menerima dan mengelola tiket pengaduan/pertanyaan publik dari formulir `kontak.php`
  - Status penanganan tiket (`baru`, `diproses`, `selesai`) dan pencatatan catatan tindak lanjut admin/staf
  - Indikator badge pesan tamu belum dibaca pada sidebar navigasi
- **Manajemen Kelas** — Buat dan kelola rombongan belajar (rombel) serta penugasan wali kelas
- **Verifikasi PPDB** — Review, verifikasi dokumen, dan seleksi calon peserta didik baru
- **Pengaturan Sekolah & Identitas Kedinasan (`dashboard/admin/settings.php`)** — Identitas sekolah (nama, NPSN, alamat, telepon, email, website, kepala sekolah, NIP, tahun ajaran), koordinat GPS geofencing presensi, serta unggah logo instansi, cap stempel resmi dinas, dan scan tanda tangan pimpinan yang disinkronisasi ke 21 berkas template cetak
- **Audit Log** — Pencatatan seluruh aktivitas penting dalam sistem (login, update, delete, penerbitan surat, transaksi kas) dengan IP address
- **Backup & Restore Database** — Export dan import skema + data database secara aman

### 🎓 Modul Alumni, Tracer Study & Legalisir Digital

Terobosan digitalisasi layanan purna-studi bagi lulusan sekolah:

- **Kuesioner Tracer Study Lulusan (`dashboard/alumni/tracer_study.php`)**:
  - Penelusuran rekam jejak karir alumni: status saat ini (**Melanjutkan Studi / Kuliah**, **Bekerja**, **Wirausaha**, atau **Mencari Kerja**)
  - Pencatatan detail nama perguruan tinggi/perusahaan, program studi/jabatan kerja, pendapatan, relevansi bidang keahlian sekolah dengan karir, dan saran kurikulum
  - **Ekspor CSV Portofolio Akreditasi BAN-SM**: Unduh laporan tracer study terformat resmi siap pakai untuk instrumen akreditasi sekolah
  - Visualisasi metrik statistik serapan lulusan secara langsung di dashboard
- **Layanan Permohonan Legalisir Dokumen Digital (`dashboard/alumni/legalisir.php`)**:
  - Formulir pengajuan mandiri alumni: upload scan ijazah/transkrip asli, nomor dokumen, keperluan legalisir (kuliah, kerja, dinas), dan kontak WhatsApp
  - Pipeline verifikasi berkas oleh Staf Tata Usaha: `Menunggu → Diverifikasi → Disetujui / Perlu Revisi / Ditolak`
  - Pelacakan progres verifikasi transparan dan notifikasi otomatis
- **Cetak Lembar Pengesahan Legalisir Ber-QR TTE (`dashboard/alumni/legalisir_print.php`)**:
  - Format cetak lembar legalisir resmi standar dinas ukuran A4
  - Dilengkapi cap stempel basah digital, tanda tangan Kepala Sekolah, dan **QR Code Validasi TTE** yang terhubung langsung ke portal verifikasi keaslian dokumen `verify_document.php`

### 📱 Ekosistem Multi-Aplikasi Mobile (Android APK Native & PWA)

Sistem mengusung konsep **1 Terpusat Database, Multi-Aplikasi Mandiri** yang memudahkan akses civitas sekolah tanpa perlu membuka browser secara manual:

- **3 Aplikasi Android Native Wrapper (`apps/android/`)**:
  1. **Aplikasi CBT Ujian (`apps/android/cbt-app/`)**: Paket `com.sekolah.cbt`, dioptimalkan dengan mode **Kiosk Screen**, layar penuh (*immersive fullscreen*), anti-screenshot, dan kunci layar tetap menyala (*keep screen on*)
  2. **Aplikasi Presensi (`apps/android/presensi-app/`)**: Paket `com.sekolah.presensi`, dengan izin kamera bawaan instan untuk scan barcode/QR kartu kehadiran dan integrasi GPS Geofencing radius
  3. **Aplikasi E-Perpustakaan (`apps/android/perpustakaan-app/`)**: Paket `com.sekolah.perpustakaan`, dilengkapi barcode scanner koleksi buku dan pembaca E-Book PDF layar penuh
- **Paket Konfigurasi Terpusat (`apps/android/app_config.json`)**:
  - Cukup ubah `server_base_url` ke IP LAN atau domain sekolah Anda, seluruh target aplikasi otomatis tersinkronisasi
- **Progressive Web App (PWA) Standar Modern**:
  - Dilengkapi Service Worker (`sw.js`) di root & dashboard untuk caching offline aset inti
  - Tiga file **Web App Manifest** terpisah:
    - CBT Ujian: `dashboard/Modul-ujian/manifest.json`
    - Presensi: `dashboard/presensi/manifest.json`
    - Perpustakaan: `dashboard/perpustakaan/manifest.json`
  - Dapat diinstal langsung dari browser (Chrome, Edge, Safari) ke HomeScreen smartphone Android, iPhone, tablet, maupun desktop PC/Mac
  - Kompatibel 100% dengan tool generator cloud seperti **[PWABuilder.com](https://www.pwabuilder.com/)** untuk generate file `.apk` instan tanpa perlu menginstall Android Studio

### 🚨 Custom Error Pages

- Halaman error premium untuk kode: **400, 401, 403, 404, 419, 500, 503**
- Desain visual konsisten dengan branding aplikasi
- Pesan error informatif dalam Bahasa Indonesia
- Saran penyelesaian masalah untuk pengguna
- Penanganan cerdas error koneksi database (MySQL mati, database tidak ditemukan, akses ditolak)

---

## 🗄️ Skema Database

Sistem menggunakan database `website_login` dengan **58 tabel** yang saling berelasi:

| #  | Tabel                     | Deskripsi                                                    |
|----|---------------------------|--------------------------------------------------------------|
| 1  | `users`                   | Data pengguna (nama, email, password hash, role, kelas, nisn)|
| 2  | `announcements`           | Pengumuman sekolah (target role, kategori, pin, expiry)      |
| 3  | `announcement_reads`      | Tracking pembaca pengumuman per pengguna                     |
| 4  | `assignments`             | Tugas pembelajaran dari guru                                 |
| 5  | `assignment_submissions`  | Pengumpulan tugas oleh siswa (file, nilai, feedback)         |
| 6  | `service_requests`        | Permohonan layanan administrasi surat siswa/ortu & SPT dinas |
| 7  | `exams`                   | Bank ujian & latihan (token, durasi, passing grade, timer)   |
| 8  | `exam_questions`          | Soal ujian (PG + Esai, gambar, bobot skor)                   |
| 9  | `exam_submissions`        | Hasil pengerjaan ujian siswa (skor, remedial, analisis)     |
| 10 | `student_attendance`      | Presensi kehadiran harian per siswa per mata pelajaran       |
| 11 | `calendar_events`         | Kalender akademik & agenda kegiatan sekolah                  |
| 12 | `parent_students`         | Relasi orang tua (wali murid) ↔ siswa                        |
| 13 | `classes`                 | Data kelas / rombel & penunjukan wali kelas (`homeroom_teacher_id`)|
| 14 | `school_settings`         | Konfigurasi identitas sekolah & pengaturan PPDB (key-value)  |
| 15 | `audit_logs`              | Log aktivitas pengguna (audit trail)                         |
| 16 | `subjects`                | Data mata pelajaran kurikulum                                |
| 17 | `timetables`              | Jadwal pelajaran mingguan per kelas                          |
| 18 | `learning_materials`      | Materi pembelajaran E-Learning (dokumen, link, video)        |
| 19 | `payment_types`           | Jenis tagihan keuangan sekolah                               |
| 20 | `student_bills`           | Tagihan keuangan per siswa                                   |
| 21 | `bill_payments`           | Transaksi pembayaran tagihan SPP                             |
| 22 | `counseling_records`      | Catatan BK (pelanggaran berpoin & prestasi siswa)            |
| 23 | `messages`                | Pesan internal konsultasi antar pengguna                     |
| 24 | `ppdb_registrations`      | Data pendaftaran calon siswa PPDB (4 jalur & verifikasi)     |
| 25 | `library_books`           | Katalog buku fisik & koleksi e-book perpustakaan             |
| 26 | `library_loans`           | Sirkulasi transaksi peminjaman, perpanjangan, & denda buku   |
| 27 | `library_reservations`    | Antrean booking & reservasi buku mandiri siswa / guru        |
| 28 | `library_reviews`         | Ulasan testimoni & rating bintang (1–5) buku komunitas       |
| 29 | `library_visitors`        | Buku tamu presensi kehadiran fisik pengunjung perpustakaan   |
| 30 | `student_report_notes`    | Catatan rapor semester (wali kelas & ekstrakurikuler)        |
| 31 | `contact_messages`        | Kotak masuk pesan tamu & tiket pengaduan formulir kontak     |
| 32 | `mail_archives`           | Buku agenda surat masuk/keluar & disposisi Kepala Sekolah    |
| 33 | `inventory_items`         | Buku inventaris sarana prasarana sekolah per ruangan         |
| 34 | `inventory_loans`         | Transaksi sirkulasi peminjaman sarpras & fasilitas sekolah   |
| 35 | `financial_expenses`      | Buku Kas Umum (BKU) & pengeluaran operasional sekolah        |
| 36 | `news`                    | Sistem portal berita sekolah & publikasi artikel dewan guru  |
| 37 | `teaching_journals`       | Jurnal mengajar KBM harian (materi, ketercapaian, guru inval)|
| 38 | `curriculum_documents`    | Arsip perangkat ajar & administrasi Kurikulum Merdeka        |
| 39 | `teacher_attendance`      | Presensi kehadiran mandiri pendidik (Clock-In/Out, izin/cuti)|
| 40 | `wa_notification_logs`    | Audit log pengiriman notifikasi instan WhatsApp Gateway      |
| 41 | `teacher_payrolls`        | Penggajian & slip honor bulanan dewan guru (Payroll)         |
| 42 | `external_apis`           | Integrasi API luar & gateway (WA, Payment, AI, Mail, Maps, Cuaca, Kustom) dengan auto-save DB |
| 43 | `extracurriculars`        | Data induk klub ekstrakurikuler, kuota, pembina, & jadwal latihan |
| 44 | `extracurricular_members` | Rekam pendaftaran siswa, peran jabatan, & predikat nilai e-rapor  |
| 45 | `extracurricular_activities`| Jadwal agenda sesi pertemuan, latihan mingguan, & kegiatan lomba|
| 46 | `extracurricular_attendance`| Lembar absensi kehadiran siswa per sesi pertemuan ekstrakurikuler|
| 47 | `extracurricular_achievements`| Portofolio kejuaraan, juara lomba, & sertifikat prestasi siswa |
| 48 | `legalisir_requests`      | Permohonan legalisir ijazah/transkrip digital alumni & tracking verifikasi TU |
| 49 | `password_resets`         | Token & sesi keamanan pemulihan kata sandi pengguna (kedaluwarsa 1 jam) |
| 50 | `academic_promotion_logs` | Riwayat audit kenaikan kelas & kelulusan siswa massal tahun ajaran |
| 51 | `alumni_records`          | Basis data induk lulusan alumni, tahun kelulusan, & kontak karir |
| 52 | `alumni_tracer_studies`   | Rekap kuesioner penelusuran lulusan (kuliah, kerja, wirausaha) BAN-SM |
| 53 | `p5_projects`             | Data tema, koordinator & projek Profil Pelajar Pancasila Kurikulum Merdeka |
| 54 | `p5_project_dimensions`   | Pemetaan dimensi, elemen, sub-elemen & target capaian fase projek P5 |
| 55 | `p5_student_assessments`  | Nilai perkembangan projek siswa per sub-elemen (MB, SB, BSH, SAB) |
| 56 | `p5_student_notes`        | Catatan proses, deskripsi capaian, & refleksi fasilitator rapor P5 |
| 57 | `live_chat_sessions`      | Sesi obrolan pengunjung publik & civitas sekolah, status AI, dan eskalasi staf |
| 58 | `live_chat_messages`      | Histori pesan obrolan, balasan cerdas bot AI, admin, dan berkas lampiran |

> 💡 **Catatan Modul:** Fitur Surat Bebas Perpustakaan beroperasi secara dinamis memeriksa status tanggungan pinjaman aktif dan denda keterlambatan langsung dari tabel `library_loans` tanpa memerlukan tabel terpisah.
>
> ⚙️ **Auto-Migration:** Sistem secara otomatis membuat seluruh tabel dan menjalankan migrasi kolom baru saat pertama kali aplikasi diakses. Tidak wajib import SQL manual.

---

## 🚀 Instalasi & Setup

### Prasyarat

| Kebutuhan      | Versi Minimum | Rekomendasi                          |
|----------------|---------------|--------------------------------------|
| PHP            | 8.0+          | XAMPP 8.2 / Laragon 6                |
| MySQL/MariaDB  | 5.7+ / 10.4+  | MySQL 8.0 atau MariaDB 10.6         |
| Web Server     | Apache 2.4+   | Dengan `mod_rewrite` & `mod_headers` |
| Browser        | Modern        | Chrome, Firefox, Edge, Safari        |

### Langkah Instalasi

#### 1. Clone Repository

```bash
git clone https://github.com/saipul12c/Manajemen-php.git
```

#### 2. Pindahkan ke Web Server

```bash
# XAMPP (Windows)
cp -r Manajemen-php/ C:/xampp/htdocs/manajemen-php/

# Laragon (Windows)
cp -r Manajemen-php/ C:/laragon/www/manajemen-php/

# Linux (Apache)
cp -r Manajemen-php/ /var/www/html/manajemen-php/
```

#### 3. Buat Database

**Opsi A — Otomatis (Direkomendasikan):**

Cukup buat database kosong bernama `website_login` melalui phpMyAdmin atau CLI:

```sql
CREATE DATABASE IF NOT EXISTS `website_login`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

Sistem akan otomatis membuat seluruh tabel dan data sampel saat pertama kali diakses.

**Opsi B — Import Manual:**

```bash
mysql -u root -p website_login < sql/database.sql
```

#### 4. Konfigurasi Database

Edit file `config/database.php` jika konfigurasi database Anda berbeda dari default:

```php
$host        = "localhost";   // Host database
$dbname      = "website_login"; // Nama database
$username    = "root";         // Username MySQL
$password_db = "";             // Password MySQL (kosong untuk XAMPP/Laragon)
```

#### 5. Akses Aplikasi

Buka browser dan navigasi ke:

```
http://localhost/manajemen-php/
```

### 👤 Akun Demo Bawaan

Setelah import `sql/database.sql`, tersedia akun demo berikut:

| Role          | Email                        | Password      |
|---------------|------------------------------|---------------|
| Administrator | admin@sekolah.id             | password      |
| Staf          | staff@sekolah.id             | password      |
| Guru          | guru@sekolah.id              | password      |
| Orang Tua     | orangtua@sekolah.id          | password      |
| Siswa         | siswa@sekolah.id             | password      |

> ⚠️ **Penting:** Segera ubah password default setelah instalasi di lingkungan produksi.

---

## 📖 Panduan Penggunaan

### Alur Pengguna Baru (Siswa / Orang Tua)

```
1. Buka halaman utama → Klik "Daftar"
2. Isi formulir registrasi (nama, email, password, role)
3. Login menggunakan akun yang telah dibuat
4. Akses dashboard sesuai role
```

### Alur PPDB Online

```
1. Buka halaman utama → Klik "Daftar Siswa Baru (PPDB)"
2. Isi formulir biodata lengkap + upload berkas
3. Dapatkan nomor registrasi unik
4. Cetak kartu pendaftaran (opsional)
5. Pantau status via tab "Cek Status" menggunakan NISN/No. Registrasi
6. Admin memverifikasi → Seleksi → Diterima
```

### Alur Ujian Online (Guru)

```
1. Dashboard → Modul Ujian → Buat Ujian Baru
2. Tentukan judul, mata pelajaran, kategori, durasi, passing grade
3. Aktifkan token akses & acak soal (opsional)
4. Tambahkan soal (Pilihan Ganda / Esai) + gambar pendukung
5. Siswa mengerjakan ujian dalam waktu yang ditentukan
6. Sistem auto-grading PG; guru menilai soal esai secara manual
7. Lihat hasil, analisis, cetak, atau export
```

### Alur Perpustakaan & E-Book (Siswa & Staf)

```
1. Buka halaman publik perpustakaan (tanpa harus login terlebih dahulu)
2. Cari buku atau filter kategori; baca e-book langsung via In-Browser PDF Reader
3. Siswa login → Buka modal detail buku fisik → Klik "Booking / Reservasi Buku"
4. Buku di-booking dan ditahan selama 2 hari di meja sirkulasi
5. Staf/Petugas di Dashboard Perpustakaan memverifikasi dan klik "Proses Jadi Pinjaman"
6. Setelah selesai membaca, siswa dapat memberikan rating (1-5 bintang) & ulasan pada katalog buku
7. Staf menambahkan buku baru di katalog dengan fitur Auto-Fill ISBN via API secara otomatis
```

### Alur Papan Informasi & Pengumuman Publik

```
1. Akses halaman mandiri informasi atau pengumuman tanpa perlu login
2. Gunakan Live Search untuk menyaring kata kunci pada judul atau isi edaran
3. Filter berdasarkan kategori (Penting, Darurat, Akademik, Kegiatan, Umum) atau target sasaran
4. Klik kartu pengumuman untuk membuka modal pop-up baca pengumuman lengkap
5. Unduh lampiran resmi (PDF/dokumen) atau klik "Cetak Dokumen"
6. Pantau agenda akademik sekolah terdekat dan nomor kontak layanan pada sidebar
```

### Alur Tiket Pengaduan Tamu Publik

```
1. Buka halaman publik kontak bantuan (tanpa login)
2. Lengkapi formulir (Nama, Email, No. Telepon, Kategori, Perihal, dan Pesan)
3. Sistem secara otomatis memproteksi dari spam bot (Honeypot) dan menghasilkan nomor tiket (TKT-YYYYMMDD-XXXX)
4. Staf/Admin membuka Dashboard → Kotak Masuk Tamu
5. Staf meninjau isi pengaduan, mengubah status tiket (baru → diproses → selesai), serta mencatat tindakan lanjut
```

### Alur Layanan Persuratan, SPT & Agenda Masuk/Keluar

```
1. Siswa/Orang Tua mengajukan permohonan surat di dashboard (Ket. Aktif, SKBB, Izin Sakit)
2. Staf TU memverifikasi; jika disetujui, staf dapat langsung mencetak surat resmi berstempel digital & QR verifikasi
3. Persetujuan surat izin/sakit siswa otomatis mensinkronkan data presensi hadir/sakit/izin di student_attendance
4. Staf TU dapat menerbitkan Surat Perintah Tugas (SPT Guru & Pegawai) untuk dinas luar, MGMP, atau pelatihan
5. Staf mendokumentasikan surat masuk/keluar di buku arsip dan menginput instruksi lembar disposisi Kepala Sekolah
```

### Alur Buku Kas Umum (BKU) & Kas Pengeluaran

```
1. Staf Keuangan/Admin mengakses Dashboard Keuangan → Kas Pengeluaran
2. Klik "Catat Pengeluaran Baru" → pilih kategori belanja, tanggal, jumlah nominal (Rp), penerima, dan upload bukti kwitansi
3. Sistem otomatis mengalokasikan nomor urut Bukti Kas Keluar resmi (EXP-YYYY/MM/NNN)
4. Dashboard otomatis mengkalkulasi neraca arus kas: Total Masuk (SPP) - Total Keluar (Operasional) = Saldo Riil
5. Staf dapat memfilter laporan kas bulanan dan mencetak laporan BKU siap audit
```

### Alur Inventaris Sarpras & Sirkulasi Peminjaman

```
1. Staf/Admin Sarpras mendata barang di sarpras/inventory.php per ruangan beserta kondisi (baik/rusak ringan/rusak berat)
2. Pengguna (guru, siswa/OSIS, pembina) yang meminjam barang dicatat pada tab Sirkulasi Peminjaman
3. Sistem memantau tanggal jatuh tempo pinjaman dengan status peringatan otomatis
4. Saat barang dikembalikan, staf mengonfirmasi pengembalian dan memperbarui kondisi fisik barang
5. Staf mencetak buku rekapitulasi inventaris untuk pertanggungjawaban aset sekolah
```

### Alur Projek Penguatan Profil Pelajar Pancasila (P5 Kurikulum Merdeka)

```
1. Guru Koordinator / Admin membuka dashboard/akademik/p5_projects.php
2. Buat Projek Baru → tentukan Judul, Tema Kemendikbud, Fase, Deskripsi, dan pilih Dimensi & Sub-Elemen sasaran
3. Fasilitator / Guru Pembina membuka dashboard/akademik/p5_assessment.php
4. Pilih projek, rombel kelas, dan input capaian setiap siswa (MB / SB / BSH / SAB) per sub-elemen
5. Buka dashboard/akademik/p5_report.php → cetak Lembar Rapor P5 resmi berstandar BSKAP Kemendikbudristek
```

### Alur Alumni, Tracer Study & Permohonan Legalisir Ijazah

```
1. Alumni membuka dashboard/alumni/tracer_study.php → isi kuesioner kelulusan (kuliah, kerja, wirausaha)
2. Staf TU dapat mengekspor rekap tracer study ke CSV untuk pemenuhan bukti fisik akreditasi sekolah (BAN-SM)
3. Alumni yang membutuhkan pengesahan membuka dashboard/alumni/legalisir.php → unggah berkas scan ijazah/transkrip asli
4. Staf TU meninjau berkas, memvalidasi nomor ijazah, dan menyetujui permohonan legalisir
5. Dokumen legalisir dicetak via legalisir_print.php lengkap dengan stempel basah digital dan QR Code validasi TTE
6. Siapa pun dapat memindai QR code pada dokumen untuk mengecek keaslian via verify_document.php
```

### Alur Radar Profil Siswa 360 & Kenaikan Kelas Massal

```
1. Guru/Wali Kelas/Admin membuka dashboard/admin/student_360.php?id=[id_siswa]
2. Sistem menyajikan ringkasan 360 derajat: grafik nilai, presensi, kedisiplinan BK, keuangan, dan ekskul siswa
3. Pada akhir tahun ajaran, Admin/Staf mengakses dashboard/admin/promotion.php
4. Pilih kelas asal dan kelas tujuan → sistem memuat seluruh siswa rombel
5. Pilih status tiap siswa (Naik Kelas / Tinggal Kelas) atau proses Kelulusan Siswa Tingkat Akhir menjadi Alumni
6. Klik "Proses Kenaikan Kelas Massal" → mutasi rombel dan arsip log diproses seketika
```

### Alur Pemasangan Multi-Aplikasi (Android & PWA)

```
1. Akses portal publik apps.php atau buka dashboard/apps_hub.php
2. Untuk PWA di Browser HP/Laptop: buka halaman aplikasi (CBT / Presensi / Perpustakaan) → klik "Install Aplikasi" di menu peramban
3. Untuk Android APK via Cloud: kunjungi PWABuilder.com → masukkan tautan modul web → generate APK instan
4. Untuk Android Native Studio: buka folder apps/android/[app-folder] di Android Studio → sesuaikan URL server di app_config.json → klik Build APK
```

---

## 🔒 Keamanan

Manajemen-PHP mengimplementasikan berbagai lapisan keamanan:

| Aspek                          | Implementasi                                                                                      |
|--------------------------------|---------------------------------------------------------------------------------------------------|
| **SQL Injection**              | PDO Prepared Statements di seluruh query                                                          |
| **XSS (Cross-Site Scripting)** | `htmlspecialchars()` pada output, sanitasi HTML pengumuman dengan whitelist tag                    |
| **CSRF Protection**            | Token 64-karakter (`bin2hex(random_bytes(32))`) dengan validasi `hash_equals()`                   |
| **Session Security**           | `session_regenerate_id(true)` setelah login, cookie `HttpOnly` & `SameSite=Lax`                   |
| **Password Hashing**           | `password_hash()` dengan `PASSWORD_DEFAULT` (bcrypt) & `password_verify()`                        |
| **Rate Limiting**              | Maks. 5 percobaan login per 15 menit per session                                                  |
| **Role Enforcement**           | `requireRole()` & `requireLogin()` di setiap halaman terproteksi                                  |
| **File Upload Validation**     | Validasi biner tipe MIME (`finfo`/`exif_imagetype`), whitelist ekstensi ketat, maks. 5 MB, rename dengan `random_bytes()` |
| **Arbitrary Upload & RCE Guard** | Pustaka `handleSecureUpload()` di 23 titik unggahan & penguncian `uploads/.htaccess` (`php_flag engine off`, pemblokiran eksekusi skrip PHP/CGI) |
| **SVG Stored XSS Sanitizer**   | Pembersihan XML/DOM parser otomatis melucuti `<script>`, event listener JavaScript, dan `<foreignObject>` pada berkas vektor TTD |
| **Anti-Scraping Official Assets** | Proteksi CSS & antarmuka (`pointer-events: none; -webkit-user-drag: none; user-select: none;`) pencegah pencurian stempel & tanda tangan |
| **Offline Vector QR Fallback** | Generator SVG QR Code mandiri internal (tanpa API luar) untuk pencetakan dokumen resmi di jaringan tertutup / *air-gapped* |
| **Live Chat Security & Anti-Flood** | Pembatasan frekuensi pesan per IP/sesi (*rate limiting*), token sesi UUIDv4, dan sanitasi input pesan XSS |
| **Security Headers**           | `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `Referrer-Policy`, `CSP`         |
| **Directory Listing**          | Dinonaktifkan via `.htaccess` (`Options -Indexes`)                                                |
| **Audit Trail**                | Pencatatan aktivitas penting dengan IP address                                                     |
| **Register Role Restriction**  | Registrasi publik dibatasi hanya untuk `siswa` & `orang_tua`                                      |
| **CSRF Token Rotation**        | Token di-reset setelah login berhasil untuk mencegah reuse                                         |
| **Bot & Spam Protection**      | Honeypot trap tersembunyi pada form kontak publik untuk memblokir bot spam otomatis               |
| **QR Authenticity Token**      | Hash token SHA-256 pada kartu PPDB untuk verifikasi keaslian                                      |
| **TTE & Document Integrity**   | Validasi keabsahan dokumen berstempel digital & QR TTE (surat, legalisir, rapor, kuitansi)        |
| **Password Reset Security**    | Token reset kata sandi 64-karakter acak dengan kedaluwarsa 1 jam dan proteksi brute force          |
| **CBT Anti-Cheat Proctoring**  | Kiosk fullscreen enforcement, deteksi window blur, pencegahan tombol pintas F12/Ctrl+U, dan lockout otomatis 3x pelanggaran |

---

## 🎨 Desain & UI/UX

- **Dark Mode** sebagai tema utama (`bg-slate-950`) dengan aksen gradien biru-indigo
- **Glassmorphism** — Efek blur dan transparansi pada card dan navigasi
- **Responsive Design** — Optimal di desktop, tablet, dan mobile
- **Animasi Micro-Interaction** — Ping animation pada badge PPDB, hover transitions, gradient glows
- **Consistent Badge System** — Warna badge seragam per role, status, dan kategori
- **Accessibility** — Kontras warna memadai, navigasi keyboard, semantic HTML
- **Print-Friendly** — Halaman cetak (rapor, kuitansi, kartu ujian, surat BK) dioptimalkan untuk printer

---

## 🚀 Roadmap Transformasi Digital (Fase 1 s.d. Fase 9 - 100% Terimplementasi)

Sistem Manajemen Sekolah telah ditingkatkan secara menyeluruh melalui implementasi 9 fase transformasi teknologi modern:

### 📱 Fase 1: WhatsApp Gateway Integration Engine
* **Engine Notifikasi Terpadu (`dashboard/admin/wa_gateway.php`)**:
  * Mendukung multi-provider: Fonnte, Wabot, Twilio, UltraMsg, serta Sandbox Mode.
  * Form uji coba kirim pesan instan dengan template dinamis (`{nama}`, `{kelas}`, `{sekolah}`, `{nominal}`).
  * Tabel log pengiriman terpusat (`wa_notification_logs`) dengan status delivery, payload viewer JSON, dan rincian waktu.
* **Otomatisasi Trigger Sistem**:
  * **Absensi Siswa**: Otomatis mengirim pesan konfirmasi ke nomor WhatsApp wali murid saat siswa dinyatakan **Alpa** atau **Sakit**.
  * **Pengumuman Kedaruratan**: Mengirimkan broadcast WhatsApp otomatis kepada civitas sekolah ketika diterbitkan pengumuman berkategori **Darurat** atau **Penting**.
  * **Kuitansi Pembayaran SPP**: Mengirimkan notifikasi tanda terima dan tautan kuitansi digital seketika saat tagihan siswa dinyatakan lunas.

### 🛡️ Fase 2: CBT Anti-Cheat & Browser Proctoring Engine
* **Integritas Ujian Online (`dashboard/Modul-ujian/exam_take.php`)**:
  * **Mode Layar Penuh (Enforced Fullscreen)**: Ujian wajib dijalankan dalam mode fullscreen peramban; keluar layar penuh dihitung sebagai pelanggaran.
  * **Deteksi Tab-Blur & Switch Window**: Menggunakan `visibilitychange` dan `window.blur` untuk mendeteksi siswa yang membuka tab browser lain atau aplikasi eksternal.
  * **Keyboard & Shortcut Blocker**: Mencegah tombol developer tools (F12, Ctrl+Shift+I/J/C), pintasan inspect element (Ctrl+U), dan save shortcut (Ctrl+S).
  * **Sistem Peringatan Bertingkat**: Menampilkan HUD badge *"Anti-Cheat: X / 3"* secara real-time. Jika pelanggaran mencapai batas toleransi (3 kali), sistem menampilkan modal penguncian otomatis (lockout) dengan countdown 3 detik dan langsung mengirimkan lembar jawaban ke server dengan flag `is_locked = 1`.
* **Dashboard Penilaian Guru (`dashboard/Modul-ujian/exam_results.php`)**:
  * Menampilkan kolom **Integritas Proctoring**: Badge *Bersih / Jujur*, *Peringatan (1–2x)*, atau *Terkunci Otomatis (3x Pelanggaran)*.

### 📍 Fase 3: Geofencing GPS Radius Attendance Guru & Staf
* **Konfigurasi Titik Koordinat Sekolah (`dashboard/admin/settings.php`)**:
  * Pengaturan Latitude, Longitude, dan Radius Aman (default 150 meter).
  * Tombol deteksi otomatis koordinat GPS perangkat administrator via HTML5 Geolocation API.
* **Presensi Mandiri Pendidik (`dashboard/presensi/teacher_attendance.php`)**:
  * Live GPS Radar Card dengan penghitungan jarak presisi waktu nyata menggunakan **Formula Haversine**.
  * Status badge dinamis: *Dalam Radius (Boleh Presensi)* vs *Luar Radius (Presensi Terkunci)*.
  * Server-side validation pada aksi *Clock-In* yang menyimpan `latitude`, `longitude`, dan `distance_meters` ke basis data.
  * Tautan koordinat presensi langsung ke Google Maps pada tabel riwayat absensi.

### 💳 Fase 4: Automated Online Payment Gateway Simulation & Webhook
* **Integrasi Saluran Pembayaran Digital (`dashboard/keuangan/payments.php`)**:
  * Mendukung **QRIS Nasional** dengan kode QR dinamis bersertifikasi NMID.
  * Mendukung **Virtual Account Otomatis** untuk 4 bank besar: BCA VA (`8808...`), Mandiri VA (`8890...`), BRI VA (`8812...`), dan BNI VA (`8834...`).
  * Fitur 1-klik *Salin Nomor VA* dan petunjuk transaksi mobile banking.
* **Simulator Instant Settlement & Webhook (`api/payment_webhook.php`)**:
  * Menerima callback webhook JSON dari payment gateway.
  * Memvalidasi kode tagihan, menandai status tagihan menjadi `lunas`, mencatat `gateway_trx_id`, dan otomatis menembakkan kuitansi digital via WhatsApp Gateway.

### 💼 Fase 5: Modul Penggajian & Honor Pendidik Terintegrasi (Teacher Payroll)
* **Kompensasi Pendidik Otomatis (`dashboard/keuangan/payroll.php`)**:
  * **Kalkulasi Batch 1-Klik**: Menghitung otomatis seluruh komponen gaji guru per periode bulan:
    * Gaji pokok bulanan pendidik.
    * Tunjangan tugas tambahan Wali Kelas (Rp 500.000) otomatis terdeteksi dari tabel `classes`.
    * Honor jam mengajar (JP) yang diakumulasikan secara real-time dari entri `teaching_journals` $\times$ tarif per jam (Rp 50.000).
    * Pemotongan penalti otomatis dari catatan absensi Alpa / luar radius GPS di `teacher_attendance`.
  * **Alur Persetujuan & Pencairan**: `Draft → Disetujui (Approved) → Dibayar (Disbursed)`.
  * **Pencairan Massal & Notifikasi WhatsApp**: Pencairan gaji otomatis mengirimkan rincian transfer ke nomor WhatsApp masing-masing guru.
* **Slip Gaji Digital Resmi (`dashboard/keuangan/payroll_slip.php`)**:
  * Dokumen cetak siap PDF berstandar dinas dengan kop sekolah, tabel rincian penghasilan vs potongan, teks terbilang rupiah otomatis, stempel QR Code verifikasi, dan tanda tangan digital Bendahara & Kepala Sekolah.

### 📲 Fase 6: Progressive Web Apps (PWA) & Multi-App Android Native Studio Wrapper
* **Pusat Instalasi Aplikasi Terpadu (`apps.php` & `dashboard/apps_hub.php`)**:
  * Konsep 1 Terpusat Basis Data untuk 3 Aplikasi Spesifik:
    1. **CBT Ujian Sekolah**: Layar penuh kiosk mode anti-cheat proctoring.
    2. **Presensi Siswa & Pendidik**: Akses instan kamera QR scanner & deteksi GPS radius sekolah.
    3. **E-Perpustakaan Digital**: Sirkulasi barcode scanner & in-browser PDF reader layar penuh.
  * Dukungan Service Worker (`sw.js`) dan 3 file `manifest.json` modul untuk instalasi instan di Android/iOS/Desktop.
* **Source Code Android Native Studio Shell (`apps/android/`)**:
  * Konfigurasi fleksibel via `app_config.json` dan source code Java WebView Shell resmi siap compile menjadi 3 file installer `.apk`.

### 🌟 Fase 7: Kurikulum Merdeka P5 & Student 360 Holistic Profile Radar
* **Manajemen & Asesmen Projek P5 (`dashboard/akademik/p5_*.php`)**:
  * Penjadwalan projek, integrasi 8 tema profil pelajar Pancasila, dan penugasan tim fasilitator pendidik.
  * Rubrik asesmen standar BSKAP 4 tingkat (MB, SB, BSH, SAB) dan cetak lembar rapor P5 format resmi.
* **Radar Profil Siswa 360 Derajat (`dashboard/admin/student_360.php`)**:
  * Penyatuan data capaian akademik, presensi kehadiran, kedisiplinan BK, histori SPP keuangan, dan portofolio prestasi ekskul dalam satu layar monitoring holistik.
* **Wizard Kenaikan Kelas & Kelulusan Massal (`dashboard/admin/promotion.php`)**:
  * Transisi rombongan belajar tahun ajaran baru berbasis checklist batch dan pengarsipan otomatis siswa lulus ke status Alumni.

### 🎓 Fase 8: Ekosistem Alumni, Penelusuran Tracer Study BAN-SM & Legalisir Digital TTE
* **Instrumen Tracer Study Terakreditasi (`dashboard/alumni/tracer_study.php`)**:
  * Kuesioner penelusuran karir lulusan (studi lanjut, kerja, wirausaha) lengkap dengan visualisasi persentase serapan dan ekspor berkas akreditasi BAN-SM (.CSV).
* **Layanan Legalisir Ijazah Digital Ber-QR TTE (`dashboard/alumni/legalisir.php` & `legalisir_print.php`)**:
  * Alur pengajuan mandiri alumni, verifikasi scan ijazah asli oleh Staf TU, penerbitan lembar pengesahan legalisir berstempel dinas, dan validasi keaslian dokumen via `verify_document.php`.

### 🤖 Fase 9: Asisten AI Real-Time, Hardening Berkas Skala Enterprise & Ekosistem TTE Kedinasan (v1.3.0)
* **Asisten Virtual AI Mandiri & Real-Time Live Chat (`api/live_chat.php` & `dashboard/admin/live_chat.php`)**:
  * Arsitektur cerdas hybrid: AI lokal mandiri berbasis basis pengetahuan sekolah (*zero API cost / offline ready*) dengan fallback integrasi Google Gemini / OpenAI LLM.
  * Widget live chat pengunjung publik dan pengguna terdaftar (multi-role) dengan animasi *typing indicator* dan auto-response topik sekolah (PPDB, SPP, Kalender, dsb.).
  * Konsol Admin & Staf untuk pemantauan obrolan aktif secara real-time, pengalihan obrolan bot ke petugas (*human takeover*), dan template balasan kilat (*quick replies*).
* **Hardening Keamanan Unggahan Terpusat Skala Enterprise (`config/upload_helper.php` & `uploads/.htaccess`)**:
  * Pustaka pengaman terpusat `handleSecureUpload()` yang diimplementasikan di 23 titik unggahan berkas.
  * Validasi tipe MIME biner nyata via `finfo_file()` dan `exif_imagetype()` (anti-spoofing ekstensi).
  * Pengacakan nama berkas kriptografis SHA-256 (`random_bytes(16)`) anti-enumerasi dan *path traversal*.
  * Pembersih mutlak berkas vektor SVG dari ancaman *Stored Cross-Site Scripting* (XSS).
  * Penguncian Apache web server di `uploads/.htaccess` (`php_flag engine off` & penonaktifan eksekusi PHP/skrip).
* **Arsitektur Tanda Tangan Elektronik (TTE) & Stempel Resmi Anti-Scraping**:
  * Efek visual percampuran tinta realistis (`mix-blend-mode: multiply`) dan preservasi warna cetak (`print-color-adjust: exact`).
  * Proteksi aset legalitas dari pencurian/ekstraksi ilegal (*anti-scraping*, pelarangan klik kanan, seleksi, dan drag-and-drop).
  * Generator Kode QR Vektor Mandiri (*Standalone SVG Fallback*) untuk pencetakan dokumen resmi dalam jaringan tertutup / *air-gapped*.
  * Standardisasi Kop Surat, logo resmi, dan penanggalan pada 21 berkas template cetak kedinasan dengan resolusi path dinamis `resolveAssetUrl()`.
  * Panel kontrol Administrator untuk konfigurasi NIP, gelar akademik guru (`title_degree`), pratinjau scan TTE, unggah scan baru, dan reset ke generator SVG.

---

## ⚙️ Auto-Migration & Seeding

Sistem memiliki mekanisme **auto-migration** yang cerdas:

1. **Tabel baru** — Otomatis dibuat menggunakan `CREATE TABLE IF NOT EXISTS`
2. **Kolom baru** — Ditambahkan menggunakan `ALTER TABLE ADD COLUMN` yang di-wrap dalam try-catch
3. **Data sampel** — Otomatis di-seed jika tabel masih kosong (kalender, kelas, jadwal, mata pelajaran, tagihan, perpustakaan, log WA, payroll, dsb.)
4. **Flag session** — Migrasi hanya dijalankan sekali per session (`$_SESSION['db_migrated_v14']`)

> Ini memastikan developer baru atau deployment baru dapat langsung berjalan tanpa konfigurasi database manual.

---

## 🤝 Kontribusi

Kontribusi sangat diapresiasi! Ikuti langkah berikut:

1. **Fork** repository ini
2. **Buat branch** fitur baru:
   ```bash
   git checkout -b fitur/nama-fitur-baru
   ```
3. **Commit** perubahan Anda:
   ```bash
   git commit -m "feat: tambah fitur [nama fitur]"
   ```
4. **Push** ke branch:
   ```bash
   git push origin fitur/nama-fitur-baru
   ```
5. **Buat Pull Request** dengan deskripsi lengkap

### Konvensi Commit

```
feat:     Fitur baru
fix:      Perbaikan 
docs:     Perubahan dokumentasi
style:    Perubahan styling/UI (tanpa logika)
refactor: Refaktor kode tanpa perubahan fitur
perf:     Peningkatan performa
test:     Penambahan/perbaikan test
chore:    Perubahan build/config/tooling
```

---

## 📝 Lisensi

Proyek ini dilisensikan di bawah **MIT License** — silakan digunakan, dimodifikasi, dan didistribusikan secara bebas.

---

## 👨‍💻 Pengembang

Dikembangkan oleh [**saipul12c**](https://github.com/saipul12c)

---

<p align="center">
  <strong>⚡ Manajemen-PHP</strong> — Digitalisasi Sekolah, Sederhanakan Administrasi.<br>
  <sub>Dibangun dengan ❤️ menggunakan PHP, MySQL, dan Tailwind CSS</sub>
</p>

