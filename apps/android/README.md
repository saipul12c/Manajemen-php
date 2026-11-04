# Paket Wrapper Aplikasi Android (CBT, Presensi, Perpustakaan)
Sistem Manajemen-PHP • 1 Backend & Database Terpadu

Direktori ini berisi paket konfigurasi dan source code untuk menghasilkan **3 file installer Android (.APK)**:
1. **`cbt-app/`** (Package: `com.sekolah.cbt`) — Aplikasi Ujian CBT Mode Kiosk & Layar Penuh.
2. **`presensi-app/`** (Package: `com.sekolah.presensi`) — Aplikasi Presensi Siswa & Guru dengan izin Kamera QR.
3. **`perpustakaan-app/`** (Package: `com.sekolah.perpustakaan`) — Aplikasi E-Perpustakaan dengan Barcode Scanner & Upload Berkas.

---

## 🚀 Pilihan 1: Generate APK Instan (Selesai dalam 1 Menit, Tanpa Android Studio)

Karena kita sudah mengonfigurasi file PWA `manifest.json` lengkap di setiap modul, Anda bisa membuat file APK resmi melalui layanan generator Google/Microsoft:

1. Pastikan web sekolah sudah online atau bisa diakses (misal via domain sekolah atau Ngrok/Cloudflare Tunnel jika di localhost).
2. Kunjungi situs resmi: **[PWABuilder.com](https://www.pwabuilder.com/)**
3. Masukkan salah satu link modul aplikasi:
   - Link CBT: `https://domain-sekolah.com/dashboard/Modul-ujian/exams.php`
   - Link Presensi: `https://domain-sekolah.com/dashboard/presensi/attendance.php`
   - Link Perpustakaan: `https://domain-sekolah.com/dashboard/perpustakaan/books.php`
4. Klik **Start**, lalu klik tombol **Package For Stores**.
5. Pilih tab **Android** > Klik **Generate APK**.
6. Unduh file `.apk` dan bagikan langsung ke siswa atau guru!

---

## 🛠️ Pilihan 2: Build Native Menggunakan Android Studio

Jika Anda ingin mengubah ikon, warna status bar, atau mengompilasi APK secara offline di laptop:

1. Buka software **Android Studio**.
2. Pilih **Open an Existing Project** dan arahkan ke salah satu folder aplikasi:
   - `apps/android/cbt-app`
   - `apps/android/presensi-app`
   - `apps/android/perpustakaan-app`
3. Sesuaikan konstanta `APP_URL` di dalam file `MainActivity.java` dengan alamat IP/domain server Anda.
4. Klik menu **Build > Build Bundle(s) / APK(s) > Build APK(s)**.
5. File `.apk` siap diinstal di perangkat tablet atau HP sekolah.

---

## 📌 Catatan Pengaturan Server Lokal (Wi-Fi Sekolah)

Jika server dijalankan secara lokal (misal laptop server dengan XAMPP/Laragon di jaringan Wi-Fi sekolah):
- Jangan gunakan `localhost` di HP Android siswa/guru.
- Gunakan IP LAN server sekolah, misalnya: `http://192.168.1.50/manajemen-php/`
- Pastikan firewall Windows di laptop server mengizinkan port web server (Port 80 / 443).
