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

**Full Changelog**: https://github.com/saipul12c/Manajemen-php/compare/v1.0.0...v1.1.0

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
