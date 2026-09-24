<?php

declare(strict_types=1);

namespace MeldeVerkehr\Analytics;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Security\SecretCipher;

final class MapAnalyticsServiceFactory
{
    public static function make(Application $app): MapAnalyticsService
    {
        $pdo = $app->database();
        $key = (string) $app->config()->get('app.key', '');
        $authorization = new AuthorizationService(new PermissionService($pdo));
        $audit = new AuditLogger($pdo, $key);

        $cases = new CaseService(
            $pdo,
            $authorization,
            new SecretCipher($key),
            $key,
            $audit,
            (string) $app->config()->get('app.timezone', 'Europe/Berlin')
        );

        return new MapAnalyticsService(
            $pdo,
            $authorization,
            $cases,
            $audit
        );
    }
}
