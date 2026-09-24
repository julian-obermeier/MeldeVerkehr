<?php

declare(strict_types=1);

namespace MeldeVerkehr\Update;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Database\MigrationRunner;

final class ReleaseHealthServiceFactory
{
    public static function make(Application $app): ReleaseHealthService
    {
        $pdo = $app->database();
        $base = $app->basePath();

        return new ReleaseHealthService(
            $pdo,
            new MigrationRunner($pdo, $base . '/database/migrations'),
            new AuditLogger($pdo, (string) $app->config()->get('app.key', '')),
            new BackupService($base, (array) $app->config()->get('database', [])),
            $base,
            (array) $app->config()->get('app', []),
            (array) $app->config()->get('mail', [])
        );
    }
}
