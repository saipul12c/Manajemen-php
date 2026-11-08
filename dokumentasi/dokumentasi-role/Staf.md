# 🟠 Panduan Operasional Peran: Staf (Tata Usaha & Administrasi Sekolah)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP v1.3.0**

---

## 1. Ringkasan Peran & Ruang Lingkup Kerja

Peran **Staf** (*Tata Usaha & Administrasi Sekolah*) bertindak sebagai motor penggerak operasional administratif harian di lingkungan sekolah. Staf memiliki wewenang mengelola kesiswaan tingkat operasional, administrasi persuratan kedinasan dan disposisi Kepala Sekolah, pengelolaan kasir SPP dan Buku Kas Umum (BKU), modul penggajian pendidik (payroll), verifikasi legalisir digital ijazah alumni, inventaris sarana prasarana sekolah, sirkulasi perpustakaan digital, serta pelayanan tamu melalui tiket bantuan dan live chat publik.

### 🛡️ Batasan Wewenang & Kontrol Akses (RBAC)
- **Kesiswaan & Orang Tua:** Staf berwenang mengelola data siswa dan orang tua (termasuk impor massal CSV dan cetak lembar buku induk Dapodik), namun secara otomatis diproteksi sistem dari memodifikasi akun sesama staf, dewan guru, atau administrator.
- **Konfigurasi Inti:** Staf tidak dapat mengakses konfigurasi pangkalan data (*backup & restore SQL*) atau memodifikasi pengaturan sistem tingkat lanjut yang dikhususkan bagi Super Admin.
- **Pusat Layanan Tamu:** Staf memiliki akses penuh untuk menindaklanjuti pesan formulir kontak publik dan mengambil alih percakapan bot AI (*human takeover*) pada modul Live Chat.

---

## 2. Navigasi & Menu Operasional Staf TU

