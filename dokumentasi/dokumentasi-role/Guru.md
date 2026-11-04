# 🟢 Panduan Operasional Peran: Guru (Tenaga Pendidik & Wali Kelas)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP**

---

## 1. Ringkasan Peran & Ruang Lingkup Pendidik

Peran **Guru** (*Tenaga Pendidik*) memegang tanggung jawab sentral dalam proses belajar mengajar (KBM), administrasi kurikulum pembelajaran, pencatatan agenda harian tatap muka, asesmen pembelajaran berbasis CBT, pemantauan kedisiplinan dan absensi siswa, pengelolaan buku nilai (*gradebook*), penyusunan rapor semester, monitoring siswa binaan (sebagai Wali Kelas), serta komunikasi konsultatif dengan orang tua siswa.

### 🎯 Kapabilitas Utama Peran Guru
1. **Jurnal Mengajar KBM Harian:** Pencatatan agenda tatap muka kelas, materi/topik pokok, pencapaian kompetensi, situasi kelas, rangkuman absensi siswa, dan dukungan guru piket/inval.
2. **Administrasi Perangkat Ajar & Kurikulum Merdeka:** Pengelolaan dan pengunggahan berkas modul ajar, RPP, silabus/ATP, program tahunan (prota), program semester (promes), serta KKTP dengan alur supervisi dari tim kurikulum/kepala sekolah.
3. **Presensi Mandiri Pendidik:** Sistem *Clock-In* masuk kerja dengan deteksi otomatis keterlambatan (melewati batas 07:15 WIB), *Clock-Out* pulang kerja, serta pengajuan izin/sakit/cuti disertai unggahan bukti surat dokter atau dinas.
4. **KBM & E-Learning Terpadu:** Distribusi materi pembelajaran digital multi-format (dokumen, video YouTube, Google Drive) dan penugasan terstruktur dengan tenggat waktu.
5. **Asesmen CBT & Analisis Butir Soal:** Pembuatan bank soal (PG & Esai), pengawasan ujian berbasis token dan *countdown timer*, *auto-grading*, penilaian manual esai, sistem remedial, serta **analisis butir soal** (indeks kesukaran $P$, efektivitas distraktor/pengecoh A/B/C/D, dan daya pembeda).
6. **Presensi Siswa Multi-Metode:** Presensi manual per rombel atau pemindaian kilat via webcam kamera (*QR Code Scanner*).
7. **Bimbingan Konseling & Karakter:** Pencatatan kedisiplinan berpoin dan rekam jejak prestasi akademik/non-akademik siswa.
8. **Dashboard & Monitoring Wali Kelas:** Pemantauan statistik kehadiran siswa binaan, kontrol tugas belum tuntas, monitoring akumulasi poin pelanggaran dan prestasi BK, pengisian catatan kepribadian rapor, serta **cetak rapor massal satu kelas** dengan *page-break* standar dinas.
9. **Peminjaman Fasilitas & Sarpras Mandiri:** Akses langsung meninjau ketersediaan barang/ruangan (proyektor, alat lab, sound system) dan mengajukan peminjaman sarpras secara terintegrasi.
10. **Publikasi Pengumuman & Berita:** Mempublikasikan pengumuman akademik ke kelas binaan serta menulis artikel berita sekolah.

---

## 2. Navigasi & Struktur Menu Guru

