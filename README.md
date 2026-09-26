<p align="center">
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
| **Database**   | MySQL 8.0+ dengan PDO (Prepared Statements)                                              |
| **Frontend**   | Tailwind CSS v4 (CDN), Font Awesome 6.5                                                  |
| **QR Code**    | html5-qrcode (scan), qrcodejs (generate)                                                 |
| **Web Server** | Apache (XAMPP / Laragon) dengan `.htaccess`                                               |
| **Session**    | PHP Native Session dengan cookie `HttpOnly`, `SameSite=Lax`                               |

### Struktur Direktori

```
manajemen-php/
│
├── index.php                    # Landing page publik (hero interaktif + statistik real-time)
├── about.php                    # Halaman profil sekolah, visi-misi, pilar peran, & keunggulan
├── kontak.php                   # Halaman kontak, pusat bantuan & tiket pengaduan publik
├── informasi.php                # Papan informasi & pengumuman sekolah publik (mading digital)
├── pengumuman.php               # Alias pengalihan langsung ke informasi.php
├── perpustakaan.php             # Katalog perpustakaan digital publik (OPAC & e-reader)
├── .htaccess                    # Konfigurasi Apache (security headers, error pages)
│
├── includes/                    # Template layout bersama untuk halaman publik
│   ├── navbar.php               #   Navbar bersama landing page & portal publik responsif
│   └── footer.php               #   Footer bersama, jam operasional, & informasi kontak
│
├── dokumentasi/                 # Arsip dokumentasi & riwayat rilis
│   ├── RELEASE-github.MD        #   Catatan rilis versi & changelog
│   └── dokumentasi-role/        #   Buku panduan operasional terperinci per peran pengguna
│       ├── Administrator.md     #     Buku panduan Super Admin (manajemen user, PPDB, audit, backup)
│       ├── Staf.md              #     Buku panduan Tata Usaha (persuratan, BKU, sarpras, perpus)
│       ├── Guru.md              #     Buku panduan Tenaga Pendidik (KBM, ujian online, presensi, rapor)
│       ├── OrangTua.md          #     Buku panduan Wali Murid (monitoring multi-anak, izin, bayar SPP)
│       └── Siswa.md             #     Buku panduan Peserta Didik (e-learning, ujian, QR, e-reader)
│
├── ppdb/                        # Modul PPDB Online Terpadu
│   ├── ppdb.php                 #   Portal pendaftaran 4 jalur & tracking seleksi mandiri
│   ├── ppdb_card.php            #   Cetak kartu pendaftaran resmi dengan barcode & QR verifikasi
│   └── ppdb_verify.php          #   Verifikasi keaslian berkas & kartu PPDB via token SHA-256
│
├── auth/                        # Modul Autentikasi
│   ├── login.php                #   Halaman login dengan rate limiting & demo credentials
│   └── register.php             #   Dialihkan ke PPDB Online (satu pintu registrasi resmi)
│
├── config/                      # Konfigurasi & Helper Sistem
│   └── database.php             #   Koneksi PDO, auto-migration v11, CSRF, helper & scoring
│
├── dashboard/                   # Area Dashboard (terproteksi login & role-based)
│   ├── index.php                #   Halaman utama dashboard (role-based widgets & feed)
│   ├── profile.php              #   Profil pengguna & edit data diri
│   │
│   ├── includes/                #   Template layout dashboard
│   │   ├── header.php           #     Header, navigasi sidebar responsif, badge notifikasi
│   │   └── footer.php           #     Footer dashboard
│   │
│   ├── admin/                   #   Panel Administrasi & Pengaturan
│   │   ├── users.php            #     CRUD manajemen pengguna & Impor Siswa Massal (CSV)
│   │   ├── student_profile_print.php # Cetak Lembar Buku Induk Siswa (Standar Kemendikbud)
│   │   ├── contact_messages.php #     Inbox pesan tamu publik & status tindak lanjut tiket
│   │   ├── classes.php          #     Manajemen kelas & rombel
│   │   ├── ppdb.php             #     Verifikasi berkas, seleksi, & 1-klik aktivasi akun siswa
│   │   ├── settings.php         #     Pengaturan identitas sekolah & kuota/gelombang PPDB
│   │   ├── audit_logs.php       #     Log aktivitas (audit trail)
│   │   └── backup.php           #     Backup & restore database
│   │
│   ├── akademik/                #   Modul Akademik
│   │   ├── timetable.php        #     Jadwal pelajaran mingguan
│   │   ├── materials.php        #     E-Learning & materi pembelajaran
│   │   ├── assignments.php      #     Tugas & pengumpulan tugas
│   │   ├── calendar.php         #     Kalender akademik & agenda
│   │   ├── gradebook.php        #     Buku nilai (gradebook)
│   │   ├── gradebook_export.php #     Export buku nilai ke spreadsheet
│   │   ├── gradebook_print.php  #     Cetak buku nilai
│   │   └── report_card.php      #     Rapor digital siswa
│   │
│   ├── Modul-ujian/             #   Sistem Ujian & Asesmen Online
│   │   ├── exams.php            #     Manajemen bank ujian & latihan
│   │   ├── exam_questions.php   #     Editor soal (PG + Esai)
│   │   ├── exam_take.php        #     Halaman mengerjakan ujian
│   │   ├── exam_results.php     #     Hasil & analisis ujian
│   │   ├── exam_card.php        #     Cetak kartu ujian
│   │   ├── exam_print.php       #     Cetak hasil ujian
│   │   └── exam_export.php      #     Export hasil ujian
│   │
│   ├── presensi/                #   Modul Presensi Kehadiran
│   │   ├── attendance.php       #     Input & rekap presensi
│   │   ├── scan_qr.php          #     Scan QR Code presensi
│   │   ├── qr_card.php          #     Generate kartu QR siswa
│   │   ├── attendance_report.php#     Laporan statistik presensi
│   │   ├── attendance_print.php #     Cetak laporan presensi
│   │   └── attendance_export.php#     Export data presensi
│   │
│   ├── keuangan/                #   Modul Keuangan & SPP
│   │   ├── payments.php         #     Tagihan & pembayaran siswa
│   │   ├── expenses.php         #     Buku Kas Umum (BKU) & pengeluaran kas operasional
│   │   ├── receipt.php          #     Cetak kuitansi pembayaran
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
│   │   └── clearance.php        #     Penerbitan surat bebas pustaka digital
│   │
│   ├── bk/                      #   Modul Bimbingan Konseling (BK)
│   │   ├── counseling.php       #     Catatan pelanggaran & prestasi
│   │   └── counseling_letter.php#     Cetak surat BK
│   │
│   ├── informasi/               #   Modul Informasi & Pengumuman
│   │   ├── announcements.php    #     CRUD pengumuman (rich text editor)
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
│   └── database.sql             #   File SQL lengkap (DDL + seed data 35 tabel)
│
└── uploads/                     # Direktori Berkas Upload Sistem
    ├── announcements/           #   Lampiran dokumen edaran pengumuman
    ├── assignments/             #   Lampiran soal tugas & berkas pengumpulan jawaban siswa
    ├── books/                   #   Gambar cover buku & file modul / e-book PDF
    ├── exams/                   #   Gambar pendukung soal ujian online
    ├── expenses/                #   Scan berkas nota / kwitansi kas keluar BKU
    ├── letters/                 #   Lampiran surat izin/sakit & scan agenda surat masuk/keluar
    ├── materials/               #   Berkas materi pembelajaran E-Learning
    ├── payments/                #   Bukti transfer pembayaran tagihan SPP
    └── ppdb/                    #   Dokumen pendaftaran calon siswa (rapor, KK, akta, foto)
```

