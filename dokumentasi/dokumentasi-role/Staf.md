# 🟠 Panduan Operasional Peran: Staf (Tata Usaha & Administrasi)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP**

---

## 1. Ringkasan Peran & Ruang Lingkup Kerja

Peran **Staf** (*Tata Usaha & Administrasi Sekolah*) bertindak sebagai motor penggerak operasional administratif harian di lingkungan sekolah. Staf memiliki wewenang mengelola kesiswaan tingkat operasional, administrasi persuratan kedinasan, pengelolaan Buku Kas Umum (BKU) dan pembayaran SPP, inventaris sarana prasarana sekolah, sirkulasi perpustakaan digital, serta pelayanan pengaduan tamu publik.

### 🛡️ Batasan Wewenang (RBAC Proteksi)
- **Kesiswaan:** Staf berwenang mengelola data siswa dan orang tua (termasuk impor massal CSV dan cetak lembar buku induk), namun secara otomatis diproteksi sistem dari memodifikasi akun sesama staf, guru, atau administrator.
- **Konfigurasi Sistem:** Staf tidak dapat mengakses audit log sistem atau pencadangan/pemulihan database (*backup & restore*).

---

## 2. Navigasi & Menu Operasional Staf TU

| Bidang Kerja | Modul / Halaman | File Eksekusi | Deskripsi Tugas Operasional |
| :--- | :--- | :--- | :--- |
| **Kesiswaan** | Administrasi Siswa & Ortu | `dashboard/admin/users.php` | Pengelolaan data murid & wali murid, reset sandi, impor massal CSV, & cetak buku induk. |
| | Verifikasi Dokumen PPDB | `dashboard/admin/ppdb.php` | Pemeriksaan berkas pendaftaran, verifikasi nilai & sertifikat, penandaan status dokumen. |
| | Cetak Lembar Buku Induk | `dashboard/admin/student_profile_print.php` | Cetak lembar buku induk siswa resmi standar Kemendikbud format A–D. |
| **Layanan Tamu** | Kotak Masuk Pesan Publik | `dashboard/admin/contact_messages.php` | Menjawab tiket aduan / pertanyaan masyarakat dari formulir kontak web sekolah. |
| **Persuratan** | Permohonan Surat Siswa | `dashboard/surat/requests.php` | Verifikasi permohonan surat keterangan aktif, izin sakit, beasiswa, & cetak surat resmi. |
| | Cetak Surat & SPT Dinas | `dashboard/surat/request_print.php` | Penerbitan Surat Perintah Tugas (SPT GTK) dinas luar/pelatihan, SKBB, & SKL sementara. |
| | Buku Agenda Surat & Disposisi | `dashboard/surat/archives.php` | Catat agenda surat masuk/keluar resmi (`AG-IN`/`AG-OUT`), upload arsip scan, & instruksi disposisi KS. |
| **Keuangan** | Kasir SPP & Pembayaran | `dashboard/keuangan/payments.php` | Cek mutasi pembayaran siswa, verifikasi bukti transfer, cetak kwitansi sah (`receipt.php`). |
| | Buku Kas Umum (BKU) | `dashboard/keuangan/expenses.php` | Catat Bukti Kas Keluar operasional, upload nota kwitansi, pantau saldo kas riil sekolah. |
| **Sarpras** | Inventaris Ruangan | `dashboard/sarpras/inventory.php` | Catat aset inventaris sekolah, kondisi layak pakai/rusak, sumber pendanaan (BOS/Yayasan). |
| | Sirkulasi Pinjam Sarana | `dashboard/sarpras/inventory.php` | Peminjaman fasilitas sekolah (ruangan/alat) oleh guru/siswa, tracking jatuh tempo, rekap inventaris. |
| **Perpustakaan**| Sirkulasi & Pengadaan Buku | `dashboard/perpustakaan/` | Input buku (Auto-Fill ISBN), sirkulasi pinjam-kembali, denda, booking mandiri, scan QR, buku tamu. |
| | Bebas Pustaka Digital | `dashboard/perpustakaan/clearance.php` | Verifikasi tanggungan buku dan penerbitan Surat Bebas Perpustakaan siswa tingkat akhir. |
| **Publikasi** | Pengumuman Sekolah | `dashboard/informasi/announcements.php` | Buat edaran dinas/sekolah, lampiran dokumen PDF, target sasaran per role, & pantau pembaca. |

