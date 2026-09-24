<?php

declare(strict_types=1);

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Config\Config;
use MeldeVerkehr\Database\Connection;
use MeldeVerkehr\Database\MigrationRunner;
use MeldeVerkehr\Support\Env;
use MeldeVerkehr\Update\BackupService;
use MeldeVerkehr\Update\MaintenanceModeService;
use MeldeVerkehr\Update\ReleaseHealthService;
use MeldeVerkehr\Update\UpdateService;

$basePath = __DIR__;

require $basePath . '/bootstrap/autoload.php';

Env::load($basePath . '/.env');

$config = new Config($basePath . '/config');
$config->load();

$pdo = Connection::make((array) $config->get('database', []));
$migrations = new MigrationRunner($pdo, $basePath . '/database/migrations');
$maintenance = new MaintenanceModeService($basePath);
$backups = new BackupService($basePath, (array) $config->get('database', []));
$health = new ReleaseHealthService(
    $pdo,
    $migrations,
    new AuditLogger($pdo, (string) $config->get('app.key', '')),
    $backups,
    $basePath,
    (array) $config->get('app', []),
    (array) $config->get('mail', [])
);
$updates = new UpdateService(
    $basePath,
    $maintenance,
    $backups,
    $migrations,
    $health
);

$command = $argv[1] ?? 'status';

try {
    switch ($command) {
        case 'status':
            echo json_encode(
                $health->check(false),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        case 'preflight':
            echo json_encode(
                $updates->preflight(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        case 'backup':
            $backup = $backups->create(true);
            echo json_encode(
                $backup,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        case 'verify-backup':
            $id = trim((string) ($argv[2] ?? ''));
            echo json_encode(
                $backups->verify($id),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        case 'restore':
            $id = trim((string) ($argv[2] ?? ''));
            $confirmation = (string) ($argv[3] ?? '');

            if ($confirmation !== '--yes') {
                throw new \RuntimeException(
                    'Restore ist destruktiv. Wiederhole mit: php update.php restore <backup-id> --yes'
                );
            }

            $verification = $backups->verify($id);
            if (!$verification['ok']) {
                throw new \RuntimeException('Backup ist nicht verifizierbar.');
            }

            $maintenance->enable('Expliziter Backup-Restore');
            echo json_encode(
                $backups->restore($id, true),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            fwrite(
                STDERR,
                "Restore completed. Maintenance remains ACTIVE until code version and health are verified."
                . PHP_EOL
            );
            break;

        case 'maintenance:on':
            $reason = trim(implode(' ', array_slice($argv, 2))) ?: 'Manuelle Wartung';
            echo json_encode(
                $maintenance->enable($reason),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        case 'maintenance:off':
            $maintenance->disable();
            echo "Maintenance disabled." . PHP_EOL;
            break;

        case 'apply':
            $externalBackupConfirmed = in_array(
                '--external-db-backup-confirmed',
                array_slice($argv, 2),
                true
            );

            echo json_encode(
                $updates->apply($externalBackupConfirmed),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        default:
            fwrite(
                STDERR,
                "Usage: php update.php [status|preflight|backup|verify-backup <id>|restore <id> --yes|maintenance:on [reason]|maintenance:off|apply [--external-db-backup-confirmed]]"
                . PHP_EOL
            );
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Update command failed: " . $e->getMessage() . PHP_EOL);

    if ($maintenance->enabled()) {
        fwrite(
            STDERR,
            "Maintenance mode remains ACTIVE. Verify backup/code/database state before disabling it."
            . PHP_EOL
        );
    }

    exit(1);
}
