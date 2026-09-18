<?php

namespace Legitymacje\Photo;

/**
 * Kaskada Haara (Viola-Jones) wczytana z pliku binarnego przygotowanego
 * przez tools/convert_cascade.php.
 *
 * Dane klasyfikatora pochodzą z OpenCV - licencja w vendor/haarcascades/LICENSE.txt.
 *
 * Okno detektora ma stały rozmiar (24x24 dla twarzy). Zamiast skalować cechy,
 * skaluje się obraz - dzięki temu przesunięcia w obrazie całkowym liczone są
 * raz na poziom piramidy, a nie raz na okno.
 */
class HaarCascade
{
    private const MAGIC = 'LGTMHAAR';
    private const VERSION = 1;
    private const MAX_RECTS = 3;

    private int $windowW;
    private int $windowH;

    /** @var array<int, float> */
    private array $stageThresholds;
    /** @var array<int, int> */
    private array $stageWeakCounts;

    /** @var array<int, int> */
    private array $featureIndex;
    /** @var array<int, float> */
    private array $thresholds;
    /** @var array<int, float> */
    private array $leafLeft;
    /** @var array<int, float> */
    private array $leafRight;

    /** @var array<int, int> */
    private array $rectCounts;
    /** @var array<int, int> */
    private array $rectCoords;
    /** @var array<int, float> */
    private array $rectWeights;

    /** Przesunięcia w obrazie całkowym, przeliczane przy zmianie szerokości obrazu. */
    private int $stride = -1;
    /** @var array<int, int> */
    private array $offsets = [];
    /** @var array<int, int> */
    private array $normOffsets = [];

    private function __construct()
    {
    }

