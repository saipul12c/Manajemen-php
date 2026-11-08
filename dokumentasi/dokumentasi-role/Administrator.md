# 🔴 Panduan Operasional Peran: Administrator (Super Admin)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP v1.3.0**

---

## 1. Ringkasan Peran & Tanggung Jawab

Peran **Administrator** (*Super Admin*) adalah pemegang otoritas dan kedaulatan tertinggi dalam ekosistem **Manajemen-PHP**. Administrator memegang wewenang penuh atas tata kelola sistem, manajemen identitas dan hak akses civitas akademika, konfigurasi kelembagaan, integrasi multi-API eksternal, pengawasan keamanan siber, integritas dokumen kedinasan, audit transaksi operasional, serta kontinuitas pangkalan data (*database disaster recovery*).

### 🛡️ Karakteristik & Hak Istimewa Otoritas
- **Tingkat Akses:** *Full Root Access* (Seluruh modul, konfigurasi inti, dan pengaturan pangkalan data).
- **Manajemen Identitas Multi-Peran:** Mengelola seluruh akun pengguna lintas peran (*administrator*, *staf*, *guru*, *orang_tua*, *siswa*), termasuk peninjauan NIP/NUPTK, gelar akademik, serta berkas Tanda Tangan Elektronik (TTE) guru.
- **Kedaulatan Pangkalan Data:** Wewenang tunggal untuk ekspor/impor cadangan database (*backup & restore* SQL), rollover kenaikan kelas massal, serta audit log sistem (*audit trail forensics*).
- **Integrasi Gerbang Eksternal:** Kendali konfigurasi Multi-API Gateway (WhatsApp Gateway, Payment Gateway Midtrans/Xendit/Tripay, Webhook otomatis, SMTP Mail, Google Maps, dan REST API RFID perangkat keras presensi).
- **Pusat Layanan Live Chat AI:** Supervisi interaksi asisten virtual AI dan pengambilalihan obrolan langsung (*human takeover*).

---

## 2. Navigasi & Struktur Menu Administrator

