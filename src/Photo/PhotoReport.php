<?php

namespace Legitymacje\Photo;

/**
 * Wynik kontroli zdjecia: bledy (blokuja wyslanie), ostrzezenia (przepuszczaja
 * zgloszenie, ale trafiaja do wiadomosci dla szkoly) oraz zmierzone wartosci.
 */
class PhotoReport
{
    private array $errors = [];
    private array $warnings = [];
    private array $metrics = [];

    /**
     * @param string $severity 'error', 'warning' albo 'off' (kontrola wylaczona)
     */
    public function add(string $severity, string $message): void
    {
        if ($message === '') {
            return;
        }

        if ($severity === 'error') {
            $this->errors[] = $message;
        } elseif ($severity === 'warning') {
            $this->warnings[] = $message;
        }
    }

    public function setMetric(string $key, $value): void
    {
        $this->metrics[$key] = $value;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    public function metrics(): array
    {
        return $this->metrics;
    }

    public function isAccepted(): bool
    {
        return $this->errors === [];
    }

    /**
     * Zwiezle podsumowanie pomiarow do wiadomosci e-mail dla szkoly.
     */
    public function metricsSummary(): string
    {
        $parts = [];
        foreach ($this->metrics as $key => $value) {
            if (is_float($value)) {
                $value = sprintf('%.1f', $value);
            } elseif (is_bool($value)) {
                $value = $value ? 'tak' : 'nie';
            } elseif ($value === null) {
                $value = '-';
            }
            $parts[] = $key . '=' . $value;
        }

        return implode(', ', $parts);
    }
}
