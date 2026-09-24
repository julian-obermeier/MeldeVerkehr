<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Security\SecretCipher;

final class OperationsServiceFactory
{
    public static function search(Application $app): CaseSearchService
    {
        $key = (string) $app->config()->get('app.key', '');

        return new CaseSearchService(
            $app->database(),
            new SecretCipher($key),
            $key
        );
    }

    public static function notifications(Application $app): NotificationService
    {
        return new NotificationService(
            $app->database(),
            new SecretCipher((string) $app->config()->get('app.key', ''))
        );
    }

    public static function documents(Application $app): DocumentCenterService
    {
        return new DocumentCenterService($app->database());
    }

    public static function exports(Application $app): ExportService
    {
        $key = (string) $app->config()->get('app.key', '');

        return new ExportService(
            $app->database(),
            self::search($app),
            new ExportStorage($app->basePath() . '/storage/app'),
            new AuditLogger($app->database(), $key)
        );
    }

    public static function retention(Application $app): RetentionService
    {
        $closed = $app->config()->get('retention.closed_case_days');
        $export = $app->config()->get('retention.export_days', 7);

        return new RetentionService(
            $app->database(),
            is_int($closed) ? $closed : null,
            is_int($export) ? $export : 7
        );
    }

    public static function diagnostics(Application $app): DiagnosticsService
    {
        return new DiagnosticsService(
            $app->database(),
            new AuditLogger(
                $app->database(),
                (string) $app->config()->get('app.key', '')
            ),
            $app->basePath() . '/storage/app'
        );
    }
}