---

## 👥 Sistem Role (Hak Akses)

Manajemen-PHP mengimplementasikan 5 peran pengguna dengan hak akses berlapis:

| # | Role                | Deskripsi                               | Hak Akses Utama                                                                                                     |
|---|---------------------|-----------------------------------------|----------------------------------------------------------------------------------------------------------------------|
| 1 | 🔴 **Administrator** | Pengelola tertinggi sistem              | Full access — CRUD semua data, manajemen pengguna & kelas, PPDB, pengaturan sekolah, audit log, backup database     |
| 2 | 🟠 **Staf**          | Tata usaha / administrasi sekolah       | Administrasi persuratan (agenda surat masuk/keluar, disposisi KS, terbitkan surat resmi & SPT Guru/Pegawai), inventaris sarpras & sirkulasi pinjam, Buku Kas Umum (BKU) kas operasional, verifikasi pembayaran SPP, PPDB, kelola akun siswa & orang tua, impor massal CSV, cetak lembar buku induk siswa, inbox pesan tamu |
| 3 | 🟢 **Guru**          | Pengajar / tenaga pendidik              | Buat & kelola ujian/latihan, soal, materi E-Learning, tugas, penilaian, presensi siswa, buku nilai, BK, scan QR     |
| 4 | 🟣 **Orang Tua**     | Wali murid / orang tua siswa            | Monitoring nilai anak, presensi, tagihan keuangan, pengumuman, pesan konsultasi ke guru, catatan BK                 |
| 5 | 🔵 **Siswa**         | Peserta didik aktif                     | Mengerjakan ujian/latihan, lihat materi & tugas, presensi, jadwal, nilai rapor, perpustakaan, pesan ke guru         |

