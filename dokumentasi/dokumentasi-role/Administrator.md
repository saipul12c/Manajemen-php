# 🔴 Panduan Operasional Peran: Administrator (Super Admin)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP**

---

## 1. Ringkasan Peran & Tanggung Jawab

Peran **Administrator** (*Super Admin*) adalah pemegang otoritas tertinggi dalam ekosistem **Manajemen-PHP**. Administrator bertanggung jawab atas tata kelola sistem, manajemen identitas dan hak akses civitas sekolah, konfigurasi parameter kelembagaan, integritas data operasional, audit keamanan, serta pemeliharaan database sekolah.

### 🛡️ Karakteristik Otoritas
- **Tingkat Akses:** *Full Access* (Semua Modul & Konfigurasi Sistem)
- **Akses Langsung:** Mengelola seluruh data pengguna lintas peran (*administrator*, *staf*, *guru*, *orang_tua*, *siswa*).
- **Proteksi Data:** Memegang wewenang tunggal untuk ekspor/impor cadangan database (*backup & restore*) dan evaluasi jejak aktivitas (*audit trail*).

---

## 2. Navigasi & Struktur Menu Administrator

| Menu Utama | Sub-Menu | File Eksekusi | Deskripsi Tugas |
| :--- | :--- | :--- | :--- |
| **Dashboard** | Ikhtisar Eksekutif | `dashboard/index.php` | Monitoring metrik statistik sistem, arus kas kasir, PPDB masuk, & feed aktivitas terbaru. |
| **Administrasi** | Manajemen Pengguna | `dashboard/admin/users.php` | CRUD pengguna 5 role, reset kredensial, impor massal CSV, & cetak lembar buku induk siswa. |
| | Pesan Tamu / Pengaduan | `dashboard/admin/contact_messages.php` | Inbox tiket bantuan dari halaman kontak publik, penanganan keluhan, & internal notes. |
| | Rombel & Kelas | `dashboard/admin/classes.php` | Kelola rombongan belajar (rombel), tingkat kelas (X/XI/XII), & penunjukan wali kelas. |
| | Seleksi & Verifikasi PPDB | `dashboard/admin/ppdb.php` | Verifikasi berkas 4 jalur PPDB, skoring seleksi, kelulusan, & aktivasi 1-klik akun siswa. |
| | Pengaturan Sekolah | `dashboard/admin/settings.php` | Konfigurasi profil sekolah, kurikulum, logo, kepala sekolah, serta buka/tutup kuota PPDB. |
| | Audit Log Sistem | `dashboard/admin/audit_logs.php` | Pelacakan jejak rekam aktivitas penting sistem beserta stempel waktu dan IP address. |
| | Backup & Restore | `dashboard/admin/backup.php` | Pencadangan berkas skema & data SQL serta pemulihan darurat sistem database. |
| **Keuangan** | Kasir SPP & Tagihan | `dashboard/keuangan/payments.php` | Penetapan tarif tagihan siswa, verifikasi bukti transfer, & penerbitan kwitansi sah. |
| | Kas Pengeluaran (BKU) | `dashboard/keuangan/expenses.php` | Pencatatan Bukti Kas Keluar operasional, upload nota kwitansi, & monitoring arus kas riil. |
| **Sarpras** | Inventaris & Sirkulasi | `dashboard/sarpras/inventory.php` | Buku inventaris sarana per ruangan, kondisi barang, sumber dana, & sirkulasi peminjaman. |
| **Perpustakaan** | Pusat Sirkulasi OPAC | `dashboard/perpustakaan/` | Manajemen buku, reservasi mandiri, barcode scanner, buku tamu, & surat bebas pustaka. |
| **Persuratan** | Persuratan & Disposisi | `dashboard/surat/` | Pengesahan permohonan surat siswa/guru, SPT dinas GTK, agenda surat masuk & disposisi KS. |

---

## 3. Prosedur Operasional Standar (SOP) Administrator

