<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Evidence\EvidenceStorage;
use MeldeVerkehr\Mail\PhpMailTransport;
use MeldeVerkehr\Queue\JobQueue;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Witness\NeutralNarrativeBuilder;
use MeldeVerkehr\Witness\WitnessService;

final class DispatchServiceFactory
{
    public static function make(
        Application $app,
        ?DispatchTransportInterface $transport = null
    ): DispatchService {
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

        $witness = new WitnessService(
            $pdo,
            $authorization,
            $cases,
            $cipher,
            new NeutralNarrativeBuilder(
                (string) $app->config()->get('app.timezone', 'Europe/Berlin')
            ),
            $audit
        );

        $routing = new AuthorityRoutingService($pdo, $authorization);
        $packages = new DispatchPackageService(
            $pdo,
            $authorization,
            $cases,
            $witness,
            $cipher
        );

        $transport ??= self::transport($app);

        return new DispatchService(
            $pdo,
            $authorization,
            $cases,
            $routing,
            $packages,
            new JobQueue($pdo),
            $transport,
            new EvidenceStorage($app->basePath() . '/storage/app'),
            $audit
        );
    }

    public static function transport(Application $app): DispatchTransportInterface
    {
        $mode = strtolower((string) $app->config()->get('dispatch.transport', 'dry_run'));

        if ($mode === 'php_mail') {
            return new PhpMailDispatchTransport(
                (string) $app->config()->get('mail.from_address', ''),
                (string) $app->config()->get('mail.from_name', 'MeldeVerkehr')
            );
        }

        return new DryRunDispatchTransport($app->database());
    }
}
