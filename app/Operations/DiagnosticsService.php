<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use MeldeVerkehr\Audit\AuditLogger;
use PDO;

final class DiagnosticsService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly string $storagePath
    ) {
    }

    public function snapshot(): array
    {
        return [
            'database' => [
                'version' => (string) $this->pdo->query('SELECT VERSION()')->fetchColumn(),
                'migrations_total' => (int) $this->pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn(),
            ],
            'queue' => $this->groupCounts('jobs', 'status'),
            'cron' => [
                'last_runs' => $this->pdo->query(
                    'SELECT task, status, started_at, finished_at, processed_count, error_count
                     FROM cron_runs ORDER BY id DESC LIMIT 20'
                )->fetchAll(),
                'last_heartbeat' => $this->latestCronHeartbeat(),
            ],
            'mail' => [
                'quarantine_open' => (int) $this->pdo->query(
                    'SELECT COUNT(*) FROM inbound_mail_quarantine WHERE resolved_at IS NULL'
                )->fetchColumn(),
                'dispatch_failed' => (int) $this->pdo->query(
                    'SELECT COUNT(*) FROM dispatches WHERE status IN ("FAILED","DELIVERY_FAILED")'
                )->fetchColumn(),
            ],
            'storage' => [
                'path' => $this->storagePath,
                'exists' => is_dir($this->storagePath),
                'writable' => is_dir($this->storagePath) && is_writable($this->storagePath),
                'free_bytes' => is_dir($this->storagePath) ? @disk_free_space($this->storagePath) : false,
            ],
            'audit' => $this->audit->verifyChain(),
            'retention' => [
                'planned' => (int) $this->pdo->query(
                    'SELECT COUNT(*) FROM retention_schedules WHERE status = "PLANNED"'
                )->fetchColumn(),
                'due' => (int) $this->pdo->query(
                    'SELECT COUNT(*) FROM retention_schedules
                     WHERE status = "PLANNED" AND eligible_at IS NOT NULL AND eligible_at <= UTC_TIMESTAMP()'
                )->fetchColumn(),
            ],
            'exports' => [
                'ready' => (int) $this->pdo->query(
                    'SELECT COUNT(*) FROM export_artifacts WHERE status = "READY"'
                )->fetchColumn(),
                'expired_ready' => (int) $this->pdo->query(
                    'SELECT COUNT(*) FROM export_artifacts
                     WHERE status = "READY" AND expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()'
                )->fetchColumn(),
            ],
        ];
    }

    private function groupCounts(string $table, string $column): array
    {
        if ($table !== 'jobs' || $column !== 'status') {
            throw new \InvalidArgumentException('Ungültige Diagnoseabfrage.');
        }

        $rows = $this->pdo->query(
            'SELECT status, COUNT(*) AS count FROM jobs GROUP BY status ORDER BY status'
        )->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['status']] = (int) $row['count'];
        }

        return $result;
    }

    private function latestCronHeartbeat(): ?array
    {
        $stmt = $this->pdo->query(
            'SELECT task, last_started_at, last_finished_at, last_status, updated_at
             FROM cron_heartbeats
             ORDER BY updated_at DESC LIMIT 1'
        );
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
