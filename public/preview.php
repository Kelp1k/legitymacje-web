<?php

declare(strict_types=1);

/**
 * Podgląd zdjęcia czekającego na decyzję ucznia.
 *
 * Token bierzemy WYŁĄCZNIE z sesji, nigdy z parametru w adresie - inaczej
 * dałoby się podejrzeć zdjęcie z cudzej sesji, znając albo zgadując token.
 * Plik leży poza katalogiem dostępnym z przeglądarki, więc ten skrypt jest
 * jedyną drogą do niego.
 */

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header_remove('X-Powered-By');

require __DIR__ . '/../src/PendingPhoto.php';

use Legitymacje\PendingPhoto;

$token = $_SESSION['crop']['token'] ?? $_SESSION['pending']['token'] ?? null;

if (!is_string($token)) {
    http_response_code(404);
    exit;
}

$path = (new PendingPhoto(__DIR__ . '/../data/pending'))->path($token);

if ($path === null) {
    http_response_code(404);
    exit;
}

$mime = (string) @mime_content_type($path);
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline');
readfile($path);
