<?php

namespace Legitymacje\Photo;

/**
 * Wynik z API rozpoznawania twarzy, sprowadzony do wspólnej postaci -
 * niezależnie od tego, który dostawca go zwrócił. Pola, których dany
 * dostawca nie udostępnia, zostają null i nie są sprawdzane.
 */
class FaceApiResult
{
    public int $faceCount = 0;

    /** Wymiary zdjęcia wysłanego do API (nie oryginału). */
    public int $imageWidth = 0;
    public int $imageHeight = 0;

    /** Prostokąt największej twarzy w pikselach wysłanego zdjęcia. */
    public ?int $faceX = null;
    public ?int $faceY = null;
    public ?int $faceWidth = null;
    public ?int $faceHeight = null;

    /** Przechylenie głowy w stopniach. */
    public ?float $roll = null;
    public ?float $yaw = null;
    public ?float $pitch = null;

    /** 0-100, im więcej tym bardziej rozmyta twarz. */
    public ?float $blur = null;

    /** 0-100, prawdopodobieństwo otwartego oka. */
    public ?float $leftEyeOpen = null;
    public ?float $rightEyeOpen = null;

    /** 0-1, pewność detekcji największej twarzy. */
    public ?float $probability = null;

    /**
     * Udział powierzchni twarzy w powierzchni zdjęcia, w procentach.
     */
    public function faceAreaPercent(): ?float
    {
        if ($this->faceWidth === null || $this->faceHeight === null) {
            return null;
        }

        $imageArea = $this->imageWidth * $this->imageHeight;
        if ($imageArea <= 0) {
            return null;
        }

        return ($this->faceWidth * $this->faceHeight) * 100.0 / $imageArea;
    }

    /**
     * Odchylenie środka twarzy od pionowej osi kadru, w procentach szerokości.
     */
    public function horizontalOffsetPercent(): ?float
    {
        if ($this->faceX === null || $this->faceWidth === null || $this->imageWidth <= 0) {
            return null;
        }

        $faceCenter = $this->faceX + $this->faceWidth / 2.0;
        $imageCenter = $this->imageWidth / 2.0;

        return abs($faceCenter - $imageCenter) * 100.0 / $this->imageWidth;
    }
}
