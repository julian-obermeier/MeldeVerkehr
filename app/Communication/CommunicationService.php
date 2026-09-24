<?php

declare(strict_types=1);

namespace MeldeVerkehr\Communication;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Auth\AuthorizationService;
use MeldeVerkehr\Cases\CaseService;
use MeldeVerkehr\Cases\CaseStatus;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class CommunicationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorizationService $authorization,
        private readonly CaseService $cases,
        private readonly ReplyAddressService $replyAddresses,
        private readonly AuthorityMessageClassifier $classifier,
        private readonly SecretCipher $cipher,
        private readonly CommunicationStorage $storage,
        private readonly AuditLogger $audit
    ) {
    }

    public function ingest(array $mail): array
    {
        $normalized = $this->normalizeMail($mail);
        $fingerprint = $this->fingerprint($normalized);

        $duplicate = $this->existingFingerprint($fingerprint);
        if ($duplicate !== null) {
            return [
                'status' => 'DUPLICATE',
                'message_id' => $duplicate['message_id'],
                'quarantine_id' => $duplicate['quarantine_id'],
            ];
        }

        $target = $this->resolveTarget(
            $normalized['to'],
            $normalized['in_reply_to']
        );

        if ($target === null) {
            return $this->quarantine(
                $normalized,
                $fingerprint,
                'Keine aktive Reply-Adresse oder passende In-Reply-To-Referenz gefunden.'
            );
        }

        $classification = $this->classifier->classify(
            $normalized['subject'],
            $normalized['text'],
            $normalized['received_at']
        );

        $messageId = Uuid::v4();
        $storedPaths = [];

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'INSERT INTO authority_messages
                 (id, case_id, dispatch_id, reply_address_id, direction, channel,
                  external_message_id, message_fingerprint, in_reply_to,
                  sender_encrypted, recipient_encrypted, subject_encrypted, body_text_encrypted,
                  body_sha256, classification, classification_source, classification_confidence,
                  received_at, sent_at, created_at)
                 VALUES
                 (:id, :case_id, :dispatch_id, :reply_address_id, "INBOUND", "EMAIL",
                  :external_message_id, :fingerprint, :in_reply_to,
                  :sender, :recipient, :subject, :body,
                  :body_sha256, :classification, :classification_source, :classification_confidence,
                  :received_at, NULL, UTC_TIMESTAMP())'
            );
            $stmt->execute([
                'id' => $messageId,
                'case_id' => $target['case_id'],
                'dispatch_id' => $target['dispatch_id'],
                'reply_address_id' => $target['reply_address_id'],
                'external_message_id' => $normalized['message_id'],
                'fingerprint' => $fingerprint,
                'in_reply_to' => $normalized['in_reply_to'],
                'sender' => $this->cipher->encrypt($normalized['from']),
                'recipient' => $this->cipher->encrypt(implode(', ', $normalized['to'])),
                'subject' => $normalized['subject'] === ''
                    ? null
                    : $this->cipher->encrypt($normalized['subject']),
                'body' => $this->cipher->encrypt($normalized['text']),
                'body_sha256' => hash('sha256', $normalized['text']),
                'classification' => $classification['classification'],
                'classification_source' => $classification['source'],
                'classification_confidence' => $classification['confidence'],
                'received_at' => $normalized['received_at'],
            ]);

            $attachmentStmt = $this->pdo->prepare(
                'INSERT INTO authority_message_attachments
                 (id, message_id, original_filename, storage_path, mime_type, file_size, sha256, created_at)
                 VALUES
                 (:id, :message_id, :filename, :path, :mime, :size, :sha256, UTC_TIMESTAMP())'
            );

            foreach ($normalized['attachments'] as $attachment) {
                $stored = $this->storage->storeAttachment(
                    (string) $target['case_id'],
                    $messageId,
                    (string) $attachment['filename'],
                    (string) $attachment['mime_type'],
                    (string) $attachment['content']
                );
                $storedPaths[] = $stored['relative_path'];

                $attachmentStmt->execute([
                    'id' => Uuid::v4(),
                    'message_id' => $messageId,
                    'filename' => $stored['original_filename'],
                    'path' => $stored['relative_path'],
                    'mime' => $stored['mime_type'],
                    'size' => $stored['size'],
                    'sha256' => $stored['sha256'],
                ]);
            }

            $this->createDerivedActions(
                (string) $target['case_id'],
                $messageId,
                $classification,
                $normalized['subject'],
                $normalized['text']
            );

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            foreach ($storedPaths as $path) {
                @unlink($this->storage->absolute((string) $path));
            }

            throw $e;
        }

        $this->advanceCase(
            (string) $target['case_id'],
            (string) $classification['classification']
        );

        $this->audit->log(
            'AUTHORITY_MESSAGE_INGESTED',
            'case',
            (string) $target['case_id'],
            'SYSTEM',
            null,
            [
                'message_id' => $messageId,
                'classification' => $classification['classification'],
                'classification_source' => $classification['source'],
                'deadline_at' => $classification['deadline_at'],
                'attachment_count' => count($normalized['attachments']),
            ]
        );

        return [
            'status' => 'INGESTED',
            'message_id' => $messageId,
            'case_id' => $target['case_id'],
            'classification' => $classification['classification'],
            'deadline_at' => $classification['deadline_at'],
        ];
    }

    public function listForCase(string $userId, string $caseId): array
    {
        $case = $this->cases->findOwned($userId, $caseId);

        if ($case === null) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        $this->authorization->authorize(
            $userId,
            'case.view_own',
            (string) $case['case']['user_id']
        );

        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM authority_messages
             WHERE case_id = :case_id
             ORDER BY COALESCE(received_at, sent_at, created_at) ASC, created_at ASC'
        );
        $stmt->execute(['case_id' => $caseId]);

        $messages = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['sender'] = $this->cipher->decrypt((string) $row['sender_encrypted']);
            $row['recipient'] = $this->cipher->decrypt((string) $row['recipient_encrypted']);
            $row['subject'] = $row['subject_encrypted'] === null
                ? ''
                : $this->cipher->decrypt((string) $row['subject_encrypted']);
            $row['body_text'] = $this->cipher->decrypt((string) $row['body_text_encrypted']);

            if (!hash_equals((string) $row['body_sha256'], hash('sha256', (string) $row['body_text']))) {
                throw new \RuntimeException('Integrität einer Behördennachricht ist verletzt.');
            }

            unset(
                $row['sender_encrypted'],
                $row['recipient_encrypted'],
                $row['subject_encrypted'],
                $row['body_text_encrypted']
            );

            $attachmentStmt = $this->pdo->prepare(
                'SELECT id, original_filename, mime_type, file_size, sha256, created_at
                 FROM authority_message_attachments
                 WHERE message_id = :message_id
                 ORDER BY created_at, id'
            );
            $attachmentStmt->execute(['message_id' => $row['id']]);
            $row['attachments'] = $attachmentStmt->fetchAll();
            $messages[] = $row;
        }

        $tasks = $this->tasksForCase($caseId);
        $deadlines = $this->deadlinesForCase($caseId);

        return [
            'case_data' => $case,
            'messages' => $messages,
            'tasks' => $tasks,
            'deadlines' => $deadlines,
        ];
    }

    public function completeTask(string $userId, string $taskId): string
    {
        $row = $this->fetchOne(
            'SELECT t.id, t.case_id, c.user_id
             FROM case_tasks t
             INNER JOIN cases c ON c.id = t.case_id
             WHERE t.id = :id LIMIT 1',
            ['id' => $taskId]
        );

        if ($row === null) {
            throw new \DomainException('Aufgabe nicht gefunden.');
        }

        $this->authorization->authorize(
            $userId,
            'case.edit_own',
            (string) $row['user_id']
        );

        $stmt = $this->pdo->prepare(
            'UPDATE case_tasks
             SET status = "DONE", completed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "OPEN"'
        );
        $stmt->execute(['id' => $taskId]);

        return (string) $row['case_id'];
    }

    public function resolveDeadline(string $userId, string $deadlineId): string
    {
        $row = $this->fetchOne(
            'SELECT d.id, d.case_id, c.user_id
             FROM case_deadlines d
             INNER JOIN cases c ON c.id = d.case_id
             WHERE d.id = :id LIMIT 1',
            ['id' => $deadlineId]
        );

        if ($row === null) {
            throw new \DomainException('Frist nicht gefunden.');
        }

        $this->authorization->authorize(
            $userId,
            'case.edit_own',
            (string) $row['user_id']
        );

        $stmt = $this->pdo->prepare(
            'UPDATE case_deadlines
             SET status = "RESOLVED", resolved_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "OPEN"'
        );
        $stmt->execute(['id' => $deadlineId]);

        return (string) $row['case_id'];
    }

    private function normalizeMail(array $mail): array
    {
        $from = strtolower(trim((string) ($mail['from'] ?? '')));
        $to = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string) $value)),
            is_array($mail['to'] ?? null) ? $mail['to'] : []
        ), static fn(string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false)));

        $subject = trim((string) ($mail['subject'] ?? ''));
        $text = trim((string) ($mail['text'] ?? ''));
        $messageId = trim((string) ($mail['message_id'] ?? ''));
        $inReplyTo = trim((string) ($mail['in_reply_to'] ?? ''));
        $receivedAt = trim((string) ($mail['received_at'] ?? ''));

        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Eingehende Nachricht enthält keinen gültigen Absender.');
        }

        if ($to === []) {
            throw new \InvalidArgumentException('Eingehende Nachricht enthält keinen gültigen Empfänger.');
        }

        if ($text === '') {
            $text = '[Leere Nachricht]';
        }

        if (mb_strlen($subject) > 500) {
            $subject = mb_substr($subject, 0, 500);
        }

        if (mb_strlen($text) > 500000) {
            $text = mb_substr($text, 0, 500000);
        }

        if ($receivedAt !== '') {
            try {
                $receivedAt = (new \DateTimeImmutable($receivedAt, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $receivedAt = '';
            }
        }

        $attachments = [];
        foreach (is_array($mail['attachments'] ?? null) ? $mail['attachments'] : [] as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $attachments[] = [
                'filename' => (string) ($attachment['filename'] ?? 'anlage'),
                'mime_type' => strtolower(trim((string) ($attachment['mime_type'] ?? 'application/octet-stream'))),
                'content' => (string) ($attachment['content'] ?? ''),
            ];
        }

        return [
            'source_id' => trim((string) ($mail['source_id'] ?? '')),
            'message_id' => $messageId === '' ? null : $messageId,
            'in_reply_to' => $inReplyTo === '' ? null : $inReplyTo,
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'text' => $text,
            'received_at' => $receivedAt === '' ? null : $receivedAt,
            'attachments' => $attachments,
        ];
    }

    private function fingerprint(array $mail): string
    {
        if ($mail['message_id'] !== null) {
            return hash('sha256', 'message-id:' . mb_strtolower((string) $mail['message_id'], 'UTF-8'));
        }

        return hash('sha256', implode('|', [
            $mail['from'],
            implode(',', $mail['to']),
            mb_strtolower($mail['subject'], 'UTF-8'),
            hash('sha256', $mail['text']),
            (string) ($mail['received_at'] ?? ''),
        ]));
    }

    private function existingFingerprint(string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM authority_messages WHERE message_fingerprint = :fingerprint LIMIT 1'
        );
        $stmt->execute(['fingerprint' => $fingerprint]);
        $messageId = $stmt->fetchColumn();

        if ($messageId !== false) {
            return ['message_id' => (string) $messageId, 'quarantine_id' => null];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id FROM inbound_mail_quarantine WHERE message_fingerprint = :fingerprint LIMIT 1'
        );
        $stmt->execute(['fingerprint' => $fingerprint]);
        $quarantineId = $stmt->fetchColumn();

        return $quarantineId === false
            ? null
            : ['message_id' => null, 'quarantine_id' => (string) $quarantineId];
    }

    private function resolveTarget(array $recipients, ?string $inReplyTo): ?array
    {
        foreach ($recipients as $recipient) {
            $reply = $this->replyAddresses->resolve($recipient);
            if ($reply !== null) {
                return [
                    'case_id' => (string) $reply['case_id'],
                    'dispatch_id' => $reply['dispatch_id'] === null ? null : (string) $reply['dispatch_id'],
                    'reply_address_id' => (string) $reply['id'],
                ];
            }
        }

        if ($inReplyTo !== null && $inReplyTo !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT d.case_id, d.id AS dispatch_id, d.reply_address_id
                 FROM dispatches d
                 WHERE d.outbound_message_id = :message_id
                 ORDER BY d.created_at DESC LIMIT 1'
            );
            $stmt->execute(['message_id' => $inReplyTo]);
            $row = $stmt->fetch();

            if (is_array($row)) {
                return [
                    'case_id' => (string) $row['case_id'],
                    'dispatch_id' => (string) $row['dispatch_id'],
                    'reply_address_id' => $row['reply_address_id'] === null ? null : (string) $row['reply_address_id'],
                ];
            }
        }

        return null;
    }

    private function quarantine(array $mail, string $fingerprint, string $reason): array
    {
        $id = Uuid::v4();
        $stmt = $this->pdo->prepare(
            'INSERT INTO inbound_mail_quarantine
             (id, source_id, message_fingerprint, sender_encrypted, recipients_encrypted,
              subject_encrypted, body_text_encrypted, body_sha256, reason, received_at, created_at, resolved_at)
             VALUES
             (:id, :source_id, :fingerprint, :sender, :recipients,
              :subject, :body, :body_sha256, :reason, :received_at, UTC_TIMESTAMP(), NULL)'
        );
        $stmt->execute([
            'id' => $id,
            'source_id' => $mail['source_id'] === '' ? null : $mail['source_id'],
            'fingerprint' => $fingerprint,
            'sender' => $this->cipher->encrypt($mail['from']),
            'recipients' => $this->cipher->encrypt(implode(', ', $mail['to'])),
            'subject' => $mail['subject'] === '' ? null : $this->cipher->encrypt($mail['subject']),
            'body' => $this->cipher->encrypt($mail['text']),
            'body_sha256' => hash('sha256', $mail['text']),
            'reason' => mb_substr($reason, 0, 255),
            'received_at' => $mail['received_at'],
        ]);

        $this->audit->log('INBOUND_MAIL_QUARANTINED', 'mail', $id, 'SYSTEM', null, [
            'reason' => $reason,
        ]);

        return [
            'status' => 'QUARANTINED',
            'quarantine_id' => $id,
        ];
    }

    private function createDerivedActions(
        string $caseId,
        string $messageId,
        array $classification,
        string $subject,
        string $body
    ): void {
        $class = (string) $classification['classification'];
        $deadlineAt = $classification['deadline_at'];

        $taskTitles = [
            'INQUIRY' => 'Rückfrage der Behörde beantworten',
            'DEMAND' => 'Nachforderung der Behörde bearbeiten',
            'DEADLINE' => 'Frist aus Behördenantwort bearbeiten',
            'REJECTION' => 'Behördenantwort mit Ablehnung/Einstellung prüfen',
            'CLOSURE' => 'Abschlussmitteilung der Behörde prüfen',
        ];

        if (isset($taskTitles[$class])) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO case_tasks
                 (id, case_id, source_message_id, task_type, title, description_encrypted,
                  status, due_at, source, created_at, completed_at)
                 VALUES
                 (:id, :case_id, :message_id, :task_type, :title, :description,
                  "OPEN", :due_at, "AUTHORITY_MESSAGE", UTC_TIMESTAMP(), NULL)'
            );
            $stmt->execute([
                'id' => Uuid::v4(),
                'case_id' => $caseId,
                'message_id' => $messageId,
                'task_type' => $class,
                'title' => $taskTitles[$class],
                'description' => $this->cipher->encrypt(
                    trim($subject . "\n\n" . mb_substr($body, 0, 2000))
                ),
                'due_at' => $deadlineAt,
            ]);
        }

        if (is_string($deadlineAt) && $deadlineAt !== '') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO case_deadlines
                 (id, case_id, source_message_id, deadline_type, due_at,
                  source_text_encrypted, status, created_at, resolved_at)
                 VALUES
                 (:id, :case_id, :message_id, "AUTHORITY_DEADLINE", :due_at,
                  :source_text, "OPEN", UTC_TIMESTAMP(), NULL)'
            );
            $stmt->execute([
                'id' => Uuid::v4(),
                'case_id' => $caseId,
                'message_id' => $messageId,
                'due_at' => $deadlineAt,
                'source_text' => $this->cipher->encrypt(
                    trim($subject . "\n\n" . mb_substr($body, 0, 2000))
                ),
            ]);
        }
    }

    private function advanceCase(string $caseId, string $classification): void
    {
        $row = $this->fetchOne(
            'SELECT user_id, status FROM cases WHERE id = :id LIMIT 1',
            ['id' => $caseId]
        );

        if ($row === null) {
            return;
        }

        $userId = (string) $row['user_id'];
        $status = (string) $row['status'];

        try {
            if (in_array($status, [CaseStatus::SENT, CaseStatus::DELIVERY_UNKNOWN], true)) {
                $this->cases->changeStatus(
                    $userId,
                    $caseId,
                    CaseStatus::DELIVERED,
                    'Behördenantwort bestätigt Erreichbarkeit des Kommunikationskanals'
                );
                $status = CaseStatus::DELIVERED;
            }

            if ($classification === 'RECEIPT') {
                if ($status === CaseStatus::DELIVERED) {
                    $this->cases->changeStatus(
                        $userId,
                        $caseId,
                        CaseStatus::AUTHORITY_PROCESSING,
                        'Eingangsbestätigung der Behörde'
                    );
                }
                return;
            }

            if (in_array($status, [CaseStatus::DELIVERED, CaseStatus::AUTHORITY_PROCESSING], true)) {
                $this->cases->changeStatus(
                    $userId,
                    $caseId,
                    CaseStatus::AUTHORITY_REPLY,
                    'Behördenantwort eingegangen'
                );
                $status = CaseStatus::AUTHORITY_REPLY;
            }

            if (
                in_array($classification, ['INQUIRY','DEMAND','DEADLINE'], true)
                && $status === CaseStatus::AUTHORITY_REPLY
            ) {
                $this->cases->changeStatus(
                    $userId,
                    $caseId,
                    CaseStatus::USER_ACTION_REQUIRED,
                    'Behördenantwort erfordert Nutzeraktion'
                );
            }
        } catch (\DomainException) {
            // Nachricht bleibt revisionssicher gespeichert; nicht jede historische Statuslage
            // erlaubt einen automatischen Statusschritt.
        }
    }

    private function tasksForCase(string $caseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, source_message_id, task_type, title, description_encrypted,
                    status, due_at, source, created_at, completed_at
             FROM case_tasks
             WHERE case_id = :case_id
             ORDER BY CASE status WHEN "OPEN" THEN 0 ELSE 1 END, due_at IS NULL, due_at, created_at'
        );
        $stmt->execute(['case_id' => $caseId]);

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['description'] = $row['description_encrypted'] === null
                ? null
                : $this->cipher->decrypt((string) $row['description_encrypted']);
            unset($row['description_encrypted']);
        }

        return $rows;
    }

    private function deadlinesForCase(string $caseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, source_message_id, deadline_type, due_at, source_text_encrypted,
                    status, created_at, resolved_at
             FROM case_deadlines
             WHERE case_id = :case_id
             ORDER BY CASE status WHEN "OPEN" THEN 0 ELSE 1 END, due_at, created_at'
        );
        $stmt->execute(['case_id' => $caseId]);

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['source_text'] = $row['source_text_encrypted'] === null
                ? null
                : $this->cipher->decrypt((string) $row['source_text_encrypted']);
            unset($row['source_text_encrypted']);
        }

        return $rows;
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
