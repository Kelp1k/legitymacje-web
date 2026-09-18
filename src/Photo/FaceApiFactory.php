<?php

namespace Legitymacje\Photo;

class FaceApiFactory
{
    /**
     * Zwraca null, gdy rozpoznawanie twarzy jest wyłączone - wtedy zdjęcie
     * w ogóle nie opuszcza serwera.
     *
     * @param array $config sekcja 'face_api' z konfiguracji
     */
    public static function create(array $config): ?FaceApiInterface
    {
        $provider = strtolower(trim((string) ($config['provider'] ?? 'none')));

        if ($provider === '' || $provider === 'none') {
            return null;
        }

        if ($provider === 'local') {
            return new LocalFaceApi($config['local'] ?? []);
        }

        if ($provider === 'facepp') {
            return new FacePlusPlusApi(self::httpClient($config, false), $config['facepp'] ?? []);
        }

        if ($provider === 'compreface') {
            $compreface = $config['compreface'] ?? [];
            $allowLocalHttp = (bool) ($compreface['allow_local_http'] ?? false);

            return new CompreFaceApi(self::httpClient($config, $allowLocalHttp), $compreface);
        }

        throw new PhotoException(sprintf('Nieznany dostawca rozpoznawania twarzy: %s', $provider));
    }

    private static function httpClient(array $config, bool $allowLocalHttp): HttpClient
    {
        return new HttpClient([
            'timeout_seconds' => $config['timeout_seconds'] ?? 8,
            'connect_timeout_seconds' => $config['connect_timeout_seconds'] ?? 4,
            'ca_bundle' => $config['ca_bundle'] ?? '',
            'pinned_public_key' => $config['pinned_public_key'] ?? '',
            'max_response_kb' => $config['max_response_kb'] ?? 256,
            'allow_local_http' => $allowLocalHttp,
        ]);
    }
}
