# Zgłoszenia do legitymacji szkolnej

Strona z jednorazowymi kodami: uczeń wpisuje kod, podaje imię, nazwisko,
e-mail i zdjęcie. Poprawne zgłoszenie leci mailem do szkoły, kod wygasa.

## Wymagania

- PHP 8.1+ (`pdo_mysql`, `fileinfo`, `openssl`, `gd`)
- Zalecane: `exif` (obsługa zdjęć obróconych przez telefon) i `curl`
  (potrzebny tylko przy włączonym rozpoznawaniu twarzy przez API)
- MySQL/MariaDB
- Dostęp SMTP (host, port, login, hasło) do skrzynki wysyłkowej

## Instalacja

```bash
mysql -u UZYTKOWNIK -p BAZA < schema.sql
cp src/config.example.php src/config.php
```

W `src/config.php` uzupełnij: `admin_email`, `from_email`, `smtp.*`, `db.*`
oraz `security.code_pepper` - losowy, długi ciąg znaków (np. wynik
`php -r "echo bin2hex(random_bytes(32));"`). Kody w bazie trzymane są
jako hash (HMAC-SHA256) tego kodu + pepper, nie jawnym tekstem - bez
peppera nikt z dostępem do samej bazy nie odczyta ani nie odgadnie
kodów offline. Pepper raz ustawiony nie może się zmienić - zmiana
unieważni wszystkie już zaimportowane/wydrukowane kody.

Sprawdź w konfiguracji PHP hostingu (`upload_max_filesize`,
`post_max_size`), że limity są co najmniej tak wysokie jak
`validation.max_file_size_kb` w `src/config.php` (domyślnie 8 MB) -
inaczej większe zdjęcia zostaną odrzucone przez PHP, zanim aplikacja
zdąży pokazać własny komunikat błędu.

Cały projekt wgraj razem (np. do `public_html/`, obok istniejącej strony).
Adres `/legitymacja` ma wskazywać na zawartość folderu `public/` - albo
ustaw go jako document root pod tą ścieżką, albo skopiuj zawartość
`public/` bezpośrednio do `public_html/legitymacja/`, zostawiając
`src/`, `vendor/`, `tools/`, `data/` jako foldery siostrzane w `public_html/`
(dokładnie jak w repo - kod szuka ich względem `public/` przez `../`).

Foldery `src/`, `vendor/`, `tools/`, `data/` mają dołączone pliki
`.htaccess` blokujące dostęp z przeglądarki (działa na Apache/LiteSpeed -
typowy hosting współdzielony). Jeśli hosting stoi na nginx, `.htaccess`
nic nie zrobi - trzeba dodać analogiczne reguły w konfiguracji serwera.

## Kody

```bash
php tools/generate_codes.php 500 10
```

Generuje 500 kodów po 10 cyfr, wstawia do bazy, zapisuje CSV w `data/`.

```bash
php tools/generate_print_sheet.php
```

Generuje plik HTML z kartami do wydruku i wycięcia (otwórz w
przeglądarce, Ctrl/Cmd+P) na podstawie najnowszego pliku CSV z `data/`
(w bazie są tylko hashe, więc źródłem jawnych kodów do druku jest CSV
zapisany przy generowaniu, nie baza).

## Konfiguracja

Wszystkie teksty na stronie i limity walidacji są w `src/config.php`
(sekcje `messages`, `validation`, `security`) - edycja bez dotykania kodu.

## Kontrola jakości zdjęcia

Zanim zgłoszenie pojedzie do szkoły, zdjęcie przechodzi automatyczną kontrolę
(`src/Photo/`). Składa się ona z dwóch warstw.

### Warstwa 1: analiza lokalna (zawsze, bez internetu)

Liczona biblioteką GD na pomniejszonej kopii roboczej. Nic nie wychodzi
poza serwer i nic nie ląduje na dysku.

| Co sprawdza | Kiedy zgłasza uwagę |
| --- | --- |
| jasność twarzy | zdjęcie za ciemne albo prześwietlone |
| kontrast i zakres tonalny | zdjęcie zamglone, czarne lub wypalone plamy |
| równomierność oświetlenia | jedna strona twarzy wyraźnie ciemniejsza |
| ostrość (wariancja laplasjanu) | zdjęcie poruszone lub nieostre |
| tło (pasy przy górnej i bocznych krawędziach) | tło ciemne albo niejednolite |
| nasycenie kolorów | zdjęcie czarno-białe |
| obecność osoby w kadrze | zdjęcie ściany, kartki, sufitu |

