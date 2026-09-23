<?php
/**
 * Dynamic Error Page Router
 * Manajemen-PHP
 * 
 * Penggunaan:
 *   header("Location: error/index.php?code=403");
 *   header("Location: error/index.php?code=404&title=Soal+Tidak+Ada");
 */

require_once __DIR__ . "/render.php";

$code = isset($_GET['code']) ? (int) $_GET['code'] : 404;
$customTitle = !empty($_GET['title']) ? trim($_GET['title']) : null;
$customDesc = !empty($_GET['desc']) ? trim($_GET['desc']) : null;

// Validasi kode HTTP yang didukung
$supportedCodes = [400, 401, 403, 404, 419, 500, 503];
if (!in_array($code, $supportedCodes, true)) {
    $code = 404;
}

renderErrorPage($code, $customTitle, $customDesc);