    public static function load(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) < 17) {
            throw new PhotoException('Nie udało się wczytać kaskady: ' . basename($path));
        }

        $header = unpack('a8magic/Cversion/CwindowW/CwindowH/vstages/vweak/vfeatures', $raw);
        if ($header['magic'] !== self::MAGIC || $header['version'] !== self::VERSION) {
            throw new PhotoException('Nieprawidłowy format pliku kaskady: ' . basename($path));
        }

        $cascade = new self();
        $cascade->windowW = $header['windowW'];
        $cascade->windowH = $header['windowH'];

        $stages = $header['stages'];
        $weak = $header['weak'];
        $features = $header['features'];

        $offset = 17;
        $take = static function (string $format, int $count, int $itemSize) use ($raw, &$offset): array {
            $values = unpack($format . $count, substr($raw, $offset, $count * $itemSize));
            $offset += $count * $itemSize;

            return array_values($values);
        };

        $cascade->stageThresholds = $take('g', $stages, 4);
        $cascade->stageWeakCounts = $take('v', $stages, 2);
        $cascade->featureIndex = $take('v', $weak, 2);
        $cascade->thresholds = $take('g', $weak, 4);
        $cascade->leafLeft = $take('g', $weak, 4);
        $cascade->leafRight = $take('g', $weak, 4);
        $cascade->rectCounts = $take('C', $features, 1);
        $cascade->rectCoords = $take('C', $features * self::MAX_RECTS * 4, 1);
        $cascade->rectWeights = $take('g', $features * self::MAX_RECTS, 4);

        return $cascade;
    }

    public function windowWidth(): int
    {
        return $this->windowW;
    }

    public function windowHeight(): int
    {
        return $this->windowH;
    }

    /**
     * Przeszukuje obraz całkowy oknem o stałym rozmiarze.
     *
     * @param array<int, int|float> $integral    obraz całkowy, (width+1) * (height+1)
     * @param array<int, int|float> $integralSq  obraz całkowy kwadratów jasności
     * @return array<int, array{0:int,1:int}> lewe górne rogi okien uznanych za twarz
     */
    public function scan(array $integral, array $integralSq, int $width, int $height, int $step): array
    {
        if ($width < $this->windowW || $height < $this->windowH) {
            return [];
        }

        $this->prepareOffsets($width + 1);

        // Kopie lokalne - w gorącej pętli dostęp do zmiennej jest wyraźnie
        // tańszy niż do właściwości obiektu.
        $ii = $integral;
        $ii2 = $integralSq;
        $offsets = $this->offsets;
        $rectWeights = $this->rectWeights;
        $rectCounts = $this->rectCounts;
        $featureIndex = $this->featureIndex;
        $thresholds = $this->thresholds;
        $leafLeft = $this->leafLeft;
        $leafRight = $this->leafRight;
        $stageThresholds = $this->stageThresholds;
        $stageWeakCounts = $this->stageWeakCounts;
        $stageCount = count($stageThresholds);

        [$n0, $n1, $n2, $n3] = $this->normOffsets;
        $normArea = ($this->windowW - 2) * ($this->windowH - 2);
        $stride = $width + 1;

        $maxX = $width - $this->windowW;
        $maxY = $height - $this->windowH;
        $hits = [];

        for ($y = 0; $y <= $maxY; $y += $step) {
            $rowBase = $y * $stride;

            for ($x = 0; $x <= $maxX; $x += $step) {
                $p = $rowBase + $x;

                $sum = $ii[$p + $n3] - $ii[$p + $n1] - $ii[$p + $n2] + $ii[$p + $n0];
                $sqSum = $ii2[$p + $n3] - $ii2[$p + $n1] - $ii2[$p + $n2] + $ii2[$p + $n0];

                // Normalizacja wariancją - bez niej kaskada reaguje na jasność,
                // a nie na kształt.
                $nf = $normArea * $sqSum - $sum * $sum;
                $nf = $nf > 0.0 ? sqrt($nf) : 1.0;

                $weak = 0;
                $passed = true;

                for ($s = 0; $s < $stageCount; $s++) {
                    $stageSum = 0.0;
                    $end = $weak + $stageWeakCounts[$s];

                    for (; $weak < $end; $weak++) {
                        $f = $featureIndex[$weak];
                        $o = $f * 12;
                        $w = $f * 3;

                        $value = $rectWeights[$w]
                                * ($ii[$p + $offsets[$o + 3]] - $ii[$p + $offsets[$o + 1]] - $ii[$p + $offsets[$o + 2]] + $ii[$p + $offsets[$o]])
                            + $rectWeights[$w + 1]
                                * ($ii[$p + $offsets[$o + 7]] - $ii[$p + $offsets[$o + 5]] - $ii[$p + $offsets[$o + 6]] + $ii[$p + $offsets[$o + 4]]);

                        if ($rectCounts[$f] > 2) {
                            $value += $rectWeights[$w + 2]
                                * ($ii[$p + $offsets[$o + 11]] - $ii[$p + $offsets[$o + 9]] - $ii[$p + $offsets[$o + 10]] + $ii[$p + $offsets[$o + 8]]);
                        }

                        $stageSum += ($value < $thresholds[$weak] * $nf) ? $leafLeft[$weak] : $leafRight[$weak];
                    }

                    if ($stageSum < $stageThresholds[$s]) {
                        $passed = false;
                        break;
                    }
                }

                if ($passed) {
                    $hits[] = [$x, $y];
                }
            }
        }

        return $hits;
    }

    /**
     * Zamienia współrzędne prostokątów cech na przesunięcia w obrazie całkowym
     * o danej szerokości wiersza. Liczone raz na poziom piramidy.
     */
    private function prepareOffsets(int $stride): void
    {
        if ($this->stride === $stride) {
            return;
        }

        $this->stride = $stride;
        $offsets = [];

        $count = count($this->rectCounts) * self::MAX_RECTS;
        for ($i = 0; $i < $count; $i++) {
            $c = $i * 4;
            $x = $this->rectCoords[$c];
            $y = $this->rectCoords[$c + 1];
            $w = $this->rectCoords[$c + 2];
            $h = $this->rectCoords[$c + 3];

            $offsets[] = $y * $stride + $x;
            $offsets[] = $y * $stride + $x + $w;
            $offsets[] = ($y + $h) * $stride + $x;
            $offsets[] = ($y + $h) * $stride + $x + $w;
        }

        $this->offsets = $offsets;

        // Prostokąt normalizacyjny: okno zwężone o 1 px z każdej strony.
        $nw = $this->windowW - 2;
        $nh = $this->windowH - 2;
        $this->normOffsets = [
            1 * $stride + 1,
            1 * $stride + 1 + $nw,
            (1 + $nh) * $stride + 1,
            (1 + $nh) * $stride + 1 + $nw,
        ];
    }
}
