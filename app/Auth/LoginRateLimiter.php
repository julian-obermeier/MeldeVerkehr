<?php

declare(strict_types=1);

namespace MeldeVerkehr\Auth;

use PDO;

final class LoginRateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $key,
        private readonly int $maxAttempts = 5,
        private readonly int $windowMinutes = 15,
        private readonly int $blockMinutes = 15
    ) {
    }

    public function keyFor(string $email, string $ip): string
    {
        return hash_hmac('sha256', strtolower(trim($email)) . '|' . $ip, $this->key);
    }

    public function blocked(string $keyHash): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT blocked_until FROM auth_rate_limits WHERE key_hash = :key_hash LIMIT 1'
        );
        $stmt->execute(['key_hash' => $keyHash]);
        $blockedUntil = $stmt->fetchColumn();

        return is_string($blockedUntil) && strtotime($blockedUntil . ' UTC') > time();
    }

    public function hit(string $keyHash): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT attempts, window_started_at FROM auth_rate_limits WHERE key_hash = :key_hash LIMIT 1'
        );
        $stmt->execute(['key_hash' => $keyHash]);
        $row = $stmt->fetch();

        $now = time();
        $attempts = 1;
        $windowStarted = gmdate('Y-m-d H:i:s', $now);

        if (is_array($row)) {
            $start = strtotime((string) $row['window_started_at'] . ' UTC');

            if ($start !== false && $start >= $now - ($this->windowMinutes * 60)) {
                $attempts = (int) $row['attempts'] + 1;
                $windowStarted = (string) $row['window_started_at'];
            }
        }

        $blockedUntil = $attempts >= $this->maxAttempts
            ? gmdate('Y-m-d H:i:s', $now + ($this->blockMinutes * 60))
            : null;

        $upsert = $this->pdo->prepare(
            'INSERT INTO auth_rate_limits (key_hash, attempts, window_started_at, blocked_until, updated_at)
             VALUES (:key_hash, :attempts, :window_started_at, :blocked_until, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               attempts = VALUES(attempts),
               window_started_at = VALUES(window_started_at),
               blocked_until = VALUES(blocked_until),
               updated_at = UTC_TIMESTAMP()'
        );
        $upsert->execute([
            'key_hash' => $keyHash,
            'attempts' => $attempts,
            'window_started_at' => $windowStarted,
            'blocked_until' => $blockedUntil,
        ]);
    }

    public function clear(string $keyHash): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM auth_rate_limits WHERE key_hash = :key_hash');
        $stmt->execute(['key_hash' => $keyHash]);
    }
}
