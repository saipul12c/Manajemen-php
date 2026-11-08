# 🟢 Panduan Operasional Peran: Guru (Tenaga Pendidik, Wali Kelas & Pembina Ekskul)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP v1.3.0**

---

## 1. Ringkasan Peran & Ruang Lingkup Pendidik

Peran **Guru** (*Tenaga Pendidik*) memegang posisi strategis dalam ekosistem **Manajemen-PHP** yang mencakup empat pilar tugas profesional:
1. **Pendidik Mata Pelajaran (KBM & Asesmen):** Perencanaan pembelajaran Kurikulum Merdeka, pengisian agenda Jurnal Mengajar KBM harian, distribusi materi e-learning terpadu, pelaksanaan asesmen CBT berproctoring cerdas, analisis butir soal, dan rekapitulasi buku nilai (*gradebook*).
2. **Wali Kelas (Homeroom Teacher):** Pengawasan rombongan belajar (rombel) binaan secara holistik, monitoring tugas siswa yang belum tuntas, pemantauan poin BK, evaluasi catatan kepribadian, penetapan status kenaikan kelas, serta pencetakan e-Rapor massal satu kelas siap cetak dinas.
3. **Fasilitator Projek Penguatan Profil Pelajar Pancasila (P5):** Pemetaan tema projek, dimensi, elemen, sub-elemen, pelaksanaan penilaian rubrik fase 4 skala (MB, SB, BSH, SAB), serta penerbitan Rapor Projek P5 resmi standar BSKAP Kemendikbudristek.
4. **Guru Pembina Ekstrakurikuler:** Pengelolaan klub bakat minat, approval pendaftaran anggota siswa, presensi mingguan kilat *"Set Hadir Semua"*, penilaian predikat rapor semester (A/B/C/D), kurasi Hall of Fame prestasi kejuaraan, serta penerbitan Piagam Penghargaan ber-QR TTE.

### 🎯 Kapabilitas Utama & Pembaruan Sistem v1.3.0
- **Presensi Mandiri Berbasis Geofencing GPS:** Pencatatan Clock-In dan Clock-Out real-time dengan live clock server dan kalkulasi radius Haversine ($\le 150\text{ meter}$) dari koordinat sekolah, serta pengajuan cuti/izin/sakit online berlampiran surat dokter/dinas.
- **Integritas Ujian CBT & Proctoring Anti-Cheat:** Pengawasan otomatis fullscreen, deteksi tab-switch/minimize (auto-lock pada pelanggaran ke-3), blokir inspect element, serta analisis butir soal (indeks kesukaran $P$ dan efektivitas distraktor A–D).
- **Radar Profil Terpadu Siswa 360° (`student_360.php`):** Potret holistik perkembangan akademik, presensi, poin kedisiplinan & prestasi BK, tagihan SPP, dan riwayat ekskul siswa binaan dalam satu layar.
- **Kredensial, Gelar Akademik & Tanda Tangan Elektronik (TTE):** Pengelolaan NIP/NUPTK, gelar akademik (`title_degree`), scan tanda tangan digital tersanitasi XSS atau generator SVG resmi, dengan efek tinta realistis `mix-blend-mode: multiply` dan proteksi anti-scraping.
- **Preservasi Cetak Berkas Kedinasan:** Dukungan CSS `@media print` (`print-color-adjust: exact`) dan generator QR Code vektor offline fallback (air-gapped ready) pada seluruh dokumen resmi (Rapor Merdeka, Rapor P5, Slip Gaji, Ledger Nilai, dsb.).

---

## 2. Navigasi & Struktur Menu Guru

