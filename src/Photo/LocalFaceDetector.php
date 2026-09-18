<?php

namespace Legitymacje\Photo;

/**
 * Wykrywanie twarzy kaskadą Haara, w całości na serwerze szkoły.
 *
 * Okno detektora ma stały rozmiar, więc zamiast skalować cechy budujemy
 * piramidę pomniejszonych kopii obrazu. Dla zdjęcia do legitymacji przeszukujemy
 * tylko te poziomy, na których twarz mogłaby mieć sensowną wielkość - dzięki
 * temu zamiast setek tysięcy okien sprawdzamy kilkanaście tysięcy.
 */
class LocalFaceDetector
{
    private HaarCascade $cascade;

    public function __construct(HaarCascade $cascade)
    {
        $this->cascade = $cascade;
    }

    /**
     * @param resource|\GdImage $image
     * @param float $minSizeRatio najmniejsza szukana twarz, jako ułamek szerokości obrazu
     * @param float $maxSizeRatio największa szukana twarz
     * @return array<int, array{x:int, y:int, w:int, h:int, neighbours:int}>
     */
    public function detect(
        $image,
        float $minSizeRatio = 0.20,
        float $maxSizeRatio = 0.80,
        float $scaleStep = 1.2,
        int $minNeighbours = 3,
        int $step = 1
    ): array {
        $srcW = imagesx($image);
        $srcH = imagesy($image);
        $windowW = $this->cascade->windowWidth();
        $windowH = $this->cascade->windowHeight();

        $minFace = max((float) $windowW, $minSizeRatio * $srcW);
        $maxFace = min((float) min($srcW, $srcH), $maxSizeRatio * $srcW);
        if ($maxFace < $minFace) {
            return [];
        }

        $candidates = [];

        // Od największej szukanej twarzy (najmocniej pomniejszony obraz)
        // do najmniejszej.
        for ($face = $maxFace; $face >= $minFace; $face /= $scaleStep) {
            $scale = $windowW / $face;
            $levelW = (int) round($srcW * $scale);
            $levelH = (int) round($srcH * $scale);

            if ($levelW < $windowW || $levelH < $windowH) {
                continue;
            }

            [$integral, $integralSq] = $this->integralImages($image, $levelW, $levelH);
            $hits = $this->cascade->scan($integral, $integralSq, $levelW, $levelH, $step);

            foreach ($hits as [$hx, $hy]) {
                $candidates[] = [
                    'x' => (int) round($hx / $scale),
                    'y' => (int) round($hy / $scale),
                    'w' => (int) round($windowW / $scale),
                    'h' => (int) round($windowH / $scale),
                ];
            }
        }

        return $this->group($candidates, $minNeighbours);
    }

    /**
     * Obraz całkowy jasności i kwadratów jasności dla pomniejszonej kopii.
     *
     * @param resource|\GdImage $image
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function integralImages($image, int $levelW, int $levelH): array
    {
        $level = imagecreatetruecolor($levelW, $levelH);
        imagecopyresampled($level, $image, 0, 0, 0, 0, $levelW, $levelH, imagesx($image), imagesy($image));

        $stride = $levelW + 1;
        $integral = array_fill(0, $stride * ($levelH + 1), 0);
        $integralSq = $integral;

        for ($y = 0; $y < $levelH; $y++) {
            $rowSum = 0;
            $rowSumSq = 0;
            $above = $y * $stride;
            $current = $above + $stride;

            for ($x = 0; $x < $levelW; $x++) {
                $rgb = imagecolorat($level, $x, $y);
                $gray = (int) (0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF));

                $rowSum += $gray;
                $rowSumSq += $gray * $gray;

                $integral[$current + $x + 1] = $integral[$above + $x + 1] + $rowSum;
                $integralSq[$current + $x + 1] = $integralSq[$above + $x + 1] + $rowSumSq;
            }
        }

        imagedestroy($level);

        return [$integral, $integralSq];
    }

    /**
     * Scala nakładające się trafienia. Prawdziwa twarz jest znajdowana wiele
     * razy - na sąsiednich pozycjach i poziomach piramidy - a przypadkowe
     * pobudzenie zwykle raz. Grupy mniejsze niż $minNeighbours odrzucamy.
     *
     * @param array<int, array{x:int,y:int,w:int,h:int}> $rects
     * @return array<int, array{x:int,y:int,w:int,h:int,neighbours:int}>
     */
    private function group(array $rects, int $minNeighbours): array
    {
        $count = count($rects);
        if ($count === 0) {
            return [];
        }

        $parent = range(0, $count - 1);

        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }

            return $i;
        };

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (!$this->similar($rects[$i], $rects[$j])) {
                    continue;
                }

                $rootA = $find($i);
                $rootB = $find($j);
                if ($rootA !== $rootB) {
                    $parent[$rootB] = $rootA;
                }
            }
        }

        $groups = [];
        for ($i = 0; $i < $count; $i++) {
            $root = $find($i);
            if (!isset($groups[$root])) {
                $groups[$root] = ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0, 'neighbours' => 0];
            }

            $groups[$root]['x'] += $rects[$i]['x'];
            $groups[$root]['y'] += $rects[$i]['y'];
            $groups[$root]['w'] += $rects[$i]['w'];
            $groups[$root]['h'] += $rects[$i]['h'];
            $groups[$root]['neighbours']++;
        }

        $result = [];
        foreach ($groups as $group) {
            $n = $group['neighbours'];
            if ($n < $minNeighbours) {
                continue;
            }

            $result[] = [
                'x' => (int) round($group['x'] / $n),
                'y' => (int) round($group['y'] / $n),
                'w' => (int) round($group['w'] / $n),
                'h' => (int) round($group['h'] / $n),
                'neighbours' => $n,
            ];
        }

        usort($result, static fn(array $a, array $b): int => ($b['w'] * $b['h']) <=> ($a['w'] * $a['h']));

        return $result;
    }

    /**
     * @param array{x:int,y:int,w:int,h:int} $a
     * @param array{x:int,y:int,w:int,h:int} $b
     */
    private function similar(array $a, array $b, float $eps = 0.25): bool
    {
        $delta = $eps * (min($a['w'], $b['w']) + min($a['h'], $b['h'])) * 0.5;

        return abs($a['x'] - $b['x']) <= $delta
            && abs($a['y'] - $b['y']) <= $delta
            && abs(($a['x'] + $a['w']) - ($b['x'] + $b['w'])) <= $delta
            && abs(($a['y'] + $a['h']) - ($b['y'] + $b['h'])) <= $delta;
    }
}
