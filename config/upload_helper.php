<?php
/**
 * Helper Keamanan Berkas & Gambar Terpusat (Centralized Secure Upload & Image Handler)
 * Sistem Manajemen-PHP
 * 
 * Melindungi sistem dari:
 * 1. MIME Spoofing & Polyglot Executable
 * 2. Stored XSS via berkas SVG
 * 3. Kebocoran Privasi Metadata EXIF (Koordinat GPS)
 * 4. Image Decompression Bomb (Resolusi Berlebih / DoS)
 * 5. Inkonsistensi Path di Basis Data (Penyebab Broken Image 404)
 */

if (!defined('UPLOAD_MAX_IMAGE_WIDTH')) {
    define('UPLOAD_MAX_IMAGE_WIDTH', 6000);
}
if (!defined('UPLOAD_MAX_IMAGE_HEIGHT')) {
    define('UPLOAD_MAX_IMAGE_HEIGHT', 6000);
}

/**
 * Peta MIME type standar untuk validasi file
 */
function getStandardMimeMap(): array {
    return [
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'gif'  => ['image/gif'],
        'svg'  => ['image/svg+xml', 'text/xml', 'image/svg'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
        'mp3'  => ['audio/mpeg', 'audio/mp3'],
        'wav'  => ['audio/wav', 'audio/x-wav'],
        'ogg'  => ['audio/ogg'],
        'm4a'  => ['audio/mp4', 'audio/x-m4a'],
        'aac'  => ['audio/aac'],
        'mp4'  => ['video/mp4'],
    ];
}

/**
 * Pembersih XSS mendalam khusus berkas vektor SVG
 */
function sanitizeSvgString(string $svg): string {
    // 1. Hapus deklarasi DTD / Entity eksternal (mencegah XXE)
    $svg = preg_replace('/<!DOCTYPE\b[^>]*>/i', '', $svg);
    $svg = preg_replace('/<!ENTITY\b[^>]*>/i', '', $svg);

    // 2. Hapus tag eksekusi skrip
    $svg = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $svg);
    $svg = preg_replace('/<foreignObject\b[^>]*>(.*?)<\/foreignObject>/is', '', $svg);
    $svg = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $svg);
    $svg = preg_replace('/<embed\b[^>]*>(.*?)<\/embed>/is', '', $svg);
    $svg = preg_replace('/<object\b[^>]*>(.*?)<\/object>/is', '', $svg);
    $svg = preg_replace('/<animate\b[^>]*>/is', '', $svg);
    $svg = preg_replace('/<set\b[^>]*>/is', '', $svg);

    // 3. Hapus inline event handlers (onload, onclick, onmouseover, dsb)
    $svg = preg_replace('/\bon[a-z0-9]+\s*=\s*(["\']).*?\1/is', '', $svg);
    $svg = preg_replace('/\bon[a-z0-9]+\s*=\s*[^>\s]+/is', '', $svg);

    // 4. Netralkan protokol berbahaya pada tautan
    $svg = preg_replace('/(?:\bhref|\bxlink:href)\s*=\s*(["\'])\s*(?:javascript|vbscript|data:text\/html):/is', 'href=$1#blocked:', $svg);

    return $svg;
}

/**
 * Bersihkan metadata EXIF (GPS Leaks) dan netralkan potensi polyglot payload pada gambar
 */
function stripExifMetadata(string $filePath, string $ext): bool {
    if (!extension_loaded('gd') || !file_exists($filePath)) {
        return false;
    }

    $ext = strtolower($ext);
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return false;
    }

    try {
        if ($ext === 'jpg' || $ext === 'jpeg') {
            if (function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
                $img = @imagecreatefromjpeg($filePath);
                if ($img !== false) {
                    @imagejpeg($img, $filePath, 92);
                    @imagedestroy($img);
                    return true;
                }
            }
        } elseif ($ext === 'png') {
            if (function_exists('imagecreatefrompng') && function_exists('imagepng')) {
                $img = @imagecreatefrompng($filePath);
                if ($img !== false) {
                    @imagealphablending($img, false);
                    @imagesavealpha($img, true);
                    @imagepng($img, $filePath, 8);
                    @imagedestroy($img);
                    return true;
                }
            }
        } elseif ($ext === 'webp') {
            if (function_exists('imagecreatefromwebp') && function_exists('imagewebp')) {
                $img = @imagecreatefromwebp($filePath);
                if ($img !== false) {
                    @imagealphablending($img, false);
                    @imagesavealpha($img, true);
                    @imagewebp($img, $filePath, 90);
                    @imagedestroy($img);
                    return true;
                }
            }
        }
    } catch (Throwable $e) {
        error_log("Gagal membersihkan EXIF: " . $e->getMessage());
    }

    return false;
}