| Kelompok Menu | Sub-Menu / Modul | File Eksekusi | Deskripsi Tugas & Wewenang Eksekutif |
| :--- | :--- | :--- | :--- |
| **Menu Utama** | Beranda Dasbor | `dashboard/index.php` | Ikhtisar eksekutif: metrik civitas, statistik keuangan riil, absensi hari ini, & feed aktivitas sistem. |
| | Pasang Aplikasi (Hub) | `dashboard/apps_hub.php` | Portal distribusi 3 aplikasi mobile terpadu (CBT Kiosk, Presensi Geofencing, E-Perpus Scanner). |
| | Kalender Akademik | `dashboard/akademik/calendar.php` | Tata kelola kalender pendidikan, agenda rapat dinas, jadwal asesmen, & libur nasional. |
| | Pengumuman Sekolah | `dashboard/informasi/announcements.php` | Publikasi surat edaran kedinasan multi-target (semua civitas / per role) berlampiran PDF. |
| | Berita & Redaksi | `dashboard/informasi/news.php` | Moderasi redaksi, persetujuan draft artikel liputan jurnalisme guru/staf, & featured headlines. |
| | Pesan & Konsultasi | `dashboard/pesan/messages.php` | Pusat komunikasi privat internal antar civitas sekolah (guru, siswa, orang tua). |
| **Pembelajaran & KBM**| Jadwal Pelajaran | `dashboard/akademik/timetable.php` | Pemetaan alokasi jadwal mengajar mingguan guru, jam pelajaran, rombel, & ruangan. |
| | Presensi Kehadiran | `dashboard/presensi/attendance.php` | Pemantauan presensi harian siswa rombel, rekap bulanan (`attendance_report.php`). |
| | Scan QR Presensi | `dashboard/presensi/scan_qr.php` | Pemindai QR/Barcode kartu identitas siswa presensi gerbang/kelas via webcam. |
| | Presensi Mandiri Pendidik | `dashboard/presensi/teacher_attendance.php` | Presensi masuk (*Clock-In*), pulang (*Clock-Out*), geofencing Haversine $\le 150\text{ m}$, & rekap cuti. |
| | Jurnal Mengajar KBM | `dashboard/akademik/journal.php` | Supervisi buku agenda tatap muka harian dewan guru, KBM terkendala, & delegasi guru piket/inval. |
| | Perangkat Ajar & RPP | `dashboard/akademik/curriculum.php` | Supervisi perangkat ajar (Modul Ajar, ATP, Prota, Promes, KKTP) & verifikasi status dokumen. |
| | Bahan Ajar & Modul | `dashboard/akademik/materials.php` | Audit distribusi bahan ajar digital (PDF, slide PPT, video YouTube, Google Drive). |
| | Tugas & Evaluasi | `dashboard/akademik/assignments.php` | Monitoring penugasan akademik siswa, submission berkas pengerjaan, & scoring guru. |
| | Buku Nilai Siswa | `dashboard/akademik/gradebook.php` | Leger nilai komprehensif seluruh mata pelajaran dan cetak ledger resmi dinas (`gradebook_print.php`). |
| | E-Rapor Akademik | `dashboard/akademik/report_card.php` | Supervisi pencetakan buku rapor Kurikulum Merdeka (Capaian Tertinggi & Perlu Bimbingan). |
| | Rapor Projek P5 | `dashboard/akademik/p5_projects.php` | Manajemen 8 tema P5 BSKAP, penetapan koordinator/fasilitator, rubrik fase, & cetak rapor P5. |
| | Ekskul & Prestasi | `dashboard/ekskul/index.php` | Direktori ekstrakurikuler, presensi pembina, Hall of Fame prestasi, & ekspor CSV akreditasi BAN-SM. |
| | Dashboard Wali Kelas | `dashboard/akademik/homeroom.php` | Monitoring rombel binaan, rekap tugas tertunda, poin BK, & cetak rapor massal 1 rombel. |
| **Ujian & CBT** | Modul Ujian CBT | `dashboard/Modul-ujian/exams.php` | Manajemen bank ujian (6 kategori), token asesmen, acak soal, & proctoring anti-cheat. |
| | Bank Soal & Analisis | `dashboard/Modul-ujian/exam_questions.php`| Editor butir soal PG/Esai, impor CSV, & analisis daya beda/indeks kesukaran butir soal ($P$). |
| | Kartu & Berita Acara | `dashboard/Modul-ujian/exam_card.php` | Penerbitan kartu tanda peserta asesmen dan cetak Berita Acara & Lembar Presensi Ruang CBT. |
| **Layanan Sekolah** | Kasir SPP & Tagihan | `dashboard/keuangan/payments.php` | Penetapan tarif tagihan, verifikasi bukti bayar transfer/QRIS, penerbitan kuitansi ber-QR. |
| | Buku Kas Umum (BKU) | `dashboard/keuangan/expenses.php` | Pencatatan bukti kas keluar (EXP), upload struk/nota, & neraca arus kas riil otomatis. |
| | Penggajian (Payroll) | `dashboard/keuangan/payroll.php` | Otomatisasi kalkulasi honor guru berbasis JP jurnal KBM, tunjangan wali kelas, & slip gaji dinas. |
| | Bimbingan Konseling (BK)| `dashboard/bk/counseling.php` | Manajemen kedisiplinan berbasis poin, rekam prestasi, & surat resmi pemanggilan orang tua. |
| | Layanan Persuratan | `dashboard/surat/requests.php` | Pengesahan surat keterangan aktif, izin sakit medis, rekomendasi, & penerbitan SPT GTK dinas luar. |
| | Legalisir Digital Alumni| `dashboard/alumni/legalisir.php` | Verifikasi legalisir ijazah online, pengesahan stempel dinas digital, & cetak lembar validasi. |
| | Tracer Study Alumni | `dashboard/alumni/tracer_study.php` | Pelacakan jejak karir alumni (BMW) & ekspor data instrumen akreditasi sekolah (BAN-SM). |
| | Agenda Surat Masuk/Keluar| `dashboard/surat/archives.php` | Registrasi nomor agenda AG-IN/AG-OUT, upload scan arsip dinas, & lembar disposisi digital KS. |
| | Perpustakaan Digital | `dashboard/perpustakaan/books.php`| Katalog OPAC, auto-fill ISBN Google Books API, sirkulasi barcode scanner, & bebas pustaka. |
| | Inventaris Sarpras | `dashboard/sarpras/inventory.php` | Buku inventaris aset ruangan, kondisi fisik (baik/rusak), sumber dana BOS, & sirkulasi pinjam. |
| **Administrasi Sistem**| PPDB Online | `dashboard/admin/ppdb.php` | Seleksi 4 jalur pendaftaran, skoring otomatis, & 1-klik aktivasi akun siswa + ortu + tagihan SPP. |
| | Pesan Tamu / Pengaduan | `dashboard/admin/contact_messages.php` | Tiket inbox bantuan publik (`TKT-YYYYMMDD-XXXX`), delegasi penanganan, & internal audit. |
| | Live Chat Masuk (AI) | `dashboard/admin/live_chat.php` | Pemantauan bot AI real-time, pengambilalihan obrolan (*human takeover*), & quick reply templates. |
| | Manajemen Kelas | `dashboard/admin/classes.php` | Tata kelola rombongan belajar (rombel), tingkat kelas X/XI/XII, & penetapan wali kelas. |
| | Kenaikan & Kelulusan | `dashboard/admin/promotion.php` | Wizard kenaikan kelas massal rollover tahun ajaran, tinggal kelas, kelulusan ke tabel alumni. |
| | Manajemen Pengguna | `dashboard/admin/users.php` | CRUD 5 role, NIP/NUPTK, gelar akademik, upload/reset TTE tanda tangan guru, radar Profil 360°. |
| | WhatsApp Gateway | `dashboard/admin/wa_gateway.php` | Broadcast pesan massal WhatsApp, pemantauan status gateway, & log pengiriman notifikasi. |
| | Kelola API Luar | `dashboard/admin/external_apis.php`| Integrasi Payment Gateway (Midtrans/Xendit/Tripay), Webhook, SMTP Mail, Maps, & RFID Presensi. |
| | Backup & Pemulihan | `dashboard/admin/backup.php` | Pencadangan berkas skema & data SQL serta prosedur pemulihan darurat pangkalan data. |
| | Pengaturan & Audit Log | `dashboard/admin/settings.php` | Konfigurasi profil sekolah, TTE/Stempel resmi KS, kuota PPDB, & inspeksi audit trail sistem. |
| **Akun Saya** | Profil Pengguna | `dashboard/profile.php` | Pembaruan data pribadi, pasfoto profil, tanda tangan digital tersanitasi, & pembaruan password. |