---

## 3. Panduan Operasional Prosedural Staf Tata Usaha

### A. Layanan Persuratan, Disposisi & Surat Perintah Tugas (SPT)

#### 1. Memproses Permohonan Surat Siswa & Wali Murid (`requests.php`)
- Buka menu `Layanan Surat` → `Permohonan Surat`.
- Tinjau daftar antrean permohonan yang berstatus **Menunggu**.
- Periksa rincian: Siswa pemohon, jenis surat (Surat Keterangan Aktif, Surat Keterangan Berkelakuan Baik / SKBB, SKL Sementara, Rekomendasi Beasiswa, Surat Keterangan Izin Sakit).
- Jika ada lampiran surat dokter (pada permohonan izin sakit), klik tautan berkas lampiran untuk memeriksa keabsahan dokumen medis.
- Klik **"Setujui"** atau **"Tolak"** (disertai alasan penolakan).
- **Sinkronisasi Presensi Otomatis:** Apabila staf TU menyetujui surat izin sakit atau izin dispensasi kegiatan, sistem secara otomatis memperbarui status absensi siswa yang bersangkutan pada tabel `student_attendance` menjadi **Sakit** atau **Izin** pada tanggal terkait tanpa perlu input manual lagi!

#### 2. Menerbitkan Surat Resmi & Surat Perintah Tugas (SPT Guru/Pegawai)
- Klik tombol **"Terbitkan Surat Baru"**.
- Pilih Klasifikasi Surat:
  - **Surat Keterangan Siswa** (Aktif / SKBB / Beasiswa).
  - **Surat Perintah Tugas (SPT / SPPD Pendidik & Tenaga Kependidikan)** — Ditujukan kepada guru atau staf untuk tugas kedinasan luar, rapat koordinasi dinas, pelatihan kurikulum / MGMP, atau pengawasan ujian.
