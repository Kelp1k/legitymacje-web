<?php

namespace Legitymacje\Photo;

/**
 * Face++ (https://www.faceplusplus.com) - darmowy klucz wystarcza do
 * wykrywania twarzy wraz z ułożeniem głowy, ostrością i stanem oczu.
 *
 * Do API trafia wyłącznie sam obraz (pomniejszony, bez EXIF) oraz klucze
 * dostępu. Imię, nazwisko, klasa, e-mail i kod nigdy nie opuszczają serwera
 * szkoły.
 */
class FacePlusPlusApi implements FaceApiInterface
{
    private const ATTRIBUTES = 'headpose,eyestatus,blur';

    private HttpClient $http;
    private string $endpoint;
    private string $apiKey;
    private string $apiSecret;

    public function __construct(HttpClient $http, array $config)
    {
        $this->http = $http;
        $this->endpoint = (string) ($config['endpoint'] ?? '');
        $this->apiKey = (string) ($config['api_key'] ?? '');
        $this->apiSecret = (string) ($config['api_secret'] ?? '');

        if ($this->endpoint === '' || $this->apiKey === '' || $this->apiSecret === '') {
            throw new PhotoException('Brak konfiguracji Face++ (endpoint, api_key, api_secret).');
        }
    }

    public function name(): string
    {
        return 'facepp';
    }

    public function preferredUploadPixels(): ?int
    {
        return null;
    }

    public function detect(string $jpegBytes, int $width, int $height): FaceApiResult
    {
        $response = $this->http->postMultipart(
            $this->endpoint,
            [
                'api_key' => $this->apiKey,
                'api_secret' => $this->apiSecret,
                'return_landmark' => '0',
                'return_attributes' => self::ATTRIBUTES,
            ],
            [
                'name' => 'image_file',
                'filename' => 'photo.jpg',
                'type' => 'image/jpeg',
                'bytes' => $jpegBytes,
            ]
        );

        $payload = json_decode($response['body'], true);
        if (!is_array($payload)) {
            throw new PhotoException('Face++ zwróciło odpowiedź, której nie da się odczytać.');
        }

        if ($response['status'] !== 200) {
            $reason = isset($payload['error_message']) ? (string) $payload['error_message'] : 'brak szczegółów';
            throw new PhotoException(sprintf('Face++ odrzuciło żądanie (HTTP %d): %s', $response['status'], $reason));
        }

        $result = new FaceApiResult();
        $result->imageWidth = $width;
        $result->imageHeight = $height;

        $faces = isset($payload['faces']) && is_array($payload['faces']) ? $payload['faces'] : [];
        $result->faceCount = count($faces);

        $largest = $this->largestFace($faces);
        if ($largest === null) {
            return $result;
        }

        $rectangle = $largest['face_rectangle'];
        $result->faceX = (int) $rectangle['left'];
        $result->faceY = (int) $rectangle['top'];
        $result->faceWidth = (int) $rectangle['width'];
        $result->faceHeight = (int) $rectangle['height'];

        $attributes = isset($largest['attributes']) && is_array($largest['attributes']) ? $largest['attributes'] : [];
        $this->applyHeadPose($result, $attributes);
        $this->applyBlur($result, $attributes);
        $this->applyEyeStatus($result, $attributes);

        return $result;
    }

    private function largestFace(array $faces): ?array
    {
        $best = null;
        $bestArea = -1;

        foreach ($faces as $face) {
            if (!is_array($face) || !isset($face['face_rectangle']['width'], $face['face_rectangle']['height'])) {
                continue;
            }

            $area = (int) $face['face_rectangle']['width'] * (int) $face['face_rectangle']['height'];
            if ($area > $bestArea) {
                $bestArea = $area;
                $best = $face;
            }
        }

        return $best;
    }

    private function applyHeadPose(FaceApiResult $result, array $attributes): void
    {
        if (!isset($attributes['headpose']) || !is_array($attributes['headpose'])) {
            return;
        }

        $pose = $attributes['headpose'];
        $result->roll = isset($pose['roll_angle']) ? (float) $pose['roll_angle'] : null;
        $result->yaw = isset($pose['yaw_angle']) ? (float) $pose['yaw_angle'] : null;
        $result->pitch = isset($pose['pitch_angle']) ? (float) $pose['pitch_angle'] : null;
    }

    private function applyBlur(FaceApiResult $result, array $attributes): void
    {
        if (isset($attributes['blur']['blurness']['value'])) {
            $result->blur = (float) $attributes['blur']['blurness']['value'];
        }
    }

    /**
     * Face++ zwraca rozkład prawdopodobieństwa stanu oka - sumujemy warianty
     * oznaczające oko otwarte (z okularami korekcyjnymi i bez).
     */
    private function applyEyeStatus(FaceApiResult $result, array $attributes): void
    {
        if (!isset($attributes['eyestatus']) || !is_array($attributes['eyestatus'])) {
            return;
        }

        $result->leftEyeOpen = $this->eyeOpenness($attributes['eyestatus']['left_eye_status'] ?? null);
        $result->rightEyeOpen = $this->eyeOpenness($attributes['eyestatus']['right_eye_status'] ?? null);
    }

    private function eyeOpenness($status): ?float
    {
        if (!is_array($status)) {
            return null;
        }

        $open = (float) ($status['no_glass_eye_open'] ?? 0)
            + (float) ($status['normal_glass_eye_open'] ?? 0);

        return min(100.0, $open);
    }
}