Ostatnia pozycja to heurystyka: sprawdza rozrzut jasności w środku kadru, więc
odróżnia portret od pustej ściany, ale **nie jest wykrywaniem twarzy**. Do tego
służy warstwa druga.

Kontrola odcienia skóry (`skin_ratio_low`) jest celowo wyłączona - wynik takiej
heurystyki zależy od karnacji i barwy światła, więc łatwo o krzywdzące odrzucenie.
Z tego samego powodu próg blokujący za ciemne zdjęcie (`brightness_min`) jest
ustawiony nisko: poprawnie naświetlony portret osoby o ciemnej karnacji ma
niższą średnią jasność niż jasnej.

### Warstwa 2: rozpoznawanie twarzy przez API (opcjonalnie)

Domyślnie wyłączone (`photo_check.face_api.provider` = `none`). Po włączeniu
dochodzą kontrole: czy twarz w ogóle jest, czy jest tylko jedna, jaką część
kadru zajmuje, czy jest wyśrodkowana, czy głowa nie jest przekrzywiona, czy
oczy są otwarte i czy sama twarz nie jest rozmyta.

Do wyboru są trzej dostawcy:

- **`local`** (zalecane) - kaskada Haara (Viola-Jones) licząca się na tym
  serwerze, w czystym PHP. Nie trzeba kluczy, nic nie wychodzi na zewnątrz,
  nie ma tematu powierzenia danych osobowych. Dane klasyfikatora leżą
  w `vendor/haarcascades/` (przekodowane kaskady OpenCV, licencja w
  `LICENSE.txt` obok). Rozpoznanie zajmuje ok. 150-300 ms i ok. 16 MB pamięci.
  Daje: obecność twarzy, liczbę osób, wielkość i wyśrodkowanie twarzy oraz
  przechylenie głowy (z linii między oczami). Nie daje oceny rozmycia twarzy.

  Metoda pochodzi z 2001 roku i ma swoje granice: **gubi twarze** przy mocnym
  cieniu, w okularach, przy przechyleniu głowy powyżej ~15-20 stopni i częściej
  przy ciemnej karnacji w słabym świetle. Dlatego przy tym dostawcy warto
  zostawić `face_missing` jako `warning` - uczeń zobaczy uwagę na kroku
  potwierdzenia i sam zdecyduje, zamiast dostać twarde odrzucenie.