| Modul Pembelajaran | Halaman / Fitur | File Eksekusi | Deskripsi Tugas & Tanggung Jawab |
| :--- | :--- | :--- | :--- |
| **Presensi Mandiri** | Presensi Pendidik | `dashboard/presensi/teacher_attendance.php` | Catat Clock-In masuk, Clock-Out pulang, dan ajukan izin/sakit/cuti kerja. |
| **Administrasi KBM**| Jurnal Mengajar Harian | `dashboard/akademik/journal.php` | Agenda KBM tatap muka, materi pokok, catatan kelas, & cetak buku jurnal. |
| | Perangkat Ajar & RPP | `dashboard/akademik/curriculum.php` | Unggah Modul Ajar, RPP, Silabus, Prota, Promes, & pantau status supervisi. |
| | Jadwal Mengajar | `dashboard/akademik/timetable.php` | Melihat jadwal KBM mingguan kelas, jam pelajaran, dan ruangan. |
| | Bahan Ajar & Materi | `dashboard/akademik/materials.php` | Unggah modul bahan ajar (PDF/Doc/PPT), video YouTube, & link Google Drive. |
| | Tugas & Evaluasi | `dashboard/akademik/assignments.php` | Buat tugas ber-deadline, tinjau berkas siswa, beri nilai & catatan feedback. |
| | Buku Nilai Siswa | `dashboard/akademik/gradebook.php` | Rekapitulasi seluruh nilai tugas & ujian per mapel, cetak ledger nilai. |
| | E-Rapor Akademik | `dashboard/akademik/report_card.php` | Lihat e-rapor siswa perorangan dan cetak lembar hasil belajar. |
| **Fungsi Wali Kelas**| Dashboard Wali Kelas | `dashboard/akademik/homeroom.php` | Monitoring kehadiran, tugas, poin BK siswa binaan, & input catatan wali. |
| | Cetak Rapor Massal | `dashboard/akademik/report_card_bulk.php` | Cetak buku rapor seluruh siswa 1 kelas sekaligus format resmi dinas. |
| **Asesmen & CBT** | Modul Ujian CBT | `dashboard/Modul-ujian/exams.php` | Buat bank ujian/latihan (6 kategori), durasi, KKM, token, & acak soal. |
| | Bank & Editor Soal | `dashboard/Modul-ujian/exam_questions.php` | Input soal Pilihan Ganda (bobot skor, kunci, gambar, import CSV) & Esai. |
| | Hasil & Analisis Butir | `dashboard/Modul-ujian/exam_results.php` | Koreksi esai, pemberian remedial, dan analisis butir soal (indeks kesukaran). |
| | Cetak Kartu Peserta | `dashboard/Modul-ujian/exam_card.php` | Cetak kartu peserta ujian siswa lengkap dengan jadwal mata pelajaran. |
| **Presensi Siswa** | Presensi Harian Kelas | `dashboard/presensi/attendance.php` | Catat kehadiran siswa per jam pertemuan (Hadir, Sakit, Izin, Alpa). |
| | Scan QR Presensi | `dashboard/presensi/scan_qr.php` | Scan kehadiran kilat menggunakan webcam dari kartu barcode/QR siswa. |
| | Rekap Presensi Siswa | `dashboard/presensi/attendance_report.php`| Pantau persentase ketidakhadiran siswa dan cetak laporan absensi kelas. |
| **Layanan Sekolah** | Bimbingan Konseling | `dashboard/bk/counseling.php` | Catat pelanggaran tata tertib (sistem poin) & penghargaan prestasi siswa. |
| | Cetak Surat BK | `dashboard/bk/counseling_letter.php` | Cetak surat resmi panggilan/pemberitahuan orang tua terkait kedisiplinan. |
| | Peminjaman Sarpras | `dashboard/sarpras/inventory.php` | Cek ketersediaan proyektor/alat lab dan ajukan peminjaman fasilitas sekolah. |
| | Perpustakaan Digital | `dashboard/perpustakaan/books.php` | Eksplorasi katalog buku fisik, e-book, dan reservasi buku perpustakaan. |
| | Penggajian & Slip Gaji | `dashboard/keuangan/payroll.php` | Rincian gaji bulanan, honor jam mengajar, dan cetak slip gaji digital. |
| **Informasi & Chat**| Pengumuman Kelas | `dashboard/informasi/announcements.php` | Terbitkan pengumuman akademik terarah untuk siswa atau kelas tertentu. |
| | Tulis Berita Sekolah | `dashboard/informasi/news.php` | Tulis artikel / liputan kegiatan sekolah untuk publikasi portal sekolah. |
| | Pesan Konsultasi | `dashboard/pesan/messages.php` | Komunikasi dua arah langsung dengan siswa dan orang tua murid. |

---

## 3. Prosedur Operasional Standar (SOP) Guru

---

