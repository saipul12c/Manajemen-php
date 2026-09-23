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
├── index.php                    # Landing page publik (hero + navigasi)
├── ppdb.php                     # Portal PPDB Online (pendaftaran + cek status)
├── ppdb_card.php                # Cetak kartu pendaftaran PPDB (barcode)
├── .htaccess                    # Konfigurasi Apache (security headers, error pages)
│
├── auth/                        # Modul Autentikasi
│   ├── login.php                #   Halaman login dengan rate limiting
│   └── register.php             #   Halaman registrasi (siswa & orang tua)
│
├── config/                      # Konfigurasi & Helper Sistem
│   └── database.php             #   Koneksi PDO, auto-migration, CSRF, helper functions
│
├── dashboard/                   # Area Dashboard (terproteksi login)
│   ├── index.php                #   Halaman utama dashboard (role-based widgets)
│   ├── profile.php              #   Profil pengguna & edit data diri
│   │
│   ├── includes/                #   Template layout
│   │   ├── header.php           #     Header, navigasi, sidebar
│   │   └── footer.php           #     Footer
│   │
│   ├── admin/                   #   Panel Administrasi
│   │   ├── users.php            #     CRUD manajemen pengguna
│   │   ├── classes.php          #     Manajemen kelas & rombel
│   │   ├── ppdb.php             #     Verifikasi & seleksi PPDB
│   │   ├── settings.php         #     Pengaturan identitas sekolah
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
│   │   ├── receipt.php          #     Cetak kuitansi pembayaran
│   │   ├── financial_report.php #     Laporan keuangan
│   │   ├── financial_print.php  #     Cetak laporan keuangan
│   │   └── financial_export.php #     Export data keuangan
│   │
│   ├── perpustakaan/            #   Modul Perpustakaan Digital
│   │   ├── books.php            #     Katalog & manajemen buku
│   │   └── loans.php            #     Sirkulasi peminjaman & pengembalian
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
│       ├── requests.php         #     Pengajuan surat & layanan
│       └── request_print.php    #     Cetak surat permohonan
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
│   └── database.sql             #   File SQL lengkap (DDL + seed data)
│
└── uploads/                     # Direktori Upload File
    └── exams/                   #   File soal ujian (gambar, dll.)
