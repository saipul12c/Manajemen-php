<?php
/**
 * Dynamic Error Page Router & Dispatcher Terproteksi
 * Manajemen-PHP
 * 
 * Penggunaan:
 *   header("Location: " . $baseUrl . "error/index.php?code=403");
 *   header("Location: " . $baseUrl . "error/index.php?code=404&title=Soal+Tidak+Ada");
 * 
 * Keamanan:
 * - Sanitasi parameter GET terhadap XSS, CRLF Injection, & Content Spoofing
 * - Validasi kode status HTTP terhadap whitelist standar
 * - Proteksi phishing URL injection pada custom message
 */

require_once __DIR__ . "/render.php";

$rawCode = isset($_GET['code']) ? filter_var($_GET['code'], FILTER_VALIDATE_INT) : 404;
$code = ($rawCode !== false && $rawCode >= 400 && $rawCode <= 599) ? (int) $rawCode : 404;

// Whitelist kode HTTP yang didukung penuh oleh sistem
$supportedCodes = [
    400, 401, 402, 403, 404, 405, 408, 409, 410,
    413, 414, 415, 419, 422, 429, 500, 502, 503, 504
];

if (!in_array($code, $supportedCodes, true)) {
    $code = 404;
}

// Sanitasi parameter judul & deskripsi kustom (Proteksi Content Spoofing / Phishing Injection)
$customTitle = null;
if (!empty($_GET['title']) && is_string($_GET['title'])) {
    $sanitizedTitle = strip_tags($_GET['title']);
    $sanitizedTitle = preg_replace('/[\r\n\t]+/', ' ', $sanitizedTitle);
    // Tolak atau bersihkan jika mengandung indikasi tautan luar phishing
    if (preg_match('#(https?://|javascript:|data:|www\.)#i', $sanitizedTitle)) {
        $sanitizedTitle = preg_replace('#(https?://|javascript:|data:|www\.)[^\s]+#i', '', $sanitizedTitle);
    }
    $sanitizedTitle = function_exists('mb_substr') ? mb_substr(trim($sanitizedTitle), 0, 100, 'UTF-8') : substr(trim($sanitizedTitle), 0, 100);
    if ($sanitizedTitle !== '') {
        $customTitle = $sanitizedTitle;
    }
}

$customDesc = null;
if (!empty($_GET['desc']) && is_string($_GET['desc'])) {
    $sanitizedDesc = strip_tags($_GET['desc']);
    $sanitizedDesc = preg_replace('/[\r\n\t]+/', ' ', $sanitizedDesc);
    // Bersihkan URL eksternal agar tidak disalahgunakan untuk penipuan (phishing)
    if (preg_match('#(https?://|javascript:|data:|www\.)#i', $sanitizedDesc)) {
        $sanitizedDesc = preg_replace('#(https?://|javascript:|data:|www\.)[^\s]+#i', '', $sanitizedDesc);
    }
    $sanitizedDesc = function_exists('mb_substr') ? mb_substr(trim($sanitizedDesc), 0, 400, 'UTF-8') : substr(trim($sanitizedDesc), 0, 400);
    if ($sanitizedDesc !== '') {
        $customDesc = $sanitizedDesc;
    }
}

renderErrorPage($code, $customTitle, $customDesc);