### A. Presensi Mandiri Pendidik (`teacher_attendance.php`)

1. **Clock-In Masuk Harian:**
   - Masuk ke menu `Presensi Mandiri Guru`.
   - Perhatikan indikator jam digital server (*Live Clock*).
   - Klik tombol hijau **"Clock-In Masuk"**.
   - Sistem secara otomatis mencatat waktu kehadiran:
     - Presensi sebelum atau tepat pukul **07:15 WIB** mendapatkan status **Hadir**.
     - Presensi melewati pukul **07:15 WIB** secara otomatis berstatus **Terlambat**.
2. **Clock-Out Pulang Harian:**
   - Saat jam mengajar/operasional sekolah telah selesai, klik tombol **"Clock-Out Pulang"**.
   - Sistem merekam jam kepulangan guru sebagai rekam jejak kedisiplinan kerja.
3. **Pengajuan Izin / Sakit / Cuti:**
   - Jika berhalangan hadir karena urusan keluarga, sakit, atau cuti dinas, klik tombol **"Ajukan Izin / Sakit / Cuti"**.
   - Pilih jenis status (`Izin`, `Sakit`, `Cuti`), masukkan tanggal dan keterangan alasan.
   - Unggah dokumen bukti pendukung (surat keterangan dokter, surat tugas, atau surat izin keluarga) dalam format PDF/JPG/PNG.
   - Pengajuan akan langsung tercatat pada buku rekap presensi dewan guru sekolah.

---

### B. Pengelolaan Jurnal Mengajar KBM Harian (`journal.php`)

1. **Pengisian Agenda Tatap Muka:**
   - Buka menu `Jurnal Mengajar KBM` → Klik tombol **"Tulis Jurnal Mengajar"**.
   - Pilih **Kelas** dan ketikkan **Mata Pelajaran** yang diajarkan.
   - Tetapkan **Tanggal** dan durasi waktu tatap muka (jam mulai s/d jam selesai).
   - Tuliskan **Materi Pokok / Topik Pembelajaran** yang disampaikan di kelas.
   - Isikan **Catatan Ketuntasan Kompetensi** (pencapaian indikator belajar dan daya serap materi).
   - Isikan **Rangkuman Presensi Siswa** (misal: *Hadir 32, Izin 1, Alpa 0*).
   - Isikan **Catatan Situasi Kelas** (misal: *Siswa aktif dalam diskusi kelompok, praktikum berjalan lancar*).
   - Pilih status pelaksanaan: `Terlaksana`, `Terkendala`, atau `Digantikan Guru Piket (Inval)`.
2. **Penanganan Guru Inval / Pengganti:**
   - Apabila guru berhalangan hadir dan jam mengajarnya diisi oleh guru piket, pilih status `Digantikan Guru Piket (Inval)`.
   - Pilih nama guru pengganti dari daftar dewan guru yang bertugas agar rekam jejak tatap muka tetap akuntabel.
3. **Cetak Agenda KBM:**
   - Guru dapat menyaring jurnal berdasarkan bulan, kelas, dan status KBM.
   - Klik tombol **"Cetak Jurnal KBM"** untuk mencetak format buku agenda mengajar resmi untuk arsip supervisi akademik kepala sekolah.

---

### C. Administrasi Kurikulum & Perangkat Ajar (`curriculum.php`)

1. **Pengunggahan Perangkat Ajar:**
   - Buka menu `Perangkat Ajar & RPP` → Klik **"Unggah Dokumen Ajar"**.
   - Masukkan informasi mata pelajaran, tingkat kelas (Fase/Kelas 10, 11, 12), tahun ajaran, dan semester.
   - Pilih Klasifikasi Dokumen:
     - **Modul Ajar**: Panduan KBM Kurikulum Merdeka lengkap dengan LKPD dan rubrik.
     - **RPP (Rencana Pelaksanaan Pembelajaran)**: RPP reguler atau satu lembar.
     - **Silabus / ATP**: Alur Tujuan Pembelajaran per fase.
     - **Program Tahunan (Prota)** & **Program Semester (Promes)**.
     - **Kriteria Ketercapaian Tujuan Pembelajaran (KKTP)**.
   - Pilih metode penyimpanan: **Unggah Berkas Langsung** (PDF/DOCX/XLSX) atau **Tautan Google Drive / Cloud**.
   - Klik **"Simpan Dokumen"**.
