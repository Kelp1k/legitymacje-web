<?php

declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
// script-src 'self' dopuszcza wylacznie assets/busy.js (wskaznik ladowania).
// Nadal bez 'unsafe-inline' i 'unsafe-eval', wiec wstrzyknietego skryptu
// przegladarka nie wykona.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header_remove('X-Powered-By');

require __DIR__ . '/../vendor/PHPMailer/Exception.php';
require __DIR__ . '/../vendor/PHPMailer/SMTP.php';
require __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/CodeStore.php';
require __DIR__ . '/../src/Validator.php';
require __DIR__ . '/../src/Mailer.php';
require __DIR__ . '/../src/RateLimiter.php';
require __DIR__ . '/../src/PendingPhoto.php';
require __DIR__ . '/../src/Photo/bootstrap.php';

use Legitymacje\Database;
use Legitymacje\CodeStore;
use Legitymacje\Validator;
use Legitymacje\Mailer;
use Legitymacje\RateLimiter;
use Legitymacje\PendingPhoto;
use Legitymacje\Photo\PhotoAnalyzer;
use Legitymacje\Photo\DocumentScan;

$config = require __DIR__ . '/../src/config.php';
$security = $config['security'];

// Teksty kroku potwierdzenia - dopisywane tylko wtedy, gdy nie ma ich
// w src/config.php, żeby starszy plik konfiguracyjny dalej działał.
$msg = $config['messages'] + [
    'photo_warning_heading' => 'Zdjęcie przyjęte, ale zwróć uwagę:',
    'confirm_heading' => 'Sprawdź zdjęcie przed wysłaniem',
    'confirm_hint' => 'Możesz wysłać to zdjęcie albo wrócić i wybrać lepsze - dopóki nie wyślesz, Twój kod pozostaje ważny.',
    'confirm_send_label' => 'Wyślij mimo to',
    'confirm_retry_label' => 'Wybierz inne zdjęcie',
    'confirm_expired' => 'Zdjęcie nie doczekało się potwierdzenia - wgraj je jeszcze raz. Twój kod jest nadal ważny.',
    'busy_code_label' => 'Sprawdzamy kod...',
    'busy_code_hint' => 'Chwilę to potrwa - nie zamykaj tej strony.',
    'busy_photo_label' => 'Sprawdzamy zdjęcie...',
    'busy_photo_hint' => 'Wgrywamy zdjęcie i sprawdzamy jego jakość. Może to potrwać kilkanaście sekund - nie zamykaj tej strony i nie klikaj ponownie.',
    'busy_send_label' => 'Wysyłamy zgłoszenie...',
    'busy_send_hint' => 'Przekazujemy zgłoszenie do szkoły - nie zamykaj tej strony.',
    'crop_heading' => 'Czy to jest Twoje zdjęcie?',
    'crop_hint' => 'Przesłany plik wygląda na skan kartki, więc samo zdjęcie zostało z niego wycięte. Jeśli wygląda dobrze, możesz je wysłać.',
    'crop_use_label' => 'Tak, użyj tego zdjęcia',
    'crop_reject_label' => 'Nie, wybiorę inne',
    'scan_detected' => 'Przesłany plik wygląda na skan albo zdjęcie kartki, a nie samo zdjęcie do legitymacji. Prześlij zdjęcie zrobione telefonem lub aparatem.',
    'scan_too_small' => 'Przesłany plik wygląda na skan kartki. Samo zdjęcie ma na nim tylko %dx%d px, a potrzebne jest co najmniej %dx%d px. Prześlij zdjęcie zrobione telefonem albo zeskanuj w rozdzielczości 400 dpi lub wyższej.',
    'scan_rejected' => 'Prześlij samo zdjęcie do legitymacji - zrobione telefonem lub aparatem, nie skan kartki.',
];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$step = $_SESSION['step'] ?? 'code';
$errors = [];
$photoWarnings = [];
$nameValue = '';
$classValue = '';
$emailValue = '';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrfValid(string $csrf): bool
{
    return isset($_POST['csrf']) && hash_equals($csrf, (string) $_POST['csrf']);
}

function captchaValid(): bool
{
    $expected = $_SESSION['captcha_sum'] ?? null;
    $given = isset($_POST['captcha']) ? (int) $_POST['captcha'] : null;
    return $expected !== null && $given !== null && $expected === $given;
}

