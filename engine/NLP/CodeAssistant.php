<?php

namespace Sentinel\NLP;

class CodeAssistant
{
    /**
     * Cek apakah pesan merupakan pertanyaan pemrograman atau koding
     */
    public static function isCodeQuery(string $text): bool
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        $keywords = [
            'koneksi database', 'pdo php', 'mysql pdo', 'koneksi pdo', 'contoh function', 'contoh fungsi',
            'cara looping', 'perulangan for',
            'perulangan while', 'bilangan prima', 'regex email', 'get dan post', 'perbedaan get dan post',
            'rest api php', 'async await', 'apa itu oop', 'object oriented', 'html dasar',
            'struktur html', 'perintah git', 'git commit', 'git push', 'fungsi php',
            'array php', 'json encode', 'curl php', 'crud php', 'session php',
            'buatkan kode', 'contoh kode', 'contoh program', 'script php', 'script python',
            'script javascript', 'cara membuat api', 'belajar php', 'belajar javascript'
        ];

        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Selesaikan pertanyaan koding dan berikan panduan + blok kode
     */
    public static function solve(string $text): ?array
    {
        $lower = mb_strtolower(trim($text), 'UTF-8');

        // 1. Koneksi Database PDO MySQL
        if (str_contains($lower, 'koneksi') && (str_contains($lower, 'pdo') || str_contains($lower, 'database') || str_contains($lower, 'mysql'))) {
            return [
                'thought' => "Pengguna meminta contoh koneksi basis data MySQL menggunakan ekstensi PDO di PHP beserta penanganan kesalahan (try-catch).",
                'reply'   => "Berikut adalah implementasi koneksi database MySQL menggunakan **PDO (PHP Data Objects)** yang aman dan standar industri:\n\n" .
                             "```php\n" .
                             "<?php\n" .
                             "\$host     = 'localhost';\n" .
                             "\$dbName   = 'nama_database';\n" .
                             "\$username = 'root';\n" .
                             "\$password = '';\n" .
                             "\$charset  = 'utf8mb4';\n\n" .
                             "\$dsn = \"mysql:host=\$host;dbname=\$dbName;charset=\$charset\";\n\n" .
                             "\$options = [\n" .
                             "    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,\n" .
                             "    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n" .
                             "    PDO::ATTR_EMULATE_PREPARES   => false,\n" .
                             "];\n\n" .
                             "try {\n" .
                             "    \$pdo = new PDO(\$dsn, \$username, \$password, \$options);\n" .
                             "    echo \"Koneksi ke database berhasil!\";\n" .
                             "} catch (PDOException \$e) {\n" .
                             "    die(\"Koneksi gagal: \" . \$e->getMessage());\n" .
                             "}\n" .
                             "```\n\n" .
                             "**Poin Penting:**\n" .
                             "- `ERRMODE_EXCEPTION`: Mengaktifkan mode exception agar kesalahan query tertangkap di blok `catch`.\n" .
                             "- `FETCH_ASSOC`: Mengembalikan baris data sebagai array asosiatif secara otomatis.\n" .
                             "- `EMULATE_PREPARES => false`: Memastikan Prepared Statement diproses langsung oleh MySQL untuk mencegah **SQL Injection**.",
                'type'    => 'coding_pdo'
            ];
        }

        // 2. Perulangan / Looping di PHP & JS
        if (str_contains($lower, 'looping') || str_contains($lower, 'perulangan') || str_contains($lower, 'for loop')) {
            return [
                'thought' => "Menjelaskan jenis-jenis perulangan (looping) dalam pemrograman seperti for, foreach, while.",
                'reply'   => "Dalam pemrograman, perulangan digunakan untuk mengeksekusi blok kode berulang kali:\n\n" .
                             "### 1. Perulangan `for` (PHP / JavaScript)\n" .
                             "```php\n" .
                             "// PHP\n" .
                             "for (\$i = 1; \$i <= 5; \$i++) {\n" .
                             "    echo \"Iterasi ke-\$i\\n\";\n" .
                             "}\n" .
                             "```\n\n" .
                             "### 2. Perulangan `foreach` (Untuk Array)\n" .
                             "```php\n" .
                             "\$buah = ['Apel', 'Mangga', 'Jeruk'];\n" .
                             "foreach (\$buah as \$index => \$item) {\n" .
                             "    echo (\$index + 1) . \". \$item\\n\";\n" .
                             "}\n" .
                             "```\n\n" .
                             "### 3. Perulangan `while`\n" .
                             "```php\n" .
                             "\$angka = 1;\n" .
                             "while (\$angka <= 3) {\n" .
                             "    echo \$angka . \" \";\n" .
                             "    \$angka++;\n" .
                             "}\n" .
                             "```",
                'type'    => 'coding_loops'
            ];
        }

        // 3. Bilangan Prima
        if (str_contains($lower, 'bilangan prima') || str_contains($lower, 'angka prima')) {
            return [
                'thought' => "Membuat fungsi algoritma pengecekan bilangan prima dengan kompleksitas O(sqrt(n)).",
                'reply'   => "Bilangan prima adalah bilangan bulat lebih besar dari 1 yang hanya memiliki dua faktor pembagi: 1 dan dirinya sendiri.\n\n" .
                             "```php\n" .
                             "function isPrime(int \$number): bool\n" .
                             "{\n" .
                             "    if (\$number <= 1) return false;\n" .
                             "    if (\$number <= 3) return true;\n" .
                             "    if (\$number % 2 === 0 || \$number % 3 === 0) return false;\n\n" .
                             "    // Cek faktor pembagi hingga akar kuadrat (O(sqrt(n)))\n" .
                             "    for (\$i = 5; \$i * \$i <= \$number; \$i += 6) {\n" .
                             "        if (\$number % \$i === 0 || \$number % (\$i + 2) === 0) {\n" .
                             "            return false;\n" .
                             "        }\n" .
                             "    }\n" .
                             "    return true;\n" .
                             "}\n\n" .
                             "// Uji Coba:\n" .
                             "\$angka = 29;\n" .
                             "echo \$angka . (isPrime(\$angka) ? \" adalah prima.\" : \" bukan prima.\");\n" .
                             "```",
                'type'    => 'coding_prime'
            ];
        }

        // 4. Perbedaan GET vs POST
        if (str_contains($lower, 'get') && str_contains($lower, 'post')) {
            return [
                'thought' => "Menganalisis perbedaan metode HTTP GET dan POST dalam transmisi data web.",
                'reply'   => "### Perbedaan Utama HTTP GET vs POST:\n\n" .
                             "| Aspek | HTTP GET | HTTP POST |\n" .
                             "|---|---|---|\n" .
                             "| **Pengiriman Data** | Melalui URL query string (`?id=10`) | Melalui request body (tersembunyi dari URL) |\n" .
                             "| **Kapasitas** | Terbatas panjang URL (~2048 karakter) | Tidak terbatas (dapat kirim file besar) |\n" .
                             "| **Keamanan** | Kurang aman untuk data sensitif | Lebih aman untuk password/kredensial |\n" .
                             "| **Caching** | Dapat di-cache oleh browser | Tidak di-cache secara default |\n" .
                             "| **Idempoten** | Ya (beberapa kali request hasil sama) | Tidak (dapat membuat entri baru) |\n" .
                             "| **Penggunaan** | Pengambilan data, pencarian | Simpan form, login, unggah file |",
                'type'    => 'coding_concept'
            ];
        }

        // 5. REST API di PHP
        if (str_contains($lower, 'rest api') || str_contains($lower, 'buat api') || str_contains($lower, 'membuat api')) {
            return [
                'thought' => "Memberikan boilerplate endpoint RESTful JSON API standar di PHP murni.",
                'reply'   => "Berikut adalah contoh endpoint REST API JSON sederhana dan clean di PHP:\n\n" .
                             "```php\n" .
                             "<?php\n" .
                             "// Set header response JSON & CORS\n" .
                             "header('Content-Type: application/json; charset=UTF-8');\n" .
                             "header('Access-Control-Allow-Origin: *');\n" .
                             "header('Access-Control-Allow-Methods: GET, POST, OPTIONS');\n\n" .
                             "\$method = \$_SERVER['REQUEST_METHOD'];\n\n" .
                             "switch (\$method) {\n" .
                             "    case 'GET':\n" .
                             "        echo json_encode([\n" .
                             "            'status'  => 'success',\n" .
                             "            'message' => 'Data berhasil diambil',\n" .
                             "            'data'    => ['id' => 1, 'nama' => 'Sentinel AI', 'versi' => '2.0']\n" .
                             "        ]);\n" .
                             "        break;\n\n" .
                             "    case 'POST':\n" .
                             "        \$payload = json_decode(file_get_contents('php://input'), true);\n" .
                             "        echo json_encode([\n" .
                             "            'status'  => 'created',\n" .
                             "            'message' => 'Data baru diterima',\n" .
                             "            'received'=> \$payload\n" .
                             "        ]);\n" .
                             "        break;\n\n" .
                             "    default:\n" .
                             "        http_response_code(405);\n" .
                             "        echo json_encode(['error' => 'Metode tidak didukung']);\n" .
                             "        break;\n" .
                             "}\n" .
                             "```",
                'type'    => 'coding_api'
            ];
        }

        // 6. OOP (Object Oriented Programming)
        if (str_contains($lower, 'oop') || str_contains($lower, 'object oriented') || str_contains($lower, 'objek')) {
            return [
                'thought' => "Menjelaskan konsep 4 pilar Object-Oriented Programming (OOP) dengan analogi praktis.",
                'reply'   => "### Konsep 4 Pilar OOP (Pemrograman Berorientasi Objek):\n\n" .
                             "1. **Encapsulation (Pembungkusan):** Mengikat data (atribut) dan perilaku (metode) dalam satu class serta membatasi akses langsung dengan visibility (`private`, `protected`, `public`).\n" .
                             "2. **Inheritance (Pewarisan):** Class anak mewarisi properti dan method dari class induk menggunakan kata kunci `extends`.\n" .
                             "3. **Polymorphism (Banyak Bentuk):** Kemampuan method dengan nama yang sama untuk memiliki perilaku berbeda di class turunan (metode overriding/interface).\n" .
                             "4. **Abstraction (Abstraksi):** Menyembunyikan detail implementasi yang rumit dan hanya menampilkan antarmuka yang penting (menggunakan `abstract class` atau `interface`).\n\n" .
                             "```php\n" .
                             "class Robot {\n" .
                             "    protected string \$name;\n\n" .
                             "    public function __construct(string \$name) {\n" .
                             "        \$this->name = \$name;\n" .
                             "    }\n\n" .
                             "    public function speak(): string {\n" .
                             "        return \"Halo, saya \" . \$this->name;\n" .
                             "    }\n" .
                             "}\n" .
                             "\$sentinel = new Robot('Sentinel');\n" .
                             "echo \$sentinel->speak();\n" .
                             "```",
                'type'    => 'coding_oop'
            ];
        }

        // 7. Git Cheat Sheet
        if (str_contains($lower, 'git') && (str_contains($lower, 'perintah') || str_contains($lower, 'commit') || str_contains($lower, 'push') || str_contains($lower, 'dasar'))) {
            return [
                'thought' => "Menyajikan ringkasan perintah esensial Git untuk alur kerja version control.",
                'reply'   => "### Perintah Esensial Git Version Control:\n\n" .
                             "```bash\n" .
                             "# 1. Inisialisasi atau Clone repositori\n" .
                             "git init\n" .
                             "git clone https://github.com/user/repository.git\n\n" .
                             "# 2. Cek status & melihat perubahan\n" .
                             "git status\n" .
                             "git diff\n\n" .
                             "# 3. Tambahkan ke staging area & simpan snapshot (commit)\n" .
                             "git add .\n" .
                             "git commit -m \"Menambahkan fitur AI cerdas\"\n\n" .
                             "# 4. Hubungkan remote & kirim ke server\n" .
                             "git branch -M main\n" .
                             "git remote add origin https://github.com/user/repo.git\n" .
                             "git push -u origin main\n\n" .
                             "# 5. Ambil pembaruan terbaru\n" .
                             "git pull origin main\n" .
                             "```",
                'type'    => 'coding_git'
            ];
        }

        // 8. Struktur HTML Dasar
        if (str_contains($lower, 'html dasar') || str_contains($lower, 'struktur html') || str_contains($lower, 'template html')) {
            return [
                'thought' => "Menyajikan template struktur HTML5 semantik modern standar W3C.",
                'reply'   => "Berikut adalah struktur dokumen HTML5 standar yang lengkap dengan meta viewport untuk responsivitas mobile:\n\n" .
                             "```html\n" .
                             "<!DOCTYPE html>\n" .
                             "<html lang=\"id\">\n" .
                             "<head>\n" .
                             "    <meta charset=\"UTF-8\">\n" .
                             "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n" .
                             "    <title>Aplikasi Web Modern</title>\n" .
                             "    <link rel=\"stylesheet\" href=\"style.css\">\n" .
                             "</head>\n" .
                             "<body>\n" .
                             "    <header>\n" .
                             "        <h1>Selamat Datang</h1>\n" .
                             "    </header>\n\n" .
                             "    <main>\n" .
                             "        <p>Konten utama halaman web Anda di sini.</p>\n" .
                             "    </main>\n\n" .
                             "    <footer>\n" .
                             "        <p>&copy; " . date('Y') . " Sentinel AI. Hak Cipta Dilindungi.</p>\n" .
                             "    </footer>\n\n" .
                             "    <script src=\"script.js\"></script>\n" .
                             "</body>\n" .
                             "</html>\n" .
                             "```",
                'type'    => 'coding_html'
            ];
        }

        // 9. Regex Email
        if (str_contains($lower, 'regex') && str_contains($lower, 'email')) {
            return [
                'thought' => "Memberikan pola Regular Expression untuk validasi format alamat email standar RFC 5322.",
                'reply'   => "Berikut adalah pola Regex untuk memvalidasi alamat email di PHP dan JavaScript:\n\n" .
                             "### Pola Regex:\n" .
                             "```regex\n" .
                             "/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}\$/\n" .
                             "```\n\n" .
                             "### Contoh Implementasi di PHP:\n" .
                             "```php\n" .
                             "\$email = \"user@example.com\";\n\n" .
                             "// Cara 1: Menggunakan fungsi bawaan filter_var (Sangat Direkomendasikan)\n" .
                             "if (filter_var(\$email, FILTER_VALIDATE_EMAIL)) {\n" .
                             "    echo \"Email valid!\";\n" .
                             "}\n\n" .
                             "// Cara 2: Menggunakan preg_match regex\n" .
                             "\$pattern = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}\$/';\n" .
                             "if (preg_match(\$pattern, \$email)) {\n" .
                             "    echo \"Format email sesuai pola regex.\";\n" .
                             "}\n" .
                             "```",
                'type'    => 'coding_regex'
            ];
        }

        return null;
    }
}

