<?php

declare(strict_types=1);

namespace MeldeVerkehr\Release;

use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Operations\OperationsServiceFactory;

final class ReleaseReadinessService
{
    public function __construct(
        private readonly Application $app,
        private readonly MaintenanceService $maintenance
    ) {
    }

    public function check(): array
    {
        $diagnostics = OperationsServiceFactory::diagnostics($this->app)->snapshot();
        $base = $this->app->basePath();
        $env = (string) $this->app->config()->get('app.env', 'production');
        $debug = (bool) $this->app->config()->get('app.debug', false);
        $installed = is_file($base . '/storage/app/installed.lock');
        $serviceWorker = is_file($base . '/public/service-worker.js');
        $manifest = is_file($base . '/public/manifest.webmanifest');
        $backupDir = $base . '/storage/backups';

        $checks = [
            $this->item('database', true, 'Datenbank erreichbar.'),
            $this->item(
                'storage',
                !empty($diagnostics['storage']['exists']) && !empty($diagnostics['storage']['writable']),
                'Runtime-Storage muss vorhanden und schreibbar sein.'
            ),
            $this->item(
                'audit',
                !empty($diagnostics['audit']['ok']),
                'Audit-Kette muss intakt sein.'
            ),
            $this->item(
                'production_debug',
                !($env === 'production' && $debug),
                'APP_DEBUG darf in production nicht aktiv sein.'
            ),
            $this->item(
                'install_lock',
                $installed,
                'Installations-Lock muss vorhanden sein.'
            ),
            $this->item(
                'pwa',
                $serviceWorker && $manifest,
                'Manifest und Service Worker müssen vorhanden sein.'
            ),
            $this->item(
                'backup_directory',
                (is_dir($backupDir) || @mkdir($backupDir, 0770, true)) && is_writable($backupDir),
                'Backup-Verzeichnis muss schreibbar sein.'
            ),
            $this->item(
                'queue_failures',
                (int) ($diagnostics['queue']['FAILED'] ?? 0) === 0,
                'Keine fehlgeschlagenen Queue-Jobs erwartet.'
            ),
            $this->item(
                'expired_exports',
                (int) ($diagnostics['exports']['expired_ready'] ?? 0) === 0,
                'Abgelaufene Exporte sollten bereinigt sein.',
                false
            ),
        ];

        $blocking = count(array_filter(
            $checks,
            static fn(array $item): bool => $item['blocking'] && !$item['ok']
        ));

        return [
            'ready' => $blocking === 0,
            'blocking_failures' => $blocking,
            'maintenance' => $this->maintenance->status(),
            'checks' => $checks,
            'diagnostics' => $diagnostics,
        ];
    }

    private function item(
        string $key,
        bool $ok,
        string $message,
        bool $blocking = true
    ): array {
        return [
            'key' => $key,
            'ok' => $ok,
            'blocking' => $blocking,
            'message' => $message,
        ];
    }
}
