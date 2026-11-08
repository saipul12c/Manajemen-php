# 🟣 Panduan Operasional Peran: Orang Tua (Wali Murid)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP v1.3.0**

---

## 1. Ringkasan Peran & Aksesibilitas Wali Murid

Peran **Orang Tua** (*Wali Murid*) dirancang untuk memberikan transparansi penuh terhadap seluruh aktivitas, perkembangan hasil belajar, kedisiplinan karakter, dan administrasi keuangan putra-putrinya di sekolah. Melalui portal orang tua yang terintegrasi, wali murid dapat memantau kehadiran anak secara *real-time*, mengontrol penyelesaian tugas belajar, mengurus izin ketidakhadiran secara digital, membayar tagihan SPP secara online, membaca buku rapor semester, serta berkonsultasi langsung dengan dewan guru dan wali kelas.

### 🌟 Fitur Unggulan & Pembaruan Sistem v1.3.0
1. **Dukungan Multi-Anak Terpadu (*Multi-Child Switching*):**
   - Bagi orang tua yang memiliki lebih dari satu anak bersekolah di institusi yang sama, akun orang tua secara otomatis terhubung ke seluruh profil anak melalui relasi resmi pangkalan data `parent_students`.
   - Orang tua cukup mengeklik nama anak pada menu pemilih (*dropdown switcher*) di bagian atas layar untuk beralih data secara instan tanpa perlu repot memiliki banyak akun.
   - **Perbaikan Keamanan Anti-IDOR (v1.3.0):** Sistem memastikan orang tua dapat melakukan pembayaran tagihan SPP dan melihat data seluruh anak yang terdaftar secara aman dan terverifikasi.
2. **Radar Profil Siswa 360 Derajat (`student_360.php`):**
   - Layar analitik holistik yang memotret seluruh aspek perkembangan anak dalam satu tampilan: biodata Dapodik, grafik radar pencapaian nilai kompetensi antar-mata pelajaran, rasio persentase kehadiran bulanan, akumulasi poin kedisiplinan dan prestasi BK, keaktifan klub ekstrakurikuler, dan riwayat tagihan SPP.
3. **E-Rapor Kurikulum Merdeka & Rapor Projek P5:**
   - Orang tua dapat meninjau buku rapor digital dengan narasi capaian pembelajaran dua sisi (*Capaian Tertinggi* dan *Perlu Bimbingan*), serta perkembangan dimensi Profil Pelajar Pancasila putra-putrinya pada Rapor P5.
4. **Verifikasi Kuitansi SPP Digital Ber-QR Code:**
   - Pembayaran SPP terhubung dengan opsi transfer manual maupun otomatis melalui Payment Gateway (QRIS / Virtual Account).
   - Setiap pembayaran yang terverifikasi menghasilkan kuitansi pembayaran resmi berstempel digital dinas dan QR Code verifikasi keaslian dokumen (`verify_document.php`).
5. **Widget Layanan AI Live Chat Mandiri:**
   - Orang tua dapat bertanya secara interaktif kapan saja melalui widget percakapan di sudut layar seputar jadwal KBM, kalender akademik, biaya pendidikan, maupun meminta bantuan langsung kepada petugas piket sekolah (*human takeover*).

---

## 2. Navigasi & Fitur Portal Orang Tua

