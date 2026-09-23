<?php

declare(strict_types=1);

namespace MeldeVerkehr\Database;

use PDO;
use Throwable;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationPath
    ) {
    }

    public function migrate(): array
    {
        $this->ensureRepository();

        $applied = $this->appliedIds();
        $files = glob(rtrim($this->migrationPath, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);

        $batch = $this->nextBatch();
        $ran = [];

        foreach ($files as $file) {
            $migration = require $file;

            if (!$migration instanceof MigrationInterface) {
                throw new \RuntimeException(sprintf('Migration %s must implement MigrationInterface.', $file));
            }

            if (isset($applied[$migration->id()])) {
                continue;
            }

            try {
                if (!$this->pdo->inTransaction()) {
                    $this->pdo->beginTransaction();
                }

                $migration->up($this->pdo);

                $stmt = $this->pdo->prepare(
                    'INSERT INTO migrations (migration_id, batch, run_at) VALUES (:id, :batch, UTC_TIMESTAMP())'
                );
                $stmt->execute([
                    'id' => $migration->id(),
                    'batch' => $batch,
                ]);

                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }

                $ran[] = $migration->id();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $e;
            }
        }

        return $ran;
    }

    public function status(): array
    {
        $this->ensureRepository();
        $applied = $this->appliedIds();
        $files = glob(rtrim($this->migrationPath, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);

        $status = [];

        foreach ($files as $file) {
            $migration = require $file;

            if (!$migration instanceof MigrationInterface) {
                continue;
            }

            $status[] = [
                'id' => $migration->id(),
                'applied' => isset($applied[$migration->id()]),
            ];
        }

        return $status;
    }

    private function ensureRepository(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                migration_id VARCHAR(190) NOT NULL PRIMARY KEY,
                batch INT UNSIGNED NOT NULL,
                run_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function appliedIds(): array
    {
        $rows = $this->pdo->query('SELECT migration_id FROM migrations')->fetchAll();
        $applied = [];

        foreach ($rows as $row) {
            $applied[(string) $row['migration_id']] = true;
        }

        return $applied;
    }

    private function nextBatch(): int
    {
        $max = (int) $this->pdo->query('SELECT COALESCE(MAX(batch), 0) FROM migrations')->fetchColumn();

        return $max + 1;
    }
}