---

## 3. Prosedur Operasional Standar (SOP) Administrator

### A. Manajemen Pengguna & Kredensial Pendidik (`users.php`)
1. **Penambahan Pengguna Baru:**
   - Masuk ke menu `Administrasi & Sistem` → `Kelola Pengguna`.
   - Klik tombol **"Tambah Pengguna"**.
   - Masukkan Nama Lengkap, Alamat Email resmi, Password awal, dan tentukan Role (`administrator`, `staf`, `guru`, `orang_tua`, atau `siswa`).
   - Apabila memilih role **Guru**, admin dapat langsung menetapkan:
     - Nomor Induk Pegawai (NIP / NUPTK).
     - Penulisan Gelar Akademik resmi (misal: `S.Pd., M.Kom.`) yang akan digabungkan secara otomatis pada seluruh dokumen cetak resmi.
   - Apabila memilih role **Siswa**, sistem menampilkan kolom tambahan: Kelas/Rombel, NISN, Jenis Kelamin, Nomor Telepon, dan Alamat Domisili.
2. **Manajemen Berkas Tanda Tangan Elektronik (TTE) Guru:**
   - Pada baris guru terkait, klik modal **Edit Pengguna**.
   - Tinjau pratinjau scan tanda tangan digital guru yang tersimpan di sistem.
   - Administrator memiliki 2 opsi perbaikan:
     - **Unggah Berkas Scan TTE Baru:** Unggah file tanda tangan basah hasil scan (format PNG transparan/JPG/SVG) yang akan langsung melewati mesin validasi `handleSecureUpload()` dan pembersihan muatan XML SVG dari serangan XSS.
     - **Reset ke Generator Tanda Tangan SVG Resmi:** Jika guru belum memiliki berkas scan, klik tombol **"Reset ke Generator SVG"**. Sistem secara instan meregenerasi tanda tangan vektor dinas resmi berbasis kaligrafi nama digital guru yang tersimpan aman.