function honeypotTriggered(): bool
{
    return !empty($_POST['website']);
}

/**
 * Sprawdza plik ze zdjęciem: format, wymiary, potem kontrola jakości.
 * Używane dla zdjęcia przesłanego przez ucznia i dla kadru wyciętego ze skanu
 * (ten drugi podaje sztuczną tablicę $file z UPLOAD_ERR_OK).
 *
 * @return array{errors: string[], warnings: string[], metrics: array, summary: string, mime: string}
 */
function analyzePhotoFile(array $file, array $config): array
{
    $formatErrors = Validator::validatePhoto($file, $config['validation']);

    if ($formatErrors !== []) {
        return ['errors' => $formatErrors, 'warnings' => [], 'metrics' => [], 'summary' => '', 'mime' => ''];
    }

    $path = (string) $file['tmp_name'];
    $report = (new PhotoAnalyzer($config['photo_check'] ?? []))->analyze($path);

    return [
        'errors' => $report->errors(),
        'warnings' => $report->warnings(),
        'metrics' => $report->metrics(),
        'summary' => $report->metricsSummary(),
        'mime' => (string) mime_content_type($path),
    ];
}

function deliverSubmission(array $config, array $submission, string $photoPath): bool
{
    $mailer = new Mailer($config);

    return $mailer->sendSubmission(
        $submission['name'],
        $submission['class'],
        $submission['email'],
        $submission['code'],
        $photoPath,
        $submission['filename'],
        $submission['mime'],
        $submission['warnings'],
        $submission['metrics']
    );
}

$db = Database::get($config['db']);
$limiter = new RateLimiter($db, $security['max_attempts_per_window'], $security['window_minutes']);
$pendingPhotos = new PendingPhoto(__DIR__ . '/../data/pending');

$discardPending = static function () use ($pendingPhotos): void {
    if (isset($_SESSION['pending']['token'])) {
        $pendingPhotos->delete((string) $_SESSION['pending']['token']);
    }
    unset($_SESSION['pending']);
};

