<?php

declare(strict_types=1);

namespace MeldeVerkehr\Dispatch;

use MeldeVerkehr\Support\Uuid;
use PDO;

final class DryRunDispatchTransport implements DispatchTransportInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function send(
        string $dispatchId,
        string $recipient,
        string $subject,
        string $text,
        array $attachments = [],
        array $headers = []
    ): array {
        $reference = 'dryrun:' . Uuid::v4();
        $messageId = $headers['Message-ID'] ?? ('<mv-' . Uuid::v4() . '@dry-run.invalid>');
        $replyTo = $headers['Reply-To'] ?? null;

        $attachmentManifest = array_map(
            static fn(array $attachment): array => [
                'name' => (string) $attachment['name'],
                'mime_type' => (string) $attachment['mime_type'],
                'sha256' => (string) $attachment['sha256'],
                'size' => (int) $attachment['size'],
            ],
            $attachments
        );

        $stmt = $this->pdo->prepare(
            'INSERT INTO dispatch_dry_run_outbox
             (dispatch_id, recipient, reply_to, subject, message_id, body_sha256, attachment_manifest_json, created_at)
             VALUES (:dispatch_id, :recipient, :reply_to, :subject, :message_id, :body_sha256, :attachments, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'dispatch_id' => $dispatchId,
            'recipient' => $recipient,
            'reply_to' => $replyTo,
            'subject' => $subject,
            'message_id' => $messageId,
            'body_sha256' => hash('sha256', $text),
            'attachments' => json_encode(
                $attachmentManifest,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ]);

        return [
            'accepted' => true,
            'provider_reference' => $reference,
            'response' => [
                'mode' => 'dry_run',
                'attachment_count' => count($attachmentManifest),
                'reply_to' => $replyTo,
            ],
            'message_id' => $messageId,
        ];
    }
}
