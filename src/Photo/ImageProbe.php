<?php

namespace Legitymacje\Photo;

/**
 * Pomiary wykonywane lokalnie, biblioteką GD - zdjęcie nie opuszcza serwera
 * i nigdy nie jest zapisywane na dysk. Wszystkie statystyki liczone są na
 * pomniejszonej kopii roboczej, żeby progi nie zależały od rozdzielczości
 * przesłanego pliku.
 */
class ImageProbe
{
    private const SUPPORTED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG];

    // Wycinek kadru, w którym na zdjęciu portretowym powinna być twarz.
    private const FACE_LEFT = 0.25;
    private const FACE_RIGHT = 0.75;
    private const FACE_TOP = 0.10;
    private const FACE_BOTTOM = 0.62;

    // Pasy przy krawędziach traktowane jako tło (dół pomijamy - tam są ramiona).
    private const BG_TOP_BAND = 0.10;
    private const BG_SIDE_BAND = 0.09;
    private const BG_SIDE_BOTTOM = 0.70;

    private string $path;
    private int $type;
    private int $orientation;

    /** @var resource|\GdImage */
    private $work;
    private int $w;
    private int $h;
    private int $srcWidth;
    private int $srcHeight;

    /** Jasność każdego piksela kopii roboczej, 1 bajt na piksel. */
    private string $luma;
    private float $colorfulness = 0.0;
    private float $skinRatioCenter = 0.0;

    private function __construct(string $path, int $type, int $orientation)
    {
        $this->path = $path;
        $this->type = $type;
        $this->orientation = $orientation;
    }

    public static function fromFile(string $path, int $workSize, int $maxMegapixels): self
    {
        if (!is_readable($path)) {
            throw new PhotoException('Nie można odczytać pliku zdjęcia.');
        }

        $info = @getimagesize($path);
        if ($info === false || !in_array($info[2], self::SUPPORTED_TYPES, true)) {
            throw new PhotoException('Plik nie jest obrazem JPG ani PNG.');
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1) {
            throw new PhotoException('Zdjęcie ma nieprawidłowe wymiary.');
        }

        if ($width * $height > $maxMegapixels * 1000000) {
            throw new PhotoException(sprintf('Zdjęcie ma zbyt wiele pikseli (%dx%d).', $width, $height));
        }

        self::assertEnoughMemory($width, $height);

        $probe = new self($path, $info[2], self::readOrientation($path, $info[2]));
        $full = $probe->loadOriented();

        $probe->srcWidth = imagesx($full);
        $probe->srcHeight = imagesy($full);

        $scale = min(1.0, $workSize / max($probe->srcWidth, $probe->srcHeight));
        $probe->w = max(1, (int) round($probe->srcWidth * $scale));
        $probe->h = max(1, (int) round($probe->srcHeight * $scale));
        $probe->work = self::resampleOnWhite($full, $probe->w, $probe->h);
        imagedestroy($full);

        $probe->scan();

        return $probe;
    }

    /**
     * Orientacja EXIF - telefony zapisują zdjęcie "poziomo" i dopiero ten
     * znacznik mówi, jak je obrócić. Bez tego pionowy portret potrafi zostać
     * zmierzony jako zdjęcie poziome.
     */
    private static function readOrientation(string $path, int $type): int
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);
        if (!is_array($exif) || !isset($exif['Orientation'])) {
            return 1;
        }

        $orientation = (int) $exif['Orientation'];

        return ($orientation >= 1 && $orientation <= 8) ? $orientation : 1;
    }

    public function width(): int
    {
        return $this->w;
    }

    public function height(): int
    {
        return $this->h;
    }

    public function sourceWidth(): int
    {
        return $this->srcWidth;
    }

    public function sourceHeight(): int
    {
        return $this->srcHeight;
    }

    public function colorfulness(): float
    {
        return $this->colorfulness;
    }

    public function skinRatioCenter(): float
    {
        return $this->skinRatioCenter;
    }

    /**
     * Jasność, kontrast i prześwietlenia w obszarze twarzy.
     */
    public function faceExposure(): array
    {
        return $this->statsForRects([$this->faceRect()]);
    }

    /**
     * Różnica jasności lewej i prawej połowy obszaru twarzy - duża wartość
     * oznacza światło padające tylko z jednej strony.
     */
    public function lightingSideDifference(): float
    {
        [$x0, $y0, $x1, $y1] = $this->faceRect();
        $mid = (int) (($x0 + $x1) / 2);

        $left = $this->statsForRects([[$x0, $y0, $mid, $y1]]);
        $right = $this->statsForRects([[$mid, $y0, $x1, $y1]]);

        return abs($left['mean'] - $right['mean']);
    }

    /**
     * Wariancja laplasjanu w obszarze twarzy - klasyczna miara ostrości.
     */
    public function sharpness(): float
    {
        return $this->laplacianForRects([$this->faceRect()])['variance'];
    }

    /**
     * Ilość szczegółów w środku kadru - odróżnia portret od zdjęcia pustej ściany.
     */
    public function subjectDetail(): float
    {
        return $this->laplacianForRects([$this->faceRect()])['mean_abs'];
    }

    /**
     * Jasność, jednolitość i "zaśmiecenie" tła.
     */
    public function background(): array
    {
        $rects = $this->backgroundRects();
        $stats = $this->statsForRects($rects);
        $edges = $this->laplacianForRects($rects);

        return [
            'brightness' => $stats['mean'],
            'uniformity' => $stats['stddev'],
            'edges' => $edges['mean_abs'],
        ];
    }

    /**
     * Zdjęcie przygotowane do wysyłki do API: obrócone zgodnie z EXIF,
     * pomniejszone i przekodowane na JPG. Przekodowanie usuwa wszystkie
     * metadane oryginału (model aparatu, data, lokalizacja GPS).
     */
    public function toJpeg(int $maxPx, int $quality): string
    {
        // Gdy kopia robocza jest już nie mniejsza niż żądany rozmiar, nie ma
        // po co dekodować oryginału drugi raz - to oszczędza pół sekundy przy
        // zdjęciach z telefonu.
        $target = min($maxPx, max($this->srcWidth, $this->srcHeight));
        $useWorkCopy = $target <= max($this->w, $this->h);

        $full = $useWorkCopy ? $this->work : $this->loadOriented();

        $scale = min(1.0, $maxPx / max(imagesx($full), imagesy($full)));
        $targetW = max(1, (int) round(imagesx($full) * $scale));
        $targetH = max(1, (int) round(imagesy($full) * $scale));

        $resized = self::resampleOnWhite($full, $targetW, $targetH);
        if (!$useWorkCopy) {
            imagedestroy($full);
        }

        ob_start();
        $ok = @imagejpeg($resized, null, max(1, min(100, $quality)));
        $bytes = (string) ob_get_clean();
        imagedestroy($resized);

        if (!$ok || $bytes === '') {
            throw new PhotoException('Nie udało się przygotować zdjęcia do wysyłki.');
        }

        return $bytes;
    }

    public function destroy(): void
    {
        if (isset($this->work)) {
            imagedestroy($this->work);
            unset($this->work);
        }
        $this->luma = '';
    }

    /**
     * Jeden przebieg po pikselach kopii roboczej: jasność, nasycenie kolorów
     * i udział odcieni skóry w środku kadru.
     */
    private function scan(): void
    {
        $w = $this->w;
        $h = $this->h;
        $this->luma = str_repeat("\0", $w * $h);

        [$fx0, $fy0, $fx1, $fy1] = $this->faceRect();

        $chromaSum = 0.0;
        $skinPixels = 0;
        $facePixels = 0;

        for ($y = 0; $y < $h; $y++) {
            $rowOffset = $y * $w;
            $inFaceRow = ($y >= $fy0 && $y < $fy1);

            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($this->work, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $yLuma = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $this->luma[$rowOffset + $x] = chr((int) $yLuma);

                $cb = 128.0 - 0.168736 * $r - 0.331264 * $g + 0.5 * $b;
                $cr = 128.0 + 0.5 * $r - 0.418688 * $g - 0.081312 * $b;
                $chromaSum += abs($cb - 128.0) + abs($cr - 128.0);

                if ($inFaceRow && $x >= $fx0 && $x < $fx1) {
                    $facePixels++;
                    if ($yLuma > 40.0 && $cb >= 77.0 && $cb <= 127.0 && $cr >= 133.0 && $cr <= 173.0) {
                        $skinPixels++;
                    }
                }
            }
        }

        $this->colorfulness = $chromaSum / ($w * $h);
        $this->skinRatioCenter = $facePixels > 0 ? ($skinPixels * 100.0 / $facePixels) : 0.0;
    }

    /**
     * @return array{0:int,1:int,2:int,3:int} [x0, y0, x1, y1] - x1/y1 wyłącznie
     */
    private function faceRect(): array
    {
        return [
            (int) ($this->w * self::FACE_LEFT),
            (int) ($this->h * self::FACE_TOP),
            (int) ceil($this->w * self::FACE_RIGHT),
            (int) ceil($this->h * self::FACE_BOTTOM),
        ];
    }

    private function backgroundRects(): array
    {
        $w = $this->w;
        $h = $this->h;
        $side = max(1, (int) ($w * self::BG_SIDE_BAND));
        $top = max(1, (int) ($h * self::BG_TOP_BAND));
        $sideBottom = (int) ($h * self::BG_SIDE_BOTTOM);

        return [
            [0, 0, $w, $top],
            [0, $top, $side, $sideBottom],
            [$w - $side, $top, $w, $sideBottom],
        ];
    }

    /**
     * @param array<int, array{0:int,1:int,2:int,3:int}> $rects
     */
    private function statsForRects(array $rects): array
    {
        $count = 0;
        $sum = 0.0;
        $sumSquares = 0.0;
        $dark = 0;
        $bright = 0;

        foreach ($rects as [$x0, $y0, $x1, $y1]) {
            [$x0, $y0, $x1, $y1] = $this->clampRect($x0, $y0, $x1, $y1);

            for ($y = $y0; $y < $y1; $y++) {
                $rowOffset = $y * $this->w;
                for ($x = $x0; $x < $x1; $x++) {
                    $value = ord($this->luma[$rowOffset + $x]);
                    $count++;
                    $sum += $value;
                    $sumSquares += $value * $value;
                    if ($value < 16) {
                        $dark++;
                    } elseif ($value > 245) {
                        $bright++;
                    }
                }
            }
        }

        if ($count === 0) {
            return ['mean' => 0.0, 'stddev' => 0.0, 'dark_pct' => 0.0, 'bright_pct' => 0.0];
        }

        $mean = $sum / $count;
        $variance = max(0.0, ($sumSquares / $count) - ($mean * $mean));

        return [
            'mean' => $mean,
            'stddev' => sqrt($variance),
            'dark_pct' => $dark * 100.0 / $count,
            'bright_pct' => $bright * 100.0 / $count,
        ];
    }

    /**
     * Laplasjan 4-sąsiedztwa: mean_abs mówi, ile jest krawędzi, variance -
     * jak bardzo są wyraźne.
     *
     * @param array<int, array{0:int,1:int,2:int,3:int}> $rects
     */
    private function laplacianForRects(array $rects): array
    {
        $count = 0;
        $sum = 0.0;
        $sumSquares = 0.0;
        $sumAbs = 0.0;

        foreach ($rects as [$x0, $y0, $x1, $y1]) {
            [$x0, $y0, $x1, $y1] = $this->clampRect($x0, $y0, $x1, $y1);

            $x0 = max(1, $x0);
            $y0 = max(1, $y0);
            $x1 = min($this->w - 1, $x1);
            $y1 = min($this->h - 1, $y1);

            for ($y = $y0; $y < $y1; $y++) {
                $rowOffset = $y * $this->w;
                for ($x = $x0; $x < $x1; $x++) {
                    $index = $rowOffset + $x;
                    $value = 4 * ord($this->luma[$index])
                        - ord($this->luma[$index - 1])
                        - ord($this->luma[$index + 1])
                        - ord($this->luma[$index - $this->w])
                        - ord($this->luma[$index + $this->w]);

                    $count++;
                    $sum += $value;
                    $sumSquares += $value * $value;
                    $sumAbs += abs($value);
                }
            }
        }

        if ($count === 0) {
            return ['variance' => 0.0, 'mean_abs' => 0.0];
        }

        $mean = $sum / $count;

        return [
            'variance' => max(0.0, ($sumSquares / $count) - ($mean * $mean)),
            'mean_abs' => $sumAbs / $count,
        ];
    }

    private function clampRect(int $x0, int $y0, int $x1, int $y1): array
    {
        return [
            max(0, min($this->w, $x0)),
            max(0, min($this->h, $y0)),
            max(0, min($this->w, $x1)),
            max(0, min($this->h, $y1)),
        ];
    }

    /**
     * @return resource|\GdImage
     */
    private function loadOriented()
    {
        $image = $this->type === IMAGETYPE_JPEG
            ? @imagecreatefromjpeg($this->path)
            : @imagecreatefrompng($this->path);

        if ($image === false) {
            throw new PhotoException('Nie udało się zdekodować zdjęcia.');
        }

        return self::applyOrientation($image, $this->orientation);
    }

    /**
     * @param resource|\GdImage $image
     * @return resource|\GdImage
     */
    private static function applyOrientation($image, int $orientation)
    {
        $white = 0xFFFFFF;

        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = self::rotate($image, 180, $white);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = self::rotate($image, -90, $white);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $image = self::rotate($image, -90, $white);
                break;
            case 7:
                $image = self::rotate($image, 90, $white);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = self::rotate($image, 90, $white);
                break;
        }

        return $image;
    }

    /**
     * @param resource|\GdImage $image
     * @return resource|\GdImage
     */
    private static function rotate($image, int $degrees, int $background)
    {
        $rotated = imagerotate($image, $degrees, $background);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * Skalowanie na białe tło - przezroczyste PNG staje się białe zamiast
     * czarnego, a obraz zawsze jest truecolor (paleta zwraca z imagecolorat
     * indeks koloru, nie kolor).
     *
     * @param resource|\GdImage $source
     * @return resource|\GdImage
     */
    private static function resampleOnWhite($source, int $targetW, int $targetH)
    {
        $canvas = imagecreatetruecolor($targetW, $targetH);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $white);
        imagealphablending($canvas, true);
        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            0,
            0,
            $targetW,
            $targetH,
            imagesx($source),
            imagesy($source)
        );

        return $canvas;
    }

    /**
     * GD trzyma obraz w pamięci jako 4 bajty na piksel - sprawdzamy z góry,
     * czy zmieści się w memory_limit, zamiast pozwolić na fatal error.
     */
    private static function assertEnoughMemory(int $width, int $height): void
    {
        $limit = self::memoryLimitBytes();
        if ($limit <= 0) {
            return;
        }

        $needed = (int) ($width * $height * 4 * 1.8);
        if (memory_get_usage(true) + $needed > $limit) {
            throw new PhotoException(sprintf('Zdjęcie za duże dla limitu pamięci PHP (%dx%d).', $width, $height));
        }
    }

    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $value = (int) $raw;
        switch (strtolower(substr($raw, -1))) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return $value;
        }
    }
}