- Isi parameter resmi: Nomor surat dinas, dasar penugasan, nama pejabat pemberi tugas (Kepala Sekolah), daftar nama pegawai yang ditugaskan, tanggal pelaksanaan, tempat tujuan, dan beban anggaran.
- Klik **"Simpan & Terbitkan"**.
- Buka berkas cetak via [`request_print.php`](file:///c:/Users/Hype%20GLK/Downloads/projek/manajemen-php/dashboard/surat/request_print.php). Berkas surat langsung siap dicetak dengan:
  - Kop surat resmi instansi dan logo sekolah.
  - Penomoran kode klasifikasi kearsipan standar (`800/SPT-GTK/2026`, dsb.).
  - Tanda tangan digital Kepala Sekolah dan simulasi cap stempel basah terakreditasi.
  - Kode QR Verifikasi Keaslian berkas ber-hash unik untuk pencegahan pemalsuan dokumen dinas.

#### 3. Pencatatan Buku Agenda Surat Masuk & Surat Keluar (`archives.php`)
- **Pencatatan Surat Masuk:**
  - Pilih tab **"Surat Masuk"** → Klik **"Catat Surat Masuk"**.
  - Sistem mengalokasikan nomor agenda otomatis: `AG-IN/YYYY/MM/NNN`.
  - Masukkan nomor surat pengirim, instansi asal (Dinas Pendidikan, Kementerian, Yayasan, Puskesmas, dll.), tanggal surat, tanggal diterima, dan perihal.
  - Unggah scan berkas fisik (PDF / JPG).
  - **Lembar Disposisi Kepala Sekolah:** Input arahan/instruksi Kepala Sekolah (misal: *Harap ditindaklanjuti bidang kurikulum sebelum tanggal 30*), pilih staf/guru penerima disposisi, dan tetapkan batas waktu.
- **Pencatatan Surat Keluar:**
  - Pilih tab **"Surat Keluar"** → Catat nomor urut agenda: `AG-OUT/YYYY/MM/NNN`.
  - Masukkan instansi tujuan, perihal surat edaran, tanggal kirim, dan unggah tembusan arsip scan.

---

### B. Pengelolaan Keuangan, SPP & Buku Kas Umum (BKU)

#### 1. Kasir SPP & Verifikasi Pembayaran Siswa (`payments.php`)
- Pantau daftar tagihan siswa dengan filter kelas dan status (`Belum Lunas`, `Menunggu Verifikasi`, `Lunas`).
- Saat orang tua mengunggah bukti transfer bank atau QRIS, status tagihan berubah menjadi **Menunggu Verifikasi**.
- Klik tombol **"Periksa Bukti"** untuk mencocokkan mutasi rekening dengan foto bukti struk transfer.
- Jika pembayaran valid, klik **"Konfirmasi Lunas"**.
- Klik ikon **"Cetak Kwitansi"** (`receipt.php`) untuk mencetak bukti tanda terima pembayaran sah bernomor seri resmi berstempel lunas.

#### 2. Pencatatan Buku Kas Umum (BKU) Kas Keluar (`expenses.php`)
- Masuk ke menu `Keuangan` → `Kas Pengeluaran (BKU)`.
- Klik tombol **"Catat Pengeluaran Baru"**.
- Sistem menghasilkan nomor Bukti Kas Keluar otomatis (`EXP-YYYY/MM/NNN`).
- Pilih Kategori Anggaran:
  - *ATK & Operasional Kantor*
  - *Listrik, Air & Internet Gedung*
  - *Konsumsi & Rapat Dinas*
  - *Pemeliharaan Gedung & Kebersihan*
  - *Kegiatan Kesiswaan & Lomba*
  - *Honorarium / Transport Tugas Kedinasan*
- Masukkan tanggal transaksi kas keluar, nominal pengeluaran (Rp), identitas pihak penerima (toko/vendor/rekanan), dan rincian peruntukan belanja.
- Unggah scan nota asli, kwitansi toko, atau invoice pembelian.
- Klik **"Simpan Transaksi Kas"**.
- **Monitoring Arus Kas Riil:** Sistem secara *real-time* mengalkulasi neraca kas sekolah:
  $$\text{Saldo Kas Tersedia} = \text{Total Penerimaan Kas SPP} - \text{Total Pengeluaran Kas BKU}$$
- Cetak Rekapitulasi Laporan BKU bulanan/tahunan untuk kebutuhan pelaporan SPJ dan audit inspektorat/yayasan.

---

### C. Manajemen Inventaris Sarana & Prasarana (Sarpras) (`inventory.php`)

#### 1. Registrasi & Inventarisasi Aset Ruangan
- Masuk ke menu `Sarpras` → `Buku Inventaris`.
- Klik **"Tambah Barang Inventaris"**.
- Masukkan Kode Barang (atau biarkan otomatis), Nama Barang (contoh: *Proyektor InFocus Epson EB-X400*), Kategori (Elektronik, Perabot, Alat Laboratorium, Olahraga), Ruangan Penempatan (Lab Komputer 1, Ruang Guru, Ruang TU, Aula, dll.).
- Tentukan Jumlah Unit dan Kondisi Fisik:
  - **Baik:** Layak digunakan secara optimal.
  - **Rusak Ringan:** Masih dapat digunakan dengan catatan / membutuhkan servis berkala.
  - **Rusak Berat:** Tidak berfungsi / diusulkan penghapusan aset.
- Masukkan Sumber Pendanaan (BOS Reguler, BOS Kinerja, Komite/Yayasan, Bantuan Hibah) dan Nilai Perolehan Aset (Rp).

#### 2. Sirkulasi Peminjaman Peralatan / Fasilitas Sekolah
- Pilih tab **"Sirkulasi Peminjaman"** → Klik **"Catat Pinjaman Baru"**.
- Pilih barang/alat yang hendak dipinjam, nama peminjam (guru, perwakilan OSIS, ekstrakurikuler, atau pihak tamu), tanggal pinjam, dan batas waktu pengembalian (*due date*).
- Sistem memberikan penanda visual (*badge warning*) apabila peminjaman telah melewati batas waktu pengembalian (*overdue*).
- Saat barang dikembalikan, staf mengonfirmasi tombol **"Proses Pengembalian"** dan memverifikasi kondisi fisik saat kembali untuk memastikan tidak ada kerusakan fasilitas.

---

### D. Tata Kelola Sirkulasi Perpustakaan Digital (`perpustakaan/`)

1. **Pengadaan Buku Baru dengan Auto-Fill ISBN (`books.php`):**
   - Saat mendaftarkan buku koleksi baru, cukup ketikkan 10 atau 13 digit nomor **ISBN** buku lalu klik tombol **"Auto-Fill via API"**.
   - Sistem secara otomatis menghubungi Google Books API dan Open Library API untuk mengisi Judul Buku, Pengarang, Penerbit, Tahun Terbit, Sinopsis, dan Rekomendasi Kategori secara instan tanpa perlu mengetik manual.
2. **Sirkulasi Pinjam-Kembali & Denda Keterlambatan (`loans.php`):**
   - Gunakan fitur **Quick Scan Webcam** (`scan.php`) untuk memindai kartu barcode anggota dan buku secara cepat di meja sirkulasi.
   - Peminjaman yang melewati batas waktu secara otomatis dikalkulasikan denda keterlambatan hariannya oleh sistem.
3. **Penyelesaian Antrean Booking Buku Mandiri (`reservations.php`):**
   - Periksa daftar buku yang di-booking oleh siswa/guru dari katalog OPAC publik.
   - Siapkan fisik buku di meja sirkulasi; saat siswa mengambil fisik buku, staf mengklik **"Proses Menjadi Pinjaman Aktif"**.
4. **Cetak Label Barcode & Nomor Panggil (`print_labels.php`):**
   - Cetak stiker label nomor panggil buku (*call number* / DDC) dan kode barcode inventaris siap tempel pada punggung buku.
5. **Penerbitan Surat Bebas Perpustakaan (`clearance.php`):**
   - Pilih nama siswa tingkat akhir. Sistem memvalidasi apakah siswa masih memiliki pinjaman buku aktif atau tunggakan denda.
   - Apabila tanggungan bersih (0 pinjaman aktif & 0 denda), staf dapat langsung mencetak lembar resmi **Surat Keterangan Bebas Perpustakaan** sebagai syarat kelulusan atau pengambilan ijazah.

---

## 4. Alur Kerja Harian Staf Tata Usaha

```
Pagi:
├── Buka Kotak Masuk Pesan Tamu (contact_messages.php) & tindak lanjuti aduan baru
├── Tinjau permohonan surat masuk dari siswa/ortu (requests.php)
└── Verifikasi pembayaran SPP yang diunggah wali murid (payments.php)

Siang:
├── Catat surat dinas masuk/keluar & proses lembar disposisi Kepala Sekolah (archives.php)
├── Layani sirkulasi peminjaman sarpras & buku perpustakaan
└── Catat transaksi pengeluaran operasional sekolah pada Buku Kas Umum (expenses.php)

Sore:
├── Cetak rekap presensi / laporan penerimaan kas harian
└── Periksa pengembalian barang sarpras & jatuh tempo pinjaman
```
