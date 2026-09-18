<?php

declare(strict_types=1);

/**
 * Sprawdza zdjęcie z linii poleceń, bez bazy danych i bez wysyłki maila.
 * Wypisuje zmierzone wartości - służy do dobrania progów w
 * src/config.php (sekcja 'photo_check').
 *
 *     php tools/check_photo.php zdjecie.jpg
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrypt dostępny wyłącznie z linii poleceń.');
}

require __DIR__ . '/../src/Validator.php';
require __DIR__ . '/../src/Photo/bootstrap.php';

use Legitymacje\Validator;
use Legitymacje\Photo\PhotoAnalyzer;

$path = $argv[1] ?? null;
if ($path === null || !is_file($path)) {
    fwrite(STDERR, "Użycie: php tools/check_photo.php ścieżka/do/zdjecia.jpg\n");
    exit(2);
}

$configPath = is_file(__DIR__ . '/../src/config.php')
    ? __DIR__ . '/../src/config.php'
    : __DIR__ . '/../src/config.example.php';

$config = require $configPath;

$formatErrors = Validator::validatePhoto([
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($path),
    'name' => basename($path),
    'tmp_name' => $path,
], $config['validation']);

$analyzer = new PhotoAnalyzer($config['photo_check'] ?? []);
$report = $analyzer->analyze($path);

printf("Plik: %s\n", $path);
printf("Konfiguracja: %s\n", basename($configPath));
printf("API twarzy: %s\n\n", $analyzer->faceApiName() ?? 'wyłączone (tylko analiza lokalna)');

echo "Pomiary:\n";
foreach ($report->metrics() as $key => $value) {
    printf("  %-18s %s\n", $key, is_float($value) ? sprintf('%.2f', $value) : (string) $value);
}

$errors = array_merge($formatErrors, $report->errors());

echo "\nBłędy (blokują zgłoszenie):\n";
foreach ($errors as $error) {
    echo '  - ' . $error . "\n";
}
if ($errors === []) {
    echo "  brak\n";
}

echo "\nUwagi (nie blokują):\n";
foreach ($report->warnings() as $warning) {
    echo '  - ' . $warning . "\n";
}
if ($report->warnings() === []) {
    echo "  brak\n";
}

printf("\nWynik: %s\n", $errors === [] ? 'zdjęcie przyjęte' : 'zdjęcie odrzucone');

exit($errors === [] ? 0 : 1);