| Kelompok Menu | Halaman / Fitur | File Eksekusi | Deskripsi Tugas & Tanggung Jawab |
| :--- | :--- | :--- | :--- |
| **Menu Utama** | Beranda Dasbor | `dashboard/index.php` | Jadwal mengajar hari ini, tugas siswa yang perlu dikoreksi, & notifikasi real-time. |
| | Pasang Aplikasi (Hub) | `dashboard/apps_hub.php` | Unduh 3 aplikasi resmi: CBT Kiosk Screen, Presensi Geofencing GPS, & E-Perpus. |
| | Kalender Akademik | `dashboard/akademik/calendar.php` | Meninjau agenda kegiatan sekolah, jadwal penilaian semester, & hari efektif KBM. |
| | Pengumuman Sekolah | `dashboard/informasi/announcements.php` | Publikasi pengumuman untuk kelas yang diampu atau seluruh siswa sekolah. |
| | Berita & Artikel | `dashboard/informasi/news.php` | Tulis artikel, liputan kegiatan kelas/lomba, & kirim draft ke redaksi sekolah. |
| | Pesan & Konsultasi | `dashboard/pesan/messages.php` | Konsultasi belajar privat dua arah dengan siswa binaan dan orang tua murid. |
| **Pembelajaran & KBM**| Jadwal Mengajar | `dashboard/akademik/timetable.php` | Jadwal tatap muka mingguan, alokasi jam pelajaran (JP), rombel, & ruangan. |
| | Presensi Kehadiran Siswa| `dashboard/presensi/attendance.php` | Input absensi kelas (Hadir, Sakit, Izin, Alpa) dan rekapitulasi kehadiran rombel. |
| | Scan QR Presensi | `dashboard/presensi/scan_qr.php` | Pindai kartu QR Code / kartu pelajar siswa secara kilat menggunakan kamera perangkat. |
| | Presensi Mandiri Pendidik| `dashboard/presensi/teacher_attendance.php`| Smart Clock-In & Clock-Out berbasis radius GPS dan pengajuan izin/sakit/cuti online. |
| | Jurnal Mengajar KBM | `dashboard/akademik/journal.php` | Agenda KBM harian, materi pokok, catatan kelas, guru inval piket, & cetak buku jurnal. |
| | Perangkat Ajar & RPP | `dashboard/akademik/curriculum.php` | Unggah Modul Ajar, Silabus/ATP, Prota, Promes, KKTP, & pantau status supervisi KS. |
| | Bahan Ajar & Modul | `dashboard/akademik/materials.php` | Unggah materi pelajaran digital multi-format (PDF, PPT, Word, YouTube, Drive). |
| | Tugas & Evaluasi | `dashboard/akademik/assignments.php` | Buat penugasan berbatas waktu, periksa submission berkas, beri skor & feedback. |
| | Buku Nilai Siswa | `dashboard/akademik/gradebook.php` | Rekapitulasi ledger nilai tugas dan ujian per mapel, cetak buku leger nilai dinas. |
| | E-Rapor Akademik | `dashboard/akademik/report_card.php` | Input capaian pembelajaran dua sisi, transkrip nilai rapor, & cetak lembar hasil belajar. |
| | Rapor Projek P5 | `dashboard/akademik/p5_projects.php` | Penilaian dimensi & elemen projek P5, rubrik 4 skala, dan cetak lembar Rapor P5. |
| | Ekskul & Prestasi | `dashboard/ekskul/index.php` | Presensi mingguan pembina, input predikat rapor ekskul, & Hall of Fame prestasi. |
| | Dashboard Wali Kelas | `dashboard/akademik/homeroom.php` | Monitoring rombel binaan, catatan kepribadian, & cetak rapor massal 1 rombel. |
| **Ujian & CBT** | Modul Ujian CBT | `dashboard/Modul-ujian/exams.php` | Buat bank ujian/latihan (6 kategori), alokasi durasi, KKM, token, & acak urutan soal. |
| | Bank & Editor Soal | `dashboard/Modul-ujian/exam_questions.php`| Input butir soal Pilihan Ganda & Esai, unggah gambar/audio, dan impor CSV massal. |
| | Hasil & Analisis Butir | `dashboard/Modul-ujian/exam_results.php` | Koreksi manual esai, izin remedial, analisis kesukaran butir ($P$) & daya pengecoh. |
| | Kartu & Berita Acara | `dashboard/Modul-ujian/exam_card.php` | Cetak kartu peserta asesmen, daftar hadir (DHPU), dan Berita Acara Ruang CBT. |
| **Layanan Sekolah** | Bimbingan Konseling (BK)| `dashboard/bk/counseling.php` | Pencatatan rekam poin pelanggaran tata tertib dan rekomendasi penghargaan siswa. |
| | Peminjaman Sarpras | `dashboard/sarpras/inventory.php` | Cek ketersediaan proyektor/alat laboratorium dan ajukan peminjaman sarana dinas. |
| | Perpustakaan Digital | `dashboard/perpustakaan/books.php`| Eksplorasi koleksi katalog buku fisik, baca e-book layar penuh, & reservasi buku. |
| | Penggajian (Payroll) | `dashboard/keuangan/payroll.php` | Rincian gaji bulanan, honor jam mengajar tatap muka, dan cetak slip gaji digital. |
| **Akun Saya** | Profil Pengguna | `dashboard/profile.php` | Pasfoto profil, penulisan gelar, upload scan TTE/SVG, dan perbarui kata sandi. |