| Bidang Kerja | Modul / Halaman | File Eksekusi | Deskripsi Tugas Operasional Harian |
| :--- | :--- | :--- | :--- |
| **Menu Utama** | Beranda Dasbor | `dashboard/index.php` | Monitoring surat masuk menunggu, verifikasi bayar SPP, calon PPDB baru, & tiket tamu. |
| | Pasang Aplikasi (Hub) | `dashboard/apps_hub.php` | Panduan instalasi 3 aplikasi mobile terpadu (CBT, Presensi, E-Perpus). |
| | Kalender Akademik | `dashboard/akademik/calendar.php` | Penjadwalan agenda rapat dinas, upacara kedinasan, kegiatan TU, & hari libur. |
| | Pengumuman Sekolah | `dashboard/informasi/announcements.php` | Terbitkan edaran kedinasan, surat pemberitahuan wali murid, & lampiran PDF. |
| | Berita & Artikel | `dashboard/informasi/news.php` | Tinjau draft berita kegiatan sekolah dari guru, koreksi redaksional, & persetujuan tayang. |
| | Pesan & Konsultasi | `dashboard/pesan/messages.php` | Saluran komunikasi privat internal tata usaha dengan guru, siswa, dan orang tua. |
| **Kesiswaan & Rombel**| Administrasi Siswa & Ortu| `dashboard/admin/users.php` | Kelola akun siswa & wali murid, reset sandi, impor massal CSV, & cetak buku induk. |
| | Manajemen Kelas | `dashboard/admin/classes.php` | Pengaturan rombongan belajar (rombel), tingkat kelas, & penunjukan wali kelas. |
| | Kenaikan & Kelulusan | `dashboard/admin/promotion.php` | Wizard kenaikan kelas massal rollover tahun ajaran & mutasi alumni ke pangkalan data. |
| | Verifikasi PPDB Online | `dashboard/admin/ppdb.php` | Verifikasi berkas 4 jalur seleksi, scoring otomatis, & aktivasi 1-klik akun siswa diterima. |
| **Layanan Tamu** | Kotak Masuk Pesan Tamu | `dashboard/admin/contact_messages.php` | Menjawab tiket aduan / pertanyaan masyarakat dari formulir kontak web sekolah (`TKT-`). |
| | Live Chat Masuk (AI) | `dashboard/admin/live_chat.php` | Monitor obrolan tamu real-time, pengambilalihan obrolan (*human takeover*), & quick reply. |
| **Persuratan** | Permohonan Surat Siswa | `dashboard/surat/requests.php` | Verifikasi surat keterangan aktif, izin sakit medis, sinkronisasi absensi, & cetak surat. |
| | Penerbitan SPT Dinas | `dashboard/surat/request_print.php` | Cetak Surat Perintah Tugas (SPT GTK) dinas luar/diklat guru berstempel dinas & QR TTE. |
| | Agenda Surat & Disposisi| `dashboard/surat/archives.php` | Catat agenda surat masuk/keluar (`AG-IN`/`AG-OUT`), upload arsip scan, & disposisi KS. |
| **Alumni** | Verifikasi Legalisir | `dashboard/alumni/legalisir.php` | Verifikasi permohonan legalisir ijazah online alumni & cetak lembar pengesahan resmi. |
| | Tracer Study Alumni | `dashboard/alumni/tracer_study.php` | Rekapitulasi pelacakan karir lulusan (BMW) berstandar instrumen akreditasi BAN-SM. |
| **Keuangan** | Kasir SPP & Pembayaran | `dashboard/keuangan/payments.php` | Cek mutasi pembayaran siswa, verifikasi bukti bayar, & cetak kuitansi sah ber-QR. |
| | Buku Kas Umum (BKU) | `dashboard/keuangan/expenses.php` | Catat bukti kas keluar (EXP), upload nota belanja, & monitoring saldo kas riil. |
| | Penggajian (Payroll) | `dashboard/keuangan/payroll.php` | Kalkulasi honor guru berbasis JP KBM jurnal, tunjangan wali kelas, & cetak slip gaji. |
| **Sarpras & Fasilitas**| Inventaris Ruangan | `dashboard/sarpras/inventory.php` | Registrasi aset inventaris sekolah, kondisi fisik (baik/rusak), sumber dana BOS. |
| | Sirkulasi Pinjam Sarpras| `dashboard/sarpras/inventory.php` | Peminjaman fasilitas/alat sekolah oleh guru/siswa, tracking jatuh tempo, & rekap sarpras. |
| **Perpustakaan** | Pengadaan & Sirkulasi | `dashboard/perpustakaan/` | Input buku (Auto-Fill ISBN), peminjaman barcode webcam, denda, booking, & cetak barcode. |
| | Bebas Pustaka Digital | `dashboard/perpustakaan/clearance.php` | Verifikasi tanggungan buku dan penerbitan Surat Bebas Perpustakaan tingkat akhir. |
| **Akun Saya** | Profil Pengguna | `dashboard/profile.php` | Pasfoto profil, pembaruan data staf, dan pembaruan kata sandi akun. |

---

## 3. Panduan Operasional Prosedural Staf Tata Usaha

### A. Layanan Persuratan, Disposisi & Surat Perintah Tugas (SPT)
1. **Memproses Permohonan Surat Siswa & Wali Murid (`requests.php`):**
   - Buka menu `Layanan Persuratan`.
   - Tinjau daftar antrean permohonan yang berstatus **Menunggu**.
   - Periksa rincian: Siswa pemohon, jenis surat (Surat Keterangan Aktif, Surat Keterangan Berkelakuan Baik / SKBB, SKL Sementara, Rekomendasi Beasiswa, Surat Keterangan Izin Sakit).
   - Apabila terdapat lampiran medis surat dokter, klik tautan dokumen untuk memeriksa keabsahan berkas yang terlindungi engine upload aman.
   - Klik **"Setujui"** atau **"Tolak"** (disertai alasan).
   - **Otomatisasi Sinkronisasi Absensi Guru:** Begitu staf TU menyetujui surat izin sakit atau permohonan dispensasi, sistem secara otomatis memperbarui status absensi siswa di tabel `student_attendance` menjadi **Sakit** atau **Izin** pada tanggal terkait!
