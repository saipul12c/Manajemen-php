# 🔵 Panduan Operasional Peran: Siswa (Peserta Didik Aktif & Calon Alumni)
**Sistem Informasi Manajemen Sekolah Terpadu — Manajemen-PHP v1.3.0**

---

## 1. Ringkasan Peran & Ruang Lingkup Siswa

Peran **Siswa** (*Peserta Didik Aktif & Calon Alumni*) merupakan sentra dari seluruh proses kegiatan belajar mengajar (KBM) dan pelayanan kesiswaan digital pada **Manajemen-PHP**. Melalui portal siswa yang modern, interaktif, dan responsif, peserta didik dapat mengakses materi E-Learning, mengumpulkan tugas secara terjadwal, mengikuti asesmen ujian online ber-timer dengan proteksi proctoring anti-curang, memantau absensi kehadiran via kartu identitas QR Code, mengeksplorasi perpustakaan digital, berpartisipasi aktif dalam ekstrakurikuler, memantau buku rapor Kurikulum Merdeka, serta mengajukan permohonan surat kedinasan hingga legalisir digital ijazah secara mandiri.

### 🚀 Kapabilitas Utama & Pembaruan Sistem v1.3.0
- **Radar Profil Siswa 360 Derajat (`student_360.php`):** Siswa dapat memantau grafik kekuatan akademik antar-mapel, persentase kehadiran bulanan, akumulasi poin kedisiplinan dan prestasi BK, status SPP, serta keaktifan ekstrakurikuler dalam 1 layar analitik terpadu.
- **Asesmen CBT Berpengaman Tinggi & Kiosk Mode:** Ujian online dilengkapi **Browser Proctoring Engine** (layar penuh wajib, deteksi perpindahan tab/jendela dengan auto-lock otomatis pada toleransi ke-3, serta pemblokiran pintasan inspeksi kode).
- **Ekosistem 3 Aplikasi Mobile Khusus (`apps_hub.php`):** Siswa dapat mengunduh dan memasang 3 aplikasi Android (APK) dan Web Apps (PWA): *CBT Ujian Kiosk Screen (Anti-Curang)*, *Presensi Mandiri Siswa & Guru*, serta *E-Perpustakaan Barcode Scanner*.
- **E-Rapor Kurikulum Merdeka & Rapor Projek P5:** Hak akses aman anti-IDOR yang memastikan privasi dokumen nilai terjaga mutlak, lengkap dengan narasi capaian pembelajaran dua sisi dan lembar evaluasi tema P5.
- **Portofolio Ekstrakurikuler & Piagam Sertifikat Ber-QR TTE:** Pendaftaran mandiri klub bakat minat, catatan kehadiran mingguan, sinkronisasi nilai predikat pembina ke rapor, serta pencetakan piagam penghargaan ber-QR Code verifikasi keaslian.
- **Layanan Alumni, Tracer Study & Legalisir Digital Ijazah:** Bagi siswa tingkat akhir dan lulusan, tersedia portal penelusuran karir (Tracer Study) dan permohonan legalisir ijazah online berstempel dinas digital.

---

## 2. Navigasi & Struktur Menu Siswa

