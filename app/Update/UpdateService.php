<?php

declare(strict_types=1);

namespace MeldeVerkehr\Update;

use MeldeVerkehr\Database\MigrationRunner;

final class UpdateService
{
    public function __construct(
        private readonly string $basePath,
        private readonly MaintenanceModeService $maintenance,
        private readonly BackupService $backups,
        private readonly MigrationRunner $migrations,
        private readonly ReleaseHealthService $health
    ) {
    }

    public function preflight(): array
    {
        return $this->health->check(true);
    }

    public function apply(bool $externalDatabaseBackupConfirmed = false): array
    {
        $preflight = $this->preflight();

        if (!$preflight['ready']) {
            throw new \RuntimeException(
                'Update-Preflight enthält blockierende Fehler. Update wurde nicht gestartet.'
            );
        }

        $backup = $this->backups->create(!$externalDatabaseBackupConfirmed);
        $verify = $this->backups->verify((string) $backup['backup_id']);

        if (!$verify['ok']) {
            throw new \RuntimeException(
                'Backup-Verifikation fehlgeschlagen. Update wurde nicht gestartet.'
            );
        }

        if (
            empty($verify['database_included'])
            && !$externalDatabaseBackupConfirmed
        ) {
            throw new \RuntimeException(
                'Kein Datenbankbackup vorhanden. Update wurde nicht gestartet.'
            );
        }

        $this->maintenance->enable('MeldeVerkehr-Update');

        try {
            $migrated = $this->migrations->migrate();
            $this->refreshInstallLock();
            $post = $this->health->check(false);

            if (!$post['ready']) {
                throw new \RuntimeException(
                    'Post-Update-Self-Check enthält blockierende Fehler. Maintenance bleibt aktiv.'
                );
            }

            $this->maintenance->disable();

            return [
                'status' => 'UPDATED',
                'backup' => $backup,
                'external_database_backup_confirmed' => $externalDatabaseBackupConfirmed,
                'migrations' => $migrated,
                'health' => $post,
            ];
        } catch (\Throwable $e) {
            // Absichtlich kein automatisches DB-Rollback:
            // Code/DB-Restore erfordert einen expliziten Operator-Entscheid.
            throw $e;
        }
    }

    private function refreshInstallLock(): void
    {
        $path = $this->basePath . '/storage/app/installed.lock';

        if (!is_file($path)) {
            throw new \RuntimeException('Installations-Lock fehlt.');
        }

        $existing = json_decode((string) file_get_contents($path), true);
        $existing = is_array($existing) ? $existing : [];
        $existing['last_updated_at'] = gmdate(DATE_ATOM);
        $existing['version'] = trim((string) @file_get_contents($this->basePath . '/VERSION'));

        $json = json_encode(
            $existing,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Installations-Lock konnte nicht aktualisiert werden.');
        }

        @chmod($path, 0600);
    }
}
