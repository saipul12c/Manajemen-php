# 🟣 Panduan Operasional Peran: Orang Tua (Wali Murid)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP**

---

## 1. Ringkasan Peran & Aksesibilitas Wali Murid

Peran **Orang Tua** (*Wali Murid*) dirancang untuk memberikan transparansi penuh terhadap seluruh aktivitas, perkembangan akademik, kedisiplinan, dan administrasi keuangan putra-putrinya di sekolah. Melalui portal ini, orang tua dapat memantau kehadiran anak secara *real-time*, mengontrol penyelesaian tugas belajar, mengurus izin/sakit secara digital, membayar tagihan SPP, serta berkonsultasi langsung dengan dewan guru.

### 🌟 Fitur Unggulan: Dukungan Multi-Anak (*Multi-Child Switching*)
Bagi orang tua yang memiliki lebih dari satu anak bersekolah di institusi yang sama, akun orang tua secara cerdas terhubung ke seluruh profil anak melalui tabel `parent_students`. Orang tua cukup memilih nama anak melalui menu selektor di bagian atas dashboard untuk beralih data secara instan tanpa perlu memiliki banyak akun.

---

## 2. Navigasi & Fitur Portal Orang Tua

| Layanan Utama | Sub-Fitur | Halaman Terkait | Manfaat bagi Orang Tua |
| :--- | :--- | :--- | :--- |
| **Dashboard** | Ikhtisar Perkembangan | `dashboard/index.php` | Status kehadiran anak hari ini, ringkasan nilai terbaru, & status tagihan SPP. |
| **Akademik Anak** | Jadwal Pelajaran | `dashboard/akademik/timetable.php` | Mengetahui jadwal belajar mingguan, mata pelajaran, dan nama guru pengampu. |
| | Monitoring Tugas | `dashboard/akademik/assignments.php` | Memantau tugas aktif anak, tenggat waktu, dan status apakah sudah mengumpulkan. |
| | Rapor Digital Semester| `dashboard/akademik/report_card.php` | Melihat nilai transkrip semester, catatan kepribadian dari wali kelas, & ekskul. |
| **Presensi** | Rekap Kehadiran Anak | `dashboard/presensi/attendance_report.php` | Memantau grafik persentase kehadiran bulanan (Hadir, Sakit, Izin, Alpa). |
| **Perizinan & Surat**| Pengajuan Izin / Sakit | `dashboard/surat/requests.php` | Mengajukan izin ketidakhadiran anak dengan upload foto surat dokter / dinas. |
| | Permohonan Surat Resmi | `dashboard/surat/requests.php` | Mengajukan Surat Keterangan Aktif, rekomendasi beasiswa, & cetak surat sah. |
| **Keuangan & SPP** | Riwayat & Bayar Tagihan | `dashboard/keuangan/payments.php` | Cek nominal SPP, bayar via Transfer Bank/QRIS, upload struk, cetak kwitansi sah. |
| **Konsultasi** | Pesan Privat ke Guru | `dashboard/pesan/messages.php` | Konsultasi perkembangan anak langsung dengan wali kelas atau guru mapel. |
| **Pengumuman** | Papan Informasi Sekolah| `informasi.php` / Dashboard | Membaca edaran resmi sekolah, agenda pertemuan wali murid, & pengumuman darurat. |

---

## 3. Panduan Penggunaan Layanan untuk Orang Tua

### A. Pengajuan Surat Izin Ketidakhadiran (Sakit / Izin Keluarga)

