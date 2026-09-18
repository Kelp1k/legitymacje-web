<?php

declare(strict_types=1);

namespace Legitymacje\Photo;

/**
 * Rozpoznaje skan kartki ze wklejonym/wydrukowanym zdjęciem i wycina z niego
 * samo zdjęcie.
 *
 * Powód: proporcje A4 (0,707) mieszczą się w tolerancji 15% wobec 35:45
 * (0,778) - odchylenie to tylko 9,1% - więc kontrola proporcji takiego skanu
 * nie łapie. Bez tej klasy uczeń dostaje komunikat "zdjęcie jest
 * prześwietlone", bo 96% kadru to biała kartka, i nie ma pojęcia, co poprawić.
 *
 * Wykrywanie działa na konturze: kolor papieru bierzemy z brzegów kadru, potem
 * szukamy prostokąta otaczającego piksele, które się od niego różnią. Jeśli ten
 * prostokąt zajmuje niewielką część kadru, to jest to zdjęcie na kartce, a nie
 * portret - portret wypełnia kadr niemal cały.
 */
class DocumentScan
{
    private string $sourcePath;
    private int $imageType;
    private int $cropX;
    private int $cropY;
    private int $cropWidth;
    private int $cropHeight;
    private bool $cropUsable;

    private function __construct(
        string $sourcePath,
        int $imageType,
        int $cropX,
        int $cropY,
        int $cropWidth,
        int $cropHeight,
        bool $cropUsable
    ) {
        $this->sourcePath = $sourcePath;
        $this->imageType = $imageType;
        $this->cropX = $cropX;
        $this->cropY = $cropY;
        $this->cropWidth = $cropWidth;
        $this->cropHeight = $cropHeight;
        $this->cropUsable = $cropUsable;
    }

    public function cropWidth(): int
    {
        return $this->cropWidth;
    }

    public function cropHeight(): int
    {
        return $this->cropHeight;
    }

    /**
     * Czy wycięty kadr ma proporcje na tyle zbliżone do zdjęcia do
     * legitymacji, żeby warto go było w ogóle pokazywać. Gdy ktoś zeskanuje
     * formularz z wpisanymi danymi obok zdjęcia, kontur obejmie także tekst i
     * przycięcie wyszłoby bezsensowne - wtedy to jest false.
     */
    public function cropUsable(): bool
    {
        return $this->cropUsable;
    }

    /**
     * Tani wstępny filtr na już policzonych pomiarach z PhotoAnalyzer.
     *
     * Pełne wykrywanie wymaga ponownego zdekodowania zdjęcia, co na skanie
     * 15 Mpx kosztuje kilka sekund. Nie ma sensu płacić tego przy każdym
     * zgłoszeniu: skan kartki zawsze ma ogromną białą powierzchnię, a tę
     * wartość analizator i tak już zmierzył.
     */
    public static function suspectedFrom(array $metrics, array $cfg): bool
    {
        $white = (float) ($metrics['biale_proc'] ?? 0.0);
        $faces = (int) ($metrics['twarze'] ?? 0);

        return $white >= (float) $cfg['suspect_white_pct'] && $faces === 0;
    }