> **Catatan:** Registrasi publik hanya mengizinkan role `siswa` dan `orang_tua`. Role `administrator`, `staf`, dan `guru` hanya dapat dibuat oleh Administrator melalui panel admin.

### 📚 Buku Panduan Operasional Per Role

Untuk panduan mendalam langkah-demi-langkah (SOP), alur kerja terperinci, dan batasan wewenang teknis untuk setiap stakeholder sekolah, silakan pelajari manual operasional khusus yang telah disusun secara profesional:

| Peran Pengguna | Dokumen Panduan | Fokus & Cakupan Operasional Utama |
| :--- | :--- | :--- |
| 🔴 **Administrator** | [**Administrator.md**](dokumentasi/dokumentasi-role/Administrator.md) | Otoritas penuh sistem, tata kelola akun 5 role, pengaturan kuota & gelombang PPDB, verifikasi skoring 4 jalur, audit log keamanan, pemeliharaan database (*backup & restore*), serta cetak lembar buku induk siswa Kemendikbud. |
| 🟠 **Staf Tata Usaha** | [**Staf.md**](dokumentasi/dokumentasi-role/Staf.md) | Operasional harian kesiswaan, verifikasi permohonan surat & sinkronisasi absensi otomatis, penerbitan SPT dinas GTK ber-QR verifikasi, Buku Agenda Surat Masuk/Keluar (`AG-IN`/`AG-OUT`), Buku Kas Umum (BKU), inventaris sarpras, dan sirkulasi perpustakaan (Auto-Fill ISBN). |
| 🟢 **Guru / Pendidik** | [**Guru.md**](dokumentasi/dokumentasi-role/Guru.md) | Distribusi materi pembelajaran E-Learning, tugas ber-deadline & feedback evaluasi, bank asesmen ujian/latihan 6 kategori, editor soal PG & esai, token & acak soal, sistem remedial, presensi QR webcam, pembinaan BK, dan pengisian rapor semester bagi Wali Kelas. |
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
  - Terintegrasi langsung dengan kotak masuk admin (`dashboard/admin/contact_messages.php`)
  - Informasi jam operasional layanan TU, kontak darurat, email, dan integrasi peta navigasi sekolah
- **📢 Papan Informasi & Pengumuman Mandiri (`informasi.php` & `pengumuman.php`)**:
  - Akses publik tanpa perlu login untuk transparansi informasi civitas sekolah
  - Menampilkan pengumuman resmi berkategori: **Penting, Darurat, Akademik, Kegiatan, dan Umum**
  - **Banner Peringatan Khusus** untuk edaran darurat / disematkan (*pinned*)
  - **Live Search & Filter Kategori Interaktif** berbasis JavaScript instan
  - **Modal Detail Pop-Up** lengkap dengan pembuat, waktu tayang, pratinjau lampiran PDF, dan tombol cetak
  - **Widget Kalender & Agenda Mendatang** terintegrasi dengan tabel `calendar_events`

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
  - Kartu pendaftaran (`ppdb_card.php`) dilengkapi Barcode registrasi dan QR Code ber-token enkripsi SHA-256
  - Panitia/petugas dapat memindai QR code untuk memvalidasi keabsahan data tanpa risiko manipulasi berkas fisik