/**
 * Verifikasi komprehensif berkas yang diunggah
 */
function verifyUploadedFile(array $file, array $allowedExts, array $allowedMimes = [], int $maxSizeBytes = 5242880): array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        $errCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $errMsg = 'Terjadi kesalahan saat mengunggah berkas (Kode: ' . $errCode . ').';
        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            $errMsg = 'Ukuran berkas melebihi batas maksimal konfigurasi server.';
        } elseif ($errCode === UPLOAD_ERR_NO_FILE) {
            $errMsg = 'Tidak ada berkas yang diunggah.';
        }
        return ['valid' => false, 'error' => $errMsg];
    }

    // 1. Validasi Ukuran Berkas
    $fileSize = (int) ($file['size'] ?? 0);
    if ($fileSize <= 0) {
        return ['valid' => false, 'error' => 'Berkas yang diunggah kosong (0 bytes).'];
    }
    if ($fileSize > $maxSizeBytes) {
        $maxMB = round($maxSizeBytes / (1024 * 1024), 1);
        return ['valid' => false, 'error' => "Ukuran berkas ($fileSize bytes) melebihi batas maksimal {$maxMB}MB."];
    }

    // 2. Validasi Ekstensi
    $origName = $file['name'] ?? '';
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowedExts = array_map('strtolower', $allowedExts);
    if (!in_array($ext, $allowedExts, true)) {
        return ['valid' => false, 'error' => 'Ekstensi berkas (.' . $ext . ') tidak didukung. Format diizinkan: ' . implode(', ', $allowedExts)];
    }

    // 3. Validasi Real MIME Type via finfo
    $detectedMime = '';
    if (function_exists('finfo_open') && !empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = strtolower((string) finfo_file($finfo, $file['tmp_name']));
            finfo_close($finfo);
        }
    } elseif (function_exists('mime_content_type') && !empty($file['tmp_name'])) {
        $detectedMime = strtolower((string) mime_content_type($file['tmp_name']));
    }

    // Resolusi daftar MIME yang diizinkan
    $mimeMap = getStandardMimeMap();
    $expectedMimes = $allowedMimes;
    if (empty($expectedMimes) && isset($mimeMap[$ext])) {
        $expectedMimes = $mimeMap[$ext];
    }

    if (!empty($expectedMimes) && !empty($detectedMime)) {
        $expectedMimes = array_map('strtolower', $expectedMimes);
        // Toleransi SVG text/xml atau image/svg+xml
        if ($ext === 'svg' && in_array($detectedMime, ['text/plain', 'text/xml', 'image/svg+xml', 'image/svg'], true)) {
            // Valid SVG MIME
        } elseif (!in_array($detectedMime, $expectedMimes, true)) {
            return ['valid' => false, 'error' => "Format konten berkas tidak cocok (Terdeteksi: $detectedMime). Berkas diduga bukan format asli $ext."];
        }
    }

    // 4. Validasi Decompression Bomb & Integritas Gambar
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    if ($isImage && !empty($file['tmp_name']) && file_exists($file['tmp_name'])) {
        $imgInfo = @getimagesize($file['tmp_name']);
        if ($imgInfo === false) {
            return ['valid' => false, 'error' => 'Berkas gambar rusak atau tidak dapat didekode sebagai gambar valid.'];
        }

        $w = (int) ($imgInfo[0] ?? 0);
        $h = (int) ($imgInfo[1] ?? 0);
        if ($w > UPLOAD_MAX_IMAGE_WIDTH || $h > UPLOAD_MAX_IMAGE_HEIGHT) {
            return ['valid' => false, 'error' => "Dimensi gambar terlalu besar ({$w}x{$h}px). Batas resolusi maksimal " . UPLOAD_MAX_IMAGE_WIDTH . "x" . UPLOAD_MAX_IMAGE_HEIGHT . "px."];
        }
    }

    return [
        'valid' => true,
        'ext'   => $ext,
        'mime'  => $detectedMime,
        'size'  => $fileSize,
    ];
}

