<?php

declare(strict_types=1);

namespace MeldeVerkehr\Community;

use MeldeVerkehr\Auth\PermissionService;
use MeldeVerkehr\Support\Uuid;
use PDO;

final class ModerationService
{
    private const TARGETS = ['POST','COMMENT','PROBLEM_AREA','PROFILE','MESSAGE'];
    private const ACTIONS = ['DISMISS','HIDE','WARN','RESTRICT'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PermissionService $permissions
    ) {
    }

    public function report(
        string $reporterUserId,
        string $targetType,
        string $targetId,
        string $category,
        ?string $reason
    ): string {
        $targetType = strtoupper(trim($targetType));
        if (!in_array($targetType, self::TARGETS, true)) {
            throw new \InvalidArgumentException('Ungültiges Meldeziel.');
        }

        if (!$this->targetExists($targetType, $targetId)) {
            throw new \DomainException('Zu meldender Inhalt wurde nicht gefunden.');
        }

        $category = strtoupper(trim($category));
        if ($category === '' || strlen($category) > 50) {
            throw new \InvalidArgumentException('Meldekategorie ist ungültig.');
        }

        $reason = trim((string) $reason);
        if (mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Meldegrund ist zu lang.');
        }

        $id = Uuid::v4();
        $this->pdo->prepare(
            'INSERT INTO community_reports
             (id, reporter_user_id, target_type, target_id, category, reason, status,
              assigned_user_id, resolution, created_at, resolved_at)
             VALUES
             (:id, :reporter, :target_type, :target_id, :category, :reason, "OPEN",
              NULL, NULL, UTC_TIMESTAMP(), NULL)'
        )->execute([
            'id' => $id,
            'reporter' => $reporterUserId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'category' => $category,
            'reason' => $reason === '' ? null : $reason,
        ]);

        return $id;
    }

    public function queue(string $moderatorUserId): array
    {
        $this->assertModerator($moderatorUserId);

        $stmt = $this->pdo->query(
            'SELECT cr.*, cp.username AS reporter_username
             FROM community_reports cr
             LEFT JOIN community_profiles cp ON cp.user_id = cr.reporter_user_id
             WHERE cr.status IN ("OPEN","IN_REVIEW")
             ORDER BY cr.created_at'
        );

        return array_values(array_filter(
            $stmt->fetchAll(),
            fn(array $report): bool => $this->inModeratorScope(
                $moderatorUserId,
                (string) $report['target_type'],
                (string) $report['target_id']
            )
        ));
    }

    public function resolve(
        string $moderatorUserId,
        string $reportId,
        string $action,
        ?string $reason = null
    ): void {
        $this->assertModerator($moderatorUserId);

        $action = strtoupper(trim($action));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Ungültige Moderationsaktion.');
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM community_reports WHERE id = :id AND status IN ("OPEN","IN_REVIEW") LIMIT 1'
        );
        $stmt->execute(['id' => $reportId]);
        $report = $stmt->fetch();

        if (!is_array($report)) {
            throw new \DomainException('Moderationsfall nicht gefunden.');
        }

        if (!$this->inModeratorScope(
            $moderatorUserId,
            (string) $report['target_type'],
            (string) $report['target_id']
        )) {
            throw new \MeldeVerkehr\Auth\AuthorizationException('Access denied.');
        }

        if ($action === 'HIDE') {
            $this->hideTarget((string) $report['target_type'], (string) $report['target_id']);
        }