3. **Impor Siswa Massal via Spreadsheet (CSV):**
   - Unduh template standar melalui tombol **"Download Template CSV"**.
   - Isi spreadsheet dengan struktur: `nama`, `email`, `password`, `kelas`, `nisn`, `gender (L/P)`, `telepon`, `alamat`.
   - Unggah berkas pada modal **"Impor Massal Siswa (CSV)"**.
   - Sistem melakukan parsing otomatis, memvalidasi duplikasi email/NISN, mengaitkan ID kelas yang cocok, dan menyuntikkan data secara instan.
4. **Inspeksi Radar Profil Terpadu Siswa 360 Derajat (`student_360.php`):**
   - Pada daftar pengguna siswa, klik ikon **"Radar 360°"**.
   - Layar analitik holistik memotret: biodata Dapodik, grafik radar pencapaian nilai kompetensi antar-mapel, rasio kehadiran, akumulasi poin pelanggaran dan prestasi BK, status riwayat tagihan SPP, serta keaktifan ekstrakurikuler.

---

### B. Pusat Layanan AI Live Chat & Human Takeover (`live_chat.php`)
1. **Arsitektur Asisten Virtual AI Mandiri:**
   - Mesin percakapan AI mandiri (`api/live_chat.php`) bekerja secara *in-house* tanpa dependensi API berbayar luar, didukung basis pengetahuan otomatis multi-topik (Prosedur PPDB, Kalender Akademik, Biaya SPP & Rekening, Presensi, E-Perpus, BK, dan Ekstrakurikuler).
2. **Pemantauan Obrolan Tamu Real-Time:**
   - Buka menu `Live Chat Masuk` (`dashboard/admin/live_chat.php`).
   - Saring antrean berdasarkan status: `Aktif` (sedang berlangsung), `Diekskalasi ke Staf` (pengunjung meminta bantuan personil), atau `Selesai (Resolved)`.
3. **Pengambilalihan Obrolan (*Human Takeover*):**
   - Klik sesi percakapan yang masuk.
   - Klik tombol **"Ambil Alih Obrolan (Human Takeover)"**.
   - Sistem menonaktifkan balasan otomatis bot AI pada sesi tersebut dan mengizinkan staf/admin mengetik balasan langsung kepada pengunjung.
   - Manfaatkan **Quick Reply Templates** untuk mengirimkan jawaban cepat terhadap pertanyaan umum (misal: rekening sekolah, jam operasional TU, tautan unduh brosur).
   - Setelah permasalahan tuntas, klik tombol **"Tandai Selesai (Resolve Session)"** untuk mengarsipkan riwayat obrolan.

---

### C. Pusat Keamanan Unggahan & Konfigurasi Berkas Kedinasan
1. **Spesifikasi Engine Upload Skala Enterprise (`upload_helper.php`):**
   - Sistem menerapkan mesin validasi terpusat di seluruh 23 titik unggah aplikasi:
     - Verifikasi tipe MIME nyata melalui *magic bytes* via `finfo_file()` dan `exif_imagetype()`.
     - Pengacakan nama berkas kriptografis 32 karakter hex SHA-256 (`bin2hex(random_bytes(16))`) guna mengeliminasi serangan path traversal dan enumerasi.
     - Sanitasi mutlak berkas vektor SVG dari skrip JavaScript/Stored XSS.
     - Folder penyimpanan `uploads/` dilindungi file `.htaccess` khusus dengan mematikan mesin eksekusi PHP (`php_flag engine off`).
