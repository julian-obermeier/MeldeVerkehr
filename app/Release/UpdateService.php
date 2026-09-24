<?php

declare(strict_types=1);

namespace MeldeVerkehr\Release;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Database\MigrationRunner;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class UpdateService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BackupService $backups,
        private readonly MaintenanceService $maintenance,
        private readonly MigrationRunner $migrations,
        private readonly AuditLogger $audit,
        private readonly string $versionStatePath
    ) {
    }

    public function preflight(string $fromVersion, string $toVersion): array
    {
        $fromVersion = trim($fromVersion);
        $toVersion = trim($toVersion);

        if ($fromVersion === '' || $toVersion === '') {
            throw new \InvalidArgumentException('Versionsangaben dürfen nicht leer sein.');
        }

        $status = $this->migrations->status();
        $pending = array_values(array_filter(
            $status,
            static fn(array $row): bool => !$row['applied']
        ));

        return [
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'pending_migrations' => array_column($pending, 'id'),
            'maintenance_active' => $this->maintenance->active(),
        ];
    }

    public function run(
        string $fromVersion,
        string $toVersion,
        ?string $actorUserId = null
    ): array {
        ReleaseRepository::ensure($this->pdo);
        $preflight = $this->preflight($fromVersion, $toVersion);
        $id = Uuid::v4();
        $before = $this->migrationCount();

        $this->pdo->prepare(
            'INSERT INTO system_update_runs
             (id, from_version, to_version, status, backup_id,
              migration_count_before, migration_count_after, notes,
              created_by_user_id, started_at, completed_at)
             VALUES
             (:id, :from_version, :to_version, "PREFLIGHT", NULL,
              :before_count, :before_count, NULL, :user_id, UTC_TIMESTAMP(), NULL)'
        )->execute([
            'id' => $id,
            'from_version' => trim($fromVersion),
            'to_version' => trim($toVersion),
            'before_count' => $before,
            'user_id' => $actorUserId,
        ]);

        $this->maintenance->enable(
            'Systemupdate ' . trim($fromVersion) . ' → ' . trim($toVersion)
        );

        try {
            $this->pdo->prepare(
                'UPDATE system_update_runs SET status = "BACKUP" WHERE id = :id'
            )->execute(['id' => $id]);

            $backup = $this->backups->create($actorUserId, true);
            $this->backups->verify((string) $backup['id']);

            $this->pdo->prepare(
                'UPDATE system_update_runs
                 SET status = "MIGRATING", backup_id = :backup_id
                 WHERE id = :id'
            )->execute([
                'backup_id' => $backup['id'],
                'id' => $id,
            ]);

            $ran = $this->migrations->migrate();
            $after = $this->migrationCount();

            $directory = dirname($this->versionStatePath);
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new \RuntimeException('Versionsstatus-Verzeichnis konnte nicht erstellt werden.');
            }

            if (file_put_contents(
                $this->versionStatePath,
                trim($toVersion) . PHP_EOL,
                LOCK_EX
            ) === false) {
                throw new \RuntimeException('Versionsstatus konnte nicht geschrieben werden.');
            }

            $this->pdo->prepare(
                'UPDATE system_update_runs
                 SET status = "COMPLETED", migration_count_after = :after_count,
                     notes = :notes, completed_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            )->execute([
                'after_count' => $after,
                'notes' => json_encode(
                    ['migrations_ran' => $ran],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                'id' => $id,
            ]);

            $this->audit->log(
                'SYSTEM_UPDATE_COMPLETED',
                'system',
                $id,
                'USER',
                $actorUserId,
                [
                    'from_version' => trim($fromVersion),
                    'to_version' => trim($toVersion),
                    'backup_id' => $backup['id'],
                    'migrations_ran' => $ran,
                ]
            );

            $this->maintenance->disable();

            return [
                'id' => $id,
                'status' => 'COMPLETED',
                'backup_id' => $backup['id'],
                'migrations_ran' => $ran,
                'migration_count_before' => $before,
                'migration_count_after' => $after,
                'preflight' => $preflight,
            ];
        } catch (Throwable $e) {
            try {
                $this->pdo->prepare(
                    'UPDATE system_update_runs
                     SET status = "FAILED", notes = :notes, completed_at = UTC_TIMESTAMP()
                     WHERE id = :id'
                )->execute([
                    'notes' => mb_substr($e->getMessage(), 0, 2000),
                    'id' => $id,
                ]);
            } catch (Throwable) {
            }

            throw $e;
        }
    }

    public function history(int $limit = 20): array
    {
        ReleaseRepository::ensure($this->pdo);
        $limit = max(1, min(100, $limit));

        return $this->pdo->query(
            'SELECT id, from_version, to_version, status, backup_id,
                    migration_count_before, migration_count_after,
                    notes, started_at, completed_at
             FROM system_update_runs
             ORDER BY started_at DESC LIMIT ' . $limit
        )->fetchAll();
    }

    private function migrationCount(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM migrations'
        )->fetchColumn();
    }
}