/**
 * Simpan Berkas Unggahan dengan Proteksi Lengkap & Format Kanonikal Konsisten
 * Mengembalikan path kanonikal 'uploads/<subfolder>/<filename>' untuk disimpan di basis data
 */
function saveSecureUpload(
    array $file,
    string $subfolder,
    array $allowedExts,
    array $allowedMimes = [],
    int $maxSizeBytes = 5242880,
    string $namePrefix = 'file'
): array {
    $check = verifyUploadedFile($file, $allowedExts, $allowedMimes, $maxSizeBytes);
    if (!$check['valid']) {
        return ['success' => false, 'error' => $check['error']];
    }

    $ext = $check['ext'];
    $subfolder = trim(str_replace(['..', '\\'], ['', '/'], $subfolder), '/');
    $uploadBaseDir = dirname(__DIR__) . '/uploads/' . $subfolder;

    if (!is_dir($uploadBaseDir)) {
        if (!@mkdir($uploadBaseDir, 0755, true)) {
            return ['success' => false, 'error' => 'Gagal membuat direktori penyimpanan server.'];
        }
    }

    // Buat index.html di dalam subfolder untuk memblokir direktori listing jika .htaccess tidak aktif
    $indexBlocker = $uploadBaseDir . '/index.html';
    if (!file_exists($indexBlocker)) {
        @file_put_contents($indexBlocker, '<!DOCTYPE html><html><head><title>Access Denied</title></head><body><h1>403 Forbidden</h1></body></html>');
    }

    // Generate Nama Berkas Acak Kriptografis
    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $namePrefix) ?: 'file';
    $randomBytes = bin2hex(random_bytes(6));
    $newFilename = "{$safePrefix}_" . date('Ymd_His') . "_{$randomBytes}.{$ext}";
    $destination = $uploadBaseDir . '/' . $newFilename;

    // Khusus SVG: Sanitasi sebelum ditulis ke disk
    if ($ext === 'svg') {
        $rawSvg = (string) @file_get_contents($file['tmp_name']);
        $cleanSvg = sanitizeSvgString($rawSvg);
        if (@file_put_contents($destination, $cleanSvg) === false) {
            return ['success' => false, 'error' => 'Gagal menyimpan berkas SVG yang telah disanitasi.'];
        }
    } else {
        if (!@move_uploaded_file($file['tmp_name'], $destination)) {
            return ['success' => false, 'error' => 'Gagal memindahkan berkas ke folder penyimpanan server.'];
        }
    }

    // Khusus JPEG / PNG / WEBP: Bersihkan Metadata EXIF (GPS Leaks)
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        stripExifMetadata($destination, $ext);
    }

    // Path kanonikal standar yang SELALU konsisten di seluruh aplikasi
    $canonicalPath = 'uploads/' . $subfolder . '/' . $newFilename;

    return [
        'success'        => true,
        'path'           => $canonicalPath,
        'filename'       => $newFilename,
        'full_disk_path' => $destination,
        'ext'            => $ext,
        'size'           => $check['size'],
    ];
}

/**
 * Simpan Data Gambar Base64 (Canvas Pad / Tanda Tangan / Webcam Selfie)
 */