| Modul Pembelajaran | Fitur / Halaman | File Eksekusi | Deskripsi Kegunaan bagi Siswa |
| :--- | :--- | :--- | :--- |
| **Menu Utama** | Beranda Dasbor | `dashboard/index.php` | Jadwal KBM hari ini, tugas yang mendekati deadline, kartu QR absensi, & info ujian. |
| | Pasang Aplikasi (Hub) | `dashboard/apps_hub.php` | Unduh 3 aplikasi Android (APK) & Web App (PWA) resmi sekolah. |
| | Kalender Akademik | `dashboard/akademik/calendar.php` | Kalender pendidikan, agenda kegiatan kesiswaan, hari efektif KBM, & jadwal asesmen. |
| | Pengumuman Sekolah | `dashboard/informasi/announcements.php` | Membaca pengumuman resmi sekolah, surat edaran, dan mengunduh lampiran PDF. |
| | Pesan & Konsultasi | `dashboard/pesan/messages.php` | Mengirimkan pesan tanya jawab materi ajar langsung kepada guru mata pelajaran. |
| **Pembelajaran & KBM**| Jadwal Pelajaran | `dashboard/akademik/timetable.php` | Jadwal mingguan mata pelajaran, jam KBM, ruang kelas, & guru pengampu. |
| | Presensi Kehadiran | `dashboard/presensi/attendance.php` | Riwayat presensi harian diri sendiri (Hadir, Sakit, Izin, Alpa) dan persentase kehadiran. |
| | Kartu Pelajar Digital | `dashboard/presensi/qr_card.php` | Kartu identitas ber-QR Code untuk dipindai oleh kamera guru/piket saat presensi. |
| | Bahan Ajar & Modul | `dashboard/akademik/materials.php` | Unduh materi pelajaran (PDF, PPT, DOC), tonton video YouTube materi, & tautan Drive. |
| | Pengumpulan Tugas | `dashboard/akademik/assignments.php` | Unduh soal, serahkan berkas jawaban tugas, lihat skor nilai & feedback guru. |
| | E-Rapor Akademik | `dashboard/akademik/report_card.php` | Transkrip capaian kompetensi nilai semester, narasi dua sisi, & catatan wali kelas. |
| | Rapor Projek P5 | `dashboard/akademik/p5_projects.php` | Meninjau nilai rubrik capaian projek Profil Pelajar Pancasila (MB/SB/BSH/SAB). |
| | Ekskul & Prestasi | `dashboard/ekskul/index.php` | Eksplorasi katalog klub bakat minat, daftar ekskul, pantau absensi, & piagam kejuaraan. |
| | Radar Siswa 360° | `dashboard/admin/student_360.php` | Dasbor potret holistik performa belajar dan kedisiplinan diri sendiri. |
| **Ujian & CBT** | Modul Ujian CBT | `dashboard/Modul-ujian/exams.php` | Mengakses ujian harian, UTS, UKK, & latihan dengan memasukkan token ujian. |
| | Ruang Ujian Online | `dashboard/Modul-ujian/exam_take.php` | Antarmuka ujian dengan timer mundur, nomor dinamis, acak opsi, & proctoring anti-curang. |
| | Hasil & Remedial | `dashboard/Modul-ujian/exam_results.php` | Melihat skor nilai perolehan ujian dan mengerjakan remedial jika belum tuntas KKM. |
| | Cetak Kartu Peserta | `dashboard/Modul-ujian/exam_card.php` | Cetak kartu tanda peserta ujian resmi lengkap dengan jadwal mata pelajaran. |
| **Layanan Siswa** | Keuangan & Tagihan | `dashboard/keuangan/payments.php` | Cek status tagihan SPP, upload bukti struk transfer, dan cetak kuitansi lunas sah. |
| | Bimbingan Konseling | `dashboard/bk/counseling.php` | Memantau rekam poin kedisiplinan tata tertib dan piagam penghargaan prestasi siswa. |
| | Permohonan Surat | `dashboard/surat/requests.php` | Mengajukan Surat Keterangan Siswa Aktif, izin sakit medis, SKBB, rekomendasi beasiswa. |
| | Legalisir Digital Alumni| `dashboard/alumni/legalisir.php` | Permohonan legalisir ijazah online bagi siswa tingkat akhir / alumni ber-QR TTE. |
| | Tracer Study Alumni | `dashboard/alumni/tracer_study.php` | Kuesioner pelacakan karir lulusan (Kuliah, Bekerja, Wirausaha) instrumen BAN-SM. |
| | Perpustakaan Digital | `dashboard/perpustakaan/books.php`| OPAC katalog buku, baca e-book fullscreen, booking buku mandiri, & ulasan bintang. |
| | Bebas Pustaka | `dashboard/perpustakaan/clearance.php` | Verifikasi tanggungan pinjaman buku dan cetak Surat Keterangan Bebas Pustaka. |
| **Akun Saya** | Profil Pengguna | `dashboard/profile.php` | Unggah pasfoto diri resmi, perbarui biodata diri, dan ganti kata sandi akun. |

