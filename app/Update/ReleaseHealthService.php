<?php

declare(strict_types=1);

namespace MeldeVerkehr\Update;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Database\MigrationRunner;
use PDO;

final class ReleaseHealthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MigrationRunner $migrations,
        private readonly AuditLogger $audit,
        private readonly BackupService $backups,
        private readonly string $basePath,
        private readonly array $appConfig,
        private readonly array $mailConfig
    ) {
    }

    public function check(bool $allowPendingMigrations = false): array
    {
        $checks = [];

        $this->add(
            $checks,
            'installation_lock',
            is_file($this->basePath . '/storage/app/installed.lock') ? 'OK' : 'ERROR',
            'Installations-Lock',
            is_file($this->basePath . '/storage/app/installed.lock')
                ? 'Vorhanden.'
                : 'storage/app/installed.lock fehlt.'
        );

        $key = trim((string) ($this->appConfig['key'] ?? ''));
        $this->add(
            $checks,
            'app_key',
            $key !== '' ? 'OK' : 'ERROR',
            'APP_KEY',
            $key !== '' ? 'Konfiguriert.' : 'APP_KEY fehlt.'
        );

        $environment = strtolower(trim((string) ($this->appConfig['env'] ?? 'production')));
        $debug = (bool) ($this->appConfig['debug'] ?? false);
        $this->add(
            $checks,
            'production_debug',
            $environment === 'production' && $debug ? 'ERROR' : 'OK',
            'Debug-Modus',
            $environment === 'production' && $debug
                ? 'APP_DEBUG muss in Produktion deaktiviert sein.'
                : 'Debug-Konfiguration ist unauffällig.'
        );

        $url = trim((string) ($this->appConfig['url'] ?? ''));
        $https = str_starts_with(strtolower($url), 'https://');
        $this->add(
            $checks,
            'app_url_https',
            $url === '' ? 'ERROR' : ($https ? 'OK' : 'WARN'),
            'Anwendungs-URL',
            $url === ''
                ? 'APP_URL fehlt.'
                : ($https ? 'HTTPS ist konfiguriert.' : 'APP_URL verwendet kein HTTPS.')
        );

        foreach ([
            'storage/app',
            'storage/logs',
            'storage/backups',
        ] as $relative) {
            $path = $this->basePath . '/' . $relative;
            $exists = is_dir($path) || @mkdir($path, 0770, true);
            $writable = $exists && is_writable($path);

            $this->add(
                $checks,
                'storage_' . str_replace('/', '_', $relative),
                $writable ? 'OK' : 'ERROR',
                $relative,
                $writable ? 'Vorhanden und schreibbar.' : 'Fehlt oder ist nicht schreibbar.'
            );
        }

        $migrationStatus = $this->migrations->status();
        $pending = array_values(array_filter(
            $migrationStatus,
            static fn(array $row): bool => empty($row['applied'])
        ));
        $this->add(
            $checks,
            'migrations',
            $pending === [] ? 'OK' : ($allowPendingMigrations ? 'WARN' : 'ERROR'),
            'Datenbankmigrationen',
            $pending === []
                ? 'Alle bekannten Migrationen sind angewendet.'
                : count($pending) . ' Migration(en) sind noch offen.',
            ['pending' => array_column($pending, 'id')]
        );

        $audit = $this->audit->verifyChain();
        $this->add(
            $checks,
            'audit_chain',
            !empty($audit['ok']) ? 'OK' : 'ERROR',
            'Audit-Kette',
            !empty($audit['ok'])
                ? 'Integrität bestätigt.'
                : 'Audit-Kette ist beschädigt.',
            $audit
        );

        $failedJobs = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM jobs WHERE status = "FAILED"'
        )->fetchColumn();
        $pendingJobs = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM jobs WHERE status IN ("PENDING","RETRY")'
        )->fetchColumn();
        $this->add(
            $checks,
            'queue',
            $failedJobs > 0 ? 'WARN' : 'OK',
            'Jobqueue',
            $failedJobs > 0
                ? $failedJobs . ' fehlgeschlagene Jobs vorhanden.'
                : 'Keine fehlgeschlagenen Jobs.',
            ['failed' => $failedJobs, 'pending_or_retry' => $pendingJobs]
        );

        $latestCron = $this->pdo->query(
            'SELECT task, status, started_at, finished_at
             FROM cron_runs ORDER BY id DESC LIMIT 1'
        )->fetch();
        if (!is_array($latestCron)) {
            $this->add(
                $checks,
                'cron',
                'WARN',
                'Cron',
                'Noch kein Cron-Lauf protokolliert.'
            );
        } else {
            $finished = strtotime((string) ($latestCron['finished_at'] ?? $latestCron['started_at']) . ' UTC');
            $stale = $finished === false || $finished < time() - 172800;
            $this->add(
                $checks,
                'cron',
                $stale ? 'WARN' : 'OK',
                'Cron',
                $stale
                    ? 'Der letzte Cron-Lauf ist älter als 48 Stunden.'
                    : 'Cron-Läufe sind aktuell.',
                $latestCron
            );
        }

        $fromAddress = trim((string) ($this->mailConfig['from_address'] ?? ''));
        $this->add(
            $checks,
            'mail_from',
            filter_var($fromAddress, FILTER_VALIDATE_EMAIL) ? 'OK' : 'WARN',
            'Mail-Absender',
            filter_var($fromAddress, FILTER_VALIDATE_EMAIL)
                ? 'Gültiger Absender konfiguriert.'
                : 'Kein gültiger MAIL_FROM_ADDRESS konfiguriert.'
        );

        $backupCapability = $this->backups->capability();
        $backupReady = !empty($backupCapability['storage_writable'])
            && !empty($backupCapability['proc_open_available'])
            && !empty($backupCapability['mysqldump_path']);

        $this->add(
            $checks,
            'backup_capability',
            $backupReady ? 'OK' : 'WARN',
            'CLI-Backup',
            $backupReady
                ? 'Vollständiges DB-/Konfigurationsbackup kann lokal erzeugt werden.'
                : 'CLI-Datenbankbackup ist nicht vollständig verfügbar; vor Updates ist ein extern bestätigtes DB-Backup erforderlich.',
            $backupCapability
        );

        $errors = count(array_filter(
            $checks,
            static fn(array $check): bool => $check['status'] === 'ERROR'
        ));
        $warnings = count(array_filter(
            $checks,
            static fn(array $check): bool => $check['status'] === 'WARN'
        ));

        return [
            'ready' => $errors === 0,
            'status' => $errors > 0 ? 'ERROR' : ($warnings > 0 ? 'WARN' : 'OK'),
            'errors' => $errors,
            'warnings' => $warnings,
            'version' => trim((string) @file_get_contents($this->basePath . '/VERSION')),
            'checked_at' => gmdate(DATE_ATOM),
            'checks' => $checks,
        ];
    }

    private function add(
        array &$checks,
        string $key,
        string $status,
        string $label,
        string $message,
        array $meta = []
    ): void {
        $checks[] = [
            'key' => $key,
            'status' => $status,
            'label' => $label,
            'message' => $message,
            'meta' => $meta,
        ];
    }
}