2. **Menerbitkan Surat Perintah Tugas (SPT GTK) Dinas Luar:**
   - Klik tombol **"Terbitkan Surat Baru"**.
   - Pilih jenis: **Surat Perintah Tugas (SPT / SPPD Guru & Tenaga Kependidikan)**.
   - Masukkan nomor surat dinas, dasar penugasan, nama pejabat pemberi tugas (Kepala Sekolah), daftar nama guru/staf yang ditugaskan, tanggal pelaksanaan, lokasi tujuan, dan beban mata anggaran.
   - Klik **"Simpan & Terbitkan"**.
   - Buka berkas cetak via `request_print.php`: Dokumen siap dicetak pada kertas A4 standar dengan kop dinas resmi, logo institusi, stempel basah berpadu natural (`mix-blend-mode: multiply`), tanda tangan Kepala Sekolah berpelindung anti-scraping, dan QR Code verifikasi dokumen.
3. **Pencatatan Buku Agenda Surat Masuk & Disposisi Kepala Sekolah (`archives.php`):**
   - **Surat Masuk:** Catat nomor agenda otomatis (`AG-IN/YYYY/MM/NNN`), instansi pengirim (Dinas Pendidikan, Kementerian, Yayasan, Puskesmas, dll.), tanggal surat, perihal, dan unggah scan berkas fisik (PDF/JPG).
   - **Lembar Disposisi Digital:** Masukkan instruksi disposisi Kepala Sekolah (misal: *Harap segera ditindaklanjuti bidang kurikulum sebelum tanggal 20*), pilih personil penerima disposisi, dan tetapkan batas waktu.
   - **Surat Keluar:** Catat nomor urut agenda dinas (`AG-OUT/YYYY/MM/NNN`), instansi tujuan, perihal edaran, dan unggah arsip tembusan scan.

---

### B. Layanan Alumni & Verifikasi Legalisir Ijazah Digital (`dashboard/alumni/`)
1. **Verifikasi Permohonan Legalisir Online (`legalisir.php`):**
   - Buka menu `Legalisir Digital Alumni`.
   - Tinjau daftar permohonan yang diajukan oleh para alumni yang berstatus **Menunggu**.
   - Periksa keaslian scan ijazah atau transkrip nilai asli yang diunggah alumni.
   - Tetapkan status verifikasi:
     - **Diverifikasi / Disetujui:** Berkas scan cocok dengan data buku induk sekolah.
     - **Perlu Revisi:** Scan berkas buram atau terpotong, berikan catatan instruksi perbaikan kepada alumni.
     - **Ditolak:** Data ijazah tidak terdaftar dalam pangkalan data arsip sekolah.
2. **Penerbitan Lembar Pengesahan Legalisir Sah (`legalisir_print.php`):**
   - Klik tombol **"Cetak Pengesahan Legalisir"**.
   - Sistem mencetak lembar legalitas resmi berukuran A4 berisikan watermark pengesahan, stempel dinas digital, tanda tangan Kepala Sekolah, dan QR Code verifikasi keaslian dokumen (`verify_document.php`).
3. **Rekapitulasi Tracer Study Alumni (`tracer_study.php`):**
   - Pantau statistik serapan lulusan: persentase yang Bekerja, Melanjutkan Kuliah, Wirausaha (BMW), dan Masa Tunggu Kerja.
   - Ekspor data dalam format spreadsheet CSV untuk kebutuhan pengisian instrumen akreditasi sekolah (BAN-SM).

---

### C. Pusat Layanan Tamu: Tiket Pengaduan & AI Live Chat Masuk
1. **Penanganan Tiket Pesan Kontak Masuk (`contact_messages.php`):**
   - Setiap pertanyaan dari formulir web `kontak.php` masuk dengan nomor tiket otomatis (`TKT-YYYYMMDD-XXXX`).
   - Staf TU meninjau identitas pengirim, kategori (Akademik, PPDB, Administrasi, Sarpras), dan kronologi pesan.
   - Ubah status menjadi **Diproses** saat berkoordinasi dengan bidang terkait, tambahkan catatan internal penanganan, dan ubah menjadi **Selesai** setelah keluhan terjawab.