| Layanan Utama | Sub-Fitur | Halaman Terkait | Manfaat bagi Orang Tua |
| :--- | :--- | :--- | :--- |
| **Menu Utama** | Beranda Dasbor | `dashboard/index.php` | Status kehadiran anak hari ini, ringkasan nilai terbaru, & status tagihan SPP aktif. |
| | Pasang Aplikasi (Hub) | `dashboard/apps_hub.php` | Pasang aplikasi PWA sekolah di smartphone Android/iOS untuk akses cepat dari layar utama. |
| | Kalender Akademik | `dashboard/akademik/calendar.php` | Jadwal efektif KBM, hari libur nasional, jadwal penilaian semester, & agenda rapat wali murid. |
| | Pengumuman Sekolah | `dashboard/informasi/announcements.php` | Surat edaran resmi sekolah, info kegiatan kesiswaan, & dokumen lampiran edaran PDF. |
| | Pesan & Konsultasi | `dashboard/pesan/messages.php` | Konsultasi belajar privat dua arah langsung dengan Wali Kelas atau Guru Mata Pelajaran. |
| **Akademik Anak** | Jadwal Pelajaran | `dashboard/akademik/timetable.php` | Mengetahui jadwal belajar mingguan anak, mata pelajaran, alokasi jam KBM, & guru pengampu. |
| | Monitoring Tugas | `dashboard/akademik/assignments.php` | Memantau tugas aktif anak, batas waktu pengumpulan, status pengerjaan, & nilai dari guru. |
| | E-Rapor Akademik | `dashboard/akademik/report_card.php` | Melihat buku rapor Kurikulum Merdeka, narasi capaian kompetensi, & cetak lembar nilai. |
| | Rapor Projek P5 | `dashboard/akademik/p5_projects.php` | Meninjau perkembangan karakter & dimensi projek Profil Pelajar Pancasila anak. |
| | Ekskul & Prestasi | `dashboard/ekskul/index.php` | Memantau keikutsertaan ekskul anak, kehadiran latihan, predikat nilai, & piagam kejuaraan. |
| | Radar Siswa 360° | `dashboard/admin/student_360.php` | Analitik holistik 360 derajat pencapaian akademik, kehadiran, poin BK, & keuangan anak. |
| **Presensi & Disiplin**| Rekap Kehadiran Anak| `dashboard/presensi/attendance.php` | Grafik persentase absensi bulanan anak (Hadir, Sakit, Izin, Alpa) dan histori waktu presensi. |
| | Rekam Jejak BK | `dashboard/bk/counseling.php` | Catatan kedisiplinan berpoin dan rekam jejak prestasi kejuaraan yang diraih anak. |
| **Perizinan & Surat**| Permohonan Surat Resmi | `dashboard/surat/requests.php` | Pengajuan surat izin sakit anak (upload surat dokter), surat keterangan aktif, & beasiswa. |
| **Keuangan & SPP** | Riwayat & Bayar Tagihan| `dashboard/keuangan/payments.php` | Cek rincian SPP, bayar transfer/QRIS, upload bukti struk, & unduh kuitansi resmi ber-QR. |
| **Perpustakaan** | Katalog Buku Sekolah| `dashboard/perpustakaan/books.php`| Meninjau koleksi literasi buku perpustakaan yang dapat dibaca atau dipinjam oleh anak. |
| **Akun Saya** | Profil Wali Murid | `dashboard/profile.php` | Perbarui nomor WhatsApp untuk penerimaan notifikasi dinas dan pembaruan kata sandi. |

---

## 3. Panduan Penggunaan Layanan untuk Orang Tua

### A. Memilih Profil Anak (*Multi-Child Selector*)
1. Jika Anda mendaftarkan lebih dari satu anak di sekolah ini:
2. Perhatikan bilah navigasi bagian atas (*top bar*) dashboard.
3. Klik menu pilihan nama anak untuk berganti profil (misal: beralih dari *Ananda Rizky - Kelas X MIPA 1* ke *Adinda Aulia - Kelas XII IPS 2*).
4. Seluruh informasi dasbor, rekap presensi, tugas belajar, dan tagihan SPP akan secara otomatis menyesuaikan dengan data anak yang dipilih secara aman.

---

### B. Pengajuan Surat Izin Ketidakhadiran (Sakit / Izin Keluarga)
Orang tua tidak perlu lagi mengirimkan surat fisik ke sekolah jika putra-putrinya berhalangan hadir:
1. Masuk ke menu `Layanan Persuratan` (`dashboard/surat/requests.php`).
2. Klik tombol **"Buat Pengajuan Baru"**.
3. Pilih Jenis Layanan:
   - **Surat Keterangan Izin Sakit** (bila anak sedang sakit).
   - **Surat Dispensasi / Izin Keperluan Keluarga** (bila ada urusan keluarga mendesak).
4. Masukkan tanggal mulai dan tanggal selesai izin.
5. Tuliskan keterangan alasan pada kolom catatan (contoh: *Anak sakit demam dan diharuskan istirahat dokter selama 3 hari terhitung tanggal 10-12 November 2026*).
6. **Unggah Lampiran Medis:** Unggah foto atau scan surat keterangan dokter / surat izin keluarga (format JPG, PNG, atau PDF) yang terlindungi mesin validasi aman.
7. Klik **"Kirim Permohonan"**.
8. **Dampak Otomatisasi Sistem:**
   - Permohonan langsung diverifikasi oleh Staf Tata Usaha.
   - Begitu disetujui, sistem secara otomatis memperbarui status absensi anak menjadi **Sakit** atau **Izin** pada buku presensi harian guru kelas tanpa perlu input manual lagi!

---

### C. Monitoring Akademik, Tugas Belajar & E-Rapor
1. **Memantau Penyelesaian Tugas Belajar (`assignments.php`):**
   - Buka menu `Tugas & Evaluasi`.
   - Tinjau seluruh daftar penugasan aktif dari para pendidik beserta tenggat waktunya (*due date*).
   - Perhatikan kolom status:
     - 🟢 **Selesai (Submitted):** Anak telah menyerahkan jawaban tugas. Orang tua dapat melihat skor nilai dan catatan evaluasi yang diberikan guru.
     - 🔴 **Belum Mengumpulkan:** Anak belum menyerahkan tugas; orang tua dapat segera mendampingi dan mengingatkan anak sebelum batas waktu berakhir.
