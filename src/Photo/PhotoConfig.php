<?php

namespace Legitymacje\Photo;

/**
 * Domyślne ustawienia kontroli zdjęcia. Sekcja 'photo_check' z src/config.php
 * nadpisuje tylko te klucze, które faktycznie w niej są - dzięki temu starszy
 * plik config.php, bez tej sekcji, dalej działa i dostaje komplet ustawień.
 */
class PhotoConfig
{
    public const DEFAULTS = [
        'enabled' => true,

        // Dłuższy bok kopii roboczej używanej do pomiarów. Większa wartość =
        // dokładniej i wolniej. Progi poniżej są dobrane do tego rozmiaru.
        'work_size_px' => 512,

        // Odrzuć zdjęcia o absurdalnej liczbie pikseli, zanim GD zacznie je
        // dekodować (ochrona przed "decompression bomb").
        'max_megapixels' => 40,

        'thresholds' => [
            // Jasność i kontrast liczone dla środkowej części kadru (twarz),
            // w skali 0-255. Progi 'min'/'max' blokują zgłoszenie, 'dim'/
            // 'washed' tylko je komentują. Uwaga: poprawnie naświetlone
            // zdjęcie osoby o ciemnej karnacji ma niższą średnią jasność -
            // dlatego próg blokujący jest celowo nisko.
            'brightness_min' => 55.0,
            'brightness_max' => 225.0,
            'brightness_dim_min' => 95.0,
            'brightness_washed_max' => 205.0,
            'contrast_min' => 16.0,

            // Procent pikseli całkiem czarnych (<16) i całkiem białych (>245).
            'clipped_dark_max_pct' => 20.0,
            'clipped_bright_max_pct' => 20.0,

            // Różnica jasności lewej i prawej połowy twarzy (0-255).
            'lighting_side_diff_max' => 45.0,

            // Wariancja laplasjanu w obszarze twarzy - im mniej, tym bardziej
            // zdjęcie nieostre lub poruszone. Ostry portret to zwykle kilkaset,
            // poruszony - kilkadziesiąt.
            'sharpness_min' => 60.0,

            // Tło: pasy przy górnej i bocznych krawędziach kadru.
            'background_brightness_min' => 105.0,
            'background_uniformity_max' => 42.0,
            'background_edges_max' => 12.0,

            // Nasycenie kolorów - przy wartości bliskiej zeru zdjęcie jest
            // czarno-białe.
            'colorfulness_min' => 3.0,

            // Rozrzut jasności w środku kadru. Poniżej tej wartości w kadrze
            // nie ma nikogo (zdjęcie ściany, kartki, sufitu). Miara nie zależy
            // od ostrości - rozmyty portret dalej liczy się jako osoba.
            'subject_contrast_min' => 8.0,

            // Udział pikseli w odcieniu skóry w środkowej części kadru.
            // Heurystyka zależna od karnacji i barwy światła - domyślnie
            // wyłączona, patrz 'checks' => 'skin_ratio_low'.
            'skin_ratio_min_pct' => 6.0,

            // Poniższe progi dotyczą wyłącznie wyniku z API rozpoznawania
            // twarzy (jeśli jest włączone).
            'face_area_min_pct' => 4.0,
            'face_area_max_pct' => 65.0,
            'face_offset_max_pct' => 22.0,
            'head_tilt_max_deg' => 20.0,
            'head_turn_max_deg' => 25.0,
            'eye_open_min_pct' => 45.0,
            'face_blur_max' => 70.0,
            'face_min_probability' => 0.75,
        ],

        // severity: 'error' blokuje zgłoszenie, 'warning' przepuszcza je
        // i dopisuje uwagę do wiadomości dla szkoły, 'off' wyłącza kontrolę.
        'checks' => [
            'file_unreadable' => [
                'severity' => 'error',
                'message' => 'Nie udało się odczytać zdjęcia - zapisz je ponownie jako JPG lub PNG i spróbuj jeszcze raz.',
            ],
            'brightness_low' => [
                'severity' => 'error',
                'message' => 'Zdjęcie jest zbyt ciemne - zrób je w dobrze oświetlonym miejscu, twarz musi być wyraźnie widoczna.',
            ],
            'brightness_high' => [
                'severity' => 'error',
                'message' => 'Zdjęcie jest prześwietlone - unikaj mocnego światła prosto w twarz i fotografowania pod słońce.',
            ],
            'brightness_dim' => [
                'severity' => 'warning',
                'message' => 'Zdjęcie jest dość ciemne - następnym razem stań bliżej okna albo zapal światło.',
            ],
            'brightness_washed' => [
                'severity' => 'warning',
                'message' => 'Zdjęcie jest mocno rozjaśnione - rysy twarzy mogą być słabo widoczne.',
            ],
            'contrast_low' => [
                'severity' => 'warning',
                'message' => 'Zdjęcie jest mało kontrastowe (szare, zamglone) - zrób je jeszcze raz przy lepszym świetle.',
            ],
            'clipping_dark' => [
                'severity' => 'warning',
                'message' => 'Duża część zdjęcia jest całkiem czarna - popraw oświetlenie i nie fotografuj się pod światło.',
            ],
            'clipping_bright' => [
                'severity' => 'warning',
                'message' => 'Duża część zdjęcia jest prześwietlona na biało - zmniejsz ilość światła padającego prosto na twarz.',
            ],
            'lighting_uneven' => [
                'severity' => 'warning',
                'message' => 'Twarz jest oświetlona nierównomiernie (jedna strona wyraźnie ciemniejsza) - ustaw się przodem do światła.',
            ],
            'sharpness_low' => [
                'severity' => 'error',
                'message' => 'Zdjęcie jest nieostre lub poruszone - zrób je jeszcze raz, trzymając aparat nieruchomo.',
            ],
            'background_dark' => [
                'severity' => 'warning',
                'message' => 'Tło jest zbyt ciemne - zdjęcie do legitymacji powinno mieć jasne, jednolite tło.',
            ],
            'background_busy' => [
                'severity' => 'warning',
                'message' => 'Tło nie jest jednolite (widać przedmioty, wzory lub cienie) - stań przed gładką, jasną ścianą.',
            ],
            'grayscale' => [
                'severity' => 'warning',
                'message' => 'Zdjęcie wygląda na czarno-białe - prześlij zdjęcie kolorowe.',
            ],
            'subject_missing' => [
                'severity' => 'error',
                'message' => 'Na zdjęciu nie widać osoby - prześlij portret, na którym twarz zajmuje środek kadru.',
            ],
            'skin_ratio_low' => [
                'severity' => 'off',
                'message' => 'Na zdjęciu nie widać wyraźnie twarzy - zrób portret z bliska, na wprost aparatu.',
            ],

            // Poniższe kontrole działają tylko z włączonym API rozpoznawania twarzy.
            'face_missing' => [
                'severity' => 'error',
                'message' => 'Nie wykryto twarzy na zdjęciu - prześlij portret z twarzą na wprost aparatu.',
            ],
            'face_multiple' => [
                'severity' => 'error',
                'message' => 'Na zdjęciu widać więcej niż jedną osobę - na legitymację potrzebne jest zdjęcie wyłącznie Twojej twarzy.',
            ],
            'face_small' => [
                'severity' => 'warning',
                'message' => 'Twarz zajmuje zbyt małą część zdjęcia - zrób portret z bliższej odległości (głowa i ramiona).',
            ],
            'face_large' => [
                'severity' => 'warning',
                'message' => 'Twarz zajmuje zbyt dużą część zdjęcia - odsuń się, na zdjęciu ma być widoczna cała głowa i ramiona.',
            ],
            'face_offcenter' => [
                'severity' => 'warning',
                'message' => 'Twarz nie jest wyśrodkowana - ustaw głowę na środku kadru, w jego górnej części.',
            ],
            'head_tilt' => [
                'severity' => 'warning',
                'message' => 'Głowa jest przekrzywiona lub odwrócona - patrz prosto w obiektyw, z głową ustawioną pionowo.',
            ],
            'eyes_closed' => [
                'severity' => 'warning',
                'message' => 'Oczy wyglądają na zamknięte - na zdjęciu do legitymacji oczy muszą być otwarte i widoczne.',
            ],
            'face_blurry' => [
                'severity' => 'error',
                'message' => 'Twarz na zdjęciu jest nieostra - zrób zdjęcie jeszcze raz przy lepszym świetle.',
            ],
            // Zachowanie przy awarii API: 'warning' - przepuść z uwagą,
            // 'off' - przepuść po cichu, 'error' - odrzuć zgłoszenie.
            'api_unavailable' => [
                'severity' => 'warning',
                'message' => 'Automatyczna kontrola twarzy jest chwilowo niedostępna - zgłoszenie sprawdzi ręcznie szkoła.',
            ],
        ],

        'face_api' => [
            // 'none'       - bez wykrywania twarzy,
            // 'local'       - kaskada Haara liczona na tym serwerze, bez kluczy
            //                 i bez wysyłania zdjęcia gdziekolwiek (zalecane),
            // 'facepp'      - Face++ (darmowy klucz, dane wychodzą na zewnątrz),
            // 'compreface'  - CompreFace na własnym serwerze.
            'provider' => 'none',

            'timeout_seconds' => 8,
            'connect_timeout_seconds' => 4,

            // Zdjęcie wysyłane do API jest zmniejszane i przekodowywane na
            // JPG - razem z metadanymi EXIF znika m.in. lokalizacja GPS.
            'max_upload_px' => 900,
            'jpeg_quality' => 85,

            // Górny limit odpowiedzi serwera API (ochrona pamięci procesu PHP).
            'max_response_kb' => 256,

            // Własna paczka certyfikatów CA (pusta = systemowa).
            'ca_bundle' => '',

            // Opcjonalne przypięcie klucza publicznego serwera API,
            // np. 'sha256//BASE64...' - dodatkowa ochrona przed podstawionym
            // certyfikatem.
            'pinned_public_key' => '',

            'local' => [
                // Puste ścieżki = kaskady z vendor/haarcascades/.
                'face_cascade' => '',
                'eye_cascade' => '',

                // Jak dużej twarzy szukać, jako ułamek szerokości kadru.
                // Węższy zakres = szybciej. Dla zdjęcia do legitymacji twarz
                // zajmuje zwykle 25-60% szerokości.
                'min_face_ratio' => 0.20,
                'max_face_ratio' => 0.80,

                // Odstęp między poziomami piramidy obrazu (1.1 = dokładniej
                // i wolniej, 1.3 = szybciej i mniej pewnie).
                'scale_step' => 1.2,

                // Ile nakładających się trafień musi potwierdzić twarz.
                'min_neighbours' => 3,
                'scan_step' => 1,

                // Za drugą osobę uznajemy twarz o co najmniej tej części
                // wielkości i liczby potwierdzeń największej twarzy, i nie
                // słabszą niż próg bezwzględny poniżej. Celowo ostrożnie:
                // lepiej przepuścić zdjęcie z dwiema osobami (szkoła je
                // zobaczy) niż zablokować poprawne zgłoszenie.
                'secondary_face_ratio' => 0.4,
                'secondary_min_neighbours' => 8,

                // Wykrywanie oczu daje kąt przechylenia głowy. Kosztuje
                // kilkadziesiąt ms; bez niego kontrola head_tilt nie działa.
                'detect_eyes' => true,
            ],

            'facepp' => [
                'endpoint' => 'https://api-us.faceplusplus.com/facepp/v3/detect',
                'api_key' => '',
                'api_secret' => '',
            ],

            'compreface' => [
                'base_url' => 'https://compreface.twojaszkola.pl',
                'api_key' => '',
                'detection_threshold' => 0.8,
                // Dopuść http:// wyłącznie dla instancji na tym samym
                // serwerze (127.0.0.1 / localhost / ::1).
                'allow_local_http' => false,
            ],
        ],
    ];

    public static function resolve(array $userConfig): array
    {
        return array_replace_recursive(self::DEFAULTS, $userConfig);
    }
}
