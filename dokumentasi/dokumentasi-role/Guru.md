# 🟢 Panduan Operasional Peran: Guru (Tenaga Pendidik & Wali Kelas)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP**

---

## 1. Ringkasan Peran & Ruang Lingkup Pendidik

Peran **Guru** (*Tenaga Pendidik*) memegang tanggung jawab sentral dalam proses belajar mengajar (KBM), asesmen pembelajaran, pemantauan kedisiplinan dan absensi siswa, pengelolaan buku nilai (*gradebook*), penyusunan rapor semester (sebagai Wali Kelas), serta komunikasi konsultatif dengan orang tua siswa.

### 🎯 Kapabilitas Utama Peran Guru
- **KBM & E-Learning:** Distribusi materi pembelajaran digital dan pengelolaan penugasan terstruktur dengan tenggat waktu.
- **Asesmen & Ujian Online:** Pembuatan bank soal (PG & Esai), pengawasan ujian berbasis token dan countdown timer, auto-grading, penilaian manual esai, dan sistem remedial.
- **Presensi Multi-Metode:** Presensi manual per rombel atau pemindaian kilat via webcam kamera (*QR Code Scanner*).
- **Pembinaan Karakter & BK:** Pencatatan kedisiplinan berpoin dan rekam jejak prestasi akademik/non-akademik siswa.
- **Fungsi Khusus Wali Kelas:** Input evaluasi kepribadian dan catatan kegiatan ekstrakurikuler pada rapor digital siswa.

---

## 2. Navigasi & Struktur Menu Guru

| Modul Pembelajaran | Halaman / Fitur | File Eksekusi | Deskripsi Tugas & Tanggung Jawab |
| :--- | :--- | :--- | :--- |
| **Akademik** | Jadwal Mengajar | `dashboard/akademik/timetable.php` | Melihat jadwal KBM mingguan kelas, jam pelajaran, dan ruangan. |
| | Materi Pembelajaran | `dashboard/akademik/materials.php` | Unggah modul bahan ajar (PDF/Doc/PPT), video YouTube, & link Google Drive. |
| | Tugas & Pengumpulan | `dashboard/akademik/assignments.php` | Buat tugas ber-deadline, tinjau pengumpulan file siswa, beri nilai & catatan koreksi. |
| | Buku Nilai (Gradebook)| `dashboard/akademik/gradebook.php` | Rekapitulasi seluruh nilai tugas & ujian per mapel, cetak & ekspor spreadsheet. |
| | Rapor Digital Semester| `dashboard/akademik/report_card.php` | Input catatan wali kelas & capaian ekstrakurikuler serta cetak rapor siswa. |
| | Kalender Akademik | `dashboard/akademik/calendar.php` | Pantau kalender pendidikan, agenda KBM, jadwal UTS/UAS, dan hari libur. |
| **Modul Ujian** | Manajemen Ujian | `dashboard/Modul-ujian/exams.php` | Buat bank ujian/latihan (6 kategori), tentukan durasi, KKM, token, & acak soal. |
| | Editor Soal Asesmen | `dashboard/Modul-ujian/exam_questions.php` | Input soal Pilihan Ganda (bobot skor, kunci jawaban, gambar) dan soal Esai. |
| | Hasil & Remedial | `dashboard/Modul-ujian/exam_results.php` | Evaluasi jawaban esai, analisis ketuntasan belajar, & pemberian remedial. |
| | Cetak & Kartu Ujian | `dashboard/Modul-ujian/exam_print.php` | Cetak rekap nilai hasil ujian per kelas dan cetak kartu peserta ujian. |
| **Presensi** | Input Presensi Harian | `dashboard/presensi/attendance.php` | Catat kehadiran siswa per jam pertemuan (Hadir, Sakit, Izin, Alpa). |
| | Scan QR Presensi | `dashboard/presensi/scan_qr.php` | Scan kehadiran kilat menggunakan webcam dari kartu barcode/QR siswa. |
| | Rekap & Grafik Absensi | `dashboard/presensi/attendance_report.php`| Pantau persentase ketidakhadiran siswa dan cetak laporan absensi kelas. |
| **Bimbingan (BK)** | Rekam Disiplin & Prestasi| `dashboard/bk/counseling.php` | Catat pelanggaran tata tertib (sistem poin) & penghargaan prestasi siswa. |
| | Cetak Surat BK | `dashboard/bk/counseling_letter.php` | Cetak surat resmi panggilan/pemberitahuan orang tua terkait kedisiplinan. |
| **Komunikasi** | Pesan Konsultasi | `dashboard/pesan/messages.php` | Komunikasi dua arah langsung dengan siswa dan orang tua murid. |

