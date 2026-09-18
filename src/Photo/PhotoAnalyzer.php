<?php

namespace Legitymacje\Photo;

/**
 * Spina całość: pomiary lokalne (GD) i opcjonalne rozpoznawanie twarzy przez
 * API. Zwraca listę błędów blokujących zgłoszenie i listę uwag.
 */
class PhotoAnalyzer
{
    private array $cfg;
    private ?FaceApiInterface $faceApi = null;
    private ?string $faceApiError = null;

    /**
     * @param array $photoCheckConfig sekcja 'photo_check' z src/config.php
     */
    public function __construct(array $photoCheckConfig, ?FaceApiInterface $faceApi = null)
    {
        $this->cfg = PhotoConfig::resolve($photoCheckConfig);

        if ($faceApi !== null) {
            $this->faceApi = $faceApi;

            return;
        }

        try {
            $this->faceApi = FaceApiFactory::create($this->cfg['face_api']);
        } catch (PhotoException $e) {
            $this->faceApiError = $e->getMessage();
            error_log('Kontrola zdjecia - blad konfiguracji API: ' . $e->getMessage());
        }
    }

    public function isEnabled(): bool
    {
        return (bool) $this->cfg['enabled'];
    }

    public function faceApiName(): ?string
    {
        return $this->faceApi !== null ? $this->faceApi->name() : null;
    }

    public function analyze(string $path): PhotoReport
    {
        $report = new PhotoReport();

        if (!$this->isEnabled()) {
            return $report;
        }

        // Bez rozszerzenia gd nie da się zmierzyć niczego. Zamiast blokować
        // wszystkie zgłoszenia, zapisujemy to w logu i przepuszczamy je dalej -
        // zwykła walidacja formatu i rozmiaru z Validatora nadal działa.
        if (!function_exists('imagecreatetruecolor')) {
            error_log('Kontrola zdjecia pominieta - brak rozszerzenia PHP gd.');

            return $report;
        }

        try {
            $probe = ImageProbe::fromFile(
                $path,
                (int) $this->cfg['work_size_px'],
                (int) $this->cfg['max_megapixels']
            );
        } catch (PhotoException $e) {
            error_log('Kontrola zdjecia - nie mozna otworzyc pliku: ' . $e->getMessage());
            $this->flag($report, 'file_unreadable');

            return $report;
        }

        try {
            $this->checkImage($probe, $report);
            $this->checkFace($probe, $report);
        } finally {
            $probe->destroy();
        }

        return $report;
    }

    /**
     * Pomiary lokalne. Jeśli w kadrze w ogóle nie widać osoby, nie ma sensu
     * komentować oświetlenia twarzy - wystarczy jedna, trafna uwaga.
     */
    private function checkImage(ImageProbe $probe, PhotoReport $report): void
    {
        $exposure = $probe->faceExposure();

        $report->setMetric('jasnosc', $exposure['mean']);
        $report->setMetric('kontrast', $exposure['stddev']);
        $report->setMetric('czarne_proc', $exposure['dark_pct']);
        $report->setMetric('biale_proc', $exposure['bright_pct']);
        $report->setMetric('szczegoly', $probe->subjectDetail());

        $this->checkBackground($probe, $report);
        $this->checkColors($probe, $report);

        // Rozrzut jasności w środku kadru: portret ma tam włosy, oczy i cień,
        // pusta ściana czy kartka papieru - nic. Miara nie zależy od ostrości,
        // więc rozmyte zdjęcie osoby dalej liczy się jako "ktoś tu jest".
        if ($exposure['stddev'] < $this->threshold('subject_contrast_min')) {
            $this->flag($report, 'subject_missing');

            return;
        }

        $this->checkLighting($probe, $report, $exposure);
        $this->checkSharpness($probe, $report);
        $this->checkSkinTone($probe, $report);
    }

    private function checkLighting(ImageProbe $probe, PhotoReport $report, array $exposure): void
    {
        $sideDifference = $probe->lightingSideDifference();
        $report->setMetric('swiatlo_boczne', $sideDifference);

        if ($exposure['mean'] < $this->threshold('brightness_min')) {
            $this->flag($report, 'brightness_low');
        } elseif ($exposure['mean'] > $this->threshold('brightness_max')) {
            $this->flag($report, 'brightness_high');
        } elseif ($exposure['mean'] < $this->threshold('brightness_dim_min')) {
            $this->flag($report, 'brightness_dim');
        } elseif ($exposure['mean'] > $this->threshold('brightness_washed_max')) {
            $this->flag($report, 'brightness_washed');
        }

        if ($exposure['stddev'] < $this->threshold('contrast_min')) {
            $this->flag($report, 'contrast_low');
        }

        if ($exposure['dark_pct'] > $this->threshold('clipped_dark_max_pct')) {
            $this->flag($report, 'clipping_dark');
        }

        if ($exposure['bright_pct'] > $this->threshold('clipped_bright_max_pct')) {
            $this->flag($report, 'clipping_bright');
        }

        if ($sideDifference > $this->threshold('lighting_side_diff_max')) {
            $this->flag($report, 'lighting_uneven');
        }
    }

    private function checkSharpness(ImageProbe $probe, PhotoReport $report): void
    {
        $sharpness = $probe->sharpness();
        $report->setMetric('ostrosc', $sharpness);

        if ($sharpness < $this->threshold('sharpness_min')) {
            $this->flag($report, 'sharpness_low');
        }
    }