### A. Manajemen Pengguna & Kesiswaan Terpadu (`users.php`)
1. **Penambahan Pengguna Baru:**
   - Navigasi ke `Panel Admin` → `Kelola Pengguna`.
   - Klik tombol **"Tambah Pengguna"**.
   - Masukkan Nama Lengkap, Alamat Email resmi, Password awal, dan tentukan Role.
   - Apabila memilih role **Siswa**, sistem menampilkan kolom tambahan: Kelas/Rombel, NISN, Jenis Kelamin, Nomor Telepon, dan Alamat Domisili.
   - Klik **"Simpan Pengguna"**.
2. **Impor Siswa Massal via Excel / CSV:**
   - Unduh template standar melalui tombol **"Download Template CSV"**.
   - Isi berkas spreadsheet dengan kolom: `nama`, `email`, `password`, `kelas`, `nisn`, `gender (L/P)`, `telepon`, `alamat`.
   - Unggah berkas pada modal **"Impor Massal Siswa (CSV)"**.
   - Sistem melakukan *parsing* otomatis, memvalidasi duplikasi email/NISN, mengaitkan ID kelas yang cocok, dan menyuntikkan data ke database secara instan.
3. **Cetak Lembar Buku Induk Siswa (`student_profile_print.php`):**
   - Pada baris data siswa yang bersangkutan, klik ikon **"Cetak Buku Induk"**.
   - Sistem menyusun formulir standar Kemendikbud:
     - **Bagian A:** Identitas Pribadi Peserta Didik (Nama, NISN, NIK, Tempat/Tgl Lahir, Agama).
     - **Bagian B:** Keterangan Tempat Tinggal & Kontak.
     - **Bagian C:** Keterangan Pendidikan Sebelumnya (Asal SMP/MTs, Nomor Ijazah).
     - **Bagian D:** Keterangan Orang Tua Kandung / Wali (Nama Ayah/Ibu, Pekerjaan, Alamat).
     - Kotak Pas Foto resmi 3x4 cm dan lembar tanda tangan pengesahan Kepala Sekolah & Tenaga Administrasi.

### B. Konfigurasi Kelembagaan & Pengaturan PPDB (`settings.php`)
1. **Identitas Sekolah:**
   - Perbarui Nama Sekolah, NPSN, Alamat Gedung, Kontak Telepon, Email Lembaga, dan Situs Web.
   - Perbarui Nama Kepala Sekolah dan NIP (digunakan otomatis pada seluruh kop surat dinas, rapor digital, dan kwitansi).
   - Atur Tahun Pelajaran & Semester aktif (misal: `2026/2027 Ganjil`).
   - Unggah Logo Lembaga (format PNG/JPG transparan) untuk kop cetak resmi.
2. **Parameter Gelombang & Daya Tampung PPDB:**
   - Atur **Status PPDB** (`Buka` atau `Tutup`).
   - Tentukan Nama Gelombang aktif (contoh: *Gelombang 1 - Jalur Prestasi & Zonasi*).
   - Tentukan Batas Kuota Daya Tampung (contoh: `150` calon siswa).
   - Tentukan Rentang Tanggal Mulai dan Selesai pendaftaran.
   - Buat Pesan Informasi Penutupan Kustom (muncul otomatis di landing page publik ketika PPDB dinonaktifkan atau kuota terpenuhi).

### C. Verifikasi & Otomatisasi Penerimaan PPDB (`admin/ppdb.php`)
1. **Pemeriksaan Dokumen & Skoring 4 Jalur:**
   - Buka tab pendaftar berdasarkan jalur: **Reguler**, **Zonasi Domisili**, **Prestasi**, atau **Afirmasi**.
   - Tinjau kalkulasi skor otomatis yang dihitung oleh sistem (`calculatePpdbScore`):
     - Rata-rata 4 Mapel Rapor (Matematika, IPA, B. Indo, B. Inggris).
     - Ditambah bobot prestasi bersertifikat (tingkat sekolah hingga internasional).
     - Atau skor prioritas radius kilometer jarak zonasi tempat tinggal.
   - Periksa lampiran berkas fisik (Scan Rapor, Kartu Keluarga, Akta Kelahiran, Pas Foto).
   - Tetapkan Status Dokumen: `Berkas Lengkap & Valid`, `Perlu Revisi`, atau `Ditolak` disertai catatan instruksi perbaikan.