2. **Layanan Live Chat Masuk & Human Takeover (`live_chat.php`):**
   - Buka menu `Live Chat Masuk`.
   - Staf dapat memantau percakapan pengunjung publik secara real-time.
   - **Fitur Human Takeover:** Saat pengunjung membutuhkan bantuan manusia atau mengklik tombol eskalasi bantuan staf, klik tombol **"Ambil Alih Obrolan (Human Takeover)"**.
   - Bot AI akan dinonaktifkan pada sesi tersebut, dan staf TU dapat mengetikkan balasan langsung dua arah.
   - Gunakan **Quick Reply Templates** untuk mengirimkan jawaban cepat yang telah terstandarisasi (syarat mutasi siswa, rincian biaya SPP, jadwal buka pelayanan TU).
   - Setelah selesai, klik tombol **"Tandai Selesai"**.

---

### D. Pengelolaan Keuangan, Kasir SPP & Buku Kas Umum (BKU)
1. **Kasir SPP & Verifikasi Pembayaran Siswa (`payments.php`):**
   - Filter tagihan siswa berdasarkan kelas dan status pembayaran (`Belum Lunas`, `Menunggu Verifikasi`, `Lunas`).
   - Ketika orang tua mengunggah bukti transfer, status berubah menjadi **Menunggu Verifikasi**.
   - Klik **"Periksa Bukti"** untuk membandingkan mutasi rekening bank sekolah dengan foto struk transfer yang diunggah.
   - Jika pembayaran valid, klik tombol **"Konfirmasi Lunas"**.
   - Cetak tanda terima pembayaran sah bernomor seri resmi via menu `receipt.php`.
2. **Pencatatan Buku Kas Umum (BKU) Kas Pengeluaran (`expenses.php`):**
   - Buka menu `Buku Kas Umum (BKU)` → Klik **"Catat Pengeluaran Baru"**.
   - Sistem menerbitkan nomor Bukti Kas Keluar otomatis (`EXP-YYYY/MM/NNN`).
   - Pilih Kategori Anggaran (ATK, Listrik/Air/Internet, Konsumsi Rapat, Pemeliharaan Gedung, Kegiatan Kesiswaan/Lomba, Honorarium).
   - Masukkan tanggal pengeluaran, nominal (Rp), nama penerima rekanan/toko, dan rincian belanja.
   - Unggah scan nota kwitansi asli atau invoice pembelian.
   - Sistem secara otomatis menghitung neraca kas riil sekolah:
     $$\text{Saldo Kas Tersedia} = \text{Penerimaan Kas SPP} - \text{Pengeluaran Kas BKU}$$
   - Cetak laporan rekapitulasi BKU bulanan/tahunan untuk kebutuhan pelaporan SPJ bos atau yayasan.

---

### E. Penggajian Pendidik & Tenaga Kependidikan (Payroll) (`payroll.php`)
1. **Kalkulasi Penggajian Bulanan:**
   - Masuk ke menu `Penggajian (Payroll)`.
   - Pilih bulan dan tahun periode penggajian.
   - Sistem secara otomatis menghitung komponen penghasilan:
     - **Gaji Pokok:** Standar upah guru/pegawai.
     - **Tunjangan Jabatan / Wali Kelas:** Otomatis ditambahkan jika guru ditugaskan sebagai wali kelas di tabel `classes`.
     - **Honor Jam Mengajar (JP):** Akumulasi otomatis dari seluruh entri tatap muka pada *Jurnal Mengajar KBM* dikalikan tarif per jam sekolah.
     - **Potongan Presensi & Keterlambatan:** Otomatis dikalkulasikan dari data presensi geofencing guru.
2. **Pencairan & Notifikasi Otomatis:**
   - Setelah divalidasi, ubah status penggajian menjadi **Dibayar**.
   - Sistem secara otomatis mengirimkan notifikasi rincian pencairan ke akun WhatsApp guru bersangkutan via WhatsApp Gateway.
3. **Cetak Slip Gaji Digital Resmi (`payroll_slip.php`):**
   - Staf TU dapat mencetak lembar slip gaji resmi berformat kedinasan dengan teks terbilang rupiah, kop dinas sekolah, dan stempel QR Code verifikasi.

---

### F. Tata Kelola Sirkulasi Perpustakaan Digital (`perpustakaan/`)
1. **Pengadaan Buku Baru dengan Auto-Fill ISBN (`books.php`):**
   - Masukkan 10 atau 13 digit nomor **ISBN** buku baru, lalu klik **"Auto-Fill via API"**.
   - Sistem otomatis menghubungi Google Books API dan Open Library API untuk mengisi judul, penulis, penerbit, tahun, dan sinopsis secara instan.