    private function checkBackground(ImageProbe $probe, PhotoReport $report): void
    {
        $background = $probe->background();

        $report->setMetric('tlo_jasnosc', $background['brightness']);
        $report->setMetric('tlo_jednolitosc', $background['uniformity']);
        $report->setMetric('tlo_krawedzie', $background['edges']);

        if ($background['brightness'] < $this->threshold('background_brightness_min')) {
            $this->flag($report, 'background_dark');
        }

        if ($background['uniformity'] > $this->threshold('background_uniformity_max')
            || $background['edges'] > $this->threshold('background_edges_max')
        ) {
            $this->flag($report, 'background_busy');
        }
    }

    private function checkColors(ImageProbe $probe, PhotoReport $report): void
    {
        $colorfulness = $probe->colorfulness();
        $report->setMetric('kolor', $colorfulness);

        if ($colorfulness < $this->threshold('colorfulness_min')) {
            $this->flag($report, 'grayscale');
        }
    }

    private function checkSkinTone(ImageProbe $probe, PhotoReport $report): void
    {
        $skinRatio = $probe->skinRatioCenter();
        $report->setMetric('skora_proc', $skinRatio);

        if ($skinRatio < $this->threshold('skin_ratio_min_pct')) {
            $this->flag($report, 'skin_ratio_low');
        }
    }

    private function checkFace(ImageProbe $probe, PhotoReport $report): void
    {
        if ($this->faceApiError !== null) {
            $this->flag($report, 'api_unavailable');

            return;
        }

        if ($this->faceApi === null) {
            return;
        }

        $faceApiConfig = $this->cfg['face_api'];

        try {
            $preferred = $this->faceApi->preferredUploadPixels();
            $uploadPx = (int) $faceApiConfig['max_upload_px'];
            if ($preferred !== null) {
                $uploadPx = min($uploadPx, $preferred);
            }

            $jpeg = $probe->toJpeg($uploadPx, (int) $faceApiConfig['jpeg_quality']);

            $size = getimagesizefromstring($jpeg);
            if ($size === false) {
                throw new PhotoException('Nie udało się odczytać wymiarów zdjęcia przygotowanego do wysyłki.');
            }

            $result = $this->faceApi->detect($jpeg, (int) $size[0], (int) $size[1]);
        } catch (PhotoException $e) {
            error_log('Kontrola zdjecia - API niedostepne: ' . $e->getMessage());
            $this->flag($report, 'api_unavailable');

            return;
        }

        $this->applyFaceResult($result, $report);
    }

    private function applyFaceResult(FaceApiResult $result, PhotoReport $report): void
    {
        $report->setMetric('twarze', $result->faceCount);

        if ($result->faceCount === 0) {
            $this->flag($report, 'face_missing');

            return;
        }

        if ($result->faceCount > 1) {
            $this->flag($report, 'face_multiple');
        }

        $area = $result->faceAreaPercent();
        if ($area !== null) {
            $report->setMetric('twarz_proc', $area);

            if ($area < $this->threshold('face_area_min_pct')) {
                $this->flag($report, 'face_small');
            } elseif ($area > $this->threshold('face_area_max_pct')) {
                $this->flag($report, 'face_large');
            }
        }

        $offset = $result->horizontalOffsetPercent();
        if ($offset !== null) {
            $report->setMetric('odchylenie_proc', $offset);

            if ($offset > $this->threshold('face_offset_max_pct')) {
                $this->flag($report, 'face_offcenter');
            }
        }

        $this->applyHeadPose($result, $report);
        $this->applyEyes($result, $report);

        if ($result->blur !== null) {
            $report->setMetric('rozmycie_twarzy', $result->blur);

            if ($result->blur > $this->threshold('face_blur_max')) {
                $this->flag($report, 'face_blurry');
            }
        }

        if ($result->probability !== null) {
            $report->setMetric('pewnosc', $result->probability);
        }
    }

    private function applyHeadPose(FaceApiResult $result, PhotoReport $report): void
    {
        $tiltLimit = $this->threshold('head_tilt_max_deg');
        $turnLimit = $this->threshold('head_turn_max_deg');
        $tilted = false;

        if ($result->roll !== null) {
            $report->setMetric('roll', $result->roll);
            $tilted = $tilted || abs($result->roll) > $tiltLimit;
        }

        if ($result->yaw !== null) {
            $report->setMetric('yaw', $result->yaw);
            $tilted = $tilted || abs($result->yaw) > $turnLimit;
        }

        if ($result->pitch !== null) {
            $report->setMetric('pitch', $result->pitch);
            $tilted = $tilted || abs($result->pitch) > $turnLimit;
        }

        if ($tilted) {
            $this->flag($report, 'head_tilt');
        }
    }

    private function applyEyes(FaceApiResult $result, PhotoReport $report): void
    {
        if ($result->leftEyeOpen === null && $result->rightEyeOpen === null) {
            return;
        }

        $openness = min(
            $result->leftEyeOpen ?? 100.0,
            $result->rightEyeOpen ?? 100.0
        );
        $report->setMetric('oczy_proc', $openness);

        if ($openness < $this->threshold('eye_open_min_pct')) {
            $this->flag($report, 'eyes_closed');
        }
    }

    private function threshold(string $key): float
    {
        return (float) ($this->cfg['thresholds'][$key] ?? PhotoConfig::DEFAULTS['thresholds'][$key]);
    }

    private function flag(PhotoReport $report, string $key): void
    {
        $check = $this->cfg['checks'][$key] ?? PhotoConfig::DEFAULTS['checks'][$key] ?? null;
        if (!is_array($check)) {
            return;
        }

        $report->add((string) ($check['severity'] ?? 'off'), (string) ($check['message'] ?? ''));
    }
}