2. **Pengajuan Supervisi & Alur Revisi:**
   - Berkas yang diunggah berstatus awal `Draft`.
   - Klik tombol **"Ajukan Supervisi"** untuk mengirimkan dokumen ke tim Kurikulum / Kepala Sekolah.
   - Tim supervisi akan meninjau kelengkapan dokumen dan memberikan status:
     - **Disetujui**: Perangkat telah valid dan memenuhi standar pembelajaran nasional.
     - **Perlu Revisi**: Dokumen memerlukan perbaikan disertai catatan evaluasi dari tim kurikulum.

---

### D. Asesmen CBT & Analisis Butir Soal

#### 1. Konfigurasi Bank Ujian (`exams.php`)
- Klik **"Buat Ujian / Latihan Baru"**.
- Tentukan Klasifikasi Asesmen:
  - *Ujian Formal:* UTS, UKK, atau Ujian Harian.
  - *Latihan & Pengayaan:* Latihan Harian, Mingguan, atau Bulanan.
- Tentukan Durasi Ujian, Nilai KKM (*Passing Grade*), **Token Akses Ujian**, dan opsi **Acak Urutan Soal**.

#### 2. Pengelolaan Bank Soal (`exam_questions.php`)
- **Pilihan Ganda:** Ketik soal, unggah gambar pendukung jika ada, isi opsi A–D/E, tentukan kunci jawaban dan bobot skor.
- **Esai:** Masukkan pertanyaan uraian terbuka dan bobot nilai maksimal soal.
- **Import Massal Soal CSV:** Guru dapat menyiapkan puluhan soal dalam format spreadsheet CSV dan mengimpornya dalam hitungan detik.

#### 3. Analisis Butir Soal & Koreksi (`exam_results.php`)
- **Koreksi Esai:** Tinjau lembar jawaban siswa dan berikan skor esai secara manual.
- **Indeks Kesukaran Soal ($P$):**
  - Sistem otomatis menghitung persentase siswa yang menjawab benar setiap nomor soal.
  - Menampilkan klasifikasi otomatis:
    - $P > 0.70$: **Mudah** (soal dikuasai mayoritas peserta didik).
    - $0.30 \le P \le 0.70$: **Sedang** (tingkat kesulitan ideal).
    - $P < 0.30$: **Sukar** (memerlukan pendalaman materi / remedial klasikal).
- **Analisis Distraktor / Pengecoh:**
  - Grafik batang horizontal menampilkan jumlah siswa yang memilih masing-masing opsi (A, B, C, D).
  - Guru dapat mengevaluasi apakah pilihan pengecoh berfungsi efektif mengecoh siswa yang belum menguasai materi.
- **Pemberian Remedial:**
  - Guru dapat memberikan izin remedial kepada siswa yang belum mencapai KKM dengan satu klik (*Grant Remedial*).

---

### E. Peran Khusus: Dashboard & Administrasi Wali Kelas & E-Rapor

Bagi guru yang ditugaskan sebagai **Wali Kelas** rombel binaan maupun guru pengampu:

1. **Monitoring Kelas Real-Time (`homeroom.php`):**
   - **Rasio Kehadiran Kelas:** Pantau persentase hadir, sakit, izin, dan alpa seluruh siswa binaan.
   - **Tugas Menumpuk:** Pantau jumlah tugas mata pelajaran yang belum diserahkan oleh siswa kelas tersebut.
   - **Poin Kedisiplinan & Prestasi BK:** Pantau akumulasi catatan pelanggaran dan penghargaan prestasi siswa binaan secara proaktif sebelum memicu panggilan orang tua.
   - **Status Kenaikan Kelas Langsung:** Meninjau dan menentukan status kenaikan kelas (*Naik Kelas*, *Tinggal di Kelas*, *Lulus*, *Tidak Lulus*) secara cepat per siswa beserta kelas tujuannya.

