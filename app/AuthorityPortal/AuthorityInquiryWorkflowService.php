<?php

declare(strict_types=1);

namespace MeldeVerkehr\AuthorityPortal;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class AuthorityInquiryWorkflowService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorityAccessService $access,
        private readonly SecretCipher $cipher,
        private readonly AuditLogger $audit
    ) {
    }

    public function citizenInbox(string $userId, string $caseId): array
    {
        $case = $this->ownedCase($userId, $caseId);

        $stmt = $this->pdo->prepare(
            'SELECT i.id, i.authority_id, a.name AS authority_name, i.inquiry_type, i.subject,
                    i.body_encrypted, i.body_sha256, i.status, i.due_at, i.created_at, i.answered_at
             FROM authority_portal_inquiries i
             INNER JOIN authorities a ON a.id = i.authority_id
             WHERE i.case_id = :case_id
             ORDER BY i.created_at DESC'
        );
        $stmt->execute(['case_id' => $caseId]);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $body = $this->cipher->decrypt((string) $row['body_encrypted']);
            if (!hash_equals((string) $row['body_sha256'], hash('sha256', $body))) {
                throw new \RuntimeException('Integrität einer Behördenanfrage ist verletzt.');
            }

            unset($row['body_encrypted']);
            $row['body'] = $body;
            $row['responses'] = $this->responses((string) $row['id']);
            $items[] = $row;
        }

        return [
            'case' => $case,
            'inquiries' => $items,
        ];
    }

    public function submitCitizenResponse(
        string $userId,
        string $inquiryId,
        string $body
    ): string {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 50000) {
            throw new \InvalidArgumentException('Antworttext ist leer oder zu lang.');
        }

        $row = $this->fetchOne(
            'SELECT i.id, i.case_id, i.status, i.case_task_id, c.user_id
             FROM authority_portal_inquiries i
             INNER JOIN cases c ON c.id = i.case_id
             WHERE i.id = :id LIMIT 1',
            ['id' => $inquiryId]
        );

        if ($row === null || (string) $row['user_id'] !== $userId) {
            throw new \DomainException('Behördenanfrage nicht gefunden.');
        }

        if (!in_array((string) $row['status'], ['OPEN', 'REVISION_REQUIRED'], true)) {
            throw new \DomainException('Diese Behördenanfrage kann derzeit nicht beantwortet werden.');
        }

        $caseId = (string) $row['case_id'];
        $version = (int) $this->scalar(
            'SELECT COALESCE(MAX(version_no), 0) + 1
             FROM authority_inquiry_responses
             WHERE inquiry_id = :id',
            ['id' => $inquiryId]
        );
        $responseId = Uuid::v4();

        try {
            $this->pdo->beginTransaction();

            $this->pdo->prepare(
                'INSERT INTO authority_inquiry_responses
                 (id, inquiry_id, case_id, version_no, body_encrypted, body_sha256,
                  submitted_by_user_id, status, submitted_at)
                 VALUES
                 (:id, :inquiry_id, :case_id, :version_no, :body, :sha256,
                  :user_id, "SUBMITTED", UTC_TIMESTAMP())'
            )->execute([
                'id' => $responseId,
                'inquiry_id' => $inquiryId,
                'case_id' => $caseId,
                'version_no' => $version,
                'body' => $this->cipher->encrypt($body),
                'sha256' => hash('sha256', $body),
                'user_id' => $userId,
            ]);

            $this->pdo->prepare(
                'UPDATE authority_portal_inquiries
                 SET status = "AWAITING_REVIEW", answered_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            )->execute(['id' => $inquiryId]);

            if (!empty($row['case_task_id'])) {
                $this->pdo->prepare(
                    'UPDATE case_tasks
                     SET status = "DONE", completed_at = UTC_TIMESTAMP()
                     WHERE id = :id'
                )->execute(['id' => $row['case_task_id']]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log(
            'AUTHORITY_INQUIRY_RESPONSE_SUBMITTED',
            'case',
            $caseId,
            'USER',
            $userId,
            [
                'inquiry_id' => $inquiryId,
                'response_id' => $responseId,
                'version_no' => $version,
            ]
        );

        return $caseId;
    }

    public function reviewAuthorityResponse(
        string $authorityUserId,
        string $inquiryId,
        string $decision,
        string $note = ''
    ): string {
        $decision = strtoupper(trim($decision));
        if (!in_array($decision, ['ACCEPT', 'REVISION_REQUIRED'], true)) {
            throw new \InvalidArgumentException('Ungültige Prüfentscheidung.');
        }

        $inquiry = $this->fetchOne(
            'SELECT i.id, i.case_id, i.authority_id, i.case_task_id, i.status
             FROM authority_portal_inquiries i
             WHERE i.id = :id LIMIT 1',
            ['id' => $inquiryId]
        );

        if ($inquiry === null) {
            throw new \DomainException('Behördenanfrage nicht gefunden.');
        }

        $this->access->assertCaseForAuthority(
            $authorityUserId,
            (string) $inquiry['authority_id'],
            (string) $inquiry['case_id'],
            'authority.case.reply'
        );

        if ((string) $inquiry['status'] !== 'AWAITING_REVIEW') {
            throw new \DomainException('Für diese Anfrage liegt keine prüfbare Bürgerantwort vor.');
        }

        $response = $this->fetchOne(
            'SELECT id
             FROM authority_inquiry_responses
             WHERE inquiry_id = :id AND status = "SUBMITTED"
             ORDER BY version_no DESC
             LIMIT 1',
            ['id' => $inquiryId]
        );

        if ($response === null) {
            throw new \DomainException('Bürgerantwort nicht gefunden.');
        }

        $note = trim($note);
        if (mb_strlen($note) > 10000) {
            throw new \InvalidArgumentException('Prüfvermerk ist zu lang.');
        }

        $responseStatus = $decision === 'ACCEPT' ? 'ACCEPTED' : 'REVISION_REQUIRED';
        $inquiryStatus = $decision === 'ACCEPT' ? 'CLOSED' : 'REVISION_REQUIRED';

        try {
            $this->pdo->beginTransaction();

            $this->pdo->prepare(
                'UPDATE authority_inquiry_responses
                 SET status = :status,
                     review_note_encrypted = :note,
                     reviewed_by_user_id = :user_id,
                     reviewed_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            )->execute([
                'status' => $responseStatus,
                'note' => $note === '' ? null : $this->cipher->encrypt($note),
                'user_id' => $authorityUserId,
                'id' => $response['id'],
            ]);

            $this->pdo->prepare(
                'UPDATE authority_portal_inquiries
                 SET status = :status,
                     answered_at = CASE WHEN :status = "CLOSED" THEN UTC_TIMESTAMP() ELSE NULL END
                 WHERE id = :id'
            )->execute([
                'status' => $inquiryStatus,
                'id' => $inquiryId,
            ]);

            if (!empty($inquiry['case_task_id']) && $decision === 'REVISION_REQUIRED') {
                $this->pdo->prepare(
                    'UPDATE case_tasks
                     SET status = "OPEN", completed_at = NULL
                     WHERE id = :id'
                )->execute(['id' => $inquiry['case_task_id']]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log(
            'AUTHORITY_INQUIRY_RESPONSE_REVIEWED',
            'case',
            (string) $inquiry['case_id'],
            'AUTHORITY',
            $authorityUserId,
            [
                'inquiry_id' => $inquiryId,
                'response_id' => $response['id'],
                'decision' => $decision,
            ]
        );

        return (string) $inquiry['case_id'];
    }

    public function authorityResponses(string $authorityUserId, string $inquiryId): array
    {
        $inquiry = $this->fetchOne(
            'SELECT id, case_id, authority_id FROM authority_portal_inquiries WHERE id = :id LIMIT 1',
            ['id' => $inquiryId]
        );

        if ($inquiry === null) {
            return [];
        }

        $this->access->assertCaseForAuthority(
            $authorityUserId,
            (string) $inquiry['authority_id'],
            (string) $inquiry['case_id'],
            'authority.case.view'
        );

        return $this->responses($inquiryId);
    }

    private function responses(string $inquiryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, inquiry_id, version_no, body_encrypted, body_sha256, status,
                    review_note_encrypted, submitted_at, reviewed_at
             FROM authority_inquiry_responses
             WHERE inquiry_id = :id
             ORDER BY version_no ASC'
        );
        $stmt->execute(['id' => $inquiryId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $body = $this->cipher->decrypt((string) $row['body_encrypted']);
            if (!hash_equals((string) $row['body_sha256'], hash('sha256', $body))) {
                throw new \RuntimeException('Integrität einer Bürgerantwort ist verletzt.');
            }

            $row['body'] = $body;
            $row['review_note'] = $row['review_note_encrypted'] === null
                ? ''
                : $this->cipher->decrypt((string) $row['review_note_encrypted']);
            unset($row['body_encrypted'], $row['review_note_encrypted']);
            $rows[] = $row;
        }

        return $rows;
    }

    private function ownedCase(string $userId, string $caseId): array
    {
        $row = $this->fetchOne(
            'SELECT id, public_number, status, user_id
             FROM cases
             WHERE id = :id LIMIT 1',
            ['id' => $caseId]
        );

        if ($row === null || (string) $row['user_id'] !== $userId) {
            throw new \DomainException('Vorgang nicht gefunden.');
        }

        return $row;
    }

    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
