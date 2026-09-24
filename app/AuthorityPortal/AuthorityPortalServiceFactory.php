<?php

declare(strict_types=1);

namespace MeldeVerkehr\AuthorityPortal;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Security\SecretCipher;

final class AuthorityPortalServiceFactory
{
    public static function access(Application $app): AuthorityAccessService
    {
        return new AuthorityAccessService(
            $app->database(),
            new PermissionService($app->database())
        );
    }

    public static function portal(Application $app): AuthorityPortalService
    {
        $key = (string) $app->config()->get('app.key', '');

        return new AuthorityPortalService(
            $app->database(),
            self::access($app),
            new SecretCipher($key),
            new AuditLogger($app->database(), $key)
        );
    }

    public static function tokens(Application $app): AuthorityApiTokenService
    {
        return new AuthorityApiTokenService(
            $app->database(),
            self::access($app)
        );
    }

    public static function holders(Application $app): AuthorityHolderService
    {
        $key = (string) $app->config()->get('app.key', '');

        return new AuthorityHolderService(
            $app->database(),
            self::access($app),
            new SecretCipher($key),
            new AuditLogger($app->database(), $key)
        );
    }

    public static function exports(Application $app): AuthorityExportService
    {
        return new AuthorityExportService(
            self::portal($app),
            self::access($app)
        );
    }
}