- **`facepp`** - [Face++](https://www.faceplusplus.com). Darmowy klucz
  (API Key + API Secret) wystarcza: rejestracja, konsola, "Get API Key".
  Limit w darmowym planie dotyczy liczby jednoczesnych żądań, nie liczby
  zdjęć. Wpisz klucze w `src/config.php` i ustaw `provider` na `facepp`.
- **`compreface`** - [CompreFace](https://github.com/exadel-inc/CompreFace),
  otwartoźródłowy serwer stawiany na własnej maszynie (Docker). Nic nie
  wychodzi poza infrastrukturę szkoły - z punktu widzenia RODO najbezpieczniejszy
  wariant. W konfiguracji podaj `base_url` i `api_key` usługi Detection.

Kaskady da się odtworzyć z oryginałów OpenCV:

```bash
php tools/convert_cascade.php haarcascade_frontalface_default.xml vendor/haarcascades/frontalface.bin
php tools/convert_cascade.php haarcascade_eye.xml vendor/haarcascades/eye.bin
```

Awaria API nie blokuje zgłoszeń: domyślnie dopisywana jest uwaga
"kontrola chwilowo niedostępna", a zgłoszenie idzie do ręcznej weryfikacji.
Zmiana `checks.api_unavailable.severity` na `error` powoduje odrzucanie
zgłoszeń, gdy API nie odpowiada, a na `off` - ciche przepuszczanie.

### Co dzieje się z wynikiem

Kontrole dzielą się na blokujące (`error`) i informacyjne (`warning`). Każdą da
się przestawić między `error`, `warning` i `off` w `src/config.php`.

**Błąd** - zgłoszenie nie wychodzi, uczeń dostaje listę rzeczy do poprawy, mail
nie jest wysyłany, a kod pozostaje ważny i można spróbować jeszcze raz.

**Uwaga** - zgłoszenie też jeszcze nie wychodzi. Uczeń widzi uwagi i wybiera:
"Wyślij mimo to" albo "Wybierz inne zdjęcie". Dopiero potwierdzenie wysyła maila
i zużywa kod - rezygnacja nie kosztuje nic. Szkoła dostaje uwagi w treści
wiadomości razem z surowymi pomiarami, więc wie, na co spojrzeć.

Między pokazaniem uwag a decyzją zdjęcie musi gdzieś poczekać, bo przeglądarka
nie odsyła raz wybranego pliku drugi raz. Ląduje w `data/pending/` (katalog
`0700`, plik `0600`, losowa nazwa) i znika zaraz po decyzji. Porzucone pliki -
gdy uczeń zamknie kartę - kasuje sprzątanie po `security.pending_photo_ttl_minutes`
(domyślnie 30 minut). Katalog `data/` jest zablokowany plikiem `.htaccess`;
na nginksie trzeba dodać analogiczną regułę ręcznie, tak samo jak dla plików
CSV z kodami.

### Bezpieczeństwo i dane osobowe

- Przy `provider` = `none` i `provider` = `local` zdjęcie **nigdy nie opuszcza
  serwera**. Zapisywane
  jest na dysk tylko wtedy, gdy czeka na potwierdzenie przez ucznia (patrz
  wyżej) - przy zdjęciu bez uwag i przy zdjęciu odrzuconym w ogóle tam nie
  trafia.
- Do API trafiają wyłącznie piksele - imię, nazwisko, klasa, e-mail i kod
  zostają na serwerze szkoły.
- Zdjęcie wysyłane do API jest wcześniej pomniejszane i przekodowywane na JPG,
  co usuwa metadane EXIF, w tym lokalizację GPS i model aparatu.
- Połączenie wyłącznie po HTTPS (TLS 1.2+), z weryfikacją certyfikatu i nazwy
  hosta oraz bez podążania za przekierowaniami. `http://` jest dopuszczalne
  tylko dla instancji CompreFace na `127.0.0.1` i wymaga jawnego
  `allow_local_http`. Można dodatkowo przypiąć klucz publiczny serwera API
  (`pinned_public_key`, np. `sha256//...`).
- Treść żądania budowana jest w pamięci - zdjęcie w żadnym momencie nie jest
  zapisywane na dysku, a plik tymczasowy PHP kasuje po zakończeniu żądania.
- Odpowiedź API ma twardy limit rozmiaru i czasu, a komunikaty błędów nie
  zawierają kluczy API, więc nie wyciekną do logów.
- Wysyłanie zdjęć uczniów (często niepełnoletnich) do firmy zewnętrznej to
  powierzenie przetwarzania danych osobowych. Przed włączeniem `facepp`
  uzgodnij to z inspektorem ochrony danych szkoły i uzupełnij klauzulę
  informacyjną. Jeśli ma tego nie być - zostaw `none` albo postaw `compreface`
  u siebie.

### Dobieranie progów

Progi są w `src/config.php` (sekcja `photo_check.thresholds`), a komplet
wartości domyślnych wraz z opisami - w `src/Photo/PhotoConfig.php`. Żeby
zobaczyć, co kontrola mierzy na konkretnym zdjęciu:

```bash
php tools/check_photo.php zdjecie.jpg
```

Skrypt wypisze wszystkie pomiary, błędy i uwagi, bez dotykania bazy i bez
wysyłania maila. Warto puścić go na kilkunastu zdjęciach, które szkoła uznaje
za dobre, i na kilku ewidentnie złych - i dopiero wtedy ustawić progi.
Wartości domyślne są celowo łagodne: lepiej przepuścić zdjęcie z uwagą, niż
odesłać ucznia z błędem, którego nie rozumie.

## Ochrona przed nadużyciami

Limit prób (`security.max_attempts_per_window`) liczony jest per adres
IP. Jeśli strona stoi za Cloudflare lub innym reverse proxy, serwer
WWW musi odtwarzać prawdziwy adres klienta (np. moduł `ngx_http_realip_module`
w nginksie z `set_real_ip_from`/`real_ip_header CF-Connecting-IP`) -
inaczej każde żądanie widziane jest z innego adresu brzegowego proxy
i limit nigdy się nie uruchomi.
