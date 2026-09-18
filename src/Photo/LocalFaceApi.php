<?php

namespace Legitymacje\Photo;

/**
 * Wykrywanie twarzy bez żadnego zewnętrznego API - kaskadą Haara liczoną
 * na serwerze szkoły. Zdjęcie nie opuszcza maszyny, nie trzeba kluczy,
 * nie ma tematu powierzenia danych osobowych.
 *
 * Dokładność jest niższa niż w komercyjnych API: metoda pochodzi z 2001 roku
 * i gubi twarze mocno zacienione, w okularach, przechylone powyżej ~20 stopni
 * oraz - to ważne - częściej gubi osoby o ciemnej karnacji w słabym świetle.
 * Dlatego domyślnie brak twarzy jest tu uwagą, a nie błędem blokującym.
 */
class LocalFaceApi implements FaceApiInterface
{
    /** Rozdzielczość, przy której kaskada działa szybko i wciąż pewnie. */
    private const WORK_PIXELS = 512;

    private LocalFaceDetector $faceDetector;
    private ?LocalFaceDetector $eyeDetector;
    private array $cfg;

    public function __construct(array $config)
    {
        $this->cfg = $config + [
            'min_face_ratio' => 0.20,
            'max_face_ratio' => 0.80,
            'scale_step' => 1.2,
            'min_neighbours' => 3,
            'scan_step' => 1,
            'secondary_face_ratio' => 0.4,
            'secondary_min_neighbours' => 8,
            'detect_eyes' => true,
        ];

        $faceCascade = (string) ($config['face_cascade'] ?? '');
        if ($faceCascade === '') {
            $faceCascade = __DIR__ . '/../../vendor/haarcascades/frontalface.bin';
        }

        $this->faceDetector = new LocalFaceDetector(HaarCascade::load($faceCascade));
        $this->eyeDetector = null;

        if ($this->cfg['detect_eyes']) {
            $eyeCascade = (string) ($config['eye_cascade'] ?? '');
            if ($eyeCascade === '') {
                $eyeCascade = __DIR__ . '/../../vendor/haarcascades/eye.bin';
            }

            if (is_readable($eyeCascade)) {
                $this->eyeDetector = new LocalFaceDetector(HaarCascade::load($eyeCascade));
            }
        }
    }

    public function name(): string
    {
        return 'local';
    }

    public function preferredUploadPixels(): ?int
    {
        return self::WORK_PIXELS;
    }

    public function detect(string $jpegBytes, int $width, int $height): FaceApiResult
    {
        $image = @imagecreatefromstring($jpegBytes);
        if ($image === false) {
            throw new PhotoException('Nie udało się zdekodować zdjęcia do wykrywania twarzy.');
        }

        try {
            $faces = $this->faceDetector->detect(
                $image,
                (float) $this->cfg['min_face_ratio'],
                (float) $this->cfg['max_face_ratio'],
                (float) $this->cfg['scale_step'],
                (int) $this->cfg['min_neighbours'],
                (int) $this->cfg['scan_step']
            );

            $result = new FaceApiResult();
            $result->imageWidth = $width;
            $result->imageHeight = $height;
            $result->faceCount = $this->countPeople($faces);

            if ($faces === []) {
                return $result;
            }

            $primary = $faces[0];
            $result->faceX = $primary['x'];
            $result->faceY = $primary['y'];
            $result->faceWidth = $primary['w'];
            $result->faceHeight = $primary['h'];
            $result->probability = min(1.0, $primary['neighbours'] / 20.0);
            $result->roll = $this->estimateRoll($image, $primary);

            return $result;
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Kaskada Haara lubi rzucić słabym trafieniem gdzieś w tle. Za drugą osobę
     * uznajemy tylko twarz o porównywalnej wielkości i porównawczo mocnym
     * potwierdzeniu - inaczej "więcej niż jedna osoba" wyskakiwałoby na
     * poprawnych zdjęciach.
     *
     * @param array<int, array{x:int,y:int,w:int,h:int,neighbours:int}> $faces
     */
    private function countPeople(array $faces): int
    {
        if ($faces === []) {
            return 0;
        }

        $primary = $faces[0];
        $minArea = $primary['w'] * $primary['h'] * (float) $this->cfg['secondary_face_ratio'];

        // Względnie do najmocniejszego trafienia, ale nigdy poniżej progu
        // bezwzględnego - inaczej przy słabo wykrytej twarzy (np. przechylona
        // głowa) szum w tle awansowałby na "drugą osobę".
        $minNeighbours = max(
            (float) $this->cfg['secondary_min_neighbours'],
            $primary['neighbours'] * (float) $this->cfg['secondary_face_ratio']
        );
        $people = 1;

        foreach (array_slice($faces, 1) as $face) {
            if ($face['w'] * $face['h'] >= $minArea && $face['neighbours'] >= $minNeighbours) {
                $people++;
            }
        }

        return $people;
    }

    /**
     * Kąt przechylenia głowy z linii łączącej oczy. Zwraca null, gdy oczu nie
     * udało się znaleźć - brak wyniku jest tu uczciwszy niż zgadywanie.
     *
     * @param resource|\GdImage $image
     * @param array{x:int,y:int,w:int,h:int,neighbours:int} $face
     */
    private function estimateRoll($image, array $face): ?float
    {
        if ($this->eyeDetector === null) {
            return null;
        }

        // Oczy są w górnej części prostokąta twarzy.
        $cropW = $face['w'];
        $cropH = (int) round($face['h'] * 0.6);
        if ($cropW < 40 || $cropH < 20) {
            return null;
        }

        $crop = imagecreatetruecolor($cropW, $cropH);
        imagecopy($crop, $image, 0, 0, $face['x'], $face['y'], $cropW, $cropH);

        try {
            $eyes = $this->eyeDetector->detect($crop, 0.18, 0.45, 1.2, 3, 1);
            if (count($eyes) < 2) {
                return null;
            }

            usort($eyes, static fn(array $a, array $b): int => $a['x'] <=> $b['x']);
            $left = $eyes[0];
            $right = $eyes[count($eyes) - 1];

            $dx = ($right['x'] + $right['w'] / 2) - ($left['x'] + $left['w'] / 2);
            $dy = ($right['y'] + $right['h'] / 2) - ($left['y'] + $left['h'] / 2);

            // Zbyt blisko siebie - to raczej dwa trafienia w to samo oko.
            if ($dx < $cropW * 0.2) {
                return null;
            }

            return rad2deg(atan2($dy, $dx));
        } finally {
            imagedestroy($crop);
        }
    }
}