---

## 3. Prosedur Operasional Standar (SOP) Siswa

### A. Pengumpulan Tugas Pembelajaran Digital (`assignments.php`)
1. Buka menu `Tugas & Evaluasi` (`dashboard/akademik/assignments.php`).
2. Saring tugas berdasarkan status: pilih tugas yang berstatus **Belum Selesai**.
3. Klik judul tugas untuk membaca petunjuk pengerjaan dari guru dan mengunduh berkas lampiran materi.
4. Perhatikan batas akhir pengumpulan (*due date*).
5. **Menyerahkan Berkas Jawaban:**
   - Tuliskan ringkasan jawaban atau catatan pengerjaan pada kotak teks.
   - Unggah berkas dokumen pengerjaan (format PDF, DOCX, XLSX, JPG, atau ZIP) maks. 5MB. Seluruh berkas melalui mesin keamanan terpusat `handleSecureUpload()` dengan nama acak SHA-256 yang aman dari penimpaan file.
   - Klik tombol **"Kirim Tugas"**.
6. Status tugas akan berubah menjadi **Selesai**. Setelah guru memeriksa, siswa dapat membuka kembali halaman tugas untuk melihat **Nilai Skor** dan **Catatan Feedback Evaluasi Pendidik**.

---

### B. Prosedur Asesmen Ujian CBT & Proctoring Anti-Cheat (`Modul-ujian/`)
1. **Memasuki Ruang Ujian (`exams.php`):**
   - Pilih asesmen yang sedang aktif (Ujian Harian, UTS, UKK, atau Asesmen Sumatif).
   - Masukkan **Token Akses Ujian** yang diberikan oleh guru pengawas di kelas.
   - Pastikan koneksi internet stabil atau gunakan aplikasi **CBT Kiosk Screen** di smartphone.
   - Klik **"Mulai Mengerjakan"**.
2. **Aturan Selama Pengerjaan Ujian (`exam_take.php`):**
   - **Mode Layar Penuh (Fullscreen Wajib):** Saat ujian dimulai, sistem mewajibkan tampilan layar penuh.
   - **Countdown Timer:** Waktu pengerjaan berjalan mundur secara otomatis di sudut layar. Bila waktu habis, sistem otomatis mengumpulkan jawaban yang telah tersimpan.
   - **Larangan Berpindah Tab / Jendela (Anti-Cheat Proctoring):**
     - Setiap kali siswa beralih tab, meminimalkan browser, atau membuka aplikasi lain, sistem memicu peringatan pelanggaran.
     - Diberikan batas toleransi maksimal **3 kali peringatan**.
     - Pada pelanggaran ke-3, ujian akan **terkunci otomatis (*Auto-Locked*)** dan jawaban terkirim seketika.
   - **Navigasi Soal Fleksibel:** Siswa dapat berpindah nomor soal menggunakan kisi nomor, menandai soal dengan status *Ragu-Ragu*, dan mengubah pilihan sebelum mengonfirmasi selesai.
   - Setelah yakin seluruh jawaban terjawab, klik tombol **"Selesai & Kumpulkan Ujian"**.
3. **Melihat Nilai & Sesi Remedial (`exam_results.php`):**
   - Skor pilihan ganda terkoreksi otomatis secara instan.
   - Apabila nilai akhir berada di bawah KKM dan guru pengampu telah memberikan akses remedial, tombol **"Kerjakan Remedial"** akan aktif di dasbor ujian siswa.

