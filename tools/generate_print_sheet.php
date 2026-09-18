<?php

declare(strict_types=1);

$csvPath = $argv[1] ?? null;
$outputPath = $argv[2] ?? __DIR__ . '/../data/kody_do_druku_' . date('Y-m-d_His') . '.html';

if ($csvPath === null) {
    $candidates = glob(__DIR__ . '/../data/kody_*.csv');
    sort($candidates);
    $csvPath = end($candidates) ?: null;
}

if ($csvPath === null || !is_file($csvPath)) {
    fwrite(STDERR, "Nie znaleziono pliku CSV z kodami. Podaj sciezke jako pierwszy argument.\n");
    exit(1);
}

$codes = [];
$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh);
while (($row = fgetcsv($fh)) !== false) {
    if (isset($row[0]) && $row[0] !== '') {
        $codes[] = $row[0];
    }
}
fclose($fh);
sort($codes);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatCode(string $code): string
{
    return implode(' ', str_split($code, 5));
}

$cells = '';
foreach ($codes as $code) {
    $cells .= '<div class="cell">' . e(formatCode($code)) . '</div>';
}

$count = count($codes);

$html = <<<HTML
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Kody do legitymacji - do druku ({$count} szt.)</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    color: #1d2433;
  }
  .sheet {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
  }
  .cell {
    padding: 3mm 2mm;
    border: 0.5pt dashed #6b7280;
    text-align: center;
    font-family: "Courier New", monospace;
    font-size: 12pt;
    font-weight: 700;
    letter-spacing: 0.3pt;
    white-space: nowrap;
    page-break-inside: avoid;
  }
  @page {
    size: A4;
    margin: 8mm;
  }
  @media print {
    .no-print { display: none; }
  }
</style>
</head>
<body>
<p class="no-print">Kody: {$count}. Wydrukuj (Ctrl/Cmd+P), potem potnij po liniach kreskowanych.</p>
<div class="sheet">
{$cells}
</div>
</body>
</html>
HTML;

file_put_contents($outputPath, $html);

echo "Zapisano {$count} kodow do: {$outputPath}\n";