---

## 3. Prosedur Operasional Standar (SOP) Guru

### A. Presensi Mandiri Pendidik Berbasis Geofencing GPS (`teacher_attendance.php`)
1. **Clock-In Masuk Harian:**
   - Buka menu `Presensi Mandiri Guru`. Izinkan peramban mengakses lokasi perangkat (*Geolocation API*).
   - Radar GPS sistem secara otomatis menghitung jarak lurus perangkat ke titik koordinat resmi sekolah menggunakan **Formula Haversine**.
   - **Ketentuan Status Presensi:**
     - **Dalam Radius Aman ($\le 150\text{ meter}$):** Tombol hijau **"Clock-In Masuk"** aktif.
       - Presensi sebelum/pada pukul **07:15 WIB** $\rightarrow$ Status **Hadir**.
       - Presensi melewati pukul **07:15 WIB** $\rightarrow$ Status **Terlambat**.
     - **Luar Radius ($> 150\text{ meter}$):** Tombol Clock-In otomatis terkunci dengan peringatan bahwa Anda berada di luar radius operasional sekolah.
2. **Clock-Out Pulang Harian:**
   - Setelah jam dinas/mengajar selesai, klik tombol **"Clock-Out Pulang"** dalam radius sekolah.
3. **Pengajuan Izin / Sakit / Cuti Kerja:**
   - Jika berhalangan hadir, klik tombol **"Ajukan Izin / Sakit / Cuti"**.
   - Pilih jenis izin, masukkan rentang tanggal dan alasan ketidakhadiran.
   - Unggah dokumen bukti (Surat Keterangan Dokter, Surat Tugas Dinas Luar, atau Surat Cuti) dalam format PDF/JPG/PNG (maks. 5MB).
   - Pengajuan langsung tercatat pada buku rekap presensi dewan guru dan diverifikasi bagian administrasi.

---

### B. Jurnal Mengajar KBM & Otomatisasi Honor Mengajar (`journal.php`)
1. **Pencatatan Agenda Tatap Muka Harian:**
   - Buka menu `Jurnal Mengajar KBM` → Klik **"Tulis Jurnal Mengajar"**.
   - Pilih Kelas, Mata Pelajaran, Tanggal KBM, dan durasi jam pelajaran (JP) (contoh: *07:30 s/d 09:00 WIB*).
   - Tuliskan **Materi Pokok / Capaian Pembelajaran** yang disampaikan.
   - Masukkan **Catatan Ketuntasan Kompetensi** dan **Rangkuman Presensi Siswa** (misal: *Hadir 34, Izin 1, Sakit 1*).
   - Masukkan **Catatan Situasi Kelas** (misal: *Diskusi kelompok aktif, asesmen formatif berjalan lancar*).
   - Tetapkan Status Pelaksanaan: `Terlaksana`, `Terkendala`, atau `Digantikan Guru Piket (Inval)`.
2. **Penanganan Guru Inval / Pengganti:**
   - Jika guru utama berhalangan dan jam tatap muka diampu guru piket, pilih status `Digantikan Guru Piket (Inval)` dan pilih nama guru pengganti yang bertugas agar rekam jejak KBM tetap akuntabel.
3. **Dampak Langsung ke Modul Penggajian (Payroll):**
   - Setiap entri jurnal KBM berstatus *Terlaksana* secara otomatis dihitung jam tatap mukanya ke dalam komponen **Honor Jam Mengajar (JP)** pada penggajian bulanan guru di menu `keuangan/payroll.php`.
4. **Cetak Buku Jurnal KBM:**
   - Saring jurnal berdasarkan bulan atau rombel kelas, lalu klik **"Cetak Jurnal KBM"** untuk mencetak format buku agenda mengajar resmi siap supervisi Kepala Sekolah dan Pengawas Dinas.

