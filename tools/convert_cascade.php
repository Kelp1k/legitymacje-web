<?php

declare(strict_types=1);

/**
 * Przerabia kaskadę Haara z XML-a OpenCV na zwarty plik binarny, który PHP
 * wczytuje jednym file_get_contents i kilkoma unpack() - zamiast parsować
 * megabajt XML-a przy każdym zgłoszeniu.
 *
 *     php tools/convert_cascade.php haarcascade_frontalface_default.xml vendor/haarcascades/frontalface.bin
 *
 * Pliki źródłowe pochodzą z https://github.com/opencv/opencv/tree/4.x/data/haarcascades
 * (licencja w vendor/haarcascades/LICENSE.txt). Obsługiwane są kaskady
 * "pniakowe" (jeden węzeł na słaby klasyfikator) - takie są wszystkie
 * kaskady twarzy i oczu z OpenCV.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrypt dostępny wyłącznie z linii poleceń.');
}

const MAGIC = 'LGTMHAAR';
const VERSION = 1;
const MAX_RECTS = 3;

$source = $argv[1] ?? null;
$target = $argv[2] ?? null;

if ($source === null || $target === null || !is_file($source)) {
    fwrite(STDERR, "Użycie: php tools/convert_cascade.php kaskada.xml wynik.bin\n");
    exit(2);
}

$xml = simplexml_load_file($source);
if ($xml === false || !isset($xml->cascade)) {
    fwrite(STDERR, "To nie jest kaskada OpenCV.\n");
    exit(1);
}

$cascade = $xml->cascade;
if ((string) $cascade->featureType !== 'HAAR') {
    fwrite(STDERR, sprintf("Obsługiwane są tylko kaskady HAAR (plik ma %s).\n", (string) $cascade->featureType));
    exit(1);
}

$windowW = (int) $cascade->width;
$windowH = (int) $cascade->height;

$stageThresholds = [];
$stageWeakCounts = [];
$featureIndexes = [];
$thresholds = [];
$leftLeaves = [];
$rightLeaves = [];

foreach ($cascade->stages->_ as $stage) {
    $stageThresholds[] = (float) $stage->stageThreshold;
    $weakInStage = 0;

    foreach ($stage->weakClassifiers->_ as $weak) {
        $nodes = preg_split('/\s+/', trim((string) $weak->internalNodes), -1, PREG_SPLIT_NO_EMPTY);
        if (count($nodes) !== 4) {
            fwrite(STDERR, "Kaskada nie jest pniakowa - ten konwerter jej nie obsłuży.\n");
            exit(1);
        }

        $leaves = preg_split('/\s+/', trim((string) $weak->leafValues), -1, PREG_SPLIT_NO_EMPTY);

        $featureIndexes[] = (int) $nodes[2];
        $thresholds[] = (float) $nodes[3];
        $leftLeaves[] = (float) $leaves[0];
        $rightLeaves[] = (float) $leaves[1];
        $weakInStage++;
    }

    $stageWeakCounts[] = $weakInStage;
}

$rectCounts = [];
$rectCoords = [];
$rectWeights = [];

foreach ($cascade->features->_ as $feature) {
    $rects = $feature->rects->_;
    $count = count($rects);
    if ($count > MAX_RECTS) {
        fwrite(STDERR, sprintf("Cecha ma %d prostokątów, obsługiwane są najwyżej %d.\n", $count, MAX_RECTS));
        exit(1);
    }

    $rectCounts[] = $count;

    for ($i = 0; $i < MAX_RECTS; $i++) {
        if ($i < $count) {
            $parts = preg_split('/\s+/', trim((string) $rects[$i]), -1, PREG_SPLIT_NO_EMPTY);
            $rectCoords[] = (int) $parts[0];
            $rectCoords[] = (int) $parts[1];
            $rectCoords[] = (int) $parts[2];
            $rectCoords[] = (int) $parts[3];
            $rectWeights[] = (float) $parts[4];
        } else {
            array_push($rectCoords, 0, 0, 0, 0);
            $rectWeights[] = 0.0;
        }
    }
}

$binary = pack('a8CCCvvv', MAGIC, VERSION, $windowW, $windowH, count($stageThresholds), count($featureIndexes), count($rectCounts))
    . pack('g*', ...$stageThresholds)
    . pack('v*', ...$stageWeakCounts)
    . pack('v*', ...$featureIndexes)
    . pack('g*', ...$thresholds)
    . pack('g*', ...$leftLeaves)
    . pack('g*', ...$rightLeaves)
    . pack('C*', ...$rectCounts)
    . pack('C*', ...$rectCoords)
    . pack('g*', ...$rectWeights);

if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
    fwrite(STDERR, "Nie udało się utworzyć katalogu docelowego.\n");
    exit(1);
}

file_put_contents($target, $binary);

printf(
    "%s -> %s\n  okno %dx%d, etapów %d, klasyfikatorów %d, cech %d, rozmiar %d KB\n",
    basename($source),
    $target,
    $windowW,
    $windowH,
    count($stageThresholds),
    count($featureIndexes),
    count($rectCounts),
    (int) round(strlen($binary) / 1024)
);