- **Otomatisasi 1-Klik Penerimaan Siswa Baru**:
  - Saat panitia menetapkan status calon siswa menjadi **Diterima** ke kelas/jurusan:
    1. Otomatis membuat/mengaktifkan akun login siswa di tabel `users`
    2. Otomatis membuat akun wali murid (orang tua) dan relasi pada tabel `parent_students`
    3. Otomatis mengaitkan kewajiban tagihan keuangan awal siswa baru pada tabel `student_bills`
- **Manajemen Gelombang & Kuota PPDB (`dashboard/admin/settings.php`)**:
  - Admin dapat membuka/menutup portal PPDB, mengubah nama gelombang, membatasi kuota daya tampung, mengatur batas periode tanggal, dan menampilkan pesan penutupan kustom

### 📚 Modul Akademik

- **Jadwal Pelajaran** — Tabel jadwal mingguan per kelas (Senin–Sabtu) dengan informasi guru, ruangan, dan waktu
- **Materi & E-Learning** — Upload dan bagikan materi pembelajaran (dokumen, video YouTube, link Google Drive) dengan tracking jumlah unduhan
- **Tugas & Pengumpulan** — Guru membuat tugas dengan deadline; siswa submit jawaban dengan file attachment; penilaian & feedback guru
- **Kalender Akademik** — Agenda sekolah dengan kategori (akademik, libur, kegiatan, ujian) dan tampilan visual kalender interaktif
- **Buku Nilai (Gradebook)** — Rekap seluruh nilai siswa per mata pelajaran, cetak & export ke spreadsheet
- **Rapor Digital** — Cetak rapor semester lengkap dengan catatan wali kelas dan kegiatan ekstrakurikuler

### 📝 Sistem Ujian & Asesmen Online

- **6 Kategori Asesmen**:
  - Ujian: UTS, UKK, Ujian Harian
  - Latihan: Latihan Harian, Latihan Mingguan, Latihan Bulanan
- **Editor Soal** mendukung Pilihan Ganda & Esai dengan gambar soal
- **Token akses** untuk keamanan ujian (kode unik per ujian)
- **Timer countdown** dengan durasi kustom per ujian
- **Acak urutan soal** (randomize questions) untuk mencegah kecurangan
- **Auto-grading** soal pilihan ganda & penilaian manual esai oleh guru
- **Passing grade** yang dapat disesuaikan per ujian
- **Sistem Remedial** — Guru memberikan kesempatan remedial bagi siswa yang tidak lulus
- **Cetak & Export** hasil ujian per siswa maupun per kelas
- **Kartu ujian** yang dapat dicetak dengan jadwal & identitas siswa

### ✅ Presensi Kehadiran

- Input presensi harian per siswa dengan status: **Hadir, Sakit, Izin, Alpa**
- **Scan QR Code** — Presensi cepat menggunakan kamera/webcam
- **Generate Kartu QR** unik per siswa untuk absensi
- Presensi per mata pelajaran (granular)
- **Laporan statistik** kehadiran (persentase, grafik tren)
- **Cetak & Export** laporan presensi ke spreadsheet

### 💰 Modul Keuangan, SPP & Buku Kas Umum (BKU)

- **Manajemen Tagihan & SPP**:
  - Jenis tagihan fleksibel (SPP bulanan, uang kegiatan, sumbangan sarana, dll.)
  - Penagihan per siswa dengan periode bulan, tahun ajaran, dan tanggal jatuh tempo
  - Metode pembayaran: Transfer Bank, Tunai, QRIS dengan upload bukti transfer
  - Pipeline verifikasi: `Belum Lunas → Menunggu Verifikasi → Lunas`
  - Cetak kuitansi resmi pembayaran berstempel digital
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

### 📢 Informasi & Pengumuman

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