---

### C. Kurikulum Merdeka & Projek Profil Pelajar Pancasila (P5)
1. **Administrasi Perangkat Ajar (`curriculum.php`):**
   - Unggah dokumen: **Modul Ajar**, **Alur Tujuan Pembelajaran (ATP)**, **Silabus**, **Program Tahunan (Prota)**, **Program Semester (Promes)**, atau **KKTP**.
   - Pilih metode penyimpanan: Unggah berkas dokumen (PDF/Word/Excel) atau tautkan berkas Google Drive / Cloud Storage.
   - Klik **"Ajukan Supervisi"** untuk mengirimkan dokumen ke pimpinan kurikulum hingga berstatus *Disetujui*.
2. **Pelaksanaan Penilaian Projek P5 (`p5_projects.php` & `p5_assessment.php`):**
   - Guru yang ditunjuk sebagai Koordinator / Fasilitator Projek P5 membuka menu `Rapor Projek P5`.
   - Pilih projek aktif (dari 8 tema resmi Kemendikbudristek: *Gaya Hidup Berkelanjutan, Kearifan Lokal, Bhinneka Tunggal Ika, Bangunlah Jiwa dan Raganya, Suara Demokrasi, Rekayasa dan Teknologi, Kewirausahaan, Kebekerjaan*).
   - Buka lembar penilaian projek per rombel siswa.
   - Berikan penilaian rubrik fase perkembangan peserta didik menggunakan 4 skala standar BSKAP:
     - **MB (Mulai Berkembang):** Peserta didik mulai menunjukkan keterlibatan dengan bimbingan fasilitator.
     - **SB (Sedang Berkembang):** Peserta didik mampu menampilkan capaian kompetensi dasar projek.
     - **BSH (Berkembang Sesuai Harapan):** Peserta didik mencapai target dimensi dan elemen secara konsisten.
     - **SAB (Sangat Berkembang):** Peserta didik melampaui target fase dan mampu menjadi teladan bagi rekan sebaya.
   - Tuliskan narasi **Catatan Proses Fasilitator** yang menggambarkan keaktifan dan refleksi projek siswa.
3. **Penerbitan & Cetak Rapor Projek P5 (`p5_report.php`):**
   - Klik tombol **"Cetak Rapor P5"** (tersedia opsi perorangan atau massal 1 rombel).
   - Dokumen dicetak pada kertas A4 standar dinas, lengkap dengan matriks deskripsi capaian dimensi P5, catatan proses fasilitator, pengesahan Kepala Sekolah, serta stempel digital ber-QR Code validasi.

---

### D. Peran Guru Pembina Ekstrakurikuler (`dashboard/ekskul/`)
Bagi guru yang mengemban tugas sebagai **Pembina Ekstrakurikuler** (Pramuka, PMR, Paskibra, Futsal, Basket, Robotik, Karya Ilmiah, Paduan Suara, Rohis, Seni Musik, dsb.):
1. **Verifikasi Pendaftaran Anggota Siswa:**
   - Masuk ke menu `Ekskul & Prestasi` → Pilih klub yang dibina.
   - Buka tab **Pendaftaran**. Tinjau siswa yang mengajukan permohonan bergabung, lalu klik **"Setujui"** atau **"Tolak"**.
2. **Lembar Presensi Mingguan Kilat (*Attendance Sheet*):**
   - Buka tab **Presensi Latihan Mingguan**.
   - Tentukan tanggal latihan dan topik kegiatan.
   - **Fitur Kilat "Set Hadir Semua":** Cukup klik tombol satu-klik *"Set Hadir Semua"*, sistem secara otomatis mengubah seluruh anggota menjadi Hadir. Pembina cukup mengubah secara spesifik siswa yang Sakit, Izin, atau Alpa.
3. **Penilaian Predikat Rapor Semester:**
   - Menjelang akhir semester, buka tab **Nilai Rapor Anggota**.
   - Tetapkan predikat nilai: **A (Sangat Baik)**, **B (Baik)**, **C (Cukup)**, atau **D (Kurang)**.
   - Tuliskan narasi catatan pembina (misal: *Sangat aktif dalam kegiatan latihan rutin, disiplin dan berkontribusi meraih Juara 2 Tingkat Kabupaten*).
   - **Otomatisasi Sistem:** Nilai predikat dan catatan pembina ini secara otomatis tersinkronisasi dan tampil di **Bagian C (Ekstrakurikuler) pada E-Rapor Akademik Siswa** tanpa perlu entri ulang oleh wali kelas!