2. **Sirkulasi Pinjam-Kembali via Quick Scan Webcam (`scan.php`):**
   - Arahkan kartu barcode anggota siswa dan barcode punggung buku ke kamera scanner sirkulasi.
   - Sistem mencatat peminjaman dan secara otomatis mengkalkulasikan denda keterlambatan jika buku dikembalikan melewati batas waktu.
3. **Penyelesaian Booking Buku Mandiri (`reservations.php`):**
   - Periksa daftar buku yang di-booking siswa dari katalog online.
   - Siapkan buku di meja sirkulasi; saat siswa datang mengambil, klik **"Proses Menjadi Pinjaman Aktif"**.
4. **Penerbitan Surat Bebas Perpustakaan (`clearance.php`):**
   - Validasi riwayat pinjaman siswa tingkat akhir (0 pinjaman aktif & 0 denda).
   - Klik **"Cetak Surat Keterangan Bebas Perpustakaan"** sebagai syarat kelulusan.

---

### G. Manajemen Inventaris Sarana & Prasarana (Sarpras) (`inventory.php`)
1. **Pencatatan Buku Inventaris Aset:**
   - Registrasi aset baru: Kode Barang, Nama Barang, Kategori (Elektronik, Perabot, Alat Lab), Ruangan Penempatan, Jumlah Unit, Nilai Aset, dan Sumber Dana (BOS/Yayasan/Komite).
   - Pemutakhiran Kondisi Fisik berkala: **Baik**, **Rusak Ringan**, atau **Rusak Berat**.
2. **Sirkulasi Peminjaman Fasilitas Sekolah:**
   - Catat peminjaman sarana (proyektor, laptop lab, sound system) oleh guru/siswa, tanggal pinjam, dan estimasi waktu pengembalian.
   - Pantau status jatuh tempo peminjaman dan lakukan konfirmasi kondisi fisik barang saat dikembalikan ke ruang sarpras.

---

## 4. Alur Kerja Harian Staf Tata Usaha

```
Pagi (07:00 - 09:30):
├── Buka Kotak Masuk Pesan Tamu (contact_messages.php) & Live Chat Masuk (live_chat.php)
├── Tinjau permohonan surat masuk dari siswa/ortu (requests.php) & sinkronkan presensi
└── Periksa mutasi pembayaran SPP siswa & konfirmasi kuitansi lunas (payments.php)

Siang (09:30 - 13:30):
├── Catat agenda surat dinas masuk/keluar & proses lembar disposisi KS (archives.php)
├── Terbitkan Surat Perintah Tugas (SPT GTK) bagi guru yang berdinas luar
├── Layani sirkulasi peminjaman sarpras dan meja sirkulasi buku perpustakaan
└── Verifikasi pengajuan legalisir digital ijazah online dari alumni (legalisir.php)

Sore (13:30 - 16:00):
├── Catat transaksi pengeluaran operasional sekolah pada Buku Kas Umum (expenses.php)
├── Tinjau ketersediaan barang sarpras & konfirmasi pengembalian alat
└── Cetak rekapitulasi penerimaan kas dan arsip dokumen harian
```

---

## 5. Rekomendasi Praktik Keamanan & Ketelitian Kerja Staf TU
- **Verifikasi Fisik Mutasi Bank:** Jangan pernah menandai tagihan SPP menjadi *Lunas* sebelum memeriksa mutasi rekening bank sekolah secara nyata guna mencegah bukti transfer palsu/rekayasa gambar.
- **Pemeriksaan Dokumen Medis:** Teliti berkas surat dokter yang dilampirkan siswa pada permohonan izin sakit sebelum memberikan persetujuan dispensasi.
- **Standar Keamanan Berkas:** Selalu unggah berkas arsip dalam format PDF/JPG resmi yang telah lolos validasi sistem aman `config/upload_helper.php`.
- **Kerahasiaan Data Civitas:** Jangan memberikan informasi kontak pribadi guru, siswa, atau orang tua kepada pihak luar tanpa izin tertulis dari Kepala Sekolah.
- **Terminasi Sesi Akun:** Selalu klik *Logout* saat meninggalkan meja kerja tata usaha.
