<?php

declare(strict_types=1);

use MeldeVerkehr\Config\Config;
use MeldeVerkehr\Database\Connection;
use MeldeVerkehr\Database\MigrationRunner;
use MeldeVerkehr\Support\Env;

$basePath = dirname(__DIR__);

require $basePath . '/bootstrap/autoload.php';

Env::load($basePath . '/.env');

$config = new Config($basePath . '/config');
$config->load();

try {
    $pdo = Connection::make((array) $config->get('database', []));
    $runner = new MigrationRunner($pdo, $basePath . '/database/migrations');

    $command = $argv[1] ?? 'migrate';

    if ($command === 'status') {
        foreach ($runner->status() as $row) {
            echo ($row['applied'] ? '[x] ' : '[ ] ') . $row['id'] . PHP_EOL;
        }
        exit(0);
    }

    if ($command !== 'migrate') {
        fwrite(STDERR, "Usage: php database/migrate.php [migrate|status]" . PHP_EOL);
        exit(2);
    }

    $ran = $runner->migrate();

    if ($ran === []) {
        echo "No pending migrations." . PHP_EOL;
        exit(0);
    }

    foreach ($ran as $id) {
        echo "Migrated: " . $id . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
