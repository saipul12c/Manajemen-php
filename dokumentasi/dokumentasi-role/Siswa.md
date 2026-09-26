# 🔵 Panduan Operasional Peran: Siswa (Peserta Didik)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP**

---

## 1. Ringkasan Peran & Pusat Layanan Belajar Mandiri

Peran **Siswa** (*Peserta Didik Aktif*) merupakan pusat dari seluruh ekosistem pembelajaran digital di **Manajemen-PHP**. Melalui portal siswa yang responsif dan modern, peserta didik dapat mengakses materi E-Learning, mengumpulkan tugas secara terjadwal, mengikuti asesmen ujian online ber-timer, membaca koleksi e-book langsung di peramban, memantau riwayat presensi kehadiran via QR Code, hingga mengajukan surat keterangan kesiswaan secara mandiri.

---

## 2. Navigasi & Modul Portal Siswa

| Modul Pembelajaran | Fitur / Halaman | File Eksekusi | Deskripsi Kegunaan |
| :--- | :--- | :--- | :--- |
| **Dashboard** | Beranda Siswa | `dashboard/index.php` | Jadwal kelas hari ini, tugas yang mendekati deadline, kartu QR absensi, & info ujian. |
| **Akademik** | Materi & Modul Ajar | `dashboard/akademik/materials.php` | Unduh modul ajar (PDF/PPT/DOC), tonton video YouTube, & link referensi materi. |
| | Pengumpulan Tugas | `dashboard/akademik/assignments.php` | Unduh soal, serahkan berkas jawaban tugas, lihat nilai & komentar evaluasi guru. |
| | Jadwal Pelajaran | `dashboard/akademik/timetable.php` | Jadwal mingguan mata pelajaran, jam KBM, ruang kelas, & guru pengampu. |
| | Rapor Digital Semester| `dashboard/akademik/report_card.php` | Transkrip capaian kompetensi nilai semester, ekskul, & catatan wali kelas. |
| | Kalender Agenda | `dashboard/akademik/calendar.php` | Kalender pendidikan, hari efektif belajar, agenda sekolah, & jadwal libur. |
| **Ujian Online** | Daftar Asesmen | `dashboard/Modul-ujian/exams.php` | Mengakses ujian harian, UTS, UKK, & latihan dengan memasukkan token ujian. |
| | Mengerjakan Ujian | `dashboard/Modul-ujian/exam_take.php` | Antarmuka ujian dengan timer countdown, navigasi nomor, & acak urutan soal. |
| | Hasil & Remedial | `dashboard/Modul-ujian/exam_results.php` | Melihat skor nilai perolehan ujian dan mengerjakan remedial jika belum tuntas KKM. |
| | Cetak Kartu Ujian | `dashboard/Modul-ujian/exam_card.php` | Cetak kartu tanda peserta ujian resmi lengkap dengan jadwal dan data diri. |
| **Presensi** | Kartu QR Digital | `dashboard/presensi/qr_card.php` | Kartu identitas presensi ber-QR Code untuk di-scan oleh guru/kamera sekolah. |
| | Rekap Absensi | `dashboard/presensi/attendance.php` | Riwayat kehadiran harian diri sendiri (Hadir, Sakit, Izin, Alpa). |
| **Perpustakaan** | Katalog Buku & E-Reader| `perpustakaan.php` / Dashboard | Katalog OPAC, baca E-Book layar penuh, booking buku mandiri, & ulasan bintang. |
| | Surat Bebas Pustaka | `dashboard/perpustakaan/clearance.php` | Cek tanggungan pinjaman buku dan terbitkan Surat Bebas Pustaka tingkat akhir. |
| **Layanan Siswa** | Permohonan Surat | `dashboard/surat/requests.php` | Mengajukan Surat Keterangan Aktif, SKBB, beasiswa, izin sakit, & cetak surat. |
| | Pesan Konsultasi | `dashboard/pesan/messages.php` | Kirim pesan tanya jawab materi ajar langsung kepada guru mata pelajaran. |

---

## 3. Panduan Operasional Prosedural Siswa

### A. Pengumpulan Tugas Belajar (`assignments.php`)

1. Buka menu `Akademik` → `Tugas & Pengumpulan`.
2. Pilih tugas yang masih aktif bertanda status **Belum Selesai**.
3. Baca instruksi pengerjaan dari guru dan unduh berkas lampiran pendukung (jika disediakan).
4. Perhatikan batas akhir pengumpulan (*due date*) agar tidak terlambat.
5. **Menyerahkan Jawaban:**
   - Tuliskan ringkasan jawaban atau catatan pengerjaan pada kolom yang tersedia.
   - Unggah berkas dokumen jawaban (format PDF, DOCX, JPG, atau ZIP) maks. 5 MB.
   - Klik tombol **"Kirim Tugas"**.
6. Status tugas akan berubah menjadi **Selesai**. Setelah guru memeriksa, siswa dapat membuka kembali tugas untuk melihat **Nilai Skor** dan **Catatan Feedback Pendidik**.

---

### B. Prosedur Mengikuti Ujian & Latihan Online (`Modul-ujian/`)