        $reason = trim((string) $reason);
        if (mb_strlen($reason) > 1000) {
            throw new \InvalidArgumentException('Moderationsbegründung ist zu lang.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'UPDATE community_reports
                 SET status = "RESOLVED", assigned_user_id = :moderator,
                     resolution = :resolution, resolved_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            )->execute([
                'moderator' => $moderatorUserId,
                'resolution' => $action . ($reason !== '' ? ': ' . $reason : ''),
                'id' => $reportId,
            ]);

            $this->pdo->prepare(
                'INSERT INTO community_moderation_actions
                 (id, report_id, moderator_user_id, action_type, target_type, target_id, reason, created_at)
                 VALUES
                 (:id, :report_id, :moderator, :action_type, :target_type, :target_id, :reason, UTC_TIMESTAMP())'
            )->execute([
                'id' => Uuid::v4(),
                'report_id' => $reportId,
                'moderator' => $moderatorUserId,
                'action_type' => $action,
                'target_type' => $report['target_type'],
                'target_id' => $report['target_id'],
                'reason' => $reason === '' ? null : $reason,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function assertModerator(string $userId): void
    {
        if (
            !$this->permissions->can($userId, 'moderation.review')
            && !$this->permissions->hasRole($userId, 'SUPER_ADMIN')
        ) {
            throw new \MeldeVerkehr\Auth\AuthorizationException('Access denied.');
        }
    }

    private function inModeratorScope(string $moderatorUserId, string $targetType, string $targetId): bool
    {
        if (
            $this->permissions->hasRole($moderatorUserId, 'SUPER_ADMIN')
            || $this->permissions->hasRole($moderatorUserId, 'COMMUNITY_MODERATOR')
        ) {
            return true;
        }

        if (!$this->permissions->hasRole($moderatorUserId, 'REGIONAL_MODERATOR')) {
            return false;
        }

        $moderatorProfile = $this->profileRegion($moderatorUserId);
        $targetUserId = $this->targetOwner($targetType, $targetId);

        if ($moderatorProfile === null || $targetUserId === null) {
            return false;
        }

        $targetProfile = $this->profileRegion($targetUserId);
        if ($targetProfile === null) {
            return false;
        }

        foreach (['region_city','region_district','region_state'] as $field) {
            $moderatorValue = trim((string) ($moderatorProfile[$field] ?? ''));
            if ($moderatorValue !== '') {
                return strcasecmp(
                    $moderatorValue,
                    trim((string) ($targetProfile[$field] ?? ''))
                ) === 0;
            }
        }

        return false;
    }

    private function targetExists(string $type, string $id): bool
    {
        $map = [
            'POST' => ['community_posts','id'],
            'COMMENT' => ['community_comments','id'],
            'PROBLEM_AREA' => ['public_problem_areas','id'],
            'PROFILE' => ['community_profiles','user_id'],
            'MESSAGE' => ['community_messages','id'],
        ];

        [$table, $column] = $map[$type];
        $stmt = $this->pdo->prepare(
            sprintf('SELECT COUNT(*) FROM %s WHERE %s = :id', $table, $column)
        );
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function targetOwner(string $type, string $id): ?string
    {
        $sql = match ($type) {
            'POST' => 'SELECT author_user_id FROM community_posts WHERE id = :id',
            'COMMENT' => 'SELECT author_user_id FROM community_comments WHERE id = :id',
            'PROBLEM_AREA' => 'SELECT created_by_user_id FROM public_problem_areas WHERE id = :id',
            'PROFILE' => 'SELECT user_id FROM community_profiles WHERE user_id = :id',
            'MESSAGE' => 'SELECT sender_user_id FROM community_messages WHERE id = :id',
            default => null,
        };

        if ($sql === null) {
            return null;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function profileRegion(string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT region_state, region_district, region_city
             FROM community_profiles WHERE user_id = :user_id AND status = "ACTIVE" LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function hideTarget(string $type, string $id): void
    {
        $sql = match ($type) {
            'POST' => 'UPDATE community_posts SET status = "HIDDEN", updated_at = UTC_TIMESTAMP() WHERE id = :id',
            'COMMENT' => 'UPDATE community_comments SET status = "HIDDEN", updated_at = UTC_TIMESTAMP() WHERE id = :id',
            'PROBLEM_AREA' => 'UPDATE public_problem_areas SET moderation_status = "HIDDEN", updated_at = UTC_TIMESTAMP() WHERE id = :id',
            'PROFILE' => 'UPDATE community_profiles SET status = "RESTRICTED", updated_at = UTC_TIMESTAMP() WHERE user_id = :id',
            'MESSAGE' => 'UPDATE community_messages SET status = "HIDDEN" WHERE id = :id',
            default => null,
        };

        if ($sql !== null) {
            $this->pdo->prepare($sql)->execute(['id' => $id]);
        }
    }
}