Orang tua tidak perlu lagi mengirimkan surat fisik ke sekolah jika anak berhalangan hadir:
1. Masuk ke menu `Layanan Surat` → `Pengajuan Surat`.
2. Klik tombol **"Ajukan Permohonan Surat"**.
3. Pilih Jenis Layanan: **Surat Keterangan Izin Sakit** atau **Surat Dispensasi / Izin Keperluan**.
4. Tuliskan keterangan alasan ketidakhadiran pada kolom catatan (contoh: *Anak sakit demam berdarah dan diharuskan istirahat dokter selama 3 hari*).
5. **Unggah Lampiran Medis:** Lampirkan foto atau dokumen scan surat keterangan dokter (format JPG, PNG, atau PDF).
6. Klik **"Kirim Pengajuan"**.
7. **Dampak Otomatisasi Sistem:**
   - Permohonan langsung masuk ke meja verifikasi Staf Tata Usaha.
   - Begitu disetujui staf, status absensi anak pada hari terkait secara otomatis berubah menjadi **Sakit** atau **Izin** pada buku presensi guru.

---

### B. Monitoring Akademik & Tugas Belajar Anak

1. **Memeriksa Tugas Sekolah (`assignments.php`):**
   - Buka menu `Akademik` → `Tugas Pembelajaran`.
   - Orang tua dapat melihat seluruh daftar tugas aktif beserta batas waktu pengumpulannya (*due date*).
   - Periksa kolom status:
     - 🟢 **Selesai:** Anak sudah mengumpulkan tugas (orang tua dapat melihat nilai skor dan komentar koreksi guru).
     - 🔴 **Belum Mengumpulkan:** Anak belum menyerahkan tugas; orang tua dapat segera mengingatkan anak sebelum batas waktu berakhir.
2. **Melihat Hasil Ujian & Rapor Semester (`report_card.php`):**
   - Pantau capaian hasil ujian harian, UTS, dan UKK anak.
   - Pada akhir semester, buka menu Rapor Digital untuk meninjau transkrip nilai lengkap beserta catatan pembinaan dari Wali Kelas dan catatan kegiatan ekstrakurikuler.

---

### C. Pembayaran SPP & Unduh Kwitansi Resmi

1. **Melihat Tagihan Pendidikan (`payments.php`):**
   - Buka menu `Keuangan & Tagihan`.
   - Tinjau daftar tagihan anak (SPP bulanan, uang kegiatan, dll.) beserta nominal (Rp), status pembayaran (`Belum Lunas` / `Lunas`), dan tanggal jatuh tempo.
2. **Melakukan Pembayaran Mandiri:**
   - Lakukan pembayaran melalui transfer ke rekening bank resmi sekolah atau scan kode QRIS lembaga yang tertera pada layar.
   - Klik tombol **"Bayar Sekarang / Upload Bukti"**.
   - Masukkan nominal bayar, tanggal transfer, bank asal pengirim, dan unggah foto struk ATM / bukti transfer mobile banking.
   - Klik **"Kirim Konfirmasi Pembayaran"**.
3. **Mengunduh Kwitansi Pembayaran Sah (`receipt.php`):**
   - Status tagihan akan berubah menjadi **Menunggu Verifikasi** saat staf keuangan memeriksa mutasi bank.
   - Setelah diverifikasi menjadi **Lunas**, tombol **"Cetak Kwitansi"** akan aktif.
   - Orang tua dapat mengunduh atau mencetak kwitansi resmi berstempel lunas digital sekolah sebagai arsip administrasi pribadi.

---

### D. Konsultasi Komunikasi dengan Pendidik (`messages.php`)

1. Buka menu `Pesan Masuk`.
2. Klik tombol **"Tulis Pesan Baru"**.
3. Pilih penerima: Anda dapat memilih Wali Kelas anak atau Guru pengampu mata pelajaran tertentu.
4. Tuliskan perihal dan pesan konsultasi (misal: konsultasi terkait perkembangan belajar anak, izin khusus, atau kendala pemahaman materi).
5. Guru yang bersangkutan akan menerima notifikasi pesan di dashboard mereka dan membalas konsultasi secara privat.

---

## 4. Keamanan Akun Orang Tua
- Pastikan alamat email yang didaftarkan aktif guna menerima notifikasi pengumuman dan kemudahan pemulihan sandi.
- Jangan membagikan informasi login kepada pihak yang tidak berhak.
- Gunakan fitur keluar (*Logout*) setelah selesai mengakses aplikasi pada perangkat publik.