---

## 3. Prosedur Operasional Standar (SOP) Guru

### A. Pengelolaan E-Learning & Tugas Pembelajaran

#### 1. Distribusi Materi Ajar (`materials.php`)
- Masuk ke `Modul Akademik` → `Materi Pembelajaran`.
- Klik tombol **"Tambah Materi"**.
- Masukkan Judul Materi, Mata Pelajaran yang diampu, dan Kelas target.
- Pilih Jenis Sumber Belajar:
  - **Upload Berkas:** Unggah modul bahan ajar (PDF, Word, PowerPoint, ZIP) maks. 5 MB.
  - **Tautan Eksternal:** Masukkan URL materi (misal: Google Drive, SlideShare, artikel ilmiah).
  - **Video Pembelajaran:** Masukkan tautan video YouTube (sistem secara otomatis menyediakan pratinjau pemutar video).
- Tuliskan ringkasan deskripsi materi dan klik **"Simpan & Publikasikan"**.
- Guru dapat memantau efektivitas materi melalui penghitung unduhan (*download counter*) siswa.

#### 2. Penugasan Terstruktur & Penilaian Siswa (`assignments.php`)
- Klik **"Buat Tugas Baru"**.
- Masukkan Judul Tugas, Mata Pelajaran, Petunjuk Pengerjaan Lengkap, dan Tanggal Batas Waktu (*Due Date*).
- Lampirkan lembar kerja soal (opsional).
- **Memeriksa & Menilai Jawaban Siswa:**
  - Klik tombol **"Lihat Pengumpulan"** pada tugas terkait.
  - Sistem menampilkan tabel status pengerjaan seluruh siswa: *Sudah Mengumpulkan* (disertai tanggal/jam) atau *Belum*.
  - Unduh berkas jawaban yang dikirimkan siswa.
  - Masukkan **Nilai Skor** (skala 0–100) dan berikan **Catatan Evaluasi / Feedback Guru** (misal: *Analisis sudah baik, perbaiki penulisan sumber sitasi*).
  - Nilai yang disimpan otomatis masuk ke kalkulasi Buku Nilai (*Gradebook*).

---

### B. Pengelolaan Ujian Online & Sistem Remedial

#### 1. Konfigurasi Bank Ujian (`exams.php`)
- Klik **"Buat Ujian / Latihan Baru"**.
- Tentukan Klasifikasi Asesmen dari 6 Kategori yang tersedia:
  - **Kategori Ujian Formal:** *Ujian Tengah Semester (UTS)*, *Ujian Kenaikan Kelas (UKK)*, atau *Ujian Harian*.
  - **Kategori Latihan & Pengayaan:** *Latihan Harian*, *Latihan Mingguan*, atau *Latihan Bulanan*.
- Masukkan Mata Pelajaran, Kelas Target, dan KKM / Passing Grade (contoh: `75`).
- Tentukan **Durasi Waktu** (menit). Sistem akan mengunci dan menjalankan *countdown timer* saat siswa mulai mengerjakan.
- **Fitur Keamanan Ujian:**
  - **Token Akses Ujian:** Buat kode token unik (misal: `BIO2026`). Siswa tidak dapat membuka soal sebelum guru membagikan token di ruang kelas.
  - **Acak Urutan Soal (Randomize):** Centang opsi acak urutan soal untuk meminimalisasi kecurangan antar peserta didik.

