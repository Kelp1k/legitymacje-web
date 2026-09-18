<?php

namespace Legitymacje\Photo;

/**
 * Minimalny klient HTTPS dla API rozpoznawania twarzy.
 *
 * Założenia bezpieczeństwa:
 * - tylko https (wyjątkiem może być instancja na 127.0.0.1, jeśli wprost
 *   na to pozwolono w konfiguracji),
 * - weryfikacja certyfikatu i nazwy hosta zawsze włączona, TLS min. 1.2,
 *   opcjonalnie przypięcie klucza publicznego,
 * - brak podążania za przekierowaniami (serwer API nie może przekierować
 *   zdjęcia w inne miejsce),
 * - treść żądania budowana w pamięci - zdjęcie nie trafia na dysk,
 * - twardy limit rozmiaru odpowiedzi i czasu oczekiwania,
 * - komunikaty błędów nie zawierają nagłówków ani treści żądania, więc
 *   klucz API nie wycieknie do logów.
 */
class HttpClient
{
    private const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    private int $timeout;
    private int $connectTimeout;
    private string $caBundle;
    private string $pinnedPublicKey;
    private int $maxResponseBytes;
    private bool $allowLocalHttp;

    public function __construct(array $options = [])
    {
        if (!function_exists('curl_init')) {
            throw new PhotoException('Rozpoznawanie twarzy wymaga rozszerzenia PHP cURL.');
        }

        $this->timeout = max(1, (int) ($options['timeout_seconds'] ?? 8));
        $this->connectTimeout = max(1, (int) ($options['connect_timeout_seconds'] ?? 4));
        $this->caBundle = (string) ($options['ca_bundle'] ?? '');
        $this->pinnedPublicKey = (string) ($options['pinned_public_key'] ?? '');
        $this->maxResponseBytes = max(1024, (int) ($options['max_response_kb'] ?? 256) * 1024);
        $this->allowLocalHttp = (bool) ($options['allow_local_http'] ?? false);
    }

    /**
     * @param array<string, string> $fields zwykłe pola formularza
     * @param array{name:string,filename:string,type:string,bytes:string}|null $file
     * @param array<int, string> $headers
     * @return array{status:int, body:string}
     */
    public function postMultipart(string $url, array $fields, ?array $file, array $headers = []): array
    {
        $this->assertSecureUrl($url);

        $boundary = '----legitymacje' . bin2hex(random_bytes(16));
        $body = $this->buildMultipartBody($boundary, $fields, $file);

        $handle = curl_init();
        if ($handle === false) {
            throw new PhotoException('Nie udało się zainicjować połączenia HTTP.');
        }

        $responseBody = '';
        $tooLarge = false;
        $limit = $this->maxResponseBytes;

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array_merge($headers, [
                'Content-Type: multipart/form-data; boundary=' . $boundary,
                'Expect:',
            ]),
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_USERAGENT => 'legitymacje-web/1.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge, $limit): int {
                if (strlen($responseBody) + strlen($chunk) > $limit) {
                    $tooLarge = true;

                    return 0;
                }

                $responseBody .= $chunk;

                return strlen($chunk);
            },
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = $this->allowLocalHttp ? 'https,http' : 'https';
        } elseif (defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = $this->allowLocalHttp
                ? CURLPROTO_HTTPS | CURLPROTO_HTTP
                : CURLPROTO_HTTPS;
        }

        if ($this->caBundle !== '') {
            if (!is_readable($this->caBundle)) {
                curl_close($handle);
                throw new PhotoException('Nie można odczytać paczki certyfikatów CA z konfiguracji.');
            }
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        if ($this->pinnedPublicKey !== '') {
            $options[CURLOPT_PINNEDPUBLICKEY] = $this->pinnedPublicKey;
        }

        curl_setopt_array($handle, $options);

        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($tooLarge) {
            throw new PhotoException('Odpowiedź serwera API przekroczyła dozwolony rozmiar.');
        }

        if ($ok === false) {
            throw new PhotoException(sprintf('Błąd połączenia z API (%d): %s', $errno, $error));
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    private function assertSecureUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new PhotoException('Nieprawidłowy adres API w konfiguracji.');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($scheme === 'http' && $this->allowLocalHttp && in_array($host, self::LOCAL_HOSTS, true)) {
            return;
        }

        throw new PhotoException('Adres API musi używać https (http dopuszczalne wyłącznie dla 127.0.0.1).');
    }

    /**
     * @param array<string, string> $fields
     * @param array{name:string,filename:string,type:string,bytes:string}|null $file
     */
    private function buildMultipartBody(string $boundary, array $fields, ?array $file): string
    {
        $body = '';

        foreach ($fields as $name => $value) {
            $this->assertSafeToken((string) $name);
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
            $body .= $value . "\r\n";
        }

        if ($file !== null) {
            $this->assertSafeToken($file['name']);
            $this->assertSafeToken($file['filename']);
            $this->assertSafeToken($file['type']);

            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $file['name'] . '"; filename="' . $file['filename'] . '"' . "\r\n";
            $body .= 'Content-Type: ' . $file['type'] . "\r\n\r\n";
            $body .= $file['bytes'] . "\r\n";
        }

        return $body . '--' . $boundary . "--\r\n";
    }

    /**
     * Nazwy pól i pliku wstawiamy do nagłówków żądania - nie mogą zawierać
     * cudzysłowów ani znaków końca linii.
     */
    private function assertSafeToken(string $value): void
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9._\/+-]+$/', $value)) {
            throw new PhotoException('Nieprawidłowa nazwa pola w żądaniu do API.');
        }
    }
}