$discardCrop = static function () use ($pendingPhotos): void {
    if (isset($_SESSION['crop']['token'])) {
        $pendingPhotos->delete((string) $_SESSION['crop']['token']);
    }
    unset($_SESSION['crop']);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'check_code') {
    if (!csrfValid($csrf)) {
        $errors[] = 'Sesja wygasła, spróbuj ponownie.';
        $step = 'code';
    } elseif ($limiter->tooManyAttempts()) {
        $errors[] = $msg['rate_limited'];
        $step = 'code';
    } else {
        $limiter->recordAttempt();
        $codes = new CodeStore($db, $config['security']['code_pepper']);
        $code = trim((string) ($_POST['code'] ?? ''));

        if (honeypotTriggered()) {
            $errors[] = $msg['code_invalid'];
            $step = 'code';
        } elseif (!captchaValid()) {
            $errors[] = $msg['captcha_invalid'];
            $step = 'code';
        } elseif ($code === '' || !$codes->exists($code)) {
            $errors[] = $msg['code_invalid'];
            $step = 'code';
        } elseif (!$codes->isValidUnused($code)) {
            $errors[] = $msg['code_used'];
            $step = 'code';
        } else {
            $discardPending();
            $_SESSION['validated_code'] = $code;
            $_SESSION['step'] = 'form';
            $step = 'form';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_form') {
    if (!csrfValid($csrf)) {
        $errors[] = 'Sesja wygasła, spróbuj ponownie.';
        $step = 'code';
        unset($_SESSION['validated_code'], $_SESSION['step']);
    } elseif (empty($_SESSION['validated_code'])) {
        $errors[] = 'Sesja wygasła - wpisz kod ponownie.';
        $step = 'code';
    } elseif ($limiter->tooManyAttempts()) {
        $errors[] = $msg['rate_limited'];
        $step = 'form';
    } else {
        $limiter->recordAttempt();
        $code = $_SESSION['validated_code'];
        $codes = new CodeStore($db, $config['security']['code_pepper']);

        if (!$codes->isValidUnused($code)) {
            $errors[] = $msg['code_used'];
            $step = 'code';
            unset($_SESSION['validated_code'], $_SESSION['step']);
        } else {
            [$name, $nameErrors] = Validator::parseName((string) ($_POST['name'] ?? ''), $config['validation']);
            $nameValue = (string) ($_POST['name'] ?? '');
            $errors = array_merge($errors, $nameErrors);

            [$class, $classErrors] = Validator::parseClass((string) ($_POST['class'] ?? ''), $config['validation']);
            $classValue = (string) ($_POST['class'] ?? '');
            $errors = array_merge($errors, $classErrors);

            [$email, $emailErrors] = Validator::parseEmail((string) ($_POST['email'] ?? ''));
            $emailValue = (string) ($_POST['email'] ?? '');
            $errors = array_merge($errors, $emailErrors);

            $checked = analyzePhotoFile($_FILES['photo'] ?? [], $config);
            $errors = array_merge($errors, $checked['errors']);
            $photoWarnings = $checked['warnings'];
            $photoMetrics = $checked['summary'];
            $photoMime = $checked['mime'];

            $discardPending();
            $discardCrop();

            // Skan kartki ze wklejonym zdjęciem: proporcje A4 mieszczą się w
            // tolerancji, więc walidacja go nie odrzuca, a kontrola jakości
            // mówi "prześwietlone", bo kadr to w 96% biały papier. Zamiast
            // tego proponujemy uczniowi wycięte zdjęcie.
            $scanCfg = $config['photo_check']['document_scan'] ?? [];
            if (
                $errors !== []
                && ($scanCfg['enabled'] ?? false)
                && DocumentScan::suspectedFrom($checked['metrics'], $scanCfg)
            ) {
                $scan = DocumentScan::detect((string) $_FILES['photo']['tmp_name'], $scanCfg);

                if ($scan !== null) {
                    $tooSmall = $scan->cropWidth() < (int) $config['validation']['min_width_px']
                        || $scan->cropHeight() < (int) $config['validation']['min_height_px'];
                    $cropBytes = $scan->cropUsable() && !$tooSmall ? $scan->cropJpeg() : null;

                    if ($cropBytes !== null) {
                        $cropToken = $pendingPhotos->storeContents($cropBytes);
                    }

                    if (!empty($cropToken)) {
                        $errors = [];
                        $photoWarnings = [];
                        $_SESSION['crop'] = [
                            'token' => $cropToken,
                            'filename' => basename((string) $_FILES['photo']['name']),
                            'submission' => ['name' => $name, 'class' => $class, 'email' => $email],
                        ];
                        $_SESSION['step'] = 'crop';
                        $step = 'crop';
                    } elseif ($tooSmall && $scan->cropUsable()) {
                        $errors = [sprintf(
                            $msg['scan_too_small'],
                            $scan->cropWidth(),
                            $scan->cropHeight(),
                            (int) $config['validation']['min_width_px'],
                            (int) $config['validation']['min_height_px']
                        )];
                    } else {
                        $errors = [$msg['scan_detected']];
                    }
                }
            }

            if ($step === 'crop') {
                // Czekamy na decyzję ucznia co do wyciętego kadru.
            } elseif (!empty($errors)) {
                $photoWarnings = [];
                $step = 'form';
            } else {
                $submission = [
                    'name' => $name,
                    'class' => $class,
                    'email' => $email,
                    'code' => $code,
                    'filename' => basename((string) $_FILES['photo']['name']),
                    'mime' => $photoMime,
                    'warnings' => $photoWarnings,
                    'metrics' => $photoMetrics,
                ];

                // Zdjęcie z uwagami nie leci od razu: uczeń najpierw je widzi
                // i decyduje. Kod zostaje nietknięty aż do potwierdzenia.
                $token = $photoWarnings !== [] ? $pendingPhotos->store($_FILES['photo']['tmp_name']) : null;

                if ($token !== null) {
                    $_SESSION['pending'] = ['token' => $token, 'submission' => $submission];
                    $_SESSION['step'] = 'confirm';
                    $step = 'confirm';
                } elseif (deliverSubmission($config, $submission, $_FILES['photo']['tmp_name']) && $codes->redeem($code)) {
                    unset($_SESSION['validated_code'], $_SESSION['step']);
                    $step = 'success';
                } else {
                    $errors[] = 'Nie udało się wysłać zgłoszenia - spróbuj ponownie za chwilę.';
                    $photoWarnings = [];
                    $step = 'form';
                }
            }
        }
    }
}

// Decyzja o kadrze wyciętym ze skanu kartki. Jak krok potwierdzenia, nie
// liczy się do limitu prób - kod jest już sprawdzony i siedzi w sesji.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_crop') {
    $crop = $_SESSION['crop'] ?? null;
    $cropPath = isset($crop['token']) ? $pendingPhotos->path((string) $crop['token']) : null;

    if (!csrfValid($csrf)) {
        $errors[] = 'Sesja wygasła, spróbuj ponownie.';
        $step = $_SESSION['step'] ?? 'code';
    } elseif ($cropPath === null || empty($_SESSION['validated_code'])) {
        $discardCrop();
        $errors[] = $msg['confirm_expired'];
        $_SESSION['step'] = 'form';
        $step = 'form';
    } elseif (($_POST['decision'] ?? '') !== 'use') {
        $nameValue = (string) ($crop['submission']['name'] ?? '');
        $classValue = (string) ($crop['submission']['class'] ?? '');
        $emailValue = (string) ($crop['submission']['email'] ?? '');
        $discardCrop();
        $errors[] = $msg['scan_rejected'];
        $_SESSION['step'] = 'form';
        $step = 'form';
    } else {
        $code = $_SESSION['validated_code'];
        $codes = new CodeStore($db, $config['security']['code_pepper']);
        $cropName = (string) ($crop['filename'] ?? 'zdjecie.jpg');

        if (!$codes->isValidUnused($code)) {
            $discardCrop();
            $errors[] = $msg['code_used'];
            unset($_SESSION['validated_code'], $_SESSION['step']);
            $step = 'code';
        } else {
            $checked = analyzePhotoFile([
                'error' => UPLOAD_ERR_OK,
                'size' => (int) @filesize($cropPath),
                'name' => $cropName,
                'tmp_name' => $cropPath,
            ], $config);

            if ($checked['errors'] !== []) {
                $nameValue = (string) ($crop['submission']['name'] ?? '');
                $classValue = (string) ($crop['submission']['class'] ?? '');
                $emailValue = (string) ($crop['submission']['email'] ?? '');
                $discardCrop();
                $errors = array_merge($errors, $checked['errors']);
                $_SESSION['step'] = 'form';
                $step = 'form';
            } else {
                $submission = [
                    'name' => (string) ($crop['submission']['name'] ?? ''),
                    'class' => (string) ($crop['submission']['class'] ?? ''),
                    'email' => (string) ($crop['submission']['email'] ?? ''),
                    'code' => $code,
                    'filename' => $cropName,
                    'mime' => $checked['mime'],
                    'warnings' => $checked['warnings'],
                    'metrics' => $checked['summary'],
                ];

                if ($checked['warnings'] !== []) {
                    // Kadr ma uwagi - przechodzi na zwykły krok potwierdzenia.
                    // Ten sam plik, więc tylko przepisujemy token, bez kasowania.
                    $_SESSION['pending'] = ['token' => (string) $crop['token'], 'submission' => $submission];
                    unset($_SESSION['crop']);
                    $_SESSION['step'] = 'confirm';
                    $step = 'confirm';
                    $photoWarnings = $checked['warnings'];
                } elseif (deliverSubmission($config, $submission, $cropPath) && $codes->redeem($code)) {
                    $discardCrop();
                    unset($_SESSION['validated_code'], $_SESSION['step']);
                    $step = 'success';
                } else {
                    $errors[] = 'Nie udało się wysłać zgłoszenia - spróbuj ponownie za chwilę.';
                    $step = 'crop';
                }
            }
        }
    }
}

// Krok potwierdzenia. Nie liczy się do limitu prób: kod jest już sprawdzony
// i siedzi w sesji, więc nie da się tędy zgadywać kodów.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_photo') {
    $pending = $_SESSION['pending'] ?? null;
    $photoPath = isset($pending['token'], $pending['submission'])
        ? $pendingPhotos->path((string) $pending['token'])
        : null;

    if (!csrfValid($csrf)) {
        $errors[] = 'Sesja wygasła, spróbuj ponownie.';
        $step = $_SESSION['step'] ?? 'code';
    } elseif ($photoPath === null || empty($_SESSION['validated_code'])) {
        $discardPending();
        $errors[] = $msg['confirm_expired'];
        $_SESSION['step'] = 'form';
        $step = 'form';
    } elseif (($_POST['decision'] ?? '') !== 'send') {
        $discardPending();
        $nameValue = (string) $pending['submission']['name'];
        $classValue = (string) $pending['submission']['class'];
        $emailValue = (string) $pending['submission']['email'];
        $_SESSION['step'] = 'form';
        $step = 'form';
    } else {
        $code = $_SESSION['validated_code'];
        $codes = new CodeStore($db, $config['security']['code_pepper']);

        if (!$codes->isValidUnused($code)) {
            $discardPending();
            $errors[] = $msg['code_used'];
            unset($_SESSION['validated_code'], $_SESSION['step']);
            $step = 'code';
        } elseif (deliverSubmission($config, $pending['submission'], $photoPath) && $codes->redeem($code)) {
            $photoWarnings = $pending['submission']['warnings'];
            $discardPending();
            unset($_SESSION['validated_code'], $_SESSION['step']);
            $step = 'success';
        } else {
            $errors[] = 'Nie udało się wysłać zgłoszenia - spróbuj ponownie za chwilę.';
            $step = 'confirm';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $step = $_SESSION['step'] ?? 'code';
}

// Na kroku potwierdzenia uwagi biorą się z sesji, żeby przetrwały odświeżenie.
if ($step === 'confirm') {
    $photoWarnings = $_SESSION['pending']['submission']['warnings'] ?? [];
    if ($photoWarnings === []) {
        $_SESSION['step'] = 'form';
        $step = 'form';
    }
}

// Odświeżenie na kroku kadru bez kadru w sesji (wygasł, został skasowany).
if ($step === 'crop' && !isset($_SESSION['crop']['token'])) {
    $_SESSION['step'] = 'form';
    $step = 'form';
}

if (random_int(1, 20) === 1) {
    $limiter->cleanup();
    $pendingPhotos->cleanup((int) ($security['pending_photo_ttl_minutes'] ?? 30));
}

$captchaSrc = 'captcha.php?r=' . bin2hex(random_bytes(4));
$previewSrc = 'preview.php?r=' . bin2hex(random_bytes(4));

// Cloudflare serwuje assets z max-age 4h, wiec po zmianie CSS albo JS
// przegladarka trzymalaby stara wersje. Znacznik czasu pliku w adresie
// sprawia, ze nowy plik ma nowy URL i cache go nie dotyczy.
$asset = static function (string $file): string {
    $path = __DIR__ . '/assets/' . $file;

    return 'assets/' . $file . '?v=' . (is_file($path) ? (string) filemtime($path) : '0');
};
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($msg['page_title']) ?></title>
<link rel="stylesheet" href="<?= e($asset('style.css')) ?>">
</head>
<body>
<main class="card">
<h1><?= e($msg['intro_heading']) ?></h1>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
<p class="alert-title"><?= e($msg['error_heading']) ?></p>
<ul>
<?php foreach ($errors as $err): ?>
<li><?= e($err) ?></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>

<?php if ($step === 'code'): ?>
<p class="lead"><?= e($msg['intro_text']) ?></p>
<form method="post" autocomplete="off" data-busy-label="<?= e($msg['busy_code_label']) ?>">
<input type="hidden" name="action" value="check_code">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<div class="honeypot" aria-hidden="true">
<label for="website">Strona WWW</label>
<input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
</div>
<label for="code"><?= e($msg['code_label']) ?></label>
<input type="text" id="code" name="code" placeholder="<?= e($msg['code_placeholder']) ?>" inputmode="numeric" autofocus required>
<label for="captcha"><?= e($msg['captcha_label']) ?></label>
<img class="captcha-image" src="<?= e($captchaSrc) ?>" alt="<?= e($msg['captcha_label']) ?>" width="140" height="48">
<input type="text" id="captcha" name="captcha" inputmode="numeric" autocomplete="off" required>
<button type="submit"><?= e($msg['submit_label']) ?></button>
<p class="progress-hint" role="status"><?= e($msg['busy_code_hint']) ?></p>
</form>

<?php elseif ($step === 'form'): ?>
<h2><?= e($msg['form_heading']) ?></h2>
<form method="post" enctype="multipart/form-data" data-busy-label="<?= e($msg['busy_photo_label']) ?>">
<input type="hidden" name="action" value="submit_form">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<label for="name"><?= e($msg['name_label']) ?></label>
<input type="text" id="name" name="name" placeholder="<?= e($msg['name_placeholder']) ?>" value="<?= e($nameValue) ?>" required>
<label for="class"><?= e($msg['class_label']) ?></label>
<select id="class" name="class" required>
<option value="" <?= $classValue === '' ? 'selected' : '' ?>><?= e($msg['class_placeholder']) ?></option>
<?php foreach ($config['validation']['classes'] as $cls): ?>
<option value="<?= e($cls) ?>" <?= $classValue === $cls ? 'selected' : '' ?>><?= e($cls) ?></option>
<?php endforeach; ?>
</select>
<label for="email"><?= e($msg['email_label']) ?></label>
<input type="email" id="email" name="email" placeholder="<?= e($msg['email_placeholder']) ?>" value="<?= e($emailValue) ?>" required>
<p class="hint"><?= e($msg['email_hint']) ?></p>
<label for="photo"><?= e($msg['photo_label']) ?></label>
<input type="file" id="photo" name="photo" accept="image/jpeg,image/png" required>
<p class="hint"><?= e($msg['photo_hint']) ?></p>
<button type="submit"><?= e($msg['submit_label']) ?></button>
<p class="progress-hint" role="status"><?= e($msg['busy_photo_hint']) ?></p>
</form>

<?php elseif ($step === 'crop'): ?>
<h2><?= e($msg['crop_heading']) ?></h2>
<p class="lead"><?= e($msg['crop_hint']) ?></p>
<img class="photo-preview" src="<?= e($previewSrc) ?>" alt="<?= e($msg['crop_heading']) ?>">
<form method="post" data-busy-label="<?= e($msg['busy_send_label']) ?>" data-busy-skip="reject">
<input type="hidden" name="action" value="confirm_crop">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<button type="submit" name="decision" value="use"><?= e($msg['crop_use_label']) ?></button>
<button type="submit" name="decision" value="reject" class="button-secondary"><?= e($msg['crop_reject_label']) ?></button>
<p class="progress-hint" role="status"><?= e($msg['busy_photo_hint']) ?></p>
</form>

<?php elseif ($step === 'confirm'): ?>
<div class="alert alert-warning">
<p class="alert-title"><?= e($msg['confirm_heading']) ?></p>
<ul>
<?php foreach ($photoWarnings as $warning): ?>
<li><?= e($warning) ?></li>
<?php endforeach; ?>
</ul>
</div>
<img class="photo-preview" src="<?= e($previewSrc) ?>" alt="<?= e($msg['confirm_heading']) ?>">
<p class="hint"><?= e($msg['confirm_hint']) ?></p>
<form method="post" data-busy-label="<?= e($msg['busy_send_label']) ?>" data-busy-skip="retry">
<input type="hidden" name="action" value="confirm_photo">
<input type="hidden" name="csrf" value="<?= e($csrf) ?>">
<button type="submit" name="decision" value="send"><?= e($msg['confirm_send_label']) ?></button>
<button type="submit" name="decision" value="retry" class="button-secondary"><?= e($msg['confirm_retry_label']) ?></button>
<p class="progress-hint" role="status"><?= e($msg['busy_send_hint']) ?></p>
</form>

<?php elseif ($step === 'success'): ?>
<div class="alert alert-success">
<p class="alert-title"><?= e($msg['success_heading']) ?></p>
<p><?= e($msg['success_text']) ?></p>
</div>
<?php if (!empty($photoWarnings)): ?>
<div class="alert alert-warning">
<p class="alert-title"><?= e($msg['photo_warning_heading']) ?></p>
<ul>
<?php foreach ($photoWarnings as $warning): ?>
<li><?= e($warning) ?></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>
<?php endif; ?>

</main>
<script src="<?= e($asset('busy.js')) ?>"></script>
</body>
</html>