---

### C. Kurikulum Merdeka: E-Rapor Akademik & Rapor Projek P5
1. **E-Rapor Akademik Kurikulum Merdeka (`report_card.php`):**
   - Siswa dapat melihat transkrip capaian kompetensi hasil belajar semester dengan proteksi privasi ketat (siswa mutlak hanya dapat melihat dokumen miliknya sendiri).
   - Lembar nilai memuat deskripsi dua sisi:
     - **Capaian Pembelajaran Tertinggi:** Kompetensi yang berhasil dikuasai secara optimal.
     - **Perlu Bimbingan / Pendampingan:** Indikator yang membutuhkan pendalaman belajar lebih lanjut.
   - Memuat catatan motivasi dari Wali Kelas dan predikat keaktifan Ekstrakurikuler.
2. **Rapor Projek Profil Pelajar Pancasila (P5) (`p5_projects.php`):**
   - Meninjau capaian projek karakter berstandar BSKAP Kemendikbudristek berdasarkan 4 skala perkembangan: *Mulai Berkembang (MB)*, *Sedang Berkembang (SB)*, *Berkembang Sesuai Harapan (BSH)*, dan *Sangat Berkembang (SAB)*.
3. **Cetak Lembar Hasil Belajar Sah:**
   - Siswa dapat mengunduh atau mencetak berkas rapor resmi format A4 berstempel digital dinas dan QR Code pengesahan Kepala Sekolah.

---

### D. Keikutsertaan Ekstrakurikuler & Portofolio Prestasi (`ekskul/`)
1. **Pendaftaran Klub Ekstrakurikuler:**
   - Masuk ke menu `Ekskul & Prestasi`.
   - Pilih klub bakat minat yang diminati (Pramuka, PMR, Paskibra, Futsal, Basket, Robotik, Karya Ilmiah Remaja, dsb.).
   - Periksa ketersediaan kuota anggota dan jadwal latihan rutin mingguan.
   - Klik tombol **"Daftar Ekstrakurikuler"**. Pengajuan akan menunggu persetujuan guru pembina klub.
2. **Memantau Kehadiran & Nilai Rapor Ekskul:**
   - Pantau rekapitulasi kehadiran mingguan yang diinput oleh pembina.
   - Pada akhir semester, nilai predikat (A/B/C/D) dan catatan pembina akan secara otomatis masuk ke dalam buku E-Rapor siswa.
3. **Pencatatan Prestasi & Piagam Sertifikat Ber-QR TTE:**
   - Apabila Anda menjuarai perlombaan atau kompetisi, laporkan kepada pembina ekskul atau guru pembimbing untuk dicatatkan ke dalam **Hall of Fame Prestasi Sekolah**.
   - Siswa dapat mengunduh dan mencetak **Piagam Penghargaan / Sertifikat Resmi** (`print_certificate.php`) ber-QR Code validasi keaslian dokumen.

---

### E. Perpustakaan Digital: Baca E-Book & Reservasi Buku Mandiri
1. **Membaca Koleksi E-Book Digital Layar Penuh:**
   - Buka menu `Perpustakaan Digital`.
   - Pilih koleksi bertanda e-book digital → Klik tombol **"Baca E-Book"**.
   - Berkas PDF buku terbuka langsung di browser dengan penampil layar penuh (*Fullscreen In-Browser Reader*) yang nyaman dibaca tanpa perlu mengunduh file ke memori perangkat.
2. **Fitur Booking / Reservasi Buku Mandiri:**
   - Jika membutuhkan buku fisik perpustakaan: cari buku pada katalog OPAC lalu klik **"Booking / Reservasi Buku"**.
   - Sistem akan menahan stok buku tersebut di meja sirkulasi perpustakaan selama **2 hari kerja**.
   - Datangi ruang perpustakaan, tunjukkan kartu anggota digital, dan staf perpustakaan akan menyerahkan fisik buku pinjaman Anda.
