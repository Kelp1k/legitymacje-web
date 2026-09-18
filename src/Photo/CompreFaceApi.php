<?php

namespace Legitymacje\Photo;

/**
 * CompreFace (https://github.com/exadel-inc/CompreFace) - darmowy,
 * otwartoźródłowy serwer rozpoznawania twarzy, który stawia się na własnej
 * maszynie (np. w sieci szkoły). Zdjęcie nie trafia wtedy do żadnej firmy
 * zewnętrznej, co jest najbezpieczniejszym wariantem z punktu widzenia RODO.
 */
class CompreFaceApi implements FaceApiInterface
{
    private const DETECT_PATH = '/api/v1/detection/detect';

    private HttpClient $http;
    private string $baseUrl;
    private string $apiKey;
    private float $threshold;

    public function __construct(HttpClient $http, array $config)
    {
        $this->http = $http;
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->threshold = (float) ($config['detection_threshold'] ?? 0.8);

        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new PhotoException('Brak konfiguracji CompreFace (base_url, api_key).');
        }
    }

    public function name(): string
    {
        return 'compreface';
    }

    public function preferredUploadPixels(): ?int
    {
        return null;
    }

    public function detect(string $jpegBytes, int $width, int $height): FaceApiResult
    {
        $url = $this->baseUrl . self::DETECT_PATH . '?' . http_build_query([
            'limit' => 0,
            'det_prob_threshold' => $this->threshold,
            'face_plugins' => 'pose',
            'status' => 'false',
        ]);

        $response = $this->http->postMultipart(
            $url,
            [],
            [
                'name' => 'file',
                'filename' => 'photo.jpg',
                'type' => 'image/jpeg',
                'bytes' => $jpegBytes,
            ],
            ['x-api-key: ' . $this->apiKey]
        );

        $payload = json_decode($response['body'], true);
        if (!is_array($payload)) {
            throw new PhotoException('CompreFace zwróciło odpowiedź, której nie da się odczytać.');
        }

        if ($response['status'] !== 200) {
            $reason = isset($payload['message']) ? (string) $payload['message'] : 'brak szczegółów';
            throw new PhotoException(sprintf('CompreFace odrzuciło żądanie (HTTP %d): %s', $response['status'], $reason));
        }

        $result = new FaceApiResult();
        $result->imageWidth = $width;
        $result->imageHeight = $height;

        $faces = isset($payload['result']) && is_array($payload['result']) ? $payload['result'] : [];
        $result->faceCount = count($faces);

        $largest = $this->largestFace($faces);
        if ($largest === null) {
            return $result;
        }

        $box = $largest['box'];
        $result->faceX = (int) $box['x_min'];
        $result->faceY = (int) $box['y_min'];
        $result->faceWidth = max(0, (int) $box['x_max'] - (int) $box['x_min']);
        $result->faceHeight = max(0, (int) $box['y_max'] - (int) $box['y_min']);
        $result->probability = isset($box['probability']) ? (float) $box['probability'] : null;

        if (isset($largest['pose']) && is_array($largest['pose'])) {
            $pose = $largest['pose'];
            $result->roll = isset($pose['roll']) ? (float) $pose['roll'] : null;
            $result->yaw = isset($pose['yaw']) ? (float) $pose['yaw'] : null;
            $result->pitch = isset($pose['pitch']) ? (float) $pose['pitch'] : null;
        }

        return $result;
    }

    private function largestFace(array $faces): ?array
    {
        $best = null;
        $bestArea = -1;

        foreach ($faces as $face) {
            if (!is_array($face) || !isset($face['box']['x_min'], $face['box']['y_min'], $face['box']['x_max'], $face['box']['y_max'])) {
                continue;
            }

            $area = ((int) $face['box']['x_max'] - (int) $face['box']['x_min'])
                * ((int) $face['box']['y_max'] - (int) $face['box']['y_min']);

            if ($area > $bestArea) {
                $bestArea = $area;
                $best = $face;
            }
        }

        return $best;
    }
}