4. **Dokumentasi Hall of Fame Prestasi & Sertifikat Ber-QR TTE:**
   - Rekam prestasi kejuaraan siswa binaan melalui menu `achievements.php` (pilih tingkat kejuaraan dari Sekolah hingga Internasional, nama perlombaan, peringkat, dan unggah scan sertifikat asli).
   - Ekspor laporan prestasi dalam format CSV yang telah disesuaikan dengan borang akreditasi sekolah (BAN-SM).
   - Terbitkan dan cetak **Piagam Penghargaan Resmi Sekolah** (`print_certificate.php`) berformat A4 berbingkai elegan, bertanda tangan pimpinan, dan ber-QR Code verifikasi keaslian dokumen.

---

### E. Asesmen CBT & Integritas Proctoring Anti-Cheat
1. **Konfigurasi Ujian (`exams.php`):**
   - Tentukan Klasifikasi: UTS, UKK, Ujian Harian, atau Latihan.
   - Atur Durasi Menit, KKM, **Token Akses Ujian**, serta opsi **Acak Soal**.
2. **Bank Soal & Impor CSV (`exam_questions.php`):**
   - Buat soal Pilihan Ganda (bobot nilai, kunci, unggah gambar/audio) dan Esai.
   - Manfaatkan fitur **Impor CSV** untuk mengunggah ratusan butir soal secara instan.
3. **Pengawasan Integritas Proctoring (`exam_results.php`):**
   - Selama pengerjaan, sistem proctoring mengawasi siswa:
     - Wajib Fullscreen.
     - Deteksi perpindahan tab / jendela peramban (*Tab Blur / Visibility Change*).
     - Siswa diberi toleransi 3 kali peringatan sebelum lembar ujian otomatis terkunci (*Auto-Locked*).
   - Pada halaman hasil ujian, guru dapat memantau kolom **Integritas Proctoring**:
     - 🟢 **Bersih (0x):** Siswa mengerjakan secara jujur tanpa beralih layar.
     - 🟡 **Peringatan (1–2x):** Siswa terdeteksi sempat berpindah tab.
     - 🔴 **Terkunci Otomatis (3x):** Pelanggaran maksimal; sistem langsung mengirimkan jawaban apa adanya.
4. **Analisis Butir Soal Cerdas:**
   - **Indeks Tingkat Kesukaran ($P$):** Menghitung rasio jawaban benar:
     - $P > 0.70$: Kategori **Mudah**.
     - $0.30 \le P \le 0.70$: Kategori **Sedang (Ideal)**.
     - $P < 0.30$: Kategori **Sukar** (rekomendasi remedial klasikal).
   - **Analisis Distraktor Pengecoh:** Grafik batang persentase pemilihan opsi A, B, C, D untuk mengevaluasi efektivitas opsi pengecoh soal.
   - **Pemberian Remedial:** Berikan izin ujian remedial 1-klik bagi siswa di bawah KKM.

---

### F. Peran Khusus: Dashboard & Administrasi Wali Kelas (`homeroom.php`)
Bagi pendidik yang ditunjuk sebagai **Wali Kelas**:
1. **Radar Pemantauan Kelas Terpadu:**
   - Pantau persentase absensi rombel binaan.
   - Radar tugas menumpuk: cek siswa yang belum menyerahkan tugas mata pelajaran tertentu secara lintas-guru.
   - Rekam jejak poin kedisiplinan dan prestasi BK siswa secara dini sebelum memicu pemanggilan orang tua.
   - Penentuan status kenaikan kelas (*Naik Kelas*, *Tinggal di Kelas*, *Lulus*) beserta rombel kelas tujuan.
