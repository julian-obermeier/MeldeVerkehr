<?php

declare(strict_types=1);

namespace MeldeVerkehr\Release;

use PDO;

final class RequestRateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $secret
    ) {
    }

    public function consume(
        string $bucket,
        string $subject,
        int $maxAttempts,
        int $windowSeconds,
        int $blockSeconds
    ): array {
        $bucket = trim($bucket);
        if ($bucket === '' || $maxAttempts < 1 || $windowSeconds < 1 || $blockSeconds < 1) {
            throw new \InvalidArgumentException('Ungültige Rate-Limit-Konfiguration.');
        }

        $hash = hash_hmac('sha256', $bucket . '|' . $subject, $this->secret);
        $stmt = $this->pdo->prepare(
            'SELECT attempts, window_started_at, blocked_until
             FROM request_rate_limits WHERE key_hash = :key_hash LIMIT 1'
        );
        $stmt->execute(['key_hash' => $hash]);
        $row = $stmt->fetch();

        $now = time();

        if (
            is_array($row)
            && is_string($row['blocked_until'])
            && strtotime($row['blocked_until'] . ' UTC') > $now
        ) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => max(1, strtotime($row['blocked_until'] . ' UTC') - $now),
            ];
        }

        $attempts = 1;
        $windowStarted = gmdate('Y-m-d H:i:s', $now);

        if (is_array($row)) {
            $started = strtotime((string) $row['window_started_at'] . ' UTC');

            if ($started !== false && $started >= $now - $windowSeconds) {
                $attempts = (int) $row['attempts'] + 1;
                $windowStarted = (string) $row['window_started_at'];
            }
        }

        $blockedUntil = $attempts > $maxAttempts
            ? gmdate('Y-m-d H:i:s', $now + $blockSeconds)
            : null;

        $this->pdo->prepare(
            'INSERT INTO request_rate_limits
             (key_hash, bucket, attempts, window_started_at, blocked_until, updated_at)
             VALUES
             (:key_hash, :bucket, :attempts, :window_started_at, :blocked_until, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                bucket = VALUES(bucket),
                attempts = VALUES(attempts),
                window_started_at = VALUES(window_started_at),
                blocked_until = VALUES(blocked_until),
                updated_at = UTC_TIMESTAMP()'
        )->execute([
            'key_hash' => $hash,
            'bucket' => $bucket,
            'attempts' => $attempts,
            'window_started_at' => $windowStarted,
            'blocked_until' => $blockedUntil,
        ]);

        return [
            'allowed' => $blockedUntil === null,
            'remaining' => max(0, $maxAttempts - $attempts),
            'retry_after' => $blockedUntil === null ? 0 : $blockSeconds,
        ];
    }

    public function cleanup(): int
    {
        return $this->pdo->exec(
            'DELETE FROM request_rate_limits
             WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)'
        );
    }
}
