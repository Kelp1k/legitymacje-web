<?php

namespace Legitymacje;

class Validator
{
    private const NAME_WORD_RE = '/^\p{L}+(-\p{L}+)?$/u';

    public static function parseName(string $raw, array $cfg): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, ['Podaj imię i nazwisko.']];
        }

        $words = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);

        if (count($words) < $cfg['min_name_words'] || count($words) > $cfg['max_name_words']) {
            return [null, ['Podaj samo imię i nazwisko (np. "Jan Kowalski").']];
        }

        foreach ($words as $word) {
            if (!preg_match(self::NAME_WORD_RE, $word)) {
                return [null, ['Imię i nazwisko może zawierać wyłącznie litery (bez cyfr i znaków specjalnych).']];
            }
        }

        $capitalized = array_map(static function (string $word): string {
            $parts = explode('-', $word);
            $parts = array_map(static function (string $p): string {
                $lower = mb_strtolower($p, 'UTF-8');
                return mb_strtoupper(mb_substr($lower, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($lower, 1, null, 'UTF-8');
            }, $parts);
            return implode('-', $parts);
        }, $words);

        return [implode(' ', $capitalized), []];
    }

    public static function parseClass(string $raw, array $cfg): array
    {
        $raw = trim($raw);
        if ($raw === '' || !in_array($raw, $cfg['classes'], true)) {
            return [null, ['Wybierz klasę z listy.']];
        }

        return [$raw, []];
    }

    public static function parseEmail(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, ['Podaj adres e-mail.']];
        }

        if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return [null, ['Podany adres e-mail wygląda na nieprawidłowy.']];
        }

        return [$raw, []];
    }

    public static function validatePhoto(array $file, array $cfg): array
    {
        $errors = [];

        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['Nie wybrano pliku ze zdjęciem.'];
        }

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return ['Plik zdjęcia jest zbyt duży.'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['Nie udało się przesłać pliku - spróbuj ponownie.'];
        }

        $sizeKb = $file['size'] / 1024;
        if ($sizeKb < $cfg['min_file_size_kb']) {
            $errors[] = sprintf(
                'Plik zdjęcia jest zbyt mały (%d KB) - minimalny rozmiar to %d KB.',
                (int) round($sizeKb),
                $cfg['min_file_size_kb']
            );
        }
        if ($sizeKb > $cfg['max_file_size_kb']) {
            $errors[] = sprintf(
                'Plik zdjęcia jest zbyt duży (%.1f MB) - maksymalny rozmiar to %d MB.',
                $sizeKb / 1024,
                (int) ($cfg['max_file_size_kb'] / 1024)
            );
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $cfg['allowed_extensions'], true)) {
            $errors[] = 'Nieprawidłowy format pliku - dozwolone formaty to JPG i PNG.';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $cfg['allowed_mime_types'], true)) {
            $errors[] = 'Nieprawidłowy format pliku - dozwolone formaty to JPG i PNG.';
        }

        $size = @getimagesize($file['tmp_name']);
        if ($size === false) {
            $errors[] = 'Nie udało się otworzyć przesłanego pliku jako zdjęcia - plik może być uszkodzony.';
            return $errors;
        }

        [$width, $height] = self::orientedSize($file['tmp_name'], (int) $size[0], (int) $size[1]);
        if ($width < $cfg['min_width_px'] || $height < $cfg['min_height_px']) {
            $errors[] = sprintf(
                'Zdjęcie ma zbyt niską rozdzielczość (%dx%d px) - minimalna wymagana rozdzielczość to %dx%d px.',
                $width,
                $height,
                $cfg['min_width_px'],
                $cfg['min_height_px']
            );
        }

        if ($height > 0) {
            $expectedRatio = $cfg['aspect_ratio_width'] / $cfg['aspect_ratio_height'];
            $actualRatio = $width / $height;
            if (abs($actualRatio - $expectedRatio) / $expectedRatio > $cfg['aspect_ratio_tolerance']) {
                $errors[] = sprintf(
                    'Zdjęcie ma nieprawidłowe proporcje (aktualnie %dx%d px) - zdjęcie do legitymacji powinno być pionowe (portret), w proporcjach zbliżonych do %dx%d mm.',
                    $width,
                    $height,
                    $cfg['aspect_ratio_width'],
                    $cfg['aspect_ratio_height']
                );
            }
        }

        return $errors;
    }

    private static function orientedSize(string $path, int $width, int $height): array
    {
        if (!function_exists('exif_read_data')) {
            return [$width, $height];
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;

        return in_array($orientation, [5, 6, 7, 8], true) ? [$height, $width] : [$width, $height];
    }
}
