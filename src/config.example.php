<?php

return [
    'admin_email' => 'administrator@szkola.pl',
    'from_email' => 'legitymacje@szkola.pl',
    'from_name' => 'Legitymacje Szkolne',

    'smtp' => [
        'host' => 'poczta.szkola.pl',
        'port' => 587,
        'use_smtps' => false,
        'use_starttls' => true,
        'username' => 'legitymacje@szkola.pl',
        'password' => 'ZMIEN_MNIE',
    ],

    'db' => [
        'host' => 'localhost',
        'name' => 'legitymacje',
        'user' => 'legitymacje',
        'password' => 'ZMIEN_MNIE',
        'charset' => 'utf8mb4',
    ],

    'messages' => [
        'page_title' => 'Legitymacja szkolna - zgłoszenie zdjęcia',
        'intro_heading' => 'Zgłoszenie zdjęcia do legitymacji szkolnej',
        'intro_text' => 'Wpisz kod otrzymany od szkoły, aby przesłać zdjęcie do legitymacji.',
        'code_label' => 'Kod',
        'code_placeholder' => 'np. 0123456789',
        'code_invalid' => 'Nieprawidłowy kod. Sprawdź, czy wpisałeś/aś go poprawnie.',
        'code_used' => 'Ten kod został już wykorzystany. Jeśli to pomyłka, skontaktuj się ze szkołą.',
        'form_heading' => 'Dane do legitymacji',
        'name_label' => 'Imię i nazwisko',
        'name_placeholder' => 'Jan Kowalski',
        'class_label' => 'Klasa',
        'class_placeholder' => '-- wybierz klasę --',
        'email_label' => 'E-mail do kontaktu',
        'email_placeholder' => 'jan.kowalski@przyklad.pl',
        'email_hint' => 'Na ten adres szkoła skontaktuje się w razie problemu ze zgłoszeniem.',
        'photo_label' => 'Zdjęcie',
        'photo_hint' => 'Format JPG lub PNG, zdjęcie portretowe (pionowe), jedna osoba, jasne jednolite tło, dobre oświetlenie.',
        'photo_warning_heading' => 'Zdjęcie przyjęte, ale zwróć uwagę:',
        'confirm_heading' => 'Sprawdź zdjęcie przed wysłaniem',
        'confirm_hint' => 'Możesz wysłać to zdjęcie albo wrócić i wybrać lepsze - dopóki nie wyślesz, Twój kod pozostaje ważny.',
        'confirm_send_label' => 'Wyślij mimo to',
        'confirm_retry_label' => 'Wybierz inne zdjęcie',
        'confirm_expired' => 'Zdjęcie nie doczekało się potwierdzenia - wgraj je jeszcze raz. Twój kod jest nadal ważny.',
        // Teksty pokazywane w trakcie przetwarzania - kontrola zdjęcia zajmuje
        // kilka sekund, więc bez nich uczeń myśli, że strona się zawiesiła.
        'busy_code_label' => 'Sprawdzamy kod...',
        'busy_code_hint' => 'Chwilę to potrwa - nie zamykaj tej strony.',
        'busy_photo_label' => 'Sprawdzamy zdjęcie...',
        'busy_photo_hint' => 'Wgrywamy zdjęcie i sprawdzamy jego jakość. Może to potrwać kilkanaście sekund - nie zamykaj tej strony i nie klikaj ponownie.',
        'busy_send_label' => 'Wysyłamy zgłoszenie...',
        'busy_send_hint' => 'Przekazujemy zgłoszenie do szkoły - nie zamykaj tej strony.',
        'submit_label' => 'Wyślij zgłoszenie',
        'success_heading' => 'Zgłoszenie przyjęte',
        'success_text' => 'Dziękujemy, zgłoszenie zostało przyjęte i przekazane do szkoły.',
        'error_heading' => 'Popraw poniższe błędy',
        'subject_admin_forward' => 'Zgłoszenie do legitymacji: {imie_nazwisko}',
        'captcha_label' => 'Przepisz wynik działania z obrazka',
        'captcha_invalid' => 'Nieprawidłowy wynik działania - spróbuj ponownie.',
        'rate_limited' => 'Zbyt wiele prób. Spróbuj ponownie za kilkanaście minut.',
    ],

    'security' => [
        'max_attempts_per_window' => 5,
        'window_minutes' => 15,
        // Po tylu minutach kasowane jest zdjęcie czekające na potwierdzenie,
        // którego uczeń nigdy nie potwierdził ani nie odrzucił.
        'pending_photo_ttl_minutes' => 30,
        'code_pepper' => 'ZMIEN_MNIE_NA_LOSOWY_DLUGI_CIAG_ZNAKOW',
    ],

    'validation' => [
        'allowed_extensions' => ['jpg', 'jpeg', 'png'],
        'allowed_mime_types' => ['image/jpeg', 'image/png'],
        'min_file_size_kb' => 10,
        'max_file_size_kb' => 8192,
        'min_width_px' => 492,
        'min_height_px' => 633,
        'aspect_ratio_width' => 35,
        'aspect_ratio_height' => 45,
        'aspect_ratio_tolerance' => 0.15,
        'min_name_words' => 2,
        'max_name_words' => 4,
        'classes' => [
            '5aT', '5dT', '5eT', '5fT', '5gT', '5hT', '5iT', '5pT', '5kT', '5lT',
            '4Ta', '4Td', '4Te', '4Tg', '4Th', '4Ti', '4Tk', '4Tl', '4Tm', '4Tp', '4Tr', '4Ts',
            '3Ta', '3Td', '3Te', '3Tf', '3Ts', '3Tl', '3Ti', '3Tp', '3Tg', '3Tm', '3Tr', '3Tk', '3Th', '3BS',
            '2Ta', '2Td', '2Tk',
            '1Ta', '1Td', '1Ti', '1Tl', '1Th', '1Tk', '1Tg', '1Tm', '1Tf',
        ],
    ],

    // Automatyczna kontrola jakości zdjęcia. Komplet ustawień wraz z opisami
    // progów jest w src/Photo/PhotoConfig.php - tutaj podaje się tylko to,
    // co ma być inne niż domyślnie. Brak tej sekcji = same wartości domyślne
    // (analiza lokalna włączona, rozpoznawanie twarzy wyłączone).
    'photo_check' => [
        'enabled' => true,

        'face_api' => [
            // 'none'       - bez wykrywania twarzy,
            // 'local'       - kaskada Haara na tym serwerze, bez kluczy i bez
            //                 wysyłania zdjęcia gdziekolwiek (zalecane),
            // 'facepp'     - Face++, darmowy klucz z https://www.faceplusplus.com,
            // 'compreface' - własny serwer CompreFace.
            'provider' => 'local',

            // Kaskada Haara bywa mniej pewna niż komercyjne API, więc brak
            // wykrytej twarzy lepiej traktować jako uwagę (uczeń zobaczy ją
            // na kroku potwierdzenia) niż jako twarde odrzucenie.
            'local' => [
                'min_face_ratio' => 0.20,
                'max_face_ratio' => 0.80,
                'min_neighbours' => 3,
            ],

            'facepp' => [
                // Region klucza: api-us.faceplusplus.com albo api-cn.faceplusplus.com.
                'endpoint' => 'https://api-us.faceplusplus.com/facepp/v3/detect',
                'api_key' => '',
                'api_secret' => '',
            ],

            'compreface' => [
                'base_url' => 'https://compreface.twojaszkola.pl',
                'api_key' => '',
            ],
        ],

        // Progi do dostrojenia na własnych zdjęciach - zmierzone wartości
        // pokazuje `php tools/check_photo.php zdjecie.jpg`.
        // 'thresholds' => [
        //     'sharpness_min' => 60.0,
        //     'background_uniformity_max' => 42.0,
        // ],

        // Które uwagi mają blokować zgłoszenie ('error'), a które tylko
        // trafiać do wiadomości dla szkoły ('warning') lub być pomijane ('off').
        'checks' => [
            // Zalecane przy 'provider' => 'local'.
            'face_missing' => ['severity' => 'warning'],
        ],
    ],
];
