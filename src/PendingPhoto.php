<?php

namespace Legitymacje;

/**
 * Przechowalnia zdjęcia czekającego na potwierdzenie przez ucznia.
 *
 * Gdy automatyczna kontrola ma do zdjęcia uwagi, nie wysyłamy go od razu -
 * uczeń najpierw je widzi i decyduje, czy wysyła, czy wybiera lepsze. Między
 * tymi dwoma żądaniami plik musi gdzieś poczekać, bo przeglądarka nie odsyła
 * raz wybranego pliku drugi raz.
 *
 * Plik leży poza katalogiem dostępnym z przeglądarki, pod losową nazwą, z
 * prawami 0600, i znika zaraz po wysłaniu albo rezygnacji. Porzucone pliki
 * kasuje cleanup() po upływie ustalonego czasu.
 */
class PendingPhoto
{
    private const TOKEN_RE = '/^[a-f0-9]{32}$/';

    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
    }

    /**
     * Przenosi przesłany plik do przechowalni i zwraca token, pod którym
     * można go później odczytać. Zwraca null, gdy się nie udało.
     */
    public function store(string $uploadedTmpPath): ?string
    {
        if (!$this->ensureDirectory()) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $target = $this->pathForToken($token);

        if (!move_uploaded_file($uploadedTmpPath, $target)) {
            error_log('Nie udalo sie odlozyc zdjecia do potwierdzenia.');

            return null;
        }

        chmod($target, 0600);

        return $token;
    }

    /**
     * Odkłada gotowe bajty obrazu (np. kadr wycięty ze skanu kartki) i zwraca
     * token. store() tu nie wystarcza, bo move_uploaded_file() przyjmuje
     * wyłącznie plik faktycznie przesłany przez przeglądarkę.
     */
    public function storeContents(string $bytes): ?string
    {
        if ($bytes === '' || !$this->ensureDirectory()) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $target = $this->pathForToken($token);

        if (@file_put_contents($target, $bytes, LOCK_EX) === false) {
            error_log('Nie udalo sie odlozyc wycietego kadru do potwierdzenia.');

            return null;
        }

        chmod($target, 0600);

        return $token;
    }

    public function path(string $token): ?string
    {
        if (!preg_match(self::TOKEN_RE, $token)) {
            return null;
        }

        $path = $this->pathForToken($token);

        return is_file($path) ? $path : null;
    }

    public function delete(string $token): void
    {
        $path = $this->path($token);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * Kasuje pliki porzucone przez uczniów, którzy nie dokończyli zgłoszenia.
     */
    public function cleanup(int $ttlMinutes): void
    {
        $files = glob($this->directory . '/*.photo');
        if ($files === false) {
            return;
        }

        $deadline = time() - ($ttlMinutes * 60);
        foreach ($files as $file) {
            if (@filemtime($file) < $deadline) {
                @unlink($file);
            }
        }
    }

    private function pathForToken(string $token): string
    {
        return $this->directory . '/' . $token . '.photo';
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }

        if (!@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            error_log('Brak katalogu na zdjecia do potwierdzenia: ' . $this->directory);

            return false;
        }

        return true;
    }
}