2. **Otomatisasi 1-Klik Pembuatan Akun Siswa Diterima:**
   - Setelah proses seleksi tuntas, ubah status pendaftar menjadi **"Diterima"**.
   - Pilih Kelas/Rombel penempatan siswa baru (misal: *X MIPA 1*).
   - Klik **"Proses Penerimaan & Buat Akun"**.
   - **Mekanisme Otomatis Sistem:**
     1. Sistem membuat akun login siswa pada tabel `users` dengan NISN terdaftar.
     2. Sistem otomatis membuat akun wali murid (Orang Tua) dan merekatkan relasi di tabel `parent_students`.
     3. Sistem mengaitkan rombel kelas aktif siswa.
     4. Sistem otomatis menerbitkan tagihan keuangan awal (biaya pendaftaran/SPP bulan berjalan) pada tabel `student_bills`.

### D. Penanganan Tiket Pengaduan Publik (`contact_messages.php`)
1. Setiap pesan dari formulir `kontak.php` publik masuk dengan status **Baru** disertai nomor tiket (`TKT-YYYYMMDD-XXXX`).
2. Administrator meninjau rincian pengirim, kategori (Akademik, PPDB, Administrasi, Fasilitas), perihal, dan kronologi pesan.
3. Ubah status penanganan menjadi **Diproses** saat diteruskan ke staf/bidang terkait.
4. Tambahkan catatan internal (*follow-up notes*) sebagai rekam jejak penyelesaian masalah.
5. Setelah teratasi, tandai status tiket menjadi **Selesai**.

### E. Pengawasan Keamanan & Pemeliharaan Database (`backup.php` & `audit_logs.php`)
1. **Audit Trail Review:**
   - Buka `Audit Log` secara berkala untuk memantau upaya login, modifikasi nilai siswa, penerbitan surat tugas dinas, atau pencatatan pengeluaran kas.
   - Analisis IP Address dan stempel waktu jika terdeteksi aktivitas mencurigakan.
2. **Pencadangan Database Rutin (Backup):**
   - Klik tombol **"Generate Database Backup (.sql)"**.
   - Simpan berkas cadangan database ke media penyimpanan eksternal yang aman.
   - Lakukan prosedur **Restore** hanya dalam situasi pemulihan darurat (*disaster recovery*).

---

## 4. Matriks Hak Akses & Pembagian Wewenang Administrator

```
Administrator (Super Admin)
│
├── Pengelolaan Seluruh Akun (Admin, Staf, Guru, Orang Tua, Siswa)
├── Hak Istimewa Konfigurasi Sekolah & Sistem Inti
├── Hak Istimewa Akses Database Dump (Backup & Restore)
├── Hak Istimewa Evaluasi Log Aktivitas Keamanan (Audit Trail)
└── Supervisi Seluruh Modul (Akademik, Ujian, Keuangan, Sarpras, Persuratan, Perpus)
```

---

## 5. Rekomendasi Praktik Keamanan Administrator
- Wajib memperbarui password default bawaan sistem (`admin123`) segera setelah instalasi awal.
- Gunakan kombinasi kata sandi minimal 12 karakter alfanumerik beserta simbol unik.
- Jangan pernah membagikan sesi login atau akun Super Admin kepada pihak lain; buatkan akun dengan peran **Staf** untuk staf tata usaha atau operator harian.
- Selalu lakukan *Logout* setelah mengakhiri sesi manajemen sistem.
