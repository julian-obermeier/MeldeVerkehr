<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Dispatch\DispatchTransportInterface;
use MeldeVerkehr\Queue\JobQueue;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class AuthorityReplyService
{
    public const JOB_TYPE = 'AUTHORITY_REPLY_SEND';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly CaseService $cases,
        private readonly SecretCipher $cipher,
        private readonly JobQueue $queue,
        private readonly DispatchTransportInterface $transport,
        private readonly string $fromAddress,
        private readonly AuditLogger $audit
    ) {
    }

    public function createDraft(string $userId, string $messageId): array
    {
        $message = $this->ownedInboundMessage($userId, $messageId);
        $case = $this->cases->findOwned($userId, (string) $message['case_id']);

        if ($case === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $body = $this->template($case, $message);
        return $this->insertVersion(
            $userId,
            (string) $message['case_id'],
            $messageId,
            $body
        );
    }

    public function saveDraft(string $userId, string $draftId, string $body): array
    {
        $draft = $this->ownedDraft($userId, $draftId);

        if ($draft['status'] !== 'DRAFT') {
            throw new \DomainException('Nur offene Entwürfe können bearbeitet werden.');
        }

        $body = trim($body);
        if (mb_strlen($body) < 20 || mb_strlen($body) > 50000) {
            throw new \InvalidArgumentException('Antworttext muss zwischen 20 und 50.000 Zeichen lang sein.');
        }

        return $this->insertVersion(
            $userId,
            (string) $draft['case_id'],
            (string) $draft['inbound_message_id'],
            $body
        );
    }

    public function queueSend(string $userId, string $draftId, bool $confirmed): array
    {
        if (!$confirmed) {
            throw new \InvalidArgumentException('Der Antwortversand muss ausdrücklich bestätigt werden.');
        }

        $draft = $this->ownedDraft($userId, $draftId);

        if ($draft['status'] !== 'DRAFT') {
            throw new \DomainException('Dieser Entwurf wurde bereits verarbeitet.');
        }

        $latest = $this->latestDraft((string) $draft['inbound_message_id']);
        if ($latest === null || (string) $latest['id'] !== $draftId) {
            throw new \DomainException('Bitte den neuesten Antwortentwurf verwenden.');
        }

        $jobUuid = $this->queue->push(
            self::JOB_TYPE,
            ['draft_id' => $draftId],
            90,
            5
        );

        $stmt = $this->pdo->prepare(
            'UPDATE authority_reply_drafts
             SET status = "QUEUED", queue_job_uuid = :job_uuid,
                 confirmed_at = UTC_TIMESTAMP(), last_error = NULL
             WHERE id = :id AND status = "DRAFT"'
        );
        $stmt->execute([
            'job_uuid' => $jobUuid,
            'id' => $draftId,
        ]);

        $this->audit->log('AUTHORITY_REPLY_QUEUED', 'case', (string) $draft['case_id'], 'USER', $userId, [
            'draft_id' => $draftId,
            'inbound_message_id' => $draft['inbound_message_id'],
            'job_uuid' => $jobUuid,
        ]);

        return [
            'draft_id' => $draftId,
            'job_uuid' => $jobUuid,
            'status' => 'QUEUED',
        ];
    }

    public function process(string $draftId): void
    {
        $row = $this->fetchOne(
            'SELECT d.*, m.external_message_id AS inbound_external_message_id,
                    m.sender_encrypted AS inbound_sender_encrypted,
                    m.subject_encrypted AS inbound_subject_encrypted,
                    m.dispatch_id AS inbound_dispatch_id,
                    m.reply_address_id,
                    c.user_id
             FROM authority_reply_drafts d
             INNER JOIN authority_messages m ON m.id = d.inbound_message_id
             INNER JOIN cases c ON c.id = d.case_id
             WHERE d.id = :id LIMIT 1',
            ['id' => $draftId]
        );

        if ($row === null) {
            throw new \DomainException('Antwortentwurf nicht gefunden.');
        }

        if ($row['status'] === 'SENT') {
            return;
        }

        if (!in_array((string) $row['status'], ['QUEUED','SENDING'], true)) {
            throw new \DomainException('Antwortentwurf ist nicht für Versand freigegeben.');
        }

        $body = $this->cipher->decrypt((string) $row['body_encrypted']);
        if (!hash_equals((string) $row['body_sha256'], hash('sha256', $body))) {
            throw new \RuntimeException('Integrität des Antwortentwurfs ist verletzt.');
        }

        $recipient = $this->cipher->decrypt((string) $row['inbound_sender_encrypted']);
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Antwortempfänger ist ungültig.');
        }

        $subject = $row['inbound_subject_encrypted'] === null
            ? 'Rückmeldung zu Ihrem Schreiben'
            : $this->cipher->decrypt((string) $row['inbound_subject_encrypted']);

        if (!str_starts_with(mb_strtolower($subject, 'UTF-8'), 're:')) {
            $subject = 'Re: ' . $subject;
        }

        $dispatch = $this->resolveDispatch((string) $row['case_id'], $row['inbound_dispatch_id']);
        $replyAddress = $this->resolveReplyAddress((string) $row['case_id'], $row['reply_address_id']);
        $domain = $this->addressDomain((string) $replyAddress['full_address']);
        $messageId = '<mv-reply-' . str_replace('-', '', $draftId) . '@' . $domain . '>';

        $existingOutbound = $this->fetchOne(
            'SELECT id FROM authority_messages
             WHERE direction = "OUTBOUND" AND external_message_id = :message_id
             LIMIT 1',
            ['message_id' => $messageId]
        );

        if ($existingOutbound !== null) {
            $this->pdo->prepare(
                'UPDATE authority_reply_drafts
                 SET status = "SENT", sent_at = COALESCE(sent_at, UTC_TIMESTAMP()), last_error = NULL
                 WHERE id = :id'
            )->execute(['id' => $draftId]);
            return;
        }

        $this->pdo->prepare(
            'UPDATE authority_reply_drafts SET status = "SENDING", last_error = NULL WHERE id = :id'
        )->execute(['id' => $draftId]);

        try {
            $headers = [
                'Reply-To' => (string) $replyAddress['full_address'],
                'Message-ID' => $messageId,
            ];

            if (is_string($row['inbound_external_message_id']) && $row['inbound_external_message_id'] !== '') {
                $headers['In-Reply-To'] = (string) $row['inbound_external_message_id'];
            }

            $result = $this->transport->send(
                (string) $dispatch['id'],
                $recipient,
                $subject,
                $body,
                [],
                $headers
            );

            if (!$result['accepted']) {
                throw new \RuntimeException(
                    'Antworttransport wurde nicht angenommen: '
                    . json_encode($result['response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            }

            $sentMessageId = (string) ($result['message_id'] ?? $messageId);
            $outboundId = Uuid::v4();
            $fingerprint = hash('sha256', 'outbound:' . mb_strtolower($sentMessageId, 'UTF-8'));

            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'INSERT INTO authority_messages
                 (id, case_id, dispatch_id, reply_address_id, direction, channel,
                  external_message_id, message_fingerprint, in_reply_to,
                  sender_encrypted, recipient_encrypted, subject_encrypted, body_text_encrypted,
                  body_sha256, classification, classification_source, classification_confidence,
                  received_at, sent_at, created_at)
                 VALUES
                 (:id, :case_id, :dispatch_id, :reply_address_id, "OUTBOUND", "EMAIL",
                  :external_message_id, :fingerprint, :in_reply_to,
                  :sender, :recipient, :subject, :body,
                  :body_sha256, "OUTBOUND_REPLY", "USER_CONFIRMED", 1.0000,
                  NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'id' => $outboundId,
                'case_id' => $row['case_id'],
                'dispatch_id' => $dispatch['id'],
                'reply_address_id' => $replyAddress['id'],
                'external_message_id' => $sentMessageId,
                'fingerprint' => $fingerprint,
                'in_reply_to' => $row['inbound_external_message_id'],
                'sender' => $this->cipher->encrypt(
                    filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL)
                        ? $this->fromAddress
                        : (string) $replyAddress['full_address']
                ),
                'recipient' => $this->cipher->encrypt($recipient),
                'subject' => $this->cipher->encrypt($subject),
                'body' => $this->cipher->encrypt($body),
                'body_sha256' => hash('sha256', $body),
            ]);

            $this->pdo->prepare(
                'UPDATE authority_reply_drafts
                 SET status = "SENT", sent_at = UTC_TIMESTAMP(), last_error = NULL
                 WHERE id = :id'
            )->execute(['id' => $draftId]);

            $this->pdo->prepare(
                'UPDATE case_tasks
                 SET status = "DONE", completed_at = UTC_TIMESTAMP()
                 WHERE source_message_id = :message_id AND status = "OPEN"'
            )->execute(['message_id' => $row['inbound_message_id']]);

            $this->pdo->commit();

            $this->advanceAfterReply(
                (string) $row['user_id'],
                (string) $row['case_id']
            );

            $this->audit->log('AUTHORITY_REPLY_SENT', 'case', (string) $row['case_id'], 'SYSTEM', null, [
                'draft_id' => $draftId,
                'outbound_message_id' => $outboundId,
                'message_id' => $sentMessageId,
                'provider_reference' => $result['provider_reference'],
            ]);
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->pdo->prepare(
                'UPDATE authority_reply_drafts
                 SET status = "QUEUED", last_error = :error
                 WHERE id = :id'
            )->execute([
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'id' => $draftId,
            ]);

            throw $e;
        }
    }

    public function latestForMessage(string $userId, string $messageId): ?array
    {
        $message = $this->ownedInboundMessage($userId, $messageId);
        $row = $this->latestDraft($messageId);

        if ($row === null) {
            return null;
        }

        $row['body'] = $this->cipher->decrypt((string) $row['body_encrypted']);
        if (!hash_equals((string) $row['body_sha256'], hash('sha256', (string) $row['body']))) {
            throw new \RuntimeException('Integrität des Antwortentwurfs ist verletzt.');
        }
        unset($row['body_encrypted']);

        return $row;
    }

    private function insertVersion(
        string $userId,
        string $caseId,
        string $messageId,
        string $body
    ): array {
        $version = $this->nextVersion($messageId);
        $id = Uuid::v4();

        $stmt = $this->pdo->prepare(
            'INSERT INTO authority_reply_drafts
             (id, case_id, inbound_message_id, version_no, body_encrypted, body_sha256,
              created_by_user_id, status, queue_job_uuid, created_at, confirmed_at, sent_at, last_error)
             VALUES
             (:id, :case_id, :message_id, :version_no, :body, :body_sha256,
              :user_id, "DRAFT", NULL, UTC_TIMESTAMP(), NULL, NULL, NULL)'
        );
        $stmt->execute([
            'id' => $id,
            'case_id' => $caseId,
            'message_id' => $messageId,
            'version_no' => $version,
            'body' => $this->cipher->encrypt($body),
            'body_sha256' => hash('sha256', $body),
            'user_id' => $userId,
        ]);

        return $this->latestForMessage($userId, $messageId)
            ?? throw new \RuntimeException('Antwortentwurf konnte nicht geladen werden.');
    }

    private function template(array $case, array $message): string
    {
        $user = $this->fetchOne(
            'SELECT first_name, last_name FROM users WHERE id = :id LIMIT 1',
            ['id' => $case['case']['user_id']]
        );

        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        $location = $case['location'] ?? [];
        $vehicle = $case['vehicle'] ?? [];
        $publicNumber = (string) $case['case']['public_number'];

        $facts = [];
        if (is_array($location)) {
            $address = trim(
                (string) ($location['street'] ?? '')
                . ' '
                . (string) ($location['house_number'] ?? '')
                . ', '
                . (string) ($location['postal_code'] ?? '')
                . ' '
                . (string) ($location['city'] ?? '')
            );
            if (trim($address, ' ,') !== '') {
                $facts[] = '- Ort: ' . trim($address, ' ,');
            }
        }
        if (is_array($vehicle) && trim((string) ($vehicle['license_plate'] ?? '')) !== '') {
            $facts[] = '- Kennzeichen: ' . trim((string) $vehicle['license_plate']);
        }

        $observedFrom = $case['case']['observed_from_local'] ?? null;
        $observedUntil = $case['case']['observed_until_local'] ?? null;
        if ($observedFrom !== null) {
            $facts[] = '- Beobachtung: ' . $observedFrom . ($observedUntil !== null ? ' bis ' . $observedUntil : '');
        }

        $requestType = match ((string) $message['classification']) {
            'INQUIRY' => 'Ihre Rückfrage',
            'DEMAND' => 'Ihre Nachforderung',
            'DEADLINE' => 'Ihre Nachricht mit Fristsetzung',
            default => 'Ihre Nachricht',
        };

        return "Sehr geehrte Damen und Herren,\n\n"
            . "vielen Dank für {$requestType} zum Vorgang {$publicNumber}.\n\n"
            . "Bereits im Vorgang dokumentierte Angaben:\n"
            . ($facts === [] ? "- siehe ursprüngliche Meldung\n" : implode("\n", $facts) . "\n")
            . "\nBitte ergänzen oder konkretisieren Sie hier die Antwort auf die behördliche Rückfrage. "
            . "Dieser Entwurf wird nicht automatisch versendet.\n\n"
            . "Mit freundlichen Grüßen\n"
            . ($name !== '' ? $name : 'Meldende Person');
    }

    private function ownedInboundMessage(string $userId, string $messageId): array
    {
        $row = $this->fetchOne(
            'SELECT m.*, c.user_id
             FROM authority_messages m
             INNER JOIN cases c ON c.id = m.case_id
             WHERE m.id = :id AND m.direction = "INBOUND" LIMIT 1',
            ['id' => $messageId]
        );

        if ($row === null) {
            throw new \DomainException('Eingehende Behördennachricht nicht gefunden.');
        }

        $this->authorization->authorize($userId, 'case.edit_own', (string) $row['user_id']);

        return $row;
    }

    private function ownedDraft(string $userId, string $draftId): array
    {
        $row = $this->fetchOne(
            'SELECT d.*, c.user_id
             FROM authority_reply_drafts d
             INNER JOIN cases c ON c.id = d.case_id
             WHERE d.id = :id LIMIT 1',
            ['id' => $draftId]
        );

        if ($row === null) {
            throw new \DomainException('Antwortentwurf nicht gefunden.');
        }

        $this->authorization->authorize($userId, 'case.edit_own', (string) $row['user_id']);

        return $row;
    }

    private function latestDraft(string $messageId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM authority_reply_drafts
             WHERE inbound_message_id = :message_id
             ORDER BY version_no DESC LIMIT 1',
            ['message_id' => $messageId]
        );
    }

    private function resolveDispatch(string $caseId, mixed $preferredDispatchId): array
    {
        if (is_string($preferredDispatchId) && $preferredDispatchId !== '') {
            $row = $this->fetchOne(
                'SELECT id FROM dispatches WHERE id = :id AND case_id = :case_id LIMIT 1',
                ['id' => $preferredDispatchId, 'case_id' => $caseId]
            );
            if ($row !== null) {
                return $row;
            }
        }

        $row = $this->fetchOne(
            'SELECT id FROM dispatches
             WHERE case_id = :case_id AND status = "SENT"
             ORDER BY sent_at DESC, created_at DESC LIMIT 1',
            ['case_id' => $caseId]
        );

        return $row ?? throw new \RuntimeException('Kein ausgehender Dispatch für Antwort gefunden.');
    }

    private function resolveReplyAddress(string $caseId, mixed $preferredReplyId): array
    {
        if (is_string($preferredReplyId) && $preferredReplyId !== '') {
            $row = $this->fetchOne(
                'SELECT id, full_address FROM case_reply_addresses
                 WHERE id = :id AND case_id = :case_id AND status = "ACTIVE" LIMIT 1',
                ['id' => $preferredReplyId, 'case_id' => $caseId]
            );
            if ($row !== null) {
                return $row;
            }
        }

        $row = $this->fetchOne(
            'SELECT id, full_address FROM case_reply_addresses
             WHERE case_id = :case_id AND status = "ACTIVE"
             ORDER BY created_at DESC LIMIT 1',
            ['case_id' => $caseId]
        );

        return $row ?? throw new \RuntimeException('Keine aktive Reply-Adresse vorhanden.');
    }

    private function advanceAfterReply(string $userId, string $caseId): void
    {
        $case = $this->cases->findOwned($userId, $caseId);
        if ($case === null) {
            return;
        }

        $status = (string) $case['case']['status'];
        if (in_array($status, [CaseStatus::USER_ACTION_REQUIRED, CaseStatus::AUTHORITY_REPLY], true)) {
            try {
                $this->cases->changeStatus(
                    $userId,
                    $caseId,
                    CaseStatus::AUTHORITY_PROCESSING,
                    'Nutzerantwort an Behörde versendet'
                );
            } catch (\DomainException) {
            }
        }
    }

    private function addressDomain(string $address): string
    {
        $parts = explode('@', $address, 2);
        $domain = strtolower(trim($parts[1] ?? ''));

        return preg_match('/^[a-z0-9.-]+$/', $domain) ? $domain : 'reply.invalid';
    }

    private function nextVersion(string $messageId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(version_no), 0) + 1
             FROM authority_reply_drafts WHERE inbound_message_id = :message_id'
        );
        $stmt->execute(['message_id' => $messageId]);

        return max(1, (int) $stmt->fetchColumn());
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