2. **Pengisian E-Rapor Standar Kurikulum Merdeka (`report_card.php`):**
   - **Deskripsi Capaian Kompetensi Dua Sisi:** Sistem secara otomatis menyusun narasi standar e-Rapor Kemendikbudristek (*Capaian Tertinggi* dan *Perlu Peningkatan / Pendampingan*) berbasis KKM & interval nilai. Guru dan wali kelas dapat menyesuaikan (override) narasi capaian mapel secara custom jika diperlukan.
   - **Catatan Wali Kelas & Motivasi:** Menyediakan rekomendasi cerdas dari rekaman BK siswa secara otomatis untuk mempermudah penulisan catatan motivasi belajar.
   - **Kegiatan Ekstrakurikuler & Praktik Kerja Lapangan (PKL/Prakerin):** Menginput kegiatan ekskul serta rekam jejak PKL/magang dunia industri (DUDI) untuk jenjang SMK.
   - **Keputusan Kenaikan Kelas / Kelulusan:** Penentuan resmi status kelulusan / kenaikan kelas yang tercetak di lembar rapor semester akhir/genap lengkap dengan tanda tangan wali kelas dan kepala sekolah.

3. **Multi-Tab Dokumen Rapor & Berkas Lengkap (3-in-1 Bundle):**
   - **Lembar Nilai Akademik:** Rincian nilai akhir, KKM mapel, predikat, narasi dua sisi, catatan kehadiran, ekskul, PKL, dan keputusan kenaikan.
   - **Halaman Sampul (Cover):** Sampul resmi Rapor Hasil Belajar Kurikulum Merdeka bertanda tangan dan logo Tut Wuri Handayani.
   - **Biodata Peserta Didik (Buku Induk):** Keterangan lengkap diri siswa sesuai format 14 butir Dapodik/Buku Induk beserta pas foto.
   - **Dukungan Cetak Fleksibel:** Tombol *Cetak Lembar Aktif* untuk cetak per halaman dan *Cetak 1 Berkas Lengkap* untuk mencetak Cover, Biodata, dan Lembar Nilai sekaligus.

4. **Cetak Rapor Massal Kelas (`report_card_bulk.php`):**
   - Wali kelas dapat mencetak seluruh rapor siswa dalam satu kelas secara sekaligus dalam satu klik.
   - Dilengkapi pemilih kelas dinamis, semester, dan tahun ajaran fleksibel.
   - Format cetak secara otomatis memisahkan rapor antar-siswa menggunakan pemisah halaman (*CSS page-break: always*) sehingga siap langsung dicetak pada kertas A4 tanpa repot mencetak satu per satu.

---

### F. Peminjaman Fasilitas & Sarpras Mandiri (`inventory.php`)

1. Buka menu `Inventaris Sarpras` pada bilah navigasi kiri.
2. Tinjau ketersediaan barang inventaris sekolah (misal: Proyektor Epson EB-X500 di Ruang Multimedia atau Laptop di Lab Komputer).
3. Klik tombol **"Ajukan Peminjaman"**:
   - Pilih barang yang ingin dipinjam.
   - Tentukan tanggal peminjaman dan tanggal rencana pengembalian.
   - Masukkan keperluan/tujuan penggunaan (misal: *Presentasi KBM Matematika Kelas X MIPA 1*).
4. Pengajuan akan tercatat di sistem untuk disetujui oleh petugas sarana dan prasarana / staf tata usaha sekolah.

---

### G. Bimbingan Konseling & Komunikasi Siswa/Orang Tua

1. **Pencatatan Pelanggaran & Prestasi Siswa (`counseling.php`):**
   - Catat pelanggaran tata tertib siswa (berbasis sistem poin) atau rekam prestasi kejuaraan yang diraih siswa.
   - Apabila poin pelanggaran siswa melampaui batas peringatan, terbitkan surat panggilan resmi orang tua (`counseling_letter.php`).
2. **Pesan Konsultasi Privat (`messages.php`):**
   - Komunikasi langsung dengan siswa atau orang tua terkait kendala belajar, kehadiran, atau bimbingan akademik.
