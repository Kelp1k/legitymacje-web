<?php

namespace Legitymacje;

use PDO;

class CodeStore
{
    private PDO $db;
    private string $pepper;

    public function __construct(PDO $db, string $pepper)
    {
        $this->db = $db;
        $this->pepper = $pepper;
    }

    public function hash(string $code): string
    {
        return hash_hmac('sha256', $code, $this->pepper);
    }

    public function isValidUnused(string $code): bool
    {
        $stmt = $this->db->prepare('SELECT used FROM codes WHERE code_hash = ?');
        $stmt->execute([$this->hash($code)]);
        $row = $stmt->fetch();
        return $row !== false && (int) $row['used'] === 0;
    }

    public function exists(string $code): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM codes WHERE code_hash = ?');
        $stmt->execute([$this->hash($code)]);
        return $stmt->fetch() !== false;
    }

    public function redeem(string $code): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE codes SET used = 1, used_at = NOW() WHERE code_hash = ? AND used = 0'
        );
        $stmt->execute([$this->hash($code)]);
        return $stmt->rowCount() === 1;
    }
}