2. **Konfigurasi Stempel Institusi, TTE KS & Kop Kedinasan (`settings.php`):**
   - Masuk ke menu `Pengaturan Sistem`.
   - Unggah **Logo Resmi Sekolah** dan **Favicon**.
   - Unggah **Scan Tanda Tangan Digital Kepala Sekolah** (PNG transparan) dan **Scan Stempel Basah Institusi** (PNG transparan).
   - Masukkan Identitas Dinas Pendidikan, Alamat Lengkap, Kontak Resmi, dan NIP Kepala Sekolah.
   - **Mekanisme Render Legalitas (`renderOfficialSignatureHtml`):**
     - Efek percampuran tinta basah alami (`mix-blend-mode: multiply`) antara stempel dan tanda tangan pimpinan di atas dokumen cetak.
     - Proteksi visual anti-scraping (`pointer-events: none; -webkit-user-drag: none; user-select: none;`) mencegah pencurian aset legalitas oleh pihak tidak bertanggung jawab.
     - Penerapan CSS `@media print` (`print-color-adjust: exact`) untuk mempertahankan ketajaman warna cetak asli pada 21 berkas resmi kedinasan.
     - **Generator QR Code Vektor Offline Fallback:** Apabila server diinstal pada jaringan tertutup (*air-gapped intranet*), sistem otomatis merender QR Code verifikasi dokumen langsung berbasis kode vektor SVG mandiri tanpa error gambar rusak (*broken image*).

---

### D. Wizard Kenaikan Kelas & Kelulusan Massal (`promotion.php`)
1. Buka menu `Kenaikan & Kelulusan` (`dashboard/admin/promotion.php`).
2. **Langkah 1 — Pemilihan Rombel Asal:** Pilih tahun ajaran aktif dan kelas rombel yang akan diproses (misal: *Kelas X MIPA 1*).
3. **Langkah 2 — Penetapan Status Peserta Didik:**
   - Sistem memuat seluruh siswa dalam kelas tersebut beserta ringkasan absensi dan nilai.
   - Tentukan status perorangan secara fleksibel:
     - **Naik Kelas (Default):** Pilih rombel kelas tujuan pada tingkat berikutnya (misal: *XI MIPA 1*).
     - **Tinggal Kelas:** Siswa tetap berada pada rombel tingkat yang sama untuk tahun ajaran berikutnya disertai catatan alasan akademik.
4. **Langkah 3 — Pemrosesan Kelulusan Siswa Tingkat Akhir (Kelas XII / IX):**
   - Pilih opsi **Lulus**.
   - Sistem secara otomatis:
     - Mengalihkan status akun siswa menjadi non-aktif dari rombel aktif.
     - Mengarsipkan data identitas dan transkrip kelulusan ke pangkalan data alumni (`alumni_records`).
     - Membuka akses alumni untuk Tracer Study dan pengajuan Legalisir Ijazah Digital.
5. **Langkah 4 — Eksekusi Rollover:**
   - Klik **"Eksekusi Kenaikan / Kelulusan Massal"**.
   - Seluruh mutasi terekam akuntabel ke tabel log histori audit `academic_promotion_logs`.

---

### E. Integrasi Multi-API Gateway & Webhook Terpadu (`external_apis.php`)
1. **WhatsApp Gateway:** Konfigurasi provider gateway (Fonnte, dsb.), token otentikasi, nomor pengirim dinas, dan template broadcast otomatis.
2. **Payment Gateway & Webhook Pembayaran Otomatis:**
   - Konfigurasi kanal pembayaran Midtrans / Xendit / Tripay (Merchant ID, Client Key, Server Key / Secret Key).
   - Sistem menyediakan endpoint webhook aman `api/payment_webhook.php` dengan verifikasi signature HMAC (`hash_equals`) untuk otomatisasi perubahan status tagihan SPP dari *Menunggu* menjadi *Lunas* secara real-time.
