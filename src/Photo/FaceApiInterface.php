<?php

namespace Legitymacje\Photo;

interface FaceApiInterface
{
    /**
     * @param string $jpegBytes zdjęcie w formacie JPG, bez metadanych EXIF
     * @throws PhotoException gdy API nie odpowiada albo zwraca błąd
     */
    public function detect(string $jpegBytes, int $width, int $height): FaceApiResult;

    /**
     * Nazwa dostawcy - trafia do logu i do wiadomości dla szkoły.
     */
    public function name(): string;

    /**
     * Rozmiar zdjęcia, jakiego dostawca potrzebuje, jeśli mniejszy niż
     * ustawiony w konfiguracji. null = bez preferencji.
     */
    public function preferredUploadPixels(): ?int;
}