    /**
     * Zwraca null, gdy zdjęcie nie wygląda na skan kartki.
     */
    public static function detect(string $path, array $cfg): ?self
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        [$width, $height] = $info;
        $type = $info[2];
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $width < 1 || $height < 1) {
            return null;
        }

        $probeWidth = max(80, (int) $cfg['probe_size_px']);
        $scale = min(1.0, $probeWidth / max($width, $height));
        $pw = max(1, (int) round($width * $scale));
        $ph = max(1, (int) round($height * $scale));

        $full = $type === IMAGETYPE_PNG ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if ($full === false) {
            return null;
        }

        $small = imagecreatetruecolor($pw, $ph);
        imagefilledrectangle($small, 0, 0, $pw, $ph, imagecolorallocate($small, 255, 255, 255));
        imagecopyresampled($small, $full, 0, 0, 0, 0, $pw, $ph, $width, $height);
        imagedestroy($full);

        $paper = self::paperLuminance($small, $pw, $ph);

        // Skan kartki ma jasne tło. Ciemne tło oznacza zwykłe zdjęcie.
        if ($paper < (float) $cfg['min_paper_luminance']) {
            imagedestroy($small);

            return null;
        }

        $box = self::largestBlock($small, $pw, $ph, $paper, (float) $cfg['content_delta']);
        imagedestroy($small);

        if ($box === null) {
            return null;
        }

        [$bx, $by, $bw, $bh, $filled] = $box;

        // Sedno rozpoznania: treść zajmuje tylko wycinek kartki. Portret
        // wypełnia kadr, więc tu nie wejdzie.
        $areaRatio = ($bw * $bh) / ($pw * $ph);
        if ($areaRatio > (float) $cfg['max_content_ratio']) {
            return null;
        }

        // Skala z kopii roboczej na oryginał.
        $fx = $width / $pw;
        $fy = $height / $ph;
        $rawX = (int) floor($bx * $fx);
        $rawY = (int) floor($by * $fy);
        $rawW = (int) ceil($bw * $fx);
        $rawH = (int) ceil($bh * $fy);

        $targetRatio = (float) $cfg['target_ratio'];
        $rawRatio = $rawH > 0 ? $rawW / $rawH : 0.0;

        // Zdjęcie na kartce jest litym prostokątem, więc jego obszar wypełnia
        // niemal cały swój opisany prostokąt. Napis albo podpis daje obszar
        // rozstrzelony - i takiego kadru nie ma sensu proponować.
        $density = ($bw * $bh) > 0 ? $filled / ($bw * $bh) : 0.0;

        $usable = $rawRatio > 0.0
            && $density >= (float) $cfg['min_block_density']
            && abs($rawRatio - $targetRatio) / $targetRatio <= (float) $cfg['crop_ratio_tolerance'];

        [$cx, $cy, $cw, $ch] = self::fitToRatio($rawX, $rawY, $rawW, $rawH, $width, $height, $targetRatio);

        return new self($path, $type, $cx, $cy, $cw, $ch, $usable);
    }

    /**
     * Wycięte zdjęcie jako JPEG. Zwraca null, gdy się nie udało.
     */
    public function cropJpeg(int $quality = 92): ?string
    {
        $full = $this->imageType === IMAGETYPE_PNG
            ? @imagecreatefrompng($this->sourcePath)
            : @imagecreatefromjpeg($this->sourcePath);

        if ($full === false) {
            return null;
        }

        $crop = imagecrop($full, [
            'x' => $this->cropX,
            'y' => $this->cropY,
            'width' => $this->cropWidth,
            'height' => $this->cropHeight,
        ]);
        imagedestroy($full);

        if ($crop === false) {
            return null;
        }

        ob_start();
        $ok = imagejpeg($crop, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($crop);

        return $ok && $bytes !== '' ? $bytes : null;
    }

    /**
     * Kolor papieru: mediana jasności pikseli z czterech brzegów kadru.
     * Mediana, a nie średnia, żeby ciemny zagięty róg nie przesunął wyniku.
     */
    private static function paperLuminance(\GdImage $img, int $w, int $h): float
    {
        $samples = [];
        $stepX = max(1, (int) ($w / 60));
        $stepY = max(1, (int) ($h / 60));

        for ($x = 0; $x < $w; $x += $stepX) {
            $samples[] = self::luminance($img, $x, 0);
            $samples[] = self::luminance($img, $x, $h - 1);
        }
        for ($y = 0; $y < $h; $y += $stepY) {
            $samples[] = self::luminance($img, 0, $y);
            $samples[] = self::luminance($img, $w - 1, $y);
        }

        if ($samples === []) {
            return 0.0;
        }

        sort($samples);

        return $samples[intdiv(count($samples), 2)];
    }

    /**
     * Największy spójny obszar pikseli różniących się od papieru.
     *
     * Globalny prostokąt otaczający całą treść tu nie wystarcza: przy skanie
     * formularza obejmowałby zdjęcie razem z wpisanym imieniem i podpisem, a
     * takie przycięcie jest bezużyteczne. Zdjęcie jest jednym dużym obszarem,
     * a litery dziesiątkami małych, więc bierzemy ten największy.
     *
     * Zwraca [x, y, szerokosc, wysokosc, liczba_pikseli_obszaru].
     */
    private static function largestBlock(\GdImage $img, int $w, int $h, float $paper, float $delta): ?array
    {
        $mask = [];
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                if (abs(self::luminance($img, $x, $y) - $paper) > $delta) {
                    $mask[$row + $x] = true;
                }
            }
        }

        if ($mask === []) {
            return null;
        }

        $best = null;
        $seen = [];

        foreach (array_keys($mask) as $start) {
            if (isset($seen[$start])) {
                continue;
            }

            // Iteracyjnie, nie rekurencyjnie - obszar zdjęcia na kartce ma
            // dziesiątki tysięcy pikseli i rekurencja przepełniłaby stos.
            $stack = [$start];
            $seen[$start] = true;
            $area = 0;
            $minX = $w;
            $maxX = -1;
            $minY = $h;
            $maxY = -1;

            while ($stack !== []) {
                $index = array_pop($stack);
                $x = $index % $w;
                $y = intdiv($index, $w);

                $area++;
                if ($x < $minX) { $minX = $x; }
                if ($x > $maxX) { $maxX = $x; }
                if ($y < $minY) { $minY = $y; }
                if ($y > $maxY) { $maxY = $y; }

                if ($x > 0) {
                    $n = $index - 1;
                    if (isset($mask[$n]) && !isset($seen[$n])) { $seen[$n] = true; $stack[] = $n; }
                }
                if ($x < $w - 1) {
                    $n = $index + 1;
                    if (isset($mask[$n]) && !isset($seen[$n])) { $seen[$n] = true; $stack[] = $n; }
                }
                if ($y > 0) {
                    $n = $index - $w;
                    if (isset($mask[$n]) && !isset($seen[$n])) { $seen[$n] = true; $stack[] = $n; }
                }
                if ($y < $h - 1) {
                    $n = $index + $w;
                    if (isset($mask[$n]) && !isset($seen[$n])) { $seen[$n] = true; $stack[] = $n; }
                }
            }

            if ($best === null || $area > $best[4]) {
                $best = [$minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1, $area];
            }
        }

        if ($best === null || $best[2] < 2 || $best[3] < 2) {
            return null;
        }

        return $best;
    }

    /**
     * Rozszerza prostokąt do docelowych proporcji, trzymając się w granicach
     * obrazu. Rozszerzamy, nigdy nie obcinamy, żeby nie uciąć czubka głowy.
     */
    private static function fitToRatio(
        int $x,
        int $y,
        int $w,
        int $h,
        int $imgW,
        int $imgH,
        float $ratio
    ): array {
        $targetW = $w;
        $targetH = $h;

        if ($h > 0 && ($w / $h) > $ratio) {
            $targetH = (int) round($w / $ratio);
        } else {
            $targetW = (int) round($h * $ratio);
        }

        // Nie zmieścimy się w obrazie - zejdź do tego, co jest. Proporcje
        // wyjdą wtedy niedokładne, ale zwykła walidacja to wychwyci.
        $targetW = min($targetW, $imgW);
        $targetH = min($targetH, $imgH);

        $cx = $x + intdiv($w, 2);
        $cy = $y + intdiv($h, 2);
        $nx = max(0, min($imgW - $targetW, $cx - intdiv($targetW, 2)));
        $ny = max(0, min($imgH - $targetH, $cy - intdiv($targetH, 2)));

        return [$nx, $ny, $targetW, $targetH];
    }

    private static function luminance(\GdImage $img, int $x, int $y): float
    {
        $rgb = imagecolorat($img, $x, $y);

        return ((($rgb >> 16) & 255) * 299 + (($rgb >> 8) & 255) * 587 + ($rgb & 255) * 114) / 1000;
    }
}