1. **Memulai Ujian (`exams.php`):**
   - Pilih asesmen yang dijadwalkan aktif (Ujian Harian / UTS / UKK / Latihan).
   - Apabila guru mengaktifkan proteksi token, masukkan **Kode Token Ujian** yang diberikan guru di kelas.
   - Klik **"Mulai Mengerjakan"**.
2. **Antarmuka Pengerjaan Soal (`exam_take.php`):**
   - **Countdown Timer:** Waktu berjalan mundur secara otomatis di pojok layar. Sistem akan mengumpulkan jawaban secara otomatis ketika waktu ujian habis.
   - **Navigasi Soal:** Siswa dapat berpindah nomor soal secara fleksibel menggunakan kisi nomor soal.
   - **Soal Pilihan Ganda:** Klik opsi jawaban yang menurut Anda benar.
   - **Soal Esai:** Ketik uraian jawaban secara runut pada kotak teks esai.
   - Klik **"Selesai & Kumpulkan Ujian"** jika seluruh soal telah dikerjakan.
3. **Melihat Hasil & Sesi Remedial (`exam_results.php`):**
   - Skor soal pilihan ganda dihitung otomatis oleh sistem.
   - Apabila nilai akhir berada di bawah KKM dan guru telah memberikan akses remedial, tombol **"Kerjakan Remedial"** akan aktif pada daftar ujian siswa.

---

### C. Perpustakaan Digital: Baca E-Book & Reservasi Buku Mandiri

1. **Membaca Koleksi E-Book Digital:**
   - Buka menu `Perpustakaan` atau akses halaman publik `perpustakaan.php`.
   - Pilih buku berkategori digital / e-book → Klik **"Baca E-Book"**.
   - Berkas PDF terbuka langsung di peramban web (*In-Browser Reader*) dengan fitur **Layar Penuh (Fullscreen)** tanpa perlu mengunduh berkas fisik ke perangkat.
2. **Fitur Booking / Reservasi Buku Mandiri:**
   - Jika Anda membutuhkan buku fisik sekolah namun belum sempat mengambilnya ke perpustakaan:
   - Cari buku pada katalog → Klik tombol **"Booking / Reservasi Buku"**.
   - Sistem menahan stok buku tersebut di meja sirkulasi perpustakaan selama **2 hari kerja**.
   - Siswa dapat memantau antrean pemesanan pada menu `Reservasi Saya`.
   - Kunjungi ruang perpustakaan dan tunjukkan kartu anggota untuk mengambil buku fisik pinjaman Anda.
3. **Review & Rating Komunitas:**
   - Berikan ulasan tertulis dan rating bintang (1–5) pada buku yang telah Anda baca guna memberikan rekomendasi bagi siswa lainnya.
4. **Penerbitan Surat Bebas Perpustakaan (`clearance.php`):**
   - Bagi siswa kelas akhir (XII / IX), buka menu `Bebas Perpustakaan`.
   - Sistem memverifikasi bahwa seluruh pinjaman buku telah dikembalikan dan tidak ada tunggakan denda.
   - Klik tombol **"Cetak Surat Keterangan Bebas Perpustakaan"** sebagai kelengkapan berkas kelulusan.

---

### D. Presensi Digital via Kartu QR Code (`qr_card.php`)

1. Masuk ke menu `Presensi` → `Kartu QR Siswa`.
2. Tampilkan kartu QR digital pada layar ponsel Anda (atau cetak fisik kartu QR).
3. Saat tiba di sekolah atau di depan kelas, arahkan kode QR ke webcam/kamera pemindai yang dioperasikan oleh guru atau petugas piket sekolah.
4. Presensi tercatat secara instan tanpa perlu antre tanda tangan manual di atas kertas.

---

### E. Pengajuan Mandiri Surat Kesiswaan (`requests.php`)

1. Buka menu `Layanan Surat` → `Pengajuan Surat`.
2. Klik **"Buat Pengajuan Baru"**.
3. Pilih jenis surat yang dibutuhkan:
   - *Surat Keterangan Siswa Aktif* (untuk keperluan dinas, tunjangan gaji orang tua, atau visa).
   - *Surat Keterangan Berkelakuan Baik (SKBB)*.
   - *Surat Keterangan Lulus (SKL) Sementara*.
   - *Surat Rekomendasi Beasiswa / Perlombaan*.
   - *Surat Dispensasi Mengikuti Kegiatan Eksternal*.
4. Masukkan alasan kebutuhan surat dan unggah berkas pendukung (jika ada).
5. Pantau status pengajuan (`Menunggu` $\rightarrow$ `Diproses` $\rightarrow$ `Selesai`).
6. Begitu disetujui TU, klik **"Cetak Surat"** untuk mengunduh dokumen resmi berstempel dan ber-QR code verifikasi.

---

## 4. Etika & Integritas Digital Peserta Didik
- Menjaga kerahasiaan akun dan kata sandi login masing-masing.
- Menjunjung tinggi kejujuran akademik saat mengikuti ujian online (dilarang membuka tab lain atau berbagi token).
- Menghormati tenggat waktu pengumpulan tugas yang ditetapkan oleh dewan guru.
- Menggunakan fitur komunikasi internal secara sopan dan beretika kepada para pendidik.
