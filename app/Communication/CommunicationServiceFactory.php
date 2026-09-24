<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Dispatch\DispatchServiceFactory;
use MeldeVerkehr\Queue\JobQueue;
use MeldeVerkehr\Security\SecretCipher;

final class CommunicationServiceFactory
{
    public static function services(Application $app): array
    {
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

        $replyAddresses = new ReplyAddressService(
            $pdo,
            (string) $app->config()->get('communications.reply_domain', 'reply.invalid'),
            (string) $app->config()->get('communications.reply_local_prefix', 'reply'),
            $key
        );

        $communications = new CommunicationService(
            $pdo,
            $authorization,
            $cases,
            $replyAddresses,
            new AuthorityMessageClassifier(
                (string) $app->config()->get('app.timezone', 'Europe/Berlin')
            ),
            $cipher,
            new CommunicationStorage($app->basePath() . '/storage/app'),
            $audit
        );

        $replies = new AuthorityReplyService(
            $pdo,
            $authorization,
            $cases,
            $cipher,
            new JobQueue($pdo),
            DispatchServiceFactory::transport($app),
            (string) $app->config()->get('mail.from_address', ''),
            $audit
        );

        return [
            'communications' => $communications,
            'replies' => $replies,
            'reply_addresses' => $replyAddresses,
        ];
    }

    public static function inboundSource(Application $app): InboundMailSourceInterface
    {
        return new ImapMailSource(
            (string) $app->config()->get('communications.imap_host', ''),
            (int) $app->config()->get('communications.imap_port', 993),
            (string) $app->config()->get('communications.imap_encryption', 'ssl'),
            (string) $app->config()->get('communications.imap_username', ''),
            (string) $app->config()->get('communications.imap_password', ''),
            (string) $app->config()->get('communications.imap_folder', 'INBOX')
        );
    }
}