2. **Pengisian E-Rapor Kurikulum Merdeka (`report_card.php`):**
   - Narasi Capaian Pembelajaran Dua Sisi: Sistem otomatis menyusun deskripsi *Capaian Tertinggi* dan *Perlu Peningkatan / Pendampingan* berbasis capaian nilai akhir. Guru/Wali kelas dapat menyesuaikan narasi capaian jika dibutuhkan.
   - Rekomendasi Cerdas Catatan Wali Kelas: Rekam jejak poin BK ditarik otomatis sebagai referensi penulisan catatan motivasi belajar.
   - Multi-Tab Dokumen Rapor Lengkap:
     - **Cover:** Sampul resmi rapor dengan logo Tut Wuri Handayani dan nomor induk.
     - **Biodata Dapodik:** Format 14 butir buku induk peserta didik beserta pasfoto.
     - **Lembar Capaian Nilai:** Rincian nilai akhir, narasi capaian, presensi, ekskul, dan tanda tangan pengesahan.
3. **Cetak E-Rapor Massal Satu Kelas (`report_card_bulk.php`):**
   - Wali kelas dapat mencetak seluruh buku rapor siswa satu rombel dalam 1 klik.
   - Format cetak otomatis disisipkan pemisah halaman dinas (*CSS page-break: always*) berstandar ukuran kertas A4 sehingga dokumen tidak terpotong saat dicetak atau disimpan ke PDF.

---

### G. Pengaturan Profil, Kredensial & Tanda Tangan Digital Pendidik (`profile.php`)
1. Buka menu `Profil Pengguna` di pojok kanan atas dasbor.
2. Perbarui biodata diri, alamat email dinas, dan nomor kontak WhatsApp aktif.
3. **Gelar Akademik & NIP:** Pastikan NIP/NUPTK serta penulisan gelar kesarjanaan (misal: `S.Pd., M.Pd.`) sudah benar agar tercantum otomatis pada seluruh dokumen rapor dan administrasi cetak.
4. **Unggah Tanda Tangan Digital Tersanitasi:**
   - Unggah scan tanda tangan basah berlatar belakang transparan (PNG/JPG/SVG).
   - Mesin sistem secara otomatis mensterilkan berkas vektor dari ancaman skrip tersembunyi (Stored XSS).
   - Tanda tangan ini secara otomatis terpasang pada rapor hasil belajar, jurnal KBM, dan buku nilai dengan efek tinta realistis (`mix-blend-mode: multiply`) dan proteksi anti-scraping dari pengunduhan ilegal.

---

## 4. Alur Kerja Harian Pendidik

```
Pagi (Sebelum KBM Dimulai):
├── Buka Presensi Mandiri (teacher_attendance.php) & lakukan Smart Clock-In GPS (≤ 150m)
├── Buka Jadwal Mengajar & Perangkat Ajar untuk persiapan materi hari ini
└── Periksa Presensi Kehadiran Siswa kelas jam pertama via Scan QR atau manual

Siang (Selama & Pasca KBM):
├── Isi Jurnal Mengajar KBM harian (materi, kehadiran, situasi kelas, status keterlaksanaan)
├── Unggah modul pembelajaran atau penugasan baru di menu Bahan Ajar & Tugas
├── Tinjau dan nilai tugas pengerjaan siswa yang masuk
└── Buka kegiatan Ekstrakurikuler (bila jadwal latihan) & klik "Set Hadir Semua"

Sore / Akhir Pekan:
├── Lakukan Smart Clock-Out Pulang dalam radius sekolah
├── Review Buku Nilai (gradebook.php) dan Hasil Asesmen CBT
└── (Khusus Wali Kelas) Pantau dashboard rombel binaan & input catatan capaian rapor
```

---

## 5. Rekomendasi Keamanan & Etika Kerja Pendidik
- **Kerahasiaan Token Ujian:** Jangan pernah membagikan token asesmen CBT sebelum waktu ujian resmi dimulai di ruang kelas.
- **Integritas Nilai Peserta Didik:** Perubahan nilai akademik pada *gradebook* dan *e-rapor* terekam secara otomatis pada audit log sistem.
- **Pemeriksaan File Penugasan Siswa:** Selalu manfaatkan peninjauan dokumen via peramban sebelum mengunduh berkas jawaban siswa yang berformat arsip (.zip).
- **Proteksi Akses Mandiri:** Selalu keluar (*Logout*) dari akun Anda apabila mengoperasikan komputer di laboratorium atau ruang multimedia bersama.
