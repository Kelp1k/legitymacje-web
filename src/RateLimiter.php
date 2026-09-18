<?php

namespace Legitymacje;

use PDO;

class RateLimiter
{
    private PDO $db;
    private int $maxAttempts;
    private int $windowMinutes;

    public function __construct(PDO $db, int $maxAttempts, int $windowMinutes)
    {
        $this->db = $db;
        $this->maxAttempts = $maxAttempts;
        $this->windowMinutes = $windowMinutes;
    }

    private function ipHash(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return hash('sha256', $ip . '|legitymacje-rate-limit');
    }

    public function tooManyAttempts(): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS cnt FROM code_attempts WHERE ip_hash = ? AND created_at > (NOW() - INTERVAL ? MINUTE)'
        );
        $stmt->execute([$this->ipHash(), $this->windowMinutes]);
        $row = $stmt->fetch();
        return ((int) $row['cnt']) >= $this->maxAttempts;
    }

    public function recordAttempt(): void
    {
        $stmt = $this->db->prepare('INSERT INTO code_attempts (ip_hash, created_at) VALUES (?, NOW())');
        $stmt->execute([$this->ipHash()]);
    }

    public function cleanup(): void
    {
        $stmt = $this->db->prepare('DELETE FROM code_attempts WHERE created_at < (NOW() - INTERVAL ? MINUTE)');
        $stmt->execute([$this->windowMinutes * 4]);
    }
}