function saveSecureBase64Image(
    string $base64Data,
    string $subfolder,
    int $maxSizeBytes = 2097152,
    string $namePrefix = 'canvas'
): array {
    $base64Data = trim($base64Data);
    if (!preg_match('#^data:image/(png|jpeg|webp);base64,(.+)$#i', $base64Data, $matches)) {
        return ['success' => false, 'error' => 'Format data gambar Base64 tidak valid.'];
    }

    $rawExt = strtolower($matches[1]);
    $ext = ($rawExt === 'jpeg') ? 'jpg' : $rawExt;
    $decodedData = base64_decode($matches[2], true);

    if ($decodedData === false) {
        return ['success' => false, 'error' => 'Gagal mendekode data biner Base64 gambar.'];
    }

    $decodedSize = strlen($decodedData);
    if ($decodedSize <= 0 || $decodedSize > $maxSizeBytes) {
        $maxMB = round($maxSizeBytes / (1024 * 1024), 1);
        return ['success' => false, 'error' => "Ukuran gambar goresan melebihi batas maksimal {$maxMB}MB."];
    }

    // Validasi integritas gambar via getimagesizefromstring
    if (function_exists('getimagesizefromstring')) {
        $info = @getimagesizefromstring($decodedData);
        if ($info === false) {
            return ['success' => false, 'error' => 'Data biner Base64 bukan gambar yang valid.'];
        }
    }

    $subfolder = trim(str_replace(['..', '\\'], ['', '/'], $subfolder), '/');
    $uploadBaseDir = dirname(__DIR__) . '/uploads/' . $subfolder;
    if (!is_dir($uploadBaseDir)) {
        @mkdir($uploadBaseDir, 0755, true);
    }

    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $namePrefix) ?: 'canvas';
    $newFilename = "{$safePrefix}_" . date('Ymd_His') . "_" . bin2hex(random_bytes(6)) . ".{$ext}";
    $destination = $uploadBaseDir . '/' . $newFilename;

    if (@file_put_contents($destination, $decodedData) === false) {
        return ['success' => false, 'error' => 'Gagal menulis file gambar Base64 ke disk server.'];
    }

    // Strip EXIF
    stripExifMetadata($destination, $ext);

    $canonicalPath = 'uploads/' . $subfolder . '/' . $newFilename;

    return [
        'success'        => true,
        'path'           => $canonicalPath,
        'filename'       => $newFilename,
        'full_disk_path' => $destination,
        'ext'            => $ext,
        'size'           => $decodedSize,
    ];
}

/**
 * Normalisasi URL Gambar & Berkas (Menyelesaikan Seluruh Broken Image 404)
 * 
 * Mengubah path warisan seperti:
 * - "../../uploads/exams/soal_1.jpg"
 * - "uploads/signatures/teacher_1.png"
 * - "news_12345.jpg"
 * Menjadi URL yang selalu akurat dan valid berdasarkan kedalaman direktori saat ini ($dashUrl).
 */
function resolveAssetUrl(?string $rawPath, string $dashUrl = ''): string {
    if (empty($rawPath)) {
        return '';
    }

    $rawPath = trim($rawPath);

    // 1. Tautan eksternal absolut atau data URI langsung dikembalikan apa adanya
    if (preg_match('#^(https?://|//|data:)#i', $rawPath)) {
        return $rawPath;
    }

    // 2. Bersihkan seluruh awalan path relatif warisan (../, ../../, ./)
    $cleanPath = preg_replace('#^(\.\./|\./)+#', '', $rawPath);
    $cleanPath = ltrim($cleanPath, '/\\');

    // 3. Tangani kasus file yang hanya berupa nama berkas (misal: "news_12345.jpg" atau "book_123.png")
    if (strpos($cleanPath, 'uploads/') !== 0) {
        if (strpos($cleanPath, 'news_') === 0) {
            $cleanPath = 'uploads/news/' . $cleanPath;
        } elseif (strpos($cleanPath, 'book_') === 0) {
            $cleanPath = 'uploads/books/' . $cleanPath;
        } elseif (strpos($cleanPath, 'soal_') === 0) {
            $cleanPath = 'uploads/exams/' . $cleanPath;
        } elseif (strpos($cleanPath, 'ann_') === 0) {
            $cleanPath = 'uploads/announcements/' . $cleanPath;
        } elseif (strpos($cleanPath, 'teacher_') === 0 || strpos($cleanPath, 'default_') === 0) {
            $cleanPath = 'uploads/signatures/' . $cleanPath;
        } else {
            // Default ke uploads jika tidak ada folder
            $cleanPath = 'uploads/' . $cleanPath;
        }
    }

    // 4. Hubungkan dengan base prefix ($dashUrl)
    // Jika $dashUrl kosong atau '', path menjadi 'uploads/...'
    // Jika $dashUrl '../', path menjadi '../uploads/...'
    if ($dashUrl !== '') {
        return rtrim($dashUrl, '/\\') . '/' . $cleanPath;
    }

    return $cleanPath;
}