3. **SMTP Mail Gateway:** Konfigurasi server SMTP surat elektronik dinas untuk pengiriman tautan token pemulihan kata sandi (*forgot password*).
4. **REST API Presensi RFID Perangkat Keras Gerbang:**
   - Konfigurasi `api/rfid_attendance.php` dengan kunci rahasia dinamis (*dynamic secret token*) dan kebijakan CORS ketat untuk menghubungkan mikrokontroler perangkat keras (ESP32 / NodeMCU / Arduino RFID Scanner) di gerbang masuk sekolah.

---

### F. Pengawasan Keamanan & Pemeliharaan Database (`backup.php` & `audit_logs.php`)
1. **Audit Trail Forensics (`audit_logs.php`):**
   - Pantau secara berkala riwayat login seluruh pengguna, perubahan nilai siswa oleh guru, mutasi tagihan keuangan, dan aktivitas penghapusan berkas.
   - Evaluasi alamat IP dan stempel waktu (*timestamp*) untuk mendeteksi anomali akses.
2. **Pencadangan Pangkalan Data Rutin (Backup SQL):**
   - Buka menu `Backup Database`.
   - Klik tombol **"Generate Database Backup (.sql)"**.
   - Berkas cadangan terenkripsi token acak aman siap diunduh ke media penyimpanan fisik terpisah (*cold storage*).
   - Prosedur **Restore** hanya dilakukan dalam situasi darurat (*disaster recovery*) dengan memilih file cadangan SQL terverifikasi.

---

## 4. Matriks Hak Akses & Pembagian Wewenang Administrator

```
Administrator (Super Admin)
│
├── Kedaulatan Tata Kelola Pengguna (Admin, Staf, Guru, Ortu, Siswa)
│   ├── Peninjauan NIP & Gelar Akademik Guru
│   ├── Verifikasi, Unggah & Reset TTE Scan / SVG Guru
│   └── Radar Analitik Profil Siswa 360 Derajat
│
├── Konfigurasi Kelembagaan & Infrastruktur
│   ├── Kop Surat, Logo & Stempel Basah Institusi
│   ├── Tanda Tangan Elektronik (TTE) Kepala Sekolah
│   └── Offline QR Code Vector Engine (Air-Gapped Ready)
│
├── Manajemen Integrasi Multi-API & Webhook
│   ├── WhatsApp Gateway Broadcast
│   ├── Payment Gateway (Midtrans, Xendit, Tripay) & HMAC Webhook Listener
│   └── REST API Gateway Mesin RFID Presensi Fisik
│
├── Kontrol Operasional Massal & Arsip
│   ├── Wizard Kenaikan Kelas & Kelulusan Massal (Rollover)
│   ├── Seleksi PPDB 4 Jalur & 1-Klik Aktivasi Akun
│   └── Portal AI Live Chat & Human Takeover Real-Time
│
└── Keamanan Siber & Pemeliharaan Database
    ├── Centralized Upload Engine (23 Titik Validasi MIME & SHA-256)
    ├── Backup & Restore Pangkalan Data SQL
    └── Audit Trail Log Forensics
```

---

## 5. Rekomendasi Praktik Keamanan Administrator
- **Pembaruan Kredensial Berkala:** Segera ganti kata sandi bawaan sistem setelah instalasi awal dengan kombinasi minimal 12 karakter alfanumerik dan simbol khusus.
- **Prinsip Hak Akses Terkecil (*Least Privilege*):** Jangan gunakan akun Administrator untuk tugas operasional harian (seperti input surat masuk atau sirkulasi buku perpus); delegasikan tugas tersebut kepada akun dengan peran **Staf**.
- **Audit Rutin Berkas Unggahan:** Periksa direktori `uploads/` secara berkala dan pastikan berkas konfigurasi `uploads/.htaccess` tetap aktif dengan direktif `php_flag engine off`.
- **Pengamanan Koneksi Eksternal:** Selalu gunakan protokol HTTPS dengan sertifikat SSL/TLS valid agar transmisi webhook pembayaran dan kunci API tetap terenkripsi dari serangan *Man-in-the-Middle* (MitM).
- **Terminasi Sesi Aman:** Selalu klik tombol **Keluar (*Logout*)** setelah menyelesaikan sesi administrasi, terutama jika menggunakan perangkat kerja bersama.