```

---

## 👥 Sistem Role (Hak Akses)

Manajemen-PHP mengimplementasikan 5 peran pengguna dengan hak akses berlapis:

| # | Role                | Deskripsi                               | Hak Akses Utama                                                                                                     |
|---|---------------------|-----------------------------------------|----------------------------------------------------------------------------------------------------------------------|
| 1 | 🔴 **Administrator** | Pengelola tertinggi sistem              | Full access — CRUD semua data, manajemen pengguna & kelas, PPDB, pengaturan sekolah, audit log, backup database     |
| 2 | 🟠 **Staf**          | Tata usaha / administrasi sekolah       | Manajemen keuangan (tagihan & verifikasi pembayaran), pengumuman, layanan surat, PPDB, laporan keuangan             |
| 3 | 🟢 **Guru**          | Pengajar / tenaga pendidik              | Buat & kelola ujian/latihan, soal, materi E-Learning, tugas, penilaian, presensi siswa, buku nilai, BK, scan QR     |
| 4 | 🟣 **Orang Tua**     | Wali murid / orang tua siswa            | Monitoring nilai anak, presensi, tagihan keuangan, pengumuman, pesan konsultasi ke guru, catatan BK                 |
| 5 | 🔵 **Siswa**         | Peserta didik aktif                     | Mengerjakan ujian/latihan, lihat materi & tugas, presensi, jadwal, nilai rapor, perpustakaan, pesan ke guru         |

> **Catatan:** Registrasi publik hanya mengizinkan role `siswa` dan `orang_tua`. Role `administrator`, `staf`, dan `guru` hanya dapat dibuat oleh Administrator melalui panel admin.

---

## ✨ Fitur Utama

### 🏠 Landing Page & Autentikasi

- **Landing Page** modern dan responsif dengan hero section dan preview dashboard
- **Login** dengan proteksi rate limiting (maks. 5 percobaan per 15 menit)
- **Registrasi** mandiri untuk siswa dan orang tua
- **Session management** dengan `session_regenerate_id()` untuk mencegah session fixation
- **CSRF Protection** di seluruh form dengan token 64-karakter

### 🎓 PPDB Online (Penerimaan Peserta Didik Baru)

- Portal pendaftaran mandiri calon siswa baru TA 2026/2027
- Formulir biodata lengkap (data pribadi, orang tua, asal sekolah, jurusan)
- Upload berkas dokumen (rapor, akta lahir, kartu keluarga, pas foto) — maks. 5 MB per file
- Nomor registrasi otomatis (`PPDB-2026-XXXX`)
- Cek status pendaftaran real-time via NISN atau nomor registrasi
- **Cetak kartu pendaftaran** dengan barcode unik
- Pipeline seleksi: `Menunggu Verifikasi → Terverifikasi → Lulus Seleksi → Diterima`
- Panel verifikasi & manajemen PPDB untuk Admin

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

### 💰 Modul Keuangan & SPP

- **Jenis Tagihan** yang fleksibel (SPP bulanan, kegiatan, sumbangan, dll.)
- **Tagihan per siswa** dengan periode bulan, tahun ajaran, dan tanggal jatuh tempo
- **Pembayaran** mendukung metode: Transfer Bank, Tunai, QRIS
- Upload bukti pembayaran dengan verifikasi oleh Staf/Admin
- Pipeline status: `Belum Lunas → Menunggu Verifikasi → Lunas`
- **Cetak kuitansi** pembayaran resmi
- **Laporan keuangan** komprehensif dengan filter periode dan export

### 📖 Perpustakaan Digital

- **Katalog buku** lengkap (kode, ISBN, judul, penulis, penerbit, tahun, kategori)
- Manajemen stok buku (total & tersedia)
- Informasi lokasi rak penyimpanan
- Dukungan **E-Book** (file PDF digital)
- **Sirkulasi Peminjaman** — Tanggal pinjam, jatuh tempo, tanggal kembali
- Perhitungan **denda keterlambatan** otomatis
- Status buku: `Dipinjam → Dikembalikan` atau `Hilang`

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

### 📄 Layanan Surat & Permohonan

- Pengajuan layanan administratif (surat keterangan, izin, dll.)
- Pipeline status: `Menunggu → Diproses → Selesai / Ditolak`
- Lampiran dokumen pendukung
- **Cetak surat** permohonan resmi

### 🛠️ Panel Administrasi

- **Manajemen Pengguna** — CRUD user, assign role, assign kelas untuk siswa
- **Manajemen Kelas** — Buat dan kelola rombongan belajar (rombel)
- **Verifikasi PPDB** — Review, verifikasi, dan seleksi calon siswa baru
- **Pengaturan Sekolah** — Identitas sekolah (nama, alamat, telepon, email, website, kepala sekolah, NIP, tahun ajaran, logo)
- **Audit Log** — Pencatatan seluruh aktivitas penting dalam sistem (login, update, delete, dll.) dengan IP address
- **Backup & Restore Database** — Export dan import skema + data database

### 🚨 Custom Error Pages

- Halaman error premium untuk kode: **400, 401, 403, 404, 419, 500, 503**
- Desain visual konsisten dengan branding aplikasi
- Pesan error informatif dalam Bahasa Indonesia
- Saran penyelesaian masalah untuk pengguna
- Penanganan cerdas error koneksi database (MySQL mati, database tidak ditemukan, akses ditolak)

---

## 🗄️ Skema Database

Sistem menggunakan database `website_login` dengan **25+ tabel** yang saling berelasi:

| #  | Tabel                     | Deskripsi                                                    |
|----|---------------------------|--------------------------------------------------------------|
| 1  | `users`                   | Data pengguna (nama, email, password hash, role, kelas)      |
| 2  | `announcements`           | Pengumuman sekolah (target role, kategori, pin, expiry)      |
| 3  | `announcement_reads`      | Tracking pembaca pengumuman                                  |
| 4  | `assignments`             | Tugas pembelajaran dari guru                                 |
| 5  | `assignment_submissions`  | Pengumpulan tugas oleh siswa (file, nilai, feedback)         |
| 6  | `service_requests`        | Permohonan layanan administrasi & surat                      |
| 7  | `exams`                   | Bank ujian & latihan (token, durasi, passing grade, timer)   |
| 8  | `exam_questions`          | Soal ujian (PG + Esai, gambar, bobot skor)                   |
| 9  | `exam_submissions`        | Hasil pengerjaan ujian siswa (skor, remedial)                |
| 10 | `student_attendance`      | Presensi kehadiran harian per siswa per mapel                |
| 11 | `calendar_events`         | Kalender akademik & agenda kegiatan sekolah                  |
| 12 | `parent_students`         | Relasi orang tua ↔ siswa (wali murid)                       |
| 13 | `classes`                 | Data kelas / rombongan belajar                               |
| 14 | `school_settings`         | Konfigurasi identitas sekolah (key-value)                    |
| 15 | `audit_logs`              | Log aktivitas pengguna (audit trail)                         |
| 16 | `subjects`                | Data mata pelajaran                                          |
| 17 | `timetables`              | Jadwal pelajaran mingguan per kelas                          |
| 18 | `learning_materials`      | Materi pembelajaran & E-Learning                             |
| 19 | `payment_types`           | Jenis tagihan keuangan                                       |
| 20 | `student_bills`           | Tagihan keuangan per siswa                                   |
| 21 | `bill_payments`           | Transaksi pembayaran tagihan                                 |
| 22 | `counseling_records`      | Catatan BK (pelanggaran & prestasi siswa)                    |
| 23 | `messages`                | Pesan internal antar pengguna                                |
| 24 | `ppdb_registrations`      | Pendaftaran calon peserta didik baru                         |
| 25 | `library_books`           | Katalog buku perpustakaan                                    |
| 26 | `library_loans`           | Sirkulasi peminjaman & pengembalian buku                     |
| 27 | `student_report_notes`    | Catatan rapor (wali kelas & ekstrakurikuler)                 |

> **Auto-Migration:** Sistem secara otomatis membuat tabel dan menjalankan migrasi kolom baru saat pertama kali diakses. Tidak perlu import SQL manual (opsional).

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
4. **Flag session** — Migrasi hanya dijalankan sekali per session (`$_SESSION['db_migrated_v7']`)

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