- **Manajemen Pengguna & Kesiswaan**:
  - CRUD pengguna (5 role) dengan proteksi hak akses hirarkis (Staf TU khusus mengelola siswa & wali murid)
  - **Impor Siswa Massal via CSV** — Unggah banyak data siswa sekaligus dari Excel/CSV lengkap dengan pemetaan kelas otomatis dan pembuatan password default
  - **Unduh Template CSV** contoh resmi langsung dari dashboard
  - **Cetak Lembar Buku Induk Siswa (`student_profile_print.php`)** — Lembar buku induk standar Kemendikbud memuat Bagian A–D (Data Diri, Alamat, Asal Sekolah, Orang Tua/Wali), kotak Pas Foto 3x4 cm, dan tanda tangan pengesahan Kepala Sekolah & Staf TU
- **Kotak Masuk Pesan Tamu Publik (`contact_messages.php`)**:
  - Menerima dan mengelola tiket pengaduan/pertanyaan publik dari formulir `kontak.php`
  - Status penanganan tiket (`baru`, `diproses`, `selesai`) dan pencatatan catatan tindak lanjut admin/staf
  - Indikator badge pesan tamu belum dibaca pada sidebar navigasi
- **Manajemen Kelas** — Buat dan kelola rombongan belajar (rombel) serta penugasan wali kelas
- **Verifikasi PPDB** — Review, verifikasi dokumen, dan seleksi calon peserta didik baru
- **Pengaturan Sekolah** — Identitas sekolah (nama, NPSN, alamat, telepon, email, website, kepala sekolah, NIP, tahun ajaran, logo)
- **Audit Log** — Pencatatan seluruh aktivitas penting dalam sistem (login, update, delete, penerbitan surat, transaksi kas) dengan IP address
- **Backup & Restore Database** — Export dan import skema + data database secara aman

### 🚨 Custom Error Pages

- Halaman error premium untuk kode: **400, 401, 403, 404, 419, 500, 503**
- Desain visual konsisten dengan branding aplikasi
- Pesan error informatif dalam Bahasa Indonesia
- Saran penyelesaian masalah untuk pengguna
- Penanganan cerdas error koneksi database (MySQL mati, database tidak ditemukan, akses ditolak)

---

## 🗄️ Skema Database

Sistem menggunakan database `website_login` dengan **35 tabel** yang saling berelasi:

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
| 9  | `exam_submissions`        | Hasil pengerjaan ujian siswa (skor, remedial)                |
| 10 | `student_attendance`      | Presensi kehadiran harian per siswa per mata pelajaran       |
| 11 | `calendar_events`         | Kalender akademik & agenda kegiatan sekolah                  |
| 12 | `parent_students`         | Relasi orang tua (wali murid) ↔ siswa                        |
| 13 | `classes`                 | Data kelas / rombongan belajar (rombel)                      |
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

