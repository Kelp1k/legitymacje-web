<?php

namespace Legitymacje\Photo;

use RuntimeException;

/**
 * Blad techniczny kontroli zdjecia (nieczytelny plik, brak polaczenia z API).
 * Komunikaty tej klasy nigdy nie zawieraja kluczy API ani tresci zdjecia -
 * trafiaja do error_log, a uzytkownik widzi wylacznie teksty z konfiguracji.
 */
class PhotoException extends RuntimeException
{
}