#### 2. Input & Editor Soal (`exam_questions.php`)
- Buka bank ujian yang dibuat → Klik **"Kelola Soal"**.
- **Soal Pilihan Ganda (PG):**
  - Ketik teks pertanyaan dan unggah gambar ilustrasi soal (jika soal eksak/diagram).
  - Masukkan opsi jawaban A, B, C, D, (dan E).
  - Pilih Kunci Jawaban Benar dan tentukan Bobot Nilai per soal.
  - *Sistem mengoreksi soal PG secara otomatis (Auto-Grading) begitu siswa menyelesaikan ujian.*
- **Soal Esai:**
  - Ketik pertanyaan esai terbuka dan tentukan bobot maksimal nilai.
  - *Guru akan mengoreksi soal esai secara manual pada lembar penilaian ujian.*

#### 3. Evaluasi Hasil & Fasilitas Remedial (`exam_results.php`)
- Buka tab **"Hasil Ujian"**.
- Tinjau skor pengerjaan seluruh siswa kelas: Nilai PG (otomatis) + Nilai Esai (manual) = Skor Akhir.
- Sistem secara otomatis menandai status: **Lulus** (Skor $\ge$ KKM) atau **Remedial** (Skor $<$ KKM).
- **Pemberian Kesempatan Remedial:**
  - Guru dapat mengklik tombol **"Beri Akses Remedial"** untuk siswa tertentu.
  - Siswa yang bersangkutan diberikan satu kesempatan pengerjaan ulang guna memperbaiki capaian kompetensi dasarnya.
- Cetak lembar berita acara nilai ujian atau ekspor ke berkas Excel/Spreadsheet.

---

### C. Manajemen Presensi Siswa (`attendance.php`)

1. **Input Presensi Harian Manual:**
   - Pilih Kelas, Mata Pelajaran, dan Tanggal KBM.
   - Tetapkan status per siswa: **H (Hadir)**, **S (Sakit)**, **I (Izin)**, atau **A (Alpa/Tanpa Keterangan)**.
   - Klik **"Simpan Presensi"**.
   - *Catatan:* Jika siswa telah memiliki permohonan surat sakit/izin resmi yang disetujui Staf TU, status kehadirannya sudah otomatis terisi tanpa perlu diubah lagi.
2. **Scan Presensi Cepat via Webcam (`scan_qr.php`):**
   - Guru membuka halaman Scan QR di perangkat laptop/HP.
   - Izinkan akses kamera/webcam.
   - Siswa memperlihatkan kartu QR Code siswa (`qr_card.php`).
   - Sistem membaca kode secara instan (< 1 detik per siswa) dan mencatat status **Hadir** dengan stempel waktu presensi.

---

### D. Bimbingan Konseling & Disiplin Siswa (`counseling.php`)

1. **Pencatatan Pelanggaran Kedisiplinan:**
   - Jika terdapat pelanggaran tata tertib sekolah, guru dapat mencatat nama siswa, jenis pelanggaran, dan kategori.
   - Sistem mencatat poin pelanggaran secara akumulatif.
2. **Pencatatan Prestasi & Penghargaan:**
   - Catat capaian prestasi siswa (kejuaraan OSN, O2SN, FLS2N, pidato, lomba tahfidz, dll.).
   - Dokumentasikan tingkat penghargaan (sekolah, kota/kabupaten, provinsi, nasional, internasional).
3. **Penerbitan Surat Peringatan / Pemanggilan Wali Murid (`counseling_letter.php`):**
   - Apabila poin pelanggaran siswa melampaui batas toleransi, guru/guru BK dapat langsung mencetak lembar surat dinas resmi panggilan orang tua siswa berstempel dan berkop sekolah.

---

### E. Peran Tambahan Khusus: Wali Kelas (`report_card.php`)

Bagi guru yang ditugaskan sebagai **Wali Kelas** pada panel `classes.php`:
1. Membuka menu `Rapor Digital Siswa`.
2. Meninjau nilai akumulasi seluruh mata pelajaran siswa rombel binaannya.
3. Mengisi **Catatan Perkembangan Kepribadian Wali Kelas** (catatan akhlak, motivasi, dan evaluasi belajar).
4. Mengisi catatan **Kegiatan Ekstrakurikuler** (nama ekskul, peran, dan predikat nilai A/B/C).
5. Mencetak **Buku Rapor Semester** resmi lengkap dengan tanda tangan Kepala Sekolah dan Wali Kelas.