3. **Rating Bintang & Ulasan Komunitas:**
   - Berikan ulasan singkat dan rating bintang (1–5) pada buku yang telah Anda baca untuk memberikan rekomendasi kepada rekan siswa lainnya.
4. **Surat Keterangan Bebas Perpustakaan (`clearance.php`):**
   - Bagi siswa kelas akhir (XII / IX), buka menu `Bebas Pustaka`.
   - Jika seluruh buku pinjaman telah dikembalikan dan bebas denda, klik **"Cetak Surat Bebas Perpustakaan"** sebagai berkas syarat kelulusan dan pengambilan ijazah.

---

### F. Presensi Digital via Kartu QR Code Pelajar (`qr_card.php`)
1. Buka menu `Presensi` → `Kartu Pelajar Digital`.
2. Tampilkan kartu QR digital pada layar smartphone Anda.
3. Saat tiba di sekolah atau memasuki ruang kelas, tunjukkan kode QR ke webcam/pemindai yang dioperasikan oleh guru piket atau kamera presensi sekolah.
4. Presensi kehadiran Anda akan langsung tercatat *Hadir* secara seketika pada buku absen sekolah.

---

### G. Layanan Permohonan Surat Kesiswaan Mandiri (`requests.php`)
1. Buka menu `Layanan Persuratan`. Klik **"Buat Pengajuan Baru"**.
2. Pilih jenis surat yang dibutuhkan:
   - *Surat Keterangan Siswa Aktif* (keperluan tunjangan gaji orang tua, perbankan, visa, atau instansi).
   - *Surat Keterangan Berkelakuan Baik (SKBB)*.
   - *Surat Rekomendasi Beasiswa / Perlombaan*.
   - *Surat Dispensasi Mengikuti Kegiatan Eksternal*.
3. Tuliskan alasan kebutuhan dan unggah dokumen pendukung (jika ada).
4. Pantau status pengajuan (`Menunggu` $\rightarrow$ `Diproses` $\rightarrow$ `Selesai`).
5. Begitu disetujui staf tata usaha, klik tombol **"Cetak Surat"** untuk mengunduh dokumen kedinasan resmi berstempel dan ber-QR Code pengesahan.

---

### H. Layanan Alumni, Tracer Study & Legalisir Digital Ijazah
Bagi siswa tingkat akhir yang telah dinyatakan lulus:
1. **Pengisian Tracer Study (`dashboard/alumni/tracer_study.php`):**
   - Isi kuesioner penelusuran lulusan (pilihan status: Kuliah, Bekerja, Wirausaha, atau Mencari Kerja). Data ini penting sebagai indikator akreditasi sekolah (BAN-SM).
2. **Pengajuan Legalisir Ijazah Online (`dashboard/alumni/legalisir.php`):**
   - Buka portal legalisir digital.
   - Masukkan nomor ijazah, tahun lulus, dan unggah berkas scan dokumen asli.
   - Pantau status verifikasi staf TU.
   - Begitu disetujui, cetak lembar pengesahan resmi berstempel dinas digital, tanda tangan Kepala Sekolah, dan QR Code verifikasi dokumen (`verify_document.php`).

---

## 4. Etika Digital & Rekomendasi Integritas Siswa
- **Kejujuran Akademik Mutlak:** Dilarang keras melakukan kecurangan selama asesmen CBT (membuka tab browser lain, menggunakan ekstensi pihak ketiga, atau menyontek).
- **Integritas Penugasan:** Unggah karya tugas asli dan hindari plagiarisme.
- **Kerahasiaan Akun & PIN:** Jangan pernah membagikan username atau kata sandi akun Anda kepada teman sekelas.
- **Etika Komunikasi Online:** Gunakan bahasa Indonesia yang sopan dan santun saat berkonsultasi privat dengan para pendidik di fitur pesan internal maupun live chat sekolah.