3. **Pengumuman Kelas (`announcements.php`):**
   - Guru dapat menerbitkan pengumuman akademik yang ditargetkan khusus untuk kelas yang diampunya.

---

### H. Geofencing GPS Presensi Mandiri Pendidik (`teacher_attendance.php`)

1. **Akurasi Lokasi & Live Radar GPS:**
   - Saat membuka menu Presensi Mandiri, peramban akan meminta izin akses lokasi (*Geolocation API*).
   - Radar GPS interaktif akan menghitung jarak lurus perangkat ke titik koordinat resmi sekolah menggunakan **Formula Haversine**.
   - Indikator status presensi:
     - **Dalam Radius Aman (≤ 150 meter):** Tombol Clock-In aktif berwarna hijau dan presensi diizinkan.
     - **Luar Radius (> 150 meter):** Tombol Clock-In otomatis terkunci dengan peringatan bahwa Anda berada di luar area sekolah.
2. **Rekam Jejak Koordinat:**
   - Setiap presensi masuk merekam titik koordinat lintang (*latitude*), bujur (*longitude*), dan estimasi jarak meter ke basis data, yang dapat ditinjau via tautan Google Maps.

---

### I. Integritas CBT Proctoring & Pengawasan Anti-Cheat (`exam_take.php` & `exam_results.php`)

1. **Pemantauan Integritas Ujian Siswa:**
   - Sistem ujian CBT kini dilengkapi **Browser Proctoring Engine** yang mengawasi siswa selama pengerjaan soal:
     - Siswa diwajibkan berada dalam mode layar penuh (*Fullscreen*).
     - Deteksi perpindahan tab atau jendela peramban (*Tab Blur / Visibility Change*).
     - Pemblokiran pintasan inspeksi kode (F12, Ctrl+Shift+I/J/C, Ctrl+U).
2. **Peringatan & Penguncian Otomatis (Auto-Lock):**
   - Siswa diberikan batas toleransi maksimal 3 kali pelanggaran.
   - Pada pelanggaran ke-3, ujian siswa langsung terkunci dan lembar jawaban otomatis terkirim dengan status *Terkunci (Auto-Locked)*.
3. **Tinjauan Hasil Ujian Guru:**
   - Pada halaman `exam_results.php`, guru dapat melihat kolom **Integritas Proctoring** dengan badge:
     - 🟢 **Bersih (0x):** Siswa mengerjakan secara tertib tanpa pindah tab.
     - 🟡 **Peringatan (1–2x):** Siswa sempat berpindah tab atau keluar layar penuh.
     - 🔴 **Terkunci Otomatis (3x):** Siswa melebihi batas toleransi kecurangan.

---

### J. Akses Penggajian & Cetak Slip Gaji Digital (`dashboard/keuangan/payroll.php`)

1. **Transparansi Kompensasi Pendidik:**
   - Melalui menu `Penggajian Guru (Payroll)`, guru dapat meninjau rincian penghasilan bulanan secara transparan:
     - **Gaji Pokok:** Standar dasar pendidik.
     - **Tunjangan Jabatan:** Tambahan tunjangan otomatis bagi guru yang mengemban amanah sebagai Wali Kelas.
     - **Honor Jam Mengajar (JP):** Akumulasi otomatis dari seluruh entri KBM yang diisi pada *Jurnal Mengajar* dikali tarif per jam sekolah.
     - **Potongan Presensi:** Potongan kedisiplinan otomatis jika terdapat alpa atau presensi di luar radius GPS sekolah.
2. **Notifikasi Pencairan WhatsApp:**
   - Saat bagian keuangan mencairkan gaji (status *Dibayar*), sistem secara otomatis mengirimkan notifikasi resmi ke nomor WhatsApp guru.
3. **Cetak Slip Gaji Digital Mandiri (`payroll_slip.php`):**
   - Guru dapat mengunduh dan mencetak slip gaji digital resmi berstandar dinas kapan saja, lengkap dengan teks terbilang rupiah, kop sekolah, dan stempel verifikasi QR Code digital.