2. **Melihat E-Rapor Kurikulum Merdeka & Rapor P5 (`report_card.php`):**
   - Pada masa akhir semester, buka menu `E-Rapor Akademik`.
   - Orang tua dapat membaca transkrip nilai capaian kompetensi, narasi dua sisi (*Capaian Pembelajaran Tertinggi* dan *Capaian yang Perlu Ditingkatkan*), catatan motivasi dari Wali Kelas, serta predikat kegiatan Ekstrakurikuler anak.
   - Buka menu `Rapor Projek P5` untuk melihat perkembangan karakter profil pelajar pancasila putra-putri Anda.
   - Klik tombol **"Cetak Rapor"** untuk mengunduh atau mencetak berkas resmi format A4 berstempel digital sekolah dan QR Code verifikasi keaslian.

---

### D. Pembayaran SPP & Unduh Kuitansi Sah Ber-QR Code
1. **Melihat Rincian Tagihan Pendidikan (`payments.php`):**
   - Buka menu `Keuangan & SPP`.
   - Tinjau daftar tagihan anak (SPP bulanan, uang kegiatan kesiswaan, atau iuran praktikum) lengkap dengan nominal rupiah, tanggal jatuh tempo, dan status pembayaran (`Belum Lunas`, `Menunggu Verifikasi`, `Lunas`).
2. **Melakukan Pembayaran Mandiri:**
   - Transfer pembayaran ke rekening bank resmi sekolah atau lakukan pemindaian kode QRIS lembaga yang tertera pada layar.
   - Klik tombol **"Bayar / Konfirmasi Pembayaran"**.
   - Masukkan nominal bayar, tanggal transfer, bank pengirim, dan unggah foto struk ATM / tangkapan layar bukti transfer mobile banking.
   - Klik **"Kirim Konfirmasi Pembayaran"**.
3. **Mengunduh Kuitansi Pembayaran Sah (`receipt.php`):**
   - Petugas kasir tata usaha sekolah akan memverifikasi mutasi bank yang masuk.
   - Begitu diverifikasi menjadi **Lunas**, tombol **"Cetak Kuitansi"** akan aktif.
   - Kuitansi memuat nomor seri unik, stempel lunas digital sekolah, dan kode QR pengesahan yang dapat diverifikasi keasliannya melalui portal publik `verify_document.php`.

---

### E. Konsultasi Privat dengan Dewan Guru & Wali Kelas (`messages.php`)
1. Buka menu `Pesan & Konsultasi`.
2. Klik tombol **"Tulis Pesan Baru"**.
3. Pilih penerima: Anda dapat memilih Wali Kelas anak, Guru pengampu mata pelajaran tertentu, atau Guru Bimbingan Konseling (BK).
4. Tuliskan perihal dan pesan konsultasi (misal: berkonsultasi mengenai motivasi belajar anak, kendala pemahaman materi tertentu, atau pembinaan karakter).
5. Guru yang bersangkutan akan menerima notifikasi pesan di dasbor mereka dan memberikan respon konsultasi secara privat.

---

### F. Layanan AI Live Chat Masuk (Bantuan Cepat 24/7)
1. Perhatikan ikon chat mengambang (*Live Chat Widget*) di pojok kanan bawah layar.
2. Klik ikon chat untuk membuka jendela bantuan virtual asisten sekolah.
3. Anda dapat menanyakan topik umum kapan saja:
   - *"Bagaimana prosedur pembayaran SPP via transfer bank?"*
   - *"Kapan jadwal ujian akhir semester dimulai?"*
   - *"Bagaimana alur pengajuan surat izin sakit anak?"*
4. Jika pertanyaan membutuhkan penanganan petugas khusus, sistem akan meneruskan obrolan ke staf tata usaha atau admin piket (*human takeover*) untuk interaksi langsung.

---

## 4. Keamanan Akun & Tips Wali Murid
- **Verifikasi Nomor WhatsApp:** Pastikan nomor ponsel yang didaftarkan pada profil akun terhubung dengan WhatsApp aktif guna menerima siaran notifikasi tagihan SPP, kuitansi, pengumuman darurat, dan rekapan presensi anak.
- **Kerahasiaan Akun:** Jaga kerahasiaan kata sandi akun portal Anda dan jangan meminjamkan akun kepada pihak lain.
- **Terminasi Sesi:** Selalu lakukan *Logout* setelah mengakses portal sekolah, khususnya jika menggunakan perangkat smartphone atau komputer bersama.
