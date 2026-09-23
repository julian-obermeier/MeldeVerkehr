<?php

declare(strict_types=1);

namespace MeldeVerkehr\Cron;

use MeldeVerkehr\Support\Uuid;
use PDO;

final class CronHeartbeat
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function start(string $task): string
    {
        $uuid = Uuid::v4();

        $stmt = $this->pdo->prepare(
            'INSERT INTO cron_runs
             (run_uuid, task, status, started_at, finished_at, processed_count, error_count, message)
             VALUES (:uuid, :task, "RUNNING", UTC_TIMESTAMP(), NULL, 0, 0, NULL)'
        );
        $stmt->execute([
            'uuid' => $uuid,
            'task' => $task,
        ]);

        return $uuid;
    }

    public function finish(
        string $uuid,
        string $status,
        int $processed = 0,
        int $errors = 0,
        ?string $message = null
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE cron_runs
             SET status = :status,
                 finished_at = UTC_TIMESTAMP(),
                 processed_count = :processed,
                 error_count = :errors,
                 message = :message
             WHERE run_uuid = :uuid'
        );
        $stmt->execute([
            'status' => $status,
            'processed' => max(0, $processed),
            'errors' => max(0, $errors),
            'message' => $message,
            'uuid' => $uuid,
        ]);
    }
}
