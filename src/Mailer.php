<?php

namespace Legitymacje;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Mailer
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * @param array<int, string> $photoWarnings uwagi z automatycznej kontroli zdjęcia
     * @param string $photoMetrics zmierzone wartości (do ręcznej weryfikacji przez szkołę)
     */
    public function sendSubmission(string $name, string $class, string $email, string $code, string $tmpPhotoPath, string $photoFilename, string $photoMime, array $photoWarnings = [], string $photoMetrics = ''): bool
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->cfg['smtp']['host'];
            $mail->Port = $this->cfg['smtp']['port'];
            $mail->SMTPAuth = !empty($this->cfg['smtp']['username']);
            $mail->Username = $this->cfg['smtp']['username'];
            $mail->Password = $this->cfg['smtp']['password'];

            if ($this->cfg['smtp']['use_smtps']) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->cfg['smtp']['use_starttls']) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
            }

            $mail->CharSet = 'UTF-8';
            $mail->setFrom($this->cfg['from_email'], $this->cfg['from_name']);
            $mail->addAddress($this->cfg['admin_email']);
            $mail->addReplyTo($email, $name);

            $subject = str_replace('{imie_nazwisko}', $name, $this->cfg['messages']['subject_admin_forward']);
            $mail->Subject = $subject;

            $body = sprintf(
                "Nowe, poprawnie zweryfikowane zgloszenie do legitymacji szkolnej.\n\n" .
                "Imie i nazwisko: %s\n" .
                "Klasa: %s\n" .
                "E-mail do kontaktu: %s\n" .
                "Kod: %s\n" .
                "Data zgloszenia: %s\n" .
                "Nazwa pliku zdjecia: %s\n\n" .
                "Zdjecie w zalaczniku.",
                $name,
                $class,
                $email,
                $code,
                date('Y-m-d H:i:s'),
                $photoFilename
            );
            $mail->Body = $body . $this->photoCheckSection($photoWarnings, $photoMetrics);

            $mail->addAttachment($tmpPhotoPath, $photoFilename, PHPMailer::ENCODING_BASE64, $photoMime);

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log('Blad wysylki maila: ' . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * @param array<int, string> $warnings
     */
    private function photoCheckSection(array $warnings, string $metrics): string
    {
        if ($warnings === [] && $metrics === '') {
            return '';
        }

        $section = "\n\n--\nAutomatyczna kontrola zdjecia\n";

        if ($warnings === []) {
            $section .= "Bez uwag.\n";
        } else {
            $section .= "Uwagi (nie blokowaly zgloszenia):\n";
            foreach ($warnings as $warning) {
                $section .= '- ' . $warning . "\n";
            }
        }

        if ($metrics !== '') {
            $section .= "\nPomiary: " . $metrics . "\n";
        }

        return $section;
    }
}
