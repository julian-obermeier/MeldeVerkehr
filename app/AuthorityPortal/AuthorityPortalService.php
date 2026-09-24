<?php

declare(strict_types=1);

namespace MeldeVerkehr\AuthorityPortal;

use MeldeVerkehr\Audit\AuditLogger;
use MeldeVerkehr\Security\SecretCipher;
use MeldeVerkehr\Support\Uuid;
use PDO;
use Throwable;

final class AuthorityPortalService
{
    private const INQUIRY_TYPES = ['TIME','PHOTO','LOCATION','WITNESS','OTHER'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuthorityAccessService $access,
        private readonly SecretCipher $cipher,
        private readonly AuditLogger $audit
    ) {
    }

    public function inbox(
        string $userId,
        ?string $authorityId = null,
        ?string $status = null,
        ?string $query = null,
        int $limit = 100
    ): array {
        $scopeIds = [];

        if ($authorityId !== null && trim($authorityId) !== '') {
            $this->access->assertAuthority($userId, $authorityId, 'authority.case.view');
            $scopeIds[] = $authorityId;
        } else {
            $scopeIds = array_map(
                static fn(array $scope): string => (string) $scope['authority_id'],
                $this->access->scopes($userId)
            );
        }

        if ($scopeIds === []) {
            return [];
        }

        $limit = max(1, min(250, $limit));
        $placeholders = implode(',', array_fill(0, count($scopeIds), '?'));
        $sql =
            'SELECT c.id, c.public_number, c.status, c.observed_from,
                    l.street, l.house_number, l.postal_code, l.city,
                    a.id AS authority_id, a.name AS authority_name,
                    MAX(d.sent_at) AS sent_at,
                    COUNT(DISTINCT api.id) AS open_inquiries
             FROM dispatches d
             INNER JOIN cases c ON c.id = d.case_id
             INNER JOIN authorities a ON a.id = d.authority_id
             LEFT JOIN locations l ON l.case_id = c.id
             LEFT JOIN authority_portal_inquiries api
               ON api.case_id = c.id
              AND api.authority_id = d.authority_id
              AND api.status = "OPEN"
             WHERE d.status = "SENT"
               AND d.authority_id IN (' . $placeholders . ')';

        $params = $scopeIds;

        if ($status !== null && trim($status) !== '') {
            $sql .= ' AND c.status = ?';
            $params[] = trim($status);
        }

        if ($query !== null && trim($query) !== '') {
            $sql .= ' AND (
                c.public_number LIKE ?
                OR l.street LIKE ?
                OR l.city LIKE ?
            )';
            $like = '%' . trim($query) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .=
            ' GROUP BY c.id, c.public_number, c.status, c.observed_from,
                       l.street, l.house_number, l.postal_code, l.city,
                       a.id, a.name
              ORDER BY sent_at DESC
              LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function caseDetail(string $userId, string $caseId): array
    {
        $access = $this->access->assertCase($userId, $caseId, 'authority.case.view');
        $package = $this->loadPackage((string) $access['dispatch_package_id']);

        $stmt = $this->pdo->prepare(
            'SELECT id, inquiry_type, subject, body_encrypted, body_sha256,
                    status, due_at, created_at, answered_at
             FROM authority_portal_inquiries
             WHERE case_id = :case_id AND authority_id = :authority_id
             ORDER BY created_at DESC'
        );
        $stmt->execute([
            'case_id' => $caseId,
            'authority_id' => $access['authority_id'],
        ]);

        $inquiries = [];
        foreach ($stmt->fetchAll() as $row) {
            $body = $this->cipher->decrypt((string) $row['body_encrypted']);
            if (!hash_equals((string) $row['body_sha256'], hash('sha256', $body))) {
                throw new \RuntimeException('Integrität einer Behördenanfrage ist verletzt.');
            }
            unset($row['body_encrypted']);
            $row['body'] = $body;
            $inquiries[] = $row;
        }

        return [
            'authority' => [
                'id' => (string) $access['authority_id'],
                'name' => (string) $access['authority_name'],
            ],
            'dispatch' => [
                'id' => (string) $access['dispatch_id'],
                'sent_at' => $access['sent_at'],
                'package_id' => (string) $access['dispatch_package_id'],
                'package_version' => (int) $package['version_no'],
                'package_sha256' => (string) $package['manifest_sha256'],
            ],
            'manifest' => $package['manifest'],
            'inquiries' => $inquiries,
        ];
    }

    public function createInquiry(
        string $userId,
        string $caseId,
        string $type,
        string $subject,
        string $body,
        ?\DateTimeImmutable $dueAt = null
    ): array {
        $access = $this->access->assertCase($userId, $caseId, 'authority.case.reply');

        $type = strtoupper(trim($type));
        if (!in_array($type, self::INQUIRY_TYPES, true)) {
            throw new \InvalidArgumentException('Ungültiger Anfragetyp.');
        }

        $subject = trim($subject);
        $body = trim($body);

        if ($subject === '' || mb_strlen($subject) > 255) {
            throw new \InvalidArgumentException('Betreff ist ungültig.');
        }

        if ($body === '' || mb_strlen($body) > 10000) {
            throw new \InvalidArgumentException('Anfragetext ist ungültig.');
        }

        if ($dueAt !== null) {
            $dueAt = $dueAt->setTimezone(new \DateTimeZone('UTC'));
        }

        $id = Uuid::v4();
        $taskId = Uuid::v4();

        try {
            $this->pdo->beginTransaction();

            $this->pdo->prepare(
                'INSERT INTO case_tasks
                 (id, case_id, source_message_id, task_type, title, description_encrypted,
                  status, due_at, source, created_at, completed_at)
                 VALUES
                 (:id, :case_id, NULL, :task_type, :title, :description,
                  "OPEN", :due_at, "AUTHORITY_PORTAL", UTC_TIMESTAMP(), NULL)'
            )->execute([
                'id' => $taskId,
                'case_id' => $caseId,
                'task_type' => 'AUTHORITY_' . $type,
                'title' => $subject,
                'description' => $this->cipher->encrypt($body),
                'due_at' => $dueAt?->format('Y-m-d H:i:s'),
            ]);

            $this->pdo->prepare(
                'INSERT INTO authority_portal_inquiries
                 (id, case_id, authority_id, created_by_user_id, inquiry_type, subject,
                  body_encrypted, body_sha256, status, due_at, case_task_id, created_at, answered_at)
                 VALUES
                 (:id, :case_id, :authority_id, :user_id, :inquiry_type, :subject,
                  :body, :sha256, "OPEN", :due_at, :task_id, UTC_TIMESTAMP(), NULL)'
            )->execute([
                'id' => $id,
                'case_id' => $caseId,
                'authority_id' => $access['authority_id'],
                'user_id' => $userId,
                'inquiry_type' => $type,
                'subject' => $subject,
                'body' => $this->cipher->encrypt($body),
                'sha256' => hash('sha256', $body),
                'due_at' => $dueAt?->format('Y-m-d H:i:s'),
                'task_id' => $taskId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log(
            'AUTHORITY_PORTAL_INQUIRY_CREATED',
            'case',
            $caseId,
            'AUTHORITY',
            $userId,
            [
                'authority_id' => $access['authority_id'],
                'inquiry_id' => $id,
                'inquiry_type' => $type,
                'due_at' => $dueAt?->format(DATE_ATOM),
            ]
        );

        return [
            'id' => $id,
            'case_id' => $caseId,
            'authority_id' => (string) $access['authority_id'],
            'inquiry_type' => $type,
            'subject' => $subject,
            'body' => $body,
            'status' => 'OPEN',
            'due_at' => $dueAt?->format(DATE_ATOM),
            'case_task_id' => $taskId,
        ];
    }

    private function loadPackage(string $packageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, version_no, manifest_encrypted, manifest_sha256
             FROM dispatch_packages
             WHERE id = :id AND status = "FROZEN"
             LIMIT 1'
        );
        $stmt->execute(['id' => $packageId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new \DomainException('Versandpaket nicht gefunden.');
        }

        $json = $this->cipher->decrypt((string) $row['manifest_encrypted']);
        if (!hash_equals((string) $row['manifest_sha256'], hash('sha256', $json))) {
            throw new \RuntimeException('Integrität des Versandpakets ist verletzt.');
        }

        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) {
            throw new \RuntimeException('Versandpaket ist ungültig.');
        }

        return [
            'id' => (string) $row['id'],
            'version_no' => (int) $row['version_no'],
            'manifest_sha256' => (string) $row['manifest_sha256'],
            'manifest' => $manifest,
        ];
    }
}
