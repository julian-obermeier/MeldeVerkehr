<?php

declare(strict_types=1);

use MeldeVerkehr\Release\ReleaseServiceFactory;

/** @var \MeldeVerkehr\Core\Application $app */
$app = require __DIR__ . '/bootstrap/app.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "maintenance.php darf nur per CLI ausgeführt werden.\n");
    exit(1);
}

$args = $argv;
array_shift($args);
$command = array_shift($args) ?? 'help';

$options = [];
$positionals = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        $pair = explode('=', substr($arg, 2), 2);
        $options[$pair[0]] = $pair[1] ?? true;
    } else {
        $positionals[] = $arg;
    }
}

$maintenance = ReleaseServiceFactory::maintenance($app);

try {
    switch ($command) {
        case 'status':
            echo json_encode([
                'maintenance' => $maintenance->status(),
                'version' => trim((string) @file_get_contents(__DIR__ . '/VERSION')),
                'last_version' => trim((string) @file_get_contents(
                    __DIR__ . '/storage/app/last-version.txt'
                )),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            break;

        case 'readiness':
            echo json_encode(
                ReleaseServiceFactory::readiness($app)->check(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        case 'maintenance:on':
            $maintenance->enable((string) ($options['reason'] ?? 'Manuelle Systemwartung'));
            echo "Maintenance-Modus aktiviert.\n";
            break;

        case 'maintenance:off':
            $maintenance->disable();
            echo "Maintenance-Modus deaktiviert.\n";
            break;

        case 'backup':
            $result = ReleaseServiceFactory::backups($app)->create(
                null,
                !isset($options['database-only'])
            );
            echo json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        case 'backup:verify':
            $id = trim((string) ($positionals[0] ?? ''));
            if ($id === '') {
                throw new InvalidArgumentException('Backup-ID fehlt.');
            }

            echo json_encode(
                ReleaseServiceFactory::backups($app)->verify($id),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        case 'restore':
            $id = trim((string) ($positionals[0] ?? ''));
            if ($id === '') {
                throw new InvalidArgumentException('Backup-ID fehlt.');
            }

            if (!isset($options['confirm'])) {
                throw new InvalidArgumentException(
                    'Restore abgebrochen. Erneut mit --confirm ausführen.'
                );
            }

            $maintenance->enable('Restore aus Backup ' . $id);

            try {
                $result = ReleaseServiceFactory::backups($app)->restore($id, true);
                $maintenance->disable();
            } catch (Throwable $e) {
                throw $e;
            }

            echo json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        case 'update':
            $targetVersion = trim((string) (
                $options['to'] ??
                @file_get_contents(__DIR__ . '/VERSION') ??
                ''
            ));
            $lastVersionPath = __DIR__ . '/storage/app/last-version.txt';
            $fromVersion = trim((string) (
                $options['from'] ??
                (is_file($lastVersionPath) ? file_get_contents($lastVersionPath) : '')
            ));

            if ($fromVersion === '') {
                throw new InvalidArgumentException(
                    'Ausgangsversion fehlt. Beim ersten Lauf --from=<Version> angeben.'
                );
            }

            $result = ReleaseServiceFactory::updates($app)->run(
                $fromVersion,
                $targetVersion,
                null
            );

            echo json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . PHP_EOL;
            break;

        case 'help':
        default:
            echo <<<'TXT'
MeldeVerkehr Release-/Maintenance-CLI

Befehle:
  php maintenance.php status
  php maintenance.php readiness
  php maintenance.php maintenance:on --reason="Wartung"
  php maintenance.php maintenance:off
  php maintenance.php backup
  php maintenance.php backup --database-only
  php maintenance.php backup:verify <BACKUP-ID>
  php maintenance.php restore <BACKUP-ID> --confirm
  php maintenance.php update --from=0.11.0-dev --to=0.12.0-rc1

Wichtig:
- Restore und Update sollten nur mit aktuellem, verifiziertem Backup ausgeführt werden.
- Bei fehlgeschlagenem Update bleibt der Maintenance-Modus absichtlich aktiv.
TXT;
            echo PHP_EOL;
            break;
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
