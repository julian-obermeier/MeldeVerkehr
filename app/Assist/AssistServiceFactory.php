<?php

declare(strict_types=1);

namespace MeldeVerkehr\Assist;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Evidence\EvidenceStorage;
use MeldeVerkehr\Security\SecretCipher;

final class AssistServiceFactory
{
    public static function make(
        Application $app,
        ?VisionProviderInterface $provider = null
    ): AssistService {
        $pdo = $app->database();
        $key = (string) $app->config()->get('app.key', '');
        $permissions = new PermissionService($pdo);
        $authorization = new AuthorizationService($permissions);
        $audit = new AuditLogger($pdo, $key);
        $cipher = new SecretCipher($key);

        $cases = new CaseService(
            $pdo,
            $authorization,
            $cipher,
            $key,
            $audit,
            (string) $app->config()->get('app.timezone', 'Europe/Berlin')
        );

        $provider ??= self::provider($app);

        return new AssistService(
            $pdo,
            $authorization,
            $cases,
            new EvidenceStorage($app->basePath() . '/storage/app'),
            $cipher,
            $provider,
            $audit
        );
    }

    public static function provider(Application $app): VisionProviderInterface
    {
        $mode = strtolower((string) $app->config()->get('assist.provider', 'disabled'));

        if ($mode === 'http_json') {
            return new HttpJsonVisionProvider(
                (string) $app->config()->get('assist.endpoint', ''),
                (string) $app->config()->get('assist.api_key', '')
            );
        }

        return new DisabledVisionProvider();
    }
}
