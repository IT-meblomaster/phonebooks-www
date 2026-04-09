<?php
declare(strict_types=1);

$baseDir = '/mnt/photos';

if (!isset($_GET['f'])) {
    http_response_code(400);
    exit('Brak pliku');
}

$file = basename((string)$_GET['f']);
$path = rtrim($baseDir, '/') . '/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    exit('Nie znaleziono pliku');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $path);
finfo_close($finfo);

$allowed = [
    'image/jpeg',
    'image/png',
    'image/avif',
    'image/webp',
];

if (!in_array($mime, $allowed, true)) {
    http_response_code(415);
    exit('Nieobsługiwany format: ' . $mime);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;
