<?php

declare(strict_types=1);

namespace MeldeVerkehr\Community;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Evidence\EvidenceStorage;
use MeldeVerkehr\Security\SecretCipher;

final class CommunityServiceFactory
{
    public static function core(Application $app): CommunityService
    {
        $pdo = $app->database();
        $key = (string) $app->config()->get('app.key', '');

        return new CommunityService(
            $pdo,
            new AuthorizationService(new PermissionService($pdo)),
            new SecretCipher($key),
            new AuditLogger($pdo, $key),
            new CommunityAbuseService($pdo)
        );
    }

    public static function releases(Application $app): CommunityReleaseService
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

        return new CommunityReleaseService(
            $pdo,
            $cases,
            new EvidenceStorage($app->basePath() . '/storage/app'),
            $audit
        );
    }

    public static function reputation(Application $app): ReputationService
    {
        $pdo = $app->database();
        $key = (string) $app->config()->get('app.key', '');

        return new ReputationService(
            $pdo,
            new PermissionService($pdo),
            new AuditLogger($pdo, $key)
        );
    }

    public static function moderation(Application $app): ModerationService
    {
        $pdo = $app->database();

        return new ModerationService(
            $pdo,
            new PermissionService($pdo),
            new CommunityAbuseService($pdo),
            new AuditLogger($pdo, (string) $app->config()->get('app.key', ''))
        );
    }
}
