<?php

declare(strict_types=1);

namespace MeldeVerkehr\Operations;

use MeldeVerkehr\Support\Uuid;
use PDO;

final class RetentionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?int $closedCaseDays = null,
        private readonly ?int $exportDays = 7
    ) {
    }

    public function planForUser(string $userId): array
    {
        $planned = 0;

        if ($this->closedCaseDays !== null && $this->closedCaseDays > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT id, closed_at
                 FROM cases
                 WHERE user_id = :user_id
                   AND status IN ("CLOSED","ARCHIVED")
                   AND closed_at IS NOT NULL'
            );
            $stmt->execute(['user_id' => $userId]);

            foreach ($stmt->fetchAll() as $case) {
                $eligible = (new \DateTimeImmutable((string) $case['closed_at'], new \DateTimeZone('UTC')))
                    ->modify('+' . $this->closedCaseDays . ' days');

                $planned += $this->upsertPlan(
                    $userId,
                    (string) $case['id'],
                    'CASE',
                    'CASE_RECORD',
                    (string) $case['id'],
                    'CLOSED_CASE_CONFIGURED',
                    $eligible,
                    'Konfigurierter Löschkandidat; keine automatische Löschung.'
                ) ? 1 : 0;
            }
        }

        if ($this->exportDays !== null && $this->exportDays > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT id, created_at FROM export_artifacts
                 WHERE user_id = :user_id AND status = "READY"'
            );
            $stmt->execute(['user_id' => $userId]);

            foreach ($stmt->fetchAll() as $export) {
                $eligible = (new \DateTimeImmutable((string) $export['created_at'], new \DateTimeZone('UTC')))
                    ->modify('+' . $this->exportDays . ' days');

                $planned += $this->upsertPlan(
                    $userId,
                    null,
                    'EXPORT',
                    'EXPORT_ARTIFACT',
                    (string) $export['id'],
                    'EXPORT_EXPIRY',
                    $eligible,
                    'Temporäres Exportartefakt.'
                ) ? 1 : 0;
            }
        }

        return [
            'planned' => $planned,
            'closed_case_days' => $this->closedCaseDays,
            'export_days' => $this->exportDays,
            'automatic_case_deletion_enabled' => false,
        ];
    }

    public function overview(string $userId): array
    {
        $this->planForUser($userId);

        $stmt = $this->pdo->prepare(
            'SELECT id, case_id, data_type, resource_type, resource_id, eligible_at,
                    status, policy_key, reason, created_at, processed_at
             FROM retention_schedules
             WHERE user_id = :user_id
             ORDER BY eligible_at IS NULL, eligible_at, created_at'
        );
        $stmt->execute(['user_id' => $userId]);

        $counts = [
            'cases' => $this->count('cases', 'user_id', $userId),
            'exports' => $this->count('export_artifacts', 'user_id', $userId),
            'community_posts' => $this->count('community_posts', 'author_user_id', $userId),
            'community_messages' => $this->messageCount($userId),
        ];

        return [
            'counts' => $counts,
            'schedules' => $stmt->fetchAll(),
            'automatic_case_deletion_enabled' => false,
            'closed_case_days' => $this->closedCaseDays,
        ];
    }

    public function accountDeletionPreview(string $userId): array
    {
        $overview = $this->overview($userId);

        return [
            'immediately_deletable' => [
                'saved_filters' => $this->count('saved_case_filters', 'user_id', $userId),
                'notifications' => $this->count('user_notifications', 'user_id', $userId),
                'expired_exports' => $this->expiredExportCount($userId),
            ],
            'review_required' => [
                'cases' => $overview['counts']['cases'],
                'community_posts' => $overview['counts']['community_posts'],
                'community_messages' => $overview['counts']['community_messages'],
            ],
            'note' => 'Fall- und Kommunikationsdaten werden nicht automatisch gelöscht. Vor produktiver Aktivierung sind Rechtsgrundlage und konkrete Aufbewahrungsfristen zu konfigurieren.',
        ];
    }

    private function upsertPlan(
        string $userId,
        ?string $caseId,
        string $dataType,
        string $resourceType,
        ?string $resourceId,
        string $policyKey,
        \DateTimeImmutable $eligibleAt,
        string $reason
    ): bool {
        $id = Uuid::v4();
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO retention_schedules
             (id, user_id, case_id, data_type, resource_type, resource_id, eligible_at,
              status, policy_key, reason, created_at, processed_at)
             VALUES
             (:id, :user_id, :case_id, :data_type, :resource_type, :resource_id, :eligible_at,
              "PLANNED", :policy_key, :reason, UTC_TIMESTAMP(), NULL)'
        );
        $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'case_id' => $caseId,
            'data_type' => $dataType,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'eligible_at' => $eligibleAt->format('Y-m-d H:i:s'),
            'policy_key' => $policyKey,
            'reason' => $reason,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function count(string $table, string $column, string $userId): int
    {
        $allowed = [
            'cases' => 'user_id',
            'export_artifacts' => 'user_id',
            'community_posts' => 'author_user_id',
            'saved_case_filters' => 'user_id',
            'user_notifications' => 'user_id',
        ];

        if (($allowed[$table] ?? null) !== $column) {
            throw new \InvalidArgumentException('Ungültige Retention-Abfrage.');
        }

        $stmt = $this->pdo->prepare(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s = :user_id', $table, $column)
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function messageCount(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM community_messages
             WHERE sender_user_id = :a OR recipient_user_id = :b'
        );
        $stmt->execute(['a' => $userId, 'b' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    private function expiredExportCount(string $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM export_artifacts
             WHERE user_id = :user_id
               AND expires_at IS NOT NULL
               AND expires_at < UTC_TIMESTAMP()'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }
}
