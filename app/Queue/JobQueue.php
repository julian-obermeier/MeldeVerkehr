<?php

declare(strict_types=1);

namespace MeldeVerkehr\Queue;

use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class JobQueue
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function push(
        string $type,
        array $payload = [],
        int $priority = 0,
        int $maxAttempts = 5,
        ?\DateTimeImmutable $availableAt = null
    ): string {
        $uuid = Uuid::v4();
        $availableAt ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $stmt = $this->pdo->prepare(
            'INSERT INTO jobs (
                uuid, type, payload_json, priority, status, attempts, max_attempts,
                available_at, locked_at, locked_by, last_error, created_at, completed_at
             ) VALUES (
                :uuid, :type, :payload_json, :priority, "PENDING", 0, :max_attempts,
                :available_at, NULL, NULL, NULL, UTC_TIMESTAMP(), NULL
             )'
        );
        $stmt->execute([
            'uuid' => $uuid,
            'type' => $type,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'priority' => $priority,
            'max_attempts' => max(1, $maxAttempts),
            'available_at' => $availableAt->format('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    public function claim(string $workerId): ?array
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->query(
                'SELECT *
                 FROM jobs
                 WHERE status IN ("PENDING", "RETRY")
                   AND available_at <= UTC_TIMESTAMP()
                   AND (locked_at IS NULL OR locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))
                 ORDER BY priority DESC, id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $job = $stmt->fetch();

            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                'UPDATE jobs
                 SET status = "RUNNING",
                     attempts = attempts + 1,
                     locked_at = UTC_TIMESTAMP(),
                     locked_by = :worker
                 WHERE id = :id'
            );
            $update->execute([
                'worker' => $workerId,
                'id' => $job['id'],
            ]);

            $this->pdo->commit();

            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['payload'] = json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);

            return $job;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function complete(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE jobs
             SET status = "DONE", completed_at = UTC_TIMESTAMP(), locked_at = NULL, locked_by = NULL, last_error = NULL
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function fail(array $job, Throwable $error): void
    {
        $attempts = (int) ($job['attempts'] ?? 1);
        $maxAttempts = (int) ($job['max_attempts'] ?? 5);
        $terminal = $attempts >= $maxAttempts;

        $delays = [60, 300, 900, 3600];
        $delay = $delays[min(max($attempts - 1, 0), count($delays) - 1)];
        $availableAt = gmdate('Y-m-d H:i:s', time() + $delay);

        $stmt = $this->pdo->prepare(
            'UPDATE jobs
             SET status = :status,
                 available_at = :available_at,
                 locked_at = NULL,
                 locked_by = NULL,
                 last_error = :last_error,
                 completed_at = :completed_at
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $terminal ? 'FAILED' : 'RETRY',
            'available_at' => $availableAt,
            'last_error' => mb_substr($error->getMessage(), 0, 2000),
            'completed_at' => $terminal ? gmdate('Y-m-d H:i:s') : null,
            'id' => $job['id'],
        ]);
    }
}
