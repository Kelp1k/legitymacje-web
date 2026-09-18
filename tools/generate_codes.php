<?php

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/CodeStore.php';

use Legitymacje\Database;
use Legitymacje\CodeStore;

$count = isset($argv[1]) ? (int) $argv[1] : 500;
$codeLength = isset($argv[2]) ? (int) $argv[2] : 10;
$outputPath = $argv[3] ?? __DIR__ . '/../data/kody_' . date('Y-m-d_His') . '.csv';

$config = require __DIR__ . '/../src/config.php';
$db = Database::get($config['db']);
$codeStore = new CodeStore($db, $config['security']['code_pepper']);

function generateCode(int $length): string
{
    $max = (int) str_repeat('9', $length);
    $value = random_int(0, $max);
    return str_pad((string) $value, $length, '0', STR_PAD_LEFT);
}

// Kody trzymane jako wartosci, nie klucze: klucz tablicy "5631572973" PHP
// zamienia na int, co przy strict_types wysypuje CodeStore::hash(string).
$newCodes = [];
$seen = [];
while (count($newCodes) < $count) {
    $code = generateCode($codeLength);
    if (isset($seen[$code])) {
        continue;
    }
    $seen[$code] = true;
    $newCodes[] = $code;
}

$db->beginTransaction();
$stmt = $db->prepare('INSERT INTO codes (code_hash) VALUES (?)');
foreach ($newCodes as $code) {
    $stmt->execute([$codeStore->hash($code)]);
}
$db->commit();

$fh = fopen($outputPath, 'w');
fputcsv($fh, ['kod']);
foreach ($newCodes as $code) {
    fputcsv($fh, [$code]);
}
fclose($fh);

echo 'Wygenerowano ' . count($newCodes) . " kodow.\n";
echo 'Zapisano do: ' . $outputPath . "\n";