> 💡 **Catatan Modul:** Fitur Surat Bebas Perpustakaan (`dashboard/perpustakaan/clearance.php`) beroperasi secara dinamis memeriksa status tanggungan pinjaman aktif dan denda keterlambatan langsung dari tabel `library_loans` tanpa memerlukan tabel terpisah.
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
| Administrator | admin@gmail.com              | admin123      |
| Staf          | staff@gmail.com              | staff123      |
| Guru          | guru@gmail.com               | guru123       |
| Orang Tua     | orangtua@gmail.com           | orangtua123   |
| Siswa         | siswa@gmail.com              | siswa123      |

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
1. Buka halaman publik: perpustakaan.php (tanpa harus login terlebih dahulu)
2. Cari buku atau filter kategori; baca e-book langsung via In-Browser PDF Reader
3. Siswa login → Buka modal detail buku fisik → Klik "Booking / Reservasi Buku"
4. Buku di-booking dan ditahan selama 2 hari di meja sirkulasi
5. Staf/Petugas di Dashboard Perpustakaan (reservations.php) memverifikasi dan klik "Proses Jadi Pinjaman"
6. Setelah selesai membaca, siswa dapat memberikan rating (1-5 bintang) & ulasan pada katalog buku
7. Staf menambahkan buku baru di books.php dengan fitur Auto-Fill ISBN via API secara otomatis
```

### Alur Papan Informasi & Pengumuman Publik

```
1. Akses halaman mandiri informasi.php atau pengumuman.php tanpa perlu login
2. Gunakan Live Search untuk menyaring kata kunci pada judul atau isi edaran
3. Filter berdasarkan kategori (Penting, Darurat, Akademik, Kegiatan, Umum) atau target sasaran
4. Klik kartu pengumuman untuk membuka modal pop-up baca pengumuman lengkap
5. Unduh lampiran resmi (PDF/dokumen) atau klik "Cetak Dokumen"
6. Pantau agenda akademik sekolah terdekat dan nomor kontak layanan pada sidebar
```

### Alur Tiket Pengaduan Tamu Publik

```
1. Buka halaman publik kontak.php (tanpa login)
2. Lengkapi formulir (Nama, Email, No. Telepon, Kategori, Perihal, dan Pesan)
3. Sistem secara otomatis memproteksi dari spam bot (Honeypot) dan menghasilkan nomor tiket (TKT-YYYYMMDD-XXXX)
4. Staf/Admin membuka Dashboard → Kotak Masuk Tamu (contact_messages.php)
5. Staf meninjau isi pengaduan, mengubah status tiket (baru → diproses → selesai), serta mencatat tindakan lanjut
```

### Alur Layanan Persuratan, SPT & Agenda Masuk/Keluar

```
1. Siswa/Orang Tua mengajukan permohonan surat di dashboard/surat/requests.php (Ket. Aktif, SKBB, Izin Sakit)
2. Staf TU memverifikasi; jika disetujui, staf dapat langsung mencetak surat resmi berstempel digital & QR verifikasi (request_print.php)
3. Persetujuan surat izin/sakit siswa otomatis mensinkronkan data presensi hadir/sakit/izin di student_attendance
4. Staf TU dapat menerbitkan Surat Perintah Tugas (SPT Guru & Pegawai) untuk dinas luar, MGMP, atau pelatihan
5. Staf mendokumentasikan surat masuk/keluar di archives.php dan menginput instruksi lembar disposisi Kepala Sekolah
```

### Alur Buku Kas Umum (BKU) & Kas Pengeluaran

```
1. Staf Keuangan/Admin mengakses Dashboard Keuangan → Kas Pengeluaran (expenses.php)
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
| **File Upload Validation**     | Whitelist ekstensi (pdf, jpg, jpeg, png), maks. 5 MB, rename dengan `random_bytes()`              |
| **Security Headers**           | `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `Referrer-Policy`, `CSP`         |
| **Directory Listing**          | Dinonaktifkan via `.htaccess` (`Options -Indexes`)                                                |
| **Audit Trail**                | Pencatatan aktivitas penting dengan IP address                                                     |
| **Register Role Restriction**  | Registrasi publik dibatasi hanya untuk `siswa` & `orang_tua`                                      |
| **CSRF Token Rotation**        | Token di-reset setelah login berhasil untuk mencegah reuse                                         |
| **Bot & Spam Protection**      | Honeypot trap tersembunyi pada form kontak publik (`kontak.php`) untuk memblokir bot spam otomatis|
| **QR Authenticity Token**      | Hash token SHA-256 pada kartu PPDB untuk verifikasi keaslian via `ppdb_verify.php`                |

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

## ⚙️ Auto-Migration & Seeding

Sistem memiliki mekanisme **auto-migration** yang cerdas:

1. **Tabel baru** — Otomatis dibuat menggunakan `CREATE TABLE IF NOT EXISTS`
2. **Kolom baru** — Ditambahkan menggunakan `ALTER TABLE ADD COLUMN` yang di-wrap dalam try-catch
3. **Data sampel** — Otomatis di-seed jika tabel masih kosong (kalender, kelas, jadwal, mata pelajaran, tagihan, perpustakaan, dsb.)
4. **Flag session** — Migrasi hanya dijalankan sekali per session (`$_SESSION['db_migrated_v11']`)

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
fix:      Perbaikan bug
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
