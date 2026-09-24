<?php

declare(strict_types=1);

namespace MeldeVerkehr\Release;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Database\MigrationRunner;
use MeldeVerkehr\Security\SecretCipher;

final class ReleaseServiceFactory
{
    public static function repository(Application $app): void
    {
        ReleaseRepository::ensure($app->database());
    }

    public static function maintenance(Application $app): MaintenanceService
    {
        $relative = (string) $app->config()->get(
            'release.maintenance_flag',
            'storage/app/maintenance.flag'
        );

        return new MaintenanceService(
            $app->basePath() . '/' . ltrim($relative, '/')
        );
    }

    public static function backups(Application $app): BackupService
    {
        self::repository($app);

        return new BackupService(
            $app->database(),
            new SecretCipher((string) $app->config()->get('app.key', '')),
            $app->basePath() . '/storage/backups',
            $app->basePath() . '/storage/app',
            (int) $app->config()->get(
                'release.backup_max_file_bytes',
                52428800
            )
        );
    }

    public static function updates(Application $app): UpdateService
    {
        self::repository($app);
        $key = (string) $app->config()->get('app.key', '');

        return new UpdateService(
            $app->database(),
            self::backups($app),
            self::maintenance($app),
            new MigrationRunner(
                $app->database(),
                $app->basePath() . '/database/migrations'
            ),
            new AuditLogger($app->database(), $key),
            $app->basePath() . '/storage/app/last-version.txt'
        );
    }

    public static function readiness(Application $app): ReleaseReadinessService
    {
        self::repository($app);

        return new ReleaseReadinessService(
            $app,
            self::maintenance($app)
        );
    }

    public static function rateLimiter(Application $app): RequestRateLimiter
    {
        self::repository($app);

        return new RequestRateLimiter(
            $app->database(),
            (string) $app->config()->get('app.key', '')
        );
    }
}
